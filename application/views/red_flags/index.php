<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ══════════════════════════════════════════════════════════════════
   Red Flags Dashboard — Premium SPA View
   ══════════════════════════════════════════════════════════════════ */
:root {
    --rf-primary: var(--gold);
    --rf-primary-dim: var(--gold-dim);
    --rf-primary-glow: var(--gold-glow);
    --rf-bg: var(--bg);
    --rf-bg2: var(--bg2);
    --rf-bg3: var(--bg3);
    --rf-bg4: var(--bg4);
    --rf-card: var(--card);
    --rf-border: var(--border);
    --rf-brd2: var(--brd2);
    --rf-t1: var(--t1);
    --rf-t2: var(--t2);
    --rf-t3: var(--t3);
    --rf-t4: var(--t4);
    --rf-shadow: var(--sh);
    --rf-r: 12px;
    --rf-r-sm: 8px;
    --rf-red: #dc2626;
    --rf-red-dim: rgba(220,38,38,.10);
    --rf-amber: #d97706;
    --rf-amber-dim: rgba(217,119,6,.10);
    --rf-blue: #2563eb;
    --rf-blue-dim: rgba(37,99,235,.10);
    --rf-green: #16a34a;
    --rf-green-dim: rgba(22,163,74,.10);
    --rf-rose: var(--rose);
    --rf-rose-dim: rgba(224,92,111,.10);
}

.rf-wrap { padding: 20px 22px 40px; }

/* ── Page Header ────────────────────────────────────────────── */
.rf-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 14px; padding: 18px 22px; margin-bottom: 24px;
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); box-shadow: var(--rf-shadow); flex-wrap: wrap;
}
.rf-header-left { display: flex; align-items: center; gap: 14px; }
.rf-header-icon {
    width: 44px; height: 44px; border-radius: 10px;
    background: var(--rf-red); display: flex; align-items: center;
    justify-content: center; flex-shrink: 0;
    box-shadow: 0 0 18px rgba(220,38,38,.25);
}
.rf-header-icon i { color: #fff; font-size: 20px; }
.rf-page-title {
    font-size: 18px; font-weight: 700; color: var(--rf-t1); font-family: var(--font-d);
}
.rf-breadcrumb {
    list-style: none; display: flex; gap: 6px; margin: 4px 0 0; padding: 0;
    font-size: 12px; color: var(--rf-t3);
}
.rf-breadcrumb li + li::before { content: '/'; margin-right: 6px; color: var(--rf-t3); }
.rf-breadcrumb a { color: var(--rf-primary); text-decoration: none; }

/* ── Tab Navigation ─────────────────────────────────────────── */
.rf-tabs {
    display: flex; gap: 4px; padding: 4px; margin-bottom: 24px;
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); overflow-x: auto;
}
.rf-tab {
    padding: 10px 20px; border-radius: var(--rf-r-sm); font-size: 13px;
    font-weight: 600; color: var(--rf-t3); cursor: pointer; white-space: nowrap;
    transition: all .18s ease; border: none; background: none;
    font-family: var(--font-b); display: flex; align-items: center; gap: 8px;
}
.rf-tab:hover { color: var(--rf-t1); background: var(--rf-bg3); }
.rf-tab.active {
    color: #fff; background: var(--rf-primary);
    box-shadow: 0 2px 8px var(--rf-primary-glow);
}
.rf-tab i { font-size: 14px; }
.rf-tab-badge {
    font-size: 10px; font-family: var(--font-m); padding: 2px 6px;
    border-radius: 10px; background: rgba(255,255,255,.2); min-width: 18px;
    text-align: center; line-height: 1.3;
}
.rf-tab:not(.active) .rf-tab-badge {
    background: var(--rf-red-dim); color: var(--rf-red);
}
.rf-tab-panel { display: none; }
.rf-tab-panel.active { display: block; }

/* ── KPI Cards ──────────────────────────────────────────────── */
.rf-kpis {
    display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 28px;
}
@media (max-width: 1200px) { .rf-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px) { .rf-kpis { grid-template-columns: repeat(2, 1fr); } }

.rf-kpi {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); padding: 20px; box-shadow: var(--rf-shadow);
    position: relative; overflow: hidden;
    transition: transform .2s ease, box-shadow .2s ease;
}
.rf-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.12); }
.rf-kpi::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    border-radius: var(--rf-r) var(--rf-r) 0 0;
}
.rf-kpi.total::before   { background: var(--rf-primary); }
.rf-kpi.active-f::before  { background: var(--rf-red); }
.rf-kpi.resolved::before { background: var(--rf-green); }
.rf-kpi.high::before     { background: var(--rf-amber); }
.rf-kpi.week::before     { background: var(--rf-blue); }

.rf-kpi-icon {
    width: 40px; height: 40px; border-radius: 10px; display: flex;
    align-items: center; justify-content: center; margin-bottom: 12px; font-size: 18px;
}
.rf-kpi.total .rf-kpi-icon    { background: var(--rf-primary-dim); color: var(--rf-primary); }
.rf-kpi.active-f .rf-kpi-icon { background: var(--rf-red-dim); color: var(--rf-red); }
.rf-kpi.resolved .rf-kpi-icon { background: var(--rf-green-dim); color: var(--rf-green); }
.rf-kpi.high .rf-kpi-icon     { background: var(--rf-amber-dim); color: var(--rf-amber); }
.rf-kpi.week .rf-kpi-icon     { background: var(--rf-blue-dim); color: var(--rf-blue); }

.rf-kpi-val {
    font-size: 28px; font-weight: 800; color: var(--rf-t1);
    font-family: var(--font-d); line-height: 1;
}
.rf-kpi-label {
    font-size: 12px; color: var(--rf-t3); margin-top: 6px;
    font-weight: 600; text-transform: uppercase; letter-spacing: .3px;
}

/* ── Loading skeleton ───────────────────────────────────────── */
.rf-skel {
    width: 48px; height: 24px; border-radius: 6px;
    background: var(--rf-bg3); animation: rf-pulse 1.2s ease-in-out infinite;
}
@keyframes rf-pulse { 0%,100% { opacity:.5; } 50% { opacity:1; } }

.rf-loading-overlay {
    display: flex; align-items: center; justify-content: center;
    padding: 60px 20px; color: var(--rf-t3); font-size: 13px;
    flex-direction: column; gap: 12px;
}
.rf-loading-overlay i { font-size: 24px; animation: rf-spin 1s linear infinite; }
@keyframes rf-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

/* ── Empty State ────────────────────────────────────────────── */
.rf-empty {
    text-align: center; padding: 60px 20px; color: var(--rf-t3);
}
.rf-empty i { font-size: 40px; display: block; margin-bottom: 14px; opacity: .3; }
.rf-empty p { font-size: 14px; margin: 0; }

/* ── Section Cards ──────────────────────────────────────────── */
.rf-section {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); box-shadow: var(--rf-shadow);
    margin-bottom: 24px; overflow: hidden;
}
.rf-section-hd {
    display: flex; align-items: center; gap: 10px; padding: 16px 20px;
    border-bottom: 1px solid var(--rf-border);
}
.rf-section-hd i { color: var(--rf-primary); font-size: 16px; }
.rf-section-title {
    font-size: 15px; font-weight: 700; color: var(--rf-t1); font-family: var(--font-d);
}
.rf-section-hd .rf-section-extra { margin-left: auto; font-size: 12px; color: var(--rf-t3); }
.rf-section-body { padding: 20px; }

/* ── Charts Grid ────────────────────────────────────────────── */
.rf-charts-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;
}
@media (max-width: 992px) { .rf-charts-grid { grid-template-columns: 1fr; } }

.rf-chart-box {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); box-shadow: var(--rf-shadow);
    padding: 20px; min-height: 280px;
}
.rf-chart-title {
    font-size: 14px; font-weight: 700; color: var(--rf-t1);
    font-family: var(--font-d); margin-bottom: 16px;
    display: flex; align-items: center; gap: 8px;
}
.rf-chart-title i { color: var(--rf-primary); }
.rf-chart-canvas { position: relative; width: 100%; height: 220px; }

/* ── Severity badges ────────────────────────────────────────── */
.rf-badge {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 20px; font-size: 11px; font-weight: 700;
    font-family: var(--font-m); letter-spacing: .3px; white-space: nowrap;
}
.rf-badge.high   { background: var(--rf-red-dim); color: var(--rf-red); }
.rf-badge.medium { background: var(--rf-amber-dim); color: var(--rf-amber); }
.rf-badge.low    { background: var(--rf-blue-dim); color: var(--rf-blue); }

.rf-status-badge {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 20px; font-size: 11px; font-weight: 700;
    font-family: var(--font-m); letter-spacing: .3px;
}
.rf-status-badge.active-s   { background: var(--rf-red-dim); color: var(--rf-red); }
.rf-status-badge.resolved-s { background: var(--rf-green-dim); color: var(--rf-green); }

.rf-type-badge {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px;
    border-radius: 20px; font-size: 11px; font-weight: 600; font-family: var(--font-b);
}
.rf-type-badge.homework    { background: var(--rf-blue-dim); color: var(--rf-blue); }
.rf-type-badge.behavior    { background: var(--rf-amber-dim); color: var(--rf-amber); }
.rf-type-badge.performance { background: var(--rf-rose-dim); color: var(--rf-rose); }

/* ── Timeline (recent flags) ────────────────────────────────── */
.rf-timeline { list-style: none; padding: 0; margin: 0; }
.rf-timeline-item {
    display: flex; gap: 14px; padding: 14px 0;
    border-bottom: 1px solid var(--rf-border); align-items: flex-start;
}
.rf-timeline-item:last-child { border-bottom: none; }
.rf-timeline-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: 5px;
}
.rf-timeline-dot.high   { background: var(--rf-red); box-shadow: 0 0 8px rgba(220,38,38,.4); }
.rf-timeline-dot.medium { background: var(--rf-amber); box-shadow: 0 0 8px rgba(217,119,6,.4); }
.rf-timeline-dot.low    { background: var(--rf-blue); box-shadow: 0 0 8px rgba(37,99,235,.4); }

.rf-timeline-content { flex: 1; min-width: 0; }
.rf-timeline-head {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px;
}
.rf-timeline-name { font-size: 13px; font-weight: 700; color: var(--rf-t1); }
.rf-timeline-class { font-size: 11px; color: var(--rf-t3); font-family: var(--font-m); }
.rf-timeline-msg {
    font-size: 12.5px; color: var(--rf-t2); line-height: 1.4;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%;
}
.rf-timeline-foot { display: flex; gap: 10px; margin-top: 6px; align-items: center; }
.rf-timeline-time { font-size: 11px; color: var(--rf-t4); font-family: var(--font-m); }
.rf-timeline-actions { margin-left: auto; display: flex; gap: 6px; }

/* ── Buttons ────────────────────────────────────────────────── */
.rf-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px;
    border-radius: var(--rf-r-sm); font-size: 12px; font-weight: 600;
    cursor: pointer; border: 1px solid transparent;
    transition: all .18s ease; font-family: var(--font-b);
}
.rf-btn.primary { background: var(--rf-primary); color: #fff; box-shadow: 0 2px 8px var(--rf-primary-glow); }
.rf-btn.primary:hover { filter: brightness(1.1); }
.rf-btn.outline { background: transparent; color: var(--rf-primary); border-color: var(--rf-border); }
.rf-btn.outline:hover { border-color: var(--rf-primary); background: var(--rf-primary-dim); }
.rf-btn.danger { background: var(--rf-red); color: #fff; }
.rf-btn.danger:hover { filter: brightness(1.1); }
.rf-btn.success { background: var(--rf-green); color: #fff; }
.rf-btn.success:hover { filter: brightness(1.1); }
.rf-btn.sm { padding: 4px 10px; font-size: 11px; }
.rf-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Filter Bar ─────────────────────────────────────────────── */
.rf-filters {
    display: flex; gap: 10px; flex-wrap: wrap; padding: 16px 20px;
    border-bottom: 1px solid var(--rf-border); align-items: flex-end;
}
.rf-filter-group { display: flex; flex-direction: column; gap: 4px; }
.rf-filter-group label {
    font-size: 10px; font-weight: 700; color: var(--rf-t4);
    text-transform: uppercase; letter-spacing: .5px; font-family: var(--font-m);
}
.rf-filter-group select, .rf-filter-group input {
    background: var(--rf-bg3); border: 1px solid var(--rf-border);
    border-radius: 6px; padding: 7px 10px; font-size: 12px;
    color: var(--rf-t1); font-family: var(--font-b);
    min-width: 130px; outline: none; transition: border-color .15s;
}
.rf-filter-group select:focus, .rf-filter-group input:focus {
    border-color: var(--rf-primary);
}

/* ── Table styles ───────────────────────────────────────────── */
.rf-table-wrap { overflow-x: auto; }
.rf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rf-table thead th {
    padding: 12px 14px; text-align: left; font-weight: 700;
    color: var(--rf-t3); font-size: 11px; text-transform: uppercase;
    letter-spacing: .5px; font-family: var(--font-m);
    border-bottom: 2px solid var(--rf-border); background: var(--rf-bg3);
    white-space: nowrap;
}
.rf-table tbody td {
    padding: 12px 14px; border-bottom: 1px solid var(--rf-border);
    color: var(--rf-t2); vertical-align: middle;
}
.rf-table tbody tr { transition: background .12s; }
.rf-table tbody tr:hover { background: var(--rf-bg3); }
.rf-table tbody tr.expanded-row { background: var(--rf-bg3); }
.rf-table tbody tr.sev-high   { background: rgba(220,38,38,.05); border-left: 3px solid var(--rf-red); }
.rf-table tbody tr.sev-medium { background: rgba(217,119,6,.04); border-left: 3px solid var(--rf-amber); }
.rf-table tbody tr.sev-low    { border-left: 3px solid transparent; }
.rf-table tbody tr.sev-high:hover   { background: rgba(220,38,38,.10); }
.rf-table tbody tr.sev-medium:hover { background: rgba(217,119,6,.08); }
.rf-table .rf-student-name { font-weight: 600; color: var(--rf-t1); cursor: pointer; }
.rf-table .rf-student-name:hover { color: var(--rf-primary); }
.rf-table .rf-msg-preview {
    max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ── Expanded row detail ────────────────────────────────────── */
.rf-expand-row {
    display: none; background: var(--rf-bg3);
}
.rf-expand-row.open { display: table-row; }
.rf-expand-detail {
    padding: 16px 20px; display: grid;
    grid-template-columns: 1fr 1fr 1fr; gap: 14px;
}
@media (max-width: 768px) { .rf-expand-detail { grid-template-columns: 1fr; } }
.rf-detail-item label {
    font-size: 10px; font-weight: 700; color: var(--rf-t4);
    text-transform: uppercase; font-family: var(--font-m);
    display: block; margin-bottom: 2px;
}
.rf-detail-item span { font-size: 13px; color: var(--rf-t1); }
.rf-detail-msg {
    grid-column: 1 / -1; padding: 12px; background: var(--rf-bg2);
    border: 1px solid var(--rf-border); border-radius: var(--rf-r-sm);
    font-size: 13px; color: var(--rf-t2); line-height: 1.5;
}

/* ── Checkbox for bulk select ───────────────────────────────── */
.rf-check {
    width: 16px; height: 16px; accent-color: var(--rf-primary);
    cursor: pointer; margin: 0;
}

/* ── Bulk action bar ────────────────────────────────────────── */
.rf-bulk-bar {
    display: none; padding: 12px 20px; background: var(--rf-primary-dim);
    border-bottom: 1px solid var(--rf-primary);
    align-items: center; gap: 12px;
}
.rf-bulk-bar.show { display: flex; }
.rf-bulk-count { font-size: 13px; font-weight: 700; color: var(--rf-primary); }

/* ── Student Drill-Down ─────────────────────────────────────── */
.rf-student-card {
    display: flex; gap: 20px; padding: 20px;
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); margin-bottom: 24px; align-items: flex-start;
}
.rf-student-avatar {
    width: 64px; height: 64px; border-radius: 14px;
    background: var(--rf-primary); display: flex; align-items: center;
    justify-content: center; font-size: 24px; font-weight: 800;
    color: #fff; font-family: var(--font-d); flex-shrink: 0;
}
.rf-student-info { flex: 1; }
.rf-student-info h3 {
    font-size: 18px; font-weight: 700; color: var(--rf-t1);
    font-family: var(--font-d); margin: 0 0 4px;
}
.rf-student-meta {
    display: flex; gap: 16px; flex-wrap: wrap; font-size: 12px; color: var(--rf-t3);
}
.rf-student-meta span { display: flex; align-items: center; gap: 4px; }
.rf-student-meta i { font-size: 11px; color: var(--rf-t4); }

.rf-student-stats {
    display: flex; gap: 12px; margin-top: 14px; flex-wrap: wrap;
}
.rf-student-stat {
    padding: 8px 16px; background: var(--rf-bg3);
    border: 1px solid var(--rf-border); border-radius: var(--rf-r-sm);
    text-align: center; min-width: 80px;
}
.rf-student-stat-val {
    font-size: 20px; font-weight: 800; color: var(--rf-t1); font-family: var(--font-d);
}
.rf-student-stat-lbl {
    font-size: 10px; color: var(--rf-t3); text-transform: uppercase;
    font-family: var(--font-m); margin-top: 2px;
}

/* ── Student Search ─────────────────────────────────────────── */
.rf-search-wrap {
    position: relative; max-width: 420px; margin-bottom: 24px;
}
.rf-search-input {
    width: 100%; padding: 10px 14px 10px 38px;
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r-sm); font-size: 13px; color: var(--rf-t1);
    font-family: var(--font-b); outline: none; transition: border-color .15s;
}
.rf-search-input:focus { border-color: var(--rf-primary); }
.rf-search-wrap i {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: var(--rf-t4); font-size: 13px;
}
.rf-search-results {
    position: absolute; top: 100%; left: 0; right: 0; z-index: 100;
    background: var(--rf-bg2); border: 1px solid var(--rf-brd2);
    border-radius: var(--rf-r-sm); box-shadow: var(--rf-shadow);
    max-height: 240px; overflow-y: auto; display: none;
}
.rf-search-results.show { display: block; }
.rf-search-item {
    padding: 10px 14px; cursor: pointer; transition: background .12s;
    display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--rf-border);
}
.rf-search-item:last-child { border-bottom: none; }
.rf-search-item:hover { background: var(--rf-bg3); }
.rf-search-item-name { font-size: 13px; font-weight: 600; color: var(--rf-t1); }
.rf-search-item-sub { font-size: 11px; color: var(--rf-t3); font-family: var(--font-m); }

/* ── Comparison card ────────────────────────────────────────── */
.rf-comparison {
    display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;
}
@media (max-width: 768px) { .rf-comparison { grid-template-columns: 1fr; } }

.rf-compare-card {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); padding: 20px; text-align: center;
}
.rf-compare-val {
    font-size: 32px; font-weight: 800; color: var(--rf-t1);
    font-family: var(--font-d);
}
.rf-compare-lbl { font-size: 12px; color: var(--rf-t3); margin-top: 4px; }
.rf-compare-change {
    display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px;
    border-radius: 20px; font-size: 11px; font-weight: 700; margin-top: 8px;
    font-family: var(--font-m);
}
.rf-compare-change.up   { background: var(--rf-red-dim); color: var(--rf-red); }
.rf-compare-change.down { background: var(--rf-green-dim); color: var(--rf-green); }
.rf-compare-change.flat { background: var(--rf-bg3); color: var(--rf-t3); }

/* ── Modal (create flag) ────────────────────────────────────── */
.rf-modal-bg {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,.55); z-index: 9999;
    display: none; align-items: center; justify-content: center;
}
.rf-modal-bg.show { display: flex; }
.rf-modal {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); width: 520px; max-width: 95vw; max-height: 85vh;
    overflow-y: auto; padding: 24px; box-shadow: 0 16px 48px rgba(0,0,0,.25);
}
.rf-modal-hd {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 20px;
}
.rf-modal-title {
    font-size: 16px; font-weight: 700; color: var(--rf-t1); font-family: var(--font-d);
}
.rf-modal-close {
    cursor: pointer; color: var(--rf-t3); font-size: 20px;
    background: none; border: none; width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px; transition: all .15s;
}
.rf-modal-close:hover { background: var(--rf-bg3); color: var(--rf-t1); }

.rf-form-group { margin-bottom: 16px; }
.rf-form-group label {
    display: block; font-size: 12px; font-weight: 700; color: var(--rf-t2);
    margin-bottom: 6px; font-family: var(--font-b);
}
.rf-form-group select, .rf-form-group input, .rf-form-group textarea {
    width: 100%; padding: 9px 12px; background: var(--rf-bg3);
    border: 1px solid var(--rf-border); border-radius: 6px;
    font-size: 13px; color: var(--rf-t1); font-family: var(--font-b);
    outline: none; transition: border-color .15s; resize: vertical;
}
.rf-form-group select:focus, .rf-form-group input:focus, .rf-form-group textarea:focus {
    border-color: var(--rf-primary);
}
.rf-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

/* ── Confirm dialog ─────────────────────────────────────────── */
.rf-confirm-bg {
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,.55); z-index: 10000;
    display: none; align-items: center; justify-content: center;
}
.rf-confirm-bg.show { display: flex; }
.rf-confirm-box {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); padding: 24px; width: 380px;
    max-width: 92vw; text-align: center; box-shadow: 0 16px 48px rgba(0,0,0,.25);
}
.rf-confirm-icon { font-size: 40px; margin-bottom: 12px; }
.rf-confirm-title { font-size: 16px; font-weight: 700; color: var(--rf-t1); margin-bottom: 8px; }
.rf-confirm-msg { font-size: 13px; color: var(--rf-t3); margin-bottom: 20px; }
.rf-confirm-btns { display: flex; gap: 10px; justify-content: center; }

/* ── Heatmap Grid ──────────────────────────────────────────── */
.rf-heatmap { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
.rf-heat-cell {
    padding: 16px; border-radius: var(--rf-r-sm); text-align: center;
    border: 1px solid var(--rf-border); transition: transform .15s, box-shadow .15s;
    cursor: default; position: relative; overflow: hidden;
}
.rf-heat-cell:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.rf-heat-cell .rf-heat-label {
    font-size: 12px; font-weight: 700; color: var(--rf-t1);
    font-family: var(--font-b); margin-bottom: 8px;
}
.rf-heat-cell .rf-heat-val {
    font-size: 22px; font-weight: 800; color: var(--rf-t1); font-family: var(--font-d);
}
.rf-heat-cell .rf-heat-sub {
    font-size: 10px; color: var(--rf-t3); font-family: var(--font-m);
    margin-top: 4px; text-transform: uppercase;
}
.rf-heat-cell.heat-0 { background: var(--rf-bg3); }
.rf-heat-cell.heat-1 { background: rgba(37,99,235,.08); }
.rf-heat-cell.heat-2 { background: rgba(217,119,6,.08); }
.rf-heat-cell.heat-3 { background: rgba(220,38,38,.08); }
.rf-heat-cell.heat-4 { background: rgba(220,38,38,.15); }

/* ── Create Flag Tab (Tab 5) ───────────────────────────────── */
.rf-create-wrap {
    max-width: 720px; margin: 0 auto;
}
.rf-create-card {
    background: var(--rf-bg2); border: 1px solid var(--rf-border);
    border-radius: var(--rf-r); padding: 28px; box-shadow: var(--rf-shadow);
}
.rf-create-title {
    font-size: 16px; font-weight: 700; color: var(--rf-t1);
    font-family: var(--font-d); margin-bottom: 24px;
    display: flex; align-items: center; gap: 10px;
}
.rf-create-title i { color: var(--rf-red); }

/* ── Toast ──────────────────────────────────────────────────── */
.rf-toast-container {
    position: fixed; top: 80px; right: 24px; z-index: 11000;
    display: flex; flex-direction: column; gap: 8px;
}
.rf-toast {
    padding: 12px 18px; border-radius: var(--rf-r-sm);
    font-size: 13px; font-weight: 600; color: #fff;
    box-shadow: 0 4px 16px rgba(0,0,0,.2);
    animation: rf-toast-in .3s ease; min-width: 260px;
    display: flex; align-items: center; gap: 8px;
}
.rf-toast.success { background: var(--rf-green); }
.rf-toast.error   { background: var(--rf-red); }
.rf-toast.info    { background: var(--rf-blue); }
@keyframes rf-toast-in { from { opacity:0; transform:translateX(20px); } to { opacity:1; transform:none; } }

/* ── Top Students Table ─────────────────────────────────────── */
.rf-top-table { width: 100%; border-collapse: collapse; }
.rf-top-table th {
    padding: 10px 12px; font-size: 11px; font-weight: 700;
    color: var(--rf-t4); text-transform: uppercase; letter-spacing: .5px;
    font-family: var(--font-m); border-bottom: 1px solid var(--rf-border);
    text-align: left;
}
.rf-top-table td {
    padding: 10px 12px; border-bottom: 1px solid var(--rf-border);
    font-size: 13px; color: var(--rf-t2);
}
.rf-top-table tbody tr:hover { background: var(--rf-bg3); }
.rf-top-rank {
    width: 24px; height: 24px; border-radius: 6px;
    background: var(--rf-primary-dim); color: var(--rf-primary);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 800; font-family: var(--font-m);
}
</style>

<!-- ═══════════════════════════════════════════════════════════════
     HTML STRUCTURE
     ═══════════════════════════════════════════════════════════════ -->
<div class="content-wrapper">
<section class="content">
<div class="rf-wrap">

    <!-- Page Header -->
    <div class="rf-header">
        <div class="rf-header-left">
            <div class="rf-header-icon"><i class="fa fa-flag"></i></div>
            <div>
                <div class="rf-page-title">Red Flags Dashboard</div>
                <ul class="rf-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li>Red Flags</li>
                </ul>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <button class="rf-btn primary" onclick="RF.openCreateModal()"><i class="fa fa-plus"></i> Create Flag</button>
            <button class="rf-btn outline" onclick="RF.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="rf-tabs">
        <button class="rf-tab active" data-tab="overview">
            <i class="fa fa-dashboard"></i> Overview
            <span class="rf-tab-badge" id="rf-badge-total">-</span>
        </button>
        <button class="rf-tab" data-tab="management">
            <i class="fa fa-list-alt"></i> Flag Management
            <span class="rf-tab-badge" id="rf-badge-active">-</span>
        </button>
        <button class="rf-tab" data-tab="student">
            <i class="fa fa-user"></i> Student Drill-Down
        </button>
        <button class="rf-tab" data-tab="analytics">
            <i class="fa fa-line-chart"></i> Analytics
        </button>
        <button class="rf-tab" data-tab="create">
            <i class="fa fa-plus-circle"></i> Create Flag
        </button>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         TAB 1: OVERVIEW
         ═══════════════════════════════════════════════════════════ -->
    <div class="rf-tab-panel active" id="tab-overview">

        <!-- KPI Cards -->
        <div class="rf-kpis">
            <div class="rf-kpi total">
                <div class="rf-kpi-icon"><i class="fa fa-flag"></i></div>
                <div class="rf-kpi-val" id="kpi-total"><span class="rf-skel"></span></div>
                <div class="rf-kpi-label">Total Flags</div>
            </div>
            <div class="rf-kpi active-f">
                <div class="rf-kpi-icon"><i class="fa fa-exclamation-circle"></i></div>
                <div class="rf-kpi-val" id="kpi-active"><span class="rf-skel"></span></div>
                <div class="rf-kpi-label">Active Flags</div>
            </div>
            <div class="rf-kpi resolved">
                <div class="rf-kpi-icon"><i class="fa fa-check-circle"></i></div>
                <div class="rf-kpi-val" id="kpi-resolved"><span class="rf-skel"></span></div>
                <div class="rf-kpi-label">Resolved</div>
            </div>
            <div class="rf-kpi high">
                <div class="rf-kpi-icon"><i class="fa fa-warning"></i></div>
                <div class="rf-kpi-val" id="kpi-high"><span class="rf-skel"></span></div>
                <div class="rf-kpi-label">High Severity</div>
            </div>
            <div class="rf-kpi week">
                <div class="rf-kpi-icon"><i class="fa fa-calendar-plus-o"></i></div>
                <div class="rf-kpi-val" id="kpi-week"><span class="rf-skel"></span></div>
                <div class="rf-kpi-label">This Week</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="rf-charts-grid">
            <div class="rf-chart-box">
                <div class="rf-chart-title"><i class="fa fa-pie-chart"></i> Severity Distribution</div>
                <div class="rf-chart-canvas"><canvas id="chart-severity"></canvas></div>
            </div>
            <div class="rf-chart-box">
                <div class="rf-chart-title"><i class="fa fa-bar-chart"></i> Flags by Type</div>
                <div class="rf-chart-canvas"><canvas id="chart-type"></canvas></div>
            </div>
        </div>

        <!-- Class Comparison Chart -->
        <div class="rf-chart-box" style="margin-bottom:24px;">
            <div class="rf-chart-title"><i class="fa fa-th"></i> Flags by Class</div>
            <div style="position:relative;width:100%;height:260px;">
                <canvas id="chart-class"></canvas>
            </div>
        </div>

        <!-- Class Heatmap -->
        <div class="rf-section" style="margin-bottom:24px;">
            <div class="rf-section-hd">
                <i class="fa fa-th-large"></i>
                <span class="rf-section-title">Class Heatmap</span>
                <span class="rf-section-extra">Flag density by class</span>
            </div>
            <div class="rf-section-body">
                <div class="rf-heatmap" id="class-heatmap">
                    <div class="rf-loading-overlay"><i class="fa fa-spinner"></i> Loading...</div>
                </div>
            </div>
        </div>

        <!-- Bottom Row: Recent Timeline + Top Students -->
        <div class="rf-charts-grid">
            <!-- Recent Flags Timeline -->
            <div class="rf-section">
                <div class="rf-section-hd">
                    <i class="fa fa-clock-o"></i>
                    <span class="rf-section-title">Recent Flags</span>
                    <span class="rf-section-extra">Last 10</span>
                </div>
                <div class="rf-section-body" style="padding:10px 20px;">
                    <div id="recent-timeline" class="rf-loading-overlay">
                        <i class="fa fa-spinner"></i> Loading...
                    </div>
                </div>
            </div>

            <!-- Top Flagged Students -->
            <div class="rf-section">
                <div class="rf-section-hd">
                    <i class="fa fa-users"></i>
                    <span class="rf-section-title">Top Flagged Students</span>
                </div>
                <div class="rf-section-body" style="padding:0;">
                    <div id="top-students" class="rf-loading-overlay">
                        <i class="fa fa-spinner"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         TAB 2: FLAG MANAGEMENT
         ═══════════════════════════════════════════════════════════ -->
    <div class="rf-tab-panel" id="tab-management">
        <div class="rf-section">
            <!-- Filter Bar -->
            <div class="rf-filters">
                <div class="rf-filter-group">
                    <label>Class</label>
                    <select id="f-class"><option value="">All Classes</option></select>
                </div>
                <div class="rf-filter-group">
                    <label>Type</label>
                    <select id="f-type">
                        <option value="">All Types</option>
                        <option value="Homework">Homework</option>
                        <option value="Behavior">Behavior</option>
                        <option value="Performance">Performance</option>
                    </select>
                </div>
                <div class="rf-filter-group">
                    <label>Severity</label>
                    <select id="f-severity">
                        <option value="">All</option>
                        <option value="High">High</option>
                        <option value="Medium">Medium</option>
                        <option value="Low">Low</option>
                    </select>
                </div>
                <div class="rf-filter-group">
                    <label>Status</label>
                    <select id="f-status">
                        <option value="">All</option>
                        <option value="Active">Active</option>
                        <option value="Resolved">Resolved</option>
                    </select>
                </div>
                <div class="rf-filter-group">
                    <label>Student</label>
                    <input type="text" id="f-student" placeholder="Name or ID...">
                </div>
                <div class="rf-filter-group">
                    <label>From</label>
                    <input type="date" id="f-date-from">
                </div>
                <div class="rf-filter-group">
                    <label>To</label>
                    <input type="date" id="f-date-to">
                </div>
                <div class="rf-filter-group" style="justify-content:flex-end;">
                    <button class="rf-btn primary" onclick="RF.loadFlags()"><i class="fa fa-search"></i> Search</button>
                </div>
                <div class="rf-filter-group" style="justify-content:flex-end;">
                    <button class="rf-btn outline" onclick="RF.clearFilters()"><i class="fa fa-times"></i> Clear</button>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div class="rf-bulk-bar" id="rf-bulk-bar">
                <input type="checkbox" class="rf-check" id="rf-select-all" onchange="RF.toggleSelectAll()">
                <span class="rf-bulk-count"><span id="rf-bulk-count">0</span> selected</span>
                <button class="rf-btn success sm" onclick="RF.bulkResolve()"><i class="fa fa-check"></i> Resolve Selected</button>
            </div>

            <!-- Flags Table -->
            <div class="rf-table-wrap">
                <div id="flags-table-area">
                    <div class="rf-loading-overlay"><i class="fa fa-spinner"></i> Loading flags...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         TAB 3: STUDENT DRILL-DOWN
         ═══════════════════════════════════════════════════════════ -->
    <div class="rf-tab-panel" id="tab-student">

        <!-- Student Search -->
        <div class="rf-search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" class="rf-search-input" id="student-search" placeholder="Search student by name or ID..." autocomplete="off">
            <div class="rf-search-results" id="student-search-results"></div>
        </div>

        <!-- Student Profile (hidden until selected) -->
        <div id="student-profile-area" style="display:none;">
            <div id="student-card"></div>

            <!-- Student flag analysis charts -->
            <div class="rf-charts-grid" style="margin-bottom:24px;">
                <div class="rf-chart-box">
                    <div class="rf-chart-title"><i class="fa fa-pie-chart"></i> Flag Types</div>
                    <div class="rf-chart-canvas"><canvas id="chart-student-types"></canvas></div>
                </div>
                <div class="rf-chart-box">
                    <div class="rf-chart-title"><i class="fa fa-bar-chart"></i> Severity Breakdown</div>
                    <div class="rf-chart-canvas"><canvas id="chart-student-severity"></canvas></div>
                </div>
            </div>

            <!-- Student Flag Timeline -->
            <div class="rf-section">
                <div class="rf-section-hd">
                    <i class="fa fa-history"></i>
                    <span class="rf-section-title">Flag History</span>
                    <span class="rf-section-extra" id="student-flag-count"></span>
                </div>
                <div class="rf-section-body" id="student-flags-list" style="padding:10px 20px;"></div>
            </div>
        </div>

        <div id="student-empty" class="rf-empty">
            <i class="fa fa-user-circle-o"></i>
            <p>Search for a student above to view their red flag history and analysis.</p>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         TAB 4: ANALYTICS
         ═══════════════════════════════════════════════════════════ -->
    <div class="rf-tab-panel" id="tab-analytics">

        <div id="analytics-area">
            <div class="rf-loading-overlay"><i class="fa fa-spinner"></i> Loading analytics...</div>
        </div>

        <div id="analytics-content" style="display:none;">

            <!-- Month Comparison -->
            <div class="rf-comparison" id="month-comparison"></div>

            <!-- Trend Line -->
            <div class="rf-chart-box" style="margin-bottom:24px;">
                <div class="rf-chart-title"><i class="fa fa-line-chart"></i> Weekly Trend (Last 12 Weeks)</div>
                <div style="position:relative;width:100%;height:280px;">
                    <canvas id="chart-trend"></canvas>
                </div>
            </div>

            <div class="rf-charts-grid">
                <!-- Teacher Activity -->
                <div class="rf-chart-box">
                    <div class="rf-chart-title"><i class="fa fa-user-circle"></i> Teacher Activity</div>
                    <div style="position:relative;width:100%;height:260px;">
                        <canvas id="chart-teachers"></canvas>
                    </div>
                </div>

                <!-- Subject Breakdown -->
                <div class="rf-chart-box">
                    <div class="rf-chart-title"><i class="fa fa-book"></i> Subject Breakdown</div>
                    <div style="position:relative;width:100%;height:260px;">
                        <canvas id="chart-subjects"></canvas>
                    </div>
                </div>
            </div>

            <!-- Resolution Stats -->
            <div class="rf-section" style="margin-top:24px;">
                <div class="rf-section-hd">
                    <i class="fa fa-clock-o"></i>
                    <span class="rf-section-title">Resolution Time Analysis</span>
                </div>
                <div class="rf-section-body" id="resolution-stats"></div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════
         TAB 5: CREATE FLAG
         ═══════════════════════════════════════════════════════════ -->
    <div class="rf-tab-panel" id="tab-create">
        <div class="rf-create-wrap">
            <div class="rf-create-card">
                <div class="rf-create-title"><i class="fa fa-flag"></i> Create New Red Flag</div>
                <form id="rf-tab-create-form" onsubmit="return RF.submitTabCreateFlag(event)">
                    <div class="rf-form-row">
                        <div class="rf-form-group">
                            <label>Class / Section *</label>
                            <select id="tc-class" required>
                                <option value="">Select class</option>
                            </select>
                        </div>
                        <div class="rf-form-group">
                            <label>Student *</label>
                            <select id="tc-student" required>
                                <option value="">Select class first</option>
                            </select>
                        </div>
                    </div>
                    <div class="rf-form-row">
                        <div class="rf-form-group">
                            <label>Type *</label>
                            <select id="tc-type" required>
                                <option value="">Select type</option>
                                <option value="Homework">Homework</option>
                                <option value="Behavior">Behavior</option>
                                <option value="Performance">Performance</option>
                            </select>
                        </div>
                        <div class="rf-form-group">
                            <label>Severity *</label>
                            <select id="tc-severity" required>
                                <option value="">Select severity</option>
                                <option value="Low">Low</option>
                                <option value="Medium">Medium</option>
                                <option value="High">High</option>
                            </select>
                        </div>
                    </div>
                    <div class="rf-form-group">
                        <label>Subject</label>
                        <input type="text" id="tc-subject" placeholder="e.g. Mathematics" maxlength="100">
                    </div>
                    <div class="rf-form-group">
                        <label>Message *</label>
                        <textarea id="tc-message" rows="4" placeholder="Describe the issue in detail..." required maxlength="1000"></textarea>
                        <div style="text-align:right;font-size:11px;color:var(--rf-t4);margin-top:4px;font-family:var(--font-m)">
                            <span id="tc-char-count">0</span> / 1000
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                        <button type="reset" class="rf-btn outline"><i class="fa fa-eraser"></i> Reset</button>
                        <button type="submit" class="rf-btn primary" id="tc-submit-btn"><i class="fa fa-plus"></i> Create Flag</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
</section>
</div>

<!-- ══════════════════════════════════════════════════════════════
     CREATE FLAG MODAL
     ══════════════════════════════════════════════════════════════ -->
<div class="rf-modal-bg" id="rf-create-modal">
    <div class="rf-modal">
        <div class="rf-modal-hd">
            <span class="rf-modal-title">Create Red Flag</span>
            <button class="rf-modal-close" onclick="RF.closeCreateModal()">&times;</button>
        </div>
        <form id="rf-create-form" onsubmit="return RF.submitCreateFlag(event)">
            <div class="rf-form-row">
                <div class="rf-form-group">
                    <label>Class / Section *</label>
                    <select id="cf-class" required></select>
                </div>
                <div class="rf-form-group">
                    <label>Student *</label>
                    <select id="cf-student" required>
                        <option value="">Select class first</option>
                    </select>
                </div>
            </div>
            <div class="rf-form-row">
                <div class="rf-form-group">
                    <label>Type *</label>
                    <select id="cf-type" required>
                        <option value="">Select type</option>
                        <option value="Homework">Homework</option>
                        <option value="Behavior">Behavior</option>
                        <option value="Performance">Performance</option>
                    </select>
                </div>
                <div class="rf-form-group">
                    <label>Severity *</label>
                    <select id="cf-severity" required>
                        <option value="">Select severity</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                    </select>
                </div>
            </div>
            <div class="rf-form-group">
                <label>Subject</label>
                <input type="text" id="cf-subject" placeholder="e.g. Mathematics" maxlength="100">
            </div>
            <div class="rf-form-group">
                <label>Message *</label>
                <textarea id="cf-message" rows="3" placeholder="Describe the issue..." required maxlength="1000"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="rf-btn outline" onclick="RF.closeCreateModal()">Cancel</button>
                <button type="submit" class="rf-btn primary" id="cf-submit-btn"><i class="fa fa-plus"></i> Create Flag</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirm dialog -->
<div class="rf-confirm-bg" id="rf-confirm">
    <div class="rf-confirm-box">
        <div class="rf-confirm-icon" id="rf-confirm-icon"></div>
        <div class="rf-confirm-title" id="rf-confirm-title"></div>
        <div class="rf-confirm-msg" id="rf-confirm-msg"></div>
        <div class="rf-confirm-btns">
            <button class="rf-btn outline" onclick="RF.closeConfirm()">Cancel</button>
            <button class="rf-btn danger" id="rf-confirm-btn">Confirm</button>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="rf-toast-container" id="rf-toasts"></div>

<!-- ══════════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>

<script>
(function() {
    'use strict';

    /* ────────────────────────────────────────────────────────────
       CORE MODULE
       ──────────────────────────────────────────────────────────── */
    var BASE = '<?= base_url("red_flags/") ?>';
    var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH  = '<?= $this->security->get_csrf_hash() ?>';

    /** XSS-safe text escaper */
    function esc(str) {
        if (str === null || str === undefined) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    /** Format timestamp (ms or s) to readable date */
    function fmtDate(ts) {
        if (!ts || ts === 0) return '-';
        var n = Number(ts);
        if (n > 9999999999) n = Math.floor(n / 1000);
        var d = new Date(n * 1000);
        return d.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
    }

    /** Format timestamp to relative time */
    function timeAgo(ts) {
        if (!ts || ts === 0) return '';
        var n = Number(ts);
        if (n > 9999999999) n = Math.floor(n / 1000);
        var diff = Math.floor(Date.now() / 1000) - n;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return fmtDate(ts);
    }

    /** Update CSRF token from response */
    function updateCsrf(data) {
        if (data && data.csrf_token) {
            CSRF_HASH = data.csrf_token;
            $('meta[name="csrf-token"]').attr('content', CSRF_HASH);
        }
    }

    /** AJAX helper */
    function ajax(endpoint, data, method) {
        method = method || 'POST';
        var opts = {
            url: BASE + endpoint,
            type: method,
            dataType: 'json',
            headers: { 'X-CSRF-Token': CSRF_HASH }
        };
        if (method === 'GET') {
            opts.data = data || {};
        } else {
            opts.data = data || {};
            opts.data[CSRF_NAME] = CSRF_HASH;
        }
        return $.ajax(opts).always(function(r) {
            var d = r.responseJSON || r;
            updateCsrf(d);
        });
    }

    /* ────────────────────────────────────────────────────────────
       STATE
       ──────────────────────────────────────────────────────────── */
    var state = {
        classes: [],
        overview: null,
        flags: [],
        selectedFlags: {},
        charts: {},
        allStudents: [],         // populated from flags for search
        currentStudentId: null,
        analyticsLoaded: false,
    };

    /* ────────────────────────────────────────────────────────────
       CHART HELPERS
       ──────────────────────────────────────────────────────────── */
    var chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: getComputedStyle(document.documentElement).getPropertyValue('--t2').trim() || '#94c9c3',
                    font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 }
                }
            }
        },
        scales: {}
    };

    function getChartColors() {
        return {
            red:    '#dc2626',
            amber:  '#d97706',
            blue:   '#2563eb',
            green:  '#16a34a',
            teal:   '#0f766e',
            rose:   '#e05c6f',
            purple: '#7c3aed',
            gray:   '#6b7280',
        };
    }

    function axisColor() {
        return getComputedStyle(document.documentElement).getPropertyValue('--t4').trim() || '#2e6b65';
    }

    function gridColor() {
        return getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || 'rgba(15,118,110,.12)';
    }

    function destroyChart(key) {
        if (state.charts[key]) {
            state.charts[key].destroy();
            state.charts[key] = null;
        }
    }

    /* ────────────────────────────────────────────────────────────
       TOAST / CONFIRM
       ──────────────────────────────────────────────────────────── */
    function toast(msg, type) {
        type = type || 'success';
        var t = $('<div class="rf-toast ' + esc(type) + '"><i class="fa fa-' +
            (type === 'success' ? 'check-circle' : type === 'error' ? 'times-circle' : 'info-circle') +
            '"></i> ' + esc(msg) + '</div>');
        $('#rf-toasts').append(t);
        setTimeout(function() { t.fadeOut(300, function() { t.remove(); }); }, 4000);
    }

    var confirmCallback = null;
    function showConfirm(title, msg, icon, btnClass, cb) {
        $('#rf-confirm-icon').html(icon);
        $('#rf-confirm-title').text(title);
        $('#rf-confirm-msg').text(msg);
        $('#rf-confirm-btn').attr('class', 'rf-btn ' + btnClass);
        confirmCallback = cb;
        $('#rf-confirm').addClass('show');
    }

    /* ────────────────────────────────────────────────────────────
       PUBLIC API (window.RF)
       ──────────────────────────────────────────────────────────── */
    window.RF = {

        /* ── Init ── */
        init: function() {
            RF.loadClasses();
            RF.loadOverview();

            // Tab switching
            $(document).on('click', '.rf-tab', function() {
                var tab = $(this).data('tab');
                $('.rf-tab').removeClass('active');
                $(this).addClass('active');
                $('.rf-tab-panel').removeClass('active');
                $('#tab-' + tab).addClass('active');

                if (tab === 'management' && state.flags.length === 0) RF.loadFlags();
                if (tab === 'analytics' && !state.analyticsLoaded) RF.loadAnalytics();
            });

            // Close modals on background click
            $(document).on('click', '.rf-modal-bg', function(e) {
                if (e.target === this) $(this).removeClass('show');
            });
            $(document).on('click', '.rf-confirm-bg', function(e) {
                if (e.target === this) $(this).removeClass('show');
            });

            // Student search
            var searchTimer;
            $('#student-search').on('input', function() {
                clearTimeout(searchTimer);
                var q = $(this).val().trim();
                if (q.length < 2) { $('#student-search-results').removeClass('show').empty(); return; }
                searchTimer = setTimeout(function() { RF.searchStudents(q); }, 300);
            });
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.rf-search-wrap').length) {
                    $('#student-search-results').removeClass('show');
                }
            });

            // Class change in create modal
            $('#cf-class').on('change', function() { RF.loadStudentsForClass($(this).val(), '#cf-student'); });

            // Class change in Tab 5 create form
            $('#tc-class').on('change', function() { RF.loadStudentsForClass($(this).val(), '#tc-student'); });

            // Character counter for Tab 5 message
            $('#tc-message').on('input', function() {
                $('#tc-char-count').text($(this).val().length);
            });
        },

        /* ── Refresh all ── */
        refresh: function() {
            RF.loadOverview();
            if ($('#tab-management').hasClass('active')) RF.loadFlags();
            if (state.analyticsLoaded) { state.analyticsLoaded = false; RF.loadAnalytics(); }
            toast('Dashboard refreshed', 'info');
        },

        /* ── Load classes ── */
        loadClasses: function() {
            ajax('get_classes', null, 'GET').done(function(r) {
                if (r.status !== 'success') return;
                state.classes = r.classes || [];
                var sel = '<option value="">All Classes</option>';
                var cfSel = '<option value="">Select class</option>';
                for (var i = 0; i < state.classes.length; i++) {
                    var c = state.classes[i];
                    var v = esc(c.class_key) + '|' + esc(c.section);
                    sel += '<option value="' + v + '">' + esc(c.label) + '</option>';
                    cfSel += '<option value="' + v + '">' + esc(c.label) + '</option>';
                }
                $('#f-class').html(sel);
                $('#cf-class').html(cfSel);
                $('#tc-class').html(cfSel);
            });
        },

        /* ── Load Overview KPIs ── */
        loadOverview: function() {
            ajax('get_overview', null, 'GET').done(function(r) {
                if (r.status !== 'success') return;
                state.overview = r;

                // KPIs
                $('#kpi-total').text(r.total || 0);
                $('#kpi-active').text(r.active || 0);
                $('#kpi-resolved').text(r.resolved || 0);
                $('#kpi-high').text(r.high || 0);
                $('#kpi-week').text(r.thisWeek || 0);
                $('#rf-badge-total').text(r.total || 0);
                $('#rf-badge-active').text(r.active || 0);

                // Build student index for search
                RF.buildStudentIndex(r);

                // Render charts
                RF.renderSeverityChart(r.bySeverity);
                RF.renderTypeChart(r.byType);
                RF.renderClassChart(r.byClass);
                RF.renderRecentTimeline(r.recentFlags);
                RF.renderTopStudents(r.topStudents);
                RF.renderClassHeatmap(r.byClass);
            }).fail(function() {
                toast('Failed to load overview data', 'error');
                $('#kpi-total, #kpi-active, #kpi-resolved, #kpi-high, #kpi-week').text('?');
            });
        },

        /* ── Build student index from all flags ── */
        buildStudentIndex: function(overview) {
            // We build from topStudents + recentFlags + flags if available
            var seen = {};
            state.allStudents = [];
            var sources = [].concat(overview.topStudents || [], overview.recentFlags || [], state.flags || []);
            for (var i = 0; i < sources.length; i++) {
                var s = sources[i];
                var id = s.studentId;
                if (!id || seen[id]) continue;
                seen[id] = true;
                state.allStudents.push({
                    studentId: id,
                    studentName: s.studentName || id,
                    classLabel: s.classLabel || '',
                    rollNo: s.rollNo || ''
                });
            }
        },

        /* ── Severity Donut Chart ── */
        renderSeverityChart: function(data) {
            if (!data) return;
            destroyChart('severity');
            var c = getChartColors();
            var ctx = document.getElementById('chart-severity');
            if (!ctx) return;
            state.charts.severity = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['High', 'Medium', 'Low'],
                    datasets: [{
                        data: [data.High || 0, data.Medium || 0, data.Low || 0],
                        backgroundColor: [c.red, c.amber, c.blue],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: axisColor(), padding: 16,
                                font: { family: "'Plus Jakarta Sans',sans-serif", size: 12, weight: 600 },
                                usePointStyle: true, pointStyleWidth: 10
                            }
                        }
                    }
                }
            });
        },

        /* ── Type Bar Chart ── */
        renderTypeChart: function(data) {
            if (!data) return;
            destroyChart('type');
            var c = getChartColors();
            var ctx = document.getElementById('chart-type');
            if (!ctx) return;
            state.charts.type = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Homework', 'Behavior', 'Performance'],
                    datasets: [{
                        label: 'Flags',
                        data: [data.Homework || 0, data.Behavior || 0, data.Performance || 0],
                        backgroundColor: [c.blue, c.amber, c.rose],
                        borderRadius: 6, borderSkipped: false,
                        maxBarThickness: 50
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: axisColor(), stepSize: 1, font: { size: 11 } }, grid: { color: gridColor() } },
                        x: { ticks: { color: axisColor(), font: { size: 11 } }, grid: { display: false } }
                    }
                }
            });
        },

        /* ── Class Comparison Chart ── */
        renderClassChart: function(data) {
            if (!data) return;
            destroyChart('class');
            var c = getChartColors();
            var labels = Object.keys(data);
            var totals = [], actives = [], highs = [];
            for (var i = 0; i < labels.length; i++) {
                var d = data[labels[i]];
                totals.push(d.total || 0);
                actives.push(d.active || 0);
                highs.push(d.high || 0);
            }
            var ctx = document.getElementById('chart-class');
            if (!ctx) return;
            state.charts['class'] = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Total', data: totals, backgroundColor: c.teal, borderRadius: 4, maxBarThickness: 30 },
                        { label: 'Active', data: actives, backgroundColor: c.amber, borderRadius: 4, maxBarThickness: 30 },
                        { label: 'High Severity', data: highs, backgroundColor: c.red, borderRadius: 4, maxBarThickness: 30 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { color: axisColor(), font: { size: 11 }, usePointStyle: true, pointStyleWidth: 10 }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: axisColor(), stepSize: 1, font: { size: 11 } }, grid: { color: gridColor() } },
                        x: { ticks: { color: axisColor(), font: { size: 10 } }, grid: { display: false } }
                    }
                }
            });
        },

        /* ── Recent Flags Timeline ── */
        renderRecentTimeline: function(flags) {
            var el = $('#recent-timeline');
            if (!flags || flags.length === 0) {
                el.html('<div class="rf-empty"><i class="fa fa-flag-o"></i><p>No flags recorded yet.</p></div>');
                return;
            }
            var html = '<ul class="rf-timeline">';
            for (var i = 0; i < flags.length; i++) {
                var f = flags[i];
                var sevClass = (f.severity || 'Low').toLowerCase();
                html += '<li class="rf-timeline-item">'
                    + '<div class="rf-timeline-dot ' + esc(sevClass) + '"></div>'
                    + '<div class="rf-timeline-content">'
                    + '<div class="rf-timeline-head">'
                    + '<span class="rf-timeline-name">' + esc(f.studentName) + '</span>'
                    + '<span class="rf-badge ' + esc(sevClass) + '">' + esc(f.severity) + '</span>'
                    + RF.typeBadgeHtml(f.type)
                    + '<span class="rf-timeline-class">' + esc(f.classLabel) + '</span>'
                    + '</div>'
                    + '<div class="rf-timeline-msg">' + esc(f.message) + '</div>'
                    + '<div class="rf-timeline-foot">'
                    + '<span class="rf-timeline-time"><i class="fa fa-clock-o"></i> ' + timeAgo(f.createdAt) + '</span>'
                    + '<span class="rf-timeline-time"><i class="fa fa-user"></i> ' + esc(f.teacherName) + '</span>';

                if (f.status === 'Active') {
                    html += '<div class="rf-timeline-actions">'
                        + '<button class="rf-btn success sm" onclick="RF.resolveFlag(\'' + esc(f.classKey) + '\',\'' + esc(f.sectionKey) + '\',\'' + esc(f.studentId) + '\',\'' + esc(f.flagId) + '\')"><i class="fa fa-check"></i></button>'
                        + '</div>';
                } else {
                    html += '<span class="rf-status-badge resolved-s">Resolved</span>';
                }

                html += '</div></div></li>';
            }
            html += '</ul>';
            el.html(html);
        },

        /* ── Top Flagged Students ── */
        renderTopStudents: function(students) {
            var el = $('#top-students');
            if (!students || students.length === 0) {
                el.html('<div class="rf-empty" style="padding:40px"><i class="fa fa-users"></i><p>No flagged students yet.</p></div>');
                return;
            }
            var html = '<table class="rf-top-table"><thead><tr><th>#</th><th>Student</th><th>Class</th><th>Flags</th><th>High</th></tr></thead><tbody>';
            for (var i = 0; i < students.length; i++) {
                var s = students[i];
                html += '<tr>'
                    + '<td><span class="rf-top-rank">' + (i + 1) + '</span></td>'
                    + '<td><span class="rf-student-name" onclick="RF.drillDown(\'' + esc(s.studentId) + '\')">' + esc(s.studentName) + '</span></td>'
                    + '<td style="font-size:12px;color:var(--rf-t3)">' + esc(s.classLabel) + '</td>'
                    + '<td><strong>' + (s.count || 0) + '</strong></td>'
                    + '<td>' + (s.highCount > 0 ? '<span class="rf-badge high">' + s.highCount + '</span>' : '<span style="color:var(--rf-t4)">0</span>') + '</td>'
                    + '</tr>';
            }
            html += '</tbody></table>';
            el.html(html);
        },

        /* ── Type badge helper ── */
        typeBadgeHtml: function(type) {
            var cls = (type || '').toLowerCase();
            var icon = cls === 'homework' ? 'fa-book' : cls === 'behavior' ? 'fa-exclamation-triangle' : 'fa-line-chart';
            return '<span class="rf-type-badge ' + esc(cls) + '"><i class="fa ' + icon + '"></i> ' + esc(type) + '</span>';
        },

        /* ════════════════════════════════════════════════════════
           TAB 2: FLAG MANAGEMENT
           ════════════════════════════════════════════════════════ */

        loadFlags: function() {
            $('#flags-table-area').html('<div class="rf-loading-overlay"><i class="fa fa-spinner"></i> Loading flags...</div>');

            var classVal = $('#f-class').val() || '';
            var parts = classVal.split('|');
            var data = {
                class_key: parts[0] || '',
                section: parts[1] || '',
                type: $('#f-type').val() || '',
                severity: $('#f-severity').val() || '',
                status: $('#f-status').val() || '',
                student: $('#f-student').val() || '',
                date_from: $('#f-date-from').val() || '',
                date_to: $('#f-date-to').val() || ''
            };

            ajax('get_flags', data).done(function(r) {
                if (r.status !== 'success') { toast('Failed to load flags', 'error'); return; }
                state.flags = r.flags || [];
                state.selectedFlags = {};
                RF.buildStudentIndex(state.overview || {});
                RF.renderFlagsTable(state.flags);
            }).fail(function() {
                $('#flags-table-area').html('<div class="rf-empty"><i class="fa fa-warning"></i><p>Failed to load flags. Please try again.</p></div>');
            });
        },

        renderFlagsTable: function(flags) {
            if (!flags || flags.length === 0) {
                $('#flags-table-area').html('<div class="rf-empty"><i class="fa fa-flag-o"></i><p>No flags match your filters.</p></div>');
                $('#rf-bulk-bar').removeClass('show');
                return;
            }

            $('#rf-bulk-bar').addClass('show');
            $('#rf-select-all').prop('checked', false);
            $('#rf-bulk-count').text('0');

            var html = '<table class="rf-table" id="rf-flags-dt"><thead><tr>'
                + '<th style="width:30px"><input type="checkbox" class="rf-check" id="rf-select-all-head" onchange="RF.toggleSelectAll()"></th>'
                + '<th>Student</th><th>Class / Section</th><th>Type</th><th>Severity</th>'
                + '<th>Teacher</th><th>Date</th><th>Status</th><th>Actions</th>'
                + '</tr></thead><tbody>';

            for (var i = 0; i < flags.length; i++) {
                var f = flags[i];
                var sevClass = (f.severity || 'Low').toLowerCase();
                var statusClass = f.status === 'Active' ? 'active-s' : 'resolved-s';
                var rowId = 'row-' + i;
                var fKey = f.classKey + '|' + f.sectionKey + '|' + f.studentId + '|' + f.flagId;

                html += '<tr data-idx="' + i + '" id="' + rowId + '" class="sev-' + sevClass + '">'
                    + '<td><input type="checkbox" class="rf-check rf-flag-check" data-key="' + esc(fKey) + '" onchange="RF.updateBulkCount()"></td>'
                    + '<td><span class="rf-student-name" onclick="RF.drillDown(\'' + esc(f.studentId) + '\')">' + esc(f.studentName) + '</span>'
                    + '<div style="font-size:11px;color:var(--rf-t4)">' + esc(f.rollNo ? 'Roll: ' + f.rollNo : '') + '</div></td>'
                    + '<td style="font-size:12px">' + esc(f.classLabel) + '</td>'
                    + '<td>' + RF.typeBadgeHtml(f.type) + '</td>'
                    + '<td><span class="rf-badge ' + esc(sevClass) + '">' + esc(f.severity) + '</span></td>'
                    + '<td style="font-size:12px">' + esc(f.teacherName) + '</td>'
                    + '<td style="font-size:12px;white-space:nowrap">' + fmtDate(f.createdAt) + '</td>'
                    + '<td><span class="rf-status-badge ' + esc(statusClass) + '">' + esc(f.status) + '</span></td>'
                    + '<td style="white-space:nowrap">'
                    + '<button class="rf-btn outline sm" title="Details" onclick="RF.toggleExpand(' + i + ')"><i class="fa fa-eye"></i></button> ';

                if (f.status === 'Active') {
                    html += '<button class="rf-btn success sm" title="Resolve" onclick="RF.resolveFlag(\'' + esc(f.classKey) + '\',\'' + esc(f.sectionKey) + '\',\'' + esc(f.studentId) + '\',\'' + esc(f.flagId) + '\')"><i class="fa fa-check"></i></button> ';
                }
                html += '<button class="rf-btn danger sm" title="Delete" onclick="RF.deleteFlag(\'' + esc(f.classKey) + '\',\'' + esc(f.sectionKey) + '\',\'' + esc(f.studentId) + '\',\'' + esc(f.flagId) + '\')"><i class="fa fa-trash"></i></button>';
                html += '</td></tr>';

                // Expandable detail row
                html += '<tr class="rf-expand-row" id="expand-' + i + '"><td colspan="9">'
                    + '<div class="rf-expand-detail">'
                    + '<div class="rf-detail-item"><label>Student ID</label><span>' + esc(f.studentId) + '</span></div>'
                    + '<div class="rf-detail-item"><label>Father\'s Name</label><span>' + esc(f.fatherName || '-') + '</span></div>'
                    + '<div class="rf-detail-item"><label>Subject</label><span>' + esc(f.subject || '-') + '</span></div>'
                    + '<div class="rf-detail-item"><label>Teacher</label><span>' + esc(f.teacherName) + ' (' + esc(f.teacherId) + ')</span></div>'
                    + '<div class="rf-detail-item"><label>Created</label><span>' + fmtDate(f.createdAt) + '</span></div>'
                    + '<div class="rf-detail-item"><label>Resolved</label><span>' + (f.resolvedAt ? fmtDate(f.resolvedAt) + ' by ' + esc(f.resolvedBy) : '-') + '</span></div>'
                    + '<div class="rf-detail-msg"><label style="margin-bottom:6px">Message</label>' + esc(f.message) + '</div>'
                    + '</div></td></tr>';
            }

            html += '</tbody></table>';
            $('#flags-table-area').html(html);

            // Init DataTable
            if ($.fn.DataTable) {
                $('#rf-flags-dt').DataTable({
                    paging: true,
                    pageLength: 20,
                    searching: false, // We have custom filters
                    ordering: true,
                    info: true,
                    autoWidth: false,
                    order: [[6, 'desc']],
                    columnDefs: [
                        { orderable: false, targets: [0, 8] }
                    ],
                    language: { emptyTable: 'No flags found' },
                    layout: {
                        topStart: { buttons: ['csv', 'excel', 'print'] }
                    }
                });
            }
        },

        toggleExpand: function(idx) {
            var row = $('#expand-' + idx);
            row.toggleClass('open');
            $('#row-' + idx).toggleClass('expanded-row');
        },

        toggleSelectAll: function() {
            var checked = $('#rf-select-all-head').is(':checked') || $('#rf-select-all').is(':checked');
            $('.rf-flag-check').prop('checked', checked);
            RF.updateBulkCount();
        },

        updateBulkCount: function() {
            var count = $('.rf-flag-check:checked').length;
            $('#rf-bulk-count').text(count);
        },

        /* ── Resolve single flag ── */
        resolveFlag: function(classKey, sectionKey, studentId, flagId) {
            showConfirm('Resolve Flag', 'Mark this flag as resolved?',
                '<i class="fa fa-check-circle" style="color:var(--rf-green)"></i>', 'success',
                function() {
                    ajax('resolve_flag', {
                        class_key: classKey, section_key: sectionKey,
                        student_id: studentId, flag_id: flagId
                    }).done(function(r) {
                        if (r.status === 'success') {
                            toast('Flag resolved successfully');
                            RF.refresh();
                        } else {
                            toast(r.message || 'Failed to resolve', 'error');
                        }
                    }).fail(function() { toast('Network error', 'error'); });
                }
            );
        },

        /* ── Delete flag ── */
        deleteFlag: function(classKey, sectionKey, studentId, flagId) {
            showConfirm('Delete Flag', 'This action cannot be undone. Are you sure?',
                '<i class="fa fa-trash" style="color:var(--rf-red)"></i>', 'danger',
                function() {
                    ajax('delete_flag', {
                        class_key: classKey, section_key: sectionKey,
                        student_id: studentId, flag_id: flagId
                    }).done(function(r) {
                        if (r.status === 'success') {
                            toast('Flag deleted');
                            RF.refresh();
                        } else {
                            toast(r.message || 'Failed to delete', 'error');
                        }
                    }).fail(function() { toast('Network error', 'error'); });
                }
            );
        },

        /* ── Bulk resolve ── */
        bulkResolve: function() {
            var checked = [];
            $('.rf-flag-check:checked').each(function() {
                var parts = $(this).data('key').split('|');
                if (parts.length === 4) {
                    checked.push({ class_key: parts[0], section_key: parts[1], student_id: parts[2], flag_id: parts[3] });
                }
            });

            if (checked.length === 0) { toast('No flags selected', 'info'); return; }

            showConfirm('Bulk Resolve', 'Resolve ' + checked.length + ' flag(s)?',
                '<i class="fa fa-check-circle" style="color:var(--rf-green)"></i>', 'success',
                function() {
                    ajax('bulk_resolve', { flags: checked }).done(function(r) {
                        if (r.status === 'success') {
                            toast(r.message || 'Flags resolved');
                            RF.refresh();
                        } else {
                            toast(r.message || 'Failed', 'error');
                        }
                    }).fail(function() { toast('Network error', 'error'); });
                }
            );
        },

        clearFilters: function() {
            $('#f-class, #f-type, #f-severity, #f-status').val('');
            $('#f-student, #f-date-from, #f-date-to').val('');
            RF.loadFlags();
        },

        /* ════════════════════════════════════════════════════════
           TAB 3: STUDENT DRILL-DOWN
           ════════════════════════════════════════════════════════ */

        searchStudents: function(q) {
            var results = [];
            var ql = q.toLowerCase();
            for (var i = 0; i < state.allStudents.length; i++) {
                var s = state.allStudents[i];
                if ((s.studentName || '').toLowerCase().indexOf(ql) !== -1
                    || (s.studentId || '').toLowerCase().indexOf(ql) !== -1
                    || (s.rollNo || '').toLowerCase().indexOf(ql) !== -1) {
                    results.push(s);
                }
                if (results.length >= 10) break;
            }

            var el = $('#student-search-results');
            if (results.length === 0) {
                el.html('<div style="padding:12px;text-align:center;color:var(--rf-t3);font-size:12px">No students found</div>').addClass('show');
                return;
            }

            var html = '';
            for (var j = 0; j < results.length; j++) {
                var r = results[j];
                html += '<div class="rf-search-item" onclick="RF.drillDown(\'' + esc(r.studentId) + '\')">'
                    + '<div><div class="rf-search-item-name">' + esc(r.studentName) + '</div>'
                    + '<div class="rf-search-item-sub">' + esc(r.classLabel) + (r.rollNo ? ' | Roll: ' + esc(r.rollNo) : '') + '</div></div>'
                    + '</div>';
            }
            el.html(html).addClass('show');
        },

        drillDown: function(studentId) {
            // Switch to student tab
            $('.rf-tab').removeClass('active');
            $('.rf-tab[data-tab="student"]').addClass('active');
            $('.rf-tab-panel').removeClass('active');
            $('#tab-student').addClass('active');

            $('#student-search-results').removeClass('show');
            $('#student-empty').hide();
            $('#student-profile-area').show();
            state.currentStudentId = studentId;

            // Load student flags
            ajax('get_student_flags/' + encodeURIComponent(studentId), null, 'GET').done(function(r) {
                if (r.status !== 'success' || !r.student) {
                    $('#student-card').html('<div class="rf-empty"><i class="fa fa-user-times"></i><p>Student not found or has no flags.</p></div>');
                    return;
                }

                var s = r.student;
                var a = r.analysis;

                // Student card
                var initials = (s.studentName || '?').charAt(0).toUpperCase();
                var cardHtml = '<div class="rf-student-card">'
                    + '<div class="rf-student-avatar">' + esc(initials) + '</div>'
                    + '<div class="rf-student-info">'
                    + '<h3>' + esc(s.studentName) + '</h3>'
                    + '<div class="rf-student-meta">'
                    + '<span><i class="fa fa-id-badge"></i> ' + esc(s.studentId) + '</span>'
                    + '<span><i class="fa fa-graduation-cap"></i> ' + esc(s.classLabel) + '</span>'
                    + (s.rollNo ? '<span><i class="fa fa-sort-numeric-asc"></i> Roll: ' + esc(s.rollNo) + '</span>' : '')
                    + (s.fatherName ? '<span><i class="fa fa-user"></i> F: ' + esc(s.fatherName) + '</span>' : '')
                    + '</div>'
                    + '<div class="rf-student-stats">'
                    + '<div class="rf-student-stat"><div class="rf-student-stat-val">' + r.total + '</div><div class="rf-student-stat-lbl">Total</div></div>'
                    + '<div class="rf-student-stat"><div class="rf-student-stat-val" style="color:var(--rf-red)">' + a.active + '</div><div class="rf-student-stat-lbl">Active</div></div>'
                    + '<div class="rf-student-stat"><div class="rf-student-stat-val" style="color:var(--rf-green)">' + a.resolved + '</div><div class="rf-student-stat-lbl">Resolved</div></div>'
                    + '</div></div></div>';
                $('#student-card').html(cardHtml);
                $('#student-flag-count').text(r.total + ' flags');

                // Charts
                RF.renderStudentTypeChart(a.byType);
                RF.renderStudentSeverityChart(a.bySeverity);

                // Flag list
                RF.renderStudentFlagsList(r.flags);

            }).fail(function() {
                toast('Failed to load student data', 'error');
            });
        },

        renderStudentTypeChart: function(data) {
            if (!data) return;
            destroyChart('studentTypes');
            var c = getChartColors();
            var ctx = document.getElementById('chart-student-types');
            if (!ctx) return;
            state.charts.studentTypes = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Homework', 'Behavior', 'Performance'],
                    datasets: [{ data: [data.Homework || 0, data.Behavior || 0, data.Performance || 0], backgroundColor: [c.blue, c.amber, c.rose], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom', labels: { color: axisColor(), font: { size: 11 }, usePointStyle: true } } } }
            });
        },

        renderStudentSeverityChart: function(data) {
            if (!data) return;
            destroyChart('studentSeverity');
            var c = getChartColors();
            var ctx = document.getElementById('chart-student-severity');
            if (!ctx) return;
            state.charts.studentSeverity = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Low', 'Medium', 'High'],
                    datasets: [{ label: 'Count', data: [data.Low || 0, data.Medium || 0, data.High || 0], backgroundColor: [c.blue, c.amber, c.red], borderRadius: 6, maxBarThickness: 50 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: axisColor(), stepSize: 1, font: { size: 11 } }, grid: { color: gridColor() } },
                        x: { ticks: { color: axisColor(), font: { size: 11 } }, grid: { display: false } }
                    }
                }
            });
        },

        renderStudentFlagsList: function(flags) {
            var el = $('#student-flags-list');
            if (!flags || flags.length === 0) {
                el.html('<div class="rf-empty"><i class="fa fa-flag-o"></i><p>No flags for this student.</p></div>');
                return;
            }
            var html = '<ul class="rf-timeline">';
            for (var i = 0; i < flags.length; i++) {
                var f = flags[i];
                var sevClass = (f.severity || 'Low').toLowerCase();
                html += '<li class="rf-timeline-item">'
                    + '<div class="rf-timeline-dot ' + esc(sevClass) + '"></div>'
                    + '<div class="rf-timeline-content">'
                    + '<div class="rf-timeline-head">'
                    + '<span class="rf-badge ' + esc(sevClass) + '">' + esc(f.severity) + '</span>'
                    + RF.typeBadgeHtml(f.type)
                    + (f.subject ? '<span style="font-size:12px;color:var(--rf-t3);font-family:var(--font-m)">' + esc(f.subject) + '</span>' : '')
                    + '<span class="rf-status-badge ' + (f.status === 'Active' ? 'active-s' : 'resolved-s') + '">' + esc(f.status) + '</span>'
                    + '</div>'
                    + '<div class="rf-timeline-msg" style="white-space:normal">' + esc(f.message) + '</div>'
                    + '<div class="rf-timeline-foot">'
                    + '<span class="rf-timeline-time"><i class="fa fa-clock-o"></i> ' + fmtDate(f.createdAt) + '</span>'
                    + '<span class="rf-timeline-time"><i class="fa fa-user"></i> ' + esc(f.teacherName) + '</span>';

                if (f.status === 'Active') {
                    html += '<div class="rf-timeline-actions">'
                        + '<button class="rf-btn success sm" onclick="RF.resolveFlag(\'' + esc(f.classKey) + '\',\'' + esc(f.sectionKey) + '\',\'' + esc(f.studentId) + '\',\'' + esc(f.flagId) + '\')"><i class="fa fa-check"></i> Resolve</button>'
                        + '</div>';
                }

                html += '</div></div></li>';
            }
            html += '</ul>';
            el.html(html);
        },

        /* ════════════════════════════════════════════════════════
           TAB 4: ANALYTICS
           ════════════════════════════════════════════════════════ */

        loadAnalytics: function() {
            $('#analytics-area').show();
            $('#analytics-content').hide();

            ajax('get_trends', null, 'GET').done(function(r) {
                if (r.status !== 'success') { toast('Failed to load analytics', 'error'); return; }
                state.analyticsLoaded = true;
                $('#analytics-area').hide();
                $('#analytics-content').show();

                // Month comparison
                var changeClass = r.monthChange > 0 ? 'up' : r.monthChange < 0 ? 'down' : 'flat';
                var changeIcon = r.monthChange > 0 ? 'fa-arrow-up' : r.monthChange < 0 ? 'fa-arrow-down' : 'fa-minus';
                $('#month-comparison').html(
                    '<div class="rf-compare-card">'
                    + '<div class="rf-compare-val">' + (r.thisMonth || 0) + '</div>'
                    + '<div class="rf-compare-lbl">This Month</div>'
                    + '<span class="rf-compare-change ' + changeClass + '"><i class="fa ' + changeIcon + '"></i> ' + Math.abs(r.monthChange || 0) + '%</span>'
                    + '</div>'
                    + '<div class="rf-compare-card">'
                    + '<div class="rf-compare-val">' + (r.lastMonth || 0) + '</div>'
                    + '<div class="rf-compare-lbl">Last Month</div>'
                    + '<div style="margin-top:8px;font-size:12px;color:var(--rf-t3)">Avg resolution: <strong style="color:var(--rf-t1)">' + (r.avgResolutionHrs || 0) + 'h</strong></div>'
                    + '</div>'
                );

                // Trend chart
                RF.renderTrendChart(r.weeklyTrend);

                // Teacher chart
                RF.renderTeacherChart(r.teacherActivity);

                // Subject chart
                RF.renderSubjectChart(r.subjectBreakdown);

                // Resolution stats
                RF.renderResolutionStats(r);

            }).fail(function() {
                $('#analytics-area').html('<div class="rf-empty"><i class="fa fa-warning"></i><p>Failed to load analytics.</p></div>');
            });
        },

        renderTrendChart: function(data) {
            if (!data) return;
            destroyChart('trend');
            var c = getChartColors();
            var labels = Object.keys(data);
            var created = [], resolved = [];
            for (var i = 0; i < labels.length; i++) {
                created.push(data[labels[i]].created || 0);
                resolved.push(data[labels[i]].resolved || 0);
            }
            var ctx = document.getElementById('chart-trend');
            if (!ctx) return;
            state.charts.trend = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Created', data: created, borderColor: c.red, backgroundColor: 'rgba(220,38,38,.08)', fill: true, tension: .4, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 },
                        { label: 'Resolved', data: resolved, borderColor: c.green, backgroundColor: 'rgba(22,163,74,.08)', fill: true, tension: .4, pointRadius: 4, pointHoverRadius: 6, borderWidth: 2 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { color: axisColor(), font: { size: 11 }, usePointStyle: true } } },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: axisColor(), stepSize: 1, font: { size: 11 } }, grid: { color: gridColor() } },
                        x: { ticks: { color: axisColor(), font: { size: 10 } }, grid: { display: false } }
                    }
                }
            });
        },

        renderTeacherChart: function(data) {
            if (!data || data.length === 0) return;
            destroyChart('teachers');
            var c = getChartColors();
            var labels = [], counts = [], highs = [];
            for (var i = 0; i < Math.min(data.length, 10); i++) {
                labels.push(data[i].name || 'Unknown');
                counts.push(data[i].count || 0);
                highs.push(data[i].high || 0);
            }
            var ctx = document.getElementById('chart-teachers');
            if (!ctx) return;
            state.charts.teachers = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        { label: 'Total Flags', data: counts, backgroundColor: c.teal, borderRadius: 4, maxBarThickness: 30 },
                        { label: 'High Severity', data: highs, backgroundColor: c.red, borderRadius: 4, maxBarThickness: 30 }
                    ]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'top', labels: { color: axisColor(), font: { size: 11 }, usePointStyle: true } } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: axisColor(), stepSize: 1, font: { size: 10 } }, grid: { color: gridColor() } },
                        y: { ticks: { color: axisColor(), font: { size: 10 } }, grid: { display: false } }
                    }
                }
            });
        },

        renderSubjectChart: function(data) {
            if (!data) return;
            destroyChart('subjects');
            var c = getChartColors();
            var labels = Object.keys(data);
            var values = [];
            var colors = [c.teal, c.blue, c.amber, c.rose, c.purple, c.green, c.red, c.gray];
            for (var i = 0; i < labels.length; i++) values.push(data[labels[i]]);

            var ctx = document.getElementById('chart-subjects');
            if (!ctx) return;
            state.charts.subjects = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors.slice(0, labels.length),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '55%',
                    plugins: { legend: { position: 'right', labels: { color: axisColor(), font: { size: 11 }, usePointStyle: true, padding: 12 } } }
                }
            });
        },

        renderResolutionStats: function(data) {
            var el = $('#resolution-stats');
            var times = data.resolutionTimes || [];
            if (times.length === 0) {
                el.html('<div class="rf-empty" style="padding:30px"><i class="fa fa-clock-o"></i><p>No resolved flags to analyze yet.</p></div>');
                return;
            }
            times.sort(function(a, b) { return a - b; });
            var min = times[0];
            var max = times[times.length - 1];
            var med = times[Math.floor(times.length / 2)];
            var avg = data.avgResolutionHrs || 0;

            // Bucket into ranges
            var under24 = 0, under72 = 0, over72 = 0;
            for (var i = 0; i < times.length; i++) {
                if (times[i] <= 24) under24++;
                else if (times[i] <= 72) under72++;
                else over72++;
            }

            el.html(
                '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px">'
                + '<div class="rf-student-stat"><div class="rf-student-stat-val">' + avg + 'h</div><div class="rf-student-stat-lbl">Average</div></div>'
                + '<div class="rf-student-stat"><div class="rf-student-stat-val">' + med + 'h</div><div class="rf-student-stat-lbl">Median</div></div>'
                + '<div class="rf-student-stat"><div class="rf-student-stat-val" style="color:var(--rf-green)">' + min + 'h</div><div class="rf-student-stat-lbl">Fastest</div></div>'
                + '<div class="rf-student-stat"><div class="rf-student-stat-val" style="color:var(--rf-red)">' + max + 'h</div><div class="rf-student-stat-lbl">Slowest</div></div>'
                + '</div>'
                + '<div style="display:flex;gap:12px;flex-wrap:wrap">'
                + '<div style="flex:1;min-width:120px;padding:12px;background:var(--rf-green-dim);border-radius:var(--rf-r-sm);text-align:center">'
                + '<div style="font-size:20px;font-weight:800;color:var(--rf-green);font-family:var(--font-d)">' + under24 + '</div>'
                + '<div style="font-size:11px;color:var(--rf-t3);margin-top:2px">Under 24h</div></div>'
                + '<div style="flex:1;min-width:120px;padding:12px;background:var(--rf-amber-dim);border-radius:var(--rf-r-sm);text-align:center">'
                + '<div style="font-size:20px;font-weight:800;color:var(--rf-amber);font-family:var(--font-d)">' + under72 + '</div>'
                + '<div style="font-size:11px;color:var(--rf-t3);margin-top:2px">24h - 72h</div></div>'
                + '<div style="flex:1;min-width:120px;padding:12px;background:var(--rf-red-dim);border-radius:var(--rf-r-sm);text-align:center">'
                + '<div style="font-size:20px;font-weight:800;color:var(--rf-red);font-family:var(--font-d)">' + over72 + '</div>'
                + '<div style="font-size:11px;color:var(--rf-t3);margin-top:2px">Over 72h</div></div>'
                + '</div>'
            );
        },

        /* ════════════════════════════════════════════════════════
           CREATE FLAG MODAL
           ════════════════════════════════════════════════════════ */

        openCreateModal: function() {
            $('#rf-create-form')[0].reset();
            $('#cf-student').html('<option value="">Select class first</option>');
            $('#rf-create-modal').addClass('show');
        },

        closeCreateModal: function() {
            $('#rf-create-modal').removeClass('show');
        },

        loadStudentsForClass: function(val, targetSel) {
            targetSel = targetSel || '#cf-student';
            if (!val) {
                $(targetSel).html('<option value="">Select class first</option>');
                return;
            }
            $(targetSel).html('<option value="">Loading...</option>');

            var parts = val.split('|');
            var classKey = parts[0];
            var section = parts[1];

            ajax('get_students_for_class', {
                class_key: classKey,
                section_key: 'Section ' + section
            }).done(function(r) {
                if (r.status !== 'success') {
                    $(targetSel).html('<option value="">Failed to load</option>');
                    return;
                }
                var students = r.students || [];
                var opts = '<option value="">Select student</option>';
                for (var i = 0; i < students.length; i++) {
                    var s = students[i];
                    opts += '<option value="' + esc(s.studentId) + '">'
                        + esc(s.name)
                        + (s.rollNo ? ' (Roll: ' + esc(s.rollNo) + ')' : '')
                        + '</option>';
                }
                if (students.length === 0) {
                    opts = '<option value="">No students found</option>';
                }
                $(targetSel).html(opts);
            }).fail(function() {
                $(targetSel).html('<option value="">Error loading students</option>');
            });
        },

        /* ── Class Heatmap ── */
        renderClassHeatmap: function(data) {
            var el = $('#class-heatmap');
            if (!data || Object.keys(data).length === 0) {
                el.html('<div class="rf-empty"><i class="fa fa-th-large"></i><p>No class data available.</p></div>');
                return;
            }
            // Find max for heat scaling
            var maxVal = 0;
            var labels = Object.keys(data);
            for (var i = 0; i < labels.length; i++) {
                var total = data[labels[i]].total || 0;
                if (total > maxVal) maxVal = total;
            }

            var html = '';
            for (var j = 0; j < labels.length; j++) {
                var d = data[labels[j]];
                var t = d.total || 0;
                var heatLevel = maxVal === 0 ? 0 : Math.min(4, Math.ceil((t / maxVal) * 4));
                html += '<div class="rf-heat-cell heat-' + heatLevel + '">'
                    + '<div class="rf-heat-label">' + esc(labels[j]) + '</div>'
                    + '<div class="rf-heat-val">' + t + '</div>'
                    + '<div class="rf-heat-sub">' + (d.active || 0) + ' active / ' + (d.high || 0) + ' high</div>'
                    + '</div>';
            }
            el.html(html);
        },

        submitCreateFlag: function(e) {
            e.preventDefault();
            var classVal = $('#cf-class').val();
            if (!classVal) { toast('Please select a class', 'error'); return false; }

            var parts = classVal.split('|');
            var btn = $('#cf-submit-btn');
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');

            ajax('create_flag', {
                class_key: parts[0],
                section_key: 'Section ' + parts[1],
                student_id: $('#cf-student').val(),
                type: $('#cf-type').val(),
                severity: $('#cf-severity').val(),
                message: $('#cf-message').val(),
                subject: $('#cf-subject').val()
            }).done(function(r) {
                if (r.status === 'success') {
                    toast('Flag created successfully');
                    RF.closeCreateModal();
                    RF.refresh();
                } else {
                    toast(r.message || 'Failed to create flag', 'error');
                }
            }).fail(function(xhr) {
                var msg = 'Network error';
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
                toast(msg, 'error');
            }).always(function() {
                btn.prop('disabled', false).html('<i class="fa fa-plus"></i> Create Flag');
            });

            return false;
        },

        /* ════════════════════════════════════════════════════════
           TAB 5: CREATE FLAG (IN-TAB FORM)
           ════════════════════════════════════════════════════════ */

        submitTabCreateFlag: function(e) {
            e.preventDefault();
            var classVal = $('#tc-class').val();
            if (!classVal) { toast('Please select a class', 'error'); return false; }

            var parts = classVal.split('|');
            var btn = $('#tc-submit-btn');
            btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');

            ajax('create_flag', {
                class_key: parts[0],
                section_key: 'Section ' + parts[1],
                student_id: $('#tc-student').val(),
                type: $('#tc-type').val(),
                severity: $('#tc-severity').val(),
                message: $('#tc-message').val(),
                subject: $('#tc-subject').val()
            }).done(function(r) {
                if (r.status === 'success') {
                    toast('Flag created successfully');
                    $('#rf-tab-create-form')[0].reset();
                    $('#tc-student').html('<option value="">Select class first</option>');
                    $('#tc-char-count').text('0');
                    RF.loadOverview(); // refresh stats
                } else {
                    toast(r.message || 'Failed to create flag', 'error');
                }
            }).fail(function(xhr) {
                var msg = 'Network error';
                try { msg = JSON.parse(xhr.responseText).message || msg; } catch(ex) {}
                toast(msg, 'error');
            }).always(function() {
                btn.prop('disabled', false).html('<i class="fa fa-plus"></i> Create Flag');
            });

            return false;
        },

        /* ── Confirm dialog handler ── */
        closeConfirm: function() {
            $('#rf-confirm').removeClass('show');
            confirmCallback = null;
        }
    };

    // Confirm button click
    $(document).on('click', '#rf-confirm-btn', function() {
        RF.closeConfirm();
        if (typeof confirmCallback === 'function') confirmCallback();
    });

    // Escape key closes modals
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            RF.closeCreateModal();
            RF.closeConfirm();
        }
    });

    // Init on DOM ready
    $(document).ready(function() {
        RF.init();
    });

})();
</script>
