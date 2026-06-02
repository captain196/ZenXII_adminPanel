<?php
/**
 * B2.3.4-A Phase 1E — Revenue Reports spoke view.
 *
 * 4 stacked sections:
 *   1. Headline KPIs (5 tiles)
 *   2. Time-series (2 Chart.js charts: MRR line + Revenue bar)
 *   3. Plan Profitability (donut + table)
 *   4. Payment Activity (recent payments table + at-risk/outstanding table)
 *
 * Variables in scope:
 *   $payload     — get_revenue_overview() composite payload
 *   $monthsBack  — currently-selected period (3/6/12/24)
 */
$kpi   = $payload['headline_kpi']        ?? [];
$cur   = (string) ($payload['currency']  ?? 'INR');
$mrrTs = $payload['time_series_mrr']     ?? [];
$payTs = $payload['time_series_payments'] ?? [];
$plansR= $payload['revenue_by_plan']     ?? [];
$payRow= $payload['recent_payments']     ?? [];
$riskR = $payload['at_risk_tenants']     ?? [];
$lostR = $payload['lost_mrr_by_state']   ?? [];

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
$money = function ($v) use ($cur) {
    if ($v === null || $v === '' || !is_numeric($v) || (float) $v <= 0) return '<span style="color:#94a3b8;">—</span>';
    return '<span style="white-space:nowrap;">' . ($cur === 'INR' ? '₹' : '') . number_format((float) $v, 2) . '</span>';
};
$moneyPlain = function ($v) use ($cur) {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return ($cur === 'INR' ? '₹' : '') . number_format((float) $v, 2);
};
$total_revenue_window = (float) ($kpi['total_revenue_window'] ?? 0);
$noPayments = $total_revenue_window <= 0 && count($payRow) === 0;
?>
<style>
.b2rv-shell{padding:18px 24px;}
.b2rv-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:10px 14px;}
.b2rv-toolbar .spacer{flex:1;}
.b2rv-toolbar .lbl{font-size:12px;color:#475569;}
.b2rv-toolbar .toggle button{border:1px solid #cbd5e1;background:#fff;padding:5px 11px;font-size:12px;cursor:pointer;color:#475569;}
.b2rv-toolbar .toggle button.active{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2rv-toolbar .toggle button:first-child{border-radius:5px 0 0 5px;}
.b2rv-toolbar .toggle button:last-child{border-radius:0 5px 5px 0;border-left:none;}
.b2rv-toolbar .toggle button:not(:first-child):not(:last-child){border-left:none;}
.b2rv-sec{background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:16px;margin-bottom:14px;}
.b2rv-sec h3{margin:0 0 12px 0;font-size:14px;color:#0f172a;font-weight:600;display:flex;align-items:center;gap:8px;}
.b2rv-sec h3 .exp{margin-left:auto;font-size:11px;font-weight:500;}
.b2rv-sec h3 .exp a{color:#475569;text-decoration:none;padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;}
.b2rv-sec h3 .exp a:hover{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2rv-kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;}
@media (max-width:1100px){.b2rv-kpis{grid-template-columns:repeat(2,1fr);} }
.b2rv-kpi{background:#fafbfc;border:1px solid #e6e8eb;border-radius:8px;padding:14px 16px;}
.b2rv-kpi .lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px;}
.b2rv-kpi .v{font-size:22px;font-weight:600;color:#0f172a;line-height:1.1;}
.b2rv-kpi .sub{font-size:11px;color:#64748b;margin-top:4px;}
.b2rv-kpi.warn .v{color:#92400e;}
.b2rv-kpi.crit .v{color:#991b1b;}
/* CSS Grid hardening: canvas inside a 1fr column has 300x150 intrinsic
   size, which makes the column grow past 1fr's share and blow out the
   layout. minmax(0,1fr) + min-width:0 on children is the canonical fix. */
.b2rv-ts{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;}
@media (max-width:900px){.b2rv-ts{grid-template-columns:minmax(0,1fr);}}
.b2rv-ts > div{min-width:0;}
.b2rv-chartwrap{height:220px;min-height:220px;position:relative;background:#fafbfc;border:1px solid #f1f5f9;border-radius:4px;}
.b2rv-plan{display:grid;grid-template-columns:280px 1fr;gap:18px;}
@media (max-width:900px){.b2rv-plan{grid-template-columns:1fr;}}
.b2rv-act{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
@media (max-width:900px){.b2rv-act{grid-template-columns:1fr;}}
.b2rv-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.b2rv-tbl th,.b2rv-tbl td{padding:7px 10px;text-align:left;border-bottom:1px solid #f1f5f9;}
.b2rv-tbl th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#475569;font-weight:600;}
.b2rv-tbl td.r{text-align:right;}
.b2rv-tbl tr:hover td{background:#f8fafc;}
.b2rv-empty{text-align:center;padding:24px;color:#64748b;font-size:12.5px;font-style:italic;}
.b2rv-lc{font-size:10px;font-weight:600;padding:2px 7px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;display:inline-block;}
.b2rv-lc.past_due{background:#fee2e2;color:#991b1b;}
.b2rv-lc.grace{background:#fef3c7;color:#92400e;}
.b2rv-lc.expiring_soon{background:#fef3c7;color:#92400e;}
.b2rv-caption{font-size:11px;color:#94a3b8;font-style:italic;margin-top:6px;}
.b2rv-lost{display:flex;gap:14px;margin-top:10px;}
.b2rv-lost div{flex:1;background:#fafbfc;border:1px solid #e6e8eb;border-radius:6px;padding:8px 10px;}
.b2rv-lost .lbl{font-size:10px;color:#64748b;text-transform:uppercase;}
.b2rv-lost .v{font-size:14px;font-weight:600;color:#475569;margin-top:2px;}
</style>

<section class="content-header" style="padding:18px 24px 0 24px;">
  <h1 style="margin:0;font-size:20px;color:#0f172a;">
    <i class="fa fa-line-chart" style="color:#0f172a;margin-right:10px;"></i>Revenue Reports
  </h1>
  <ol class="breadcrumb" style="margin-top:6px;background:transparent;padding:0;">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li class="active">Revenue Reports</li>
  </ol>
</section>

<div class="b2rv-shell">

  <!-- ── PERIOD TOOLBAR ─────────────────────────────────────────── -->
  <div class="b2rv-toolbar">
    <span class="lbl">Period</span>
    <span class="toggle" id="b2rvPeriod">
      <?php foreach ([3, 6, 12, 24] as $n): ?>
        <button type="button" data-m="<?= $n ?>" class="<?= $monthsBack === $n ? 'active' : '' ?>"><?= $n ?>mo</button>
      <?php endforeach; ?>
    </span>
    <span class="spacer"></span>
    <span class="lbl">Currency: <?= $h($cur) ?></span>
    <span class="lbl">·</span>
    <span class="lbl">Generated <?= $h(substr((string) ($payload['generated_at'] ?? date('c')), 11, 8)) ?> UTC</span>
  </div>

  <!-- ── SECTION 1: HEADLINE KPIs ──────────────────────────────── -->
  <div class="b2rv-sec">
    <h3>
      <i class="fa fa-tachometer" style="color:#0f172a;"></i>Headline KPIs
      <span class="exp">
        <a href="#" data-export="overview" data-format="csv">CSV</a>
        <a href="#" data-export="overview" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2rv-kpis">
      <div class="b2rv-kpi">
        <div class="lbl">MRR (Current)</div>
        <div class="v"><?= $moneyPlain($kpi['mrr'] ?? 0) ?></div>
        <div class="sub">Σ active+trialing+grace subscriptions / month</div>
      </div>
      <div class="b2rv-kpi">
        <div class="lbl">ARR</div>
        <div class="v"><?= $moneyPlain($kpi['arr'] ?? 0) ?></div>
        <div class="sub">= MRR × 12</div>
      </div>
      <div class="b2rv-kpi">
        <div class="lbl">Revenue (<?= $monthsBack ?>mo)</div>
        <div class="v"><?= $total_revenue_window > 0 ? $moneyPlain($total_revenue_window) : '<span style="color:#94a3b8;">—</span>' ?></div>
        <div class="sub"><?= $noPayments ? 'No payments recorded yet' : ('Σ payments.amount in window') ?></div>
      </div>
      <div class="b2rv-kpi">
        <div class="lbl">ARPU</div>
        <div class="v"><?= ($kpi['arpu'] ?? 0) > 0 ? $moneyPlain($kpi['arpu']) : '<span style="color:#94a3b8;">—</span>' ?></div>
        <div class="sub">Revenue ÷ <?= (int) ($kpi['active_tenants'] ?? 0) ?> active tenants</div>
      </div>
      <div class="b2rv-kpi <?= ((int) ($kpi['outstanding_count'] ?? 0)) > 0 ? 'crit' : '' ?>">
        <div class="lbl">Outstanding</div>
        <div class="v"><?= $moneyPlain($kpi['outstanding_amount'] ?? 0) ?></div>
        <div class="sub"><?= (int) ($kpi['outstanding_count'] ?? 0) ?> tenant<?= ((int) ($kpi['outstanding_count'] ?? 0)) === 1 ? '' : 's' ?> in grace/past_due</div>
      </div>
    </div>
  </div>

  <!-- ── SECTION 2: TIME-SERIES ───────────────────────────────── -->
  <div class="b2rv-sec">
    <h3>
      <i class="fa fa-area-chart" style="color:#0f172a;"></i>Trends — trailing <?= $monthsBack ?> months
      <span class="exp">
        <a href="#" data-export="timeseries" data-format="csv">CSV</a>
        <a href="#" data-export="timeseries" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2rv-ts">
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">MRR over time (from analyticsRollups)</div>
        <div class="b2rv-chartwrap"><canvas id="b2rvMrrChart"></canvas></div>
      </div>
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">Payments collected per month</div>
        <div class="b2rv-chartwrap"><canvas id="b2rvPayChart"></canvas></div>
      </div>
    </div>
    <div class="b2rv-caption">Source: <code>analyticsRollups/{YYYY-MM}</code> (Phase 1A backfill). Churn/expansion decomposition becomes available in Phase 2 (Revenue Center).</div>
  </div>

  <!-- ── SECTION 3: PLAN PROFITABILITY ────────────────────────── -->
  <div class="b2rv-sec">
    <h3>
      <i class="fa fa-pie-chart" style="color:#0f172a;"></i>Plan Profitability
      <span class="exp">
        <a href="#" data-export="plans" data-format="csv">CSV</a>
        <a href="#" data-export="plans" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2rv-plan">
      <div class="b2rv-chartwrap" style="height:200px;"><canvas id="b2rvPlanChart"></canvas></div>
      <div>
        <?php if (empty($plansR)): ?>
          <div class="b2rv-empty">No revenue-bearing plans (no active subscriptions found).</div>
        <?php else: ?>
        <table class="b2rv-tbl">
          <thead>
            <tr>
              <th>Plan</th>
              <th class="r">MRR</th>
              <th class="r">ARR</th>
              <th class="r">Tenants</th>
              <th class="r">Avg / Tenant</th>
              <th class="r">Share</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($plansR as $p): ?>
              <tr>
                <td><strong><?= $h($p['planName'] ?? '') ?></strong></td>
                <td class="r"><?= $moneyPlain($p['mrr'] ?? 0) ?></td>
                <td class="r"><?= $moneyPlain($p['arr'] ?? 0) ?></td>
                <td class="r"><?= (int) ($p['tenants'] ?? 0) ?></td>
                <td class="r"><?= $moneyPlain($p['avgPerTenant'] ?? 0) ?></td>
                <td class="r"><?= number_format((float) ($p['sharePct'] ?? 0), 1) ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── SECTION 4: PAYMENT ACTIVITY ──────────────────────────── -->
  <div class="b2rv-sec">
    <h3><i class="fa fa-credit-card" style="color:#0f172a;"></i>Payment Activity</h3>
    <div class="b2rv-act">
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;display:flex;align-items:center;">
          Recent Payments (top 10)
          <span style="margin-left:auto;">
            <a href="#" data-export="payments" data-format="csv" style="font-size:11px;color:#475569;text-decoration:none;padding:2px 7px;border:1px solid #cbd5e1;border-radius:4px;">CSV</a>
            <a href="#" data-export="payments" data-format="xlsx" style="font-size:11px;color:#475569;text-decoration:none;padding:2px 7px;border:1px solid #cbd5e1;border-radius:4px;">XLSX</a>
          </span>
        </div>
        <?php if (empty($payRow)): ?>
          <div class="b2rv-empty">No payments recorded yet.</div>
        <?php else: ?>
        <table class="b2rv-tbl">
          <thead><tr><th>Date</th><th>School</th><th class="r">Amount</th><th>Method</th></tr></thead>
          <tbody>
            <?php foreach ($payRow as $r): ?>
              <tr>
                <td><?= $h(substr((string) ($r['paidAt'] ?? ''), 0, 10)) ?></td>
                <td><?= $h($r['schoolName'] ?? '') ?></td>
                <td class="r"><?= $moneyPlain($r['amount'] ?? 0) ?></td>
                <td><?= $h($r['method'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;display:flex;align-items:center;">
          At-Risk Tenants
          <span style="margin-left:auto;">
            <a href="#" data-export="atrisk" data-format="csv" style="font-size:11px;color:#475569;text-decoration:none;padding:2px 7px;border:1px solid #cbd5e1;border-radius:4px;">CSV</a>
            <a href="#" data-export="atrisk" data-format="xlsx" style="font-size:11px;color:#475569;text-decoration:none;padding:2px 7px;border:1px solid #cbd5e1;border-radius:4px;">XLSX</a>
          </span>
        </div>
        <?php if (empty($riskR)): ?>
          <div class="b2rv-empty">No tenants in past_due / grace / expiring_soon states.</div>
        <?php else: ?>
        <table class="b2rv-tbl">
          <thead><tr><th>School</th><th>State</th><th>Days</th><th class="r">MRR</th></tr></thead>
          <tbody>
            <?php foreach ($riskR as $r): ?>
              <tr>
                <td><strong><?= $h($r['schoolName'] ?? '') ?></strong><br><small style="color:#94a3b8;"><?= $h($r['city'] ?? '') ?></small></td>
                <td><span class="b2rv-lc <?= $h($r['state'] ?? '') ?>"><?= $h(str_replace('_', ' ', (string) $r['state'])) ?></span></td>
                <td><?= $r['daysToEnd'] !== null ? (int) $r['daysToEnd'] : '—' ?></td>
                <td class="r"><?= $moneyPlain($r['atRiskMrr'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="b2rv-caption">Based on lifecycle state. Refer to subscription dates for billing reality.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Lost-MRR-by-state mini-band -->
    <div class="b2rv-lost">
      <?php foreach (['past_due' => 'Past Due', 'suspended' => 'Suspended', 'expired' => 'Expired'] as $k => $lbl): ?>
        <div>
          <div class="lbl"><?= $h($lbl) ?> · Lost MRR</div>
          <div class="v"><?= ($lostR[$k] ?? 0) > 0 ? $moneyPlain($lostR[$k]) : '<span style="color:#94a3b8;font-weight:400;">—</span>' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  // ── period selector ────────────────────────────────────────────
  document.getElementById('b2rvPeriod').addEventListener('click', function(ev){
    var btn = ev.target.closest('button[data-m]');
    if (!btn) return;
    var months = parseInt(btn.getAttribute('data-m'), 10);
    if (!months) return;
    var url = new URL(window.location.href);
    url.searchParams.set('months', months);
    window.location.href = url.toString();
  });

  // ── exports ────────────────────────────────────────────────────
  document.querySelectorAll('[data-export]').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var section = a.getAttribute('data-export');
      var format  = a.getAttribute('data-format');
      var months  = <?= (int) $monthsBack ?>;
      window.location.href = '<?= base_url("superadmin/dashboard/revenue/export") ?>' +
        '?section=' + encodeURIComponent(section) +
        '&format='  + encodeURIComponent(format)  +
        '&months='  + months;
    });
  });

  // ── charts ─────────────────────────────────────────────────────
  var mrrTs = <?= json_encode(array_values($mrrTs)) ?>;
  var payTs = <?= json_encode(array_values($payTs)) ?>;
  var plans = <?= json_encode(array_values($plansR)) ?>;

  // Per-chart options factory. Avoids sharing the options object across
  // Chart.js instances (Chart.js may mutate options internally). suggestedMax
  // gives the bar chart a visible y-axis range when all data is 0.
  var makeAxisOpts = function(suggestedMax){
    var yOpts = { beginAtZero: true, ticks: { font: { size: 10 } } };
    if (suggestedMax) yOpts.suggestedMax = suggestedMax;
    return {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: yOpts, x: { ticks: { font: { size: 10 } } } }
    };
  };
  var maxVal = function(rows, key){
    var m = 0;
    rows.forEach(function(r){ var v = Number(r[key] || 0); if (v > m) m = v; });
    return m;
  };

  if (mrrTs.length) {
    var mrrMax = maxVal(mrrTs, 'mrr');
    new Chart(document.getElementById('b2rvMrrChart'), {
      type: 'line',
      data: {
        labels: mrrTs.map(function(r){ return r.period; }),
        datasets: [{
          label: 'MRR',
          data: mrrTs.map(function(r){ return Number(r.mrr || 0); }),
          borderColor: '#0f172a',
          backgroundColor: 'rgba(15,23,42,.07)',
          tension: 0.25, fill: true, pointRadius: 3, borderWidth: 2
        }]
      },
      options: makeAxisOpts(mrrMax > 0 ? null : 100)
    });
  }

  if (payTs.length) {
    var payMax = maxVal(payTs, 'totalRevenue');
    new Chart(document.getElementById('b2rvPayChart'), {
      type: 'bar',
      data: {
        labels: payTs.map(function(r){ return r.period; }),
        datasets: [{
          label: 'Revenue',
          data: payTs.map(function(r){ return Number(r.totalRevenue || 0); }),
          backgroundColor: 'rgba(34,197,94,.45)',
          borderColor: '#16a34a',
          borderWidth: 1
        }]
      },
      options: makeAxisOpts(payMax > 0 ? null : 100)
    });
  }

  if (plans.length) {
    var planColors = ['#0f172a', '#16a34a', '#0ea5e9', '#f59e0b', '#a855f7', '#ef4444'];
    new Chart(document.getElementById('b2rvPlanChart'), {
      type: 'doughnut',
      data: {
        labels: plans.map(function(p){ return p.planName; }),
        datasets: [{
          data: plans.map(function(p){ return Number(p.mrr || 0); }),
          backgroundColor: plans.map(function(_, i){ return planColors[i % planColors.length]; })
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } },
          tooltip: { callbacks: { label: function(ctx){
            var pct = plans[ctx.dataIndex] && plans[ctx.dataIndex].sharePct;
            return ctx.label + ': ' + ctx.formattedValue + (pct ? ' (' + Number(pct).toFixed(1) + '%)' : '');
          }}}
        }
      }
    });
  }
})();
</script>
