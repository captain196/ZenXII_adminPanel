<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── School Backup Module ──────────────────────────────────────────── */
.sb-wrap{padding:20px;max-width:1100px;margin:0 auto}
.sb-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.sb-header-title{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.sb-header-title i{color:var(--gold);font-size:1.1rem}
.sb-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.sb-breadcrumb a{color:var(--gold);text-decoration:none}
.sb-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}

/* Tabs */
.sb-tabs{display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap}
.sb-tab{padding:8px 18px;border-radius:8px;font-size:12.5px;font-weight:600;font-family:var(--font-b);
    cursor:pointer;background:var(--bg3);color:var(--t3);border:1px solid var(--border);transition:all .15s var(--ease)}
.sb-tab:hover{color:var(--t1);border-color:var(--gold-ring)}
.sb-tab.active{background:var(--gold);color:#fff;border-color:var(--gold)}
.sb-pane{display:none}.sb-pane.active{display:block}

/* KPIs */
.sb-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px;margin-bottom:20px}
.sb-kpi-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 16px;text-align:center}
.sb-kpi-num{font-size:26px;font-weight:800;font-family:var(--font-b);line-height:1;color:var(--t1)}
.sb-kpi-lbl{font-size:11px;color:var(--t3);margin-top:5px;text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}

/* Box */
.sb-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
.sb-box-head{font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:14px;
    padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.sb-box-head i{color:var(--gold)}

/* Forms */
.sb-fg{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
.sb-fg label{font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.sb-fg select,.sb-fg input{padding:8px 12px;border:1px solid var(--border);background:var(--bg3);
    border-radius:6px;font-size:13px;color:var(--t1);font-family:var(--font-b);transition:border-color .2s,box-shadow .2s}
.sb-fg select:focus,.sb-fg input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}

/* Toggle switch */
.sb-toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.sb-toggle input{opacity:0;width:0;height:0}
.sb-toggle-slider{position:absolute;inset:0;background:var(--border);border-radius:24px;cursor:pointer;transition:background .2s}
.sb-toggle-slider::before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.sb-toggle input:checked+.sb-toggle-slider{background:var(--gold)}
.sb-toggle input:checked+.sb-toggle-slider::before{transform:translateX(20px)}

/* Toggle row */
.sb-toggle-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid var(--border)}
.sb-toggle-info{flex:1}
.sb-toggle-info strong{font-size:13px;font-family:var(--font-b);color:var(--t1);display:block}
.sb-toggle-info span{font-size:11.5px;color:var(--t3);font-family:var(--font-m)}

/* Buttons */
.sb-btn{padding:8px 18px;border-radius:7px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;
    font-family:var(--font-b);transition:all .15s var(--ease);display:inline-flex;align-items:center;gap:6px}
.sb-btn:disabled{opacity:.5;cursor:not-allowed}
.sb-btn-p{background:var(--gold);color:#fff}.sb-btn-p:hover:not(:disabled){background:var(--gold2)}
.sb-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}.sb-btn-s:hover{border-color:var(--gold-ring)}
.sb-btn-lg{padding:12px 28px;font-size:13px;border-radius:8px}

/* Table */
.sb-table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:8px}
.sb-table{width:100%;border-collapse:collapse}
.sb-table th{padding:10px 12px;background:var(--bg3);color:var(--t3);font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);border-bottom:2px solid var(--border);
    text-align:left;position:sticky;top:0;z-index:1}
.sb-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);font-size:12.5px}
.sb-table tr:hover td{background:var(--gold-dim)}

/* Badges */
.sb-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:600;letter-spacing:.3px}
.sb-b-green{background:rgba(22,163,74,.12);color:#16a34a}
.sb-b-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.sb-b-amber{background:rgba(217,119,6,.12);color:#d97706}
.sb-b-teal{background:rgba(15,118,110,.12);color:var(--gold)}
.sb-b-gray{background:rgba(107,114,128,.12);color:#6b7280}

/* Empty */
.sb-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.sb-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.4}

/* Status dot */
.sb-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.sb-dot-on{background:#16a34a}.sb-dot-off{background:#6b7280}

/* Info callout */
.sb-callout{padding:14px 18px;border-radius:8px;font-size:12.5px;font-family:var(--font-b);display:flex;align-items:flex-start;gap:10px;margin-bottom:16px}
.sb-callout i{font-size:14px;margin-top:1px;flex-shrink:0}
.sb-callout-info{background:rgba(59,130,246,.08);color:#3b82f6;border:1px solid rgba(59,130,246,.15)}
.sb-callout-warn{background:rgba(217,119,6,.08);color:#d97706;border:1px solid rgba(217,119,6,.15)}
.sb-callout-ok{background:rgba(22,163,74,.08);color:#16a34a;border:1px solid rgba(22,163,74,.15)}

/* Last run */
.sb-last-run{margin-top:14px;padding:12px 16px;background:var(--bg3);border-radius:8px;font-size:12px;color:var(--t2);font-family:var(--font-m)}
.sb-last-run strong{color:var(--t1);font-family:var(--font-b)}

/* Responsive */
@media(max-width:768px){.sb-wrap{padding:14px 10px}.sb-kpi{grid-template-columns:1fr 1fr}.sb-toggle-row{flex-wrap:wrap}}
@media(max-width:480px){.sb-kpi{grid-template-columns:1fr}}
</style>

<div class="content-wrapper"><section class="content"><div class="sb-wrap">

<!-- Header -->
<div class="sb-header"><div>
    <div class="sb-header-title"><i class="fa fa-cloud-download"></i> Backup Management</div>
    <ol class="sb-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li>Backup</li></ol>
</div>
<button class="sb-btn sb-btn-s" onclick="SB.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
</div>

<!-- KPIs -->
<div class="sb-kpi">
    <div class="sb-kpi-card"><div class="sb-kpi-num" id="kpi-total">-</div><div class="sb-kpi-lbl">Total Backups</div></div>
    <div class="sb-kpi-card"><div class="sb-kpi-num" id="kpi-size" style="color:var(--gold)">-</div><div class="sb-kpi-lbl">Total Size</div></div>
    <div class="sb-kpi-card"><div class="sb-kpi-num" id="kpi-auto" style="color:#3b82f6">-</div><div class="sb-kpi-lbl">Scheduled</div></div>
    <div class="sb-kpi-card"><div class="sb-kpi-num" id="kpi-manual" style="color:#8b5cf6">-</div><div class="sb-kpi-lbl">Manual</div></div>
    <div class="sb-kpi-card">
        <div class="sb-kpi-num" id="kpi-status" style="font-size:13px;font-weight:700">-</div>
        <div class="sb-kpi-lbl">Auto-Backup</div>
    </div>
</div>

<!-- Tabs -->
<div class="sb-tabs">
    <div class="sb-tab active" data-tab="settings"><i class="fa fa-cog" style="margin-right:5px"></i>Backup Settings</div>
    <div class="sb-tab" data-tab="history"><i class="fa fa-history" style="margin-right:5px"></i>Backup History</div>
    <div class="sb-tab" data-tab="manual"><i class="fa fa-plus-circle" style="margin-right:5px"></i>Manual Backup</div>
</div>

<!-- ═══════════════════════════════════════════ SETTINGS TAB ═══ -->
<div class="sb-pane active" id="pane-settings">
    <div class="sb-box">
        <div class="sb-box-head"><i class="fa fa-cog"></i> Daily Backup Configuration</div>

        <div class="sb-callout sb-callout-info">
            <i class="fa fa-info-circle"></i>
            <span>When enabled, a full backup of your school data (students, classes, fees, exams, attendance, users) will be created automatically every day. Backups are stored securely on the server.</span>
        </div>

        <!-- Enable toggle -->
        <div class="sb-toggle-row">
            <div class="sb-toggle-info">
                <strong>Enable Daily Automatic Backup</strong>
                <span>Automatically create a backup of all school data every day</span>
            </div>
            <label class="sb-toggle">
                <input type="checkbox" id="schedEnabled">
                <span class="sb-toggle-slider"></span>
            </label>
        </div>

        <!-- Retention -->
        <div style="padding-top:18px">
            <div class="sb-fg" style="max-width:280px">
                <label>Retention Period (days)</label>
                <select id="schedRetention">
                    <?php for ($i = 1; $i <= 14; $i++): ?>
                    <option value="<?= $i ?>"<?= $i === 7 ? ' selected' : '' ?>><?= $i ?> day<?= $i > 1 ? 's' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <p style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin:0 0 16px">
                Older backups beyond this limit will be automatically removed to save storage space.
            </p>
        </div>

        <button class="sb-btn sb-btn-p" id="btnSaveSchedule" onclick="SB.saveSchedule()">
            <i class="fa fa-check"></i> Save Settings
        </button>

        <!-- Last run info -->
        <div class="sb-last-run" id="lastRunInfo" style="display:none">
            <i class="fa fa-clock-o" style="margin-right:6px;color:var(--gold)"></i>
            Last automatic backup: <strong id="lastRunTime">-</strong>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ HISTORY TAB ═══ -->
<div class="sb-pane" id="pane-history">
    <div class="sb-box">
        <div class="sb-box-head"><i class="fa fa-history"></i> Backup History</div>
        <div class="sb-table-wrap">
            <table class="sb-table" id="backupTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Backup ID</th>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Format</th>
                        <th>Size</th>
                        <th>Created By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="backupTbody">
                    <tr><td colspan="8" class="sb-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════ MANUAL TAB ═══ -->
<div class="sb-pane" id="pane-manual">
    <div class="sb-box">
        <div class="sb-box-head"><i class="fa fa-plus-circle"></i> Create Manual Backup</div>

        <div class="sb-callout sb-callout-info">
            <i class="fa fa-info-circle"></i>
            <span>Create an on-demand backup of your school data. Manual backups are limited to <strong>1 per day</strong>. The backup includes all students, classes, subjects, fees, exams, attendance, and admin user data.</span>
        </div>

        <div id="manualWarn" style="display:none">
            <div class="sb-callout sb-callout-warn">
                <i class="fa fa-exclamation-triangle"></i>
                <span id="manualWarnMsg">You have already created a backup today.</span>
            </div>
        </div>

        <div style="text-align:center;padding:30px 0">
            <div style="margin-bottom:20px">
                <i class="fa fa-database" style="font-size:48px;color:var(--gold);opacity:.5;display:block;margin-bottom:12px"></i>
                <p style="font-size:13px;color:var(--t2);font-family:var(--font-b);margin:0">
                    Click the button below to create a snapshot of your entire school database.
                </p>
            </div>
            <button class="sb-btn sb-btn-p sb-btn-lg" id="btnCreateBackup" onclick="SB.createBackup()">
                <i class="fa fa-cloud-upload"></i> Create Backup Now
            </button>
        </div>

        <div id="manualResult" style="display:none">
            <div class="sb-callout sb-callout-ok">
                <i class="fa fa-check-circle"></i>
                <span id="manualResultMsg"></span>
            </div>
        </div>
    </div>

    <!-- Data scope info -->
    <div class="sb-box">
        <div class="sb-box-head"><i class="fa fa-list-ul"></i> What's Included in a Backup</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-users" style="color:var(--gold);width:16px"></i> Student Profiles
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-graduation-cap" style="color:#3b82f6;width:16px"></i> Classes & Sections
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-book" style="color:#8b5cf6;width:16px"></i> Subjects
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-calendar-check-o" style="color:#16a34a;width:16px"></i> Attendance
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-inr" style="color:#d97706;width:16px"></i> Fees & Accounts
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-file-text-o" style="color:#ec4899;width:16px"></i> Exams & Results
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-user-secret" style="color:#6366f1;width:16px"></i> Admin Users
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:12px;font-family:var(--font-b);color:var(--t1)">
                <i class="fa fa-cogs" style="color:#0ea5e9;width:16px"></i> School Configuration
            </div>
        </div>
    </div>
</div>

</div></section></div>

<!-- ═══════════════════════════════════════════════════════════════════
     JAVASCRIPT — runs after footer loads jQuery + DataTables
════════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function() {

var BASE = '<?= base_url() ?>';
var SERVER_DATE = '<?= date("Y-m-d") ?>';

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function toast(msg, ok) {
    var t = $('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:12.5px;font-weight:600;font-family:var(--font-b);color:#fff;box-shadow:0 4px 16px rgba(0,0,0,.15);background:' + (ok ? 'var(--gold)' : '#dc2626') + '">' + esc(msg) + '</div>');
    $('body').append(t);
    setTimeout(function() { t.fadeOut(300, function() { t.remove(); }); }, 3500);
}

/* ── Tab switching ─────────────────────────────────────────────── */
$('.sb-tab').on('click', function() {
    var tab = $(this).data('tab');
    $('.sb-tab').removeClass('active');
    $(this).addClass('active');
    $('.sb-pane').removeClass('active');
    $('#pane-' + tab).addClass('active');

    if (tab === 'history' && !SB._historyLoaded) SB.loadBackups();
    if (tab === 'manual')  SB.checkManualLimit();
});

/* ══════════════════════════════════════════════════════════════════
   SB — main namespace
══════════════════════════════════════════════════════════════════ */
window.SB = {};

SB._historyLoaded = false;
SB._backups       = [];
SB._schedule      = {};
SB._dt            = null;

/* ── Refresh ─────────────────────────────────────────────────── */
SB.refresh = function() {
    SB.loadSchedule();
    SB.loadBackups();
};

/* ── Load schedule ───────────────────────────────────────────── */
SB.loadSchedule = function() {
    $.get(BASE + 'school_backup/get_schedule').done(function(r) {
        if (!r || r.status !== 'success') return;
        var s = r.schedule || {};
        SB._schedule = s;

        $('#schedEnabled').prop('checked', !!s.enabled);
        $('#schedRetention').val(s.retention || 7);

        // KPI status
        if (s.enabled) {
            $('#kpi-status').html('<span class="sb-dot sb-dot-on"></span>ON');
        } else {
            $('#kpi-status').html('<span class="sb-dot sb-dot-off"></span>OFF');
        }

        // Last run
        if (s.last_run) {
            $('#lastRunInfo').show();
            $('#lastRunTime').text(s.last_run);
        } else {
            $('#lastRunInfo').hide();
        }
    });
};

/* ── Save schedule ───────────────────────────────────────────── */
SB.saveSchedule = function() {
    var btn = $('#btnSaveSchedule');
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

    $.post(BASE + 'school_backup/save_schedule', {
        enabled:   $('#schedEnabled').is(':checked') ? 1 : 0,
        retention: $('#schedRetention').val()
    }).done(function(r) {
        if (r && r.status === 'success') {
            toast(r.message || 'Settings saved.', true);
            SB.loadSchedule();
        } else {
            toast(r && r.message || 'Save failed.', false);
        }
    }).fail(function() {
        toast('Network error.', false);
    }).always(function() {
        btn.prop('disabled', false).html('<i class="fa fa-check"></i> Save Settings');
    });
};

/* ── Load backups ────────────────────────────────────────────── */
SB.loadBackups = function(callback) {
    $.get(BASE + 'school_backup/get_backups').done(function(r) {
        if (!r || r.status !== 'success') return;
        SB._backups = r.backups || [];
        SB._historyLoaded = true;

        // KPIs
        $('#kpi-total').text(r.total || 0);
        $('#kpi-size').text(r.total_size || '0 B');
        $('#kpi-auto').text(r.scheduled || 0);
        $('#kpi-manual').text(r.manual || 0);

        SB.renderTable();
        SB.checkManualLimit();
        if (typeof callback === 'function') callback();
    });
};

/* ── Render table ────────────────────────────────────────────── */
SB.renderTable = function() {
    // Destroy existing DataTable if any
    if (SB._dt) {
        SB._dt.destroy();
        SB._dt = null;
    }

    var tbody = $('#backupTbody');
    var rows  = SB._backups;

    if (!rows.length) {
        tbody.html('<tr><td colspan="8" class="sb-empty"><i class="fa fa-inbox"></i> No backups yet. Create your first backup from the Manual Backup tab.</td></tr>');
        return;
    }

    var html = '';
    rows.forEach(function(b, i) {
        var typeClass = b.type === 'scheduled' ? 'sb-b-blue' : 'sb-b-teal';
        var fmtClass  = b.format === 'zip' ? 'sb-b-amber' : 'sb-b-gray';
        html += '<tr>' +
            '<td>' + (i + 1) + '</td>' +
            '<td><code style="font-size:11px;font-family:var(--font-m);color:var(--t2)">' + esc(b.backup_id) + '</code></td>' +
            '<td>' + esc(b.created_at || '') + '</td>' +
            '<td><span class="sb-badge ' + typeClass + '">' + esc(b.type || 'manual') + '</span></td>' +
            '<td><span class="sb-badge ' + fmtClass + '">' + esc(b.format || 'json') + '</span></td>' +
            '<td>' + esc(b.size_human || '-') + '</td>' +
            '<td>' + esc(b.created_by || '-') + '</td>' +
            '<td><a href="' + BASE + 'school_backup/download/' + encodeURIComponent(b.backup_id) + '" class="sb-btn sb-btn-s" style="padding:5px 12px;font-size:11.5px" title="Download"><i class="fa fa-download"></i> Download</a></td>' +
            '</tr>';
    });
    tbody.html(html);

    // Init DataTable
    SB._dt = new DataTable('#backupTable', {
        paging: true,
        searching: true,
        ordering: true,
        order: [[2, 'desc']],
        info: true,
        lengthMenu: [10, 25, 50],
        autoWidth: false,
        columnDefs: [
            { targets: 0, orderable: false, width: '30px' },
            { targets: -1, orderable: false, width: '100px' }
        ],
        layout: {
            topStart: {
                buttons: ['csv', 'print']
            }
        },
        language: { emptyTable: 'No backups found.' }
    });
};

/* ── Check manual limit ──────────────────────────────────────── */
SB.checkManualLimit = function() {
    var today  = SERVER_DATE;
    var count  = 0;
    (SB._backups || []).forEach(function(b) {
        if (b.type === 'manual' && (b.created_at || '').indexOf(today) === 0) count++;
    });

    if (count >= 1) {
        $('#manualWarn').show();
        $('#manualWarnMsg').text('You have already created a backup today. Manual backups are limited to 1 per day.');
        $('#btnCreateBackup').prop('disabled', true);
    } else {
        $('#manualWarn').hide();
        $('#btnCreateBackup').prop('disabled', false);
    }
};

/* ── Create backup ───────────────────────────────────────────── */
SB.createBackup = function() {
    var btn = $('#btnCreateBackup');
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating Backup...');
    $('#manualResult').hide();

    $.post(BASE + 'school_backup/create_backup', {action: 'create'}).done(function(r) {
        if (r && r.status === 'success') {
            toast(r.message || 'Backup created!', true);
            $('#manualResult').show();
            $('#manualResultMsg').html(
                'Backup <strong>' + esc(r.backup_id || '') + '</strong> created successfully (' + esc(r.size_human || '') + ').' +
                ' <a href="' + BASE + 'school_backup/download/' + encodeURIComponent(r.backup_id || '') + '" style="color:inherit;font-weight:700;text-decoration:underline">Download now</a>'
            );
            // Disable button immediately (just created one today)
            btn.prop('disabled', true);
            // Refresh lists — loadBackups will call checkManualLimit when done
            SB.loadBackups();
        } else {
            toast(r && r.message || 'Backup failed.', false);
            btn.prop('disabled', false);
        }
    }).fail(function() {
        toast('Network error. Please try again.', false);
        btn.prop('disabled', false);
    }).always(function() {
        btn.html('<i class="fa fa-cloud-upload"></i> Create Backup Now');
    });
};

/* ── Init ─────────────────────────────────────────────────────── */
SB.loadSchedule();
SB.loadBackups();

}); // DOMContentLoaded
</script>
