<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class NextDayHistoryRepository
{
    public function save(string $symbol, bool $passed, float $score, array $payload, string $source = 'single_search'): void
    {
        $stmt = db()->prepare(
            'INSERT INTO next_day_symbol_history(symbol, source, passed, score, scanned_at, payload)
             VALUES (:symbol, :source, :passed, :score, :scanned_at, :payload)'
        );

        $stmt->execute([
            ':symbol' => strtoupper($symbol),
            ':source' => $source,
            ':passed' => $passed ? 1 : 0,
            ':score' => $score,
            ':scanned_at' => gmdate(DATE_ATOM),
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    public function recent(int $limit = 20): array
    {
        $stmt = db()->prepare(
            'SELECT symbol, source, passed, score, scanned_at
             FROM next_day_symbol_history
             ORDER BY id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function count(): int
    {
        return (int) db()->query('SELECT COUNT(*) FROM next_day_symbol_history')->fetchColumn();
    }
}
