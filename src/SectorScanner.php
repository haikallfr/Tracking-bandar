<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SectorUniverse.php';
require_once __DIR__ . '/ExternalContextService.php';
require_once __DIR__ . '/NextDayFilter.php';

final class SectorScanner
{
    public function scan(string $sector, string $profile = 'swing', string $subsector = ''): array
    {
        $sector = trim($sector);
        $subsector = trim($subsector);
        if ($sector === '') {
            throw new RuntimeException('Sektor wajib dipilih.');
        }

        $profile = NextDayFilter::normalizeProfile($profile);
        $universe = SectorUniverse::bySectorAndSubsector($sector, $subsector);
        if ($universe === []) {
            throw new RuntimeException('Belum ada universe sektor/subsektor yang cocok untuk pilihan ini.');
        }

        $dataset = $this->loadDataset($profile);
        $itemsBySymbol = [];
        foreach (($dataset['items'] ?? []) as $item) {
            $symbol = strtoupper((string) ($item['symbol'] ?? ''));
            if ($symbol !== '') {
                $itemsBySymbol[$symbol] = $item;
            }
        }

        $baseCandidates = [];
        foreach ($universe as $company) {
            $symbol = (string) $company['symbol'];
            $item = $itemsBySymbol[$symbol] ?? null;
            if (!is_array($item)) {
                continue;
            }

            $baseCandidates[] = $this->buildBaseCandidate($company, $item);
        }

        usort($baseCandidates, static function (array $a, array $b): int {
            return ($b['opportunity_score'] <=> $a['opportunity_score'])
                ?: (($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        });

        $topSymbols = array_column(array_slice($baseCandidates, 0, 8), 'symbol');
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
            return ($b['opportunity_score'] <=> $a['opportunity_score'])
                ?: (($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        });

        return [
            'sector' => $sector,
            'subsector' => $subsector,
            'profile' => $profile,
            'effective_profile' => (string) ($dataset['effective_profile'] ?? $profile),
            'generated_at' => gmdate(DATE_ATOM),
            'dataset_file' => $dataset['file'] ?? '',
            'universe_count' => count($universe),
            'ranked_count' => count($candidates),
            'candidates' => $candidates,
            'notes' => [
                'Universe sektor sekarang dibaca dari file Excel di folder assets/Sektor yang sudah diimpor ke referensi emiten, jadi sektor utama tidak lagi bergantung pada tebakan nama perusahaan.',
                'Ranking saat ini menggabungkan market confirmation dari dataset scan terbaru, jejak akumulasi, pricing posture, dan katalis eksternal.',
                'Fallback berbasis nama emiten hanya dipakai jika suatu sektor belum punya mapping yang cukup di referensi.',
                'Layer valuasi finansial mendalam seperti PE/PBV historis dan FCF/DER belum terintegrasi penuh, jadi v1 ini fokus pada value-momentum proxy yang paling realistis dari data yang sudah kita punya.',
            ],
        ];
    }

    private function loadDataset(string $profile): array
    {
        foreach ($this->profileFallbacks($profile) as $candidateProfile) {
            $settingMap = [
                'swing' => 'next_day_swing_dataset_latest_file',
                'fast' => 'next_day_fast_dataset_latest_file',
                'fast_v2' => 'next_day_fast_v2_dataset_latest_file',
                'fast_v3' => 'next_day_fast_v3_dataset_latest_file',
                'fast_v4' => 'next_day_fast_v4_dataset_latest_file',
                'fast_v5' => 'next_day_fast_v5_dataset_latest_file',
                'fast_v6' => 'next_day_fast_v6_dataset_latest_file',
                'fast_v7' => 'next_day_fast_v7_dataset_latest_file',
            ];

            $path = (string) setting($settingMap[$candidateProfile] ?? 'next_day_swing_dataset_latest_file', '');
            if ($path === '' || !is_file($path)) {
                $path = $this->findLatestDatasetFile($candidateProfile);
            }

            if ($path !== '' && is_file($path)) {
                $decoded = json_decode((string) file_get_contents($path), true);
                if (!is_array($decoded)) {
                    continue;
                }

                $decoded['file'] = $path;
                $decoded['effective_profile'] = $candidateProfile;
                return $decoded;
            }
        }
        throw new RuntimeException('File dataset terbaru untuk profile ini belum tersedia.');
    }

    private function profileFallbacks(string $profile): array
    {
        return match ($profile) {
            'fast_v7' => ['fast_v7', 'fast_v6', 'fast_v5', 'fast_v4', 'fast_v3', 'fast_v2', 'fast', 'swing'],
            'fast_v6' => ['fast_v6', 'fast_v5', 'fast_v4', 'fast_v3', 'fast_v2', 'fast', 'swing'],
            'fast_v5' => ['fast_v5', 'fast_v4', 'fast_v3', 'fast_v2', 'fast', 'swing'],
            'fast_v4' => ['fast_v4', 'fast_v3', 'fast_v2', 'fast', 'swing'],
            'fast_v3' => ['fast_v3', 'fast_v2', 'fast', 'swing'],
            'fast_v2' => ['fast_v2', 'fast', 'swing'],
            'fast' => ['fast', 'swing'],
            default => ['swing'],
        };
    }

    private function findLatestDatasetFile(string $profile): string
    {
        $pattern = match ($profile) {
            'swing' => NEXT_DAY_RUN_DIR . '/next-day-swing-*.json',
            'fast' => NEXT_DAY_RUN_DIR . '/next-day-fast-*.json',
            'fast_v2' => NEXT_DAY_RUN_DIR . '/next-day-fast-v2-*.json',
            'fast_v3' => NEXT_DAY_RUN_DIR . '/next-day-fast-v3-*.json',
            'fast_v4' => NEXT_DAY_RUN_DIR . '/next-day-fast-v4-*.json',
            'fast_v5' => NEXT_DAY_RUN_DIR . '/next-day-fast-v5-*.json',
            'fast_v6' => NEXT_DAY_RUN_DIR . '/next-day-fast-v6-*.json',
            'fast_v7' => NEXT_DAY_RUN_DIR . '/next-day-fast-v7-*.json',
            default => NEXT_DAY_RUN_DIR . '/next-day-swing-*.json',
        };

        $files = glob($pattern) ?: [];
        rsort($files, SORT_STRING);

        return $files[0] ?? '';
    }

    private function buildBaseCandidate(array $company, array $item): array
    {
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];

        $score = (float) ($item['score'] ?? 0);
        $buyShare = (float) ($metrics['buy_market_share'] ?? 0);
        $dominanceGap = (float) ($metrics['dominance_gap'] ?? 0);
        $turnover = (float) ($metrics['turnover_acceleration'] ?? 0);
        $clean = (float) ($metrics['clean_ratio'] ?? 0);
        $acc = (float) ($metrics['acc_ratio'] ?? 0);
        $breakout = (float) ($metrics['breakout_pct'] ?? 0);
        $extension = (float) ($metrics['extension_pct'] ?? 0);
        $distDays = (int) ($enrichment['dist_days'] ?? 0);

        $boardBoost = match (mb_strtolower(trim((string) ($company['listing_board'] ?? '')))) {
            'utama' => 6,
            'pengembangan' => 2,
            'pemantauan khusus' => -8,
            default => 0,
        };

        $valueScore = $this->valueScore($breakout, $extension, $distDays, (string) ($company['listing_board'] ?? ''));
        $momentumScore = $this->momentumScore($score, $buyShare, $dominanceGap, $turnover, $clean, $acc, $distDays);
        $opportunityScore = max(1, min(100, (int) round(($score * 0.18) + ($momentumScore * 0.47) + ($valueScore * 0.27) + $boardBoost)));

        return [
            'symbol' => (string) ($company['symbol'] ?? ''),
            'company_name' => (string) ($company['company_name'] ?? ''),
            'listing_board' => (string) ($company['listing_board'] ?? ''),
            'status' => (string) ($item['status'] ?? 'filtered_out'),
            'label' => (string) ($item['label'] ?? 'Watchlist'),
            'score' => $score,
            'opportunity_score' => $opportunityScore,
            'value_score' => $valueScore,
            'momentum_score' => $momentumScore,
            'catalyst_score' => 50,
            'valuation_view' => $this->valuationView($breakout, $extension, $distDays),
            'pricing_posture' => $this->pricingPosture($breakout, $extension, $distDays),
            'thesis' => $this->thesis($item, [], $opportunityScore),
            'metrics' => [
                'buy_market_share' => $buyShare,
                'dominance_gap' => $dominanceGap,
                'turnover_acceleration' => $turnover,
                'clean_ratio' => $clean,
                'acc_ratio' => $acc,
                'breakout_pct' => $breakout,
                'extension_pct' => $extension,
                'dist_days' => $distDays,
            ],
            'signals' => [
                'has_buyback' => false,
                'has_rights_issue' => false,
                'has_dividend' => false,
                'has_financial_growth' => false,
                'has_negative_event' => false,
            ],
            'external_summary' => [],
            'failures' => array_slice(is_array($item['failures'] ?? null) ? $item['failures'] : [], 0, 3),
        ];
    }

    private function applyExternalContext(array $candidate, array $external): array
    {
        $signals = $external['signals'] ?? [];
        $boost = 0;
        $catalystScore = 50;
        if (($signals['has_financial_growth'] ?? false) === true) {
            $boost += 8;
            $catalystScore += 18;
        }
        if (($signals['has_dividend'] ?? false) === true) {
            $boost += 4;
            $catalystScore += 10;
        }
        if (($signals['has_buyback'] ?? false) === true) {
            $boost += 3;
            $catalystScore += 6;
        }
        if (($signals['has_rights_issue'] ?? false) === true) {
            $boost -= 3;
            $catalystScore -= 8;
        }
        if (($signals['has_negative_event'] ?? false) === true) {
            $boost -= 8;
            $catalystScore -= 20;
        }

        $candidate['opportunity_score'] = max(1, min(100, (int) round(((int) ($candidate['opportunity_score'] ?? 0)) + $boost)));
        $candidate['catalyst_score'] = max(1, min(100, $catalystScore));
        $candidate['signals'] = [
            'has_buyback' => (bool) ($signals['has_buyback'] ?? false),
            'has_rights_issue' => (bool) ($signals['has_rights_issue'] ?? false),
            'has_dividend' => (bool) ($signals['has_dividend'] ?? false),
            'has_financial_growth' => (bool) ($signals['has_financial_growth'] ?? false),
            'has_negative_event' => (bool) ($signals['has_negative_event'] ?? false),
        ];
        $candidate['external_summary'] = is_array($signals['summary'] ?? null) ? $signals['summary'] : [];
        $candidate['thesis'] = $this->thesis($candidate, $signals, (int) $candidate['opportunity_score']);

        return $candidate;
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

    private function valuationView(float $breakout, float $extension, int $distDays): string
    {
        if ($extension <= 3 && $breakout <= 1 && $distDays <= 2) {
            return 'Undervalued relatif ke basis';
        }
        if ($extension <= 6 && $breakout <= 4) {
            return 'Masih wajar';
        }
        if ($extension > 10 || $breakout > 10) {
            return 'Mulai mahal / overextended';
        }

        return 'Sedang transisi';
    }

    private function thesis(array $item, array $signals, int $opportunityScore): string
    {
        $parts = [];
        if ($opportunityScore >= 80) {
            $parts[] = 'setup value-momentum paling menonjol di sektor ini';
        } elseif ($opportunityScore >= 70) {
            $parts[] = 'kandidat kuat namun masih butuh validasi lanjutan';
        } else {
            $parts[] = 'masuk radar tetapi belum cukup bersih';
        }

        if (($signals['has_financial_growth'] ?? false) === true) {
            $parts[] = 'narasi pertumbuhan mulai mendukung';
        }
        if (($signals['has_dividend'] ?? false) === true) {
            $parts[] = 'dividen bisa jadi pemicu rerating';
        }
        if (($signals['has_negative_event'] ?? false) === true) {
            $parts[] = 'ada risiko berita negatif yang perlu diawasi';
        }

        return ucfirst(implode(', ', $parts)) . '.';
    }

    private function valueScore(float $breakout, float $extension, int $distDays, string $listingBoard): int
    {
        $score = 50;

        if ($breakout <= 1) {
            $score += 16;
        } elseif ($breakout <= 4) {
            $score += 8;
        } elseif ($breakout > 10) {
            $score -= 14;
        }

        if ($extension <= 3) {
            $score += 18;
        } elseif ($extension <= 6) {
            $score += 10;
        } elseif ($extension > 12) {
            $score -= 18;
        } elseif ($extension > 8) {
            $score -= 10;
        }

        if ($distDays <= 1) {
            $score += 10;
        } elseif ($distDays >= 4) {
            $score -= 10;
        }

        $board = mb_strtolower(trim($listingBoard));
        if ($board === 'utama') {
            $score += 6;
        } elseif ($board === 'pemantauan khusus') {
            $score -= 12;
        }

        return max(1, min(100, $score));
    }

    private function momentumScore(
        float $score,
        float $buyShare,
        float $dominanceGap,
        float $turnover,
        float $clean,
        float $acc,
        int $distDays
    ): int {
        $raw = 20
            + ($score * 0.32)
            + ($buyShare * 0.18)
            + ($dominanceGap * 0.75)
            + ($turnover * 9)
            + ($clean * 0.1)
            + ($acc * 0.1)
            - max(0, $distDays - 2) * 4;

        return max(1, min(100, (int) round($raw)));
    }
}
