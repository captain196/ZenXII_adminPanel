<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size:16px !important; }

.ac-wrap { padding:24px 22px 52px; min-height:100vh; }

/* ── Page Header ── */
.ac-head {
    display:flex; align-items:center; gap:14px;
    padding:18px 22px; margin-bottom:22px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh);
}
.ac-head-icon {
    width:44px; height:44px; border-radius:10px;
    background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow);
}
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }

/* ── Quick Links ── */
.ac-quick { display:flex; gap:10px; flex-wrap:wrap; }
.ac-ql {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px; border-radius:8px; border:1px solid var(--border);
    background:var(--bg2); color:var(--t2); font-size:13px; font-weight:600;
    text-decoration:none; cursor:pointer; transition:all var(--ease); font-family:var(--font-b);
}
.ac-ql:hover { border-color:var(--gold); color:var(--gold); background:var(--gold-dim); }
.ac-ql i { font-size:13px; }

/* ── Stat Grid ── */
.ac-stat-grid {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(155px,1fr));
    gap:14px; margin-bottom:24px;
}
.ac-stat {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:18px 16px; text-align:center;
    box-shadow:var(--sh); transition:border-color var(--ease);
}
.ac-stat:hover { border-color:var(--gold); }
.ac-stat-val {
    font-size:2rem; font-weight:700; color:var(--gold);
    font-family:var(--font-b); line-height:1.1;
}
.ac-stat-lbl {
    font-size:11px; color:var(--t3); margin-top:6px;
    text-transform:uppercase; letter-spacing:.5px; font-weight:600;
}

/* ── Chart Row ── */
.ac-chart-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:22px; }
.ac-card {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); padding:20px 22px; box-shadow:var(--sh);
}
.ac-card-title {
    font-size:14px; font-weight:700; color:var(--t1); margin-bottom:16px;
    display:flex; align-items:center; gap:8px;
}
.ac-card-title i { color:var(--gold); font-size:15px; }

/* ── Funnel ── */
.ac-funnel { display:flex; flex-direction:column; gap:10px; }
.ac-funnel-row { display:flex; align-items:center; gap:12px; }
.ac-funnel-lbl { width:100px; font-size:12px; color:var(--t2); text-align:right; font-weight:600; }
.ac-funnel-bar { flex:1; height:30px; background:var(--bg3); border-radius:6px; overflow:hidden; }
.ac-funnel-fill {
    height:100%; border-radius:6px; transition:width .5s ease;
    display:flex; align-items:center; padding:0 12px;
}
.ac-funnel-fill span { font-size:12px; font-weight:700; color:#fff; }

/* ── Source Bars ── */
.ac-bar-row { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.ac-bar-lbl { width:90px; font-size:12px; color:var(--t2); text-align:right; font-weight:600; }
.ac-bar-track { flex:1; height:24px; background:var(--bg3); border-radius:4px; overflow:hidden; }
.ac-bar-fill {
    height:100%; background:var(--gold); border-radius:4px;
    display:flex; align-items:center; padding:0 8px;
}
.ac-bar-fill span { font-size:11px; font-weight:700; color:#fff; }

/* ── Table ── */
.ac-table-wrap {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; overflow:hidden; box-shadow:var(--sh);
}
.ac-table { width:100%; border-collapse:collapse; font-size:13px; }
.ac-table th {
    background:var(--bg3); color:var(--t2); font-family:var(--font-m);
    padding:10px 14px; text-align:left; border-bottom:1px solid var(--border);
    font-size:11px; text-transform:uppercase; letter-spacing:.4px;
}
.ac-table td {
    padding:10px 14px; border-bottom:1px solid var(--border); color:var(--t1);
}
.ac-table tr:last-child td { border-bottom:none; }
.ac-table tr:hover td { background:var(--gold-dim); }

/* ── Monthly Trend ── */
.ac-trend { display:flex; align-items:flex-end; gap:14px; height:150px; padding:10px 0; }
.ac-trend-bar { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
.ac-trend-val { font-size:11px; color:var(--t2); font-weight:700; }
.ac-trend-fill { width:100%; max-width:50px; background:var(--gold); border-radius:4px 4px 0 0; transition:height .4s ease; }
.ac-trend-month { font-size:10px; color:var(--t3); font-weight:600; }

.ac-empty { text-align:center; padding:36px; color:var(--t3); font-size:13px; }

@media(max-width:767px) {
    .ac-chart-row { grid-template-columns:1fr; }
    .ac-stat-grid { grid-template-columns:repeat(2,1fr); }
    .ac-head { flex-wrap:wrap; }
    .ac-quick { width:100%; }
}
@media(max-width:640px) {
    .ac-stat-grid { grid-template-columns:1fr 1fr; }
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <!-- Header -->
    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-graduation-cap"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Admission CRM</div>
            <div class="ac-head-sub">Session <?= htmlspecialchars($session_year) ?> — Manage inquiries, applications &amp; enrollment</div>
        </div>
        <div class="ac-quick">
            <a href="<?= base_url('admission_crm/inquiries') ?>" class="ac-ql"><i class="fa fa-phone"></i> Inquiries</a>
            <a href="<?= base_url('admission_crm/applications') ?>" class="ac-ql"><i class="fa fa-file-text-o"></i> Applications</a>
            <a href="<?= base_url('admission_crm/pipeline') ?>" class="ac-ql"><i class="fa fa-columns"></i> Pipeline</a>
            <a href="<?= base_url('admission_crm/waitlist') ?>" class="ac-ql"><i class="fa fa-list-ol"></i> Waitlist</a>
            <a href="<?= base_url('admission_crm/settings') ?>" class="ac-ql"><i class="fa fa-cog"></i> Settings</a>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="ac-stat-grid">
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['total_inquiries'] ?></div><div class="ac-stat-lbl">Inquiries</div></div>
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['total_applications'] ?></div><div class="ac-stat-lbl">Applications</div></div>
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['pending_approval'] ?></div><div class="ac-stat-lbl">Pending</div></div>
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['approved'] ?></div><div class="ac-stat-lbl">Approved</div></div>
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['enrolled'] ?></div><div class="ac-stat-lbl">Enrolled</div></div>
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['total_waitlist'] ?></div><div class="ac-stat-lbl">Waitlisted</div></div>
        <div class="ac-stat"><div class="ac-stat-val"><?= $stats['rejected'] ?></div><div class="ac-stat-lbl">Rejected</div></div>
    </div>

    <!-- Charts Row -->
    <div class="ac-chart-row">
        <!-- Funnel -->
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-filter"></i> Admission Funnel</div>
            <div class="ac-funnel">
                <?php
                $maxVal = max($stats['total_inquiries'], 1);
                $funnelData = [
                    ['Inquiries', $stats['total_inquiries'], '#0f766e'],
                    ['Applications', $stats['total_applications'], '#14b8a6'],
                    ['Approved', $stats['approved'], '#15803d'],
                    ['Enrolled', $stats['enrolled'], '#0d6b63'],
                ];
                foreach ($funnelData as $f):
                    $pct = round(($f[1] / $maxVal) * 100);
                ?>
                <div class="ac-funnel-row">
                    <div class="ac-funnel-lbl"><?= $f[0] ?></div>
                    <div class="ac-funnel-bar">
                        <div class="ac-funnel-fill" style="width:<?= max($pct, 6) ?>%;background:<?= $f[2] ?>;">
                            <span><?= $f[1] ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Source Breakdown -->
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-pie-chart"></i> Inquiry Sources</div>
            <?php if (empty($source_breakdown)): ?>
                <div class="ac-empty">No inquiry data yet</div>
            <?php else: ?>
                <?php
                $maxSrc = max(array_values($source_breakdown));
                foreach ($source_breakdown as $src => $cnt):
                    $pct = round(($cnt / $maxSrc) * 100);
                ?>
                <div class="ac-bar-row">
                    <div class="ac-bar-lbl"><?= htmlspecialchars($src) ?></div>
                    <div class="ac-bar-track">
                        <div class="ac-bar-fill" style="width:<?= max($pct,8) ?>%;"><span><?= $cnt ?></span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Class-wise Breakdown -->
    <div class="ac-card" style="margin-bottom:22px;">
        <div class="ac-card-title"><i class="fa fa-bar-chart"></i> Class-wise Admission Status</div>
        <?php if (empty($class_breakdown)): ?>
            <div class="ac-empty">No application data yet</div>
        <?php else: ?>
        <div class="ac-table-wrap" style="box-shadow:none;">
            <table class="ac-table">
                <thead>
                    <tr><th>Class</th><th>Applied</th><th>Approved</th><th>Enrolled</th><th>Waitlisted</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($class_breakdown as $cls => $d): ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($cls) ?></td>
                        <td><?= $d['applied'] ?></td>
                        <td style="color:var(--green);"><?= $d['approved'] ?></td>
                        <td style="color:var(--gold);"><?= $d['enrolled'] ?></td>
                        <td style="color:var(--amber);"><?= $d['waitlisted'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Monthly Trend -->
    <?php if (!empty($monthly_trend)): ?>
    <div class="ac-card">
        <div class="ac-card-title"><i class="fa fa-line-chart"></i> Monthly Inquiry Trend</div>
        <div class="ac-trend">
            <?php
            $maxM = max(array_values($monthly_trend));
            foreach ($monthly_trend as $month => $cnt):
                $h = round(($cnt / max($maxM,1)) * 120);
            ?>
            <div class="ac-trend-bar">
                <span class="ac-trend-val"><?= $cnt ?></span>
                <div class="ac-trend-fill" style="height:<?= max($h,8) ?>px;"></div>
                <span class="ac-trend-month"><?= substr($month, 5) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>
