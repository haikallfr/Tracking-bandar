<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/CioSectorScanner.php';
require_once __DIR__ . '/../src/SectorUniverse.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$sector = trim((string) ($_GET['sector'] ?? ''));
$subsector = trim((string) ($_GET['subsector'] ?? ''));
$metaOnly = ((string) ($_GET['meta'] ?? '')) === '1';

try {
    if ($sector === '' || $metaOnly) {
        $payload = [
            'ok' => true,
            'sectors' => SectorUniverse::availableSectors(),
            'selected_sector' => $sector,
            'subsectors' => [],
        ];
        if ($sector !== '') {
            $payload['subsectors'] = SectorUniverse::availableSubsectors($sector);
        }
        json_response($payload);
    }

    $result = (new CioSectorScanner())->scan($sector, $subsector);
    json_response([
        'ok' => true,
        'result' => $result,
        'sectors' => SectorUniverse::availableSectors(),
        'subsectors' => SectorUniverse::availableSubsectors($sector),
    ]);
} catch (Throwable $e) {
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
        'sectors' => SectorUniverse::availableSectors(),
        'subsectors' => $sector !== '' ? SectorUniverse::availableSubsectors($sector) : [],
    ], 500);
}
