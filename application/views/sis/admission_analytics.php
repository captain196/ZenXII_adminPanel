<?php
defined('BASEPATH') or exit('No direct script access allowed');
// LEAD SYSTEM — Admission analytics dashboard
$esc = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };

$statusColors = [
    'new'        => '#3b82f6',
    'contacted'  => '#f59e0b',
    'interested' => '#8b5cf6',
    'approved'   => '#22c55e',
    'admitted'   => '#0f766e',
    'enrolled'   => '#059669',
    'rejected'   => '#ef4444',
];
?>

<style>
.aa-wrap { padding:20px; max-width:1400px; margin:0 auto; }
.aa-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; }
.aa-hdr h1 { font-size:1.25rem; color:var(--t1); font-family:var(--font-b); margin:0; }
.aa-hdr .aa-session { font-size:13px; color:var(--t3); background:var(--bg3); padding:5px 14px; border-radius:20px; }

/* Top metric cards */
.aa-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:24px; }
.aa-card {
    background:var(--bg2); border:1px solid var(--border); border-radius:10px;
    padding:20px 24px; position:relative; overflow:hidden;
}
.aa-card::before {
    content:''; position:absolute; top:0; left:0; width:4px; height:100%;
    border-radius:10px 0 0 10px;
}
.aa-card.c-total::before  { background:#3b82f6; }
.aa-card.c-admit::before  { background:#0f766e; }
.aa-card.c-conv::before   { background:#8b5cf6; }
.aa-card.c-public::before { background:#f59e0b; }
.aa-card.c-new::before    { background:#ef4444; }
.aa-card .aa-card-val { font-size:2rem; font-weight:800; color:var(--t1); line-height:1; }
.aa-card .aa-card-lbl { font-size:11px; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; margin-top:4px; }
.aa-card .aa-card-sub { font-size:12px; color:var(--t3); margin-top:6px; }

/* Chart grid */
.aa-charts { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
@media(max-width:900px) { .aa-charts { grid-template-columns:1fr; } }

.aa-chart-box {
    background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:20px 24px;
}
.aa-chart-title {
    font-size:13px; font-weight:700; color:var(--t1); margin-bottom:16px;
    display:flex; align-items:center; gap:8px;
}
.aa-chart-title i { color:var(--gold); }

/* Pure CSS bar chart */
.bar-chart { display:flex; flex-direction:column; gap:8px; }
.bar-row { display:flex; align-items:center; gap:10px; }
.bar-label { width:80px; font-size:12px; color:var(--t2); text-align:right; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bar-track { flex:1; height:24px; background:var(--bg3); border-radius:6px; overflow:hidden; position:relative; }
.bar-fill {
    height:100%; border-radius:6px; transition:width .6s cubic-bezier(.4,0,.2,1);
    display:flex; align-items:center; padding-left:8px;
    font-size:11px; font-weight:600; color:#fff; min-width:0;
}
.bar-count { font-size:12px; color:var(--t3); width:36px; text-align:right; }

/* Pure CSS donut chart */
.donut-wrap { display:flex; align-items:center; gap:24px; flex-wrap:wrap; }
.donut-svg { width:160px; height:160px; flex-shrink:0; }
.donut-legend { display:flex; flex-direction:column; gap:6px; }
.donut-leg-item { display:flex; align-items:center; gap:8px; font-size:12px; color:var(--t2); }
.donut-leg-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.donut-leg-val { font-weight:700; margin-left:auto; min-width:28px; text-align:right; }

/* Monthly trend */
.trend-chart { display:flex; align-items:flex-end; gap:6px; height:120px; padding-top:10px; }
.trend-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; height:100%; justify-content:flex-end; }
.trend-bar {
    width:100%; max-width:40px; background:var(--gold); border-radius:4px 4px 0 0;
    transition:height .5s cubic-bezier(.4,0,.2,1); position:relative;
}
.trend-bar:hover { opacity:.85; }
.trend-bar-val { font-size:10px; color:var(--t1); font-weight:700; text-align:center; margin-bottom:2px; }
.trend-bar-lbl { font-size:10px; color:var(--t3); margin-top:4px; white-space:nowrap; }

/* Recent leads table */
.aa-recent { background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:20px 24px; margin-bottom:24px; }
.aa-recent h3 { font-size:13px; font-weight:700; color:var(--t1); margin-bottom:12px; display:flex; align-items:center; gap:8px; }
.aa-recent h3 i { color:var(--gold); }
.aa-tbl { width:100%; border-collapse:collapse; }
.aa-tbl th { font-size:10px; text-transform:uppercase; color:var(--t3); letter-spacing:.4px; text-align:left; padding:6px 10px; border-bottom:1px solid var(--border); }
.aa-tbl td { font-size:12px; padding:8px 10px; border-bottom:1px solid var(--border); color:var(--t1); }
.aa-tbl tr:hover td { background:var(--gold-dim); }
.aa-badge {
    display:inline-block; padding:2px 8px; border-radius:12px; font-size:10px; font-weight:600;
    text-transform:uppercase; letter-spacing:.3px;
}

.aa-empty { text-align:center; padding:40px; color:var(--t3); }
.aa-empty i { font-size:2rem; margin-bottom:8px; display:block; }
</style>

<div class="aa-wrap">

<div class="aa-hdr">
    <h1><i class="fa fa-chart-bar" style="color:var(--gold);margin-right:8px;"></i>Admission Analytics</h1>
    <span class="aa-session"><i class="fa fa-calendar"></i> <?= $esc($session_year) ?></span>
</div>

<?php if ($total === 0): ?>
<div class="aa-empty">
    <i class="fa fa-inbox"></i>
    <p>No admission data for this session yet.</p>
    <p style="font-size:12px;margin-top:8px;">Leads from the public admission form and CRM will appear here.</p>
</div>
<?php else: ?>

<!-- ── Top Metric Cards ── -->
<div class="aa-cards">
    <div class="aa-card c-total">
        <div class="aa-card-val"><?= $total ?></div>
        <div class="aa-card-lbl">Total Leads</div>
        <div class="aa-card-sub"><?= $by_source['public_form'] ?> public, <?= $by_source['crm'] ?> CRM</div>
    </div>
    <div class="aa-card c-admit">
        <div class="aa-card-val"><?= $admitted ?></div>
        <div class="aa-card-lbl">Admitted</div>
        <div class="aa-card-sub"><?= ($by_status['approved'] ?? 0) ?> approved pending</div>
    </div>
    <div class="aa-card c-conv">
        <div class="aa-card-val"><?= $conversion_rate ?>%</div>
        <div class="aa-card-lbl">Conversion Rate</div>
        <div class="aa-card-sub">admitted / total leads</div>
    </div>
    <div class="aa-card c-new">
        <div class="aa-card-val"><?= ($by_status['new'] ?? 0) ?></div>
        <div class="aa-card-lbl">New (Unprocessed)</div>
        <div class="aa-card-sub">require follow-up</div>
    </div>
    <div class="aa-card c-public">
        <div class="aa-card-val"><?= $by_source['public_form'] ?></div>
        <div class="aa-card-lbl">From Public Form</div>
        <div class="aa-card-sub"><?= $total > 0 ? round(($by_source['public_form'] / $total) * 100) : 0 ?>% of total</div>
    </div>
</div>

<!-- ── Charts Row ── -->
<div class="aa-charts">

    <!-- Bar chart: Leads by Class -->
    <div class="aa-chart-box">
        <div class="aa-chart-title"><i class="fa fa-school"></i> Leads by Class</div>
        <?php
        $maxClassCount = max(1, max($by_class ?: [1]));
        ?>
        <div class="bar-chart">
        <?php foreach ($by_class as $cls => $count): ?>
            <div class="bar-row">
                <div class="bar-label" title="<?= $esc($cls) ?>"><?= $esc($cls) ?></div>
                <div class="bar-track">
                    <div class="bar-fill" style="width:<?= round(($count / $maxClassCount) * 100) ?>%;background:var(--gold);">
                        <?= $count >= 3 ? $count : '' ?>
                    </div>
                </div>
                <div class="bar-count"><?= $count ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Donut chart: Leads by Status -->
    <div class="aa-chart-box">
        <div class="aa-chart-title"><i class="fa fa-pie-chart"></i> Leads by Status</div>
        <div class="donut-wrap">
            <svg class="donut-svg" viewBox="0 0 42 42">
                <circle cx="21" cy="21" r="15.9" fill="transparent" stroke="var(--bg3)" stroke-width="5"/>
                <?php
                $radius = 15.9;
                $circumference = 2 * M_PI * $radius;
                $offset = 0;
                $activeStatuses = array_filter($by_status, fn($v) => $v > 0);
                foreach ($activeStatuses as $st => $cnt):
                    $pct = $cnt / max($total, 1);
                    $dashLen = $pct * $circumference;
                    $dashGap = $circumference - $dashLen;
                    $color = $statusColors[$st] ?? '#94a3b8';
                ?>
                <circle cx="21" cy="21" r="<?= $radius ?>" fill="transparent"
                        stroke="<?= $color ?>" stroke-width="5"
                        stroke-dasharray="<?= round($dashLen, 2) ?> <?= round($dashGap, 2) ?>"
                        stroke-dashoffset="<?= round(-$offset, 2) ?>"
                        style="transition:stroke-dashoffset .5s;"/>
                <?php
                    $offset += $dashLen;
                endforeach;
                ?>
                <text x="21" y="21" text-anchor="middle" dominant-baseline="central"
                      style="font-size:5px;font-weight:800;fill:var(--t1);font-family:var(--font-b);">
                    <?= $total ?>
                </text>
            </svg>
            <div class="donut-legend">
            <?php foreach ($activeStatuses as $st => $cnt): ?>
                <div class="donut-leg-item">
                    <span class="donut-leg-dot" style="background:<?= $statusColors[$st] ?? '#94a3b8' ?>"></span>
                    <span><?= ucfirst($esc($st)) ?></span>
                    <span class="donut-leg-val"><?= $cnt ?></span>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Monthly Trend ── -->
<?php if (!empty($by_month)): ?>
<div class="aa-chart-box" style="margin-bottom:24px;">
    <div class="aa-chart-title"><i class="fa fa-line-chart"></i> Monthly Trend</div>
    <?php $maxMonth = max(1, max($by_month)); ?>
    <div class="trend-chart">
    <?php foreach ($by_month as $monthKey => $cnt): ?>
        <?php
        $barHeight = round(($cnt / $maxMonth) * 100);
        $label = date('M y', strtotime($monthKey . '-01'));
        ?>
        <div class="trend-bar-wrap">
            <div class="trend-bar-val"><?= $cnt ?></div>
            <div class="trend-bar" style="height:<?= max($barHeight, 4) ?>%;"></div>
            <div class="trend-bar-lbl"><?= $label ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Recent Leads ── -->
<?php if (!empty($recent_leads)): ?>
<div class="aa-recent">
    <h3><i class="fa fa-clock-o"></i> Recent Leads</h3>
    <table class="aa-tbl">
        <thead><tr><th>ID</th><th>Student</th><th>Class</th><th>Source</th><th>Status</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($recent_leads as $l): ?>
            <tr>
                <td><code style="font-size:11px;"><?= $esc($l['id']) ?></code></td>
                <td style="font-weight:600;"><?= $esc($l['name']) ?></td>
                <td><?= $esc($l['class']) ?></td>
                <td><?= $l['source'] === 'public_form' ? 'Public' : 'CRM' ?></td>
                <td><span class="aa-badge" style="background:<?= $statusColors[$l['status']] ?? '#94a3b8' ?>20;color:<?= $statusColors[$l['status']] ?? '#94a3b8' ?>;"><?= $esc($l['status']) ?></span></td>
                <td style="color:var(--t3);font-size:11px;"><?= $esc($l['date']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php endif; /* total === 0 */ ?>
</div>
