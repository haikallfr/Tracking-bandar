<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class SymbolUniverse
{
    private const SOURCE_URL = 'https://raw.githubusercontent.com/wildangunawan/Dataset-Saham-IDX/master/List%20Emiten/all.csv';
    private const CACHE_PATH = STORAGE_DIR . '/symbol-universe.json';
    private const CACHE_TTL = 86400;

    public function all(): array
    {
        $cached = $this->readCache();
        if ($cached !== []) {
            return $cached;
        }

        $symbols = $this->fetchRemote();
        $this->writeCache($symbols);

        return $symbols;
    }

    private function readCache(): array
    {
        if (!is_file(self::CACHE_PATH)) {
            return [];
        }

        if ((time() - (int) filemtime(self::CACHE_PATH)) > self::CACHE_TTL) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents(self::CACHE_PATH), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    private function writeCache(array $symbols): void
    {
        file_put_contents(self::CACHE_PATH, json_encode(array_values($symbols), JSON_THROW_ON_ERROR));
    }

    private function fetchRemote(): array
    {
        $ch = curl_init(self::SOURCE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: text/csv,text/plain;q=0.9,*/*;q=0.8',
                'User-Agent: Mozilla/5.0 BrokerSummaryDashboard/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Gagal mengambil daftar saham: ' . $error);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('Daftar saham tidak bisa diambil. HTTP ' . $httpCode);
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($body)) ?: [];
        if (count($lines) < 2) {
            throw new RuntimeException('Daftar saham tidak valid.');
        }

        $symbols = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '\\');
            $code = strtoupper(trim((string) ($columns[0] ?? '')));
            if ($code !== '') {
                $symbols[] = $code;
            }
        }

        $symbols = array_values(array_unique($symbols));

        if ($symbols === []) {
            throw new RuntimeException('Daftar saham kosong.');
        }

        return $symbols;
    }
}
