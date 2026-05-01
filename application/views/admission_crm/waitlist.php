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

/* ── Stats ── */
.ac-stat-row { display:flex; gap:14px; margin-bottom:20px; flex-wrap:wrap; }
.ac-stat-mini {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:14px 20px; box-shadow:var(--sh);
    transition:border-color var(--ease);
}
.ac-stat-mini:hover { border-color:var(--gold); }
.ac-stat-mini .val { font-size:1.5rem; font-weight:700; color:var(--gold); font-family:var(--font-b); line-height:1.1; }
.ac-stat-mini .lbl { font-size:10px; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; font-weight:600; margin-top:2px; }

/* ── Filter ── */
.ac-filters {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:14px 16px; margin-bottom:20px;
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
    box-shadow:var(--sh);
}
.ac-fg { display:flex; flex-direction:column; gap:4px; }
.ac-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
.ac-fg select {
    padding:8px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg3); color:var(--t1); font-size:13px;
    font-family:var(--font-b); min-width:160px; outline:none;
    transition:border-color var(--ease), box-shadow var(--ease);
}
.ac-fg select:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }

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

/* ── Priority Badge ── */
.ac-priority { display:inline-block; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.ac-p-high { background:rgba(220,38,38,.12); color:#dc2626; }
.ac-p-medium { background:rgba(217,119,6,.12); color:#d97706; }
.ac-p-low { background:rgba(37,99,235,.12); color:#2563eb; }

/* ── Actions ── */
.ac-act {
    padding:5px 12px; border-radius:6px; border:1px solid var(--border);
    background:var(--bg3); color:var(--t2); cursor:pointer; font-size:12px;
    transition:all var(--ease); display:inline-flex; align-items:center; gap:5px;
    font-family:var(--font-b);
}
.ac-act:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }
.ac-act-green:hover { background:#dcfce7; color:#166534; border-color:#bbf7d0; }
.ac-act-red:hover { background:#fee2e2; color:#991b1b; border-color:#fecaca; }

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
    .ac-stat-row { gap:10px; }
    .ac-stat-mini { flex:1; min-width:100px; }
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-list-ol"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Waiting List</div>
            <div class="ac-head-sub">Session <?= htmlspecialchars($session_year) ?> — Manage waitlisted applicants by priority</div>
        </div>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <div class="ac-stat-row" id="statsBar"></div>

    <div class="ac-filters">
        <div class="ac-fg">
            <label>Filter by Class</label>
            <select id="filterClass" onchange="loadWaitlist()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="tableWrap">
        <div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading waitlist...</div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var allWaitlist = [];

document.addEventListener('DOMContentLoaded', function() { loadWaitlist(); });

function loadWaitlist() {
    fetch(BASE + 'admission_crm/fetch_waitlist', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: ''
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') { allWaitlist = data.waitlist || []; renderStats(); renderTable(); }
    })
    .catch(function() { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load</div>'; });
}

function renderStats() {
    var classCounts = {};
    allWaitlist.forEach(function(w) { var c = w.class || 'Unknown'; classCounts[c] = (classCounts[c] || 0) + 1; });
    var html = '<div class="ac-stat-mini"><div class="val">' + allWaitlist.length + '</div><div class="lbl">Total Waiting</div></div>';
    Object.keys(classCounts).sort().forEach(function(c) {
        html += '<div class="ac-stat-mini"><div class="val">' + classCounts[c] + '</div><div class="lbl">Class ' + esc(c) + '</div></div>';
    });
    document.getElementById('statsBar').innerHTML = html;
}

function renderTable() {
    var cf = document.getElementById('filterClass').value;
    var filtered = allWaitlist.filter(function(w) { return !cf || w.class === cf; });

    if (filtered.length === 0) { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-inbox"></i> Waitlist is empty</div>'; return; }

    var html = '<div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>#</th><th>ID</th><th>Student</th><th>Parent</th><th>Class</th><th>Phone</th><th>Priority</th><th>Reason</th><th>Date</th><th>Actions</th></tr></thead><tbody>';

    filtered.forEach(function(w, idx) {
        var pCls = w.priority <= 10 ? 'ac-p-high' : (w.priority <= 50 ? 'ac-p-medium' : 'ac-p-low');
        html += '<tr>';
        html += '<td style="font-weight:700;color:var(--gold);">' + (idx + 1) + '</td>';
        html += '<td style="font-family:var(--font-m);font-size:11px;">' + esc(w.waitlist_id || w.id) + '</td>';
        html += '<td style="font-weight:600;">' + esc(w.student_name) + '</td>';
        html += '<td>' + esc(w.parent_name) + '</td>';
        html += '<td>' + esc(w.class || '-') + '</td>';
        html += '<td>' + esc(w.phone) + '</td>';
        html += '<td><span class="ac-priority ' + pCls + '">' + w.priority + '</span></td>';
        html += '<td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(w.reason || '-') + '</td>';
        html += '<td style="font-size:12px;">' + esc((w.created_at || '').substring(0, 10)) + '</td>';
        html += '<td style="white-space:nowrap;">';
        html += '<button class="ac-act ac-act-green" onclick="promoteEntry(\'' + w.id + '\')" title="Promote & Approve"><i class="fa fa-arrow-up"></i> Promote</button> ';
        html += '<button class="ac-act ac-act-red" onclick="removeEntry(\'' + w.id + '\')" title="Remove"><i class="fa fa-times"></i></button>';
        html += '</td></tr>';
    });

    html += '</tbody></table></div>';
    document.getElementById('tableWrap').innerHTML = html;
}

function promoteEntry(id) {
    if (!confirm('Promote this student from waitlist? They will be approved for admission.')) return;
    fetch(BASE + 'admission_crm/promote_from_waitlist', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body:new URLSearchParams({id:id}).toString() })
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadWaitlist();});
}

function removeEntry(id) {
    if (!confirm('Remove from waitlist? The application will return to pending status.')) return;
    fetch(BASE + 'admission_crm/remove_from_waitlist', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body:new URLSearchParams({id:id}).toString() })
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadWaitlist();});
}

function showAlert(msg,type){var el=document.getElementById('pageAlert');el.className='ac-alert ac-alert-'+type;el.textContent=msg;el.style.display='block';setTimeout(function(){el.style.display='none';},4000);}
function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
</script>
