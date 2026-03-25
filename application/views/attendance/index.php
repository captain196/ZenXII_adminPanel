<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Attendance Dashboard ────────────────────────────────── */
:root {
    --att-primary: var(--gold);
    --att-primary-dim: var(--gold-dim);
    --att-primary-glow: var(--gold-glow);
    --att-bg: var(--bg);
    --att-bg2: var(--bg2);
    --att-bg3: var(--bg3);
    --att-border: var(--border);
    --att-t1: var(--t1);
    --att-t2: var(--t2);
    --att-t3: var(--t3);
    --att-shadow: var(--sh);
    --att-radius: 10px;
    --att-green: #16a34a;
    --att-red: #dc2626;
    --att-amber: #d97706;
    --att-blue: #2563eb;
}

.att-wrap { padding: 20px 22px 40px; }

/* ── Header ──────────────────────────────────────────────── */
.att-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; padding: 18px 22px; margin-bottom: 24px;
    background: var(--att-bg2); border: 1px solid var(--att-border);
    border-radius: var(--att-radius); box-shadow: var(--att-shadow);
    flex-wrap: wrap;
}
.att-header-left { display: flex; align-items: center; gap: 14px; }
.att-header-icon {
    width: 44px; height: 44px; border-radius: 10px;
    background: var(--att-primary); display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
    box-shadow: 0 0 18px var(--att-primary-glow);
}
.att-header-icon i { color: #fff; font-size: 20px; }
.att-page-title { font-size: 18px; font-weight: 700; color: var(--att-t1); font-family: var(--font-d); }
.att-breadcrumb {
    list-style: none; display: flex; gap: 6px; margin: 4px 0 0; padding: 0;
    font-size: 12px; color: var(--att-t3);
}
.att-breadcrumb li + li::before { content: '/'; margin-right: 6px; color: var(--att-t3); }
.att-breadcrumb a { color: var(--att-primary); text-decoration: none; }
.att-breadcrumb a:hover { text-decoration: underline; }

/* ── Stat Cards ──────────────────────────────────────────── */
.att-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 28px;
}
.att-stat-card {
    background: var(--att-bg2); border: 1px solid var(--att-border);
    border-radius: var(--att-radius); padding: 20px;
    box-shadow: var(--att-shadow);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    position: relative; overflow: hidden;
}
.att-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
}
.att-stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 3px; border-radius: var(--att-radius) var(--att-radius) 0 0;
}
.att-stat-card.present::before { background: var(--att-green); }
.att-stat-card.absent::before  { background: var(--att-red); }
.att-stat-card.late::before    { background: var(--att-amber); }
.att-stat-card.staff::before   { background: var(--att-blue); }

.att-stat-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px; font-size: 18px;
}
.att-stat-card.present .att-stat-icon { background: rgba(22,163,74,0.1); color: var(--att-green); }
.att-stat-card.absent  .att-stat-icon { background: rgba(220,38,38,0.1); color: var(--att-red); }
.att-stat-card.late    .att-stat-icon { background: rgba(217,119,6,0.1); color: var(--att-amber); }
.att-stat-card.staff   .att-stat-icon { background: rgba(37,99,235,0.1); color: var(--att-blue); }

.att-stat-value {
    font-size: 28px; font-weight: 800; color: var(--att-t1);
    font-family: var(--font-d); line-height: 1;
}
.att-stat-label {
    font-size: 12.5px; color: var(--att-t3); margin-top: 6px;
    font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px;
}
.att-stat-loading {
    width: 48px; height: 24px; border-radius: 6px;
    background: var(--att-bg3); animation: att-pulse 1.2s ease-in-out infinite;
}
@keyframes att-pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

/* ── Quick Actions ───────────────────────────────────────── */
.att-actions {
    display: flex; gap: 12px; margin-bottom: 28px; flex-wrap: wrap;
}
.att-action-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 20px; border-radius: 8px; font-size: 13px;
    font-weight: 600; text-decoration: none;
    transition: all 0.2s ease; cursor: pointer; border: none;
}
.att-action-btn.primary {
    background: var(--att-primary); color: #fff;
    box-shadow: 0 0 14px var(--att-primary-glow);
}
.att-action-btn.primary:hover {
    filter: brightness(1.1);
    box-shadow: 0 0 22px var(--att-primary-glow);
}
.att-action-btn.outline {
    background: var(--att-bg2); color: var(--att-primary);
    border: 1px solid var(--att-border);
}
.att-action-btn.outline:hover {
    border-color: var(--att-primary); background: var(--att-primary-dim);
}

/* ── Punch Log Table ─────────────────────────────────────── */
.att-section-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 15px; font-weight: 700; color: var(--att-t1);
    margin-bottom: 14px; font-family: var(--font-d);
}
.att-section-title i { color: var(--att-primary); font-size: 16px; }
.att-section-title small {
    font-size: 11px; font-weight: 500; color: var(--att-t3);
    margin-left: auto;
}

.att-table-wrap {
    background: var(--att-bg2); border: 1px solid var(--att-border);
    border-radius: var(--att-radius); box-shadow: var(--att-shadow);
    overflow: hidden;
}
.att-table {
    width: 100%; border-collapse: collapse; font-size: 13px;
}
.att-table thead th {
    padding: 12px 16px; text-align: left; font-weight: 700;
    color: var(--att-t2); font-size: 11.5px; text-transform: uppercase;
    letter-spacing: 0.4px; border-bottom: 1px solid var(--att-border);
    background: var(--att-bg3);
}
.att-table tbody td {
    padding: 10px 16px; color: var(--att-t1);
    border-bottom: 1px solid var(--att-border);
}
.att-table tbody tr:last-child td { border-bottom: none; }
.att-table tbody tr:hover { background: var(--att-primary-dim); }

.att-badge {
    display: inline-block; padding: 2px 8px; border-radius: 6px;
    font-size: 11px; font-weight: 600;
}
.att-badge.in  { background: rgba(22,163,74,0.12); color: var(--att-green); }
.att-badge.out { background: rgba(220,38,38,0.12); color: var(--att-red); }

.att-empty {
    text-align: center; padding: 40px 20px; color: var(--att-t3);
}
.att-empty i { font-size: 32px; margin-bottom: 10px; display: block; opacity: 0.5; }

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 1024px) {
    .att-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 767px) {
    .att-wrap { padding: 14px 12px 30px; }
    .att-header { padding: 14px 16px; }
    .att-stats { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .att-stat-card { padding: 14px; }
    .att-stat-value { font-size: 22px; }
    .att-actions { gap: 8px; }
    .att-action-btn { padding: 8px 14px; font-size: 12px; }
    .att-table-wrap { overflow-x: auto; }
}
@media (max-width: 479px) {
    .att-stats { grid-template-columns: 1fr; }
    .att-header { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">
<div class="att-wrap">

    <!-- Page Header -->
    <div class="att-header">
        <div class="att-header-left">
            <div class="att-header-icon"><i class="fa fa-calendar-check-o"></i></div>
            <div>
                <div class="att-page-title">Attendance Dashboard</div>
                <ol class="att-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li>Attendance</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Today's Quick Stats -->
    <div class="att-stats">
        <div class="att-stat-card present">
            <div class="att-stat-icon"><i class="fa fa-check-circle"></i></div>
            <div class="att-stat-value" id="attStatPresent"><div class="att-stat-loading"></div></div>
            <div class="att-stat-label">Students Present Today</div>
        </div>
        <div class="att-stat-card absent">
            <div class="att-stat-icon"><i class="fa fa-times-circle"></i></div>
            <div class="att-stat-value" id="attStatAbsent"><div class="att-stat-loading"></div></div>
            <div class="att-stat-label">Students Absent Today</div>
        </div>
        <div class="att-stat-card late">
            <div class="att-stat-icon"><i class="fa fa-clock-o"></i></div>
            <div class="att-stat-value" id="attStatLate"><div class="att-stat-loading"></div></div>
            <div class="att-stat-label">Late Arrivals Today</div>
        </div>
        <div class="att-stat-card staff">
            <div class="att-stat-icon"><i class="fa fa-user-circle"></i></div>
            <div class="att-stat-value" id="attStatStaff"><div class="att-stat-loading"></div></div>
            <div class="att-stat-label">Staff Present Today</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="att-actions">
        <a href="<?= base_url('attendance/student') ?>" class="att-action-btn primary">
            <i class="fa fa-users"></i> Mark Student Attendance
        </a>
        <a href="<?= base_url('attendance/staff') ?>" class="att-action-btn outline">
            <i class="fa fa-id-badge"></i> Mark Staff Attendance
        </a>
        <a href="<?= base_url('attendance/analytics') ?>" class="att-action-btn outline">
            <i class="fa fa-bar-chart"></i> View Analytics
        </a>
    </div>

    <!-- Recent Punch Log -->
    <div class="att-section-title">
        <i class="fa fa-list-ul"></i> Recent Punch Log
        <small>Last 10 device punches</small>
    </div>
    <div class="att-table-wrap">
        <table class="att-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Time</th>
                    <th>Device</th>
                </tr>
            </thead>
            <tbody id="attPunchBody">
                <tr>
                    <td colspan="5" class="att-empty">
                        <i class="fa fa-spinner fa-spin"></i>
                        Loading punch log...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

</div>
</div>
</section>
</div>

<script>
(function(){
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    function esc(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s || ''));
        return d.innerHTML;
    }

    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) { Object.keys(data).forEach(function(k){ fd.append(k, data[k]); }); }
        return fetch(BASE + url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') === -1) throw new Error('Non-JSON response');
                return r.json();
            })
            .then(function(data) {
                if (data.csrf_hash) { CSRF_HASH = data.csrf_hash; }
                return data;
            });
    }

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) { el.textContent = val; }
    }

    /* ── Load Today's Stats ─────────────────────────────── */
    function loadStats() {
        return postData('attendance/dashboard_stats', {})
            .then(function(data) {
                if (!data || data.status !== 'success') throw new Error('Failed');

                var stu  = data.students || {};
                var stf  = data.staff || {};

                setText('attStatPresent', (stu.present || 0) + (stu.late || 0));
                setText('attStatAbsent', stu.absent || 0);
                setText('attStatLate', stu.late || 0);
                setText('attStatStaff', (stf.present || 0) + (stf.late || 0) + ' / ' + (stf.total || 0));
            })
            .catch(function() {
                setText('attStatPresent', '--');
                setText('attStatAbsent', '--');
                setText('attStatLate', '--');
                setText('attStatStaff', '--');
            });
    }

    /* ── Load Punch Log ─────────────────────────────────── */
    function loadPunchLog() {
        postData('attendance/fetch_punch_log')
            .then(function(data) {
                var tbody = document.getElementById('attPunchBody');
                if (!tbody) return;

                var rows = data.punches || data.data || [];
                if (!Array.isArray(rows) || rows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="att-empty">' +
                        '<i class="fa fa-inbox"></i>No recent punches found.</td></tr>';
                    return;
                }

                var limit = rows.slice(0, 10);
                var html = '';
                for (var i = 0; i < limit.length; i++) {
                    var p = limit[i];
                    var typeCls = (p.type || '').toLowerCase() === 'out' ? 'out' : 'in';
                    html += '<tr>' +
                        '<td>' + esc(p.name) + '</td>' +
                        '<td>' + esc(p.user_id || p.id) + '</td>' +
                        '<td><span class="att-badge ' + typeCls + '">' + esc(p.type || 'IN') + '</span></td>' +
                        '<td>' + esc(p.time || p.punch_time) + '</td>' +
                        '<td>' + esc(p.device || '-') + '</td>' +
                        '</tr>';
                }
                tbody.innerHTML = html;
            })
            .catch(function() {
                var tbody = document.getElementById('attPunchBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="5" class="att-empty">' +
                        '<i class="fa fa-exclamation-triangle"></i>Failed to load punch log.</td></tr>';
                }
            });
    }

    /* ── Init (chained to avoid CSRF token race condition) ── */
    loadStats().then(function() { loadPunchLog(); });
})();
</script>
