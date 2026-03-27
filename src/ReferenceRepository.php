<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class ReferenceRepository
{
    public function saveSymbolReference(
        string $symbol,
        string $companyName,
        string $listingBoard,
        float $listedShares,
        string $sector,
        string $subsector,
        string $source
    ): void {
        $stmt = db()->prepare(
            'INSERT INTO symbol_reference(symbol, company_name, listing_board, listed_shares, sector, subsector, source, updated_at)
             VALUES (:symbol, :company_name, :listing_board, :listed_shares, :sector, :subsector, :source, :updated_at)
             ON CONFLICT(symbol) DO UPDATE SET
                company_name = excluded.company_name,
                listing_board = excluded.listing_board,
                listed_shares = excluded.listed_shares,
                sector = excluded.sector,
                subsector = excluded.subsector,
                source = excluded.source,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':company_name' => $companyName,
            ':listing_board' => $listingBoard,
            ':listed_shares' => $listedShares,
            ':sector' => $sector,
            ':subsector' => $subsector,
            ':source' => $source,
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function saveOwnershipReference(
        string $symbol,
        string $ownerName,
        string $ownerType,
        float $ownershipPct,
        float $ownershipShares,
        string $effectiveDate,
        string $source
    ): void {
        $stmt = db()->prepare(
            'INSERT INTO ownership_reference(symbol, owner_name, owner_type, ownership_pct, ownership_shares, effective_date, source, updated_at)
             VALUES (:symbol, :owner_name, :owner_type, :ownership_pct, :ownership_shares, :effective_date, :source, :updated_at)
             ON CONFLICT(symbol, owner_name, effective_date) DO UPDATE SET
                owner_type = excluded.owner_type,
                ownership_pct = excluded.ownership_pct,
                ownership_shares = excluded.ownership_shares,
                source = excluded.source,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':owner_name' => $ownerName,
            ':owner_type' => $ownerType,
            ':ownership_pct' => $ownershipPct,
            ':ownership_shares' => $ownershipShares,
            ':effective_date' => $effectiveDate,
            ':source' => $source,
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function saveCorporateAction(
        string $symbol,
        string $actionType,
        string $actionDate,
        string $headline,
        array $rawPayload,
        string $source
    ): void {
        $stmt = db()->prepare(
            'INSERT INTO corporate_action_reference(symbol, action_type, action_date, headline, source, raw_payload, updated_at)
             VALUES (:symbol, :action_type, :action_date, :headline, :source, :raw_payload, :updated_at)
             ON CONFLICT(symbol, action_type, action_date, headline) DO UPDATE SET
                source = excluded.source,
                raw_payload = excluded.raw_payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':action_type' => $actionType,
            ':action_date' => $actionDate,
            ':headline' => $headline,
            ':source' => $source,
            ':raw_payload' => json_encode($rawPayload, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function counts(): array
    {
        return [
            'symbol_reference' => (int) db()->query('SELECT COUNT(*) FROM symbol_reference')->fetchColumn(),
            'ownership_reference' => (int) db()->query('SELECT COUNT(*) FROM ownership_reference')->fetchColumn(),
            'corporate_action_reference' => (int) db()->query('SELECT COUNT(*) FROM corporate_action_reference')->fetchColumn(),
        ];
    }
}
