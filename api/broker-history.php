<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/BrokerHistoricalRepository.php';

function broker_history_payload(string $symbol, string $brokerCode): array
{
    $status = (string) setting('broker_history_status', 'idle');
    $pid = (int) setting('broker_history_pid', '0');
    if ($status === 'running' && $pid > 0 && !process_alive($pid)) {
        $status = 'idle';
        save_setting('broker_history_status', 'idle');
        save_setting('broker_history_pid', '');
    }

    $repository = new BrokerHistoricalRepository();
    $result = $repository->find($symbol, $brokerCode);
    $meta = json_decode((string) setting('broker_history_meta', '{}'), true);
    $meta = is_array($meta) ? $meta : [];

    $activeSymbol = strtoupper((string) setting('broker_history_symbol', ''));
    $activeBroker = strtoupper((string) setting('broker_history_broker_code', ''));

    return [
        'ok' => true,
        'symbol' => strtoupper($symbol),
        'broker_code' => strtoupper($brokerCode),
        'result' => $result,
        'task' => [
            'status' => ($activeSymbol === strtoupper($symbol) && $activeBroker === strtoupper($brokerCode)) ? $status : 'idle',
            'started_at' => (string) setting('broker_history_started_at', ''),
            'finished_at' => (string) setting('broker_history_finished_at', ''),
            'current_range' => (string) setting('broker_history_current_range', ''),
            'scanned' => (int) setting('broker_history_scanned', '0'),
            'total' => (int) setting('broker_history_total', '0'),
            'errors' => (int) setting('broker_history_errors', '0'),
            'error_log' => json_decode((string) setting('broker_history_error_log', '[]'), true) ?: [],
            'meta' => $meta,
        ],
    ];
}

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$brokerCode = strtoupper(trim((string) ($_GET['broker_code'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($symbol === '' || $brokerCode === '') {
        json_response(['ok' => false, 'message' => 'Symbol dan broker code wajib diisi.'], 422);
    }

    json_response(broker_history_payload($symbol, $brokerCode));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$body = request_json();
$action = (string) ($body['action'] ?? 'start');
$symbol = strtoupper(trim((string) ($body['symbol'] ?? '')));
$brokerCode = strtoupper(trim((string) ($body['broker_code'] ?? '')));

if ($symbol === '' || $brokerCode === '') {
    json_response(['ok' => false, 'message' => 'Symbol dan broker code wajib diisi.'], 422);
}

if ((string) setting('stockbit_token', '') === '') {
    json_response(['ok' => false, 'message' => 'Token Stockbit belum siap.'], 422);
}

$currentStatus = (string) setting('broker_history_status', 'idle');
$currentPid = (int) setting('broker_history_pid', '0');

if ($action === 'cancel') {
    save_setting('broker_history_cancel_requested', '1');

    if ($currentStatus === 'running' && $currentPid > 0) {
        terminate_process($currentPid);
        save_setting('broker_history_status', 'idle');
        save_setting('broker_history_pid', '');
        save_setting('broker_history_current_range', '');
    }

    json_response([
        'ok' => true,
        'message' => 'Scan histori sekuritas dibatalkan.',
    ] + broker_history_payload($symbol, $brokerCode));
}

if ($currentStatus === 'running' && $currentPid > 0 && process_alive($currentPid)) {
    json_response([
        'ok' => true,
        'message' => 'Scan histori sekuritas sedang berjalan.',
    ] + broker_history_payload($symbol, $brokerCode));
}

$workerScript = realpath(__DIR__ . '/../workers/broker-history-worker.php');
if ($workerScript === false) {
    json_response(['ok' => false, 'message' => 'Worker histori sekuritas tidak ditemukan.'], 500);
}

$logPath = STORAGE_DIR . '/broker-history-worker.log';
$command = sprintf(
    'php %s %s %s > %s 2>&1 & echo $!',
    escapeshellarg($workerScript),
    escapeshellarg($symbol),
    escapeshellarg($brokerCode),
    escapeshellarg($logPath)
);
$pid = trim((string) shell_exec($command));

if ($pid === '' || !ctype_digit($pid)) {
    json_response(['ok' => false, 'message' => 'Gagal memulai scan histori sekuritas.'], 500);
}

save_setting('broker_history_status', 'running');
save_setting('broker_history_pid', $pid);
save_setting('broker_history_symbol', $symbol);
save_setting('broker_history_broker_code', $brokerCode);
save_setting('broker_history_cancel_requested', '0');

json_response([
    'ok' => true,
    'message' => 'Scan histori sekuritas dimulai di background.',
] + broker_history_payload($symbol, $brokerCode));
