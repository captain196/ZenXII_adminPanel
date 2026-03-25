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

/* ── Filters ── */
.ac-filters {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:16px; margin-bottom:20px;
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
    box-shadow:var(--sh);
}
.ac-fg { display:flex; flex-direction:column; gap:4px; }
.ac-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
.ac-fg select, .ac-fg input[type="text"], .ac-fg input[type="tel"], .ac-fg input[type="email"],
.ac-fg input[type="date"], .ac-fg input[type="number"] {
    padding:8px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg3); color:var(--t1); font-size:13px;
    font-family:var(--font-b); min-width:140px;
    transition:border-color var(--ease), box-shadow var(--ease); outline:none;
}
.ac-fg select:focus, .ac-fg input:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }
.ac-fg textarea {
    width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg3); color:var(--t1); font-size:13px; font-family:var(--font-b);
    resize:vertical; min-height:60px; outline:none; transition:border-color var(--ease), box-shadow var(--ease);
}
.ac-fg textarea:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }

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
.ac-badge-pending { background:rgba(217,119,6,.12); color:#d97706; }
.ac-badge-approved { background:rgba(22,163,74,.12); color:#16a34a; }
.ac-badge-rejected { background:rgba(220,38,38,.12); color:#dc2626; }
.ac-badge-enrolled { background:rgba(37,99,235,.12); color:#2563eb; }
.ac-badge-waitlisted { background:rgba(249,115,22,.12); color:#f97316; }

/* ── Action Buttons ── */
.ac-act {
    padding:5px 10px; border-radius:6px; border:1px solid var(--border);
    background:var(--bg3); color:var(--t2); cursor:pointer; font-size:12px;
    transition:all var(--ease); display:inline-flex; align-items:center; gap:4px;
}
.ac-act:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }
.ac-act-red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
.ac-act-green:hover { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
.ac-act-blue:hover { background:rgba(37,99,235,.12); color:#2563eb; border-color:rgba(37,99,235,.25); }

/* ── Modal ── */
.ac-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.55);
    z-index:2000; align-items:center; justify-content:center;
}
.ac-overlay.active { display:flex; }
.ac-modal {
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r);
    padding:24px; width:90%; max-width:720px; max-height:85vh; overflow-y:auto;
    box-shadow:0 8px 48px rgba(0,0,0,.4);
}
.ac-modal h2 {
    margin:0 0 20px; font-size:16px; font-weight:700;
    color:var(--t1); font-family:var(--font-d);
    padding-bottom:12px; border-bottom:1px solid var(--border);
}
.ac-modal h3 {
    font-size:13px; font-weight:700; color:var(--gold);
    margin:16px 0 10px; padding-top:12px; border-top:1px solid var(--border);
    text-transform:uppercase; letter-spacing:.4px;
}
.ac-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
.ac-form-grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 16px; }
.ac-modal-foot { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid var(--border); }

/* ── Detail Panel ── */
.ac-detail {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); padding:22px; margin-bottom:20px;
    display:none; box-shadow:var(--sh);
}
.ac-detail h3 { margin:0 0 14px; font-size:15px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-detail-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.ac-detail-item { font-size:13px; }
.ac-detail-item label { display:block; color:var(--t3); font-size:10px; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; font-weight:600; }
.ac-detail-item span { color:var(--t1); }
.ac-history { max-height:200px; overflow-y:auto; margin-top:14px; }
.ac-history-item { padding:8px 0; border-bottom:1px solid var(--border); font-size:12px; color:var(--t2); }
.ac-history-item .ts { color:var(--t3); font-size:11px; }

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
    .ac-form-grid, .ac-form-grid-3 { grid-template-columns:1fr; }
    .ac-detail-grid { grid-template-columns:1fr 1fr; }
    .ac-filters { flex-direction:column; align-items:stretch; }
}
@media(max-width:640px) {
    .ac-detail-grid { grid-template-columns:1fr; }
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-file-text-o"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Applications</div>
            <div class="ac-head-sub">Manage admission applications, approvals &amp; enrollment</div>
        </div>
        <button class="ac-btn ac-btn-primary" onclick="openAppModal()"><i class="fa fa-plus"></i> New Application</button>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <!-- Detail Panel -->
    <div class="ac-detail" id="detailPanel"></div>

    <!-- Filters -->
    <div class="ac-filters">
        <div class="ac-fg">
            <label>Status</label>
            <select id="filterStatus" onchange="loadApplications()">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
                <option value="enrolled">Enrolled</option>
                <option value="waitlisted">Waitlisted</option>
            </select>
        </div>
        <div class="ac-fg">
            <label>Class</label>
            <select id="filterClass" onchange="loadApplications()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="ac-fg">
            <label>Search</label>
            <input type="text" id="filterSearch" placeholder="Name or ID..." oninput="filterTable()" style="min-width:180px;">
        </div>
    </div>

    <div id="tableWrap">
        <div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading applications...</div>
    </div>

    <!-- Application Modal -->
    <div class="ac-overlay" id="appModal">
        <div class="ac-modal">
            <h2 id="modalTitle"><i class="fa fa-file-text-o" style="color:var(--gold);margin-right:8px;"></i>New Application</h2>
            <input type="hidden" id="appId">

            <div class="ac-form-grid">
                <div class="ac-fg"><label>Student Name *</label><input type="text" id="appStudentName"></div>
                <div class="ac-fg"><label>Parent / Guardian *</label><input type="text" id="appParentName"></div>
                <div class="ac-fg"><label>Father's Name</label><input type="text" id="appFatherName"></div>
                <div class="ac-fg"><label>Mother's Name</label><input type="text" id="appMotherName"></div>
                <div class="ac-fg"><label>Phone *</label><input type="tel" id="appPhone"></div>
                <div class="ac-fg"><label>Email</label><input type="email" id="appEmail"></div>
                <div class="ac-fg">
                    <label>Class *</label>
                    <select id="appClass">
                        <option value="">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>" data-section="<?= htmlspecialchars($c['section']) ?>">
                            <?= htmlspecialchars($c['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ac-fg"><label>Section</label><input type="text" id="appSection" readonly style="opacity:.7;"></div>
                <div class="ac-fg"><label>Date of Birth</label><input type="date" id="appDOB"></div>
                <div class="ac-fg">
                    <label>Gender</label>
                    <select id="appGender"><option value="">-- Select --</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select>
                </div>
                <div class="ac-fg"><label>Blood Group</label><input type="text" id="appBloodGroup"></div>
                <div class="ac-fg"><label>Category</label><input type="text" id="appCategory"></div>
                <div class="ac-fg"><label>Religion</label><input type="text" id="appReligion"></div>
                <div class="ac-fg"><label>Nationality</label><input type="text" id="appNationality" value="Indian"></div>
                <div class="ac-fg"><label>Father's Occupation</label><input type="text" id="appFatherOcc"></div>
                <div class="ac-fg"><label>Mother's Occupation</label><input type="text" id="appMotherOcc"></div>
            </div>

            <h3><i class="fa fa-map-marker" style="margin-right:6px;"></i> Address</h3>
            <div class="ac-fg"><input type="text" id="appAddress" placeholder="Street address"></div>
            <div class="ac-form-grid-3">
                <div class="ac-fg"><label>City</label><input type="text" id="appCity"></div>
                <div class="ac-fg"><label>State</label><input type="text" id="appState"></div>
                <div class="ac-fg"><label>Pincode</label><input type="text" id="appPincode"></div>
            </div>

            <h3><i class="fa fa-school" style="margin-right:6px;"></i> Previous School</h3>
            <div class="ac-form-grid-3">
                <div class="ac-fg"><label>School Name</label><input type="text" id="appPrevSchool"></div>
                <div class="ac-fg"><label>Class</label><input type="text" id="appPrevClass"></div>
                <div class="ac-fg"><label>Marks/Grade</label><input type="text" id="appPrevMarks"></div>
            </div>

            <div class="ac-fg" style="margin-top:8px;"><label>Notes</label><textarea id="appNotes" rows="2"></textarea></div>

            <div class="ac-modal-foot">
                <button class="ac-btn ac-btn-ghost" onclick="closeAppModal()">Cancel</button>
                <button class="ac-btn ac-btn-primary" onclick="saveApplication()" id="saveAppBtn"><i class="fa fa-check"></i> Save Application</button>
            </div>
        </div>
    </div>

    <!-- Approve/Reject Modal -->
    <div class="ac-overlay" id="actionModal">
        <div class="ac-modal" style="max-width:440px;">
            <h2 id="actionTitle">Approve Application</h2>
            <input type="hidden" id="actionAppId">
            <input type="hidden" id="actionType">
            <div class="ac-fg"><label id="actionLabel">Remarks</label><textarea id="actionRemarks" rows="3"></textarea></div>
            <div class="ac-modal-foot">
                <button class="ac-btn ac-btn-ghost" onclick="closeActionModal()">Cancel</button>
                <button class="ac-btn ac-btn-primary" onclick="submitAction()" id="actionBtn">Confirm</button>
            </div>
        </div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var allApps = [];

document.addEventListener('DOMContentLoaded', function() {
    loadApplications();
    document.getElementById('appClass').addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        document.getElementById('appSection').value = opt.getAttribute('data-section') || '';
    });
});

function loadApplications() {
    fetch(BASE + 'admission_crm/fetch_applications', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: ''
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') { allApps = data.data.applications || []; renderTable(allApps); }
    })
    .catch(function() { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load</div>'; });
}

function renderTable(items) {
    var sf = document.getElementById('filterStatus').value;
    var cf = document.getElementById('filterClass').value;
    var filtered = items.filter(function(a) { if (sf && a.status !== sf) return false; if (cf && a.class !== cf) return false; return true; });

    if (filtered.length === 0) { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-inbox"></i> No applications found</div>'; return; }

    var bm = { pending:'ac-badge-pending', approved:'ac-badge-approved', rejected:'ac-badge-rejected', enrolled:'ac-badge-enrolled', waitlisted:'ac-badge-waitlisted' };
    var html = '<div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>ID</th><th>Student</th><th>Class</th><th>Phone</th><th>Stage</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';

    filtered.forEach(function(a) {
        var bc = bm[a.status] || 'ac-badge-pending';
        html += '<tr data-name="' + (a.student_name || '').toLowerCase() + '" data-id="' + (a.application_id || '').toLowerCase() + '">';
        html += '<td style="font-family:var(--font-m);font-size:11px;">' + esc(a.application_id || a.id) + '</td>';
        html += '<td style="font-weight:600;">' + esc(a.student_name) + '</td>';
        html += '<td>' + esc(a.class || '-') + '</td>';
        html += '<td>' + esc(a.phone) + '</td>';
        html += '<td style="font-size:12px;color:var(--t3);">' + esc((a.stage || '').replace(/_/g, ' ')) + '</td>';
        html += '<td><span class="ac-badge ' + bc + '">' + esc(a.status) + '</span></td>';
        html += '<td style="font-size:12px;">' + esc((a.created_at || '').substring(0, 10)) + '</td>';
        html += '<td style="white-space:nowrap;">';
        html += '<button class="ac-act" onclick="viewApp(\'' + a.id + '\')" title="View"><i class="fa fa-eye"></i></button> ';
        html += '<button class="ac-act" onclick="editApp(\'' + a.id + '\')" title="Edit"><i class="fa fa-pencil"></i></button> ';
        if (a.status === 'pending') {
            html += '<button class="ac-act ac-act-green" onclick="openAction(\'' + a.id + '\',\'approve\')" title="Approve"><i class="fa fa-check"></i></button> ';
            html += '<button class="ac-act ac-act-red" onclick="openAction(\'' + a.id + '\',\'reject\')" title="Reject"><i class="fa fa-times"></i></button> ';
            html += '<button class="ac-act" onclick="addWaitlist(\'' + a.id + '\')" title="Waitlist"><i class="fa fa-clock-o"></i></button> ';
        }
        if (a.status === 'approved') html += '<button class="ac-act ac-act-blue" onclick="enrollStudent(\'' + a.id + '\')" title="Enroll"><i class="fa fa-user-plus"></i></button> ';
        html += '<button class="ac-act ac-act-red" onclick="deleteApp(\'' + a.id + '\')"><i class="fa fa-trash"></i></button>';
        html += '</td></tr>';
    });
    html += '</tbody></table></div>';
    document.getElementById('tableWrap').innerHTML = html;
}

function filterTable() {
    var q = document.getElementById('filterSearch').value.toLowerCase();
    document.querySelectorAll('.ac-table tbody tr').forEach(function(r) {
        r.style.display = ((r.getAttribute('data-name')||'').indexOf(q) !== -1 || (r.getAttribute('data-id')||'').indexOf(q) !== -1) ? '' : 'none';
    });
}

function viewApp(id) {
    fetch(BASE + 'admission_crm/get_application?id=' + encodeURIComponent(id), { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status !== 'success') return;
        var a = data.data.application, p = document.getElementById('detailPanel');
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">';
        html += '<h3><i class="fa fa-user" style="color:var(--gold);margin-right:8px;"></i>' + esc(a.student_name) + ' — ' + esc(a.application_id || a.id) + '</h3>';
        html += '<button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="document.getElementById(\'detailPanel\').style.display=\'none\'"><i class="fa fa-times"></i> Close</button></div>';
        html += '<div class="ac-detail-grid">';
        [['Class',a.class],['Section',a.section],['Status',a.status],['Stage',(a.stage||'').replace(/_/g,' ')],['Phone',a.phone],['Email',a.email],['DOB',a.dob],['Gender',a.gender],['Blood Group',a.blood_group],['Father',a.father_name],['Mother',a.mother_name],['Category',a.category],['Religion',a.religion],['Nationality',a.nationality],['Address',a.address],['City',a.city],['State',a.state],['Previous School',a.previous_school],['Previous Class',a.previous_class],['Created',(a.created_at||'').substring(0,10)]].forEach(function(f) {
            if (f[1]) html += '<div class="ac-detail-item"><label>' + f[0] + '</label><span>' + esc(f[1]) + '</span></div>';
        });
        html += '</div>';
        if (a.history && a.history.length) {
            html += '<h4 style="margin:16px 0 8px;font-size:13px;color:var(--t2);font-weight:700;">History</h4><div class="ac-history">';
            a.history.forEach(function(h) { html += '<div class="ac-history-item">' + esc(h.action) + ' <span class="ts">— ' + esc(h.by) + ' · ' + esc(h.timestamp) + '</span></div>'; });
            html += '</div>';
        }
        p.innerHTML = html; p.style.display = 'block'; p.scrollIntoView({behavior:'smooth'});
    });
}

function openAppModal(app) {
    var ids = ['appId','appStudentName','appParentName','appFatherName','appMotherName','appPhone','appEmail','appClass','appSection','appDOB','appGender','appBloodGroup','appCategory','appReligion','appNationality','appFatherOcc','appMotherOcc','appAddress','appCity','appState','appPincode','appPrevSchool','appPrevClass','appPrevMarks','appNotes'];
    ids.forEach(function(f) { var el = document.getElementById(f); if (el) el.value = f === 'appNationality' ? 'Indian' : ''; });
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-file-text-o" style="color:var(--gold);margin-right:8px;"></i>New Application';
    if (app) {
        var map = {appId:'id',appStudentName:'student_name',appParentName:'parent_name',appFatherName:'father_name',appMotherName:'mother_name',appPhone:'phone',appEmail:'email',appClass:'class',appSection:'section',appDOB:'dob',appGender:'gender',appBloodGroup:'blood_group',appCategory:'category',appReligion:'religion',appNationality:'nationality',appFatherOcc:'father_occupation',appMotherOcc:'mother_occupation',appAddress:'address',appCity:'city',appState:'state',appPincode:'pincode',appPrevSchool:'previous_school',appPrevClass:'previous_class',appPrevMarks:'previous_marks',appNotes:'notes'};
        Object.keys(map).forEach(function(k) { var el = document.getElementById(k); if (el) el.value = app[map[k]] || ''; });
        document.getElementById('modalTitle').innerHTML = '<i class="fa fa-pencil" style="color:var(--gold);margin-right:8px;"></i>Edit Application';
    }
    document.getElementById('appModal').classList.add('active');
}
function closeAppModal() { document.getElementById('appModal').classList.remove('active'); }
function editApp(id) { var a = allApps.find(function(x){return x.id===id;}); if (a) openAppModal(a); }

function saveApplication() {
    var btn = document.getElementById('saveAppBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
    var ids = {id:'appId',student_name:'appStudentName',parent_name:'appParentName',father_name:'appFatherName',mother_name:'appMotherName',phone:'appPhone',email:'appEmail','class':'appClass',section:'appSection',dob:'appDOB',gender:'appGender',blood_group:'appBloodGroup',category:'appCategory',religion:'appReligion',nationality:'appNationality',father_occupation:'appFatherOcc',mother_occupation:'appMotherOcc',address:'appAddress',city:'appCity',state:'appState',pincode:'appPincode',previous_school:'appPrevSchool',previous_class:'appPrevClass',previous_marks:'appPrevMarks',notes:'appNotes'};
    var body = new URLSearchParams();
    Object.keys(ids).forEach(function(k) { body.append(k, document.getElementById(ids[k]).value); });
    fetch(BASE + 'admission_crm/save_application', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body:body.toString() })
    .then(function(r){return r.json();}).then(function(d) { btn.disabled=false; btn.innerHTML='<i class="fa fa-check"></i> Save Application'; showAlert(d.message,d.status==='success'?'success':'error'); if(d.status==='success'){closeAppModal();loadApplications();} })
    .catch(function(){btn.disabled=false;btn.innerHTML='<i class="fa fa-check"></i> Save Application';showAlert('Save failed','error');});
}

function openAction(id,type) {
    document.getElementById('actionAppId').value = id;
    document.getElementById('actionType').value = type;
    document.getElementById('actionRemarks').value = '';
    if (type==='approve') { document.getElementById('actionTitle').textContent='Approve Application'; document.getElementById('actionLabel').textContent='Remarks (optional)'; document.getElementById('actionBtn').textContent='Approve'; document.getElementById('actionBtn').style.background='var(--green)'; }
    else { document.getElementById('actionTitle').textContent='Reject Application'; document.getElementById('actionLabel').textContent='Reason for rejection'; document.getElementById('actionBtn').textContent='Reject'; document.getElementById('actionBtn').style.background='#dc2626'; }
    document.getElementById('actionModal').classList.add('active');
}
function closeActionModal(){document.getElementById('actionModal').classList.remove('active');}

function submitAction() {
    var id=document.getElementById('actionAppId').value, type=document.getElementById('actionType').value, remarks=document.getElementById('actionRemarks').value;
    var ep = type==='approve'?'approve_application':'reject_application', bk = type==='approve'?'remarks':'reason';
    var body = new URLSearchParams({id:id}); body.append(bk,remarks);
    fetch(BASE+'admission_crm/'+ep,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString()})
    .then(function(r){return r.json();}).then(function(d){closeActionModal();showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadApplications();});
}

function enrollStudent(id) {
    if (!confirm('Enroll this student? This will create a student profile and add them to the class roster.')) return;
    fetch(BASE+'admission_crm/enroll_student',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({id:id}).toString()})
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadApplications();});
}

function addWaitlist(id) {
    var reason = prompt('Reason for waitlisting (optional):'); if (reason===null) return;
    fetch(BASE+'admission_crm/add_to_waitlist',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({application_id:id,reason:reason||'',priority:'99'}).toString()})
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadApplications();});
}

function deleteApp(id) {
    if (!confirm('Delete this application? This cannot be undone.')) return;
    fetch(BASE+'admission_crm/delete_application',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({id:id}).toString()})
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadApplications();});
}

function showAlert(msg,type){var el=document.getElementById('pageAlert');el.className='ac-alert ac-alert-'+type;el.textContent=msg;el.style.display='block';setTimeout(function(){el.style.display='none';},4000);}
function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
</script>
