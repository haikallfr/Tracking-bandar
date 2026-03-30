<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/StockbitClient.php';
require_once __DIR__ . '/RadarBandar.php';
require_once __DIR__ . '/BrokerRepository.php';
require_once __DIR__ . '/HistoricalRepository.php';
require_once __DIR__ . '/CandidateEnricher.php';
require_once __DIR__ . '/ExternalContextService.php';

final class SystemAnalyst
{
    public function analyze(string $symbol, string $mode = 'high'): array
    {
        $symbol = strtoupper(trim($symbol));
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['high', 'basic'], true)) {
            $mode = 'high';
        }

        $item = $this->buildRadarItem($symbol, $mode);
        if ($item === null) {
            throw new RuntimeException('Data radar untuk simbol ini belum cukup untuk dianalisa.');
        }

        $reference = $this->symbolReference($symbol);
        $external = (new ExternalContextService())->collect($symbol, (string) ($reference['company_name'] ?? ''));
        $analysis = $this->buildAnalysis($item, $mode, $reference, $external);

        return [
            'symbol' => $symbol,
            'mode' => $mode,
            'generated_at' => gmdate(DATE_ATOM),
            'company' => $reference,
            'item' => $item,
            'external_context' => $external,
            'analysis' => $analysis,
        ];
    }

    private function buildRadarItem(string $symbol, string $mode): ?array
    {
        $token = (string) setting('stockbit_token', '');
        if ($token === '') {
            throw new RuntimeException('Token Stockbit belum siap.');
        }

        $filters = [
            'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board' => 'MARKET_BOARD_ALL',
            'investor_type' => 'INVESTOR_TYPE_ALL',
        ];

        $client = new StockbitClient($token);
        $radar = new RadarBandar(new BrokerRepository());
        $payload = $client->fetchSymbol($symbol, $filters);
        $updatedAt = gmdate(DATE_ATOM);

        if ($mode === 'basic') {
            return $radar->evaluateBasic($symbol, $payload, $updatedAt);
        }

        $item = $radar->evaluate($symbol, $payload, $updatedAt);
        if ($item !== null) {
            $enricher = new CandidateEnricher($client, new HistoricalRepository());
            $enrichment = $enricher->enrich($symbol, 12);
            if ($enrichment !== []) {
                $item = $radar->refine($item, $enrichment);
            }
        }

        return $item;
    }

    private function symbolReference(string $symbol): array
    {
        $stmt = db()->prepare('SELECT symbol, company_name, sector, subsector, listing_board FROM symbol_reference WHERE symbol = :symbol LIMIT 1');
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [
            'symbol' => $symbol,
            'company_name' => '',
            'sector' => '',
            'subsector' => '',
            'listing_board' => '',
        ];
    }

    private function buildAnalysis(array $item, string $mode, array $reference, array $external): array
    {
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $topBuyer = $item['top_buyers'][0] ?? [];
        $topSeller = $item['top_sellers'][0] ?? [];
        $signals = $external['signals'] ?? [];
        $externalSummary = is_array($signals['summary'] ?? null) ? $signals['summary'] : [];
        $hasBuyback = (bool) ($signals['has_buyback'] ?? false);
        $hasRightsIssue = (bool) ($signals['has_rights_issue'] ?? false);
        $hasDividend = (bool) ($signals['has_dividend'] ?? false);
        $hasNegativeEvent = (bool) ($signals['has_negative_event'] ?? false);
        $hasFinancialGrowth = (bool) ($signals['has_financial_growth'] ?? false);

        $score = (float) ($item['score'] ?? 0);
        $buyShare = (float) ($metrics['buy_market_share'] ?? 0);
        $lotShare = (float) ($metrics['buy_lot_share'] ?? 0);
        $dominanceGap = (float) ($metrics['dominance_gap'] ?? 0);
        $repeatRatio = (float) ($metrics['repeat_ratio'] ?? 0);
        $cleanRatio = (float) ($metrics['clean_ratio'] ?? 0);
        $accRatio = (float) ($metrics['acc_ratio'] ?? 0);
        $turnoverAccel = (float) ($metrics['turnover_acceleration'] ?? 0);
        $breakoutPct = (float) ($metrics['breakout_pct'] ?? 0);
        $extensionPct = (float) ($metrics['extension_pct'] ?? 0);
        $closeVsOpen = (float) ($metrics['intraday_close_vs_open_pct'] ?? 0);
        $rangePct = (float) ($metrics['intraday_range_pct'] ?? 0);
        $distDays = (int) ($enrichment['dist_days'] ?? 0);
        $repeatBroker = (string) ($enrichment['repeat_broker_code'] ?? ($topBuyer['broker_code'] ?? ''));
        $repeatDays = (int) ($enrichment['repeat_broker_days'] ?? 0);
        $buyerType = (string) ($topBuyer['broker_type'] ?? '');

        $setup = 'Netral';
        if ($mode === 'basic') {
            $setup = $score >= 87 ? 'Radar Dasar Kuat' : 'Radar Dasar Lemah';
        } elseif ($score >= 95 && $cleanRatio >= 70 && $repeatRatio >= 60) {
            $setup = 'Akumulasi Bersih';
        } elseif ($turnoverAccel >= 1.2 && $closeVsOpen >= 2 && $rangePct <= 20) {
            $setup = 'Fast Ignition';
        } elseif ($score >= 90) {
            $setup = 'Prime Setup';
        }

        $bias = 'Netral';
        if ($score >= 95 && $dominanceGap >= 20 && $distDays === 0) {
            $bias = 'Bullish Kuat';
        } elseif ($score >= 85 && $buyShare >= 70) {
            $bias = 'Bullish Moderat';
        } elseif ($dominanceGap <= 2 || $distDays >= 2) {
            $bias = 'Rapuh';
        }
        if ($hasNegativeEvent && $bias === 'Bullish Kuat') {
            $bias = 'Bullish Moderat';
        } elseif ($hasNegativeEvent) {
            $bias = 'Netral';
        }

        $decision = 'Layak Pantau';
        if ($setup === 'Akumulasi Bersih' && $turnoverAccel >= 0.8 && $extensionPct <= 6) {
            $decision = 'Layak Entry Bertahap';
        } elseif ($setup === 'Fast Ignition' && $rangePct <= 18) {
            $decision = 'Tunggu Konfirmasi';
        } elseif ($bias === 'Rapuh') {
            $decision = 'Hindari';
        }
        if ($hasBuyback && $decision === 'Layak Entry Bertahap') {
            $decision = 'Layak Pantau';
        }
        if ($hasRightsIssue) {
            $decision = 'Tunggu Konfirmasi';
        }
        if ($hasNegativeEvent) {
            $decision = 'Tunggu Konfirmasi';
        }

        $summary = [];
        $summary[] = sprintf(
            '%s berada di kategori %s dengan bias %s berdasarkan score %.1f dan dominasi buyer %.2f%%.',
            $item['symbol'] ?? '-',
            $setup,
            $bias,
            $score,
            $dominanceGap
        );
        $summary[] = sprintf(
            'Buyer utama %s %s mendominasi %d hari dengan clean accumulation %.2f%% dan percepatan turnover %sx.',
            $repeatBroker !== '' ? $repeatBroker : '-',
            $buyerType !== '' ? '(' . $buyerType . ')' : '',
            $repeatDays,
            $cleanRatio,
            number_format($turnoverAccel, 2, ',', '.')
        );

        if ($buyerType === 'Pemerintah' && $repeatRatio >= 80) {
            $summary[] = 'Karena broker dominan berasal dari kategori pemerintah dan sangat konsisten, sinyal ini perlu dibaca hati-hati karena bisa tercampur aksi institusi atau corporate support.';
        } elseif ($closeVsOpen >= 2 && $turnoverAccel >= 1.2) {
            $summary[] = 'Struktur ini lebih dekat ke pola ignition cepat daripada swing rapi.';
        } else {
            $summary[] = 'Sinyal saat ini lebih cocok dipakai sebagai bahan pantau daripada keputusan agresif tanpa konfirmasi tambahan.';
        }
        foreach ($externalSummary as $line) {
            $summary[] = $line;
        }

        $happening = [
            sprintf('Top buyer menguasai %.2f%% turnover dan %.2f%% volume.', $buyShare, $lotShare),
            sprintf('Broker dominan %s aktif %d hari dengan repeat ratio %.2f%%.', $repeatBroker !== '' ? $repeatBroker : '-', $repeatDays, $repeatRatio),
            sprintf('Clean accumulation %.2f%%, acc ratio %.2f%%, distribusi %d hari.', $cleanRatio, $accRatio, $distDays),
            sprintf('Breakout %.2f%%, extension %.2f%%, close vs open %.2f%%.', $breakoutPct, $extensionPct, $closeVsOpen),
        ];
        if ($hasBuyback) {
            $happening[] = 'Ada konteks buyback, sehingga dukungan harga bisa berasal dari aksi korporasi dan bukan semata flow spekulatif.';
        }
        if ($hasFinancialGrowth) {
            $happening[] = 'Ada berita yang mendukung narasi fundamental atau pertumbuhan laba.';
        }

        $risks = [];
        if ($buyerType === 'Pemerintah' && $repeatRatio >= 80) {
            $risks[] = 'Dominasi satu broker pemerintah sangat tinggi, sehingga ada risiko sinyal tidak murni berasal dari bandar spekulatif.';
        }
        if ($hasBuyback) {
            $risks[] = 'Jika dorongan harga lebih banyak ditopang buyback, keberlanjutan pergerakan setelah program selesai perlu diuji ulang.';
        }
        if ($hasRightsIssue) {
            $risks[] = 'Rights issue / HMETD dapat mengubah supply-demand dan membuat broker flow sulit dibaca secara biasa.';
        }
        if ($extensionPct > 6) {
            $risks[] = sprintf('Extension %.2f%% sudah mulai tinggi, jadi reward-ratio bisa memburuk.', $extensionPct);
        }
        if ($rangePct > 10) {
            $risks[] = sprintf('Range intraday %.2f%% cukup lebar, sehingga entry terlambat mudah terjebak.', $rangePct);
        }
        if ($dominanceGap < 10) {
            $risks[] = 'Dominance gap belum terlalu lebar, jadi seller masih berpotensi mengimbangi buyer.';
        }
        if ($risks === []) {
            $risks[] = 'Risiko utama tetap ada pada perubahan perilaku broker dominan jika volume lanjutan tidak mengikuti.';
        }

        $next = [];
        if ($decision === 'Layak Entry Bertahap') {
            $next[] = 'Pantau apakah buyer dominan tetap bertahan di sesi berikutnya.';
            $next[] = 'Jaga entry tetap bertahap, jangan mengejar jika extension melebar cepat.';
        } elseif ($decision === 'Tunggu Konfirmasi') {
            $next[] = 'Tunggu candle atau aliran volume berikutnya mengonfirmasi follow-through.';
            $next[] = 'Pastikan buyer dominan tidak hilang setelah dorongan awal.';
        } elseif ($decision === 'Hindari') {
            $next[] = 'Jangan pakai saham ini sebagai hunter utama sebelum struktur buyer membaik.';
            $next[] = 'Cari kandidat lain yang dominance gap dan clean accumulation-nya lebih kuat.';
        } else {
            $next[] = 'Pantau apakah turnover tetap hidup dan distribusi tetap minim.';
            $next[] = 'Perhatikan apakah breakout berubah lebih sehat tanpa extension berlebihan.';
        }
        if ($hasBuyback) {
            $next[] = 'Pisahkan pembacaan sinyal buyback dari akumulasi bandar murni.';
        }
        if ($hasDividend || $hasFinancialGrowth) {
            $next[] = 'Pantau apakah sentimen fundamental ikut mendukung flow broker pada sesi berikutnya.';
        }

        return [
            'setup' => $setup,
            'bias' => $bias,
            'decision' => $decision,
            'summary' => $summary,
            'happening' => $happening,
            'risks' => $risks,
            'next_watch' => $next,
            'meta' => [
                'top_buyer_code' => (string) ($topBuyer['broker_code'] ?? ''),
                'top_buyer_type' => $buyerType,
                'top_seller_code' => (string) ($topSeller['broker_code'] ?? ''),
                'company_name' => (string) ($reference['company_name'] ?? ''),
                'external_keywords' => $signals['keywords'] ?? [],
            ],
        ];
    }
}
