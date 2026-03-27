<?php

declare(strict_types=1);

require_once __DIR__ . '/ReferenceRepository.php';

final class OfficialImportService
{
    public function __construct(
        private readonly ReferenceRepository $repository,
    ) {
    }

    public function importListedCompaniesCsv(string $path, string $source = 'official_csv'): int
    {
        $rows = $this->readCsv($path);
        $count = 0;

        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? $row['code'] ?? '')));
            if ($symbol === '') {
                continue;
            }

            $this->repository->saveSymbolReference(
                $symbol,
                trim((string) ($row['company_name'] ?? $row['name'] ?? '')),
                trim((string) ($row['listing_board'] ?? $row['board'] ?? '')),
                (float) ($row['listed_shares'] ?? $row['shares'] ?? 0),
                trim((string) ($row['sector'] ?? '')),
                trim((string) ($row['subsector'] ?? $row['sub_sector'] ?? '')),
                $source
            );
            $count++;
        }

        return $count;
    }

    public function importOwnershipCsv(string $path, string $effectiveDate, string $source = 'official_csv'): int
    {
        $rows = $this->readCsv($path);
        $count = 0;

        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? $row['code'] ?? '')));
            $ownerName = trim((string) ($row['owner_name'] ?? $row['shareholder'] ?? $row['owner'] ?? ''));
            if ($symbol === '' || $ownerName === '') {
                continue;
            }

            $this->repository->saveOwnershipReference(
                $symbol,
                $ownerName,
                trim((string) ($row['owner_type'] ?? $row['type'] ?? '')),
                (float) ($row['ownership_pct'] ?? $row['percentage'] ?? $row['pct'] ?? 0),
                (float) ($row['ownership_shares'] ?? $row['shares'] ?? 0),
                $effectiveDate,
                $source
            );
            $count++;
        }

        return $count;
    }

    public function importCorporateActionsCsv(string $path, string $source = 'official_csv'): int
    {
        $rows = $this->readCsv($path);
        $count = 0;

        foreach ($rows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? $row['code'] ?? '')));
            $actionType = trim((string) ($row['action_type'] ?? $row['type'] ?? ''));
            $actionDate = trim((string) ($row['action_date'] ?? $row['date'] ?? ''));
            if ($symbol === '' || $actionType === '' || $actionDate === '') {
                continue;
            }

            $this->repository->saveCorporateAction(
                $symbol,
                $actionType,
                $actionDate,
                trim((string) ($row['headline'] ?? $row['title'] ?? '')),
                $row,
                $source
            );
            $count++;
        }

        return $count;
    }

    private function readCsv(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('File tidak ditemukan: ' . $path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Gagal membuka file: ' . $path);
        }

        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header)) {
            fclose($handle);
            throw new RuntimeException('Header CSV tidak valid: ' . $path);
        }

        $normalizedHeader = array_map(
            static fn (string $value): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $value) ?? '')),
            $header
        );

        $rows = [];
        while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($line === [null] || $line === []) {
                continue;
            }

            $rows[] = array_combine($normalizedHeader, array_pad($line, count($normalizedHeader), '')) ?: [];
        }

        fclose($handle);

        return $rows;
    }
}
