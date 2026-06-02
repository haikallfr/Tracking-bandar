<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/SystemAnalyst.php';
require_once __DIR__ . '/ExternalContextService.php';

final class CioSwingAnalyst
{
    public function analyze(string $symbol, string $sectorInput = ''): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new RuntimeException('Symbol wajib diisi.');
        }

        $system = $this->safeSystemAnalysis($symbol);
        $reference = $this->reference($symbol);
        $effectiveSector = $this->effectiveSector($sectorInput, $reference, $symbol);
        $companyName = (string) ($reference['company_name'] ?? '');
        $external = (new ExternalContextService())->collect($symbol, $companyName, true);

        $macro = $this->macroContext($effectiveSector);
        $sector = $this->sectorContext($symbol, $effectiveSector, $reference);
        $micro = $this->microContext($system, $reference, $external);
        $catalyst = $this->catalystContext($system, $external);
        $strategy = $this->strategyContext($system, $macro, $sector, $micro, $catalyst);

        return [
            'symbol' => $symbol,
            'sector' => $effectiveSector,
            'generated_at' => gmdate(DATE_ATOM),
            'company' => [
                'symbol' => $symbol,
                'company_name' => $companyName,
                'sector' => $effectiveSector,
                'subsector' => (string) ($reference['subsector'] ?? ''),
                'listing_board' => (string) ($reference['listing_board'] ?? ''),
            ],
            'system' => $system,
            'macro' => $macro,
            'sector_analysis' => $sector,
            'micro' => $micro,
            'catalyst' => $catalyst,
            'strategy' => $strategy,
            'data_notes' => $this->dataNotes($reference, $effectiveSector),
        ];
    }

    private function safeSystemAnalysis(string $symbol): array
    {
        try {
            return (new SystemAnalyst())->analyze($symbol, 'high');
        } catch (Throwable $error) {
            return [
                'symbol' => $symbol,
                'mode' => 'high',
                'generated_at' => gmdate(DATE_ATOM),
                'company' => [
                    'symbol' => $symbol,
                    'company_name' => '',
                    'sector' => '',
                    'subsector' => '',
                    'listing_board' => '',
                ],
                'item' => [
                    'symbol' => $symbol,
                    'score' => 0,
                    'metrics' => [],
                    'enrichment' => [],
                    'top_buyers' => [],
                    'top_sellers' => [],
                    'reasons' => [],
                ],
                'external_context' => [
                    'news' => [],
                    'signals' => [
                        'summary' => [],
                    ],
                ],
                'analysis' => [
                    'setup' => 'Data live tidak tersedia',
                    'bias' => 'Netral',
                    'decision' => 'Tunggu data live',
                    'summary' => [
                        'Data live Stockbit belum bisa diambil saat laporan dibuat, sehingga bagian broker flow dan momentum internal diturunkan ke mode aman.'
                    ],
                    'happening' => [
                        'Halaman tetap menyusun konteks makro, sektoral, dan katalis luar, tetapi validasi smart money dari feed internal sedang tidak aktif.'
                    ],
                    'risks' => [
                        'Tanpa data live internal, conviction report harus dibaca sebagai laporan konteks, bukan trigger eksekusi penuh.'
                    ],
                    'next_watch' => [
                        'Ulangi analisa saat koneksi data live Stockbit kembali tersedia untuk memvalidasi flow broker dan momentum harga.'
                    ],
                ],
                'system_error' => $error->getMessage(),
            ];
        }
    }

    private function reference(string $symbol): array
    {
        $stmt = db()->prepare('SELECT symbol, company_name, sector, subsector, listing_board FROM symbol_reference WHERE symbol = :symbol LIMIT 1');
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : [
            'symbol' => $symbol,
            'company_name' => '',
            'sector' => '',
            'subsector' => '',
            'listing_board' => '',
        ];
    }

    private function effectiveSector(string $sectorInput, array $reference, string $symbol): string
    {
        $sectorInput = trim($sectorInput);
        if ($sectorInput !== '') {
            return $sectorInput;
        }

        $sector = trim((string) ($reference['sector'] ?? ''));
        if ($sector !== '') {
            return $sector;
        }

        $subsector = trim((string) ($reference['subsector'] ?? ''));
        if ($subsector !== '') {
            return $subsector;
        }

        $company = strtolower((string) ($reference['company_name'] ?? ''));
        $guessMap = [
            'bank' => 'Perbankan',
            'coal' => 'Batubara',
            'energy' => 'Energi',
            'asam' => 'Batubara',
            'consumer' => 'Konsumsi',
            'retail' => 'Ritel',
            'telekom' => 'Telekomunikasi',
            'property' => 'Properti',
            'logistik' => 'Logistik',
        ];

        foreach ($guessMap as $needle => $label) {
            if ($company !== '' && str_contains($company, $needle)) {
                return $label;
            }
        }

        return 'Sektor belum ditentukan untuk ' . $symbol;
    }

    private function macroContext(string $sector): array
    {
        $globalNews = $this->fetchNewsBundle([
            'The Fed suku bunga IHSG',
            'inflasi AS IHSG',
            'geopolitik pasar saham indonesia',
            'capital inflow IHSG asing',
        ], 8);

        $domesticNews = $this->fetchNewsBundle([
            'BI Rate IHSG',
            'USD IDR IHSG',
            'inflasi Indonesia IHSG',
            'PDB Indonesia IHSG',
        ], 8);

        $commodityKeyword = $this->commodityKeyword($sector);
        $commodityNews = $commodityKeyword !== ''
            ? $this->fetchNewsBundle([
                $commodityKeyword . ' harga outlook',
                $commodityKeyword . ' supply demand 6 bulan',
            ], 6)
            : [];

        $globalTailwind = $this->newsScore($globalNews, ['inflow', 'optimis', 'turun', 'pemangkasan', 'stimulus'], ['outflow', 'geopolitik', 'hawkish', 'naik', 'risk off']);
        $domesticTailwind = $this->newsScore($domesticNews, ['stabil', 'menguat', 'pemangkasan', 'pertumbuhan', 'daya beli'], ['melemah', 'inflasi tinggi', 'tekanan', 'depresiasi', 'perlambatan']);
        $commodityTailwind = $commodityNews !== [] ? $this->newsScore($commodityNews, ['naik', 'defisit', 'permintaan'], ['turun', 'surplus', 'tekanan']) : 0;

        return [
            'global' => [
                'stance' => $this->tailwindLabel($globalTailwind),
                'summary' => $this->macroParagraph(
                    'Global',
                    $globalTailwind,
                    'Arah The Fed, inflasi AS, dan tensi geopolitik saat ini membentuk sentimen risk-on atau risk-off ke IHSG.',
                    'Untuk sektor ini, fokus utamanya adalah apakah arus modal asing cenderung masuk ke aset berisiko atau justru parkir di instrumen defensif.'
                ),
                'news' => $globalNews,
            ],
            'domestic' => [
                'stance' => $this->tailwindLabel($domesticTailwind),
                'summary' => $this->macroParagraph(
                    'Domestik',
                    $domesticTailwind,
                    'BI Rate, USD/IDR, inflasi domestik, dan ritme pertumbuhan ekonomi Indonesia akan langsung memengaruhi cost of capital, daya beli, dan minat dana institusi.',
                    'Untuk saham ini, pembacaan domestik lebih relevan sebagai tailwind bila biaya dana stabil, rupiah tidak tertekan ekstrem, dan narasi pertumbuhan domestik tetap hidup.'
                ),
                'news' => $domesticNews,
            ],
            'commodity' => [
                'keyword' => $commodityKeyword,
                'stance' => $commodityKeyword !== '' ? $this->tailwindLabel($commodityTailwind) : 'Tidak dominan',
                'summary' => $commodityKeyword !== ''
                    ? $this->macroParagraph(
                        'Komoditas',
                        $commodityTailwind,
                        'Komoditas kunci yang paling relevan untuk sektor ini saat ini adalah ' . $commodityKeyword . '.',
                        'Supply-demand 6 bulan ke depan perlu dibaca sebagai pendorong margin bila harga komoditas tetap kuat, atau sebagai headwind bila tren harga melemah.'
                    )
                    : 'Untuk simbol ini saya belum menemukan indikasi bahwa harga komoditas tertentu menjadi driver utama yang paling dominan pada fase sekarang.',
                'news' => $commodityNews,
            ],
        ];
    }

    private function sectorContext(string $symbol, string $sector, array $reference): array
    {
        $sectorNews = $this->fetchNewsBundle([
            $sector . ' IHSG',
            $sector . ' saham indonesia',
            $sector . ' rotasi sektor IHSG',
        ], 8);

        $cycleScore = $this->newsScore($sectorNews, ['pemulihan', 'recovery', 'akumulasi', 'tumbuh', 'naik'], ['jenuh', 'peak', 'tekanan', 'melemah', 'turun']);
        $rotationScore = $this->newsScore($sectorNews, ['rotasi', 'asing masuk', 'akumulasi', 'tema', 'katalis'], ['outflow', 'distribusi', 'profit taking']);
        $peers = $this->peers($symbol, $sector, (string) ($reference['subsector'] ?? ''));

        $cycle = 'Recovery';
        if ($cycleScore >= 2) {
            $cycle = 'Boom';
        } elseif ($cycleScore <= -2) {
            $cycle = 'Depression';
        } elseif ($cycleScore === -1) {
            $cycle = 'Peak';
        }

        return [
            'cycle' => $cycle,
            'rotation' => $rotationScore > 0 ? 'Ada indikasi rotasi dana mulai masuk sektor ini.' : 'Belum ada bukti kuat rotasi dana besar yang konsisten.',
            'narrative' => $this->sectorNarrative($sector, $rotationScore),
            'moat' => $this->moatSummary($symbol, $reference, $peers),
            'peers' => $peers,
            'news' => $sectorNews,
        ];
    }

    private function microContext(array $system, array $reference, array $external): array
    {
        $item = $system['item'] ?? [];
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $ownership = $this->owners((string) ($item['symbol'] ?? ''));
        $signals = $external['signals'] ?? [];

        $quality = [];
        $quality[] = sprintf(
            'Dari sisi operasional pasar, buyer share %.2f%% dan dominance gap %.2f%% menunjukkan apakah permintaan yang masuk bersifat meyakinkan atau hanya spike sesaat.',
            (float) ($metrics['buy_market_share'] ?? 0),
            (float) ($metrics['dominance_gap'] ?? 0)
        );
        $quality[] = sprintf(
            'Repeat ratio %.2f%%, clean ratio %.2f%%, dan acc ratio %.2f%% membantu menilai apakah aliran beli cukup rapi untuk menopang swing 1-6 bulan atau masih terlalu berisik.',
            (float) ($metrics['repeat_ratio'] ?? 0),
            (float) ($metrics['clean_ratio'] ?? 0),
            (float) ($metrics['acc_ratio'] ?? 0)
        );
        if (($signals['has_financial_growth'] ?? false) === true) {
            $quality[] = 'Ada berita yang mendukung narasi pertumbuhan laba atau pendapatan, sehingga kualitas cerita fundamentalnya cenderung lebih sehat dibanding saham yang hanya ditopang flow spekulatif.';
        } else {
            $quality[] = 'Data laporan keuangan detail seperti GPM, NPM 3 tahun, FCF, DER, dan interest coverage belum terintegrasi otomatis di sistem ini, jadi bagian fundamental mendalam masih dibaca dari kombinasi berita, konteks emiten, dan perilaku pasar.';
        }

        $balance = [];
        $balance[] = $ownership !== []
            ? 'Struktur kepemilikan menunjukkan pemegang besar berikut: ' . implode(', ', array_map(
                static fn (array $row): string => trim((string) ($row['owner_name'] ?? '-')) . ' ' . number_format((float) ($row['ownership_pct'] ?? 0), 2, ',', '.') . '%',
                array_slice($ownership, 0, 3)
            )) . '.'
            : 'Data kepemilikan besar belum terisi di referensi lokal, sehingga pembacaan neraca dan struktur modal masih perlu dilengkapi dari sumber eksternal resmi.';
        $balance[] = sprintf(
            'Turnover acceleration %sx dan distribusi %d hari memberi petunjuk apakah dana baru benar-benar masuk atau harga hanya bergerak tanpa quality follow-through.',
            number_format((float) ($metrics['turnover_acceleration'] ?? 0), 2, ',', '.'),
            (int) ($enrichment['dist_days'] ?? 0)
        );

        $valuation = [];
        $valuation[] = sprintf(
            'Valuasi historis PE/PBV 5 tahun belum tersimpan di sistem ini. Untuk sementara, sistem membaca apakah harga sedang terlalu jauh dari basis teknis lewat breakout %.2f%% dan extension %.2f%%.',
            (float) ($metrics['breakout_pct'] ?? 0),
            (float) ($metrics['extension_pct'] ?? 0)
        );
        $valuation[] = 'Interpretasi sementara: breakout yang terlalu jauh tanpa clean accumulation biasanya lebih dekat ke over-extension, sedangkan breakout moderat dengan akumulasi rapi lebih dekat ke fair entry untuk swing.';

        return [
            'earnings_quality' => $quality,
            'balance_sheet' => $balance,
            'valuation' => $valuation,
            'ownership' => $ownership,
        ];
    }

    private function catalystContext(array $system, array $external): array
    {
        $item = $system['item'] ?? [];
        $metrics = $item['metrics'] ?? [];
        $enrichment = $item['enrichment'] ?? [];
        $signals = $external['signals'] ?? [];
        $news = is_array($external['news'] ?? null) ? $external['news'] : [];

        $catalysts = [];
        if (($signals['has_buyback'] ?? false) === true) {
            $catalysts[] = 'Ada sinyal buyback/pembelian kembali saham yang bisa menjadi penyangga harga dalam jangka pendek-menengah.';
        }
        if (($signals['has_dividend'] ?? false) === true) {
            $catalysts[] = 'Ada narasi dividen yang bisa menjadi pemicu rerating dan minat dana defensif.';
        }
        if (($signals['has_rights_issue'] ?? false) === true) {
            $catalysts[] = 'Rights issue / HMETD berpotensi menjadi katalis sekaligus sumber dilusi, jadi perlu dibaca dua arah.';
        }
        if (($signals['has_financial_growth'] ?? false) === true) {
            $catalysts[] = 'Ada narasi pertumbuhan laba atau pendapatan yang memperkuat tesis di luar broker flow murni.';
        }
        if ($catalysts === []) {
            $catalysts[] = 'Belum ada aksi korporasi dominan yang sangat kuat dari data lokal; trigger paling dekat masih berasal dari rilis kinerja, rotasi sektor, dan arus dana broker.';
        }

        $smartMoney = [];
        $smartMoney[] = sprintf(
            'Repeat broker %s aktif %d hari, clean accumulation %.2f%%, dan top buyer share %.2f%%.',
            (string) ($enrichment['repeat_broker_code'] ?? '-'),
            (int) ($enrichment['repeat_broker_days'] ?? 0),
            (float) ($metrics['clean_ratio'] ?? 0),
            (float) ($metrics['buy_market_share'] ?? 0)
        );
        $smartMoney[] = sprintf(
            'Turnover acceleration %sx, breakout %.2f%%, dan close vs open %.2f%% memberi petunjuk apakah markup sedang dimulai atau harga sudah terlalu jauh.',
            number_format((float) ($metrics['turnover_acceleration'] ?? 0), 2, ',', '.'),
            (float) ($metrics['breakout_pct'] ?? 0),
            (float) ($metrics['intraday_close_vs_open_pct'] ?? 0)
        );
        if (count($news) > 0) {
            $smartMoney[] = 'Konteks berita terbaru mendukung atau menahan akumulasi ini, sehingga pembacaan smart money perlu selalu disejajarkan dengan katalis eksternal.';
        }

        return [
            'short_medium_term' => $catalysts,
            'smart_money' => $smartMoney,
            'news' => $news,
        ];
    }

    private function strategyContext(array $system, array $macro, array $sector, array $micro, array $catalyst): array
    {
        $item = $system['item'] ?? [];
        $metrics = $item['metrics'] ?? [];
        $analysis = $system['analysis'] ?? [];

        $baseScore = (float) ($item['score'] ?? 0);
        $macroScore = $this->stanceScore((string) ($macro['global']['stance'] ?? 'Netral')) + $this->stanceScore((string) ($macro['domestic']['stance'] ?? 'Netral'));
        $sectorScore = match ((string) ($sector['cycle'] ?? 'Recovery')) {
            'Boom' => 10,
            'Recovery' => 6,
            'Peak' => -2,
            'Depression' => -6,
            default => 0,
        };
        $catalystScore = count($catalyst['short_medium_term'] ?? []) >= 2 ? 8 : 4;
        $penalty = 0;
        if (((float) ($metrics['extension_pct'] ?? 0)) > 10) {
            $penalty += 6;
        }
        if (((float) ($metrics['intraday_range_pct'] ?? 0)) > 18) {
            $penalty += 5;
        }
        if (((float) ($metrics['dominance_gap'] ?? 0)) < 5) {
            $penalty += 4;
        }

        $conviction = max(1, min(100, (int) round(($baseScore * 0.62) + $macroScore + $sectorScore + $catalystScore - $penalty)));
        $latest = max(1.0, (float) (($item['enrichment']['latest_avg_price'] ?? 0) ?: 1));
        $entryHigh = $latest;
        $entryLow = $latest * (1 - min(0.08, max(0.03, ((float) ($metrics['intraday_range_pct'] ?? 0)) / 100 / 2)));
        $tp1 = $latest * (1 + (0.08 + max(0, ((float) ($metrics['breakout_pct'] ?? 0)) / 100 / 3)));
        $tp2 = $latest * (1 + (0.14 + max(0, ((float) ($metrics['turnover_acceleration'] ?? 0)) * 0.02)));
        $cutLoss = $entryLow * 0.95;

        $bullish = [];
        $bullish[] = 'Skenario bullish valid jika arus buyer tetap dominan, distribusi tidak bertambah, dan katalis eksternal tidak berubah negatif.';
        $bullish[] = 'Jika sektor masuk fase recovery ke boom, saham dengan struktur akumulasi yang rapi biasanya menjadi pemimpin tren 1-6 bulan.';

        $bearish = [];
        $bearish[] = 'Tesis rusak bila buyer dominan menghilang, distribusi bertambah, atau harga bergerak terlalu jauh tanpa dukungan volume lanjutan.';
        $bearish[] = 'Risk factor utama lainnya adalah perubahan sentimen makro, rupiah tertekan, atau corporate action yang justru menciptakan dilusi dan bukan rerating.';

        return [
            'conviction_score' => $conviction,
            'conviction_label' => $this->convictionLabel($conviction),
            'summary' => [
                sprintf('Secara gabungan makro, sektoral, flow broker, dan katalis, %s mendapat Swing Conviction Score %d/100.', (string) ($item['symbol'] ?? '-'), $conviction),
                sprintf('Setup internal sistem saat ini: %s dengan keputusan %s.', (string) ($analysis['setup'] ?? 'Netral'), (string) ($analysis['decision'] ?? 'Layak Pantau')),
            ],
            'bullish' => $bullish,
            'bearish' => $bearish,
            'action_plan' => [
                'entry_zone' => sprintf('Buy zone ideal: %s sampai %s, dengan prioritas entry bertahap di area pullback sehat.', $this->formatPrice($entryLow), $this->formatPrice($entryHigh)),
                'take_profit' => sprintf('Target take profit rasional: TP1 %s, TP2 %s, sambil evaluasi ulang jika extension sudah terlalu tinggi.', $this->formatPrice($tp1), $this->formatPrice($tp2)),
                'cut_loss' => sprintf('Invalidation level / cut loss: di bawah %s, karena area itu menandakan asumsi momentum dan kualitas flow mulai gagal.', $this->formatPrice($cutLoss)),
            ],
        ];
    }

    private function peers(string $symbol, string $sector, string $subsector): array
    {
        if (trim($sector) === '' && trim($subsector) === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT symbol, company_name, sector, subsector
             FROM symbol_reference
             WHERE symbol <> :symbol
               AND (
                    (:subsector <> "" AND subsector = :subsector)
                 OR (:sector <> "" AND sector = :sector)
               )
             ORDER BY company_name ASC
             LIMIT 3'
        );
        $stmt->execute([
            ':symbol' => $symbol,
            ':sector' => $sector,
            ':subsector' => $subsector,
        ]);

        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function owners(string $symbol): array
    {
        if ($symbol === '') {
            return [];
        }

        $stmt = db()->prepare(
            'SELECT owner_name, owner_type, ownership_pct, effective_date
             FROM ownership_reference
             WHERE symbol = :symbol
             ORDER BY effective_date DESC, ownership_pct DESC
             LIMIT 5'
        );
        $stmt->execute([':symbol' => $symbol]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function moatSummary(string $symbol, array $reference, array $peers): array
    {
        $company = trim((string) ($reference['company_name'] ?? $symbol));
        $base = [];
        $base[] = $company . ' perlu dinilai lewat posisi merek, distribusi, efisiensi biaya, dan apakah ia menjadi nama yang lebih dulu dipilih dana saat sektor ini hidup.';
        if ($peers !== []) {
            $base[] = 'Kompetitor terdekat yang layak diperbandingkan saat ini: ' . implode(', ', array_map(static fn (array $row): string => (string) ($row['symbol'] ?? '-'), $peers)) . '.';
        } else {
            $base[] = 'Data peer sektoral di referensi lokal masih tipis, jadi perbandingan kompetitor belum bisa dibuat sekeras yang ideal.';
        }
        $base[] = 'Moat terbaik untuk swing 1-6 bulan biasanya datang dari kombinasi cerita yang mudah dipahami pasar, likuiditas memadai, dan kemampuan menjaga margin atau volume penjualan saat siklus sektor membaik.';

        return $base;
    }

    private function sectorNarrative(string $sector, int $rotationScore): string
    {
        if ($rotationScore > 1) {
            return 'Narasi sektor saat ini cenderung punya bahan bakar karena ada tema yang bisa dijual ke pasar, sehingga peluang rerating 1-6 bulan lebih terbuka.';
        }
        if ($rotationScore < 0) {
            return 'Narasi sektor saat ini belum sekuat yang dibutuhkan untuk memicu tren besar; saham tetap bisa jalan, tapi biasanya lebih selektif dan rentan fake move.';
        }

        return 'Narasi sektor masih campuran. Fokus utama ada pada katalis spesifik emiten dan apakah sektor ini berhasil menarik rotasi dana berikutnya.';
    }

    private function fetchNewsBundle(array $queries, int $limit = 8): array
    {
        $items = [];

        foreach ($queries as $query) {
            $rssUrl = 'https://news.google.com/rss/search?q=' . rawurlencode(trim($query) . ' when:90d') . '&hl=id&gl=ID&ceid=ID:id';
            $raw = $this->fetchUrl($rssUrl);
            if ($raw === '') {
                continue;
            }

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw);
            libxml_clear_errors();
            if (!$xml instanceof SimpleXMLElement) {
                continue;
            }

            foreach ($xml->channel->item ?? [] as $item) {
                $title = trim((string) ($item->title ?? ''));
                $link = trim((string) ($item->link ?? ''));
                $publishedAt = trim((string) ($item->pubDate ?? ''));
                if ($title === '' || $link === '') {
                    continue;
                }

                $key = sha1($title . '|' . $link);
                $items[$key] = [
                    'title' => $title,
                    'url' => $link,
                    'published_at' => $publishedAt,
                ];
            }
        }

        $items = array_values($items);
        usort($items, static function (array $a, array $b): int {
            return strtotime((string) ($b['published_at'] ?? '')) <=> strtotime((string) ($a['published_at'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    private function fetchUrl(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => 'TrackingBandar/1.0 CIO Analyst',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        return is_string($raw) ? $raw : '';
    }

    private function newsScore(array $news, array $positive, array $negative): int
    {
        $score = 0;
        foreach ($news as $item) {
            $text = strtolower((string) (($item['title'] ?? '') . ' ' . ($item['description'] ?? '')));
            foreach ($positive as $term) {
                if ($term !== '' && str_contains($text, strtolower($term))) {
                    $score++;
                }
            }
            foreach ($negative as $term) {
                if ($term !== '' && str_contains($text, strtolower($term))) {
                    $score--;
                }
            }
        }

        return $score;
    }

    private function tailwindLabel(int $score): string
    {
        if ($score >= 2) {
            return 'Tailwind';
        }
        if ($score <= -2) {
            return 'Headwind';
        }

        return 'Netral';
    }

    private function macroParagraph(string $label, int $score, string $base, string $sectorImpact): string
    {
        $stance = $this->tailwindLabel($score);
        return $label . ' saat ini cenderung ' . strtolower($stance) . '. ' . $base . ' ' . $sectorImpact;
    }

    private function stanceScore(string $stance): int
    {
        return match (strtolower($stance)) {
            'tailwind' => 6,
            'headwind' => -6,
            default => 0,
        };
    }

    private function convictionLabel(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Sangat meyakinkan',
            $score >= 75 => 'Meyakinkan',
            $score >= 65 => 'Menarik tapi selektif',
            $score >= 50 => 'Netral',
            default => 'Lemah',
        };
    }

    private function formatPrice(float $value): string
    {
        return number_format(max(0, round($value)), 0, ',', '.');
    }

    private function commodityKeyword(string $sector): string
    {
        $sector = strtolower($sector);
        $map = [
            'batubara' => 'batubara',
            'coal' => 'batubara',
            'nikel' => 'nikel',
            'cpo' => 'CPO',
            'sawit' => 'CPO',
            'energi' => 'minyak dan gas',
            'oil' => 'minyak mentah',
            'gas' => 'gas alam',
        ];

        foreach ($map as $needle => $keyword) {
            if ($sector !== '' && str_contains($sector, $needle)) {
                return $keyword;
            }
        }

        return '';
    }

    private function dataNotes(array $reference, string $sector): array
    {
        $notes = [];
        if (trim((string) ($reference['sector'] ?? '')) === '' && trim($sector) !== '') {
            $notes[] = 'Sektor saat ini memakai input/heuristik karena referensi sektor resmi emiten belum lengkap di database lokal.';
        }
        $notes[] = 'Bagian laporan keuangan mendalam seperti GPM, NPM 3 tahun, FCF, DER, interest coverage, dan mean PE/PBV 5 tahun belum terintegrasi otomatis, jadi analisa fundamental masih bersifat semi-terstruktur.';
        $notes[] = 'Halaman ini dirancang untuk menggabungkan data internal broker flow dengan konteks luar terbaru, bukan menggantikan pengecekan manual atas laporan keuangan resmi emiten.';

        return $notes;
    }
}
