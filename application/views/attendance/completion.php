<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<style>
.acx-wrap{max-width:1180px;margin:0 auto;padding:4px 2px}
.acx-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-bottom:16px}
.acx-bar label{font-size:12px;color:var(--att-t3,#8a94a0);font-weight:600}
.acx-bar input[type=date],.acx-bar select{height:34px;border:1px solid #d6dbe1;border-radius:6px;padding:0 10px;font-size:13px}
.acx-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
.acx-card{background:#fff;border:1px solid #e3e7ec;border-radius:8px;padding:14px 16px}
.acx-card .n{font-size:24px;font-weight:700}
.acx-card .l{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#8a94a0;margin-top:2px}
.acx-c-completed .n{color:#256b44}.acx-c-partial .n{color:#a15c00}.acx-c-not_started .n{color:#c0392b}
.acx-c-locked .n{color:#555}.acx-c-holiday .n{color:#2f6fb2}
.acx-tbl{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e3e7ec;border-radius:8px;overflow:hidden;font-size:13px}
.acx-tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#8a94a0;padding:10px 12px;border-bottom:1px solid #eef1f5;background:#fafbfc}
.acx-tbl td{padding:10px 12px;border-bottom:1px solid #f4f6f8}
.acx-pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:11px}
.acx-p-completed{background:#eef8f1;color:#256b44}.acx-p-partial{background:#fff4e0;color:#a15c00}
.acx-p-not_started{background:#fdecea;color:#c0392b}.acx-p-locked{background:#eef1f5;color:#555}
.acx-p-holiday{background:#e8f1fb;color:#2f6fb2}
.acx-empty{text-align:center;color:#8a94a0;padding:30px;font-size:14px}
.acx-note{background:#e8f1fb;border-left:3px solid #2f6fb2;padding:10px 14px;border-radius:0 4px 4px 0;font-size:13px;color:#2c4a66;margin-bottom:14px}
</style>

<div class="content-wrapper">
<section class="content-header"><h1>Attendance Completion <small>which sections have marked attendance</small></h1></section>
<section class="content acx-wrap">
  <div class="acx-note">Shows, per section, whether attendance was actually <strong>marked</strong> for the chosen date — so a section where nobody marked is distinguished from one where everyone was present. Derived live from the canonical attendance data; nothing is stored.</div>

  <div class="acx-bar">
    <div><label>Date</label><br><input type="date" id="acxDate"></div>
    <div><label>Status</label><br>
      <select id="acxFilter">
        <option value="">All</option>
        <option value="not_started">Not started</option>
        <option value="partial">Partial</option>
        <option value="completed">Completed</option>
        <option value="locked">Locked</option>
        <option value="holiday">Holiday</option>
      </select>
    </div>
    <div><label>Search</label><br><input type="text" id="acxSearch" placeholder="class / section" style="height:34px;border:1px solid #d6dbe1;border-radius:6px;padding:0 10px;font-size:13px"></div>
    <div style="align-self:flex-end"><button class="btn btn-primary btn-sm" id="acxReload" style="height:34px">Refresh</button></div>
  </div>

  <div id="acxHoliday" class="acx-note" style="display:none;background:#e8f1fb;border-left-color:#2f6fb2"></div>
  <div class="acx-cards" id="acxCards"></div>
  <div id="acxTableWrap"><div class="acx-empty">Loading…</div></div>
</section>
</div>

<script>
(function(){
  var BASE = <?= json_encode(rtrim(base_url(), '/') . '/') ?>;
  var dateEl = document.getElementById('acxDate');
  var today = new Date(); var tzoff = today.getTimezoneOffset()*60000;
  dateEl.value = new Date(today - tzoff).toISOString().slice(0,10);

  var LABEL = {completed:'Completed', partial:'Partial', not_started:'Not started', locked:'Locked', holiday:'Holiday'};
  var lastRows = [];

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

  function renderCards(sum){
    var order = ['not_started','partial','completed','locked','holiday'];
    document.getElementById('acxCards').innerHTML = order.map(function(k){
      return '<div class="acx-card acx-c-'+k+'"><div class="n">'+(sum[k]||0)+'</div><div class="l">'+LABEL[k]+'</div></div>';
    }).join('');
  }
  function renderTable(){
    var f = document.getElementById('acxFilter').value;
    var q = (document.getElementById('acxSearch').value||'').toLowerCase().trim();
    var rows = lastRows.filter(function(r){
      if (f && r.status!==f) return false;
      if (q && (r.className+' '+r.section).toLowerCase().indexOf(q)===-1) return false;
      return true;
    });
    if (!rows.length){ document.getElementById('acxTableWrap').innerHTML='<div class="acx-empty">No sections match.</div>'; return; }
    var html='<table class="acx-tbl"><thead><tr><th>Class</th><th>Section</th><th>Marked</th><th>Total</th><th>Status</th></tr></thead><tbody>';
    rows.forEach(function(r){
      html+='<tr><td>'+esc(r.className)+'</td><td>'+esc(r.section)+'</td><td>'+r.marked+'</td><td>'+r.total+'</td>'
          +'<td><span class="acx-pill acx-p-'+r.status+'">'+LABEL[r.status]+'</span></td></tr>';
    });
    html+='</tbody></table>';
    document.getElementById('acxTableWrap').innerHTML=html;
  }
  function load(){
    document.getElementById('acxTableWrap').innerHTML='<div class="acx-empty">Loading…</div>';
    fetch(BASE+'attendance/completion_data?date='+encodeURIComponent(dateEl.value), {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || j.status!=='success'){ document.getElementById('acxTableWrap').innerHTML='<div class="acx-empty">'+esc((j&&j.message)||'Failed to load.')+'</div>'; return; }
        lastRows = j.sections||[];
        renderCards(j.summary||{});
        var hol=document.getElementById('acxHoliday');
        if (j.isHoliday){ hol.style.display='block'; hol.innerHTML='<strong>Non-teaching day'+(j.holidayName?': '+esc(j.holidayName):'')+'.</strong> Attendance is not expected for students on this date.'; }
        else hol.style.display='none';
        renderTable();
      })
      .catch(function(){ document.getElementById('acxTableWrap').innerHTML='<div class="acx-empty">Network error.</div>'; });
  }
  document.getElementById('acxReload').addEventListener('click', load);
  dateEl.addEventListener('change', load);
  document.getElementById('acxFilter').addEventListener('change', renderTable);
  document.getElementById('acxSearch').addEventListener('input', renderTable);
  load();
})();
</script>
