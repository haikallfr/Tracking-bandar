<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/KseiRadar.php';

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$search = trim((string) ($_GET['q'] ?? ''));
$tier = trim((string) ($_GET['tier'] ?? 'all'));

try {
    json_response([
        'ok' => true,
        'result' => (new KseiRadar())->dashboard($symbol, $search, $tier),
    ]);
} catch (Throwable $throwable) {
    json_response([
        'ok' => false,
        'message' => $throwable->getMessage(),
    ], 500);
}
