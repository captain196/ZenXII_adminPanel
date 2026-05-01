<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size:16px !important; }

.ac-wrap { padding:24px 22px 52px; min-height:100vh; }

/* ── Header ── */
.ac-head {
    display:flex; align-items:center; gap:14px;
    padding:18px 22px; margin-bottom:22px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh);
}
.ac-head-icon {
    width:44px; height:44px; border-radius:10px;
    background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow);
}
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }

/* ── Buttons ── */
.ac-btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px; border-radius:8px; border:none;
    font-size:13px; font-weight:600; cursor:pointer;
    transition:all var(--ease); font-family:var(--font-b);
}
.ac-btn-primary { background:var(--gold); color:#fff; box-shadow:0 2px 10px var(--gold-ring); }
.ac-btn-primary:hover { background:var(--gold2); }
.ac-btn-ghost { background:transparent; color:var(--t2); border:1px solid var(--border); }
.ac-btn-ghost:hover { border-color:var(--gold); color:var(--gold); }
.ac-btn-sm { padding:5px 12px; font-size:11.5px; }
.ac-btn-danger { background:#c0392b; color:#fff; }
.ac-btn-danger:hover { background:#a93226; }

/* ── Filter Bar ── */
.ac-filters {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:16px; margin-bottom:20px;
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
    box-shadow:var(--sh);
}
.ac-fg { display:flex; flex-direction:column; gap:4px; }
.ac-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
.ac-fg select, .ac-fg input[type="text"], .ac-fg input[type="tel"], .ac-fg input[type="email"],
.ac-fg input[type="date"] {
    padding:8px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg3); color:var(--t1); font-size:13px;
    font-family:var(--font-b); min-width:140px;
    transition:border-color var(--ease), box-shadow var(--ease); outline:none;
}
.ac-fg select:focus, .ac-fg input:focus {
    border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring);
}

/* ── Table ── */
.ac-table-wrap {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; overflow:hidden; box-shadow:var(--sh);
}
.ac-table { width:100%; border-collapse:collapse; font-size:13px; }
.ac-table th {
    background:var(--bg3); color:var(--t2); font-family:var(--font-m);
    padding:10px 14px; text-align:left; border-bottom:1px solid var(--border);
    font-size:11px; text-transform:uppercase; letter-spacing:.4px;
}
.ac-table td { padding:10px 14px; border-bottom:1px solid var(--border); color:var(--t1); }
.ac-table tr:last-child td { border-bottom:none; }
.ac-table tr:hover td { background:var(--gold-dim); }

/* ── Badges ── */
.ac-badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.ac-badge-new { background:rgba(22,163,74,.12); color:#16a34a; }
.ac-badge-contacted { background:rgba(37,99,235,.12); color:#2563eb; }
.ac-badge-follow { background:rgba(217,119,6,.12); color:#d97706; }
.ac-badge-converted { background:rgba(15,118,110,.12); color:#0f766e; }
.ac-badge-lost { background:rgba(220,38,38,.12); color:#dc2626; }

/* ── Action Buttons ── */
.ac-act {
    padding:5px 10px; border-radius:6px; border:1px solid var(--border);
    background:var(--bg3); color:var(--t2); cursor:pointer; font-size:12px;
    transition:all var(--ease); display:inline-flex; align-items:center; gap:4px;
}
.ac-act:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }
.ac-act-red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
.ac-act-green:hover { background:#dcfce7; color:#166534; border-color:#bbf7d0; }

/* ── Modal ── */
.ac-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
    z-index:2000; align-items:center; justify-content:center;
}
.ac-overlay.active { display:flex; }
.ac-modal {
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r);
    padding:24px; width:90%; max-width:600px; max-height:85vh; overflow-y:auto;
    box-shadow:0 8px 48px rgba(0,0,0,.4);
}
.ac-modal h2 {
    margin:0 0 20px; font-size:16px; font-weight:700;
    color:var(--t1); font-family:var(--font-d);
    padding-bottom:12px; border-bottom:1px solid var(--border);
}
.ac-modal .ac-fg input, .ac-modal .ac-fg select, .ac-modal .ac-fg textarea {
    width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg3); color:var(--t1); font-size:13px; font-family:var(--font-b);
    transition:border-color var(--ease), box-shadow var(--ease); outline:none;
}
.ac-modal .ac-fg textarea { resize:vertical; min-height:60px; }
.ac-modal .ac-fg input:focus, .ac-modal .ac-fg select:focus, .ac-modal .ac-fg textarea:focus {
    border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring);
}
.ac-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
.ac-modal-foot { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid var(--border); }

/* ── Misc ── */
.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.ac-empty { text-align:center; padding:48px; color:var(--t3); font-size:13px; }
.ac-empty i { font-size:2.5rem; display:block; margin-bottom:10px; opacity:.6; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; transition:color var(--ease); }
.ac-back:hover { color:var(--gold); }

@media(max-width:767px) {
    .ac-head { flex-wrap:wrap; }
    .ac-form-grid { grid-template-columns:1fr; }
    .ac-filters { flex-direction:column; align-items:stretch; }
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-phone"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Admission Inquiries</div>
            <div class="ac-head-sub">Track and manage all admission inquiries</div>
        </div>
        <button class="ac-btn ac-btn-primary" onclick="openModal()"><i class="fa fa-plus"></i> New Inquiry</button>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <!-- Filters -->
    <div class="ac-filters">
        <div class="ac-fg">
            <label>Status</label>
            <select id="filterStatus" onchange="loadInquiries()">
                <option value="">All Statuses</option>
                <option value="new">New</option>
                <option value="contacted">Contacted</option>
                <option value="follow_up">Follow-up</option>
                <option value="converted">Converted</option>
                <option value="lost">Lost</option>
            </select>
        </div>
        <div class="ac-fg">
            <label>Class</label>
            <select id="filterClass" onchange="loadInquiries()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= htmlspecialchars($c['class_name']) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ac-fg">
            <label>Search</label>
            <input type="text" id="filterSearch" placeholder="Name or phone..." oninput="filterTable()" style="min-width:180px;">
        </div>
    </div>

    <!-- Table -->
    <div id="tableWrap">
        <div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading inquiries...</div>
    </div>

    <!-- Modal -->
    <div class="ac-overlay" id="inqModal">
        <div class="ac-modal">
            <h2 id="modalTitle"><i class="fa fa-phone" style="color:var(--gold);margin-right:8px;"></i>New Inquiry</h2>
            <input type="hidden" id="inqId">
            <div class="ac-form-grid">
                <div class="ac-fg"><label>Student Name *</label><input type="text" id="inqStudentName"></div>
                <div class="ac-fg"><label>Parent / Guardian *</label><input type="text" id="inqParentName"></div>
                <div class="ac-fg"><label>Phone *</label><input type="tel" id="inqPhone"></div>
                <div class="ac-fg"><label>Email</label><input type="email" id="inqEmail"></div>
                <div class="ac-fg">
                    <label>Class Interested</label>
                    <select id="inqClass">
                        <option value="">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ac-fg">
                    <label>Source</label>
                    <select id="inqSource">
                        <option value="Walk-in">Walk-in</option>
                        <option value="Phone">Phone Call</option>
                        <option value="Online Form">Online Form</option>
                        <option value="Referral">Referral</option>
                        <option value="Advertisement">Advertisement</option>
                        <option value="Social Media">Social Media</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="ac-fg">
                    <label>Status</label>
                    <select id="inqStatus">
                        <option value="new">New</option>
                        <option value="contacted">Contacted</option>
                        <option value="follow_up">Follow-up Required</option>
                        <option value="converted">Converted</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
                <div class="ac-fg"><label>Follow-up Date</label><input type="date" id="inqFollowUp"></div>
            </div>
            <div class="ac-fg"><label>Notes</label><textarea id="inqNotes" rows="3"></textarea></div>
            <div class="ac-modal-foot">
                <button class="ac-btn ac-btn-ghost" onclick="closeModal()">Cancel</button>
                <button class="ac-btn ac-btn-primary" onclick="saveInquiry()" id="saveBtn"><i class="fa fa-check"></i> Save Inquiry</button>
            </div>
        </div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var allInquiries = [];

document.addEventListener('DOMContentLoaded', function() { loadInquiries(); });

function loadInquiries() {
    fetch(BASE + 'admission_crm/fetch_inquiries', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: ''
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            allInquiries = data.inquiries || [];
            renderTable(allInquiries);
        }
    })
    .catch(function() {
        document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load inquiries</div>';
    });
}

function renderTable(items) {
    var statusFilter = document.getElementById('filterStatus').value;
    var classFilter = document.getElementById('filterClass').value;

    var filtered = items.filter(function(i) {
        if (statusFilter && i.status !== statusFilter) return false;
        if (classFilter && i.class !== classFilter.replace('Class ', '')) return false;
        return true;
    });

    if (filtered.length === 0) {
        document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-inbox"></i> No inquiries found</div>';
        return;
    }

    var badgeMap = { new:'ac-badge-new', contacted:'ac-badge-contacted', follow_up:'ac-badge-follow', converted:'ac-badge-converted', lost:'ac-badge-lost' };
    var html = '<div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>ID</th><th>Student</th><th>Parent</th><th>Phone</th><th>Class</th><th>Source</th><th>Status</th><th>Follow-up</th><th>Actions</th></tr></thead><tbody>';

    filtered.forEach(function(i) {
        var bc = badgeMap[i.status] || 'ac-badge-new';
        html += '<tr data-name="' + (i.student_name || '').toLowerCase() + '" data-phone="' + (i.phone || '') + '">';
        html += '<td style="font-family:var(--font-m);font-size:11px;">' + esc(i.inquiry_id || i.id) + '</td>';
        html += '<td style="font-weight:600;">' + esc(i.student_name) + '</td>';
        html += '<td>' + esc(i.parent_name) + '</td>';
        html += '<td>' + esc(i.phone) + '</td>';
        html += '<td>' + esc(i.class || '-') + '</td>';
        html += '<td>' + esc(i.source || '-') + '</td>';
        html += '<td><span class="ac-badge ' + bc + '">' + esc(i.status) + '</span></td>';
        html += '<td style="font-size:12px;">' + esc(i.follow_up_date || '-') + '</td>';
        html += '<td style="white-space:nowrap;">';
        html += '<button class="ac-act" onclick="editInquiry(\'' + i.id + '\')" title="Edit"><i class="fa fa-pencil"></i></button> ';
        if (i.status !== 'converted') {
            html += '<button class="ac-act ac-act-green" onclick="convertInquiry(\'' + i.id + '\')" title="Convert to Application"><i class="fa fa-arrow-right"></i></button> ';
        }
        html += '<button class="ac-act ac-act-red" onclick="deleteInquiry(\'' + i.id + '\')" title="Delete"><i class="fa fa-trash"></i></button>';
        html += '</td></tr>';
    });

    html += '</tbody></table></div>';
    document.getElementById('tableWrap').innerHTML = html;
}

function filterTable() {
    var q = document.getElementById('filterSearch').value.toLowerCase();
    var rows = document.querySelectorAll('.ac-table tbody tr');
    rows.forEach(function(row) {
        var name = row.getAttribute('data-name') || '';
        var phone = row.getAttribute('data-phone') || '';
        row.style.display = (name.indexOf(q) !== -1 || phone.indexOf(q) !== -1) ? '' : 'none';
    });
}

function openModal(inq) {
    document.getElementById('inqId').value = '';
    document.getElementById('inqStudentName').value = '';
    document.getElementById('inqParentName').value = '';
    document.getElementById('inqPhone').value = '';
    document.getElementById('inqEmail').value = '';
    document.getElementById('inqClass').value = '';
    document.getElementById('inqSource').value = 'Walk-in';
    document.getElementById('inqStatus').value = 'new';
    document.getElementById('inqFollowUp').value = '';
    document.getElementById('inqNotes').value = '';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-phone" style="color:var(--gold);margin-right:8px;"></i>New Inquiry';

    if (inq) {
        document.getElementById('inqId').value = inq.id || '';
        document.getElementById('inqStudentName').value = inq.student_name || '';
        document.getElementById('inqParentName').value = inq.parent_name || '';
        document.getElementById('inqPhone').value = inq.phone || '';
        document.getElementById('inqEmail').value = inq.email || '';
        document.getElementById('inqClass').value = inq.class || '';
        document.getElementById('inqSource').value = inq.source || 'Walk-in';
        document.getElementById('inqStatus').value = inq.status || 'new';
        document.getElementById('inqFollowUp').value = inq.follow_up_date || '';
        document.getElementById('inqNotes').value = inq.notes || '';
        document.getElementById('modalTitle').innerHTML = '<i class="fa fa-pencil" style="color:var(--gold);margin-right:8px;"></i>Edit Inquiry';
    }

    document.getElementById('inqModal').classList.add('active');
}

function closeModal() { document.getElementById('inqModal').classList.remove('active'); }

function editInquiry(id) {
    var inq = allInquiries.find(function(i) { return i.id === id; });
    if (inq) openModal(inq);
}

function saveInquiry() {
    var btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    var body = new URLSearchParams({
        id: document.getElementById('inqId').value,
        student_name: document.getElementById('inqStudentName').value,
        parent_name: document.getElementById('inqParentName').value,
        phone: document.getElementById('inqPhone').value,
        email: document.getElementById('inqEmail').value,
        'class': document.getElementById('inqClass').value,
        source: document.getElementById('inqSource').value,
        status: document.getElementById('inqStatus').value,
        follow_up_date: document.getElementById('inqFollowUp').value,
        notes: document.getElementById('inqNotes').value,
    });

    fetch(BASE + 'admission_crm/save_inquiry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: body.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save Inquiry';
        showAlert(data.message, data.status === 'success' ? 'success' : 'error');
        if (data.status === 'success') { closeModal(); loadInquiries(); }
    })
    .catch(function() {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save Inquiry';
        showAlert('Save failed', 'error');
    });
}

function convertInquiry(id) {
    if (!confirm('Convert this inquiry to an application?')) return;
    fetch(BASE + 'admission_crm/convert_to_application', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ inquiry_id: id }).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        showAlert(data.message, data.status === 'success' ? 'success' : 'error');
        if (data.status === 'success') loadInquiries();
    });
}

function deleteInquiry(id) {
    if (!confirm('Delete this inquiry? This cannot be undone.')) return;
    fetch(BASE + 'admission_crm/delete_inquiry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ id: id }).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        showAlert(data.message, data.status === 'success' ? 'success' : 'error');
        if (data.status === 'success') loadInquiries();
    });
}

function showAlert(msg, type) {
    var el = document.getElementById('pageAlert');
    el.className = 'ac-alert ac-alert-' + type;
    el.textContent = msg;
    el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 4000);
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
</script>
