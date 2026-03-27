<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class BrokerRepository
{
    public function save(string $symbol, array $payload): void
    {
        $stmt = db()->prepare(
            'INSERT INTO broker_cache(symbol, payload, updated_at)
             VALUES (:symbol, :payload, :updated_at)
             ON CONFLICT(symbol) DO UPDATE SET
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function saveBatch(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $stmt = db()->prepare(
            'INSERT INTO broker_cache(symbol, payload, updated_at)
             VALUES (:symbol, :payload, :updated_at)
             ON CONFLICT(symbol) DO UPDATE SET
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        db()->beginTransaction();
        try {
            foreach ($entries as $entry) {
                $stmt->execute([
                    ':symbol' => strtoupper((string) ($entry['symbol'] ?? '')),
                    ':payload' => json_encode($entry['payload'] ?? [], JSON_THROW_ON_ERROR),
                    ':updated_at' => (string) ($entry['updated_at'] ?? gmdate(DATE_ATOM)),
                ]);
            }
            db()->commit();
        } catch (Throwable $error) {
            db()->rollBack();
            throw $error;
        }
    }

    public function all(): array
    {
        $rows = db()->query('SELECT symbol, payload, updated_at FROM broker_cache ORDER BY symbol ASC')->fetchAll();

        return array_map(static function (array $row): array {
            return [
                'symbol' => $row['symbol'],
                'updated_at' => $row['updated_at'],
                'payload' => json_decode($row['payload'], true) ?? [],
            ];
        }, $rows);
    }

    public function updatedAt(): ?string
    {
        $row = db()->query('SELECT MAX(updated_at) AS updated_at FROM broker_cache')->fetch();
        return $row['updated_at'] ?? null;
    }
}
