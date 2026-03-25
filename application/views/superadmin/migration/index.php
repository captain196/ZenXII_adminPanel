<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="mg-wrap">

    <!-- Top bar -->
    <div class="mg-topbar">
        <div>
            <h1 class="mg-title"><i class="fa fa-database"></i> Firebase Migration</h1>
            <ol class="mg-bread">
                <li><a href="<?= site_url('superadmin/dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li>Migration</li>
            </ol>
        </div>
        <div class="mg-topbar-right">
            <button class="mg-btn mg-btn-primary" id="btnAnalyze" onclick="runAnalysis()">
                <i class="fa fa-search"></i> Run Analysis
            </button>
            <button class="mg-btn mg-btn-ghost" id="btnLoad" onclick="loadReport()">
                <i class="fa fa-refresh"></i> Load Report
            </button>
            <button class="mg-btn mg-btn-danger mg-btn-sm" id="btnClear" onclick="clearMap()">
                <i class="fa fa-trash-o"></i> Clear Map
            </button>
        </div>
    </div>

    <!-- Info banner -->
    <div class="mg-info-banner">
        <i class="fa fa-info-circle"></i>
        <div>
            <strong>Phase 1 — Read-Only Analysis</strong><br>
            This tool scans all Firebase data sources to build a migration map. It does NOT modify any existing data.
            Only <code>System/Migration/</code> is written to. Review the report before proceeding to Phase 2.
        </div>
    </div>

    <!-- Summary cards -->
    <div class="mg-stats" id="statsRow" style="display:none;">
        <div class="mg-stat mg-stat-purple">
            <div class="mg-stat-icon"><i class="fa fa-university"></i></div>
            <div><div class="mg-stat-label">Total Schools</div><div class="mg-stat-val" id="statTotal">0</div></div>
        </div>
        <div class="mg-stat mg-stat-amber">
            <div class="mg-stat-icon"><i class="fa fa-exclamation-triangle"></i></div>
            <div><div class="mg-stat-label">Legacy (Need Migration)</div><div class="mg-stat-val" id="statLegacy">0</div></div>
        </div>
        <div class="mg-stat mg-stat-green">
            <div class="mg-stat-icon"><i class="fa fa-check-circle"></i></div>
            <div><div class="mg-stat-label">Already Migrated</div><div class="mg-stat-val" id="statMigrated">0</div></div>
        </div>
        <div class="mg-stat mg-stat-rose">
            <div class="mg-stat-icon"><i class="fa fa-warning"></i></div>
            <div><div class="mg-stat-label">Warnings</div><div class="mg-stat-val" id="statWarnings">0</div></div>
        </div>
    </div>

    <!-- Sources scanned -->
    <div class="mg-card" id="sourcesCard" style="display:none;">
        <div class="mg-card-head"><i class="fa fa-sitemap"></i><h3>Firebase Sources Scanned</h3></div>
        <div class="mg-card-body">
            <div class="mg-source-grid" id="sourcesGrid"></div>
        </div>
    </div>

    <!-- School Map Table -->
    <div class="mg-card" id="schoolMapCard" style="display:none;">
        <div class="mg-card-head">
            <div style="display:flex;align-items:center;gap:10px;">
                <i class="fa fa-list"></i><h3>School Migration Map</h3>
            </div>
            <div class="mg-card-head-right">
                <div class="mg-filter-pills">
                    <button class="mg-pill active" data-filter="all" onclick="filterSchools('all', this)">All</button>
                    <button class="mg-pill" data-filter="legacy" onclick="filterSchools('legacy', this)">Legacy</button>
                    <button class="mg-pill" data-filter="migrated_ready" onclick="filterSchools('migrated_ready', this)">Migrated</button>
                    <button class="mg-pill" data-filter="needs_review" onclick="filterSchools('needs_review', this)">Needs Review</button>
                </div>
            </div>
        </div>
        <div class="mg-card-body" style="padding:0;">
            <div class="mg-table-wrap">
                <table class="mg-table" id="schoolMapTable">
                    <thead>
                        <tr>
                            <th style="width:30px;">#</th>
                            <th>School Name</th>
                            <th>Firebase Key</th>
                            <th>School ID (Target)</th>
                            <th>School Code</th>
                            <th>Status</th>
                            <th>Data Locations</th>
                            <th>Sessions</th>
                            <th>Students</th>
                            <th>Actions Required</th>
                        </tr>
                    </thead>
                    <tbody id="schoolMapBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Warnings -->
    <div class="mg-card" id="warningsCard" style="display:none;">
        <div class="mg-card-head mg-card-head-amber"><i class="fa fa-exclamation-triangle"></i><h3>Warnings</h3></div>
        <div class="mg-card-body" style="padding:0;">
            <table class="mg-table">
                <thead><tr><th style="width:30px;">#</th><th>School</th><th>Warning</th></tr></thead>
                <tbody id="warningsBody"></tbody>
            </table>
        </div>
    </div>

    <!-- Orphaned Indexes -->
    <div class="mg-card" id="orphansCard" style="display:none;">
        <div class="mg-card-head mg-card-head-rose"><i class="fa fa-unlink"></i><h3>Orphaned References</h3></div>
        <div class="mg-card-body">
            <div id="orphanedCodesSection" style="display:none;">
                <h4 class="mg-section-title">Orphaned School_ids Entries</h4>
                <table class="mg-table"><thead><tr><th>Code</th><th>Points To</th></tr></thead>
                <tbody id="orphanedCodesBody"></tbody></table>
            </div>
            <div id="orphanedNamesSection" style="display:none;margin-top:16px;">
                <h4 class="mg-section-title">Orphaned School_names Entries</h4>
                <table class="mg-table"><thead><tr><th>Name Key</th><th>Points To</th></tr></thead>
                <tbody id="orphanedNamesBody"></tbody></table>
            </div>
            <div id="orphanedParentsSection" style="display:none;margin-top:16px;">
                <h4 class="mg-section-title">Orphaned Users/Parents Keys</h4>
                <p class="mg-muted">These Users/Parents/{key} nodes don't match any discovered school.</p>
                <div id="orphanedParentsList"></div>
            </div>
        </div>
    </div>

    <!-- School Detail Modal -->
    <div class="mg-modal-overlay" id="detailModal">
        <div class="mg-modal">
            <div class="mg-modal-head">
                <h3 id="modalTitle">School Detail</h3>
                <button class="mg-modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="mg-modal-body" id="modalBody"></div>
        </div>
    </div>

    <!-- Loading -->
    <div class="mg-loading" id="loadingOverlay">
        <div class="mg-spinner"></div>
        <p>Scanning Firebase structure...</p>
    </div>

</div>
</div>


<script>
var BASE = '<?= rtrim(base_url(), "/") ?>';
var CSRF = document.querySelector('meta[name="csrf-token"]').content;
var MG   = { schoolMap: {}, summary: null };

function saPost(url, data) {
    data = data || {};
    data.csrf_token = CSRF;
    return $.ajax({ url: BASE + url, type: 'POST', data: data, dataType: 'json' });
}

/* ── Run Analysis ── */
function runAnalysis() {
    $('#loadingOverlay').addClass('active');
    $('#btnAnalyze').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Analyzing...');
    saPost('/superadmin/migration/analyze')
        .done(function(r) {
            if (r.status === 'success') {
                showToast(r.message, 'success');
                loadReport();
            } else {
                showToast(r.message || 'Analysis failed.', 'error');
            }
        })
        .fail(function(xhr) {
            var msg = 'Analysis request failed.';
            try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
            showToast(msg, 'error');
        })
        .always(function() {
            $('#loadingOverlay').removeClass('active');
            $('#btnAnalyze').prop('disabled', false).html('<i class="fa fa-search"></i> Run Analysis');
        });
}

/* ── Load Report ── */
function loadReport() {
    $('#loadingOverlay').addClass('active');
    saPost('/superadmin/migration/get_report')
        .done(function(r) {
            if (r.status === 'success' && r.migration) {
                renderReport(r.migration);
            } else {
                showToast(r.message || 'No report found.', 'error');
            }
        })
        .fail(function() { showToast('Failed to load report.', 'error'); })
        .always(function() { $('#loadingOverlay').removeClass('active'); });
}

/* ── Clear Map ── */
function clearMap() {
    if (!confirm('Clear the migration map? You will need to re-run analysis.')) return;
    saPost('/superadmin/migration/clear_map')
        .done(function(r) {
            showToast(r.message || 'Cleared.', 'success');
            ['statsRow','sourcesCard','schoolMapCard','warningsCard','orphansCard'].forEach(function(id) {
                document.getElementById(id).style.display = 'none';
            });
        })
        .fail(function() { showToast('Failed to clear.', 'error'); });
}

/* ── Render Report ── */
function renderReport(data) {
    var s = data.summary || {};
    MG.summary = s;
    MG.schoolMap = data.school_map || {};

    // Stats
    $('#statTotal').text(s.total_schools || 0);
    $('#statLegacy').text(s.legacy_schools || 0);
    $('#statMigrated').text(s.migrated_schools || 0);
    $('#statWarnings').text(s.warnings_count || 0);
    $('#statsRow').show();

    // Sources
    var src = s.sources_scanned || {};
    var srcHtml = '';
    $.each(src, function(path, count) {
        srcHtml += '<div class="mg-source-item"><span class="mg-source-path">' + esc(path) + '</span>'
                 + '<span class="mg-source-count">' + count + ' keys</span></div>';
    });
    srcHtml += '<div class="mg-source-item"><span class="mg-source-path">Analyzed at</span>'
             + '<span class="mg-source-count">' + esc(s.analyzed_at || '?') + ' (' + (s.elapsed_ms || 0) + 'ms)</span></div>';
    $('#sourcesGrid').html(srcHtml);
    $('#sourcesCard').show();

    // School map table
    var map = data.school_map || {};
    var rows = '';
    var idx = 0;
    $.each(map, function(sid, school) {
        idx++;
        var statusBadge = statusBadgeHtml(school.status);
        var sessCount = (school.academic_sessions || []).length;
        var locCount  = (school.data_locations || []).length;
        var actCount  = (school.migration_actions || []).length;
        var newTag    = school.school_id_is_new ? ' <span class="mg-tag mg-tag-amber">NEW</span>' : '';

        rows += '<tr data-status="' + esc(school.status) + '" onclick="showDetail(\'' + esc(sid) + '\')" style="cursor:pointer;">'
             + '<td>' + idx + '</td>'
             + '<td><strong>' + esc(school.school_name || '?') + '</strong></td>'
             + '<td><code>' + esc(school.firebase_key) + '</code></td>'
             + '<td><code>' + esc(school.school_id) + '</code>' + newTag + '</td>'
             + '<td>' + esc(school.school_code || '—') + (school.school_code_is_generated ? ' <span class="mg-tag mg-tag-amber">GEN</span>' : '') + '</td>'
             + '<td>' + statusBadge + '</td>'
             + '<td><span class="mg-count">' + locCount + '</span></td>'
             + '<td><span class="mg-count">' + sessCount + '</span></td>'
             + '<td>' + (school.has_students ? '<i class="fa fa-check" style="color:var(--green);"></i>' : '<i class="fa fa-minus" style="color:var(--t4);"></i>') + '</td>'
             + '<td><span class="mg-count mg-count-purple">' + actCount + '</span></td>'
             + '</tr>';
    });
    $('#schoolMapBody').html(rows);
    $('#schoolMapCard').show();

    // Warnings
    var warns = data.warnings || [];
    if (warns.length > 0) {
        var wRows = '';
        warns.forEach(function(w, i) {
            wRows += '<tr><td>' + (i+1) + '</td><td>' + esc(w.school) + '</td><td>' + esc(w.warning) + '</td></tr>';
        });
        $('#warningsBody').html(wRows);
        $('#warningsCard').show();
    }

    // Orphans
    var hasOrphans = false;
    var oCodes = data.orphaned_codes || [];
    if (oCodes.length > 0) {
        var oRows = '';
        oCodes.forEach(function(o) { oRows += '<tr><td><code>' + esc(o.code) + '</code></td><td>' + esc(o.points_to) + '</td></tr>'; });
        $('#orphanedCodesBody').html(oRows);
        $('#orphanedCodesSection').show();
        hasOrphans = true;
    }
    var oNames = data.orphaned_names || [];
    if (oNames.length > 0) {
        var nRows = '';
        oNames.forEach(function(o) { nRows += '<tr><td><code>' + esc(o.name_key) + '</code></td><td>' + esc(o.points_to) + '</td></tr>'; });
        $('#orphanedNamesBody').html(nRows);
        $('#orphanedNamesSection').show();
        hasOrphans = true;
    }
    var oParents = data.orphaned_parents || [];
    if (oParents.length > 0) {
        var pHtml = '';
        oParents.forEach(function(p) { pHtml += '<span class="mg-tag mg-tag-rose">' + esc(p) + '</span> '; });
        $('#orphanedParentsList').html(pHtml);
        $('#orphanedParentsSection').show();
        hasOrphans = true;
    }
    if (hasOrphans) $('#orphansCard').show();
}

/* ── Filter ── */
function filterSchools(status, btn) {
    $('.mg-pill').removeClass('active');
    $(btn).addClass('active');
    $('#schoolMapBody tr').each(function() {
        var rowStatus = $(this).data('status');
        $(this).toggle(status === 'all' || rowStatus === status);
    });
}

/* ── Detail Modal ── */
function showDetail(sid) {
    var school = MG.schoolMap[sid];
    if (!school) return;
    $('#modalTitle').text(school.school_name || sid);

    var html = '<div class="mg-detail-grid">';

    // Identity
    html += section('Identity', [
        row('Firebase Key', '<code>' + esc(school.firebase_key) + '</code>'),
        row('Target School ID', '<code>' + esc(school.school_id) + '</code>' + (school.school_id_is_new ? ' <span class="mg-tag mg-tag-amber">NEW (will be generated)</span>' : '')),
        row('School Code', esc(school.school_code || '—') + (school.school_code_is_generated ? ' <span class="mg-tag mg-tag-amber">AUTO-GENERATED</span>' : '')),
        row('Legacy Key', school.legacy_key ? '<code>' + esc(school.legacy_key) + '</code>' : '—'),
        row('Status', statusBadgeHtml(school.status)),
    ]);

    // Data locations
    var locs = (school.data_locations || []).map(function(l) { return '<div><code>' + esc(l) + '</code></div>'; }).join('');
    html += section('Data Locations Found', [locs || '<span class="mg-muted">None</span>']);

    // Profile fields (shows fragmentation)
    var fields = school.profile_fields || {};
    var fHtml = '';
    $.each(fields, function(path, val) {
        var valStr = typeof val === 'string' ? val : JSON.stringify(val);
        if (valStr.length > 80) valStr = valStr.substring(0, 80) + '...';
        fHtml += '<tr><td><code style="font-size:10px;">' + esc(path) + '</code></td><td style="font-size:11px;">' + esc(valStr) + '</td></tr>';
    });
    if (fHtml) {
        html += section('Profile Fields (all sources)', [
            '<table class="mg-table mg-table-sm"><thead><tr><th>Source Path</th><th>Value</th></tr></thead><tbody>' + fHtml + '</tbody></table>'
        ]);
    }

    // Subscription
    var sub = school.subscription || {};
    if (sub.status) {
        html += section('Subscription', [
            row('Location', '<code>' + esc(sub.location || '?') + '</code>'),
            row('Status', esc(sub.status)),
            row('Plan', esc(sub.plan || '?')),
            row('Expiry', esc(sub.expiry || '?')),
        ]);
    }

    // Academic sessions
    var sess = school.academic_sessions || [];
    if (sess.length > 0) {
        html += section('Academic Sessions', [
            sess.map(function(s) { return '<span class="mg-tag">' + esc(s) + '</span>'; }).join(' ')
        ]);
    }

    // Capabilities
    html += section('Capabilities', [
        row('Has Config', school.has_config ? '<i class="fa fa-check" style="color:var(--green);"></i> Yes' : 'No'),
        row('Has Students', school.has_students ? '<i class="fa fa-check" style="color:var(--green);"></i> Yes' : 'No'),
        row('Has Admin', school.has_admin ? '<i class="fa fa-check" style="color:var(--green);"></i> Yes' : 'No'),
    ]);

    // Migration actions
    var actions = school.migration_actions || [];
    if (actions.length > 0) {
        var aHtml = '<ol class="mg-action-list">';
        actions.forEach(function(a) { aHtml += '<li>' + esc(a) + '</li>'; });
        aHtml += '</ol>';
        html += section('Migration Actions Required', [aHtml]);
    }

    // Warnings
    var warns = school.warnings || [];
    if (warns.length > 0) {
        var wHtml = warns.map(function(w) { return '<div class="mg-warn-item"><i class="fa fa-exclamation-triangle"></i> ' + esc(w) + '</div>'; }).join('');
        html += section('Warnings', [wHtml]);
    }

    html += '</div>';
    $('#modalBody').html(html);
    $('#detailModal').addClass('open');
}

function closeModal() {
    $('#detailModal').removeClass('open');
}
$('#detailModal').on('click', function(e) { if (e.target === this) closeModal(); });

/* ── Helpers ── */
function esc(s) { return $('<span>').text(s || '').html(); }

function statusBadgeHtml(status) {
    var cls = 'mg-badge-gray';
    var label = status || 'unknown';
    if (status === 'legacy') { cls = 'mg-badge-amber'; label = 'Legacy'; }
    else if (status === 'migrated_ready') { cls = 'mg-badge-green'; label = 'Migrated'; }
    else if (status === 'needs_review') { cls = 'mg-badge-rose'; label = 'Needs Review'; }
    return '<span class="mg-badge ' + cls + '">' + label + '</span>';
}

function section(title, contentArr) {
    return '<div class="mg-detail-section"><h4>' + title + '</h4>' + contentArr.join('') + '</div>';
}
function row(label, value) {
    return '<div class="mg-detail-row"><span class="mg-detail-label">' + label + '</span><span>' + value + '</span></div>';
}

function showToast(msg, type) {
    var el = $('<div class="mg-toast mg-toast-' + (type || 'info') + '">' + esc(msg) + '</div>');
    $('#toastWrap').append(el);
    setTimeout(function() { el.addClass('mg-toast-hide'); setTimeout(function() { el.remove(); }, 300); }, 4000);
}
</script>

<div id="toastWrap" class="mg-toast-wrap"></div>


<style>
/* ── Migration Dashboard ── */
.mg-wrap { padding: 20px 24px; min-height: 100vh; }

.mg-topbar { display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px; }
.mg-title { font-size:1.3rem;font-weight:700;color:var(--t1);margin:0 0 4px;display:flex;align-items:center;gap:8px; }
.mg-title i { color:var(--sa); }
.mg-bread { list-style:none;padding:0;margin:0;display:flex;gap:6px;font-size:.73rem;color:var(--t3); }
.mg-bread li:not(:last-child)::after { content:'/';margin-left:6px; }
.mg-bread a { color:var(--sa);text-decoration:none; }
.mg-topbar-right { display:flex;gap:8px;align-items:center;flex-wrap:wrap; }

/* Buttons */
.mg-btn { display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--r-sm);font-size:.8rem;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);text-decoration:none; }
.mg-btn-primary { background:var(--sa);color:#fff; }
.mg-btn-primary:hover { background:var(--sa2); }
.mg-btn-ghost { background:var(--bg2);color:var(--t1);border:1.5px solid var(--border); }
.mg-btn-ghost:hover { border-color:var(--sa);color:var(--sa); }
.mg-btn-danger { background:var(--rose);color:#fff; }
.mg-btn-danger:hover { background:#b91c1c; }
.mg-btn-sm { padding:6px 12px;font-size:.73rem; }
.mg-btn:disabled { opacity:.5;cursor:not-allowed; }

/* Info banner */
.mg-info-banner { display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:var(--r);background:var(--sa-dim);border:1px solid var(--sa-ring);color:var(--t1);font-size:.8rem;margin-bottom:20px; }
.mg-info-banner i { color:var(--sa);font-size:18px;margin-top:2px;flex-shrink:0; }
.mg-info-banner code { background:var(--bg3);padding:1px 5px;border-radius:3px;font-size:.73rem; }

/* Stats */
.mg-stats { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px; }
.mg-stat { background:var(--bg2);border-radius:var(--r);padding:16px 18px;display:flex;align-items:center;gap:14px;box-shadow:var(--sh);border-left:4px solid transparent; }
.mg-stat-purple { border-left-color:var(--sa); }
.mg-stat-amber { border-left-color:var(--amber); }
.mg-stat-green { border-left-color:var(--green); }
.mg-stat-rose { border-left-color:var(--rose); }
.mg-stat-icon { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0; }
.mg-stat-purple .mg-stat-icon { background:var(--sa-dim);color:var(--sa); }
.mg-stat-amber .mg-stat-icon { background:var(--amber-dim);color:var(--amber); }
.mg-stat-green .mg-stat-icon { background:var(--green-dim);color:var(--green); }
.mg-stat-rose .mg-stat-icon { background:var(--rose-dim);color:var(--rose); }
.mg-stat-label { font-size:.68rem;color:var(--t3);font-weight:600;text-transform:uppercase;letter-spacing:.5px; }
.mg-stat-val { font-size:1.25rem;font-weight:800;color:var(--t1);line-height:1.2; }

/* Cards */
.mg-card { background:var(--bg2);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:20px;overflow:hidden; }
.mg-card-head { display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1.5px solid var(--border);flex-wrap:wrap; }
.mg-card-head h3 { margin:0;font-size:.85rem;font-weight:700;color:var(--t1); }
.mg-card-head i { color:var(--sa);font-size:15px; }
.mg-card-head-amber { background:var(--amber-dim); }
.mg-card-head-amber i { color:var(--amber); }
.mg-card-head-rose { background:var(--rose-dim); }
.mg-card-head-rose i { color:var(--rose); }
.mg-card-head-right { margin-left:auto;display:flex;gap:8px;align-items:center; }
.mg-card-body { padding:18px; }

/* Source grid */
.mg-source-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px; }
.mg-source-item { display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:var(--bg3);border-radius:var(--r-sm);border:1px solid var(--border); }
.mg-source-path { font-size:.78rem;font-weight:600;color:var(--t1);font-family:'JetBrains Mono',monospace; }
.mg-source-count { font-size:.73rem;font-weight:700;color:var(--sa);background:var(--sa-dim);padding:2px 8px;border-radius:10px; }

/* Table */
.mg-table-wrap { overflow-x:auto; }
.mg-table { width:100%;border-collapse:collapse; }
.mg-table th { padding:8px 10px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);background:var(--bg3);border-bottom:1.5px solid var(--border);text-align:left;white-space:nowrap; }
.mg-table td { padding:8px 10px;font-size:.8rem;color:var(--t1);border-bottom:1px solid var(--border);vertical-align:middle; }
.mg-table tbody tr:hover { background:var(--sa-dim); }
.mg-table code { font-size:.73rem;background:var(--bg3);padding:1px 5px;border-radius:3px;color:var(--sa); }
.mg-table-sm th,.mg-table-sm td { padding:5px 8px;font-size:.73rem; }

/* Badges */
.mg-badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:.68rem;font-weight:700; }
.mg-badge-green { background:var(--green-dim);color:var(--green); }
.mg-badge-amber { background:var(--amber-dim);color:var(--amber); }
.mg-badge-rose { background:var(--rose-dim);color:var(--rose); }
.mg-badge-gray { background:var(--bg3);color:var(--t3); }

.mg-tag { display:inline-block;padding:2px 8px;border-radius:10px;font-size:.65rem;font-weight:700;background:var(--bg3);color:var(--t2); }
.mg-tag-amber { background:var(--amber-dim);color:var(--amber); }
.mg-tag-rose { background:var(--rose-dim);color:var(--rose); }

.mg-count { display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:22px;padding:0 6px;border-radius:11px;font-size:.73rem;font-weight:700;background:var(--bg3);color:var(--t2); }
.mg-count-purple { background:var(--sa-dim);color:var(--sa); }

/* Filter pills */
.mg-filter-pills { display:flex;gap:4px;background:var(--bg3);border-radius:8px;padding:3px; }
.mg-pill { padding:5px 12px;border-radius:6px;font-size:.73rem;font-weight:600;cursor:pointer;border:none;background:transparent;color:var(--t3);transition:all var(--ease); }
.mg-pill.active { background:var(--bg2);color:var(--t1);box-shadow:0 1px 4px rgba(0,0,0,.1); }

/* Section titles */
.mg-section-title { font-size:.8rem;font-weight:700;color:var(--t1);margin:0 0 10px; }
.mg-muted { font-size:.78rem;color:var(--t3); }

/* Modal */
.mg-modal-overlay { display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:2000;align-items:center;justify-content:center;padding:20px; }
.mg-modal-overlay.open { display:flex; }
.mg-modal { background:var(--bg2);border-radius:var(--r);width:100%;max-width:720px;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3); }
.mg-modal-head { display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1.5px solid var(--border); }
.mg-modal-head h3 { margin:0;font-size:.95rem;font-weight:700;color:var(--t1); }
.mg-modal-close { background:none;border:none;font-size:22px;color:var(--t3);cursor:pointer;line-height:1; }
.mg-modal-close:hover { color:var(--rose); }
.mg-modal-body { padding:20px; }

/* Detail sections */
.mg-detail-section { margin-bottom:18px; }
.mg-detail-section h4 { font-size:.78rem;font-weight:700;color:var(--sa);text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px;padding-bottom:6px;border-bottom:1px solid var(--border); }
.mg-detail-row { display:flex;justify-content:space-between;align-items:center;padding:5px 0;font-size:.8rem;border-bottom:1px dashed var(--border); }
.mg-detail-row:last-child { border-bottom:none; }
.mg-detail-label { font-weight:600;color:var(--t3);font-size:.73rem; }
.mg-action-list { margin:0;padding-left:20px; }
.mg-action-list li { font-size:.78rem;color:var(--t1);margin-bottom:4px;line-height:1.4; }
.mg-warn-item { font-size:.78rem;color:var(--amber);padding:4px 0;display:flex;align-items:center;gap:6px; }

/* Loading */
.mg-loading { display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:3000;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:#fff; }
.mg-loading.active { display:flex; }
.mg-loading p { font-size:.85rem;font-weight:600; }
.mg-spinner { width:40px;height:40px;border:4px solid rgba(255,255,255,.2);border-top-color:var(--sa3);border-radius:50%;animation:mgSpin .7s linear infinite; }
@keyframes mgSpin { to { transform:rotate(360deg); } }

/* Toast */
.mg-toast-wrap { position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:8px; }
.mg-toast { padding:12px 18px;border-radius:8px;font-size:.8rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.15);animation:mgToastIn .25s ease;min-width:240px;max-width:500px;word-break:break-word; }
@keyframes mgToastIn { from{transform:translateX(60px);opacity:0;}to{transform:translateX(0);opacity:1;} }
.mg-toast-success { background:var(--green-dim);color:var(--green);border-left:4px solid var(--green); }
.mg-toast-error { background:var(--rose-dim);color:var(--rose);border-left:4px solid var(--rose); }
.mg-toast-info { background:var(--sa-dim);color:var(--sa);border-left:4px solid var(--sa); }
.mg-toast-hide { animation:mgToastOut .3s ease forwards; }
@keyframes mgToastOut { to{transform:translateX(60px);opacity:0;} }
</style>
