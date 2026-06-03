<?php
/**
 * B2.3.4-A Phase 1B — Super Admin Dashboard Hub.
 *
 * Locked design baseline: docs/design/B2_3_4_A_Analytics_Design_Locked.md
 * Q-A1 (12-month default), Q-A2 (high-signal feed), Q-A3 (active=H1),
 * Q-A4 (MRR includes trialing), Q-A5 (D/W/M selector), Q-A6 (ZenXii palette),
 * Q-A7 (Firestore saved searches), Q-A8 (CSV+Excel), Q-A9 (KPI→spoke nav),
 * Q-A10 (alerts persist until resolved).
 */
$summary         = $summary         ?? [];
$recent_activity = $recent_activity ?? [];
$expiry_alerts   = $expiry_alerts   ?? [];
$hub             = $hub             ?? [];

// Headline KPIs sourced from B2_analytics_service (Phase 1B canonical).
// Falls back to the legacy $summary array if analytics service didn't
// initialise (defensive — see Superadmin::dashboard error path).
$kpi = is_array($hub['kpi'] ?? null) ? $hub['kpi'] : [
    'total_schools' => $summary['total_schools'] ?? 0,
    'active_schools' => $summary['active_schools'] ?? 0,
    'total_students' => $summary['total_students'] ?? 0,
    'total_staff'    => $summary['total_staff']    ?? 0,
    'mrr'            => 0,
    'active_subscriptions' => 0,
];

$alerts          = is_array($hub['alerts'] ?? null) ? $hub['alerts'] : [];
$top_schools     = is_array($hub['top_schools'] ?? null) ? $hub['top_schools'] : [];
$expiring_soon   = is_array($hub['expiring_soon'] ?? null) ? $hub['expiring_soon'] : [];
$feed_activity   = is_array($hub['recent_activity'] ?? null) ? $hub['recent_activity'] : [];
$saved_searches  = is_array($hub['saved_searches'] ?? null) ? $hub['saved_searches'] : [];
$ts_growth       = is_array($hub['time_series']['schools_growth'] ?? null) ? $hub['time_series']['schools_growth'] : [];
$ts_revenue      = is_array($hub['time_series']['revenue'] ?? null) ? $hub['time_series']['revenue'] : [];
$generated_at_iso = (string) ($hub['generated_at'] ?? '');
// 2026-06-02 IST display fix. Pre-fix showed the raw ISO 8601 string
// (server-local Europe/Berlin +02:00); operator works in IST. Convert
// using the embedded offset → Asia/Kolkata.
$generated_at_ist = '—';
if ($generated_at_iso !== '') {
    try {
        $dtHub = new \DateTime($generated_at_iso);
        $dtHub->setTimezone(new \DateTimeZone('Asia/Kolkata'));
        $generated_at_ist = $dtHub->format('Y-m-d H:i:s') . ' IST';
    } catch (\Throwable $eHub) {
        $generated_at_ist = $generated_at_iso; // best-effort fallback
    }
}

$active_pct = ($kpi['total_schools'] ?? 0) > 0
    ? round(($kpi['active_schools'] / $kpi['total_schools']) * 100)
    : 0;
?>

<section class="content-header">
    <h1><i class="fa fa-th-large" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Dashboard</h1>
    <ol class="breadcrumb">
        <li class="active">Super Admin Overview</li>
    </ol>
</section>

<!-- Chart.js 4.4 (also loaded by Reports spoke; idempotent) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<section class="content" style="padding:20px 24px;">

  <!-- ═══ Refresh row + generated timestamp ═══ -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding:9px 16px;background:var(--sa-dim);border:1px solid var(--sa-ring);border-radius:10px;flex-wrap:wrap;gap:8px;">
    <span style="font-size:12px;color:var(--t3);font-family:var(--font-m);">
      <i class="fa fa-clock-o" style="margin-right:5px;color:var(--sa3);"></i>
      Hub data as of <strong id="hubGeneratedAt" style="color:var(--t2);" data-iso="<?= htmlspecialchars($generated_at_iso) ?>"><?= htmlspecialchars($generated_at_ist) ?></strong>
    </span>
    <button class="btn btn-primary btn-sm" id="hubRefreshBtn" style="font-size:11.5px;padding:5px 14px;">
      <i class="fa fa-refresh" id="hubRefreshIcon"></i> Refresh Hub
    </button>
  </div>

  <!-- ═══ ALERT BANNER (Q-A10 persist until resolved) ═══ -->
  <div id="alertBanner" style="margin-bottom:18px;<?= empty($alerts) ? 'display:none;' : '' ?>">
    <?php if (!empty($alerts)): ?>
    <div style="padding:10px 14px;background:rgba(245,158,11,0.08);border:1px solid var(--amber, #f59e0b);border-left:3px solid var(--amber, #f59e0b);border-radius:8px;font-size:12.5px;color:var(--t1);">
      <i class="fa fa-exclamation-triangle" style="color:var(--amber, #f59e0b);margin-right:6px;"></i>
      <strong><?= count($alerts) ?> open alert<?= count($alerts) === 1 ? '' : 's' ?></strong>
      <span style="margin-left:10px;color:var(--t3);">
        <?php
        $counts = [];
        foreach ($alerts as $a) {
            $t = (string) ($a['alertType'] ?? 'unknown');
            $counts[$t] = ($counts[$t] ?? 0) + 1;
        }
        $bits = [];
        foreach ($counts as $t => $n) $bits[] = "{$n} " . str_replace('_', ' ', $t);
        echo htmlspecialchars(implode(' · ', $bits));
        ?>
      </span>
      <span style="float:right;">
        <button class="btn btn-default btn-xs" id="alertsRegenerateBtn"><i class="fa fa-repeat"></i> Re-scan</button>
        <button class="btn btn-default btn-xs" id="alertsExpandBtn"><i class="fa fa-list-ul"></i> View</button>
      </span>
      <div id="alertsExpandBody" style="display:none;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
        <?php foreach ($alerts as $a): ?>
        <div data-alert-id="<?= htmlspecialchars((string) ($a['alertId'] ?? '')) ?>" style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:12px;">
          <?php
          $sev = (string) ($a['severity'] ?? 'info');
          $sevColor = ['info' => 'var(--sa3)', 'warn' => 'var(--amber, #f59e0b)', 'critical' => 'var(--rose, #ef4444)'][$sev] ?? 'var(--t3)';
          ?>
          <i class="fa fa-circle" style="color:<?= $sevColor ?>;font-size:8px;"></i>
          <span style="flex:1;"><strong><?= htmlspecialchars(str_replace('_', ' ', (string) ($a['alertType'] ?? ''))) ?></strong>
            <?php $tgt = $a['affectedTenants'][0] ?? ''; ?>
            <?php if ($tgt !== ''): ?> — <a href="<?= base_url('superadmin/schools/view/' . urlencode($tgt)) ?>" style="color:var(--sa3);"><?= htmlspecialchars($tgt) ?></a><?php endif; ?>
          </span>
          <button class="btn btn-default btn-xs sa-alert-dismiss" data-alert-id="<?= htmlspecialchars((string) ($a['alertId'] ?? '')) ?>"><i class="fa fa-check"></i> Dismiss</button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ═══ 6 KPI tiles (Q-A9 click → spoke) ═══ -->
  <div class="row" style="margin-bottom:24px;">
    <?php
    $tiles = [
        ['label' => 'Total Schools',         'val' => number_format($kpi['total_schools']),         'icon' => 'fa-building',     'href' => 'superadmin/schools'],
        ['label' => 'Active Schools',        'val' => number_format($kpi['active_schools']) . " <span style='font-size:12px;color:var(--t3);'>({$active_pct}%)</span>", 'icon' => 'fa-check-circle', 'href' => 'superadmin/dashboard/schools-search?state=active'],
        ['label' => 'Total Students',        'val' => number_format($kpi['total_students']),        'icon' => 'fa-users',        'href' => 'superadmin/dashboard/cross-school'],
        ['label' => 'Total Staff',           'val' => number_format($kpi['total_staff']),           'icon' => 'fa-user-md',      'href' => 'superadmin/dashboard/cross-school'],
        ['label' => 'MRR',                   'val' => '₹' . number_format($kpi['mrr'], 0, '.', ','), 'icon' => 'fa-inr',          'href' => 'superadmin/dashboard/revenue'],
        ['label' => 'Active Subscriptions',  'val' => number_format($kpi['active_subscriptions']),  'icon' => 'fa-id-card-o',    'href' => 'superadmin/dashboard/revenue'],
    ];
    foreach ($tiles as $t): ?>
    <div class="col-md-4 col-sm-6" style="margin-bottom:14px;">
      <a href="<?= base_url($t['href']) ?>" style="text-decoration:none;display:block;">
        <div class="sa-stat" style="border-radius:10px;cursor:pointer;transition:transform .15s, border-color .15s;" onmouseover="this.style.transform='translateY(-2px)';this.style.borderColor='var(--sa3)';" onmouseout="this.style.transform='';this.style.borderColor='';">
          <div class="sa-stat-icon purple" style="background:rgba(124,58,237,0.12);color:var(--sa3);">
            <i class="fa <?= $t['icon'] ?>"></i>
          </div>
          <div style="flex:1;">
            <div class="sa-stat-label" style="font-size:11.5px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;"><?= htmlspecialchars($t['label']) ?></div>
            <div class="sa-stat-val" style="font-size:24px;font-weight:700;color:var(--t1);font-family:var(--font-d);"><?= $t['val'] ?></div>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══ 2 time-series charts ═══ -->
  <div class="row" style="margin-bottom:24px;">
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-line-chart" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">School Growth (last 12 months)</span></div>
        <div class="box-body" style="padding:14px 16px;">
          <canvas id="schoolGrowthChart" height="120"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-6" style="margin-bottom:14px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-money" style="color:var(--green, #22c55e);margin-right:8px;"></i><span class="box-title">Revenue Trend (last 12 months)</span></div>
        <div class="box-body" style="padding:14px 16px;">
          <canvas id="revenueTrendChart" height="120"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══ Quick search + saved filter pills ═══ -->
  <div class="box" style="margin-bottom:24px;">
    <div class="box-body" style="padding:14px 16px;">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="position:relative;flex:1;min-width:240px;">
          <input type="text" id="hubQuickSearch" class="form-control" placeholder="Quick search by school name, code, or city..." autocomplete="off" style="height:38px;padding-left:34px;">
          <i class="fa fa-search" style="position:absolute;left:12px;top:12px;color:var(--t3);"></i>
          <div id="hubQuickSearchDropdown" style="position:absolute;top:100%;left:0;right:0;z-index:9000;display:none;background:var(--bg2);border:1px solid var(--border);border-top:none;border-radius:0 0 8px 8px;max-height:280px;overflow-y:auto;"></div>
        </div>
        <a href="<?= base_url('superadmin/dashboard/schools-search') ?>" class="btn btn-default btn-sm" style="font-size:12px;">
          <i class="fa fa-sliders"></i> Advanced
        </a>
      </div>
      <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
        <span style="font-size:11px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;">Saved:</span>
        <a href="<?= base_url('superadmin/dashboard/schools-search?state=active&plan=Premium') ?>" class="btn btn-default btn-xs">Active + Premium</a>
        <a href="<?= base_url('superadmin/dashboard/schools-search?expiring=30') ?>" class="btn btn-default btn-xs">Expiring 30d</a>
        <a href="<?= base_url('superadmin/dashboard/schools-search?state=past_due') ?>" class="btn btn-default btn-xs">Past Due</a>
        <a href="<?= base_url('superadmin/dashboard/schools-search?plan=Free') ?>" class="btn btn-default btn-xs">Free Tier</a>
        <span id="userSavedPills" style="display:flex;gap:6px;flex-wrap:wrap;">
          <?php foreach ($saved_searches as $ss): ?>
          <a href="<?= base_url('superadmin/dashboard/schools-search?saved=' . urlencode((string) ($ss['slug'] ?? ''))) ?>" class="btn btn-default btn-xs" style="background:var(--sa-dim);border-color:var(--sa-ring);">
            <?php if (!empty($ss['isPinned'])): ?><i class="fa fa-thumb-tack"></i> <?php endif; ?>
            <?= htmlspecialchars((string) ($ss['name'] ?? '')) ?>
          </a>
          <?php endforeach; ?>
        </span>
      </div>
    </div>
  </div>

  <!-- ═══ 3 widgets row ═══ -->
  <div class="row">

    <!-- Recent Activity (Q-A2 high-signal only) -->
    <div class="col-md-4" style="margin-bottom:18px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-history" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Recent Activity</span></div>
        <div class="box-body" style="padding:8px 0;">
          <?php if (empty($feed_activity)): ?>
          <div style="padding:14px 16px;color:var(--t3);font-size:12px;text-align:center;">No recent high-signal activity.</div>
          <?php else: ?>
          <?php foreach ($feed_activity as $a): ?>
          <div style="padding:8px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-start;">
            <div style="width:30px;flex-shrink:0;">
              <?php
              $iconMap = [
                  'b2_onboard' => 'fa-plus-circle',
                  'b2_lifecycle_transition' => 'fa-exchange',
                  'b2_admin_toggle' => 'fa-toggle-on',
                  'b2_plan_change' => 'fa-tags',
                  'b2_payment_received' => 'fa-money',
                  'b2_payment_failed' => 'fa-times-circle',
                  'b2_refund' => 'fa-undo',
              ];
              $action = (string) ($a['action'] ?? '');
              $icon = $iconMap[$action] ?? 'fa-circle';
              ?>
              <i class="fa <?= $icon ?>" style="color:var(--sa3);font-size:14px;"></i>
            </div>
            <div style="flex:1;">
              <div style="font-size:12.5px;color:var(--t1);"><?= htmlspecialchars(str_replace('_', ' ', $action)) ?></div>
              <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);">
                <?php
                $sid = (string) ($a['schoolId'] ?? '');
                $ts = (string) ($a['ts'] ?? '');
                $tsHuman = $ts !== '' ? date('d M H:i', strtotime($ts)) : '—';
                echo htmlspecialchars($sid . ' · ' . $tsHuman);
                ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Top Schools by Students -->
    <div class="col-md-4" style="margin-bottom:18px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-trophy" style="color:var(--sa3);margin-right:8px;"></i><span class="box-title">Top Schools</span></div>
        <div class="box-body" style="padding:8px 0;">
          <?php if (empty($top_schools)): ?>
          <div style="padding:14px 16px;color:var(--t3);font-size:12px;text-align:center;">No tenants yet.</div>
          <?php else: ?>
          <?php foreach ($top_schools as $i => $t): ?>
          <?php // Phase 1H H1.P1.a: repointed from legacy /superadmin/schools/view/{id}
                // to Phase 1G Per-Tenant Deep Dive. Phase 1H H1.P0.a: badge
                // override when adminDisabled (red dot dominant). ?>
          <a href="<?= base_url('superadmin/dashboard/tenant/' . urlencode((string) ($t['schoolId'] ?? ''))) ?>" style="text-decoration:none;display:block;">
            <div style="padding:8px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center;">
              <div style="width:24px;height:24px;background:var(--sa-dim);border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--sa3);font-weight:700;font-size:11px;flex-shrink:0;"><?= $i + 1 ?></div>
              <div style="flex:1;">
                <div style="font-size:12.5px;color:var(--t1);font-weight:600;">
                  <?= htmlspecialchars((string) ($t['schoolName'] ?? $t['schoolId'] ?? '')) ?>
                  <?php if (!empty($t['adminDisabled'])): ?>
                    <span style="display:inline-block;font-size:9px;font-weight:600;padding:1px 5px;border-radius:9px;background:#fee2e2;color:#991b1b;text-transform:uppercase;letter-spacing:.3px;margin-left:4px;" title="underlying lifecycle: <?= htmlspecialchars((string) ($t['lifecycleState'] ?? '')) ?>">disabled</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);"><?= number_format((int) ($t['totalStudents'] ?? 0)) ?> students · <?= number_format((int) ($t['totalStaff'] ?? 0)) ?> staff</div>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Expiring Soon -->
    <div class="col-md-4" style="margin-bottom:18px;">
      <div class="box">
        <div class="box-header"><i class="fa fa-calendar-times-o" style="color:var(--amber, #f59e0b);margin-right:8px;"></i><span class="box-title">Expiring Soon (30 days)</span></div>
        <div class="box-body" style="padding:8px 0;">
          <?php if (empty($expiring_soon)): ?>
          <div style="padding:14px 16px;color:var(--t3);font-size:12px;text-align:center;">No tenants expiring in the next 30 days.</div>
          <?php else: ?>
          <?php foreach ($expiring_soon as $t): ?>
          <?php // Phase 1H H1.P1.a: repointed to Phase 1G Tenant Detail. ?>
          <a href="<?= base_url('superadmin/dashboard/tenant/' . urlencode((string) ($t['schoolId'] ?? ''))) ?>" style="text-decoration:none;display:block;">
            <div style="padding:8px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:center;">
              <div style="flex:1;">
                <div style="font-size:12.5px;color:var(--t1);font-weight:600;">
                  <?= htmlspecialchars((string) ($t['schoolName'] ?? $t['schoolId'] ?? '')) ?>
                  <?php if (!empty($t['adminDisabled'])): ?>
                    <span style="display:inline-block;font-size:9px;font-weight:600;padding:1px 5px;border-radius:9px;background:#fee2e2;color:#991b1b;text-transform:uppercase;letter-spacing:.3px;margin-left:4px;">disabled</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);">
                  <?php
                  $end = (string) ($t['subscriptionPeriodEnd'] ?? '');
                  $endTs = $end !== '' ? strtotime($end) : 0;
                  $days = $endTs > 0 ? max(0, (int) ceil(($endTs - time()) / 86400)) : 0;
                  echo htmlspecialchars($end . ' · in ' . $days . ' day' . ($days === 1 ? '' : 's'));
                  ?>
                </div>
              </div>
              <span class="label" style="background:var(--amber, #f59e0b);color:#fff;font-size:10px;font-weight:600;"><?= $days ?>d</span>
            </div>
          </a>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>

  <!-- ═══ Navigation to spokes ═══ -->
  <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:16px;">
    <a href="<?= base_url('superadmin/dashboard/statistics') ?>" class="btn btn-default"><i class="fa fa-bar-chart"></i> Statistics</a>
    <a href="<?= base_url('superadmin/dashboard/schools-search') ?>" class="btn btn-default"><i class="fa fa-search"></i> School Search</a>
    <a href="<?= base_url('superadmin/dashboard/revenue') ?>" class="btn btn-default"><i class="fa fa-line-chart"></i> Revenue Reports</a>
    <a href="<?= base_url('superadmin/dashboard/cross-school') ?>" class="btn btn-default"><i class="fa fa-th"></i> Cross-School</a>
    <a href="<?= base_url('superadmin/reports') ?>" class="btn btn-default"><i class="fa fa-history"></i> Activity Reports</a>
  </div>

</section>

<script>
$(function(){
  // ── Phase 1B Hub interactivity ─────────────────────────────────

  // ── Time-series charts ──
  var gradient = function(ctx, color, alpha) {
    var grad = ctx.createLinearGradient(0, 0, 0, 200);
    grad.addColorStop(0, color);
    grad.addColorStop(1, 'rgba(124,58,237,0)');
    return grad;
  };

  var growthData = <?= json_encode($ts_growth) ?>;
  var revenueData = <?= json_encode($ts_revenue) ?>;

  var growthCtx = document.getElementById('schoolGrowthChart');
  var growthChart = null;
  if (growthCtx) {
    growthChart = new Chart(growthCtx, {
      type: 'bar',
      data: {
        labels: growthData.map(d => d.period),
        datasets: [
          { label: 'Total Schools', data: growthData.map(d => d.totalSchools), backgroundColor: 'rgba(124,58,237,0.6)', borderColor: '#7c3aed', borderWidth: 1 },
          { label: 'New Schools',  data: growthData.map(d => d.newSchoolsCount), backgroundColor: 'rgba(34,197,94,0.5)', borderColor: '#22c55e', borderWidth: 1 }
        ]
      },
      options: { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11}}}}, scales:{ x:{ticks:{font:{size:10}}}, y:{beginAtZero:true,ticks:{font:{size:10}}}} }
    });
  }

  var revCtx = document.getElementById('revenueTrendChart');
  var revChart = null;
  if (revCtx) {
    revChart = new Chart(revCtx, {
      type: 'line',
      data: {
        labels: revenueData.map(d => d.period),
        datasets: [
          { label: 'MRR (₹)', data: revenueData.map(d => d.mrr), borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,0.15)', tension: 0.3, fill: true },
          { label: 'Revenue Collected (₹)', data: revenueData.map(d => d.totalRevenue), borderColor: '#7c3aed', backgroundColor: 'rgba(124,58,237,0.10)', tension: 0.3, fill: false }
        ]
      },
      options: { responsive:true, maintainAspectRatio:true, plugins:{legend:{display:true,position:'bottom',labels:{font:{size:11}}}}, scales:{ x:{ticks:{font:{size:10}}}, y:{beginAtZero:true,ticks:{font:{size:10}}}} }
    });
  }

  // ── Refresh Hub via AJAX ──
  $('#hubRefreshBtn').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $('#hubRefreshIcon').addClass('fa-spin');
    $.ajax({
      url: BASE_URL + 'superadmin/dashboard/hub_data',
      type: 'GET',
      success: function(r){
        if (r.status !== 'success') { saToast(r.message || 'Refresh failed.', 'error'); return; }
        // Reload page to re-render Hub HTML (simpler than client-side rebuild for Phase 1B).
        location.reload();
      },
      error: function(){ saToast('Refresh failed.', 'error'); },
      complete: function(){ $btn.prop('disabled', false); $('#hubRefreshIcon').removeClass('fa-spin'); }
    });
  });

  // ── Alert banner controls ──
  $('#alertsExpandBtn').on('click', function(){
    $('#alertsExpandBody').slideToggle(150);
  });

  $('#alertsRegenerateBtn').on('click', function(){
    var $btn = $(this).prop('disabled', true);
    $.post(BASE_URL + 'superadmin/dashboard/alerts_regenerate', {}, function(r){
      if (r.status === 'success') {
        saToast('Re-scanned: ' + (r.generated||0) + ' new, ' + (r.skipped||0) + ' existing.', 'info');
        setTimeout(function(){ location.reload(); }, 800);
      } else { saToast(r.message || 'Re-scan failed.', 'error'); }
      $btn.prop('disabled', false);
    }, 'json').fail(function(){ saToast('Re-scan failed.', 'error'); $btn.prop('disabled', false); });
  });

  $(document).on('click', '.sa-alert-dismiss', function(){
    var alertId = $(this).data('alert-id');
    var $row = $(this).closest('[data-alert-id]');
    if (!alertId) return;
    $.post(BASE_URL + 'superadmin/dashboard/alert_dismiss', { alert_id: alertId }, function(r){
      if (r.status === 'success') {
        $row.fadeOut(200, function(){ $row.remove(); });
        saToast('Alert dismissed.', 'success');
      } else { saToast(r.message || 'Dismiss failed.', 'error'); }
    }, 'json').fail(function(){ saToast('Dismiss failed.', 'error'); });
  });

  // ── Quick search type-ahead ──
  var quickSearchTimer = null;
  var $quickInput = $('#hubQuickSearch');
  var $quickDropdown = $('#hubQuickSearchDropdown');
  $quickInput.on('input', function(){
    var q = $.trim($(this).val());
    clearTimeout(quickSearchTimer);
    if (q.length < 2) { $quickDropdown.hide().empty(); return; }
    quickSearchTimer = setTimeout(function(){
      $.get(BASE_URL + 'superadmin/dashboard/quick_search', { q: q }, function(r){
        if (r.status !== 'success' || !Array.isArray(r.rows)) { $quickDropdown.hide(); return; }
        if (r.rows.length === 0) {
          $quickDropdown.html('<div style="padding:10px 14px;font-size:12px;color:var(--t3);">No matches for "' + escHtml(q) + '"</div>').show();
          return;
        }
        var html = '';
        r.rows.forEach(function(t){
          // Phase 1H H1.P1.a: repointed to Phase 1G Tenant Detail.
          html += '<a href="' + BASE_URL + 'superadmin/dashboard/tenant/' + encodeURIComponent(t.schoolId || '') + '" style="display:block;padding:9px 14px;border-bottom:1px solid var(--border);text-decoration:none;color:var(--t1);">';
          html += '<div style="font-size:12.5px;font-weight:600;">' + escHtml(t.schoolName || t.schoolId || '') + '</div>';
          html += '<div style="font-size:11px;color:var(--t3);font-family:var(--font-m);">' + escHtml((t.schoolCode || '?') + ' · ' + (t.city || '—') + ' · ' + (t.lifecycleState || '—')) + '</div>';
          html += '</a>';
        });
        $quickDropdown.html(html).show();
      }, 'json').fail(function(){ $quickDropdown.hide(); });
    }, 300);
  });
  $(document).on('click', function(e){
    if (!$(e.target).closest('#hubQuickSearch, #hubQuickSearchDropdown').length) $quickDropdown.hide();
  });
  function escHtml(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

});
</script>
