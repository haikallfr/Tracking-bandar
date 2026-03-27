<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/StockbitClient.php';
require_once __DIR__ . '/../src/RadarBandar.php';
require_once __DIR__ . '/../src/BrokerRepository.php';
require_once __DIR__ . '/../src/HistoricalRepository.php';
require_once __DIR__ . '/../src/CandidateEnricher.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'high')));

if ($symbol === '') {
    json_response(['ok' => false, 'message' => 'Symbol wajib diisi.'], 422);
}

if (!in_array($mode, ['high', 'basic'], true)) {
    json_response(['ok' => false, 'message' => 'Mode tidak dikenali.'], 422);
}

$token = (string) setting('stockbit_token', '');
if ($token === '') {
    json_response(['ok' => false, 'message' => 'Token Stockbit belum siap.'], 422);
}

$filters = [
    'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
    'transaction_type' => 'TRANSACTION_TYPE_NET',
    'market_board' => 'MARKET_BOARD_ALL',
    'investor_type' => 'INVESTOR_TYPE_ALL',
];

$client = new StockbitClient($token);
$radar = new RadarBandar(new BrokerRepository());

try {
    $payload = $client->fetchSymbol($symbol, $filters);
    $updatedAt = gmdate(DATE_ATOM);

    if ($mode === 'basic') {
        $item = $radar->evaluateBasic($symbol, $payload, $updatedAt);
    } else {
        $item = $radar->evaluate($symbol, $payload, $updatedAt);
        if ($item !== null) {
            $enricher = new CandidateEnricher($client, new HistoricalRepository());
            $enrichment = $enricher->enrich($symbol, 12);
            if ($enrichment !== []) {
                $item = $radar->refine($item, $enrichment);
            }
        }
    }

    json_response([
        'ok' => true,
        'symbol' => $symbol,
        'mode' => $mode,
        'filters' => $filters,
        'item' => $item,
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
