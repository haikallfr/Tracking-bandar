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
$profiles = NextDayFilter::profiles();
$resultsByProfile = [];
$datasetByProfile = [];
$matchedByProfile = [];
$rules = [];

foreach ($profiles as $profile) {
    $resultsByProfile[$profile] = [];
    $datasetByProfile[$profile] = [];
    $matchedByProfile[$profile] = 0;
    $rules[$profile] = NextDayFilter::rules($profile);
}

$errors = 0;
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
    'profiles' => $profiles,
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

                foreach ($profiles as $profile) {
                    if (NextDayFilter::passes($item, $profile)) {
                        $profileItem = $item;
                        $profileItem['next_day_reasons'] = NextDayFilter::reasons($item, $profile);
                        $profileItem['next_day_profile'] = $profile;
                        $resultsByProfile[$profile][] = $profileItem;
                        $datasetByProfile[$profile][] = [
                            'symbol' => $symbol,
                            'status' => 'passed',
                            'profile' => $profile,
                            'score' => $item['score'] ?? 0,
                            'score_base' => $item['score_base'] ?? ($item['score'] ?? 0),
                            'label' => $item['label'] ?? '',
                            'metrics' => $item['metrics'] ?? [],
                            'reasons' => $profileItem['next_day_reasons'] ?? [],
                            'top_buyers' => $item['top_buyers'] ?? [],
                            'top_sellers' => $item['top_sellers'] ?? [],
                            'enrichment' => $item['enrichment'] ?? [],
                            'updated_at' => $item['updated_at'] ?? gmdate(DATE_ATOM),
                        ];
                    } else {
                        $datasetByProfile[$profile][] = [
                            'symbol' => $symbol,
                            'status' => 'filtered_out',
                            'profile' => $profile,
                            'score' => $item['score'] ?? 0,
                            'score_base' => $item['score_base'] ?? ($item['score'] ?? 0),
                            'label' => $item['label'] ?? '',
                            'metrics' => $item['metrics'] ?? [],
                            'failures' => NextDayFilter::failures($item, $profile),
                            'top_buyers' => $item['top_buyers'] ?? [],
                            'top_sellers' => $item['top_sellers'] ?? [],
                            'enrichment' => $item['enrichment'] ?? [],
                            'updated_at' => $item['updated_at'] ?? gmdate(DATE_ATOM),
                        ];
                    }
                }
            } else {
                foreach ($profiles as $profile) {
                    $datasetByProfile[$profile][] = [
                        'symbol' => $symbol,
                        'status' => 'skipped',
                        'profile' => $profile,
                        'reason' => 'insufficient_market_data',
                        'updated_at' => gmdate(DATE_ATOM),
                    ];
                }
            }
        } catch (Throwable $e) {
            $errors++;
            append_worker_error('next_day_error_log', $symbol, $e);
            foreach ($profiles as $profile) {
                $datasetByProfile[$profile][] = [
                    'symbol' => $symbol,
                    'status' => 'error',
                    'profile' => $profile,
                    'error' => $e->getMessage(),
                    'updated_at' => gmdate(DATE_ATOM),
                ];
            }
        }
    }

    if ($entries !== []) {
        $repository->saveBatch($entries);
    }

    foreach ($batch['errors'] as $symbol => $message) {
        $errors++;
        append_worker_error('next_day_error_log', $symbol, new RuntimeException($message));
        foreach ($profiles as $profile) {
            $datasetByProfile[$profile][] = [
                'symbol' => $symbol,
                'status' => 'error',
                'profile' => $profile,
                'error' => $message,
                'updated_at' => gmdate(DATE_ATOM),
            ];
        }
    }

    foreach ($profiles as $profile) {
        usort($resultsByProfile[$profile], static function (array $left, array $right): int {
            $leftAcceleration = (float) (($left['metrics'] ?? [])['turnover_acceleration'] ?? 0);
            $rightAcceleration = (float) (($right['metrics'] ?? [])['turnover_acceleration'] ?? 0);

            return ($right['score'] <=> $left['score'])
                ?: ($rightAcceleration <=> $leftAcceleration)
                ?: strcmp((string) ($left['symbol'] ?? ''), (string) ($right['symbol'] ?? ''));
        });
        $matchedByProfile[$profile] = count($resultsByProfile[$profile]);
    }

    $scanned = min(count($symbols), ($batchIndex + 1) * $batchSize);
    save_setting('next_day_scanned', (string) $scanned);
    save_setting('next_day_matched', (string) $matchedByProfile['swing']);
    save_setting('next_day_errors', (string) $errors);

    if ($scanned < count($symbols)) {
        usleep(random_int(1000, 3000) * 1000);
    }
}

$finishedAt = gmdate(DATE_ATOM);
save_setting('next_day_results', json_encode($resultsByProfile['swing'], JSON_THROW_ON_ERROR));

$timestamp = gmdate('Ymd-His');

foreach ($profiles as $profile) {
    $datasetPayload = [
        'run_type' => 'next_day_full_scan',
        'profile' => $profile,
        'generated_at' => $finishedAt,
        'started_at' => $startedAt,
        'filters' => $filters,
        'rules' => $rules[$profile],
        'summary' => [
            'scanned' => count($datasetByProfile[$profile]),
            'passed' => $matchedByProfile[$profile],
            'errors' => $errors,
            'universe_total' => count($symbols),
        ],
        'items' => $datasetByProfile[$profile],
    ];
    $fileProfile = match ($profile) {
        'fast' => 'fast-v1',
        'fast_v2' => 'fast-v2',
        default => 'swing',
    };

    $datasetFile = NEXT_DAY_RUN_DIR . '/next-day-' . $fileProfile . '-' . $timestamp . '.json';
    file_put_contents($datasetFile, json_encode($datasetPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    if ($profile === 'swing') {
        save_setting('next_day_dataset_latest_file', $datasetFile);
        save_setting('next_day_dataset_latest_generated_at', $finishedAt);
        save_setting('next_day_dataset_latest_count', (string) count($datasetByProfile[$profile]));
        save_setting('next_day_swing_dataset_latest_file', $datasetFile);
        save_setting('next_day_swing_dataset_latest_generated_at', $finishedAt);
        save_setting('next_day_swing_dataset_latest_count', (string) count($datasetByProfile[$profile]));
        continue;
    }

    if ($profile === 'fast') {
        save_setting('next_day_fast_dataset_latest_file', $datasetFile);
        save_setting('next_day_fast_dataset_latest_generated_at', $finishedAt);
        save_setting('next_day_fast_dataset_latest_count', (string) count($datasetByProfile[$profile]));
        continue;
    }

    if ($profile === 'fast_v2') {
        save_setting('next_day_fast_v2_dataset_latest_file', $datasetFile);
        save_setting('next_day_fast_v2_dataset_latest_generated_at', $finishedAt);
        save_setting('next_day_fast_v2_dataset_latest_count', (string) count($datasetByProfile[$profile]));
    }
}

save_setting('next_day_status', 'idle');
save_setting('next_day_finished_at', $finishedAt);
save_setting('next_day_pid', '');
save_setting('next_day_current_symbol', '');
save_setting('next_day_cancel_requested', '0');
