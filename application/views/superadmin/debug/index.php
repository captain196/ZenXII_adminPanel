<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Debug Panel Styles ─────────────────────────────────────────────────── */
.dbg-toggle-wrap{display:flex;align-items:center;gap:14px;padding:16px 22px;
    background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);margin-bottom:20px;}
.dbg-toggle-badge{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:600;
    letter-spacing:.4px;padding:5px 12px;border-radius:20px;text-transform:uppercase;}
.dbg-on {background:rgba(22,163,74,.12);color:#16a34a;border:1px solid rgba(22,163,74,.3);}
.dbg-off{background:rgba(220,38,38,.10);color:#dc2626;border:1px solid rgba(220,38,38,.25);}
.dbg-pulse{width:8px;height:8px;border-radius:50%;animation:pulse 1.4s ease-in-out infinite;}
.dbg-pulse-green{background:#16a34a;box-shadow:0 0 0 0 rgba(22,163,74,.4);}
.dbg-pulse-red  {background:#dc2626;box-shadow:0 0 0 0 rgba(220,38,38,.4);}
@keyframes pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.3);opacity:.7}}

.stat-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:22px;}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r-sm);
    padding:14px 16px;text-align:center;}
.stat-num{font-size:26px;font-weight:700;font-family:var(--font-b);line-height:1;}
.stat-lbl{font-size:11px;color:var(--t3);margin-top:4px;text-transform:uppercase;letter-spacing:.4px;}
.stat-card.c-green .stat-num{color:var(--green);}
.stat-card.c-rose  .stat-num{color:var(--rose);}
.stat-card.c-amber .stat-num{color:var(--amber);}
.stat-card.c-blue  .stat-num{color:var(--blue);}
.stat-card.c-sa    .stat-num{color:var(--sa);}

/* Log table */
.log-table{width:100%;border-collapse:collapse;font-size:12.5px;font-family:var(--font-m);}
.log-table th{padding:9px 10px;background:var(--bg3);color:var(--t2);font-weight:600;
    border-bottom:2px solid var(--border);text-align:left;font-size:11.5px;letter-spacing:.3px;}
.log-table td{padding:7px 10px;border-bottom:1px solid var(--border);color:var(--t1);vertical-align:top;word-break:break-all;}
.log-table tr:hover td{background:var(--bg3);}
.log-table tr.t-slow td{background:rgba(217,119,6,.06);}
.log-table tr.t-error td{background:rgba(220,38,38,.06);}
.log-table tr.t-schema td{background:rgba(109,40,217,.06);}
.log-table tr.t-auth td{background:rgba(220,38,38,.06);}

.badge-type{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10.5px;font-weight:600;letter-spacing:.3px;}
.bt-read  {background:rgba(2,132,199,.12);color:#0284c7;}
.bt-write {background:rgba(22,163,74,.12);color:#16a34a;}
.bt-delete{background:rgba(220,38,38,.12);color:#dc2626;}
.bt-slow  {background:rgba(217,119,6,.14);color:#d97706;}
.bt-error {background:rgba(220,38,38,.12);color:#dc2626;}
.bt-schema{background:rgba(109,40,217,.12);color:#6d28d9;}
.bt-auth  {background:rgba(220,38,38,.12);color:#dc2626;}
.bt-req   {background:rgba(74,181,227,.12);color:#0284c7;}
.bt-ajax  {background:rgba(217,119,6,.14);color:#d97706;}
.bt-push  {background:rgba(22,163,74,.12);color:#16a34a;}

.path-cell{font-family:var(--font-m);font-size:11.5px;color:var(--sa3);max-width:260px;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.dur-fast {color:var(--green);}
.dur-mid  {color:var(--amber);}
.dur-slow {color:var(--rose);font-weight:600;}

.log-wrap{max-height:520px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--r-sm);}
.log-wrap::-webkit-scrollbar{width:5px;}
.log-wrap::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}

/* Schema check results */
.schema-row{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;
    border-bottom:1px solid var(--border);}
.schema-row:last-child{border-bottom:none;}
.schema-ok  {border-left:3px solid var(--green);}
.schema-fail{border-left:3px solid var(--rose);}
.schema-empty{border-left:3px solid var(--amber);}
.schema-error{border-left:3px solid var(--rose);}

/* Live indicator dot */
.live-dot{display:inline-block;width:7px;height:7px;border-radius:50%;
    background:var(--green);animation:pulse 1.4s ease-in-out infinite;margin-right:5px;}

/* Slow bar */
.perf-bar-wrap{background:var(--bg3);border-radius:4px;height:8px;overflow:hidden;width:100%;}
.perf-bar{height:100%;border-radius:4px;transition:width .5s ease;}
.pb-green{background:var(--green);}
.pb-amber{background:var(--amber);}
.pb-rose {background:var(--rose);}
</style>

<div class="content-wrapper">
  <section class="content-header">
    <h1 style="font-family:var(--font-b);font-weight:700;color:var(--t1);">
      <i class="fa fa-bug" style="color:var(--sa);margin-right:8px;"></i>Debug Testing Panel
    </h1>
    <ol class="breadcrumb">
      <li><a href="<?= base_url('superadmin/dashboard') ?>">SA Home</a></li>
      <li class="active">Debug Panel</li>
    </ol>
  </section>

  <section class="content">

    <!-- ── Debug Mode Toggle ──────────────────────────────────────────────── -->
    <div class="dbg-toggle-wrap">
      <div class="dbg-toggle-badge <?= $debug_on ? 'dbg-on' : 'dbg-off' ?>" id="debugBadge">
        <span class="dbg-pulse <?= $debug_on ? 'dbg-pulse-green' : 'dbg-pulse-red' ?>" id="debugPulse"></span>
        Debug Mode: <span id="debugStatusText"><?= $debug_on ? 'ACTIVE' : 'INACTIVE' ?></span>
      </div>
      <div style="flex:1;font-size:12.5px;color:var(--t3);">
        <?php if ($debug_on): ?>
          Logging every Firebase op, controller request, schema check, and unauthorized attempt to
          <code style="font-size:11px;color:var(--sa3);">application/logs/debug_<?= $today ?>.log</code>
        <?php else: ?>
          Enable to start capturing Firebase reads/writes, slow ops, schema mismatches, and security events.
        <?php endif; ?>
      </div>
      <button class="btn btn-sm <?= $debug_on ? 'btn-danger' : 'btn-success' ?>" id="toggleDebugBtn" style="font-size:12px;">
        <i class="fa <?= $debug_on ? 'fa-stop' : 'fa-play' ?>"></i>
        <?= $debug_on ? ' Disable Debug' : ' Enable Debug' ?>
      </button>
      <button class="btn btn-sm btn-default" id="runSchemaCheckBtn" style="font-size:12px;">
        <i class="fa fa-check-circle-o"></i> Schema Check
      </button>
      <button class="btn btn-sm btn-warning" id="clearLogsBtn" data-date="<?= $today ?>" style="font-size:12px;">
        <i class="fa fa-trash-o"></i> Clear Today's Logs
      </button>
    </div>

    <!-- ── Stat Cards ─────────────────────────────────────────────────────── -->
    <div class="stat-row" id="statRow">
      <div class="stat-card c-blue">
        <div class="stat-num" id="st-req"><?= $stats['total_requests'] ?></div>
        <div class="stat-lbl">Requests</div>
      </div>
      <div class="stat-card c-green">
        <div class="stat-num" id="st-fb"><?= $stats['total_firebase'] ?></div>
        <div class="stat-lbl">Firebase Ops</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="st-read" style="color:var(--blue);"><?= $stats['firebase_reads'] ?></div>
        <div class="stat-lbl">FB Reads</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="st-write" style="color:var(--green);"><?= $stats['firebase_writes'] ?></div>
        <div class="stat-lbl">FB Writes</div>
      </div>
      <div class="stat-card c-rose">
        <div class="stat-num" id="st-fberr"><?= $stats['firebase_errors'] ?></div>
        <div class="stat-lbl">FB Errors</div>
      </div>
      <div class="stat-card c-sa">
        <div class="stat-num" id="st-schema"><?= $stats['schema_mismatches'] ?></div>
        <div class="stat-lbl">Schema Issues</div>
      </div>
      <div class="stat-card c-rose">
        <div class="stat-num" id="st-unauth"><?= $stats['unauthorized'] ?></div>
        <div class="stat-lbl">Unauth Attempts</div>
      </div>
      <div class="stat-card c-amber">
        <div class="stat-num" id="st-slow"><?= $stats['slow_ops'] ?></div>
        <div class="stat-lbl">Slow Ops >&nbsp;<?= $slow_threshold ?>ms</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="st-ajax" style="color:var(--amber);"><?= $stats['ajax_errors'] ?></div>
        <div class="stat-lbl">AJAX Errors</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="st-reqms" style="color:var(--t1);font-size:20px;"><?= $stats['avg_request_ms'] ?>ms</div>
        <div class="stat-lbl">Avg Request</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="st-fbms" style="color:var(--t1);font-size:20px;"><?= $stats['avg_firebase_ms'] ?>ms</div>
        <div class="stat-lbl">Avg FB Op</div>
      </div>
    </div>

    <!-- ── Date Selector ─────────────────────────────────────────────────── -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
      <label style="font-size:12.5px;color:var(--t2);font-weight:600;margin:0;">Log Date:</label>
      <select id="logDatePicker" class="form-control" style="width:auto;font-size:13px;min-width:160px;">
        <?php foreach ($log_dates as $d): ?>
          <option value="<?= $d ?>" <?= $d === $today ? 'selected' : '' ?>><?= $d ?></option>
        <?php endforeach; ?>
        <?php if (empty($log_dates)): ?>
          <option value="<?= $today ?>"><?= $today ?> (no logs yet)</option>
        <?php endif; ?>
      </select>
      <button class="btn btn-sm btn-primary" id="refreshLogsBtn" style="font-size:12px;">
        <i class="fa fa-refresh"></i> Refresh
      </button>
      <label style="font-size:12px;color:var(--t3);margin:0;">
        <input type="checkbox" id="autoRefreshChk" style="margin-right:4px;">Auto-refresh (10s)
      </label>
      <span id="lastRefreshedAt" style="font-size:11.5px;color:var(--t4);margin-left:auto;"></span>
    </div>

    <!-- ── Tabs ──────────────────────────────────────────────────────────── -->
    <ul class="nav nav-tabs" style="border-color:var(--border);margin-bottom:0;">
      <li class="active"><a href="#tab-requests" data-toggle="tab">
        <i class="fa fa-exchange"></i> Requests <span class="badge" id="cnt-req" style="background:var(--blue);">0</span>
      </a></li>
      <li><a href="#tab-firebase" data-toggle="tab">
        <i class="fa fa-database"></i> Firebase Ops <span class="badge" id="cnt-fb" style="background:var(--green);">0</span>
      </a></li>
      <li><a href="#tab-schema" data-toggle="tab">
        <i class="fa fa-sitemap"></i> Schema Issues <span class="badge" id="cnt-schema" style="background:var(--sa);">0</span>
      </a></li>
      <li><a href="#tab-security" data-toggle="tab">
        <i class="fa fa-shield"></i> Security <span class="badge" id="cnt-sec" style="background:var(--rose);">0</span>
      </a></li>
      <li><a href="#tab-perf" data-toggle="tab">
        <i class="fa fa-tachometer"></i> Performance <span class="badge" id="cnt-perf" style="background:var(--amber);">0</span>
      </a></li>
      <li><a href="#tab-schema-live" data-toggle="tab">
        <i class="fa fa-check-circle"></i> Live Schema Check
      </a></li>
    </ul>

    <div class="tab-content" style="background:var(--bg2);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--r) var(--r);padding:18px;">

      <!-- ── Request Log Tab ───────────────────────────────────────────── -->
      <div class="tab-pane active" id="tab-requests">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--t2);">HTTP Request Log</span>
          <input type="text" id="filterReq" placeholder="Search URI / controller..." class="form-control"
            style="width:260px;font-size:12px;height:30px;margin-left:auto;">
        </div>
        <div class="log-wrap" id="wrapReq">
          <table class="log-table">
            <thead><tr>
              <th>Time</th><th>Method</th><th>URI</th><th>Controller</th>
              <th>Action</th><th>Duration</th><th>IP</th>
            </tr></thead>
            <tbody id="bodyReq"><tr><td colspan="7" style="text-align:center;color:var(--t4);padding:20px;">
              <i class="fa fa-spin fa-circle-o-notch"></i> Loading...
            </td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ── Firebase Ops Tab ──────────────────────────────────────────── -->
      <div class="tab-pane" id="tab-firebase">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
          <span style="font-size:12.5px;font-weight:600;color:var(--t2);">Firebase Operations</span>
          <div class="btn-group btn-group-xs" id="fbTypeFilter">
            <button class="btn btn-default active" data-fb="ALL">All</button>
            <button class="btn btn-default" data-fb="READ">Reads</button>
            <button class="btn btn-default" data-fb="WRITE">Writes</button>
            <button class="btn btn-default" data-fb="DELETE">Deletes</button>
            <button class="btn btn-default" data-fb="ERROR">Errors</button>
          </div>
          <input type="text" id="filterFb" placeholder="Filter path..." class="form-control"
            style="width:240px;font-size:12px;height:30px;margin-left:auto;">
        </div>
        <div class="log-wrap" id="wrapFb">
          <table class="log-table">
            <thead><tr>
              <th>Time</th><th>Op</th><th>Path</th><th>Duration</th><th>Size</th><th>Caller</th>
            </tr></thead>
            <tbody id="bodyFb"><tr><td colspan="6" style="text-align:center;color:var(--t4);padding:20px;">
              Click Refresh to load.
            </td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ── Schema Issues Tab ─────────────────────────────────────────── -->
      <div class="tab-pane" id="tab-schema">
        <p style="font-size:12.5px;color:var(--t3);margin-bottom:10px;">
          Schema mismatches detected during Firebase reads. These indicate missing required fields
          compared to the expected GraderIQ node structure.
        </p>
        <div class="log-wrap" id="wrapSchema">
          <table class="log-table">
            <thead><tr>
              <th>Time</th><th>Path</th><th>Expected Schema</th><th>Missing Fields</th>
            </tr></thead>
            <tbody id="bodySchema"><tr><td colspan="4" style="text-align:center;color:var(--t4);padding:20px;">
              No mismatches recorded yet.
            </td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ── Security Tab ──────────────────────────────────────────────── -->
      <div class="tab-pane" id="tab-security">
        <div class="log-wrap" id="wrapSec">
          <table class="log-table">
            <thead><tr>
              <th>Time</th><th>Type</th><th>URI / URL</th><th>IP</th><th>Details</th>
            </tr></thead>
            <tbody id="bodySec"><tr><td colspan="5" style="text-align:center;color:var(--t4);padding:20px;">
              No security events recorded.
            </td></tr></tbody>
          </table>
        </div>
      </div>

      <!-- ── Performance Tab ───────────────────────────────────────────── -->
      <div class="tab-pane" id="tab-perf">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <!-- Slow ops list -->
          <div>
            <p style="font-size:12.5px;font-weight:600;color:var(--t2);margin-bottom:8px;">
              <i class="fa fa-warning" style="color:var(--amber);"></i> Slow Operations (&gt;<?= $slow_threshold ?>ms)
            </p>
            <div class="log-wrap" id="wrapSlow" style="max-height:380px;">
              <table class="log-table">
                <thead><tr><th>Time</th><th>Op</th><th>Path</th><th>Duration</th></tr></thead>
                <tbody id="bodySlow"><tr><td colspan="4" style="text-align:center;color:var(--t4);padding:20px;">No slow ops.</td></tr></tbody>
              </table>
            </div>
          </div>
          <!-- Top Firebase paths -->
          <div>
            <p style="font-size:12.5px;font-weight:600;color:var(--t2);margin-bottom:8px;">
              <i class="fa fa-bar-chart" style="color:var(--blue);"></i> Top Firebase Paths (by call count)
            </p>
            <div id="topPathsWrap" style="padding:6px 0;">
              <p style="color:var(--t4);font-size:12px;">Loading...</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Live Schema Check Tab ─────────────────────────────────────── -->
      <div class="tab-pane" id="tab-schema-live">
        <p style="font-size:12.5px;color:var(--t3);margin-bottom:14px;">
          Runs a live Firebase read against key paths and validates required fields.
          Results show pass/fail for each node schema.
        </p>
        <button class="btn btn-primary btn-sm" id="runSchemaBtn2">
          <i class="fa fa-check"></i> Run Schema Validation Now
        </button>
        <span id="schemaCheckedAt" style="font-size:11.5px;color:var(--t4);margin-left:12px;"></span>
        <div id="schemaResultsWrap" style="margin-top:14px;border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;">
          <p style="color:var(--t4);font-size:12.5px;text-align:center;padding:30px;">
            Click the button above to run schema validation.
          </p>
        </div>
      </div>

    </div><!-- /tab-content -->

  </section>
</div><!-- /content-wrapper -->

<script>
(function(){
'use strict';

var BASE  = BASE_URL;
var _csrf = function(obj){ var t=document.querySelector('meta[name="csrf-name"]'),h=document.querySelector('meta[name="csrf-token"]'); if(t&&h){obj[t.content]=h.content;} return obj; };

/* ── Colour helpers ───────────────────────────────────────────────────── */
function opBadge(op){
    var map={READ:'bt-read READ',SHALLOW:'bt-read SHALLOW',WRITE:'bt-write WRITE',
             DELETE:'bt-delete DELETE',PUSH:'bt-push PUSH',
             FIREBASE_ERROR:'bt-error ERROR',FIREBASE_READ:'bt-read READ',
             FIREBASE_WRITE:'bt-write WRITE'};
    var cls = (map[op]||'bt-req '+op).split(' ');
    return '<span class="badge-type '+cls[0]+'">'+cls[1]+'</span>';
}
function durClass(ms){ return ms>=500?'dur-slow':ms>=200?'dur-mid':'dur-fast'; }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtBytes(b){ b=parseInt(b)||0; return b>=1048576?(b/1048576).toFixed(1)+' MB':b>=1024?(b/1024).toFixed(1)+' KB':b+' B'; }
function fmtMs(ms){ ms=parseFloat(ms)||0; return '<span class="'+durClass(ms)+'">'+ms.toFixed(1)+'ms</span>'; }
function typeBadge(type){
    var m={FIREBASE_READ:'bt-read',FIREBASE_WRITE:'bt-write',FIREBASE_ERROR:'bt-error',
           SCHEMA_MISMATCH:'bt-schema',UNAUTHORIZED:'bt-auth',AJAX_ERROR:'bt-ajax',
           SLOW_OP:'bt-slow',REQUEST:'bt-req'};
    return '<span class="badge-type '+(m[type]||'bt-req')+'">'+type+'</span>';
}

/* ── Stored log data (all fetched once per refresh) ─────────────────── */
var _all = [];

/* ── Render functions ────────────────────────────────────────────────── */
function renderRequests(entries){
    var rows = entries.filter(function(e){return e.type==='REQUEST';});
    var q = ($('#filterReq').val()||'').toLowerCase();
    if(q) rows=rows.filter(function(e){return (e.uri||'').toLowerCase().includes(q)||(e.controller||'').toLowerCase().includes(q);});
    $('#cnt-req').text(rows.length);
    if(!rows.length){$('#bodyReq').html('<tr><td colspan="7" style="text-align:center;color:var(--t4);padding:16px;">No request entries.</td></tr>');return;}
    var h='';
    rows.forEach(function(e){
        h+='<tr><td>'+esc(e.ts||'')+'</td>'
          +'<td><span class="badge-type bt-req">'+esc(e.method||'GET')+'</span></td>'
          +'<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(e.uri||'')+'">'+esc(e.uri||'')+'</td>'
          +'<td>'+esc(e.controller||'')+'</td>'
          +'<td>'+esc(e.action||'')+'</td>'
          +'<td>'+fmtMs(e.duration_ms)+'</td>'
          +'<td>'+esc(e.ip||'')+'</td></tr>';
    });
    $('#bodyReq').html(h);
}

var _fbFilter = 'ALL';
function renderFirebase(entries){
    var rows = entries.filter(function(e){
        return e.type==='FIREBASE_READ'||e.type==='FIREBASE_WRITE'||
               e.type==='FIREBASE_ERROR'||e.op==='SHALLOW'||e.op==='PUSH'||e.op==='DELETE';
    });
    if(_fbFilter!=='ALL'){
        rows=rows.filter(function(e){
            if(_fbFilter==='READ')  return e.op==='READ'||e.op==='SHALLOW';
            if(_fbFilter==='WRITE') return e.op==='WRITE'||e.op==='PUSH';
            if(_fbFilter==='DELETE')return e.op==='DELETE';
            if(_fbFilter==='ERROR') return e.type==='FIREBASE_ERROR';
            return true;
        });
    }
    var q=($('#filterFb').val()||'').toLowerCase();
    if(q) rows=rows.filter(function(e){return (e.path||'').toLowerCase().includes(q);});
    $('#cnt-fb').text(rows.length);
    if(!rows.length){$('#bodyFb').html('<tr><td colspan="6" style="text-align:center;color:var(--t4);padding:16px;">No Firebase operations.</td></tr>');return;}
    var h='';
    rows.forEach(function(e){
        var isErr=e.type==='FIREBASE_ERROR';
        h+='<tr class="'+(isErr?'t-error':'')+'">'
          +'<td>'+esc(e.ts||'')+'</td>'
          +'<td>'+opBadge(e.op||e.type)+'</td>'
          +'<td class="path-cell" title="'+esc(e.path||'')+'">'+esc(e.path||'')+'</td>'
          +'<td>'+fmtMs(e.duration_ms)+'</td>'
          +'<td style="color:var(--t3);font-size:11px;">'+fmtBytes(e.size_bytes)+'</td>'
          +'<td style="font-size:11px;color:var(--t3);">'+esc(e.caller||'')+'</td>'
          +(isErr?'':'')+'</tr>';
        if(isErr){
            h+='<tr class="t-error"><td colspan="6" style="padding:4px 10px 8px;font-size:11.5px;color:var(--rose);">'
               +'<i class="fa fa-times-circle"></i> '+esc(e.error||'')+'</td></tr>';
        }
    });
    $('#bodyFb').html(h);
}

function renderSchema(entries){
    var rows=entries.filter(function(e){return e.type==='SCHEMA_MISMATCH';});
    $('#cnt-schema').text(rows.length);
    if(!rows.length){$('#bodySchema').html('<tr><td colspan="4" style="text-align:center;color:var(--green);padding:16px;"><i class="fa fa-check-circle"></i> No schema mismatches detected.</td></tr>');return;}
    var h='';
    rows.forEach(function(e){
        h+='<tr class="t-schema">'
          +'<td>'+esc(e.ts||'')+'</td>'
          +'<td class="path-cell" title="'+esc(e.path||'')+'">'+esc(e.path||'')+'</td>'
          +'<td style="font-size:11px;color:var(--t3);">'+esc(e.schema||'')+'</td>'
          +'<td style="color:var(--rose);">'+esc((e.missing||[]).join(', '))+'</td></tr>';
    });
    $('#bodySchema').html(h);
}

function renderSecurity(entries){
    var rows=entries.filter(function(e){return e.type==='UNAUTHORIZED'||e.type==='AJAX_ERROR';});
    $('#cnt-sec').text(rows.length);
    if(!rows.length){$('#bodySec').html('<tr><td colspan="5" style="text-align:center;color:var(--green);padding:16px;"><i class="fa fa-shield"></i> No security events.</td></tr>');return;}
    var h='';
    rows.forEach(function(e){
        var detail='';
        if(e.type==='UNAUTHORIZED') detail='SA ID: '+(e.sa_id||'none');
        if(e.type==='AJAX_ERROR')   detail='Status: '+e.status+' — '+(e.error||'');
        h+='<tr class="t-auth">'
          +'<td>'+esc(e.ts||'')+'</td>'
          +'<td>'+typeBadge(e.type)+'</td>'
          +'<td style="font-size:12px;word-break:break-all;">'+esc(e.uri||e.url||'')+'</td>'
          +'<td>'+esc(e.ip||'')+'</td>'
          +'<td style="font-size:11.5px;color:var(--t3);">'+esc(detail)+'</td></tr>';
    });
    $('#bodySec').html(h);
}

function renderPerf(entries, stats){
    // Slow ops table
    var slow=entries.filter(function(e){return e.type==='SLOW_OP';});
    $('#cnt-perf').text(slow.length);
    if(!slow.length){$('#bodySlow').html('<tr><td colspan="4" style="text-align:center;color:var(--green);padding:16px;"><i class="fa fa-check"></i> No slow ops.</td></tr>');}
    else{
        var h='';
        slow.forEach(function(e){
            h+='<tr class="t-slow"><td>'+esc(e.ts||'')+'</td><td>'+opBadge(e.op||'')+'</td>'
              +'<td class="path-cell" title="'+esc(e.path||'')+'">'+esc(e.path||'')+'</td>'
              +'<td>'+fmtMs(e.duration_ms)+'</td></tr>';
        });
        $('#bodySlow').html(h);
    }

    // Top paths bar chart
    var paths = stats && stats.top_paths ? stats.top_paths : {};
    var keys  = Object.keys(paths);
    if(!keys.length){$('#topPathsWrap').html('<p style="color:var(--t4);font-size:12px;">No path data yet.</p>');return;}
    var maxVal = Math.max.apply(null, keys.map(function(k){return paths[k];}));
    var h2='';
    keys.forEach(function(k){
        var pct = maxVal>0?Math.round(paths[k]/maxVal*100):0;
        var cls = pct>66?'pb-rose':pct>33?'pb-amber':'pb-green';
        h2+='<div style="margin-bottom:8px;">'
           +'<div style="display:flex;justify-content:space-between;margin-bottom:3px;">'
           +'<span style="font-size:11px;color:var(--t2);font-family:var(--font-m);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+esc(k)+'">'+esc(k)+'</span>'
           +'<span style="font-size:11px;color:var(--t3);">'+paths[k]+'×</span></div>'
           +'<div class="perf-bar-wrap"><div class="perf-bar '+cls+'" style="width:'+pct+'%;"></div></div>'
           +'</div>';
    });
    $('#topPathsWrap').html(h2);
}

/* ── Fetch & render all logs ─────────────────────────────────────────── */
function loadLogs(){
    var date=$('#logDatePicker').val();
    $('#bodyReq,#bodyFb,#bodySchema,#bodySec,#bodySlow').html('<tr><td colspan="9" style="text-align:center;color:var(--t4);padding:14px;"><i class="fa fa-spin fa-circle-o-notch"></i> Loading...</td></tr>');

    $.ajax({
        url:BASE+'superadmin/debug/get_logs',type:'POST',dataType:'json',
        data:_csrf({date:date}),
        success:function(r){
            if(r.status!=='success') return;
            _all=r.entries||[];
            renderRequests(_all);
            renderFirebase(_all);
            renderSchema(_all);
            renderSecurity(_all);

            // fetch stats for perf tab
            $.ajax({url:BASE+'superadmin/debug/get_stats',type:'POST',dataType:'json',
                data:_csrf({date:date}),
                success:function(sr){
                    var st=sr.stats||{};
                    renderPerf(_all, st);
                    // update stat cards
                    $('#st-req').text(st.total_requests||0);
                    $('#st-fb').text(st.total_firebase||0);
                    $('#st-read').text(st.firebase_reads||0);
                    $('#st-write').text(st.firebase_writes||0);
                    $('#st-fberr').text(st.firebase_errors||0);
                    $('#st-schema').text(st.schema_mismatches||0);
                    $('#st-unauth').text(st.unauthorized||0);
                    $('#st-slow').text(st.slow_ops||0);
                    $('#st-ajax').text(st.ajax_errors||0);
                    $('#st-reqms').text((st.avg_request_ms||0)+'ms');
                    $('#st-fbms').text((st.avg_firebase_ms||0)+'ms');
                }
            });

            $('#lastRefreshedAt').text('Refreshed: '+new Date().toLocaleTimeString());
        },
        error:function(){ saToast('Failed to load debug logs.','error'); }
    });
}

/* ── Debug Mode Toggle ───────────────────────────────────────────────── */
$('#toggleDebugBtn').on('click',function(){
    var cur='<?= $debug_on ? '1' : '0' ?>';
    // read live state from badge text
    var isOn=$('#debugStatusText').text().trim()==='ACTIVE';
    var enable=isOn?'0':'1';
    $.ajax({url:BASE+'superadmin/debug/toggle_debug',type:'POST',dataType:'json',
        data:_csrf({enable:enable}),
        success:function(r){
            if(r.status==='success'){
                var on=r.enabled;
                $('#debugStatusText').text(on?'ACTIVE':'INACTIVE');
                $('#debugBadge').removeClass('dbg-on dbg-off').addClass(on?'dbg-on':'dbg-off');
                $('#debugPulse').removeClass('dbg-pulse-green dbg-pulse-red').addClass(on?'dbg-pulse-green':'dbg-pulse-red');
                $('#toggleDebugBtn').removeClass('btn-success btn-danger').addClass(on?'btn-danger':'btn-success')
                    .html('<i class="fa '+(on?'fa-stop':'fa-play')+'"></i> '+(on?'Disable Debug':'Enable Debug'));
                saToast(r.message, 'success');
            } else { saToast(r.message||'Toggle failed.','error'); }
        }
    });
});

/* ── Filter events ───────────────────────────────────────────────────── */
$('#filterReq').on('input', function(){ renderRequests(_all); });
$('#filterFb').on('input',  function(){ renderFirebase(_all); });
$('#fbTypeFilter').on('click','button',function(){
    $('#fbTypeFilter button').removeClass('active');
    $(this).addClass('active');
    _fbFilter=$(this).data('fb');
    renderFirebase(_all);
});

/* ── Refresh ─────────────────────────────────────────────────────────── */
$('#refreshLogsBtn').on('click', loadLogs);
$('#logDatePicker').on('change', loadLogs);

/* ── Auto-refresh ────────────────────────────────────────────────────── */
var _autoInterval=null;
$('#autoRefreshChk').on('change',function(){
    if(this.checked){_autoInterval=setInterval(loadLogs,10000);}
    else{clearInterval(_autoInterval);_autoInterval=null;}
});

/* ── Clear logs ──────────────────────────────────────────────────────── */
$('#clearLogsBtn').on('click',function(){
    var date=$('#logDatePicker').val();
    if(!confirm('Delete debug log for '+date+'?')) return;
    $.ajax({url:BASE+'superadmin/debug/clear_debug_logs',type:'POST',dataType:'json',
        data:_csrf({date:date}),
        success:function(r){
            saToast('Deleted '+r.deleted+' log file(s).','success');
            _all=[];
            renderRequests([]);renderFirebase([]);renderSchema([]);renderSecurity([]);renderPerf([],{});
        }
    });
});

/* ── Schema Check (button in toolbar) ───────────────────────────────── */
function runSchemaCheck(){
    var $btn=$('#runSchemaCheckBtn,#runSchemaBtn2');
    $btn.prop('disabled',true).html('<i class="fa fa-spin fa-circle-o-notch"></i> Checking...');
    $.ajax({url:BASE+'superadmin/debug/schema_check',type:'POST',dataType:'json',
        data:_csrf({}),
        success:function(r){
            $btn.prop('disabled',false).html('<i class="fa fa-check-circle-o"></i> Schema Check');
            if(r.status!=='success'){saToast('Schema check failed.','error');return;}
            $('#schemaCheckedAt').text('Checked at '+r.checked_at);
            var res=r.results||[];
            var h='';
            res.forEach(function(row){
                var cls='schema-'+row.status;
                var icon=row.status==='ok'?'<i class="fa fa-check-circle" style="color:var(--green);"></i>':
                         row.status==='empty'?'<i class="fa fa-minus-circle" style="color:var(--amber);"></i>':
                         '<i class="fa fa-times-circle" style="color:var(--rose);"></i>';
                h+='<div class="schema-row '+cls+'">'
                  +'<div style="width:26px;flex-shrink:0;padding-top:2px;">'+icon+'</div>'
                  +'<div style="flex:1;">'
                  +'<div style="font-size:12.5px;font-weight:600;color:var(--t1);">'+esc(row.label)+'</div>'
                  +'<div style="font-size:11px;color:var(--t4);font-family:var(--font-m);">'+esc(row.path)+'</div>'
                  +(row.issues&&row.issues.length?'<div style="margin-top:4px;font-size:11.5px;color:var(--rose);">'+row.issues.map(esc).join('<br>')+'</div>':'')
                  +'</div>'
                  +'</div>';
            });
            $('#schemaResultsWrap').html(h||'<p style="text-align:center;padding:20px;color:var(--t4);">No results.</p>');
            // Switch to schema live tab
            $('a[href="#tab-schema-live"]').tab('show');
        },
        error:function(){ $btn.prop('disabled',false).html('<i class="fa fa-check-circle-o"></i> Schema Check'); saToast('Request failed.','error'); }
    });
}
$('#runSchemaCheckBtn').on('click', runSchemaCheck);
$('#runSchemaBtn2').on('click', runSchemaCheck);

/* ── Init ────────────────────────────────────────────────────────────── */
loadLogs();

})();
</script>
