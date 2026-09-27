<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<style>
.aex-wrap{max-width:1100px;margin:0 auto;padding:4px 2px}
.aex-card{background:#fff;border:1px solid #e3e7ec;border-radius:8px;padding:18px;margin-bottom:18px}
.aex-card h3{margin:0 0 14px;font-size:15px;font-weight:700;color:#2c3e50}
.aex-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.aex-f label{display:block;font-size:12px;color:#8a94a0;font-weight:600;margin-bottom:4px}
.aex-f input,.aex-f select{width:100%;height:34px;border:1px solid #d6dbe1;border-radius:6px;padding:0 10px;font-size:13px}
.aex-note{background:#e8f1fb;border-left:3px solid #2f6fb2;padding:10px 14px;border-radius:0 4px 4px 0;font-size:13px;color:#2c4a66;margin-bottom:14px}
.aex-tbl{width:100%;border-collapse:collapse;font-size:13px}
.aex-tbl th{text-align:left;font-size:11px;text-transform:uppercase;color:#8a94a0;padding:9px 10px;border-bottom:1px solid #eef1f5;background:#fafbfc}
.aex-tbl td{padding:9px 10px;border-bottom:1px solid #f4f6f8}
.aex-pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:11px}
.aex-eligible{background:#eef8f1;color:#256b44}.aex-warning{background:#fff4e0;color:#a15c00}
.aex-shortage{background:#fdecea;color:#c0392b}.aex-not_applicable{background:#eef1f5;color:#777}
.aex-msg{font-size:13px;margin-left:10px}
</style>
<div class="content-wrapper">
<section class="content-header"><h1>Attendance Eligibility <small>configurable shortage / eligibility</small></h1></section>
<section class="content aex-wrap">
  <div class="aex-note">Attendance % is <strong>always calculated live</strong> from marked attendance — never stored as an editable value. Thresholds are policy (boards differ: CBSE 75%/60% for X–XII, others vary), so nothing is hardcoded.</div>

  <div class="aex-card">
    <h3>Eligibility Policy</h3>
    <div class="aex-grid">
      <div class="aex-f"><label>Minimum % (eligible)</label><input type="number" id="pMin" min="1" max="100"></div>
      <div class="aex-f"><label>Condonable floor %</label><input type="number" id="pFloor" min="0" max="100"></div>
      <div class="aex-f"><label>Warning at %</label><input type="number" id="pWarn" min="1" max="100"></div>
      <div class="aex-f"><label>Tardy counts as present</label><select id="pTardy"><option value="1">Yes</option><option value="0">No</option></select></div>
      <div class="aex-f"><label>Leave treatment</label><select id="pLeave"><option value="denominator">Counts against % (in denominator)</option><option value="excused">Excused (excluded)</option><option value="present">Counts as present</option></select></div>
      <div class="aex-f"><label>Applicable classes (comma-sep, blank=all)</label><input type="text" id="pClasses" placeholder="Class 10th, Class 12th"></div>
      <div class="aex-f"><label>Cutoff date (blank=to date)</label><input type="date" id="pCutoff"></div>
      <div class="aex-f"><label>Board label (info)</label><input type="text" id="pBoard" placeholder="CBSE / CISCE / State"></div>
    </div>
    <div style="margin-top:14px"><button class="btn btn-primary btn-sm" id="pSave">Save Policy</button><span class="aex-msg" id="pMsg"></span></div>
  </div>

  <div class="aex-card">
    <h3>Eligibility Report</h3>
    <div class="aex-grid" style="grid-template-columns:1fr 1fr auto">
      <div class="aex-f"><label>Class (e.g. Class 9th)</label><input type="text" id="rClass"></div>
      <div class="aex-f"><label>Section (e.g. A)</label><input type="text" id="rSection"></div>
      <div class="aex-f" style="align-self:end"><button class="btn btn-primary btn-sm" id="rRun" style="height:34px">Run</button></div>
    </div>
    <div id="rSummary" style="margin:12px 0;font-size:13px;color:#555"></div>
    <div id="rTable"></div>
  </div>
</section>
</div>
<script>
(function(){
  var BASE=<?= json_encode(rtrim(base_url(),'/').'/') ?>;
  function csrf(){var m=document.cookie.match(/csrf_token=([^;]+)/);return m?decodeURIComponent(m[1]):'';}
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function loadPolicy(){
    fetch(BASE+'attendance/eligibility_policy',{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(function(j){
      if(!j||j.status!=='success')return; var p=j.policy;
      pMin.value=p.minPercent;pFloor.value=p.condonableFloor;pWarn.value=p.warningThreshold;
      pTardy.value=p.includeTardyPresent?'1':'0';pLeave.value=p.leaveTreatment;
      pClasses.value=(p.applicableClasses||[]).join(', ');pCutoff.value=p.cutoffDate||'';pBoard.value=p.boardLabel||'';
    });
  }
  document.getElementById('pSave').addEventListener('click',function(){
    var classes=pClasses.value.split(',').map(function(s){return s.trim();}).filter(Boolean);
    var body=new URLSearchParams();
    body.set('minPercent',pMin.value);body.set('condonableFloor',pFloor.value);body.set('warningThreshold',pWarn.value);
    body.set('includeTardyPresent',pTardy.value);body.set('leaveTreatment',pLeave.value);
    body.set('applicableClasses',JSON.stringify(classes));body.set('cutoffDate',pCutoff.value);body.set('boardLabel',pBoard.value);
    body.set('csrf_token',csrf());
    fetch(BASE+'attendance/eligibility_save',{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
      .then(r=>r.json()).then(function(j){document.getElementById('pMsg').textContent=(j&&j.status==='success')?'Saved.':((j&&j.message)||'Failed');document.getElementById('pMsg').style.color=(j&&j.status==='success')?'#256b44':'#c0392b';});
  });
  document.getElementById('rRun').addEventListener('click',function(){
    document.getElementById('rTable').innerHTML='Loading…';
    fetch(BASE+'attendance/eligibility_report?class='+encodeURIComponent(rClass.value)+'&section='+encodeURIComponent(rSection.value),{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(function(j){
        if(!j||j.status!=='success'){document.getElementById('rTable').innerHTML=esc((j&&j.message)||'Failed');return;}
        var s=j.summary||{};document.getElementById('rSummary').innerHTML='Eligible: <b>'+(s.eligible||0)+'</b> · Warning: <b>'+(s.warning||0)+'</b> · Shortage: <b>'+(s.shortage||0)+'</b> · N/A: '+(s.not_applicable||0)+' (min '+j.policy.minPercent+'% / floor '+j.policy.condonableFloor+'%)';
        var rows=j.students||[];if(!rows.length){document.getElementById('rTable').innerHTML='<div style="color:#8a94a0;padding:16px">No students.</div>';return;}
        var h='<table class="aex-tbl"><thead><tr><th>Student</th><th>%</th><th>Present</th><th>Absent</th><th>Leave</th><th>Working days</th><th>Days to eligible</th><th>Status</th></tr></thead><tbody>';
        rows.forEach(function(r){h+='<tr><td>'+esc(r.name)+'</td><td>'+r.percentage+'</td><td>'+r.present+'</td><td>'+r.absent+'</td><td>'+r.leave+'</td><td>'+r.workingDays+'</td><td>'+(r.daysToEligible||0)+'</td><td><span class="aex-pill aex-'+r.status+'">'+r.status.replace('_',' ')+'</span></td></tr>';});
        h+='</tbody></table>';document.getElementById('rTable').innerHTML=h;
      });
  });
  loadPolicy();
})();
</script>
