<?php defined('BASEPATH') or exit('No direct script access allowed');
// LEAD SYSTEM — Admission leads list view
$esc = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
?>

<style>
.leads-wrap { padding:20px; max-width:1400px; margin:0 auto; }
.leads-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.leads-hdr h1 { font-size:1.25rem; color:var(--t1); font-family:var(--font-b); margin:0; }
.leads-toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.leads-toolbar input, .leads-toolbar select {
    padding:8px 12px; border:1px solid var(--border); border-radius:6px; font-size:13px;
    font-family:var(--font-b); background:var(--bg2); color:var(--t1); outline:none;
}
.leads-toolbar input:focus, .leads-toolbar select:focus { border-color:var(--gold); }

.leads-stats { display:flex; gap:12px; margin-bottom:20px; flex-wrap:wrap; }
.leads-stat {
    padding:14px 20px; background:var(--bg2); border:1px solid var(--border);
    border-radius:8px; flex:1; min-width:130px; text-align:center;
}
.leads-stat .num { font-size:1.5rem; font-weight:700; color:var(--gold); }
.leads-stat .lbl { font-size:11px; color:var(--t3); text-transform:uppercase; letter-spacing:.3px; margin-top:2px; }

.leads-tbl { width:100%; border-collapse:collapse; background:var(--bg2); border-radius:8px; overflow:hidden; border:1px solid var(--border); }
.leads-tbl th { padding:10px 14px; background:var(--bg3); font-size:11px; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; text-align:left; font-weight:600; }
.leads-tbl td { padding:10px 14px; font-size:13px; border-top:1px solid var(--border); color:var(--t1); }
.leads-tbl tr:hover td { background:var(--gold-dim); }
.leads-tbl .name-cell { font-weight:600; }

.badge-status {
    display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:600;
    text-transform:uppercase; letter-spacing:.3px;
}
.badge-new        { background:#dbeafe; color:#1e40af; }
.badge-contacted  { background:#fef3c7; color:#92400e; }
.badge-interested { background:#ede9fe; color:#5b21b6; }
.badge-approved   { background:#dcfce7; color:#166534; }
.badge-enrolled, .badge-admitted { background:#d1fae5; color:#065f46; }
.badge-rejected   { background:#fee2e2; color:#991b1b; }

.btn-sm {
    padding:5px 12px; border:none; border-radius:5px; font-size:12px; cursor:pointer;
    font-family:var(--font-b); font-weight:600; transition:background .2s;
}
.btn-view  { background:var(--gold-dim); color:var(--gold); }
.btn-view:hover { background:var(--gold); color:#fff; }
.btn-convert { background:#dcfce7; color:#166534; }
.btn-convert:hover { background:#166534; color:#fff; }
.btn-primary { background:var(--gold); color:#fff; padding:8px 18px; border:none; border-radius:6px; font-size:13px; cursor:pointer; font-weight:600; }
.btn-primary:hover { background:var(--gold2); }

.leads-empty { text-align:center; padding:48px; color:var(--t3); font-size:14px; }
.leads-empty i { font-size:2.5rem; margin-bottom:12px; display:block; color:var(--border); }

/* Detail Modal */
.lead-modal-bg {
    display:none; position:fixed; top:0; left:0; width:100%; height:100%;
    background:rgba(0,0,0,.45); z-index:9999; align-items:center; justify-content:center;
}
.lead-modal-bg.active { display:flex; }
.lead-modal {
    background:var(--bg2); border-radius:12px; width:90%; max-width:600px;
    max-height:85vh; overflow-y:auto; padding:28px; position:relative;
    box-shadow:0 20px 60px rgba(0,0,0,.25);
}
.lead-modal-close {
    position:absolute; top:12px; right:14px; background:none; border:none;
    font-size:20px; cursor:pointer; color:var(--t3); padding:4px;
}
.lead-modal h2 { font-size:16px; font-weight:700; margin-bottom:16px; color:var(--t1); }
.lead-detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px 16px; }
.lead-detail-item { margin-bottom:6px; }
.lead-detail-item .ld-lbl { font-size:10px; text-transform:uppercase; color:var(--t3); letter-spacing:.3px; font-weight:600; }
.lead-detail-item .ld-val { font-size:13px; color:var(--t1); margin-top:1px; }
.lead-detail-full { grid-column:1/-1; }
.lead-actions { display:flex; gap:10px; margin-top:18px; padding-top:14px; border-top:1px solid var(--border); flex-wrap:wrap; }

@media(max-width:768px) {
    .leads-tbl { font-size:12px; }
    .leads-tbl th, .leads-tbl td { padding:8px 10px; }
    .lead-detail-grid { grid-template-columns:1fr; }
}
</style>

<div class="leads-wrap">

    <div class="leads-hdr">
        <h1><i class="fa fa-users" style="color:var(--gold);margin-right:8px;"></i>Admission Leads</h1>
        <div class="leads-toolbar">
            <input type="text" id="searchLeads" placeholder="Search name or phone...">
            <select id="filterStatus">
                <option value="">All Status</option>
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="interested">Interested</option>
                <option value="approved">Approved</option>
                <option value="admitted">Admitted</option>
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

    <table class="leads-tbl">
        <thead>
            <tr>
                <th>ID</th>
                <th>Student Name</th>
                <th>Parent / Phone</th>
                <th>Class</th>
                <th>Source</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="leadsBody">
            <tr><td colspan="8" class="leads-empty"><i class="fa fa-spinner fa-spin"></i>Loading leads...</td></tr>
        </tbody>
    </table>
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
    })
    .catch(function() {
        document.getElementById('leadsBody').innerHTML = '<tr><td colspan="8" class="leads-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load leads.</td></tr>';
    });
}

function renderStats() {
    var stats = { total:0, 'new':0, contacted:0, interested:0, approved:0, admitted:0, rejected:0 };
    allLeads.forEach(function(l) {
        stats.total++;
        var s = (l.status || 'new').toLowerCase();
        if (stats[s] !== undefined) stats[s]++;
    });
    document.getElementById('leadStats').innerHTML =
        '<div class="leads-stat"><div class="num">' + stats.total + '</div><div class="lbl">Total</div></div>' +
        '<div class="leads-stat"><div class="num">' + stats['new'] + '</div><div class="lbl">New</div></div>' +
        '<div class="leads-stat"><div class="num">' + stats.contacted + '</div><div class="lbl">Contacted</div></div>' +
        '<div class="leads-stat"><div class="num">' + stats.approved + '</div><div class="lbl">Approved</div></div>' +
        '<div class="leads-stat"><div class="num">' + stats.admitted + '</div><div class="lbl">Admitted</div></div>';
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

function badgeClass(status) {
    var s = (status || 'new').toLowerCase();
    return 'badge-status badge-' + s;
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
        if (fStatus && (l.status || 'new') !== fStatus) return false;
        if (fSource && (l.source || 'manual') !== fSource) return false;
        return true;
    });

    if (filtered.length === 0) {
        document.getElementById('leadsBody').innerHTML = '<tr><td colspan="8" class="leads-empty"><i class="fa fa-inbox"></i>No leads found.</td></tr>';
        return;
    }

    var html = '';
    filtered.forEach(function(l) {
        var status = (l.status || 'new').toLowerCase();
        var isAdmitted = status === 'admitted' || status === 'enrolled';
        html += '<tr>' +
            '<td><code style="font-size:11px;">' + esc(l.id) + '</code></td>' +
            '<td class="name-cell">' + esc(l.student_name) + '</td>' +
            '<td>' + esc(l.parent_name || l.father_name || '') + '<br><small style="color:var(--t3);">' + esc(l.phone) + '</small></td>' +
            '<td>' + esc(l['class']) + '</td>' +
            '<td><small>' + esc(l.source === 'public_form' ? 'Public' : 'CRM') + '</small></td>' +
            '<td><span class="' + badgeClass(status) + '">' + esc(status) + '</span></td>' +
            '<td><small>' + esc((l.created_at || '').substring(0, 10)) + '</small></td>' +
            '<td>' +
                '<button class="btn-sm btn-view" onclick="viewLead(\'' + esc(l.id) + '\')"><i class="fa fa-eye"></i></button> ' +
                (isAdmitted ? '' : '<a href="<?= base_url("sis/studentAdmission") ?>?lead_id=' + encodeURIComponent(l.id) + '" class="btn-sm btn-convert" title="Convert to Student"><i class="fa fa-user-plus"></i></a>') +
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
        var status = (L.status || 'new').toLowerCase();
        var isAdmitted = status === 'admitted' || status === 'enrolled';
        document.getElementById('modalTitle').innerHTML = '<i class="fa fa-user" style="color:var(--gold);margin-right:6px;"></i>' + esc(L.student_name || 'Lead') + ' <span class="' + badgeClass(status) + '" style="font-size:11px;margin-left:8px;">' + esc(status) + '</span>';

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
        if (!isAdmitted) {
            actions += '<a href="<?= base_url("sis/studentAdmission") ?>?lead_id=' + encodeURIComponent(L.id) + '" class="btn-primary"><i class="fa fa-user-plus" style="margin-right:4px;"></i>Convert to Student</a>';
            actions += '<select id="statusSelect" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:13px;">' +
                '<option value="">Change Status...</option>' +
                ['new','contacted','interested','approved','rejected'].map(function(s) { return '<option value="' + s + '"' + (s === status ? ' selected' : '') + '>' + s.charAt(0).toUpperCase() + s.slice(1) + '</option>'; }).join('') +
                '</select>';
            actions += '<button class="btn-primary" style="background:var(--gold-dim);color:var(--gold);" onclick="updateLeadStatus(\'' + esc(L.id) + '\')"><i class="fa fa-save"></i> Update</button>';
        } else {
            if (L.student_id) actions += '<a href="<?= base_url("sis/student_profile/") ?>' + encodeURIComponent(L.student_id) + '" class="btn-primary"><i class="fa fa-user" style="margin-right:4px;"></i>View Student Profile</a>';
            actions += '<span style="color:var(--t3);font-size:13px;padding:8px;">This lead has been converted to a student.</span>';
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
