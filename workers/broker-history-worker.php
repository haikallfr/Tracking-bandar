<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/BrokerHistoricalRepository.php';

$symbol = strtoupper(trim((string) ($argv[1] ?? '')));
$brokerCode = strtoupper(trim((string) ($argv[2] ?? '')));

if ($symbol === '' || $brokerCode === '') {
    fwrite(STDERR, "symbol and broker code are required\n");
    exit(1);
}

$client = new StockbitClient((string) setting('stockbit_token', ''));
$repository = new BrokerHistoricalRepository();

if (!$client->hasToken()) {
    fwrite(STDERR, "missing stockbit token\n");
    exit(1);
}

function build_history_windows(int $startYear = 2000): array
{
    $windows = [];
    $today = new DateTimeImmutable('today');
    $currentYear = (int) $today->format('Y');

    for ($year = $currentYear; $year >= $startYear; $year--) {
        $from = sprintf('%04d-01-01', $year);
        $to = $year === $currentYear
            ? $today->format('Y-m-d')
            : sprintf('%04d-12-31', $year);

        $windows[] = [
            'label' => (string) $year,
            'from' => $from,
            'to' => $to,
        ];
    }

    return $windows;
}

function extract_broker_totals(array $payload, string $brokerCode): array
{
    $summary = $payload['market_detector']['data']['broker_summary'] ?? [];
    $brokerCode = strtoupper($brokerCode);
    $buyValue = 0.0;
    $buyLot = 0.0;
    $sellValue = 0.0;
    $sellLot = 0.0;
    $buyFreq = 0;
    $sellFreq = 0;
    $buyAvgWeight = 0.0;
    $sellAvgWeight = 0.0;
    $type = '';

    foreach (($summary['brokers_buy'] ?? []) as $row) {
        if (strtoupper((string) ($row['netbs_broker_code'] ?? '')) !== $brokerCode) {
            continue;
        }

        $buyValue += (float) ($row['bval'] ?? 0);
        $buyLot += (float) ($row['blot'] ?? 0);
        $buyFreq += (int) ($row['freq'] ?? 0);
        $buyAvgWeight += ((float) ($row['netbs_buy_avg_price'] ?? 0)) * ((float) ($row['blot'] ?? 0));
        $type = (string) ($row['type'] ?? $type);
    }

    foreach (($summary['brokers_sell'] ?? []) as $row) {
        if (strtoupper((string) ($row['netbs_broker_code'] ?? '')) !== $brokerCode) {
            continue;
        }

        $sellValue += (float) ($row['sval'] ?? 0);
        $sellLot += (float) ($row['slot'] ?? 0);
        $sellFreq += (int) ($row['freq'] ?? 0);
        $sellAvgWeight += ((float) ($row['netbs_sell_avg_price'] ?? 0)) * ((float) ($row['slot'] ?? 0));
        $type = (string) ($row['type'] ?? $type);
    }

    return [
        'buy_value' => $buyValue,
        'buy_lot' => $buyLot,
        'sell_value' => $sellValue,
        'sell_lot' => $sellLot,
        'buy_freq' => $buyFreq,
        'sell_freq' => $sellFreq,
        'buy_avg_weight' => $buyAvgWeight,
        'sell_avg_weight' => $sellAvgWeight,
        'type' => $type,
    ];
}

$windows = build_history_windows(2000);
$pid = (string) getmypid();
$startedAt = gmdate(DATE_ATOM);

save_setting('broker_history_status', 'running');
save_setting('broker_history_started_at', $startedAt);
save_setting('broker_history_finished_at', '');
save_setting('broker_history_pid', $pid);
save_setting('broker_history_symbol', $symbol);
save_setting('broker_history_broker_code', $brokerCode);
save_setting('broker_history_scanned', '0');
save_setting('broker_history_total', (string) count($windows));
save_setting('broker_history_current_range', '');
save_setting('broker_history_errors', '0');
save_setting('broker_history_cancel_requested', '0');
save_setting('broker_history_error_log', '[]');
save_setting('broker_history_meta', json_encode([
    'symbol' => $symbol,
    'broker_code' => $brokerCode,
    'window_mode' => 'yearly',
    'start_year' => 2000,
], JSON_THROW_ON_ERROR));

$totals = [
    'buy_value' => 0.0,
    'buy_lot' => 0.0,
    'sell_value' => 0.0,
    'sell_lot' => 0.0,
    'buy_freq' => 0,
    'sell_freq' => 0,
    'buy_avg_weight' => 0.0,
    'sell_avg_weight' => 0.0,
];
$type = '';
$errors = 0;
$hitWindows = [];

foreach ($windows as $index => $window) {
    if (setting('broker_history_cancel_requested', '0') === '1') {
        break;
    }

    save_setting('broker_history_current_range', $window['from'] . ' s/d ' . $window['to']);

    try {
        $payload = $client->fetchSymbol($symbol, [
            'from' => $window['from'],
            'to' => $window['to'],
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board' => 'MARKET_BOARD_ALL',
            'investor_type' => 'INVESTOR_TYPE_ALL',
        ]);
        $slice = extract_broker_totals($payload, $brokerCode);

        $totals['buy_value'] += $slice['buy_value'];
        $totals['buy_lot'] += $slice['buy_lot'];
        $totals['sell_value'] += $slice['sell_value'];
        $totals['sell_lot'] += $slice['sell_lot'];
        $totals['buy_freq'] += $slice['buy_freq'];
        $totals['sell_freq'] += $slice['sell_freq'];
        $totals['buy_avg_weight'] += $slice['buy_avg_weight'];
        $totals['sell_avg_weight'] += $slice['sell_avg_weight'];

        if ($slice['type'] !== '') {
            $type = $slice['type'];
        }

        if ($slice['buy_value'] > 0 || $slice['sell_value'] > 0 || $slice['buy_lot'] > 0 || $slice['sell_lot'] > 0) {
            $hitWindows[] = [
                'label' => $window['label'],
                'from' => $window['from'],
                'to' => $window['to'],
                'buy_value' => $slice['buy_value'],
                'buy_lot' => $slice['buy_lot'],
                'sell_value' => $slice['sell_value'],
                'sell_lot' => $slice['sell_lot'],
                'buy_freq' => $slice['buy_freq'],
                'sell_freq' => $slice['sell_freq'],
                'buy_avg' => $slice['buy_lot'] > 0 ? ($slice['buy_avg_weight'] / $slice['buy_lot']) : 0,
                'sell_avg' => $slice['sell_lot'] > 0 ? ($slice['sell_avg_weight'] / $slice['sell_lot']) : 0,
            ];
        }
    } catch (Throwable $error) {
        $errors++;
        append_worker_error('broker_history_error_log', $symbol . ':' . $window['label'], $error, 50);
    }

    save_setting('broker_history_scanned', (string) ($index + 1));
    save_setting('broker_history_errors', (string) $errors);
}

$netValue = $totals['buy_value'] - $totals['sell_value'];
$netLot = $totals['buy_lot'] - $totals['sell_lot'];
$buyAvg = $totals['buy_lot'] > 0 ? ($totals['buy_avg_weight'] / $totals['buy_lot']) : 0.0;
$sellAvg = $totals['sell_lot'] > 0 ? ($totals['sell_avg_weight'] / $totals['sell_lot']) : 0.0;
$displayAvg = $netLot >= 0 ? $buyAvg : $sellAvg;
$finishedAt = gmdate(DATE_ATOM);

$result = [
    'symbol' => $symbol,
    'broker_code' => $brokerCode,
    'broker_type' => $type,
    'generated_at' => $finishedAt,
    'range' => [
        'from' => end($windows)['from'] ?? '',
        'to' => $windows[0]['to'] ?? '',
    ],
    'window_mode' => 'yearly',
    'windows_scanned' => (int) setting('broker_history_scanned', '0'),
    'windows_total' => count($windows),
    'windows_with_hits' => count($hitWindows),
    'buy_value' => $totals['buy_value'],
    'buy_lot' => $totals['buy_lot'],
    'sell_value' => $totals['sell_value'],
    'sell_lot' => $totals['sell_lot'],
    'net_value' => $netValue,
    'net_lot' => $netLot,
    'buy_avg' => $buyAvg,
    'sell_avg' => $sellAvg,
    'display_avg' => $displayAvg,
    'buy_freq' => $totals['buy_freq'],
    'sell_freq' => $totals['sell_freq'],
    'hit_windows' => $hitWindows,
    'errors' => $errors,
];

$repository->save($symbol, $brokerCode, $result);

save_setting('broker_history_status', 'idle');
save_setting('broker_history_finished_at', $finishedAt);
save_setting('broker_history_pid', '');
save_setting('broker_history_current_range', '');
save_setting('broker_history_cancel_requested', '0');
