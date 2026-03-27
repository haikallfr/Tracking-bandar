<?php

declare(strict_types=1);

final class ProbabilityFilter
{
    public static function rules(): array
    {
        return [
            'dist_days_max' => 1,
            'clean_ratio_min' => 65,
            'repeat_ratio_min' => 40,
            'extension_pct_max' => 4,
            'breakout_pct_max' => -7,
            'intraday_range_pct_max' => 7.5,
            'turnover_acceleration_min' => 0.45,
        ];
    }

    public static function filter(array $items): array
    {
        $filtered = [];

        foreach ($items as $item) {
            if (!self::passes($item)) {
                continue;
            }

            $item['elite_reasons'] = self::reasons($item);
            $filtered[] = $item;
        }

        usort($filtered, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: strcmp((string) $left['symbol'], (string) $right['symbol']);
        });

        return $filtered;
    }

    public static function passes(array $item): bool
    {
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $rules = self::rules();

        $distDays = (int) ($enrichment['dist_days'] ?? 0);
        $cleanRatio = (float) ($metrics['clean_ratio'] ?? 0);
        $repeatRatio = (float) ($metrics['repeat_ratio'] ?? 0);
        $extensionPct = (float) ($metrics['extension_pct'] ?? 0);
        $breakoutPct = (float) ($metrics['breakout_pct'] ?? 0);
        $intradayRangePct = (float) ($metrics['intraday_range_pct'] ?? 0);
        $turnoverAcceleration = (float) ($metrics['turnover_acceleration'] ?? 0);

        if ($distDays > $rules['dist_days_max']) {
            return false;
        }

        if ($cleanRatio < $rules['clean_ratio_min']) {
            return false;
        }

        if ($repeatRatio < $rules['repeat_ratio_min']) {
            return false;
        }

        if ($extensionPct > $rules['extension_pct_max']) {
            return false;
        }

        if ($breakoutPct > $rules['breakout_pct_max']) {
            return false;
        }

        if ($intradayRangePct > $rules['intraday_range_pct_max']) {
            return false;
        }

        if ($turnoverAcceleration < $rules['turnover_acceleration_min']) {
            return false;
        }

        return true;
    }

    public static function reasons(array $item): array
    {
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];

        return [
            sprintf('Distribusi maksimal %d hari', (int) ($enrichment['dist_days'] ?? 0)),
            sprintf('Clean accumulation %s%%', number_format((float) ($metrics['clean_ratio'] ?? 0), 2, ',', '.')),
            sprintf('Repeat broker %s%%', number_format((float) ($metrics['repeat_ratio'] ?? 0), 2, ',', '.')),
            sprintf('Breakout %s%% dan extension %s%%', number_format((float) ($metrics['breakout_pct'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['extension_pct'] ?? 0), 2, ',', '.')),
            sprintf('Turnover accel %sx dan intraday range %s%%', number_format((float) ($metrics['turnover_acceleration'] ?? 0), 2, ',', '.'), number_format((float) ($metrics['intraday_range_pct'] ?? 0), 2, ',', '.')),
        ];
    }
}
