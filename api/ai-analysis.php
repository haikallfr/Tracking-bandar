<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/AiAnalysisService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'high')));
$force = ((string) ($_GET['force'] ?? '0')) === '1';

if ($symbol === '') {
    json_response(['ok' => false, 'message' => 'Symbol wajib diisi.'], 422);
}

try {
    $service = new AiAnalysisService();
    $analysis = $service->analyze($symbol, $mode, $force);

    json_response([
        'ok' => true,
        'configured' => $service->configured(),
        'symbol' => $symbol,
        'mode' => $mode,
        'analysis' => $analysis,
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
