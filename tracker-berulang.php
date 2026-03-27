<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radar Bandar Full Market</title>
    <link rel="stylesheet" href="./assets/app.css">
</head>
<body>
    <div class="wrap">
        <section class="hero centered">
            <span class="eyebrow">Analisis Saham</span>
            <h1>Analisis Saham</h1>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="link" href="./radar-potensial.php">High Convection</a>
                <a class="link" href="./peluang-besok.php">Peluang Besok</a>
                <form class="search-form" action="./radar-saham.php" method="get">
                    <input type="text" name="symbol" placeholder="Cari simbol, mis. BBCA" autocomplete="off">
                    <input type="hidden" name="mode" value="high">
                    <button class="button icon-button" type="submit" title="Cari Saham" aria-label="Cari Saham">⌕<span class="sr-only">Cari Saham</span></button>
                </form>
                <button class="button secondary icon-button" type="button" id="load-btn" title="Muat Hasil Screening Tersimpan" aria-label="Muat Hasil Screening Tersimpan">🗂<span class="sr-only">Muat Hasil Screening Tersimpan</span></button>
                <button class="button icon-button" type="button" id="start-btn" title="Jalankan Full Market Radar" aria-label="Jalankan Full Market Radar">▶<span class="sr-only">Jalankan Full Market Radar</span></button>
                <button class="button secondary icon-button" type="button" id="cancel-btn" title="Cancel Scan" aria-label="Cancel Scan">✕<span class="sr-only">Cancel Scan</span></button>
            </div>
            <div class="notice" id="message">Belum ada run aktif. Klik `Muat Hasil Screening Tersimpan` untuk melihat hasil terakhir atau jalankan scan baru bila perlu.</div>
        </section>

        <section class="stats" id="stats"></section>
        <section class="grid radar-grid" id="items"></section>
    </div>

    <script>
        const state = { pollHandle: null, running: false };
        const startBtn = document.getElementById('start-btn');
        const loadBtn = document.getElementById('load-btn');
        const cancelBtn = document.getElementById('cancel-btn');
        const statsEl = document.getElementById('stats');
        const itemsEl = document.getElementById('items');
        const messageEl = document.getElementById('message');

        function escapeHtml(text) {
            return String(text)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        }

        function setMessage(text) {
            messageEl.textContent = text;
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
            const enrichment = item.enrichment || {};
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
                        <div>
                            <div class="muted">${escapeHtml(item.from || '-')} sampai ${escapeHtml(item.to || '-')}</div>
                            <span class="badge">${escapeHtml(item.label)}</span>
                        </div>
                    <div class="metrics">
                        <div class="metric"><span>Buy / Turnover</span><strong>${escapeHtml(String(metrics.buy_market_share || 0))}%</strong></div>
                        <div class="metric"><span>Buy / Volume</span><strong>${escapeHtml(String(metrics.buy_lot_share || 0))}%</strong></div>
                        <div class="metric"><span>Konsentrasi Buyer</span><strong>${escapeHtml(String(metrics.buy_concentration || 0))}%</strong></div>
                        <div class="metric"><span>Dominance Gap</span><strong>${escapeHtml(String(metrics.dominance_gap || 0))}%</strong></div>
                        <div class="metric"><span>Repeat Broker</span><strong>${escapeHtml(String(metrics.repeat_ratio || 0))}%</strong></div>
                        <div class="metric"><span>Clean Acc Days</span><strong>${escapeHtml(String(metrics.clean_ratio || 0))}%</strong></div>
                        <div class="metric"><span>Turnover Accel</span><strong>${escapeHtml(String(metrics.turnover_acceleration || 0))}x</strong></div>
                        <div class="metric"><span>Breakout / Extension</span><strong>${escapeHtml(String(metrics.breakout_pct || 0))}% / ${escapeHtml(String(metrics.extension_pct || 0))}%</strong></div>
                        <div class="metric"><span>Close vs Open</span><strong>${escapeHtml(String(metrics.intraday_close_vs_open_pct || 0))}%</strong></div>
                        <div class="metric"><span>Range Intraday</span><strong>${escapeHtml(String(metrics.intraday_range_pct || 0))}%</strong></div>
                        <div class="metric"><span>Close vs Tail Avg</span><strong>${escapeHtml(String(metrics.intraday_close_vs_tail_avg_pct || 0))}%</strong></div>
                        <div class="metric"><span>Tail Compression</span><strong>${escapeHtml(String(metrics.tail_compression_pct || 0))}%</strong></div>
                    </div>
                    <div class="reasons">
                        <strong>Alasan skor</strong>
                        <ul>${(item.reasons || []).map((reason) => `<li>${escapeHtml(reason)}</li>`).join('')}</ul>
                    </div>
                    ${enrichment.history_days ? `
                        <div class="enrichment">
                            <strong>Konfirmasi historikal</strong>
                            <ul>
                                <li>Snapshot harian tersimpan: ${escapeHtml(String(enrichment.history_days || 0))} hari</li>
                                <li>Broker dominan berulang: ${escapeHtml(enrichment.repeat_broker_code || '-')} selama ${escapeHtml(String(enrichment.repeat_broker_days || 0))} hari</li>
                                <li>Buyer lebih kuat dari seller: ${escapeHtml(String(enrichment.clean_buyer_days || 0))} hari</li>
                                <li>Tekanan buyer tunggal kuat: ${escapeHtml(String(enrichment.buy_dominance_days || 0))} hari</li>
                            </ul>
                        </div>
                    ` : ''}
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
            const meta = data.meta || {};
            const radar = data.radar || {};
            const summary = meta.summary || {};
            const items = Array.isArray(radar.items) ? radar.items : [];

            state.running = meta.status === 'running';
            startBtn.disabled = state.running;
            startBtn.innerHTML = state.running
                ? '◌<span class="sr-only">Radar Sedang Berjalan</span>'
                : '▶<span class="sr-only">Jalankan Full Market Radar</span>';
            loadBtn.disabled = false;
            cancelBtn.disabled = !state.running;

            statsEl.innerHTML = [
                statCard('Status', state.running ? 'Running' : 'Idle', meta.current_symbol ? `Sedang memproses ${meta.current_symbol}` : 'Worker berjalan terpisah dari website.'),
                statCard('Progress', `${summary.scanned || 0}/${meta.total || 0}`, `Lolos score ${meta.threshold || 95}: ${summary.matched || 0} saham pada scan aktif.`),
                statCard('Last Finish', radar.generated_at ? new Date(radar.generated_at).toLocaleString('id-ID') : '-', 'Hasil tersimpan terakhir tidak dihapus saat scan baru berjalan.'),
                statCard('Errors', String(summary.errors || 0), 'Jumlah simbol yang gagal diproses.')
            ].join('');

            if (!items.length) {
                itemsEl.innerHTML = '<article class="card item"><div class="muted">Belum ada saham dengan score 95+ pada hasil screening tersimpan saat ini.</div></article>';
            } else {
                itemsEl.innerHTML = items.map(renderItem).join('');
            }

            if (state.running) {
                setMessage(`Full market radar sedang berjalan. Progress ${summary.scanned || 0}/${meta.total || 0}. Simbol aktif: ${meta.current_symbol || '-'}. Hasil tersimpan terakhir tetap bisa dimuat sampai scan baru selesai.`);
            } else if (radar.generated_at) {
                setMessage(`Hasil screening tersimpan terakhir selesai ${new Date(radar.generated_at).toLocaleString('id-ID')}. Ditampilkan ${radar.count || 0} saham dengan score minimal ${meta.threshold || 95}.`);
            } else {
                setMessage('Belum ada run aktif. Klik `Muat Hasil Screening Tersimpan` untuk melihat hasil terakhir atau jalankan scan baru bila perlu.');
            }

            managePolling();
        }

        async function loadRadar() {
            const response = await fetch('./api/radar.php', { cache: 'no-store' });
            const data = await response.json();
            render(data);
        }

        async function startRadar() {
            const response = await fetch('./api/radar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start' }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memulai radar.');
            }
            render(data);
        }

        async function cancelRadar() {
            const response = await fetch('./api/radar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cancel' }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal cancel radar.');
            }
            render(data);
        }

        function managePolling() {
            if (state.pollHandle) {
                clearInterval(state.pollHandle);
                state.pollHandle = null;
            }

            if (!state.running) {
                return;
            }

            state.pollHandle = setInterval(() => {
                loadRadar().catch((error) => {
                    setMessage(error.message || 'Gagal memuat progress radar.');
                });
            }, 3000);
        }

        startBtn.addEventListener('click', () => {
            startRadar().catch((error) => {
                setMessage(error.message || 'Gagal memulai radar.');
            });
        });

        loadBtn.addEventListener('click', () => {
            setMessage('Memuat hasil screening tersimpan terakhir...');
            loadRadar().catch((error) => {
                setMessage(error.message || 'Gagal memuat hasil screening tersimpan.');
            });
        });

        cancelBtn.addEventListener('click', () => {
            setMessage('Mengirim permintaan cancel scan...');
            cancelRadar().catch((error) => {
                setMessage(error.message || 'Gagal cancel radar.');
            });
        });

        loadRadar().catch((error) => {
            setMessage(error.message || 'Gagal memuat radar.');
        });
    </script>
</body>
</html>
