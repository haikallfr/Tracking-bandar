<?php

declare(strict_types=1);

require_once __DIR__ . '/StockbitClient.php';
require_once __DIR__ . '/HistoricalRepository.php';

final class CandidateEnricher
{
    public function __construct(
        private readonly StockbitClient $client,
        private readonly HistoricalRepository $repository,
    ) {
    }

    public function enrich(string $symbol, int $targetDays = 12): array
    {
        $this->collectDailySnapshots($symbol, $targetDays);
        $this->collectPriceFeed($symbol);

        return $this->summarize($symbol, $targetDays);
    }

    private function collectDailySnapshots(string $symbol, int $targetDays): void
    {
        $filters = [
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board' => 'MARKET_BOARD_ALL',
            'investor_type' => 'INVESTOR_TYPE_ALL',
        ];

        $collected = 0;
        $cursor = new DateTimeImmutable('today', new DateTimeZone('Asia/Makassar'));

        while ($collected < $targetDays) {
            $cursor = $cursor->modify('-1 day');
            $weekday = (int) $cursor->format('N');
            if ($weekday > 5) {
                continue;
            }

            $date = $cursor->format('Y-m-d');

            try {
                $payload = $this->client->fetchSymbol($symbol, $filters + ['from' => $date, 'to' => $date]);
                $data = $payload['market_detector']['data'] ?? [];
                $marketValue = abs((float) ($data['bandar_detector']['value'] ?? 0));
                $marketVolume = abs((float) ($data['bandar_detector']['volume'] ?? 0));
                $summary = $data['broker_summary'] ?? [];
                $hasActivity = !empty($summary['brokers_buy']) || !empty($summary['brokers_sell']);

                if ($marketValue <= 0 && !$hasActivity) {
                    continue;
                }

                $this->repository->saveDailySnapshot($symbol, $date, $payload, $marketValue, $marketVolume);
                $collected++;
            } catch (Throwable) {
                continue;
            }
        }
    }

    private function collectPriceFeed(string $symbol): void
    {
        try {
            $payload = $this->client->fetchCloseFeed($symbol, '1d');
            $this->repository->savePriceFeed($symbol, '1d', $payload);
        } catch (Throwable) {
        }
    }

    private function summarize(string $symbol, int $targetDays): array
    {
        $rows = $this->repository->dailySnapshots($symbol, $targetDays);
        if ($rows === []) {
            return [];
        }

        $buyerCounts = [];
        $cleanBuyerDays = 0;
        $buyDominanceDays = 0;
        $topBuyerValueTotal = 0.0;
        $accDays = 0;
        $bigAccDays = 0;
        $distDays = 0;
        $prices = [];
        $marketValues = [];

        $chronologicalRows = array_reverse($rows);

        foreach ($chronologicalRows as $row) {
            $data = $row['payload']['market_detector']['data'] ?? [];
            $summary = $data['broker_summary'] ?? [];
            $buys = is_array($summary['brokers_buy'] ?? null) ? $summary['brokers_buy'] : [];
            $sells = is_array($summary['brokers_sell'] ?? null) ? $summary['brokers_sell'] : [];

            $topBuyer = $buys[0]['netbs_broker_code'] ?? '';
            if ($topBuyer !== '') {
                $buyerCounts[$topBuyer] = ($buyerCounts[$topBuyer] ?? 0) + 1;
            }

            $topBuy3 = $this->sumField(array_slice($buys, 0, 3), 'bval');
            $topSell3 = $this->sumField(array_slice($sells, 0, 3), 'sval');
            $topBuyerValue = $this->sumField(array_slice($buys, 0, 1), 'bval');
            $marketValue = max(1.0, abs((float) ($data['bandar_detector']['value'] ?? 0)));

            if ($topBuy3 > $topSell3) {
                $cleanBuyerDays++;
            }

            if (($topBuyerValue / $marketValue) >= 0.2) {
                $buyDominanceDays++;
            }

            $topBuyerValueTotal += $topBuyerValue;

            $accdist = strtolower((string) ($data['bandar_detector']['avg']['accdist'] ?? ''));
            if (str_contains($accdist, 'big acc')) {
                $bigAccDays++;
                $accDays++;
            } elseif (str_contains($accdist, 'acc')) {
                $accDays++;
            }

            if (str_contains($accdist, 'dist')) {
                $distDays++;
            }

            $prices[] = (float) ($data['bandar_detector']['average'] ?? 0);
            $marketValues[] = (float) ($row['market_value'] ?? 0);
        }

        arsort($buyerCounts);
        $repeatBroker = (string) array_key_first($buyerCounts);
        $repeatDays = (int) ($buyerCounts[$repeatBroker] ?? 0);
        $priceFeed = $this->repository->priceFeed($symbol, '1d');
        $priceSeries = is_array($priceFeed['payload']['data'][0]['prices'] ?? null)
            ? array_map('floatval', $priceFeed['payload']['data'][0]['prices'])
            : [];
        $pricePoints = count($priceSeries);
        $intradayLow = $priceSeries !== [] ? min($priceSeries) : 0.0;
        $intradayHigh = $priceSeries !== [] ? max($priceSeries) : 0.0;
        $intradayClose = $priceSeries !== [] ? $priceSeries[count($priceSeries) - 1] : 0.0;
        $intradayOpen = $priceSeries !== [] ? $priceSeries[0] : 0.0;
        $tailSeries = count($priceSeries) >= 30 ? array_slice($priceSeries, -30) : $priceSeries;
        $tailAverage = $tailSeries !== [] ? array_sum($tailSeries) / count($tailSeries) : 0.0;
        $tailLow = $tailSeries !== [] ? min($tailSeries) : 0.0;
        $tailHigh = $tailSeries !== [] ? max($tailSeries) : 0.0;

        $firstPrice = (float) ($prices[0] ?? 0);
        $lastPrice = (float) ($prices[count($prices) - 1] ?? 0);
        $priorPrices = count($prices) > 1 ? array_slice($prices, 0, -1) : $prices;
        $priorHigh = $priorPrices !== [] ? max($priorPrices) : $lastPrice;
        $priorAverage = $priorPrices !== [] ? array_sum($priorPrices) / count($priorPrices) : $lastPrice;
        $latestTurnover = (float) ($marketValues[count($marketValues) - 1] ?? 0);
        $priorTurnoverAverage = count($marketValues) > 1
            ? array_sum(array_slice($marketValues, 0, -1)) / count(array_slice($marketValues, 0, -1))
            : $latestTurnover;
        $priceChangePct = $firstPrice > 0 ? (($lastPrice - $firstPrice) / $firstPrice) * 100 : 0.0;
        $turnoverAcceleration = $priorTurnoverAverage > 0 ? $latestTurnover / $priorTurnoverAverage : 0.0;
        $breakoutPct = $priorHigh > 0 ? (($lastPrice - $priorHigh) / $priorHigh) * 100 : 0.0;
        $extensionPct = $priorAverage > 0 ? (($lastPrice - $priorAverage) / $priorAverage) * 100 : 0.0;

        return [
            'history_days' => count($rows),
            'repeat_broker_code' => $repeatBroker,
            'repeat_broker_days' => $repeatDays,
            'clean_buyer_days' => $cleanBuyerDays,
            'buy_dominance_days' => $buyDominanceDays,
            'acc_days' => $accDays,
            'big_acc_days' => $bigAccDays,
            'dist_days' => $distDays,
            'price_points' => $pricePoints,
            'top_buyer_value_total' => $topBuyerValueTotal,
            'price_change_pct' => round($priceChangePct, 2),
            'turnover_acceleration' => round($turnoverAcceleration, 2),
            'breakout_pct' => round($breakoutPct, 2),
            'extension_pct' => round($extensionPct, 2),
            'latest_avg_price' => round($lastPrice, 2),
            'intraday_range_pct' => ($intradayLow > 0 && $intradayHigh > 0)
                ? round((($intradayHigh - $intradayLow) / $intradayLow) * 100, 2)
                : 0.0,
            'intraday_close_vs_open_pct' => $intradayOpen > 0
                ? round((($intradayClose - $intradayOpen) / $intradayOpen) * 100, 2)
                : 0.0,
            'intraday_close_vs_tail_avg_pct' => $tailAverage > 0
                ? round((($intradayClose - $tailAverage) / $tailAverage) * 100, 2)
                : 0.0,
            'intraday_tail_compression_pct' => ($tailLow > 0 && $tailHigh > 0)
                ? round((($tailHigh - $tailLow) / $tailLow) * 100, 2)
                : 0.0,
        ];
    }

    private function sumField(array $rows, string $field): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += abs((float) ($row[$field] ?? 0));
        }

        return $sum;
    }
}
