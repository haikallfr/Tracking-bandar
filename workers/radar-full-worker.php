<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/SymbolUniverse.php';
require_once __DIR__ . '/../src/RadarBandar.php';
require_once __DIR__ . '/../src/BrokerRepository.php';
require_once __DIR__ . '/../src/HistoricalRepository.php';
require_once __DIR__ . '/../src/CandidateEnricher.php';

$client = new StockbitClient((string) setting('stockbit_token', ''));
$universe = new SymbolUniverse();
$repository = new BrokerRepository();
$radar = new RadarBandar($repository);
$enricher = new CandidateEnricher($client, new HistoricalRepository());

$filters = [
    'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
    'transaction_type' => 'TRANSACTION_TYPE_NET',
    'market_board' => 'MARKET_BOARD_ALL',
    'investor_type' => 'INVESTOR_TYPE_ALL',
];

$symbols = $universe->all();
$pid = (string) getmypid();
$startedAt = gmdate(DATE_ATOM);
$results = [];
$errors = 0;
$matched = 0;
$threshold = RadarBandar::FULL_MARKET_FILTER_SCORE;
$batchSize = 10;
$concurrency = 8;

save_setting('radar_full_status', 'running');
save_setting('radar_full_started_at', $startedAt);
save_setting('radar_full_pid', $pid);
save_setting('radar_full_cancel_requested', '0');
save_setting('radar_full_scanned', '0');
save_setting('radar_full_total', (string) count($symbols));
save_setting('radar_full_matched', '0');
save_setting('radar_full_errors', '0');
save_setting('radar_full_current_symbol', '');
save_setting('radar_full_error_log', '[]');
save_setting('radar_full_meta', json_encode([
    'filters' => $filters,
    'threshold' => $threshold,
    'batch_size' => $batchSize,
    'concurrency' => $concurrency,
], JSON_THROW_ON_ERROR));

foreach (array_chunk($symbols, $batchSize) as $batchIndex => $batchSymbols) {
    if (setting('radar_full_cancel_requested', '0') === '1') {
        break;
    }

    save_setting('radar_full_current_symbol', implode(', ', array_slice($batchSymbols, 0, 3)));
    $batch = $client->fetchMarketDetectorsBatch($batchSymbols, $filters, $concurrency, 3);
    $entries = [];

    foreach ($batch['items'] as $symbol => $payload) {
        $entries[] = [
            'symbol' => $symbol,
            'payload' => $payload,
            'updated_at' => $payload['fetched_at'] ?? gmdate(DATE_ATOM),
        ];

        try {
            $item = $radar->evaluate($symbol, $payload, $payload['fetched_at'] ?? gmdate(DATE_ATOM));
            if ($item === null) {
                continue;
            }

            $enrichment = $enricher->enrich($symbol, 12);
            if ($enrichment !== []) {
                $item = $radar->refine($item, $enrichment);
            }

            if ((float) $item['score'] >= $threshold) {
                $results[] = $item;
            }
        } catch (Throwable $error) {
            $errors++;
            append_worker_error('radar_full_error_log', $symbol, $error);
        }
    }

    if ($entries !== []) {
        $repository->saveBatch($entries);
    }

    foreach ($batch['errors'] as $symbol => $message) {
        $errors++;
        append_worker_error('radar_full_error_log', $symbol, new RuntimeException($message));
    }

    usort($results, static function (array $left, array $right): int {
        return ($right['score'] <=> $left['score'])
            ?: strcmp((string) $left['symbol'], (string) $right['symbol']);
    });
    $matched = count($results);

    $scanned = min(count($symbols), ($batchIndex + 1) * $batchSize);
    save_setting('radar_full_scanned', (string) $scanned);
    save_setting('radar_full_matched', (string) $matched);
    save_setting('radar_full_errors', (string) $errors);

    if ($scanned < count($symbols)) {
        usleep(random_int(1000, 3000) * 1000);
    }
}

$finishedAt = gmdate(DATE_ATOM);
save_setting('radar_full_results', json_encode($results, JSON_THROW_ON_ERROR));
save_setting('radar_full_status', 'idle');
save_setting('radar_full_finished_at', $finishedAt);
save_setting('radar_full_pid', '');
save_setting('radar_full_current_symbol', '');
save_setting('radar_full_cancel_requested', '0');
