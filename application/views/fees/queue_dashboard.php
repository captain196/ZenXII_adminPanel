<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<style>
:root{--qd-bg:#f7f8fb;--qd-card:#fff;--qd-border:#e5e7eb;--qd-text:#1f2937;
  --qd-muted:#6b7280;--qd-teal:#0f766e;--qd-blue:#1d4ed8;--qd-amber:#b45309;
  --qd-red:#b91c1c;--qd-green:#047857;}
.qd-wrap{background:var(--qd-bg);padding:14px 18px;color:var(--qd-text);font:13px/1.45 system-ui,sans-serif;}
.qd-hd{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.qd-hd h1{font-size:20px;margin:0;font-weight:700;}
.qd-hd .muted{color:var(--qd-muted);font-size:12px;}
.qd-hd .spacer{flex:1;}
.qd-btn{padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font:inherit;}
.qd-btn:hover{background:#f3f4f6;}
.qd-btn.primary{background:var(--qd-blue);color:#fff;border-color:var(--qd-blue);}
.qd-btn.green{background:var(--qd-green);color:#fff;border-color:var(--qd-green);}
.qd-btn[disabled]{opacity:.55;cursor:progress;}
.qd-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;}
.qd-kpi{background:var(--qd-card);border:1px solid var(--qd-border);border-radius:10px;padding:14px 16px;}
.qd-kpi .lbl{font-size:11px;letter-spacing:.3px;text-transform:uppercase;color:var(--qd-muted);font-weight:600;}
.qd-kpi .val{font-size:24px;font-weight:700;margin-top:6px;}
.qd-kpi.queued .val{color:var(--qd-amber);}
.qd-kpi.processing .val{color:var(--qd-blue);}
.qd-kpi.failed .val{color:var(--qd-red);}
.qd-kpi.age .val{color:var(--qd-teal);}
.qd-card{background:var(--qd-card);border:1px solid var(--qd-border);border-radius:10px;}
.qd-card h2{margin:0;padding:12px 14px;border-bottom:1px solid var(--qd-border);font-size:14px;font-weight:700;color:#111;display:flex;align-items:center;gap:10px;}
.qd-card h2 .spacer{flex:1;}
.qd-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.3px;}
.qd-pill.queued    {background:#fef3c7;color:#92400e;}
.qd-pill.processing{background:#dbeafe;color:#1e40af;}
.qd-pill.failed    {background:#fee2e2;color:#991b1b;}
.qd-pill.done      {background:#d1fae5;color:#065f46;}
table.qd-tbl{width:100%;border-collapse:collapse;font-size:12px;}
table.qd-tbl th{background:#f3f4f6;text-align:left;padding:8px 10px;font-weight:600;color:#374151;border-bottom:1px solid var(--qd-border);}
table.qd-tbl td{padding:8px 10px;border-bottom:1px solid #f1f3f5;vertical-align:top;}
.qd-empty{padding:24px;text-align:center;color:var(--qd-muted);}
.qd-err{font-size:11px;color:var(--qd-red);background:#fef2f2;padding:6px 8px;border-radius:6px;display:block;margin-top:4px;word-break:break-word;max-width:520px;}
.qd-sub{font-size:11px;color:var(--qd-muted);}
.qd-stale{color:var(--qd-red);font-weight:700;}
.qd-health-row{display:flex;gap:6px;align-items:center;font-size:11px;color:var(--qd-muted);}
.qd-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#9ca3af;}
.qd-dot.green{background:var(--qd-green);}
.qd-dot.amber{background:var(--qd-amber);}
.qd-dot.red  {background:var(--qd-red);}
.qd-alerts{margin-bottom:12px;}
.qd-alert{display:flex;gap:10px;align-items:flex-start;border-radius:8px;padding:10px 14px;margin-bottom:6px;border:1px solid transparent;}
.qd-alert.critical{background:#7f1d1d;color:#fff;border-color:#991b1b;}
.qd-alert.error{background:#fee2e2;color:#991b1b;border-color:#fecaca;}
.qd-alert.warning{background:#fef3c7;color:#7a5d00;border-color:#fde68a;}
.qd-alert i{flex:none;font-size:16px;margin-top:1px;}
.qd-alert .txt{flex:1;}
.qd-ops{display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px;border-top:1px solid var(--qd-border);background:#fafafa;border-radius:0 0 10px 10px;}
.qd-op-section{margin-bottom:14px;}
.qd-worker-badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;}
.qd-worker-badge.up  {background:#d1fae5;color:#065f46;}
.qd-worker-badge.warn{background:#fef3c7;color:#92400e;}
.qd-worker-badge.down{background:#fee2e2;color:#991b1b;}
.qd-kpi.metrics .val{color:#6d28d9;font-size:18px;}
.qd-kpi.metrics .sub-metric{font-size:11px;color:var(--qd-muted);margin-top:2px;}
</style>

<div class="qd-wrap">
  <div class="qd-hd">
    <h1>Fee Queue Dashboard</h1>
    <span class="muted" id="qdUpdated">— loading —</span>
    <span class="muted" style="padding-left:8px">· school: <code id="qdSchool">—</code></span>
    <div class="spacer"></div>
    <span class="qd-worker-badge" id="qdWorkerBadge" title="Worker heartbeat age">—</span>
    <span class="qd-health-row"><span id="qdDot" class="qd-dot"></span><span id="qdHealthLabel">checking…</span></span>
    <button class="qd-btn primary" id="qdRefreshBtn"><i class="fa fa-refresh"></i> Refresh</button>
  </div>

  <!-- Phase 7F — auto alerts banner. One <div> per active alert. -->
  <div class="qd-alerts" id="qdAlerts" style="display:none"></div>

  <div class="qd-kpis">
    <div class="qd-kpi queued"><div class="lbl">Queued</div><div class="val" id="kQueued">—</div></div>
    <div class="qd-kpi processing"><div class="lbl">Processing</div><div class="val" id="kProcessing">—</div><div class="qd-sub" id="kStuck"></div></div>
    <div class="qd-kpi failed"><div class="lbl">Failed</div><div class="val" id="kFailed">—</div></div>
    <div class="qd-kpi age"><div class="lbl">Oldest queued</div><div class="val" id="kOldest">—</div><div class="qd-sub" id="kOldestId"></div></div>
    <div class="qd-kpi metrics">
      <div class="lbl">Performance (last 50)</div>
      <div class="val" id="kAvgMs">—</div>
      <div class="sub-metric" id="kSuccess">—</div>
    </div>
  </div>

  <!-- Phase 7F — operator action panel. Bulk operations with confirms. -->
  <div class="qd-card qd-op-section">
    <h2>Operator actions <span class="spacer"></span><span class="qd-sub">Destructive — captured in feeAuditLogs</span></h2>
    <div class="qd-ops">
      <button class="qd-btn" id="opRetryAll"><i class="fa fa-refresh"></i> Retry all failed</button>
      <button class="qd-btn" id="opReapStuck"><i class="fa fa-bolt"></i> Force re-run stuck</button>
      <button class="qd-btn" id="opClearLocks"><i class="fa fa-unlock"></i> Clear stale locks</button>
      <span class="qd-sub" id="opStatus" style="margin-left:auto"></span>
    </div>
  </div>

  <div class="qd-card">
    <h2>Failed jobs <span class="spacer"></span><span class="qd-sub" id="qdFailedHint">Showing newest 50</span></h2>
    <table class="qd-tbl">
      <thead>
        <tr>
          <th style="width:140px">Job ID</th>
          <th style="width:90px">Receipt</th>
          <th style="width:120px">Student</th>
          <th style="width:70px">Attempts</th>
          <th style="width:170px">Last error at</th>
          <th>Error</th>
          <th style="width:110px;text-align:right">Action</th>
        </tr>
      </thead>
      <tbody id="qdFailedTbody">
        <tr><td colspan="7" class="qd-empty">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
    const SITE_URL = <?= json_encode(site_url(), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_NAME  = <?= json_encode($this->security->get_csrf_token_name()) ?>;
    const CSRF_HASH  = <?= json_encode($this->security->get_csrf_hash()) ?>;

    const $ = (id) => document.getElementById(id);
    const autoRefreshMs = 10000;  // 10 s dashboard polling

    function formatAge(sec) {
        if (!sec && sec !== 0) return '—';
        if (sec < 60)  return sec + ' s';
        if (sec < 3600) return Math.round(sec/60) + ' min';
        return Math.round(sec/3600) + ' h';
    }
    function fmtTs(iso) {
        if (!iso) return '—';
        try { return new Date(iso).toLocaleString(); } catch(_) { return iso; }
    }
    function esc(s) { return String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function updateHealth() {
        fetch(SITE_URL + '/fees/queue_status', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(resp => {
                // json_success wraps body under `data` in this codebase.
                const d = (resp && resp.data) ? resp.data : resp;
                $('kQueued').textContent     = d.queued ?? '—';
                $('kProcessing').textContent = d.processing ?? '—';
                $('kFailed').textContent     = d.failed ?? '—';
                $('kOldest').textContent     = formatAge(d.oldest_job_seconds || 0);
                $('kOldestId').textContent   = d.oldest_job_id ? ('job ' + d.oldest_job_id) : '';
                $('kStuck').textContent      = d.stuck_processing > 0
                    ? d.stuck_processing + ' stuck > 5 min'
                    : '';
                if (d.stuck_processing > 0) $('kStuck').className = 'qd-sub qd-stale';
                $('qdUpdated').textContent = 'Updated ' + new Date().toLocaleTimeString();

                // Phase 7F — metrics tile.
                const m = d.metrics || {};
                const avgMs = m.avg_processing_ms || 0;
                $('kAvgMs').textContent = avgMs > 0 ? (avgMs / 1000).toFixed(1) + ' s avg' : '—';
                const sample = m.sample_size || 0;
                const rate = (m.success_rate_pct !== undefined) ? m.success_rate_pct : null;
                $('kSuccess').textContent = sample > 0 && rate !== null
                    ? rate.toFixed(1) + '% success · ' + sample + ' recent jobs'
                    : 'no recent completions';

                // Phase 7F — worker heartbeat badge.
                const w = d.worker || {};
                const badge = $('qdWorkerBadge');
                const age = (w.age_seconds !== undefined && w.age_seconds >= 0) ? w.age_seconds : -1;
                if (w.down) {
                    badge.className = 'qd-worker-badge down';
                    badge.innerHTML = '<i class="fa fa-exclamation-triangle"></i> worker DOWN' + (age >= 0 ? ' (' + formatAge(age) + ' silent)' : '');
                } else if (age > 60) {
                    badge.className = 'qd-worker-badge warn';
                    badge.innerHTML = '<i class="fa fa-clock-o"></i> worker · last run ' + formatAge(age) + ' ago';
                } else {
                    badge.className = 'qd-worker-badge up';
                    badge.innerHTML = '<i class="fa fa-check-circle"></i> worker OK' + (age >= 0 ? ' (' + formatAge(age) + ' ago)' : '');
                }

                // Phase 7F — alerts banner (server-derived messages).
                const alerts = Array.isArray(d.alerts) ? d.alerts : [];
                const wrap = $('qdAlerts');
                if (alerts.length === 0) {
                    wrap.style.display = 'none';
                    wrap.innerHTML = '';
                } else {
                    wrap.innerHTML = alerts.map(a => {
                        const sev = (a && a.severity) || 'warning';
                        const msg = (a && a.message) || '';
                        const icon = sev === 'critical' ? 'fa-exclamation-circle'
                                  : sev === 'error'    ? 'fa-times-circle'
                                  :                      'fa-exclamation-triangle';
                        return '<div class="qd-alert ' + sev + '"><i class="fa ' + icon + '"></i><div class="txt">' + esc(msg) + '</div></div>';
                    }).join('');
                    wrap.style.display = '';
                }

                // Traffic light.
                const dot = $('qdDot'); const lbl = $('qdHealthLabel');
                if (w.down || d.failed > 0 || d.stuck_processing > 0) {
                    dot.className = 'qd-dot red';   lbl.textContent = 'attention needed';
                } else if (d.oldest_job_seconds > 120 || d.queued > 20) {
                    dot.className = 'qd-dot amber'; lbl.textContent = 'backlog forming';
                } else {
                    dot.className = 'qd-dot green'; lbl.textContent = 'healthy';
                }
            })
            .catch(e => {
                $('qdUpdated').textContent = 'error: ' + (e && e.message || 'fetch failed');
                $('qdDot').className = 'qd-dot red';
                $('qdHealthLabel').textContent = 'unreachable';
            });
    }

    // ── Phase 7F — operator bulk actions ────────────────────────────────
    function opCall(path, confirmText, noopText) {
        return function () {
            if (!confirm(confirmText)) return;
            const btns = document.querySelectorAll('#opRetryAll,#opReapStuck,#opClearLocks');
            btns.forEach(b => b.disabled = true);
            $('opStatus').textContent = 'Running…';
            const fd = new FormData();
            fd.append(CSRF_NAME, CSRF_HASH);
            fetch(SITE_URL + path, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(r => r.json())
                .then(resp => {
                    btns.forEach(b => b.disabled = false);
                    const ok = resp && resp.status === 'success';
                    const d  = (resp && resp.data) ? resp.data : {};
                    if (ok) {
                        const msg = d.message || noopText;
                        $('opStatus').textContent = msg;
                        updateHealth(); loadFailed();
                    } else {
                        $('opStatus').textContent = 'Error: ' + ((resp && resp.message) || 'unknown');
                    }
                })
                .catch(e => {
                    btns.forEach(b => b.disabled = false);
                    $('opStatus').textContent = 'Network error: ' + (e && e.message || '');
                });
        };
    }
    $('opRetryAll').addEventListener('click',
        opCall('/fees/queue_retry_all_failed',
            'Re-queue ALL failed jobs for this school? Each job will be processed by the next worker cycle.',
            'No action.'));
    $('opReapStuck').addEventListener('click',
        opCall('/fees/queue_reap_stuck',
            'Force-reset all \u201Cprocessing\u201D jobs older than 5 min back to queued?',
            'No action.'));
    $('opClearLocks').addEventListener('click',
        opCall('/fees/queue_clear_stale_locks',
            'Delete all feeLocks older than 120 s for this school? Use only if a submit is reporting \u201Cpayment still processing\u201D stuck.',
            'No action.'));

    function renderFailedRow(j) {
        const errHtml = j.lastError ? '<span class="qd-err">' + esc(j.lastError) + '</span>' : '—';
        return ''
          + '<tr data-job-id="' + esc(j.jobId) + '">'
          + '<td><code>' + esc(j.jobId) + '</code></td>'
          + '<td>F' + esc(j.receiptNo || '') + '</td>'
          + '<td>' + esc(j.studentId || '') + '</td>'
          + '<td>' + (j.attempts || 0) + '</td>'
          + '<td>' + fmtTs(j.lastErrorAt) + '</td>'
          + '<td>' + errHtml + '</td>'
          + '<td style="text-align:right"><button class="qd-btn green qd-retry-btn" data-job-id="' + esc(j.jobId) + '"><i class="fa fa-refresh"></i> Retry</button></td>'
          + '</tr>';
    }

    function loadFailed() {
        fetch(SITE_URL + '/fees/queue_failed_jobs', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(resp => {
                const d = (resp && resp.data) ? resp.data : resp;
                const jobs = (d && d.jobs) ? d.jobs : [];
                const tb = $('qdFailedTbody');
                if (!jobs.length) {
                    tb.innerHTML = '<tr><td colspan="7" class="qd-empty"><i class="fa fa-check-circle" style="color:#10b981"></i> No failed jobs — all clean.</td></tr>';
                    return;
                }
                tb.innerHTML = jobs.map(renderFailedRow).join('');
                tb.querySelectorAll('.qd-retry-btn').forEach(btn => {
                    btn.addEventListener('click', onRetryClick);
                });
            })
            .catch(e => {
                $('qdFailedTbody').innerHTML = '<tr><td colspan="7" class="qd-empty qd-stale">Failed to load: ' + esc(e.message || 'unknown') + '</td></tr>';
            });
    }

    function onRetryClick(ev) {
        const btn = ev.currentTarget;
        const jobId = btn.getAttribute('data-job-id');
        if (!jobId) return;
        if (!confirm('Re-queue job ' + jobId + ' for another worker pass?')) return;
        btn.disabled = true;
        btn.textContent = 'Retrying…';
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('jobId', jobId);
        fetch(SITE_URL + '/fees/queue_job_retry', {
            method: 'POST', credentials: 'same-origin', body: fd,
        })
        .then(r => r.json())
        .then(resp => {
            const ok = resp && (resp.status === 'success' || (resp.data && resp.data.status === 'queued'));
            if (ok) {
                // Drop the row optimistically; next poll will reconcile.
                const tr = btn.closest('tr'); if (tr) tr.remove();
                // Refresh counters right away so the KPIs drop.
                updateHealth();
                if (!$('qdFailedTbody').children.length) loadFailed();
            } else {
                btn.disabled = false;
                btn.textContent = 'Retry';
                alert('Retry failed: ' + (resp && resp.message ? resp.message : 'unknown error'));
            }
        })
        .catch(e => {
            btn.disabled = false;
            btn.textContent = 'Retry';
            alert('Retry network error: ' + (e && e.message || 'unknown'));
        });
    }

    $('qdRefreshBtn').addEventListener('click', () => { updateHealth(); loadFailed(); });

    updateHealth();
    loadFailed();
    setInterval(updateHealth, autoRefreshMs);
    setInterval(loadFailed, autoRefreshMs * 3);  // failed list refreshes slower
})();
</script>
