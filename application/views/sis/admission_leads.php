<?php defined('BASEPATH') or exit('No direct script access allowed');
// LEAD SYSTEM — Admission leads list view
$esc = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
?>

<style>
/* Layout */
.leads-wrap { padding:20px 24px; max-width:1500px; margin:0 auto; }

/* Header */
.leads-hdr {
    display:flex; align-items:flex-start; justify-content:space-between;
    margin-bottom:18px; gap:16px; flex-wrap:wrap;
}
.leads-hdr-left h1 {
    font-size:1.5rem; color:var(--t1); font-family:var(--font-b);
    margin:0 0 4px 0; display:flex; align-items:center; gap:10px;
}
.leads-hdr-left h1 i { color:var(--gold); font-size:1.25rem; }
.leads-hdr-left .leads-sub { font-size:12px; color:var(--t3); margin:0; }
.leads-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.leads-toolbar input, .leads-toolbar select {
    padding:7px 10px; border:1px solid var(--border); border-radius:6px; font-size:13px;
    font-family:var(--font-b); background:var(--bg2); color:var(--t1); outline:none;
    transition:border-color .15s;
}
.leads-toolbar input { min-width:220px; }
.leads-toolbar input:focus, .leads-toolbar select:focus { border-color:var(--gold); }

/* Stats funnel — 6 fixed slots in a grid so nothing overflows or gets clipped */
.leads-stats {
    display:grid;
    grid-template-columns:repeat(6, minmax(0, 1fr));
    gap:10px; margin-bottom:20px;
}
.leads-stat {
    padding:14px 16px; background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; text-align:center;
    transition:transform .15s, border-color .15s;
}
.leads-stat:hover { transform:translateY(-1px); border-color:var(--gold-dim); }
.leads-stat.is-total { border-color:var(--gold); background:linear-gradient(180deg, var(--gold-dim) 0%, var(--bg2) 70%); }
.leads-stat .num { font-size:1.6rem; font-weight:700; color:var(--t1); line-height:1.1; }
.leads-stat .lbl {
    font-size:10px; color:var(--t3); text-transform:uppercase;
    letter-spacing:.4px; margin-top:4px; font-weight:600;
}
.leads-stat.is-total .num { color:var(--gold); }

/* Table */
.leads-table-wrap {
    background:var(--bg2); border:1px solid var(--border); border-radius:10px;
    overflow:hidden;
}
.leads-tbl { width:100%; border-collapse:collapse; }
.leads-tbl th {
    padding:11px 14px; background:var(--bg3); font-size:10px; color:var(--t3);
    text-transform:uppercase; letter-spacing:.5px; text-align:left; font-weight:700;
    border-bottom:1px solid var(--border);
}
.leads-tbl td {
    padding:12px 14px; font-size:13px; border-top:1px solid var(--border); color:var(--t1);
    vertical-align:middle;
}
.leads-tbl tbody tr:hover td { background:var(--gold-dim); }
.leads-tbl .name-cell { font-weight:600; }
.leads-tbl .name-cell .lead-id {
    display:block; font-size:10px; color:var(--t3); font-weight:500;
    margin-top:2px; font-family:ui-monospace, "Courier New", monospace;
}

/* Status badges — every status known to the controller gets a colour.
   `pending` is a legacy alias that historic rows still carry; we render
   it with its own pill so the row never shows an unstyled status. */
.badge-status {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700;
    text-transform:uppercase; letter-spacing:.3px;
    background:#e5e7eb; color:#374151;
}
.badge-status::before {
    content:''; display:inline-block; width:6px; height:6px; border-radius:50%;
    background:currentColor; flex-shrink:0;
}
.badge-new        { background:#dbeafe; color:#1e40af; }
.badge-pending    { background:#fde68a; color:#92400e; }
.badge-contacted  { background:#fef3c7; color:#92400e; }
.badge-interested { background:#ede9fe; color:#5b21b6; }
.badge-approved   { background:#dcfce7; color:#166534; }
.badge-enrolled,
.badge-admitted   { background:#d1fae5; color:#065f46; }
.badge-rejected   { background:#fee2e2; color:#991b1b; }

/* Buttons */
.btn-sm {
    padding:5px 10px; border:none; border-radius:5px; font-size:12px; cursor:pointer;
    font-family:var(--font-b); font-weight:600; transition:background .15s, color .15s;
    text-decoration:none; display:inline-flex; align-items:center; gap:4px;
}
.btn-view  { background:var(--gold-dim); color:var(--gold); }
.btn-view:hover { background:var(--gold); color:#fff; }
.btn-convert { background:#dcfce7; color:#166534; }
.btn-convert:hover { background:#166534; color:#fff; }
.btn-primary {
    background:var(--gold); color:#fff; padding:8px 14px; border:none;
    border-radius:6px; font-size:13px; cursor:pointer; font-weight:600;
    text-decoration:none; display:inline-flex; align-items:center; gap:6px;
}
.btn-primary:hover { background:var(--gold2); }

/* Empty / loading */
.leads-empty { text-align:center; padding:48px 24px; color:var(--t3); font-size:14px; }
.leads-empty i { font-size:2.5rem; margin-bottom:12px; display:block; color:var(--border); }

/* Detail Modal */
.lead-modal-bg {
    display:none; position:fixed; top:0; left:0; width:100%; height:100%;
    background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center;
}
.lead-modal-bg.active { display:flex; }
.lead-modal {
    background:var(--bg2); border-radius:12px; width:90%; max-width:620px;
    max-height:85vh; overflow-y:auto; padding:24px 26px; position:relative;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.lead-modal-close {
    position:absolute; top:12px; right:14px; background:none; border:none;
    font-size:22px; cursor:pointer; color:var(--t3); padding:4px;
}
.lead-modal h2 {
    font-size:16px; font-weight:700; margin:0 0 16px 0; color:var(--t1);
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
}
.lead-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
.lead-detail-item { margin-bottom:4px; }
.lead-detail-item .ld-lbl {
    font-size:10px; text-transform:uppercase; color:var(--t3);
    letter-spacing:.4px; font-weight:700;
}
.lead-detail-item .ld-val { font-size:13px; color:var(--t1); margin-top:2px; }
.lead-detail-full { grid-column:1/-1; }
.lead-actions {
    display:flex; gap:10px; margin-top:18px; padding-top:14px;
    border-top:1px solid var(--border); flex-wrap:wrap; align-items:center;
}

/* Responsive */
@media(max-width:1100px) { .leads-stats { grid-template-columns:repeat(3, 1fr); } }
@media(max-width:768px) {
    .leads-wrap { padding:14px; }
    .leads-stats { grid-template-columns:repeat(2, 1fr); }
    .leads-tbl { font-size:12px; }
    .leads-tbl th, .leads-tbl td { padding:9px 10px; }
    .lead-detail-grid { grid-template-columns:1fr; }
    .leads-toolbar input { min-width:0; flex:1; }
}
</style>

<div class="content-wrapper">
<div class="leads-wrap">

    <!-- View switcher (IA reorg 2026-07): Funnel is a view of Applications. -->
    <div class="ac-viewsw" style="display:inline-flex;gap:4px;background:var(--bg3,#f1f0ed);border:1px solid var(--border,#e6e2dc);border-radius:10px;padding:4px;margin-bottom:16px;flex-wrap:wrap;">
        <a href="<?= base_url('sis/applications') ?>" style="display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;font-size:13px;font-weight:600;color:var(--t2,#5b524b);text-decoration:none;"><i class="fa fa-table"></i> Table</a>
        <a href="<?= base_url('sis/pipeline') ?>" style="display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;font-size:13px;font-weight:600;color:var(--t2,#5b524b);text-decoration:none;"><i class="fa fa-columns"></i> Board</a>
        <span style="display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;font-size:13px;font-weight:600;background:var(--card,#fff);color:var(--gold,#BC5A3C);box-shadow:0 1px 2px rgba(0,0,0,.07);"><i class="fa fa-filter"></i> Funnel</span>
    </div>

    <div class="leads-hdr">
        <div class="leads-hdr-left">
            <h1><i class="fa fa-filter"></i>Applications — Funnel</h1>
            <p class="leads-sub">Public-form applications and walk-in inquiries for session <?= $esc($session_year ?? '') ?></p>
        </div>
        <div class="leads-toolbar">
            <input type="text" id="searchLeads" placeholder="Search name, phone, or ID…">
            <select id="filterStatus">
                <option value="">All Status</option>
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="approved">Approved</option>
                <option value="enrolled">Enrolled</option>
                <option value="rejected">Rejected</option>
            </select>
            <select id="filterSource">
                <option value="">All Sources</option>
                <option value="public_form">Public Form</option>
                <option value="manual">Manual Entry</option>
            </select>
        </div>
    </div>

    <div class="leads-stats" id="leadStats"></div>

    <div class="leads-table-wrap">
        <table class="leads-tbl">
            <thead>
                <tr>
                    <th>Student / ID</th>
                    <th>Parent / Phone</th>
                    <th>Class</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody id="leadsBody">
                <tr><td colspan="7" class="leads-empty"><i class="fa fa-spinner fa-spin"></i>Loading leads…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Lead Detail Modal -->
<div class="lead-modal-bg" id="leadModal">
    <div class="lead-modal">
        <button class="lead-modal-close" onclick="closeLeadModal()">&times;</button>
        <h2 id="modalTitle">Lead Details</h2>
        <div id="modalBody"></div>
        <div class="lead-actions" id="modalActions"></div>
    </div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;
var allLeads  = [];

/* Normalize legacy / inconsistent status strings to a canonical bucket
   for COUNTING and FILTERING (not for display). `pending` is legacy
   data — it pre-dates the canonical status set and behaves like `new`.
   `admitted` is the older spelling of `enrolled`. Display still uses
   the raw status so the operator sees exactly what's in the data. */
function normalizeStatus(s) {
    s = (s || 'new').toString().toLowerCase().trim();
    if (s === 'pending' || s === '') return 'new';
    if (s === 'admitted') return 'enrolled';
    if (s === 'interested') return 'contacted';
    return s;
}

function loadLeads() {
    fetch('<?= base_url("sis/fetch_leads") ?>', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            allLeads = data.leads || [];
            renderStats();
            renderTable();
        }
        else { document.getElementById('leadsBody').innerHTML = '<tr><td colspan="7" class="leads-empty"><i class="fa fa-exclamation-triangle"></i> ' + String(data.message || 'Could not load leads.').replace(/</g,'&lt;') + '</td></tr>'; }
    })
    .catch(function() {
        document.getElementById('leadsBody').innerHTML = '<tr><td colspan="7" class="leads-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load leads.</td></tr>';
    });
}

function renderStats() {
    /* Six-card funnel covers every status the controller can write.
       `interested` folds into `contacted` and `admitted` folds into
       `enrolled` so the same outcome never gets split across two cards. */
    var c = { total:0, 'new':0, contacted:0, approved:0, enrolled:0, rejected:0 };
    allLeads.forEach(function(l) {
        c.total++;
        var s = normalizeStatus(l.status);
        if (c[s] !== undefined) c[s]++;
    });
    var cards = [
        ['total','Total','is-total'], ['new','New',''], ['contacted','Contacted',''],
        ['approved','Approved',''], ['enrolled','Enrolled',''], ['rejected','Rejected','']
    ];
    document.getElementById('leadStats').innerHTML = cards.map(function(card) {
        return '<div class="leads-stat ' + card[2] + '"><div class="num">' + c[card[0]] + '</div><div class="lbl">' + card[1] + '</div></div>';
    }).join('');
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

/* Badge class derived from the RAW status, so a legacy `pending` row
   still gets its own pill colour (see .badge-pending). Falls back to
   the neutral grey defined on `.badge-status` for any unknown value. */
function badgeClass(status) {
    return 'badge-status badge-' + (status || 'new').toString().toLowerCase().trim();
}

function renderTable() {
    var search = (document.getElementById('searchLeads').value || '').toLowerCase();
    var fStatus = document.getElementById('filterStatus').value;
    var fSource = document.getElementById('filterSource').value;

    var filtered = allLeads.filter(function(l) {
        if (search && (l.student_name || '').toLowerCase().indexOf(search) < 0 &&
            (l.phone || '').indexOf(search) < 0 &&
            (l.parent_name || '').toLowerCase().indexOf(search) < 0 &&
            (l.id || '').toLowerCase().indexOf(search) < 0) return false;
        // Filter uses normalized status so picking "New" also surfaces legacy "pending" rows.
        if (fStatus && normalizeStatus(l.status) !== fStatus) return false;
        if (fSource && (l.source || 'manual') !== fSource) return false;
        return true;
    });

    if (filtered.length === 0) {
        document.getElementById('leadsBody').innerHTML = '<tr><td colspan="7" class="leads-empty"><i class="fa fa-inbox"></i>No leads match your filters.</td></tr>';
        return;
    }

    var html = '';
    filtered.forEach(function(l) {
        var rawStatus = (l.status || 'new').toString().toLowerCase().trim();
        var normalized = normalizeStatus(l.status);
        var isClosed = normalized === 'enrolled' || normalized === 'rejected';
        var convertBtn = isClosed ? ''
            : '<a href="<?= base_url("sis/studentAdmission") ?>?lead_id=' + encodeURIComponent(l.id) + '" class="btn-sm btn-convert" title="Convert to Student"><i class="fa fa-user-plus"></i> Convert</a>';
        html += '<tr>' +
            '<td class="name-cell">' + esc(l.student_name) + '<span class="lead-id">' + esc(l.id) + '</span></td>' +
            '<td>' + esc(l.parent_name || l.father_name || '—') + '<br><small style="color:var(--t3);">' + esc(l.phone) + '</small></td>' +
            '<td>' + esc(l['class'] || '—') + '</td>' +
            '<td><small>' + esc(l.source === 'public_form' ? 'Public' : 'CRM') + '</small></td>' +
            '<td><span class="' + badgeClass(rawStatus) + '">' + esc(rawStatus) + '</span></td>' +
            '<td><small>' + esc((l.created_at || '').substring(0, 10)) + '</small></td>' +
            '<td style="text-align:right;white-space:nowrap;">' +
                '<button class="btn-sm btn-view" onclick="viewLead(\'' + esc(l.id) + '\')" title="View details"><i class="fa fa-eye"></i></button> ' +
                convertBtn +
            '</td></tr>';
    });
    document.getElementById('leadsBody').innerHTML = html;
}

function viewLead(leadId) {
    fetch('<?= base_url("sis/admission_lead") ?>?lead_id=' + encodeURIComponent(leadId), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status !== 'success' || !data.lead) { alert('Lead not found.'); return; }
        var L = data.lead;
        var rawStatus = (L.status || 'new').toString().toLowerCase().trim();
        var normalized = normalizeStatus(L.status);
        var isClosed = normalized === 'enrolled';
        document.getElementById('modalTitle').innerHTML = '<i class="fa fa-user" style="color:var(--gold);"></i>' + esc(L.student_name || 'Lead') + ' <span class="' + badgeClass(rawStatus) + '" style="font-size:10px;">' + esc(rawStatus) + '</span>';

        var body = '<div class="lead-detail-grid">';
        var fields = [
            ['Student Name', L.student_name], ['Application ID', L.id],
            ['Parent Name', L.parent_name || L.father_name], ['Phone', L.phone],
            ['Email', L.email], ['Class', L['class']],
            ['Section', L.section], ['Gender', L.gender],
            ['DOB', L.dob], ['Religion', L.religion],
            ['Source', L.source === 'public_form' ? 'Public Form' : 'CRM Entry'],
            ['Created', L.created_at],
        ];
        fields.forEach(function(f) {
            if (f[1]) body += '<div class="lead-detail-item"><div class="ld-lbl">' + f[0] + '</div><div class="ld-val">' + esc(f[1]) + '</div></div>';
        });
        if (L.notes) body += '<div class="lead-detail-item lead-detail-full"><div class="ld-lbl">Notes</div><div class="ld-val">' + esc(L.notes) + '</div></div>';
        if (L.address || L.city) body += '<div class="lead-detail-item lead-detail-full"><div class="ld-lbl">Address</div><div class="ld-val">' + esc([L.address, L.city, L.state, L.pincode].filter(Boolean).join(', ')) + '</div></div>';

        // History timeline
        if (L.history && L.history.length) {
            body += '<div class="lead-detail-item lead-detail-full" style="margin-top:12px;"><div class="ld-lbl">History</div>';
            L.history.forEach(function(h) {
                body += '<div style="font-size:12px;color:var(--t2);margin-top:4px;"><span style="color:var(--t3);">' + esc(h.timestamp || '') + '</span> — ' + esc(h.action) + ' <em style="color:var(--t3);">(' + esc(h.by || 'System') + ')</em></div>';
            });
            body += '</div>';
        }
        body += '</div>';
        document.getElementById('modalBody').innerHTML = body;

        // Actions
        var actions = '';
        if (!isClosed) {
            actions += '<a href="<?= base_url("sis/studentAdmission") ?>?lead_id=' + encodeURIComponent(L.id) + '" class="btn-primary"><i class="fa fa-user-plus"></i>Convert to Student</a>';
            // Pre-select the closest canonical bucket so the legacy `pending` /
            // `admitted` rows surface a sensible default in the dropdown.
            var canonicalForDropdown = ['new','contacted','interested','approved','rejected'].indexOf(rawStatus) >= 0 ? rawStatus : normalized;
            actions += '<select id="statusSelect" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;background:var(--bg2);color:var(--t1);">' +
                '<option value="">Change Status…</option>' +
                ['new','contacted','interested','approved','rejected'].map(function(s) { return '<option value="' + s + '"' + (s === canonicalForDropdown ? ' selected' : '') + '>' + s.charAt(0).toUpperCase() + s.slice(1) + '</option>'; }).join('') +
                '</select>';
            actions += '<button class="btn-primary" style="background:var(--gold-dim);color:var(--gold);" onclick="updateLeadStatus(\'' + esc(L.id) + '\')"><i class="fa fa-save"></i>Update</button>';
        } else {
            if (L.student_id) actions += '<a href="<?= base_url("sis/student_profile/") ?>' + encodeURIComponent(L.student_id) + '" class="btn-primary"><i class="fa fa-user"></i>View Student Profile</a>';
            actions += '<span style="color:var(--t3);font-size:13px;padding:6px 8px;">This lead has been converted to a student.</span>';
        }
        document.getElementById('modalActions').innerHTML = actions;

        document.getElementById('leadModal').classList.add('active');
    });
}

function closeLeadModal() {
    document.getElementById('leadModal').classList.remove('active');
}
document.getElementById('leadModal').addEventListener('click', function(e) {
    if (e.target === this) closeLeadModal();
});

function updateLeadStatus(leadId) {
    var sel = document.getElementById('statusSelect');
    if (!sel || !sel.value) { alert('Select a status first.'); return; }
    var body = new URLSearchParams({ lead_id: leadId, status: sel.value });
    body.append(csrfName, csrfToken);
    fetch('<?= base_url("sis/update_lead_status") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            closeLeadModal();
            loadLeads();
        } else {
            alert(data.message || 'Update failed.');
        }
    });
}

document.getElementById('searchLeads').addEventListener('input', renderTable);
document.getElementById('filterStatus').addEventListener('change', renderTable);
document.getElementById('filterSource').addEventListener('change', renderTable);

loadLeads();
</script>

</div><!-- /.content-wrapper -->

