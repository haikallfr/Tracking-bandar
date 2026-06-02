<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class KseiRadar
{
    public function dashboard(string $symbol = '', string $search = '', string $tier = 'all'): array
    {
        $latestDate = $this->latestDate();
        $previousDate = $this->previousDate($latestDate);

        return [
            'dates' => [
                'latest' => $latestDate,
                'previous' => $previousDate,
                'available' => $this->availableDates(),
            ],
            'stats' => $this->stats($latestDate),
            'activity' => [
                'top_accumulation' => $this->activity($latestDate, $previousDate, 'accumulation', 18),
                'top_distribution' => $this->activity($latestDate, $previousDate, 'distribution', 18),
                'new_holders' => $this->newHolders($latestDate, $previousDate, 12),
                'exits' => $this->exits($latestDate, $previousDate, 12),
                'repeated_accumulation' => $this->repeatedAccumulation(12),
            ],
            'ownership' => $symbol !== '' ? $this->ownershipMap($symbol, $latestDate) : null,
            'search' => $search !== '' ? $this->search($search, $latestDate) : [],
            'free_float' => $this->freeFloatScreener($latestDate, $tier, 80),
            'notes' => [
                'Sumber data adalah publikasi IDX/KSEI pemegang saham di atas 1%.',
                'Angka free float dihitung dari 100% dikurangi total kepemilikan yang tercatat >1%, sehingga tetap indikator praktis, bukan pengganti laporan free float resmi.',
                'Akumulasi/distribusi membutuhkan minimal dua snapshot tanggal. Semakin sering data KSEI masuk, semakin kuat deteksi pola berulangnya.',
            ],
        ];
    }

    private function latestDate(): string
    {
        return (string) db()->query('SELECT MAX(effective_date) FROM ownership_positions')->fetchColumn();
    }

    private function previousDate(string $latestDate): string
    {
        if ($latestDate === '') {
            return '';
        }

        $stmt = db()->prepare('SELECT MAX(effective_date) FROM ownership_positions WHERE effective_date < :date');
        $stmt->execute([':date' => $latestDate]);
        return (string) $stmt->fetchColumn();
    }

    private function availableDates(): array
    {
        $stmt = db()->query(
            'SELECT effective_date, COUNT(*) AS positions, COUNT(DISTINCT symbol) AS symbols
             FROM ownership_positions
             GROUP BY effective_date
             ORDER BY effective_date DESC'
        );

        return $stmt->fetchAll() ?: [];
    }

    private function stats(string $latestDate): array
    {
        if ($latestDate === '') {
            return [
                'positions' => 0,
                'symbols' => 0,
                'holders' => 0,
                'foreign_pct' => 0,
                'domestic_pct' => 0,
            ];
        }

        $stmt = db()->prepare(
            'SELECT
                COUNT(*) AS positions,
                COUNT(DISTINCT symbol) AS symbols,
                COUNT(DISTINCT owner_name) AS holders,
                SUM(CASE WHEN local_foreign = "F" THEN ownership_pct ELSE 0 END) AS foreign_pct,
                SUM(CASE WHEN local_foreign IN ("L", "D") THEN ownership_pct ELSE 0 END) AS domestic_pct
             FROM ownership_positions
             WHERE effective_date = :date'
        );
        $stmt->execute([':date' => $latestDate]);

        $row = $stmt->fetch() ?: [];
        return [
            'positions' => (int) ($row['positions'] ?? 0),
            'symbols' => (int) ($row['symbols'] ?? 0),
            'holders' => (int) ($row['holders'] ?? 0),
            'foreign_pct' => round((float) ($row['foreign_pct'] ?? 0), 2),
            'domestic_pct' => round((float) ($row['domestic_pct'] ?? 0), 2),
        ];
    }

    private function activity(string $latestDate, string $previousDate, string $mode, int $limit): array
    {
        if ($latestDate === '' || $previousDate === '') {
            return [];
        }

        $operator = $mode === 'distribution' ? '<' : '>';
        $direction = $mode === 'distribution' ? 'ASC' : 'DESC';
        $stmt = db()->prepare(
            "SELECT
                c.symbol,
                COALESCE(NULLIF(s.company_name, ''), c.issuer_name) AS company_name,
                c.owner_name,
                c.owner_type,
                c.local_foreign,
                p.ownership_pct AS previous_pct,
                c.ownership_pct AS current_pct,
                c.ownership_pct - p.ownership_pct AS delta_pct,
                c.total_holding_shares - p.total_holding_shares AS delta_shares
             FROM ownership_positions c
             JOIN ownership_positions p
               ON p.symbol = c.symbol
              AND p.owner_name = c.owner_name
              AND p.effective_date = :previous_date
             LEFT JOIN symbol_reference s ON s.symbol = c.symbol
             WHERE c.effective_date = :latest_date
               AND ABS(c.ownership_pct - p.ownership_pct) >= 0.01
               AND c.ownership_pct - p.ownership_pct {$operator} 0
             ORDER BY delta_pct {$direction}
             LIMIT :limit"
        );
        $stmt->bindValue(':latest_date', $latestDate);
        $stmt->bindValue(':previous_date', $previousDate);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'normalizeActivityRow'], $stmt->fetchAll() ?: []);
    }

    private function newHolders(string $latestDate, string $previousDate, int $limit): array
    {
        if ($latestDate === '' || $previousDate === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT c.symbol,
                    COALESCE(NULLIF(s.company_name, ""), c.issuer_name) AS company_name,
                    c.owner_name,
                    c.owner_type,
                    c.local_foreign,
                    0 AS previous_pct,
                    c.ownership_pct AS current_pct,
                    c.ownership_pct AS delta_pct,
                    c.total_holding_shares AS delta_shares
             FROM ownership_positions c
             LEFT JOIN ownership_positions p
               ON p.symbol = c.symbol
              AND p.owner_name = c.owner_name
              AND p.effective_date = :previous_date
             LEFT JOIN symbol_reference s ON s.symbol = c.symbol
             WHERE c.effective_date = :latest_date
               AND p.symbol IS NULL
             ORDER BY c.ownership_pct DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':latest_date', $latestDate);
        $stmt->bindValue(':previous_date', $previousDate);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'normalizeActivityRow'], $stmt->fetchAll() ?: []);
    }

    private function exits(string $latestDate, string $previousDate, int $limit): array
    {
        if ($latestDate === '' || $previousDate === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT p.symbol,
                    COALESCE(NULLIF(s.company_name, ""), p.issuer_name) AS company_name,
                    p.owner_name,
                    p.owner_type,
                    p.local_foreign,
                    p.ownership_pct AS previous_pct,
                    0 AS current_pct,
                    -p.ownership_pct AS delta_pct,
                    -p.total_holding_shares AS delta_shares
             FROM ownership_positions p
             LEFT JOIN ownership_positions c
               ON c.symbol = p.symbol
              AND c.owner_name = p.owner_name
              AND c.effective_date = :latest_date
             LEFT JOIN symbol_reference s ON s.symbol = p.symbol
             WHERE p.effective_date = :previous_date
               AND c.symbol IS NULL
             ORDER BY p.ownership_pct DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':latest_date', $latestDate);
        $stmt->bindValue(':previous_date', $previousDate);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'normalizeActivityRow'], $stmt->fetchAll() ?: []);
    }

    private function repeatedAccumulation(int $limit): array
    {
        $stmt = db()->query(
            'SELECT symbol, owner_name, effective_date, ownership_pct, total_holding_shares
             FROM ownership_positions
             ORDER BY symbol ASC, owner_name ASC, effective_date ASC'
        );

        $series = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $key = $row['symbol'] . '|' . $row['owner_name'];
            $series[$key][] = $row;
        }

        $items = [];
        foreach ($series as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $positive = 0;
            $deltaPct = 0.0;
            $deltaShares = 0.0;
            for ($i = 1; $i < count($rows); $i++) {
                $step = (float) $rows[$i]['ownership_pct'] - (float) $rows[$i - 1]['ownership_pct'];
                if ($step > 0) {
                    $positive++;
                    $deltaPct += $step;
                    $deltaShares += (float) $rows[$i]['total_holding_shares'] - (float) $rows[$i - 1]['total_holding_shares'];
                }
            }

            if ($positive < 1) {
                continue;
            }

            $first = $rows[0];
            $last = $rows[count($rows) - 1];
            $items[] = [
                'symbol' => (string) $last['symbol'],
                'owner_name' => (string) $last['owner_name'],
                'periods' => $positive,
                'start_pct' => round((float) $first['ownership_pct'], 2),
                'current_pct' => round((float) $last['ownership_pct'], 2),
                'delta_pct' => round($deltaPct, 2),
                'delta_shares' => round($deltaShares),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['delta_pct'] <=> $a['delta_pct']);
        return array_slice($items, 0, $limit);
    }

    private function ownershipMap(string $symbol, string $latestDate): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '' || $latestDate === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT p.*,
                    COALESCE(NULLIF(s.company_name, ""), p.issuer_name) AS company_name,
                    s.sector,
                    s.subsector
             FROM ownership_positions p
             LEFT JOIN symbol_reference s ON s.symbol = p.symbol
             WHERE p.symbol = :symbol
               AND p.effective_date = :date
             ORDER BY p.ownership_pct DESC'
        );
        $stmt->execute([':symbol' => $symbol, ':date' => $latestDate]);
        $holders = $stmt->fetchAll() ?: [];
        if ($holders === []) {
            return ['symbol' => $symbol, 'holders' => [], 'message' => 'Data kepemilikan belum tersedia untuk simbol ini.'];
        }

        $totalRecorded = array_sum(array_map(static fn (array $row): float => (float) $row['ownership_pct'], $holders));
        $foreign = array_sum(array_map(static fn (array $row): float => $row['local_foreign'] === 'F' ? (float) $row['ownership_pct'] : 0.0, $holders));
        $domestic = array_sum(array_map(static fn (array $row): float => in_array($row['local_foreign'], ['L', 'D'], true) ? (float) $row['ownership_pct'] : 0.0, $holders));
        $typeBreakdown = [];

        foreach ($holders as $row) {
            $label = $this->ownerTypeLabel((string) $row['owner_type']);
            $typeBreakdown[$label] = ($typeBreakdown[$label] ?? 0) + (float) $row['ownership_pct'];
        }
        arsort($typeBreakdown);

        return [
            'symbol' => $symbol,
            'company_name' => (string) ($holders[0]['company_name'] ?? ''),
            'sector' => (string) ($holders[0]['sector'] ?? ''),
            'subsector' => (string) ($holders[0]['subsector'] ?? ''),
            'effective_date' => $latestDate,
            'recorded_pct' => round($totalRecorded, 2),
            'free_float_pct' => $this->freeFloat($totalRecorded),
            'liquidity_status' => $this->liquidityStatus($this->freeFloat($totalRecorded)),
            'foreign_pct' => round($foreign, 2),
            'domestic_pct' => round($domestic, 2),
            'top_holder' => [
                'name' => (string) ($holders[0]['owner_name'] ?? ''),
                'pct' => round((float) ($holders[0]['ownership_pct'] ?? 0), 2),
            ],
            'type_breakdown' => array_map(
                static fn (string $label, float $pct): array => ['label' => $label, 'pct' => round($pct, 2)],
                array_keys($typeBreakdown),
                $typeBreakdown
            ),
            'holders' => array_map([$this, 'normalizeHolderRow'], $holders),
        ];
    }

    private function search(string $search, string $latestDate): array
    {
        if ($latestDate === '') {
            return [];
        }

        $needle = '%' . strtoupper(trim($search)) . '%';
        $stmt = db()->prepare(
            'SELECT p.symbol,
                    COALESCE(NULLIF(s.company_name, ""), p.issuer_name) AS company_name,
                    MAX(p.ownership_pct) AS max_holder_pct,
                    SUM(p.ownership_pct) AS recorded_pct,
                    COUNT(*) AS holders
             FROM ownership_positions p
             LEFT JOIN symbol_reference s ON s.symbol = p.symbol
             WHERE p.effective_date = :date
               AND (
                    p.symbol LIKE :needle
                 OR UPPER(p.owner_name) LIKE :needle
                 OR UPPER(COALESCE(s.company_name, p.issuer_name)) LIKE :needle
               )
             GROUP BY p.symbol
             ORDER BY p.symbol ASC
             LIMIT 24'
        );
        $stmt->execute([':date' => $latestDate, ':needle' => $needle]);

        return array_map(static function (array $row): array {
            $recorded = (float) ($row['recorded_pct'] ?? 0);
            return [
                'symbol' => (string) $row['symbol'],
                'company_name' => (string) $row['company_name'],
                'holders' => (int) $row['holders'],
                'max_holder_pct' => round((float) $row['max_holder_pct'], 2),
                'recorded_pct' => round($recorded, 2),
                'free_float_pct' => round(max(0, min(100, 100 - $recorded)), 2),
            ];
        }, $stmt->fetchAll() ?: []);
    }

    private function freeFloatScreener(string $latestDate, string $tier, int $limit): array
    {
        if ($latestDate === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT p.symbol,
                    COALESCE(NULLIF(s.company_name, ""), MAX(p.issuer_name)) AS company_name,
                    SUM(p.ownership_pct) AS recorded_pct,
                    COUNT(*) AS holders,
                    MAX(p.ownership_pct) AS top_holder_pct
             FROM ownership_positions p
             LEFT JOIN symbol_reference s ON s.symbol = p.symbol
             WHERE p.effective_date = :date
             GROUP BY p.symbol
             ORDER BY recorded_pct DESC'
        );
        $stmt->execute([':date' => $latestDate]);

        $items = [];
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $recorded = (float) ($row['recorded_pct'] ?? 0);
            $freeFloat = $this->freeFloat($recorded);
            $status = $this->liquidityStatus($freeFloat);
            if (!$this->tierMatches($tier, $freeFloat)) {
                continue;
            }

            $items[] = [
                'symbol' => (string) $row['symbol'],
                'company_name' => (string) $row['company_name'],
                'recorded_pct' => round($recorded, 2),
                'free_float_pct' => $freeFloat,
                'holders' => (int) $row['holders'],
                'top_holder_pct' => round((float) $row['top_holder_pct'], 2),
                'status' => $status,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['free_float_pct'] <=> $b['free_float_pct']);
        return array_slice($items, 0, $limit);
    }

    private function normalizeActivityRow(array $row): array
    {
        $deltaShares = (float) ($row['delta_shares'] ?? 0);
        return [
            'symbol' => (string) ($row['symbol'] ?? ''),
            'company_name' => (string) ($row['company_name'] ?? ''),
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_type' => (string) ($row['owner_type'] ?? ''),
            'owner_type_label' => $this->ownerTypeLabel((string) ($row['owner_type'] ?? '')),
            'local_foreign' => (string) ($row['local_foreign'] ?? ''),
            'previous_pct' => round((float) ($row['previous_pct'] ?? 0), 2),
            'current_pct' => round((float) ($row['current_pct'] ?? 0), 2),
            'delta_pct' => round((float) ($row['delta_pct'] ?? 0), 2),
            'delta_shares' => round($deltaShares),
        ];
    }

    private function normalizeHolderRow(array $row): array
    {
        return [
            'owner_name' => (string) ($row['owner_name'] ?? ''),
            'owner_type' => (string) ($row['owner_type'] ?? ''),
            'owner_type_label' => $this->ownerTypeLabel((string) ($row['owner_type'] ?? '')),
            'local_foreign' => (string) ($row['local_foreign'] ?? ''),
            'nationality' => (string) ($row['nationality'] ?? ''),
            'domicile' => (string) ($row['domicile'] ?? ''),
            'shares' => round((float) ($row['total_holding_shares'] ?? 0)),
            'ownership_pct' => round((float) ($row['ownership_pct'] ?? 0), 2),
        ];
    }

    private function ownerTypeLabel(string $type): string
    {
        return match (strtoupper($type)) {
            'CP', 'DCP', 'BCP' => 'Korporasi',
            'ID' => 'Individu',
            'MF', 'SEMF' => 'Reksa Dana',
            'PF' => 'Dana Pensiun',
            'IB', 'SC', 'ASC', 'TIB' => 'Bank/Sekuritas',
            'IS' => 'Institusi',
            'OT' => 'Lainnya',
            default => $type !== '' ? strtoupper($type) : 'Tidak terklasifikasi',
        };
    }

    private function freeFloat(float $recordedPct): float
    {
        return round(max(0, min(100, 100 - $recordedPct)), 2);
    }

    private function liquidityStatus(float $freeFloat): string
    {
        if ($freeFloat >= 40) {
            return 'Liquid';
        }

        if ($freeFloat >= 20) {
            return 'Moderat';
        }

        if ($freeFloat >= 10) {
            return 'Rendah';
        }

        return 'Sangat Rendah';
    }

    private function tierMatches(string $tier, float $freeFloat): bool
    {
        return match ($tier) {
            'liquid' => $freeFloat >= 40,
            'moderate' => $freeFloat >= 20 && $freeFloat < 40,
            'low' => $freeFloat >= 10 && $freeFloat < 20,
            'very_low' => $freeFloat < 10,
            default => true,
        };
    }
}
