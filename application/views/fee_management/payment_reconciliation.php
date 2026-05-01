<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div style="max-width:1200px;margin:0 auto;padding:24px 20px;">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:1.5rem;font-weight:700;color:var(--t1);margin:0 0 4px;display:flex;align-items:center;gap:10px">
        <i class="fa fa-balance-scale" style="color:var(--gold)"></i> Payment Reconciliation
      </h1>
      <ol style="list-style:none;margin:0;padding:0;display:flex;gap:6px;font-size:13px;color:var(--t3)">
        <li><a href="<?= base_url('admin') ?>" style="color:var(--gold);text-decoration:none">Dashboard</a></li>
        <li>&rsaquo; <a href="<?= base_url('fees/fees_dashboard') ?>" style="color:var(--gold);text-decoration:none">Fees</a></li>
        <li>&rsaquo; Payment Reconciliation</li>
      </ol>
    </div>
  </div>

  <!-- Filters -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 18px;margin-bottom:18px">
    <div style="flex:0 0 auto"><label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">From</label><input type="date" id="prFrom" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px"></div>
    <div style="flex:0 0 auto"><label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">To</label><input type="date" id="prTo" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px"></div>
    <div style="flex:0 0 auto"><label style="font-size:12px;font-weight:700;color:var(--t3);display:block;margin-bottom:4px">Status</label>
      <select id="prStatus" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;background:var(--bg);color:var(--t1);font-size:13px">
        <option value="">All</option><option value="paid">Paid</option><option value="fees_failed">Fees Failed</option><option value="created">Pending</option>
      </select>
    </div>
    <button onclick="loadRecon()" style="padding:8px 18px;border:none;border-radius:7px;background:var(--gold);color:#fff;font-size:13px;font-weight:600;cursor:pointer"><i class="fa fa-refresh"></i> Load</button>
  </div>

  <!-- Stats -->
  <div id="prStats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px"></div>

  <!-- Tabs -->
  <div style="display:flex;gap:2px;background:var(--bg);border-radius:8px;padding:3px;margin-bottom:14px;width:fit-content">
    <button class="pr-tab active" data-tab="failed" onclick="prTab('failed',this)"><i class="fa fa-exclamation-triangle"></i> Failed <span id="prFailedBadge" class="pr-badge">0</span></button>
    <button class="pr-tab" data-tab="orphans" onclick="prTab('orphans',this)"><i class="fa fa-unlink"></i> Orphans <span id="prOrphanBadge" class="pr-badge">0</span></button>
    <button class="pr-tab" data-tab="duplicates" onclick="prTab('duplicates',this)"><i class="fa fa-clone"></i> Duplicates <span id="prDupBadge" class="pr-badge">0</span></button>
    <button class="pr-tab" data-tab="success" onclick="prTab('success',this)"><i class="fa fa-check-circle"></i> Successful</button>
    <button class="pr-tab" data-tab="bank" onclick="prTab('bank',this)"><i class="fa fa-upload"></i> Bank Statement</button>
  </div>

  <!-- Panels -->
  <div class="pr-panel active" id="panelFailed"><div class="pr-loading">Loading...</div></div>
  <div class="pr-panel" id="panelOrphans"></div>
  <div class="pr-panel" id="panelDuplicates"></div>
  <div class="pr-panel" id="panelSuccess"></div>
  <div class="pr-panel" id="panelBank">
    <div class="pr-card">
      <div class="pr-card-hd">
        <div><strong>Bank Statement Reconciliation</strong>
          <div style="font-size:11px;color:var(--t3);margin-top:2px;">
            Upload a bank-export CSV — we match each row to gateway payments and flag unmatched ones for investigation.
          </div>
        </div>
        <div>
          <label class="pr-btn" style="cursor:pointer;">
            <i class="fa fa-upload"></i> Upload CSV
            <input type="file" id="bankCsv" accept=".csv,text/csv" style="display:none" onchange="handleBankCsv(event)">
          </label>
          <button class="pr-btn" onclick="downloadBankTemplate()"><i class="fa fa-download"></i> Template</button>
        </div>
      </div>
      <div class="pr-card-bd">
        <div style="font-size:12px;color:var(--t3);">
          Expected columns (any order; header names matched case-insensitively):
          <code>date, order_id, payment_id, amount, description</code>.
          Match priority: <b>payment_id</b> → <b>order_id</b> → <b>amount+date</b>.
        </div>
      </div>
    </div>
    <div id="bankResults" style="margin-top:12px;"></div>
  </div>

</div>
</div>

<style>
.pr-tab{font:600 12px/1 var(--font-b);padding:8px 14px;border-radius:6px;border:none;background:transparent;color:var(--t3);cursor:pointer;transition:all .15s;display:flex;align-items:center;gap:5px}
.pr-tab.active{background:var(--gold);color:#fff}
.pr-tab:not(.active):hover{color:var(--t1)}
.pr-badge{font-size:10px;padding:1px 6px;border-radius:8px;background:rgba(220,38,38,.15);color:#dc2626;font-weight:700}
.pr-tab.active .pr-badge{background:rgba(255,255,255,.25);color:#fff}
.pr-panel{display:none}.pr-panel.active{display:block}
.pr-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:10px}
.pr-card-hd{padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);font-size:13px}
.pr-card-bd{padding:12px 16px;font-size:12.5px}
.pr-row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid var(--border)}
.pr-row:last-child{border-bottom:none}
.pr-lbl{color:var(--t3);font-weight:600;font-size:11px}
.pr-val{color:var(--t1);font-family:var(--font-m);font-size:12px;text-align:right}
.pr-btn{font-size:11px;padding:5px 12px;border-radius:5px;border:1px solid var(--gold);color:var(--gold);background:transparent;cursor:pointer;font-weight:600;transition:all .15s}
.pr-btn:hover{background:var(--gold);color:#fff}
.pr-btn-danger{border-color:#dc2626;color:#dc2626}
.pr-btn-danger:hover{background:#dc2626;color:#fff}
.pr-stat{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px;text-align:center}
.pr-stat-val{font-size:22px;font-weight:700}
.pr-stat-lbl{font-size:11px;color:var(--t3);font-weight:600;margin-top:2px}
.pr-loading{text-align:center;padding:30px 0;color:var(--t3)}
.pr-empty{text-align:center;padding:30px 0;color:var(--t3);font-size:13px}
</style>

<script>
var BASE='<?= rtrim(base_url(),"/") ?>/';
var csrfName='<?= $this->security->get_csrf_token_name() ?>';
var csrfHash='<?= $this->security->get_csrf_hash() ?>';
function esc(s){var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML;}
function fmt(n){return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}

function post(url,data){
  var fd=new FormData();fd.append(csrfName,csrfHash);
  for(var k in data)fd.append(k,data[k]);
  return fetch(BASE+url,{method:'POST',body:fd,headers:{'X-CSRF-Token':csrfHash,'X-Requested-With':'XMLHttpRequest'}})
    .then(function(r){return r.json();}).then(function(r){if(r.csrf_hash)csrfHash=r.csrf_hash;return r;});
}

function prTab(tab,btn){
  document.querySelectorAll('.pr-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.pr-panel').forEach(function(p){p.classList.remove('active');});
  if(btn)btn.classList.add('active');
  var panelMap={failed:'panelFailed',orphans:'panelOrphans',duplicates:'panelDuplicates',success:'panelSuccess',bank:'panelBank'};
  document.getElementById(panelMap[tab]).classList.add('active');
}

/* ── Bank-statement CSV reconciliation ─────────────────────────── */
var _reconSuccessful = [];  // cached from loadRecon
var _reconFailed = [];
function downloadBankTemplate(){
  var csv = 'date,order_id,payment_id,amount,description\n'
          + '17-04-2026,order_ABC123,pay_XYZ789,2800.00,Razorpay collection for STU0001\n';
  var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a'); a.href=url; a.download='bank_reconciliation_template.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function parseCsv(text){
  var lines = text.split(/\r?\n/).filter(function(l){return l.trim() !== '';});
  if (lines.length < 2) return {headers: [], rows: []};
  var headers = lines[0].split(',').map(function(h){return h.trim().toLowerCase().replace(/"/g,'');});
  var rows = [];
  for (var i = 1; i < lines.length; i++) {
    // Simple CSV split — doesn't handle quoted commas; ok for bank exports.
    var cells = lines[i].split(',').map(function(c){return c.trim().replace(/^"|"$/g, '');});
    var row = {};
    headers.forEach(function(h, idx){ row[h] = cells[idx] || ''; });
    rows.push(row);
  }
  return {headers: headers, rows: rows};
}

function matchBankRow(bankRow, pool){
  var pid = (bankRow.payment_id || bankRow.paymentid || bankRow.txn_id || '').trim();
  var oid = (bankRow.order_id   || bankRow.orderid   || bankRow.ref_id  || '').trim();
  var amt = parseFloat(bankRow.amount || 0);
  var dateStr = (bankRow.date || '').substring(0,10);

  for (var i = 0; i < pool.length; i++) {
    var p = pool[i];
    if (pid && p.payment_id && pid === p.payment_id) return {match: p, by: 'payment_id'};
    if (oid && p.order_id   && oid === p.order_id)   return {match: p, by: 'order_id'};
  }
  // Amount + date fallback
  if (!isNaN(amt) && amt > 0) {
    for (var j = 0; j < pool.length; j++) {
      var p2 = pool[j];
      var pAmt = parseFloat(p2.amount || 0);
      var pDate = (p2.paid_at || p2.created_at || '').substring(0,10);
      if (Math.abs(pAmt - amt) < 0.01 && pDate === dateStr) {
        return {match: p2, by: 'amount+date'};
      }
    }
  }
  return {match: null, by: ''};
}

function handleBankCsv(ev){
  var file = ev.target.files && ev.target.files[0];
  if (!file) return;
  var reader = new FileReader();
  reader.onload = function(e){
    var parsed = parseCsv(e.target.result);
    var results = document.getElementById('bankResults');
    if (!parsed.rows.length) {
      results.innerHTML = '<div class="pr-empty" style="color:#dc2626">CSV has no data rows or could not be parsed.</div>';
      return;
    }
    // Match each row against successful + failed payments we already loaded.
    var pool = _reconSuccessful.concat(_reconFailed);
    if (!pool.length) {
      results.innerHTML = '<div class="pr-empty" style="color:#d97706"><i class="fa fa-exclamation-triangle"></i> Load the Successful tab first (click Load) so we have payments to match against.</div>';
      return;
    }
    var matched = 0, unmatched = 0;
    var rows = parsed.rows.map(function(r){
      var m = matchBankRow(r, pool);
      if (m.match) matched++; else unmatched++;
      return {raw: r, match: m.match, by: m.by};
    });

    var h = '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">'
          + '<div class="pr-stat"><div class="pr-stat-val">'+parsed.rows.length+'</div><div class="pr-stat-lbl">Rows in CSV</div></div>'
          + '<div class="pr-stat"><div class="pr-stat-val" style="color:#16a34a">'+matched+'</div><div class="pr-stat-lbl">Matched</div></div>'
          + '<div class="pr-stat"><div class="pr-stat-val" style="color:#dc2626">'+unmatched+'</div><div class="pr-stat-lbl">Unmatched</div></div>'
          + '</div>';

    h += '<table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr style="border-bottom:2px solid var(--border)">'
       + '<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Date</th>'
       + '<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Order / Payment ID</th>'
       + '<th style="text-align:right;padding:8px;color:var(--t3);font-size:11px">Amount</th>'
       + '<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Description</th>'
       + '<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Match</th></tr></thead><tbody>';
    rows.forEach(function(r){
      var color = r.match ? '#16a34a' : '#dc2626';
      var matchCell = r.match
        ? '<span style="color:#16a34a;font-weight:600;">✓ '+esc(r.match.receipt_key || r.match.order_id || '')+'</span> <span style="font-size:10px;color:var(--t3);">('+esc(r.by)+')</span>'
        : '<span style="color:#dc2626;font-weight:600;">✗ Unmatched</span>';
      h += '<tr style="border-bottom:1px solid var(--border);">'
         + '<td style="padding:8px;">'+esc(r.raw.date || '-')+'</td>'
         + '<td style="padding:8px;"><code style="font-size:11px">'+esc(r.raw.order_id || r.raw.payment_id || '-')+'</code></td>'
         + '<td style="padding:8px;text-align:right;font-family:var(--font-m);">'+fmt(r.raw.amount || 0)+'</td>'
         + '<td style="padding:8px;color:var(--t3);">'+esc(r.raw.description || '-')+'</td>'
         + '<td style="padding:8px;">'+matchCell+'</td></tr>';
    });
    h += '</tbody></table>';
    results.innerHTML = h;
  };
  reader.readAsText(file);
}

function loadRecon(){
  var params={date_from:document.getElementById('prFrom').value,date_to:document.getElementById('prTo').value,status:document.getElementById('prStatus').value};
  document.getElementById('panelFailed').innerHTML='<div class="pr-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';
  post('fee_management/get_reconciliation_data',params).then(function(r){
    if(r.status!=='success'){document.getElementById('panelFailed').innerHTML='<div class="pr-empty" style="color:#dc2626">'+esc(r.message)+'</div>';return;}
    renderStats(r.stats);
    renderFailed(r.fees_failed||[]);
    renderOrphans(r.orphans||[]);
    renderDuplicates(r.duplicates||[]);
    renderSuccess(r.successful||[]);
    // Cache for bank-statement CSV matching.
    _reconSuccessful = r.successful || [];
    _reconFailed     = r.fees_failed || [];
    document.getElementById('prFailedBadge').textContent=r.fees_failed?r.fees_failed.length:0;
    document.getElementById('prOrphanBadge').textContent=r.orphans?r.orphans.length:0;
    document.getElementById('prDupBadge').textContent=r.duplicates?r.duplicates.length:0;
  });
}

function renderStats(s){
  var el=document.getElementById('prStats');
  el.innerHTML='<div class="pr-stat"><div class="pr-stat-val">'+s.total+'</div><div class="pr-stat-lbl">Total Orders</div></div>'
    +'<div class="pr-stat"><div class="pr-stat-val" style="color:var(--gold)">'+s.paid+'</div><div class="pr-stat-lbl">Paid</div></div>'
    +'<div class="pr-stat"><div class="pr-stat-val" style="color:#dc2626">'+s.failed+'</div><div class="pr-stat-lbl">Failed</div></div>'
    +'<div class="pr-stat"><div class="pr-stat-val" style="color:#d97706">'+s.orphan+'</div><div class="pr-stat-lbl">Orphans</div></div>'
    +'<div class="pr-stat"><div class="pr-stat-val" style="color:#7c3aed">'+s.duplicate+'</div><div class="pr-stat-lbl">Duplicates</div></div>'
    +'<div class="pr-stat"><div class="pr-stat-val">Rs '+fmt(s.total_amount)+'</div><div class="pr-stat-lbl">Total Amount</div></div>'
    +'<div class="pr-stat"><div class="pr-stat-val" style="color:#dc2626">Rs '+fmt(s.failed_amount)+'</div><div class="pr-stat-lbl">At Risk</div></div>';
}

function itemCard(it,actions){
  var h='<div class="pr-card"><div class="pr-card-hd"><div>';
  h+='<strong style="color:var(--t1)">'+esc(it.student_name||it.student_id)+'</strong>';
  h+=' <span style="font-size:11px;color:var(--t3)">'+esc(it.order_id)+'</span>';
  h+='</div><div style="display:flex;gap:6px">'+actions+'</div></div>';
  h+='<div class="pr-card-bd">';
  h+='<div class="pr-row"><span class="pr-lbl">Amount</span><span class="pr-val" style="font-weight:700;color:var(--gold)">Rs '+fmt(it.amount)+'</span></div>';
  if(it.receipt_key) h+='<div class="pr-row"><span class="pr-lbl">Receipt</span><span class="pr-val">'+esc(it.receipt_key)+'</span></div>';
  h+='<div class="pr-row"><span class="pr-lbl">Status</span><span class="pr-val">'+statusBadge(it.status)+'</span></div>';
  h+='<div class="pr-row"><span class="pr-lbl">Gateway</span><span class="pr-val">'+esc(it.gateway)+'</span></div>';
  h+='<div class="pr-row"><span class="pr-lbl">Source</span><span class="pr-val">'+esc(it.source||'-')+'</span></div>';
  h+='<div class="pr-row"><span class="pr-lbl">Created</span><span class="pr-val">'+esc((it.created_at||'').substring(0,19))+'</span></div>';
  if(it.failure_reason) h+='<div class="pr-row"><span class="pr-lbl">Error</span><span class="pr-val" style="color:#dc2626;max-width:70%;text-align:right;word-break:break-word">'+esc(it.failure_reason)+'</span></div>';
  if(it.issue) h+='<div class="pr-row"><span class="pr-lbl">Issue</span><span class="pr-val" style="color:#d97706">'+esc(it.issue)+'</span></div>';
  if(it.hit_count) h+='<div class="pr-row"><span class="pr-lbl">Processing Attempts</span><span class="pr-val" style="color:#7c3aed;font-weight:700">'+it.hit_count+'</span></div>';
  h+='</div></div>';
  return h;
}

function statusBadge(s){
  var colors={paid:'background:rgba(15,118,110,.1);color:#0f766e',fees_failed:'background:rgba(220,38,38,.1);color:#dc2626',created:'background:rgba(59,130,246,.1);color:#2563eb',verified:'background:rgba(124,58,237,.1);color:#7c3aed',processing:'background:rgba(217,119,6,.1);color:#d97706'};
  return '<span style="font-size:10px;padding:2px 8px;border-radius:4px;font-weight:700;text-transform:uppercase;'+(colors[s]||'')+'">'+(s||'unknown')+'</span>';
}

function renderFailed(items){
  var el=document.getElementById('panelFailed');
  if(!items.length){el.innerHTML='<div class="pr-empty"><i class="fa fa-check-circle" style="color:var(--gold);font-size:20px;display:block;margin-bottom:8px"></i>No failed payments. All clear.</div>';return;}
  var h='';
  items.forEach(function(it){
    h+=itemCard(it,
      '<button class="pr-btn" onclick="retryPayment(\''+esc(it.record_id)+'\',this)"><i class="fa fa-refresh"></i> Retry</button>'
      +'<button class="pr-btn" onclick="viewAudit(\''+esc(it.order_id)+'\')"><i class="fa fa-eye"></i> Audit</button>'
    );
  });
  el.innerHTML=h;
}

function renderOrphans(items){
  var el=document.getElementById('panelOrphans');
  if(!items.length){el.innerHTML='<div class="pr-empty"><i class="fa fa-check-circle" style="color:var(--gold);font-size:20px;display:block;margin-bottom:8px"></i>No orphan payments.</div>';return;}
  var h='';
  items.forEach(function(it){h+=itemCard(it,'<button class="pr-btn" onclick="viewAudit(\''+esc(it.order_id||it.record_id)+'\')"><i class="fa fa-search"></i> Investigate</button>');});
  el.innerHTML=h;
}

function renderDuplicates(items){
  var el=document.getElementById('panelDuplicates');
  if(!items.length){el.innerHTML='<div class="pr-empty"><i class="fa fa-check-circle" style="color:var(--gold);font-size:20px;display:block;margin-bottom:8px"></i>No duplicate processing detected.</div>';return;}
  var h='';
  items.forEach(function(it){h+=itemCard(it,'<button class="pr-btn" onclick="viewAudit(\''+esc(it.order_id)+'\')"><i class="fa fa-eye"></i> View Audit</button>');});
  el.innerHTML=h;
}

function renderSuccess(items){
  var el=document.getElementById('panelSuccess');
  if(!items.length){el.innerHTML='<div class="pr-empty">No successful payments in this range.</div>';return;}
  var h='<table style="width:100%;border-collapse:collapse;font-size:12.5px"><thead><tr style="border-bottom:2px solid var(--border)">';
  h+='<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Student</th>';
  h+='<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Order</th>';
  h+='<th style="text-align:right;padding:8px;color:var(--t3);font-size:11px">Amount</th>';
  h+='<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Receipt</th>';
  h+='<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Date</th>';
  h+='<th style="text-align:left;padding:8px;color:var(--t3);font-size:11px">Source</th>';
  h+='</tr></thead><tbody>';
  items.forEach(function(it){
    h+='<tr style="border-bottom:1px solid var(--border)">';
    h+='<td style="padding:8px">'+esc(it.student_name||it.student_id)+'</td>';
    h+='<td style="padding:8px"><code style="font-size:11px">'+esc(it.order_id)+'</code></td>';
    h+='<td style="padding:8px;text-align:right;font-family:var(--font-m);font-weight:600;color:var(--gold)">'+fmt(it.amount)+'</td>';
    h+='<td style="padding:8px">'+esc(it.receipt_key||'-')+'</td>';
    h+='<td style="padding:8px;font-size:11px;color:var(--t3)">'+esc((it.paid_at||it.created_at||'').substring(0,10))+'</td>';
    h+='<td style="padding:8px">'+esc(it.source||'-')+'</td>';
    h+='</tr>';
  });
  h+='</tbody></table>';
  el.innerHTML=h;
}

function retryPayment(recordId,btn){
  if(!confirm('Retry fee processing for this payment?\n\nThis will attempt to create the receipt and fee records again.\n\nRecord: '+recordId))return;
  btn.disabled=true;btn.innerHTML='<i class="fa fa-spinner fa-spin"></i>';
  post('fee_management/retry_payment_processing',{order_record_id:recordId}).then(function(r){
    if(r.status==='success'){
      btn.innerHTML='<i class="fa fa-check"></i> Done';btn.style.color='#0f766e';btn.style.borderColor='#0f766e';
      setTimeout(loadRecon,1000);
    } else {
      alert(r.message||'Retry failed.');btn.disabled=false;btn.innerHTML='<i class="fa fa-refresh"></i> Retry';
    }
  });
}

function viewAudit(query){
  window.open(BASE+'fees/transaction_audit#search='+encodeURIComponent(query),'_blank');
}

// Auto-load on page open
loadRecon();
</script>
