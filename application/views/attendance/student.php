<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
:root {
    --sa-primary: var(--gold, #0f766e);
    --sa-primary-dim: var(--gold-dim, rgba(15,118,110,.10));
    --sa-primary-ring: var(--gold-ring, rgba(15,118,110,.22));
    --sa-bg: var(--bg, #f0f7f5);
    --sa-bg2: var(--bg2, #ffffff);
    --sa-bg3: var(--bg3, #e6f4f1);
    --sa-bg4: var(--bg4, #cce9e4);
    --sa-border: var(--border, #d1ddd9);
    --sa-t1: var(--t1, #1a1a2e);
    --sa-t2: var(--t2, #475569);
    --sa-t3: var(--t3, #94a3b8);
    --sa-shadow: var(--sh, 0 1px 3px rgba(0,0,0,.06));
    --sa-card: var(--card, var(--bg2, #fff));
    --sa-ease: var(--ease, all .2s ease);
    --sa-r: 10px;
    --sa-font: var(--font-b, 'Plus Jakarta Sans', sans-serif);
    --sa-mono: var(--font-m, 'JetBrains Mono', monospace);

    --sa-p: #10b981;
    --sa-a: #ef4444;
    --sa-l: #f59e0b;
    --sa-t: #3b82f6;
    --sa-h: #8b5cf6;
    --sa-v: #94a3b8;

    --sa-p-bg: rgba(16,185,129,.12);
    --sa-a-bg: rgba(239,68,68,.12);
    --sa-l-bg: rgba(245,158,11,.12);
    --sa-t-bg: rgba(59,130,246,.12);
    --sa-h-bg: rgba(139,92,246,.12);
    --sa-v-bg: rgba(148,163,184,.10);

    --sa-p-bg2: rgba(16,185,129,.22);
    --sa-a-bg2: rgba(239,68,68,.22);
    --sa-l-bg2: rgba(245,158,11,.22);

    --sa-dirty: rgba(234,179,8,.50);
    --sa-sun-bg: rgba(239,68,68,.07);
    --sa-hol-bg: rgba(139,92,246,.08);
}

/* ── Page Header ── */
.sa-page-head {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:20px; flex-wrap:wrap; gap:10px;
}
.sa-page-title {
    font-family:var(--sa-font); font-weight:800; font-size:20px;
    color:var(--sa-t1); margin:0; display:flex; align-items:center; gap:10px;
}
.sa-page-title i { color:var(--sa-primary); font-size:22px; }
.sa-page-sub {
    font-family:var(--sa-font); font-size:13px; color:var(--sa-t3); margin:2px 0 0;
}
.sa-badge-month {
    display:inline-flex; align-items:center; gap:5px;
    font-family:var(--sa-font); font-size:12px; font-weight:600;
    background:var(--sa-primary-dim); color:var(--sa-primary);
    padding:4px 12px; border-radius:20px; display:none;
}

/* ── Filter Card ── */
.sa-filter-card {
    display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;
    padding:20px 22px; border-radius:var(--sa-r);
    background:var(--sa-card); border:1px solid var(--sa-border);
    box-shadow:var(--sa-shadow); margin-bottom:18px;
}
.sa-fg { display:flex; flex-direction:column; gap:5px; }
.sa-fg label {
    font-family:var(--sa-font); font-size:10.5px; font-weight:700;
    text-transform:uppercase; letter-spacing:.8px; color:var(--sa-t3);
}
.sa-fg select {
    font-family:var(--sa-font); font-size:13px; height:40px;
    border-radius:8px; padding:0 14px; border:1px solid var(--sa-border);
    background:var(--sa-bg); color:var(--sa-t1); cursor:pointer;
    transition:var(--sa-ease); min-width:160px; appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat:no-repeat; background-position:right 12px center;
    padding-right:32px;
}
.sa-fg select:focus {
    outline:none; border-color:var(--sa-primary);
    box-shadow:0 0 0 3px var(--sa-primary-ring);
}
.sa-btn {
    font-family:var(--sa-font); font-size:13px; font-weight:700;
    border:none; border-radius:8px; padding:0 22px; height:40px;
    cursor:pointer; transition:var(--sa-ease); display:inline-flex;
    align-items:center; gap:7px; letter-spacing:.2px;
}
.sa-btn-primary {
    background:var(--sa-primary); color:#fff;
    box-shadow:0 2px 8px rgba(15,118,110,.25);
}
.sa-btn-primary:hover { opacity:.9; transform:translateY(-1px); box-shadow:0 4px 12px rgba(15,118,110,.3); }
.sa-btn-primary:active { transform:translateY(0); }
.sa-btn-primary:disabled { opacity:.4; cursor:not-allowed; transform:none; box-shadow:none; }
.sa-btn-sm { height:34px; padding:0 14px; font-size:12px; border-radius:7px; }
.sa-btn-outline {
    background:transparent; border:1.5px solid var(--sa-border); color:var(--sa-t2);
}
.sa-btn-outline:hover { background:var(--sa-bg3); border-color:var(--sa-primary); color:var(--sa-primary); }
.sa-btn-ghost {
    background:transparent; border:none; color:var(--sa-t3);
    padding:0 10px;
}
.sa-btn-ghost:hover { color:var(--sa-primary); }

/* ── Stats Strip ── */
.sa-stats-strip {
    display:none; gap:10px; margin-bottom:16px;
    grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));
}
.sa-stats-strip.visible { display:grid; }
.sa-stat-card {
    padding:14px 16px; border-radius:var(--sa-r);
    background:var(--sa-card); border:1px solid var(--sa-border);
    box-shadow:var(--sa-shadow); text-align:center;
    transition:var(--sa-ease);
}
.sa-stat-card:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.08); }
.sa-stat-num {
    font-family:var(--sa-mono); font-size:24px; font-weight:800;
    line-height:1.1;
}
.sa-stat-label {
    font-family:var(--sa-font); font-size:10.5px; font-weight:600;
    text-transform:uppercase; letter-spacing:.6px; color:var(--sa-t3);
    margin-top:4px;
}

/* ── Toolbar ── */
.sa-toolbar {
    display:none; flex-wrap:wrap; gap:10px; align-items:center;
    padding:12px 18px; border-radius:var(--sa-r);
    background:var(--sa-card); border:1px solid var(--sa-border);
    box-shadow:var(--sa-shadow); margin-bottom:16px;
}
.sa-toolbar.visible { display:flex; }
.sa-toolbar-group { display:flex; gap:8px; align-items:center; }
.sa-toolbar-sep { width:1px; height:26px; background:var(--sa-border); margin:0 4px; }
.sa-day-input {
    width:52px; height:34px; text-align:center; font-family:var(--sa-mono);
    font-size:13px; font-weight:600; border:1.5px solid var(--sa-border); border-radius:7px;
    background:var(--sa-bg); color:var(--sa-t1); transition:var(--sa-ease);
}
.sa-day-input:focus { outline:none; border-color:var(--sa-primary); box-shadow:0 0 0 3px var(--sa-primary-ring); }

/* ── Legend ── */
.sa-legend {
    display:flex; flex-wrap:wrap; gap:14px; align-items:center;
    margin-left:auto; font-family:var(--sa-font); font-size:11.5px; color:var(--sa-t2);
}
.sa-legend-chip {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px 3px 4px; border-radius:6px;
    background:var(--sa-bg); border:1px solid var(--sa-border);
}
.sa-legend-pip {
    width:22px; height:22px; border-radius:5px; display:inline-flex;
    align-items:center; justify-content:center;
    font-size:10px; font-weight:800; color:#fff;
}

/* ── Attendance Grid ── */
.sa-grid-wrap {
    border-radius:var(--sa-r); background:var(--sa-card);
    border:1px solid var(--sa-border); box-shadow:var(--sa-shadow);
    overflow:auto; max-height:calc(100vh - 280px);
    scrollbar-width:thin; scrollbar-color:var(--sa-border) transparent;
}
.sa-grid-wrap::-webkit-scrollbar { width:6px; height:6px; }
.sa-grid-wrap::-webkit-scrollbar-thumb { background:var(--sa-border); border-radius:3px; }

.sa-grid {
    width:max-content; min-width:100%; border-collapse:separate;
    border-spacing:0; font-family:var(--sa-font);
}

/* ── Sticky columns ── */
.sa-grid th:nth-child(1),
.sa-grid td:nth-child(1),
.sa-grid th:nth-child(2),
.sa-grid td:nth-child(2) { position:sticky; z-index:5; }
.sa-grid th:nth-child(1),
.sa-grid td:nth-child(1) { left:0; }
.sa-grid th:nth-child(2),
.sa-grid td:nth-child(2) { left:42px; }
.sa-grid td:nth-child(1),
.sa-grid td:nth-child(2) { background:var(--sa-card); }

.sa-grid thead { position:sticky; top:0; z-index:12; }
.sa-grid thead th:nth-child(1),
.sa-grid thead th:nth-child(2) { z-index:15; }

.sa-grid th {
    background:var(--sa-bg3); color:var(--sa-t3); font-size:10px;
    font-weight:700; text-transform:uppercase; letter-spacing:.5px;
    padding:11px 6px; text-align:center; white-space:nowrap;
    border-bottom:2px solid var(--sa-border);
}
.sa-grid th.sa-th-idx { width:42px; text-align:center; padding-left:10px; }
.sa-grid th.sa-th-student { text-align:left; padding-left:14px; min-width:200px; }
.sa-grid th.sa-th-day { width:38px; }
.sa-grid th.sa-th-pct { min-width:100px; }
.sa-grid th.sa-col-sun { background:rgba(239,68,68,.10); color:#dc2626; }
.sa-grid th.sa-col-hol { background:rgba(139,92,246,.14); color:#7c3aed; }

.sa-grid td {
    padding:4px; text-align:center; border-bottom:1px solid var(--sa-border);
    font-size:13px; color:var(--sa-t1); vertical-align:middle;
}
.sa-grid td.sa-td-idx {
    font-family:var(--sa-mono); font-size:11px; color:var(--sa-t3);
    padding:4px 8px; text-align:center;
}
.sa-grid td.sa-td-student {
    text-align:left; padding:6px 14px;
}
.sa-td-student-inner {
    display:flex; align-items:center; gap:10px;
}
.sa-avatar {
    width:32px; height:32px; border-radius:8px; display:flex;
    align-items:center; justify-content:center;
    font-family:var(--sa-font); font-size:12px; font-weight:800;
    color:#fff; flex-shrink:0; text-transform:uppercase;
    background:var(--sa-primary);
}
.sa-avatar.av-1 { background:#0f766e; }
.sa-avatar.av-2 { background:#7c3aed; }
.sa-avatar.av-3 { background:#2563eb; }
.sa-avatar.av-4 { background:#dc2626; }
.sa-avatar.av-5 { background:#d97706; }
.sa-avatar.av-6 { background:#059669; }

.sa-stu-info { overflow:hidden; }
.sa-stu-name {
    font-weight:600; font-size:13px; color:var(--sa-t1);
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px;
    line-height:1.3;
}
.sa-stu-id {
    font-family:var(--sa-mono); font-size:10px; color:var(--sa-t3);
    letter-spacing:.3px;
}

.sa-grid td.sa-col-sun { background:var(--sa-sun-bg); border-left:1px solid rgba(239,68,68,.10); border-right:1px solid rgba(239,68,68,.10); }
.sa-grid td.sa-col-hol { background:var(--sa-hol-bg); border-left:1px solid rgba(139,92,246,.10); border-right:1px solid rgba(139,92,246,.10); }

.sa-grid td.sa-td-pct { padding:6px 10px; }
.sa-pct-wrap {
    display:flex; align-items:center; gap:8px;
}
.sa-pct-bar-track {
    flex:1; height:6px; border-radius:3px; background:var(--sa-bg3);
    overflow:hidden; min-width:40px;
}
.sa-pct-bar-fill {
    height:100%; border-radius:3px; transition:width .4s ease;
}
.sa-pct-num {
    font-family:var(--sa-mono); font-size:11px; font-weight:700;
    min-width:32px; text-align:right;
}
.sa-pct-counts {
    font-family:var(--sa-mono); font-size:10px; color:var(--sa-t3);
    display:flex; gap:6px; margin-top:2px;
}

.sa-grid tbody tr { transition:background .15s ease; }
.sa-grid tbody tr:hover td { background:var(--sa-bg3); }
.sa-grid tbody tr:hover td:nth-child(1),
.sa-grid tbody tr:hover td:nth-child(2) { background:var(--sa-bg3); }
.sa-grid tbody tr:hover td.sa-col-sun { background:rgba(239,68,68,.12); }
.sa-grid tbody tr:hover td.sa-col-hol { background:rgba(139,92,246,.16); }

/* ── Day Cells ── */
.sa-cell {
    width:30px; height:30px; border-radius:7px; display:inline-flex;
    align-items:center; justify-content:center; font-size:10px; font-weight:800;
    cursor:pointer; user-select:none; transition:transform .12s ease, box-shadow .15s ease;
    position:relative; line-height:1; letter-spacing:.3px;
}
.sa-cell:hover { transform:scale(1.18); z-index:2; }
.sa-cell:active { transform:scale(.92); }
.sa-cell.sa-dirty { box-shadow:0 0 0 2.5px var(--sa-dirty); }

.sa-cell[data-v="P"] { background:var(--sa-p-bg); color:var(--sa-p); }
.sa-cell[data-v="A"] { background:var(--sa-a-bg); color:var(--sa-a); }
.sa-cell[data-v="L"] { background:var(--sa-l-bg); color:var(--sa-l); }
.sa-cell[data-v="T"] { background:var(--sa-t-bg); color:var(--sa-t); }
.sa-cell[data-v="H"] { background:var(--sa-h-bg); color:var(--sa-h); }
.sa-cell[data-v="V"] { background:var(--sa-v-bg); color:var(--sa-v); }

.sa-cell .sa-late-dot {
    position:absolute; top:-2px; right:-2px; width:10px; height:10px;
    background:var(--sa-t); border-radius:50%;
    border:2px solid var(--sa-card);
}

/* ── Toast ── */
.sa-toast {
    position:fixed; bottom:24px; right:24px; z-index:9999;
    padding:14px 24px; border-radius:10px; font-family:var(--sa-font);
    font-size:13px; font-weight:600; color:#fff;
    box-shadow:0 10px 30px rgba(0,0,0,.2);
    transform:translateY(120px); opacity:0; transition:all .35s cubic-bezier(.4,0,.2,1);
    pointer-events:none; display:flex; align-items:center; gap:8px;
}
.sa-toast.show { transform:translateY(0); opacity:1; }
.sa-toast.success { background:var(--sa-p); }
.sa-toast.error { background:var(--sa-a); }

/* ── Modal ── */
.sa-modal-overlay {
    position:fixed; inset:0; z-index:9998; background:rgba(0,0,0,.45);
    display:none; align-items:center; justify-content:center;
    backdrop-filter:blur(6px);
}
.sa-modal-overlay.open { display:flex; }
.sa-modal {
    background:var(--sa-card); border:1px solid var(--sa-border);
    border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.25);
    width:440px; max-width:92vw; max-height:80vh; overflow-y:auto;
    padding:28px; animation:sa-modal-in .25s ease;
}
@keyframes sa-modal-in {
    from { opacity:0; transform:scale(.95) translateY(10px); }
    to { opacity:1; transform:scale(1) translateY(0); }
}
.sa-modal-head {
    display:flex; align-items:center; justify-content:space-between;
    margin-bottom:20px;
}
.sa-modal h3 {
    font-family:var(--sa-font); font-size:16px; font-weight:700;
    color:var(--sa-t1); margin:0;
}
.sa-modal-close {
    width:32px; height:32px; display:flex; align-items:center; justify-content:center;
    background:var(--sa-bg3); border:none; border-radius:8px; font-size:16px;
    color:var(--sa-t3); cursor:pointer; transition:var(--sa-ease);
}
.sa-modal-close:hover { background:var(--sa-a-bg); color:var(--sa-a); }
.sa-modal-stat {
    display:flex; justify-content:space-between; align-items:center;
    padding:10px 0; border-bottom:1px solid var(--sa-border);
    font-family:var(--sa-font); font-size:13px; color:var(--sa-t2);
}
.sa-modal-stat:last-of-type { border-bottom:none; }
.sa-modal-stat span:last-child { font-weight:700; color:var(--sa-t1); }
.sa-modal-stat .sa-stat-dot {
    width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:6px;
}
.sa-modal-bar-wrap {
    margin-top:18px; height:10px; border-radius:5px;
    background:var(--sa-bg3); overflow:hidden; display:flex;
}
.sa-modal-bar-seg { height:100%; transition:width .4s ease; }

/* ── Loading ── */
.sa-loading {
    display:none; text-align:center; padding:60px 20px;
    font-family:var(--sa-font); font-size:14px; color:var(--sa-t3);
}
.sa-loading.visible { display:block; }
.sa-spinner {
    width:36px; height:36px; border:3px solid var(--sa-border);
    border-top-color:var(--sa-primary); border-radius:50%;
    animation:sa-spin .7s linear infinite; margin:0 auto 14px;
}
@keyframes sa-spin { to { transform:rotate(360deg); } }

/* ── Empty State ── */
.sa-empty {
    text-align:center; padding:70px 20px; font-family:var(--sa-font); color:var(--sa-t3);
}
.sa-empty i { font-size:52px; margin-bottom:14px; display:block; opacity:.3; }
.sa-empty p { font-size:14px; margin:4px 0 0; }
.sa-empty strong { color:var(--sa-t2); }

/* ── Responsive ── */
@media (max-width:767px) {
    .sa-filter-card { flex-direction:column; align-items:stretch; }
    .sa-fg select { min-width:100%; }
    .sa-toolbar { flex-direction:column; align-items:stretch; }
    .sa-legend { margin-left:0; margin-top:8px; }
    .sa-toolbar-sep { display:none; }
    .sa-stats-strip.visible { grid-template-columns:repeat(2, 1fr); }
    .sa-modal { padding:18px; }
    .sa-page-head { flex-direction:column; align-items:flex-start; }
    .sa-grid th.sa-th-student { min-width:140px; }
    .sa-grid th:nth-child(2),
    .sa-grid td:nth-child(2) { left:36px; }
    .sa-grid th:nth-child(1) { width:36px; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="sa-page-head">
        <div>
            <h4 class="sa-page-title">
                <i class="fa fa-calendar-check-o"></i> Student Attendance
            </h4>
            <p class="sa-page-sub">Mark and manage daily student attendance by class &amp; section</p>
        </div>
        <span class="sa-badge-month" id="saMonthBadge">
            <i class="fa fa-calendar"></i> <span id="saMonthLabel"></span>
        </span>
    </div>

    <!-- Filter Card -->
    <div class="sa-filter-card">
        <div class="sa-fg">
            <label for="attClass">Class</label>
            <select id="attClass">
                <option value="">Select Class</option>
                <?php
                    $uniqueClasses = [];
                    if (!empty($Classes)) {
                        foreach ($Classes as $c) {
                            $cn = htmlspecialchars($c['class_name'], ENT_QUOTES, 'UTF-8');
                            if (!in_array($cn, $uniqueClasses)) {
                                $uniqueClasses[] = $cn;
                                echo '<option value="' . $cn . '">' . $cn . '</option>';
                            }
                        }
                    }
                ?>
            </select>
        </div>
        <div class="sa-fg">
            <label for="attSection">Section</label>
            <select id="attSection" disabled>
                <option value="">Select Section</option>
            </select>
        </div>
        <div class="sa-fg">
            <label for="attMonth">Month</label>
            <select id="attMonth">
                <?php
                    $currentMonth    = date('F');
                    $currentMonthNum = (int) date('n');
                    $currentYear     = (int) date('Y');
                    $monthNumMap = [
                        'January'=>1,'February'=>2,'March'=>3,'April'=>4,'May'=>5,'June'=>6,
                        'July'=>7,'August'=>8,'September'=>9,'October'=>10,'November'=>11,'December'=>12
                    ];
                    $sessParts  = explode('-', isset($session_year) ? $session_year : '');
                    $sessStart  = (int)($sessParts[0] ?? $currentYear);
                    if ($sessStart < 100) $sessStart += 2000;
                    $acadStart  = 4; // April
                    $sessionOn  = ($currentYear > $sessStart) || ($currentYear === $sessStart && $currentMonthNum >= $acadStart);

                    if (!empty($months)) {
                        foreach ($months as $m) {
                            $ms   = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
                            $mNum = $monthNumMap[$m] ?? 0;
                            if (!$sessionOn) {
                                // Session hasn't started — disable months after current calendar month
                                $isFuture = ($mNum > $currentMonthNum);
                            } else {
                                // Session running — compute actual calendar date for this month
                                $mYear    = ($mNum >= $acadStart) ? $sessStart : $sessStart + 1;
                                $lastDay  = cal_days_in_month(CAL_GREGORIAN, $currentMonthNum, $currentYear);
                                $isFuture = mktime(0,0,0, $mNum, 1, $mYear) > mktime(23,59,59, $currentMonthNum, $lastDay, $currentYear);
                            }
                            $sel = ($ms === $currentMonth) ? ' selected' : '';
                            $dis = $isFuture ? ' disabled style="opacity:.4;cursor:not-allowed"' : '';
                            echo '<option value="' . $ms . '"' . $sel . $dis . '>' . $ms . ($isFuture ? ' (upcoming)' : '') . '</option>';
                        }
                    }
                ?>
            </select>
        </div>
        <div class="sa-fg" style="align-self:flex-end;">
            <button type="button" class="sa-btn sa-btn-primary" id="attLoadBtn">
                <i class="fa fa-search"></i> Load Attendance
            </button>
        </div>
    </div>

    <!-- Stats Strip -->
    <div class="sa-stats-strip" id="saStatsStrip">
        <div class="sa-stat-card">
            <div class="sa-stat-num" style="color:var(--sa-t1)" id="ssTotalStudents">0</div>
            <div class="sa-stat-label">Students</div>
        </div>
        <div class="sa-stat-card">
            <div class="sa-stat-num" style="color:var(--sa-p)" id="ssTotalPresent">0</div>
            <div class="sa-stat-label">Total Present</div>
        </div>
        <div class="sa-stat-card">
            <div class="sa-stat-num" style="color:var(--sa-a)" id="ssTotalAbsent">0</div>
            <div class="sa-stat-label">Total Absent</div>
        </div>
        <div class="sa-stat-card">
            <div class="sa-stat-num" style="color:var(--sa-l)" id="ssTotalLeave">0</div>
            <div class="sa-stat-label">On Leave</div>
        </div>
        <div class="sa-stat-card">
            <div class="sa-stat-num" style="color:var(--sa-primary)" id="ssAvgPct">0%</div>
            <div class="sa-stat-label">Avg Attendance</div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="sa-toolbar" id="attToolbar">
        <div class="sa-toolbar-group">
            <button type="button" class="sa-btn sa-btn-primary sa-btn-sm" id="attSaveBtn" disabled>
                <i class="fa fa-save"></i> Save Changes
            </button>
            <span id="saDirtyCount" style="font-family:var(--sa-font);font-size:11px;color:var(--sa-t3);display:none;">
                <i class="fa fa-pencil"></i> <em id="saDirtyNum">0</em> modified
            </span>
        </div>
        <div class="sa-toolbar-sep"></div>
        <div class="sa-toolbar-group">
            <label style="font-family:var(--sa-font);font-size:11px;font-weight:700;color:var(--sa-t3);letter-spacing:.5px;">DAY:</label>
            <input type="number" id="attDayPicker" class="sa-day-input" min="1" max="31" value="1">
            <button type="button" class="sa-btn sa-btn-outline sa-btn-sm" data-bulk="P">
                <i class="fa fa-check"></i> All Present
            </button>
            <button type="button" class="sa-btn sa-btn-outline sa-btn-sm" data-bulk="A">
                <i class="fa fa-times"></i> All Absent
            </button>
            <button type="button" class="sa-btn sa-btn-outline sa-btn-sm" data-bulk="H">
                <i class="fa fa-star"></i> Holiday
            </button>
        </div>
        <div class="sa-toolbar-sep"></div>
        <div class="sa-legend">
            <span class="sa-legend-chip"><span class="sa-legend-pip" style="background:var(--sa-p);">P</span> Present</span>
            <span class="sa-legend-chip"><span class="sa-legend-pip" style="background:var(--sa-a);">A</span> Absent</span>
            <span class="sa-legend-chip"><span class="sa-legend-pip" style="background:var(--sa-l);">L</span> Leave</span>
            <span class="sa-legend-chip"><span class="sa-legend-pip" style="background:var(--sa-t);">T</span> Late</span>
            <span class="sa-legend-chip"><span class="sa-legend-pip" style="background:var(--sa-h);">H</span> Holiday</span>
            <span class="sa-legend-chip"><span class="sa-legend-pip" style="background:var(--sa-v);">V</span> Vacant</span>
        </div>
    </div>

    <!-- Loading -->
    <div class="sa-loading" id="attLoading">
        <div class="sa-spinner"></div>
        Loading attendance data&hellip;
    </div>

    <!-- Empty State -->
    <div class="sa-empty" id="attEmpty" style="display:none;">
        <i class="fa fa-calendar-o"></i>
        <p><strong>No data loaded</strong></p>
        <p>Select a class, section, and month, then click <strong>Load Attendance</strong></p>
    </div>

    <!-- Grid -->
    <div class="sa-grid-wrap" id="attGridWrap" style="display:none;">
        <table class="sa-grid" id="attGrid">
            <thead id="attGridHead"></thead>
            <tbody id="attGridBody"></tbody>
        </table>
    </div>

    <!-- Student Summary Modal -->
    <div class="sa-modal-overlay" id="attModal">
        <div class="sa-modal">
            <div class="sa-modal-head">
                <h3 id="attModalTitle">Student Summary</h3>
                <button class="sa-modal-close" id="attModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div id="attModalBody"></div>
        </div>
    </div>

    <!-- Toast -->
    <div class="sa-toast" id="attToast"></div>

</div>
</section>
</div>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    var classesData = <?= json_encode($Classes ?: []) ?>;

    var AVATAR_COLORS = ['av-1','av-2','av-3','av-4','av-5','av-6'];

    /* ── State ── */
    var state = {
        students: [],
        daysInMonth: 0,
        sundays: [],
        holidays: {},
        month: '',
        year: 0,
        attendance: {},
        original: {},
        dirty: new Set()
    };

    /* ── Refs ── */
    var elClass    = document.getElementById('attClass');
    var elSection  = document.getElementById('attSection');
    var elMonth    = document.getElementById('attMonth');
    var elLoadBtn  = document.getElementById('attLoadBtn');
    var elToolbar  = document.getElementById('attToolbar');
    var elSaveBtn  = document.getElementById('attSaveBtn');
    var elDayPick  = document.getElementById('attDayPicker');
    var elLoading  = document.getElementById('attLoading');
    var elEmpty    = document.getElementById('attEmpty');
    var elGridWrap = document.getElementById('attGridWrap');
    var elHead     = document.getElementById('attGridHead');
    var elBody     = document.getElementById('attGridBody');
    var elModal    = document.getElementById('attModal');
    var elModalTitle = document.getElementById('attModalTitle');
    var elModalBody  = document.getElementById('attModalBody');
    var elToast    = document.getElementById('attToast');
    var elStatsStrip = document.getElementById('saStatsStrip');
    var elMonthBadge = document.getElementById('saMonthBadge');
    var elMonthLabel = document.getElementById('saMonthLabel');
    var elDirtyCount = document.getElementById('saDirtyCount');
    var elDirtyNum   = document.getElementById('saDirtyNum');

    /* ── Helpers ── */
    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    var CYCLE = ['V','P','A','L','T','H'];

    function nextMark(v) {
        var i = CYCLE.indexOf(v);
        return CYCLE[(i + 1) % CYCLE.length];
    }

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
        return parts[0].substring(0,2).toUpperCase();
    }

    function showToast(msg, type) {
        elToast.innerHTML = '<i class="fa fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + '"></i> ' + esc(msg);
        elToast.className = 'sa-toast ' + (type || 'success');
        setTimeout(function(){ elToast.classList.add('show'); }, 10);
        setTimeout(function(){ elToast.classList.remove('show'); }, 3000);
    }

    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) {
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        }
        return fetch(BASE + url, { method: 'POST', body: fd })
            .then(function(r) {
                var ct = r.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1) return r.json();
                return r.text().then(function(t) {
                    try { return JSON.parse(t); } catch(e) { throw new Error('Invalid response'); }
                });
            })
            .then(function(j) {
                if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash;
                return j;
            });
    }

    /* ── Section Dropdown ── */
    elClass.addEventListener('change', function(){
        var cls = elClass.value;
        elSection.innerHTML = '<option value="">Select Section</option>';
        if (!cls) { elSection.disabled = true; return; }
        var seen = {};
        classesData.forEach(function(c){
            if (c.class_name === cls && !seen[c.section]) {
                seen[c.section] = true;
                var o = document.createElement('option');
                o.value = c.section;
                o.textContent = c.section;
                elSection.appendChild(o);
            }
        });
        elSection.disabled = false;
    });

    /* ── Load ── */
    elLoadBtn.addEventListener('click', loadAttendance);

    function loadAttendance() {
        var cls = elClass.value, sec = elSection.value, mon = elMonth.value;
        if (!cls || !sec || !mon) {
            showToast('Please select class, section, and month.', 'error');
            return;
        }
        elGridWrap.style.display = 'none';
        elEmpty.style.display = 'none';
        elToolbar.classList.remove('visible');
        elStatsStrip.classList.remove('visible');
        elMonthBadge.style.display = 'none';
        elLoading.classList.add('visible');

        postData('attendance/fetch_student', { 'class': cls, section: sec, month: mon })
            .then(function(res) {
                elLoading.classList.remove('visible');
                if (!res || res.status === 'error') {
                    showToast(res ? res.message : 'Failed to load data.', 'error');
                    elEmpty.style.display = 'block';
                    return;
                }
                state.students = res.students || [];
                state.daysInMonth = parseInt(res.daysInMonth, 10) || 30;
                state.sundays = res.sundays || [];
                state.holidays = res.holidays || {};
                state.month = res.month || mon;
                state.year = parseInt(res.year, 10) || new Date().getFullYear();
                state.dirty = new Set();

                /* Parse attendance strings into arrays */
                state.attendance = {};
                state.original = {};
                state.students.forEach(function(s) {
                    var str = s.attendance || '';
                    var arr = [];
                    for (var d = 0; d < state.daysInMonth; d++) {
                        arr.push(str.charAt(d) || 'V');
                    }
                    state.attendance[s.id] = arr;
                    state.original[s.id] = arr.join('');
                });

                elDayPick.max = state.daysInMonth;
                if (parseInt(elDayPick.value, 10) > state.daysInMonth) elDayPick.value = 1;

                /* Month badge */
                elMonthLabel.textContent = state.month + ' ' + state.year;
                elMonthBadge.style.display = 'inline-flex';

                renderGrid();
                updateStats();
                elGridWrap.style.display = 'block';
                elToolbar.classList.add('visible');
                elStatsStrip.classList.add('visible');
                updateSaveBtn();
            })
            .catch(function() {
                elLoading.classList.remove('visible');
                showToast('Network error loading attendance.', 'error');
                elEmpty.style.display = 'block';
            });
    }

    /* ── Update Stats ── */
    function updateStats() {
        var totalP = 0, totalA = 0, totalL = 0, totalWorking = 0, totalPresent = 0;
        state.students.forEach(function(s) {
            var arr = state.attendance[s.id];
            var c = {P:0,A:0,L:0,T:0,H:0,V:0};
            arr.forEach(function(v){ if (c[v] !== undefined) c[v]++; });
            totalP += c.P + c.T;
            totalA += c.A;
            totalL += c.L;
            var w = state.daysInMonth - c.H - c.V;
            totalWorking += w;
            totalPresent += c.P + c.T;
        });
        document.getElementById('ssTotalStudents').textContent = state.students.length;
        document.getElementById('ssTotalPresent').textContent = totalP;
        document.getElementById('ssTotalAbsent').textContent = totalA;
        document.getElementById('ssTotalLeave').textContent = totalL;
        var avgPct = totalWorking > 0 ? Math.round(totalPresent / totalWorking * 100) : 0;
        document.getElementById('ssAvgPct').textContent = avgPct + '%';
    }

    /* ── Render Grid ── */
    function renderGrid() {
        var sundaySet = {};
        state.sundays.forEach(function(d){ sundaySet[d] = true; });
        var holidaySet = {};
        Object.keys(state.holidays).forEach(function(d){ holidaySet[parseInt(d,10)] = state.holidays[d]; });

        /* Day names for header */
        var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

        /* Header */
        var hHtml = '<tr><th class="sa-th-idx">#</th><th class="sa-th-student">Student</th>';
        for (var d = 1; d <= state.daysInMonth; d++) {
            var cls = 'sa-th-day';
            if (sundaySet[d]) cls += ' sa-col-sun';
            if (holidaySet[d]) cls += ' sa-col-hol';
            var dt = new Date(state.year, getMonthIndex(state.month), d);
            var dn = dayNames[dt.getDay()];
            hHtml += '<th class="' + cls + '" title="' + dn + ', ' + state.month + ' ' + d + '">';
            var isSun = sundaySet[d];
            hHtml += '<div style="line-height:1.2' + (isSun ? ';color:#dc2626;font-weight:900' : '') + '">' + d + '</div>';
            hHtml += '<div style="font-size:8px;font-weight:' + (isSun ? '700' : '500') + ';letter-spacing:0;' + (isSun ? 'color:#dc2626;opacity:1' : 'opacity:.6') + '">' + dn.charAt(0) + '</div>';
            hHtml += '</th>';
        }
        hHtml += '<th class="sa-th-pct">Attendance</th></tr>';
        elHead.innerHTML = hHtml;

        /* Body */
        var bHtml = '';
        state.students.forEach(function(s, idx) {
            var att = state.attendance[s.id];
            var avColor = AVATAR_COLORS[idx % AVATAR_COLORS.length];

            bHtml += '<tr data-sid="' + esc(s.id) + '">';
            bHtml += '<td class="sa-td-idx">' + (idx + 1) + '</td>';
            bHtml += '<td class="sa-td-student"><div class="sa-td-student-inner">';
            bHtml += '<div class="sa-avatar ' + avColor + '">' + getInitials(s.name) + '</div>';
            bHtml += '<div class="sa-stu-info">';
            bHtml += '<div class="sa-stu-name" title="' + esc(s.name) + '">' + esc(s.name) + '</div>';
            bHtml += '<div class="sa-stu-id">' + esc(s.id) + '</div>';
            bHtml += '</div></div></td>';

            for (var d = 0; d < state.daysInMonth; d++) {
                var day = d + 1;
                var v = att[d];
                var tdCls = '';
                if (sundaySet[day]) tdCls += ' sa-col-sun';
                if (holidaySet[day]) tdCls += ' sa-col-hol';
                var dirtyMark = state.dirty.has(s.id) && att[d] !== state.original[s.id].charAt(d) ? ' sa-dirty' : '';
                var lateDot = v === 'T' ? '<span class="sa-late-dot"></span>' : '';
                bHtml += '<td class="' + tdCls + '">';
                bHtml += '<span class="sa-cell' + dirtyMark + '" data-v="' + v + '" data-sid="' + esc(s.id) + '" data-d="' + d + '">';
                bHtml += v + lateDot + '</span></td>';
            }

            /* Percentage bar */
            var c = {P:0,A:0,L:0,T:0,H:0,V:0};
            att.forEach(function(v){ if (c[v] !== undefined) c[v]++; });
            var working = state.daysInMonth - c.H - c.V;
            var pct = working > 0 ? Math.round((c.P + c.T) / working * 100) : 0;
            var barColor = pct >= 75 ? 'var(--sa-p)' : (pct >= 50 ? 'var(--sa-l)' : 'var(--sa-a)');

            bHtml += '<td class="sa-td-pct"><div class="sa-pct-wrap">';
            bHtml += '<div class="sa-pct-bar-track"><div class="sa-pct-bar-fill" style="width:' + pct + '%;background:' + barColor + '"></div></div>';
            bHtml += '<span class="sa-pct-num" style="color:' + barColor + '">' + pct + '%</span>';
            bHtml += '</div>';
            bHtml += '<div class="sa-pct-counts">';
            bHtml += '<span style="color:var(--sa-p)">P:' + (c.P+c.T) + '</span>';
            bHtml += '<span style="color:var(--sa-a)">A:' + c.A + '</span>';
            bHtml += '<span style="color:var(--sa-l)">L:' + c.L + '</span>';
            bHtml += '</div></td>';
            bHtml += '</tr>';
        });
        elBody.innerHTML = bHtml;
    }

    function getMonthIndex(monthName) {
        var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var idx = months.indexOf(monthName);
        return idx >= 0 ? idx : 0;
    }

    function summaryPct(arr) {
        var c = {P:0,A:0,L:0,T:0,H:0,V:0};
        arr.forEach(function(v){ if (c[v] !== undefined) c[v]++; });
        var working = state.daysInMonth - c.H - c.V;
        return { counts: c, pct: working > 0 ? Math.round((c.P + c.T) / working * 100) : 0 };
    }

    function updateCell(sid, d) {
        var cell = elBody.querySelector('.sa-cell[data-sid="' + CSS.escape(sid) + '"][data-d="' + d + '"]');
        if (!cell) return;
        var v = state.attendance[sid][d];
        cell.setAttribute('data-v', v);
        var isDirty = v !== state.original[sid].charAt(d);
        cell.classList.toggle('sa-dirty', isDirty);
        cell.innerHTML = v + (v === 'T' ? '<span class="sa-late-dot"></span>' : '');

        /* Update percentage bar */
        var row = cell.closest('tr');
        if (row) {
            var pctTd = row.querySelector('.sa-td-pct');
            if (pctTd) {
                var s = summaryPct(state.attendance[sid]);
                var barColor = s.pct >= 75 ? 'var(--sa-p)' : (s.pct >= 50 ? 'var(--sa-l)' : 'var(--sa-a)');
                var fill = pctTd.querySelector('.sa-pct-bar-fill');
                var num = pctTd.querySelector('.sa-pct-num');
                var counts = pctTd.querySelector('.sa-pct-counts');
                if (fill) { fill.style.width = s.pct + '%'; fill.style.background = barColor; }
                if (num) { num.textContent = s.pct + '%'; num.style.color = barColor; }
                if (counts) {
                    counts.innerHTML = '<span style="color:var(--sa-p)">P:' + (s.counts.P+s.counts.T) + '</span>'
                        + '<span style="color:var(--sa-a)">A:' + s.counts.A + '</span>'
                        + '<span style="color:var(--sa-l)">L:' + s.counts.L + '</span>';
                }
            }
        }
    }

    function updateSaveBtn() {
        elSaveBtn.disabled = state.dirty.size === 0;
        if (state.dirty.size > 0) {
            elDirtyNum.textContent = state.dirty.size;
            elDirtyCount.style.display = 'inline';
        } else {
            elDirtyCount.style.display = 'none';
        }
    }

    function markDirty(sid) {
        var arr = state.attendance[sid];
        var orig = state.original[sid];
        if (arr.join('') !== orig) {
            state.dirty.add(sid);
        } else {
            state.dirty.delete(sid);
        }
        updateSaveBtn();
        updateStats();
    }

    /* ── Cell Click ── */
    elBody.addEventListener('click', function(e) {
        var cell = e.target.closest('.sa-cell');
        if (!cell) return;
        var sid = cell.getAttribute('data-sid');
        var d = parseInt(cell.getAttribute('data-d'), 10);
        var curr = state.attendance[sid][d];
        state.attendance[sid][d] = nextMark(curr);
        updateCell(sid, d);
        markDirty(sid);
    });

    /* ── Double-click Row → Modal ── */
    elBody.addEventListener('dblclick', function(e) {
        var row = e.target.closest('tr');
        if (!row) return;
        var sid = row.getAttribute('data-sid');
        if (!sid || !state.attendance[sid]) return;
        showStudentModal(sid);
    });

    function showStudentModal(sid) {
        var student = null;
        state.students.forEach(function(s){ if (s.id === sid) student = s; });
        if (!student) return;

        var arr = state.attendance[sid];
        var c = {P:0,A:0,L:0,T:0,H:0,V:0};
        arr.forEach(function(v){ if (c[v] !== undefined) c[v]++; });
        var working = state.daysInMonth - c.H - c.V;
        var pct = working > 0 ? Math.round((c.P + c.T) / working * 100) : 0;

        elModalTitle.textContent = student.name;

        var html = '';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-t2)"></span>Total Days</span><span>' + state.daysInMonth + '</span></div>';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-p)"></span>Present</span><span style="color:var(--sa-p)">' + c.P + '</span></div>';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-a)"></span>Absent</span><span style="color:var(--sa-a)">' + c.A + '</span></div>';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-l)"></span>Leave</span><span style="color:var(--sa-l)">' + c.L + '</span></div>';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-t)"></span>Late</span><span style="color:var(--sa-t)">' + c.T + '</span></div>';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-h)"></span>Holiday</span><span style="color:var(--sa-h)">' + c.H + '</span></div>';
        html += '<div class="sa-modal-stat"><span><span class="sa-stat-dot" style="background:var(--sa-v)"></span>Vacant</span><span style="color:var(--sa-v)">' + c.V + '</span></div>';
        html += '<div class="sa-modal-stat" style="font-weight:700;border-bottom:none;padding-top:14px;"><span>Attendance %</span><span style="font-size:18px;color:' + (pct >= 75 ? 'var(--sa-p)' : pct >= 50 ? 'var(--sa-l)' : 'var(--sa-a)') + '">' + pct + '%</span></div>';

        var total = c.P + c.A + c.L + c.T + c.H + c.V;
        html += '<div class="sa-modal-bar-wrap">';
        if (total > 0) {
            var segments = [
                {v:c.P, color:'var(--sa-p)'}, {v:c.T, color:'var(--sa-t)'},
                {v:c.L, color:'var(--sa-l)'}, {v:c.A, color:'var(--sa-a)'},
                {v:c.H, color:'var(--sa-h)'}, {v:c.V, color:'var(--sa-v)'}
            ];
            segments.forEach(function(seg){
                if (seg.v > 0) {
                    html += '<div class="sa-modal-bar-seg" style="width:' + (seg.v/total*100) + '%;background:' + seg.color + ';"></div>';
                }
            });
        }
        html += '</div>';

        elModalBody.innerHTML = html;
        elModal.classList.add('open');
    }

    document.getElementById('attModalClose').addEventListener('click', function() {
        elModal.classList.remove('open');
    });
    elModal.addEventListener('click', function(e) {
        if (e.target === elModal) elModal.classList.remove('open');
    });

    /* ── Bulk Actions ── */
    document.querySelectorAll('[data-bulk]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mark = btn.getAttribute('data-bulk');
            var day = parseInt(elDayPick.value, 10);
            if (day < 1 || day > state.daysInMonth) {
                showToast('Invalid day number.', 'error');
                return;
            }
            var d = day - 1;
            state.students.forEach(function(s) {
                state.attendance[s.id][d] = mark;
                updateCell(s.id, d);
                markDirty(s.id);
            });
        });
    });

    /* ── Save ── */
    elSaveBtn.addEventListener('click', function() {
        if (state.dirty.size === 0) return;

        var cls = elClass.value;
        var sec = elSection.value;
        var mon = elMonth.value;

        var attObj = {};
        var lateObj = {};
        state.dirty.forEach(function(sid) {
            var str = state.attendance[sid].join('');
            attObj[sid] = str;
            var lateDays = [];
            state.attendance[sid].forEach(function(v, i) {
                if (v === 'T') lateDays.push(i + 1);
            });
            if (lateDays.length > 0) lateObj[sid] = lateDays;
        });

        elSaveBtn.disabled = true;
        elSaveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        postData('attendance/save_student', {
            'class': cls,
            section: sec,
            month: mon,
            attendance: JSON.stringify(attObj),
            late: JSON.stringify(lateObj)
        })
        .then(function(res) {
            elSaveBtn.innerHTML = '<i class="fa fa-save"></i> Save Changes';
            if (res && res.status === 'success') {
                state.dirty.forEach(function(sid) {
                    state.original[sid] = state.attendance[sid].join('');
                });
                state.dirty = new Set();
                updateSaveBtn();
                elBody.querySelectorAll('.sa-dirty').forEach(function(c){ c.classList.remove('sa-dirty'); });
                showToast('Attendance saved successfully!', 'success');
            } else {
                showToast(res && res.message ? res.message : 'Failed to save attendance.', 'error');
                elSaveBtn.disabled = false;
            }
        })
        .catch(function() {
            elSaveBtn.innerHTML = '<i class="fa fa-save"></i> Save Changes';
            elSaveBtn.disabled = false;
            showToast('Network error while saving.', 'error');
        });
    });

    /* ── Warn on Leave ── */
    window.addEventListener('beforeunload', function(e) {
        if (state.dirty.size > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

})();
</script>
