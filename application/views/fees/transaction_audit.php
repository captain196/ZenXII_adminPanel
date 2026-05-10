<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fd-wrap" style="max-width:1200px;margin:0 auto;padding:24px 20px;">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:1.5rem;font-weight:700;color:var(--t1);margin:0 0 4px;display:flex;align-items:center;gap:10px">
        <i class="fa fa-shield" style="color:var(--gold)"></i> Transaction Audit
      </h1>
      <ol style="list-style:none;margin:0;padding:0;display:flex;gap:6px;font-size:13px;color:var(--t3)">
        <li><a href="<?= base_url('admin') ?>" style="color:var(--gold);text-decoration:none">Dashboard</a></li>
        <li>&rsaquo;</li>
        <li><a href="<?= base_url('fees/fees_dashboard') ?>" style="color:var(--gold);text-decoration:none">Fees</a></li>
        <li>&rsaquo;</li>
        <li>Transaction Audit</li>
      </ol>
    </div>
    <div style="display:flex;gap:8px">
      <button class="fd-btn fd-btn-ghost" onclick="switchTab('recovery',document.querySelector('.ta-tab[data-tab=&quot;recovery&quot;]'))"><i class="fa fa-exclamation-triangle"></i> Recovery Panel</button>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;gap:2px;background:var(--bg);border-radius:8px;padding:3px;margin-bottom:18px;width:fit-content">
    <button class="ta-tab active" data-tab="search" onclick="switchTab('search',this)"><i class="fa fa-search"></i> Search</button>
    <button class="ta-tab" data-tab="recovery" onclick="switchTab('recovery',this)"><i class="fa fa-wrench"></i> Recovery</button>
  </div>

  <!-- Search Panel -->
  <div class="ta-panel active" id="panelSearch">
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:18px">
      <div style="flex:1;min-width:250px">
        <label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">Search</label>
        <input type="text" id="auditQuery" placeholder="Receipt #, TXN_id, or Student ID..." style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:14px">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">Type</label>
        <select id="auditType" style="padding:10px 14px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px">
          <option value="auto">Auto-detect</option>
          <option value="receipt_no">Receipt #</option>
          <option value="txn_id">Transaction ID</option>
          <option value="student_id">Student ID</option>
        </select>
      </div>
      <button onclick="doAuditSearch()" style="padding:10px 20px;border:none;border-radius:7px;background:var(--gold);color:#fff;font-size:13px;font-weight:600;cursor:pointer"><i class="fa fa-search"></i> Search</button>
    </div>

    <div id="auditResults" style="min-height:200px">
      <div style="text-align:center;padding:40px 0;color:var(--t3);font-size:13px">
        <i class="fa fa-shield" style="font-size:28px;display:block;margin-bottom:10px;color:var(--gold);opacity:.4"></i>
        Enter a receipt number, transaction ID, or student ID to view the full audit trail.
      </div>
    </div>
  </div>

  <!-- Recovery Panel -->
  <div class="ta-panel" id="panelRecovery">
    <div id="recoveryContent" style="min-height:200px">
      <div style="text-align:center;padding:40px 0;color:var(--t3)">
        <i class="fa fa-spinner fa-spin"></i> Loading...
      </div>
    </div>
  </div>

</div>
</div>

<style>
.ta-tab{font:600 12px/1 var(--font-b);padding:8px 16px;border-radius:6px;border:none;background:transparent;color:var(--t3);cursor:pointer;transition:all .15s}
.ta-tab.active{background:var(--gold);color:#fff}
.ta-tab:not(.active):hover{color:var(--t1)}
.ta-panel{display:none}
.ta-panel.active{display:block}
.ta-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;margin-bottom:14px;overflow:hidden}
.ta-card-hd{padding:12px 16px;font:700 13px/1.3 var(--font-b);display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border)}
.ta-card-hd i{color:var(--gold)}
.ta-card-bd{padding:14px 16px}
.ta-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);font-size:13px}
.ta-row:last-child{border-bottom:none}
.ta-row .ta-label{color:var(--t3);font-weight:600;font-size:12px}
.ta-row .ta-val{color:var(--t1);font-family:var(--font-m);font-size:12px;text-align:right;max-width:60%;word-break:break-all}
.ta-badge{font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;text-transform:uppercase}
.ta-badge.success{background:rgba(15,118,110,.1);color:#0f766e}
.ta-badge.processing{background:rgba(217,119,6,.1);color:#d97706}
.ta-badge.error{background:rgba(220,38,38,.1);color:#dc2626}
.ta-badge.pending{background:rgba(59,130,246,.1);color:#2563eb}
.ta-steps{display:flex;flex-wrap:wrap;gap:4px;padding:8px 0}
.ta-step{font-size:10px;padding:3px 8px;border-radius:4px;background:var(--bg);color:var(--t2);font-weight:600}
.ta-step:last-child{background:rgba(15,118,110,.1);color:#0f766e}
.ta-issue{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;font-size:13px;background:var(--bg2)}
.ta-issue .ta-issue-info{flex:1}
.ta-issue .ta-issue-age{font-size:11px;color:var(--t3);margin-top:2px}
.ta-action-btn{font-size:11px;padding:5px 12px;border-radius:5px;border:1px solid var(--border);color:var(--t2);background:var(--bg);cursor:pointer;font-weight:600;transition:all .15s;display:inline-flex;align-items:center;gap:4px}
.ta-action-btn:hover{border-color:var(--t3)}
.ta-action-btn.clear{border-color:#dc2626;color:#dc2626}
.ta-action-btn.clear:hover{background:#dc2626;color:#fff}
.ta-action-btn.diagnose{border-color:var(--gold);color:var(--gold)}
.ta-action-btn.diagnose:hover{background:var(--gold);color:#fff}
.ta-action-btn.view{border-color:#2563eb;color:#2563eb}
.ta-action-btn.view:hover{background:#2563eb;color:#fff}
.fd-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;border:none;font-size:13px;font-weight:600;cursor:pointer}
.fd-btn-ghost{background:transparent;color:var(--gold);border:1.5px solid var(--gold)}
.fd-btn-ghost:hover{background:var(--gold);color:#fff}
</style>

<script>
var BASE = '<?= rtrim(base_url(), "/") ?>/';
var csrfName = '<?= $this->security->get_csrf_token_name() ?>';
var csrfHash = '<?= $this->security->get_csrf_hash() ?>';

function esc(s){var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
function fmt(n){return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}

// Convert any ISO/RFC timestamp to a human-readable IST (Asia/Kolkata) string
// in DD-MM-YYYY HH:MM:SS AM/PM format. Server timestamps are ISO 8601 with the
// server's timezone offset (often +02:00 for the dev box, or Z/+00:00 in
// production); the admin office is in India, so we always render in IST.
// Falls back to the original string if the input isn't parseable.
function fmtIST(iso){
  if(!iso) return '';
  var d = new Date(iso);
  if(isNaN(d.getTime())) return String(iso);
  // Use Intl with Asia/Kolkata for correct IST conversion incl. handling of
  // any source timezone offset. Output: "24-04-2026 08:57:34 AM"
  try {
    var dateStr = d.toLocaleDateString('en-GB', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      timeZone: 'Asia/Kolkata'
    }).replace(/\//g, '-');
    var timeStr = d.toLocaleTimeString('en-IN', {
      hour: '2-digit', minute: '2-digit', second: '2-digit',
      hour12: true,
      timeZone: 'Asia/Kolkata'
    });
    return dateStr + ' ' + timeStr + ' IST';
  } catch(e) { return String(iso); }
}

function switchTab(tab,btn){
  document.querySelectorAll('.ta-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.ta-panel').forEach(function(p){p.classList.remove('active');});
  if(btn)btn.classList.add('active');
  document.getElementById('panel'+tab.charAt(0).toUpperCase()+tab.slice(1)).classList.add('active');
  if(tab==='recovery') loadStale();
}

function post(url,data){
  var fd=new FormData();
  fd.append(csrfName,csrfHash);
  for(var k in data) fd.append(k,data[k]);
  return fetch(BASE+url,{method:'POST',body:fd,headers:{'X-CSRF-Token':csrfHash,'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();})
    .then(function(r){if(r.csrf_hash)csrfHash=r.csrf_hash;return r;});
}

// Last successful search (saved so we can render a "Back" button when drilling
// into a single receipt from a student receipt list).
var lastSearchQuery = '';
var lastSearchType  = '';

function doAuditSearch(){
  var q=document.getElementById('auditQuery').value.trim();
  if(!q)return;
  var el=document.getElementById('auditResults');
  el.innerHTML='<div style="text-align:center;padding:30px 0;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Searching...</div>';
  var typ=document.getElementById('auditType').value;
  post('fees/search_transaction',{query:q,type:typ}).then(function(r){
    if(r.status!=='success'){el.innerHTML='<div style="padding:20px;color:#dc2626">'+esc(r.message)+'</div>';return;}
    // Record the search context for back-navigation. Auto-detect resolution:
    // a numeric query becomes receipt_no on the server; we reflect that here
    // so the Back button doesn't drop the user on the same receipt detail.
    var resolvedType=typ;
    if(typ==='auto'){
      if(q.indexOf('TXN_')===0) resolvedType='txn_id';
      else if(/^\d+$/.test(q))  resolvedType='receipt_no';
      else                       resolvedType='student_id';
    }
    if(resolvedType==='student_id' && r.fees_records){
      lastSearchQuery=q; lastSearchType='student_id';
    }
    renderAuditResults(r,el);
  }).catch(function(){el.innerHTML='<div style="padding:20px;color:#dc2626">Search failed.</div>';});
}

// Restore the previous student-id search (the receipt list).
function backToStudentList(){
  if(!lastSearchQuery) return;
  document.getElementById('auditQuery').value=lastSearchQuery;
  document.getElementById('auditType').value=lastSearchType;
  doAuditSearch();
}

// Return to the Recovery panel after drilling into a receipt from there.
function backToRecovery(){
  switchTab('recovery', document.querySelector('.ta-tab[data-tab="recovery"]'));
}

// Field-fallback helper — Firestore canonical schema is camelCase, but a few
// server response paths still use snake_case for legacy reasons. Read both
// forms so the UI doesn't show empty cells when one form is missing.
function pick(obj){
  for(var i=1;i<arguments.length;i++){
    var k=arguments[i];
    if(obj && obj[k]!=null && obj[k]!=='') return obj[k];
  }
  return '';
}

function renderAuditResults(r,el){
  var h='';

  // Contextual "Back" button. Three navigation sources can land here:
  //   1. student-id search → receipt # click  → back to student receipt list
  //   2. Recovery panel  → View button       → back to Recovery
  //   3. direct receipt-no search             → no back (entry point)
  if(document.getElementById('auditType').value==='receipt_no'){
    if(lastSearchType==='student_id' && lastSearchQuery){
      h+='<div style="margin-bottom:14px"><button class="fd-btn fd-btn-ghost" onclick="backToStudentList()"><i class="fa fa-arrow-left"></i> Back to '+esc(lastSearchQuery)+'\'s receipts</button></div>';
    } else if(lastSearchType==='recovery_jump'){
      h+='<div style="margin-bottom:14px"><button class="fd-btn fd-btn-ghost" onclick="backToRecovery()"><i class="fa fa-arrow-left"></i> Back to Recovery Panel</button></div>';
    }
  }

  // Idempotency / Transaction trace
  var idemp=r.idempotency||(r.idempotency_history?r.idempotency_history[0]:null);
  if(Array.isArray(r.idempotency)&&r.idempotency.length)idemp=r.idempotency[0];
  if(idemp){
    var st=idemp.status||'unknown';
    var bc=st==='success'?'success':st==='processing'?'processing':'error';
    h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-exchange"></i> Transaction Trace <span class="ta-badge '+bc+'">'+esc(st)+'</span></div><div class="ta-card-bd">';
    var v;
    v=pick(idemp,'txnId','txn_id');         if(v) h+='<div class="ta-row"><span class="ta-label">Transaction ID</span><span class="ta-val" style="font-weight:700;color:var(--gold)">'+esc(v)+'</span></div>';
    v=pick(idemp,'receiptNo','receipt_no'); if(v) h+='<div class="ta-row"><span class="ta-label">Receipt #</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(idemp,'userId','user_id','studentId'); if(v) h+='<div class="ta-row"><span class="ta-label">Student ID</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(idemp,'amount');                  if(v) h+='<div class="ta-row"><span class="ta-label">Amount</span><span class="ta-val">Rs '+fmt(v)+'</span></div>';
    v=pick(idemp,'startedAt','started_at');   if(v) h+='<div class="ta-row"><span class="ta-label">Started</span><span class="ta-val">'+esc(fmtIST(v))+'</span></div>';
    v=pick(idemp,'completedAt','completed_at'); if(v) h+='<div class="ta-row"><span class="ta-label">Completed</span><span class="ta-val">'+esc(fmtIST(v))+'</span></div>';
    v=pick(idemp,'durationMs','duration_ms'); if(v) h+='<div class="ta-row"><span class="ta-label">Duration</span><span class="ta-val">'+v+'ms</span></div>';
    v=pick(idemp,'writesCount','writes_count'); if(v) h+='<div class="ta-row"><span class="ta-label">Writes</span><span class="ta-val">'+v+'</span></div>';
    v=pick(idemp,'step');                    if(v) h+='<div class="ta-row"><span class="ta-label">Last Step</span><span class="ta-val">'+esc(v)+'</span></div>';
    if(idemp.steps&&idemp.steps.length){
      h+='<div style="margin-top:8px"><span class="ta-label">Step Trace</span><div class="ta-steps">';
      idemp.steps.forEach(function(s){h+='<span class="ta-step">'+esc(s)+'</span>';});
      h+='</div></div>';
    }
    h+='</div></div>';
  }

  // Fees Record (single — receipt_no search returns r.receipt; legacy r.fees_record kept as fallback)
  var fr = r.receipt || r.fees_record;
  if(fr && typeof fr==='object'){
    h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-file-text-o"></i> Fees Record';
    var rn = pick(fr,'receiptNo','receipt_no');
    if(rn) h+=' <span class="ta-badge success" style="margin-left:6px">Receipt #'+esc(rn)+'</span>';
    h+='</div><div class="ta-card-bd">';
    var v;
    v=pick(fr,'studentName','student_name'); if(v) h+='<div class="ta-row"><span class="ta-label">Student</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'studentId','student_id');     if(v) h+='<div class="ta-row"><span class="ta-label">Student ID</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'className','class','Class');  if(v) h+='<div class="ta-row"><span class="ta-label">Class</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'section','Section');           if(v) h+='<div class="ta-row"><span class="ta-label">Section</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'amount','Amount');             if(v!=='') h+='<div class="ta-row"><span class="ta-label">Amount</span><span class="ta-val">Rs '+fmt(v)+'</span></div>';
    v=pick(fr,'discount','Discount');         if(v!=='') h+='<div class="ta-row"><span class="ta-label">Discount</span><span class="ta-val">Rs '+fmt(v)+'</span></div>';
    v=pick(fr,'fine','Fine');                 if(v!=='') h+='<div class="ta-row"><span class="ta-label">Fine</span><span class="ta-val">Rs '+fmt(v)+'</span></div>';
    v=pick(fr,'paymentMode','mode','Mode');   if(v) h+='<div class="ta-row"><span class="ta-label">Mode</span><span class="ta-val">'+esc(v)+'</span></div>';
    // Receipt 'date' field is already DD-MM-YYYY (Indian); only ISO timestamps need IST conversion.
    v=pick(fr,'date','Date','paymentDate');   if(v) h+='<div class="ta-row"><span class="ta-label">Date</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'collectedBy','collected_by');  if(v) h+='<div class="ta-row"><span class="ta-label">Collected By</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'txnId','txn_id');              if(v) h+='<div class="ta-row"><span class="ta-label">TXN ID</span><span class="ta-val" style="color:var(--gold);font-family:var(--font-m);font-size:11px">'+esc(v)+'</span></div>';
    v=pick(fr,'remarks');                     if(v) h+='<div class="ta-row"><span class="ta-label">Remarks</span><span class="ta-val">'+esc(v)+'</span></div>';
    v=pick(fr,'createdAt','created_at');      if(v) h+='<div class="ta-row"><span class="ta-label">Created</span><span class="ta-val" style="font-size:11px;color:var(--t3)">'+esc(fmtIST(v))+'</span></div>';
    v=pick(fr,'updatedAt','updated_at');      if(v) h+='<div class="ta-row"><span class="ta-label">Last Updated</span><span class="ta-val" style="font-size:11px;color:var(--t3)">'+esc(fmtIST(v))+'</span></div>';
    h+='</div></div>';
  }

  // Voucher
  if(r.voucher){
    h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-book"></i> Voucher</div><div class="ta-card-bd">';
    for(var vk in r.voucher) h+='<div class="ta-row"><span class="ta-label">'+esc(vk)+'</span><span class="ta-val">'+esc(r.voucher[vk])+'</span></div>';
    h+='</div></div>';
  }

  // Allocations
  if(r.allocations&&r.allocations.allocations){
    var al=r.allocations;
    h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-list-ol"></i> Payment Allocations</div><div class="ta-card-bd">';
    h+='<div class="ta-row"><span class="ta-label">Total</span><span class="ta-val">Rs '+fmt(al.total_amount)+'</span></div>';
    h+='<div class="ta-row"><span class="ta-label">Net Received</span><span class="ta-val" style="font-weight:700;color:var(--gold)">Rs '+fmt(al.net_received)+'</span></div>';
    if(al.advance_credit>0) h+='<div class="ta-row"><span class="ta-label">Advance</span><span class="ta-val" style="color:#d97706">Rs '+fmt(al.advance_credit)+'</span></div>';
    h+='<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:12px"><thead><tr style="border-bottom:2px solid var(--border)"><th style="text-align:left;padding:5px 8px;font-size:11px;color:var(--t3)">Period</th><th style="text-align:left;padding:5px 8px;font-size:11px;color:var(--t3)">Fee Head</th><th style="text-align:right;padding:5px 8px;font-size:11px;color:var(--t3)">Amount</th><th style="text-align:left;padding:5px 8px;font-size:11px;color:var(--t3)">Status</th></tr></thead><tbody>';
    al.allocations.forEach(function(a){
      var sc=a.new_status==='paid'?'color:#0f766e':'color:#d97706';
      h+='<tr style="border-bottom:1px solid var(--border)"><td style="padding:5px 8px">'+esc(a.period)+'</td><td style="padding:5px 8px">'+esc(a.fee_head)+'</td><td style="padding:5px 8px;text-align:right;font-family:var(--font-m)">'+fmt(a.amount)+'</td><td style="padding:5px 8px;'+sc+';font-weight:600;text-transform:uppercase;font-size:11px">'+esc(a.new_status)+'</td></tr>';
    });
    h+='</tbody></table></div></div>';
  }

  // Ledger entries
  if(r.ledger_entries&&r.ledger_entries.length){
    h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-calculator"></i> Accounting Journal Entries ('+r.ledger_entries.length+')</div><div class="ta-card-bd">';
    r.ledger_entries.forEach(function(e){
      // Ledger 'date' may be either DD-MM-YYYY (already Indian) or ISO 8601 with TZ.
      // Detect by presence of 'T' to decide whether to run IST conversion.
      var dateRaw = e.date || e.createdAt || e.created_at || '';
      var dateOut = (typeof dateRaw==='string' && dateRaw.indexOf('T')!==-1) ? fmtIST(dateRaw) : dateRaw;
      h+='<div style="padding:8px 0;border-bottom:1px solid var(--border)">';
      h+='<div style="display:flex;justify-content:space-between;font-size:12px"><span style="font-weight:600">'+esc(e.voucher_no||e._id)+'</span><span style="color:var(--t3)">'+esc(dateOut)+'</span></div>';
      h+='<div style="font-size:12px;color:var(--t2);margin-top:2px">'+esc(e.narration)+'</div>';
      h+='<div style="font-size:11px;color:var(--t3);margin-top:2px">Dr: '+fmt(e.total_dr)+' | Cr: '+fmt(e.total_cr)+'</div>';
      h+='</div>';
    });
    h+='</div></div>';
  }

  // Student receipts list (for student_id search). Backend sends an array of
  // receipt docs in canonical Firestore camelCase shape (receiptNo, amount,
  // paymentMode, date, txnId, ...).
  if(r.fees_records){
    var recs = Array.isArray(r.fees_records)
      ? r.fees_records
      : Object.keys(r.fees_records).map(function(k){return r.fees_records[k];});
    if(recs.length){
      // Sort by receiptNo numerically (Firestore returns unordered).
      recs.sort(function(a,b){
        var ra=parseInt(pick(a,'receiptNo','receipt_no')||'0',10);
        var rb=parseInt(pick(b,'receiptNo','receipt_no')||'0',10);
        return ra-rb;
      });
      h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-list"></i> All Receipts ('+recs.length+')</div><div class="ta-card-bd">';
      h+='<table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr style="border-bottom:2px solid var(--border)"><th style="text-align:left;padding:5px 8px;color:var(--t3)">Receipt #</th><th style="text-align:left;padding:5px 8px;color:var(--t3)">Date</th><th style="text-align:right;padding:5px 8px;color:var(--t3)">Amount</th><th style="text-align:left;padding:5px 8px;color:var(--t3)">Mode</th><th style="text-align:left;padding:5px 8px;color:var(--t3)">TXN</th></tr></thead><tbody>';
      recs.forEach(function(rec){
        var recNo  = pick(rec,'receiptNo','receipt_no');
        var recDt  = pick(rec,'date','Date','paymentDate');
        var recAmt = pick(rec,'amount','Amount');
        var recMd  = pick(rec,'paymentMode','mode','Mode');
        var recTxn = pick(rec,'txnId','txn_id');
        var clk = recNo
          ? 'document.getElementById(\'auditQuery\').value=\''+esc(recNo)+'\';document.getElementById(\'auditType\').value=\'receipt_no\';doAuditSearch()'
          : '';
        h+='<tr style="border-bottom:1px solid var(--border);'+(recNo?'cursor:pointer':'')+'"'
          + (clk?' onclick="'+clk+'"':'')
          + '>';
        h+='<td style="padding:5px 8px;color:var(--gold);font-weight:700">'+esc(recNo||'—')+'</td>';
        h+='<td style="padding:5px 8px">'+esc(recDt||'—')+'</td>';
        h+='<td style="padding:5px 8px;text-align:right;font-family:var(--font-m)">Rs '+fmt(recAmt)+'</td>';
        h+='<td style="padding:5px 8px">'+esc(recMd||'—')+'</td>';
        h+='<td style="padding:5px 8px;font-family:var(--font-m);font-size:10px;color:var(--t3)">'+esc(recTxn||'—')+'</td>';
        h+='</tr>';
      });
      h+='</tbody></table></div></div>';
    }
  }

  // Pending
  if(r.pending){
    h+='<div class="ta-card"><div class="ta-card-hd"><i class="fa fa-clock-o"></i> Pending Marker <span class="ta-badge pending">Active</span></div><div class="ta-card-bd">';
    for(var pk in r.pending){
      if(pk.charAt(0)==='_')continue;
      h+='<div class="ta-row"><span class="ta-label">'+esc(pk)+'</span><span class="ta-val">'+esc(typeof r.pending[pk]==='object'?JSON.stringify(r.pending[pk]):r.pending[pk])+'</span></div>';
    }
    h+='</div></div>';
  }

  if(!h) h='<div style="text-align:center;padding:30px 0;color:var(--t3)"><i class="fa fa-info-circle"></i> No records found for this query.</div>';
  el.innerHTML=h;
}

// Recovery panel
function loadStale(){
  var el=document.getElementById('recoveryContent');
  el.innerHTML='<div style="text-align:center;padding:30px 0;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Scanning...</div>';
  post('fees/get_stale_transactions',{}).then(function(r){
    if(r.status!=='success'){el.innerHTML='<div style="color:#dc2626;padding:20px">'+esc(r.message)+'</div>';return;}
    var h='';

    // Stats
    h+='<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px">';
    h+='<div class="ta-card" style="margin:0;text-align:center"><div class="ta-card-bd"><div style="font-size:22px;font-weight:700;color:'+(r.total_issues>0?'#dc2626':'#0f766e')+'">'+r.total_issues+'</div><div style="font-size:11px;color:var(--t3);font-weight:600">Total Issues</div></div></div>';
    h+='<div class="ta-card" style="margin:0;text-align:center"><div class="ta-card-bd"><div style="font-size:22px;font-weight:700;color:var(--t1)">'+r.stale_processing.length+'</div><div style="font-size:11px;color:var(--t3);font-weight:600">Stale Processing</div></div></div>';
    h+='<div class="ta-card" style="margin:0;text-align:center"><div class="ta-card-bd"><div style="font-size:22px;font-weight:700;color:var(--t1)">'+r.stale_pending.length+'</div><div style="font-size:11px;color:var(--t3);font-weight:600">Pending Fees</div></div></div>';
    h+='<div class="ta-card" style="margin:0;text-align:center"><div class="ta-card-bd"><div style="font-size:22px;font-weight:700;color:var(--t1)">'+r.active_locks.length+'</div><div style="font-size:11px;color:var(--t3);font-weight:600">Active Locks</div></div></div>';
    h+='<div class="ta-card" style="margin:0;text-align:center"><div class="ta-card-bd"><div style="font-size:22px;font-weight:700;color:'+(r.sync_pending&&r.sync_pending.length?'#d97706':'var(--t1)')+'">'+((r.sync_pending||[]).length)+'</div><div style="font-size:11px;color:var(--t3);font-weight:600">Sync Pending</div></div></div>';
    h+='</div>';

    if(r.total_issues===0&&r.active_locks.length===0){
      h+='<div style="text-align:center;padding:30px 0;color:#0f766e"><i class="fa fa-check-circle" style="font-size:28px;display:block;margin-bottom:8px"></i>All clear. No stale transactions detected.</div>';
    }

    function issueList(items,title,icon,defaultAction,type){
      if(!items.length)return '';
      var s='<div class="ta-card"><div class="ta-card-hd"><i class="fa '+icon+'"></i> '+title+' ('+items.length+')</div><div class="ta-card-bd">';
      items.forEach(function(it){
        var key=it._key||it._receipt_no||it._student_id||'';
        var age=Math.round((it._age_seconds||0)/60);
        // Read with camelCase + snake_case fallback. Firestore canonical
        // schema is camelCase (receiptNo, userId, etc.), but a few legacy
        // writers still emit snake_case. pick() returns the first non-empty
        // value found — so the row info string + button args stay populated
        // regardless of which writer produced the doc.
        var receiptNo = pick(it,'receiptNo','receipt_no');
        var userId    = pick(it,'userId','user_id','studentId','student_id');
        var step      = pick(it,'step');
        var amount    = pick(it,'amount');
        var refundId  = pick(it,'refundId','refund_id');
        var kind      = pick(it,'kind');

        var info='';
        if(kind)      info+=(info?' | ':'')+'Kind: '+kind;
        if(receiptNo) info+=(info?' | ':'')+'Receipt: '+receiptNo;
        if(userId)    info+=(info?' | ':'')+'Student: '+userId;
        if(step)      info+=(info?' | ':'')+'Step: '+step;
        if(amount)    info+=(info?' | ':'')+'Amt: Rs '+fmt(amount);
        if(refundId)  info+=(info?' | ':'')+'Refund: '+refundId;

        s+='<div class="ta-issue" id="issue_'+esc(key)+'">';
        s+='<div class="ta-issue-info"><strong>'+esc(key)+'</strong><div class="ta-issue-age">'+esc(info)+' | Age: '+age+' min</div></div>';
        s+='<div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap">';

        // Smart action buttons based on issue type
        if(type==='processing'){
          // Stale processing — offer Diagnose first, then Clear
          s+='<button class="ta-action-btn diagnose" onclick="diagnoseIssue(\''+esc(key)+'\',\''+esc(receiptNo)+'\',\''+esc(userId)+'\')" title="Analyze what was written"><i class="fa fa-stethoscope"></i> Diagnose</button>';
          s+='<button class="ta-action-btn view" onclick="viewInAudit(\''+esc(receiptNo)+'\')" title="View full audit trail" '+(receiptNo?'':'disabled style="opacity:.4;cursor:not-allowed"')+'><i class="fa fa-eye"></i> View</button>';
          s+='<button class="ta-action-btn clear" onclick="confirmResolve(\''+defaultAction+'\',\''+esc(key)+'\',\'Stale processing record\')" title="Mark as manually cleared"><i class="fa fa-times"></i> Clear</button>';
        } else if(type==='pending'){
          s+='<button class="ta-action-btn view" onclick="viewInAudit(\''+esc(receiptNo)+'\')" title="View receipt" '+(receiptNo?'':'disabled style="opacity:.4;cursor:not-allowed"')+'><i class="fa fa-eye"></i> View</button>';
          s+='<button class="ta-action-btn clear" onclick="confirmResolve(\''+defaultAction+'\',\''+esc(key)+'\',\'Pending fees marker\')" title="Clear pending marker"><i class="fa fa-times"></i> Clear</button>';
        } else if(type==='reservation'){
          s+='<button class="ta-action-btn clear" onclick="confirmResolve(\''+defaultAction+'\',\''+esc(key)+'\',\'Stale receipt reservation\')" title="Release reserved receipt number"><i class="fa fa-times"></i> Release</button>';
        } else if(type==='lock'){
          s+='<button class="ta-action-btn clear" onclick="confirmResolve(\''+defaultAction+'\',\''+esc(key)+'\',\'Student lock\')" title="Release student lock"><i class="fa fa-unlock"></i> Unlock</button>';
        }
        // 'sync' case removed in Phase 9 — wallet sync failures no longer exist.

        s+='</div></div>';
      });
      s+='</div></div>';
      return s;
    }

    h+=issueList(r.stale_processing,'Stale Processing','fa-exclamation-triangle','clear_processing','processing');
    h+=issueList(r.stale_pending,'Pending Fee Markers','fa-clock-o','clear_pending','pending');
    h+=issueList(r.stale_reservations,'Stale Receipt Reservations','fa-ticket','clear_reservation','reservation');
    h+=issueList(r.active_locks,'Active Student Locks','fa-lock','clear_lock','lock');
    // 'Advance Balance Sync Failures' list removed in Phase 9.

    el.innerHTML=h;
  });
}

// Diagnose a stale processing record
function diagnoseIssue(idempKey,receiptNo,studentId){
  var el=document.getElementById('issue_'+idempKey);
  if(!el)return;
  // Insert diagnosis panel below the issue
  var existing=document.getElementById('diag_'+idempKey);
  if(existing){existing.remove();return;} // toggle off

  var diagDiv=document.createElement('div');
  diagDiv.id='diag_'+idempKey;
  diagDiv.style.cssText='margin:8px 0 0;padding:12px;background:var(--bg);border-radius:8px;border:1px solid var(--border);font-size:12px';
  diagDiv.innerHTML='<i class="fa fa-spinner fa-spin"></i> Diagnosing...';
  el.after(diagDiv);

  post('fees/diagnose_transaction',{idemp_key:idempKey,receipt_no:receiptNo,student_id:studentId}).then(function(r){
    if(r.status!=='success'){diagDiv.innerHTML='<span style="color:#dc2626">'+esc(r.message)+'</span>';return;}

    var h='<div style="font:700 12px/1.3 var(--font-b);color:var(--gold);margin-bottom:8px"><i class="fa fa-stethoscope"></i> Diagnosis Result</div>';

    // Recommendation banner
    var recColor = r.recommendation==='clean_clear'?'#0f766e':r.recommendation==='mark_complete'?'#2563eb':'#d97706';
    h+='<div style="padding:8px 12px;border-radius:6px;border-left:3px solid '+recColor+';background:var(--bg2);margin-bottom:10px;color:var(--t1)">'+esc(r.recommendation_text)+'</div>';

    // Write status table
    if(r.checks){
      h+='<table style="width:100%;border-collapse:collapse;margin-bottom:10px">';
      h+='<thead><tr><th style="text-align:left;padding:4px 8px;font-size:11px;color:var(--t3);border-bottom:1px solid var(--border)">Record</th><th style="text-align:left;padding:4px 8px;font-size:11px;color:var(--t3);border-bottom:1px solid var(--border)">Status</th></tr></thead><tbody>';
      for(var ck in r.checks){
        var cv=r.checks[ck];
        var sc=cv==='written'||cv==='finalized'||cv==='exists'?'color:#0f766e':cv==='missing'||cv==='cleared'||cv==='free'?'color:#dc2626':'color:#d97706';
        var icon=cv==='written'||cv==='finalized'?'fa-check-circle':cv==='missing'||cv==='cleared'||cv==='free'?'fa-times-circle':'fa-exclamation-circle';
        h+='<tr style="border-bottom:1px solid var(--border)"><td style="padding:4px 8px;text-transform:capitalize">'+esc(ck.replace(/_/g,' '))+'</td>';
        h+='<td style="padding:4px 8px;font-weight:600;'+sc+'"><i class="fa '+icon+'" style="margin-right:4px"></i>'+esc(cv)+'</td></tr>';
      }
      h+='</tbody></table>';
    }

    // Safe action buttons
    if(r.safe_actions&&r.safe_actions.length){
      h+='<div style="display:flex;gap:6px;flex-wrap:wrap">';
      var actionLabels={clear_lock:'Unlock Student',clear_pending:'Clear Pending',clear_reservation:'Release Receipt',clear_processing:'Clear Processing',mark_success:'Mark Complete',view_details:'View Details'};
      var actionIcons={clear_lock:'fa-unlock',clear_pending:'fa-eraser',clear_reservation:'fa-ticket',clear_processing:'fa-times',mark_success:'fa-check',view_details:'fa-eye'};
      r.safe_actions.forEach(function(a){
        if(a==='view_details'){
          h+='<button class="ta-action-btn view" onclick="viewInAudit(\''+esc(receiptNo)+'\')"><i class="fa fa-eye"></i> View Details</button>';
        } else {
          h+='<button class="ta-action-btn '+(a==='mark_success'?'diagnose':'clear')+'" onclick="confirmResolve(\''+a+'\',\''+esc(idempKey)+'\',\''+esc(actionLabels[a]||a)+'\')"><i class="fa '+(actionIcons[a]||'fa-cog')+'"></i> '+esc(actionLabels[a]||a)+'</button>';
        }
      });
      h+='</div>';
    }

    diagDiv.innerHTML=h;
  });
}

// View a receipt in the audit search tab. Mark navigation source as 'recovery'
// so the receipt detail can offer a "Back to Recovery" button.
function viewInAudit(receiptNo){
  if(!receiptNo)return;
  lastSearchQuery = '';            // clear student-list context
  lastSearchType  = 'recovery_jump'; // signal: came from Recovery
  document.getElementById('auditQuery').value=receiptNo;
  document.getElementById('auditType').value='receipt_no';
  switchTab('search',document.querySelector('.ta-tab[data-tab="search"]'));
  doAuditSearch();
}

// Confirmation modal before destructive actions
function confirmResolve(action,key,label){
  var actionDescriptions={
    clear_lock:'This will release the student payment lock, allowing new payments to be processed.',
    clear_pending:'This will remove the pending fee marker. The fee payment may or may not have completed.',
    clear_reservation:'This will release the reserved receipt number so it can be reused.',
    clear_processing:'This will mark the idempotency record as manually cleared. A retry with the same data will be treated as a new request.',
    mark_success:'This will mark the transaction as successfully completed. Only use this if you have verified all financial records exist.'
  };

  var desc=actionDescriptions[action]||'This action will modify financial records.';
  var severity=action==='mark_success'?'high':'medium';

  var confirmed=confirm(
    '--- ' + label.toUpperCase() + ' ---\n\n'
    + desc + '\n\n'
    + 'Key: ' + key + '\n'
    + (severity==='high' ? '\n WARNING: This marks a financial transaction as complete.\n' : '')
    + '\nAre you sure?'
  );
  if(!confirmed)return;

  // Find and disable the button that was clicked
  post('fees/resolve_stale',{action:action,key:key}).then(function(r){
    if(r.status==='success'){
      // Show success feedback
      var msg=r.message||'Resolved successfully.';
      var el=document.getElementById('issue_'+key);
      if(el){
        el.style.transition='opacity .3s,max-height .3s';
        el.style.opacity='0';el.style.maxHeight='0';el.style.overflow='hidden';
        setTimeout(function(){el.remove();},400);
      }
      // Remove diagnosis panel if exists
      var diag=document.getElementById('diag_'+key);
      if(diag)diag.remove();

      // Show feedback toast
      var toast=document.createElement('div');
      toast.style.cssText='position:fixed;bottom:20px;right:20px;padding:12px 20px;background:#0f766e;color:#fff;border-radius:8px;font-size:13px;font-weight:600;z-index:10000;box-shadow:0 4px 12px rgba(0,0,0,.2)';
      toast.innerHTML='<i class="fa fa-check-circle" style="margin-right:6px"></i>'+esc(msg);
      document.body.appendChild(toast);
      setTimeout(function(){toast.remove();},4000);

      // Refresh counts after a short delay
      setTimeout(loadStale,500);
    } else {
      alert(r.message||'Action failed. Please try again.');
    }
  }).catch(function(){alert('Request failed.');});
}

// recalcAdvance removed in Phase 9 — wallet subsystem gone.

// Enter key search
document.getElementById('auditQuery').addEventListener('keypress',function(e){if(e.key==='Enter')doAuditSearch();});
</script>
