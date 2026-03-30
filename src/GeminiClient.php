<?php

declare(strict_types=1);

final class GeminiClient
{
    private string $apiKey;
    private string $model;

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = trim((string) ($apiKey ?: setting('gemini_api_key', '') ?: getenv('GEMINI_API_KEY')));
        $this->model = trim((string) ($model ?: setting('gemini_model', 'gemini-2.5-flash')));
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function generateAnalysis(string $prompt, bool $grounded = true): array
    {
        if (!$this->configured()) {
            throw new RuntimeException('GEMINI_API_KEY belum diatur.');
        }

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'topP' => 0.9,
                'maxOutputTokens' => 1400,
                'thinkingConfig' => [
                    'thinkingBudget' => 0,
                ],
            ],
        ];

        if ($grounded) {
            $payload['tools'] = [
                ['google_search' => new stdClass()],
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $message = curl_error($ch) ?: 'Gagal terhubung ke Gemini.';
            curl_close($ch);
            throw new RuntimeException($message);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Respons Gemini tidak valid.');
        }

        if ($status >= 400) {
            $message = $data['error']['message'] ?? ('Gemini mengembalikan status ' . $status);
            throw new RuntimeException($message);
        }

        $text = [];
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $text[] = trim($part['text']);
            }
        }

        $sources = [];
        foreach (($data['candidates'][0]['groundingMetadata']['groundingChunks'] ?? []) as $chunk) {
            $web = $chunk['web'] ?? null;
            if (!is_array($web)) {
                continue;
            }

            $uri = trim((string) ($web['uri'] ?? ''));
            if ($uri === '') {
                continue;
            }

            $sources[] = [
                'title' => trim((string) ($web['title'] ?? $uri)),
                'url' => $uri,
            ];
        }

        $sources = array_values(array_unique(array_map(
            static fn (array $source): string => json_encode($source, JSON_THROW_ON_ERROR),
            $sources
        )));
        $sources = array_map(static fn (string $source): array => json_decode($source, true, 512, JSON_THROW_ON_ERROR), $sources);

        return [
            'model' => $this->model,
            'text' => trim(implode("\n\n", array_filter($text))),
            'sources' => $sources,
            'raw' => $data,
        ];
    }
}
