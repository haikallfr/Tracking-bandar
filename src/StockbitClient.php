<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use Psr\Http\Message\ResponseInterface;

final class StockbitClient
{
    private string $token;
    private string $baseUrl = 'https://exodus.stockbit.com';
    private ?Client $httpClient = null;

    public function __construct(string $token)
    {
        $this->token = trim($token);
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    public function fetchSymbol(string $symbol, array $filters = []): array
    {
        if (!$this->hasToken()) {
            throw new RuntimeException('Token Stockbit belum diisi.');
        }

        $query = $this->buildQuery($filters);

        return [
            'symbol' => $symbol,
            'market_detector' => $this->get('/marketdetectors/' . rawurlencode($symbol), $query),
            'brokers' => $this->get('/findata-view/marketdetectors/brokers', [
                'symbol' => $symbol,
                'page' => 1,
                'limit' => 50,
            ] + $query),
            'fetched_at' => gmdate(DATE_ATOM),
        ];
    }

    public function fetchCloseFeed(string $symbol, string $timeframe = '1d'): array
    {
        if (!$this->hasToken()) {
            throw new RuntimeException('Token Stockbit belum diisi.');
        }

        return $this->get('/company-price-feed/prices/close', [
            'symbol' => strtoupper($symbol),
            'timeframe' => $timeframe,
        ]);
    }

    public function fetchMarketDetectorsBatch(
        array $symbols,
        array $filters = [],
        int $concurrency = 8,
        int $maxRetries = 3
    ): array {
        if (!$this->hasToken()) {
            throw new RuntimeException('Token Stockbit belum diisi.');
        }

        $pending = array_values(array_unique(array_map(static fn ($symbol) => strtoupper(trim((string) $symbol)), $symbols)));
        $pending = array_values(array_filter($pending, static fn ($symbol) => $symbol !== ''));
        $query = $this->buildQuery($filters);
        $items = [];
        $errors = [];

        for ($attempt = 1; $attempt <= $maxRetries && $pending !== []; $attempt++) {
            $attemptErrors = [];
            $client = $this->httpClient();
            $requests = function () use ($pending, $query, $client) {
                foreach ($pending as $symbol) {
                    yield $symbol => function () use ($client, $symbol, $query) {
                        return $client->getAsync('/marketdetectors/' . rawurlencode($symbol), [
                            'query' => $query,
                            'http_errors' => false,
                        ]);
                    };
                }
            };

            $pool = new Pool($client, $requests(), [
                'concurrency' => $concurrency,
                'fulfilled' => function (ResponseInterface $response, string $symbol) use (&$items, &$attemptErrors): void {
                    try {
                        $items[$symbol] = [
                            'symbol' => $symbol,
                            'market_detector' => $this->decodeResponse(
                                (string) $response->getBody(),
                                $response->getStatusCode()
                            ),
                            'fetched_at' => gmdate(DATE_ATOM),
                        ];
                    } catch (Throwable $error) {
                        $attemptErrors[$symbol] = $error->getMessage();
                    }
                },
                'rejected' => function ($reason, string $symbol) use (&$attemptErrors): void {
                    $attemptErrors[$symbol] = $reason instanceof Throwable
                        ? $reason->getMessage()
                        : 'Request async gagal.';
                },
            ]);

            $pool->promise()->wait();

            if ($attemptErrors === []) {
                break;
            }

            if ($attempt < $maxRetries) {
                $pending = array_keys($attemptErrors);
                $backoffMs = (250 * (2 ** ($attempt - 1))) + random_int(200, 800);
                usleep($backoffMs * 1000);
                continue;
            }

            $errors = $attemptErrors;
        }

        return [
            'items' => $items,
            'errors' => $errors,
        ];
    }

    private function buildQuery(array $filters): array
    {
        $query = [];

        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        $period = trim((string) ($filters['period'] ?? ''));
        $transactionType = trim((string) ($filters['transaction_type'] ?? ''));
        $marketBoard = trim((string) ($filters['market_board'] ?? ''));
        $investorType = trim((string) ($filters['investor_type'] ?? ''));

        if ($from !== '' && $to !== '') {
            $query['from'] = $from;
            $query['to'] = $to;
        } elseif ($period !== '') {
            $query['period'] = $period;
        }

        if ($transactionType !== '') {
            $query['transaction_type'] = $transactionType;
        }

        if ($marketBoard !== '') {
            $query['market_board'] = $marketBoard;
        }

        if ($investorType !== '') {
            $query['investor_type'] = $investorType;
        }

        return $query;
    }

    private function get(string $path, array $params = []): array
    {
        try {
            $response = $this->httpClient()->request('GET', $path, [
                'query' => $params,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $error) {
            throw new RuntimeException('Gagal terhubung ke Stockbit: ' . $error->getMessage(), 0, $error);
        }

        return $this->decodeResponse((string) $response->getBody(), $response->getStatusCode());
    }

    private function httpClient(): Client
    {
        if ($this->httpClient instanceof Client) {
            return $this->httpClient;
        }

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'connect_timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
                'User-Agent' => 'Mozilla/5.0 BrokerSummaryDashboard/1.0',
            ],
        ]);

        return $this->httpClient;
    }

    private function decodeResponse(string $body, int $httpCode): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respons Stockbit tidak valid.');
        }

        if ($httpCode >= 400) {
            $message = $decoded['message'] ?? $decoded['error_type'] ?? ('HTTP ' . $httpCode);
            throw new RuntimeException('Stockbit menolak request: ' . $message);
        }

        if (($decoded['error_type'] ?? '') !== '') {
            throw new RuntimeException((string) ($decoded['message'] ?? $decoded['error_type']));
        }

        return $decoded;
    }
}
