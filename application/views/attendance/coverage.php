<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<style>
.cov-wrap{max-width:960px;margin:0 auto;padding:4px 2px}
.cov-card{background:#fff;border:1px solid #e3e7ec;border-radius:8px;padding:18px;margin-bottom:18px}
.cov-card h3{margin:0 0 12px;font-size:15px;font-weight:700;color:#2c3e50}
.cov-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.cov-f label{display:block;font-size:12px;color:#8a94a0;font-weight:600;margin-bottom:4px}
.cov-f input{border:1px solid #d6dbe1;border-radius:6px;padding:8px 10px;font-size:13px;height:34px}
.cov-note{background:#eef6ff;border-left:3px solid #3b82f6;padding:10px 14px;border-radius:0 4px 4px 0;font-size:13px;color:#1e3a5f;margin:12px 0}
.cov-tbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
.cov-tbl th,.cov-tbl td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef1f5}
.cov-tbl th{color:#8a94a0;font-weight:600;font-size:12px}
.cov-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.cov-live{background:#e7f6ec;color:#1e7e42}.cov-rev{background:#f2f3f5;color:#8a94a0}
.cov-out{font-size:13px;margin-top:10px;color:#6b7280}
</style>
<div class="content-wrapper">
<section class="content-header"><h1>Substitute Coverage <small>let a stand-in mark a section for one day</small></h1></section>
<section class="content cov-wrap">
  <div class="cov-note">A class teacher may mark only their own sections. When they are absent, grant a
  substitute <strong>one day</strong> of marking rights on <strong>one section</strong> here. The grant is date-scoped
  and revocable; the attendance itself still records who actually marked it.</div>

  <div class="cov-card">
    <h3>Grant coverage</h3>
    <div class="cov-row">
      <div class="cov-f"><label>Date</label><input type="date" id="gDate"></div>
      <div class="cov-f"><label>Class</label><input id="gClass" placeholder="Class 8th"></div>
      <div class="cov-f"><label>Section</label><input id="gSection" placeholder="A"></div>
      <div class="cov-f"><label>Substitute staff ID</label><input id="gSub" placeholder="TCHR_..."></div>
      <div class="cov-f"><label>Reason</label><input id="gReason" placeholder="e.g. sick leave"></div>
      <button class="btn btn-primary btn-sm" id="gBtn" style="height:34px">Grant</button>
    </div>
    <div class="cov-out" id="gOut"></div>
  </div>

  <div class="cov-card">
    <h3>Grants <small style="font-weight:400;color:#8a94a0">— filter by date</small>
      <input type="date" id="fDate" style="margin-left:8px;border:1px solid #d6dbe1;border-radius:6px;padding:5px 8px;font-size:12px">
      <button class="btn btn-default btn-xs" id="fBtn">Refresh</button></h3>
    <table class="cov-tbl"><thead><tr>
      <th>Date</th><th>Class / Section</th><th>Substitute</th><th>Reason</th><th>Status</th><th></th>
    </tr></thead><tbody id="gRows"><tr><td colspan="6" style="color:#8a94a0">Loading…</td></tr></tbody></table>
  </div>
</section>
</div>
<script>
(function(){
  var BASE=<?= json_encode(rtrim(base_url(),'/').'/') ?>;
  function csrf(){var m=document.cookie.match(/csrf_token=([^;]+)/);return m?decodeURIComponent(m[1]):'';}
  function P(url,body){body.set('csrf_token',csrf());return fetch(BASE+url,{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:body.toString()}).then(function(r){return r.json();});}
  function G(url){return fetch(BASE+url,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function(r){return r.json();});}
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}

  function load(){
    var d=document.getElementById('fDate').value;
    G('attendance/coverage_list'+(d?('?date='+encodeURIComponent(d)):'')).then(function(j){
      var rows=(j&&j.status==='success'&&j.grants)||[];var tb=document.getElementById('gRows');
      if(!rows.length){tb.innerHTML='<tr><td colspan="6" style="color:#8a94a0">No grants.</td></tr>';return;}
      tb.innerHTML=rows.map(function(g){
        var live=!g.revoked;
        var badge=live?'<span class="cov-badge cov-live">Live</span>':'<span class="cov-badge cov-rev">Revoked</span>';
        var act=live?'<button class="btn btn-default btn-xs revBtn" data-d="'+esc(g.date)+'" data-c="'+esc(g.classLabel||g.classKey)+'" data-s="'+esc(g.sectionLabel||g.sectionKey)+'" data-u="'+esc(g.substituteId)+'">Revoke</button>':'';
        return '<tr><td>'+esc(g.date)+'</td><td>'+esc((g.classLabel||g.classKey)+' / '+(g.sectionLabel||g.sectionKey))+'</td><td>'+esc(g.substituteName||g.substituteId)+'</td><td>'+esc(g.reason||'')+'</td><td>'+badge+'</td><td>'+act+'</td></tr>';
      }).join('');
      Array.prototype.forEach.call(document.querySelectorAll('.revBtn'),function(b){
        b.onclick=function(){if(!confirm('Revoke this coverage?'))return;var body=new URLSearchParams();
          body.set('date',b.dataset.d);body.set('class',b.dataset.c);body.set('section',b.dataset.s);body.set('substitute_id',b.dataset.u);
          P('attendance/coverage_revoke',body).then(function(){load();});};
      });
    });
  }
  document.getElementById('gBtn').onclick=function(){
    var body=new URLSearchParams();
    body.set('date',gDate.value);body.set('class',gClass.value);body.set('section',gSection.value);
    body.set('substitute_id',gSub.value);body.set('reason',gReason.value);
    P('attendance/coverage_grant',body).then(function(j){
      document.getElementById('gOut').textContent=(j&&j.message)||(j&&j.error)||'Done.';load();
    });
  };
  document.getElementById('fBtn').onclick=load;
  load();
})();
</script>
