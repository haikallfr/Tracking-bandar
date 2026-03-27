<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radar Sangat Potensial</title>
    <link rel="stylesheet" href="./assets/app.css">
</head>
<body>
    <div class="wrap">
        <section class="hero centered">
            <span class="eyebrow">High Convection</span>
            <h1>High Convection</h1>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="link" href="./peluang-besok.php">Peluang Besok</a>
                <form class="search-form" action="./radar-saham.php" method="get">
                    <input type="text" name="symbol" placeholder="Cari simbol, mis. BBCA" autocomplete="off">
                    <input type="hidden" name="mode" value="high">
                    <button class="button icon-button" type="submit" title="Cari Saham" aria-label="Cari Saham">⌕<span class="sr-only">Cari Saham</span></button>
                </form>
                <button class="button secondary icon-button" type="button" id="load-btn" title="Muat Hasil Filter" aria-label="Muat Hasil Filter">🗂<span class="sr-only">Muat Hasil Filter</span></button>
            </div>
            <div class="notice" id="message">Klik `Muat Hasil Filter` untuk melihat kandidat yang lolos filter setup ketat.</div>
        </section>

        <section class="stats" id="stats"></section>
        <section class="grid radar-grid" id="items"></section>
    </div>

    <script>
        const loadBtn = document.getElementById('load-btn');
        const statsEl = document.getElementById('stats');
        const itemsEl = document.getElementById('items');
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

        function statCard(label, value, hint) {
            return `
                <article class="card stat">
                    <span class="eyebrow">${label}</span>
                    <strong>${escapeHtml(value)}</strong>
                    <div class="muted">${escapeHtml(hint)}</div>
                </article>
            `;
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

        function renderItem(item) {
            const metrics = item.metrics || {};
            return `
                <details class="card item">
                    <summary class="item-summary">
                        <div class="item-head">
                            <h2>${escapeHtml(item.symbol)}</h2>
                            <div class="summary-meta">
                                <div class="score">${escapeHtml(String(item.score))}</div>
                                <div class="tap-hint">Klik detail</div>
                            </div>
                        </div>
                    </summary>
                    <div class="item-body">
                        <div class="muted">${escapeHtml(item.from || '-')} sampai ${escapeHtml(item.to || '-')}</div>
                        <span class="badge">${escapeHtml(item.label || 'Prime Setup')}</span>
                        <div class="metrics">
                            <div class="metric"><span>Repeat Broker</span><strong>${escapeHtml(String(metrics.repeat_ratio || 0))}%</strong></div>
                            <div class="metric"><span>Clean Acc</span><strong>${escapeHtml(String(metrics.clean_ratio || 0))}%</strong></div>
                            <div class="metric"><span>Acc Ratio</span><strong>${escapeHtml(String(metrics.acc_ratio || 0))}%</strong></div>
                            <div class="metric"><span>Dominance Gap</span><strong>${escapeHtml(String(metrics.dominance_gap || 0))}%</strong></div>
                            <div class="metric"><span>Turnover Accel</span><strong>${escapeHtml(String(metrics.turnover_acceleration || 0))}x</strong></div>
                            <div class="metric"><span>Extension</span><strong>${escapeHtml(String(metrics.extension_pct || 0))}%</strong></div>
                            <div class="metric"><span>Intraday Range</span><strong>${escapeHtml(String(metrics.intraday_range_pct || 0))}%</strong></div>
                            <div class="metric"><span>Close vs Open</span><strong>${escapeHtml(String(metrics.intraday_close_vs_open_pct || 0))}%</strong></div>
                        </div>
                        <div class="reasons">
                            <strong>Alasan lolos filter</strong>
                            <ul>${(item.elite_reasons || []).map((reason) => `<li>${escapeHtml(reason)}</li>`).join('')}</ul>
                        </div>
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
                    </div>
                </details>
            `;
        }

        function render(data) {
            const items = Array.isArray(data.radar?.items) ? data.radar.items : [];
            const rules = data.rules || {};
            const anyOf = rules.any_of || {};

            statsEl.innerHTML = [
                statCard('Sumber Radar', String(data.source?.count || 0), 'Jumlah kandidat radar probabilitas tinggi tersimpan.'),
                statCard('Lolos Filter', String(data.radar?.count || 0), 'Kandidat yang tersisa setelah filter setup ketat.'),
                statCard('Distribusi', `<= ${rules.dist_days_max ?? 1} hari`, 'Distribusi harus sangat minim.'),
                statCard('Trigger Inti', `Turnover >= ${rules.turnover_acceleration_min ?? 0.45}x`, `Breakout <= ${rules.breakout_pct_max ?? -7}% dan extension <= ${rules.extension_pct_max ?? 4}%.`)
            ].join('');

            if (!items.length) {
                itemsEl.innerHTML = '<article class="card item"><div class="muted">Belum ada saham yang lolos filter sangat potensial dari hasil radar tersimpan saat ini.</div></article>';
                messageEl.textContent = 'Belum ada saham yang lolos filter sangat potensial.';
                return;
            }

            itemsEl.innerHTML = items.map(renderItem).join('');
            messageEl.textContent = `Filter ketat selesai. ${items.length} saham lolos dari ${data.source?.count || 0} kandidat radar probabilitas tinggi.`;
        }

        async function loadRadar() {
            const response = await fetch('./api/radar-elite.php', { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memuat radar sangat potensial.');
            }
            render(data);
        }

        loadBtn.addEventListener('click', () => {
            messageEl.textContent = 'Memuat hasil filter sangat potensial...';
            loadRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal memuat radar sangat potensial.';
            });
        });

        loadRadar().catch((error) => {
            messageEl.textContent = error.message || 'Gagal memuat radar sangat potensial.';
        });
    </script>
</body>
</html>
