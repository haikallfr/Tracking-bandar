<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class SectorUniverse
{
    public static function availableSectors(): array
    {
        $stmt = db()->query(
            'SELECT DISTINCT TRIM(sector) AS sector
             FROM symbol_reference
             WHERE TRIM(COALESCE(sector, "")) <> ""
             ORDER BY sector ASC'
        );
        $rows = $stmt->fetchAll();
        $mapped = [];

        foreach ($rows as $row) {
            $sector = trim((string) ($row['sector'] ?? ''));
            if ($sector !== '') {
                $mapped[] = $sector;
            }
        }

        if ($mapped !== []) {
            return array_values(array_unique($mapped));
        }

        return self::presets();
    }

    public static function presets(): array
    {
        return [
            'Perbankan',
            'Batubara',
            'Energi',
            'Konsumsi',
            'Ritel',
            'Properti',
            'Konstruksi & Infrastruktur',
            'Healthcare',
            'Teknologi',
            'Media & Telekomunikasi',
            'Transportasi & Logistik',
            'Basic Materials',
        ];
    }

    public static function bySector(string $sector): array
    {
        return self::bySectorAndSubsector($sector, '');
    }

    public static function availableSubsectors(string $sector): array
    {
        $sector = trim($sector);
        if ($sector === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT DISTINCT TRIM(subsector) AS subsector
             FROM symbol_reference
             WHERE lower(trim(sector)) = lower(trim(:sector))
               AND TRIM(COALESCE(subsector, "")) <> ""
             ORDER BY subsector ASC'
        );
        $stmt->execute([':sector' => $sector]);
        $rows = $stmt->fetchAll();
        $mapped = [];

        foreach ($rows as $row) {
            $subsector = trim((string) ($row['subsector'] ?? ''));
            if ($subsector !== '' && strcasecmp($subsector, $sector) !== 0) {
                $mapped[] = $subsector;
            }
        }

        return array_values(array_unique($mapped));
    }

    public static function bySectorAndSubsector(string $sector, string $subsector = ''): array
    {
        $sector = trim($sector);
        $subsector = trim($subsector);
        if ($sector === '') {
            return [];
        }

        $mapped = self::mappedBySector($sector, $subsector);
        if ($mapped !== []) {
            return $mapped;
        }

        if ($subsector !== '') {
            return [];
        }

        $keywords = self::keywords($sector);
        if ($keywords === []) {
            return [];
        }

        $stmt = db()->query('SELECT symbol, company_name, listing_board FROM symbol_reference ORDER BY symbol ASC');
        $rows = $stmt->fetchAll();
        $matches = [];

        foreach ($rows as $row) {
            $company = strtolower(trim((string) ($row['company_name'] ?? '')));
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($company === '' || $symbol === '') {
                continue;
            }

            foreach ($keywords as $keyword) {
                if (str_contains($company, $keyword)) {
                    $matches[] = [
                        'symbol' => $symbol,
                        'company_name' => (string) ($row['company_name'] ?? ''),
                        'listing_board' => (string) ($row['listing_board'] ?? ''),
                        'sector' => $sector,
                    ];
                    break;
                }
            }
        }

        return $matches;
    }

    private static function mappedBySector(string $sector, string $subsector = ''): array
    {
        if ($subsector !== '') {
            $stmt = db()->prepare(
                'SELECT symbol, company_name, listing_board, sector, subsector
                 FROM symbol_reference
                 WHERE lower(trim(sector)) = lower(trim(:sector))
                   AND lower(trim(subsector)) = lower(trim(:subsector))
                 ORDER BY symbol ASC'
            );
            $stmt->execute([
                ':sector' => $sector,
                ':subsector' => $subsector,
            ]);
        } else {
            $stmt = db()->prepare(
                'SELECT symbol, company_name, listing_board, sector, subsector
                 FROM symbol_reference
                 WHERE lower(trim(sector)) = lower(trim(:sector))
                    OR lower(trim(subsector)) = lower(trim(:sector))
                 ORDER BY symbol ASC'
            );
            $stmt->execute([':sector' => $sector]);
        }

        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private static function keywords(string $sector): array
    {
        return match (mb_strtolower($sector)) {
            'perbankan' => ['bank ', ' bank', 'bank perkreditan', 'bank indonesia tbk', 'bank raya', 'bank central', 'bank rakyat', 'bank negara'],
            'batubara' => [' coal', ' coal ', 'batubara', 'bukit asam', 'resource', 'resources', 'mining', 'tambang'],
            'energi' => [' energi', 'energy', 'minyak', 'gas', 'geothermal', 'power', 'listrik', 'resource', 'resources'],
            'konsumsi' => [' consumer', 'food', 'foods', 'beverage', 'indofood', 'mayora', 'ultrajaya', 'tobacco', 'farmasi'],
            'ritel' => [' retail', 'mart', 'store', 'hero', 'map ', 'mitra', 'ranch'],
            'properti' => [' property', 'properti', 'land', 'realty', 'development', 'developments', 'ciputra', 'pakuwon', 'sentul'],
            'konstruksi & infrastruktur' => [' konstruksi', 'construction', 'infrastruktur', 'infrastructure', 'wijaya', 'adhi', 'pp ', 'jasa', 'toll', 'tower'],
            'healthcare' => [' medika', 'medikaloka', 'hermina', 'siloam', 'health', 'healthcare', 'laboratories', 'diagnostic', 'farmasi'],
            'teknologi' => [' teknologi', 'technology', 'tech', 'dci', 'm cash', 'digital', 'computindo', 'multipolar'],
            'media & telekomunikasi' => [' telekom', 'telecommunication', 'media', 'vision', 'surya citra', 'sarana menara', 'tower', 'broadcast'],
            'transportasi & logistik' => [' logistik', 'logistic', 'shipping', 'transport', 'cargo', 'airlines', 'pelayaran', 'samudera'],
            'basic materials' => [' cement', 'semen', 'kimia', 'chemical', 'steel', 'metal', 'nickel', 'smelter', 'materials', 'alum', 'basic materials'],
            default => [],
        };
    }
}
