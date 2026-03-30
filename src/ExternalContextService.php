<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

final class ExternalContextService
{
    public function collect(string $symbol, string $companyName = '', bool $force = false): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return $this->emptyContext();
        }

        if (!$force) {
            $cached = $this->cached($symbol);
            if ($cached !== null) {
                return $cached;
            }
        }

        $news = $this->fetchNews($symbol, $companyName);
        $signals = $this->deriveSignals($news);

        $context = [
            'symbol' => $symbol,
            'generated_at' => gmdate(DATE_ATOM),
            'news' => array_slice($news, 0, 8),
            'signals' => $signals,
        ];

        $this->save($symbol, $context);

        return $context;
    }

    private function emptyContext(): array
    {
        return [
            'generated_at' => gmdate(DATE_ATOM),
            'news' => [],
            'signals' => [
                'has_buyback' => false,
                'has_rights_issue' => false,
                'has_dividend' => false,
                'has_split' => false,
                'has_financial_growth' => false,
                'has_negative_event' => false,
                'keywords' => [],
                'summary' => [],
            ],
        ];
    }

    private function cached(string $symbol): ?array
    {
        $stmt = db()->prepare('SELECT payload, updated_at FROM external_context_cache WHERE symbol = :symbol LIMIT 1');
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $updatedAt = strtotime((string) ($row['updated_at'] ?? ''));
        if ($updatedAt === false || (time() - $updatedAt) > 60 * 60 * 4) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        return is_array($payload) ? $payload : null;
    }

    private function save(string $symbol, array $payload): void
    {
        $stmt = db()->prepare(
            'INSERT INTO external_context_cache(symbol, payload, updated_at)
             VALUES (:symbol, :payload, :updated_at)
             ON CONFLICT(symbol) DO UPDATE SET
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => $symbol,
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    private function fetchNews(string $symbol, string $companyName): array
    {
        $queries = array_values(array_unique(array_filter([
            $symbol . ' saham',
            $companyName !== '' ? $companyName . ' saham' : '',
            $symbol . ' buyback',
            $companyName !== '' ? $companyName . ' buyback' : '',
            $symbol . ' rights issue',
            $companyName !== '' ? $companyName . ' rights issue' : '',
            $symbol . ' dividen',
            $companyName !== '' ? $companyName . ' dividen' : '',
            $symbol . ' stock split',
            $companyName !== '' ? $companyName . ' stock split' : '',
        ])));

        $items = [];
        foreach ($queries as $query) {
            $rssUrl = 'https://news.google.com/rss/search?q=' . rawurlencode($query . ' when:90d') . '&hl=id&gl=ID&ceid=ID:id';
            $xml = $this->fetchXml($rssUrl);
            if ($xml === null) {
                continue;
            }

            foreach ($xml->channel->item ?? [] as $item) {
                $title = trim((string) ($item->title ?? ''));
                $link = trim((string) ($item->link ?? ''));
                $pubDate = trim((string) ($item->pubDate ?? ''));
                $description = html_entity_decode(strip_tags((string) ($item->description ?? '')));
                if ($title === '' || $link === '') {
                    continue;
                }

                if (!$this->isRelevantNews($symbol, $companyName, $title . ' ' . $description)) {
                    continue;
                }

                $key = sha1($title . '|' . $link);
                $items[$key] = [
                    'title' => $title,
                    'url' => $link,
                    'published_at' => $pubDate,
                    'description' => trim($description),
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strtotime((string) ($b['published_at'] ?? '')) <=> strtotime((string) ($a['published_at'] ?? ''));
        });

        return array_values($items);
    }

    private function isRelevantNews(string $symbol, string $companyName, string $text): bool
    {
        $haystack = strtolower($text);
        $symbolNeedle = strtolower($symbol);
        if ($symbolNeedle !== '' && preg_match('/\b' . preg_quote($symbolNeedle, '/') . '\b/i', $text)) {
            return true;
        }

        $companyName = strtolower(trim($companyName));
        if ($companyName !== '' && str_contains($haystack, $companyName)) {
            return true;
        }

        $terms = $this->companyTerms($companyName);
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    private function companyTerms(string $companyName): array
    {
        if ($companyName === '') {
            return [];
        }

        $clean = preg_replace('/\btbk\b\.?/i', '', $companyName);
        $clean = preg_replace('/\bindonesia\b/i', '', (string) $clean);
        $clean = trim((string) $clean);
        if ($clean === '') {
            return [];
        }

        $terms = [$clean];
        $parts = preg_split('/\s+/', $clean) ?: [];
        $ignored = ['bank', 'saham', 'tbk', 'pt', 'persero'];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= 4 && !in_array($part, $ignored, true)) {
                $terms[] = $part;
            }
        }

        return array_values(array_unique($terms));
    }

    private function fetchXml(string $url): ?SimpleXMLElement
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'TrackingBandar/1.0',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        libxml_clear_errors();

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }

    private function deriveSignals(array $news): array
    {
        $joined = strtolower(implode("\n", array_map(
            static fn (array $item): string => trim(((string) ($item['title'] ?? '')) . ' ' . ((string) ($item['description'] ?? ''))),
            $news
        )));

        $keywords = [];
        $flags = [
            'buyback' => ['buyback', 'pembelian kembali'],
            'rights_issue' => ['rights issue', 'hmetd', 'hm etd', 'rights'],
            'dividend' => ['dividen', 'dividend'],
            'split' => ['stock split', 'pemecahan saham', 'reverse stock'],
            'financial_growth' => ['laba naik', 'laba tumbuh', 'pertumbuhan laba', 'profit growth', 'pendapatan naik', 'kinerja positif'],
            'negative_event' => ['rugi', 'kerugian', 'gugatan', 'suspensi', 'gagal bayar', 'fraud', 'default', 'penurunan laba'],
        ];

        $results = [
            'has_buyback' => false,
            'has_rights_issue' => false,
            'has_dividend' => false,
            'has_split' => false,
            'has_financial_growth' => false,
            'has_negative_event' => false,
            'keywords' => [],
            'summary' => [],
        ];

        foreach ($flags as $name => $terms) {
            foreach ($terms as $term) {
                if (str_contains($joined, strtolower($term))) {
                    $keywords[] = $term;
                    $results['has_' . ($name === 'financial_growth' ? 'financial_growth' : ($name === 'negative_event' ? 'negative_event' : $name))] = true;
                }
            }
        }

        if ($results['has_buyback']) {
            $results['summary'][] = 'Ada indikasi berita buyback atau pembelian kembali saham.';
        }
        if ($results['has_rights_issue']) {
            $results['summary'][] = 'Ada indikasi rights issue / HMETD yang bisa memengaruhi pembacaan broker flow.';
        }
        if ($results['has_dividend']) {
            $results['summary'][] = 'Ada konteks dividen yang bisa mempengaruhi sentimen jangka pendek.';
        }
        if ($results['has_split']) {
            $results['summary'][] = 'Ada indikasi stock split atau aksi serupa yang perlu dibaca terpisah dari flow biasa.';
        }
        if ($results['has_financial_growth']) {
            $results['summary'][] = 'Ada berita yang mengarah ke pertumbuhan laba/fundamental positif.';
        }
        if ($results['has_negative_event']) {
            $results['summary'][] = 'Ada berita bernada negatif yang bisa mengganggu follow-through.';
        }

        $results['keywords'] = array_values(array_unique($keywords));

        return $results;
    }
}
