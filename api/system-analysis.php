<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/SystemAnalyst.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'high')));

if ($symbol === '') {
    json_response(['ok' => false, 'message' => 'Symbol wajib diisi.'], 422);
}

try {
    $service = new SystemAnalyst();
    $analysis = $service->analyze($symbol, $mode);

    json_response([
        'ok' => true,
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
