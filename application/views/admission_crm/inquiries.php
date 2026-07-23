<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size:16px !important; }
.ac-wrap { padding:24px 22px 52px; min-height:100vh; }
.ac-head { display:flex; align-items:center; gap:14px; padding:18px 22px; margin-bottom:16px;
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.ac-head-icon { width:44px; height:44px; border-radius:10px; background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow); }
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; transition:color var(--ease); }
.ac-back:hover { color:var(--gold); }

.ac-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; border-radius:9px; border:none;
    font-size:13px; font-weight:600; cursor:pointer; transition:all var(--ease); font-family:var(--font-b); }
.ac-btn-primary { background:var(--gold); color:#fff; box-shadow:0 2px 10px var(--gold-ring); }
.ac-btn-primary:hover { background:var(--gold2); }
.ac-btn-ghost { background:transparent; color:var(--t2); border:1px solid var(--border); }
.ac-btn-ghost:hover { border-color:var(--gold); color:var(--gold); }

/* Sub-toolbar: status tabs + search */
.ac-sub { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.ac-tabs { display:inline-flex; gap:4px; flex-wrap:wrap; }
.ac-tab { border:1px solid var(--border); background:var(--bg2); color:var(--t2); font-size:12.5px; font-weight:600;
    padding:7px 13px; border-radius:20px; cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-family:var(--font-b); }
.ac-tab:hover { border-color:var(--gold-ring); color:var(--t1); }
.ac-tab.on { background:var(--gold-dim); border-color:transparent; color:var(--gold); }
.ac-tab .ct { font-size:11px; font-weight:800; opacity:.85; }
.ac-search { display:flex; align-items:center; gap:8px; background:var(--bg2); border:1px solid var(--border);
    border-radius:9px; padding:8px 12px; color:var(--t3); font-size:13px; min-width:190px; margin-left:auto; }
.ac-search input { border:0; background:none; outline:none; color:var(--t1); font-size:13px; font-family:var(--font-b); width:100%; }

/* Table */
.ac-tbl-wrap { overflow-x:auto; background:var(--bg2); border:1px solid var(--border); border-radius:14px; box-shadow:var(--sh); }
.ac-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.ac-tbl th { background:var(--bg3); color:var(--t3); font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700;
    padding:11px 15px; text-align:left; border-bottom:1px solid var(--border); white-space:nowrap; }
.ac-tbl td { padding:11px 15px; border-bottom:1px solid var(--border); color:var(--t1); vertical-align:middle; }
.ac-tbl tr:last-child td { border-bottom:0; }
.ac-tbl tbody tr:hover td { background:var(--bg3); }
.cellname { display:flex; align-items:center; gap:11px; }
.ac-av { width:32px; height:32px; border-radius:9px; display:grid; place-items:center; font-weight:700; font-size:11.5px; color:#fff; flex:0 0 auto; }
.pill { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:650; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.rowact { display:flex; gap:5px; opacity:.4; }
tr:hover .rowact { opacity:1; }
.ac-mini { width:30px; height:30px; border-radius:8px; border:1px solid var(--border); background:var(--bg2); color:var(--t2);
    display:grid; place-items:center; font-size:12px; cursor:pointer; transition:all var(--ease); }
.ac-mini:hover { border-color:var(--gold); color:var(--gold); }
.ac-mini.green:hover { border-color:#2FA875; color:#2FA875; }
.ac-mini.red:hover { border-color:#C0402E; color:#C0402E; }

/* Modal */
.ac-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:2000; align-items:center; justify-content:center; }
.ac-overlay.active { display:flex; }
.ac-modal { background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); padding:24px; width:90%; max-width:600px; max-height:85vh; overflow-y:auto; box-shadow:0 8px 48px rgba(0,0,0,.4); }
.ac-modal h2 { margin:0 0 20px; font-size:16px; font-weight:700; color:var(--t1); font-family:var(--font-d); padding-bottom:12px; border-bottom:1px solid var(--border); }
.ac-fg { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
.ac-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
.ac-modal .ac-fg input, .ac-modal .ac-fg select, .ac-modal .ac-fg textarea {
    width:100%; padding:9px 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--t1);
    font-size:13px; font-family:var(--font-b); outline:none; transition:border-color var(--ease), box-shadow var(--ease); }
.ac-modal .ac-fg textarea { resize:vertical; min-height:60px; }
.ac-modal .ac-fg input:focus, .ac-modal .ac-fg select:focus, .ac-modal .ac-fg textarea:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }
.ac-form-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
.ac-modal-foot { display:flex; justify-content:flex-end; gap:10px; margin-top:12px; padding-top:16px; border-top:1px solid var(--border); }

.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.ac-empty { text-align:center; padding:48px; color:var(--t3); font-size:13px; }
.ac-empty i { font-size:2.2rem; display:block; margin-bottom:10px; opacity:.5; }

@media(max-width:767px) { .ac-head { flex-wrap:wrap; } .ac-form-grid { grid-template-columns:1fr; } .ac-search { margin-left:0; width:100%; } }
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Overview</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-comments-o"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Inquiries</div>
            <div class="ac-head-sub">First-contact enquiries — qualify, follow up, then convert to an application</div>
        </div>
        <button class="ac-btn ac-btn-primary" onclick="openModal()"><i class="fa fa-plus"></i> New Inquiry</button>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <!-- Status tabs + search -->
    <div class="ac-sub">
        <div class="ac-tabs" id="statusTabs">
            <button class="ac-tab on" data-st="all" onclick="setStatus('all',this)">All <span class="ct" id="ct-all">0</span></button>
            <button class="ac-tab" data-st="new" onclick="setStatus('new',this)">New <span class="ct" id="ct-new">0</span></button>
            <button class="ac-tab" data-st="contacted" onclick="setStatus('contacted',this)">Contacted <span class="ct" id="ct-contacted">0</span></button>
            <button class="ac-tab" data-st="follow_up" onclick="setStatus('follow_up',this)">Follow-up <span class="ct" id="ct-follow_up">0</span></button>
            <button class="ac-tab" data-st="converted" onclick="setStatus('converted',this)">Converted <span class="ct" id="ct-converted">0</span></button>
        </div>
        <div class="ac-search"><i class="fa fa-search"></i><input type="text" id="filterSearch" placeholder="Search name or phone…" oninput="filterTable()"></div>
    </div>

    <div id="tableWrap">
        <div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading inquiries…</div>
    </div>

    <!-- Modal (unchanged wiring) -->
    <div class="ac-overlay" id="inqModal">
        <div class="ac-modal">
            <h2 id="modalTitle"><i class="fa fa-comments-o" style="color:var(--gold);margin-right:8px;"></i>New Inquiry</h2>
            <input type="hidden" id="inqId">
            <div class="ac-form-grid">
                <div class="ac-fg"><label for="inqStudentName">Student Name *</label><input type="text" id="inqStudentName"></div>
                <div class="ac-fg"><label for="inqParentName">Parent / Guardian *</label><input type="text" id="inqParentName"></div>
                <div class="ac-fg"><label for="inqPhone">Phone *</label><input type="tel" id="inqPhone"></div>
                <div class="ac-fg"><label for="inqEmail">Email</label><input type="email" id="inqEmail"></div>
                <div class="ac-fg">
                    <label for="inqClass">Class Interested</label>
                    <select id="inqClass">
                        <option value="">-- Select --</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ac-fg">
                    <label for="inqSource">Source</label>
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
                    <label for="inqStatus">Status</label>
                    <select id="inqStatus">
                        <option value="new">New</option>
                        <option value="contacted">Contacted</option>
                        <option value="follow_up">Follow-up Required</option>
                        <option value="converted">Converted</option>
                        <option value="lost">Lost</option>
                    </select>
                </div>
                <div class="ac-fg"><label for="inqFollowUp">Follow-up Date</label><input type="date" id="inqFollowUp"></div>
            </div>
            <div class="ac-fg"><label for="inqNotes">Notes</label><textarea id="inqNotes" rows="3"></textarea></div>
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
var curStatus = 'all';

var PILL = { new:'#4E82E0', contacted:'#DD9433', follow_up:'#C05A3B', converted:'#2FA875', lost:'#DB5A48' };
function avColor(s){var p=['#4E82E0','#DD9433','#9576DA','#2FA875','#C05A3B','#3BB0C9','#E06A9B','#6E8AA8'];s=String(s||'');var h=0;for(var i=0;i<s.length;i++){h=(h*31+s.charCodeAt(i))&0x7fffffff;}return p[h%p.length];}
function initials(n){n=String(n||'').trim();if(!n)return '?';var p=n.split(/\s+/);return ((p[0].charAt(0)||'')+(p.length>1?p[p.length-1].charAt(0):'')).toUpperCase();}
function tint(hex,a){var h=(hex||'#888').replace('#','');if(h.length===3)h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];var n=parseInt(h,16);return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')';}

document.addEventListener('DOMContentLoaded', function() { loadInquiries(); });

function loadInquiries() {
    fetch(BASE + 'admission_crm/fetch_inquiries', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: ''
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') { allInquiries = data.inquiries || []; renderTable(); }
        else { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> ' + String(data.message || 'Could not load inquiries').replace(/</g,'&lt;') + '</div>'; }
    })
    .catch(function() {
        document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load inquiries</div>';
    });
}

function setStatus(st, btn) {
    curStatus = st;
    document.querySelectorAll('#statusTabs .ac-tab').forEach(function(t){ t.classList.remove('on'); });
    btn.classList.add('on');
    renderTable();
}

function renderTable() {
    // Live tab counts (whole set)
    var counts = { all: allInquiries.length, new:0, contacted:0, follow_up:0, converted:0 };
    allInquiries.forEach(function(i){ if (counts[i.status] !== undefined) counts[i.status]++; });
    ['all','new','contacted','follow_up','converted'].forEach(function(k){ var e=document.getElementById('ct-'+k); if(e) e.textContent=counts[k]; });

    var filtered = allInquiries.filter(function(i){ return curStatus === 'all' || i.status === curStatus; });

    if (filtered.length === 0) {
        document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-inbox"></i> No inquiries here</div>';
        return;
    }

    var html = '<div class="ac-tbl-wrap"><table class="ac-tbl"><thead><tr>'
        + '<th>Student</th><th>Parent</th><th>Phone</th><th>Class</th><th>Source</th><th>Status</th><th>Follow-up</th><th></th>'
        + '</tr></thead><tbody>';

    filtered.forEach(function(i) {
        var col = avColor(i.student_name);
        var pc = PILL[i.status] || '#6E8AA8';
        html += '<tr data-name="' + escAttr((i.student_name || '').toLowerCase()) + '" data-phone="' + escAttr(i.phone || '') + '">';
        html += '<td><div class="cellname"><div class="ac-av" style="background:' + col + '">' + esc(initials(i.student_name)) + '</div>'
             +  '<div><div style="font-weight:600">' + esc(i.student_name) + '</div><div style="font-size:11px;color:var(--t3)">' + esc(i.inquiry_id || i.id) + '</div></div></div></td>';
        html += '<td>' + esc(i.parent_name || '-') + '</td>';
        html += '<td>' + esc(i.phone || '-') + '</td>';
        html += '<td>' + esc(i.class || '-') + '</td>';
        html += '<td>' + esc(i.source || '-') + '</td>';
        html += '<td><span class="pill" style="background:' + tint(pc,.14) + ';color:' + pc + '">' + esc((i.status || 'new').replace(/_/g,' ')) + '</span></td>';
        html += '<td style="font-size:12px;color:var(--t3)">' + esc(i.follow_up_date || '—') + '</td>';
        html += '<td><div class="rowact">';
        html += '<button class="ac-mini" onclick="editInquiry(\'' + esc(i.id) + '\')" title="Edit"><i class="fa fa-pencil"></i></button>';
        if (i.status !== 'converted') html += '<button class="ac-mini green" onclick="convertInquiry(\'' + esc(i.id) + '\')" title="Convert to application"><i class="fa fa-arrow-right"></i></button>';
        html += '<button class="ac-mini red" onclick="deleteInquiry(\'' + esc(i.id) + '\')" title="Delete"><i class="fa fa-trash"></i></button>';
        html += '</div></td></tr>';
    });

    html += '</tbody></table></div>';
    document.getElementById('tableWrap').innerHTML = html;
    filterTable();
}

function filterTable() {
    var q = document.getElementById('filterSearch').value.toLowerCase();
    document.querySelectorAll('.ac-tbl tbody tr').forEach(function(row) {
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
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-comments-o" style="color:var(--gold);margin-right:8px;"></i>New Inquiry';

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

function editInquiry(id) { var inq = allInquiries.find(function(i){ return i.id === id; }); if (inq) openModal(inq); }

function saveInquiry() {
    var btn = document.getElementById('saveBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';
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
    .then(function(data) { showAlert(data.message, data.status === 'success' ? 'success' : 'error'); if (data.status === 'success') loadInquiries(); });
}

function deleteInquiry(id) {
    if (!confirm('Delete this inquiry? This cannot be undone.')) return;
    fetch(BASE + 'admission_crm/delete_inquiry', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ id: id }).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { showAlert(data.message, data.status === 'success' ? 'success' : 'error'); if (data.status === 'success') loadInquiries(); });
}

function showAlert(msg, type) {
    var el = document.getElementById('pageAlert');
    el.className = 'ac-alert ac-alert-' + type; el.textContent = msg; el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 4000);
}
function esc(s) { var d = document.createElement('div'); d.textContent = (s===null||s===undefined)?'':s; return d.innerHTML; }
function escAttr(s){return (s==null?'':String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
</script>
