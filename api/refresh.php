<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/BrokerRepository.php';

$watchlist = json_decode((string) setting('watchlist', '[]'), true) ?? [];
$filters = [
    'period' => (string) setting('period', ''),
    'from' => (string) setting('date_from', ''),
    'to' => (string) setting('date_to', ''),
    'transaction_type' => (string) setting('transaction_type', 'TRANSACTION_TYPE_NET'),
    'market_board' => (string) setting('market_board', 'MARKET_BOARD_REGULER'),
    'investor_type' => (string) setting('investor_type', 'INVESTOR_TYPE_ALL'),
];
$client = new StockbitClient((string) setting('stockbit_token', ''));
$repo = new BrokerRepository();

if (!$client->hasToken()) {
    json_response([
        'ok' => false,
        'message' => 'Token Stockbit belum siap. Jalankan bookmarklet impor token dulu.',
    ], 422);
}

if ($watchlist === []) {
    json_response([
        'ok' => false,
        'message' => 'Watchlist kosong. Gunakan pencarian simbol di dashboard atau isi watchlist bila ingin refresh massal.',
    ], 422);
}

$results = [];
$errors = [];

foreach ($watchlist as $symbol) {
    try {
        $payload = $client->fetchSymbol((string) $symbol, $filters);
        $repo->save((string) $symbol, $payload);
        $results[] = [
            'symbol' => $symbol,
            'ok' => true,
        ];
    } catch (Throwable $throwable) {
        $errors[] = [
            'symbol' => $symbol,
            'message' => $throwable->getMessage(),
        ];
    }
}

json_response([
    'ok' => $errors === [],
    'message' => $errors === [] ? 'Refresh selesai.' : 'Sebagian data gagal diperbarui.',
    'results' => $results,
    'errors' => $errors,
]);
