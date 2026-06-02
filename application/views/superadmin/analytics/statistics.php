<?php
/**
 * B2.3.4-A Phase 1C — Statistics spoke view.
 *
 * Widgets (per locked design §1.2 S-1..S-8):
 *   • 4 KPI tiles: Net New (30d), Average Tenant Age, Total Schools, Active %
 *   • Schools Onboarded by Month  — bar chart
 *   • Lifecycle Distribution      — donut chart (drill-down on click)
 *   • Plan Distribution           — donut chart (drill-down on click)
 *   • Students Growth             — line chart
 *   • Staff Growth                — line chart
 *   • Schools by City             — horizontal bar chart (drill-down on click)
 *   • Recent Registrations        — table (last 30 days)
 *
 * Time range: 3 / 6 / 12 / 24 months selector (Q-A1 default 12).
 * Resolution selector (D/W/M) deferred to Phase 1H — only monthly cadence
 * is available from the analyticsRollups source.
 */
$payload     = $payload ?? [];
$monthsBack  = (int) ($monthsBack ?? 12);

$onboardedSeries = is_array($payload['schools_onboarded_series'] ?? null) ? $payload['schools_onboarded_series'] : [];
$studentsSeries  = is_array($payload['students_growth_series'] ?? null) ? $payload['students_growth_series'] : [];
$staffSeries     = is_array($payload['staff_growth_series'] ?? null) ? $payload['staff_growth_series'] : [];
$lifecycleDist   = is_array($payload['lifecycle_distribution'] ?? null) ? $payload['lifecycle_distribution'] : [];
$planDist        = is_array($payload['plan_distribution'] ?? null) ? $payload['plan_distribution'] : [];
$cityDist        = is_array($payload['schools_by_city'] ?? null) ? $payload['schools_by_city'] : [];
$recentRegs      = is_array($payload['recent_registrations'] ?? null) ? $payload['recent_registrations'] : [];
$avgAge          = (int) ($payload['avg_tenant_age_days'] ?? 0);
$net30           = (int) ($payload['net_new_30d'] ?? 0);
$net90           = (int) ($payload['net_new_90d'] ?? 0);
$generatedAtIso  = (string) ($payload['generated_at'] ?? '');
// 2026-06-02 IST display fix. Pre-fix showed the raw ISO 8601 string
// (server-local Europe/Berlin +02:00); operator works in IST. Convert
// using the embedded offset → Asia/Kolkata.
$generatedAtIst = '—';
if ($generatedAtIso !== '') {
    try {
        $dtSt = new \DateTime($generatedAtIso);
        $dtSt->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $generatedAtIst = $dtSt->format('Y-m-d H:i:s') . ' IST';
    } catch (\Throwable $eSt) {
        $generatedAtIst = $generatedAtIso;
    }
}

$totalSchools = end($onboardedSeries)['totalSchools'] ?? 0;
$activeCount  = array_sum(array_intersect_key($lifecycleDist, array_flip(['active','trialing','expiring_soon','grace'])));
$activePct    = $totalSchools > 0 ? round(($activeCount / $totalSchools) * 100) : 0;
?>

<section class="content-header">
  <h1><i class="fa fa-bar-chart" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Statistics</h1>
  <ol class="breadcrumb">
    <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
    <li class="active">Statistics</li>
  </ol>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<section class="content" style="padding:20px 24px;">

  <!-- Range row -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding:9px 16px;background:var(--sa-dim);border:1px solid var(--sa-ring);border-radius:10px;flex-wrap:wrap;gap:8px;">
    <span style="font-size:12px;color:var(--t3);font-family:var(--font-m);">
      <i class="fa fa-clock-o" style="margin-right:5px;color:var(--sa3);"></i>
      Stats as of <strong style="color:var(--t2);" id="statsGeneratedAt" data-iso="<?= htmlspecialchars($generatedAtIso) ?>"><?= htmlspecialchars($generatedAtIst) ?></strong>
      &nbsp;·&nbsp; Range:
    </span>
    <div style="display:flex;gap:6px;align-items:center;">
      <?php foreach ([3, 6, 12, 24] as $m): ?>
      <a href="<?= base_url('superadmin/dashboard/statistics?months=' . $m) ?>" class="btn btn-default btn-xs" style="font-size:11.5px;padding:5px 12px;<?= $monthsBack === $m ? 'background:var(--sa3);color:#fff;border-color:var(--sa3);' : '' ?>"><?= $m ?> mo</a>
      <?php endforeach; ?>
      <button class="btn btn-primary btn-xs" id="statsRefreshBtn" style="margin-left:8px;font-size:11.5px;padding:5px 12px;">
        <i class="fa fa-refresh" id="statsRefreshIcon"></i> Refresh
      </button>
    </div>
  </div>

  <!-- 4 KPI tiles -->
  <div class="row" style="margin-bottom:24px;">
    <?php
    $tiles = [
        ['label' => 'Net New (30d)',     'val' => number_format($net30),         'icon' => 'fa-plus-circle'],
        ['label' => 'Net New (90d)',     'val' => number_format($net90),         'icon' => 'fa-plus-square'],
        ['label' => 'Avg Tenant Age',    'val' => number_format($avgAge) . ' <span style="font-size:13px;color:var(--t3);">days</span>', 'icon' => 'fa-hourglass-half'],
        ['label' => 'Active %',          'val' => $activePct . '%',              'icon' => 'fa-percent'],
    ];
    foreach ($tiles as $t): ?>
    <div class="col-md-3 col-sm-6" style="margin-bottom:14px;">
      <div class="sa-stat" style="border-radius:10px;">
        <div class="sa-stat-icon purple" style="background:rgba(124,58,237,0.12);color:var(--sa3);"><i class="fa <?= $t['icon'] ?>"></i></div>
        <div style="flex:1;">
          <div class="sa-stat-label" style="font-size:11.5px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;"><?= htmlspecialchars($t['label']) ?></div>
          <div class="sa-stat-val" style="font-size:24px;font-weight:700;color:var(--t1);font-family:var(--font-d);"><?= $t['val'] ?></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Schools Onboarded by Month -->
  <div class="box" style="margin-bottom:18px;">
    <div class="box-header">
      <i class="fa fa-line-chart" style="color:var(--sa3);margin-right:8px;"></i>
      <span class="box-title">Schools Onboarded by Month (last <?= $monthsBack ?>)</span>
    </div>
    <div class="box-body" style="padding:14px 16px;">
      <canvas id="onboardedChart" height="80"></canvas>
    </div>
  </div>

  <!-- Lifecycle + Plan distribution -->
  <div class="row" style="margin-bottom:18px;">
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-pie-chart" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Lifecycle Distribution</span></div>
        <div class="box-body" style="padding:14px 16px;">
          <canvas id="lifecycleChart" height="160"></canvas>
          <p style="font-size:11px;color:var(--t3);margin-top:8px;text-align:center;">Click a slice to view tenants in that state.</p>
        </div>
      </div>
    </div>
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-tags" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Plan Distribution</span></div>
        <div class="box-body" style="padding:14px 16px;">
          <canvas id="planChart" height="160"></canvas>
          <p style="font-size:11px;color:var(--t3);margin-top:8px;text-align:center;">Click a slice to view tenants on that plan.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Students + Staff growth -->
  <div class="row" style="margin-bottom:18px;">
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-users" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Students Growth (<?= $monthsBack ?> mo)</span></div>
        <div class="box-body" style="padding:14px 16px;"><canvas id="studentsGrowthChart" height="100"></canvas></div>
      </div>
    </div>
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-user-md" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Staff Growth (<?= $monthsBack ?> mo)</span></div>
        <div class="box-body" style="padding:14px 16px;"><canvas id="staffGrowthChart" height="100"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Schools by City + Recent Registrations -->
  <div class="row" style="margin-bottom:18px;">
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-map-marker" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Schools by City</span></div>
        <div class="box-body" style="padding:14px 16px;">
          <canvas id="cityChart" height="180"></canvas>
          <p style="font-size:11px;color:var(--t3);margin-top:8px;text-align:center;">Click a bar to view tenants in that city.</p>
        </div>
      </div>
    </div>
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-plus-circle" style="color:var(--green, #22c55e);margin-right:8px;"></i><span class="box-title">Recent Registrations (last 30 days)</span></div>
        <div class="box-body" style="padding:8px 0;">
          <?php if (empty($recentRegs)): ?>
          <div style="padding:14px 16px;color:var(--t3);font-size:12px;text-align:center;">No new tenants in the last 30 days.</div>
          <?php else: ?>
          <?php foreach ($recentRegs as $r): ?>
          <a href="<?= base_url('superadmin/schools/view/' . urlencode((string) ($r['schoolId'] ?? ''))) ?>" style="text-decoration:none;display:block;">
            <div style="padding:8px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center;">
              <div style="flex:1;">
                <div style="font-size:12.5px;color:var(--t1);font-weight:600;"><?= htmlspecialchars((string) ($r['schoolName'] ?? '')) ?></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);">
                  <?= htmlspecialchars((string) ($r['city'] ?? '—')) ?> · onboarded <?= htmlspecialchars(date('d M Y', $r['_ts'] ?? time())) ?>
                </div>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</section>

<!-- Drill-down modal -->
<div id="drilldownModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;">
  <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px;max-width:540px;width:92%;max-height:78vh;display:flex;flex-direction:column;">
    <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:10px;margin-bottom:10px;">
      <h4 id="drilldownTitle" style="margin:0;font-family:var(--font-b);color:var(--t1);font-size:15px;">Drill-down</h4>
      <button class="btn btn-default btn-xs" id="drilldownCloseBtn"><i class="fa fa-times"></i></button>
    </div>
    <div id="drilldownBody" style="overflow-y:auto;font-size:12.5px;color:var(--t1);"></div>
  </div>
</div>

<script>
$(function(){
  var onboardedData = <?= json_encode($onboardedSeries) ?>;
  var studentsData  = <?= json_encode($studentsSeries) ?>;
  var staffData     = <?= json_encode($staffSeries) ?>;
  var lifecycleData = <?= json_encode($lifecycleDist) ?>;
  var planData      = <?= json_encode($planDist) ?>;
  var cityData      = <?= json_encode($cityDist) ?>;

  var palette = ['#7c3aed','#22c55e','#f59e0b','#ef4444','#4ab5e3','#a78bfa','#84cc16','#ec4899'];

  // Schools Onboarded
  new Chart(document.getElementById('onboardedChart'), {
    type: 'bar',
    data: {
      labels: onboardedData.map(d => d.period),
      datasets: [
        { label: 'New Schools', data: onboardedData.map(d => d.newSchoolsCount), backgroundColor: 'rgba(34,197,94,0.6)', borderColor: '#22c55e', borderWidth: 1 },
        { label: 'Total Schools', data: onboardedData.map(d => d.totalSchools), backgroundColor: 'rgba(124,58,237,0.4)', borderColor: '#7c3aed', borderWidth: 1 }
      ]
    },
    options: { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11}}}}, scales:{ x:{ticks:{font:{size:10}}}, y:{beginAtZero:true,ticks:{font:{size:10}}}} }
  });

  // Lifecycle donut (drill-down)
  var lifecycleLabels = Object.keys(lifecycleData);
  var lifecycleValues = lifecycleLabels.map(k => lifecycleData[k]);
  var lifecycleChart = new Chart(document.getElementById('lifecycleChart'), {
    type: 'doughnut',
    data: { labels: lifecycleLabels, datasets: [{ data: lifecycleValues, backgroundColor: palette.slice(0, lifecycleLabels.length) }] },
    options: {
      responsive:true, maintainAspectRatio:true,
      plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11}}}},
      onClick: function(e, els) {
        if (!els.length) return;
        var i = els[0].index;
        openDrilldown('lifecycle', lifecycleLabels[i]);
      }
    }
  });

  // Plan donut (drill-down)
  var planLabels = Object.keys(planData);
  var planValues = planLabels.map(k => planData[k]);
  new Chart(document.getElementById('planChart'), {
    type: 'doughnut',
    data: { labels: planLabels, datasets: [{ data: planValues, backgroundColor: palette.slice(0, planLabels.length) }] },
    options: {
      responsive:true, maintainAspectRatio:true,
      plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11}}}},
      onClick: function(e, els) {
        if (!els.length) return;
        var i = els[0].index;
        openDrilldown('plan', planLabels[i]);
      }
    }
  });

  // Students growth
  new Chart(document.getElementById('studentsGrowthChart'), {
    type: 'line',
    data: {
      labels: studentsData.map(d => d.period),
      datasets: [{ label: 'Total Students', data: studentsData.map(d => d.totalStudents), borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.10)', tension: 0.3, fill: true }]
    },
    options: { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{ x:{ticks:{font:{size:10}}}, y:{beginAtZero:true,ticks:{font:{size:10}}}} }
  });

  // Staff growth
  new Chart(document.getElementById('staffGrowthChart'), {
    type: 'line',
    data: {
      labels: staffData.map(d => d.period),
      datasets: [{ label: 'Total Staff', data: staffData.map(d => d.totalStaff), borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,0.10)', tension: 0.3, fill: true }]
    },
    options: { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:false}}, scales:{ x:{ticks:{font:{size:10}}}, y:{beginAtZero:true,ticks:{font:{size:10}}}} }
  });

  // Schools by city (horizontal bar) — drill-down on click
  var cityLabels = Object.keys(cityData);
  var cityValues = cityLabels.map(k => cityData[k]);
  new Chart(document.getElementById('cityChart'), {
    type: 'bar',
    data: { labels: cityLabels, datasets: [{ label: 'Schools', data: cityValues, backgroundColor: palette.slice(0, cityLabels.length) }] },
    options: {
      indexAxis: 'y', responsive:true, maintainAspectRatio:true,
      plugins:{legend:{display:false}},
      scales:{ x:{beginAtZero:true,ticks:{font:{size:10}}}, y:{ticks:{font:{size:11}}} },
      onClick: function(e, els) {
        if (!els.length) return;
        var i = els[0].index;
        if (cityLabels[i] && cityLabels[i] !== '— Unspecified') openDrilldown('city', cityLabels[i]);
      }
    }
  });

  // Refresh
  $('#statsRefreshBtn').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $('#statsRefreshIcon').addClass('fa-spin');
    location.reload();
  });

  // ── Drill-down modal ──
  function openDrilldown(type, value) {
    $('#drilldownTitle').text(type.charAt(0).toUpperCase() + type.slice(1) + ': ' + value);
    $('#drilldownBody').html('<div style="padding:20px;text-align:center;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading…</div>');
    $('#drilldownModal').css('display', 'flex');
    $.get(BASE_URL + 'superadmin/dashboard/drilldown', { type: type, value: value }, function(r) {
      if (r.status !== 'success' || !Array.isArray(r.rows)) {
        $('#drilldownBody').html('<div style="padding:20px;text-align:center;color:var(--rose, #ef4444);">' + (r.message || 'Failed to load') + '</div>');
        return;
      }
      if (r.rows.length === 0) {
        $('#drilldownBody').html('<div style="padding:20px;text-align:center;color:var(--t3);">No tenants match.</div>');
        return;
      }
      var html = '<div style="margin-bottom:10px;color:var(--t3);font-size:11px;font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;">' + r.rows.length + ' tenant' + (r.rows.length === 1 ? '' : 's') + '</div>';
      r.rows.forEach(function(t) {
        html += '<a href="' + BASE_URL + 'superadmin/schools/view/' + encodeURIComponent(t.schoolId || '') + '" style="text-decoration:none;color:var(--t1);display:block;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;">';
        html += '<div style="font-weight:600;font-size:12.5px;">' + escHtml(t.schoolName || t.schoolId || '') + '</div>';
        html += '<div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-top:3px;">';
        html += escHtml((t.schoolCode || '?') + ' · ' + (t.city || '—') + ' · ' + (t.lifecycleState || '—') + ' · ' + (t.totalStudents || 0) + ' students');
        html += '</div></a>';
      });
      $('#drilldownBody').html(html);
    }, 'json').fail(function() {
      $('#drilldownBody').html('<div style="padding:20px;text-align:center;color:var(--rose, #ef4444);">Network error</div>');
    });
  }

  $('#drilldownCloseBtn').on('click', function(){ $('#drilldownModal').hide(); });
  $('#drilldownModal').on('click', function(e) {
    if (e.target.id === 'drilldownModal') $(this).hide();
  });

  function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
});
</script>
