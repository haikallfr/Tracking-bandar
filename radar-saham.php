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
    <link rel="stylesheet" href="./assets/app.css">
</head>
<body>
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

            resultEl.innerHTML = renderItem(data.item, data.mode || mode);
            messageEl.textContent = `${symbol} berhasil dimuat pada mode ${data.mode === 'basic' ? 'radar dasar' : 'probabilitas tinggi'}.`;
        }

        if (initialSymbol) {
            loadSymbol(initialSymbol, initialMode).catch((error) => {
                messageEl.textContent = error.message || 'Gagal memuat radar saham.';
                resultEl.innerHTML = '<article class="card item"><div class="muted">Gagal memuat detail saham.</div></article>';
            });
        }
    </script>
</body>
</html>
