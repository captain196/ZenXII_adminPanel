<?php
/**
 * B2.3.4-A Phase 1G — Per-Tenant Deep Dive view.
 *
 * 6 stacked sections: Identity Card, KPI Tiles, Time-Series (dual chart),
 * Subscription + Billing, Activity Timeline, Stats Health + Alerts.
 *
 * Variables in scope: $payload, $schoolId, $daysWindow, $monthsTrend
 */
$identity = $payload['identity']     ?? [];
$kpis     = $payload['kpis']         ?? [];
$series   = $payload['time_series']  ?? [];
$sub      = $payload['subscription'] ?? [];
$pay      = $payload['payments']     ?? ['rows' => [], 'lifetime_total' => 0, 'total_count' => 0];
$payRows  = is_array($pay['rows'] ?? null) ? $pay['rows'] : [];
$payTotal = (float) ($pay['lifetime_total'] ?? 0);
$payCount = (int)   ($pay['total_count']    ?? 0);
$act      = $payload['activity']     ?? [];
$health   = $payload['stats_health'] ?? [];
$alerts   = $payload['alerts']       ?? [];

$isTest      = !empty($identity['isTestTenant']);
$lifecycle   = strtolower((string) ($identity['lifecycleState'] ?? ''));
$adminDisabled = !empty($identity['adminDisabled']);

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
$money = function ($v) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return '₹' . number_format((float) $v, 2);
};
$ageLabel = function ($h) {
    if ($h === null) return '—';
    if ($h < 24) return $h . 'h';
    return floor($h / 24) . 'd';
};
// 2026-06-02 IST conversion. Pre-fix labeled server-local time as "UTC"
// which was doubly wrong: the offset is actually +02:00 (not UTC), and
// the operator is in IST (Asia/Kolkata, +05:30). Use the ISO 8601 string's
// offset to anchor, then convert into IST for display.
$tzIst = function ($iso, $fmt = 'H:i:s') {
    if (!is_string($iso) || $iso === '') return '—';
    try {
        $dt = new \DateTime($iso);
        $dt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        return $dt->format($fmt);
    } catch (\Throwable $e) {
        return substr($iso, 11, 8); // best-effort fallback
    }
};
?>
<style>
.b2td-shell{padding:18px 24px;}
.b2td-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:10px 14px;flex-wrap:wrap;}
.b2td-toolbar .grp{display:flex;align-items:center;gap:6px;}
.b2td-toolbar .lbl{font-size:12px;color:#475569;}
.b2td-toolbar .spacer{flex:1;}
.b2td-toolbar .toggle button{border:1px solid #cbd5e1;background:#fff;padding:5px 10px;font-size:12px;cursor:pointer;color:#475569;}
.b2td-toolbar .toggle button.active{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2td-toolbar .toggle button:first-child{border-radius:5px 0 0 5px;}
.b2td-toolbar .toggle button:last-child{border-radius:0 5px 5px 0;border-left:none;}
.b2td-toolbar .toggle button:not(:first-child):not(:last-child){border-left:none;}
.b2td-sec{background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:16px;margin-bottom:14px;}
.b2td-sec h3{margin:0 0 12px 0;font-size:14px;color:#0f172a;font-weight:600;display:flex;align-items:center;gap:8px;}
.b2td-sec h3 .exp{margin-left:auto;font-size:11px;font-weight:500;}
.b2td-sec h3 .exp a{color:#475569;text-decoration:none;padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;}
.b2td-sec h3 .exp a:hover{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2td-id{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;}
@media (max-width:900px){.b2td-id{grid-template-columns:minmax(0,1fr);}}
.b2td-id .left{display:flex;align-items:flex-start;gap:14px;}
.b2td-id .logo{width:60px;height:60px;border-radius:8px;background:#f8fafc;border:1px solid #e6e8eb;display:flex;align-items:center;justify-content:center;font-size:22px;color:#94a3b8;flex-shrink:0;}
.b2td-id .logo img{max-width:100%;max-height:100%;border-radius:6px;}
.b2td-id h2{margin:0;font-size:18px;color:#0f172a;font-weight:700;display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;}
.b2td-id .sub{font-size:12.5px;color:#64748b;margin-top:4px;}
.b2td-id .badges{margin-top:6px;display:flex;flex-wrap:wrap;gap:5px;}
.b2td-id .grid{display:grid;grid-template-columns:auto 1fr;gap:5px 14px;font-size:12.5px;}
.b2td-id .grid .k{color:#94a3b8;}
.b2td-id .grid .v{color:#0f172a;}
.b2td-id .actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;}
.b2td-id .actions a{font-size:11.5px;padding:4px 10px;border:1px solid #cbd5e1;border-radius:5px;color:#0f172a;text-decoration:none;background:#fafbfc;}
.b2td-id .actions a:hover{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2td-badge{display:inline-block;font-size:9px;font-weight:600;padding:2px 6px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:#fef3c7;color:#92400e;}
.b2td-badge.disabled{background:#fee2e2;color:#991b1b;}
.b2td-lc{display:inline-block;font-size:10px;font-weight:600;padding:2px 8px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.b2td-lc.active{background:#dcfce7;color:#166534;}
.b2td-lc.trialing{background:#dbeafe;color:#1e40af;}
.b2td-lc.expiring_soon,.b2td-lc.grace{background:#fef3c7;color:#92400e;}
.b2td-lc.past_due{background:#fee2e2;color:#991b1b;}
.b2td-lc.suspended,.b2td-lc.expired{background:#e5e7eb;color:#374151;}
.b2td-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;}
@media (max-width:1100px){.b2td-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}}
.b2td-kpi{background:#fafbfc;border:1px solid #e6e8eb;border-radius:8px;padding:14px 16px;}
.b2td-kpi .lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px;}
.b2td-kpi .v{font-size:22px;font-weight:600;color:#0f172a;line-height:1.1;}
.b2td-kpi .sub{font-size:11px;color:#64748b;margin-top:4px;}
.b2td-ts{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;}
@media (max-width:900px){.b2td-ts{grid-template-columns:minmax(0,1fr);}}
.b2td-chartwrap{height:220px;min-height:220px;position:relative;background:#fafbfc;border:1px solid #f1f5f9;border-radius:4px;}
.b2td-bill{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;}
@media (max-width:900px){.b2td-bill{grid-template-columns:minmax(0,1fr);}}
.b2td-tbl{width:100%;border-collapse:collapse;font-size:12.5px;}
.b2td-tbl th,.b2td-tbl td{padding:7px 10px;text-align:left;border-bottom:1px solid #f1f5f9;}
.b2td-tbl th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#475569;font-weight:600;}
.b2td-tbl td.r{text-align:right;}
.b2td-tbl tr:hover td{background:#f8fafc;}
.b2td-empty{text-align:center;padding:18px;color:#64748b;font-size:12.5px;font-style:italic;}
.b2td-kv{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:12.5px;}
.b2td-kv .k{color:#94a3b8;}
.b2td-kv .v{color:#0f172a;}
.b2td-alert{padding:7px 10px;border-radius:5px;background:#fef3c7;border:1px solid #fde68a;color:#92400e;font-size:12px;margin-bottom:5px;}
.b2td-alert.critical{background:#fee2e2;border-color:#fecaca;color:#991b1b;}
.b2td-alert.info{background:#dbeafe;border-color:#bfdbfe;color:#1e40af;}
.b2td-caption{font-size:11px;color:#94a3b8;font-style:italic;margin-top:6px;}
</style>

<section class="content-header" style="padding:18px 24px 0 24px;">
  <h1 style="margin:0;font-size:20px;color:#0f172a;">
    <i class="fa fa-building-o" style="color:#0f172a;margin-right:10px;"></i>Per-Tenant Deep Dive
  </h1>
  <ol class="breadcrumb" style="margin-top:6px;background:transparent;padding:0;">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li><a href="<?= base_url('superadmin/dashboard/schools-search') ?>">School Search</a></li>
    <li class="active"><?= $h($schoolId) ?></li>
  </ol>
</section>

<div class="b2td-shell">

  <!-- ── TOOLBAR ─────────────────────────────────────────────────────── -->
  <div class="b2td-toolbar">
    <span class="grp">
      <span class="lbl">Activity window</span>
      <span class="toggle" id="b2tdDays">
        <?php foreach ([7, 30, 90] as $d): ?>
          <button type="button" data-days="<?= $d ?>" class="<?= $daysWindow === $d ? 'active' : '' ?>"><?= $d ?>d</button>
        <?php endforeach; ?>
      </span>
    </span>
    <span class="grp">
      <span class="lbl">Time-series window</span>
      <span class="toggle" id="b2tdMonths">
        <?php foreach ([3, 6, 12] as $m): ?>
          <button type="button" data-months="<?= $m ?>" class="<?= $monthsTrend === $m ? 'active' : '' ?>"><?= $m ?>mo</button>
        <?php endforeach; ?>
      </span>
    </span>
    <span class="spacer"></span>
    <span class="lbl">Generated <?= $h($tzIst((string) ($payload['generated_at'] ?? date('c')))) ?> IST</span>
  </div>

  <!-- ── SECTION 1: IDENTITY CARD ───────────────────────────────────── -->
  <div class="b2td-sec">
    <h3>
      <i class="fa fa-id-card-o" style="color:#0f172a;"></i>Identity
      <span class="exp">
        <a href="#" data-export="identity" data-format="csv">CSV</a>
        <a href="#" data-export="identity" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2td-id">
      <div class="left">
        <div class="logo">
          <?php if (!empty($identity['logoUrl'])): ?>
            <img src="<?= $h($identity['logoUrl']) ?>" alt="">
          <?php else: ?>
            <i class="fa fa-graduation-cap"></i>
          <?php endif; ?>
        </div>
        <div>
          <h2>
            <?= $h($identity['schoolName'] ?? '—') ?>
            <?php if ($isTest): ?><span class="b2td-badge">non-prod</span><?php endif; ?>
            <?php if ($adminDisabled): ?><span class="b2td-badge disabled">disabled</span><?php endif; ?>
          </h2>
          <div class="sub">
            <?= $h($identity['schoolCode'] ?? '') ?>
            <?= !empty($identity['city']) ? ' · ' . $h($identity['city']) : '' ?>
            <?= !empty($identity['region']) ? ', ' . $h($identity['region']) : '' ?>
          </div>
          <div class="badges">
            <?php // When admin-disabled, the DISABLED badge in the H2 header is
                  // the dominant signal. Showing a green ACTIVE lifecycle badge
                  // alongside it produces a visually-contradictory double-render
                  // (Phase 1G defect surfaced via IIT Kanpur display 2026-06-02).
                  // Inline caption keeps the underlying lifecycle visible without
                  // the colored-badge contradiction.
            ?>
            <?php if ($adminDisabled): ?>
              <?php if ($lifecycle): ?>
                <span style="font-size:11px;color:#94a3b8;font-style:italic;">
                  underlying lifecycle: <?= $h(str_replace('_', ' ', $lifecycle)) ?>
                </span>
              <?php endif; ?>
            <?php elseif ($lifecycle): ?>
              <span class="b2td-lc <?= $h($lifecycle) ?>"><?= $h(str_replace('_', ' ', $lifecycle)) ?></span>
            <?php endif; ?>
          </div>
          <div class="actions">
            <a href="<?= base_url('superadmin/schools/view/' . urlencode($schoolId)) ?>"><i class="fa fa-info-circle"></i> School Detail</a>
            <a href="<?= base_url('superadmin/schools/view/' . urlencode($schoolId)) ?>#subscription"><i class="fa fa-credit-card"></i> Subscription</a>
          </div>
        </div>
      </div>
      <div class="grid">
        <span class="k">School ID</span>      <span class="v"><code><?= $h($identity['schoolId'] ?? $schoolId) ?></code></span>
        <span class="k">Plan</span>            <span class="v"><?= $h($identity['planName'] ?? '— No Plan') ?></span>
        <span class="k">Created</span>         <span class="v"><?= $h(substr((string) ($identity['createdAt'] ?? ''), 0, 10) ?: '—') ?></span>
        <span class="k">Primary SSA</span>     <span class="v"><?= $h($identity['primarySsaId'] ?? '—') ?></span>
        <span class="k">Domain</span>          <span class="v"><?= $h($identity['domainIdentifier'] ?? '—') ?></span>
        <?php if (!empty($identity['planFamilyId'])): ?>
        <span class="k">Plan Family ID</span>  <span class="v"><code><?= $h($identity['planFamilyId']) ?></code></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── SECTION 2: KPI TILES ───────────────────────────────────────── -->
  <div class="b2td-sec">
    <h3>
      <i class="fa fa-tachometer" style="color:#0f172a;"></i>Tenant KPIs
      <span class="exp">
        <a href="#" data-export="kpis" data-format="csv">CSV</a>
        <a href="#" data-export="kpis" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2td-kpis">
      <div class="b2td-kpi">
        <div class="lbl">Total Students</div>
        <div class="v"><?= number_format((int) ($kpis['students'] ?? 0)) ?></div>
        <div class="sub">statsCache</div>
      </div>
      <div class="b2td-kpi">
        <div class="lbl">Total Staff</div>
        <div class="v"><?= number_format((int) ($kpis['staff'] ?? 0)) ?></div>
        <div class="sub">statsCache</div>
      </div>
      <div class="b2td-kpi">
        <div class="lbl">MRR</div>
        <div class="v"><?= $money($kpis['mrr'] ?? 0) ?></div>
        <div class="sub">per-tenant allocation</div>
      </div>
      <div class="b2td-kpi">
        <div class="lbl">Activity (<?= $daysWindow ?>d)</div>
        <div class="v"><?= number_format((int) ($kpis['activity_count'] ?? 0)) ?></div>
        <div class="sub">tenantAudit events</div>
      </div>
      <div class="b2td-kpi">
        <div class="lbl">Data Age</div>
        <div class="v"><?= $ageLabel($kpis['data_age_hours'] ?? null) ?></div>
        <div class="sub">since statsCache update</div>
      </div>
    </div>
  </div>

  <!-- ── SECTION 3: TIME-SERIES ─────────────────────────────────────── -->
  <div class="b2td-sec">
    <h3>
      <i class="fa fa-line-chart" style="color:#0f172a;"></i>Trends — last <?= $monthsTrend ?> months
      <span class="exp">
        <a href="#" data-export="timeseries" data-format="csv">CSV</a>
        <a href="#" data-export="timeseries" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2td-ts">
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">Students &amp; Staff over time</div>
        <div class="b2td-chartwrap"><canvas id="b2tdGrowthChart"></canvas></div>
      </div>
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">Audit events per month</div>
        <div class="b2td-chartwrap"><canvas id="b2tdActivityChart"></canvas></div>
      </div>
    </div>
    <div class="b2td-caption">Tenant absent from a historical rollup = 0 baseline (genuine net-new).</div>
  </div>

  <!-- ── SECTION 4: SUBSCRIPTION + BILLING ──────────────────────────── -->
  <div class="b2td-sec">
    <h3>
      <i class="fa fa-credit-card" style="color:#0f172a;"></i>Subscription &amp; Billing
      <span class="exp">
        <a href="#" data-export="subscription" data-format="csv">Sub CSV</a>
        <a href="#" data-export="subscription" data-format="xlsx">Sub XLSX</a>
        <a href="#" data-export="payments"     data-format="csv">Pay CSV</a>
        <a href="#" data-export="payments"     data-format="xlsx">Pay XLSX</a>
      </span>
    </h3>
    <div class="b2td-bill">
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">Subscription details</div>
        <?php if (empty($sub['subscriptionId'])): ?>
          <div class="b2td-empty">No active subscription pointer on schoolControl.</div>
        <?php else: ?>
          <div class="b2td-kv">
            <span class="k">Status</span>           <span class="v"><?= $h($sub['status'] ?? '—') ?></span>
            <span class="k">Plan</span>              <span class="v"><?= $h($sub['planName'] ?? '—') ?></span>
            <span class="k">Price</span>             <span class="v"><?= $money($sub['price'] ?? 0) ?> / <?= $h($sub['billingCycle'] ?: '—') ?></span>
            <span class="k">Period start</span>      <span class="v"><?= $h(substr((string) ($sub['periodStart'] ?? ''), 0, 10) ?: '—') ?></span>
            <span class="k">Period end</span>        <span class="v"><?= $h(substr((string) ($sub['periodEnd']   ?? ''), 0, 10) ?: '—') ?></span>
            <?php if (!empty($sub['graceEnd'])): ?>
            <span class="k">Grace end</span>         <span class="v"><?= $h(substr((string) $sub['graceEnd'], 0, 10)) ?></span>
            <?php endif; ?>
            <span class="k">Subscription ID</span>   <span class="v"><code><?= $h($sub['subscriptionId']) ?></code></span>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;display:flex;justify-content:space-between;align-items:baseline;">
          <span>Recent payments (top 10)</span>
          <small style="color:#94a3b8;">Lifetime: <?= $money($payTotal) ?> · <?= $payCount ?> total</small>
        </div>
        <?php if (empty($payRows)): ?>
          <div class="b2td-empty">No payments recorded for this tenant.</div>
        <?php else: ?>
        <table class="b2td-tbl">
          <thead><tr><th>Date</th><th class="r">Amount</th><th>Method</th></tr></thead>
          <tbody>
            <?php foreach ($payRows as $r): ?>
              <tr>
                <td><?= $h(substr((string) ($r['paidAt'] ?? ''), 0, 10)) ?></td>
                <td class="r"><?= $money($r['amount'] ?? 0) ?></td>
                <td><?= $h($r['method'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── SECTION 5: ACTIVITY TIMELINE ───────────────────────────────── -->
  <div class="b2td-sec">
    <h3>
      <i class="fa fa-history" style="color:#0f172a;"></i>Activity Timeline (last <?= $daysWindow ?>d, top <?= count((array) $act) ?: 0 ?>)
      <span class="exp">
        <a href="#" data-export="activity" data-format="csv">CSV</a>
        <a href="#" data-export="activity" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <?php if (empty($act)): ?>
      <div class="b2td-empty">No audit events for this tenant in the window.</div>
    <?php else: ?>
    <table class="b2td-tbl">
      <thead><tr><th>Timestamp</th><th>Actor</th><th>Action</th><th>Target / Notes</th></tr></thead>
      <tbody>
        <?php foreach ($act as $r): ?>
          <tr>
            <td><code style="font-size:11px;"><?= $h($tzIst((string) ($r['ts'] ?? ''), 'Y-m-d H:i:s')) ?></code></td>
            <td><?= $h($r['actor']  ?? '—') ?></td>
            <td><?= $h($r['action'] ?? '—') ?></td>
            <td>
              <?php if (!empty($r['target'])): ?><code style="font-size:11px;"><?= $h($r['target']) ?></code><?php endif; ?>
              <?php if (!empty($r['notes'])): ?><span style="color:#64748b;font-size:11.5px;"><?= $h($r['notes']) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="b2td-caption">Capped at 50 most-recent rows. Full audit history available via tenantAudit collection.</div>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 6: STATS HEALTH + ALERTS ───────────────────────────── -->
  <div class="b2td-sec">
    <h3><i class="fa fa-heartbeat" style="color:#0f172a;"></i>Stats Health &amp; Alerts</h3>
    <div class="b2td-bill">
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">Stats freshness</div>
        <?php if (empty($health) || ($health['lastUpdated'] ?? '') === ''): ?>
          <div class="b2td-empty">No statsCache.lastUpdated timestamp on this tenant.</div>
        <?php else: ?>
          <div class="b2td-kv">
            <span class="k">Last updated</span>  <span class="v"><?= $h($tzIst((string) ($health['lastUpdated'] ?? ''), 'Y-m-d H:i:s')) ?> IST</span>
            <span class="k">Hours ago</span>      <span class="v"><?= $ageLabel($health['hoursAgo'] ?? null) ?> <?= !empty($health['isStale']) ? '<span class="b2td-badge disabled">stale</span>' : '' ?></span>
            <span class="k">Source</span>         <span class="v"><?= $h($health['source'] ?? '—') ?></span>
            <span class="k">Students</span>       <span class="v"><?= (int) ($health['students'] ?? 0) ?></span>
            <span class="k">Staff</span>          <span class="v"><?= (int) ($health['staff']    ?? 0) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">Open alerts</div>
        <?php if (empty($alerts)): ?>
          <div class="b2td-empty">No open alerts for this tenant.</div>
        <?php else: ?>
          <?php foreach ($alerts as $a):
              $sev = strtolower((string) ($a['severity'] ?? 'info')); ?>
            <div class="b2td-alert <?= $h($sev) ?>">
              <strong><?= $h(str_replace('_', ' ', (string) ($a['alertType'] ?? ''))) ?></strong>
              <span style="float:right;font-size:11px;"><?= $h(substr((string) ($a['createdAt'] ?? ''), 0, 10)) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  function rebuildUrl(updates){
    var url = new URL(window.location.href);
    Object.keys(updates).forEach(function(k){
      if (updates[k] === null || updates[k] === undefined || updates[k] === '') url.searchParams.delete(k);
      else url.searchParams.set(k, updates[k]);
    });
    return url.toString();
  }
  document.getElementById('b2tdDays').addEventListener('click', function(ev){
    var b = ev.target.closest('button[data-days]'); if (!b) return;
    window.location.href = rebuildUrl({days: b.getAttribute('data-days')});
  });
  document.getElementById('b2tdMonths').addEventListener('click', function(ev){
    var b = ev.target.closest('button[data-months]'); if (!b) return;
    window.location.href = rebuildUrl({months: b.getAttribute('data-months')});
  });
  document.querySelectorAll('[data-export]').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var url = new URL('<?= base_url('superadmin/dashboard/tenant/' . urlencode($schoolId) . '/export') ?>', window.location.origin);
      url.searchParams.set('section', a.getAttribute('data-export'));
      url.searchParams.set('format',  a.getAttribute('data-format'));
      url.searchParams.set('days',    '<?= (int) $daysWindow ?>');
      url.searchParams.set('months',  '<?= (int) $monthsTrend ?>');
      window.location.href = url.toString();
    });
  });

  // Per-chart options factory (Phase 1E hardening pattern reused)
  function axisOpts(suggestedMax){
    var y = { beginAtZero: true, ticks: { font: { size: 10 } } };
    if (suggestedMax) y.suggestedMax = suggestedMax;
    return { responsive: true, maintainAspectRatio: false,
             plugins: { legend: { display: true, position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } } },
             scales: { y: y, x: { ticks: { font: { size: 10 } } } } };
  }
  function maxVal(rows, key){ var m = 0; rows.forEach(function(r){ var v = Number(r[key] || 0); if (v > m) m = v; }); return m; }

  var series = <?= json_encode(array_values((array) $series)) ?>;
  if (series.length) {
    var stuMax = maxVal(series, 'totalStudents'); var staMax = maxVal(series, 'totalStaff');
    var growthMax = Math.max(stuMax, staMax);
    new Chart(document.getElementById('b2tdGrowthChart'), {
      type: 'line',
      data: { labels: series.map(function(r){ return r.period; }),
              datasets: [
                { label: 'Students', data: series.map(function(r){ return Number(r.totalStudents || 0); }),
                  borderColor: '#0f172a', backgroundColor: 'rgba(15,23,42,.07)', tension: 0.25, fill: false, pointRadius: 3, borderWidth: 2 },
                { label: 'Staff',    data: series.map(function(r){ return Number(r.totalStaff    || 0); }),
                  borderColor: '#16a34a', backgroundColor: 'rgba(34,197,94,.07)', tension: 0.25, fill: false, pointRadius: 3, borderWidth: 2 } ] },
      options: axisOpts(growthMax > 0 ? null : 10)
    });
    var auditMax = maxVal(series, 'auditCount');
    new Chart(document.getElementById('b2tdActivityChart'), {
      type: 'bar',
      data: { labels: series.map(function(r){ return r.period; }),
              datasets: [{ label: 'Audit events', data: series.map(function(r){ return Number(r.auditCount || 0); }),
                            backgroundColor: 'rgba(15,23,42,.6)', borderColor: '#0f172a', borderWidth: 1 }] },
      options: axisOpts(auditMax > 0 ? null : 5)
    });
  }
})();
</script>
