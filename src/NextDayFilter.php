<?php

declare(strict_types=1);

final class NextDayFilter
{
    public static function rules(): array
    {
        return [
            'score_min' => 95,
            'dist_days_max' => 1,
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
    }

    public static function filter(array $items): array
    {
        $filtered = [];

        foreach ($items as $item) {
            if (!self::passes($item)) {
                continue;
            }

            $item['next_day_reasons'] = self::reasons($item);
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

    public static function passes(array $item): bool
    {
        $rules = self::rules();
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];

        $score = (float) ($item['score'] ?? 0);
        $distDays = (int) ($enrichment['dist_days'] ?? 0);
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
            || $dominanceGap < $rules['dominance_gap_min']
            || $turnoverAcceleration < $rules['turnover_acceleration_min']
            || $breakoutPct < $rules['breakout_pct_min']
            || $breakoutPct > $rules['breakout_pct_max']
            || $extensionPct > $rules['extension_pct_max']
        ) {
            return false;
        }

        if ($hasIntradaySignal) {
            return $closeVsOpen >= $rules['intraday_close_vs_open_pct_min']
                && $intradayRange <= $rules['intraday_range_pct_max'];
        }

        return true;
    }

    public static function reasons(array $item): array
    {
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];

        return [
            sprintf('Score %s dengan clean accumulation %s%%', number_format((float) ($item['score'] ?? 0), 1, ',', '.'), number_format((float) ($metrics['clean_ratio'] ?? 0), 2, ',', '.')),
            sprintf('Repeat broker %s%%, acc ratio %s%%, distribusi %d hari', number_format((float) ($metrics['repeat_ratio'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['acc_ratio'] ?? 0), 2, ',', '.'), (int) ($enrichment['dist_days'] ?? 0)),
            sprintf('Dominance gap %s%% dan turnover accel %sx', number_format((float) ($metrics['dominance_gap'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['turnover_acceleration'] ?? 0), 2, ',', '.')),
            sprintf('Close vs open %s%%, range intraday %s%%', number_format((float) ($metrics['intraday_close_vs_open_pct'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['intraday_range_pct'] ?? 0), 2, ',', '.')),
            sprintf('Breakout %s%% dan extension %s%%', number_format((float) ($metrics['breakout_pct'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['extension_pct'] ?? 0), 2, ',', '.')),
        ];
    }

    public static function failures(array $item): array
    {
        $rules = self::rules();
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $failures = [];

        $checks = [
            'score' => (float) ($item['score'] ?? 0) >= $rules['score_min'],
            'dist' => (int) ($enrichment['dist_days'] ?? 0) <= $rules['dist_days_max'],
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
        if (!$checks['clean']) {
            $failures[] = sprintf('Clean accumulation di bawah %s%%', $rules['clean_ratio_min']);
        }
        if (!$checks['repeat']) {
            $failures[] = sprintf('Repeat broker di bawah %s%%', $rules['repeat_ratio_min']);
        }
        if (!$checks['acc']) {
            $failures[] = sprintf('Acc ratio di bawah %s%%', $rules['acc_ratio_min']);
        }
        if (!$checks['gap']) {
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
