<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/RadarBandar.php';
require_once __DIR__ . '/../src/BrokerRepository.php';

function radar_payload(): array
{
    $status = (string) setting('radar_full_status', 'idle');
    $pid = (int) setting('radar_full_pid', '0');
    if ($status === 'running' && $pid > 0 && !process_alive($pid)) {
        $status = 'idle';
        save_setting('radar_full_status', 'idle');
        save_setting('radar_full_pid', '');
    }

    $meta = json_decode((string) setting('radar_full_meta', '{}'), true);
    $meta = is_array($meta) ? $meta : [];

    return [
        'ok' => true,
        'token_configured' => setting('stockbit_token', '') !== '',
        'radar' => [
            'generated_at' => (string) setting('radar_full_finished_at', ''),
            'count' => count(json_decode((string) setting('radar_full_results', '[]'), true) ?: []),
            'items' => json_decode((string) setting('radar_full_results', '[]'), true) ?: [],
        ],
        'meta' => [
            'status' => $status,
            'cancel_requested' => setting('radar_full_cancel_requested', '0') === '1',
            'started_at' => (string) setting('radar_full_started_at', ''),
            'finished_at' => (string) setting('radar_full_finished_at', ''),
            'current_symbol' => (string) setting('radar_full_current_symbol', ''),
            'summary' => [
                'scanned' => (int) setting('radar_full_scanned', '0'),
                'matched' => (int) setting('radar_full_matched', '0'),
                'errors' => (int) setting('radar_full_errors', '0'),
            ],
            'error_log' => json_decode((string) setting('radar_full_error_log', '[]'), true) ?: [],
            'total' => (int) setting('radar_full_total', '0'),
            'threshold' => $meta['threshold'] ?? RadarBandar::FULL_MARKET_FILTER_SCORE,
            'filters' => $meta['filters'] ?? [],
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(radar_payload());
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

$currentStatus = (string) setting('radar_full_status', 'idle');
$currentPid = (int) setting('radar_full_pid', '0');

if ($action === 'cancel') {
    save_setting('radar_full_cancel_requested', '1');

    if ($currentStatus === 'running' && $currentPid > 0) {
        terminate_process($currentPid);
        save_setting('radar_full_status', 'idle');
        save_setting('radar_full_pid', '');
        save_setting('radar_full_current_symbol', '');
    }

    json_response([
        'ok' => true,
        'message' => 'Permintaan cancel radar full market dikirim.',
    ] + radar_payload());
}

if ($currentStatus === 'running' && $currentPid > 0 && process_alive($currentPid)) {
    json_response([
        'ok' => true,
        'message' => 'Radar full market sedang berjalan.',
    ] + radar_payload());
}

$workerScript = realpath(__DIR__ . '/../workers/radar-full-worker.php');
if ($workerScript === false) {
    json_response(['ok' => false, 'message' => 'Worker radar tidak ditemukan.'], 500);
}

$logPath = STORAGE_DIR . '/radar-full-worker.log';
$command = sprintf(
    'php %s > %s 2>&1 & echo $!',
    escapeshellarg($workerScript),
    escapeshellarg($logPath)
);
$pid = trim((string) shell_exec($command));

if ($pid === '' || !ctype_digit($pid)) {
    json_response(['ok' => false, 'message' => 'Gagal memulai radar full market.'], 500);
}

save_setting('radar_full_status', 'running');
save_setting('radar_full_pid', $pid);
save_setting('radar_full_cancel_requested', '0');

json_response([
    'ok' => true,
    'message' => 'Radar full market dimulai di background.',
] + radar_payload());
