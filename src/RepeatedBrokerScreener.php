<?php

declare(strict_types=1);

require_once __DIR__ . '/StockbitClient.php';
require_once __DIR__ . '/SymbolUniverse.php';

final class RepeatedBrokerScreener
{
    public function __construct(
        private readonly StockbitClient $client,
        private readonly SymbolUniverse $universe,
    ) {
    }

    public function run(): array
    {
        if (!$this->client->hasToken()) {
            throw new RuntimeException('Token Stockbit belum siap. Jalankan impor token dulu.');
        }

        $symbols = $this->universe->all();
        $filters = [
            'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board' => 'MARKET_BOARD_ALL',
            'investor_type' => 'INVESTOR_TYPE_ALL',
        ];

        $hits = [];
        $errors = [];

        foreach ($symbols as $symbol) {
            try {
                $payload = $this->client->fetchSymbol($symbol, $filters);
                $summary = $payload['market_detector']['data']['broker_summary'] ?? [];
                $buyHits = $this->extractHits($summary['brokers_buy'] ?? [], 'buy');
                $sellHits = $this->extractHits($summary['brokers_sell'] ?? [], 'sell');

                if ($buyHits === [] && $sellHits === []) {
                    continue;
                }

                $hits[] = [
                    'symbol' => $symbol,
                    'from' => $payload['market_detector']['data']['from'] ?? null,
                    'to' => $payload['market_detector']['data']['to'] ?? null,
                    'buy_hits' => $buyHits,
                    'sell_hits' => $sellHits,
                    'buy_repeat_total' => array_sum(array_column($buyHits, 'freq')),
                    'sell_repeat_total' => array_sum(array_column($sellHits, 'freq')),
                ];
            } catch (Throwable $throwable) {
                $errors[] = [
                    'symbol' => $symbol,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        usort($hits, static function (array $left, array $right): int {
            $leftScore = ($left['buy_repeat_total'] ?? 0) + ($left['sell_repeat_total'] ?? 0);
            $rightScore = ($right['buy_repeat_total'] ?? 0) + ($right['sell_repeat_total'] ?? 0);

            return $rightScore <=> $leftScore ?: strcmp($left['symbol'], $right['symbol']);
        });

        return [
            'created_at' => gmdate(DATE_ATOM),
            'filters' => $filters,
            'summary' => [
                'scanned' => count($symbols),
                'matched' => count($hits),
                'errors' => count($errors),
            ],
            'hits' => $hits,
            'errors' => $errors,
        ];
    }

    public function filters(): array
    {
        return [
            'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board' => 'MARKET_BOARD_ALL',
            'investor_type' => 'INVESTOR_TYPE_ALL',
        ];
    }

    public function symbolUniverse(): array
    {
        return $this->universe->all();
    }

    public function screenSymbol(string $symbol, array $filters): array
    {
        $payload = $this->client->fetchSymbol($symbol, $filters);
        $summary = $payload['market_detector']['data']['broker_summary'] ?? [];
        $buyHits = $this->extractHits($summary['brokers_buy'] ?? [], 'buy');
        $sellHits = $this->extractHits($summary['brokers_sell'] ?? [], 'sell');

        return [
            'symbol' => $symbol,
            'from' => $payload['market_detector']['data']['from'] ?? null,
            'to' => $payload['market_detector']['data']['to'] ?? null,
            'buy_hits' => $buyHits,
            'sell_hits' => $sellHits,
            'buy_repeat_total' => array_sum(array_column($buyHits, 'freq')),
            'sell_repeat_total' => array_sum(array_column($sellHits, 'freq')),
        ];
    }

    private function extractHits(array $rows, string $side): array
    {
        $hits = [];

        foreach ($rows as $row) {
            $freq = (int) ($row['freq'] ?? 0);
            if ($freq < 2) {
                continue;
            }

            $hits[] = [
                'broker_code' => (string) ($row['netbs_broker_code'] ?? ''),
                'broker_type' => (string) ($row['type'] ?? ''),
                'freq' => $freq,
                'value' => abs((float) ($side === 'buy' ? ($row['bval'] ?? 0) : ($row['sval'] ?? 0))),
                'lot' => abs((float) ($side === 'buy' ? ($row['blot'] ?? 0) : ($row['slot'] ?? 0))),
                'avg_price' => (float) ($side === 'buy' ? ($row['netbs_buy_avg_price'] ?? 0) : ($row['netbs_sell_avg_price'] ?? 0)),
            ];
        }

        usort($hits, static function (array $left, array $right): int {
            return ($right['freq'] <=> $left['freq'])
                ?: ($right['value'] <=> $left['value'])
                ?: strcmp($left['broker_code'], $right['broker_code']);
        });

        return $hits;
    }
}
