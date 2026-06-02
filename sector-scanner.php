<?php
declare(strict_types=1);

require_once __DIR__ . '/src/SectorUniverse.php';
require_once __DIR__ . '/src/NextDayFilter.php';

$initialSector = trim((string) ($_GET['sector'] ?? ''));
$initialSubsector = trim((string) ($_GET['subsector'] ?? ''));
$initialProfile = NextDayFilter::normalizeProfile((string) ($_GET['profile'] ?? 'fast_v5'));
$profileLabel = match ($initialProfile) {
    'fast' => 'Fast V1',
    'fast_v2' => 'Fast V2',
    'fast_v3' => 'Fast V3',
    'fast_v4' => 'Fast V4',
    'fast_v5' => 'Fast V5',
    default => 'Swing',
};
$sectors = SectorUniverse::availableSectors();
$subsectors = $initialSector !== '' ? SectorUniverse::availableSubsectors($initialSector) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sector Scanner</title>
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
            <span class="eyebrow">Sector Scanner</span>
            <h1>Sector Scanner</h1>
            <p class="lead">Cari saham di satu sektor yang masih dekat valuasi/persepsi basis, tetapi mulai punya kombinasi katalis, akumulasi, dan momentum untuk 1-6 bulan.</p>
        </section>

        <section class="card panel">
            <div class="actions actions-toolbar">
                <a class="link icon-button" href="./index.php" title="Kembali ke Dashboard Utama" aria-label="Kembali ke Dashboard Utama">⌂<span class="sr-only">Kembali ke Dashboard Utama</span></a>
                <a class="link" href="./cio-swing.php">CIO Swing</a>
                <a class="link" href="./ksei-radar.php">KSEI Radar</a>
                <a class="link" href="./tracker-berulang.php">Analisis Saham</a>
                <a class="link" href="./peluang-besok.php">Day Trade</a>
                <form class="search-form" id="sector-form">
                    <select id="sector-select" name="sector">
                        <option value="">Pilih sektor</option>
                        <?php foreach ($sectors as $sector): ?>
                            <option value="<?= htmlspecialchars($sector, ENT_QUOTES) ?>"<?= $initialSector === $sector ? ' selected' : '' ?>><?= htmlspecialchars($sector, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="subsector-select" name="subsector">
                        <option value="">Semua Subsektor</option>
                        <?php foreach ($subsectors as $subsector): ?>
                            <option value="<?= htmlspecialchars($subsector, ENT_QUOTES) ?>"<?= $initialSubsector === $subsector ? ' selected' : '' ?>><?= htmlspecialchars($subsector, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="profile-select" name="profile">
                        <?php foreach ([
                            'swing' => 'Swing',
                            'fast' => 'Fast V1',
                            'fast_v2' => 'Fast V2',
                            'fast_v3' => 'Fast V3',
                            'fast_v4' => 'Fast V4',
                            'fast_v5' => 'Fast V5',
                        ] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"<?= $initialProfile === $value ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button" type="submit">Scan Sektor</button>
                </form>
            </div>
            <div class="notice" id="sector-message">Pilih sektor lalu jalankan scan. Daftar sektor dibaca dari file Excel di folder <code>assets/Sektor</code> yang sudah diimpor ke referensi emiten.</div>
        </section>

        <section id="sector-result"></section>
    </div>

    <script>
        const initialSector = <?= json_encode($initialSector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialSubsector = <?= json_encode($initialSubsector, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialProfile = <?= json_encode($initialProfile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const initialProfileLabel = <?= json_encode($profileLabel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const formEl = document.getElementById('sector-form');
        const sectorSelectEl = document.getElementById('sector-select');
        const subsectorSelectEl = document.getElementById('subsector-select');
        const profileSelectEl = document.getElementById('profile-select');
        const messageEl = document.getElementById('sector-message');
        const resultEl = document.getElementById('sector-result');

        function escapeHtml(text) {
            return String(text ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        }

        function metric(label, value) {
            return `<div class="metric"><span>${escapeHtml(label)}</span><strong>${escapeHtml(String(value))}</strong></div>`;
        }

        function renderList(items) {
            if (!Array.isArray(items) || !items.length) {
                return '<div class="muted">Belum ada catatan tambahan.</div>';
            }
            return `<ul class="report-list">${items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`;
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

        function renderCandidate(item) {
            const m = item.metrics || {};
            return `
                <article class="card item">
                    <div class="item-head">
                        <div>
                            <h2>${escapeHtml(item.symbol)}</h2>
                            <div class="muted">${escapeHtml(item.company_name || '')}</div>
                        </div>
                        <div class="summary-meta">
                            <div class="score">${escapeHtml(String(item.opportunity_score || 0))}</div>
                            <span class="badge">${escapeHtml(item.pricing_posture || '-')}</span>
                        </div>
                    </div>
                    <div class="metrics">
                        ${metric('Signal Score', item.score || 0)}
                        ${metric('Value', item.value_score || 0)}
                        ${metric('Momentum', item.momentum_score || 0)}
                        ${metric('Catalyst', item.catalyst_score || 0)}
                        ${metric('Buy Share', `${m.buy_market_share || 0}%`)}
                        ${metric('Dominance', `${m.dominance_gap || 0}%`)}
                        ${metric('Turnover', `${m.turnover_acceleration || 0}x`)}
                        ${metric('Clean', `${m.clean_ratio || 0}%`)}
                        ${metric('Acc', `${m.acc_ratio || 0}%`)}
                        ${metric('Breakout', `${m.breakout_pct || 0}%`)}
                        ${metric('Extension', `${m.extension_pct || 0}%`)}
                    </div>
                    <div class="reasons">
                        <strong>Valuation View</strong>
                        <ul><li>${escapeHtml(item.valuation_view || '-')}</li></ul>
                    </div>
                    <div class="reasons">
                        <strong>Tesis</strong>
                        <ul><li>${escapeHtml(item.thesis || '-')}</li></ul>
                    </div>
                    <div class="reasons">
                        <strong>Catatan Eksternal</strong>
                        ${renderList(item.external_summary || [])}
                    </div>
                    <div class="reasons">
                        <strong>Yang Masih Mengganjal</strong>
                        ${renderList(item.failures || [])}
                    </div>
                    <div class="actions">
                        <a class="link" href="./cio-swing.php?symbol=${encodeURIComponent(item.symbol)}&sector=${encodeURIComponent(sectorSelectEl.value)}${subsectorSelectEl.value ? `&subsector=${encodeURIComponent(subsectorSelectEl.value)}` : ''}">Buka CIO Detail</a>
                    </div>
                </article>
            `;
        }

        function renderResult(result) {
            return `
                <section class="report-layout">
                    <article class="card report-hero">
                        <div>
                            <span class="eyebrow">Sector Opportunity Ranking</span>
                            <h2>${escapeHtml(result.sector || '-')}</h2>
                            <p class="lead">Profile aktif: ${escapeHtml(profileSelectEl.options[profileSelectEl.selectedIndex]?.text || initialProfileLabel)}${result.subsector ? ` | Subsektor: ${escapeHtml(result.subsector)}` : ''}</p>
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
                                <span class="eyebrow">Dataset</span>
                                <strong>${escapeHtml(result.effective_profile || result.profile || '-')}</strong>
                            </article>
                        </div>
                    </article>
                    <article class="card report-section">
                        <span class="eyebrow">Catatan Scanner</span>
                        ${renderList(result.notes || [])}
                    </article>
                    <section class="grid radar-grid">
                        ${(result.candidates || []).slice(0, 20).map(renderCandidate).join('')}
                    </section>
                </section>
            `;
        }

        async function syncSubsectors(sector, selected = '') {
            if (!sector) {
                renderSubsectorOptions([], '');
                return;
            }

            try {
                const response = await fetch(`./api/sector-scanner.php?meta=1&sector=${encodeURIComponent(sector)}`, { cache: 'no-store' });
                const data = await response.json();
                renderSubsectorOptions(data.subsectors || [], selected);
            } catch (error) {
                renderSubsectorOptions([], '');
            }
        }

        async function loadSector(sector, profile, subsector = '') {
            if (!sector) {
                messageEl.textContent = 'Pilih sektor dulu.';
                return;
            }

            messageEl.textContent = `Menjalankan Sector Scanner untuk ${sector}${subsector ? ` / ${subsector}` : ''}...`;
            resultEl.innerHTML = '';

            try {
                const query = new URLSearchParams({ sector, profile });
                if (subsector) query.set('subsector', subsector);
                const response = await fetch(`./api/sector-scanner.php?${query.toString()}`, { cache: 'no-store' });
                const data = await response.json();
                if (!data.ok) {
                    throw new Error(data.message || 'Gagal memindai sektor.');
                }

                renderSubsectorOptions(data.subsectors || [], data.result?.subsector || subsector);
                resultEl.innerHTML = renderResult(data.result || {});
                messageEl.textContent = `Sector Scanner ${sector}${subsector ? ` / ${subsector}` : ''} selesai.`;
            } catch (error) {
                messageEl.textContent = error.message || 'Gagal memindai sektor.';
                resultEl.innerHTML = '<article class="card report-section"><div class="muted">Hasil scanner belum tersedia.</div></article>';
            }
        }

        sectorSelectEl.addEventListener('change', () => {
            syncSubsectors(sectorSelectEl.value || '', '');
        });

        formEl.addEventListener('submit', (event) => {
            event.preventDefault();
            const sector = sectorSelectEl.value || '';
            const subsector = subsectorSelectEl.value || '';
            const profile = profileSelectEl.value || 'fast_v5';
            const url = new URL(window.location.href);
            if (sector) url.searchParams.set('sector', sector); else url.searchParams.delete('sector');
            if (subsector) url.searchParams.set('subsector', subsector); else url.searchParams.delete('subsector');
            url.searchParams.set('profile', profile);
            window.history.replaceState({}, '', url);
            loadSector(sector, profile, subsector);
        });

        if (initialSector) {
            syncSubsectors(initialSector, initialSubsector).then(() => {
                loadSector(initialSector, initialProfile, initialSubsector);
            });
        }
    </script>
</body>
</html>
