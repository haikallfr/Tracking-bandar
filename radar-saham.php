<?php
declare(strict_types=1);

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$mode = strtolower(trim((string) ($_GET['mode'] ?? 'high')));
if (!in_array($mode, ['high', 'basic'], true)) {
    $mode = 'high';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radar Saham</title>
    <script>
        (() => {
            const saved = localStorage.getItem('tracking_bandar_theme');
            const dark = saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.dataset.theme = 'dark';
        })();
    </script>
    <link rel="stylesheet" href="./assets/app.css">
</head>
<body>
    <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Aktifkan mode gelap" title="Mode gelap">☾</button>
    <div class="wrap">
        <section class="hero centered">
            <span class="eyebrow">Radar Saham</span>
            <h1>Detail satu simbol.</h1>
            <p class="lead">Cari satu saham dan tampilkan score radar.</p>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="link" href="./tracker-berulang.php">Analisis Saham</a>
                <a class="link" href="./radar-dasar.php">Analisis Saham Sederhana</a>
                <a class="link" href="./radar-potensial.php">High Convection</a>
                <form class="search-form" action="./radar-saham.php" method="get">
                    <input type="text" name="symbol" placeholder="Cari simbol, mis. BBCA" value="<?= htmlspecialchars($symbol, ENT_QUOTES) ?>" autocomplete="off">
                    <select name="mode">
                        <option value="high"<?= $mode === 'high' ? ' selected' : '' ?>>Analisis Saham</option>
                        <option value="basic"<?= $mode === 'basic' ? ' selected' : '' ?>>Analisis Saham Sederhana</option>
                    </select>
                    <button class="button icon-button" type="submit" title="Cari Saham" aria-label="Cari Saham">⌕<span class="sr-only">Cari Saham</span></button>
                </form>
            </div>
            <div class="notice" id="message">Masukkan simbol lalu cari untuk membuka detail satu saham.</div>
        </section>

        <section id="result"></section>
    </div>

    <script>
        const initialSymbol = <?= json_encode($symbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialMode = <?= json_encode($mode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const resultEl = document.getElementById('result');
        const messageEl = document.getElementById('message');
        let currentSymbol = initialSymbol;
        let currentMode = initialMode;

        function escapeHtml(text) {
            return String(text)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        }

        function formatCompactNumber(value) {
            const num = Math.abs(Number(value || 0));
            if (!num) return '0';
            if (num >= 1e12) return `${(num / 1e12).toFixed(1).replace(/\\.0$/, '')}T`;
            if (num >= 1e9) return `${(num / 1e9).toFixed(1).replace(/\\.0$/, '')}B`;
            if (num >= 1e6) return `${(num / 1e6).toFixed(1).replace(/\\.0$/, '')}M`;
            if (num >= 1e3) return `${(num / 1e3).toFixed(1).replace(/\\.0$/, '')}K`;
            return `${Math.round(num)}`;
        }

        function renderBrokerList(rows, side) {
            if (!Array.isArray(rows) || !rows.length) {
                return '<div class="muted">Tidak ada data broker.</div>';
            }

            return `<ul>${rows.map((row) => `
                <li>
                    <strong class="${side}">${escapeHtml(row.broker_code)}</strong>
                    ${escapeHtml(row.broker_type || '-')} • Val ${formatCompactNumber(row.value)} • Freq ${escapeHtml(String(row.freq || 0))}
                </li>
            `).join('')}</ul>`;
        }

        function metric(label, value) {
            return `<div class="metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>`;
        }

        function renderAnalysisList(items) {
            return `<ul>${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`;
        }

        function renderSystemPanel(symbol, mode) {
            return `
                <section class="card panel ai-panel">
                    <div class="ai-panel-head">
                        <div>
                            <span class="eyebrow">Analisa Sistem</span>
                            <h3 class="ai-title">Analisa internal ${escapeHtml(symbol)}</h3>
                            <div class="muted">Sistem membaca data radar, broker flow, dan historikal yang kita punya lalu menyusun keputusan otomatis.</div>
                        </div>
                        <div class="actions ai-actions">
                            <button class="button" type="button" id="ai-run-btn">Analisa Sistem</button>
                            <button class="button secondary" type="button" id="ai-refresh-btn">Refresh</button>
                        </div>
                    </div>
                    <div class="notice" id="ai-message">Klik Analisa Sistem untuk membaca ${escapeHtml(symbol)} pada mode ${escapeHtml(mode === 'basic' ? 'analisis sederhana' : 'analisis saham')}.</div>
                    <div id="ai-result"></div>
                </section>
            `;
        }

        function bindAiPanel(symbol, mode) {
            const runBtn = document.getElementById('ai-run-btn');
            const refreshBtn = document.getElementById('ai-refresh-btn');
            const aiMessageEl = document.getElementById('ai-message');
            const aiResultEl = document.getElementById('ai-result');

            async function loadAi(force = false) {
                aiMessageEl.textContent = force ? `Memperbarui analisa AI ${symbol}...` : `Menganalisa ${symbol} dengan AI...`;
                runBtn.disabled = true;
                refreshBtn.disabled = true;

                try {
                        const response = await fetch(`./api/system-analysis.php?symbol=${encodeURIComponent(symbol)}&mode=${encodeURIComponent(mode)}`, {
                            cache: 'no-store'
                        });
                        const data = await response.json();

                        if (!data.ok) {
                            throw new Error(data.message || 'Gagal memuat analisa sistem.');
                        }

                        const analysis = data.analysis?.analysis || {};
                        const external = data.analysis?.external_context || {};
                        const externalSignals = external.signals || {};
                        const news = Array.isArray(external.news) ? external.news : [];

                        aiResultEl.innerHTML = `
                            <article class="card item ai-result-card">
                                <div class="item-head">
                                    <div>
                                        <h2>${escapeHtml(symbol)}</h2>
                                        <div class="muted">${escapeHtml(data.analysis?.company?.company_name || '')}</div>
                                    </div>
                                    <div class="summary-meta">
                                        <div class="badge">${escapeHtml(analysis.setup || 'Analisa Sistem')}</div>
                                    </div>
                                </div>
                                <div class="item-body">
                                    <div class="reasons">
                                        <strong>Ringkasan</strong>
                                        ${renderAnalysisList(analysis.summary || [])}
                                    </div>
                                    <div class="reasons">
                                        <strong>Yang Sedang Terjadi</strong>
                                        ${renderAnalysisList(analysis.happening || [])}
                                    </div>
                                    <div class="reasons">
                                        <strong>Risiko Utama</strong>
                                        ${renderAnalysisList(analysis.risks || [])}
                                    </div>
                                    <div class="reasons">
                                        <strong>Keputusan</strong>
                                        <ul><li>${escapeHtml(analysis.decision || 'Layak Pantau')}</li></ul>
                                    </div>
                                    <div class="reasons">
                                        <strong>Yang Perlu Dipantau</strong>
                                        ${renderAnalysisList(analysis.next_watch || [])}
                                    </div>
                                    ${(externalSignals.summary || []).length || news.length ? `
                                        <div class="reasons">
                                            <strong>Konteks Luar</strong>
                                            ${renderAnalysisList(externalSignals.summary || [])}
                                            ${news.length ? `<ul>${news.slice(0, 6).map((item) => `<li><a href="${escapeHtml(item.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.title)}</a></li>`).join('')}</ul>` : ''}
                                        </div>
                                    ` : ''}
                                </div>
                            </article>
                        `;

                        aiMessageEl.textContent = `Analisa sistem ${symbol} berhasil dibuat.`;
                    } catch (error) {
                        aiMessageEl.textContent = error.message || 'Gagal memuat analisa sistem.';
                        aiResultEl.innerHTML = '<article class="card item"><div class="muted">Analisa sistem belum tersedia.</div></article>';
                    } finally {
                        runBtn.disabled = false;
                        refreshBtn.disabled = false;
                }
            }

            runBtn?.addEventListener('click', () => loadAi(false));
            refreshBtn?.addEventListener('click', () => loadAi(true));
        }

        function renderItem(item, mode) {
            const metrics = item.metrics || {};
            const enrichment = item.enrichment || {};
            const metricHtml = mode === 'basic'
                ? [
                    metric('Buy / Turnover', `${metrics.buy_market_share || 0}%`),
                    metric('Buy / Volume', `${metrics.buy_lot_share || 0}%`),
                    metric('Konsentrasi Buyer', `${metrics.buy_concentration || 0}%`),
                    metric('Frequency Intensity', `${metrics.frequency_intensity || 0}%`),
                    metric('Dominance Gap', `${metrics.dominance_gap || 0}%`)
                ].join('')
                : [
                    metric('Buy / Turnover', `${metrics.buy_market_share || 0}%`),
                    metric('Buy / Volume', `${metrics.buy_lot_share || 0}%`),
                    metric('Konsentrasi Buyer', `${metrics.buy_concentration || 0}%`),
                    metric('Dominance Gap', `${metrics.dominance_gap || 0}%`),
                    metric('Repeat Broker', `${metrics.repeat_ratio || 0}%`),
                    metric('Clean Acc Days', `${metrics.clean_ratio || 0}%`),
                    metric('Turnover Accel', `${metrics.turnover_acceleration || 0}x`),
                    metric('Breakout / Extension', `${metrics.breakout_pct || 0}% / ${metrics.extension_pct || 0}%`),
                    metric('Close vs Open', `${metrics.intraday_close_vs_open_pct || 0}%`),
                    metric('Range Intraday', `${metrics.intraday_range_pct || 0}%`),
                    metric('Close vs Tail Avg', `${metrics.intraday_close_vs_tail_avg_pct || 0}%`),
                    metric('Tail Compression', `${metrics.tail_compression_pct || 0}%`)
                ].join('');

            const enrichmentHtml = mode === 'high' && enrichment.history_days
                ? `
                    <div class="enrichment">
                        <strong>Konfirmasi historikal</strong>
                        <ul>
                            <li>Snapshot harian: ${escapeHtml(String(enrichment.history_days || 0))} hari</li>
                            <li>Broker dominan: ${escapeHtml(enrichment.repeat_broker_code || '-')} selama ${escapeHtml(String(enrichment.repeat_broker_days || 0))} hari</li>
                            <li>Buyer lebih kuat dari seller: ${escapeHtml(String(enrichment.clean_buyer_days || 0))} hari</li>
                            <li>Tekanan buyer dominan: ${escapeHtml(String(enrichment.buy_dominance_days || 0))} hari</li>
                        </ul>
                    </div>
                `
                : '';

            return `
                <article class="card item">
                    <div class="item-head">
                        <div>
                            <h2>${escapeHtml(item.symbol)}</h2>
                            <div class="muted">${escapeHtml(item.from || '-')} sampai ${escapeHtml(item.to || '-')}</div>
                        </div>
                        <div>
                            <div class="score">${escapeHtml(String(item.score))}</div>
                            <span class="badge">${escapeHtml(item.label || (mode === 'basic' ? 'Analisis Sederhana' : 'Analisis Saham'))}</span>
                        </div>
                    </div>
                    <div class="metrics">${metricHtml}</div>
                    <div class="reasons">
                        <strong>${mode === 'basic' ? 'Alasan dasar' : 'Alasan skor'}</strong>
                        <ul>${(item.reasons || []).map((reason) => `<li>${escapeHtml(reason)}</li>`).join('')}</ul>
                    </div>
                    ${enrichmentHtml}
                    <div class="brokers brokers-grid">
                        <div class="broker-block">
                            <h3 class="buy">Top Buyer</h3>
                            ${renderBrokerList(item.top_buyers || [], 'buy')}
                        </div>
                        <div class="broker-block">
                            <h3 class="sell">Top Seller</h3>
                            ${renderBrokerList(item.top_sellers || [], 'sell')}
                        </div>
                    </div>
                </article>
            `;
        }

        async function loadSymbol(symbol, mode) {
            if (!symbol) {
                return;
            }

            messageEl.textContent = `Memuat radar ${symbol}...`;
            const response = await fetch(`./api/radar-symbol.php?symbol=${encodeURIComponent(symbol)}&mode=${encodeURIComponent(mode)}`, {
                cache: 'no-store'
            });
            const data = await response.json();

            if (!data.ok) {
                throw new Error(data.message || 'Gagal memuat radar saham.');
            }

            if (!data.item) {
                resultEl.innerHTML = '<article class="card item"><div class="muted">Tidak ada data radar untuk simbol ini.</div></article>';
                messageEl.textContent = `Tidak ada data radar untuk ${symbol}.`;
                return;
            }

            currentSymbol = symbol;
            currentMode = data.mode || mode;
            resultEl.innerHTML = renderItem(data.item, currentMode) + renderSystemPanel(symbol, currentMode);
            bindAiPanel(symbol, currentMode);
            messageEl.textContent = `${symbol} berhasil dimuat pada mode ${data.mode === 'basic' ? 'radar dasar' : 'probabilitas tinggi'}.`;
        }

        if (initialSymbol) {
            loadSymbol(initialSymbol, initialMode).catch((error) => {
                messageEl.textContent = error.message || 'Gagal memuat radar saham.';
                resultEl.innerHTML = '<article class="card item"><div class="muted">Gagal memuat detail saham.</div></article>';
            });
        }
    </script>
    <script src="./assets/theme.js"></script>
</body>
</html>
