<?php
$summary         = $summary         ?? [];
$recent_activity = $recent_activity ?? [];
$expiry_alerts   = $expiry_alerts   ?? [];

$total_schools   = $summary['total_schools']   ?? 0;
$active_schools  = $summary['active_schools']  ?? 0;
$total_students  = $summary['total_students']  ?? 0;
$total_staff     = $summary['total_staff']     ?? 0;
$total_revenue   = $summary['total_revenue']   ?? 0;
$recent_regs     = $summary['recent_regs']     ?? 0;
$last_refreshed_raw = $summary['last_refreshed'] ?? '';
// Format for display: parse ISO string from Firebase → human-readable local date/time
if ($last_refreshed_raw && ($ts = strtotime($last_refreshed_raw)) !== false) {
    $last_refreshed = date('d/m/Y, H:i:s', $ts);
} else {
    $last_refreshed = 'Never';
}

$active_pct = $total_schools > 0 ? round(($active_schools / $total_schools) * 100) : 0;
?>

<!-- Page Header -->
<section class="content-header">
    <h1><i class="fa fa-th-large" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Dashboard</h1>
    <ol class="breadcrumb">
        <li class="active">Super Admin Overview</li>
    </ol>
</section>

<!-- Chart.js 4.4 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<section class="content" style="padding:20px 24px;">

    <!-- Refresh Banner -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:10px 16px;background:var(--sa-dim);border:1px solid var(--sa-ring);border-radius:10px;flex-wrap:wrap;gap:8px;">
        <span style="font-size:12px;color:var(--t3);font-family:var(--font-m);">
            <i class="fa fa-clock-o" style="margin-right:5px;color:var(--sa3);"></i>
            Stats last refreshed: <strong id="lastRefreshed" style="color:var(--t2);"><?= htmlspecialchars($last_refreshed) ?></strong>
        </span>
        <button class="btn btn-primary btn-sm" id="refreshStatsBtn" style="font-size:11.5px;padding:5px 14px;">
            <i class="fa fa-refresh" id="refreshIcon"></i> Refresh Stats
        </button>
    </div>

    <!-- ── KPI Cards (6) ── -->
    <div class="row" style="margin-bottom:24px;">

        <!-- Total Schools -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 16px;position:relative;overflow:hidden;">
                <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;border-radius:50%;background:rgba(139,92,246,.10);"></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Total Schools</div>
                <div id="statTotalSchools" style="font-size:26px;font-weight:800;color:var(--t1);font-family:var(--font-d);line-height:1;"><?= number_format($total_schools) ?></div>
                <div style="margin-top:8px;font-size:10.5px;color:var(--t4);">
                    <i class="fa fa-building" style="color:#8b5cf6;margin-right:3px;"></i> All time
                </div>
            </div>
        </div>

        <!-- Active Schools -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 16px;position:relative;overflow:hidden;">
                <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;border-radius:50%;background:rgba(34,197,94,.10);"></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Active Schools</div>
                <div id="statActiveSchools" style="font-size:26px;font-weight:800;color:var(--t1);font-family:var(--font-d);line-height:1;"><?= number_format($active_schools) ?></div>
                <div style="margin-top:8px;font-size:10.5px;color:var(--t4);">
                    <i class="fa fa-check-circle" style="color:#22c55e;margin-right:3px;"></i>
                    <span id="statActivePct"><?= $active_pct ?>%</span> of total
                </div>
            </div>
        </div>

        <!-- Total Students -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 16px;position:relative;overflow:hidden;">
                <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;border-radius:50%;background:rgba(59,130,246,.10);"></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Total Students</div>
                <div id="statTotalStudents" style="font-size:26px;font-weight:800;color:var(--t1);font-family:var(--font-d);line-height:1;"><?= number_format($total_students) ?></div>
                <div style="margin-top:8px;font-size:10.5px;color:var(--t4);">
                    <i class="fa fa-users" style="color:#3b82f6;margin-right:3px;"></i> Across all schools
                </div>
            </div>
        </div>

        <!-- Total Teachers -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 16px;position:relative;overflow:hidden;">
                <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;border-radius:50%;background:rgba(20,184,166,.10);"></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Total Teachers</div>
                <div id="statTotalStaff" style="font-size:26px;font-weight:800;color:var(--t1);font-family:var(--font-d);line-height:1;"><?= number_format($total_staff) ?></div>
                <div style="margin-top:8px;font-size:10.5px;color:var(--t4);">
                    <i class="fa fa-user-tie" style="color:#14b8a6;margin-right:3px;"></i> Staff across schools
                </div>
            </div>
        </div>

        <!-- Total Revenue -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 16px;position:relative;overflow:hidden;">
                <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;border-radius:50%;background:rgba(245,158,11,.10);"></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">Total Revenue</div>
                <div id="statRevenue" style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);line-height:1;">₹<?= number_format($total_revenue) ?></div>
                <div style="margin-top:8px;font-size:10.5px;color:var(--t4);">
                    <i class="fa fa-money" style="color:#f59e0b;margin-right:3px;"></i> Paid payments
                </div>
            </div>
        </div>

        <!-- New Schools (30d) -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:18px 16px;position:relative;overflow:hidden;">
                <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;border-radius:50%;background:rgba(99,102,241,.10);"></div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;">New Schools</div>
                <div id="statRecentRegs" style="font-size:26px;font-weight:800;color:var(--t1);font-family:var(--font-d);line-height:1;"><?= number_format($recent_regs) ?></div>
                <div style="margin-top:8px;font-size:10.5px;color:var(--t4);">
                    <i class="fa fa-calendar-plus-o" style="color:#6366f1;margin-right:3px;"></i> Last 30 days
                </div>
            </div>
        </div>

    </div><!-- /.row KPIs -->

    <!-- ── Charts Row 1 ── -->
    <div class="row" style="margin-bottom:24px;">

        <!-- School Status Doughnut -->
        <div class="col-md-4" style="margin-bottom:20px;">
            <div class="box" style="margin-bottom:0;height:100%;">
                <div class="box-header">
                    <i class="fa fa-pie-chart" style="color:var(--sa3);margin-right:8px;"></i>
                    <span class="box-title">School Status Distribution</span>
                </div>
                <div class="box-body" style="position:relative;display:flex;align-items:center;justify-content:center;min-height:240px;">
                    <div style="position:relative;width:200px;height:200px;">
                        <canvas id="statusChart" width="200" height="200"></canvas>
                        <div id="statusChartCenter" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;">
                            <div id="statusChartTotal" style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                            <div style="font-size:10px;color:var(--t3);font-family:var(--font-m);">Schools</div>
                        </div>
                    </div>
                </div>
                <div class="box-footer" id="statusLegend" style="display:flex;flex-wrap:wrap;gap:6px;padding:10px 16px;"></div>
            </div>
        </div>

        <!-- Revenue Trend Bar -->
        <div class="col-md-8" style="margin-bottom:20px;">
            <div class="box" style="margin-bottom:0;height:100%;">
                <div class="box-header">
                    <i class="fa fa-bar-chart" style="color:var(--sa3);margin-right:8px;"></i>
                    <span class="box-title">Revenue Trend (Last 6 Months)</span>
                </div>
                <div class="box-body" style="min-height:240px;display:flex;align-items:center;">
                    <canvas id="revenueChart" style="width:100%;max-height:220px;"></canvas>
                </div>
            </div>
        </div>

    </div><!-- /.row charts1 -->

    <!-- ── Charts Row 2 ── -->
    <div class="row" style="margin-bottom:24px;">

        <!-- Plan Distribution Doughnut -->
        <div class="col-md-4" style="margin-bottom:20px;">
            <div class="box" style="margin-bottom:0;height:100%;">
                <div class="box-header">
                    <i class="fa fa-tags" style="color:var(--sa3);margin-right:8px;"></i>
                    <span class="box-title">Plan Distribution</span>
                </div>
                <div class="box-body" style="display:flex;align-items:center;justify-content:center;min-height:220px;">
                    <div style="width:180px;height:180px;">
                        <canvas id="planChart" width="180" height="180"></canvas>
                    </div>
                </div>
                <div class="box-footer" id="planLegend" style="display:flex;flex-wrap:wrap;gap:6px;padding:10px 16px;"></div>
            </div>
        </div>

        <!-- Top Schools by Students -->
        <div class="col-md-8" style="margin-bottom:20px;">
            <div class="box" style="margin-bottom:0;height:100%;">
                <div class="box-header">
                    <i class="fa fa-trophy" style="color:var(--sa3);margin-right:8px;"></i>
                    <span class="box-title">Top Schools by Students</span>
                </div>
                <div class="box-body" style="min-height:220px;display:flex;align-items:center;">
                    <canvas id="topSchoolsChart" style="width:100%;max-height:200px;"></canvas>
                </div>
            </div>
        </div>

    </div><!-- /.row charts2 -->

    <!-- ── Info Row ── -->
    <div class="row" style="margin-bottom:24px;">

        <!-- Subscription Expiry Alerts -->
        <div class="col-md-5" style="margin-bottom:20px;">
            <div class="box box-danger" style="margin-bottom:0;">
                <div class="box-header">
                    <i class="fa fa-exclamation-triangle" style="color:var(--rose);margin-right:8px;"></i>
                    <span class="box-title">Subscription Expiry Alerts</span>
                    <span class="label label-danger" style="float:right;margin-top:2px;"><?= count($expiry_alerts) ?> expiring</span>
                </div>
                <div class="box-body" style="padding:0 !important;max-height:300px;overflow-y:auto;">
                    <?php if(empty($expiry_alerts)): ?>
                    <div style="padding:32px;text-align:center;color:var(--t3);">
                        <i class="fa fa-check-circle" style="font-size:28px;opacity:.3;display:block;margin-bottom:8px;color:#22c55e;"></i>
                        No subscriptions expiring in the next 15 days
                    </div>
                    <?php else: ?>
                    <ul style="list-style:none;margin:0;padding:0;">
                        <?php foreach($expiry_alerts as $a): ?>
                        <li style="padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;">
                            <div style="min-width:0;">
                                <div style="font-size:13px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($a['name']) ?></div>
                                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);">
                                    <?= htmlspecialchars($a['plan_name'] ?? '—') ?> · Expires <?= htmlspecialchars($a['expiry_date']) ?>
                                </div>
                            </div>
                            <span class="label <?= $a['days_left'] <= 3 ? 'label-danger' : ($a['days_left'] <= 7 ? 'label-warning' : 'label-info') ?>" style="flex-shrink:0;">
                                <?= $a['days_left'] ?> day<?= $a['days_left'] != 1 ? 's' : '' ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <div class="box-footer">
                    <a href="<?= base_url('superadmin/plans/subscriptions') ?>" class="btn btn-default btn-xs">
                        <i class="fa fa-calendar-check-o"></i> View All Subscriptions
                    </a>
                </div>
            </div>
        </div>

        <!-- Recent SA Activity -->
        <div class="col-md-7" style="margin-bottom:20px;">
            <div class="box box-primary" style="margin-bottom:0;">
                <div class="box-header">
                    <i class="fa fa-history" style="color:var(--sa3);margin-right:8px;"></i>
                    <span class="box-title">Recent SA Activity</span>
                    <span style="float:right;font-size:11px;color:var(--t3);font-family:var(--font-m);margin-top:3px;">Today</span>
                </div>
                <div class="box-body" style="padding:0 !important;max-height:300px;overflow-y:auto;">
                    <?php if(empty($recent_activity)): ?>
                    <div style="padding:32px;text-align:center;color:var(--t3);">
                        <i class="fa fa-list-alt" style="font-size:28px;opacity:.3;display:block;margin-bottom:8px;"></i>
                        No activity recorded today
                    </div>
                    <?php else: ?>
                    <ul style="list-style:none;margin:0;padding:0;">
                        <?php foreach($recent_activity as $log): ?>
                        <li style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:10px;">
                            <div style="width:7px;height:7px;border-radius:50%;background:var(--sa3);flex-shrink:0;margin-top:5px;"></div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:12.5px;color:var(--t1);font-weight:500;">
                                    <span style="color:var(--sa3);font-family:var(--font-m);"><?= htmlspecialchars($log['action'] ?? 'action') ?></span>
                                    <?php if(!empty($log['school_uid'])): ?>
                                    <span style="color:var(--t3);font-size:11px;"> — <?= htmlspecialchars($log['school_uid']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:11px;color:var(--t3);margin-top:2px;">
                                    by <strong style="color:var(--t2);"><?= htmlspecialchars($log['sa_name'] ?? 'SA') ?></strong>
                                    · <?= htmlspecialchars(substr($log['timestamp'] ?? '', 11, 8)) ?>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <div class="box-footer">
                    <a href="<?= base_url('superadmin/monitor') ?>" class="btn btn-default btn-xs">
                        <i class="fa fa-heartbeat"></i> Full Activity Log
                    </a>
                </div>
            </div>
        </div>

    </div><!-- /.row info -->

    <!-- ── Recent Registrations ── -->
    <div class="box" style="margin-bottom:24px;">
        <div class="box-header">
            <i class="fa fa-plus-circle" style="color:var(--sa3);margin-right:8px;"></i>
            <span class="box-title">Recent Registrations <span style="font-size:11px;color:var(--t3);font-weight:400;">(Last 30 days)</span></span>
        </div>
        <div class="box-body" style="padding:0;overflow-x:auto;">
            <table class="table table-hover" style="margin:0;min-width:600px;">
                <thead>
                    <tr style="background:var(--bg3);">
                        <th style="padding:10px 14px;font-size:11px;color:var(--t3);font-family:var(--font-m);border-bottom:1px solid var(--border);">SCHOOL</th>
                        <th style="padding:10px 14px;font-size:11px;color:var(--t3);font-family:var(--font-m);border-bottom:1px solid var(--border);">CODE</th>
                        <th style="padding:10px 14px;font-size:11px;color:var(--t3);font-family:var(--font-m);border-bottom:1px solid var(--border);">PLAN</th>
                        <th style="padding:10px 14px;font-size:11px;color:var(--t3);font-family:var(--font-m);border-bottom:1px solid var(--border);">STATUS</th>
                        <th style="padding:10px 14px;font-size:11px;color:var(--t3);font-family:var(--font-m);border-bottom:1px solid var(--border);">REGISTERED</th>
                        <th style="padding:10px 14px;font-size:11px;color:var(--t3);font-family:var(--font-m);border-bottom:1px solid var(--border);">ACTION</th>
                    </tr>
                </thead>
                <tbody id="recentRegsBody">
                    <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--t3);">
                        <i class="fa fa-spinner fa-spin"></i> Loading...
                    </td></tr>
                </tbody>
            </table>
        </div>
        <div class="box-footer">
            <a href="<?= base_url('superadmin/schools') ?>" class="btn btn-default btn-xs">
                <i class="fa fa-building"></i> All Schools
            </a>
        </div>
    </div>

    <!-- ── Quick Actions ── -->
    <div class="box" style="margin-bottom:0;">
        <div class="box-header">
            <i class="fa fa-bolt" style="color:var(--sa3);margin-right:8px;"></i>
            <span class="box-title">Quick Actions</span>
        </div>
        <div class="box-body">
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a href="<?= base_url('superadmin/schools/create') ?>" class="btn btn-primary">
                    <i class="fa fa-plus"></i> Onboard New School
                </a>
                <a href="<?= base_url('superadmin/plans') ?>" class="btn btn-default">
                    <i class="fa fa-tags"></i> Manage Plans
                </a>
                <a href="<?= base_url('superadmin/plans/subscriptions') ?>" class="btn btn-default">
                    <i class="fa fa-calendar-check-o"></i> Subscriptions
                </a>
                <a href="<?= base_url('superadmin/plans/payments') ?>" class="btn btn-default">
                    <i class="fa fa-money"></i> Payments
                </a>
                <a href="<?= base_url('superadmin/reports') ?>" class="btn btn-default">
                    <i class="fa fa-bar-chart"></i> Reports
                </a>
                <a href="<?= base_url('superadmin/backups') ?>" class="btn btn-default">
                    <i class="fa fa-database"></i> Backups
                </a>
                <a href="<?= base_url('superadmin/monitor') ?>" class="btn btn-default">
                    <i class="fa fa-heartbeat"></i> Monitor
                </a>
            </div>
        </div>
    </div>

</section>

<script>
(function(){

/* ── helpers ── */
function fmt(n){ return parseInt(n||0).toLocaleString('en-IN'); }
function fmtMoney(n){ return '₹'+parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:0}); }
function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

var PALETTE = ['#8b5cf6','#22c55e','#eab308','#ef4444','#6b7280','#3b82f6','#f97316','#14b8a6','#ec4899','#6366f1'];
var statusColors = { active:'#22c55e', grace:'#eab308', expired:'#ef4444', suspended:'#6b7280', inactive:'#9ca3af' };
var statusLabels = { active:'Active', grace:'Grace Period', expired:'Expired', suspended:'Suspended', inactive:'Inactive' };

/* ── Chart instances ── */
var chartStatus    = null;
var chartRevenue   = null;
var chartPlan      = null;
var chartTopSchool = null;

function getTextColor(){
    return getComputedStyle(document.documentElement).getPropertyValue('--t2').trim() || '#9ca3af';
}
function getGridColor(){
    return getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || 'rgba(255,255,255,.08)';
}

/* ── Build legend pill ── */
function buildLegend(container, labels, colors){
    var html = labels.map(function(l,i){
        return '<span style="font-size:10.5px;padding:2px 8px;border-radius:10px;background:'+colors[i]+'22;color:'+colors[i]+';border:1px solid '+colors[i]+'44;font-family:var(--font-m);">'+escHtml(l)+'</span>';
    }).join('');
    document.getElementById(container).innerHTML = html;
}

/* ── Status Doughnut ── */
function buildStatusChart(counts){
    var labels = [], data = [], colors = [];
    Object.keys(counts).forEach(function(k){
        if(counts[k] > 0){
            labels.push(statusLabels[k] || k);
            data.push(counts[k]);
            colors.push(statusColors[k] || '#9ca3af');
        }
    });
    var total = data.reduce(function(a,b){ return a+b; }, 0);
    document.getElementById('statusChartTotal').textContent = total;

    var ctx = document.getElementById('statusChart').getContext('2d');
    if(chartStatus) chartStatus.destroy();
    chartStatus = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderWidth: 2, borderColor: 'transparent', hoverOffset: 4 }] },
        options: {
            cutout: '70%',
            plugins: { legend: { display: false }, tooltip: { callbacks: {
                label: function(c){ return ' '+c.label+': '+c.raw+' ('+Math.round(c.raw/total*100)+'%)'; }
            }}},
            animation: { duration: 600 }
        }
    });
    buildLegend('statusLegend', labels, colors);
}

/* ── Revenue Bar ── */
function buildRevenueChart(monthsObj){
    var labels = Object.keys(monthsObj).map(function(k){
        var d = new Date(k+'-01');
        return d.toLocaleDateString('en-IN',{month:'short',year:'2-digit'});
    });
    var data = Object.values(monthsObj).map(parseFloat);

    var ctx = document.getElementById('revenueChart').getContext('2d');
    if(chartRevenue) chartRevenue.destroy();
    chartRevenue = new Chart(ctx, {
        type: 'bar',
        data: { labels: labels, datasets: [{
            label: 'Revenue (₹)',
            data: data,
            backgroundColor: 'rgba(139,92,246,.65)',
            borderColor:     'rgba(139,92,246,1)',
            borderWidth: 1,
            borderRadius: 5,
        }]},
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false }, tooltip: { callbacks: {
                label: function(c){ return ' '+fmtMoney(c.raw); }
            }}},
            scales: {
                x: { ticks:{ color: getTextColor(), font:{size:11} }, grid:{ color: getGridColor() } },
                y: { ticks:{ color: getTextColor(), font:{size:11}, callback: function(v){ return fmtMoney(v); } }, grid:{ color: getGridColor() } }
            },
            animation: { duration: 600 }
        }
    });
}

/* ── Plan Doughnut ── */
function buildPlanChart(planCounts){
    var labels = Object.keys(planCounts);
    var data   = Object.values(planCounts);
    var colors = labels.map(function(_,i){ return PALETTE[i % PALETTE.length]; });

    var ctx = document.getElementById('planChart').getContext('2d');
    if(chartPlan) chartPlan.destroy();
    chartPlan = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: labels, datasets: [{ data: data, backgroundColor: colors, borderWidth: 2, borderColor: 'transparent', hoverOffset: 4 }] },
        options: {
            cutout: '60%',
            plugins: { legend: { display: false }, tooltip: { callbacks: {
                label: function(c){ return ' '+c.label+': '+c.raw+' school'+(c.raw!=1?'s':''); }
            }}},
            animation: { duration: 600 }
        }
    });
    buildLegend('planLegend', labels, colors);
}

/* ── Top Schools Horizontal Bar ── */
function buildTopSchoolsChart(rows){
    var labels = rows.map(function(r){ return r.name; });
    var data   = rows.map(function(r){ return r.count; });
    var colors = labels.map(function(_,i){ return PALETTE[i % PALETTE.length]; });

    var ctx = document.getElementById('topSchoolsChart').getContext('2d');
    if(chartTopSchool) chartTopSchool.destroy();
    chartTopSchool = new Chart(ctx, {
        type: 'bar',
        data: { labels: labels, datasets: [{
            label: 'Students',
            data: data,
            backgroundColor: colors,
            borderRadius: 4,
        }]},
        options: {
            indexAxis: 'y',
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { callbacks: {
                label: function(c){ return ' '+fmt(c.raw)+' students'; }
            }}},
            scales: {
                x: { ticks:{ color: getTextColor(), font:{size:11} }, grid:{ color: getGridColor() } },
                y: { ticks:{ color: getTextColor(), font:{size:11}, maxRotation:0 }, grid:{ color:'transparent' } }
            },
            animation: { duration: 600 }
        }
    });
}

/* ── Recent Registrations table ── */
function renderRecentRegs(rows){
    if(!rows || !rows.length){
        document.getElementById('recentRegsBody').innerHTML =
            '<tr><td colspan="6" style="text-align:center;padding:28px;color:var(--t3);">No new schools registered in the last 30 days.</td></tr>';
        return;
    }
    var statusCls = { active:'label-success', grace:'label-warning', expired:'label-danger', suspended:'label-default', inactive:'label-default' };
    var html = rows.map(function(r){
        var cls = statusCls[r.status] || 'label-default';
        return '<tr>'
            +'<td style="padding:10px 14px;"><strong>'+escHtml(r.name)+'</strong>'+(r.city?'<br><small style="color:var(--t3);">'+escHtml(r.city)+'</small>':'')+'</td>'
            +'<td style="padding:10px 14px;"><code style="font-size:11px;">'+escHtml(r.school_code||'—')+'</code></td>'
            +'<td style="padding:10px 14px;">'+escHtml(r.plan_name||'—')+'</td>'
            +'<td style="padding:10px 14px;"><span class="label '+cls+'">'+escHtml(r.status||'Inactive')+'</span></td>'
            +'<td style="padding:10px 14px;font-size:12px;">'+escHtml((r.created_at||'').substring(0,10))+'</td>'
            +'<td style="padding:10px 14px;">'
            +'<a href="'+BASE_URL+'superadmin/schools/view/'+encodeURIComponent(r.uid||'')+'" class="btn btn-default btn-xs"><i class="fa fa-eye"></i></a>'
            +'</td>'
            +'</tr>';
    }).join('');
    document.getElementById('recentRegsBody').innerHTML = html;
}

/* ── Load all chart data ── */
function loadCharts(){
    // $.post(BASE_URL+'superadmin/dashboard/charts', {}, function(r){
        var csrf = {};
        csrf['<?= $this->security->get_csrf_token_name() ?>'] = '<?= $this->security->get_csrf_hash() ?>';
        $.post(BASE_URL+'superadmin/dashboard/charts', csrf, function(r){
        if(r.status !== 'success') return;
        buildStatusChart(r.status_counts   || {});
        buildRevenueChart(r.revenue_months  || {});
        buildPlanChart(r.plan_counts        || {});
        buildTopSchoolsChart(r.school_students || []);
        renderRecentRegs(r.recent_regs      || []);
    }, 'json').fail(function(){
        document.getElementById('recentRegsBody').innerHTML =
            '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--t3);">Failed to load data.</td></tr>';
    });
}

/* ── Refresh Stats button ── */
$('#refreshStatsBtn').on('click', function(){
    var $btn  = $(this).prop('disabled', true);
    var $icon = $('#refreshIcon').addClass('fa-spin');

    $.ajax({
        url:  BASE_URL + 'superadmin/dashboard/refresh_stats',
        type: 'POST',
        success: function(r){
            if(r.status === 'success'){
                var d = r;
                $('#statTotalSchools').text(fmt(d.total_schools));
                $('#statActiveSchools').text(fmt(d.active_schools));
                $('#statTotalStudents').text(fmt(d.total_students));
                $('#statTotalStaff').text(fmt(d.total_staff));
                $('#statRevenue').text(fmtMoney(d.total_revenue));
                $('#statRecentRegs').text(fmt(d.recent_regs));
                var ts = d.last_refreshed;
                $('#lastRefreshed').text(ts ? new Date(ts).toLocaleString() : '');
                var pct = d.total_schools > 0 ? Math.round(d.active_schools / d.total_schools * 100) : 0;
                $('#statActivePct').text(pct + '%');
                loadCharts();
                if(typeof saToast === 'function') saToast('Stats refreshed.', 'success');
            } else {
                if(typeof saToast === 'function') saToast(r.message || 'Refresh failed.', 'error');
            }
        },
        error: function(){ if(typeof saToast === 'function') saToast('Server error.', 'error'); },
        complete: function(){ $btn.prop('disabled', false); $icon.removeClass('fa-spin'); }
    });
});

/* ── Re-render charts when theme changes (MutationObserver on body class) ── */
var _themeObserver = new MutationObserver(function(){
    if(chartRevenue)   { chartRevenue.options.scales.x.ticks.color   = getTextColor(); chartRevenue.options.scales.x.grid.color   = getGridColor(); chartRevenue.options.scales.y.ticks.color   = getTextColor(); chartRevenue.options.scales.y.grid.color   = getGridColor(); chartRevenue.update('none'); }
    if(chartTopSchool) { chartTopSchool.options.scales.x.ticks.color = getTextColor(); chartTopSchool.options.scales.x.grid.color = getGridColor(); chartTopSchool.options.scales.y.ticks.color = getTextColor(); chartTopSchool.update('none'); }
});
_themeObserver.observe(document.body, { attributes: true, attributeFilter: ['class','data-theme'] });

/* ── Init ── */
$(function(){ loadCharts(); });

})();
</script>
