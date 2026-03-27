<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

function radar_basic_payload(): array
{
    $status = (string) setting('radar_basic_status', 'idle');
    $pid = (int) setting('radar_basic_pid', '0');
    if ($status === 'running' && $pid > 0 && !process_alive($pid)) {
        $status = 'idle';
        save_setting('radar_basic_status', 'idle');
        save_setting('radar_basic_pid', '');
    }

    $meta = json_decode((string) setting('radar_basic_meta', '{}'), true);
    $meta = is_array($meta) ? $meta : [];

    return [
        'ok' => true,
        'token_configured' => setting('stockbit_token', '') !== '',
        'radar' => [
            'generated_at' => (string) setting('radar_basic_finished_at', ''),
            'count' => count(json_decode((string) setting('radar_basic_results', '[]'), true) ?: []),
            'items' => json_decode((string) setting('radar_basic_results', '[]'), true) ?: [],
        ],
        'meta' => [
            'status' => $status,
            'cancel_requested' => setting('radar_basic_cancel_requested', '0') === '1',
            'started_at' => (string) setting('radar_basic_started_at', ''),
            'finished_at' => (string) setting('radar_basic_finished_at', ''),
            'current_symbol' => (string) setting('radar_basic_current_symbol', ''),
            'scanned' => (int) setting('radar_basic_scanned', '0'),
            'total' => (int) setting('radar_basic_total', '0'),
            'threshold' => $meta['threshold'] ?? 87,
            'formula' => $meta['formula'] ?? [],
            'filters' => $meta['filters'] ?? [],
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(radar_basic_payload());
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

$currentStatus = (string) setting('radar_basic_status', 'idle');
$currentPid = (int) setting('radar_basic_pid', '0');

if ($action === 'cancel') {
    save_setting('radar_basic_cancel_requested', '1');

    if ($currentStatus === 'running' && $currentPid > 0) {
        terminate_process($currentPid);
        save_setting('radar_basic_status', 'idle');
        save_setting('radar_basic_pid', '');
        save_setting('radar_basic_current_symbol', '');
    }

    json_response([
        'ok' => true,
        'message' => 'Permintaan cancel radar dasar full market dikirim.',
    ] + radar_basic_payload());
}

if ($currentStatus === 'running' && $currentPid > 0 && process_alive($currentPid)) {
    json_response([
        'ok' => true,
        'message' => 'Radar dasar full market sedang berjalan.',
    ] + radar_basic_payload());
}

$workerScript = realpath(__DIR__ . '/../workers/radar-basic-worker.php');
if ($workerScript === false) {
    json_response(['ok' => false, 'message' => 'Worker radar dasar tidak ditemukan.'], 500);
}

$logPath = STORAGE_DIR . '/radar-basic-worker.log';
$command = sprintf(
    'php %s > %s 2>&1 & echo $!',
    escapeshellarg($workerScript),
    escapeshellarg($logPath)
);
$pid = trim((string) shell_exec($command));

if ($pid === '' || !ctype_digit($pid)) {
    json_response(['ok' => false, 'message' => 'Gagal memulai radar dasar full market.'], 500);
}

save_setting('radar_basic_status', 'running');
save_setting('radar_basic_pid', $pid);
save_setting('radar_basic_cancel_requested', '0');

json_response([
    'ok' => true,
    'message' => 'Radar dasar full market dimulai di background.',
] + radar_basic_payload());
