<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/BrokerRepository.php';

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$filters = [
    'period' => trim((string) ($_GET['period'] ?? (string) setting('period', ''))),
    'from' => trim((string) ($_GET['from'] ?? (string) setting('date_from', ''))),
    'to' => trim((string) ($_GET['to'] ?? (string) setting('date_to', ''))),
    'transaction_type' => trim((string) ($_GET['transaction_type'] ?? (string) setting('transaction_type', 'TRANSACTION_TYPE_NET'))),
    'market_board' => trim((string) ($_GET['market_board'] ?? (string) setting('market_board', 'MARKET_BOARD_REGULER'))),
    'investor_type' => trim((string) ($_GET['investor_type'] ?? (string) setting('investor_type', 'INVESTOR_TYPE_ALL'))),
];

if ($symbol === '') {
    json_response([
        'ok' => false,
        'message' => 'Symbol wajib diisi.',
    ], 422);
}

$client = new StockbitClient((string) setting('stockbit_token', ''));
$repo = new BrokerRepository();

if (!$client->hasToken()) {
    json_response([
        'ok' => false,
        'message' => 'Token belum siap. Jalankan bookmarklet impor token dari Stockbit.',
    ], 422);
}

try {
    $payload = $client->fetchSymbol($symbol, $filters);
    $repo->save($symbol, $payload);

    json_response([
        'ok' => true,
        'message' => 'Data berhasil diambil.',
        'item' => [
            'symbol' => $symbol,
            'updated_at' => gmdate(DATE_ATOM),
            'payload' => $payload,
        ],
    ]);
} catch (Throwable $throwable) {
    json_response([
        'ok' => false,
        'message' => $throwable->getMessage(),
    ], 500);
}
