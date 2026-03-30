<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/StockbitClient.php';
require_once __DIR__ . '/RadarBandar.php';
require_once __DIR__ . '/BrokerRepository.php';
require_once __DIR__ . '/HistoricalRepository.php';
require_once __DIR__ . '/CandidateEnricher.php';
require_once __DIR__ . '/GeminiClient.php';

final class AiAnalysisService
{
    private GeminiClient $gemini;

    public function __construct(?GeminiClient $gemini = null)
    {
        $this->gemini = $gemini ?? new GeminiClient();
    }

    public function configured(): bool
    {
        return $this->gemini->configured();
    }

    public function analyze(string $symbol, string $mode = 'high', bool $force = false): array
    {
        $symbol = strtoupper(trim($symbol));
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['high', 'basic'], true)) {
            $mode = 'high';
        }

        if (!$this->configured()) {
            throw new RuntimeException('GEMINI_API_KEY belum diatur. Setelah key tersedia, Analisa AI bisa aktif.');
        }

        $item = $this->buildRadarItem($symbol, $mode);
        if ($item === null) {
            throw new RuntimeException('Data radar untuk simbol ini belum cukup untuk dianalisa AI.');
        }

        $context = $this->buildContext($symbol, $mode, $item);
        $contextHash = sha1(json_encode($context, JSON_THROW_ON_ERROR));

        if (!$force) {
            $cached = $this->cached($symbol, $mode, $contextHash);
            if ($cached !== null) {
                return $cached;
            }
        }

        $prompt = $this->buildPrompt($context);
        $response = $this->gemini->generateAnalysis($prompt, true);

        $analysis = [
            'symbol' => $symbol,
            'mode' => $mode,
            'company' => $context['company'],
            'generated_at' => gmdate(DATE_ATOM),
            'cached' => false,
            'model' => $response['model'],
            'analysis' => $response['text'],
            'sources' => $response['sources'],
            'context' => $context,
        ];

        $this->saveCache($symbol, $mode, $contextHash, $analysis);

        return $analysis;
    }

    private function buildRadarItem(string $symbol, string $mode): ?array
    {
        $token = (string) setting('stockbit_token', '');
        if ($token === '') {
            throw new RuntimeException('Token Stockbit belum siap.');
        }

        $filters = [
            'period' => 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
            'transaction_type' => 'TRANSACTION_TYPE_NET',
            'market_board' => 'MARKET_BOARD_ALL',
            'investor_type' => 'INVESTOR_TYPE_ALL',
        ];

        $client = new StockbitClient($token);
        $radar = new RadarBandar(new BrokerRepository());
        $payload = $client->fetchSymbol($symbol, $filters);
        $updatedAt = gmdate(DATE_ATOM);

        if ($mode === 'basic') {
            return $radar->evaluateBasic($symbol, $payload, $updatedAt);
        }

        $item = $radar->evaluate($symbol, $payload, $updatedAt);
        if ($item !== null) {
            $enricher = new CandidateEnricher($client, new HistoricalRepository());
            $enrichment = $enricher->enrich($symbol, 12);
            if ($enrichment !== []) {
                $item = $radar->refine($item, $enrichment);
            }
        }

        return $item;
    }

    private function buildContext(string $symbol, string $mode, array $item): array
    {
        $reference = $this->symbolReference($symbol);
        $corporateActions = $this->recentCorporateActions($symbol);
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];

        return [
            'symbol' => $symbol,
            'mode' => $mode,
            'company' => [
                'name' => $reference['company_name'] ?? '',
                'sector' => $reference['sector'] ?? '',
                'subsector' => $reference['subsector'] ?? '',
                'listing_board' => $reference['listing_board'] ?? '',
            ],
            'period' => [
                'from' => $item['from'] ?? '',
                'to' => $item['to'] ?? '',
            ],
            'score' => [
                'final' => (float) ($item['score'] ?? 0),
                'base' => (float) ($item['score_base'] ?? ($item['score'] ?? 0)),
                'label' => (string) ($item['label'] ?? ''),
            ],
            'metrics' => [
                'buy_market_share' => (float) ($metrics['buy_market_share'] ?? 0),
                'buy_lot_share' => (float) ($metrics['buy_lot_share'] ?? 0),
                'buy_concentration' => (float) ($metrics['buy_concentration'] ?? 0),
                'dominance_gap' => (float) ($metrics['dominance_gap'] ?? 0),
                'repeat_ratio' => (float) ($metrics['repeat_ratio'] ?? 0),
                'clean_ratio' => (float) ($metrics['clean_ratio'] ?? 0),
                'acc_ratio' => (float) ($metrics['acc_ratio'] ?? 0),
                'turnover_acceleration' => (float) ($metrics['turnover_acceleration'] ?? 0),
                'breakout_pct' => (float) ($metrics['breakout_pct'] ?? 0),
                'extension_pct' => (float) ($metrics['extension_pct'] ?? 0),
                'intraday_close_vs_open_pct' => (float) ($metrics['intraday_close_vs_open_pct'] ?? 0),
                'intraday_range_pct' => (float) ($metrics['intraday_range_pct'] ?? 0),
            ],
            'enrichment' => [
                'history_days' => (int) ($enrichment['history_days'] ?? 0),
                'repeat_broker_code' => (string) ($enrichment['repeat_broker_code'] ?? ''),
                'repeat_broker_days' => (int) ($enrichment['repeat_broker_days'] ?? 0),
                'clean_buyer_days' => (int) ($enrichment['clean_buyer_days'] ?? 0),
                'buy_dominance_days' => (int) ($enrichment['buy_dominance_days'] ?? 0),
                'dist_days' => (int) ($enrichment['dist_days'] ?? 0),
                'acc_days' => (int) ($enrichment['acc_days'] ?? 0),
            ],
            'top_buyers' => array_slice($item['top_buyers'] ?? [], 0, 5),
            'top_sellers' => array_slice($item['top_sellers'] ?? [], 0, 5),
            'reasons' => array_values($item['reasons'] ?? []),
            'corporate_actions' => $corporateActions,
        ];
    }

    private function buildPrompt(array $context): string
    {
        $symbol = $context['symbol'];
        $companyName = $context['company']['name'] ?: $symbol;

        return <<<PROMPT
Anda adalah analis saham Indonesia yang disiplin dan tidak hiperbola.

Tugas Anda:
1. Analisa saham {$symbol} ({$companyName}) memakai data internal berikut.
2. Tambahkan konteks eksternal terbaru memakai pencarian web Google jika memang relevan, terutama:
   - buyback
   - rights issue
   - corporate action
   - berita material
   - sentimen yang menjelaskan broker flow
3. Bedakan jelas mana fakta dari data internal dan mana konteks tambahan dari luar.
4. Jika ada kemungkinan sinyal radar tercampur corporate action, katakan dengan tegas.
5. Beri keputusan manusiawi, bukan sekadar mengulang angka.

Data internal:
{$this->prettyJson($context)}

Format jawaban wajib:
Ringkasan:
- maksimal 3 kalimat

Yang Sedang Terjadi:
- 3 sampai 5 poin singkat

Risiko Utama:
- 2 sampai 4 poin singkat

Keputusan:
- pilih salah satu: Layak Pantau / Tunggu Konfirmasi / Hindari / Layak Entry Bertahap
- jelaskan 2 kalimat kenapa

Yang Perlu Dipantau Berikutnya:
- 2 sampai 4 poin singkat

Aturan penting:
- jangan memberi janji pasti naik
- jangan buat rekomendasi bombastis
- jika konteks luar tidak kuat, bilang belum cukup bukti
- gunakan bahasa Indonesia yang jelas dan profesional
PROMPT;
    }

    private function prettyJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function symbolReference(string $symbol): array
    {
        $stmt = db()->prepare('SELECT symbol, company_name, sector, subsector, listing_board FROM symbol_reference WHERE symbol = :symbol LIMIT 1');
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [];
    }

    private function recentCorporateActions(string $symbol): array
    {
        $stmt = db()->prepare(
            'SELECT action_type, action_date, headline, source
             FROM corporate_action_reference
             WHERE symbol = :symbol
             ORDER BY action_date DESC
             LIMIT 5'
        );
        $stmt->execute([':symbol' => $symbol]);

        return $stmt->fetchAll() ?: [];
    }

    private function cached(string $symbol, string $mode, string $contextHash): ?array
    {
        $stmt = db()->prepare(
            'SELECT payload FROM ai_analysis_cache
             WHERE symbol = :symbol AND mode = :mode AND context_hash = :context_hash
             LIMIT 1'
        );
        $stmt->execute([
            ':symbol' => $symbol,
            ':mode' => $mode,
            ':context_hash' => $contextHash,
        ]);
        $payload = $stmt->fetchColumn();
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $decoded['cached'] = true;
        return $decoded;
    }

    private function saveCache(string $symbol, string $mode, string $contextHash, array $analysis): void
    {
        $stmt = db()->prepare(
            'INSERT INTO ai_analysis_cache(symbol, mode, context_hash, model, payload, updated_at)
             VALUES (:symbol, :mode, :context_hash, :model, :payload, :updated_at)
             ON CONFLICT(symbol, mode, context_hash) DO UPDATE SET
                model = excluded.model,
                payload = excluded.payload,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':symbol' => $symbol,
            ':mode' => $mode,
            ':context_hash' => $contextHash,
            ':model' => (string) ($analysis['model'] ?? ''),
            ':payload' => json_encode($analysis, JSON_THROW_ON_ERROR),
            ':updated_at' => gmdate(DATE_ATOM),
        ]);
    }
}
