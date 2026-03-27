<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ReferenceRepository.php';
require_once __DIR__ . '/../src/OfficialImportService.php';

$sourceUrl = 'https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/master/List%20Emiten/all.csv';
$tempPath = STORAGE_DIR . '/imports-listed-companies.csv';

$body = file_get_contents($sourceUrl);
if ($body === false || trim($body) === '') {
    fwrite(STDERR, "Gagal mengambil mirror listed companies.\n");
    exit(1);
}

file_put_contents($tempPath, $body);

$service = new OfficialImportService(new ReferenceRepository());
$count = $service->importListedCompaniesCsv($tempPath, 'mirror_listed_companies');

echo "Imported {$count} symbol references from mirror.\n";
