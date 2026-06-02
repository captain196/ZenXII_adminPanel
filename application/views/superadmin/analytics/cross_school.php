<?php
/**
 * B2.3.4-A Phase 1F — Cross-School Summaries spoke view.
 *
 * 4 stacked sections:
 *   1. Fleet KPIs (5 tiles)
 *   2. Leaderboards (top-5 + bottom-5 by selected metric)
 *   3. Comparative Matrix (sortable per-tenant grid; ALL metrics)
 *   4. Engagement Distribution (Chart.js bar histogram)
 *
 * Operator-locked constraint: test/soak tenants excluded by default
 * (toggle to include). When included, marked with NON-PROD badge.
 *
 * Variables in scope:
 *   $payload, $daysWindow, $monthsTrend, $metricKey, $includeTest
 */
$kpi    = $payload['fleet_kpi']               ?? [];
$matrix = $payload['comparative_matrix']      ?? [];
$top    = $payload['leaderboard_top']         ?? [];
$bot    = $payload['leaderboard_bottom']      ?? [];
$dist   = $payload['engagement_distribution'] ?? ['labels' => [], 'counts' => [], 'metric' => $metricKey];
$testCount = (int) ($payload['test_tenant_count'] ?? 0);
$prodCount = (int) ($payload['production_tenant_count'] ?? 0);

$metricsLabels = [
    'activity_volume'      => 'Activity Volume',
    'student_growth'       => 'Student Growth',
    'staff_growth'         => 'Staff Growth',
    'data_freshness_hours' => 'Data Freshness (h)',
    'revenue_contribution' => 'Revenue Contribution',
];
$metricLbl = $metricsLabels[$metricKey] ?? $metricKey;

$h = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); };
$fmtMetric = function ($row, $metric) {
    $v = $row[$metric] ?? null;
    if ($v === null) return '<span style="color:#94a3b8;">—</span>';
    if ($metric === 'revenue_contribution') return '₹' . number_format((float) $v, 2);
    if ($metric === 'data_freshness_hours') return (int) $v . 'h';
    if (in_array($metric, ['student_growth', 'staff_growth'], true)) {
        $n = (int) $v;
        $sign = $n > 0 ? '+' : '';
        return $sign . $n;
    }
    return number_format((float) $v, 0);
};
?>
<style>
.b2cs-shell{padding:18px 24px;}
.b2cs-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:10px 14px;flex-wrap:wrap;}
.b2cs-toolbar .grp{display:flex;align-items:center;gap:6px;}
.b2cs-toolbar .lbl{font-size:12px;color:#475569;}
.b2cs-toolbar .spacer{flex:1;}
.b2cs-toolbar select{padding:5px 8px;border:1px solid #cbd5e1;border-radius:5px;font-size:12px;background:#fff;}
.b2cs-toolbar .toggle button{border:1px solid #cbd5e1;background:#fff;padding:5px 10px;font-size:12px;cursor:pointer;color:#475569;}
.b2cs-toolbar .toggle button.active{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2cs-toolbar .toggle button:first-child{border-radius:5px 0 0 5px;}
.b2cs-toolbar .toggle button:last-child{border-radius:0 5px 5px 0;border-left:none;}
.b2cs-toolbar .toggle button:not(:first-child):not(:last-child){border-left:none;}
.b2cs-toolbar .scope-pill{display:inline-flex;align-items:center;gap:8px;font-size:11px;color:#475569;background:#f8fafc;border:1px solid #e6e8eb;border-radius:11px;padding:3px 9px;}
.b2cs-toolbar .scope-pill input{margin:0;}
.b2cs-sec{background:#fff;border:1px solid #e6e8eb;border-radius:8px;padding:16px;margin-bottom:14px;}
.b2cs-sec h3{margin:0 0 12px 0;font-size:14px;color:#0f172a;font-weight:600;display:flex;align-items:center;gap:8px;}
.b2cs-sec h3 .exp{margin-left:auto;font-size:11px;font-weight:500;}
.b2cs-sec h3 .exp a{color:#475569;text-decoration:none;padding:3px 8px;border:1px solid #cbd5e1;border-radius:4px;}
.b2cs-sec h3 .exp a:hover{background:#0f172a;color:#fff;border-color:#0f172a;}
.b2cs-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;}
@media (max-width:1100px){.b2cs-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}}
.b2cs-kpi{background:#fafbfc;border:1px solid #e6e8eb;border-radius:8px;padding:14px 16px;}
.b2cs-kpi .lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;margin-bottom:5px;}
.b2cs-kpi .v{font-size:22px;font-weight:600;color:#0f172a;line-height:1.1;}
.b2cs-kpi .sub{font-size:11px;color:#64748b;margin-top:4px;}
.b2cs-boards{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px;}
@media (max-width:900px){.b2cs-boards{grid-template-columns:minmax(0,1fr);}}
.b2cs-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.b2cs-tbl th,.b2cs-tbl td{padding:7px 10px;text-align:left;border-bottom:1px solid #f1f5f9;}
.b2cs-tbl th{background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#475569;font-weight:600;cursor:pointer;user-select:none;}
.b2cs-tbl th .arr{font-size:9px;color:#94a3b8;margin-left:3px;}
.b2cs-tbl td.r{text-align:right;}
.b2cs-tbl tr:hover td{background:#f8fafc;}
.b2cs-tbl .rank{display:inline-block;width:18px;height:18px;border-radius:50%;background:#0f172a;color:#fff;font-size:11px;text-align:center;line-height:18px;font-weight:600;margin-right:6px;}
.b2cs-tbl .rank.alt{background:#dc2626;}
.b2cs-badge{display:inline-block;font-size:9px;font-weight:600;padding:1px 5px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;background:#fef3c7;color:#92400e;margin-left:5px;vertical-align:middle;}
.b2cs-lc{display:inline-block;font-size:10px;font-weight:600;padding:2px 7px;border-radius:9px;text-transform:uppercase;letter-spacing:.3px;}
.b2cs-lc.active{background:#dcfce7;color:#166534;}
.b2cs-lc.trialing{background:#dbeafe;color:#1e40af;}
.b2cs-lc.expiring_soon{background:#fef3c7;color:#92400e;}
.b2cs-lc.grace{background:#fef3c7;color:#92400e;}
.b2cs-lc.past_due{background:#fee2e2;color:#991b1b;}
.b2cs-lc.suspended{background:#e5e7eb;color:#374151;}
.b2cs-lc.expired{background:#e5e7eb;color:#374151;}
.b2cs-empty{text-align:center;padding:24px;color:#64748b;font-size:12.5px;font-style:italic;}
.b2cs-chartwrap{height:220px;min-height:220px;position:relative;background:#fafbfc;border:1px solid #f1f5f9;border-radius:4px;}
.b2cs-caption{font-size:11px;color:#94a3b8;font-style:italic;margin-top:8px;}
</style>

<section class="content-header" style="padding:18px 24px 0 24px;">
  <h1 style="margin:0;font-size:20px;color:#0f172a;">
    <i class="fa fa-bar-chart" style="color:#0f172a;margin-right:10px;"></i>Cross-School Summaries
    <small style="font-size:12px;color:#94a3b8;font-weight:400;">Comparative engagement and performance ranking across tenants</small>
  </h1>
  <ol class="breadcrumb" style="margin-top:6px;background:transparent;padding:0;">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li class="active">Cross-School Summaries</li>
  </ol>
</section>

<div class="b2cs-shell">

  <!-- ── TOOLBAR ─────────────────────────────────────────────────────── -->
  <div class="b2cs-toolbar">
    <span class="grp">
      <span class="lbl">Engagement window</span>
      <span class="toggle" id="b2csDays">
        <?php foreach ([7, 30, 90] as $d): ?>
          <button type="button" data-days="<?= $d ?>" class="<?= $daysWindow === $d ? 'active' : '' ?>"><?= $d ?>d</button>
        <?php endforeach; ?>
      </span>
    </span>
    <span class="grp">
      <span class="lbl">Growth window</span>
      <span class="toggle" id="b2csMonths">
        <?php foreach ([3, 6, 12] as $m): ?>
          <button type="button" data-months="<?= $m ?>" class="<?= $monthsTrend === $m ? 'active' : '' ?>"><?= $m ?>mo</button>
        <?php endforeach; ?>
      </span>
    </span>
    <span class="grp">
      <span class="lbl">Rank by</span>
      <select id="b2csMetric">
        <?php foreach ($metricsLabels as $k => $lbl): ?>
          <option value="<?= $h($k) ?>" <?= $metricKey === $k ? 'selected' : '' ?>><?= $h($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </span>
    <span class="spacer"></span>
    <label class="scope-pill" title="When unchecked, test/soak/ZZ-prefixed tenants are filtered out of rankings and KPIs">
      <input type="checkbox" id="b2csIncludeTest" <?= $includeTest ? 'checked' : '' ?>>
      Include <?= $testCount ?> test tenant<?= $testCount === 1 ? '' : 's' ?>
    </label>
    <span class="scope-pill">In-scope: <strong style="color:#0f172a;"><?= $prodCount ?></strong> tenant<?= $prodCount === 1 ? '' : 's' ?></span>
  </div>

  <?php if ($prodCount === 0 && !$includeTest): ?>
    <div class="b2cs-sec" style="text-align:center;color:#475569;">
      <i class="fa fa-info-circle" style="color:#0ea5e9;font-size:24px;margin-bottom:8px;"></i>
      <h4 style="margin:0 0 4px 0;color:#475569;">No production tenants in scope</h4>
      <p style="margin:0;font-size:12px;">All current tenants are flagged as test/soak. Toggle "Include test tenants" above to view comparative metrics.</p>
    </div>
  <?php else: ?>

  <!-- ── SECTION 1: FLEET KPIs ─────────────────────────────────────── -->
  <div class="b2cs-sec">
    <h3>
      <i class="fa fa-th" style="color:#0f172a;"></i>Fleet KPIs
      <span class="exp">
        <a href="#" data-export="overview" data-format="csv">CSV</a>
        <a href="#" data-export="overview" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <div class="b2cs-kpis">
      <div class="b2cs-kpi">
        <div class="lbl">Total Audit Events</div>
        <div class="v"><?= number_format((int) ($kpi['total_audit_events'] ?? 0)) ?></div>
        <div class="sub"><?= (int) ($kpi['window_days'] ?? $daysWindow) ?>-day window</div>
      </div>
      <div class="b2cs-kpi">
        <div class="lbl">Avg Activity / Tenant</div>
        <div class="v"><?= number_format((int) ($kpi['avg_activity_tenant'] ?? 0)) ?></div>
        <div class="sub">events per in-scope tenant</div>
      </div>
      <div class="b2cs-kpi">
        <div class="lbl">Total Student Δ</div>
        <div class="v"><?php $sd = (int) ($kpi['total_student_delta'] ?? 0); echo ($sd > 0 ? '+' : '') . number_format($sd); ?></div>
        <div class="sub">trailing 3 months</div>
      </div>
      <div class="b2cs-kpi">
        <div class="lbl">Stale Tenants</div>
        <div class="v"><?= (int) ($kpi['stale_tenants_count'] ?? 0) ?> / <?= (int) ($kpi['stale_tenants_total'] ?? 0) ?></div>
        <div class="sub">&gt;7 days since statsCache update</div>
      </div>
      <div class="b2cs-kpi">
        <div class="lbl">MRR / Active Tenant</div>
        <div class="v">₹<?= number_format((float) ($kpi['cross_tenant_mrr'] ?? 0), 2) ?></div>
        <div class="sub">cross-tenant average</div>
      </div>
    </div>
  </div>

  <!-- ── SECTION 2: LEADERBOARDS ───────────────────────────────────── -->
  <div class="b2cs-sec">
    <h3>
      <i class="fa fa-trophy" style="color:#0f172a;"></i>Leaderboards — by <?= $h($metricLbl) ?>
      <span class="exp">
        <a href="#" data-export="leaderboards" data-format="csv">CSV</a>
        <a href="#" data-export="leaderboards" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <?php // Single-tenant nudge: with only 1 in-scope tenant, TOP and BOTTOM
          // leaderboards would show the same row — confusing UX. Suppress BOTTOM
          // and show an informational caption instead.
          $singleTenantScope = ($prodCount <= 1); ?>
    <?php if ($singleTenantScope): ?>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#1e40af;">
        <i class="fa fa-info-circle" style="margin-right:6px;"></i>
        Only <strong><?= $prodCount ?></strong> tenant in scope — leaderboards become meaningful with ≥ 2 tenants.
        <?php if ($testCount > 0): ?>Toggle <em>Include <?= $testCount ?> test tenant<?= $testCount === 1 ? '' : 's' ?></em> above to compare against the full fleet.<?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="b2cs-boards" <?php if ($singleTenantScope): ?>style="grid-template-columns:minmax(0,1fr);"<?php endif; ?>>
      <!-- TOP -->
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;">
          <i class="fa fa-caret-up" style="color:#16a34a;"></i>
          <?= $singleTenantScope ? 'In-scope tenant' : 'TOP 5' ?>
        </div>
        <?php if (empty($top)): ?>
          <div class="b2cs-empty">No data for this metric in scope.</div>
        <?php else: ?>
          <table class="b2cs-tbl">
            <thead><tr><th>School</th><th>Plan</th><th class="r"><?= $h($metricLbl) ?></th></tr></thead>
            <tbody>
              <?php foreach ($top as $i => $r): ?>
                <tr>
                  <td>
                    <span class="rank"><?= ($i + 1) ?></span>
                    <strong><?= $h($r['schoolName'] ?? '') ?></strong>
                    <?php if (!empty($r['isTestTenant'])): ?><span class="b2cs-badge">non-prod</span><?php endif; ?>
                  </td>
                  <td><?= $h($r['planName'] ?? '') ?></td>
                  <td class="r"><?= $fmtMetric($r, $metricKey) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php if (!$singleTenantScope): ?>
      <!-- BOTTOM 5 -->
      <div>
        <div style="font-size:11.5px;color:#475569;margin-bottom:6px;"><i class="fa fa-caret-down" style="color:#dc2626;"></i> BOTTOM 5</div>
        <?php if (empty($bot)): ?>
          <div class="b2cs-empty">No non-zero data points for bottom ranking.</div>
        <?php else: ?>
          <table class="b2cs-tbl">
            <thead><tr><th>School</th><th>Plan</th><th class="r"><?= $h($metricLbl) ?></th></tr></thead>
            <tbody>
              <?php foreach ($bot as $i => $r): ?>
                <tr>
                  <td>
                    <span class="rank alt"><?= ($i + 1) ?></span>
                    <strong><?= $h($r['schoolName'] ?? '') ?></strong>
                    <?php if (!empty($r['isTestTenant'])): ?><span class="b2cs-badge">non-prod</span><?php endif; ?>
                  </td>
                  <td><?= $h($r['planName'] ?? '') ?></td>
                  <td class="r"><?= $fmtMetric($r, $metricKey) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if (!$singleTenantScope): ?>
      <div class="b2cs-caption">Bottom rankings exclude tenants with zero/null values to avoid surfacing no-data tenants as worst performers.</div>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 3: COMPARATIVE MATRIX ─────────────────────────────── -->
  <div class="b2cs-sec">
    <h3>
      <i class="fa fa-table" style="color:#0f172a;"></i>Comparative Matrix
      <span class="exp">
        <a href="#" data-export="compare" data-format="csv">CSV</a>
        <a href="#" data-export="compare" data-format="xlsx">XLSX</a>
      </span>
    </h3>
    <?php if (empty($matrix)): ?>
      <div class="b2cs-empty">No tenants in scope.</div>
    <?php else: ?>
      <table class="b2cs-tbl" id="b2csMatrix">
        <thead>
          <tr>
            <th data-sort="schoolName">School</th>
            <th data-sort="planName">Plan</th>
            <th data-sort="lifecycleState">Lifecycle</th>
            <th class="r" data-sort="activity_volume">Activity (<?= $daysWindow ?>d)</th>
            <th class="r" data-sort="student_delta">Student Δ</th>
            <th class="r" data-sort="staff_delta">Staff Δ</th>
            <th class="r" data-sort="data_freshness_hours">Data Age</th>
            <th class="r" data-sort="revenue_contribution">Revenue/mo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matrix as $r):
            // When includeTest is false the service has already filtered;
            // when true, all rows render with non-prod badge on tests.
            if (!$includeTest && !empty($r['isTestTenant'])) continue;
            $lc = strtolower((string) ($r['lifecycleState'] ?? '')); ?>
            <tr>
              <td>
                <strong><?= $h($r['schoolName'] ?? '') ?></strong>
                <?php if (!empty($r['isTestTenant'])): ?><span class="b2cs-badge">non-prod</span><?php endif; ?>
                <br><small style="color:#94a3b8;"><?= $h($r['schoolCode'] ?? '') ?><?= !empty($r['city']) ? ' · ' . $h($r['city']) : '' ?></small>
              </td>
              <td><?= $h($r['planName'] ?? '') ?></td>
              <td><span class="b2cs-lc <?= $h($lc) ?>"><?= $h($lc) ?></span></td>
              <td class="r"><?= number_format((int) ($r['activity_volume'] ?? 0)) ?></td>
              <td class="r"><?php $v = (int) ($r['student_delta'] ?? 0); echo ($v > 0 ? '+' : '') . $v; ?></td>
              <td class="r"><?php $v = (int) ($r['staff_delta'] ?? 0); echo ($v > 0 ? '+' : '') . $v; ?></td>
              <td class="r"><?= $r['data_freshness_hours'] === null ? '<span style="color:#94a3b8;">—</span>' : (int) $r['data_freshness_hours'] . 'h' ?></td>
              <td class="r">₹<?= number_format((float) ($r['revenue_contribution'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 4: ENGAGEMENT DISTRIBUTION ────────────────────────── -->
  <div class="b2cs-sec">
    <h3>
      <i class="fa fa-bar-chart" style="color:#0f172a;"></i>Engagement Distribution — by <?= $h($metricLbl) ?>
    </h3>
    <div class="b2cs-chartwrap"><canvas id="b2csDistChart"></canvas></div>
    <div class="b2cs-caption">Bucketed histogram of in-scope tenants by the selected metric. Sum of bar heights == in-scope tenant count.</div>
  </div>

  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  function rebuildUrl(updates){
    var url = new URL(window.location.href);
    Object.keys(updates).forEach(function(k){
      if (updates[k] === null || updates[k] === undefined || updates[k] === '') {
        url.searchParams.delete(k);
      } else {
        url.searchParams.set(k, updates[k]);
      }
    });
    return url.toString();
  }

  // Period + metric + include-test cycling
  var daysGroup = document.getElementById('b2csDays');
  if (daysGroup) daysGroup.addEventListener('click', function(ev){
    var b = ev.target.closest('button[data-days]'); if (!b) return;
    window.location.href = rebuildUrl({days: b.getAttribute('data-days')});
  });
  var monthsGroup = document.getElementById('b2csMonths');
  if (monthsGroup) monthsGroup.addEventListener('click', function(ev){
    var b = ev.target.closest('button[data-months]'); if (!b) return;
    window.location.href = rebuildUrl({months: b.getAttribute('data-months')});
  });
  var metricSel = document.getElementById('b2csMetric');
  if (metricSel) metricSel.addEventListener('change', function(){
    window.location.href = rebuildUrl({metric: metricSel.value});
  });
  var incTest = document.getElementById('b2csIncludeTest');
  if (incTest) incTest.addEventListener('change', function(){
    window.location.href = rebuildUrl({include_test: incTest.checked ? '1' : '0'});
  });

  // Matrix column sort (client-side; simple in-place sort)
  var matrix = document.getElementById('b2csMatrix');
  if (matrix) {
    var sortState = {field: null, asc: true};
    matrix.querySelectorAll('th[data-sort]').forEach(function(th){
      th.addEventListener('click', function(){
        var field = th.getAttribute('data-sort');
        if (sortState.field === field) sortState.asc = !sortState.asc; else { sortState.field = field; sortState.asc = false; }
        var tbody = matrix.querySelector('tbody');
        var rows = Array.from(tbody.querySelectorAll('tr'));
        var idx = Array.from(th.parentElement.children).indexOf(th);
        rows.sort(function(a, b){
          var av = a.children[idx].textContent.trim().replace(/[₹,h+]/g,'');
          var bv = b.children[idx].textContent.trim().replace(/[₹,h+]/g,'');
          var na = parseFloat(av), nb = parseFloat(bv);
          if (!isNaN(na) && !isNaN(nb)) return sortState.asc ? na - nb : nb - na;
          return sortState.asc ? av.localeCompare(bv) : bv.localeCompare(av);
        });
        rows.forEach(function(r){ tbody.appendChild(r); });
      });
    });
  }

  // Exports
  document.querySelectorAll('[data-export]').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var section = a.getAttribute('data-export');
      var format  = a.getAttribute('data-format');
      var url = new URL('<?= base_url("superadmin/dashboard/cross-school/export") ?>', window.location.origin);
      url.searchParams.set('section', section);
      url.searchParams.set('format', format);
      url.searchParams.set('days', '<?= (int) $daysWindow ?>');
      url.searchParams.set('months', '<?= (int) $monthsTrend ?>');
      url.searchParams.set('metric', '<?= $h($metricKey) ?>');
      url.searchParams.set('include_test', '<?= $includeTest ? '1' : '0' ?>');
      window.location.href = url.toString();
    });
  });

  // Distribution chart
  var distLabels = <?= json_encode($dist['labels'] ?? []) ?>;
  var distCounts = <?= json_encode($dist['counts'] ?? []) ?>;
  if (distLabels.length) {
    var maxCount = Math.max.apply(null, distCounts);
    new Chart(document.getElementById('b2csDistChart'), {
      type: 'bar',
      data: {
        labels: distLabels,
        datasets: [{
          label: 'Tenants',
          data: distCounts,
          backgroundColor: 'rgba(15,23,42,.6)',
          borderColor: '#0f172a',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, suggestedMax: maxCount > 0 ? null : 3, ticks: { font: { size: 10 }, stepSize: 1 } },
          x: { ticks: { font: { size: 10 } } }
        }
      }
    });
  }
})();
</script>
