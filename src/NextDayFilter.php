<?php

declare(strict_types=1);

final class NextDayFilter
{
    public static function normalizeProfile(string $profile): string
    {
        $profile = strtolower(trim($profile));

        return match ($profile) {
            'fast', 'fast_v1', 'v1' => 'fast',
            'fast_v2', 'v2' => 'fast_v2',
            'fast_v3', 'v3' => 'fast_v3',
            'fast_v4', 'v4' => 'fast_v4',
            'fast_v5', 'v5' => 'fast_v5',
            'fast_v6', 'v6' => 'fast_v6',
            'fast_v7', 'v7' => 'fast_v7',
            default => 'swing',
        };
    }

    public static function rules(string $profile = 'swing'): array
    {
        $profile = self::normalizeProfile($profile);
        $base = [
            'score_min' => 95,
            'dist_days_max' => 1,
            'buy_market_share_min' => 0,
            'clean_ratio_min' => 65,
            'repeat_ratio_min' => 33,
            'acc_ratio_min' => 55,
            'dominance_gap_min' => 8,
            'turnover_acceleration_min' => 0.5,
            'intraday_close_vs_open_pct_min' => -0.2,
            'intraday_range_pct_max' => 5.5,
            'breakout_pct_min' => -5,
            'breakout_pct_max' => 4,
            'extension_pct_max' => 6.5,
        ];

        if ($profile === 'fast') {
            return array_merge($base, [
                'profile' => 'fast',
                'profile_label' => 'Fast Trade V1',
                'score_min' => 90,
                'turnover_acceleration_min' => 1.2,
                'intraday_close_vs_open_pct_min' => 2.0,
                'intraday_range_pct_max' => 20.0,
                'breakout_pct_min' => 1,
                'breakout_pct_max' => 15,
                'extension_pct_max' => 15.0,
            ]);
        }

        if ($profile === 'fast_v2') {
            return array_merge($base, [
                'profile' => 'fast_v2',
                'profile_label' => 'Fast Trade V2',
                'score_min' => 75,
                'dist_days_max' => 2,
                'clean_ratio_min' => 15,
                'repeat_ratio_min' => 16,
                'acc_ratio_min' => 8,
                'dominance_gap_min' => 0,
                'turnover_acceleration_min' => 0.25,
                'intraday_close_vs_open_pct_min' => -1.0,
                'intraday_range_pct_max' => 25.0,
                'breakout_pct_min' => -50,
                'breakout_pct_max' => 2,
                'extension_pct_max' => 5.0,
            ]);
        }

        if ($profile === 'fast_v3') {
            return array_merge($base, [
                'profile' => 'fast_v3',
                'profile_label' => 'Fast Trade V3',
                'score_min' => 80,
                'dist_days_max' => 2,
                'buy_market_share_min' => 80,
                'clean_ratio_min' => 15,
                'repeat_ratio_min' => 16,
                'acc_ratio_min' => 8,
                'dominance_gap_min' => 3,
                'turnover_acceleration_min' => 0.4,
                'intraday_close_vs_open_pct_min' => -1.0,
                'intraday_range_pct_max' => 20.0,
                'breakout_pct_min' => -25,
                'breakout_pct_max' => 2,
                'extension_pct_max' => 5.0,
            ]);
        }

        if ($profile === 'fast_v4') {
            return array_merge($base, [
                'profile' => 'fast_v4',
                'profile_label' => 'Fast Trade V4',
                'score_min' => 72,
                'dist_days_max' => 4,
                'buy_market_share_min' => 65,
                'clean_ratio_min' => 10,
                'repeat_ratio_min' => 12,
                'acc_ratio_min' => 5,
                'dominance_gap_min' => 0,
                'turnover_acceleration_min' => 0.2,
                'intraday_close_vs_open_pct_min' => -4.0,
                'intraday_range_pct_max' => 28.0,
                'breakout_pct_min' => -50,
                'breakout_pct_max' => 6,
                'extension_pct_max' => 8.0,
            ]);
        }

        if ($profile === 'fast_v5') {
            return array_merge($base, [
                'profile' => 'fast_v5',
                'profile_label' => 'Fast Trade V5',
                'score_min' => 71,
                'dist_days_max' => 5,
                'buy_market_share_min' => 65,
                'clean_ratio_min' => 10,
                'repeat_ratio_min' => 12,
                'acc_ratio_min' => 5,
                'dominance_gap_min' => 0,
                'turnover_acceleration_min' => 0.2,
                'intraday_close_vs_open_pct_min' => -4.0,
                'intraday_range_pct_max' => 30.0,
                'breakout_pct_min' => -50,
                'breakout_pct_max' => 12,
                'extension_pct_max' => 12.0,
            ]);
        }

        if ($profile === 'fast_v6') {
            return array_merge($base, [
                'profile' => 'fast_v6',
                'profile_label' => 'Fast Trade V6',
                'score_min' => 78,
                'dist_days_max' => 7,
                'buy_market_share_min' => 75,
                'clean_ratio_min' => 25,
                'repeat_ratio_min' => 20,
                'acc_ratio_min' => 20,
                'dominance_gap_min' => 5,
                'turnover_acceleration_min' => 0.2,
                'intraday_close_vs_open_pct_min' => -4.0,
                'intraday_range_pct_max' => 32.0,
                'breakout_pct_min' => -20,
                'breakout_pct_max' => 12,
                'extension_pct_max' => 25.0,
                'alt_buy_market_share_min' => 68,
                'alt_dominance_gap_min' => 15,
                'alt_turnover_acceleration_min' => 2.0,
            ]);
        }

        if ($profile === 'fast_v7') {
            return array_merge($base, [
                'profile' => 'fast_v7',
                'profile_label' => 'Fast Trade V7',
                'score_min' => 80,
                'dist_days_max' => 4,
                'buy_market_share_min' => 80,
                'clean_ratio_min' => 35,
                'repeat_ratio_min' => 25,
                'acc_ratio_min' => 25,
                'dominance_gap_min' => 10,
                'turnover_acceleration_min' => 0.3,
                'intraday_close_vs_open_pct_min' => -4.0,
                'intraday_range_pct_max' => 32.0,
                'breakout_pct_min' => -18,
                'breakout_pct_max' => 8,
                'extension_pct_max' => 12.0,
                'alt_buy_market_share_min' => 75,
                'alt_dominance_gap_min' => 15,
                'alt_turnover_acceleration_min' => 3.0,
            ]);
        }

        return array_merge($base, [
            'profile' => 'swing',
            'profile_label' => 'Swing',
        ]);
    }

    public static function profiles(): array
    {
        return ['swing', 'fast', 'fast_v2', 'fast_v3', 'fast_v4', 'fast_v5', 'fast_v6', 'fast_v7'];
    }

    public static function filter(array $items, string $profile = 'swing'): array
    {
        $filtered = [];

        foreach ($items as $item) {
            if (!self::passes($item, $profile)) {
                continue;
            }

            $item['next_day_reasons'] = self::reasons($item, $profile);
            $filtered[] = $item;
        }

        usort($filtered, static function (array $left, array $right): int {
            $leftAcceleration = (float) (($left['metrics'] ?? [])['turnover_acceleration'] ?? 0);
            $rightAcceleration = (float) (($right['metrics'] ?? [])['turnover_acceleration'] ?? 0);

            return ($right['score'] <=> $left['score'])
                ?: ($rightAcceleration <=> $leftAcceleration)
                ?: strcmp((string) ($left['symbol'] ?? ''), (string) ($right['symbol'] ?? ''));
        });

        return $filtered;
    }

    public static function passes(array $item, string $profile = 'swing'): bool
    {
        $rules = self::rules($profile);
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];

        $score = (float) ($item['score'] ?? 0);
        $distDays = (int) ($enrichment['dist_days'] ?? 0);
        $buyMarketShare = (float) ($metrics['buy_market_share'] ?? 0);
        $cleanRatio = (float) ($metrics['clean_ratio'] ?? 0);
        $repeatRatio = (float) ($metrics['repeat_ratio'] ?? 0);
        $accRatio = (float) ($metrics['acc_ratio'] ?? 0);
        $dominanceGap = (float) ($metrics['dominance_gap'] ?? 0);
        $turnoverAcceleration = (float) ($metrics['turnover_acceleration'] ?? 0);
        $closeVsOpen = (float) ($metrics['intraday_close_vs_open_pct'] ?? 0);
        $intradayRange = (float) ($metrics['intraday_range_pct'] ?? 0);
        $breakoutPct = (float) ($metrics['breakout_pct'] ?? 0);
        $extensionPct = (float) ($metrics['extension_pct'] ?? 0);
        $hasIntradaySignal = abs($closeVsOpen) > 0.0001 || abs($intradayRange) > 0.0001;

        if (
            $score < $rules['score_min']
            || $distDays > $rules['dist_days_max']
            || $cleanRatio < $rules['clean_ratio_min']
            || $repeatRatio < $rules['repeat_ratio_min']
            || $accRatio < $rules['acc_ratio_min']
            || $turnoverAcceleration < $rules['turnover_acceleration_min']
            || $breakoutPct < $rules['breakout_pct_min']
            || $breakoutPct > $rules['breakout_pct_max']
            || $extensionPct > $rules['extension_pct_max']
        ) {
            return false;
        }

        if ($profile === 'fast_v6' || $profile === 'fast_v7') {
            $primaryFlow = $buyMarketShare >= $rules['buy_market_share_min']
                && $dominanceGap >= $rules['dominance_gap_min'];
            $altFlow = $buyMarketShare >= ($rules['alt_buy_market_share_min'] ?? $rules['buy_market_share_min'])
                && $dominanceGap >= ($rules['alt_dominance_gap_min'] ?? $rules['dominance_gap_min'])
                && $turnoverAcceleration >= ($rules['alt_turnover_acceleration_min'] ?? $rules['turnover_acceleration_min']);

            if (!$primaryFlow && !$altFlow) {
                return false;
            }
        } elseif (
            $buyMarketShare < $rules['buy_market_share_min']
            || $dominanceGap < $rules['dominance_gap_min']
        ) {
            return false;
        }

        if ($hasIntradaySignal) {
            return $closeVsOpen >= $rules['intraday_close_vs_open_pct_min']
                && $intradayRange <= $rules['intraday_range_pct_max'];
        }

        return true;
    }

    public static function reasons(array $item, string $profile = 'swing'): array
    {
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $rules = self::rules($profile);

        return [
            sprintf('Profil %s', $rules['profile_label']),
            sprintf('Score %s dengan clean accumulation %s%%', number_format((float) ($item['score'] ?? 0), 1, ',', '.'), number_format((float) ($metrics['clean_ratio'] ?? 0), 2, ',', '.')),
            sprintf('Buy share %s%% dan dominance gap %s%%', number_format((float) ($metrics['buy_market_share'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['dominance_gap'] ?? 0), 2, ',', '.')),
            sprintf('Repeat broker %s%%, acc ratio %s%%, distribusi %d hari', number_format((float) ($metrics['repeat_ratio'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['acc_ratio'] ?? 0), 2, ',', '.'), (int) ($enrichment['dist_days'] ?? 0)),
            sprintf('Dominance gap %s%% dan turnover accel %sx', number_format((float) ($metrics['dominance_gap'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['turnover_acceleration'] ?? 0), 2, ',', '.')),
            sprintf('Close vs open %s%%, range intraday %s%%', number_format((float) ($metrics['intraday_close_vs_open_pct'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['intraday_range_pct'] ?? 0), 2, ',', '.')),
            sprintf('Breakout %s%% dan extension %s%%', number_format((float) ($metrics['breakout_pct'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['extension_pct'] ?? 0), 2, ',', '.')),
        ];
    }

    public static function failures(array $item, string $profile = 'swing'): array
    {
        $rules = self::rules($profile);
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $failures = [];

        $checks = [
            'score' => (float) ($item['score'] ?? 0) >= $rules['score_min'],
            'dist' => (int) ($enrichment['dist_days'] ?? 0) <= $rules['dist_days_max'],
            'buy_share' => (float) ($metrics['buy_market_share'] ?? 0) >= $rules['buy_market_share_min'],
            'clean' => (float) ($metrics['clean_ratio'] ?? 0) >= $rules['clean_ratio_min'],
            'repeat' => (float) ($metrics['repeat_ratio'] ?? 0) >= $rules['repeat_ratio_min'],
            'acc' => (float) ($metrics['acc_ratio'] ?? 0) >= $rules['acc_ratio_min'],
            'gap' => (float) ($metrics['dominance_gap'] ?? 0) >= $rules['dominance_gap_min'],
            'turn' => (float) ($metrics['turnover_acceleration'] ?? 0) >= $rules['turnover_acceleration_min'],
            'breakout_min' => (float) ($metrics['breakout_pct'] ?? 0) >= $rules['breakout_pct_min'],
            'breakout_max' => (float) ($metrics['breakout_pct'] ?? 0) <= $rules['breakout_pct_max'],
            'ext' => (float) ($metrics['extension_pct'] ?? 0) <= $rules['extension_pct_max'],
        ];

        if (!$checks['score']) {
            $failures[] = sprintf('Score masih di bawah %s', $rules['score_min']);
        }
        if (!$checks['dist']) {
            $failures[] = sprintf('Distribusi lebih dari %d hari', $rules['dist_days_max']);
        }
        if ($profile === 'fast_v6' || $profile === 'fast_v7') {
            $primaryFlow = (float) ($metrics['buy_market_share'] ?? 0) >= $rules['buy_market_share_min']
                && (float) ($metrics['dominance_gap'] ?? 0) >= $rules['dominance_gap_min'];
            $altFlow = (float) ($metrics['buy_market_share'] ?? 0) >= ($rules['alt_buy_market_share_min'] ?? $rules['buy_market_share_min'])
                && (float) ($metrics['dominance_gap'] ?? 0) >= ($rules['alt_dominance_gap_min'] ?? $rules['dominance_gap_min'])
                && (float) ($metrics['turnover_acceleration'] ?? 0) >= ($rules['alt_turnover_acceleration_min'] ?? $rules['turnover_acceleration_min']);

            if (!$primaryFlow && !$altFlow) {
                $failures[] = sprintf(
                    'Belum lolos jalur flow utama (%s%%/%s%%) atau jalur momentum kuat (%s%%/%s%%/%sx)',
                    $rules['buy_market_share_min'],
                    $rules['dominance_gap_min'],
                    $rules['alt_buy_market_share_min'] ?? $rules['buy_market_share_min'],
                    $rules['alt_dominance_gap_min'] ?? $rules['dominance_gap_min'],
                    $rules['alt_turnover_acceleration_min'] ?? $rules['turnover_acceleration_min']
                );
            }
        } elseif (!$checks['buy_share']) {
            $failures[] = sprintf('Buy share di bawah %s%%', $rules['buy_market_share_min']);
        }
        if (!$checks['clean']) {
            $failures[] = sprintf('Clean accumulation di bawah %s%%', $rules['clean_ratio_min']);
        }
        if (!$checks['repeat']) {
            $failures[] = sprintf('Repeat broker di bawah %s%%', $rules['repeat_ratio_min']);
        }
        if (!$checks['acc']) {
            $failures[] = sprintf('Acc ratio di bawah %s%%', $rules['acc_ratio_min']);
        }
        if (!in_array($profile, ['fast_v6', 'fast_v7'], true) && !$checks['gap']) {
            $failures[] = sprintf('Dominance gap di bawah %s%%', $rules['dominance_gap_min']);
        }
        if (!$checks['turn']) {
            $failures[] = sprintf('Turnover acceleration di bawah %sx', $rules['turnover_acceleration_min']);
        }
        if (!$checks['breakout_min'] || !$checks['breakout_max']) {
            $failures[] = sprintf('Breakout harus di rentang %s%% s/d %s%%', $rules['breakout_pct_min'], $rules['breakout_pct_max']);
        }
        if (!$checks['ext']) {
            $failures[] = sprintf('Extension melebihi %s%%', $rules['extension_pct_max']);
        }

        $closeVsOpen = (float) ($metrics['intraday_close_vs_open_pct'] ?? 0);
        $intradayRange = (float) ($metrics['intraday_range_pct'] ?? 0);
        $hasIntradaySignal = abs($closeVsOpen) > 0.0001 || abs($intradayRange) > 0.0001;
        if ($hasIntradaySignal) {
            if ($closeVsOpen < $rules['intraday_close_vs_open_pct_min']) {
                $failures[] = sprintf('Close vs open di bawah %s%%', $rules['intraday_close_vs_open_pct_min']);
            }
            if ($intradayRange > $rules['intraday_range_pct_max']) {
                $failures[] = sprintf('Range intraday di atas %s%%', $rules['intraday_range_pct_max']);
            }
        }

        return $failures;
    }
}
