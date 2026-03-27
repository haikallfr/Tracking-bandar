<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ReferenceRepository.php';
require_once __DIR__ . '/../src/OfficialImportService.php';

$type = $argv[1] ?? '';
$path = $argv[2] ?? '';
$extra = $argv[3] ?? '';

if ($type === '' || $path === '') {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php scripts/import-official-csv.php listed_companies /path/file.csv\n");
    fwrite(STDERR, "  php scripts/import-official-csv.php ownership /path/file.csv 2026-03-31\n");
    fwrite(STDERR, "  php scripts/import-official-csv.php corporate_actions /path/file.csv\n");
    exit(1);
}

$service = new OfficialImportService(new ReferenceRepository());

switch ($type) {
    case 'listed_companies':
        $count = $service->importListedCompaniesCsv($path, 'official_listed_companies_csv');
        echo "Imported {$count} listed company rows.\n";
        break;

    case 'ownership':
        if ($extra === '') {
            fwrite(STDERR, "Ownership import membutuhkan effective_date YYYY-MM-DD.\n");
            exit(1);
        }
        $count = $service->importOwnershipCsv($path, $extra, 'official_ownership_csv');
        echo "Imported {$count} ownership rows.\n";
        break;

    case 'corporate_actions':
        $count = $service->importCorporateActionsCsv($path, 'official_corporate_action_csv');
        echo "Imported {$count} corporate action rows.\n";
        break;

    default:
        fwrite(STDERR, "Type tidak dikenali: {$type}\n");
        exit(1);
}
