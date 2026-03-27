<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/BrokerRepository.php';

$repo = new BrokerRepository();

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
    'last_updated' => $repo->updatedAt(),
    'items' => $repo->all(),
]);
