<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<style>
:root{--ah-bg:#f7f8fb;--ah-card:#fff;--ah-border:#e5e7eb;--ah-text:#1f2937;
  --ah-muted:#6b7280;--ah-teal:#0f766e;--ah-blue:#1d4ed8;--ah-amber:#b45309;
  --ah-red:#b91c1c;--ah-green:#047857;}
.ah-wrap{background:var(--ah-bg);padding:14px 18px;color:var(--ah-text);font:13px/1.45 system-ui,sans-serif;}
.ah-hd{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.ah-hd h1{font-size:20px;margin:0;font-weight:700;}
.ah-hd .muted{color:var(--ah-muted);font-size:12px;}
.ah-hd .spacer{flex:1;}
.ah-btn{padding:6px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;font:inherit;}
.ah-btn:hover{background:#f3f4f6;}
.ah-btn.primary{background:var(--ah-blue);color:#fff;border-color:var(--ah-blue);}
.ah-btn.warn{background:var(--ah-amber);color:#fff;border-color:var(--ah-amber);}
.ah-dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#9ca3af;}
.ah-dot.green{background:var(--ah-green);}
.ah-dot.amber{background:var(--ah-amber);}
.ah-dot.red  {background:var(--ah-red);}
.ah-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:14px;}
.ah-kpi{background:var(--ah-card);border:1px solid var(--ah-border);border-radius:10px;padding:14px 16px;}
.ah-kpi .lbl{font-size:11px;text-transform:uppercase;color:var(--ah-muted);font-weight:600;letter-spacing:.3px;}
.ah-kpi .val{font-size:22px;font-weight:700;margin-top:6px;}
.ah-kpi.bad  .val{color:var(--ah-red);}
.ah-kpi.warn .val{color:var(--ah-amber);}
.ah-kpi.ok   .val{color:var(--ah-green);}
.ah-kpi .sub{font-size:11px;color:var(--ah-muted);margin-top:2px;}
.ah-alerts{margin-bottom:12px;}
.ah-alert{display:flex;gap:10px;align-items:flex-start;border-radius:8px;padding:10px 14px;margin-bottom:6px;border:1px solid transparent;}
.ah-alert.critical{background:#7f1d1d;color:#fff;border-color:#991b1b;}
.ah-alert.error{background:#fee2e2;color:#991b1b;border-color:#fecaca;}
.ah-alert.warning{background:#fef3c7;color:#7a5d00;border-color:#fde68a;}
.ah-alert .txt{flex:1;}
.ah-alert .ts{font-size:11px;opacity:.8;}
.ah-alert .ack{margin-left:auto;}
.ah-card{background:var(--ah-card);border:1px solid var(--ah-border);border-radius:10px;}
.ah-card h2{margin:0;padding:12px 14px;border-bottom:1px solid var(--ah-border);font-size:14px;font-weight:700;}
table.ah-tbl{width:100%;border-collapse:collapse;font-size:12px;}
table.ah-tbl th{background:#f3f4f6;text-align:left;padding:8px 10px;font-weight:600;color:#374151;border-bottom:1px solid var(--ah-border);}
table.ah-tbl td{padding:8px 10px;border-bottom:1px solid #f1f3f5;vertical-align:top;}
.ah-empty{padding:22px;text-align:center;color:var(--ah-muted);}
.ah-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;}
.ah-badge.up  {background:#d1fae5;color:#065f46;}
.ah-badge.down{background:#fee2e2;color:#991b1b;}
</style>

<div class="ah-wrap">
  <div class="ah-hd">
    <h1>Accounting Health</h1>
    <span class="muted" id="ahSchool">—</span>
    <span class="muted" id="ahUpdated">loading…</span>
    <div class="spacer"></div>
    <span><span id="ahDot" class="ah-dot"></span>&nbsp;<span id="ahStatusTxt">checking…</span></span>
    <button class="ah-btn primary" id="ahRefreshBtn"><i class="fa fa-refresh"></i> Refresh</button>
  </div>

  <div class="ah-alerts" id="ahAlerts" style="display:none"></div>

  <div class="ah-kpis">
    <div class="ah-kpi" id="kWorker"><div class="lbl">FeeWorker</div><div class="val">—</div><div class="sub"></div></div>
    <div class="ah-kpi" id="kRecon"><div class="lbl">Reconciler</div><div class="val">—</div><div class="sub"></div></div>
    <div class="ah-kpi" id="kIdemp"><div class="lbl">Idempotency</div><div class="val">—</div><div class="sub"></div></div>
    <div class="ah-kpi" id="kPeriod"><div class="lbl">Period Lock</div><div class="val">—</div><div class="sub"></div></div>
    <div class="ah-kpi ok" id="kJournals"><div class="lbl">Journals 24h</div><div class="val">—</div><div class="sub"></div></div>
  </div>

  <div class="ah-card">
    <h2>Open alerts (persisted by Watchdog)</h2>
    <table class="ah-tbl">
      <thead><tr>
        <th style="width:100px">Severity</th>
        <th style="width:160px">Rule</th>
        <th>Message</th>
        <th style="width:150px">Created</th>
        <th style="width:120px;text-align:right">Action</th>
      </tr></thead>
      <tbody id="ahAlertsTbody">
        <tr><td colspan="5" class="ah-empty">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
(function () {
    const SITE_URL = <?= json_encode(site_url(), JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_NAME = <?= json_encode($this->security->get_csrf_token_name()) ?>;
    const CSRF_HASH = <?= json_encode($this->security->get_csrf_hash()) ?>;
    const $ = (id) => document.getElementById(id);
    const poll_ms = 10000;
    const esc = (s) => String(s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const fmtAge = (s) => {
        if (s === null || s === undefined) return '—';
        if (s < 0)   return 'never';
        if (s < 60)  return s + ' s';
        if (s < 3600) return Math.round(s/60) + ' min';
        return Math.round(s/3600) + ' h';
    };

    function setKpi(id, val, sub, cls) {
        const tile = $(id);
        if (!tile) return;
        tile.className = 'ah-kpi ' + (cls || '');
        tile.querySelector('.val').textContent = val;
        tile.querySelector('.sub').textContent = sub || '';
    }
    function renderAlerts(alerts) {
        const wrap = $('ahAlerts');
        if (!alerts || !alerts.length) { wrap.style.display = 'none'; wrap.innerHTML = ''; return; }
        wrap.innerHTML = alerts.map(a => {
            const sev = a.severity || 'warning';
            const icon = sev === 'critical' ? 'fa-exclamation-circle'
                      : sev === 'error'    ? 'fa-times-circle'
                      :                      'fa-exclamation-triangle';
            return '<div class="ah-alert ' + sev + '"><i class="fa ' + icon + '"></i><div class="txt">' + esc(a.message) + '</div></div>';
        }).join('');
        wrap.style.display = '';
    }
    function renderOpenAlerts(latest) {
        const tb = $('ahAlertsTbody');
        if (!latest || !latest.length) {
            tb.innerHTML = '<tr><td colspan="5" class="ah-empty"><i class="fa fa-check-circle" style="color:#10b981"></i> No open alerts.</td></tr>';
            return;
        }
        tb.innerHTML = latest.map(a => ''
          + '<tr data-alert-id="' + esc(a.alertId) + '">'
          +   '<td><span class="ah-badge ' + (a.severity === 'critical' || a.severity === 'error' ? 'down' : 'up') + '">' + esc(a.severity) + '</span></td>'
          +   '<td><code>' + esc(a.rule) + '</code></td>'
          +   '<td>' + esc(a.message) + '</td>'
          +   '<td>' + esc(a.createdAt) + '</td>'
          +   '<td style="text-align:right"><button class="ah-btn warn ah-ack-btn" data-alert-id="' + esc(a.alertId) + '">Acknowledge</button></td>'
          + '</tr>'
        ).join('');
        tb.querySelectorAll('.ah-ack-btn').forEach(b => b.addEventListener('click', onAck));
    }
    function onAck(ev) {
        const btn = ev.currentTarget;
        const alertId = btn.getAttribute('data-alert-id');
        if (!confirm('Acknowledge alert ' + alertId + '?')) return;
        btn.disabled = true; btn.textContent = 'Acking…';
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('alertId', alertId);
        fetch(SITE_URL + '/accounting/acknowledge_alert', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(r => r.json())
            .then(resp => {
                if (resp && resp.status === 'success') {
                    const tr = btn.closest('tr'); if (tr) tr.remove();
                    refresh();
                } else {
                    btn.disabled = false; btn.textContent = 'Acknowledge';
                    alert('Ack failed: ' + (resp && resp.message || 'unknown'));
                }
            })
            .catch(e => { btn.disabled = false; btn.textContent = 'Acknowledge'; alert('Network error: ' + (e && e.message)); });
    }

    function refresh() {
        fetch(SITE_URL + '/accounting/health_json', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(resp => {
                const d = resp && resp.data ? resp.data : {};
                $('ahSchool').textContent  = 'school=' + (d.schoolId || '—') + ' / session=' + (d.session || '—');
                $('ahUpdated').textContent = 'updated ' + new Date().toLocaleTimeString();

                const status = (d.status || 'green');
                $('ahDot').className = 'ah-dot ' + (status === 'red' ? 'red' : status === 'amber' ? 'amber' : 'green');
                $('ahStatusTxt').textContent = status === 'red' ? 'needs attention'
                                              : status === 'amber' ? 'warnings present'
                                              : 'healthy';

                const w = d.worker || {};
                setKpi('kWorker',
                    w.down ? 'DOWN' : 'UP',
                    'last run ' + fmtAge(w.age_seconds),
                    w.down ? 'bad' : 'ok');
                const r = d.reconciler || {};
                setKpi('kRecon',
                    r.down ? 'DOWN' : 'UP',
                    'last run ' + fmtAge(r.age_seconds),
                    r.down ? 'bad' : 'ok');
                const idemp = d.idempotency || {};
                setKpi('kIdemp',
                    (idemp.processing_count || 0) + ' / ' + (idemp.failed_count || 0),
                    'processing / failed ' + (idemp.stuck_count ? ' · ' + idemp.stuck_count + ' stuck' : ''),
                    idemp.failed_count > 0 ? 'bad' : idemp.stuck_count > 0 ? 'warn' : 'ok');
                const pl = d.period_lock || {};
                setKpi('kPeriod',
                    pl.open ? 'OPEN' : 'LOCKED',
                    pl.locked_until ? 'until ' + pl.locked_until : '—',
                    pl.open ? 'ok' : 'warn');
                const m = d.metrics_24h || {};
                setKpi('kJournals',
                    (m.journals_24h === -1 ? 'n/a' : m.journals_24h) + (m.journals_truncated ? '+' : ''),
                    'posted in 24h',
                    'ok');

                renderAlerts(d.alerts || []);
                renderOpenAlerts((d.open_alerts && d.open_alerts.latest) || []);
            })
            .catch(e => {
                $('ahUpdated').textContent = 'error — ' + (e && e.message || 'fetch failed');
                $('ahDot').className = 'ah-dot red';
                $('ahStatusTxt').textContent = 'unreachable';
            });
    }

    $('ahRefreshBtn').addEventListener('click', refresh);
    refresh();
    setInterval(() => { if (!document.hidden) refresh(); }, poll_ms);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
</script>
