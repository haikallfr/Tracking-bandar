<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radar Dasar Sederhana Full Market</title>
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
            <span class="eyebrow">Analisis Saham Sederhana</span>
            <h1>Analisis Saham Sederhana</h1>
            <p class="lead">Skor 87+. Formula sederhana. Klik detail untuk lihat alasan.</p>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="button" href="./tracker-berulang.php">Analisis Saham</a>
                <a class="link" href="./radar-potensial.php">High Convection</a>
                <form class="search-form" action="./radar-saham.php" method="get">
                    <input type="text" name="symbol" placeholder="Cari simbol, mis. BBCA" autocomplete="off">
                    <input type="hidden" name="mode" value="basic">
                    <button class="button icon-button" type="submit" title="Cari Saham" aria-label="Cari Saham">⌕<span class="sr-only">Cari Saham</span></button>
                </form>
                <button class="button secondary icon-button" type="button" id="load-btn" title="Muat Hasil Screening Tersimpan" aria-label="Muat Hasil Screening Tersimpan">🗂<span class="sr-only">Muat Hasil Screening Tersimpan</span></button>
                <button class="button icon-button" type="button" id="start-btn" title="Jalankan Radar Dasar Full Market" aria-label="Jalankan Radar Dasar Full Market">▶<span class="sr-only">Jalankan Radar Dasar Full Market</span></button>
                <button class="button secondary icon-button" type="button" id="cancel-btn" title="Cancel Scan" aria-label="Cancel Scan">✕<span class="sr-only">Cancel Scan</span></button>
            </div>
            <div class="notice" id="message">Belum ada run aktif. Klik `Muat Hasil Screening Tersimpan` untuk membuka hasil terakhir atau jalankan scan baru bila perlu.</div>
        </section>

        <section class="stats" id="stats"></section>
        <section class="grid radar-grid" id="items"></section>
    </div>

    <script>
        const statsEl = document.getElementById('stats');
        const itemsEl = document.getElementById('items');
        const messageEl = document.getElementById('message');
        const startBtn = document.getElementById('start-btn');
        const loadBtn = document.getElementById('load-btn');
        const cancelBtn = document.getElementById('cancel-btn');
        const state = { running: false, pollHandle: null };

        function escapeHtml(text) {
            return String(text)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
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
                    <div class="metrics">
                        <div class="metric"><span>Buy / Turnover</span><strong>${escapeHtml(String(metrics.buy_market_share || 0))}%</strong></div>
                        <div class="metric"><span>Buy / Volume</span><strong>${escapeHtml(String(metrics.buy_lot_share || 0))}%</strong></div>
                        <div class="metric"><span>Konsentrasi Buyer</span><strong>${escapeHtml(String(metrics.buy_concentration || 0))}%</strong></div>
                        <div class="metric"><span>Frequency Intensity</span><strong>${escapeHtml(String(metrics.frequency_intensity || 0))}%</strong></div>
                        <div class="metric"><span>Dominance Gap</span><strong>${escapeHtml(String(metrics.dominance_gap || 0))}%</strong></div>
                    </div>
                    <div class="reasons" style="margin-top:14px;">
                        <strong>Alasan dasar</strong>
                        <ul>${(item.reasons || []).map((reason) => `<li>${escapeHtml(reason)}</li>`).join('')}</ul>
                    </div>
                    </div>
                </details>
            `;
        }

        function render(data) {
            const radar = data.radar || {};
            const meta = data.meta || {};
            const items = Array.isArray(radar.items) ? radar.items : [];
            state.running = meta.status === 'running';
            startBtn.disabled = state.running;
            startBtn.innerHTML = state.running
                ? '◌<span class="sr-only">Radar Dasar Sedang Berjalan</span>'
                : '▶<span class="sr-only">Jalankan Radar Dasar Full Market</span>';
            loadBtn.disabled = false;
            cancelBtn.disabled = !state.running;

            statsEl.innerHTML = [
                statCard('Status', state.running ? 'Running' : 'Idle', meta.current_symbol ? `Sedang memproses ${meta.current_symbol}` : 'Worker terpisah dari website.'),
                statCard('Progress', `${meta.scanned || 0}/${meta.total || 0}`, `Hanya score > ${meta.threshold || 87} yang ditampilkan.`),
                statCard('Komponen', String((meta.formula || []).length), (meta.formula || []).join(', ')),
                statCard('Last Build', radar.generated_at ? new Date(radar.generated_at).toLocaleString('id-ID') : '-', 'Hasil tersimpan terakhir tidak dihapus saat scan baru berjalan.')
            ].join('');

            if (!items.length) {
                itemsEl.innerHTML = '<article class="card item"><div class="muted">Belum ada hasil tersimpan untuk radar dasar full market.</div></article>';
            } else {
                itemsEl.innerHTML = items.map(renderItem).join('');
                window.bindAnimatedDetails?.(itemsEl);
            }

            if (state.running) {
                messageEl.textContent = `Radar dasar full market sedang berjalan. Progress ${meta.scanned || 0}/${meta.total || 0}. Simbol aktif: ${meta.current_symbol || '-'}. Hasil tersimpan terakhir tetap bisa dimuat sampai scan baru selesai.`;
            } else if (radar.generated_at) {
                messageEl.textContent = `Hasil screening tersimpan terakhir selesai ${new Date(radar.generated_at).toLocaleString('id-ID')}. Ditampilkan ${items.length} saham versi formula sederhana lama dengan score > ${meta.threshold || 87}.`;
            } else {
                messageEl.textContent = 'Belum ada run aktif. Klik `Muat Hasil Screening Tersimpan` untuk membuka hasil terakhir atau jalankan scan baru bila perlu.';
            }

            managePolling();
        }

        async function loadRadar() {
            const response = await fetch('./api/radar-basic.php', { cache: 'no-store' });
            const data = await response.json();
            render(data);
        }

        async function startRadar() {
            const response = await fetch('./api/radar-basic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'start' }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memulai radar dasar.');
            }
            render(data);
        }

        async function cancelRadar() {
            const response = await fetch('./api/radar-basic.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'cancel' }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal cancel radar dasar.');
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
                    messageEl.textContent = error.message || 'Gagal memuat radar dasar.';
                });
            }, 3000);
        }

        startBtn.addEventListener('click', () => {
            startRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal memulai radar dasar.';
            });
        });

        loadBtn.addEventListener('click', () => {
            messageEl.textContent = 'Memuat hasil screening tersimpan terakhir...';
            loadRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal memuat hasil screening tersimpan.';
            });
        });

        cancelBtn.addEventListener('click', () => {
            messageEl.textContent = 'Mengirim permintaan cancel scan...';
            cancelRadar().catch((error) => {
                messageEl.textContent = error.message || 'Gagal cancel radar dasar.';
            });
        });

        loadRadar().catch((error) => {
            messageEl.textContent = error.message || 'Gagal memuat radar dasar.';
        });
    </script>
    <script src="./assets/details-animate.js"></script>
    <script src="./assets/theme.js"></script>
</body>
</html>
