<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<style>
.aco-wrap{max-width:1000px;margin:0 auto;padding:4px 2px}
.aco-card{background:#fff;border:1px solid #e3e7ec;border-radius:8px;padding:18px;margin-bottom:18px}
.aco-card h3{margin:0 0 12px;font-size:15px;font-weight:700;color:#2c3e50}
.aco-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.aco-f label{display:block;font-size:12px;color:#8a94a0;font-weight:600;margin-bottom:4px}
.aco-f input,.aco-f textarea{border:1px solid #d6dbe1;border-radius:6px;padding:8px 10px;font-size:13px}
.aco-f input{height:34px}
.aco-note{background:#fff8e6;border-left:3px solid #e0a800;padding:10px 14px;border-radius:0 4px 4px 0;font-size:13px;color:#6b5411;margin:12px 0}
.aco-out{font-size:13px;margin-top:12px;white-space:pre-wrap;background:#fafbfc;border:1px solid #eef1f5;border-radius:6px;padding:12px;max-height:280px;overflow:auto}
</style>
<div class="content-wrapper">
<section class="content-header"><h1>Calendar Setup <small>copy last year · bulk import</small></h1></section>
<section class="content aco-wrap">
  <div class="aco-card">
    <h3>Copy / Roll-forward holidays &amp; events</h3>
    <div class="aco-row">
      <div class="aco-f"><label>From session</label><input id="cFrom" placeholder="2025-26"></div>
      <div class="aco-f"><label>To session</label><input id="cTo" placeholder="2026-27"></div>
      <button class="btn btn-default btn-sm" id="cPrev" style="height:34px">Preview</button>
      <button class="btn btn-primary btn-sm" id="cRun" style="height:34px">Copy</button>
    </div>
    <div class="aco-note">Dates shift by the year difference. Lunar festivals (Diwali, Holi, Eid…) move each year — <strong>review shifted dates after copying</strong>. Re-running is safe (already-present events are skipped).</div>
    <div class="aco-out" id="cOut" style="display:none"></div>
  </div>
  <div class="aco-card">
    <h3>Bulk import (CSV)</h3>
    <div class="aco-f"><label>Target session</label><input id="iTo" placeholder="2026-27" style="width:160px"></div>
    <div class="aco-f" style="margin-top:10px"><label>CSV — header: <code>title,type,startDate,endDate,description</code> (type = holiday/event/exam/…)</label>
      <textarea id="iCsv" rows="6" style="width:100%;font-family:monospace">title,type,startDate,endDate,description
Diwali,holiday,2026-11-08,2026-11-10,Festival of lights
Sports Day,activity,2026-12-05,,Annual sports</textarea></div>
    <div style="margin-top:10px"><button class="btn btn-default btn-sm" id="iPrev">Validate</button> <button class="btn btn-primary btn-sm" id="iRun">Import</button></div>
    <div class="aco-out" id="iOut" style="display:none"></div>
  </div>
</section>
</div>
<script>
(function(){
  var BASE=<?= json_encode(rtrim(base_url(),'/').'/') ?>;
  function csrf(){var m=document.cookie.match(/csrf_token=([^;]+)/);return m?decodeURIComponent(m[1]):'';}
  function P(url,body){body.set('csrf_token',csrf());return fetch(BASE+url,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();});}
  function show(el,j){el.style.display='block';el.textContent=JSON.stringify(j,null,2);}
  document.getElementById('cPrev').onclick=function(){var b=new URLSearchParams();b.set('source',cFrom.value);b.set('target',cTo.value);P('academic/calendar_copy_preview',b).then(function(j){show(cOut,j);});};
  document.getElementById('cRun').onclick=function(){if(!confirm('Copy events from '+cFrom.value+' to '+cTo.value+'?'))return;var b=new URLSearchParams();b.set('source',cFrom.value);b.set('target',cTo.value);P('academic/calendar_copy_commit',b).then(function(j){show(cOut,j);});};
  document.getElementById('iPrev').onclick=function(){var b=new URLSearchParams();b.set('target',iTo.value);b.set('csv',iCsv.value);P('academic/calendar_import_preview',b).then(function(j){show(iOut,j);});};
  document.getElementById('iRun').onclick=function(){if(!confirm('Import events into '+iTo.value+'?'))return;var b=new URLSearchParams();b.set('target',iTo.value);b.set('csv',iCsv.value);P('academic/calendar_import_commit',b).then(function(j){show(iOut,j);});};
})();
</script>
