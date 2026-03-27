<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class HistoricalRepository
{
    public function saveDailySnapshot(string $symbol, string $tradeDate, array $payload, float $marketValue, float $marketVolume): void
    {
        $stmt = db()->prepare(
            'INSERT INTO broker_daily_history(symbol, trade_date, payload, market_value, market_volume, updated_at)
             VALUES (:symbol, :trade_date, :payload, :market_value, :market_volume, :updated_at)
             ON CONFLICT(symbol, trade_date) DO UPDATE SET
                payload = excluded.payload,
                market_value = excluded.market_value,
                market_volume = excluded.market_volume,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':trade_date' => $tradeDate,
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':market_value' => $marketValue,
            ':market_volume' => $marketVolume,
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function saveDailySnapshotsBatch(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $stmt = db()->prepare(
            'INSERT INTO broker_daily_history(symbol, trade_date, payload, market_value, market_volume, updated_at)
             VALUES (:symbol, :trade_date, :payload, :market_value, :market_volume, :updated_at)
             ON CONFLICT(symbol, trade_date) DO UPDATE SET
                payload = excluded.payload,
                market_value = excluded.market_value,
                market_volume = excluded.market_volume,
                updated_at = excluded.updated_at'
        );

        db()->beginTransaction();
        try {
            foreach ($entries as $entry) {
                $stmt->execute([
                    ':symbol' => strtoupper((string) ($entry['symbol'] ?? '')),
                    ':trade_date' => (string) ($entry['trade_date'] ?? ''),
                    ':payload' => json_encode($entry['payload'] ?? [], JSON_THROW_ON_ERROR),
                    ':market_value' => (float) ($entry['market_value'] ?? 0),
                    ':market_volume' => (float) ($entry['market_volume'] ?? 0),
                    ':updated_at' => (string) ($entry['updated_at'] ?? gmdate(DATE_ATOM)),
                ]);
            }
            db()->commit();
        } catch (Throwable $error) {
            db()->rollBack();
            throw $error;
        }
    }

    public function dailySnapshots(string $symbol, int $limit = 20): array
    {
        $stmt = db()->prepare(
            'SELECT trade_date, payload, market_value, market_volume, updated_at
             FROM broker_daily_history
             WHERE symbol = :symbol
             ORDER BY trade_date DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':symbol', strtoupper($symbol), PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static function (array $row): array {
            return [
                'trade_date' => $row['trade_date'],
                'market_value' => (float) $row['market_value'],
                'market_volume' => (float) $row['market_volume'],
                'updated_at' => $row['updated_at'],
                'payload' => json_decode($row['payload'], true) ?? [],
            ];
        }, $stmt->fetchAll());
    }

    public function savePriceFeed(string $symbol, string $timeframe, array $payload): void
    {
        $stmt = db()->prepare(
            'INSERT INTO price_feed_cache(symbol, timeframe, payload, updated_at)
             VALUES (:symbol, :timeframe, :payload, :updated_at)
             ON CONFLICT(symbol, timeframe) DO UPDATE SET
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':timeframe' => $timeframe,
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function priceFeed(string $symbol, string $timeframe): ?array
    {
        $stmt = db()->prepare(
            'SELECT payload, updated_at FROM price_feed_cache WHERE symbol = :symbol AND timeframe = :timeframe LIMIT 1'
        );
        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':timeframe' => $timeframe,
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
