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

.ac-sub { display:flex; align-items:center; gap:10px; margin-bottom:14px; flex-wrap:wrap; }
.ac-select { padding:8px 12px; border:1px solid var(--border); border-radius:9px; background:var(--bg2); color:var(--t1);
    font-size:13px; font-family:var(--font-b); outline:none; }
.ac-select:focus { border-color:var(--gold); }

.ac-tbl-wrap { overflow-x:auto; background:var(--bg2); border:1px solid var(--border); border-radius:14px; box-shadow:var(--sh); }
.ac-tbl { width:100%; border-collapse:collapse; font-size:13px; }
.ac-tbl th { background:var(--bg3); color:var(--t3); font-size:11px; text-transform:uppercase; letter-spacing:.05em; font-weight:700;
    padding:11px 15px; text-align:left; border-bottom:1px solid var(--border); white-space:nowrap; }
.ac-tbl td { padding:11px 15px; border-bottom:1px solid var(--border); color:var(--t1); vertical-align:middle; }
.ac-tbl tr:last-child td { border-bottom:0; }
.ac-tbl tbody tr:hover td { background:var(--bg3); }
.wl-rank { font-weight:800; color:var(--gold); font-size:15px; font-variant-numeric:tabular-nums; }
.cellname { display:flex; align-items:center; gap:11px; }
.ac-av { width:32px; height:32px; border-radius:9px; display:grid; place-items:center; font-weight:700; font-size:11.5px; color:#fff; flex:0 0 auto; }
.rowact { display:flex; gap:6px; align-items:center; }
.ac-btn { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:8px; border:1px solid var(--gold); background:var(--gold);
    color:#fff; font-size:12px; font-weight:600; cursor:pointer; font-family:var(--font-b); box-shadow:0 1px 6px var(--gold-ring); }
.ac-btn:hover { background:var(--gold2); }
.ac-mini { width:30px; height:30px; border-radius:8px; border:1px solid var(--border); background:var(--bg2); color:var(--t2);
    display:grid; place-items:center; font-size:12px; cursor:pointer; transition:all var(--ease); }
.ac-mini.red:hover { border-color:#C0402E; color:#C0402E; }

.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.ac-empty { text-align:center; padding:48px; color:var(--t3); font-size:13px; }
.ac-empty i { font-size:2.2rem; display:block; margin-bottom:10px; opacity:.5; }

@media(max-width:767px){ .ac-head { flex-wrap:wrap; } }
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Overview</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-hourglass-half"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Waiting List</div>
            <div class="ac-head-sub">Session <?= htmlspecialchars($session_year) ?> — approved-but-full applicants, ranked by priority. Promote to Approved when a seat opens, then enrol from the Applications list.</div>
        </div>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <div class="ac-sub">
        <select id="filterClass" class="ac-select" onchange="renderTable()">
            <option value="">All Classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div id="tableWrap">
        <div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading waiting list…</div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var allWaitlist = [];
function avColor(s){var p=['#4E82E0','#DD9433','#9576DA','#2FA875','#C05A3B','#3BB0C9','#E06A9B','#6E8AA8'];s=String(s||'');var h=0;for(var i=0;i<s.length;i++){h=(h*31+s.charCodeAt(i))&0x7fffffff;}return p[h%p.length];}
function initials(n){n=String(n||'').trim();if(!n)return '?';var p=n.split(/\s+/);return ((p[0].charAt(0)||'')+(p.length>1?p[p.length-1].charAt(0):'')).toUpperCase();}

document.addEventListener('DOMContentLoaded', function() { loadWaitlist(); });

function loadWaitlist() {
    fetch(BASE + 'admission_crm/fetch_waitlist', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: ''
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') { allWaitlist = data.waitlist || []; renderTable(); }
        else { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> ' + String(data.message || 'Could not load waiting list').replace(/</g,'&lt;') + '</div>'; }
    })
    .catch(function() { document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> Failed to load</div>'; });
}

function renderTable() {
    var cf = document.getElementById('filterClass').value;
    var filtered = allWaitlist.filter(function(w) { return !cf || w.class === cf; });

    if (filtered.length === 0) {
        document.getElementById('tableWrap').innerHTML = '<div class="ac-empty"><i class="fa fa-check-circle" style="color:#2FA875;"></i> The waiting list is empty.</div>';
        return;
    }

    var html = '<div class="ac-tbl-wrap"><table class="ac-tbl"><thead><tr>'
        + '<th>#</th><th>Applicant</th><th>Class</th><th>Reason</th><th>Waiting since</th><th></th>'
        + '</tr></thead><tbody>';

    filtered.forEach(function(w, idx) {
        var col = avColor(w.student_name);
        html += '<tr>';
        html += '<td><span class="wl-rank">' + (idx + 1) + '</span></td>';
        html += '<td><div class="cellname"><div class="ac-av" style="background:' + col + '">' + esc(initials(w.student_name)) + '</div>'
             +  '<div><div style="font-weight:600">' + esc(w.student_name) + '</div><div style="font-size:11px;color:var(--t3)">' + esc(w.waitlist_id || w.id) + '</div></div></div></td>';
        html += '<td>' + esc(w.class ? ('Grade ' + w.class) : '-') + '</td>';
        html += '<td style="color:var(--t2)">' + esc(w.reason || 'Awaiting seat') + '</td>';
        html += '<td style="color:var(--t3)">' + esc((w.created_at || '').substring(0, 10) || '—') + '</td>';
        html += '<td><div class="rowact">';
        html += '<button class="ac-btn" onclick="promoteEntry(\'' + esc(w.id) + '\')"><i class="fa fa-arrow-up"></i> Promote to Approved</button>';
        html += '<button class="ac-mini red" onclick="removeEntry(\'' + esc(w.id) + '\')" title="Remove"><i class="fa fa-times"></i></button>';
        html += '</div></td></tr>';
    });

    html += '</tbody></table></div>';
    document.getElementById('tableWrap').innerHTML = html;
}

function promoteEntry(id) {
    if (!confirm('Promote this student from the waiting list? They will be approved for admission.')) return;
    fetch(BASE + 'admission_crm/promote_from_waitlist', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body:new URLSearchParams({id:id}).toString() })
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadWaitlist();});
}

function removeEntry(id) {
    if (!confirm('Remove from the waiting list? The application will return to pending status.')) return;
    fetch(BASE + 'admission_crm/remove_from_waitlist', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'}, body:new URLSearchParams({id:id}).toString() })
    .then(function(r){return r.json();}).then(function(d){showAlert(d.message,d.status==='success'?'success':'error');if(d.status==='success')loadWaitlist();});
}

function showAlert(msg,type){var el=document.getElementById('pageAlert');el.className='ac-alert ac-alert-'+type;el.textContent=msg;el.style.display='block';setTimeout(function(){el.style.display='none';},4000);}
function esc(s){var d=document.createElement('div');d.textContent=(s===null||s===undefined)?'':s;return d.innerHTML;}
</script>
