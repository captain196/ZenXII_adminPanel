<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── Homework Tracking Module ───────────────────────────────── */
:root {
    --hw-primary: var(--gold);
    --hw-primary2: var(--gold2);
    --hw-primary-dim: var(--gold-dim);
    --hw-primary-ring: var(--gold-ring);
    --hw-primary-glow: var(--gold-glow);
    --hw-bg: var(--bg);
    --hw-bg2: var(--bg2);
    --hw-bg3: var(--bg3);
    --hw-border: var(--border);
    --hw-t1: var(--t1);
    --hw-t2: var(--t2);
    --hw-t3: var(--t3);
    --hw-shadow: var(--sh);
    --hw-radius: 12px;
    --hw-green: #16a34a;
    --hw-red: #dc2626;
    --hw-amber: #d97706;
    --hw-blue: #2563eb;
    --hw-purple: #7c3aed;
}

.hw-wrap { padding: 24px 28px 40px; font-family: var(--font-b, 'Plus Jakarta Sans', sans-serif); }

/* ── Page Header ──────────────────────────────────────────── */
.hw-page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 14px;
}
.hw-page-header h2 {
    margin: 0; font-size: 1.6rem; font-weight: 700; color: var(--hw-t1);
    display: flex; align-items: center; gap: 12px; font-family: var(--font-d);
}
.hw-page-header h2 i {
    color: var(--hw-primary); font-size: 1.3rem; width: 44px; height: 44px;
    display: flex; align-items: center; justify-content: center;
    background: var(--hw-primary-dim); border-radius: 10px;
}
.hw-page-sub { font-size: 0.9rem; color: var(--hw-t3); font-weight: 400; font-family: var(--font-b); }

/* ── Tabs ─────────────────────────────────────────────────── */
.hw-tabs {
    display: flex; gap: 4px; margin-bottom: 24px; flex-wrap: wrap;
    background: var(--hw-bg2); border-radius: 12px; padding: 6px;
    box-shadow: 0 2px 8px var(--hw-shadow); border: 1px solid var(--hw-border);
}
.hw-tab {
    padding: 11px 22px; cursor: pointer; font-weight: 600; color: var(--hw-t2);
    border-radius: 8px; transition: all .2s; font-size: 0.95rem; white-space: nowrap;
    display: flex; align-items: center; gap: 8px; text-decoration: none; border: none;
    background: transparent;
}
.hw-tab i { font-size: 1rem; opacity: .7; }
.hw-tab:hover { color: var(--hw-primary); background: var(--hw-primary-dim); }
.hw-tab:hover i { opacity: 1; }
.hw-tab.active { color: #fff; background: var(--hw-primary); box-shadow: 0 2px 8px var(--hw-primary-glow); }
.hw-tab.active i { opacity: 1; color: #fff; }

/* ── Panels ───────────────────────────────────────────────── */
.hw-panel { display: none; animation: hwFadeIn .25s ease; }
.hw-panel.active { display: block; }
@keyframes hwFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* ── Stat Cards ───────────────────────────────────────────── */
.hw-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px; }
.hw-stat-card {
    background: var(--hw-bg2); border: 1px solid var(--hw-border);
    border-radius: var(--hw-radius); padding: 22px; box-shadow: var(--hw-shadow);
    transition: transform .2s, box-shadow .2s; position: relative; overflow: hidden;
}
.hw-stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.08); }
.hw-stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0;
    height: 3px; border-radius: var(--hw-radius) var(--hw-radius) 0 0;
}
.hw-stat-card.total::before   { background: var(--hw-primary); }
.hw-stat-card.active::before  { background: var(--hw-green); }
.hw-stat-card.overdue::before { background: var(--hw-red); }
.hw-stat-card.rate::before    { background: var(--hw-blue); }

.hw-stat-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 12px; font-size: 18px;
}
.hw-stat-card.total .hw-stat-icon   { background: var(--hw-primary-dim); color: var(--hw-primary); }
.hw-stat-card.active .hw-stat-icon  { background: rgba(22,163,74,0.1); color: var(--hw-green); }
.hw-stat-card.overdue .hw-stat-icon { background: rgba(220,38,38,0.1); color: var(--hw-red); }
.hw-stat-card.rate .hw-stat-icon    { background: rgba(37,99,235,0.1); color: var(--hw-blue); }

.hw-stat-value {
    font-size: 28px; font-weight: 800; color: var(--hw-t1);
    font-family: var(--font-d); line-height: 1;
}
.hw-stat-label {
    font-size: 13px; color: var(--hw-t3); margin-top: 6px;
    font-weight: 600; text-transform: uppercase; letter-spacing: .3px;
}
.hw-stat-sub { font-size: 12px; color: var(--hw-t3); margin-top: 4px; }

/* Skeleton loading */
.hw-skel { width: 48px; height: 24px; border-radius: 6px; background: var(--hw-bg3); animation: hwPulse 1.2s ease-in-out infinite; }
@keyframes hwPulse { 0%, 100% { opacity: .5; } 50% { opacity: 1; } }

/* ── Card ─────────────────────────────────────────────────── */
.hw-card {
    background: var(--hw-bg2); border-radius: var(--hw-radius);
    border: 1px solid var(--hw-border); box-shadow: var(--hw-shadow); overflow: hidden;
    margin-bottom: 24px;
}
.hw-card-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 24px; border-bottom: 1px solid var(--hw-border);
    background: var(--hw-bg3); flex-wrap: wrap; gap: 12px;
}
.hw-card-head h3 {
    margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--hw-t1);
    display: flex; align-items: center; gap: 10px; font-family: var(--font-d);
}
.hw-card-head h3 i {
    color: var(--hw-primary); font-size: 1rem; width: 34px; height: 34px;
    display: flex; align-items: center; justify-content: center;
    background: var(--hw-primary-dim); border-radius: 8px;
}
.hw-card-body { padding: 0; }
.hw-card-body-padded { padding: 24px; }

/* ── Filter Bar ───────────────────────────────────────────── */
.hw-filter-bar {
    display: flex; gap: 12px; padding: 16px 24px; flex-wrap: wrap; align-items: flex-end;
    background: var(--hw-bg); border-bottom: 1px solid var(--hw-border);
}
.hw-filter-group { display: flex; flex-direction: column; gap: 4px; }
.hw-filter-group label {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .4px; color: var(--hw-t3);
}
.hw-filter-bar select, .hw-filter-bar input[type="text"], .hw-filter-bar input[type="date"] {
    padding: 9px 14px; border: 1px solid var(--hw-border); border-radius: 8px;
    font-size: 14px; background: var(--hw-bg2); color: var(--hw-t1);
    min-width: 140px; transition: border-color .2s, box-shadow .2s;
}
.hw-filter-bar select:focus, .hw-filter-bar input:focus {
    border-color: var(--hw-primary); box-shadow: 0 0 0 3px var(--hw-primary-ring); outline: none;
}

/* ── Table ─────────────────────────────────────────────────── */
.hw-table-wrap { overflow-x: auto; }
.hw-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.hw-table thead th {
    padding: 13px 16px; text-align: left; font-weight: 700;
    color: var(--hw-t2); font-size: 12px; text-transform: uppercase;
    letter-spacing: .4px; border-bottom: 1px solid var(--hw-border);
    background: var(--hw-bg3); white-space: nowrap;
}
.hw-table tbody td {
    padding: 12px 16px; color: var(--hw-t1);
    border-bottom: 1px solid var(--hw-border); vertical-align: middle;
}
.hw-table tbody tr:last-child td { border-bottom: none; }
.hw-table tbody tr:hover td { background: var(--hw-primary-dim); }
.hw-table tbody tr { cursor: pointer; transition: background .15s; }

/* ── Badges ───────────────────────────────────────────────── */
.hw-badge {
    display: inline-block; padding: 4px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 600; white-space: nowrap;
}
.hw-badge-green  { background: rgba(22,163,74,.12); color: var(--hw-green); }
.hw-badge-red    { background: rgba(220,38,38,.12); color: var(--hw-red); }
.hw-badge-amber  { background: rgba(217,119,6,.12); color: var(--hw-amber); }
.hw-badge-blue   { background: rgba(37,99,235,.12); color: var(--hw-blue); }
.hw-badge-gray   { background: rgba(107,114,128,.12); color: #6b7280; }
.hw-badge-purple { background: rgba(124,58,237,.12); color: var(--hw-purple); }

/* ── Progress Bar ─────────────────────────────────────────── */
.hw-progress-wrap { display: flex; align-items: center; gap: 8px; min-width: 120px; }
.hw-progress {
    flex: 1; height: 6px; background: var(--hw-bg3); border-radius: 3px; overflow: hidden;
}
.hw-progress-bar {
    height: 100%; border-radius: 3px; transition: width .4s ease;
}
.hw-progress-bar.green  { background: var(--hw-green); }
.hw-progress-bar.amber  { background: var(--hw-amber); }
.hw-progress-bar.red    { background: var(--hw-red); }
.hw-progress-label { font-size: 12px; font-weight: 600; color: var(--hw-t2); white-space: nowrap; }

/* ── Buttons ──────────────────────────────────────────────── */
.hw-btn {
    padding: 9px 20px; border: none; border-radius: 8px; font-weight: 600;
    font-size: 14px; cursor: pointer; transition: all .2s;
    display: inline-flex; align-items: center; gap: 7px; text-decoration: none;
}
.hw-btn:hover { transform: translateY(-1px); }
.hw-btn-primary { background: var(--hw-primary); color: #fff; box-shadow: 0 2px 6px var(--hw-primary-glow); }
.hw-btn-primary:hover { background: var(--hw-primary2); box-shadow: 0 4px 12px var(--hw-primary-glow); }
.hw-btn-outline { background: transparent; color: var(--hw-primary); border: 1.5px solid var(--hw-primary); }
.hw-btn-outline:hover { background: var(--hw-primary-dim); }
.hw-btn-danger { background: var(--hw-red); color: #fff; }
.hw-btn-danger:hover { background: #b91c1c; }
.hw-btn-success { background: var(--hw-green); color: #fff; }
.hw-btn-success:hover { background: #15803d; }
.hw-btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; }
.hw-btn-icon { width: 30px; height: 30px; padding: 0; justify-content: center; border-radius: 6px; }

/* ── Charts ───────────────────────────────────────────────── */
.hw-chart-row { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.hw-chart-box {
    background: var(--hw-bg2); border-radius: var(--hw-radius);
    border: 1px solid var(--hw-border); padding: 20px; box-shadow: var(--hw-shadow);
}
.hw-chart-title {
    font-size: 14px; font-weight: 700; color: var(--hw-t1);
    margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
    font-family: var(--font-d);
}
.hw-chart-title i { color: var(--hw-primary); }
.hw-chart-canvas { width: 100% !important; max-height: 280px; }

/* ── Grid Layouts ─────────────────────────────────────────── */
.hw-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
.hw-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-bottom: 24px; }

/* ── Empty State ──────────────────────────────────────────── */
.hw-empty { text-align: center; padding: 48px 20px; color: var(--hw-t3); font-size: 15px; }
.hw-empty i { font-size: 36px; margin-bottom: 12px; display: block; opacity: .4; }

/* ── Modal ─────────────────────────────────────────────────── */
.hw-modal-overlay {
    position: fixed !important; top: 0 !important; left: 0 !important;
    width: 100vw !important; height: 100vh !important;
    background: rgba(0,0,0,.5); z-index: 10000;
    display: none; align-items: center; justify-content: center;
    transform: none !important;
}
.hw-modal-overlay.show { display: flex !important; }
.hw-modal {
    background: var(--hw-bg2); border-radius: 16px; width: 95%; max-width: 720px;
    max-height: 90vh; overflow-y: auto; box-shadow: 0 12px 48px rgba(0,0,0,.2);
    animation: hwModalIn .2s ease;
    margin: auto;
}
@keyframes hwModalIn { from { opacity: 0; transform: scale(.96) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.hw-modal-head {
    padding: 18px 24px; border-bottom: 1px solid var(--hw-border);
    display: flex; align-items: center; justify-content: space-between;
    background: var(--hw-bg3); border-radius: 16px 16px 0 0;
}
.hw-modal-head h4 { margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--hw-t1); font-family: var(--font-d); }
.hw-modal-close {
    background: var(--hw-bg); border: none; font-size: 1.2rem; cursor: pointer;
    color: var(--hw-t2); width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center; transition: background .15s;
}
.hw-modal-close:hover { background: rgba(220,38,38,.1); color: var(--hw-red); }
.hw-modal-body { padding: 24px; font-size: 14px; }
.hw-modal-foot {
    padding: 16px 24px; border-top: 1px solid var(--hw-border);
    display: flex; justify-content: flex-end; gap: 12px;
    background: var(--hw-bg); border-radius: 0 0 16px 16px;
}

/* ── Form ──────────────────────────────────────────────────── */
.hw-fg { margin-bottom: 16px; }
.hw-fg label {
    display: block; font-weight: 700; margin-bottom: 5px; font-size: 13px;
    color: var(--hw-t2); text-transform: uppercase; letter-spacing: .3px;
}
.hw-fg input, .hw-fg select, .hw-fg textarea {
    width: 100%; padding: 10px 14px; border: 1px solid var(--hw-border);
    border-radius: 8px; font-size: 14px; background: var(--hw-bg);
    color: var(--hw-t1); transition: border-color .2s, box-shadow .2s;
}
.hw-fg input:focus, .hw-fg select:focus, .hw-fg textarea:focus {
    border-color: var(--hw-primary); box-shadow: 0 0 0 3px var(--hw-primary-ring); outline: none;
}
.hw-fg textarea { resize: vertical; min-height: 80px; }
.hw-fg-row { display: flex; gap: 16px; }
.hw-fg-row > .hw-fg { flex: 1; }

/* ── Alert Cards ──────────────────────────────────────────── */
.hw-alert-list { max-height: 300px; overflow-y: auto; }
.hw-alert-item {
    display: flex; align-items: center; gap: 14px; padding: 12px 16px;
    border-bottom: 1px solid var(--hw-border); transition: background .15s;
}
.hw-alert-item:hover { background: var(--hw-primary-dim); }
.hw-alert-item:last-child { border-bottom: none; }
.hw-alert-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
}
.hw-alert-dot.red { background: var(--hw-red); box-shadow: 0 0 6px rgba(220,38,38,.4); }
.hw-alert-dot.amber { background: var(--hw-amber); box-shadow: 0 0 6px rgba(217,119,6,.4); }
.hw-alert-info { flex: 1; }
.hw-alert-title { font-size: 14px; font-weight: 600; color: var(--hw-t1); }
.hw-alert-meta { font-size: 12px; color: var(--hw-t3); margin-top: 2px; }

/* ── Gauge ─────────────────────────────────────────────────── */
.hw-gauge-wrap { display: flex; align-items: center; justify-content: center; margin-top: 8px; }
.hw-gauge { position: relative; width: 64px; height: 64px; }
.hw-gauge canvas { width: 64px; height: 64px; }
.hw-gauge-val {
    position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 800; color: var(--hw-t1); font-family: var(--font-d);
}

/* ── Checkbox multi-select ────────────────────────────────── */
/* Section chips — replaces the old vertical checkbox list. The native
   checkboxes are hidden but kept inside each label so form semantics
   (name="createSection", `name=createSection`:checked queries) are
   preserved. The selected state is driven by `:has(input:checked)` —
   needs Chrome 105 / Firefox 121 / Safari 15.4 (covered by every modern
   admin browser). */
.hw-check-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px;
    border: 1px solid var(--hw-border);
    border-radius: 10px;
    background: var(--hw-bg2);
    min-height: 56px;
    align-items: center;
}
.hw-check-list .hw-check-empty {
    width: 100%;
    text-align: left;
    color: var(--hw-t3);
    font-size: 12px;
    padding: 4px 2px;
}
.hw-check-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 14px;
    border: 1px solid var(--hw-border);
    border-radius: 999px;
    background: var(--hw-bg);
    font-size: 13px;
    font-weight: 500;
    color: var(--hw-t1);
    cursor: pointer;
    user-select: none;
    transition: background 140ms ease, border-color 140ms ease, color 140ms ease, box-shadow 140ms ease;
}
.hw-check-item:hover { border-color: var(--hw-primary); }
.hw-check-item input[type="checkbox"] {
    /* keep the input in the DOM for form serialization, just hide it */
    position: absolute;
    width: 1px; height: 1px;
    margin: -1px; padding: 0;
    overflow: hidden;
    clip: rect(0,0,0,0);
    white-space: nowrap;
    border: 0;
}
.hw-check-item:has(input:checked) {
    background: var(--hw-primary);
    border-color: var(--hw-primary);
    color: #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
}
.hw-check-item--all {
    background: transparent;
    border-style: dashed;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.4px;
    padding: 7px 12px;
}
.hw-check-item--all:has(input:checked) { border-style: solid; }

/* ── Responsive ───────────────────────────────────────────── */
@media (max-width: 1100px) { .hw-stats { grid-template-columns: repeat(2, 1fr); } .hw-chart-row, .hw-grid-2, .hw-grid-3 { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .hw-wrap { padding: 16px 14px 30px; }
    .hw-stats { grid-template-columns: 1fr 1fr; gap: 12px; }
    .hw-stat-card { padding: 16px; }
    .hw-stat-value { font-size: 22px; }
    .hw-tabs { gap: 2px; padding: 4px; border-radius: 10px; }
    .hw-tab { padding: 9px 14px; font-size: 12px; }
    .hw-tab i { display: none; }
    .hw-filter-bar { padding: 12px 16px; }
    .hw-fg-row { flex-direction: column; gap: 0; }
}
@media (max-width: 480px) { .hw-stats { grid-template-columns: 1fr; } }
</style>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<div class="content-wrapper">
<section class="content">
<div class="hw-wrap">

    <!-- ── Page Header ────────────────────────────────────────────── -->
    <div class="hw-page-header">
        <h2><i class="fa fa-book"></i> Homework Tracking <span class="hw-page-sub">| <?= htmlspecialchars($session_year) ?></span></h2>
    </div>

    <!-- ── Tab Navigation ─────────────────────────────────────────── -->
    <div class="hw-tabs">
        <button class="hw-tab active" data-tab="dashboard"><i class="fa fa-dashboard"></i> Dashboard</button>
        <button class="hw-tab" data-tab="list"><i class="fa fa-list"></i> Homework List</button>
        <button class="hw-tab" data-tab="submissions"><i class="fa fa-check-square-o"></i> Submissions</button>
        <button class="hw-tab" data-tab="analytics"><i class="fa fa-bar-chart"></i> Analytics</button>
        <button class="hw-tab" data-tab="create"><i class="fa fa-plus-circle"></i> Create</button>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 1 — DASHBOARD
         ═══════════════════════════════════════════════════════════════ -->
    <div class="hw-panel active" id="panel-dashboard">

        <!-- Stat Cards -->
        <div class="hw-stats">
            <div class="hw-stat-card total">
                <div class="hw-stat-icon"><i class="fa fa-book"></i></div>
                <div class="hw-stat-value" id="dStatTotal"><div class="hw-skel"></div></div>
                <div class="hw-stat-label">Total Homework</div>
                <div class="hw-stat-sub" id="dStatDueWeek"></div>
            </div>
            <div class="hw-stat-card active">
                <div class="hw-stat-icon"><i class="fa fa-bolt"></i></div>
                <div class="hw-stat-value" id="dStatActive"><div class="hw-skel"></div></div>
                <div class="hw-stat-label">Active</div>
                <div class="hw-stat-sub" id="dStatDueToday"></div>
            </div>
            <div class="hw-stat-card overdue">
                <div class="hw-stat-icon"><i class="fa fa-exclamation-triangle"></i></div>
                <div class="hw-stat-value" id="dStatOverdue"><div class="hw-skel"></div></div>
                <div class="hw-stat-label">Overdue</div>
                <div class="hw-stat-sub">Past due date, still open</div>
            </div>
            <div class="hw-stat-card rate">
                <div class="hw-stat-icon"><i class="fa fa-pie-chart"></i></div>
                <div class="hw-stat-value" id="dStatRate"><div class="hw-skel"></div></div>
                <div class="hw-stat-label">Avg Submission Rate</div>
                <div class="hw-gauge-wrap"><div class="hw-gauge" id="gaugeRate"><canvas width="64" height="64"></canvas><div class="hw-gauge-val" id="gaugeRateVal">-</div></div></div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="hw-chart-row">
            <div class="hw-chart-box">
                <div class="hw-chart-title"><i class="fa fa-pie-chart"></i> Homework by Status</div>
                <canvas id="chartStatus" class="hw-chart-canvas"></canvas>
            </div>
            <div class="hw-chart-box">
                <div class="hw-chart-title"><i class="fa fa-bar-chart"></i> Submission Rate by Class</div>
                <canvas id="chartClassRate" class="hw-chart-canvas"></canvas>
            </div>
        </div>

        <!-- Recent + Overdue -->
        <div class="hw-grid-2">
            <div class="hw-card">
                <div class="hw-card-head">
                    <h3><i class="fa fa-clock-o"></i> Recent Homework</h3>
                </div>
                <div class="hw-card-body">
                    <div class="hw-alert-list" id="recentHwList">
                        <div class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
                    </div>
                </div>
            </div>
            <div class="hw-card">
                <div class="hw-card-head">
                    <h3><i class="fa fa-exclamation-circle"></i> Overdue Alerts</h3>
                </div>
                <div class="hw-card-body">
                    <div class="hw-alert-list" id="overdueAlertList">
                        <div class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 2 — HOMEWORK LIST
         ═══════════════════════════════════════════════════════════════ -->
    <div class="hw-panel" id="panel-list">
        <div class="hw-card">
            <div class="hw-card-head">
                <h3><i class="fa fa-list-ul"></i> All Homework</h3>
                <button class="hw-btn hw-btn-outline hw-btn-sm" onclick="HW.list.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
            </div>
            <div class="hw-filter-bar">
                <div class="hw-filter-group">
                    <label>Class</label>
                    <select id="fltClass" onchange="HW.list.onClassChange()"><option value="">All Classes</option></select>
                </div>
                <div class="hw-filter-group">
                    <label>Section</label>
                    <select id="fltSection" onchange="HW.list.refresh()"><option value="">All Sections</option></select>
                </div>
                <div class="hw-filter-group">
                    <label>Subject</label>
                    <select id="fltSubject" onchange="HW.list.refresh()"><option value="">All Subjects</option></select>
                </div>
                <div class="hw-filter-group">
                    <label>Status</label>
                    <select id="fltStatus" onchange="HW.list.refresh()">
                        <option value="">All</option>
                        <option value="Active">Active</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Closed">Closed</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
                <div class="hw-filter-group">
                    <label>From</label>
                    <input type="date" id="fltDateFrom" onchange="HW.list.refresh()">
                </div>
                <div class="hw-filter-group">
                    <label>To</label>
                    <input type="date" id="fltDateTo" onchange="HW.list.refresh()">
                </div>
            </div>
            <div class="hw-card-body">
                <div class="hw-table-wrap">
                    <table class="hw-table" id="hwListTable">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Class / Section</th>
                                <th>Teacher</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Submission</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="hwListBody">
                            <tr><td colspan="8" class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 3 — SUBMISSIONS TRACKER
         ═══════════════════════════════════════════════════════════════ -->
    <div class="hw-panel" id="panel-submissions">
        <div class="hw-card">
            <div class="hw-card-head">
                <h3><i class="fa fa-check-square-o"></i> Submission Tracker</h3>
                <button class="hw-btn hw-btn-outline hw-btn-sm" id="btnExportSub" style="display:none;" onclick="HW.sub.exportCSV()"><i class="fa fa-download"></i> Export CSV</button>
            </div>
            <div class="hw-filter-bar">
                <div class="hw-filter-group">
                    <label>Class</label>
                    <select id="subClass" onchange="HW.sub.onClassChange()"><option value="">Select Class</option></select>
                </div>
                <div class="hw-filter-group">
                    <label>Section</label>
                    <select id="subSection" onchange="HW.sub.onSectionChange()"><option value="">Select Section</option></select>
                </div>
                <div class="hw-filter-group">
                    <label>Homework</label>
                    <select id="subHwSelect" onchange="HW.sub.loadSubmissions()" style="min-width:220px;"><option value="">Select Homework</option></select>
                </div>
            </div>
            <div class="hw-card-body">
                <!-- Summary bar -->
                <div id="subSummary" style="display:none; padding:16px 24px; background:var(--hw-bg3); border-bottom:1px solid var(--hw-border);">
                    <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
                        <strong id="subHwTitle" style="color:var(--hw-t1); font-size:14px;"></strong>
                        <div class="hw-progress-wrap" style="min-width:200px;">
                            <div class="hw-progress" style="height:8px;">
                                <div class="hw-progress-bar green" id="subProgressBar" style="width:0%"></div>
                            </div>
                            <span class="hw-progress-label" id="subProgressLabel">0/0</span>
                        </div>
                    </div>
                </div>
                <div class="hw-table-wrap">
                    <table class="hw-table" id="subTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student Name</th>
                                <th>Roll No</th>
                                <th>Status</th>
                                <th>Response</th>
                                <th>Score</th>
                                <th>Remark</th>
                                <th>Reviewed By</th>
                                <th>Submitted At</th>
                            </tr>
                        </thead>
                        <tbody id="subBody">
                            <tr><td colspan="9" class="hw-empty"><i class="fa fa-hand-pointer-o"></i> Select a class, section, and homework to view submissions</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 4 — ANALYTICS
         ═══════════════════════════════════════════════════════════════ -->
    <div class="hw-panel" id="panel-analytics">

        <!-- Trend Charts -->
        <div class="hw-chart-row">
            <div class="hw-chart-box">
                <div class="hw-chart-title"><i class="fa fa-line-chart"></i> Homework Creation Trend (Weekly)</div>
                <canvas id="chartCreationTrend" class="hw-chart-canvas"></canvas>
            </div>
            <div class="hw-chart-box">
                <div class="hw-chart-title"><i class="fa fa-line-chart"></i> Submission Rate Trend (Weekly)</div>
                <canvas id="chartSubRateTrend" class="hw-chart-canvas"></canvas>
            </div>
        </div>

        <div class="hw-chart-row">
            <div class="hw-chart-box">
                <div class="hw-chart-title"><i class="fa fa-pie-chart"></i> Subject Distribution</div>
                <canvas id="chartSubjectDist" class="hw-chart-canvas"></canvas>
            </div>
            <div class="hw-chart-box">
                <div class="hw-chart-title"><i class="fa fa-bar-chart"></i> Avg Submission Rate by Class</div>
                <canvas id="chartClassComparison" class="hw-chart-canvas"></canvas>
            </div>
        </div>

        <!-- Teacher Compliance Table -->
        <div class="hw-card">
            <div class="hw-card-head">
                <h3><i class="fa fa-user"></i> Teacher Homework Activity</h3>
            </div>
            <div class="hw-card-body">
                <div class="hw-table-wrap">
                    <table class="hw-table" id="teacherActivityTable">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>ID</th>
                                <th>Homework Created</th>
                                <th>Active Weeks</th>
                                <th>Avg Submission Rate</th>
                            </tr>
                        </thead>
                        <tbody id="teacherActivityBody">
                            <tr><td colspan="5" class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TAB 5 — CREATE HOMEWORK
         ═══════════════════════════════════════════════════════════════ -->
    <div class="hw-panel" id="panel-create">
        <div class="hw-card">
            <div class="hw-card-head">
                <h3><i class="fa fa-plus-circle"></i> Create New Homework</h3>
            </div>
            <div class="hw-card-body-padded">
                <form id="createHwForm" onsubmit="return false;">
                    <div class="hw-fg-row">
                        <div class="hw-fg">
                            <label>Class *</label>
                            <select id="createClass" onchange="HW.create.onClassChange()" required>
                                <option value="">Select Class</option>
                            </select>
                        </div>
                        <div class="hw-fg">
                            <label>Sections *</label>
                            <div class="hw-check-list" id="createSectionsList">
                                <div class="hw-check-empty">Select a class first</div>
                            </div>
                        </div>
                    </div>
                    <div class="hw-fg-row">
                        <div class="hw-fg">
                            <label>Subject *</label>
                            <input type="text" id="createSubject" placeholder="e.g. Mathematics" required>
                        </div>
                        <div class="hw-fg">
                            <label>Due Date *</label>
                            <input type="date" id="createDueDate" required>
                        </div>
                    </div>
                    <div class="hw-fg">
                        <label>Title *</label>
                        <input type="text" id="createTitle" placeholder="e.g. Chapter 5 - Exercise Questions" required>
                    </div>
                    <div class="hw-fg">
                        <label>Description</label>
                        <textarea id="createDesc" placeholder="Detailed instructions for students..."></textarea>
                    </div>

                    <!-- Preview -->
                    <div id="createPreview" style="display:none; margin-bottom:16px;">
                        <div style="font-size:12px;font-weight:700;color:var(--hw-t3);text-transform:uppercase;margin-bottom:8px;">Preview</div>
                        <div style="background:var(--hw-bg3);border-radius:10px;padding:16px;border:1px solid var(--hw-border);">
                            <div id="previewContent"></div>
                        </div>
                    </div>

                    <div style="display:flex;gap:12px;">
                        <button type="button" class="hw-btn hw-btn-outline" onclick="HW.create.preview()"><i class="fa fa-eye"></i> Preview</button>
                        <button type="button" class="hw-btn hw-btn-primary" onclick="HW.create.submit()"><i class="fa fa-paper-plane"></i> Create Homework</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- /.hw-wrap -->
</section>
</div><!-- /.content-wrapper -->

<!-- ═══════════════════════════════════════════════════════════════
     DETAIL MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div class="hw-modal-overlay" id="hwDetailModal">
    <div class="hw-modal">
        <div class="hw-modal-head">
            <h4 id="detailModalTitle">Homework Detail</h4>
            <button class="hw-modal-close" onclick="HW.detail.close()">&times;</button>
        </div>
        <div class="hw-modal-body" id="detailModalBody">
            <div class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
        </div>
        <div class="hw-modal-foot" id="detailModalFoot"></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EDIT MODAL
     ═══════════════════════════════════════════════════════════════ -->
<div class="hw-modal-overlay" id="hwEditModal">
    <div class="hw-modal">
        <div class="hw-modal-head">
            <h4>Edit Homework</h4>
            <button class="hw-modal-close" onclick="HW.edit.close()">&times;</button>
        </div>
        <div class="hw-modal-body">
            <form id="editHwForm" onsubmit="return false;">
                <input type="hidden" id="editClass">
                <input type="hidden" id="editSection">
                <input type="hidden" id="editHwId">
                <div class="hw-fg">
                    <label>Title</label>
                    <input type="text" id="editTitle">
                </div>
                <div class="hw-fg">
                    <label>Description</label>
                    <textarea id="editDesc"></textarea>
                </div>
                <div class="hw-fg-row">
                    <div class="hw-fg">
                        <label>Subject</label>
                        <input type="text" id="editSubject">
                    </div>
                    <div class="hw-fg">
                        <label>Due Date</label>
                        <input type="date" id="editDueDate">
                    </div>
                </div>
                <div class="hw-fg">
                    <label>Status</label>
                    <select id="editStatus">
                        <option value="Active">Active</option>
                        <option value="Closed">Closed</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="hw-modal-foot">
            <button class="hw-btn hw-btn-outline" onclick="HW.edit.close()">Cancel</button>
            <button class="hw-btn hw-btn-primary" onclick="HW.edit.save()"><i class="fa fa-save"></i> Save Changes</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function(){
(function(){
'use strict';

// Move modals to <body> to escape any ancestor transform/overflow that breaks position:fixed
['hwDetailModal','hwEditModal'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el && el.parentElement !== document.body) document.body.appendChild(el);
});

/* ── Globals ──────────────────────────────────────────────── */
var BASE = '<?= base_url() ?>';
var CSRF = {
    name:  document.querySelector('meta[name=csrf-name]').getAttribute('content'),
    token: document.querySelector('meta[name=csrf-token]').getAttribute('content')
};

function ajaxPost(url, data, cb) {
    data[CSRF.name] = CSRF.token;
    $.ajax({
        url: BASE + url, type: 'POST', data: data, dataType: 'json',
        success: function(r) {
            if (r.csrf_token) CSRF.token = r.csrf_token;
            cb(r);
        },
        error: function(x) {
            if (x.responseJSON && x.responseJSON.csrf_token) CSRF.token = x.responseJSON.csrf_token;
            cb({ status: 'error', message: x.responseJSON ? x.responseJSON.message : 'Request failed' });
        }
    });
}

function esc(s) { var d = document.createElement('div'); d.appendChild(document.createTextNode(s || '')); return d.innerHTML; }
function escJs(s) { return (s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '\\"'); }
function $$(sel) { if (typeof sel === 'string') return document.querySelectorAll(sel); return sel; }
function $1(sel) { return document.querySelector(sel); }
function setText(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
function setHtml(id, v) { var el = document.getElementById(id); if (el) el.innerHTML = v; }

function statusBadge(s) {
    var key = (s || '').toLowerCase();
    var map = {
        'active':   'hw-badge-green',
        'closed':   'hw-badge-gray',
        'archived': 'hw-badge-blue',
        'overdue':  'hw-badge-red',
    };
    var label = s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
    return '<span class="hw-badge ' + (map[key] || 'hw-badge-gray') + '">' + esc(label) + '</span>';
}

function subStatusBadge(s) {
    var key = (s || '').toLowerCase();
    var map = {
        'submitted': 'hw-badge-green',
        'reviewed':  'hw-badge-purple',
        'complete':  'hw-badge-green',
        'done':      'hw-badge-green',
        'pending':   'hw-badge-red',
    };
    var labels = {
        'submitted': 'Submitted',
        'reviewed':  'Reviewed',
        'complete':  'Complete',
        'done':      'Done',
        'pending':   'Pending',
    };
    return '<span class="hw-badge ' + (map[key] || 'hw-badge-gray') + '">' + esc(labels[key] || s) + '</span>';
}

function progressBar(rate) {
    var cls = rate >= 70 ? 'green' : (rate >= 40 ? 'amber' : 'red');
    return '<div class="hw-progress-wrap">' +
        '<div class="hw-progress"><div class="hw-progress-bar ' + cls + '" style="width:' + rate + '%"></div></div>' +
        '<span class="hw-progress-label">' + rate + '%</span></div>';
}

function formatTs(ts) {
    if (!ts || ts === 0) return '-';
    var d;
    if (typeof ts === 'string') {
        // Handle ISO strings like "2026-04-09T23:38:36+05:30"
        d = new Date(ts);
    } else if (typeof ts === 'object' && ts._seconds) {
        // Handle Firestore Timestamp objects {_seconds, _nanoseconds}
        d = new Date(ts._seconds * 1000);
    } else if (typeof ts === 'number') {
        // Handle Unix ms or seconds
        d = new Date(ts > 1e12 ? ts : ts * 1000);
    } else {
        d = new Date(parseInt(ts));
    }
    if (isNaN(d.getTime())) return '-';
    return d.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' }) + ' ' +
           d.toLocaleTimeString('en-IN', { hour:'2-digit', minute:'2-digit' });
}

function formatDate(ds) {
    if (!ds) return '-';
    var d = new Date(ds + 'T00:00:00');
    if (isNaN(d.getTime())) return ds;
    return d.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
}

function toast(msg, type) {
    var el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:14px 24px;border-radius:10px;font-size:13px;font-weight:600;color:#fff;box-shadow:0 4px 20px rgba(0,0,0,.2);animation:hwFadeIn .3s ease;max-width:360px;';
    el.style.background = type === 'error' ? '#dc2626' : (type === 'warning' ? '#d97706' : '#16a34a');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function() { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; }, 2500);
    setTimeout(function() { el.remove(); }, 3000);
}

/* ── Class/Section cache ──────────────────────────────────── */
var _classSections = null;   // [{class_key, section, label, class_section}]
var _hwCache = null;         // cached homework list

function loadClassSections(cb) {
    if (_classSections) { cb(_classSections); return; }
    // Fetch class/sections from dedicated endpoint (all school sections, not just those with homework)
    ajaxPost('homework/get_class_sections', {}, function(r) {
        var classMap = {};
        if (r.status === 'success' && r.class_sections) {
            r.class_sections.forEach(function(cs) {
                if (!classMap[cs.class]) classMap[cs.class] = {};
                classMap[cs.class][cs.section] = true;
            });
        }
        // Also fetch homework list to get subject info
        ajaxPost('homework/get_homework_list', {}, function(r2) {
            _hwCache = (r2.status === 'success') ? (r2.homework || []) : [];
            var subjectSet = {};
            _hwCache.forEach(function(h) {
                // Merge any extra class/sections from homework data
                if (h.class && h.section) {
                    if (!classMap[h.class]) classMap[h.class] = {};
                    classMap[h.class][h.section] = true;
                }
                if (h.subject) subjectSet[h.subject] = true;
            });
            _classSections = { classMap: classMap, subjects: Object.keys(subjectSet) };
            cb(_classSections);
        });
    });
}

function populateClassDropdown(selId, includeAll) {
    var sel = document.getElementById(selId);
    if (!sel || !_classSections) return;
    sel.innerHTML = includeAll ? '<option value="">All Classes</option>' : '<option value="">Select Class</option>';
    var classes = Object.keys(_classSections.classMap).sort();
    classes.forEach(function(c) {
        var o = document.createElement('option');
        o.value = c; o.textContent = c;
        sel.appendChild(o);
    });
}

function populateSectionDropdown(selId, classKey, includeAll) {
    var sel = document.getElementById(selId);
    if (!sel) return;
    sel.innerHTML = includeAll ? '<option value="">All Sections</option>' : '<option value="">Select Section</option>';
    if (!classKey || !_classSections || !_classSections.classMap[classKey]) return;
    var sections = Object.keys(_classSections.classMap[classKey]).sort();
    sections.forEach(function(s) {
        var o = document.createElement('option');
        o.value = s; o.textContent = s;
        sel.appendChild(o);
    });
}

function populateSubjectDropdown(selId) {
    var sel = document.getElementById(selId);
    if (!sel || !_classSections) return;
    sel.innerHTML = '<option value="">All Subjects</option>';
    (_classSections.subjects || []).sort().forEach(function(s) {
        var o = document.createElement('option');
        o.value = s; o.textContent = s;
        sel.appendChild(o);
    });
}

/* ── Chart helpers ────────────────────────────────────────── */
var _charts = {};
function destroyChart(id) { if (_charts[id]) { _charts[id].destroy(); delete _charts[id]; } }

function getChartColors() {
    var cs = getComputedStyle(document.documentElement);
    return {
        primary: cs.getPropertyValue('--gold').trim() || '#0f766e',
        green: '#16a34a', red: '#dc2626', amber: '#d97706', blue: '#2563eb',
        purple: '#7c3aed', gray: '#6b7280',
        t1: cs.getPropertyValue('--t1').trim() || '#e6f4f1',
        t3: cs.getPropertyValue('--t3').trim() || '#5a9e98',
        border: cs.getPropertyValue('--border').trim() || 'rgba(15,118,110,0.12)',
        bg3: cs.getPropertyValue('--bg3').trim() || '#0f2545',
    };
}

var PALETTE = ['#0f766e','#2563eb','#d97706','#7c3aed','#dc2626','#16a34a','#db2777','#ea580c','#0891b2','#4f46e5','#059669','#be123c'];

/* ── Gauge renderer ───────────────────────────────────────── */
function drawGauge(canvasId, pct) {
    var wrap = document.getElementById(canvasId);
    if (!wrap) return;
    var canvas = wrap.querySelector('canvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var size = 64, cx = size/2, cy = size/2, r = 26, lw = 6;
    ctx.clearRect(0,0,size,size);
    // Background arc
    ctx.beginPath();
    ctx.arc(cx,cy,r, -Math.PI, Math.PI);
    ctx.strokeStyle = getChartColors().bg3;
    ctx.lineWidth = lw;
    ctx.lineCap = 'round';
    ctx.stroke();
    // Value arc
    var angle = -Math.PI + (Math.PI * 2 * Math.min(pct,100) / 100);
    var color = pct >= 70 ? '#16a34a' : (pct >= 40 ? '#d97706' : '#dc2626');
    ctx.beginPath();
    ctx.arc(cx,cy,r, -Math.PI, angle);
    ctx.strokeStyle = color;
    ctx.lineWidth = lw;
    ctx.lineCap = 'round';
    ctx.stroke();
}

/* ══════════════════════════════════════════════════════════════
   TAB SWITCHING
   ══════════════════════════════════════════════════════════════ */
document.addEventListener('click', function(e) {
    var tab = e.target.closest('.hw-tab');
    if (!tab) return;
    var target = tab.getAttribute('data-tab');
    if (!target) return;

    // Switch tab active
    var allTabs = document.querySelectorAll('.hw-tab');
    for (var i = 0; i < allTabs.length; i++) allTabs[i].classList.remove('active');
    tab.classList.add('active');

    // Switch panel
    var allPanels = document.querySelectorAll('.hw-panel');
    for (var j = 0; j < allPanels.length; j++) allPanels[j].classList.remove('active');
    var panel = document.getElementById('panel-' + target);
    if (panel) panel.classList.add('active');

    // Lazy-load tab data
    if (target === 'list' && !HW.list._loaded) HW.list.init();
    if (target === 'submissions' && !HW.sub._loaded) HW.sub.init();
    if (target === 'analytics' && !HW.analytics._loaded) HW.analytics.init();
    if (target === 'create' && !HW.create._loaded) HW.create.init();
});

/* ══════════════════════════════════════════════════════════════
   HW NAMESPACE
   ══════════════════════════════════════════════════════════════ */
window.HW = {};

/* ── DASHBOARD ────────────────────────────────────────────── */
HW.dash = {
    init: function() {
        this.loadOverview();
        this.loadCharts();
        this.loadRecent();
        this.loadOverdue();
    },

    loadOverview: function() {
        ajaxPost('homework/get_overview', {}, function(r) {
            if (r.status !== 'success') return;
            setText('dStatTotal', r.total || 0);
            setText('dStatActive', r.active || 0);
            setText('dStatOverdue', r.overdue || 0);
            setText('dStatRate', (r.avg_rate || 0) + '%');
            setText('dStatDueToday', (r.due_today || 0) + ' due today');
            setText('dStatDueWeek', (r.due_week || 0) + ' due this week');

            // Gauge
            var rate = r.avg_rate || 0;
            drawGauge('gaugeRate', rate);
            setText('gaugeRateVal', rate + '%');

            // Status donut data
            HW.dash._statusData = {
                active: r.active || 0,
                overdue: r.overdue || 0,
                closed: r.closed || 0,
            };
            HW.dash.renderStatusChart();
        });
    },

    renderStatusChart: function() {
        var d = this._statusData;
        if (!d) return;
        var C = getChartColors();
        destroyChart('chartStatus');
        var ctx = document.getElementById('chartStatus');
        if (!ctx) return;
        _charts['chartStatus'] = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Active', 'Overdue', 'Closed'],
                datasets: [{
                    data: [d.active, d.overdue, d.closed],
                    backgroundColor: [C.green, C.red, C.gray],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: C.t1, padding: 16, font: { size: 12 } } }
                }
            }
        });
    },

    loadCharts: function() {
        // Submission rate by class
        ajaxPost('homework/get_class_summary', {}, function(r) {
            if (r.status !== 'success') return;
            var classes = r.classes || [];
            var C = getChartColors();
            destroyChart('chartClassRate');
            var ctx = document.getElementById('chartClassRate');
            if (!ctx) return;
            _charts['chartClassRate'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: classes.map(function(c) { return c.label; }),
                    datasets: [{
                        label: 'Avg Submission %',
                        data: classes.map(function(c) { return c.avg_rate; }),
                        backgroundColor: C.primary + 'cc',
                        borderColor: C.primary,
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { max: 100, grid: { color: C.border }, ticks: { color: C.t3, callback: function(v) { return v + '%'; } } },
                        y: { grid: { display: false }, ticks: { color: C.t1, font: { size: 11 } } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        });
    },

    loadRecent: function() {
        ajaxPost('homework/get_homework_list', {}, function(r) {
            if (r.status !== 'success') {
                setHtml('recentHwList', '<div class="hw-empty"><i class="fa fa-exclamation-circle"></i> Failed to load</div>');
                return;
            }
            var list = (r.homework || []).slice(0, 15);
            if (!list.length) {
                setHtml('recentHwList', '<div class="hw-empty"><i class="fa fa-inbox"></i> No homework found</div>');
                return;
            }
            var html = '';
            list.forEach(function(h) {
                html += '<div class="hw-alert-item" onclick="HW.detail.open(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\')">' +
                    '<div class="hw-alert-dot ' + (h.status.toLowerCase() === 'overdue' ? 'red' : (h.status.toLowerCase() === 'active' ? 'amber' : '')) + '"></div>' +
                    '<div class="hw-alert-info"><div class="hw-alert-title">' + esc(h.title) + '</div>' +
                    '<div class="hw-alert-meta">' + esc(h.subject) + ' &middot; ' + esc(h.class) + ' / ' + esc(h.section) + ' &middot; Due: ' + formatDate(h.dueDate) + '</div></div>' +
                    statusBadge(h.status) + '</div>';
            });
            setHtml('recentHwList', html);
        });
    },

    loadOverdue: function() {
        ajaxPost('homework/get_overdue_report', {}, function(r) {
            if (r.status !== 'success') {
                setHtml('overdueAlertList', '<div class="hw-empty"><i class="fa fa-exclamation-circle"></i> Failed to load</div>');
                return;
            }
            var list = r.overdue || [];
            if (!list.length) {
                setHtml('overdueAlertList', '<div class="hw-empty"><i class="fa fa-check-circle" style="color:var(--hw-green);opacity:.6;"></i> No overdue homework</div>');
                return;
            }
            var html = '';
            list.forEach(function(h) {
                html += '<div class="hw-alert-item" onclick="HW.detail.open(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\')">' +
                    '<div class="hw-alert-dot red"></div>' +
                    '<div class="hw-alert-info"><div class="hw-alert-title">' + esc(h.title) + '</div>' +
                    '<div class="hw-alert-meta">' + esc(h.subject) + ' &middot; ' + esc(h.class) + ' / ' + esc(h.section) +
                    ' &middot; <strong style="color:var(--hw-red);">' + h.days_past + ' days overdue</strong> &middot; ' +
                    esc(h.teacherName) + '</div></div>' +
                    progressBar(h.rate) + '</div>';
            });
            setHtml('overdueAlertList', html);
        });
    },
};

/* ── HOMEWORK LIST ────────────────────────────────────────── */
HW.list = {
    _loaded: false,
    _dt: null,

    init: function() {
        this._loaded = true;
        var self = this;
        loadClassSections(function() {
            populateClassDropdown('fltClass', true);
            populateSubjectDropdown('fltSubject');
            self.refresh();
        });
    },

    onClassChange: function() {
        var cls = document.getElementById('fltClass').value;
        populateSectionDropdown('fltSection', cls, true);
        this.refresh();
    },

    refresh: function() {
        var params = {
            class:     document.getElementById('fltClass').value,
            section:   document.getElementById('fltSection').value,
            subject:   document.getElementById('fltSubject').value,
            status:    document.getElementById('fltStatus').value,
            date_from: document.getElementById('fltDateFrom').value,
            date_to:   document.getElementById('fltDateTo').value,
        };
        setHtml('hwListBody', '<tr><td colspan="8" class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>');

        ajaxPost('homework/get_homework_list', params, function(r) {
            if (r.status !== 'success') {
                setHtml('hwListBody', '<tr><td colspan="8" class="hw-empty"><i class="fa fa-exclamation-circle"></i> ' + esc(r.message || 'Failed') + '</td></tr>');
                return;
            }
            var list = r.homework || [];
            if (!list.length) {
                setHtml('hwListBody', '<tr><td colspan="8" class="hw-empty"><i class="fa fa-inbox"></i> No homework found matching filters</td></tr>');
                return;
            }

            // Update class sections cache from response
            var classMap = {};
            var subjectSet = {};
            list.forEach(function(h) {
                if (!classMap[h.class]) classMap[h.class] = {};
                classMap[h.class][h.section] = true;
                if (h.subject) subjectSet[h.subject] = true;
            });
            if (!_classSections) _classSections = { classMap: classMap, subjects: Object.keys(subjectSet) };

            var html = '';
            list.forEach(function(h) {
                var sl = h.status.toLowerCase();
                html += '<tr onclick="HW.detail.open(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\')">' +
                    '<td><strong>' + esc(h.title) + '</strong></td>' +
                    '<td>' + esc(h.subject) + '</td>' +
                    '<td>' + esc(h.class) + ' / ' + esc(h.section) + '</td>' +
                    '<td>' + esc(h.teacherName) + '</td>' +
                    '<td>' + formatDate(h.dueDate) + '</td>' +
                    '<td>' + statusBadge(h.status) + '</td>' +
                    '<td>' + progressBar(h.rate) + '</td>' +
                    '<td style="white-space:nowrap;" onclick="event.stopPropagation();">' +
                        '<button class="hw-btn hw-btn-sm hw-btn-outline" title="Edit" onclick="HW.edit.open(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\')"><i class="fa fa-pencil"></i></button> ' +
                        (sl === 'active' || sl === 'overdue' ? '<button class="hw-btn hw-btn-sm hw-btn-success" title="Close" onclick="HW.actions.close(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\')"><i class="fa fa-check"></i></button> ' : '') +
                        '<button class="hw-btn hw-btn-sm hw-btn-danger" title="Delete" onclick="HW.actions.remove(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\')"><i class="fa fa-trash"></i></button>' +
                    '</td></tr>';
            });
            setHtml('hwListBody', html);
        });
    },
};

/* ── DETAIL MODAL ─────────────────────────────────────────── */
HW.detail = {
    open: function(cls, sec, hwId) {
        var overlay = document.getElementById('hwDetailModal');
        overlay.classList.add('show');
        setHtml('detailModalBody', '<div class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</div>');
        setHtml('detailModalFoot', '');

        ajaxPost('homework/get_homework_detail', { class: cls, section: sec, hw_id: hwId }, function(r) {
            if (r.status !== 'success') {
                setHtml('detailModalBody', '<div class="hw-empty"><i class="fa fa-exclamation-circle"></i> ' + esc(r.message || 'Failed') + '</div>');
                return;
            }
            var h = r.homework;
            var subs = r.submissions || [];

            setText('detailModalTitle', h.title);

            var html = '<div style="margin-bottom:20px;">' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">' +
                '<div><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Subject</strong><div style="color:var(--hw-t1);margin-top:2px;">' + esc(h.subject) + '</div></div>' +
                '<div><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Class / Section</strong><div style="color:var(--hw-t1);margin-top:2px;">' + esc(h.class) + ' / ' + esc(h.section) + '</div></div>' +
                '<div><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Teacher</strong><div style="color:var(--hw-t1);margin-top:2px;">' + esc(h.teacherName) + ' (' + esc(h.teacherId) + ')</div></div>' +
                '<div><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Due Date</strong><div style="color:var(--hw-t1);margin-top:2px;">' + formatDate(h.dueDate) + '</div></div>' +
                '<div><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Status</strong><div style="margin-top:2px;">' + statusBadge(h.status) + '</div></div>' +
                '<div><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Created</strong><div style="color:var(--hw-t1);margin-top:2px;">' + formatTs(h.createdAt) + '</div></div>' +
                '</div></div>';

            if (h.description) {
                html += '<div style="margin-bottom:16px;"><strong style="color:var(--hw-t3);font-size:11px;text-transform:uppercase;">Description</strong>' +
                    '<div style="color:var(--hw-t1);margin-top:4px;font-size:13px;background:var(--hw-bg3);padding:12px;border-radius:8px;">' + esc(h.description) + '</div></div>';
            }

            // Submission summary
            var detailPct = h.total > 0 ? Math.round(h.submitted / h.total * 100) : 0;
            var detailBarCls = detailPct >= 70 ? 'green' : (detailPct >= 40 ? 'amber' : 'red');
            html += '<div style="margin-bottom:12px;display:flex;align-items:center;gap:16px;">' +
                '<strong style="color:var(--hw-t1);font-size:14px;">Submissions</strong>' +
                '<div class="hw-progress-wrap" style="min-width:200px;">' +
                '<div class="hw-progress" style="height:8px;"><div class="hw-progress-bar ' + detailBarCls +
                '" style="width:' + detailPct + '%"></div></div>' +
                '<span class="hw-progress-label">' + h.submitted + '/' + h.total + '</span></div></div>';

            // Submission table
            if (subs.length) {
                html += '<div class="hw-table-wrap" style="max-height:300px;overflow-y:auto;"><table class="hw-table"><thead><tr>' +
                    '<th>Student</th><th>Status</th><th>Response</th><th>Score</th><th>Remark</th><th>Submitted</th></tr></thead><tbody>';
                subs.forEach(function(s) {
                    var scoreStr = (s.score !== undefined && s.score >= 0) ? s.score : '-';
                    html += '<tr>' +
                        '<td>' + esc(s.studentName || s.studentId) + '</td>' +
                        '<td>' + subStatusBadge(s.status) + '</td>' +
                        '<td style="max-width:200px;white-space:pre-wrap;word-break:break-word;">' + esc(s.text || '') + '</td>' +
                        '<td>' + scoreStr + '</td>' +
                        '<td>' + esc(s.remarks || '') + '</td>' +
                        '<td>' + formatTs(s.submittedAt) + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<div style="color:var(--hw-t3);font-size:14px;text-align:center;padding:16px;">No submission data available</div>';
            }

            setHtml('detailModalBody', html);

            // Footer actions
            var foot = '<button class="hw-btn hw-btn-outline" onclick="HW.detail.close()">Close</button>';
            var hsl = (h.status || '').toLowerCase();
            if (hsl === 'active' || hsl === 'overdue') {
                foot += ' <button class="hw-btn hw-btn-success" onclick="HW.actions.close(\'' + escJs(h.class) + '\',\'' + escJs(h.section) + '\',\'' + escJs(h.id) + '\');HW.detail.close();"><i class="fa fa-check"></i> Close Homework</button>';
            }
            setHtml('detailModalFoot', foot);
        });
    },

    close: function() {
        document.getElementById('hwDetailModal').classList.remove('show');
    }
};

/* ── EDIT MODAL ───────────────────────────────────────────── */
HW.edit = {
    open: function(cls, sec, hwId) {
        document.getElementById('hwEditModal').classList.add('show');
        document.getElementById('editClass').value = cls;
        document.getElementById('editSection').value = sec;
        document.getElementById('editHwId').value = hwId;

        // Load current data
        ajaxPost('homework/get_homework_detail', { class: cls, section: sec, hw_id: hwId }, function(r) {
            if (r.status !== 'success') { toast(r.message || 'Failed to load', 'error'); return; }
            var h = r.homework;
            document.getElementById('editTitle').value = h.title || '';
            document.getElementById('editDesc').value = h.description || '';
            document.getElementById('editSubject').value = h.subject || '';
            document.getElementById('editDueDate').value = h.dueDate || '';
            var rawStatus = h.status === 'Overdue' ? 'Active' : h.status;
            document.getElementById('editStatus').value = rawStatus || 'Active';
        });
    },

    close: function() {
        document.getElementById('hwEditModal').classList.remove('show');
    },

    save: function() {
        var data = {
            class:       document.getElementById('editClass').value,
            section:     document.getElementById('editSection').value,
            hw_id:       document.getElementById('editHwId').value,
            title:       document.getElementById('editTitle').value,
            description: document.getElementById('editDesc').value,
            subject:     document.getElementById('editSubject').value,
            due_date:    document.getElementById('editDueDate').value,
            status:      document.getElementById('editStatus').value,
        };
        ajaxPost('homework/update_homework', data, function(r) {
            if (r.status !== 'success') { toast(r.message || 'Update failed', 'error'); return; }
            toast('Homework updated successfully');
            HW.edit.close();
            if (HW.list._loaded) HW.list.refresh();
        });
    }
};

/* ── ACTIONS (Close, Delete) ──────────────────────────────── */
HW.actions = {
    close: function(cls, sec, hwId) {
        if (!confirm('Close this homework? Students will no longer be able to submit.')) return;
        ajaxPost('homework/close_homework', { class: cls, section: sec, hw_id: hwId }, function(r) {
            if (r.status !== 'success') { toast(r.message || 'Failed', 'error'); return; }
            toast('Homework closed successfully');
            if (HW.list._loaded) HW.list.refresh();
            HW.dash.init();
        });
    },

    remove: function(cls, sec, hwId) {
        if (!confirm('Delete this homework permanently? This cannot be undone.')) return;
        ajaxPost('homework/delete_homework', { class: cls, section: sec, hw_id: hwId }, function(r) {
            if (r.status !== 'success') { toast(r.message || 'Failed', 'error'); return; }
            toast('Homework deleted successfully');
            if (HW.list._loaded) HW.list.refresh();
            HW.dash.init();
        });
    }
};

/* ── SUBMISSIONS TRACKER ──────────────────────────────────── */
HW.sub = {
    _loaded: false,
    _hwList: [],

    init: function() {
        this._loaded = true;
        var self = this;
        loadClassSections(function() {
            populateClassDropdown('subClass', false);
        });
    },

    onClassChange: function() {
        var cls = document.getElementById('subClass').value;
        populateSectionDropdown('subSection', cls, false);
        document.getElementById('subHwSelect').innerHTML = '<option value="">Select Homework</option>';
        this._clearTable();
    },

    onSectionChange: function() {
        var cls = document.getElementById('subClass').value;
        var sec = document.getElementById('subSection').value;
        document.getElementById('subHwSelect').innerHTML = '<option value="">Loading...</option>';
        if (!cls || !sec) {
            document.getElementById('subHwSelect').innerHTML = '<option value="">Select Homework</option>';
            return;
        }
        // Fetch homework for this class/section
        ajaxPost('homework/get_homework_list', { class: cls, section: sec }, function(r) {
            var sel = document.getElementById('subHwSelect');
            sel.innerHTML = '<option value="">Select Homework</option>';
            if (r.status !== 'success') return;
            HW.sub._hwList = r.homework || [];
            HW.sub._hwList.forEach(function(h) {
                var o = document.createElement('option');
                o.value = h.id;
                o.textContent = h.title + ' (' + formatDate(h.dueDate) + ') — ' + h.status;
                o.setAttribute('data-class', h.class);
                o.setAttribute('data-section', h.section);
                sel.appendChild(o);
            });
        });
        this._clearTable();
    },

    loadSubmissions: function() {
        var hwId = document.getElementById('subHwSelect').value;
        if (!hwId) { this._clearTable(); return; }

        var cls = document.getElementById('subClass').value;
        var sec = document.getElementById('subSection').value;

        setHtml('subBody', '<tr><td colspan="9" class="hw-empty"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>');
        document.getElementById('subSummary').style.display = 'none';
        document.getElementById('btnExportSub').style.display = 'none';

        ajaxPost('homework/get_submissions', { class: cls, section: sec, hw_id: hwId }, function(r) {
            if (r.status !== 'success') {
                setHtml('subBody', '<tr><td colspan="6" class="hw-empty"><i class="fa fa-exclamation-circle"></i> ' + esc(r.message || 'Failed') + '</td></tr>');
                return;
            }

            var subs = r.submissions || [];
            setText('subHwTitle', r.hw_title || 'Homework');

            var total = r.total_students || subs.length;
            var submitted = r.submitted || 0;
            var pct = total > 0 ? Math.round(submitted / total * 100) : 0;
            var barCls = pct >= 70 ? 'green' : (pct >= 40 ? 'amber' : 'red');

            document.getElementById('subProgressBar').style.width = pct + '%';
            document.getElementById('subProgressBar').className = 'hw-progress-bar ' + barCls;
            setText('subProgressLabel', submitted + '/' + total + ' (' + pct + '%)');
            document.getElementById('subSummary').style.display = 'block';
            document.getElementById('btnExportSub').style.display = '';

            if (!subs.length) {
                setHtml('subBody', '<tr><td colspan="9" class="hw-empty"><i class="fa fa-inbox"></i> No students found</td></tr>');
                return;
            }

            var html = '';
            subs.forEach(function(s, i) {
                var scoreStr = (s.score !== undefined && s.score >= 0) ? s.score : '-';
                html += '<tr><td>' + (i + 1) + '</td>' +
                    '<td>' + esc(s.studentName) + '</td>' +
                    '<td>' + esc(s.rollNo) + '</td>' +
                    '<td>' + subStatusBadge(s.status) + '</td>' +
                    '<td style="max-width:200px;white-space:pre-wrap;word-break:break-word;">' + esc(s.text || '') + '</td>' +
                    '<td>' + scoreStr + '</td>' +
                    '<td>' + esc(s.remarks || '') + '</td>' +
                    '<td>' + esc(s.reviewedBy || '') + '</td>' +
                    '<td>' + formatTs(s.submittedAt) + '</td></tr>';
            });
            setHtml('subBody', html);
            HW.sub._lastData = subs;
        });
    },

    _clearTable: function() {
        setHtml('subBody', '<tr><td colspan="9" class="hw-empty"><i class="fa fa-hand-pointer-o"></i> Select a class, section, and homework to view submissions</td></tr>');
        document.getElementById('subSummary').style.display = 'none';
        document.getElementById('btnExportSub').style.display = 'none';
    },

    exportCSV: function() {
        var data = this._lastData;
        if (!data || !data.length) return;
        var csv = 'Student ID,Student Name,Roll No,Status,Response,Score,Remark,Reviewed By,Submitted At\n';
        data.forEach(function(s) {
            var scoreStr = (s.score !== undefined && s.score >= 0) ? s.score : '';
            csv += '"' + s.studentId + '","' + s.studentName + '","' + s.rollNo + '","' + s.status + '","' +
                (s.text || '').replace(/"/g, '""') + '","' + scoreStr + '","' +
                (s.remarks || '').replace(/"/g, '""') + '","' + (s.reviewedBy || '') + '","' + formatTs(s.submittedAt) + '"\n';
        });
        var blob = new Blob([csv], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'homework_submissions.csv';
        a.click();
    }
};

/* ── ANALYTICS ────────────────────────────────────────────── */
HW.analytics = {
    _loaded: false,

    init: function() {
        this._loaded = true;
        this.loadTrends();
        this.loadSubjectDist();
        this.loadClassComparison();
        this.loadTeacherActivity();
    },

    loadTrends: function() {
        ajaxPost('homework/get_trend_data', {}, function(r) {
            if (r.status !== 'success') return;
            var C = getChartColors();

            // Creation trend
            destroyChart('chartCreationTrend');
            var ctx1 = document.getElementById('chartCreationTrend');
            if (ctx1) {
                _charts['chartCreationTrend'] = new Chart(ctx1, {
                    type: 'line',
                    data: {
                        labels: r.labels || [],
                        datasets: [{
                            label: 'Homework Created',
                            data: r.created || [],
                            borderColor: C.primary,
                            backgroundColor: C.primary + '22',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 4,
                            pointBackgroundColor: C.primary,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: {
                            x: { grid: { color: C.border }, ticks: { color: C.t3, maxRotation: 45 } },
                            y: { beginAtZero: true, grid: { color: C.border }, ticks: { color: C.t3 } }
                        },
                        plugins: { legend: { labels: { color: C.t1 } } }
                    }
                });
            }

            // Submission rate trend
            destroyChart('chartSubRateTrend');
            var ctx2 = document.getElementById('chartSubRateTrend');
            if (ctx2) {
                _charts['chartSubRateTrend'] = new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: r.labels || [],
                        datasets: [{
                            label: 'Avg Submission Rate %',
                            data: r.rates || [],
                            borderColor: C.blue,
                            backgroundColor: C.blue + '22',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 4,
                            pointBackgroundColor: C.blue,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        scales: {
                            x: { grid: { color: C.border }, ticks: { color: C.t3, maxRotation: 45 } },
                            y: { min: 0, max: 100, grid: { color: C.border }, ticks: { color: C.t3, callback: function(v) { return v + '%'; } } }
                        },
                        plugins: { legend: { labels: { color: C.t1 } } }
                    }
                });
            }
        });
    },

    loadSubjectDist: function() {
        ajaxPost('homework/get_subject_breakdown', {}, function(r) {
            if (r.status !== 'success') return;
            var subjects = r.subjects || [];
            var C = getChartColors();
            destroyChart('chartSubjectDist');
            var ctx = document.getElementById('chartSubjectDist');
            if (!ctx) return;
            _charts['chartSubjectDist'] = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: subjects.map(function(s) { return s.subject; }),
                    datasets: [{
                        data: subjects.map(function(s) { return s.total; }),
                        backgroundColor: subjects.map(function(s, i) { return PALETTE[i % PALETTE.length]; }),
                        borderWidth: 0,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { color: C.t1, padding: 12, font: { size: 11 } } }
                    }
                }
            });
        });
    },

    loadClassComparison: function() {
        ajaxPost('homework/get_class_summary', {}, function(r) {
            if (r.status !== 'success') return;
            var classes = r.classes || [];
            var C = getChartColors();
            destroyChart('chartClassComparison');
            var ctx = document.getElementById('chartClassComparison');
            if (!ctx) return;
            _charts['chartClassComparison'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: classes.map(function(c) { return c.label; }),
                    datasets: [{
                        label: 'Avg Submission Rate %',
                        data: classes.map(function(c) { return c.avg_rate; }),
                        backgroundColor: classes.map(function(c, i) { return PALETTE[i % PALETTE.length] + 'cc'; }),
                        borderColor: classes.map(function(c, i) { return PALETTE[i % PALETTE.length]; }),
                        borderWidth: 1,
                        borderRadius: 4,
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: { max: 100, grid: { color: C.border }, ticks: { color: C.t3, callback: function(v) { return v + '%'; } } },
                        y: { grid: { display: false }, ticks: { color: C.t1, font: { size: 11 } } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        });
    },

    loadTeacherActivity: function() {
        ajaxPost('homework/get_teacher_activity', {}, function(r) {
            if (r.status !== 'success') {
                setHtml('teacherActivityBody', '<tr><td colspan="5" class="hw-empty"><i class="fa fa-exclamation-circle"></i> Failed to load</td></tr>');
                return;
            }
            var teachers = r.teachers || [];
            if (!teachers.length) {
                setHtml('teacherActivityBody', '<tr><td colspan="5" class="hw-empty"><i class="fa fa-inbox"></i> No teacher activity data</td></tr>');
                return;
            }
            var html = '';
            teachers.forEach(function(t) {
                html += '<tr>' +
                    '<td><strong>' + esc(t.teacherName) + '</strong></td>' +
                    '<td><span class="hw-badge hw-badge-blue">' + esc(t.teacherId) + '</span></td>' +
                    '<td>' + t.total + '</td>' +
                    '<td>' + t.active_weeks + '</td>' +
                    '<td>' + progressBar(t.avg_rate) + '</td></tr>';
            });
            setHtml('teacherActivityBody', html);
        });
    }
};

/* ── CREATE HOMEWORK ──────────────────────────────────────── */
HW.create = {
    _loaded: false,

    init: function() {
        this._loaded = true;
        var self = this;
        loadClassSections(function() {
            populateClassDropdown('createClass', false);
        });
        // Set default due date to tomorrow
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        document.getElementById('createDueDate').value = tomorrow.toISOString().split('T')[0];
    },

    onClassChange: function() {
        var cls = document.getElementById('createClass').value;
        var listEl = document.getElementById('createSectionsList');
        if (!cls || !_classSections || !_classSections.classMap[cls]) {
            listEl.innerHTML = '<div class="hw-check-empty">Select a class first</div>';
            return;
        }
        var sections = Object.keys(_classSections.classMap[cls]).sort();
        var html = '<label class="hw-check-item hw-check-item--all"><input type="checkbox" value="__all__" onchange="HW.create.toggleAll(this)"><span>All</span></label>';
        sections.forEach(function(s) {
            html += '<label class="hw-check-item"><input type="checkbox" name="createSection" value="' + esc(s) + '"><span>' + esc(s) + '</span></label>';
        });
        listEl.innerHTML = html;
    },

    toggleAll: function(el) {
        var checks = document.querySelectorAll('input[name="createSection"]');
        for (var i = 0; i < checks.length; i++) checks[i].checked = el.checked;
    },

    preview: function() {
        var cls = document.getElementById('createClass').value;
        var sections = [];
        var checks = document.querySelectorAll('input[name="createSection"]:checked');
        for (var i = 0; i < checks.length; i++) sections.push(checks[i].value);
        var subj  = document.getElementById('createSubject').value;
        var title = document.getElementById('createTitle').value;
        var desc  = document.getElementById('createDesc').value;
        var due   = document.getElementById('createDueDate').value;

        if (!cls || !sections.length || !subj || !title || !due) {
            toast('Please fill all required fields first', 'warning');
            return;
        }

        var html = '<div style="font-size:13px;">' +
            '<p><strong>Title:</strong> ' + esc(title) + '</p>' +
            '<p><strong>Subject:</strong> ' + esc(subj) + '</p>' +
            '<p><strong>Class:</strong> ' + esc(cls) + ' | <strong>Sections:</strong> ' + esc(sections.join(', ')) + '</p>' +
            '<p><strong>Due:</strong> ' + formatDate(due) + '</p>' +
            (desc ? '<p><strong>Description:</strong> ' + esc(desc) + '</p>' : '') +
            '</div>';

        setHtml('previewContent', html);
        document.getElementById('createPreview').style.display = 'block';
    },

    submit: function() {
        var cls = document.getElementById('createClass').value;
        var sections = [];
        var checks = document.querySelectorAll('input[name="createSection"]:checked');
        for (var i = 0; i < checks.length; i++) sections.push(checks[i].value);
        var subj  = document.getElementById('createSubject').value;
        var title = document.getElementById('createTitle').value;
        var desc  = document.getElementById('createDesc').value;
        var due   = document.getElementById('createDueDate').value;

        if (!cls || !sections.length || !subj || !title || !due) {
            toast('Please fill all required fields', 'warning');
            return;
        }

        if (!confirm('Create homework for ' + sections.length + ' section(s)?')) return;

        var data = {
            class:    cls,
            subject:  subj,
            title:    title,
            description: desc,
            due_date: due,
        };
        // Add sections as array
        sections.forEach(function(s, i) {
            data['sections[' + i + ']'] = s;
        });

        ajaxPost('homework/create_homework', data, function(r) {
            if (r.status !== 'success') { toast(r.message || 'Creation failed', 'error'); return; }
            toast('Homework created successfully!');

            // Reset form
            document.getElementById('createHwForm').reset();
            document.getElementById('createSectionsList').innerHTML = '<div class="hw-check-empty">Select a class first</div>';
            document.getElementById('createPreview').style.display = 'none';

            // Reset default due date
            var tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('createDueDate').value = tomorrow.toISOString().split('T')[0];

            // Invalidate caches
            _classSections = null;
            _hwCache = null;
            if (HW.list._loaded) { HW.list._loaded = false; }
        });
    }
};

/* ── INIT ────────────────────────────────────── */
HW.dash.init();

})();
}); // end DOMContentLoaded wrapper
</script>
