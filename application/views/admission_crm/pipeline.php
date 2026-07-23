<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size:16px !important; }
.ac-wrap { padding:24px 22px 52px; min-height:100vh; }

/* ── Header ── */
.ac-head { display:flex; align-items:center; gap:14px; padding:18px 22px; margin-bottom:16px;
    background:var(--bg2); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); }
.ac-head-icon { width:44px; height:44px; border-radius:10px; background:var(--gold);
    display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 0 18px var(--gold-glow); }
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; transition:color var(--ease); }
.ac-back:hover { color:var(--gold); }
.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

/* ── Board toolbar ── */
.np-tools { display:flex; align-items:center; gap:9px; flex-wrap:wrap; margin-bottom:16px; }
.np-search { display:flex; align-items:center; gap:8px; background:var(--bg2); border:1px solid var(--border);
    border-radius:9px; padding:8px 12px; color:var(--t3); font-size:13px; min-width:200px; }
.np-search input { border:0; background:none; outline:none; color:var(--t1); font-size:13px; font-family:var(--font-b); width:100%; }
.np-select { padding:8px 12px; border:1px solid var(--border); border-radius:9px; background:var(--bg2); color:var(--t1);
    font-size:13px; font-family:var(--font-b); outline:none; }
.np-select:focus { border-color:var(--gold); }
.np-chip { border:1px solid var(--border); background:var(--bg2); color:var(--t2); font-size:12.5px; font-weight:600;
    padding:8px 13px; border-radius:20px; cursor:pointer; display:inline-flex; align-items:center; gap:7px; font-family:var(--font-b); transition:all var(--ease); }
.np-chip:hover { border-color:var(--gold-ring); color:var(--t1); }
.np-chip.on { background:var(--gold-dim); border-color:transparent; color:var(--gold); }
.np-chip .n { font-size:11px; font-weight:800; background:var(--bg3); border-radius:20px; padding:0 6px; min-width:16px; text-align:center; }
.np-chip.on .n { background:var(--gold); color:#fff; }
.np-seg { display:inline-flex; background:var(--bg3); border:1px solid var(--border); border-radius:9px; padding:3px; margin-left:auto; }
.np-seg button { border:0; background:none; color:var(--t2); font-size:12px; font-weight:600; padding:7px 12px; border-radius:7px; cursor:pointer; font-family:var(--font-b); }
.np-seg button.on { background:var(--bg2); color:var(--gold); box-shadow:0 1px 2px rgba(0,0,0,.08); }

/* ── Board ── */
.np-board { display:flex; gap:14px; overflow-x:auto; padding:2px 2px 18px; align-items:flex-start; }
.np-board::-webkit-scrollbar { height:8px; }
.np-board::-webkit-scrollbar-thumb { background:var(--border); border-radius:4px; }
.np-col { min-width:270px; flex:1; background:var(--bg2); border:1px solid var(--border); border-radius:14px;
    display:flex; flex-direction:column; transition:background var(--ease), border-color var(--ease); }
.np-col.drop { background:var(--gold-dim); border-color:var(--gold); }
.np-col-h { display:flex; align-items:center; gap:9px; padding:13px 15px 9px; }
.np-dot { width:9px; height:9px; border-radius:50%; flex:0 0 auto; }
.np-col-nm { font-size:13px; font-weight:700; color:var(--t1); font-family:var(--font-b); }
.np-col-ct { font-size:12.5px; font-weight:800; }
.np-aging { margin-left:auto; font-size:10.5px; font-weight:800; color:#C0402E; background:rgba(219,90,72,.12);
    border-radius:20px; padding:2px 8px; display:inline-flex; align-items:center; gap:3px; }
/* No inner scroll — columns grow with content and the PAGE scrolls as one. */
.np-col-b { padding:3px 11px 8px; display:flex; flex-direction:column; gap:10px; flex:1; min-height:60px; }
.np-empty { border:1.5px dashed var(--border); border-radius:11px; padding:22px 8px; text-align:center; color:var(--t3); font-size:12px; }

.np-card { background:var(--bg2); border:1px solid var(--border); border-radius:14px; padding:13px 14px;
    box-shadow:0 1px 2px rgba(60,50,45,.05); cursor:grab; position:relative;
    transition:transform .14s cubic-bezier(.2,.7,.3,1), box-shadow .14s, border-color .14s; }
.np-card:hover { box-shadow:0 8px 22px rgba(60,50,45,.11); border-color:var(--gold-ring); transform:translateY(-2px); }
.np-card:active { cursor:grabbing; }
.np-card.dragging { opacity:.55; transform:rotate(2deg) scale(.97); }
.np-labels { display:flex; align-items:center; gap:8px; margin-bottom:11px; }
.np-lab { font-size:11px; font-weight:700; padding:3px 10px; border-radius:8px; white-space:nowrap; }
.np-src { font-size:11px; color:var(--t3); font-weight:500; margin-left:auto; white-space:nowrap; }
.np-name { font-weight:650; font-size:14.5px; line-height:1.25; letter-spacing:-.012em; color:var(--t1); }
.np-id { font-size:11.5px; color:var(--t3); margin-top:3px; font-weight:500; }
.np-foot { display:flex; align-items:center; gap:8px; margin-top:13px; }
.np-av { width:27px; height:27px; border-radius:8px; display:grid; place-items:center; font-weight:700; font-size:10.5px; color:#fff; flex:0 0 auto; }
.np-pill { font-size:11px; font-weight:650; padding:3px 9px; border-radius:20px; display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
.np-moves { display:flex; gap:5px; flex-wrap:wrap; margin-top:11px; padding-top:10px; border-top:1px solid var(--border); }
.np-move { padding:4px 9px; border-radius:6px; border:1px solid var(--border); background:var(--bg3); color:var(--t2);
    font-size:10.5px; font-weight:600; cursor:pointer; font-family:var(--font-b); transition:all var(--ease); }
.np-move:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }
.np-card:focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-color:var(--gold-ring); }
@media (hover:hover) { .np-moves { opacity:0; max-height:0; margin:0; padding:0; border:0; overflow:hidden; transition:opacity .12s; }
    /* Reveal the move buttons on keyboard focus too, not just mouse hover, so the
       board is operable without a pointer (drag-and-drop has no keyboard path). */
    .np-card:hover .np-moves, .np-card:focus-within .np-moves { opacity:1; max-height:120px; margin-top:11px; padding-top:10px; border-top:1px solid var(--border); } }

.np-board.compact .np-src, .np-board.compact .np-id { display:none; }
.np-board.compact .np-labels { margin-bottom:8px; }
.np-board.compact .np-name { font-size:13px; }
.np-board.compact .np-foot { margin-top:9px; }

@media(max-width:767px){ .ac-head { flex-wrap:wrap; } .np-board { gap:10px; } .np-col { min-width:240px; } }
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Overview</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-columns"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Applications — Board</div>
            <div class="ac-head-sub">Session <?= htmlspecialchars($session_year) ?> — drag applicants through stages</div>
        </div>
    </div>

    <!-- View switcher (IA reorg 2026-07): Board is a view of Applications. -->
    <div class="ac-viewsw" style="display:inline-flex;gap:4px;background:var(--bg3,#f1f0ed);border:1px solid var(--border,#e6e2dc);border-radius:10px;padding:4px;margin-bottom:16px;flex-wrap:wrap;">
        <a href="<?= base_url('sis/applications') ?>" style="display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;font-size:13px;font-weight:600;color:var(--t2,#5b524b);text-decoration:none;"><i class="fa fa-table"></i> Table</a>
        <span style="display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;font-size:13px;font-weight:600;background:var(--card,#fff);color:var(--gold,#BC5A3C);box-shadow:0 1px 2px rgba(0,0,0,.07);"><i class="fa fa-columns"></i> Board</span>
        <a href="<?= base_url('sis/admission_leads') ?>" style="display:inline-flex;align-items:center;gap:7px;padding:7px 15px;border-radius:7px;font-size:13px;font-weight:600;color:var(--t2,#5b524b);text-decoration:none;"><i class="fa fa-filter"></i> Funnel</a>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <!-- Board toolbar -->
    <div class="np-tools">
        <div class="np-search"><i class="fa fa-search"></i><input id="boardSearch" placeholder="Search applicant…" oninput="onBoardSearch(this.value)"></div>
        <select id="filterClass" class="np-select" onchange="renderPipeline()">
            <option value="">All Classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="np-chip on" data-flag="all" onclick="setFlag('all',this)">All</button>
        <button class="np-chip" data-flag="attention" onclick="setFlag('attention',this)">&#9873; Needs attention <span class="n" id="cntAtt">0</span></button>
        <button class="np-chip" data-flag="unpaid" onclick="setFlag('unpaid',this)">&#9888; Fee due <span class="n" id="cntUnpaid">0</span></button>
        <div class="np-seg">
            <button class="on" data-d="comfortable" onclick="setDensity('comfortable',this)">Comfortable</button>
            <button data-d="compact" onclick="setDensity('compact',this)">Compact</button>
        </div>
    </div>

    <div class="np-board" id="pipelineBoard">
        <div style="text-align:center;padding:80px 0;color:var(--t3);width:100%;font-size:13px;">
            <i class="fa fa-spinner fa-spin" style="font-size:2rem;display:block;margin-bottom:12px;"></i>Loading board…
        </div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var pipelineData = {}, stagesData = {};
var bState = { q:'', flag:'all', density:'comfortable' };
var dragStage = null;

var STAGE_COL = { document_collection:'#4E82E0', under_review:'#DD9433', interview:'#9576DA',
    approved:'#2FA875', rejected:'#DB5A48', waitlisted:'#8E72C8', enrolled:'#2AA79C' };
var AV = ['#4E82E0','#DD9433','#9576DA','#2FA875','#C05A3B','#3BB0C9','#E06A9B','#6E8AA8','#5FA85C','#B77BD8','#D0596B','#3F9AA8'];

function stageColor(k){ return STAGE_COL[k] || '#C05A3B'; }
function hashCode(s){ s=String(s); var h=0; for(var i=0;i<s.length;i++){ h=(h*31+s.charCodeAt(i))|0; } return Math.abs(h); }
function classColor(c){ return AV[hashCode(c||'x') % AV.length]; }
/* Avatar colour is keyed by NAME with the SAME palette+hash as the Table view
   (applications.php avColor), so a given applicant shows one identity colour
   across Table and Board. (The class chip stays coloured by class.) */
function avColorName(s){ var P=['#4E82E0','#DD9433','#9576DA','#2FA875','#C05A3B','#3BB0C9','#E06A9B','#6E8AA8'];
    s=String(s||''); var h=0; for(var i=0;i<s.length;i++){ h=(h*31+s.charCodeAt(i))&0x7fffffff; } return P[h%P.length]; }
function tint(hex,a){ var h=(hex||'#888').replace('#',''); if(h.length===3) h=h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
    var n=parseInt(h,16); return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')'; }
function initials(n){ n=String(n||'').trim(); if(!n) return '?'; var p=n.split(/\s+/);
    return ((p[0].charAt(0)||'') + (p.length>1 ? p[p.length-1].charAt(0) : '')).toUpperCase(); }
function daysSince(d){ if(!d) return null; var t=Date.parse(String(d).replace(' ','T')); if(isNaN(t)) return null;
    return Math.max(0, Math.floor((Date.now()-t)/86400000)); }
function srcMap(s){ s=String(s||'').toLowerCase();
    if(s.indexOf('public')>-1 || s.indexOf('online')>-1) return '🌐 Online';
    if(s.indexOf('walk')>-1 || s.indexOf('admin')>-1) return '🚶 Walk-in';
    if(s.indexOf('refer')>-1) return '👥 Referral';
    return s ? ('• ' + s.replace(/_/g,' ')) : ''; }

document.addEventListener('DOMContentLoaded', function(){ loadPipeline(); });

function loadPipeline(){
    fetch(BASE + 'admission_crm/fetch_pipeline', {
        method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest' }, body:''
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.status === 'success') { pipelineData = data.pipeline || {}; stagesData = data.stages || {}; renderPipeline(); }
        else {
            document.getElementById('pipelineBoard').innerHTML =
              '<div style="text-align:center;padding:80px 0;color:var(--t3);width:100%;font-size:13px;"><i class="fa fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:8px;"></i>' + String(data.message || 'Could not load board').replace(/</g,'&lt;') + '</div>';
        }
    })
    .catch(function(){
        document.getElementById('pipelineBoard').innerHTML =
          '<div style="text-align:center;padding:80px 0;color:var(--t3);width:100%;font-size:13px;"><i class="fa fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:8px;"></i>Failed to load board</div>';
    });
}

function passesFilter(item){
    if (bState.q && String(item.student_name||'').toLowerCase().indexOf(bState.q) === -1
        && String(item.application_id||item.id||'').toLowerCase().indexOf(bState.q) === -1) return false;
    var age = daysSince(item.updated_at || item.created_at);
    if (bState.flag === 'attention' && !(age !== null && age > 7)) return false;
    if (bState.flag === 'unpaid') { var ps=String(item.payment_status||'').toLowerCase(); if (ps === '' || ps === 'paid') return false; }
    return true;
}

function renderPipeline(){
    var cf = document.getElementById('filterClass').value;
    var board = document.getElementById('pipelineBoard');
    var stageKeys = Object.keys(pipelineData);

    // Live filter-chip counts (whole board, class-scoped, ignoring the flag).
    var att=0, unpaid=0;
    stageKeys.forEach(function(sk){
        (pipelineData[sk].items || []).forEach(function(i){
            if (cf && i.class !== cf) return;
            var age = daysSince(i.updated_at || i.created_at); if (age !== null && age > 7) att++;
            var ps = String(i.payment_status||'').toLowerCase(); if (ps !== '' && ps !== 'paid') unpaid++;
        });
    });
    var ea=document.getElementById('cntAtt'); if(ea) ea.textContent=att;
    var eu=document.getElementById('cntUnpaid'); if(eu) eu.textContent=unpaid;

    board.className = 'np-board' + (bState.density === 'compact' ? ' compact' : '');
    var html = '';
    stageKeys.forEach(function(sk){
        var stage = pipelineData[sk];
        var color = stageColor(sk);
        var items = (stage.items || []).filter(function(i){ return (!cf || i.class === cf) && passesFilter(i); });
        items.sort(function(a,b){ return (daysSince(b.updated_at||b.created_at)||0) - (daysSince(a.updated_at||a.created_at)||0); });
        var aging = items.filter(function(i){ var d=daysSince(i.updated_at||i.created_at); return d!==null && d>7; }).length;

        html += '<div class="np-col" data-stage="' + esc(sk) + '">';
        html += '<div class="np-col-h"><span class="np-dot" style="background:' + color + '"></span>';
        html += '<span class="np-col-nm">' + esc(stage.label) + '</span>';
        html += '<span class="np-col-ct" style="color:' + color + '">' + items.length + '</span>';
        if (aging) html += '<span class="np-aging">&#9873; ' + aging + ' aging</span>';
        html += '</div><div class="np-col-b">';
        if (items.length === 0) {
            html += '<div class="np-empty">' + ((bState.q||bState.flag!=='all'||cf) ? 'No matches here' : 'Drop an applicant here') + '</div>';
        } else {
            items.forEach(function(item){ html += cardHtml(item, sk); });
        }
        html += '</div></div>';
    });
    board.innerHTML = html;
    wireDnd();
}

function cardHtml(item, sk){
    var col = classColor(item.class);
    var cls = esc(item.class || '-') + (item.section ? (' &middot; ' + esc(item.section)) : '');
    var age = daysSince(item.updated_at || item.created_at);
    var agePill = '';
    if (age !== null) {
        var ac = age > 14 ? '#C0402E' : age > 7 ? '#B5741F' : '#8A8178';
        agePill = '<span class="np-pill" style="background:' + tint(ac, .13) + ';color:' + ac + '" title="' + age + ' days since applied">&#9200; ' + age + 'd</span>';
    }
    var ps = String(item.payment_status || '').toLowerCase();
    var feePill = ps === 'paid'
        ? '<span class="np-pill" style="background:' + tint('#7d8a7f', .12) + ';color:#6a8a72">&#10003; Paid</span>'
        : (ps !== '' ? '<span class="np-pill" style="background:' + tint('#B5741F', .14) + ';color:#B5741F">&#9888; Due</span>' : '');
    var src = srcMap(item.source);

    // Move chips — every non-terminal stage except the current one and
    // 'rejected' (rejection routes through the Table with a reason).
    var moves = '';
    Object.keys(pipelineData).forEach(function(t){
        // 'rejected' routes through the Table (needs a reason); 'waitlisted'
        // routes through the Table's Waitlist action (creates the crmWaitlist
        // doc). Dragging into either column here would desync the two stores.
        if (t === sk || t === 'rejected' || t === 'waitlisted') return;
        moves += '<button class="np-move" onclick="moveStage(\'' + esc(item.id) + '\',\'' + esc(t) + '\')">&rarr; ' + esc(pipelineData[t].label) + '</button>';
    });

    var stageLabel = (pipelineData[sk] && pipelineData[sk].label) ? pipelineData[sk].label : sk;
    var h = '<div class="np-card" draggable="true" tabindex="0" role="group"'
          + ' aria-label="' + esc(item.student_name) + ', ' + esc(item.class || '') + ', stage ' + esc(stageLabel) + '. Press Tab for move options."'
          + ' data-id="' + esc(item.id) + '" data-stage="' + esc(sk) + '"';
    h += ' ondragstart="onDragStart(event)" ondragend="onDragEnd(event)">';
    h += '<div class="np-labels"><span class="np-lab" style="background:' + tint(col, .14) + ';color:' + col + '">' + cls + '</span>';
    if (src) h += '<span class="np-src">' + esc(src) + '</span>';
    h += '</div>';
    h += '<div class="np-name">' + esc(item.student_name) + '</div>';
    h += '<div class="np-id">' + esc(item.application_id || item.id || '') + '</div>';
    h += '<div class="np-foot"><div class="np-av" style="background:' + avColorName(item.student_name) + '">' + esc(initials(item.student_name)) + '</div>';
    h += agePill + feePill + '</div>';
    if (moves) h += '<div class="np-moves">' + moves + '</div>';
    h += '</div>';
    return h;
}

/* ── Drag & drop ── */
function onDragStart(e){
    var card = e.target.closest('.np-card'); if(!card) return;
    dragStage = card.getAttribute('data-stage');
    e.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
    e.dataTransfer.effectAllowed = 'move';
    setTimeout(function(){ card.classList.add('dragging'); }, 0);
}
function onDragEnd(e){ var c=e.target.closest('.np-card'); if(c) c.classList.remove('dragging'); dragStage=null; }
function wireDnd(){
    document.querySelectorAll('#pipelineBoard .np-col').forEach(function(col){
        col.addEventListener('dragover', function(e){ e.preventDefault(); col.classList.add('drop'); });
        col.addEventListener('dragleave', function(e){ if(!col.contains(e.relatedTarget)) col.classList.remove('drop'); });
        col.addEventListener('drop', function(e){
            e.preventDefault(); col.classList.remove('drop');
            var id = e.dataTransfer.getData('text/plain');
            var target = col.getAttribute('data-stage');
            if (id && target && target !== dragStage && target !== 'rejected' && target !== 'waitlisted') moveStage(id, target);
        });
    });
}

function moveStage(id, newStage){
    // Optimistic LIVE move — update the local model + re-render instantly so
    // the card lands in the target column with no flash, THEN persist. If the
    // save fails, reload from the server to revert to the truth.
    if (applyLocalMove(id, newStage)) renderPipeline();
    fetch(BASE + 'admission_crm/update_stage', {
        method:'POST', headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest' },
        body: new URLSearchParams({ id:id, stage:newStage }).toString()
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        // Success needs NO toast — the card already moved live. Only surface a
        // problem (and revert to server truth) so there's no delayed pop-up.
        if (data.status !== 'success') { showAlert(data.message || 'Could not move — reverting.', 'error'); loadPipeline(); }
    })
    .catch(function(){ showAlert('Could not save the move — reverting.', 'error'); loadPipeline(); });
}

// Move a card between columns in the in-memory model, mirroring the backend's
// stage↔status lockstep (update_stage): approved/rejected set status too,
// moving out of a terminal stage returns to pending.
function applyLocalMove(id, newStage){
    var item = null;
    Object.keys(pipelineData).forEach(function(k){
        var arr = pipelineData[k].items || [];
        for (var i = 0; i < arr.length; i++){ if (arr[i].id === id){ item = arr[i]; arr.splice(i,1); break; } }
    });
    if (!item) return false;
    item.stage = newStage;
    if (newStage === 'approved') item.status = 'approved';
    else if (newStage === 'rejected') item.status = 'rejected';
    else if (item.status === 'approved' || item.status === 'rejected') item.status = 'pending';
    var d = new Date();
    item.updated_at = d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2)+' '+('0'+d.getHours()).slice(-2)+':'+('0'+d.getMinutes()).slice(-2)+':00';
    if (pipelineData[newStage]) pipelineData[newStage].items.push(item);
    else { var fk = Object.keys(pipelineData)[0]; if (fk) pipelineData[fk].items.push(item); }
    return true;
}

/* ── Toolbar handlers ── */
function onBoardSearch(v){ bState.q = String(v||'').trim().toLowerCase(); renderPipeline(); }
function setFlag(f, btn){ bState.flag = f;
    document.querySelectorAll('.np-chip').forEach(function(c){ c.classList.remove('on'); }); btn.classList.add('on'); renderPipeline(); }
function setDensity(d, btn){ bState.density = d;
    btn.parentNode.querySelectorAll('button').forEach(function(b){ b.classList.remove('on'); }); btn.classList.add('on'); renderPipeline(); }

function showAlert(msg, type){
    var el = document.getElementById('pageAlert');
    el.className = 'ac-alert ac-alert-' + type; el.textContent = msg; el.style.display = 'block';
    setTimeout(function(){ el.style.display = 'none'; }, 3000);
}
function esc(s){ var d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : s; return d.innerHTML; }
</script>
