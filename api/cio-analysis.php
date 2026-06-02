<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CioSwingAnalyst.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$sector = trim((string) ($_GET['sector'] ?? ''));

if ($symbol === '') {
    json_response(['ok' => false, 'message' => 'Symbol wajib diisi.'], 422);
}

try {
    $analysis = (new CioSwingAnalyst())->analyze($symbol, $sector);

    json_response([
        'ok' => true,
        'symbol' => $symbol,
        'sector' => $analysis['sector'] ?? $sector,
        'analysis' => $analysis,
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
