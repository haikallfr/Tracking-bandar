<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class BrokerHistoricalRepository
{
    public function save(string $symbol, string $brokerCode, array $payload): void
    {
        $stmt = db()->prepare(
            'INSERT INTO broker_historical_lookup(symbol, broker_code, payload, updated_at)
             VALUES (:symbol, :broker_code, :payload, :updated_at)
             ON CONFLICT(symbol, broker_code) DO UPDATE SET
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':broker_code' => strtoupper($brokerCode),
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function find(string $symbol, string $brokerCode): ?array
    {
        $stmt = db()->prepare(
            'SELECT payload, updated_at
             FROM broker_historical_lookup
             WHERE symbol = :symbol AND broker_code = :broker_code
             LIMIT 1'
        );
        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':broker_code' => strtoupper($brokerCode),
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'updated_at' => $row['updated_at'],
            'payload' => json_decode($row['payload'], true) ?? [],
        ];
    }
}
