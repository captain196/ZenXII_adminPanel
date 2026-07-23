<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
// ── Level gating ── The regularization LIST is view; DECIDING a request
// (approve/reject) is a MANAGE action. Without manage the list stays fully
// visible but the approve/reject controls are replaced with a view-only note.
$att_can_edit   = function_exists('has_permission') ? has_permission('Attendance', 'edit')   : true;
$att_can_manage = function_exists('has_permission') ? has_permission('Attendance', 'manage') : true;
?>

<!-- Attendance Design System (shared, cacheable) — provides the loading/progress
     primitives (att-loadbar, att-spin, att-loading-overlay). This page keeps its
     own sr-* card vocabulary; the att-* classes are used only for loading UI. -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.1.0">

<style>
.sr-wrap { --sr-accent:var(--att-primary,#BC5A3C); --sr-green:var(--att-green,#16a34a); --sr-red:var(--att-red,#dc2626); --sr-amber:var(--att-amber,#d97706);
    font-family: var(--att-font,var(--font-b,'Plus Jakarta Sans',sans-serif)); color: var(--att-t1,#1f2933); max-width: 1000px; }
.sr-banner { display:flex; align-items:center; gap:9px; font-size:13px; font-weight:600;
    color: var(--att-amber,#d97706); background: var(--att-amber-bg,rgba(217,119,6,.12));
    border: 1px solid rgba(217,119,6,.25); border-radius: 11px; padding: 11px 16px; margin: 0 0 18px; }
.sr-hdr { display:flex; align-items:flex-start; gap:14px; margin-bottom: 18px; }
.sr-hdr .sr-ic { width:44px; height:44px; flex:0 0 44px; border-radius:12px; display:flex; align-items:center; justify-content:center;
    background: var(--att-primary-dim,rgba(188,90,60,.12)); color: var(--sr-accent); font-size:20px; }
.sr-hdr h4 { font-weight: 800; margin: 0 0 4px; font-size: 19px; }
.sr-hdr p { font-size: 13px; color: var(--t3,#5a6b7d); margin: 0; line-height:1.5; }
.sr-hdr .sr-refresh { margin-left:auto; font:inherit; font-size:13px; font-weight:600; cursor:pointer;
    background: var(--bg2,#f4f7fb); border:1px solid var(--border,#d8e0ea); color: var(--t2,#3a4a5b);
    padding:8px 14px; border-radius:9px; display:flex; align-items:center; gap:7px; }
.sr-refresh:hover { border-color: var(--sr-accent); color: var(--sr-accent); }

.sr-tabs { display: flex; gap: 8px; margin: 0 0 18px; flex-wrap: wrap; }
.sr-tab { font: inherit; font-size: 13px; font-weight: 600; border: 1px solid var(--border,#d8e0ea);
    background: var(--bg2,#f4f7fb); color: var(--t2,#3a4a5b); padding: 8px 16px; border-radius: 999px; cursor: pointer; display:flex; gap:7px; align-items:center; }
.sr-tab .sr-cnt { font-size:11px; font-weight:800; min-width:18px; text-align:center; padding:1px 6px; border-radius:999px;
    background: var(--att-primary-dim,rgba(188,90,60,.12)); color: var(--sr-accent); }
.sr-tab.active { background: var(--sr-accent); color: #fff; border-color: var(--sr-accent); }
.sr-tab.active .sr-cnt { background: rgba(255,255,255,.25); color:#fff; }

.sr-list { display: flex; flex-direction: column; gap: 12px; }
.sr-card { background: var(--bg2,#fff); border: 1px solid var(--border,#d8e0ea); border-radius: 14px; padding: 16px 18px;
    transition: box-shadow .15s, border-color .15s; }
.sr-card:hover { box-shadow: 0 3px 14px rgba(15,59,111,.08); }
.sr-card-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sr-av { width:34px; height:34px; border-radius:50%; flex:0 0 34px; display:flex; align-items:center; justify-content:center;
    background: var(--att-primary-dim,rgba(188,90,60,.12)); color: var(--sr-accent); font-weight:800; font-size:14px; }
.sr-name { font-weight: 800; font-size: 15px; }
.sr-sid { font-family: var(--font-m,monospace); font-size: 12px; color: var(--t3,#5a6b7d); background: var(--bg3,#eef2f7); padding:2px 8px; border-radius:6px; }
.sr-pill { font-size: 11px; font-weight: 800; padding: 3px 11px; border-radius: 999px; margin-left: auto; text-transform: capitalize; }
.sr-pill.pending { color: var(--sr-amber); background: rgba(217,119,6,.14); }
.sr-pill.approved { color: var(--sr-green); background: rgba(22,163,74,.14); }
.sr-pill.rejected, .sr-pill.auto_rejected { color: var(--sr-red); background: rgba(220,38,38,.14); }
.sr-pill.cancelled { color: var(--t3,#5a6b7d); background: rgba(120,130,145,.14); }
.sr-reason { font-size: 13.5px; color: var(--t2,#3a4a5b); margin: 12px 0 2px; line-height:1.55; }
.sr-reason em { color: var(--t3,#5a6b7d); }
.sr-meta { font-size:11.5px; color: var(--t3,#5a6b7d); margin-top:2px; }
.sr-dates { display: flex; flex-wrap: wrap; gap: 7px; margin: 12px 0 2px; }
.sr-date { font-size: 12px; font-family: var(--font-m,monospace); font-weight:600; background: var(--bg3,#eef2f7);
    border: 1px solid var(--border,#d8e0ea); border-radius: 8px; padding: 4px 10px; color: var(--t1,#1f2933); }
.sr-date small { color: var(--sr-accent); margin-left: 6px; font-weight:700; }
.sr-actions { display: flex; gap: 8px; align-items: center; margin-top: 14px; flex-wrap: wrap;
    border-top:1px dashed var(--border,#d8e0ea); padding-top:14px; }
.sr-actions label { font-size:11.5px; font-weight:700; color:var(--t3,#5a6b7d); }
.sr-actions select.sr-mark { font: inherit; font-size: 13px; padding: 8px 10px;
    border: 1px solid var(--border,#d8e0ea); border-radius: 9px; background: var(--bg,#fff); color: var(--t1,#1f2933); }
.sr-actions input.sr-remarks { flex: 1; min-width: 160px; font: inherit; font-size: 13px;
    padding: 8px 12px; border: 1px solid var(--border,#d8e0ea); border-radius: 9px; background: var(--bg,#fff); color: var(--t1,#1f2933); }
.sr-actions input.sr-remarks:focus, .sr-actions select.sr-mark:focus { outline:none; border-color:var(--sr-accent); box-shadow:0 0 0 3px var(--att-primary-ring,rgba(188,90,60,.25)); }
.sr-btn { font: inherit; font-size: 13px; font-weight: 700; border: 0; border-radius: 9px; padding: 9px 18px; cursor: pointer; }
.sr-btn.approve { background: var(--sr-green); color: #fff; }
.sr-btn.reject { background: transparent; color: var(--sr-red); border: 1px solid rgba(220,38,38,.5); }
.sr-btn.approve:hover { filter:brightness(1.05); } .sr-btn.reject:hover { background: rgba(220,38,38,.08); }
.sr-btn:disabled { opacity: .5; cursor: default; }
.sr-state { text-align: center; color: var(--t3,#5a6b7d); font-size: 14px; padding: 48px 0; }
.sr-state .sr-emoji { font-size: 30px; display:block; margin-bottom:8px; opacity:.7; }
.sr-spin { width:22px; height:22px; border:3px solid var(--border,#d8e0ea); border-top-color:var(--sr-accent);
    border-radius:50%; animation:sr-rot .8s linear infinite; display:inline-block; }
@keyframes sr-rot { to { transform: rotate(360deg); } }
.sr-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
    background: var(--t1,#1f2933); color: var(--bg,#fff); font-size: 13px; font-weight: 700; padding: 11px 20px;
    border-radius: 11px; opacity: 0; transition: opacity .2s; z-index: 9999; pointer-events: none; box-shadow:0 6px 20px rgba(0,0,0,.25); }
.sr-toast.show { opacity: 1; }
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

<!-- Top progress bar — driven by a busy counter inside the request helper -->
<div class="att-loadbar" id="attLoadbar"></div>

<div class="sr-wrap">
    <div class="sr-hdr">
        <div class="sr-ic"><i class="fa-solid fa-user-clock"></i></div>
        <div>
            <h4>Staff Attendance Regularization</h4>
            <p>GPS self-attendance correction requests filed by staff from the app. Approving stamps the corrected day onto their attendance record.</p>
        </div>
        <button class="sr-refresh" id="srRefresh"><i class="fa-solid fa-rotate"></i> Refresh</button>
    </div>

    <?php if (!$att_can_manage): ?>
    <div class="sr-banner" role="status"><i class="fa-solid fa-lock"></i> View-only — you can review regularization requests, but approving or rejecting needs Attendance manage access.</div>
    <?php endif; ?>

    <div class="sr-tabs" id="srTabs">
        <button class="sr-tab active" data-status="pending">Pending <span class="sr-cnt" id="cnt-pending">·</span></button>
        <button class="sr-tab" data-status="approved">Approved</button>
        <button class="sr-tab" data-status="rejected">Rejected</button>
        <button class="sr-tab" data-status="all">All</button>
    </div>

    <div class="sr-list" id="srList">
        <div class="sr-state"><span class="sr-spin"></span></div>
    </div>
</div>

</div><!-- /.container-fluid -->
</section>
</div><!-- /.content-wrapper -->

<div class="sr-toast" id="srToast"></div>

<script>
/* Native DOM + fetch (NO jQuery). jQuery loads in the footer AFTER this inline
   script, so any $() here would throw and kill the page (blank list, dead tabs).
   `list` stays CSRF-excluded (read-only, also hit by the Approvals badge); the
   state-changing `decide` is CSRF-protected, so every POST carries the token. */
(function () {
    var LIST_URL   = '<?= site_url('attendance/staff_regularization/list') ?>';
    var DECIDE_URL = '<?= site_url('attendance/staff_regularization/decide') ?>';
    var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH  = '<?= $this->security->get_csrf_hash() ?>';
    var CAN_MANAGE = <?= $att_can_manage ? 'true' : 'false' ?>;
    var state = { status: 'pending' };
    var listEl = document.getElementById('srList');

    function esc(s){ var d=document.createElement('div'); d.textContent = (s==null?'':String(s)); return d.innerHTML; }

    // Top progress bar — busy counter so the bar stays lit while ANY request is
    // in flight and clears only when the last one settles.
    var _busy = 0;
    function busyStart(){ _busy++; var b = document.getElementById('attLoadbar'); if (b) b.classList.add('on'); }
    function busyEnd(){ _busy = Math.max(0, _busy - 1); if (!_busy){ var b = document.getElementById('attLoadbar'); if (b) b.classList.remove('on'); } }
    function initial(n){ n=(n||'?').trim(); return n?n.charAt(0).toUpperCase():'?'; }
    function fmtTime(iso){ if(!iso) return ''; try{ var d=new Date(iso); if(isNaN(d.getTime()))return ''; return d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});}catch(e){return '';} }
    function toast(msg){ var t=document.getElementById('srToast'); t.textContent=msg; t.classList.add('show'); setTimeout(function(){t.classList.remove('show');},2600); }
    function stateBox(emoji,msg){ return '<div class="sr-state"><span class="sr-emoji">'+emoji+'</span>'+esc(msg)+'</div>'; }

    // Container loading state: shimmer skeleton cards (att-skel) while the list
    // loads — reads as real in-progress content, not a blank flash or lone spinner.
    function skelList(){
        var card = '<div class="sr-card" aria-hidden="true">'
            + '<div class="att-skel att-skel-row" style="width:38%;height:18px;margin:2px 0 12px"></div>'
            + '<div class="att-skel att-skel-row" style="width:82%"></div>'
            + '<div class="att-skel att-skel-row" style="width:26%;height:26px;border-radius:8px;margin-top:12px"></div>'
            + '</div>';
        return card + card + card;
    }

    function post(url, params){
        var body = new URLSearchParams();
        Object.keys(params).forEach(function(k){ body.append(k, params[k]); });
        // Always attach the CSRF token — required by the protected `decide`
        // route, harmlessly ignored by the excluded `list` route.
        if (CSRF_NAME) body.append(CSRF_NAME, CSRF_HASH);
        // Fail-closed: fetch resolves even on 403/401/500, so reject unless the
        // HTTP status is ok AND the payload isn't an explicit error. Callers show
        // e.message. Refresh the CSRF token if the server rotated it.
        busyStart();
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function(r){
            return r.json().catch(function(){ return {}; }).then(function(j){
                if (j && j.csrf_token) CSRF_HASH = j.csrf_token;
                if (j && j.csrf_hash)  CSRF_HASH = j.csrf_hash;
                if (!r.ok || (j && (j.status === 'error' || j.success === false))) {
                    throw new Error((j && (j.message || j.error)) || ('Request failed (' + r.status + ')'));
                }
                return j;
            });
        }).finally(busyEnd);
    }

    function render(batches){
        var keys = Object.keys(batches || {});
        var cnt = document.getElementById('cnt-pending');
        if (state.status === 'pending' && cnt) cnt.textContent = keys.length;
        if (!keys.length){ listEl.innerHTML = stateBox('📭','No '+state.status+' requests.'); return; }
        var html = '';
        keys.forEach(function(bid){
            var items = batches[bid]; if (!items || !items.length) return;
            var first = items[0];
            var status = (first.status || 'pending').toLowerCase();
            var datesHtml = items.map(function(it){
                var ci = fmtTime(it.claimedCheckInAt), co = fmtTime(it.claimedCheckOutAt);
                var times = (ci || co) ? ' <small>'+esc(ci)+(co?' – '+esc(co):'')+'</small>' : '';
                return '<span class="sr-date">'+esc(it.date)+times+'</span>';
            }).join('');
            var actions = '';
            if (status === 'pending' && CAN_MANAGE){
                actions = '<div class="sr-actions">'+
                    '<label>Apply as</label>'+
                    '<select class="sr-mark"><option value="">Present</option><option value="T">Late (T)</option><option value="M">Half-day (M)</option><option value="L">Leave (L)</option><option value="W">Work-on-off (W)</option></select>'+
                    '<input class="sr-remarks" type="text" placeholder="Remarks (optional)">'+
                    '<button class="sr-btn reject" data-batch="'+esc(bid)+'" data-decision="reject">Reject</button>'+
                    '<button class="sr-btn approve" data-batch="'+esc(bid)+'" data-decision="approve">Approve</button>'+
                '</div>';
            } else if (status === 'pending'){
                actions = '<div class="sr-actions"><span class="sr-meta"><i class="fa-solid fa-lock"></i> View-only — approving needs manage access.</span></div>';
            } else if (first.remarks){
                actions = '<div class="sr-reason"><em>Admin remarks: '+esc(first.remarks)+'</em></div>';
            }
            var appliedMark = first.appliedMark ? (' · applied as '+esc(first.appliedMark)) : '';
            html += '<div class="sr-card">'+
                '<div class="sr-card-top">'+
                    '<span class="sr-av">'+esc(initial(first.staffName||first.staffId))+'</span>'+
                    '<span class="sr-name">'+esc(first.staffName||first.staffId)+'</span>'+
                    '<span class="sr-sid">'+esc(first.staffId)+'</span>'+
                    '<span class="sr-pill '+esc(status)+'">'+esc(status.replace('_',' '))+'</span>'+
                '</div>'+
                '<div class="sr-reason">'+esc(first.reason||'(no reason given)')+'</div>'+
                '<div class="sr-meta">'+items.length+' day'+(items.length===1?'':'s')+' requested'+appliedMark+'</div>'+
                '<div class="sr-dates">'+datesHtml+'</div>'+
                actions+
            '</div>';
        });
        listEl.innerHTML = html;
    }

    function load(){
        listEl.innerHTML = skelList();
        post(LIST_URL, { status: state.status }).then(function(res){
            // json_success() merges data at the TOP LEVEL — batches is res.batches,
            // NOT res.data.batches. `batches` is always present (even {}) on success.
            if (res && res.batches !== undefined){ render(res.batches || {}); }
            else { listEl.innerHTML = stateBox('⚠️', (res && res.message) || 'Could not load requests.'); }
        }).catch(function(e){ listEl.innerHTML = stateBox('⚠️', (e && e.message) || 'Could not load requests. Reload the page and try again.'); });
    }

    document.getElementById('srTabs').addEventListener('click', function(e){
        var btn = e.target.closest('.sr-tab'); if (!btn) return;
        this.querySelectorAll('.sr-tab').forEach(function(t){ t.classList.remove('active'); });
        btn.classList.add('active');
        state.status = btn.getAttribute('data-status');
        load();
    });
    document.getElementById('srRefresh').addEventListener('click', load);

    listEl.addEventListener('click', function(e){
        var btn = e.target.closest('.sr-btn'); if (!btn || btn.disabled) return;
        if (!CAN_MANAGE) { toast("View-only — you don't have manage access."); return; }
        var bid = btn.getAttribute('data-batch'), decision = btn.getAttribute('data-decision');
        if (decision === 'reject' && !confirm('Reject this regularization request?')) return;
        var card = btn.closest('.sr-card');
        var remarksEl = card.querySelector('.sr-remarks'), markEl = card.querySelector('.sr-mark');
        var remarks = remarksEl ? remarksEl.value : '', mark = markEl ? markEl.value : '';
        // Loading feedback: disable both buttons and show a spinner on the one
        // that was clicked until the decide round-trip resolves.
        var btnOrig = btn.innerHTML;
        card.querySelectorAll('.sr-btn').forEach(function(b){ b.disabled = true; });
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (decision === 'approve' ? 'Approving…' : 'Rejecting…');
        function restoreBtns(){
            card.querySelectorAll('.sr-btn').forEach(function(b){ b.disabled = false; });
            btn.innerHTML = btnOrig;
        }
        post(DECIDE_URL, { batch_id: bid, decision: decision, remarks: remarks, mark: mark }).then(function(res){
            if (res && res.status === 'success'){
                // appliedCount / skippedCount are TOP-LEVEL (json_success merge), not res.data.*
                toast(decision === 'approve'
                    ? ('Approved '+(res.appliedCount||0)+' day(s)'+(res.skippedCount?', '+res.skippedCount+' skipped':''))
                    : 'Request rejected');
                load();
            } else {
                toast((res && res.message) || 'Action failed');
                restoreBtns();
            }
        }).catch(function(e){
            toast((e && e.message) || 'Action failed');
            restoreBtns();
        });
    });

    load();
})();
</script>
