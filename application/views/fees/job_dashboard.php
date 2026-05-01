<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<style>
:root{--fm-bg:#f7f8fb;--fm-card:#fff;--fm-border:#e5e7eb;--fm-text:#1f2937;
  --fm-muted:#6b7280;--fm-teal:#0f766e;--fm-blue:#1d4ed8;--fm-amber:#b45309;
  --fm-red:#b91c1c;--fm-green:#047857;--fm-violet:#6d28d9;}
.jd-wrap{background:var(--fm-bg);padding:14px 18px;color:var(--fm-text);font:13px/1.45 system-ui,sans-serif;}
.jd-hd{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.jd-hd h1{font-size:20px;margin:0;font-weight:700;}
.jd-hd .muted{color:var(--fm-muted);font-size:12px;}
.jd-hd .spacer{flex:1;}
.jd-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.3px;}
.jd-pill.pending   {background:#eef2ff;color:#3730a3;}
.jd-pill.running   {background:#ecfdf5;color:#065f46;}
.jd-pill.paused    {background:#fef3c7;color:#92400e;}
.jd-pill.completed {background:#d1fae5;color:#065f46;}
.jd-pill.failed    {background:#fee2e2;color:#991b1b;}
.jd-pill.unknown   {background:#f1f5f9;color:#475569;}
.jd-grid{display:grid;grid-template-columns:minmax(520px,2fr) minmax(360px,1fr);gap:14px;}
.jd-card{background:var(--fm-card);border:1px solid var(--fm-border);border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.03);}
.jd-card h2{margin:0;padding:12px 14px;border-bottom:1px solid var(--fm-border);font-size:14px;font-weight:700;color:#111;}
.jd-toolbar{display:flex;gap:8px;padding:8px 14px;border-bottom:1px solid var(--fm-border);align-items:center;background:#fafafa;}
.jd-toolbar select,.jd-toolbar input{padding:5px 8px;border:1px solid #d1d5db;border-radius:6px;font:inherit;}
.jd-btn{padding:5px 10px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font:inherit;}
.jd-btn:hover{background:#f3f4f6;}
.jd-btn.primary{background:var(--fm-blue);color:#fff;border-color:var(--fm-blue);}
.jd-btn.danger {background:var(--fm-red);color:#fff;border-color:var(--fm-red);}
.jd-btn.warn   {background:var(--fm-amber);color:#fff;border-color:var(--fm-amber);}
.jd-btn.green  {background:var(--fm-green);color:#fff;border-color:var(--fm-green);}
.jd-btn[disabled]{opacity:.5;cursor:not-allowed;}
table.jd-tbl{width:100%;border-collapse:collapse;font-size:12px;}
table.jd-tbl th{background:#f3f4f6;text-align:left;padding:8px 10px;font-weight:600;color:#374151;border-bottom:1px solid var(--fm-border);}
table.jd-tbl td{padding:7px 10px;border-bottom:1px solid #f1f3f5;vertical-align:middle;}
table.jd-tbl tr.selected td{background:#eef6ff;}
table.jd-tbl tr:hover{background:#f9fafb;cursor:pointer;}
.progress{position:relative;width:120px;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;}
.progress > i{position:absolute;inset:0 auto 0 0;background:var(--fm-blue);}
.progress-txt{font-size:11px;color:var(--fm-muted);margin-top:2px;}
.workers{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:8px;padding:10px 14px;}
.worker{background:#fafcff;border:1px solid var(--fm-border);border-radius:8px;padding:10px;}
.worker .wid{font-weight:700;color:var(--fm-blue);font-size:12px;}
.worker .row{display:flex;justify-content:space-between;font-size:11px;color:var(--fm-muted);margin-top:3px;}
.worker.dead{border-color:var(--fm-red);background:#fff1f2;}
.worker.running{border-left:3px solid var(--fm-green);}
.worker.paused{border-left:3px solid var(--fm-amber);}
.worker.completed{border-left:3px solid var(--fm-muted);}
.worker.completed_with_errors{border-left:3px solid var(--fm-red);}
.jd-actions{padding:10px 14px;border-top:1px solid var(--fm-border);background:#fafafa;display:flex;gap:8px;flex-wrap:wrap;}
.jd-detail-empty{padding:28px 14px;color:var(--fm-muted);text-align:center;}
.jd-alerts{max-height:320px;overflow:auto;}
.alert{padding:8px 14px;border-bottom:1px solid #f1f3f5;font-size:12px;}
.alert .sev{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;margin-right:6px;}
.alert .sev.info{background:#eef2ff;color:#3730a3;}
.alert .sev.warning{background:#fef3c7;color:#92400e;}
.alert .sev.error{background:#fee2e2;color:#991b1b;}
.alert .sev.critical{background:#7f1d1d;color:#fff;}
.alert .title{font-weight:600;}
.alert .msg{color:var(--fm-muted);margin-top:2px;}
.alert .foot{font-size:10px;color:var(--fm-muted);margin-top:3px;display:flex;gap:8px;}
.kvs{display:grid;grid-template-columns:auto 1fr;gap:3px 12px;padding:10px 14px;font-size:12px;}
.kvs dt{color:var(--fm-muted);}
.kvs dd{margin:0;font-weight:500;}
table.jd-tbl td.num{font-variant-numeric:tabular-nums;text-align:right;}
.small{font-size:11px;color:var(--fm-muted);}
.log-tail{padding:8px 14px;font-family:ui-monospace,Menlo,monospace;font-size:11px;background:#0f172a;color:#cbd5e1;max-height:180px;overflow:auto;white-space:pre-wrap;}
</style>

<div class="jd-wrap">
  <div class="jd-hd">
    <h1>Fee Generation Jobs</h1>
    <span class="muted">school <?= htmlspecialchars($school_name) ?> · session <?= htmlspecialchars($session_year) ?></span>
    <span class="spacer"></span>
    <label class="small"><input type="checkbox" id="jd-autorefresh" checked> auto-refresh (5s)</label>
    <button class="jd-btn" id="jd-refresh">Refresh</button>
  </div>

  <div class="jd-grid">
    <div class="jd-card">
      <h2>Jobs</h2>
      <div class="jd-toolbar">
        <label>Status
          <select id="jd-filter-status">
            <option value="">(all)</option>
            <option>pending</option><option>running</option><option>paused</option>
            <option>completed</option><option>failed</option>
          </select>
        </label>
        <label>Limit
          <select id="jd-filter-limit">
            <option>20</option><option selected>50</option><option>100</option>
          </select>
        </label>
      </div>
      <div style="max-height:380px;overflow:auto;">
        <table class="jd-tbl" id="jd-jobs">
          <thead>
            <tr><th>Job</th><th>Status</th><th>Progress</th>
                <th class="num">Students</th><th class="num">Demands</th>
                <th class="num">Failed</th><th>Requested</th><th>Duration</th></tr>
          </thead>
          <tbody><tr><td colspan="8" class="jd-detail-empty">Loading…</td></tr></tbody>
        </table>
      </div>

      <h2 style="margin-top:0;border-top:1px solid var(--fm-border);">Selected Job Detail</h2>
      <div id="jd-detail"><div class="jd-detail-empty">Pick a job above to inspect workers, retries, failed batches.</div></div>
    </div>

    <div class="jd-card">
      <h2>Recent Alerts</h2>
      <div class="jd-toolbar">
        <label>Severity
          <select id="jd-alert-sev">
            <option value="">(all)</option>
            <option>info</option><option>warning</option><option>error</option><option>critical</option>
          </select>
        </label>
        <label>Status
          <select id="jd-alert-status">
            <option value="open">open</option>
            <option value="">(all)</option>
            <option>acknowledged</option><option>resolved</option>
          </select>
        </label>
      </div>
      <div class="jd-alerts" id="jd-alerts"><div class="jd-detail-empty">Loading…</div></div>
    </div>
  </div>
</div>

<script>
(() => {
  const BASE = '<?= site_url() ?>';
  const CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
  let   CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
  let   selectedJobId = null;
  let   pollTimer = null;

  const fmtDur = s => {
    if (!s || s < 0) return '—';
    if (s < 60)  return s + 's';
    if (s < 3600) return Math.floor(s/60) + 'm ' + (s%60) + 's';
    return Math.floor(s/3600) + 'h ' + Math.floor((s%3600)/60) + 'm';
  };
  const esc = s => String(s==null?'':s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
  const absorbCsrf = r => { if (r && r._csrf_token) CSRF_HASH = r._csrf_token; };
  const getJSON = async url => { const r = await fetch(url, { credentials: 'same-origin' }); const j = await r.json(); absorbCsrf(j); return j; };
  const postJSON = async (url, body) => {
    const fd = new FormData(); fd.append(CSRF_NAME, CSRF_HASH);
    for (const k in body) fd.append(k, body[k]);
    const r = await fetch(url, { method: 'POST', credentials: 'same-origin', body: fd });
    const j = await r.json(); absorbCsrf(j); return j;
  };

  function statusPill(s){ return `<span class="jd-pill ${esc(s||'unknown')}">${esc(s||'?')}</span>`; }

  async function loadJobs(){
    const status = document.getElementById('jd-filter-status').value;
    const limit  = document.getElementById('jd-filter-limit').value;
    const q = new URLSearchParams({ limit, ...(status ? {status} : {}) });
    try {
      const j = await getJSON(`${BASE}fees/jobs_list?${q}`);
      const tb = document.querySelector('#jd-jobs tbody');
      const data = (j && j.data) ? (j.data.jobs || []) : [];
      if (!data.length) { tb.innerHTML = `<tr><td colspan="8" class="jd-detail-empty">No jobs.</td></tr>`; return; }
      tb.innerHTML = data.map(job => `
        <tr data-jobid="${esc(job.jobId)}" class="${job.jobId===selectedJobId?'selected':''}">
          <td><code>${esc(job.jobId)}</code></td>
          <td>${statusPill(job.status)}</td>
          <td>
            <div class="progress"><i style="width:${Math.min(100, job.progressPercent||0)}%"></i></div>
            <div class="progress-txt">${job.progressPercent||0}% (${job.processedStudents||0}/${job.totalStudents||0})</div>
          </td>
          <td class="num">${job.totalStudents||0}</td>
          <td class="num">${job.demandsWritten||0}</td>
          <td class="num" style="${(job.failedBatches||0)>0?'color:var(--fm-red);font-weight:700':''}">${job.failedBatches||0}</td>
          <td>${esc((job.requestedAt||'').replace('T',' ').slice(0,16))}<div class="small">by ${esc(job.requestedBy||'—')}</div></td>
          <td>${fmtDur(job.durationSec)}</td>
        </tr>
      `).join('');
      tb.querySelectorAll('tr[data-jobid]').forEach(tr => {
        tr.onclick = () => { selectedJobId = tr.getAttribute('data-jobid'); loadJobs(); loadDetail(); };
      });
    } catch(e){ console.error('loadJobs', e); }
  }

  async function loadDetail(){
    const box = document.getElementById('jd-detail');
    if (!selectedJobId) { box.innerHTML = '<div class="jd-detail-empty">Pick a job above.</div>'; return; }
    try {
      const j = await getJSON(`${BASE}fees/job_detail?jobId=${encodeURIComponent(selectedJobId)}`);
      if (!j || !j.data) { box.innerHTML = '<div class="jd-detail-empty">Not found.</div>'; return; }
      const d = j.data;
      box.innerHTML = renderDetail(d);
      wireActions();
    } catch(e){ console.error('loadDetail', e); }
  }

  function renderDetail(d){
    const job = d.job || {};
    const workers = d.workers || [];
    const failed  = d.failedBatches || [];
    const retries = d.retryLogs || [];
    const isRunning   = job.status === 'running';
    const isPaused    = job.status === 'paused';
    const hasFailures = (job.failedBatches || 0) > 0 || failed.length > 0;
    return `
      <dl class="kvs">
        <dt>Job ID</dt><dd><code>${esc(job.jobId)}</code></dd>
        <dt>Status</dt><dd>${statusPill(job.status)} ${job.finalizeReason ? `<span class="small">${esc(job.finalizeReason)}</span>` : ''}</dd>
        <dt>Scope</dt><dd>${esc(JSON.stringify(job.scope||{}))}</dd>
        <dt>Workers</dt><dd>${workers.length} of ${job.totalWorkers||0}</dd>
        <dt>Requested</dt><dd>${esc((job.requestedAt||'').replace('T',' ').slice(0,19))} by ${esc(job.requestedBy||'—')}</dd>
        <dt>Started</dt><dd>${esc((job.startedAt||'—').replace('T',' ').slice(0,19))}</dd>
        <dt>Completed</dt><dd>${esc((job.completedAt||'—').replace('T',' ').slice(0,19))}</dd>
      </dl>

      <div class="workers">
        ${workers.map(w => `
          <div class="worker ${esc(w.status==='dead'?'dead':w.status)}">
            <div class="wid">worker_${w.workerId} <span class="small">· ${esc(w.status)}</span></div>
            <div class="row"><span>Students</span><b>${w.processedCount}</b></div>
            <div class="row"><span>Demands</span><b>${w.demandsWritten}</b></div>
            <div class="row"><span>Batches</span><b>${w.batchesCommitted}</b></div>
            <div class="row"><span>Failed</span><b style="${w.failedBatches>0?'color:var(--fm-red)':''}">${w.failedBatches}</b></div>
            <div class="row"><span>Last HB</span><b>${w.heartbeatAgeSec}s ago</b></div>
            ${w.lastProcessedStudentId ? `<div class="row"><span>lastSid</span><b><code>${esc(w.lastProcessedStudentId)}</code></b></div>` : ''}
          </div>
        `).join('')}
      </div>

      <div class="jd-actions">
        <button class="jd-btn warn"  data-act="pause"    ${isRunning?'':'disabled'}>Pause</button>
        <button class="jd-btn green" data-act="resume"   ${isPaused?'':'disabled'}>Resume</button>
        <button class="jd-btn"       data-act="retry"    ${hasFailures?'':'disabled'}>Retry failed</button>
        <button class="jd-btn danger" data-act="finalize">Force finalize</button>
      </div>

      ${failed.length ? `
        <h2 style="border-top:1px solid var(--fm-border);">Failed batches (${failed.length})</h2>
        <table class="jd-tbl">
          <thead><tr><th>Batch</th><th>Worker</th><th class="num">Ops</th><th class="num">Attempts</th><th>Status</th><th>Last error</th></tr></thead>
          <tbody>
            ${failed.map(f => `
              <tr><td><code>${esc(f.batchId||'')}</code></td>
                  <td>${esc(f.workerId ?? '—')}</td>
                  <td class="num">${f.opsCount||0}</td>
                  <td class="num">${f.attempts||0}</td>
                  <td>${statusPill(f.status||'?')}</td>
                  <td class="small">${esc((f.lastError||'').slice(0,120))}</td></tr>
            `).join('')}
          </tbody>
        </table>` : ''}

      ${retries.length ? `
        <h2 style="border-top:1px solid var(--fm-border);">Retry history (${retries.length})</h2>
        <table class="jd-tbl">
          <thead><tr><th>At</th><th>Kind</th><th>Target</th><th>Status</th><th>By</th></tr></thead>
          <tbody>
            ${retries.map(r => `
              <tr><td>${esc((r.timestamp||'').replace('T',' ').slice(0,19))}</td>
                  <td>${esc(r.kind||'')}</td>
                  <td><code>${esc(r.target||'')}</code></td>
                  <td>${statusPill(r.status||'?')}</td>
                  <td>${esc(r.performedBy||'—')}</td></tr>
            `).join('')}
          </tbody>
        </table>` : ''}

      <div id="jd-cli-tail" style="display:none;"><h2 style="border-top:1px solid var(--fm-border);">CLI output</h2><div class="log-tail" id="jd-cli-out"></div></div>
    `;
  }

  function wireActions(){
    const box = document.getElementById('jd-detail');
    box.querySelectorAll('button[data-act]').forEach(btn => {
      btn.onclick = async () => {
        const act = btn.getAttribute('data-act');
        const confirmMsg = {pause:'Pause this job?', resume:'Resume this job?',
          retry:'Retry all failed batches now?', finalize:'Force-finalize this job? (will mark it completed or failed based on current state)'}[act];
        if (!confirm(confirmMsg)) return;
        btn.disabled = true; btn.textContent = '…';
        const url = {pause:'job_pause',resume:'job_resume',retry:'job_retry_failed',finalize:'job_finalize'}[act];
        try {
          const j = await postJSON(`${BASE}fees/${url}`, { jobId: selectedJobId });
          if (j && j.data && Array.isArray(j.data.output)) {
            const tailBox = document.getElementById('jd-cli-tail');
            const tailOut = document.getElementById('jd-cli-out');
            tailBox.style.display = ''; tailOut.textContent = j.data.output.join('\n');
          }
        } catch(e){ alert('Action failed: ' + e.message); }
        loadJobs(); loadDetail();
      };
    });
  }

  async function loadAlerts(){
    const severity = document.getElementById('jd-alert-sev').value;
    const status   = document.getElementById('jd-alert-status').value;
    const q = new URLSearchParams({ limit: 30, ...(severity?{severity}:{}), ...(status?{status}:{}) });
    try {
      const j = await getJSON(`${BASE}fees/alerts_feed?${q}`);
      const box = document.getElementById('jd-alerts');
      const data = (j && j.data) ? (j.data.alerts || []) : [];
      if (!data.length) { box.innerHTML = '<div class="jd-detail-empty">No alerts.</div>'; return; }
      box.innerHTML = data.map(a => `
        <div class="alert">
          <div><span class="sev ${esc(a.severity||'info')}">${esc((a.severity||'info').toUpperCase())}</span><span class="title">${esc(a.title||'')}</span></div>
          <div class="msg">${esc(a.message||'')}</div>
          <div class="foot">
            <span>${esc((a.createdAt||'').replace('T',' ').slice(0,19))}</span>
            <span>${esc(a.category||'')}</span>
            ${a.jobId ? `<span>job=${esc(a.jobId)}</span>` : ''}
            ${a.occurrences>1 ? `<span>×${a.occurrences}</span>` : ''}
            ${a.status==='open' ? `<a href="#" data-ack="${esc(a.alertId)}">ack</a>` : `<span>${esc(a.status||'')}</span>`}
          </div>
        </div>
      `).join('');
      box.querySelectorAll('a[data-ack]').forEach(a => a.onclick = async ev => {
        ev.preventDefault();
        await postJSON(`${BASE}fees/alert_ack`, { alertId: a.getAttribute('data-ack') });
        loadAlerts();
      });
    } catch(e){ console.error('loadAlerts', e); }
  }

  function tick(){ loadJobs(); if (selectedJobId) loadDetail(); loadAlerts(); }
  function setupPolling(){
    if (pollTimer) clearInterval(pollTimer); pollTimer = null;
    if (document.getElementById('jd-autorefresh').checked) {
      pollTimer = setInterval(tick, 5000);
    }
  }

  document.getElementById('jd-refresh').onclick = tick;
  document.getElementById('jd-filter-status').onchange = loadJobs;
  document.getElementById('jd-filter-limit').onchange  = loadJobs;
  document.getElementById('jd-alert-sev').onchange     = loadAlerts;
  document.getElementById('jd-alert-status').onchange  = loadAlerts;
  document.getElementById('jd-autorefresh').onchange   = setupPolling;

  tick(); setupPolling();
})();
</script>
