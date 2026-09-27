<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
    /* ── Access levels (defense-in-depth; controller remains source of truth) ──
     * In these marking views, marking/saving counts as EDIT.
     * MANAGE gates correction DECIDE/approve + lock (none inline here yet). */
    $att_can_edit   = function_exists('has_permission') ? has_permission('Attendance', 'edit')   : true;
    $att_can_manage = function_exists('has_permission') ? has_permission('Attendance', 'manage') : true;
?>

<!-- Attendance Design System (shared, cacheable) — see assets/css/attendance_design_system.css.
     staff.php inlines most classes but relies on the external file for .att-header*
     (header card + icon box). Linked BEFORE the inline <style> so page-specific inline
     rules still win on any overlap. Mirrors student.php. -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.1.0">

<style>
:root {
    --att-primary: var(--gold);
    --att-bg: var(--bg);
    --att-bg2: var(--bg2);
    --att-bg3: var(--bg3);
    --att-bg4: var(--bg4);
    --att-border: var(--border);
    --att-text: var(--t1);
    --att-text2: var(--t2);
    --att-text3: var(--t3);
    --att-shadow: var(--sh);
    --att-card: var(--card, var(--bg2));
    --att-ease: var(--ease);
    --att-r: var(--r-sm, 8px);
    --att-font: var(--font-b, 'Plus Jakarta Sans', sans-serif);
    --att-font-m: var(--font-m, 'JetBrains Mono', monospace);

    --att-p: #16a34a;
    --att-a: #dc2626;
    --att-l: #d97706;
    --att-t: #2563eb;
    --att-h: #7c3aed;
    --att-v: #9ca3af;
    --att-m: #0891b2;   /* half-day */
    --att-w: #4f46e5;   /* work-on-off (extra) */
    --att-o: #64748b;   /* weekly-off (rest) */

    --att-p-bg: rgba(22,163,74,.15);
    --att-a-bg: rgba(220,38,38,.15);
    --att-l-bg: rgba(217,119,6,.15);
    --att-t-bg: rgba(37,99,235,.15);
    --att-h-bg: rgba(124,58,237,.15);
    --att-v-bg: rgba(156,163,175,.12);
    --att-m-bg: rgba(8,145,178,.15);
    --att-w-bg: rgba(79,70,229,.15);
    --att-o-bg: rgba(100,116,139,.14);

    --att-dirty: rgba(234,179,8,.45);
    --att-sunday: rgba(239,68,68,.07);
    --att-holiday: rgba(139,92,246,.08);
}

/* ── Filter Bar ── */
.att-filter-bar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    padding: 18px 20px; border-radius: var(--att-r);
    background: var(--att-card); border: 1px solid var(--att-border);
    box-shadow: var(--att-shadow); margin-bottom: 16px;
}
.att-filter-bar .att-fg { display: flex; flex-direction: column; gap: 4px; }
.att-filter-bar label {
    font-family: var(--att-font); font-size: 11px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px; color: var(--att-text3);
}
.att-filter-bar select, .att-filter-bar button {
    font-family: var(--att-font); font-size: 13px; height: 38px;
    border-radius: 6px; padding: 0 12px; border: 1px solid var(--att-border);
    background: var(--att-bg); color: var(--att-text); transition: var(--att-ease);
}
.att-filter-bar select:focus { outline: none; border-color: var(--att-primary); box-shadow: 0 0 0 3px rgba(188,90,60,.15); }
.att-filter-bar select { min-width: 150px; cursor: pointer; }
.att-btn {
    font-family: var(--att-font); font-size: 13px; font-weight: 600;
    border: none; border-radius: 6px; padding: 0 20px; height: 38px;
    cursor: pointer; transition: var(--att-ease); display: inline-flex;
    align-items: center; gap: 6px;
}
.att-btn-primary { background: var(--att-primary); color: #fff; }
.att-btn-primary:hover { opacity: .88; }
.att-btn-primary:disabled { opacity: .45; cursor: not-allowed; }
.att-btn-sm { height: 32px; padding: 0 14px; font-size: 12px; }
.att-btn-outline {
    background: transparent; border: 1px solid var(--att-border);
    color: var(--att-text2);
}
.att-btn-outline:hover { background: var(--att-bg3); }

/* ── Toolbar ── */
/* ── Month lock (GAP-1) ── */
.att-lock { display: flex; align-items: center; gap: 10px; }
.att-lock-pill { display: inline-flex; align-items: center; gap: 6px; font-family: var(--att-font);
    font-size: 11.5px; font-weight: 700; letter-spacing: .02em; padding: 5px 11px; border-radius: 999px;
    border: 1px solid; white-space: nowrap; }
.att-lock-pill.open   { color: #16a34a; border-color: rgba(22,163,74,.35); background: rgba(22,163,74,.10); }
.att-lock-pill.locked { color: #dc2626; border-color: rgba(220,38,38,.35); background: rgba(220,38,38,.10); }
.att-lock-pill.unknown{ color: #d97706; border-color: rgba(217,119,6,.35); background: rgba(217,119,6,.10); }
.att-lock-months { font-family: var(--att-font); font-size: 11px; color: var(--att-text3); margin-top: 6px; }

/* The locked banner. Deliberately loud: it answers the question the operator is
   about to ask, which is why a register that looks editable is not. */
.att-lock-banner { display: none; align-items: flex-start; gap: 10px; margin-bottom: 16px;
    padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(220,38,38,.3);
    background: rgba(220,38,38,.07); color: var(--att-text); font-size: 13px; line-height: 1.55; }
.att-lock-banner.show { display: flex !important; }
.att-lock-banner i { color: #dc2626; font-size: 15px; margin-top: 2px; }

/* Reason dialog — plain DOM. This view runs before jQuery loads, on purpose, so
   it cannot reuse the HR panel's promptReason(); the behaviour is mirrored, not
   the implementation. */
.att-rsn-back { display: none; position: fixed; inset: 0; z-index: 5000; background: rgba(0,0,0,.45);
    align-items: center; justify-content: center; padding: 20px; }
.att-rsn-back.show { display: flex !important; }
.att-rsn { width: 100%; max-width: 520px; background: var(--att-card); border: 1px solid var(--att-border);
    border-radius: 14px; padding: 20px; box-shadow: 0 18px 50px rgba(0,0,0,.28); }
.att-rsn h3 { margin: 0 0 4px; font-size: 16px; font-weight: 700; color: var(--att-text); }
.att-rsn .warn { padding: 11px 13px; border-radius: 8px; background: rgba(217,119,6,.08);
    border-left: 4px solid #d97706; font-size: 12.5px; line-height: 1.6; margin: 12px 0; }
.att-rsn .warn b { display: block; margin-bottom: 4px; }
.att-rsn .warn ul { margin: 6px 0 0; padding-left: 18px; }
.att-rsn textarea { width: 100%; min-height: 84px; resize: vertical; padding: 10px 12px; font: inherit;
    font-size: 13px; color: var(--att-text); background: var(--att-bg2); border: 1px solid var(--att-border);
    border-radius: 8px; }
.att-rsn textarea:focus { outline: 2px solid var(--att-primary); outline-offset: 1px; }
.att-rsn-count { font-size: 11px; color: var(--att-text3); margin-top: 5px; }
.att-rsn-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.att-rsn-err { color: #dc2626; font-size: 12px; margin-top: 8px; display: none; }
.att-rsn-err.show { display: block; }
.att-toolbar {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    padding: 14px 20px; border-radius: var(--att-r);
    background: var(--att-card); border: 1px solid var(--att-border);
    box-shadow: var(--att-shadow); margin-bottom: 16px;
}
/* Only the EDIT toolbar (#attToolbar) is collapsed until a month loads; the
   filter card shares the .att-toolbar class and must stay visible. Scoping the
   hide to the id (not the class) prevents that collision — see student.php,
   which never hides .att-toolbar. JS toggles #attToolbar.visible after load. */
#attToolbar { display: none; }
/* Sticky action/save bar — stays in view while the register scrolls. */
#attToolbar.visible { display: flex; position: sticky; top: 0; z-index: 20; }
/* Unsaved-changes affordance: amber ring on the action bar until saved. */
#attToolbar.att-has-unsaved {
    border-color: var(--att-l);
    box-shadow: inset 0 0 0 1px var(--att-l), var(--att-shadow);
}
/* Keyboard focus — visible ring on the focused mark cell. */
.att-cell:focus-visible { outline: 2px solid var(--att-primary); outline-offset: 1px; }
/* Read-only (view-level) — grid shown but not interactive. */
.att-grid-wrap.att-readonly .att-cell { cursor: default; }
.att-grid-wrap.att-readonly .att-cell:hover { transform: none; box-shadow: none; }
.att-toolbar-section { display: flex; gap: 8px; align-items: center; }
.att-toolbar-divider { width: 1px; height: 28px; background: var(--att-border); margin: 0 6px; }
.att-day-picker {
    width: 56px; height: 32px; text-align: center; font-family: var(--att-font-m);
    font-size: 13px; border: 1px solid var(--att-border); border-radius: 6px;
    background: var(--att-bg); color: var(--att-text);
}
.att-day-picker:focus { outline: none; border-color: var(--att-primary); }

/* ── Legend ── */
.att-legend {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: center;
    margin-left: auto; font-family: var(--att-font); font-size: 12px; color: var(--att-text2);
}
.att-legend-item { display: flex; align-items: center; gap: 4px; }
.att-legend-dot {
    width: 14px; height: 14px; border-radius: 4px; display: inline-flex;
    align-items: center; justify-content: center; font-size: 9px; font-weight: 700; color: #fff;
}

/* ── Grid Table ── */
.att-grid-wrap {
    border-radius: var(--att-r); background: var(--att-card);
    border: 1px solid var(--att-border); box-shadow: var(--att-shadow);
    overflow: auto; max-height: calc(100vh - 260px);
}
.att-mgrid {
    /* fixed layout → every column takes its defined <th> width for BOTH header
       and body, so the date headers always sit exactly above their marks
       (auto layout let a sticky header size its columns independently). */
    table-layout: fixed;
    width: max-content; min-width: 100%; border-collapse: separate;
    border-spacing: 0; font-family: var(--att-font);
}
/* Header is intentionally NOT position:sticky. In a horizontally-scrollable
   container Safari mis-sizes sticky table-header cells (they stop honoring the
   column widths), which drifts the date headers off the marks below. A plain,
   non-sticky header renders on the exact same table column grid as the body, so
   every date sits precisely above its column. */
.att-mgrid thead th { position: static; }
.att-mgrid th {
    background: var(--att-bg3); color: var(--att-text2); font-size: 11px;
    font-weight: 600; text-transform: uppercase; letter-spacing: .3px;
    padding: 10px 6px; text-align: center; white-space: nowrap;
    border-bottom: 2px solid var(--att-border);
}
.att-mgrid th.att-th-idx { width: 46px; }
.att-mgrid th.att-th-name { text-align: left; padding-left: 14px; width: 180px; }
.att-mgrid th.att-th-id { text-align: left; width: 92px; }
.att-mgrid th.att-th-summary { width: 120px; }
.att-mgrid th.att-th-day { width: 38px; }
.att-mgrid th.att-col-sunday { background: rgba(239,68,68,.10); color: #dc2626; }
.att-mgrid th.att-col-holiday { background: rgba(139,92,246,.14); color: #7c3aed; }

.att-mgrid td {
    padding: 4px; text-align: center; border-bottom: 1px solid var(--att-border);
    font-size: 13px; color: var(--att-text); vertical-align: middle;
}
.att-mgrid td.att-td-idx { font-family: var(--att-font-m); font-size: 12px; color: var(--att-text3); padding: 4px 8px; }
.att-mgrid td.att-td-name {
    text-align: left; padding-left: 14px; font-weight: 500;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;
}
.att-mgrid td.att-td-id {
    text-align: left; font-family: var(--att-font-m); font-size: 11px; color: var(--att-text3);
}
.att-mgrid td.att-td-summary {
    font-family: var(--att-font-m); font-size: 11px; white-space: nowrap;
}
.att-mgrid td.att-col-sunday { background: var(--att-sunday); border-left: 1px solid rgba(239,68,68,.10); border-right: 1px solid rgba(239,68,68,.10); }
.att-mgrid td.att-col-holiday { background: var(--att-holiday); border-left: 1px solid rgba(139,92,246,.10); border-right: 1px solid rgba(139,92,246,.10); }
/* Phase D — Special Working Day: a normally-off day turned working. Deliberately
   NOT locked (unlike sunday/holiday below) — a genuinely normal editable cell,
   just visually flagged so the override is legible at a glance. */
.att-mgrid td.att-col-swd { background: rgba(16,163,74,.10); border-left: 1px solid rgba(16,163,74,.18); border-right: 1px solid rgba(16,163,74,.18); }
.att-mgrid tbody tr:hover td.att-col-swd { background: rgba(16,163,74,.16); }
/* Phase F — read-only Half-Day Leave overlay. An underline stripe, not a
   background fill, so it composes with whatever else the cell already shows
   (working day, SWD, dirty edit) instead of competing for the same paint.
   Display-only: does not lock/unlock the cell and carries no marking or
   payroll meaning by itself. */
.att-mgrid td.att-col-hdleave { box-shadow: inset 0 -3px 0 0 rgba(217,119,6,.65); }
.att-hdleave-dot {
    display: inline-block; width: 6px; height: 6px; border-radius: 50%;
    background: #d97706; margin-left: 2px; vertical-align: middle;
}
/* Future days: not yet editable. Kept visually uniform with the rest of the
   grid — only the date number is muted and the marks are lightly dimmed, so the
   future half doesn't read as a washed-out / broken block. */
.att-mgrid th.att-col-future,
.att-mgrid td.att-col-future {
    color: var(--att-text3);
}
.att-mgrid td.att-col-future .att-cell { cursor: not-allowed; opacity: .5; }
.att-mgrid td.att-col-future .att-cell:hover { transform: none; }
/* Sundays and Holidays are also locked — same cursor + dim treatment.
   The existing red/purple column tint stays so the reason is still legible. */
.att-mgrid td.att-col-sunday .att-cell,
.att-mgrid td.att-col-holiday .att-cell { cursor: not-allowed; opacity: .55; }
.att-mgrid td.att-col-sunday .att-cell:hover,
.att-mgrid td.att-col-holiday .att-cell:hover { transform: none; }

.att-mgrid tbody tr:hover td { background: var(--att-bg3); }
.att-mgrid tbody tr:hover td.att-col-sunday { background: rgba(239,68,68,.12); }
.att-mgrid tbody tr:hover td.att-col-holiday { background: rgba(139,92,246,.16); }

/* N-35 — a row whose employee has no resolvable work schedule at all
   (see Work Schedules → blocked panel for the same list). Every cell is
   already individually locked via att-col-sunday; this just marks the row
   itself so it doesn't read as an ordinary editable staff member. */
.att-mgrid tbody tr.att-row-blocked td.att-td-name { color: #dc2626; }

/* ── Day Cells ── */
.att-cell {
    width: 32px; height: 32px; border-radius: 6px; display: inline-flex;
    align-items: center; justify-content: center; font-size: 11px; font-weight: 700;
    cursor: pointer; user-select: none; transition: transform .1s, box-shadow .15s;
    position: relative; line-height: 1;
}
.att-cell:hover { transform: scale(1.12); }
.att-cell:active { transform: scale(.95); }
.att-cell.att-dirty { box-shadow: 0 0 0 2px var(--att-dirty); }

.att-cell[data-v="P"] { background: var(--att-p-bg); color: var(--att-p); }
.att-cell[data-v="A"] { background: var(--att-a-bg); color: var(--att-a); }
.att-cell[data-v="L"] { background: var(--att-l-bg); color: var(--att-l); }
.att-cell[data-v="T"] { background: var(--att-t-bg); color: var(--att-t); }
.att-cell[data-v="H"] { background: var(--att-h-bg); color: var(--att-h); }
.att-cell[data-v="V"] { background: var(--att-v-bg); color: var(--att-v); }
.att-cell[data-v="M"] { background: var(--att-m-bg); color: var(--att-m); }
.att-cell[data-v="W"] { background: var(--att-w-bg); color: var(--att-w); }
.att-cell[data-v="O"] { background: var(--att-o-bg); color: var(--att-o); }

.att-cell .att-clock {
    position: absolute; top: -3px; right: -3px; width: 12px; height: 12px;
    font-size: 8px; display: flex; align-items: center; justify-content: center;
    background: var(--att-t); color: #fff; border-radius: 50%;
}

/* ── Toast ── */
.att-toast {
    position: fixed; bottom: 24px; right: 24px; z-index: 9999;
    padding: 12px 22px; border-radius: 8px; font-family: var(--att-font);
    font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.25);
    transform: translateY(100px); opacity: 0; transition: all .3s ease;
    pointer-events: none;
}
.att-toast.show { transform: translateY(0); opacity: 1; }
.att-toast.success { background: var(--att-p); }
.att-toast.error { background: var(--att-a); }

/* ── Modal ── */
.att-modal-overlay {
    position: fixed; inset: 0; z-index: 9998; background: rgba(0,0,0,.5);
    display: none; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
}
.att-modal-overlay.open { display: flex; }
.att-modal {
    background: var(--att-card); border: 1px solid var(--att-border);
    border-radius: var(--att-r); box-shadow: 0 16px 48px rgba(0,0,0,.3);
    width: 420px; max-width: 92vw; max-height: 80vh; overflow-y: auto;
    padding: 24px;
}
.att-modal h3 {
    font-family: var(--att-font); font-size: 16px; font-weight: 700;
    color: var(--att-text); margin: 0 0 16px;
}
.att-modal-close {
    float: right; background: none; border: none; font-size: 18px;
    color: var(--att-text3); cursor: pointer; padding: 0; line-height: 1;
}
.att-modal-close:hover { color: var(--att-text); }
.att-modal-stat {
    display: flex; justify-content: space-between; padding: 8px 0;
    border-bottom: 1px solid var(--att-border); font-family: var(--att-font);
    font-size: 13px; color: var(--att-text2);
}
.att-modal-stat span:last-child { font-weight: 600; color: var(--att-text); }
.att-modal-bar-wrap {
    margin-top: 16px; height: 10px; border-radius: 5px;
    background: var(--att-bg3); overflow: hidden; display: flex;
}
.att-modal-bar-seg { height: 100%; transition: width .3s ease; }

/* ── Loading ── */
.att-loading {
    display: none; text-align: center; padding: 48px 20px;
    font-family: var(--att-font); font-size: 14px; color: var(--att-text3);
}
.att-loading.visible { display: block; }
.att-spinner {
    width: 32px; height: 32px; border: 3px solid var(--att-border);
    border-top-color: var(--att-primary); border-radius: 50%;
    animation: att-spin .7s linear infinite; margin: 0 auto 12px;
}
@keyframes att-spin { to { transform: rotate(360deg); } }

/* ── Empty State ── */
.att-empty {
    text-align: center; padding: 60px 20px; font-family: var(--att-font);
    color: var(--att-text3);
}
.att-empty i { font-size: 48px; margin-bottom: 12px; display: block; opacity: .4; }
.att-empty p { font-size: 14px; margin: 0; }

/* ── Responsive ── */
@media (max-width: 767px) {
    .att-filter-bar { flex-direction: column; align-items: stretch; }
    .att-filter-bar select { min-width: 100%; }
    .att-toolbar { flex-direction: column; align-items: stretch; }
    .att-legend { margin-left: 0; margin-top: 8px; }
    .att-toolbar-divider { display: none; }
    .att-modal { padding: 16px; }
    .att-kpis { grid-template-columns: repeat(2, 1fr) !important; }
}

/* ── Summary KPIs (redesign) — additive, computed after load ── */
.att-kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
.att-kpis:empty { display: none; }
.att-kpi {
    position: relative; overflow: hidden; background: var(--att-card);
    border: 1px solid var(--att-border); border-radius: var(--att-r);
    padding: 14px 15px 12px; box-shadow: var(--att-shadow);
}
.att-kpi::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    background: var(--k, var(--att-primary)); opacity: .85;
}
.att-kpi-label { font-family: var(--att-font); font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--att-text3); }
.att-kpi-val { font-family: var(--att-font); font-size: 26px; font-weight: 800; line-height: 1.1; margin-top: 7px; letter-spacing: -.01em; font-variant-numeric: tabular-nums; color: var(--att-text); }
.att-kpi-val small { font-size: 14px; font-weight: 600; color: var(--att-text3); }
.att-kpi-sub { font-family: var(--att-font); font-size: 11.5px; color: var(--att-text2); margin-top: 3px; }

/* ── Skeleton shimmer (redesign) ── */
.att-sk { display: block; border-radius: 6px;
    background: linear-gradient(90deg, var(--att-bg3) 25%, var(--att-bg2) 50%, var(--att-bg3) 75%);
    background-size: 200% 100%; animation: attShim 1.3s linear infinite; }
@keyframes attShim { to { background-position: -200% 0; } }

/* ── Live status pill (redesign) ── */
.att-status { display: inline-flex; align-items: center; gap: 7px; font-family: var(--att-font); font-size: 12px; font-weight: 600; color: var(--att-text2); }
.att-status:empty { display: none; }
.att-status .att-status-sp { width: 13px; height: 13px; border-radius: 50%; border: 2px solid var(--att-border); border-top-color: var(--att-primary); animation: attSpin .7s linear infinite; display: none; flex: 0 0 auto; }
.att-status.is-loading .att-status-sp { display: inline-block; }
@keyframes attSpin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .att-sk { animation: none; } .att-status .att-status-sp { animation: none; } }
</style>

<!-- Top progress bar — driven by attBusyStart/attBusyEnd on every request -->
<div class="att-loadbar" id="attLoadbar"></div>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header — shared .att-header card (consistent with Student Attendance) -->
    <div class="att-header">
        <div class="att-header-left">
            <div class="att-header-icon"><i class="fa fa-id-badge"></i></div>
            <div>
                <div class="att-page-title">Staff Attendance</div>
                <div class="att-subtitle">Mark and manage daily attendance for staff members</div>
                <ul class="att-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li><a href="<?= base_url('attendance') ?>">Attendance</a></li>
                    <li>Staff Attendance</li>
                </ul>
            </div>
        </div>
    </div>

    <?php if (!$att_can_edit): ?>
    <!-- Read-only banner (view-level access) — data still shown; editing blocked -->
    <div class="att-alert att-alert-info show" style="margin-bottom:16px;">
        <i class="fa fa-eye"></i>
        <span><strong>View-only access</strong> — you can see attendance but can't mark or edit it.</span>
    </div>
    <?php endif; ?>

    <!-- Month lock (GAP-1) — state of the month on screen, and of the session. -->
    <div class="att-lock-banner" id="attLockBanner" role="status">
        <i class="fa fa-lock"></i>
        <span id="attLockBannerText"></span>
    </div>
    <div class="att-lock-months" id="attLockedMonths"></div>

    <!-- Re-opening a closed month asks for a reason, as the payroll unlock does. -->
    <div class="att-rsn-back" id="attRsnBack" role="dialog" aria-modal="true" aria-labelledby="attRsnTitle">
        <div class="att-rsn">
            <h3 id="attRsnTitle"><i class="fa fa-unlock-alt"></i> Re-open a closed month</h3>
            <div class="warn">
                <b><i class="fa fa-exclamation-triangle"></i> You are re-opening a month that was closed.</b>
                <ul>
                    <li>Attendance for <b id="attRsnMonth"></b> becomes editable again.</li>
                    <li>Any payslip already calculated from this month is <b>not</b> recalculated by this action.</li>
                    <li>Your reason is audit-logged with your admin id and the time.</li>
                </ul>
            </div>
            <label for="attRsnText" style="font-size:12.5px;font-weight:600;">Reason</label>
            <textarea id="attRsnText" maxlength="500" placeholder="Why does this month need to be re-opened?"></textarea>
            <div class="att-rsn-count"><span id="attRsnCount">0</span> / 10 characters minimum</div>
            <div class="att-rsn-err" id="attRsnErr"></div>
            <div class="att-rsn-actions">
                <button type="button" class="att-btn att-btn-outline att-btn-sm" id="attRsnCancel">Cancel</button>
                <button type="button" class="att-btn att-btn-primary att-btn-sm" id="attRsnOk" disabled>
                    <i class="fa fa-unlock-alt"></i> Re-open month
                </button>
            </div>
        </div>
    </div>
    <!-- Summary KPIs (redesign) — populated by renderKpis() after each load -->
    <div class="att-kpis" id="attKpis"></div>

    <!-- Filter Card — shared .att-toolbar (consistent with Student Attendance) -->
    <div class="att-toolbar">
        <div class="att-fg">
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
                    $acadStart  = 4;
                    $sessionOn  = ($currentYear > $sessStart) || ($currentYear === $sessStart && $currentMonthNum >= $acadStart);

                    if (!empty($months)) {
                        foreach ($months as $m) {
                            $ms   = htmlspecialchars($m, ENT_QUOTES, 'UTF-8');
                            $mNum = $monthNumMap[$m] ?? 0;
                            if (!$sessionOn) {
                                $isFuture = ($mNum > $currentMonthNum);
                            } else {
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
        <div class="att-fg" style="align-self:flex-end;">
            <button type="button" class="att-btn att-btn-outline" id="attLoadBtn" title="Reload this month from the server">
                <i class="fa fa-refresh"></i> Refresh
            </button>
        </div>
        <div class="att-fg att-lock" style="align-self:flex-end;" id="attLockCtl">
            <span class="att-lock-pill unknown" id="attLockPill"><i class="fa fa-circle-o-notch fa-spin"></i> Checking&hellip;</span>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" id="attLockBtn" style="display:none"></button>
        </div>        <div style="flex:1 1 auto;"></div>
        <span class="att-status" id="attStatus" style="align-self:flex-end;">
            <span class="att-status-sp"></span><span id="attStatusText"></span>
        </span>
    </div>

    <!-- Toolbar -->
    <div class="att-toolbar" id="attToolbar">
        <div class="att-toolbar-section">
            <button type="button" class="att-btn att-btn-primary att-btn-sm" id="attSaveBtn" disabled>
                <i class="fa fa-save"></i> Save Changes
            </button>
            <span id="attDirtyCount" style="font-family:var(--att-font);font-size:11px;color:var(--att-l);font-weight:700;display:none;">
                <i class="fa fa-pencil"></i> <em id="attDirtyNum">0</em> unsaved
            </span>
        </div>
        <div class="att-toolbar-divider"></div>
        <div class="att-toolbar-section">
            <label style="font-family:var(--att-font);font-size:12px;font-weight:600;color:var(--att-text3);">Day:</label>
            <input type="number" id="attDayPicker" class="att-day-picker" min="1" max="31" value="1"<?= $att_can_edit ? '' : ' disabled' ?>>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="P"<?= $att_can_edit ? '' : ' disabled' ?>>
                <i class="fa fa-check"></i> All Present
            </button>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="H"<?= $att_can_edit ? '' : ' disabled' ?>>
                <i class="fa fa-star"></i> Holiday
            </button>
        </div>
        <div class="att-toolbar-divider"></div>
        <div class="att-legend">
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-p);">P</span> Present</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-a);">A</span> Absent</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-l);">L</span> Leave</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-t);">T</span> Late</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-h);">H</span> Holiday</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-v);">V</span> Vacant</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-m);">M</span> Half-day</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-w);">W</span> Work-off</span>
            <span class="att-legend-item"><span class="att-legend-dot" style="background:var(--att-o);">O</span> Weekly-off</span>
        </div>
    </div>

    <!-- Loading -->
    <div class="att-loading" id="attLoading">
        <div class="att-spinner"></div>
        Loading staff attendance data&hellip;
    </div>

    <!-- Empty State -->
    <div class="att-empty" id="attEmpty" style="display:none;">
        <i class="fa fa-calendar-o"></i>
        <p>No staff attendance found for this month.</p>
    </div>

    <!-- Grid -->
    <div class="att-grid-wrap" id="attGridWrap" style="display:none;">
        <table class="att-mgrid" id="attGrid">
            <thead id="attGridHead"></thead>
            <tbody id="attGridBody"></tbody>
        </table>
    </div>

    <!-- Staff Summary Modal -->
    <div class="att-modal-overlay" id="attModal">
        <div class="att-modal">
            <button class="att-modal-close" id="attModalClose">&times;</button>
            <h3 id="attModalTitle">Staff Summary</h3>
            <div id="attModalBody"></div>
        </div>
    </div>

    <!-- Toast -->
    <div class="att-toast" id="attToast"></div>

    <!-- Past-edit confirmation modal (two-step) -->
    <div class="att-modal-overlay" id="attPastConfirm">
        <div class="att-modal">
            <button class="att-modal-close" id="attPastConfirmClose">&times;</button>
            <h3 id="attPastConfirmTitle">Confirm edits to past dates</h3>
            <div id="attPastConfirmBody"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="att-btn att-btn-outline att-btn-sm" id="attPastConfirmCancel">Cancel</button>
                <button type="button" class="att-btn att-btn-primary att-btn-sm" id="attPastConfirmNext">Continue</button>
            </div>
        </div>
    </div>

</div>
</section>
</div>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    // Access level (mirrors server RBAC — defense-in-depth only).
    var CAN_EDIT = <?= $att_can_edit ? 'true' : 'false' ?>;

    /* ── Busy counter → top loadbar ──
       Any in-flight fetch turns the bar on; it clears only when the LAST
       request settles (success OR error). Wired into postData below so every
       call gets it automatically. */
    var _attBusy = 0;
    function attBusyStart(){ _attBusy++; var b=document.getElementById('attLoadbar'); if(b)b.classList.add('on'); }
    function attBusyEnd(){ _attBusy=Math.max(0,_attBusy-1); if(!_attBusy){ var b=document.getElementById('attLoadbar'); if(b)b.classList.remove('on'); } }

    /* ── State ── */
    var state = {
        staff: [],
        daysInMonth: 0,
        nonWorkingDays: {},   /* staffId -> {day: true, ...} — N-35: per-employee, not a flat Sunday list */
        scheduleBlocked: {},  /* staffId -> reason string, when that employee's schedule cannot be resolved at all */
        holidayDays: {},         /* staffId -> {day: name, ...} — Phase D: per-employee, department-scoped */
        specialWorkingDays: {},  /* staffId -> {day: name, ...} — Phase D: overrides weekly-off/holiday to working */
        halfDayLeaveDays: {},    /* staffId -> {day: label, ...} — Phase F: display-only overlay, does not affect marking or LOP */
        month: '',
        year: 0,
        attendance: {},   /* staffId -> array of day chars */
        original: {},     /* staffId -> original string */
        dirty: new Set()
    };

    /* ── Refs ── */
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
    var elKpis       = document.getElementById('attKpis');
    var elStatus     = document.getElementById('attStatus');
    var elStatusText = document.getElementById('attStatusText');
    var elDirty      = document.getElementById('attDirtyCount');
    var elDirtyNum   = document.getElementById('attDirtyNum');

    /* ── Helpers ── */
    function esc(s) {
        if (s == null) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }
    var _monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function getMonthIndex(name) {
        var idx = _monthNames.indexOf(name);
        return idx >= 0 ? idx : 0;
    }

    // Click-cycle for manual marking. M (half-day), W (work-on-off), O
    // (weekly-off) were added so an admin can set them by hand, just like
    // P/A/L — the backend save_staff_attendance already accepts all of these.
    var CYCLE = ['V','P','A','L','T','H','M','W','O'];

    function nextMark(v) {
        var i = CYCLE.indexOf(v);
        return CYCLE[(i + 1) % CYCLE.length];
    }

    /**
     * Classify a day in the currently loaded month relative to today.
     * Returns 'past' | 'today' | 'future'. Used to:
     *   - block edits to future days
     *   - trigger double-confirmation for past-date edits on save
     */
    function dayState(day) {
        if (!state.month || !state.year) return 'today';
        var now = new Date();
        now.setHours(0,0,0,0);
        var cellDate = new Date(state.year, getMonthIndex(state.month), day);
        cellDate.setHours(0,0,0,0);
        if (cellDate.getTime() > now.getTime()) return 'future';
        if (cellDate.getTime() < now.getTime()) return 'past';
        return 'today';
    }

    /**
     * Single source of truth for "can this day be edited?" — N-35/Phase D:
     * resolved PER EMPLOYEE, not per column. Two staff in this same grid can
     * legitimately have different weekly-offs AND different applicable
     * holidays (department-scoped) — see state.nonWorkingDays/holidayDays/
     * specialWorkingDays, all populated per-staff from
     * Policy_assignment_service + Holiday_service via attendance/fetch_staff.
     *
     * Returns the lock reason ('future' | 'blocked' | 'weeklyoff' | 'holiday')
     * if locked, otherwise null. Order matters: future wins, then a
     * fully-unresolvable schedule (the whole row is unsafe to mark), then a
     * Special Working Day (overrides weekly-off/holiday back to a normal,
     * unlocked day — checked BEFORE those, not after), then the per-employee
     * weekly-off/holiday checks — so the toast message stays accurate when
     * more than one applies.
     *
     * Holidays are read live from state.holidayDays (refreshed every
     * loadAttendance), so any calendar change takes effect on the next Load
     * click — no extra wiring needed.
     */
    function dateLockReason(staffId, day) {
        if (dayState(day) === 'future') return 'future';
        if (state.scheduleBlocked && state.scheduleBlocked[staffId]) return 'blocked';
        var swd = state.specialWorkingDays && state.specialWorkingDays[staffId];
        if (swd && swd[day] !== undefined) return null;   // Phase D — overrides everything below
        var nwd = state.nonWorkingDays && state.nonWorkingDays[staffId];
        if (nwd && nwd[day]) return 'weeklyoff';
        var hol = state.holidayDays && state.holidayDays[staffId];
        // Use property-existence (not truthiness) so a holiday saved with an
        // empty name still locks the date.
        if (hol && hol[day] !== undefined) return 'holiday';
        return null;
    }

    function lockToastMessage(reason, day, staffId) {
        if (reason === 'future')    return 'Future dates cannot be marked.';
        if (reason === 'blocked') {
            var why = (state.scheduleBlocked && state.scheduleBlocked[staffId]) || '';
            return 'This employee has no resolvable work schedule for this period — assign one under '
                 + 'Attendance → Work Schedules before marking attendance.' + (why ? ' (' + why + ')' : '');
        }
        if (reason === 'weeklyoff') return 'This is a scheduled weekly off for this employee.';
        if (reason === 'holiday') {
            var hol = state.holidayDays && state.holidayDays[staffId];
            var label = (hol && hol[day]) ? hol[day] : '';
            return 'Holiday (' + (label || 'declared') + ') — attendance cannot be marked.';
        }
        return 'This date is locked.';
    }

    function showToast(msg, type) {
        elToast.textContent = msg;
        elToast.className = 'att-toast ' + (type || 'success');
        setTimeout(function(){ elToast.classList.add('show'); }, 10);
        setTimeout(function(){ elToast.classList.remove('show'); }, 3000);
    }

    /* ── Month lock (GAP-1) ────────────────────────────────────────────
       A closed month is not editable by anyone, whatever their permission, so
       the effective gate is the permission AND the lock. Every site that read
       CAN_EDIT now asks this instead — including the save guard, so a tab left
       open across a month close cannot post into it. The server refuses
       independently; this stops an operator spending an afternoon on a month
       that was never going to save. */
    var MONTH_LOCKED = false;
    var LOCK_READABLE = true;
    var LOCK_CAN_MANAGE = false;
    var lockBusy = false;

    function canEditNow() { return CAN_EDIT && !MONTH_LOCKED; }

    function lockEls() {
        return {
            pill:   document.getElementById('attLockPill'),
            btn:    document.getElementById('attLockBtn'),
            banner: document.getElementById('attLockBanner'),
            text:   document.getElementById('attLockBannerText')
        };
    }

    /* Paint the state of the month now on screen. `lock` rides along with the
       register payload, so this runs after every month load. */
    function applyLockState(lock) {
        lock = lock || {};
        MONTH_LOCKED    = !!lock.isLocked;
        LOCK_READABLE   = lock.readable !== false;
        LOCK_CAN_MANAGE = !!lock.canManage;

        var el = lockEls();
        if (!el.pill) return;
        var monthSel = document.getElementById('attMonth');
        var month = monthSel ? monthSel.value : 'this month';

        if (!LOCK_READABLE) {
            /* The writer refuses when it cannot read the lock, so inviting an
               edit here would only produce a failure at Save. */
            el.pill.className = 'att-lock-pill unknown';
            el.pill.innerHTML = '<i class="fa fa-question-circle"></i> Lock state unavailable';
            el.text.innerHTML = '<b>The lock state for ' + esc(month) + ' could not be read.</b> '
                + 'Editing is held back rather than risking a change to a closed month. Refresh to retry.';
            el.banner.classList.add('show');
        } else if (MONTH_LOCKED) {
            el.pill.className = 'att-lock-pill locked';
            el.pill.innerHTML = '<i class="fa fa-lock"></i> Closed';
            el.text.innerHTML = '<b>' + esc(month) + ' is closed.</b> '
                + 'Attendance for this month can no longer be changed — payroll may already have been '
                + 'calculated from it.' + (LOCK_CAN_MANAGE ? ' You can re-open it, with a reason.' : '');
            el.banner.classList.add('show');
        } else {
            el.pill.className = 'att-lock-pill open';
            el.pill.innerHTML = '<i class="fa fa-unlock"></i> Open';
            el.banner.classList.remove('show');
        }

        if (LOCK_CAN_MANAGE && LOCK_READABLE) {
            el.btn.style.display = '';
            el.btn.innerHTML = MONTH_LOCKED
                ? '<i class="fa fa-unlock-alt"></i> Re-open month'
                : '<i class="fa fa-lock"></i> Close month';
            el.btn.disabled = lockBusy;
        } else {
            el.btn.style.display = 'none';
        }

        /* Repaint through the same path a permission change would use. */
        if (elGridWrap) elGridWrap.classList.toggle('att-readonly', !canEditNow());
        var save = document.getElementById('attSaveBtn');
        if (save && !canEditNow()) save.disabled = true;
    }

    function setLockBusy(on, label) {
        lockBusy = on;
        var btn = lockEls().btn;
        if (!btn) return;
        btn.disabled = on;
        if (on) btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> ' + (label || 'Working...');
    }

    /* Close the month. Confirmed, because it stops everyone editing it. */
    function doLockMonth() {
        var month = document.getElementById('attMonth').value;
        if (!confirm('Close ' + month + '?\n\nNobody will be able to change staff attendance for '
                   + month + ' until it is re-opened. Do this once the month is settled and payroll '
                   + 'has been calculated from it.')) return;

        setLockBusy(true, 'Closing...');
        postData('attendance/lock_staff_attendance', { month: month })
            .then(function (res) {
                showToast((res && res.message) || (month + ' closed.'), 'success');
                lockBusy = false;
                loadAttendance();            /* re-read; never assume the new state */
                loadLockedMonths();
            })
            .catch(function (e) {
                lockBusy = false;
                showToast((e && e.message) || 'Could not close the month.', 'error');
                applyLockState({ isLocked: MONTH_LOCKED, readable: LOCK_READABLE, canManage: LOCK_CAN_MANAGE });
            });
    }

    /* Re-open the month. Requires a reason of at least 10 characters — the same
       bar Hr::unlock_payroll() sets for the equivalent act on a payroll run. */
    function doUnlockMonth() {
        var month = document.getElementById('attMonth').value;
        var back  = document.getElementById('attRsnBack');
        var text  = document.getElementById('attRsnText');
        var ok    = document.getElementById('attRsnOk');
        var count = document.getElementById('attRsnCount');
        var err   = document.getElementById('attRsnErr');
        if (!back) return;

        document.getElementById('attRsnMonth').textContent = month;
        text.value = '';
        count.textContent = '0';
        ok.disabled = true;
        err.classList.remove('show');
        back.classList.add('show');
        setTimeout(function () { text.focus(); }, 60);

        function onType() {
            var n = text.value.trim().length;
            count.textContent = n;
            ok.disabled = n < 10;
        }
        function close() {
            back.classList.remove('show');
            text.removeEventListener('input', onType);
            ok.onclick = null;
            document.removeEventListener('keydown', onKey);
        }
        function onKey(ev) { if (ev.key === 'Escape') close(); }

        text.addEventListener('input', onType);
        document.addEventListener('keydown', onKey);
        document.getElementById('attRsnCancel').onclick = close;
        back.onclick = function (ev) { if (ev.target === back) close(); };

        ok.onclick = function () {
            var reason = text.value.trim();
            if (reason.length < 10) return;     /* the server checks this too */

            ok.disabled = true;
            ok.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Re-opening...';
            err.classList.remove('show');

            postData('attendance/unlock_staff_attendance', { month: month, reason: reason })
                .then(function (res) {
                    close();
                    showToast((res && res.message) || (month + ' re-opened.'), 'success');
                    loadAttendance();
                    loadLockedMonths();
                })
                .catch(function (e) {
                    /* Keep the dialog open so a retry does not lose the reason. */
                    err.textContent = (e && e.message) || 'Could not re-open the month.';
                    err.classList.add('show');
                    ok.disabled = text.value.trim().length < 10;
                    ok.innerHTML = '<i class="fa fa-unlock-alt"></i> Re-open month';
                });
        };
    }

    /* Which months of the session are closed. One query, so "is anything still
       open?" is answerable without clicking through twelve months. */
    function loadLockedMonths() {
        var el = document.getElementById('attLockedMonths');
        if (!el) return;

        postData('attendance/staff_lock_status', {})
            .then(function (res) {
                var closed = (res.months || []).filter(function (m) { return m.isLocked; });
                if (!closed.length) { el.textContent = ''; return; }
                el.innerHTML = '<i class="fa fa-lock"></i> Closed this session: '
                    + closed.map(function (m) { return esc(m.month); }).join(', ');
            })
            .catch(function () { el.textContent = ''; });   /* a summary line, never a blocker */
    }
    function postData(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        if (data) {
            Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        }
        // FAIL-CLOSED: fetch never rejects on 403/401/500, so we inspect the
        // parsed body + HTTP status and THROW on any failure. Callers show the
        // thrown message in .catch and only flip UI to "saved" in the resolved
        // .then — never optimistically.
        // Drive the top progress bar for EVERY request routed through here.
        attBusyStart();
        return fetch(BASE + url, { method: 'POST', body: fd })
            .then(function(r) {
                return r.text().then(function(t) {
                    var j = {};
                    try { j = t ? JSON.parse(t) : {}; } catch (e) { j = {}; }
                    if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash;
                    if (!r.ok || (j && (j.status === 'error' || j.success === false))) {
                        throw new Error((j && (j.message || j.error)) || ('Request failed (' + r.status + ')'));
                    }
                    return j;
                });
            })
            .finally(function(){ attBusyEnd(); });
    }

    /* ── Live status pill (redesign) ── */
    function setStatus(mode, text) {
        if (!elStatus) return;
        elStatus.classList.toggle('is-loading', mode === 'loading');
        if (elStatusText) elStatusText.textContent = text || '';
    }

    /* ── Skeleton tiles shown while a month loads (redesign) ── */
    function kpiSkeleton() {
        if (!elKpis) return;
        var h = '';
        for (var i = 0; i < 5; i++) {
            h += '<div class="att-kpi">'
               +   '<span class="att-sk" style="width:62px;height:10px"></span>'
               +   '<span class="att-sk" style="width:48px;height:22px;margin-top:12px"></span>'
               +   '<span class="att-sk" style="width:84px;height:9px;margin-top:9px"></span>'
               + '</div>';
        }
        elKpis.innerHTML = h;
    }

    /**
     * Summary KPIs (redesign) — derived entirely from the already-loaded
     * state.attendance, so it adds ZERO server calls. "Today" resolves to the
     * current date for the live month, else the last day of a past month.
     */
    function renderKpis() {
        if (!elKpis) return;
        if (!state.staff.length) { elKpis.innerHTML = ''; return; }

        var now = new Date();
        var isCurrent = (state.year === now.getFullYear() &&
                         getMonthIndex(state.month) === now.getMonth());
        var refDay = isCurrent ? Math.min(now.getDate(), state.daysInMonth) : state.daysInMonth;
        var di = refDay - 1;

        var presentT = 0, absentT = 0, leaveT = 0, avgSum = 0, avgN = 0;
        state.staff.forEach(function(s) {
            var arr = state.attendance[s.id];
            if (!arr) return;
            var v = arr[di];
            if (v === 'P' || v === 'T') presentT++;
            else if (v === 'A') absentT++;
            else if (v === 'L') leaveT++;

            var c = { P:0, A:0, L:0, T:0 };
            arr.forEach(function(x){ if (c[x] !== undefined) c[x]++; });
            var workable = c.P + c.A + c.L + c.T;
            if (workable > 0) { avgSum += Math.round((c.P + c.T) / workable * 100); avgN++; }
        });
        var avg = avgN ? Math.round(avgSum / avgN) : 0;
        var head = state.staff.length;
        var when = isCurrent ? 'today' : (state.month.slice(0,3) + ' ' + refDay);

        var tiles = [
            { k:'var(--att-primary)', label:'Total staff',     val:head,     sub:'on the roster' },
            { k:'var(--att-p)',       label:'Present ' + when,  val:presentT, sub:head ? Math.round(presentT/head*100) + '% of roster' : '' },
            { k:'var(--att-a)',       label:'Absent ' + when,   val:absentT,  sub:absentT ? 'need follow-up' : 'none' },
            { k:'var(--att-l)',       label:'On leave ' + when, val:leaveT,   sub:leaveT ? 'approved' : 'none' },
            { k:'var(--att-t)',       label:'Month average',    val:avg, unit:'%', sub:'attendance rate' }
        ];
        elKpis.innerHTML = tiles.map(function(t){
            return '<div class="att-kpi" style="--k:' + t.k + '">'
                 +   '<div class="att-kpi-label">' + esc(t.label) + '</div>'
                 +   '<div class="att-kpi-val">' + t.val + (t.unit ? '<small>' + t.unit + '</small>' : '') + '</div>'
                 +   '<div class="att-kpi-sub">' + esc(t.sub) + '</div>'
                 + '</div>';
        }).join('');
    }

    /* ── Single-scroll fit (redesign) ──
     * Size the register to fill the remaining viewport so it is the ONE scroll
     * region — sticky day-header + name column stay pinned — instead of a short
     * boxed scroller stacked above a second page scrollbar. Measures the grid's
     * live top offset (adapts to KPIs / toolbar wrapping) rather than a brittle
     * fixed calc(). Re-runs on resize. */
    function fitGrid() {
        if (!elGridWrap || elGridWrap.style.display === 'none') return;
        var top = elGridWrap.getBoundingClientRect().top;
        var avail = window.innerHeight - top - 14;   /* leave a little breathing room */
        elGridWrap.style.maxHeight = Math.max(240, avail) + 'px';
    }
    var _fitRaf = null;
    window.addEventListener('resize', function() {
        if (_fitRaf) cancelAnimationFrame(_fitRaf);
        _fitRaf = requestAnimationFrame(fitGrid);
    });

    /* ── Load ── */
    /* GAP-1 — one button, two acts. Which one is decided by the state the
       server last reported, never by what the label happens to say. */
    var lockBtnEl = document.getElementById('attLockBtn');
    if (lockBtnEl) {
        lockBtnEl.addEventListener('click', function () {
            if (lockBusy || !LOCK_CAN_MANAGE || !LOCK_READABLE) return;
            if (MONTH_LOCKED) { doUnlockMonth(); } else { doLockMonth(); }
        });
    }
    elLoadBtn.addEventListener('click', loadAttendance);
    // Redesign: month picker drives loading (no required "Load" click) …
    elMonth.addEventListener('change', loadAttendance);

    function loadAttendance() {
        var mon = elMonth.value;
        if (!mon) {
            showToast('Please select a month.', 'error');
            return;
        }
        elGridWrap.style.display = 'none';
        elEmpty.style.display = 'none';
        elToolbar.classList.remove('visible');
        elLoading.classList.add('visible');
        setStatus('loading', 'Loading ' + mon + '…');
        kpiSkeleton();
        elLoadBtn.classList.add('is-loading');
        elLoadBtn.disabled = true;

        postData('attendance/fetch_staff', { month: mon })
            .then(function(res) {
                elLoading.classList.remove('visible');
                elLoadBtn.classList.remove('is-loading');
                elLoadBtn.disabled = false;
                if (!res || res.status === 'error') {
                    // GAP-1: the lock state came with this payload, so a failed
                    // load leaves it unknown. Say so, rather than leaving the pill
                    // spinning and the register looking editable.
                    applyLockState({ readable: false });
                    showToast(res ? res.message : 'Failed to load data.', 'error');
                    elEmpty.style.display = 'block';
                    if (elKpis) elKpis.innerHTML = '';
                    setStatus('', '');
                    return;
                }
                state.staff = res.staff || [];
                state.daysInMonth = parseInt(res.daysInMonth, 10) || 30;
                state.nonWorkingDays = res.nonWorkingDays || {};
                state.scheduleBlocked = res.scheduleBlocked || {};
                // Phase D — per-staff now, not a flat shared map.
                state.holidayDays = res.holidayDays || {};
                state.specialWorkingDays = res.specialWorkingDays || {};
                state.halfDayLeaveDays = res.halfDayLeaveDays || {};
                state.month = res.month || mon;
                state.year = parseInt(res.year, 10) || new Date().getFullYear();
                state.dirty = new Set();

                /* GAP-1 — the register now knows whether this month is closed.
                   Painted BEFORE the empty-roster return below, so the state is
                   right even for a month with no rows to show. */
                applyLockState(res.lock);

                if (state.staff.length === 0) {
                    showToast('No staff found for this month.', 'error');
                    elEmpty.style.display = 'block';
                    if (elKpis) elKpis.innerHTML = '';
                    setStatus('', 'No data · ' + mon);
                    return;
                }

                /* Parse attendance strings into arrays */
                state.attendance = {};
                state.original = {};
                state.staff.forEach(function(s) {
                    var str = s.attendance || '';
                    var arr = [];
                    for (var d = 0; d < state.daysInMonth; d++) {
                        arr.push(str.charAt(d) || 'V');
                    }
                    state.attendance[s.id] = arr;
                    state.original[s.id] = arr.join('');
                });

                /* Day-picker max: for the current calendar month, cap at today
                 * so bulk actions can't target future days. Past months stay
                 * fully spannable so admins can audit retroactively. */
                var now = new Date();
                var isCurrentMonth = (state.year === now.getFullYear() &&
                                      getMonthIndex(state.month) === now.getMonth());
                elDayPick.max = isCurrentMonth ? now.getDate() : state.daysInMonth;
                if (parseInt(elDayPick.value, 10) > parseInt(elDayPick.max, 10)) elDayPick.value = 1;

                renderGrid();
                renderKpis();
                elGridWrap.style.display = 'block';
                elGridWrap.classList.toggle('att-readonly', !canEditNow());
                elToolbar.classList.add('visible');
                updateSaveBtn();
                setStatus('ready', 'Updated · ' + state.month);
                fitGrid();   /* size the register to fill the viewport → single scroll */
            })
            .catch(function(e) {
                elLoading.classList.remove('visible');
                elLoadBtn.classList.remove('is-loading');
                elLoadBtn.disabled = false;
                applyLockState({ readable: false });   // GAP-1 — unknown, not open
                showToast((e && e.message) ? e.message : 'Network error loading attendance.', 'error');
                elEmpty.style.display = 'block';
                if (elKpis) elKpis.innerHTML = '';
                setStatus('', '');
            });
    }

    /* ── Render Grid ── */
    function renderGrid() {
        /* Header — school-wide facts only (future). Weekly-off AND holiday
         * are both per EMPLOYEE now (N-35/Phase D), so neither can be shaded
         * at the column level — a date might be a holiday for Teaching and a
         * normal working day for Security. Per-row shading happens in the
         * body loop below. */
        var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var hHtml = '<tr><th class="att-th-idx">#</th><th class="att-th-name">Staff Name</th><th class="att-th-id">Dept</th>';
        for (var d = 1; d <= state.daysInMonth; d++) {
            var cls = 'att-th-day';
            if (dayState(d) === 'future') cls += ' att-col-future';
            var dt = new Date(state.year, getMonthIndex(state.month), d);
            var dn = dayNames[dt.getDay()];
            hHtml += '<th class="' + cls + '" title="' + dn + ', ' + state.month + ' ' + d + '">';
            hHtml += '<div style="line-height:1.2">' + d + '</div>';
            hHtml += '<div style="font-size:8px;font-weight:500;letter-spacing:0;opacity:.6">' + dn.charAt(0) + '</div>';
            hHtml += '</th>';
        }
        hHtml += '<th class="att-th-summary">Summary</th></tr>';
        elHead.innerHTML = hHtml;

        /* Body */
        var bHtml = '';
        state.staff.forEach(function(s, idx) {
            var att = state.attendance[s.id];
            var blockedWhy = state.scheduleBlocked && state.scheduleBlocked[s.id];
            var nwd = (state.nonWorkingDays && state.nonWorkingDays[s.id]) || {};
            var hol = (state.holidayDays && state.holidayDays[s.id]) || {};
            var swd = (state.specialWorkingDays && state.specialWorkingDays[s.id]) || {};
            var hdLeave = (state.halfDayLeaveDays && state.halfDayLeaveDays[s.id]) || {};
            var rowCls = blockedWhy ? ' att-row-blocked' : '';
            bHtml += '<tr data-sid="' + esc(s.id) + '" class="' + rowCls + '"' +
                (blockedWhy ? ' title="No resolvable work schedule — ' + esc(blockedWhy) + '"' : '') + '>';
            bHtml += '<td class="att-td-idx">' + (idx + 1) + '</td>';
            var nameTitle = s.designation ? s.name + ' — ' + s.designation : s.name;
            bHtml += '<td class="att-td-name" title="' + esc(nameTitle) + '">' + esc(s.name)
                   + (blockedWhy ? ' <i class="fa fa-exclamation-triangle" style="color:#dc2626" title="No resolvable work schedule"></i>' : '') + '</td>';
            bHtml += '<td class="att-td-id" style="font-size:10px;color:var(--att-text3)">' + esc(s.department || '-') + '</td>';
            for (var d = 0; d < state.daysInMonth; d++) {
                var day = d + 1;
                var v = att[d];
                var tdCls = '';
                var isSwd = swd[day] !== undefined;
                if (isSwd) {
                    tdCls += ' att-col-swd';                          /* Phase D — overrides weekly-off/holiday back to working */
                } else if (blockedWhy) {
                    tdCls += ' att-col-sunday';                       /* whole row locked — reuse the off-day shade */
                } else if (nwd[day]) {
                    tdCls += ' att-col-sunday';                       /* this employee's own weekly-off/rest day */
                } else if (hol[day] !== undefined) {
                    tdCls += ' att-col-holiday';                      /* this employee's own applicable holiday */
                }
                if (dayState(day) === 'future') tdCls += ' att-col-future';
                // Phase F — display-only; never affects locking/editability.
                var hasHdLeave = hdLeave[day] !== undefined;
                if (hasHdLeave) tdCls += ' att-col-hdleave';
                var dirtyMark = state.dirty.has(s.id) && att[d] !== state.original[s.id].charAt(d) ? ' att-dirty' : '';
                var clock = v === 'T' ? '<span class="att-clock"><i class="fa fa-clock-o"></i></span>' : '';
                var hdDot = hasHdLeave ? '<span class="att-hdleave-dot"></span>' : '';
                // Only genuinely editable cells are keyboard-focusable.
                var editable = canEditNow() && !dateLockReason(s.id, day);
                var cellAttrs = editable ? ' tabindex="0" role="button" aria-label="Toggle mark, day ' + day + '"' : '';
                var cellTitle = isSwd ? ' title="Special Working Day' + (swd[day] ? ': ' + esc(swd[day]) : '') + '"'
                    : (hasHdLeave ? ' title="Half-Day Leave' + (hdLeave[day] ? ': ' + esc(hdLeave[day]) : '') + ' — informational only, does not affect marking"' : '');
                bHtml += '<td class="' + tdCls + '"' + cellTitle + '>';
                bHtml += '<span class="att-cell' + dirtyMark + '" data-v="' + v + '" data-sid="' + esc(s.id) + '" data-d="' + d + '"' + cellAttrs + '>';
                bHtml += v + clock + hdDot + '</span></td>';
            }
            bHtml += '<td class="att-td-summary">' + summaryText(att) + '</td>';
            bHtml += '</tr>';
        });
        elBody.innerHTML = bHtml;
    }

    function summaryText(arr) {
        var c = {P:0,A:0,L:0,T:0,H:0,V:0};
        arr.forEach(function(v){ if (c[v] !== undefined) c[v]++; });
        return '<span style="color:var(--att-p)">P:' + c.P + '</span> '
             + '<span style="color:var(--att-a)">A:' + c.A + '</span> '
             + '<span style="color:var(--att-l)">L:' + c.L + '</span>';
    }

    function updateCell(sid, d) {
        var cell = elBody.querySelector('.att-cell[data-sid="' + CSS.escape(sid) + '"][data-d="' + d + '"]');
        if (!cell) return;
        var v = state.attendance[sid][d];
        cell.setAttribute('data-v', v);
        var isDirty = v !== state.original[sid].charAt(d);
        cell.classList.toggle('att-dirty', isDirty);
        cell.innerHTML = v + (v === 'T' ? '<span class="att-clock"><i class="fa fa-clock-o"></i></span>' : '');

        /* Update summary */
        var row = cell.closest('tr');
        if (row) {
            var sumTd = row.querySelector('.att-td-summary');
            if (sumTd) sumTd.innerHTML = summaryText(state.attendance[sid]);
        }
    }

    function updateSaveBtn() {
        // View-only: Save can never enable, and no "unsaved" affordance shows.
        if (!canEditNow()) {
            elSaveBtn.disabled = true;
            if (elDirty) elDirty.style.display = 'none';
            if (elToolbar) elToolbar.classList.remove('att-has-unsaved');
            return;
        }
        elSaveBtn.disabled = state.dirty.size === 0;
        // Visible unsaved-changes indicator that clears on confirmed save.
        if (elDirty) {
            if (state.dirty.size > 0) {
                if (elDirtyNum) elDirtyNum.textContent = state.dirty.size;
                elDirty.style.display = 'inline';
                elToolbar.classList.add('att-has-unsaved');
            } else {
                elDirty.style.display = 'none';
                elToolbar.classList.remove('att-has-unsaved');
            }
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
    }

    /* ── Cell toggle (click + keyboard) ── */
    function toggleCell(cell) {
        if (!cell) return;
        // View-only users can read the register but never mutate it.
        if (!canEditNow()) {
            showToast('View-only access — you can see attendance but can\'t mark or edit it.', 'error');
            return;
        }
        var sid = cell.getAttribute('data-sid');
        var d = parseInt(cell.getAttribute('data-d'), 10);
        var day = d + 1;
        // Block edits on future dates, this employee's scheduled weekly-offs,
        // declared holidays, or an unresolvable schedule (N-35: per employee).
        var lock = dateLockReason(sid, day);
        if (lock) {
            showToast(lockToastMessage(lock, day, sid), 'error');
            return;
        }
        var curr = state.attendance[sid][d];
        state.attendance[sid][d] = nextMark(curr);
        updateCell(sid, d);
        markDirty(sid);
    }

    elBody.addEventListener('click', function(e) {
        toggleCell(e.target.closest('.att-cell'));
    });
    // Keyboard-friendly: Enter / Space cycles the focused cell.
    elBody.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        var cell = e.target.closest('.att-cell');
        if (!cell) return;
        e.preventDefault();
        toggleCell(cell);
    });

    /* ── Double-click Row → Modal ── */
    elBody.addEventListener('dblclick', function(e) {
        var row = e.target.closest('tr');
        if (!row) return;
        var sid = row.getAttribute('data-sid');
        if (!sid || !state.attendance[sid]) return;
        showStaffModal(sid);
    });

    function showStaffModal(sid) {
        var member = null;
        state.staff.forEach(function(s){ if (s.id === sid) member = s; });
        if (!member) return;

        var arr = state.attendance[sid];
        var c = {P:0,A:0,L:0,T:0,H:0,V:0};
        arr.forEach(function(v){ if (c[v] !== undefined) c[v]++; });
        var working = state.daysInMonth - c.H - c.V;
        var pct = working > 0 ? Math.round((c.P + c.T) / working * 100) : 0;

        elModalTitle.textContent = member.name + ' - Attendance Summary';

        var html = '';
        html += '<div class="att-modal-stat"><span>Total Days</span><span>' + state.daysInMonth + '</span></div>';
        html += '<div class="att-modal-stat"><span>Present</span><span style="color:var(--att-p)">' + c.P + '</span></div>';
        html += '<div class="att-modal-stat"><span>Absent</span><span style="color:var(--att-a)">' + c.A + '</span></div>';
        html += '<div class="att-modal-stat"><span>Leave</span><span style="color:var(--att-l)">' + c.L + '</span></div>';
        html += '<div class="att-modal-stat"><span>Late</span><span style="color:var(--att-t)">' + c.T + '</span></div>';
        html += '<div class="att-modal-stat"><span>Holiday</span><span style="color:var(--att-h)">' + c.H + '</span></div>';
        html += '<div class="att-modal-stat"><span>Vacant</span><span style="color:var(--att-v)">' + c.V + '</span></div>';
        html += '<div class="att-modal-stat" style="font-weight:700;border-bottom:none;"><span>Attendance %</span><span>' + pct + '%</span></div>';

        var total = c.P + c.A + c.L + c.T + c.H + c.V;
        html += '<div class="att-modal-bar-wrap">';
        if (total > 0) {
            var segments = [
                {v:c.P, color:'var(--att-p)'}, {v:c.T, color:'var(--att-t)'},
                {v:c.L, color:'var(--att-l)'}, {v:c.A, color:'var(--att-a)'},
                {v:c.H, color:'var(--att-h)'}, {v:c.V, color:'var(--att-v)'}
            ];
            segments.forEach(function(seg){
                if (seg.v > 0) {
                    html += '<div class="att-modal-bar-seg" style="width:' + (seg.v/total*100) + '%;background:' + seg.color + ';"></div>';
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
            if (!canEditNow()) {
                showToast('View-only access — bulk marking is disabled.', 'error');
                return;
            }
            var mark = btn.getAttribute('data-bulk');
            var day = parseInt(elDayPick.value, 10);
            if (day < 1 || day > state.daysInMonth) {
                showToast('Invalid day number.', 'error');
                return;
            }
            // Block bulk on future dates outright — that applies to every row
            // identically. Weekly-off/holiday/blocked are now per EMPLOYEE
            // (N-35), so bulk-marking skips only the staff for whom this
            // specific day is locked, rather than refusing the whole action
            // because at least one row happens to be off that day.
            if (dayState(day) === 'future') {
                showToast(lockToastMessage('future', day), 'error');
                return;
            }
            var d = day - 1;
            var applied = 0, skipped = 0;
            state.staff.forEach(function(s) {
                if (dateLockReason(s.id, day)) { skipped++; return; }
                state.attendance[s.id][d] = mark;
                updateCell(s.id, d);
                markDirty(s.id);
                applied++;
            });
            if (skipped > 0) {
                showToast('Applied to ' + applied + ' staff — ' + skipped
                    + ' skipped (weekly off, holiday, or no resolvable schedule on that day).',
                    applied > 0 ? 'success' : 'error');
            }
        });
    });

    /* ── Save ── */
    /**
     * Walk the dirty set and collect every (staffId, day) cell whose value
     * changed vs the loaded original AND whose date is strictly before today.
     * The result drives the two-step past-edit confirmation modal.
     */
    function collectPastEdits() {
        var edits = [];   // [{sid, name, day}]
        state.dirty.forEach(function(sid) {
            var cur  = state.attendance[sid];
            var orig = state.original[sid];
            for (var i = 0; i < cur.length; i++) {
                if (cur[i] !== orig.charAt(i) && dayState(i + 1) === 'past') {
                    var member = null;
                    for (var k = 0; k < state.staff.length; k++) {
                        if (state.staff[k].id === sid) { member = state.staff[k]; break; }
                    }
                    edits.push({ sid: sid, name: member ? member.name : sid, day: i + 1 });
                }
            }
        });
        return edits;
    }

    function performSave() {
        var mon = elMonth.value;

        var attObj = {};
        var lateObj = {};
        state.dirty.forEach(function(sid) {
            var str = state.attendance[sid].join('');
            attObj[sid] = str;
            /* Build late data: array of day numbers (1-based) */
            var lateDays = [];
            state.attendance[sid].forEach(function(v, i) {
                if (v === 'T') lateDays.push(i + 1);
            });
            if (lateDays.length > 0) lateObj[sid] = lateDays;
        });

        elSaveBtn.disabled = true;
        elSaveBtn.classList.add('is-loading');

        postData('attendance/save_staff', {
            month: mon,
            attendance: JSON.stringify(attObj),
            late: JSON.stringify(lateObj)
        })
        .then(function(res) {
            elSaveBtn.classList.remove('is-loading');
            if (res && res.status === 'success') {
                var skipped = Array.isArray(res.skipped) ? res.skipped : [];
                var savedCount = (typeof res.saved === 'number') ? res.saved : 0;
                var skippedIds = {};
                skipped.forEach(function(s){ if (s && s.staffId) skippedIds[s.staffId] = true; });

                state.dirty.forEach(function(sid) {
                    if (!skippedIds[sid]) {
                        state.original[sid] = state.attendance[sid].join('');
                    }
                });
                state.dirty = new Set(Object.keys(skippedIds));
                updateSaveBtn();
                elBody.querySelectorAll('.att-dirty').forEach(function(c){
                    var sid = c.getAttribute('data-sid');
                    if (sid && !skippedIds[sid]) c.classList.remove('att-dirty');
                });

                if (skipped.length > 0) {
                    showSkippedModal(skipped, savedCount);
                } else {
                    showToast('Staff attendance saved successfully!', 'success');
                }
            } else {
                showToast(res && res.message ? res.message : 'Failed to save attendance.', 'error');
                elSaveBtn.disabled = false;
            }
        })
        .catch(function(e) {
            // Fail-closed: server rejected (403/500/status:error) — nothing was
            // committed to state.original, so dirty marks stay highlighted.
            elSaveBtn.classList.remove('is-loading');
            elSaveBtn.disabled = false;
            showToast((e && e.message) ? e.message : 'Network error while saving.', 'error');
        });
    }

    /* ── Past-edit confirmation modal (two-step) ── */
    var elPC       = document.getElementById('attPastConfirm');
    var elPCTitle  = document.getElementById('attPastConfirmTitle');
    var elPCBody   = document.getElementById('attPastConfirmBody');
    var elPCNext   = document.getElementById('attPastConfirmNext');
    var elPCCancel = document.getElementById('attPastConfirmCancel');
    var elPCClose  = document.getElementById('attPastConfirmClose');

    function closePastConfirm() { elPC.classList.remove('open'); }
    elPCClose.addEventListener('click', closePastConfirm);
    elPCCancel.addEventListener('click', closePastConfirm);
    elPC.addEventListener('click', function(e){ if (e.target === elPC) closePastConfirm(); });

    function openPastConfirm(edits) {
        // Step 1 — list affected past dates, grouped by date for readability.
        var byDay = {};
        edits.forEach(function(e){
            var k = state.month + ' ' + e.day + ', ' + state.year;
            (byDay[k] = byDay[k] || []).push(e.name);
        });
        var rows = '';
        Object.keys(byDay).sort().forEach(function(k){
            var names = byDay[k];
            rows += '<div class="att-modal-stat"><span>' + esc(k) + '</span>'
                  + '<span style="color:var(--att-a);font-weight:700">' + names.length + ' staff</span></div>';
        });
        elPCTitle.textContent = 'Confirm edits to past dates';
        elPCBody.innerHTML =
            '<p style="font-family:var(--att-font);font-size:13px;color:var(--att-text2);margin:0 0 14px 0;">'
          + 'You are about to modify attendance for <strong>' + edits.length + ' past-date entr' + (edits.length === 1 ? 'y' : 'ies') + '</strong>. '
          + 'Past-date changes are recorded in the audit log.</p>'
          + rows;
        elPCNext.innerHTML = 'Continue';
        elPCNext.onclick = function(){ openPastConfirmStep2(edits); };
        elPC.classList.add('open');
    }

    function openPastConfirmStep2(edits) {
        // Step 2 — final "are you absolutely sure?" gate before POST.
        elPCTitle.textContent = 'Are you absolutely sure?';
        elPCBody.innerHTML =
            '<p style="font-family:var(--att-font);font-size:13px;color:var(--att-text2);margin:0 0 8px 0;">'
          + 'This will overwrite <strong>' + edits.length + '</strong> past-date attendance mark' + (edits.length === 1 ? '' : 's') + '. '
          + 'There is no undo — only another correction will reverse it.</p>'
          + '<p style="font-family:var(--att-font);font-size:12px;color:var(--att-text3);margin:0;">Click <strong>Yes, save changes</strong> to proceed, or Cancel to review.</p>';
        elPCNext.innerHTML = '<i class="fa fa-check"></i> Yes, save changes';
        elPCNext.onclick = function(){
            closePastConfirm();
            performSave();
        };
    }

    elSaveBtn.addEventListener('click', function() {
        if (!canEditNow()) return;   // hardened: view-only, or a closed month, can never save
        if (state.dirty.size === 0) return;
        var pastEdits = collectPastEdits();
        if (pastEdits.length > 0) {
            openPastConfirm(pastEdits);
        } else {
            performSave();
        }
    });

    /**
     * Server returned a non-empty `skipped` list — show admin exactly which
     * staff members didn't sync and why, instead of a "Saved" toast hiding
     * silent Firestore failures.
     */
    function reasonText(reason) {
        switch (reason) {
            case 'firestore_write_failed':
                return 'Firestore write failed (network/permissions — retry, or check server logs).';
            case 'invalid_id_format':
                return 'Invalid staff-ID format.';
            default:
                return reason || 'Unknown reason.';
        }
    }

    function showSkippedModal(skipped, savedCount) {
        elPCTitle.textContent = (savedCount > 0)
            ? ('Saved ' + savedCount + ' · ' + skipped.length + ' could NOT be saved')
            : (skipped.length + ' staff member' + (skipped.length === 1 ? '' : 's') + ' could NOT be saved');

        var rows = '';
        skipped.forEach(function(s) {
            rows += '<div class="att-modal-stat" style="align-items:flex-start;">'
                  +   '<span><strong>' + esc(s.name || s.staffId) + '</strong>'
                  +     '<div style="font-size:11px;color:var(--att-text3);margin-top:2px;">' + esc(s.staffId) + '</div></span>'
                  +   '<span style="color:var(--att-a);font-size:11px;max-width:55%;text-align:right;line-height:1.3;">'
                  +     esc(reasonText(s.reason))
                  +   '</span>'
                  + '</div>';
        });

        elPCBody.innerHTML =
            '<p style="font-family:var(--att-font);font-size:13px;color:var(--att-text2);margin:0 0 14px 0;">'
          + 'Their marks are still highlighted — fix the underlying records, then Save again. '
          + 'The other ' + savedCount + ' mark' + (savedCount === 1 ? '' : 's') + ' synced successfully.</p>'
          + rows;

        elPCNext.innerHTML = 'OK';
        elPCNext.onclick = closePastConfirm;
        elPC.classList.add('open');
    }

    /* ── Warn on Leave ── */
    window.addEventListener('beforeunload', function(e) {
        if (state.dirty.size > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    /* ── Auto-load on arrival (redesign) ──
     * The month <select> defaults to the current month (server-marked selected),
     * so the register + KPIs populate immediately — no "Load" click required.
     * Falls back gracefully: if no month is selectable, nothing fires. */
    if (elMonth && elMonth.value) {
        loadAttendance();
    }

    /* GAP-1 — which months of the session are closed. Independent of the
       register load: it is session-wide, one query, and a failure here must
       never hold up the month the operator actually asked for. */
    loadLockedMonths();

})();
</script>
