<?php
declare(strict_types=1);

require_once __DIR__ . '/src/SectorUniverse.php';

$symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
$sector = trim((string) ($_GET['sector'] ?? ''));
$subsector = trim((string) ($_GET['subsector'] ?? ''));
$sectors = SectorUniverse::availableSectors();
$subsectors = $sector !== '' ? SectorUniverse::availableSubsectors($sector) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CIO Swing Analysis</title>
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
            <span class="eyebrow">CIO Swing Analysis</span>
            <h1>CIO Swing Analysis</h1>
            <p class="lead">Satu modul untuk dua kebutuhan: mencari kandidat terbaik dalam satu sektor, lalu membedah satu emiten secara top-down dan bottom-up untuk horizon swing 1-6 bulan.</p>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="link" href="./tracker-berulang.php">Analisis Saham</a>
                <a class="link" href="./peluang-besok.php">Day Trade</a>
                <a class="link" href="./radar-potensial.php">High Convection</a>
                <a class="link" href="./ksei-radar.php">KSEI Radar</a>
                <form class="search-form" id="cio-form">
                    <input type="text" id="cio-symbol" name="symbol" placeholder="Kode emiten, mis. BBCA" value="<?= htmlspecialchars($symbol, ENT_QUOTES) ?>" autocomplete="off">
                    <input type="text" id="cio-sector" name="sector" placeholder="Sektor opsional, mis. Financials" value="<?= htmlspecialchars($sector, ENT_QUOTES) ?>" autocomplete="off">
                    <button class="button" type="submit">Analisa Emiten</button>
                </form>
            </div>
            <div class="notice" id="cio-message">Masukkan kode emiten untuk membuka laporan CIO detail. Sektor boleh dikosongkan jika Anda ingin sistem mengisi dari referensi emiten.</div>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <span class="link">CIO Sector Scanner</span>
                <form class="search-form" id="cio-sector-form">
                    <select id="cio-sector-select" name="sector">
                        <option value="">Pilih sektor</option>
                        <?php foreach ($sectors as $sectorOption): ?>
                            <option value="<?= htmlspecialchars($sectorOption, ENT_QUOTES) ?>"<?= $sector === $sectorOption ? ' selected' : '' ?>><?= htmlspecialchars($sectorOption, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="cio-subsector-select" name="subsector">
                        <option value="">Semua Subsektor</option>
                        <?php foreach ($subsectors as $subsectorOption): ?>
                            <option value="<?= htmlspecialchars($subsectorOption, ENT_QUOTES) ?>"<?= $subsector === $subsectorOption ? ' selected' : '' ?>><?= htmlspecialchars($subsectorOption, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit">Scan Sektor CIO</button>
                </form>
            </div>
            <div class="notice" id="cio-sector-message">Pilih sektor atau subsektor untuk mencari shortlist CIO: saham yang terlihat masih menarik secara positioning, namun mulai punya katalis, momentum, dan peluang re-rating 1-6 bulan.</div>
        </section>

        <section id="cio-sector-result"></section>
        <section id="cio-result"></section>
    </div>

    <script>
        const initialSymbol = <?= json_encode($symbol, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialSector = <?= json_encode($sector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialSubsector = <?= json_encode($subsector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const formEl = document.getElementById('cio-form');
        const symbolEl = document.getElementById('cio-symbol');
        const sectorEl = document.getElementById('cio-sector');
        const messageEl = document.getElementById('cio-message');
        const resultEl = document.getElementById('cio-result');

        const sectorFormEl = document.getElementById('cio-sector-form');
        const sectorSelectEl = document.getElementById('cio-sector-select');
        const subsectorSelectEl = document.getElementById('cio-subsector-select');
        const sectorMessageEl = document.getElementById('cio-sector-message');
        const sectorResultEl = document.getElementById('cio-sector-result');

        function escapeHtml(text) {
            return String(text ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        }

        function renderList(items) {
            if (!Array.isArray(items) || !items.length) {
                return '<div class="muted">Belum ada poin yang cukup untuk ditampilkan.</div>';
            }
            return `<ul class="report-list">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`;
        }

        function renderNews(items) {
            if (!Array.isArray(items) || !items.length) {
                return '<div class="muted">Belum ada konteks berita yang cukup relevan.</div>';
            }

            return `
                <ul class="report-list">
                    ${items.slice(0, 5).map((item) => `
                        <li><a href="${escapeHtml(item.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.title)}</a></li>
                    `).join('')}
                </ul>
            `;
        }

        function renderPeers(items) {
            if (!Array.isArray(items) || !items.length) {
                return '<div class="muted">Peer sektoral belum cukup tersedia di database lokal.</div>';
            }

            return `
                <div class="report-grid report-grid-compact">
                    ${items.map((row) => `
                        <article class="card report-card">
                            <strong>${escapeHtml(row.symbol || '-')}</strong>
                            <div class="muted">${escapeHtml(row.company_name || '')}</div>
                            <div class="muted">${escapeHtml(row.subsector || row.sector || '')}</div>
                        </article>
                    `).join('')}
                </div>
            `;
        }

        function renderActionPlan(plan) {
            return `
                <div class="report-kpis">
                    <article class="card report-kpi">
                        <span class="eyebrow">Entry Zone</span>
                        <strong>${escapeHtml(plan.entry_zone || '-')}</strong>
                    </article>
                    <article class="card report-kpi">
                        <span class="eyebrow">Take Profit</span>
                        <strong>${escapeHtml(plan.take_profit || '-')}</strong>
                    </article>
                    <article class="card report-kpi">
                        <span class="eyebrow">Invalidation</span>
                        <strong>${escapeHtml(plan.cut_loss || '-')}</strong>
                    </article>
                </div>
            `;
        }

        function metric(label, value) {
            return `<div class="metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>`;
        }

        function renderSubsectorOptions(items, selected = '') {
            const options = ['<option value="">Semua Subsektor</option>'];
            for (const item of (items || [])) {
                const safe = escapeHtml(item);
                const selectedAttr = item === selected ? ' selected' : '';
                options.push(`<option value="${safe}"${selectedAttr}>${safe}</option>`);
            }
            subsectorSelectEl.innerHTML = options.join('');
        }

        function renderSectorCandidate(item) {
            const m = item.metrics || {};
            return `
                <article class="card item">
                    <div class="item-head">
                        <div>
                            <h2>${escapeHtml(item.symbol)}</h2>
                            <div class="muted">${escapeHtml(item.company_name || '')}</div>
                            <div class="muted">${escapeHtml(item.subsector || item.sector || '')}</div>
                        </div>
                        <div class="summary-meta">
                            <div class="score">${escapeHtml(String(item.conviction_score || 0))}</div>
                            <span class="badge">${escapeHtml(item.pricing_posture || '-')}</span>
                        </div>
                    </div>
                    <div class="metrics">
                        ${metric('Conviction', item.conviction_score || 0)}
                        ${metric('Value', item.value_score || 0)}
                        ${metric('Momentum', item.momentum_score || 0)}
                        ${metric('Catalyst', item.catalyst_score || 0)}
                        ${metric('Signal', m.score || 0)}
                        ${metric('Buy Share', `${m.buy_market_share || 0}%`)}
                        ${metric('Dominance', `${m.dominance_gap || 0}%`)}
                        ${metric('Turnover', `${m.turnover_acceleration || 0}x`)}
                    </div>
                    <div class="reasons">
                        <strong>Valuation View</strong>
                        <ul><li>${escapeHtml(item.valuation_view || '-')}</li></ul>
                    </div>
                    <div class="reasons">
                        <strong>Tesis CIO</strong>
                        <ul><li>${escapeHtml(item.thesis || '-')}</li></ul>
                    </div>
                    <div class="reasons">
                        <strong>Katalis / Konteks</strong>
                        ${renderList(item.external_summary || [])}
                    </div>
                    <div class="reasons">
                        <strong>Risiko Utama</strong>
                        ${renderList(item.risks || [])}
                    </div>
                    <div class="actions">
                        <a class="link" href="./cio-swing.php?symbol=${encodeURIComponent(item.symbol)}&sector=${encodeURIComponent(sectorSelectEl.value)}${subsectorSelectEl.value ? `&subsector=${encodeURIComponent(subsectorSelectEl.value)}` : ''}">Buka CIO Detail</a>
                    </div>
                </article>
            `;
        }

        function renderSectorScan(result) {
            return `
                <section class="report-layout">
                    <article class="card report-hero">
                        <div>
                            <span class="eyebrow">CIO Sector Scanner</span>
                            <h2>${escapeHtml(result.sector || '-')}</h2>
                            <p class="lead">${result.subsector ? `Subsektor: ${escapeHtml(result.subsector)} • ` : ''}${escapeHtml(result.snapshot_label || 'Internal Market Snapshot')}</p>
                        </div>
                        <div class="report-kpis">
                            <article class="card report-kpi">
                                <span class="eyebrow">Universe</span>
                                <strong>${escapeHtml(String(result.universe_count || 0))}</strong>
                            </article>
                            <article class="card report-kpi">
                                <span class="eyebrow">Diranking</span>
                                <strong>${escapeHtml(String(result.ranked_count || 0))}</strong>
                            </article>
                            <article class="card report-kpi">
                                <span class="eyebrow">Generated</span>
                                <strong>${escapeHtml(new Date(result.generated_at || Date.now()).toLocaleString('id-ID'))}</strong>
                            </article>
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">Catatan Scanner</span>
                        ${renderList(result.notes || [])}
                    </article>

                    <section class="grid radar-grid">
                        ${(result.candidates || []).map(renderSectorCandidate).join('')}
                    </section>
                </section>
            `;
        }

        function renderReport(data) {
            const analysis = data.analysis || {};
            const company = analysis.company || {};
            const macro = analysis.macro || {};
            const sector = analysis.sector_analysis || {};
            const micro = analysis.micro || {};
            const catalyst = analysis.catalyst || {};
            const strategy = analysis.strategy || {};
            const system = analysis.system || {};
            const systemAnalysis = system.analysis || {};

            return `
                <section class="report-layout">
                    <article class="card report-hero">
                        <div>
                            <span class="eyebrow">Chief Investment Officer Report</span>
                            <h2>${escapeHtml(company.symbol || data.symbol || '-')} ${company.company_name ? '• ' + escapeHtml(company.company_name) : ''}</h2>
                            <p class="lead">${escapeHtml(company.sector || data.sector || '-')} ${company.subsector ? '• ' + escapeHtml(company.subsector) : ''}</p>
                        </div>
                        <div class="report-kpis">
                            <article class="card report-kpi">
                                <span class="eyebrow">Swing Conviction</span>
                                <strong>${escapeHtml(String(strategy.conviction_score || '-'))}</strong>
                                <div class="muted">${escapeHtml(strategy.conviction_label || '')}</div>
                            </article>
                            <article class="card report-kpi">
                                <span class="eyebrow">Setup Internal</span>
                                <strong>${escapeHtml(systemAnalysis.setup || '-')}</strong>
                                <div class="muted">${escapeHtml(systemAnalysis.decision || '')}</div>
                            </article>
                            <article class="card report-kpi">
                                <span class="eyebrow">Generated</span>
                                <strong>${escapeHtml(new Date(analysis.generated_at || Date.now()).toLocaleString('id-ID'))}</strong>
                            </article>
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">Executive View</span>
                        ${renderList(strategy.summary || [])}
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">1. Analisis Makroekonomi</span>
                        <div class="report-prose">
                            <h3>Global</h3>
                            <p>${escapeHtml(macro.global?.summary || '-')}</p>
                            ${renderNews(macro.global?.news || [])}
                            <h3>Domestik</h3>
                            <p>${escapeHtml(macro.domestic?.summary || '-')}</p>
                            ${renderNews(macro.domestic?.news || [])}
                            <h3>Komoditas</h3>
                            <p>${escapeHtml(macro.commodity?.summary || '-')}</p>
                            ${renderNews(macro.commodity?.news || [])}
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">2. Analisis Sektoral & Tren Masa Depan</span>
                        <div class="report-prose">
                            <p><strong>Posisi siklus sektor:</strong> ${escapeHtml(sector.cycle || '-')}</p>
                            <p><strong>Rotasi sektor:</strong> ${escapeHtml(sector.rotation || '-')}</p>
                            <p><strong>Narasi ke depan:</strong> ${escapeHtml(sector.narrative || '-')}</p>
                            <h3>Competitive Advantage / Moat</h3>
                            ${renderList(sector.moat || [])}
                            <h3>Peer Terdekat</h3>
                            ${renderPeers(sector.peers || [])}
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">3. Analisis Mikroekonomi</span>
                        <div class="report-prose">
                            <h3>Kualitas Laba & Pendapatan</h3>
                            ${renderList(micro.earnings_quality || [])}
                            <h3>Kesehatan Neraca & Arus Kas</h3>
                            ${renderList(micro.balance_sheet || [])}
                            <h3>Valuasi Terkalibrasi</h3>
                            ${renderList(micro.valuation || [])}
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">4. Katalis & Jejak Smart Money</span>
                        <div class="report-prose">
                            <h3>Katalis Jangka Pendek-Menengah</h3>
                            ${renderList(catalyst.short_medium_term || [])}
                            <h3>Jejak Akumulasi</h3>
                            ${renderList(catalyst.smart_money || [])}
                            <h3>Berita Pendukung</h3>
                            ${renderNews(catalyst.news || [])}
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">5. Kesimpulan & Strategi Swing Trading</span>
                        <div class="report-prose">
                            <h3>Skenario Bullish</h3>
                            ${renderList(strategy.bullish || [])}
                            <h3>Skenario Bearish</h3>
                            ${renderList(strategy.bearish || [])}
                            <h3>Actionable Plan</h3>
                            ${renderActionPlan(strategy.action_plan || {})}
                        </div>
                    </article>

                    <article class="card report-section">
                        <span class="eyebrow">Catatan Data</span>
                        ${renderList(analysis.data_notes || [])}
                    </article>
                </section>
            `;
        }

        async function loadReport(symbol, sector) {
            if (!symbol) {
                messageEl.textContent = 'Symbol wajib diisi.';
                return;
            }

            messageEl.textContent = `Menyusun CIO Swing Analysis untuk ${symbol}...`;
            resultEl.innerHTML = '';

            try {
                const query = new URLSearchParams({ symbol, sector });
                const response = await fetch(`./api/cio-analysis.php?${query.toString()}`, { cache: 'no-store' });
                const data = await response.json();

                if (!data.ok) {
                    throw new Error(data.message || 'Gagal memuat laporan.');
                }

                resultEl.innerHTML = renderReport(data);
                messageEl.textContent = `Laporan CIO untuk ${symbol} selesai dibuat.`;
            } catch (error) {
                messageEl.textContent = error.message || 'Gagal memuat laporan.';
                resultEl.innerHTML = '<article class="card report-section"><div class="muted">Laporan belum tersedia.</div></article>';
            }
        }

        async function syncCioSubsectors(sector, selected = '') {
            if (!sector) {
                renderSubsectorOptions([], '');
                return;
            }

            try {
                const response = await fetch(`./api/cio-sector-scan.php?meta=1&sector=${encodeURIComponent(sector)}`, { cache: 'no-store' });
                const data = await response.json();
                renderSubsectorOptions(data.subsectors || [], selected);
            } catch (error) {
                renderSubsectorOptions([], '');
            }
        }

        async function loadSectorScan(sector, subsector = '') {
            if (!sector) {
                sectorMessageEl.textContent = 'Pilih sektor dulu.';
                return;
            }

            sectorMessageEl.textContent = `Menjalankan CIO Sector Scanner untuk ${sector}${subsector ? ` / ${subsector}` : ''}...`;
            sectorResultEl.innerHTML = '';

            try {
                const query = new URLSearchParams({ sector });
                if (subsector) query.set('subsector', subsector);
                const response = await fetch(`./api/cio-sector-scan.php?${query.toString()}`, { cache: 'no-store' });
                const data = await response.json();
                if (!data.ok) {
                    throw new Error(data.message || 'Gagal memuat scanner sektor CIO.');
                }

                renderSubsectorOptions(data.subsectors || [], data.result?.subsector || subsector);
                sectorResultEl.innerHTML = renderSectorScan(data.result || {});
                sectorMessageEl.textContent = `CIO Sector Scanner ${sector}${subsector ? ` / ${subsector}` : ''} selesai.`;
            } catch (error) {
                sectorMessageEl.textContent = error.message || 'Gagal memuat scanner sektor CIO.';
                sectorResultEl.innerHTML = '<article class="card report-section"><div class="muted">Hasil scanner CIO belum tersedia.</div></article>';
            }
        }

        sectorSelectEl.addEventListener('change', () => {
            syncCioSubsectors(sectorSelectEl.value || '', '');
        });

        sectorFormEl.addEventListener('submit', (event) => {
            event.preventDefault();
            const selectedSector = sectorSelectEl.value || '';
            const selectedSubsector = subsectorSelectEl.value || '';
            const url = new URL(window.location.href);
            if (selectedSector) url.searchParams.set('sector', selectedSector); else url.searchParams.delete('sector');
            if (selectedSubsector) url.searchParams.set('subsector', selectedSubsector); else url.searchParams.delete('subsector');
            window.history.replaceState({}, '', url);
            loadSectorScan(selectedSector, selectedSubsector);
        });

        formEl.addEventListener('submit', (event) => {
            event.preventDefault();
            const symbol = (symbolEl.value || '').trim().toUpperCase();
            const selectedSector = (sectorEl.value || '').trim();
            const url = new URL(window.location.href);
            url.searchParams.set('symbol', symbol);
            if (selectedSector) {
                url.searchParams.set('sector', selectedSector);
            } else {
                url.searchParams.delete('sector');
            }
            window.history.replaceState({}, '', url);
            loadReport(symbol, selectedSector);
        });

        if (initialSymbol) {
            loadReport(initialSymbol, initialSector);
        }

        if (initialSector && !initialSymbol) {
            syncCioSubsectors(initialSector, initialSubsector).then(() => {
                loadSectorScan(initialSector, initialSubsector);
            });
        }
    </script>
</body>
</html>
