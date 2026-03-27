<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peluang Besok</title>
    <link rel="stylesheet" href="./assets/app.css">
</head>
<body>
    <div class="wrap">
        <section class="hero centered">
            <span class="eyebrow">Peluang Besok</span>
            <h1>Peluang Besok</h1>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="link" href="./tracker-berulang.php">Analisis Saham</a>
                <a class="link" href="./radar-potensial.php">High Convection</a>
                <form class="search-form" id="single-screen-form">
                    <input type="text" id="single-symbol" name="symbol" placeholder="Cari simbol, mis. BBCA" autocomplete="off">
                    <button class="button icon-button" type="submit" title="Cari Saham" aria-label="Cari Saham">⌕<span class="sr-only">Cari Saham</span></button>
                </form>
                <button class="button secondary icon-button" type="button" id="load-btn" title="Muat Hasil Peluang Besok" aria-label="Muat Hasil Peluang Besok">🗂<span class="sr-only">Muat Hasil Peluang Besok</span></button>
                <button class="button icon-button" type="button" id="start-btn" title="Jalankan Scan Peluang Besok" aria-label="Jalankan Scan Peluang Besok">▶<span class="sr-only">Jalankan Scan Peluang Besok</span></button>
                <button class="button secondary icon-button" type="button" id="cancel-btn" title="Cancel Scan Peluang Besok" aria-label="Cancel Scan Peluang Besok">✕<span class="sr-only">Cancel Scan Peluang Besok</span></button>
            </div>
            <div class="notice" id="message">Klik muat hasil tersimpan atau jalankan scan full market khusus peluang besok.</div>
        </section>

        <section class="stats" id="stats"></section>
        <section class="grid radar-grid" id="items"></section>
    </div>

    <script>
        const loadBtn = document.getElementById('load-btn');
        const startBtn = document.getElementById('start-btn');
        const cancelBtn = document.getElementById('cancel-btn');
        const singleScreenForm = document.getElementById('single-screen-form');
        const singleSymbolEl = document.getElementById('single-symbol');
        const statsEl = document.getElementById('stats');
        const itemsEl = document.getElementById('items');
        const messageEl = document.getElementById('message');
        const state = { running: false, pollHandle: null };

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
            const reasons = item.next_day_reasons || item.next_day_failures || [];
            const reasonsTitle = item.next_day_reasons ? 'Alasan lolos' : 'Belum lolos karena';
            const badgeText = item.next_day_reasons ? 'Besok Siap Pantau' : 'Belum Lolos';
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
                        <span class="badge">${escapeHtml(badgeText)}</span>
                        <div class="metrics">
                            <div class="metric"><span>Clean Acc</span><strong>${escapeHtml(String(metrics.clean_ratio || 0))}%</strong></div>
                            <div class="metric"><span>Repeat Broker</span><strong>${escapeHtml(String(metrics.repeat_ratio || 0))}%</strong></div>
                            <div class="metric"><span>Acc Ratio</span><strong>${escapeHtml(String(metrics.acc_ratio || 0))}%</strong></div>
                            <div class="metric"><span>Dominance Gap</span><strong>${escapeHtml(String(metrics.dominance_gap || 0))}%</strong></div>
                            <div class="metric"><span>Turnover Accel</span><strong>${escapeHtml(String(metrics.turnover_acceleration || 0))}x</strong></div>
                            <div class="metric"><span>Close vs Open</span><strong>${escapeHtml(String(metrics.intraday_close_vs_open_pct || 0))}%</strong></div>
                            <div class="metric"><span>Breakout</span><strong>${escapeHtml(String(metrics.breakout_pct || 0))}%</strong></div>
                            <div class="metric"><span>Extension</span><strong>${escapeHtml(String(metrics.extension_pct || 0))}%</strong></div>
                        </div>
                        <div class="reasons">
                            <strong>${escapeHtml(reasonsTitle)}</strong>
                            <ul>${reasons.map((reason) => `<li>${escapeHtml(reason)}</li>`).join('')}</ul>
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
            const meta = data.meta || {};
            const summary = meta.summary || {};

            state.running = meta.status === 'running';
            startBtn.disabled = state.running;
            startBtn.innerHTML = state.running
                ? '◌<span class="sr-only">Scan Peluang Besok Sedang Berjalan</span>'
                : '▶<span class="sr-only">Jalankan Scan Peluang Besok</span>';
            cancelBtn.disabled = !state.running;

            statsEl.innerHTML = [
                statCard('Status', state.running ? 'Running' : 'Idle', meta.current_symbol ? `Sedang memproses ${meta.current_symbol}` : 'Worker berjalan terpisah dari website.'),
                statCard('Progress', `${summary.scanned || 0}/${meta.total || 0}`, `Lolos filter peluang besok: ${summary.matched || 0} saham.`),
                statCard('Errors', String(summary.errors || 0), 'Kalau tinggi, biasanya token atau request live bermasalah.'),
                statCard('Turnover', `>= ${rules.turnover_acceleration_min ?? 0.6}x`, 'Percepatan turnover harus hidup.'),
                statCard('Dataset', String(data.dataset?.count || 0), 'Jumlah item yang tersimpan penuh untuk testing.')
            ].join('');

            if (!items.length) {
                itemsEl.innerHTML = '<article class="card item"><div class="muted">Belum ada saham yang lolos filter peluang besok dari hasil analisis yang tersimpan.</div></article>';
                if ((summary.errors || 0) > 0) {
                    messageEl.textContent = `Scan selesai tetapi ${summary.errors || 0} request gagal. Kemungkinan token Stockbit sudah tidak valid untuk batch scan. Impor token lagi lalu jalankan ulang.`;
                } else {
                    messageEl.textContent = state.running
                        ? `Scan peluang besok sedang berjalan. Progress ${summary.scanned || 0}/${meta.total || 0}.`
                        : 'Belum ada saham yang cukup kuat untuk shortlist besok.';
                }
                managePolling();
                return;
            }

            itemsEl.innerHTML = items.map(renderItem).join('');
            messageEl.textContent = state.running
                ? `Scan peluang besok sedang berjalan. Progress ${summary.scanned || 0}/${meta.total || 0}. Hasil tersimpan terakhir tetap tampil.`
                : `${items.length} saham lolos shortlist peluang besok dari full market scan terakhir. Dataset testing tersimpan: ${data.dataset?.count || 0} item.`;
            managePolling();
        }

        async function loadRadar() {
            const response = await fetch('./api/next-day.php', { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memuat peluang besok.');
            }
            render(data);
        }

        async function loadSingleSymbol(symbol) {
            const response = await fetch(`./api/next-day.php?symbol=${encodeURIComponent(symbol)}`, { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal screen simbol peluang besok.');
            }

            statsEl.innerHTML = [
                statCard('Mode', 'Single Symbol', `Screen cepat untuk ${data.symbol || symbol}.`),
                statCard('Status', data.passed ? 'Lolos' : 'Belum Lolos', data.passed ? 'Simbol ini masuk shortlist peluang besok.' : 'Masih ada syarat yang belum terpenuhi.'),
                statCard('Score', String(data.item?.score || 0), 'Score dasar setelah refinement historikal.'),
                statCard('Turnover', `${data.item?.metrics?.turnover_acceleration || 0}x`, 'Percepatan turnover simbol ini.'),
                statCard('Riwayat', String(data.history_count || 0), 'Jumlah hasil search yang sudah tersimpan untuk testing.')
            ].join('');

            itemsEl.innerHTML = renderItem(data.item);
            messageEl.textContent = data.passed
                ? `${data.symbol} lolos filter peluang besok dan hasilnya sudah tersimpan.`
                : `${data.symbol} sudah discren cepat, belum lolos filter peluang besok, dan hasilnya sudah tersimpan untuk testing.`;
        }

        async function startRadar() {
            const response = await fetch('./api/next-day.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start' }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memulai peluang besok.');
            }
            render(data);
        }

        async function cancelRadar() {
            const response = await fetch('./api/next-day.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cancel' }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal cancel peluang besok.');
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
                    messageEl.textContent = error.message || 'Gagal memuat peluang besok.';
                });
            }, 3000);
        }

        loadBtn.addEventListener('click', () => {
            messageEl.textContent = 'Memuat shortlist peluang besok...';
            loadRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal memuat peluang besok.';
            });
        });

        startBtn.addEventListener('click', () => {
            startRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal memulai peluang besok.';
            });
        });

        cancelBtn.addEventListener('click', () => {
            cancelRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal cancel peluang besok.';
            });
        });

        singleScreenForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const symbol = (singleSymbolEl.value || '').trim().toUpperCase();
            if (!symbol) {
                messageEl.textContent = 'Masukkan simbol dulu untuk screen cepat.';
                return;
            }

            messageEl.textContent = `Screen cepat ${symbol}...`;
            loadSingleSymbol(symbol).catch((error) => {
                messageEl.textContent = error.message || 'Gagal screen simbol peluang besok.';
            });
        });

        loadRadar().catch((error) => {
            messageEl.textContent = error.message || 'Gagal memuat peluang besok.';
        });
    </script>
</body>
</html>
