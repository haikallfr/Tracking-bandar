<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/SymbolUniverse.php';
require_once __DIR__ . '/../src/RadarBandar.php';
require_once __DIR__ . '/../src/BrokerRepository.php';

$client = new StockbitClient((string) setting('stockbit_token', ''));
$universe = new SymbolUniverse();
$repository = new BrokerRepository();
$radar = new RadarBandar($repository);

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
$threshold = 87.0;
$matched = 0;
$errors = 0;
$batchSize = 10;
$concurrency = 8;

save_setting('radar_basic_status', 'running');
save_setting('radar_basic_started_at', $startedAt);
save_setting('radar_basic_pid', $pid);
save_setting('radar_basic_cancel_requested', '0');
save_setting('radar_basic_scanned', '0');
save_setting('radar_basic_total', (string) count($symbols));
save_setting('radar_basic_matched', '0');
save_setting('radar_basic_errors', '0');
save_setting('radar_basic_current_symbol', '');
save_setting('radar_basic_error_log', '[]');
save_setting('radar_basic_meta', json_encode([
    'filters' => $filters,
    'threshold' => $threshold,
    'batch_size' => $batchSize,
    'concurrency' => $concurrency,
    'formula' => [
        'buy_market_share',
        'buy_lot_share',
        'buy_concentration',
        'frequency_intensity',
        'dominance_gap',
    ],
], JSON_THROW_ON_ERROR));

foreach (array_chunk($symbols, $batchSize) as $batchIndex => $batchSymbols) {
    if (setting('radar_basic_cancel_requested', '0') === '1') {
        break;
    }

    save_setting('radar_basic_current_symbol', implode(', ', array_slice($batchSymbols, 0, 3)));

    $batch = $client->fetchMarketDetectorsBatch($batchSymbols, $filters, $concurrency, 3);
    $entries = [];

    foreach ($batch['items'] as $symbol => $payload) {
        $entries[] = [
            'symbol' => $symbol,
            'payload' => $payload,
            'updated_at' => $payload['fetched_at'] ?? gmdate(DATE_ATOM),
        ];

        $item = $radar->evaluateBasic($symbol, $payload, $payload['fetched_at'] ?? gmdate(DATE_ATOM));
        if ($item !== null && (float) $item['score'] > $threshold) {
            $item['score_base'] = $item['score'];
            $results[] = $item;
        }
    }

    if ($entries !== []) {
        $repository->saveBatch($entries);
    }

    foreach ($batch['errors'] as $symbol => $message) {
        $errors++;
        append_worker_error('radar_basic_error_log', $symbol, new RuntimeException($message));
    }

    usort($results, static function (array $left, array $right): int {
        return ($right['score'] <=> $left['score'])
            ?: strcmp((string) $left['symbol'], (string) $right['symbol']);
    });
    $matched = count($results);

    $scanned = min(count($symbols), ($batchIndex + 1) * $batchSize);
    save_setting('radar_basic_scanned', (string) $scanned);
    save_setting('radar_basic_matched', (string) $matched);
    save_setting('radar_basic_errors', (string) $errors);

    if ($scanned < count($symbols)) {
        usleep(random_int(1000, 3000) * 1000);
    }
}

save_setting('radar_basic_results', json_encode($results, JSON_THROW_ON_ERROR));
save_setting('radar_basic_status', 'idle');
save_setting('radar_basic_finished_at', gmdate(DATE_ATOM));
save_setting('radar_basic_pid', '');
save_setting('radar_basic_current_symbol', '');
save_setting('radar_basic_cancel_requested', '0');
