<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/SymbolUniverse.php';
require_once __DIR__ . '/../src/RadarBandar.php';
require_once __DIR__ . '/../src/BrokerRepository.php';
require_once __DIR__ . '/../src/HistoricalRepository.php';
require_once __DIR__ . '/../src/CandidateEnricher.php';
require_once __DIR__ . '/../src/NextDayFilter.php';

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
$dataset = [];
$errors = 0;
$matched = 0;
$rules = NextDayFilter::rules();
$batchSize = 10;
$concurrency = 8;

save_setting('next_day_status', 'running');
save_setting('next_day_started_at', $startedAt);
save_setting('next_day_pid', $pid);
save_setting('next_day_cancel_requested', '0');
save_setting('next_day_scanned', '0');
save_setting('next_day_total', (string) count($symbols));
save_setting('next_day_matched', '0');
save_setting('next_day_errors', '0');
save_setting('next_day_current_symbol', '');
save_setting('next_day_error_log', '[]');
save_setting('next_day_meta', json_encode([
    'filters' => $filters,
    'rules' => $rules,
    'batch_size' => $batchSize,
    'concurrency' => $concurrency,
], JSON_THROW_ON_ERROR));

foreach (array_chunk($symbols, $batchSize) as $batchIndex => $batchSymbols) {
    if (setting('next_day_cancel_requested', '0') === '1') {
        break;
    }

    save_setting('next_day_current_symbol', implode(', ', array_slice($batchSymbols, 0, 3)));
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

            if ($item !== null) {
                $enrichment = $enricher->enrich($symbol, 12);
                if ($enrichment !== []) {
                    $item = $radar->refine($item, $enrichment);
                }

                if (NextDayFilter::passes($item)) {
                    $item['next_day_reasons'] = NextDayFilter::reasons($item);
                    $results[] = $item;
                    $dataset[] = [
                        'symbol' => $symbol,
                        'status' => 'passed',
                        'score' => $item['score'] ?? 0,
                        'score_base' => $item['score_base'] ?? ($item['score'] ?? 0),
                        'label' => $item['label'] ?? '',
                        'metrics' => $item['metrics'] ?? [],
                        'reasons' => $item['next_day_reasons'] ?? [],
                        'top_buyers' => $item['top_buyers'] ?? [],
                        'top_sellers' => $item['top_sellers'] ?? [],
                        'enrichment' => $item['enrichment'] ?? [],
                        'updated_at' => $item['updated_at'] ?? gmdate(DATE_ATOM),
                    ];
                } else {
                    $dataset[] = [
                        'symbol' => $symbol,
                        'status' => 'filtered_out',
                        'score' => $item['score'] ?? 0,
                        'score_base' => $item['score_base'] ?? ($item['score'] ?? 0),
                        'label' => $item['label'] ?? '',
                        'metrics' => $item['metrics'] ?? [],
                        'failures' => NextDayFilter::failures($item),
                        'top_buyers' => $item['top_buyers'] ?? [],
                        'top_sellers' => $item['top_sellers'] ?? [],
                        'enrichment' => $item['enrichment'] ?? [],
                        'updated_at' => $item['updated_at'] ?? gmdate(DATE_ATOM),
                    ];
                }
            } else {
                $dataset[] = [
                    'symbol' => $symbol,
                    'status' => 'skipped',
                    'reason' => 'insufficient_market_data',
                    'updated_at' => gmdate(DATE_ATOM),
                ];
            }
        } catch (Throwable $e) {
            $errors++;
            append_worker_error('next_day_error_log', $symbol, $e);
            $dataset[] = [
                'symbol' => $symbol,
                'status' => 'error',
                'error' => $e->getMessage(),
                'updated_at' => gmdate(DATE_ATOM),
            ];
        }
    }

    if ($entries !== []) {
        $repository->saveBatch($entries);
    }

    foreach ($batch['errors'] as $symbol => $message) {
        $errors++;
        append_worker_error('next_day_error_log', $symbol, new RuntimeException($message));
        $dataset[] = [
            'symbol' => $symbol,
            'status' => 'error',
            'error' => $message,
            'updated_at' => gmdate(DATE_ATOM),
        ];
    }

    usort($results, static function (array $left, array $right): int {
        $leftAcceleration = (float) (($left['metrics'] ?? [])['turnover_acceleration'] ?? 0);
        $rightAcceleration = (float) (($right['metrics'] ?? [])['turnover_acceleration'] ?? 0);

        return ($right['score'] <=> $left['score'])
            ?: ($rightAcceleration <=> $leftAcceleration)
            ?: strcmp((string) ($left['symbol'] ?? ''), (string) ($right['symbol'] ?? ''));
    });
    $matched = count($results);

    $scanned = min(count($symbols), ($batchIndex + 1) * $batchSize);
    save_setting('next_day_scanned', (string) $scanned);
    save_setting('next_day_matched', (string) $matched);
    save_setting('next_day_errors', (string) $errors);

    if ($scanned < count($symbols)) {
        usleep(random_int(1000, 3000) * 1000);
    }
}

$finishedAt = gmdate(DATE_ATOM);
save_setting('next_day_results', json_encode($results, JSON_THROW_ON_ERROR));

$datasetPayload = [
    'run_type' => 'next_day_full_scan',
    'generated_at' => $finishedAt,
    'started_at' => $startedAt,
    'filters' => $filters,
    'rules' => $rules,
    'summary' => [
        'scanned' => count($dataset),
        'passed' => $matched,
        'errors' => $errors,
        'universe_total' => count($symbols),
    ],
    'items' => $dataset,
];
$datasetFile = NEXT_DAY_RUN_DIR . '/next-day-' . gmdate('Ymd-His') . '.json';
file_put_contents($datasetFile, json_encode($datasetPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
save_setting('next_day_dataset_latest_file', $datasetFile);
save_setting('next_day_dataset_latest_generated_at', $finishedAt);
save_setting('next_day_dataset_latest_count', (string) count($dataset));

save_setting('next_day_status', 'idle');
save_setting('next_day_finished_at', $finishedAt);
save_setting('next_day_pid', '');
save_setting('next_day_current_symbol', '');
save_setting('next_day_cancel_requested', '0');
