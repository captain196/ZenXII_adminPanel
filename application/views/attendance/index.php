<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<!-- Attendance Design System (shared, cacheable) — see assets/css/attendance_design_system.css -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.1.0">

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
                <ul class="att-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li>Attendance</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Today's Quick Stats -->
    <div class="att-kpi-grid-5">
        <div class="att-kpi att-kpi-success">
            <div class="att-kpi-icon"><i class="fa fa-check-circle"></i></div>
            <div class="att-kpi-value" id="attStatPresent"><div class="att-kpi-loading"></div></div>
            <div class="att-kpi-label">Students Present Today</div>
        </div>
        <div class="att-kpi att-kpi-danger">
            <div class="att-kpi-icon"><i class="fa fa-times-circle"></i></div>
            <div class="att-kpi-value" id="attStatAbsent"><div class="att-kpi-loading"></div></div>
            <div class="att-kpi-label">Students Absent Today</div>
        </div>
        <div class="att-kpi att-kpi-warning">
            <div class="att-kpi-icon"><i class="fa fa-clock-o"></i></div>
            <div class="att-kpi-value" id="attStatLate"><div class="att-kpi-loading"></div></div>
            <div class="att-kpi-label">Late Arrivals Today</div>
        </div>
        <div class="att-kpi att-kpi-info">
            <div class="att-kpi-icon"><i class="fa fa-user-circle"></i></div>
            <div class="att-kpi-value" id="attStatStaff"><div class="att-kpi-loading"></div></div>
            <div class="att-kpi-label">Staff Present Today</div>
        </div>
        <div class="att-kpi att-kpi-purple att-kpi--clickable" id="attCorrectionsCard"
             onclick="window.location='<?= base_url('attendance/control') ?>#corrections'"
             title="Open Control Panel — Corrections tab">
            <div class="att-kpi-icon"><i class="fa fa-flag-checkered"></i></div>
            <div class="att-kpi-value" id="attStatCorrections"><div class="att-kpi-loading"></div></div>
            <div class="att-kpi-label">Pending Corrections</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="att-actions">
        <a href="<?= base_url('attendance/control') ?>" class="att-btn att-btn-primary">
            <i class="fa fa-shield"></i> Attendance Control Panel
        </a>
        <a href="<?= base_url('attendance/student') ?>" class="att-btn att-btn-outline">
            <i class="fa fa-users"></i> Mark Student Attendance
        </a>
        <a href="<?= base_url('attendance/staff') ?>" class="att-btn att-btn-outline">
            <i class="fa fa-id-badge"></i> Mark Staff Attendance
        </a>
        <a href="<?= base_url('attendance/scan') ?>" class="att-btn att-btn-outline">
            <i class="fa fa-qrcode"></i> QR Scan
        </a>
        <a href="<?= base_url('attendance/analytics') ?>" class="att-btn att-btn-outline">
            <i class="fa fa-bar-chart"></i> View Analytics
        </a>
        <a href="<?= base_url('attendance/audit') ?>" class="att-btn att-btn-outline">
            <i class="fa fa-history"></i> Audit Log
        </a>
        <a href="<?= base_url('attendance/staff_regularization') ?>" class="att-btn att-btn-outline">
            <i class="fa fa-user-clock"></i> Regularization Requests
        </a>
    </div>

    <!-- Recent Punch Log -->
    <div class="att-section-title">
        <i class="fa fa-list-ul"></i> Recent Punch Log
        <small>Latest 10 punches today</small>
        <a class="att-link" href="<?= base_url('attendance/punch_log') ?>">View full log <i class="fa fa-arrow-right"></i></a>
    </div>
    <div class="att-card att-card--panel">
        <div class="att-table-wrap">
            <table class="att-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>ID</th>
                        <th>Direction</th>
                        <th>Outcome</th>
                        <th>Time</th>
                        <th>Device</th>
                    </tr>
                </thead>
                <tbody id="attPunchBody">
                    <tr>
                        <td colspan="6" class="att-empty">
                            <i class="fa fa-spinner fa-spin"></i>
                            Loading punch log...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- .att-wrap -->
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
                    tbody.innerHTML = '<tr><td colspan="6" class="att-empty">' +
                        '<i class="fa fa-inbox"></i>No punches recorded today.</td></tr>';
                    return;
                }

                var limit = rows.slice(0, 10);
                var html = '';
                for (var i = 0; i < limit.length; i++) {
                    var p = limit[i];
                    var pid = p.user_id || p.person_id || p.id || '';
                    var nm  = p.name || pid || '—';
                    var dir = (p.direction || 'in').toLowerCase() === 'out' ? 'out' : 'in';
                    var oc  = (p.outcome || '').toLowerCase();
                    var ocHtml = oc === 'accepted' ? '<span class="att-tag att-tag-green">Accepted</span>'
                               : oc === 'rejected' ? '<span class="att-tag att-tag-red">Rejected</span>'
                               : '<span class="att-tag att-tag-gray">—</span>';
                    html += '<tr>' +
                        '<td class="att-u-strong">' + esc(nm) + '</td>' +
                        '<td class="att-mono att-u-muted">' + esc(pid) + '</td>' +
                        '<td><span class="att-tag ' + (dir === 'out' ? 'att-tag-red' : 'att-tag-green') + '">' + (dir === 'out' ? 'OUT' : 'IN') + '</span></td>' +
                        '<td>' + ocHtml + '</td>' +
                        '<td>' + esc(p.time || p.punch_time || '—') + '</td>' +
                        '<td>' + esc(p.device || '—') + '</td>' +
                        '</tr>';
                }
                tbody.innerHTML = html;
            })
            .catch(function() {
                var tbody = document.getElementById('attPunchBody');
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="6" class="att-empty">' +
                        '<i class="fa fa-exclamation-triangle"></i>Failed to load punch log.</td></tr>';
                }
            });
    }

    /* ── Pending corrections count ──────────────────────── */
    function loadCorrectionsCount() {
        // Use GET — correction_list is a GET endpoint and credentials carry the session.
        fetch(BASE + 'attendance/correction/list?status=pending&limit=100', {
            credentials: 'same-origin'
        })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(j) {
            if (!j || j.status !== 'success') {
                setText('attStatCorrections', '--');
                return;
            }
            var n = (j.requests && j.requests.length) || 0;
            setText('attStatCorrections', String(n));
            var card = document.getElementById('attCorrectionsCard');
            if (card) {
                if (n > 0) card.classList.add('has-pending');
                else       card.classList.remove('has-pending');
            }
        })
        .catch(function() { setText('attStatCorrections', '--'); });
    }

    /* ── Init: load all three IN PARALLEL ───────────────────
       Previously chained behind loadStats(), so a slow dashboard_stats blocked
       the punch log + corrections from even starting. csrf_regenerate=FALSE
       (see config.php) means concurrent POSTs share one stable token — no race —
       so we fire them independently for a much faster perceived load. */
    loadStats();
    loadPunchLog();
    loadCorrectionsCount();
})();
</script>
