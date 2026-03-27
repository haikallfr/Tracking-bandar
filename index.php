<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broker Summary Otomatis</title>
    <link rel="stylesheet" href="./assets/app.css">
</head>
<body>
    <div class="wrap">
        <section class="hero">
            <h1 class="title">Broker Summary</h1>
            <div class="hero-actions">
                <a class="nav-link" href="./tracker-berulang.php">Analisis Saham</a>
                <a class="nav-link" href="./radar-dasar.php">Analisis Saham Sederhana</a>
                <a class="nav-link" href="./radar-potensial.php">High Convection</a>
                <a class="nav-link" href="./peluang-besok.php">Peluang Besok</a>
            </div>
        </section>

        <section class="topbar" id="stats"></section>

        <section class="grid">
            <main class="card content">
                <div class="card toolbar-card">
                    <span class="eyebrow">Import Token</span>
                    <div class="actions">
                        <a id="bookmarklet-link" class="bookmarklet" href="#">Impor Token Stockbit</a>
                        <button type="button" class="secondary" id="refresh-btn">Refresh Sekarang</button>
                    </div>
                    <div class="notice mini" id="import-status" style="margin-top:12px;">
                        Token belum diimpor. Setelah bookmarklet dijalankan, halaman ini akan menyimpan token otomatis lalu siap dipakai refresh.
                    </div>
                    <div class="notice" id="message" style="margin-top:12px;">
                        Login ke Stockbit lalu klik bookmarklet sekali. Setelah itu Anda cukup cari simbol dan ubah filter di panel utama.
                    </div>
                </div>

                <span class="eyebrow">Data Cache</span>
                <div class="searchbar">
                    <input id="symbol-search" type="text" placeholder="Cari simbol, misalnya BBCA">
                    <button type="button" id="search-btn">Cari Simbol</button>
                </div>
                <div class="toolbar">
                    <div>
                        <span class="toolbar-label">Shortcut</span>
                        <div class="shortcut-row" id="global-shortcuts"></div>
                    </div>
                    <div>
                        <span class="toolbar-label">Geser Tanggal</span>
                        <div class="date-nav">
                            <button type="button" class="secondary icon-button" id="date-prev-btn" title="Tanggal sebelumnya" aria-label="Tanggal sebelumnya">←</button>
                            <button type="button" class="secondary icon-button" id="date-next-btn" title="Tanggal selanjutnya" aria-label="Tanggal selanjutnya">→</button>
                        </div>
                    </div>
                    <div>
                        <span class="toolbar-label">Tanggal Mulai</span>
                        <input id="date-from" name="date_from" type="date">
                    </div>
                    <div>
                        <span class="toolbar-label">Tanggal Akhir</span>
                        <input id="date-to" name="date_to" type="date">
                    </div>
                    <div>
                        <span class="toolbar-label">Investor</span>
                        <select id="investor-type" name="investor_type">
                            <option value="INVESTOR_TYPE_ALL">All Investor</option>
                            <option value="INVESTOR_TYPE_DOMESTIC">Domestic</option>
                            <option value="INVESTOR_TYPE_FOREIGN">Foreign</option>
                        </select>
                    </div>
                    <div>
                        <span class="toolbar-label">Board</span>
                        <select id="market-board" name="market_board">
                            <option value="MARKET_BOARD_ALL">All Market</option>
                            <option value="MARKET_BOARD_REGULER">Regular</option>
                            <option value="MARKET_BOARD_TUNAI">Tunai</option>
                            <option value="MARKET_BOARD_NEGO">Nego</option>
                        </select>
                    </div>
                    <div>
                        <span class="toolbar-label">Type</span>
                        <select id="transaction-type" name="transaction_type">
                            <option value="TRANSACTION_TYPE_NET">Net</option>
                            <option value="TRANSACTION_TYPE_GROSS">Gross</option>
                        </select>
                    </div>
                </div>
                <div class="list" id="items"></div>
            </main>
        </section>
    </div>

    <script>
        const state = {
            autoRefreshHandle: null,
            autoRefreshMinutes: 15,
            currentSymbol: '',
            currentItem: null,
            period: 'BROKER_SUMMARY_PERIOD_LATEST',
            currentBrokerCode: '',
            currentBrokerHistory: null,
            brokerHistoryPollHandle: null,
        };

        const statsEl = document.getElementById('stats');
        const itemsEl = document.getElementById('items');
        const messageEl = document.getElementById('message');
        const importStatusEl = document.getElementById('import-status');
        const refreshBtn = document.getElementById('refresh-btn');
        const bookmarkletLinkEl = document.getElementById('bookmarklet-link');
        const symbolSearchEl = document.getElementById('symbol-search');
        const searchBtn = document.getElementById('search-btn');
        const datePrevBtn = document.getElementById('date-prev-btn');
        const dateNextBtn = document.getElementById('date-next-btn');
        const dateFromEl = document.getElementById('date-from');
        const dateToEl = document.getElementById('date-to');
        const investorTypeEl = document.getElementById('investor-type');
        const marketBoardEl = document.getElementById('market-board');
        const transactionTypeEl = document.getElementById('transaction-type');
        const globalShortcutsEl = document.getElementById('global-shortcuts');

        const PERIOD_SHORTCUTS = {
            '1D': 'BROKER_SUMMARY_PERIOD_LATEST',
            '7D': 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS',
            '30D': 'BROKER_SUMMARY_PERIOD_LAST_1_MONTH',
        };

        function escapeHtml(text) {
            return String(text)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;');
        }

        function setMessage(text, isError = false) {
            messageEl.textContent = text;
            messageEl.className = 'notice ' + (isError ? 'status-bad' : 'status-good');
        }

        function statCard(label, value, hint) {
            return `
                <div class="card stat">
                    <span class="eyebrow">${label}</span>
                    <strong>${value}</strong>
                    <div class="muted">${hint}</div>
                </div>
            `;
        }

        function formatCompactNumber(value) {
            const num = Math.abs(Number(value || 0));
            if (!num) return '0';
            if (num >= 1e12) return `${(num / 1e12).toFixed(1).replace(/\.0$/, '')}T`;
            if (num >= 1e9) return `${(num / 1e9).toFixed(1).replace(/\.0$/, '')}B`;
            if (num >= 1e6) return `${(num / 1e6).toFixed(1).replace(/\.0$/, '')}M`;
            if (num >= 1e3) return `${(num / 1e3).toFixed(1).replace(/\.0$/, '')}K`;
            return `${Math.round(num)}`;
        }

        function formatPrice(value) {
            const num = Number(value || 0);
            if (!num) return '0';
            return Math.round(num).toLocaleString('id-ID');
        }

        function formatDateId(dateValue) {
            const raw = String(dateValue || '');
            if (!/^\d{8}$/.test(raw)) return '-';
            const year = Number(raw.slice(0, 4));
            const month = Number(raw.slice(4, 6)) - 1;
            const day = Number(raw.slice(6, 8));
            return new Date(year, month, day).toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            });
        }

        function brokerTypeClass(type) {
            const value = String(type || '').toLowerCase();
            if (value.includes('asing')) return 'type-color-asing';
            if (value.includes('lokal')) return 'type-color-lokal';
            if (value.includes('pemerintah')) return 'type-color-pemerintah';
            return '';
        }

        function currentFilters() {
            return {
                period: state.period || 'BROKER_SUMMARY_PERIOD_LATEST',
                from: dateFromEl.value,
                to: dateToEl.value,
                investor_type: investorTypeEl.value,
                market_board: marketBoardEl.value,
                transaction_type: transactionTypeEl.value,
            };
        }

        function buildQueryString(filters) {
            const params = new URLSearchParams();
            Object.entries(filters).forEach(([key, value]) => {
                if (value) params.set(key, value);
            });
            return params.toString();
        }

        function labelFromInvestor(value) {
            return {
                'INVESTOR_TYPE_ALL': 'All Investor',
                'INVESTOR_TYPE_DOMESTIC': 'Domestic',
                'INVESTOR_TYPE_FOREIGN': 'Foreign',
            }[value] || 'All Investor';
        }

        function labelFromBoard(value) {
            return {
                'MARKET_BOARD_ALL': 'All Market',
                'MARKET_BOARD_REGULER': 'Regular',
                'MARKET_BOARD_TUNAI': 'Tunai',
                'MARKET_BOARD_NEGO': 'Nego',
            }[value] || 'Regular';
        }

        function labelFromTransaction(value) {
            return {
                'TRANSACTION_TYPE_NET': 'Net',
                'TRANSACTION_TYPE_GROSS': 'Gross',
            }[value] || 'Net';
        }

        function formatInputDate(value) {
            if (!value) return '';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return value;
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
            });
        }

        function deriveDisplayedDates(filters, fallbackDateLabel) {
            if (filters.from && filters.to) {
                return {
                    left: formatInputDate(filters.from),
                    right: formatInputDate(filters.to),
                };
            }

            const now = new Date();
            const end = new Date(now);
            const start = new Date(now);

            if (filters.period === 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS') {
                start.setDate(start.getDate() - 6);
                return { left: formatInputDate(start), right: formatInputDate(end) };
            }

            if (filters.period === 'BROKER_SUMMARY_PERIOD_LAST_1_MONTH') {
                start.setDate(start.getDate() - 29);
                return { left: formatInputDate(start), right: formatInputDate(end) };
            }

            if (filters.period === 'BROKER_SUMMARY_PERIOD_YESTERDAY') {
                start.setDate(start.getDate() - 1);
                return { left: formatInputDate(start), right: formatInputDate(start) };
            }

            return { left: fallbackDateLabel, right: fallbackDateLabel };
        }

        function renderBrokerLookup(item) {
            const brokerCode = String(state.currentBrokerCode || '').trim().toUpperCase();
            const history = state.currentBrokerHistory
                && state.currentBrokerHistory.symbol === item.symbol
                && state.currentBrokerHistory.broker_code === brokerCode
                ? state.currentBrokerHistory
                : null;
            const result = history?.result?.payload || null;
            const task = history?.task || null;

            let body = '<div class="muted">Masukkan kode sekuritas, misalnya GR atau YP, lalu sistem akan scan histori broker itu di background dan hitung akumulasi kumulatifnya.</div>';

            if (brokerCode && task?.status === 'running') {
                body = `
                    <div class="notice">
                        Scan histori ${escapeHtml(brokerCode)} sedang berjalan.
                        Progress ${escapeHtml(String(task.scanned || 0))}/${escapeHtml(String(task.total || 0))}
                        ${task.current_range ? `• ${escapeHtml(task.current_range)}` : ''}
                    </div>
                `;
            } else if (brokerCode && result) {
                const netClass = Number(result.net_value || 0) >= 0 ? 'buy' : 'sell';
                const avgLabel = netClass === 'buy' ? 'Buy Avg' : 'Sell Avg';
                body = `
                    <div class="broker-focus-card ${netClass}">
                        <div class="broker-focus-code">${escapeHtml(result.broker_code || brokerCode)}</div>
                        <div class="broker-focus-metric">${formatCompactNumber(result.net_value || 0)}</div>
                        <div class="broker-focus-metric">${formatCompactNumber(result.net_lot || 0)}</div>
                        <div class="broker-focus-metric">${formatPrice(result.display_avg || 0)}</div>
                    </div>
                    <div class="broker-focus-meta">
                        <span class="badge ${netClass === 'buy' ? 'buy-badge' : 'sell-badge'}">${netClass === 'buy' ? 'Net Buy' : 'Net Sell'}</span>
                        <span>${escapeHtml(result.broker_type || '-')}</span>
                        <span>${avgLabel} ${formatPrice(result.display_avg || 0)}</span>
                        <span>Buy ${formatCompactNumber(result.buy_lot || 0)} lot</span>
                        <span>Sell ${formatCompactNumber(result.sell_lot || 0)} lot</span>
                        <span>${escapeHtml(result.range?.from || '-')} s/d ${escapeHtml(result.range?.to || '-')}</span>
                        <span>${escapeHtml(String(result.windows_with_hits || 0))} window ada aktivitas</span>
                    </div>
                `;
            } else if (brokerCode && task?.status === 'idle' && task?.finished_at) {
                body = `<div class="muted">Scan histori selesai, tetapi tidak ada hasil akumulasi yang ditemukan untuk ${escapeHtml(brokerCode)}.</div>`;
            }

            return `
                <div class="broker-lookup">
                    <div class="broker-lookup-head">
                        <div>
                            <h4 class="broker-lookup-title">Cek Sekuritas</h4>
                            <div class="summary-sub">Scan histori broker dari tahun ke tahun lalu hitung net kumulatifnya.</div>
                        </div>
                        <div class="broker-lookup-form">
                            <input id="broker-code-input" type="text" maxlength="4" placeholder="Kode, mis. GR" value="${escapeHtml(brokerCode)}">
                            <button type="button" class="secondary" id="broker-code-btn">Hitung</button>
                        </div>
                    </div>
                    <div id="broker-lookup-result">${body}</div>
                </div>
            `;
        }

        function parseInputDate(value) {
            if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
            const [year, month, day] = value.split('-').map(Number);
            return new Date(year, month - 1, day);
        }

        function formatInputValue(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        function addDays(date, days) {
            const next = new Date(date);
            next.setDate(next.getDate() + days);
            return next;
        }

        function inferCurrentDateRange() {
            const fromDate = parseInputDate(dateFromEl.value);
            const toDate = parseInputDate(dateToEl.value);

            if (fromDate && toDate) {
                return { from: fromDate, to: toDate };
            }

            if (fromDate && !toDate) {
                return { from: fromDate, to: fromDate };
            }

            if (!fromDate && toDate) {
                return { from: toDate, to: toDate };
            }

            const now = new Date();
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

            if (state.period === 'BROKER_SUMMARY_PERIOD_LAST_7_DAYS') {
                return { from: addDays(today, -6), to: today };
            }

            if (state.period === 'BROKER_SUMMARY_PERIOD_LAST_1_MONTH') {
                return { from: addDays(today, -29), to: today };
            }

            if (state.period === 'BROKER_SUMMARY_PERIOD_YESTERDAY') {
                const yesterday = addDays(today, -1);
                return { from: yesterday, to: yesterday };
            }

            return { from: today, to: today };
        }

        function shiftDateRange(days) {
            const range = inferCurrentDateRange();
            dateFromEl.value = formatInputValue(addDays(range.from, days));
            dateToEl.value = formatInputValue(addDays(range.to, days));
            state.period = '';
            globalShortcutsEl.innerHTML = renderShortcuts(currentFilters());
            saveSettings().then(() => {
                return rerunCurrentSymbol();
            }).catch(error => setMessage(error.message, true));
        }

        function renderShortcuts(filters) {
            return Object.entries(PERIOD_SHORTCUTS).map(([label, value]) => `
                <button type="button" class="shortcut-btn ${filters.period === value && !filters.from && !filters.to ? 'active' : ''}" data-period="${value}">${label}</button>
            `).join('');
        }

        function renderBrokerRows(rows, side) {
            return rows.map((row) => {
                const code = escapeHtml(row.netbs_broker_code || '-');
                const typeClass = brokerTypeClass(row.type);
                const value = side === 'buy' ? formatCompactNumber(row.bval) : formatCompactNumber(row.sval);
                const lot = side === 'buy' ? formatCompactNumber(row.blot) : formatCompactNumber(row.slot);
                const avg = side === 'buy' ? formatPrice(row.netbs_buy_avg_price) : formatPrice(row.netbs_sell_avg_price);
                const color = side === 'buy' ? 'buy-color' : 'sell-color';

                return `
                    <div class="table-row ${color}">
                        <div class="broker-code ${typeClass}">${code}</div>
                        <div>${value}</div>
                        <div>${lot}</div>
                        <div>${avg}</div>
                    </div>
                `;
            }).join('');
        }

        function renderBrokerSummary(item) {
            const summary = item.payload?.market_detector?.data?.broker_summary || {};
            const buys = Array.isArray(summary.brokers_buy) ? summary.brokers_buy.slice(0, 10) : [];
            const sells = Array.isArray(summary.brokers_sell) ? summary.brokers_sell.slice(0, 10) : [];

            return `
                <article class="symbol-card">
                    <div class="summary-card">
                        <div class="summary-top">
                            <div>
                                <h3 class="summary-title">Broker Summary</h3>
                                <div class="summary-sub">${item.symbol} • cache ${new Date(item.updated_at).toLocaleString('id-ID')}</div>
                            </div>
                            <span class="badge">live-style</span>
                        </div>

                        <div class="summary-grid">
                            <section class="side-table">
                                <div class="table-head">
                                    <div>BY</div>
                                    <div>B.val</div>
                                    <div>B.lot</div>
                                    <div>B.avg</div>
                                </div>
                                <div class="table-wrap">${renderBrokerRows(buys, 'buy')}</div>
                            </section>

                            <section class="side-table">
                                <div class="table-head">
                                    <div>SL</div>
                                    <div>S.val</div>
                                    <div>S.lot</div>
                                    <div>S.avg</div>
                                </div>
                                <div class="table-wrap">${renderBrokerRows(sells, 'sell')}</div>
                            </section>
                        </div>

                        ${renderBrokerLookup(item)}
                    </div>
                </article>
            `;
        }

        function renderDashboard(data) {
            statsEl.innerHTML = [
                statCard('Token', data.token_configured ? 'Siap' : 'Belum ada', data.token_configured ? 'Impor token sudah tersimpan lokal.' : 'Jalankan bookmarklet dulu.'),
                statCard('Watchlist', data.watchlist.length, data.watchlist.join(', ') || 'Kosong, pakai pencarian simbol'),
                statCard('Auto Refresh', `${data.auto_refresh_minutes} menit`, 'Untuk cron dan refresh berkala di browser.'),
                statCard('Update Terakhir', data.last_updated ? new Date(data.last_updated).toLocaleString('id-ID') : '-', 'Berdasarkan cache lokal.'),
            ].join('');

            state.period = data.period || 'BROKER_SUMMARY_PERIOD_LATEST';
            dateFromEl.value = data.date_from || '';
            dateToEl.value = data.date_to || '';
            investorTypeEl.value = data.investor_type || 'INVESTOR_TYPE_ALL';
            marketBoardEl.value = data.market_board || 'MARKET_BOARD_REGULER';
            transactionTypeEl.value = data.transaction_type || 'TRANSACTION_TYPE_NET';
            globalShortcutsEl.innerHTML = renderShortcuts(currentFilters());
            state.autoRefreshMinutes = data.auto_refresh_minutes;
            resetAutoRefresh();
            importStatusEl.textContent = data.token_configured
                ? `Token terakhir diimpor: ${data.token_imported_at ? new Date(data.token_imported_at).toLocaleString('id-ID') : 'tersimpan'}`
                : 'Token belum diimpor. Login ke Stockbit lalu klik bookmarklet impor token.';

            if (!state.currentSymbol) {
                itemsEl.innerHTML = '<div class="symbol-card muted">Cari simbol saham seperti BBCA untuk menampilkan broker summary. Cache lama tidak akan ditampilkan otomatis.</div>';
                return;
            }

            const matchedItem = data.items.find((item) => item.symbol === state.currentSymbol);
            if (!matchedItem) {
                itemsEl.innerHTML = '<div class="symbol-card muted">Simbol aktif belum punya hasil. Cari simbol lagi untuk memuat data terbaru.</div>';
                return;
            }

            matchedItem.payload.applied_filters = currentFilters();
            state.currentItem = matchedItem;
            itemsEl.innerHTML = renderBrokerSummary(matchedItem);
        }

        async function loadDashboard() {
            const response = await fetch('./api/dashboard.php', { cache: 'no-store' });
            const data = await response.json();
            renderDashboard(data);
        }

        async function saveSettings() {
            const payload = {
                watchlist: '',
                auto_refresh_minutes: state.autoRefreshMinutes,
                period: state.period || 'BROKER_SUMMARY_PERIOD_LATEST',
                date_from: dateFromEl.value,
                date_to: dateToEl.value,
                transaction_type: transactionTypeEl.value,
                market_board: marketBoardEl.value,
                investor_type: investorTypeEl.value,
            };

            const response = await fetch('./api/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            setMessage(data.message || 'Pengaturan tersimpan.', !data.ok);

            if (data.ok) {
                await loadDashboard();
            }
        }

        function renderSingleItem(item) {
            state.currentItem = item;
            itemsEl.innerHTML = renderBrokerSummary(item);
        }

        function resetBrokerHistoryPoll() {
            if (state.brokerHistoryPollHandle) {
                clearInterval(state.brokerHistoryPollHandle);
                state.brokerHistoryPollHandle = null;
            }
        }

        async function loadBrokerHistory(symbol = state.currentSymbol, brokerCode = state.currentBrokerCode) {
            const activeSymbol = String(symbol || '').trim().toUpperCase();
            const activeBroker = String(brokerCode || '').trim().toUpperCase();
            if (!activeSymbol || !activeBroker) return;

            const response = await fetch(`./api/broker-history.php?${buildQueryString({ symbol: activeSymbol, broker_code: activeBroker })}`, { cache: 'no-store' });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memuat histori sekuritas.');
            }

            state.currentBrokerHistory = data;

            if (state.currentItem && state.currentItem.symbol === activeSymbol) {
                renderSingleItem(state.currentItem);
            }

            if (data.task?.status === 'running') {
                if (!state.brokerHistoryPollHandle) {
                    state.brokerHistoryPollHandle = setInterval(() => {
                        loadBrokerHistory(activeSymbol, activeBroker).catch(() => {});
                    }, 2000);
                }
            } else {
                resetBrokerHistoryPoll();
            }
        }

        async function refreshData() {
            setMessage('Sedang mengambil data terbaru dari Stockbit...');
            const response = await fetch('./api/refresh.php', { cache: 'no-store' });
            const data = await response.json();
            const detail = data.errors?.length ? ' ' + data.errors.map(item => `${item.symbol}: ${item.message}`).join(' | ') : '';
            setMessage((data.message || 'Refresh selesai.') + detail, !data.ok);
            await loadDashboard();
        }

        async function searchSymbol() {
            const symbol = symbolSearchEl.value.trim().toUpperCase() || state.currentSymbol;
            if (!symbol) {
                setMessage('Masukkan simbol saham dulu, misalnya BBCA.', true);
                return;
            }

            resetBrokerHistoryPoll();
            if (state.currentSymbol !== symbol) {
                state.currentBrokerCode = '';
                state.currentBrokerHistory = null;
            }
            state.currentSymbol = symbol;
            symbolSearchEl.value = symbol;
            setMessage(`Sedang mengambil data ${symbol}...`);
            const response = await fetch(`./api/symbol.php?${buildQueryString({ symbol, ...currentFilters() })}`, { cache: 'no-store' });
            const data = await response.json();
            setMessage(data.message || 'Pencarian selesai.', !data.ok);

            if (data.ok && data.item) {
                data.item.payload.applied_filters = currentFilters();
                renderSingleItem(data.item);
                if (state.currentBrokerCode) {
                    loadBrokerHistory(state.currentSymbol, state.currentBrokerCode).catch(() => {});
                }
            }
        }

        function resetAutoRefresh() {
            if (state.autoRefreshHandle) {
                clearInterval(state.autoRefreshHandle);
            }

            state.autoRefreshHandle = setInterval(() => {
                refreshData().catch(error => setMessage(error.message, true));
            }, state.autoRefreshMinutes * 60 * 1000);
        }

        function buildBookmarklet() {
            const importUrl = `${window.location.origin}/api/import-token.php`;
            const script = `(function(){try{var get=function(k){var v=localStorage.getItem(k);return v?atob(v):''};var f=document.createElement('form');f.method='POST';f.action='${importUrl}';f.target='_blank';[['access_token',get('at')],['access_token_expiry',get('ate')],['refresh_token',get('ar')],['refresh_token_expiry',get('are')],['access_user',get('au')]].forEach(function(entry){var i=document.createElement('input');i.type='hidden';i.name=entry[0];i.value=entry[1];f.appendChild(i);});document.body.appendChild(f);f.submit();f.remove();}catch(e){alert('Gagal impor token: '+e.message);}})();`;
            bookmarkletLinkEl.href = `javascript:${script}`;
        }

        function rerunCurrentSymbol() {
            if (!state.currentSymbol) return Promise.resolve();
            return searchSymbol();
        }

        async function applyBrokerLookup() {
            const brokerInput = document.getElementById('broker-code-input');
            if (!brokerInput || !state.currentItem) return;
            state.currentBrokerCode = brokerInput.value.trim().toUpperCase();
            state.currentBrokerHistory = null;
            renderSingleItem(state.currentItem);

            if (!state.currentBrokerCode) {
                setMessage('Masukkan kode sekuritas dulu, misalnya GR.', true);
                return;
            }

            setMessage(`Memulai scan histori ${state.currentBrokerCode} untuk ${state.currentSymbol}...`);
            const response = await fetch('./api/broker-history.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'start',
                    symbol: state.currentSymbol,
                    broker_code: state.currentBrokerCode,
                }),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.message || 'Gagal memulai scan histori sekuritas.');
            }

            state.currentBrokerHistory = data;
            renderSingleItem(state.currentItem);
            loadBrokerHistory(state.currentSymbol, state.currentBrokerCode).catch(() => {});
        }

        function showImportMessage() {
            const params = new URLSearchParams(window.location.search);
            const status = params.get('import');
            if (status === 'success') {
                setMessage('Token Stockbit berhasil diimpor otomatis. Sekarang klik Refresh Sekarang.', false);
                history.replaceState({}, '', window.location.pathname);
            } else if (status === 'failed') {
                setMessage('Impor token gagal. Pastikan bookmarklet dijalankan saat Anda sedang login di Stockbit.', true);
                history.replaceState({}, '', window.location.pathname);
            }
        }

        refreshBtn.addEventListener('click', () => {
            refreshData().catch(error => setMessage(error.message, true));
        });

        searchBtn.addEventListener('click', () => {
            searchSymbol().catch(error => setMessage(error.message, true));
        });

        symbolSearchEl.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchSymbol().catch(error => setMessage(error.message, true));
            }
        });

        document.addEventListener('click', (event) => {
            const brokerButton = event.target.closest('#broker-code-btn');
            if (!brokerButton) return;
            applyBrokerLookup().catch(error => setMessage(error.message, true));
        });

        document.addEventListener('keydown', (event) => {
            if (event.target instanceof HTMLElement && event.target.id === 'broker-code-input' && event.key === 'Enter') {
                event.preventDefault();
                applyBrokerLookup().catch(error => setMessage(error.message, true));
            }
        });

        document.addEventListener('click', (event) => {
            const target = event.target.closest('[data-period]');
            if (!target) return;
            state.period = target.dataset.period;
            dateFromEl.value = '';
            dateToEl.value = '';
            globalShortcutsEl.innerHTML = renderShortcuts(currentFilters());
            saveSettings().then(() => {
                return rerunCurrentSymbol();
            }).catch(error => setMessage(error.message, true));
        });

        dateFromEl.addEventListener('change', () => {
            state.period = '';
            globalShortcutsEl.innerHTML = renderShortcuts(currentFilters());
            saveSettings().then(() => {
                return rerunCurrentSymbol();
            }).catch(error => setMessage(error.message, true));
        });

        dateToEl.addEventListener('change', () => {
            state.period = '';
            globalShortcutsEl.innerHTML = renderShortcuts(currentFilters());
            saveSettings().then(() => {
                return rerunCurrentSymbol();
            }).catch(error => setMessage(error.message, true));
        });

        datePrevBtn.addEventListener('click', () => {
            shiftDateRange(-1);
        });

        dateNextBtn.addEventListener('click', () => {
            shiftDateRange(1);
        });

        [investorTypeEl, marketBoardEl, transactionTypeEl].forEach((el) => {
            el.addEventListener('change', () => {
                saveSettings().then(() => {
                    return rerunCurrentSymbol();
                }).catch(error => setMessage(error.message, true));
            });
        });

        buildBookmarklet();
        showImportMessage();
        loadDashboard().catch(error => setMessage(error.message, true));
    </script>
</body>
</html>
