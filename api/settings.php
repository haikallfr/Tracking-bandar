<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'token_configured' => setting('stockbit_token', '') !== '',
        'token_imported_at' => (string) setting('stockbit_imported_at', ''),
        'watchlist' => json_decode((string) setting('watchlist', '[]'), true) ?? [],
        'auto_refresh_minutes' => (int) setting('auto_refresh_minutes', '15'),
        'period' => (string) setting('period', ''),
        'date_from' => (string) setting('date_from', ''),
        'date_to' => (string) setting('date_to', ''),
        'transaction_type' => (string) setting('transaction_type', 'TRANSACTION_TYPE_NET'),
        'market_board' => (string) setting('market_board', 'MARKET_BOARD_REGULER'),
        'investor_type' => (string) setting('investor_type', 'INVESTOR_TYPE_ALL'),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$data = request_json();
$watchlist = normalize_symbols((string) ($data['watchlist'] ?? ''));
$minutes = max(5, min(240, (int) ($data['auto_refresh_minutes'] ?? 15)));
$period = trim((string) ($data['period'] ?? ''));
$dateFrom = trim((string) ($data['date_from'] ?? ''));
$dateTo = trim((string) ($data['date_to'] ?? ''));
$transactionType = trim((string) ($data['transaction_type'] ?? 'TRANSACTION_TYPE_NET'));
$marketBoard = trim((string) ($data['market_board'] ?? 'MARKET_BOARD_REGULER'));
$investorType = trim((string) ($data['investor_type'] ?? 'INVESTOR_TYPE_ALL'));
$token = trim((string) ($data['stockbit_token'] ?? ''));

save_setting('watchlist', json_encode($watchlist, JSON_THROW_ON_ERROR));
save_setting('auto_refresh_minutes', (string) $minutes);
save_setting('period', $period);
save_setting('date_from', $dateFrom);
save_setting('date_to', $dateTo);
save_setting('transaction_type', $transactionType);
save_setting('market_board', $marketBoard);
save_setting('investor_type', $investorType);

if ($token !== '') {
    save_setting('stockbit_token', $token);
    save_setting('stockbit_imported_at', gmdate(DATE_ATOM));
}

json_response([
    'ok' => true,
    'message' => 'Pengaturan tersimpan.',
    'token_configured' => setting('stockbit_token', '') !== '',
]);
