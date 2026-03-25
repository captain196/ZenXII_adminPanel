<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size: 16px !important; }
.sis-wrap { max-width:1200px; margin:0 auto; padding:24px 20px; }
.sis-hero { display:flex; align-items:center; gap:16px; padding:20px 24px;
    background:var(--bg2); border:1px solid var(--border); border-radius:12px; margin-bottom:24px; }
.sis-hero i { font-size:2.4rem; color:var(--gold); }
.sis-hero h1 { margin:0; font-size:1.5rem; color:var(--t1); font-family:var(--font-b); }
.sis-hero p  { margin:4px 0 0; color:var(--t3); font-size:.9rem; }

.stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:24px; }
.stat-card { background:var(--bg2); border:1px solid var(--border); border-radius:10px;
    padding:20px; text-align:center; }
.stat-card .stat-num { font-size:2.2rem; font-weight:700; color:var(--gold); font-family:var(--font-b); }
.stat-card .stat-label { font-size:.82rem; color:var(--t3); margin-top:4px; text-transform:uppercase; letter-spacing:.05em; }

.sis-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
@media(max-width:767px){ .sis-grid{grid-template-columns:1fr;} }

.sis-panel { background:var(--bg2); border:1px solid var(--border); border-radius:10px; padding:20px; }
.sis-panel h3 { margin:0 0 16px; font-size:1rem; color:var(--t1); font-family:var(--font-b);
    border-bottom:1px solid var(--border); padding-bottom:10px; }

.class-bar { display:flex; align-items:center; gap:10px; margin-bottom:8px; }
.class-bar .cls-name { width:60px; font-size:.82rem; color:var(--t2); font-family:var(--font-m); }
.class-bar .bar-wrap { flex:1; background:var(--bg3); border-radius:4px; height:8px; overflow:hidden; }
.class-bar .bar-fill { background:var(--gold); height:100%; border-radius:4px; transition:width .5s var(--ease); }
.class-bar .cls-cnt  { font-size:.84rem; color:var(--t3); min-width:28px; text-align:right; }

.promo-list { list-style:none; padding:0; margin:0; }
.promo-item { display:flex; align-items:flex-start; gap:12px; padding:10px 0;
    border-bottom:1px solid var(--border); }
.promo-item:last-child { border-bottom:none; }
.promo-icon { width:34px; height:34px; border-radius:50%; background:var(--gold-dim);
    display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.promo-icon i { color:var(--gold); }
.promo-detail { flex:1; }
.promo-title { font-size:.88rem; color:var(--t1); font-weight:600; }
.promo-sub   { font-size:.84rem; color:var(--t3); margin-top:2px; }

.sis-quick { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.quick-btn { display:flex; flex-direction:column; align-items:center; gap:6px; padding:16px 8px;
    background:var(--bg3); border:1px solid var(--border); border-radius:8px;
    color:var(--t2); text-decoration:none; transition:all .2s var(--ease); font-size:.82rem; }
.quick-btn:hover { background:var(--gold-dim); border-color:var(--gold-ring); color:var(--gold); }
.quick-btn i { font-size:1.4rem; }
</style>

<div class="content-wrapper">
<div class="sis-wrap">

    <div class="sis-hero">
        <i class="fa fa-id-badge"></i>
        <div>
            <h1>Student Information System</h1>
            <p>Session: <?= htmlspecialchars($session_year) ?> &nbsp;&bull;&nbsp; Manage admissions, promotions, TCs, and student records.</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-num"><?= $total_students ?></div>
            <div class="stat-label">Total Registered</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $enrolled_count ?></div>
            <div class="stat-label">Enrolled This Session</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= $tc_count ?></div>
            <div class="stat-label">TC Issued</div>
        </div>
        <div class="stat-card">
            <div class="stat-num"><?= count($class_counts) ?></div>
            <div class="stat-label">Active Classes</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="sis-panel" style="margin-bottom:20px;">
        <h3><i class="fa fa-bolt" style="color:var(--gold);margin-right:6px;"></i>Quick Actions</h3>
        <div class="sis-quick">
            <a href="<?= base_url('sis/admission') ?>" class="quick-btn"><i class="fa fa-user-plus"></i>New Admission</a>
            <a href="<?= base_url('sis/students') ?>" class="quick-btn"><i class="fa fa-list"></i>Student List</a>
            <a href="<?= base_url('sis/promote') ?>" class="quick-btn"><i class="fa fa-level-up"></i>Promotion</a>
            <a href="<?= base_url('sis/tc') ?>" class="quick-btn"><i class="fa fa-file-text-o"></i>Transfer Cert.</a>
            <a href="<?= base_url('sis/id_card') ?>" class="quick-btn"><i class="fa fa-id-card-o"></i>ID Cards</a>
            <a href="<?= base_url('student/attendance') ?>" class="quick-btn"><i class="fa fa-check-square-o"></i>Attendance</a>
        </div>
    </div>

    <div class="sis-grid">
        <!-- Class Distribution -->
        <div class="sis-panel">
            <h3><i class="fa fa-bar-chart" style="color:var(--gold);margin-right:6px;"></i>Class Distribution</h3>
            <?php
            $maxCount = $class_counts ? max($class_counts) : 1;
            foreach ($class_counts as $cls => $cnt):
                $pct = round($cnt / $maxCount * 100);
            ?>
            <div class="class-bar">
                <div class="cls-name">Class <?= htmlspecialchars($cls) ?></div>
                <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                <div class="cls-cnt"><?= $cnt ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($class_counts)): ?>
            <p style="color:var(--t3);font-size:.85rem;">No enrollment data for this session.</p>
            <?php endif; ?>
        </div>

        <!-- Recent Promotions -->
        <div class="sis-panel">
            <h3><i class="fa fa-history" style="color:var(--gold);margin-right:6px;"></i>Recent Promotions</h3>
            <?php if (empty($recent_promotions)): ?>
            <p style="color:var(--t3);font-size:.85rem;">No promotions recorded yet.</p>
            <?php else: ?>
            <ul class="promo-list">
                <?php foreach ($recent_promotions as $b): if (!is_array($b)) continue; ?>
                <li class="promo-item">
                    <div class="promo-icon"><i class="fa fa-level-up"></i></div>
                    <div class="promo-detail">
                        <div class="promo-title">
                            Class <?= htmlspecialchars($b['from_class'] ?? '?') ?> &rarr;
                            Class <?= htmlspecialchars($b['to_class'] ?? '?') ?>
                            <span style="font-weight:400;color:var(--t3);">(<?= (int)($b['count'] ?? 0) ?> students)</span>
                        </div>
                        <div class="promo-sub">
                            <?= htmlspecialchars($b['session_from'] ?? '') ?> &rarr; <?= htmlspecialchars($b['session_to'] ?? '') ?>
                            &bull; by <?= htmlspecialchars($b['promoted_by'] ?? 'Admin') ?>
                            &bull; <?= htmlspecialchars(substr($b['promoted_at'] ?? '', 0, 10)) ?>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>
