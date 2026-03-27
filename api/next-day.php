<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/NextDayFilter.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/RadarBandar.php';
require_once __DIR__ . '/../src/BrokerRepository.php';
require_once __DIR__ . '/../src/HistoricalRepository.php';
require_once __DIR__ . '/../src/CandidateEnricher.php';
require_once __DIR__ . '/../src/NextDayHistoryRepository.php';

function next_day_payload(): array
{
    $status = (string) setting('next_day_status', 'idle');
    $pid = (int) setting('next_day_pid', '0');
    if ($status === 'running' && $pid > 0 && !process_alive($pid)) {
        $status = 'idle';
        save_setting('next_day_status', 'idle');
        save_setting('next_day_pid', '');
    }

    $meta = json_decode((string) setting('next_day_meta', '{}'), true);
    $meta = is_array($meta) ? $meta : [];
    $items = json_decode((string) setting('next_day_results', '[]'), true);
    $items = is_array($items) ? $items : [];

    return [
        'ok' => true,
        'token_configured' => setting('stockbit_token', '') !== '',
        'source' => [
            'generated_at' => (string) setting('next_day_finished_at', ''),
            'count' => count($items),
        ],
        'dataset' => [
            'latest_file' => (string) setting('next_day_dataset_latest_file', ''),
            'generated_at' => (string) setting('next_day_dataset_latest_generated_at', ''),
            'count' => (int) setting('next_day_dataset_latest_count', '0'),
        ],
        'rules' => $meta['rules'] ?? NextDayFilter::rules(),
        'radar' => [
            'generated_at' => (string) setting('next_day_finished_at', ''),
            'count' => count($items),
            'items' => $items,
        ],
        'meta' => [
            'status' => $status,
            'cancel_requested' => setting('next_day_cancel_requested', '0') === '1',
            'started_at' => (string) setting('next_day_started_at', ''),
            'finished_at' => (string) setting('next_day_finished_at', ''),
            'current_symbol' => (string) setting('next_day_current_symbol', ''),
            'summary' => [
                'scanned' => (int) setting('next_day_scanned', '0'),
                'matched' => (int) setting('next_day_matched', '0'),
                'errors' => (int) setting('next_day_errors', '0'),
            ],
            'error_log' => json_decode((string) setting('next_day_error_log', '[]'), true) ?: [],
            'total' => (int) setting('next_day_total', '0'),
            'filters' => $meta['filters'] ?? [],
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['symbol'])) {
    $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
    if ($symbol === '') {
        json_response(['ok' => false, 'message' => 'Simbol wajib diisi.'], 422);
    }

    if ((string) setting('stockbit_token', '') === '') {
        json_response(['ok' => false, 'message' => 'Token Stockbit belum siap.'], 422);
    }

    $client = new StockbitClient((string) setting('stockbit_token', ''));
    $radar = new RadarBandar(new BrokerRepository());
    $enricher = new CandidateEnricher($client, new HistoricalRepository());
    $history = new NextDayHistoryRepository();
    $filters = [
        'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
        'transaction_type' => 'TRANSACTION_TYPE_NET',
        'market_board' => 'MARKET_BOARD_ALL',
        'investor_type' => 'INVESTOR_TYPE_ALL',
    ];

    try {
        $payload = $client->fetchSymbol($symbol, $filters);
        $item = $radar->evaluate($symbol, $payload, gmdate(DATE_ATOM));
        if ($item === null) {
            json_response(['ok' => false, 'message' => 'Data simbol tidak cukup untuk dianalisis.'], 404);
        }

        $enrichment = $enricher->enrich($symbol, 12);
        if ($enrichment !== []) {
            $item = $radar->refine($item, $enrichment);
        }

        $passed = NextDayFilter::passes($item);
        if ($passed) {
            $item['next_day_reasons'] = NextDayFilter::reasons($item);
        } else {
            $item['next_day_failures'] = NextDayFilter::failures($item);
        }

        $history->save($symbol, $passed, (float) ($item['score'] ?? 0), [
            'symbol' => $symbol,
            'passed' => $passed,
            'rules' => NextDayFilter::rules(),
            'item' => $item,
        ]);

        json_response([
            'ok' => true,
            'mode' => 'single',
            'symbol' => $symbol,
            'passed' => $passed,
            'history_saved' => true,
            'history_count' => $history->count(),
            'rules' => NextDayFilter::rules(),
            'item' => $item,
        ]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(next_day_payload());
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$body = request_json();
$action = (string) ($body['action'] ?? 'start');

if (!in_array($action, ['start', 'cancel'], true)) {
    json_response(['ok' => false, 'message' => 'Action tidak dikenali.'], 422);
}

if ((string) setting('stockbit_token', '') === '') {
    json_response(['ok' => false, 'message' => 'Token Stockbit belum siap.'], 422);
}

$currentStatus = (string) setting('next_day_status', 'idle');
$currentPid = (int) setting('next_day_pid', '0');

if ($action === 'cancel') {
    save_setting('next_day_cancel_requested', '1');

    if ($currentStatus === 'running' && $currentPid > 0) {
        terminate_process($currentPid);
        save_setting('next_day_status', 'idle');
        save_setting('next_day_pid', '');
        save_setting('next_day_current_symbol', '');
    }

    json_response([
        'ok' => true,
        'message' => 'Permintaan cancel peluang besok dikirim.',
    ] + next_day_payload());
}

if ($currentStatus === 'running' && $currentPid > 0 && process_alive($currentPid)) {
    json_response([
        'ok' => true,
        'message' => 'Scanner peluang besok sedang berjalan.',
    ] + next_day_payload());
}

$workerScript = realpath(__DIR__ . '/../workers/next-day-worker.php');
if ($workerScript === false) {
    json_response(['ok' => false, 'message' => 'Worker peluang besok tidak ditemukan.'], 500);
}

$logPath = STORAGE_DIR . '/next-day-worker.log';
$command = sprintf(
    'php %s > %s 2>&1 & echo $!',
    escapeshellarg($workerScript),
    escapeshellarg($logPath)
);
$pid = trim((string) shell_exec($command));

if ($pid === '' || !ctype_digit($pid)) {
    json_response(['ok' => false, 'message' => 'Gagal memulai scanner peluang besok.'], 500);
}

save_setting('next_day_status', 'running');
save_setting('next_day_pid', $pid);
save_setting('next_day_cancel_requested', '0');

json_response([
    'ok' => true,
    'message' => 'Scanner peluang besok dimulai di background.',
] + next_day_payload());
