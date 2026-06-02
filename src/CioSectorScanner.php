<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SectorUniverse.php';
require_once __DIR__ . '/ExternalContextService.php';

final class CioSectorScanner
{
    public function scan(string $sector, string $subsector = ''): array
    {
        $sector = trim($sector);
        $subsector = trim($subsector);
        if ($sector === '') {
            throw new RuntimeException('Sektor wajib dipilih.');
        }

        $universe = SectorUniverse::bySectorAndSubsector($sector, $subsector);
        if ($universe === []) {
            throw new RuntimeException('Belum ada universe sektor/subsektor yang cocok untuk pilihan ini.');
        }

        $snapshot = $this->loadLatestSignalSnapshot();
        $itemsBySymbol = [];
        foreach (($snapshot['items'] ?? []) as $item) {
            $symbol = strtoupper((string) ($item['symbol'] ?? ''));
            if ($symbol !== '') {
                $itemsBySymbol[$symbol] = $item;
            }
        }

        $baseCandidates = [];
        foreach ($universe as $company) {
            $symbol = strtoupper((string) ($company['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }

            $item = $itemsBySymbol[$symbol] ?? null;
            $baseCandidates[] = $this->buildCandidate($company, is_array($item) ? $item : []);
        }

        usort($baseCandidates, static function (array $a, array $b): int {
            return ($b['conviction_score'] <=> $a['conviction_score'])
                ?: (($b['value_score'] ?? 0) <=> ($a['value_score'] ?? 0))
                ?: strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
        });

        $topSymbols = array_column(array_slice($baseCandidates, 0, 10), 'symbol');
        $topLookup = array_flip($topSymbols);
        $candidates = [];

        foreach ($baseCandidates as $candidate) {
            if (isset($topLookup[$candidate['symbol']])) {
                $external = (new ExternalContextService())->collect($candidate['symbol'], (string) ($candidate['company_name'] ?? ''));
                $candidate = $this->applyExternalContext($candidate, $external);
            }
            $candidates[] = $candidate;
        }

        usort($candidates, static function (array $a, array $b): int {
            return ($b['conviction_score'] <=> $a['conviction_score'])
                ?: (($b['value_score'] ?? 0) <=> ($a['value_score'] ?? 0))
                ?: strcmp((string) ($a['symbol'] ?? ''), (string) ($b['symbol'] ?? ''));
        });

        return [
            'sector' => $sector,
            'subsector' => $subsector,
            'snapshot_label' => (string) ($snapshot['label'] ?? 'Internal Market Snapshot'),
            'snapshot_file' => (string) ($snapshot['file'] ?? ''),
            'generated_at' => gmdate(DATE_ATOM),
            'universe_count' => count($universe),
            'ranked_count' => count($candidates),
            'candidates' => array_slice($candidates, 0, 20),
            'notes' => [
                'CIO Sector Scanner ini khusus untuk mencari kandidat sektor yang terlihat murah relatif terhadap positioning saat ini, tetapi mulai punya jejak momentum dan katalis.',
                'Scanner ini tidak memakai pilihan Fast V1/V2/V3 di UI. Sistem memilih snapshot sinyal internal terbaru secara otomatis di belakang layar hanya sebagai lapisan konfirmasi pasar.',
                'Hasil terbaik dipakai untuk shortlist CIO, lalu tiap emiten dibedah lebih dalam lewat halaman CIO Detail.',
            ],
        ];
    }

    private function loadLatestSignalSnapshot(): array
    {
        $patterns = [
            'Internal Snapshot (Latest V7)' => NEXT_DAY_RUN_DIR . '/next-day-fast-v7-*.json',
            'Internal Snapshot (Latest V6)' => NEXT_DAY_RUN_DIR . '/next-day-fast-v6-*.json',
            'Internal Snapshot (Latest)' => NEXT_DAY_RUN_DIR . '/next-day-fast-v5-*.json',
            'Internal Snapshot (Fallback V4)' => NEXT_DAY_RUN_DIR . '/next-day-fast-v4-*.json',
            'Internal Snapshot (Fallback V3)' => NEXT_DAY_RUN_DIR . '/next-day-fast-v3-*.json',
            'Internal Snapshot (Fallback V2)' => NEXT_DAY_RUN_DIR . '/next-day-fast-v2-*.json',
            'Internal Snapshot (Fallback V1)' => NEXT_DAY_RUN_DIR . '/next-day-fast-*.json',
            'Internal Snapshot (Swing)' => NEXT_DAY_RUN_DIR . '/next-day-swing-*.json',
        ];

        foreach ($patterns as $label => $pattern) {
            $files = glob($pattern) ?: [];
            rsort($files, SORT_STRING);
            $file = $files[0] ?? '';
            if ($file === '' || !is_file($file)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($file), true);
            if (!is_array($decoded)) {
                continue;
            }

            $decoded['label'] = $label;
            $decoded['file'] = $file;

            return $decoded;
        }

        return [
            'items' => [],
            'label' => 'Internal Snapshot Unavailable',
            'file' => '',
        ];
    }

    private function buildCandidate(array $company, array $item): array
    {
        $metrics = is_array($item['metrics'] ?? null) ? $item['metrics'] : [];
        $enrichment = is_array($item['enrichment'] ?? null) ? $item['enrichment'] : [];
        $score = (float) ($item['score'] ?? 0);
        $buyShare = (float) ($metrics['buy_market_share'] ?? 0);
        $dominance = (float) ($metrics['dominance_gap'] ?? 0);
        $turnover = (float) ($metrics['turnover_acceleration'] ?? 0);
        $clean = (float) ($metrics['clean_ratio'] ?? 0);
        $acc = (float) ($metrics['acc_ratio'] ?? 0);
        $repeat = (float) ($metrics['repeat_ratio'] ?? 0);
        $breakout = (float) ($metrics['breakout_pct'] ?? 0);
        $extension = (float) ($metrics['extension_pct'] ?? 0);
        $closeVsOpen = (float) ($metrics['intraday_close_vs_open_pct'] ?? 0);
        $distDays = (int) ($enrichment['dist_days'] ?? 0);
        $board = trim((string) ($company['listing_board'] ?? ''));

        $valueScore = $this->valueScore($breakout, $extension, $distDays, $board);
        $momentumScore = $this->momentumScore($score, $buyShare, $dominance, $turnover, $clean, $acc, $repeat, $closeVsOpen);
        $catalystScore = 48;

        $boardBoost = match (mb_strtolower($board)) {
            'utama' => 5,
            'pengembangan' => 2,
            'pemantauan khusus' => -10,
            default => 0,
        };

        $conviction = max(1, min(100, (int) round(($valueScore * 0.38) + ($momentumScore * 0.42) + ($catalystScore * 0.20) + $boardBoost)));

        return [
            'symbol' => (string) ($company['symbol'] ?? ''),
            'company_name' => (string) ($company['company_name'] ?? ''),
            'sector' => (string) ($company['sector'] ?? ''),
            'subsector' => (string) ($company['subsector'] ?? ''),
            'listing_board' => $board,
            'conviction_score' => $conviction,
            'value_score' => $valueScore,
            'momentum_score' => $momentumScore,
            'catalyst_score' => $catalystScore,
            'pricing_posture' => $this->pricingPosture($breakout, $extension, $distDays),
            'valuation_view' => $this->valuationView($breakout, $extension, $distDays, $board),
            'thesis' => $this->thesis($valueScore, $momentumScore, $board, $distDays),
            'metrics' => [
                'score' => $score,
                'buy_market_share' => $buyShare,
                'dominance_gap' => $dominance,
                'turnover_acceleration' => $turnover,
                'clean_ratio' => $clean,
                'acc_ratio' => $acc,
                'repeat_ratio' => $repeat,
                'breakout_pct' => $breakout,
                'extension_pct' => $extension,
                'close_vs_open_pct' => $closeVsOpen,
                'dist_days' => $distDays,
            ],
            'external_summary' => [],
            'risks' => $this->baseRisks($breakout, $extension, $dominance, $distDays),
        ];
    }

    private function applyExternalContext(array $candidate, array $external): array
    {
        $signals = is_array($external['signals'] ?? null) ? $external['signals'] : [];
        $boost = 0;
        $catalyst = (int) ($candidate['catalyst_score'] ?? 48);

        if (($signals['has_financial_growth'] ?? false) === true) {
            $boost += 8;
            $catalyst += 18;
        }
        if (($signals['has_dividend'] ?? false) === true) {
            $boost += 4;
            $catalyst += 10;
        }
        if (($signals['has_buyback'] ?? false) === true) {
            $boost += 3;
            $catalyst += 8;
        }
        if (($signals['has_rights_issue'] ?? false) === true) {
            $boost -= 3;
            $catalyst -= 8;
        }
        if (($signals['has_negative_event'] ?? false) === true) {
            $boost -= 8;
            $catalyst -= 20;
        }

        $candidate['catalyst_score'] = max(1, min(100, $catalyst));
        $candidate['conviction_score'] = max(1, min(100, (int) round(((int) ($candidate['conviction_score'] ?? 0)) + $boost)));
        $candidate['external_summary'] = is_array($signals['summary'] ?? null) ? $signals['summary'] : [];
        $candidate['thesis'] = $candidate['thesis'] . $this->externalThesisAppendix($signals);

        return $candidate;
    }

    private function valueScore(float $breakout, float $extension, int $distDays, string $board): int
    {
        $score = 52;

        if ($breakout <= 0) {
            $score += 18;
        } elseif ($breakout <= 5) {
            $score += 10;
        } elseif ($breakout <= 12) {
            $score += 3;
        } else {
            $score -= 10;
        }

        if ($extension <= 3) {
            $score += 14;
        } elseif ($extension <= 8) {
            $score += 6;
        } else {
            $score -= 8;
        }

        if ($distDays === 0) {
            $score += 8;
        } elseif ($distDays >= 3) {
            $score -= 8;
        }

        if (mb_strtolower(trim($board)) === 'pemantauan khusus') {
            $score -= 15;
        }

        return max(1, min(100, $score));
    }

    private function momentumScore(float $score, float $buyShare, float $dominance, float $turnover, float $clean, float $acc, float $repeat, float $closeVsOpen): int
    {
        $result = 20;
        $result += (int) round(min(30, $score * 0.25));
        $result += (int) round(min(14, max(0, ($buyShare - 50) * 0.25)));
        $result += (int) round(min(10, max(0, $dominance * 0.3)));
        $result += (int) round(min(10, max(0, ($turnover - 0.2) * 12)));
        $result += (int) round(min(8, $clean * 0.08));
        $result += (int) round(min(5, $acc * 0.05));
        $result += (int) round(min(5, $repeat * 0.05));
        $result += (int) round(min(6, max(0, $closeVsOpen * 0.5)));

        return max(1, min(100, $result));
    }

    private function pricingPosture(float $breakout, float $extension, int $distDays): string
    {
        if ($extension <= 4 && $breakout <= 2 && $distDays <= 2) {
            return 'Masih dekat basis';
        }
        if ($breakout < 0 && $extension <= 6) {
            return 'Recovery dari bawah';
        }
        if ($extension > 10) {
            return 'Sudah panas';
        }

        return 'Transisi / perlu konfirmasi';
    }

    private function valuationView(float $breakout, float $extension, int $distDays, string $board): string
    {
        if (mb_strtolower(trim($board)) === 'pemantauan khusus') {
            return 'Murah belum tentu sehat';
        }
        if ($breakout <= 0 && $extension <= 5 && $distDays <= 1) {
            return 'Masih menarik untuk re-rating';
        }
        if ($extension > 10) {
            return 'Sudah mulai mahal secara positioning';
        }

        return 'Masih wajar';
    }

    private function thesis(int $valueScore, int $momentumScore, string $board, int $distDays): string
    {
        if ($valueScore >= 75 && $momentumScore >= 70 && mb_strtolower(trim($board)) === 'utama') {
            return 'Saham ini menarik karena masih cukup dekat dengan basis persepsi, tetapi sudah mulai menunjukkan tanda bahwa pasar sedang mengakui ulang valuasinya.';
        }
        if ($valueScore >= 70 && $distDays <= 1) {
            return 'Kandidat ini terlihat seperti value recovery yang belum terlalu ramai, sehingga cocok dijadikan shortlist CIO untuk 1-6 bulan.';
        }

        return 'Kandidat ini masih layak dipantau sebagai ide sektoral, tetapi butuh validasi tambahan agar thesis swing-nya lebih matang.';
    }

    private function externalThesisAppendix(array $signals): string
    {
        if (($signals['has_negative_event'] ?? false) === true) {
            return ' Namun ada risiko headline negatif yang bisa menghambat re-rating.';
        }
        if (($signals['has_financial_growth'] ?? false) === true) {
            return ' Ada juga dukungan narasi pertumbuhan yang membuat peluang re-rating lebih sehat.';
        }
        if (($signals['has_buyback'] ?? false) === true) {
            return ' Program buyback juga bisa menjadi penyangga psikologis harga dalam jangka pendek-menengah.';
        }

        return '';
    }

    private function baseRisks(float $breakout, float $extension, float $dominance, int $distDays): array
    {
        $risks = [];
        if ($extension > 10) {
            $risks[] = 'Harga sudah cukup jauh dari basis sehingga reward-risk mulai menurun.';
        }
        if ($dominance < 5) {
            $risks[] = 'Dominasi buyer belum kuat, sehingga re-rating masih bisa gagal berlanjut.';
        }
        if ($distDays >= 3) {
            $risks[] = 'Distribusi sudah beberapa hari, jadi ada risiko saham hanya rebound sesaat.';
        }
        if ($breakout > 12) {
            $risks[] = 'Breakout sudah terlalu jauh untuk disebut murah secara positioning.';
        }
        if ($risks === []) {
            $risks[] = 'Risiko utama tetap ada pada perubahan sentimen sektor dan hilangnya follow-through pasar.';
        }

        return $risks;
    }
}
