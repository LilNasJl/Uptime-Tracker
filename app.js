/**
 * PC Uptime & Power Monitor - Pure Vanilla JS Frontend
 * Connects to api.php and renders multi-PC telemetry, live ticking timers,
 * 20-row default paginated history, and interactive filtering.
 */

const API_URL = 'api.php';
let selectedPc = 'all';
let currentPage = 1;
let perPage = 20;
let totalPages = 1;
let currentDevices = [];
let tickInterval = null;

document.addEventListener('DOMContentLoaded', () => {
    initControls();
    fetchDashboard();

    // Poll backend every 10 seconds for real-time telemetry updates
    setInterval(fetchDashboard, 10000);

    // Tick online PC uptimes second-by-second
    tickInterval = setInterval(tickLiveUptimes, 1000);
});

function initControls() {
    const selector = document.getElementById('pc-selector');
    selector.addEventListener('change', (e) => {
        selectedPc = e.target.value;
        currentPage = 1;
        fetchDashboard();
    });

    document.getElementById('btn-refresh').addEventListener('click', () => {
        fetchDashboard();
    });

    // Rows per page dropdown (10, 20, 50, 100)
    const perPageSelect = document.getElementById('per-page-select');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', (e) => {
            perPage = parseInt(e.target.value, 10) || 20;
            currentPage = 1;
            fetchDashboard();
        });
    }

    // Pagination navigation buttons
    document.getElementById('btn-page-first').addEventListener('click', () => {
        if (currentPage !== 1) {
            currentPage = 1;
            fetchDashboard();
        }
    });

    document.getElementById('btn-page-prev').addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            fetchDashboard();
        }
    });

    document.getElementById('btn-page-next').addEventListener('click', () => {
        if (currentPage < totalPages) {
            currentPage++;
            fetchDashboard();
        }
    });

    document.getElementById('btn-page-last').addEventListener('click', () => {
        if (currentPage !== totalPages) {
            currentPage = totalPages;
            fetchDashboard();
        }
    });

    // Modal instructions
    const modal = document.getElementById('setup-modal');
    document.getElementById('btn-setup').addEventListener('click', () => {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/index\.html$/i, '').replace(/\/$/, '');
        document.getElementById('cmd-setup-example').textContent =
            `php tracker.php setup --name="Office-PC-1" --api="${baseUrl}/api.php"`;
        modal.style.display = 'flex';
    });

    document.getElementById('modal-close').addEventListener('click', () => {
        modal.style.display = 'none';
    });

    window.addEventListener('click', (e) => {
        if (e.target === modal) modal.style.display = 'none';
    });
}

async function fetchDashboard() {
    try {
        const url = `${API_URL}?action=dashboard&pc=${encodeURIComponent(selectedPc)}&page=${currentPage}&limit=${perPage}`;
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success) return;

        currentDevices = data.devices || [];

        renderFleetHeader(data.fleet, currentDevices);
        renderPcGrid(currentDevices);
        renderKpis(data.stats, currentDevices);
        renderChart(data.stats ? data.stats.daily_history : []);

        if (data.pagination) {
            currentPage = data.pagination.page;
            totalPages = data.pagination.total_pages;
        }

        renderSessionsTable(data.sessions || [], data.pagination || null);
    } catch (err) {
        console.error('Failed to fetch dashboard data:', err);
    }
}

function renderFleetHeader(fleet, devices) {
    const emptyAlert = document.getElementById('empty-alert');
    const dot = document.getElementById('fleet-dot');
    const summaryText = document.getElementById('fleet-summary-text');
    const pcCountLabel = document.getElementById('pc-count-label');
    const selector = document.getElementById('pc-selector');

    const total = fleet ? fleet.total_pcs : 0;
    const online = fleet ? fleet.online_pcs : 0;

    pcCountLabel.textContent = total;
    summaryText.textContent = `${online} / ${total} PCs Online`;

    if (total === 0) {
        emptyAlert.style.display = 'block';
        dot.className = 'status-dot';
    } else {
        emptyAlert.style.display = 'none';
        dot.className = online > 0 ? 'status-dot online' : 'status-dot offline';
    }

    // Update dropdown options while preserving current selection
    let optionsHtml = '<option value="all">🌐 All PCs (Fleet View)</option>';
    devices.forEach((dev) => {
        const icon = dev.is_online ? '🟢' : '🔴';
        const sel = (selectedPc === dev.pc_name) ? 'selected' : '';
        optionsHtml += `<option value="${escapeHtml(dev.pc_name)}" ${sel}>${icon} ${escapeHtml(dev.pc_name)}</option>`;
    });
    selector.innerHTML = optionsHtml;
}

function renderPcGrid(devices) {
    const grid = document.getElementById('pc-grid');
    if (!devices || devices.length === 0) {
        grid.innerHTML = '<div class="card" style="grid-column: 1/-1; text-align:center; color:var(--text-muted); padding:28px;">No PCs registered yet. Run <code>php tracker.php setup</code> on a PC to connect it.</div>';
        return;
    }

    let html = '';
    devices.forEach((dev, idx) => {
        const isSelected = (selectedPc === dev.pc_name);
        const cardClasses = [
            'pc-card',
            dev.is_online ? 'is-online' : 'is-offline',
            isSelected ? 'active-filter' : ''
        ].join(' ');

        const statusBadge = dev.is_online
            ? '<span class="badge badge-success">🟢 ONLINE</span>'
            : '<span class="badge badge-danger">🔴 OFFLINE</span>';

        const uptimeDisplay = dev.is_online
            ? formatDuration(dev.uptime_seconds)
            : `Turned Off (Last run: ${formatDuration(dev.last_uptime_sec)})`;

        html += `
            <div class="${cardClasses}" onclick="selectPcFilter('${escapeJs(dev.pc_name)}')">
                <div class="pc-card-top">
                    <div class="pc-name">
                        <span>🖥️ ${escapeHtml(dev.pc_name)}</span>
                    </div>
                    <div class="pc-actions">
                        ${statusBadge}
                        <button class="pc-delete-btn" title="Remove ${escapeHtml(dev.pc_name)}" onclick="deletePc('${escapeJs(dev.pc_name)}', event)">✕</button>
                    </div>
                </div>
                <div class="pc-uptime-live" id="pc-live-up-${idx}">
                    ${uptimeDisplay}
                </div>
                <div class="pc-meta">
                    <span><strong>Turned ON:</strong> ${escapeHtml(dev.last_boot || '--')}</span>
                    <span><strong>Last Ping:</strong> ${escapeHtml(dev.last_seen)} (${escapeHtml(dev.last_seen_ago)})</span>
                    <span><strong>Host / OS:</strong> ${escapeHtml(dev.system_hostname || '--')} (${escapeHtml(dev.os || '--')})</span>
                </div>
            </div>
        `;
    });

    grid.innerHTML = html;
}

function tickLiveUptimes() {
    currentDevices.forEach((dev, idx) => {
        if (dev.is_online) {
            dev.uptime_seconds = (dev.uptime_seconds || 0) + 1;
            const el = document.getElementById(`pc-live-up-${idx}`);
            if (el) {
                el.textContent = formatDuration(dev.uptime_seconds);
            }
        }
    });
}

function selectPcFilter(pcName) {
    if (selectedPc === pcName) {
        selectedPc = 'all';
    } else {
        selectedPc = pcName;
    }
    currentPage = 1;
    document.getElementById('pc-selector').value = selectedPc;
    fetchDashboard();
}

async function deletePc(pcName, event) {
    if (event) event.stopPropagation();
    if (!confirm(`Are you sure you want to remove [${pcName}] from the dashboard?`)) {
        return;
    }
    try {
        const res = await fetch(`${API_URL}?action=delete_pc&pc=${encodeURIComponent(pcName)}`);
        const data = await res.json();
        if (data.success) {
            if (selectedPc === pcName) {
                selectedPc = 'all';
            }
            currentPage = 1;
            fetchDashboard();
        } else {
            alert('Failed to remove PC: ' + (data.error || 'Unknown error'));
        }
    } catch (err) {
        console.error('Error removing PC:', err);
    }
}

function renderKpis(stats, devices) {
    if (!stats) return;

    const filterNameEl = document.getElementById('kpi-filter-name');
    const filterSubEl = document.getElementById('kpi-filter-sub');
    const chartScopeEl = document.getElementById('chart-scope-label');

    if (selectedPc === 'all') {
        filterNameEl.textContent = 'All PCs';
        filterSubEl.textContent = `Combined across ${devices.length} connected PC(s)`;
        chartScopeEl.textContent = 'All PCs';
    } else {
        filterNameEl.textContent = selectedPc;
        filterSubEl.textContent = 'Click active PC card again to reset filter';
        chartScopeEl.textContent = selectedPc;
    }

    document.getElementById('kpi-today-uptime').textContent = stats.today_uptime_formatted || '0s';
    document.getElementById('kpi-total-uptime').textContent = stats.total_uptime_formatted || '0s';
    document.getElementById('kpi-total-sessions').textContent = `${stats.total_sessions || 0} recorded sessions`;
    document.getElementById('kpi-dirty-count').textContent = stats.unexpected_shutdowns || 0;
}

function renderChart(history) {
    const container = document.getElementById('chart-bars');
    if (!history || history.length === 0) {
        container.innerHTML = '<div style="margin:auto; color:var(--text-muted); font-size:12px;">No activity data</div>';
        return;
    }

    const maxHours = Math.max(...history.map(h => h.hours), 12);

    let html = '';
    history.forEach((item) => {
        const pct = item.hours > 0 ? Math.max(6, Math.min(100, Math.round((item.hours / maxHours) * 100))) : 3;
        html += `
            <div class="chart-bar-group">
                <span class="chart-bar-val">${item.hours}h</span>
                <div class="chart-bar-fill" style="height: ${pct}%;" title="${item.log_date}: ${item.hours} hrs (${item.session_count} sessions)"></div>
                <span class="chart-bar-label">${escapeHtml(item.label)}</span>
            </div>
        `;
    });

    container.innerHTML = html;
}

function renderSessionsTable(sessions, pagination) {
    const tbody = document.getElementById('sessions-tbody');
    const badge = document.getElementById('sessions-badge');

    const totalRecords = pagination ? pagination.total_records : sessions.length;
    badge.textContent = `${totalRecords} Sessions`;

    if (sessions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-state">No sessions recorded for this filter.</td></tr>';
    } else {
        let html = '';
        sessions.forEach((s) => {
            let statusBadge = '';
            let shutdownCol = s.shutdown_time ? escapeHtml(s.shutdown_time) : '<span style="color:var(--text-muted)">--</span>';
            let typeBadge = '';

            if (Number(s.is_current) === 1 || s.shutdown_type === 'active') {
                statusBadge = '<span class="badge badge-success">🟢 Running</span>';
                shutdownCol = '<span style="color:var(--success); font-weight:600;">Active Now</span>';
                typeBadge = '<span class="badge badge-success">Turned ON</span>';
            } else if (s.shutdown_type === 'shutdown_dirty') {
                statusBadge = '<span class="badge badge-danger">💥 Crash / Cut</span>';
                typeBadge = '<span class="badge badge-danger">Unexpected Power Loss</span>';
            } else if (s.shutdown_type === 'offline_timeout') {
                statusBadge = '<span class="badge badge-warning">🔴 Offline</span>';
                typeBadge = '<span class="badge badge-warning">Heartbeat Stopped</span>';
            } else if (s.shutdown_type === 'shutdown_requested') {
                statusBadge = '<span class="badge badge-info">⏹️ Turned OFF</span>';
                typeBadge = '<span class="badge badge-info">User Shutdown / Restart</span>';
            } else {
                statusBadge = '<span class="badge">⏹️ Turned OFF</span>';
                typeBadge = '<span class="badge">Clean OS Shutdown</span>';
            }

            html += `
                <tr>
                    <td>
                        <span class="pc-tag" onclick="selectPcFilter('${escapeJs(s.pc_name)}')">
                            🖥️ ${escapeHtml(s.pc_name)}
                        </span>
                    </td>
                    <td>${statusBadge}</td>
                    <td class="font-mono">${escapeHtml(s.boot_time)}</td>
                    <td class="font-mono">${shutdownCol}</td>
                    <td class="font-mono"><strong>${escapeHtml(s.duration_formatted)}</strong></td>
                    <td>${typeBadge}</td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    // Update pagination controls
    if (pagination) {
        const infoEl = document.getElementById('pagination-info');
        const displayEl = document.getElementById('page-display');
        const btnFirst = document.getElementById('btn-page-first');
        const btnPrev  = document.getElementById('btn-page-prev');
        const btnNext  = document.getElementById('btn-page-next');
        const btnLast  = document.getElementById('btn-page-last');

        if (pagination.total_records === 0) {
            infoEl.textContent = 'Showing 0 - 0 of 0 sessions';
            displayEl.textContent = 'Page 1 of 1';
            btnFirst.disabled = true;
            btnPrev.disabled  = true;
            btnNext.disabled  = true;
            btnLast.disabled  = true;
        } else {
            infoEl.textContent = `Showing ${pagination.start_index} - ${pagination.end_index} of ${pagination.total_records} sessions`;
            displayEl.textContent = `Page ${pagination.page} of ${pagination.total_pages}`;
            btnFirst.disabled = !pagination.has_prev;
            btnPrev.disabled  = !pagination.has_prev;
            btnNext.disabled  = !pagination.has_next;
            btnLast.disabled  = !pagination.has_next;
        }
    }
}

function formatDuration(sec) {
    sec = Math.max(0, parseInt(sec, 10) || 0);
    if (sec === 0) return '0s';

    const d = Math.floor(sec / 86400);
    const h = Math.floor((sec % 86400) / 3600);
    const m = Math.floor((sec % 3600) / 60);
    const s = sec % 60;

    const parts = [];
    if (d > 0) parts.push(`${d}d`);
    if (h > 0) parts.push(`${h}h`);
    if (m > 0) parts.push(`${m}m`);
    if (s > 0 || parts.length === 0) parts.push(`${s}s`);

    return parts.join(' ');
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function escapeJs(str) {
    if (!str) return '';
    return String(str).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}
