<?php
declare(strict_types=1);

$initialSymbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$initialSearch = trim((string) ($_GET['q'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KSEI Radar</title>
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
            <span class="eyebrow">Ownership Intelligence</span>
            <h1>KSEI Radar</h1>
            <p class="lead">Pantau akumulasi/distribusi pemegang saham di atas 1%, peta kepemilikan emiten, dan indikator free float dari data resmi KSEI yang sudah kita import.</p>
            <div class="hero-actions">
                <a class="nav-link" href="./index.php">Broker Summary</a>
                <a class="nav-link" href="./peluang-besok.php">Day Trade</a>
                <a class="nav-link" href="./cio-swing.php">CIO Swing</a>
            </div>
        </section>

        <section class="panel card ksei-control-panel">
            <form class="ksei-form" id="ksei-form">
                <div>
                    <label for="symbol-input">Detail Emiten</label>
                    <input id="symbol-input" name="symbol" type="text" placeholder="BBCA" value="<?= htmlspecialchars($initialSymbol, ENT_QUOTES) ?>" autocomplete="off">
                </div>
                <div>
                    <label for="search-input">Cari Emiten / Pemegang</label>
                    <input id="search-input" name="q" type="text" placeholder="Prajogo, Narada, BBCA..." value="<?= htmlspecialchars($initialSearch, ENT_QUOTES) ?>" autocomplete="off">
                </div>
                <div>
                    <label for="tier-select">Free Float</label>
                    <select id="tier-select" name="tier">
                        <option value="all">Semua Tier</option>
                        <option value="very_low">Sangat Rendah &lt; 10%</option>
                        <option value="low">Rendah 10-20%</option>
                        <option value="moderate">Moderat 20-40%</option>
                        <option value="liquid">Liquid ≥ 40%</option>
                    </select>
                </div>
                <button type="submit">Scan KSEI</button>
            </form>
            <div class="notice mini" id="ksei-message">Memuat KSEI Radar...</div>
        </section>

        <section class="topbar" id="ksei-stats"></section>
        <section id="ownership-result"></section>
        <section id="search-result"></section>

        <section class="ksei-dashboard">
            <article class="card content">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">01 — Aktivitas</span>
                        <h2>Top Akumulasi</h2>
                    </div>
                    <span class="badge" id="accumulation-date">-</span>
                </div>
                <div class="ksei-table" id="top-accumulation"></div>
            </article>

            <article class="card content">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">01 — Aktivitas</span>
                        <h2>Top Distribusi</h2>
                    </div>
                    <span class="badge" id="distribution-date">-</span>
                </div>
                <div class="ksei-table" id="top-distribution"></div>
            </article>

            <article class="card content">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Pola</span>
                        <h2>Akumulasi Berulang</h2>
                    </div>
                    <span class="badge">Multi Snapshot</span>
                </div>
                <div class="ksei-table" id="repeated-accumulation"></div>
            </article>

            <article class="card content">
                <div class="section-head">
                    <div>
                        <span class="eyebrow">Perubahan</span>
                        <h2>Pemegang Baru</h2>
                    </div>
                    <span class="badge">Masuk >1%</span>
                </div>
                <div class="ksei-table" id="new-holders"></div>
            </article>
        </section>

        <section class="card content">
            <div class="section-head">
                <div>
                    <span class="eyebrow">03 — Liquidity Indicator</span>
                    <h2>Free Float Screener</h2>
                </div>
                <span class="badge" id="free-float-count">-</span>
            </div>
            <div class="ksei-table wide" id="free-float"></div>
        </section>
    </div>

    <script src="./assets/theme.js"></script>
    <script>
        const initialSymbol = <?= json_encode($initialSymbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialSearch = <?= json_encode($initialSearch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const formEl = document.getElementById('ksei-form');
        const symbolEl = document.getElementById('symbol-input');
        const searchEl = document.getElementById('search-input');
        const tierEl = document.getElementById('tier-select');
        const messageEl = document.getElementById('ksei-message');
        const statsEl = document.getElementById('ksei-stats');
        const ownershipEl = document.getElementById('ownership-result');
        const searchResultEl = document.getElementById('search-result');

        const formatPct = (value, signed = false) => {
            const num = Number(value || 0);
            const prefix = signed && num > 0 ? '+' : '';
            return `${prefix}${num.toFixed(2)}%`;
        };

        const formatShares = (value) => {
            const num = Math.abs(Number(value || 0));
            if (num >= 1_000_000_000) return `${(num / 1_000_000_000).toFixed(2)}B`;
            if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(2)}M`;
            if (num >= 1_000) return `${(num / 1_000).toFixed(2)}K`;
            return num.toFixed(0);
        };

        const escapeHtml = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        function renderStats(result) {
            const stats = result.stats || {};
            const dates = result.dates || {};
            statsEl.innerHTML = [
                ['Snapshot', dates.latest || '-', `Pembanding: ${dates.previous || '-'}`],
                ['Emiten', stats.symbols || 0, 'Saham dengan data >1%'],
                ['Posisi', stats.positions || 0, 'Pemegang saham tercatat'],
                ['Pemegang', stats.holders || 0, 'Nama unik di database'],
                ['Tanggal', (dates.available || []).length, 'Jumlah snapshot KSEI'],
            ].map(([label, value, desc]) => `
                <article class="card stat">
                    <span class="eyebrow">${escapeHtml(label)}</span>
                    <strong>${escapeHtml(value)}</strong>
                    <div class="muted">${escapeHtml(desc)}</div>
                </article>
            `).join('');
        }

        function renderActivityRow(item, mode = 'acc') {
            const delta = Number(item.delta_pct || 0);
            const tone = delta >= 0 ? 'buy' : 'sell';
            return `
                <div class="ksei-row">
                    <div>
                        <a class="ksei-symbol" href="./ksei-radar.php?symbol=${encodeURIComponent(item.symbol)}">${escapeHtml(item.symbol)}</a>
                        <div class="muted">${escapeHtml(item.company_name || '')}</div>
                    </div>
                    <div>
                        <strong>${escapeHtml(item.owner_name)}</strong>
                        <div class="muted">${escapeHtml(item.owner_type_label || item.owner_type || '-')} ${item.local_foreign ? '• ' + escapeHtml(item.local_foreign) : ''}</div>
                    </div>
                    <div class="${tone}">
                        <strong>${formatPct(delta, true)}</strong>
                        <div class="muted">${formatShares(item.delta_shares)} saham</div>
                    </div>
                    <div>
                        <strong>${formatPct(item.current_pct)}</strong>
                        <div class="muted">dari ${formatPct(item.previous_pct)}</div>
                    </div>
                </div>
            `;
        }

        function renderActivity(targetId, items, emptyText, mode = 'acc') {
            const target = document.getElementById(targetId);
            target.innerHTML = items && items.length
                ? items.map((item) => renderActivityRow(item, mode)).join('')
                : `<div class="notice">${escapeHtml(emptyText)}</div>`;
        }

        function renderRepeated(items) {
            const target = document.getElementById('repeated-accumulation');
            target.innerHTML = items && items.length
                ? items.map((item) => `
                    <div class="ksei-row compact">
                        <div>
                            <a class="ksei-symbol" href="./ksei-radar.php?symbol=${encodeURIComponent(item.symbol)}">${escapeHtml(item.symbol)}</a>
                            <div class="muted">${escapeHtml(item.owner_name)}</div>
                        </div>
                        <div><strong>${item.periods} periode naik</strong><div class="muted">${formatPct(item.start_pct)} → ${formatPct(item.current_pct)}</div></div>
                        <div class="buy"><strong>${formatPct(item.delta_pct, true)}</strong><div class="muted">${formatShares(item.delta_shares)} saham</div></div>
                    </div>
                `).join('')
                : '<div class="notice">Belum cukup banyak snapshot untuk pola berulang yang kuat.</div>';
        }

        function renderOwnership(ownership) {
            if (!ownership || !ownership.symbol) {
                ownershipEl.innerHTML = '';
                return;
            }

            if (!ownership.holders || !ownership.holders.length) {
                ownershipEl.innerHTML = `<article class="card content"><div class="notice">${escapeHtml(ownership.message || 'Data tidak tersedia.')}</div></article>`;
                return;
            }

            ownershipEl.innerHTML = `
                <article class="card content ownership-card">
                    <div class="section-head">
                        <div>
                            <span class="eyebrow">02 — Peta Kepemilikan</span>
                            <h2>${escapeHtml(ownership.symbol)} · ${escapeHtml(ownership.company_name || '')}</h2>
                            <p class="lead">${escapeHtml(ownership.sector || '-')} ${ownership.subsector ? '• ' + escapeHtml(ownership.subsector) : ''}</p>
                        </div>
                        <span class="badge">${escapeHtml(ownership.effective_date || '-')}</span>
                    </div>
                    <div class="ownership-metrics">
                        <div class="metric"><span>Tercatat >1%</span><strong>${formatPct(ownership.recorded_pct)}</strong></div>
                        <div class="metric"><span>Free Float</span><strong>${formatPct(ownership.free_float_pct)}</strong></div>
                        <div class="metric"><span>Likuiditas</span><strong>${escapeHtml(ownership.liquidity_status)}</strong></div>
                        <div class="metric"><span>Pemegang Terbesar</span><strong>${formatPct(ownership.top_holder?.pct || 0)}</strong></div>
                    </div>
                    <div class="ownership-split">
                        <div>
                            <h3>Lokal vs Asing</h3>
                            <div class="split-bar">
                                <span style="width:${Math.min(100, ownership.domestic_pct || 0)}%"></span>
                            </div>
                            <div class="muted">Lokal ${formatPct(ownership.domestic_pct)} • Asing ${formatPct(ownership.foreign_pct)}</div>
                        </div>
                        <div>
                            <h3>Tipe Investor</h3>
                            <div class="chip-row">
                                ${(ownership.type_breakdown || []).map((item) => `<span class="badge">${escapeHtml(item.label)} ${formatPct(item.pct)}</span>`).join('')}
                            </div>
                        </div>
                    </div>
                    <div class="ksei-table wide">
                        ${ownership.holders.map((item, index) => `
                            <div class="ksei-row holder">
                                <div><strong>#${index + 1}</strong></div>
                                <div><strong>${escapeHtml(item.owner_name)}</strong><div class="muted">${escapeHtml(item.owner_type_label)} • ${escapeHtml(item.domicile || item.nationality || '-')}</div></div>
                                <div><strong>${formatShares(item.shares)}</strong><div class="muted">saham</div></div>
                                <div><strong>${formatPct(item.ownership_pct)}</strong><div class="muted">${escapeHtml(item.local_foreign || '-')}</div></div>
                            </div>
                        `).join('')}
                    </div>
                </article>
            `;
        }

        function renderSearch(items) {
            if (!items || !items.length) {
                searchResultEl.innerHTML = '';
                return;
            }

            searchResultEl.innerHTML = `
                <article class="card content">
                    <div class="section-head">
                        <div>
                            <span class="eyebrow">Cari Saham</span>
                            <h2>Hasil Pencarian</h2>
                        </div>
                        <span class="badge">${items.length} hasil</span>
                    </div>
                    <div class="search-result-grid">
                        ${items.map((item) => `
                            <a class="search-result-card" href="./ksei-radar.php?symbol=${encodeURIComponent(item.symbol)}&q=${encodeURIComponent(searchEl.value || '')}">
                                <strong>${escapeHtml(item.symbol)}</strong>
                                <span>${escapeHtml(item.company_name || '')}</span>
                                <small>${formatPct(item.recorded_pct)} tercatat >1% • Free float ${formatPct(item.free_float_pct)}</small>
                            </a>
                        `).join('')}
                    </div>
                </article>
            `;
        }

        function renderFreeFloat(items) {
            document.getElementById('free-float-count').textContent = `${items.length || 0} emiten`;
            document.getElementById('free-float').innerHTML = items && items.length
                ? items.map((item, index) => `
                    <div class="ksei-row free-float-row">
                        <div><strong>#${index + 1}</strong></div>
                        <div>
                            <a class="ksei-symbol" href="./ksei-radar.php?symbol=${encodeURIComponent(item.symbol)}">${escapeHtml(item.symbol)}</a>
                            <div class="muted">${escapeHtml(item.company_name || '')}</div>
                        </div>
                        <div><strong>${formatPct(item.free_float_pct)}</strong><div class="float-meter"><span style="width:${Math.max(2, item.free_float_pct)}%"></span></div></div>
                        <div><strong>${escapeHtml(item.status)}</strong><div class="muted">${formatPct(item.recorded_pct)} tercatat >1%</div></div>
                    </div>
                `).join('')
                : '<div class="notice">Tidak ada emiten pada filter ini.</div>';
        }

        async function loadDashboard() {
            const symbol = (symbolEl.value || '').trim().toUpperCase();
            const q = (searchEl.value || '').trim();
            const tier = tierEl.value || 'all';
            const query = new URLSearchParams({ tier });
            if (symbol) query.set('symbol', symbol);
            if (q) query.set('q', q);

            messageEl.textContent = 'Membaca database KSEI...';
            const response = await fetch(`./api/ksei-radar.php?${query.toString()}`, { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) throw new Error(data.message || 'Gagal memuat KSEI Radar.');

            const result = data.result || {};
            const dates = result.dates || {};
            document.getElementById('accumulation-date').textContent = `${dates.previous || '-'} → ${dates.latest || '-'}`;
            document.getElementById('distribution-date').textContent = `${dates.previous || '-'} → ${dates.latest || '-'}`;

            renderStats(result);
            renderOwnership(result.ownership);
            renderSearch(result.search || []);
            renderActivity('top-accumulation', result.activity?.top_accumulation || [], 'Belum ada akumulasi yang terbaca.', 'acc');
            renderActivity('top-distribution', result.activity?.top_distribution || [], 'Belum ada distribusi yang terbaca.', 'dist');
            renderActivity('new-holders', result.activity?.new_holders || [], 'Tidak ada pemegang baru yang masuk >1%.', 'acc');
            renderRepeated(result.activity?.repeated_accumulation || []);
            renderFreeFloat(result.free_float || []);

            messageEl.textContent = `KSEI Radar siap. Snapshot aktif: ${dates.latest || '-'}, pembanding: ${dates.previous || '-'}.`;
        }

        formEl.addEventListener('submit', (event) => {
            event.preventDefault();
            const url = new URL(window.location.href);
            const symbol = (symbolEl.value || '').trim().toUpperCase();
            const q = (searchEl.value || '').trim();
            if (symbol) url.searchParams.set('symbol', symbol); else url.searchParams.delete('symbol');
            if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
            if (tierEl.value && tierEl.value !== 'all') url.searchParams.set('tier', tierEl.value); else url.searchParams.delete('tier');
            window.history.replaceState({}, '', url);
            loadDashboard().catch((error) => {
                messageEl.textContent = error.message;
            });
        });

        symbolEl.addEventListener('input', () => {
            symbolEl.value = symbolEl.value.toUpperCase();
        });

        loadDashboard().catch((error) => {
            messageEl.textContent = error.message;
        });
    </script>
</body>
</html>
