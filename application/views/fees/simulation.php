<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="content-wrapper">
<div style="max-width:900px;margin:0 auto;padding:24px 20px;">

  <h1 style="font-size:1.5rem;font-weight:700;color:var(--t1);margin:0 0 6px;display:flex;align-items:center;gap:10px">
    <i class="fa fa-flask" style="color:var(--gold)"></i> Fee Module Load Simulation
  </h1>
  <p style="font-size:13px;color:var(--t3);margin:0 0 20px">Stress-test payments, demands, and concurrency safety. All data in <code>Schools/Simulation/*</code> — zero production impact.</p>

  <!-- Config -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:18px">
    <div><label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">Schools</label>
      <input type="number" id="simSchools" value="3" min="1" max="50" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px;width:80px"></div>
    <div><label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">Students/Section</label>
      <input type="number" id="simStudents" value="10" min="5" max="40" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px;width:80px"></div>
    <div><label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">Test</label>
      <select id="simTest" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px">
        <option value="all">All Tests</option>
        <option value="demands">Demand Generation</option>
        <option value="payments">Fee Payments</option>
        <option value="online">Online Payments</option>
        <option value="advance">Advance Stress</option>
      </select>
    </div>
    <button onclick="runSim()" id="btnRun" style="padding:9px 20px;border:none;border-radius:7px;background:var(--gold);color:#fff;font-size:13px;font-weight:600;cursor:pointer"><i class="fa fa-play"></i> Run Simulation</button>
    <button onclick="cleanupSim()" style="padding:9px 20px;border:none;border-radius:7px;background:transparent;border:1.5px solid #dc2626;color:#dc2626;font-size:13px;font-weight:600;cursor:pointer"><i class="fa fa-trash"></i> Cleanup</button>
  </div>

  <!-- Parallel Simulation -->
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:18px">
    <h3 style="font-size:14px;font-weight:700;color:var(--t1);margin:0 0 10px;display:flex;align-items:center;gap:6px"><i class="fa fa-sitemap" style="color:var(--gold)"></i> Parallel Multi-School Simulation</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <div><label style="font-size:11px;font-weight:700;color:var(--t3);display:block;margin-bottom:3px">Batch Size</label>
        <input type="number" id="parBatch" value="5" min="1" max="20" style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:12px;width:70px"></div>
      <div><label style="font-size:11px;font-weight:700;color:var(--t3);display:block;margin-bottom:3px">Max Parallel</label>
        <input type="number" id="parMax" value="3" min="1" max="10" style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:12px;width:70px"></div>
      <div><label style="font-size:11px;font-weight:700;color:var(--t3);display:block;margin-bottom:3px">Batch Delay (ms)</label>
        <input type="number" id="parDelay" value="2000" min="500" max="10000" step="500" style="padding:7px 10px;border:1.5px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:12px;width:90px"></div>
      <button onclick="runParallel()" id="btnParallel" style="padding:8px 18px;border:none;border-radius:7px;background:#7c3aed;color:#fff;font-size:12px;font-weight:600;cursor:pointer"><i class="fa fa-bolt"></i> Run Parallel</button>
    </div>
  </div>

  <!-- Results -->
  <div id="simOutput" style="min-height:200px">
    <div style="text-align:center;padding:40px 0;color:var(--t3)">
      <i class="fa fa-flask" style="font-size:32px;display:block;margin-bottom:10px;opacity:.3"></i>
      Configure and click "Run Simulation" to start.
    </div>
  </div>

</div>
</div>

<script>
var BASE='<?= rtrim(base_url(),"/") ?>/';
var csrfName='<?= $this->security->get_csrf_token_name() ?>';
var csrfHash='<?= $this->security->get_csrf_hash() ?>';

function runParallel(){
  var btn=document.getElementById('btnParallel');
  var el=document.getElementById('simOutput');
  btn.disabled=true;btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Running Parallel...';
  el.innerHTML='<div style="text-align:center;padding:40px 0;color:var(--t3)"><i class="fa fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px"></i>Running parallel simulation across multiple schools...<br>This may take several minutes for large school counts.</div>';

  var url=BASE+'fee_simulation/run_parallel?schools='+document.getElementById('simSchools').value
    +'&students='+document.getElementById('simStudents').value
    +'&batch_size='+document.getElementById('parBatch').value
    +'&max_parallel='+document.getElementById('parMax').value
    +'&batch_delay='+document.getElementById('parDelay').value;

  fetch(url).then(function(r){return r.json();}).then(function(r){
    btn.disabled=false;btn.innerHTML='<i class="fa fa-bolt"></i> Run Parallel';
    renderParallelResults(r,el);
  }).catch(function(e){
    btn.disabled=false;btn.innerHTML='<i class="fa fa-bolt"></i> Run Parallel';
    el.innerHTML='<div style="color:#dc2626;padding:20px">Parallel simulation failed: '+e.message+'</div>';
  });
}

function renderParallelResults(r,el){
  var h='';var s=r.summary||{};

  // Summary stats
  h+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:16px">';
  h+=statCard('Schools',s.total_schools,'var(--t1)');
  h+=statCard('Passed',s.schools_passed,'#0f766e');
  h+=statCard('Failed',s.schools_failed,s.schools_failed>0?'#dc2626':'#0f766e');
  h+=statCard('Operations',s.total_operations,'var(--t1)');
  h+=statCard('Success',s.success_rate,'#0f766e');
  h+=statCard('Avg School',s.avg_school_ms+'ms','var(--gold)');
  h+=statCard('Max School',s.max_school_ms+'ms',parseFloat(s.max_school_ms)>5000?'#dc2626':'var(--t1)');
  h+=statCard('Total Time',fmt(s.total_duration_ms)+'ms','var(--t1)');
  h+='</div>';

  // Batch progress
  var batches=r.batches||[];
  if(batches.length){
    h+='<div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:14px">';
    h+='<div style="font:700 13px/1.3 var(--font-b);margin-bottom:8px"><i class="fa fa-layer-group" style="color:var(--gold)"></i> Batch Progress</div>';
    h+='<div style="display:flex;gap:6px;flex-wrap:wrap">';
    batches.forEach(function(b){
      var c=b.failed>0?'#dc2626':'#0f766e';
      h+='<div style="padding:6px 12px;border-radius:6px;background:var(--bg);border:1px solid var(--border);font-size:11px;text-align:center">';
      h+='<div style="font-weight:700;color:'+c+'">Batch '+b.batch+'</div>';
      h+='<div style="color:var(--t3)">'+b.schools+' | '+fmt(b.time_ms)+'ms</div>';
      h+='</div>';
    });
    h+='</div></div>';
  }

  // Per-school results table
  var schools=r.school_results||[];
  if(schools.length){
    h+='<div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:14px">';
    h+='<div style="padding:12px 16px;font:700 13px/1.3 var(--font-b);border-bottom:1px solid var(--border)"><i class="fa fa-th-list" style="color:var(--gold)"></i> School Results ('+schools.length+')</div>';
    h+='<div style="max-height:300px;overflow-y:auto"><table style="width:100%;border-collapse:collapse;font-size:12px">';
    h+='<thead><tr style="border-bottom:2px solid var(--border);position:sticky;top:0;background:var(--bg2)"><th style="padding:6px 10px;text-align:left;color:var(--t3)">School</th><th style="padding:6px 10px;text-align:center;color:var(--t3)">Status</th><th style="padding:6px 10px;text-align:right;color:var(--t3)">Ops</th><th style="padding:6px 10px;text-align:right;color:var(--t3)">Errors</th><th style="padding:6px 10px;text-align:right;color:var(--t3)">Time</th></tr></thead><tbody>';
    schools.forEach(function(sc){
      var ok=sc.status==='PASSED';
      h+='<tr style="border-bottom:1px solid var(--border)">';
      h+='<td style="padding:5px 10px;font-weight:600">'+esc(sc.school||'?')+'</td>';
      h+='<td style="padding:5px 10px;text-align:center"><span style="font-size:10px;padding:2px 8px;border-radius:4px;font-weight:700;background:'+(ok?'rgba(15,118,110,.1)':'rgba(220,38,38,.1)')+';color:'+(ok?'#0f766e':'#dc2626')+'">'+esc(sc.status)+'</span></td>';
      h+='<td style="padding:5px 10px;text-align:right">'+esc(''+sc.operations)+'</td>';
      h+='<td style="padding:5px 10px;text-align:right;color:'+(sc.errors>0?'#dc2626':'var(--t1)')+'">'+esc(''+sc.errors)+'</td>';
      h+='<td style="padding:5px 10px;text-align:right;color:var(--t3)">'+fmt(sc.duration_ms)+'ms</td>';
      h+='</tr>';
    });
    h+='</tbody></table></div></div>';
  }

  // Validation
  var v=r.validation||{};
  if(v.checks){
    h+='<div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:14px">';
    h+='<div style="font:700 13px/1.3 var(--font-b);margin-bottom:8px"><i class="fa '+(v.status==='PASSED'?'fa-check-circle':'fa-times-circle')+'" style="color:'+(v.status==='PASSED'?'#0f766e':'#dc2626')+'"></i> Global Validation: '+esc(v.status||'?')+'</div>';
    h+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px">';
    for(var ck in v.checks){
      var cv=v.checks[ck];var cc=(ck.indexOf('negative')>=0||ck.indexOf('orphan')>=0||ck.indexOf('stuck')>=0)&&cv>0?'#dc2626':'var(--t1)';
      h+='<div style="display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)"><span style="color:var(--t3);font-size:11px">'+esc(ck.replace(/_/g,' '))+'</span><strong style="color:'+cc+';font-size:12px">'+cv+'</strong></div>';
    }
    h+='</div></div>';
  }

  // Errors
  if(r.errors&&r.errors.length){
    h+='<div style="background:rgba(220,38,38,.05);border:1px solid rgba(220,38,38,.15);border-radius:10px;padding:14px 16px">';
    h+='<div style="font:700 12px/1.3 var(--font-b);color:#dc2626;margin-bottom:6px"><i class="fa fa-exclamation-triangle"></i> Anomalies ('+r.errors.length+')</div>';
    r.errors.forEach(function(e){h+='<div style="font-size:11px;color:#991b1b;padding:2px 0">'+esc(e)+'</div>';});
    h+='</div>';
  }

  el.innerHTML=h;
}

function runSim(){
  var btn=document.getElementById('btnRun');
  var el=document.getElementById('simOutput');
  btn.disabled=true;btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Running...';
  el.innerHTML='<div style="text-align:center;padding:40px 0;color:var(--t3)"><i class="fa fa-spinner fa-spin" style="font-size:28px;display:block;margin-bottom:10px"></i>Running simulation... this may take a few minutes.</div>';

  var url=BASE+'fee_simulation/run?test='+document.getElementById('simTest').value
    +'&schools='+document.getElementById('simSchools').value
    +'&students='+document.getElementById('simStudents').value;

  fetch(url).then(function(r){return r.json();}).then(function(r){
    btn.disabled=false;btn.innerHTML='<i class="fa fa-play"></i> Run Simulation';
    renderResults(r,el);
  }).catch(function(e){
    btn.disabled=false;btn.innerHTML='<i class="fa fa-play"></i> Run Simulation';
    el.innerHTML='<div style="color:#dc2626;padding:20px">Simulation failed: '+e.message+'</div>';
  });
}

function cleanupSim(){
  if(!confirm('Delete ALL simulation data from Firebase?\n\nThis removes Schools/Simulation/* entirely.'))return;
  var fd=new FormData();fd.append(csrfName,csrfHash);
  fetch(BASE+'fee_simulation/cleanup',{method:'POST',body:fd,headers:{'X-CSRF-Token':csrfHash}})
    .then(function(r){return r.json();}).then(function(r){alert(r.message||'Done');});
}

function esc(s){var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
function fmt(n){return parseFloat(n||0).toLocaleString('en-IN',{maximumFractionDigits:1});}

function renderResults(r,el){
  var h='';

  // Summary
  var s=r.summary||{};
  h+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:18px">';
  h+=statCard('Operations',s.total_operations,'var(--t1)');
  h+=statCard('Avg Time',s.avg_time_ms+'ms','var(--gold)');
  h+=statCard('Max Time',s.max_time_ms+'ms',parseFloat(s.max_time_ms)>1000?'#dc2626':'var(--t1)');
  h+=statCard('Errors',s.errors,s.errors>0?'#dc2626':'#0f766e');
  h+=statCard('Success',s.success_rate,'#0f766e');
  h+=statCard('Duration',fmt(r.duration_ms)+'ms','var(--t1)');
  h+='</div>';

  // Test results
  var tests=r.tests||{};
  for(var name in tests){
    var t=tests[name];
    var passed=t.status==='complete'||t.status==='PASSED';
    h+='<div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;margin-bottom:10px;overflow:hidden">';
    h+='<div style="padding:12px 16px;font:700 13px/1.3 var(--font-b);display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border)">';
    h+='<i class="fa '+(passed?'fa-check-circle':'fa-times-circle')+'" style="color:'+(passed?'#0f766e':'#dc2626')+'"></i>';
    h+=esc(name.replace(/_/g,' ').toUpperCase());
    h+='<span style="margin-left:auto;font-size:11px;color:var(--t3)">'+fmt(t.time_ms)+'ms</span>';
    h+='</div>';
    h+='<div style="padding:12px 16px;font-size:12.5px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:6px">';
    for(var k in t){
      if(k==='status'||k==='time_ms')continue;
      var v=t[k];
      if(typeof v==='object'){
        for(var kk in v){
          var vv=v[kk];
          var c=(kk.indexOf('negative')>=0||kk.indexOf('orphan')>=0||kk.indexOf('stuck')>=0||kk.indexOf('duplicate')>=0)&&vv>0?'#dc2626':'var(--t1)';
          h+='<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border)"><span style="color:var(--t3)">'+esc(kk.replace(/_/g,' '))+'</span><strong style="color:'+c+'">'+esc(''+vv)+'</strong></div>';
        }
      } else {
        var c2=(k.indexOf('mismatch')>=0||k.indexOf('error')>=0)&&v>0?'#dc2626':k==='balance_correct'?(v?'#0f766e':'#dc2626'):'var(--t1)';
        h+='<div style="display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px solid var(--border)"><span style="color:var(--t3)">'+esc(k.replace(/_/g,' '))+'</span><strong style="color:'+c2+'">'+esc(''+v)+'</strong></div>';
      }
    }
    h+='</div></div>';
  }

  // Errors
  if(r.errors&&r.errors.length){
    h+='<div style="background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);border-radius:10px;padding:14px 16px;margin-top:14px">';
    h+='<div style="font:700 13px/1.3 var(--font-b);color:#dc2626;margin-bottom:8px"><i class="fa fa-exclamation-triangle"></i> Anomalies ('+r.errors.length+')</div>';
    r.errors.forEach(function(e){h+='<div style="font-size:12px;color:#991b1b;padding:3px 0;border-bottom:1px solid rgba(220,38,38,.1)">'+esc(e)+'</div>';});
    h+='</div>';
  }

  el.innerHTML=h;
}

function statCard(label,value,color){
  return '<div style="background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px;text-align:center">'
    +'<div style="font-size:20px;font-weight:700;color:'+color+'">'+esc(''+value)+'</div>'
    +'<div style="font-size:11px;color:var(--t3);font-weight:600;margin-top:2px">'+esc(label)+'</div></div>';
}
</script>
