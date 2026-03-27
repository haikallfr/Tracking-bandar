<?php

declare(strict_types=1);

require_once __DIR__ . '/BrokerRepository.php';

final class RadarBandar
{
    public const FULL_MARKET_FILTER_SCORE = 95.0;

    public function __construct(
        private readonly BrokerRepository $repository,
    ) {
    }

    public function build(): array
    {
        $items = [];

        foreach ($this->repository->all() as $entry) {
            $radar = $this->buildItem($entry);
            if ($radar !== null) {
                $items[] = $radar;
            }
        }

        usort($items, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: strcmp((string) $left['symbol'], (string) $right['symbol']);
        });

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'count' => count($items),
            'items' => $items,
        ];
    }

    public function buildBasic(): array
    {
        $items = [];

        foreach ($this->repository->all() as $entry) {
            $radar = $this->buildBasicItem($entry);
            if ($radar !== null) {
                $radar['score_base'] = $radar['score'];
                $items[] = $radar;
            }
        }

        usort($items, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: strcmp((string) $left['symbol'], (string) $right['symbol']);
        });

        return [
            'generated_at' => gmdate(DATE_ATOM),
            'count' => count($items),
            'items' => $items,
        ];
    }

    public function evaluateBasic(string $symbol, array $payload, ?string $updatedAt = null): ?array
    {
        return $this->buildBasicItem([
            'symbol' => $symbol,
            'updated_at' => $updatedAt ?? gmdate(DATE_ATOM),
            'payload' => $payload,
        ]);
    }

    public function evaluate(string $symbol, array $payload, ?string $updatedAt = null): ?array
    {
        return $this->buildItem([
            'symbol' => $symbol,
            'updated_at' => $updatedAt ?? gmdate(DATE_ATOM),
            'payload' => $payload,
        ]);
    }

    public function refine(array $item, array $enrichment): array
    {
        $historyDays = max(1, (int) ($enrichment['history_days'] ?? 0));
        $repeatRatio = min(1.0, ((float) ($enrichment['repeat_broker_days'] ?? 0)) / $historyDays);
        $cleanRatio = min(1.0, ((float) ($enrichment['clean_buyer_days'] ?? 0)) / $historyDays);
        $dominanceRatio = min(1.0, ((float) ($enrichment['buy_dominance_days'] ?? 0)) / $historyDays);
        $accRatio = min(1.0, ((float) ($enrichment['acc_days'] ?? 0)) / $historyDays);
        $bigAccRatio = min(1.0, ((float) ($enrichment['big_acc_days'] ?? 0)) / $historyDays);
        $distRatio = min(1.0, ((float) ($enrichment['dist_days'] ?? 0)) / $historyDays);
        $turnoverAccel = min(1.0, max(0.0, (((float) ($enrichment['turnover_acceleration'] ?? 1)) - 1) / 2));
        $breakoutPct = (float) ($enrichment['breakout_pct'] ?? 0);
        $extensionPct = (float) ($enrichment['extension_pct'] ?? 0);
        $priceChangePct = (float) ($enrichment['price_change_pct'] ?? 0);
        $intradayRangePct = (float) ($enrichment['intraday_range_pct'] ?? 0);
        $intradayCloseVsOpenPct = (float) ($enrichment['intraday_close_vs_open_pct'] ?? 0);
        $intradayCloseVsTailAvgPct = (float) ($enrichment['intraday_close_vs_tail_avg_pct'] ?? 0);
        $tailCompressionPct = (float) ($enrichment['intraday_tail_compression_pct'] ?? 0);

        $breakoutQuality = 0.0;
        if ($breakoutPct >= -1 && $breakoutPct <= 6) {
            $breakoutQuality = 1.0;
        } elseif ($breakoutPct > 6 && $breakoutPct <= 10) {
            $breakoutQuality = 0.6;
        } elseif ($breakoutPct < -1 && $breakoutPct >= -4) {
            $breakoutQuality = 0.35;
        }

        $extensionPenalty = 0.0;
        if ($extensionPct > 12) {
            $extensionPenalty = min(1.0, ($extensionPct - 12) / 12);
        }

        $trendPenalty = $priceChangePct < -3 ? min(1.0, abs($priceChangePct) / 15) : 0.0;
        $closingPressure = 0.0;
        if ($intradayCloseVsOpenPct >= 0.4) {
            $closingPressure += 0.5;
        }
        if ($intradayCloseVsTailAvgPct >= 0.3) {
            $closingPressure += 0.5;
        }
        $closingPressure = min(1.0, $closingPressure);

        $compressionQuality = 0.0;
        if ($tailCompressionPct > 0 && $tailCompressionPct <= 1.5) {
            $compressionQuality = 1.0;
        } elseif ($tailCompressionPct <= 3.0) {
            $compressionQuality = 0.6;
        } elseif ($tailCompressionPct <= 5.0) {
            $compressionQuality = 0.25;
        }

        $noisePenalty = 0.0;
        if ($intradayRangePct > 6) {
            $noisePenalty = min(1.0, ($intradayRangePct - 6) / 10);
        }

        $bonus = (
            5.0 * $repeatRatio +
            4.5 * $cleanRatio +
            3.5 * $dominanceRatio +
            3.0 * $accRatio +
            2.5 * $bigAccRatio +
            2.0 * $turnoverAccel +
            2.5 * $breakoutQuality +
            2.0 * $closingPressure +
            1.5 * $compressionQuality
        );

        $penalty = (
            5.0 * $distRatio +
            4.0 * $extensionPenalty +
            3.0 * $trendPenalty +
            2.0 * $noisePenalty
        );

        $item['score_base'] = $item['score'];
        $item['score'] = round(max(0, min(99.9, (float) $item['score'] + $bonus - $penalty)), 1);
        $item['enrichment'] = $enrichment;
        $item['metrics']['repeat_ratio'] = round($repeatRatio * 100, 2);
        $item['metrics']['clean_ratio'] = round($cleanRatio * 100, 2);
        $item['metrics']['acc_ratio'] = round($accRatio * 100, 2);
        $item['metrics']['turnover_acceleration'] = round((float) ($enrichment['turnover_acceleration'] ?? 0), 2);
        $item['metrics']['breakout_pct'] = round($breakoutPct, 2);
        $item['metrics']['extension_pct'] = round($extensionPct, 2);
        $item['metrics']['intraday_range_pct'] = round($intradayRangePct, 2);
        $item['metrics']['intraday_close_vs_open_pct'] = round($intradayCloseVsOpenPct, 2);
        $item['metrics']['intraday_close_vs_tail_avg_pct'] = round($intradayCloseVsTailAvgPct, 2);
        $item['metrics']['tail_compression_pct'] = round($tailCompressionPct, 2);

        if ($item['score'] >= 95) {
            $item['label'] = 'Prime Setup';
        } elseif ($item['score'] >= 90) {
            $item['label'] = 'High Conviction';
        }

        $item['reasons'][] = sprintf(
            'Broker %s dominan %s/%s hari',
            $enrichment['repeat_broker_code'] !== '' ? $enrichment['repeat_broker_code'] : '-',
            number_format((int) ($enrichment['repeat_broker_days'] ?? 0), 0, ',', '.'),
            number_format($historyDays, 0, ',', '.')
        );
        $item['reasons'][] = sprintf(
            'Akumulasi bersih %s hari, distribusi %s hari',
            number_format((int) ($enrichment['clean_buyer_days'] ?? 0), 0, ',', '.'),
            number_format((int) ($enrichment['dist_days'] ?? 0), 0, ',', '.')
        );
        $item['reasons'][] = sprintf(
            'Percepatan turnover %sx, breakout %.2f%%, extension %.2f%%',
            number_format((float) ($enrichment['turnover_acceleration'] ?? 0), 2, ',', '.'),
            $breakoutPct,
            $extensionPct
        );
        $item['reasons'][] = sprintf(
            'Intraday close %.2f%% vs open, range %.2f%%, compression tail %.2f%%',
            $intradayCloseVsOpenPct,
            $intradayRangePct,
            $tailCompressionPct
        );

        return $item;
    }

    private function buildItem(array $entry): ?array
    {
        $payload = $entry['payload'] ?? [];
        $data = $payload['market_detector']['data'] ?? [];
        $summary = $data['broker_summary'] ?? [];
        $buys = is_array($summary['brokers_buy'] ?? null) ? $summary['brokers_buy'] : [];
        $sells = is_array($summary['brokers_sell'] ?? null) ? $summary['brokers_sell'] : [];

        if ($buys === [] && $sells === []) {
            return null;
        }

        $bandar = $data['bandar_detector'] ?? [];
        $marketValue = abs((float) ($bandar['value'] ?? 0));
        $marketVolume = abs((float) ($bandar['volume'] ?? 0));

        if ($marketValue <= 0 || $marketVolume <= 0) {
            return null;
        }

        $buyTotal = $this->sumField($buys, 'bval');
        $sellTotal = $this->sumField($sells, 'sval');
        $topBuy3Value = $this->sumField(array_slice($buys, 0, 3), 'bval');
        $topSell3Value = $this->sumField(array_slice($sells, 0, 3), 'sval');
        $topBuy3Lot = $this->sumField(array_slice($buys, 0, 3), 'blot');
        $topBuy1Value = $this->sumField(array_slice($buys, 0, 1), 'bval');
        $maxBuyFreq = $this->maxFreq($buys);

        $buyMarketShare = $topBuy3Value / $marketValue;
        $buyLotShare = $topBuy3Lot / $marketVolume;
        $buyConcentration = $buyTotal > 0 ? $topBuy3Value / $buyTotal : 0.0;
        $sellConcentration = $sellTotal > 0 ? $topSell3Value / $sellTotal : 0.0;
        $dominanceGap = max(0.0, $buyMarketShare - ($topSell3Value / $marketValue));
        $frequencyIntensity = min(1.0, log10(max(1, $maxBuyFreq) + 1) / 5);
        $topBuyerPressure = $topBuy1Value / $marketValue;

        $score = 100 * (
            0.32 * min(1.0, $buyMarketShare) +
            0.23 * min(1.0, $buyLotShare) +
            0.20 * min(1.0, $buyConcentration) +
            0.15 * min(1.0, $frequencyIntensity) +
            0.10 * min(1.0, $dominanceGap)
        );

        $label = $score >= 75
            ? 'High Conviction'
            : ($score >= 55 ? 'Akumulasi Fokus' : ($score >= 40 ? 'Watchlist' : 'Noise'));

        $reasons = array_values(array_filter([
            sprintf('Top 3 buyer menguasai %s turnover', $this->formatPercent($buyMarketShare * 100)),
            sprintf('Top 3 buyer menguasai %s volume', $this->formatPercent($buyLotShare * 100)),
            sprintf('Konsentrasi beli %s dari total buyer flow', $this->formatPercent($buyConcentration * 100)),
            sprintf('Frekuensi buyer tertinggi %s kali', number_format($maxBuyFreq, 0, ',', '.')),
            $topBuyerPressure >= 0.2 ? sprintf('Top buyer tunggal menekan %s turnover', $this->formatPercent($topBuyerPressure * 100)) : null,
        ]));

        return [
            'symbol' => $entry['symbol'],
            'updated_at' => $entry['updated_at'],
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
            'score' => round($score, 1),
            'label' => $label,
            'metrics' => [
                'buy_market_share' => round($buyMarketShare * 100, 2),
                'buy_lot_share' => round($buyLotShare * 100, 2),
                'buy_concentration' => round($buyConcentration * 100, 2),
                'sell_concentration' => round($sellConcentration * 100, 2),
                'dominance_gap' => round($dominanceGap * 100, 2),
                'max_buy_freq' => $maxBuyFreq,
                'market_value' => $marketValue,
                'market_volume' => $marketVolume,
                'top_buy_value' => $topBuy3Value,
            ],
            'top_buyers' => $this->mapRows(array_slice($buys, 0, 3), 'buy'),
            'top_sellers' => $this->mapRows(array_slice($sells, 0, 3), 'sell'),
            'reasons' => $reasons,
        ];
    }

    private function buildBasicItem(array $entry): ?array
    {
        $payload = $entry['payload'] ?? [];
        $data = $payload['market_detector']['data'] ?? [];
        $summary = $data['broker_summary'] ?? [];
        $buys = is_array($summary['brokers_buy'] ?? null) ? $summary['brokers_buy'] : [];
        $sells = is_array($summary['brokers_sell'] ?? null) ? $summary['brokers_sell'] : [];

        if ($buys === [] && $sells === []) {
            return null;
        }

        $bandar = $data['bandar_detector'] ?? [];
        $marketValue = abs((float) ($bandar['value'] ?? 0));
        $marketVolume = abs((float) ($bandar['volume'] ?? 0));

        if ($marketValue <= 0 || $marketVolume <= 0) {
            return null;
        }

        $buyTotal = $this->sumField($buys, 'bval');
        $topBuy3Value = $this->sumField(array_slice($buys, 0, 3), 'bval');
        $topSell3Value = $this->sumField(array_slice($sells, 0, 3), 'sval');
        $topBuy3Lot = $this->sumField(array_slice($buys, 0, 3), 'blot');
        $maxBuyFreq = $this->maxFreq($buys);

        $buyMarketShare = $topBuy3Value / $marketValue;
        $buyLotShare = $topBuy3Lot / $marketVolume;
        $buyConcentration = $buyTotal > 0 ? $topBuy3Value / $buyTotal : 0.0;
        $dominanceGap = max(0.0, $buyMarketShare - ($topSell3Value / $marketValue));
        $frequencyIntensity = min(1.0, log10(max(1, $maxBuyFreq) + 1) / 5);

        $score = 100 * (
            0.32 * min(1.0, $buyMarketShare) +
            0.23 * min(1.0, $buyLotShare) +
            0.20 * min(1.0, $buyConcentration) +
            0.15 * min(1.0, $frequencyIntensity) +
            0.10 * min(1.0, $dominanceGap)
        );

        $reasons = [
            sprintf('Buyer menguasai %s turnover', $this->formatPercent($buyMarketShare * 100)),
            sprintf('Buyer menguasai %s volume', $this->formatPercent($buyLotShare * 100)),
            sprintf('Konsentrasi buyer %s dari total buyer flow', $this->formatPercent($buyConcentration * 100)),
            sprintf('Frekuensi broker buyer teratas %s kali', number_format($maxBuyFreq, 0, ',', '.')),
            sprintf('Seller tertinggal, dominance gap %s', $this->formatPercent($dominanceGap * 100)),
        ];

        return [
            'symbol' => $entry['symbol'],
            'updated_at' => $entry['updated_at'],
            'from' => (string) ($data['from'] ?? ''),
            'to' => (string) ($data['to'] ?? ''),
            'score' => round($score, 1),
            'label' => $score >= 85 ? 'Basic Strong Flow' : ($score >= 70 ? 'Basic Focus' : 'Basic Watch'),
            'metrics' => [
                'buy_market_share' => round($buyMarketShare * 100, 2),
                'buy_lot_share' => round($buyLotShare * 100, 2),
                'buy_concentration' => round($buyConcentration * 100, 2),
                'dominance_gap' => round($dominanceGap * 100, 2),
                'frequency_intensity' => round($frequencyIntensity * 100, 2),
                'max_buy_freq' => $maxBuyFreq,
            ],
            'top_buyers' => $this->mapRows(array_slice($buys, 0, 3), 'buy'),
            'top_sellers' => $this->mapRows(array_slice($sells, 0, 3), 'sell'),
            'reasons' => $reasons,
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

    private function maxFreq(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int) ($row['freq'] ?? 0));
        }

        return $max;
    }

    private function mapRows(array $rows, string $side): array
    {
        return array_map(static function (array $row) use ($side): array {
            return [
                'broker_code' => (string) ($row['netbs_broker_code'] ?? ''),
                'broker_type' => (string) ($row['type'] ?? ''),
                'value' => abs((float) ($side === 'buy' ? ($row['bval'] ?? 0) : ($row['sval'] ?? 0))),
                'lot' => abs((float) ($side === 'buy' ? ($row['blot'] ?? 0) : ($row['slot'] ?? 0))),
                'avg_price' => (float) ($side === 'buy' ? ($row['netbs_buy_avg_price'] ?? 0) : ($row['netbs_sell_avg_price'] ?? 0)),
                'freq' => (int) ($row['freq'] ?? 0),
            ];
        }, $rows);
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, $value >= 10 ? 1 : 2, ',', '.') . '%';
    }
}
