<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
    /* ── Access levels (defense-in-depth; controller remains source of truth) ──
     * In these marking views, marking/saving counts as EDIT.
     * MANAGE gates correction DECIDE/approve + lock (none inline here yet). */
    $att_can_edit   = function_exists('has_permission') ? has_permission('Attendance', 'edit')   : true;
    $att_can_manage = function_exists('has_permission') ? has_permission('Attendance', 'manage') : true;
?>

<!-- Attendance Design System (shared, cacheable) — see assets/css/attendance_design_system.css -->
<link rel="stylesheet" href="<?= base_url('assets/css/attendance_design_system.css') ?>?v=2.1.0">

<style>
/* ============================================================================
 * student.php — page-local: ONLY the register-specific attendance matrix.
 * All chrome (header, filter, buttons, metrics, legend, toast, modal, stage,
 * loading, empty) is consumed from the shared ADS above. The --sa-* tokens are
 * the register's status palette + theme aliases used by the matrix cells /
 * %-bars / avatars and by inline var(--sa-*) references in the markup + JS.
 * ========================================================================== */
:root {
    --sa-primary: var(--gold, #BC5A3C);
    --sa-primary-dim: var(--gold-dim, rgba(188,90,60,.10));
    --sa-primary-ring: var(--gold-ring, rgba(188,90,60,.22));
    --sa-bg: var(--bg, #F7F4F1);
    --sa-bg2: var(--bg2, #ffffff);
    --sa-bg3: var(--bg3, #F7ECE7);
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

/* Chrome (header, filter, buttons, metrics, toolbar, legend) now lives in the
   shared Attendance Design System — see the <link> above. */

/* ── Attendance Grid (register-specific matrix) ── */
.sa-grid-wrap {
    border-radius:var(--sa-r); background:var(--sa-card);
    border:1px solid var(--sa-border); box-shadow:var(--sa-shadow);
    overflow:auto; max-height:calc(100vh - 280px);
    scrollbar-width:thin; scrollbar-color:var(--sa-border) transparent;
}
.sa-grid-wrap::-webkit-scrollbar { width:6px; height:6px; }
.sa-grid-wrap::-webkit-scrollbar-thumb { background:var(--sa-border); border-radius:3px; }

.sa-grid {
    /* fixed layout → every column takes its defined <th> width for BOTH header
       and body, so the date headers always sit exactly above their marks. */
    table-layout:fixed;
    width:max-content; min-width:100%; border-collapse:separate;
    border-spacing:0; font-family:var(--sa-font);
}

/* Frozen first columns disabled — position:sticky on table cells mis-sizes in
   Safari inside a scroll container and drifts the columns out of alignment. The
   first two columns keep a solid background but scroll with the grid. */
.sa-grid td:nth-child(1),
.sa-grid td:nth-child(2) { background:var(--sa-card); }

/* Header is intentionally NOT position:sticky — Safari mis-sizes sticky
   table-header cells in a scroll container, drifting the date headers off the
   marks. A plain header shares the body's fixed column grid, so it stays aligned. */
.sa-grid thead th { position:static; }
.sa-grid thead th:nth-child(1),
.sa-grid thead th:nth-child(2) { z-index:15; }

.sa-grid th {
    background:var(--sa-bg3); color:var(--sa-t3); font-size:10px;
    font-weight:700; text-transform:uppercase; letter-spacing:.5px;
    padding:11px 6px; text-align:center; white-space:nowrap;
    border-bottom:2px solid var(--sa-border);
}
.sa-grid th.sa-th-idx { width:42px; text-align:center; padding-left:10px; }
.sa-grid th.sa-th-student { text-align:left; padding-left:14px; width:200px; }
.sa-grid th.sa-th-day { width:38px; }
.sa-grid th.sa-th-pct { width:112px; }
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
.sa-avatar.av-1 { background:#BC5A3C; }
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
/* Future days: not yet editable. Kept visually uniform with the rest of the
   grid — only the date number is muted and the marks are lightly dimmed, so the
   future half doesn't read as a washed-out / broken block. */
.sa-grid th.sa-col-future,
.sa-grid td.sa-col-future {
    color:var(--sa-t3);
}
.sa-grid td.sa-col-future .sa-cell { cursor:not-allowed; opacity:.5; }
.sa-grid td.sa-col-future .sa-cell:hover { transform:none; }
/* Sundays and Holidays are also locked — same cursor + dim treatment. The
   existing red/purple column tint stays so the reason is still legible. */
.sa-grid td.sa-col-sun .sa-cell,
.sa-grid td.sa-col-hol .sa-cell { cursor:not-allowed; opacity:.55; }
.sa-grid td.sa-col-sun .sa-cell:hover,
.sa-grid td.sa-col-hol .sa-cell:hover { transform:none; }

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

/* Toast, modal, loading, and empty-state styles now live in the shared ADS. */

/* ── Sticky action/save bar — stays in view while the register scrolls ── */
#attToolbar { position:sticky; top:0; z-index:20; }
/* Unsaved-changes affordance: amber ring on the action bar until saved. */
#attToolbar.att-has-unsaved {
    border-color:var(--sa-l);
    box-shadow:inset 0 0 0 1px var(--sa-l), var(--sa-shadow);
}

/* ── Keyboard focus — visible ring on the focused mark cell ── */
.sa-cell:focus-visible { outline:2px solid var(--sa-primary); outline-offset:1px; }

/* ── Read-only (view-level) — grid is shown but not interactive ── */
.sa-grid-wrap.att-readonly .sa-cell { cursor:default; }
.sa-grid-wrap.att-readonly .sa-cell:hover { transform:none; box-shadow:none; }

/* ── Responsive (matrix only) ── */
@media (max-width:767px) {
    .sa-grid th.sa-th-student { width:140px; }
    .sa-grid th:nth-child(2),
    .sa-grid td:nth-child(2) { left:36px; }
    .sa-grid th:nth-child(1) { width:36px; }
}
</style>

<!-- Top progress bar — driven by attBusyStart/attBusyEnd on every request -->
<div class="att-loadbar" id="attLoadbar"></div>
<script>
    /* Shared busy counter: any in-flight fetch turns the top loadbar on; it
       clears only when the LAST request settles (success OR error). Defined at
       page scope so BOTH the register IIFE (postData) and the stage/correction
       IIFE below drive the same bar. */
    var _attBusy = 0;
    function attBusyStart(){ _attBusy++; var b=document.getElementById('attLoadbar'); if(b)b.classList.add('on'); }
    function attBusyEnd(){ _attBusy=Math.max(0,_attBusy-1); if(!_attBusy){ var b=document.getElementById('attLoadbar'); if(b)b.classList.remove('on'); } }
</script>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div class="att-header">
        <div class="att-header-left">
            <div class="att-header-icon"><i class="fa fa-calendar-check-o"></i></div>
            <div>
                <div class="att-page-title">Student Attendance</div>
                <div class="att-subtitle">Mark and manage daily student attendance by class &amp; section</div>
                <ul class="att-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
                    <li><a href="<?= base_url('attendance') ?>">Attendance</a></li>
                    <li>Student Attendance</li>
                </ul>
            </div>
        </div>
        <span class="att-chip" id="saMonthBadge" style="display:none;">
            <i class="fa fa-calendar"></i> <span id="saMonthLabel"></span>
        </span>
    </div>

    <?php if (!$att_can_edit): ?>
    <!-- Read-only banner (view-level access) — data still shown; editing blocked -->
    <div class="att-alert att-alert-info show" style="margin-bottom:16px;">
        <i class="fa fa-eye"></i>
        <span><strong>View-only access</strong> — you can see attendance but can't mark or edit it.</span>
    </div>
    <?php endif; ?>

    <!-- Phase 2/3 — Stage + Lock + Corrections strip (att-stage-* from the shared ADS) -->
    <div class="att-stage-strip unknown" id="saStageStrip" data-current-month="0">
        <span class="att-stage-pill" id="saStagePill">—</span>
        <span class="att-stage-msg"  id="saStageMsg">Pick a class and section to see today's status.</span>
        <span class="att-corr-badge" id="saCorrBadge" style="display:none">
            <i class="fa fa-flag-checkered"></i> <span id="saCorrCount">0</span> pending
        </span>
        <span class="att-stage-spacer"></span>
        <a class="att-stage-link" id="saStageOpenPanel"
           href="<?= base_url('attendance/control') ?>" target="_blank">
            Open Control Panel <i class="fa fa-external-link"></i>
        </a>
    </div>

    <!-- Filter Card -->
    <div class="att-toolbar">
        <div class="att-fg">
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
        <div class="att-fg">
            <label for="attSection">Section</label>
            <select id="attSection" disabled>
                <option value="">Select Section</option>
            </select>
        </div>
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
        <div class="att-fg" style="align-self:flex-end;">
            <button type="button" class="att-btn att-btn-primary" id="attLoadBtn">
                <i class="fa fa-search"></i> Load Attendance
            </button>
        </div>
    </div>

    <!-- Stats Strip -->
    <div class="att-metric-grid" id="saStatsStrip" style="display:none;">
        <div class="att-metric">
            <div class="att-metric-num" style="color:var(--sa-t1)" id="ssTotalStudents">0</div>
            <div class="att-metric-label">Students</div>
        </div>
        <div class="att-metric">
            <div class="att-metric-num" style="color:var(--sa-p)" id="ssTotalPresent">0</div>
            <div class="att-metric-label">Total Present</div>
        </div>
        <div class="att-metric">
            <div class="att-metric-num" style="color:var(--sa-a)" id="ssTotalAbsent">0</div>
            <div class="att-metric-label">Total Absent</div>
        </div>
        <div class="att-metric">
            <div class="att-metric-num" style="color:var(--sa-l)" id="ssTotalLeave">0</div>
            <div class="att-metric-label">On Leave</div>
        </div>
        <div class="att-metric">
            <div class="att-metric-num" style="color:var(--sa-primary)" id="ssAvgPct">0%</div>
            <div class="att-metric-label">Avg Attendance</div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="att-toolbar" id="attToolbar" style="display:none;">
        <div class="att-toolbar-section">
            <button type="button" class="att-btn att-btn-primary att-btn-sm" id="attSaveBtn" disabled>
                <i class="fa fa-save"></i> Save Changes
            </button>
            <span id="saDirtyCount" style="font-family:var(--sa-font);font-size:11px;color:var(--sa-l);font-weight:700;display:none;">
                <i class="fa fa-pencil"></i> <em id="saDirtyNum">0</em> unsaved
            </span>
        </div>
        <div class="att-toolbar-divider"></div>
        <div class="att-toolbar-section">
            <label style="font-family:var(--sa-font);font-size:11px;font-weight:700;color:var(--sa-t3);letter-spacing:.5px;">DAY:</label>
            <input type="number" id="attDayPicker" class="att-day-input" min="1" max="31" value="1"<?= $att_can_edit ? '' : ' disabled' ?>>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="P"<?= $att_can_edit ? '' : ' disabled' ?>>
                <i class="fa fa-check"></i> All Present
            </button>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="A"<?= $att_can_edit ? '' : ' disabled' ?>>
                <i class="fa fa-times"></i> All Absent
            </button>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="H"<?= $att_can_edit ? '' : ' disabled' ?>>
                <i class="fa fa-star"></i> Holiday
            </button>
        </div>
        <div class="att-toolbar-divider"></div>
        <div class="att-legend">
            <span class="att-legend-chip"><span class="att-legend-pip" style="background:var(--sa-p);">P</span> Present</span>
            <span class="att-legend-chip"><span class="att-legend-pip" style="background:var(--sa-a);">A</span> Absent</span>
            <span class="att-legend-chip"><span class="att-legend-pip" style="background:var(--sa-l);">L</span> Leave</span>
            <span class="att-legend-chip"><span class="att-legend-pip" style="background:var(--sa-t);">T</span> Late</span>
            <span class="att-legend-chip"><span class="att-legend-pip" style="background:var(--sa-h);">H</span> Holiday</span>
            <span class="att-legend-chip"><span class="att-legend-pip" style="background:var(--sa-v);">V</span> Vacant</span>
        </div>
    </div>

    <!-- Loading -->
    <div class="att-loading" id="attLoading">
        <div class="att-loader">
            <span class="att-loader-ring"></span>
            <span class="att-loader-text">Loading attendance data&hellip;</span>
        </div>
    </div>

    <!-- Empty State -->
    <div class="att-empty" id="attEmpty" style="display:none;">
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
    <div class="att-modal-overlay" id="attModal">
        <div class="att-modal">
            <div class="att-modal-head">
                <h3 id="attModalTitle">Student Summary</h3>
                <button class="att-modal-close" id="attModalClose"><i class="fa fa-times"></i></button>
            </div>
            <div id="attModalBody"></div>
        </div>
    </div>

    <!-- Past-edit confirmation modal (two-step) -->
    <div class="att-modal-overlay" id="attPastConfirm">
        <div class="att-modal">
            <div class="att-modal-head">
                <h3 id="attPastConfirmTitle">Confirm edits to past dates</h3>
                <button class="att-modal-close" id="attPastConfirmClose"><i class="fa fa-times"></i></button>
            </div>
            <div id="attPastConfirmBody"></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="att-btn att-btn-outline att-btn-sm" id="attPastConfirmCancel">Cancel</button>
                <button type="button" class="att-btn att-btn-primary att-btn-sm" id="attPastConfirmNext">Continue</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div class="att-toast" id="attToast"></div>

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
     * Single source of truth for "can this day be edited?"
     * Returns the lock reason ('future' | 'sunday' | 'holiday') if locked,
     * otherwise null. Order matters: future wins over sunday/holiday so the
     * toast message stays accurate when both apply.
     *
     * Holidays are read live from state.holidays (refreshed every
     * loadAttendance), so any holiday added or edited via /attendance/settings
     * takes effect on the next Load click — no extra wiring needed.
     */
    function dateLockReason(day) {
        if (dayState(day) === 'future') return 'future';
        if (state.sundays && state.sundays.indexOf(day) !== -1) return 'sunday';
        // Use property-existence (not truthiness) so a holiday saved with an
        // empty name still locks the date.
        if (state.holidays && state.holidays[day] !== undefined) return 'holiday';
        return null;
    }

    function lockToastMessage(reason, day) {
        if (reason === 'future')  return 'Future dates cannot be marked.';
        if (reason === 'sunday')  return 'Sundays cannot be marked.';
        if (reason === 'holiday') {
            var label = (state.holidays && state.holidays[day]) ? state.holidays[day] : '';
            return 'Holiday (' + (label || 'declared') + ') — attendance cannot be marked.';
        }
        return 'This date is locked.';
    }

    function getInitials(name) {
        if (!name) return '?';
        var parts = name.trim().split(/\s+/);
        if (parts.length >= 2) return (parts[0][0] + parts[parts.length-1][0]).toUpperCase();
        return parts[0].substring(0,2).toUpperCase();
    }

    function showToast(msg, type) {
        elToast.innerHTML = '<i class="fa fa-' + (type === 'error' ? 'exclamation-circle' : 'check-circle') + '"></i> ' + esc(msg);
        elToast.className = 'att-toast ' + (type || 'success');
        setTimeout(function(){ elToast.classList.add('show'); }, 10);
        setTimeout(function(){ elToast.classList.remove('show'); }, 3000);
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
        elToolbar.style.display = 'none';
        elStatsStrip.style.display = 'none';
        elMonthBadge.style.display = 'none';
        elLoading.style.display = 'block';
        elLoadBtn.classList.add('is-loading');
        elLoadBtn.disabled = true;

        postData('attendance/fetch_student', { 'class': cls, section: sec, month: mon })
            .then(function(res) {
                elLoading.style.display = 'none';
                elLoadBtn.classList.remove('is-loading');
                elLoadBtn.disabled = false;
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

                /* Day-picker max: for the current calendar month, cap at today
                 * so bulk actions can't target future days. Past months stay
                 * fully spannable so admins can audit retroactively. */
                var now = new Date();
                var isCurrentMonth = (state.year === now.getFullYear() &&
                                      getMonthIndex(state.month) === now.getMonth());
                elDayPick.max = isCurrentMonth ? now.getDate() : state.daysInMonth;
                if (parseInt(elDayPick.value, 10) > parseInt(elDayPick.max, 10)) elDayPick.value = 1;

                /* Month badge */
                elMonthLabel.textContent = state.month + ' ' + state.year;
                elMonthBadge.style.display = 'inline-flex';

                renderGrid();
                updateStats();
                elGridWrap.style.display = 'block';
                elGridWrap.classList.toggle('att-readonly', !CAN_EDIT);
                elToolbar.style.display = 'flex';
                elStatsStrip.style.display = 'grid';
                updateSaveBtn();
            })
            .catch(function(e) {
                elLoading.style.display = 'none';
                elLoadBtn.classList.remove('is-loading');
                elLoadBtn.disabled = false;
                showToast((e && e.message) ? e.message : 'Network error loading attendance.', 'error');
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
            if (dayState(d) === 'future') cls += ' sa-col-future';
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
                if (dayState(day) === 'future') tdCls += ' sa-col-future';
                var dirtyMark = state.dirty.has(s.id) && att[d] !== state.original[s.id].charAt(d) ? ' sa-dirty' : '';
                var lateDot = v === 'T' ? '<span class="sa-late-dot"></span>' : '';
                // Only genuinely editable cells are keyboard-focusable.
                var editable = CAN_EDIT && !dateLockReason(day);
                var cellAttrs = editable ? ' tabindex="0" role="button" aria-label="Toggle mark, day ' + day + '"' : '';
                bHtml += '<td class="' + tdCls + '">';
                bHtml += '<span class="sa-cell' + dirtyMark + '" data-v="' + v + '" data-sid="' + esc(s.id) + '" data-d="' + d + '"' + cellAttrs + '>';
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
        // View-only: Save can never enable, and no "unsaved" affordance shows.
        if (!CAN_EDIT) {
            elSaveBtn.disabled = true;
            elDirtyCount.style.display = 'none';
            elToolbar.classList.remove('att-has-unsaved');
            return;
        }
        elSaveBtn.disabled = state.dirty.size === 0;
        if (state.dirty.size > 0) {
            elDirtyNum.textContent = state.dirty.size;
            elDirtyCount.style.display = 'inline';
            elToolbar.classList.add('att-has-unsaved');
        } else {
            elDirtyCount.style.display = 'none';
            elToolbar.classList.remove('att-has-unsaved');
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

    /* ── Cell toggle (click + keyboard) ── */
    function toggleCell(cell) {
        if (!cell) return;
        // View-only users can read the register but never mutate it.
        if (!CAN_EDIT) {
            showToast('View-only access — you can see attendance but can\'t mark or edit it.', 'error');
            return;
        }
        var sid = cell.getAttribute('data-sid');
        var d = parseInt(cell.getAttribute('data-d'), 10);
        var day = d + 1;
        // Block edits on future dates, Sundays, and declared holidays.
        var lock = dateLockReason(day);
        if (lock) {
            showToast(lockToastMessage(lock, day), 'error');
            return;
        }
        var curr = state.attendance[sid][d];
        state.attendance[sid][d] = nextMark(curr);
        updateCell(sid, d);
        markDirty(sid);
    }

    elBody.addEventListener('click', function(e) {
        toggleCell(e.target.closest('.sa-cell'));
    });
    // Keyboard-friendly: Enter / Space cycles the focused cell.
    elBody.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        var cell = e.target.closest('.sa-cell');
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
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-t2)"></span>Total Days</span><span>' + state.daysInMonth + '</span></div>';
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-p)"></span>Present</span><span style="color:var(--sa-p)">' + c.P + '</span></div>';
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-a)"></span>Absent</span><span style="color:var(--sa-a)">' + c.A + '</span></div>';
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-l)"></span>Leave</span><span style="color:var(--sa-l)">' + c.L + '</span></div>';
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-t)"></span>Late</span><span style="color:var(--sa-t)">' + c.T + '</span></div>';
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-h)"></span>Holiday</span><span style="color:var(--sa-h)">' + c.H + '</span></div>';
        html += '<div class="att-modal-stat"><span><span class="att-modal-stat-dot" style="background:var(--sa-v)"></span>Vacant</span><span style="color:var(--sa-v)">' + c.V + '</span></div>';
        html += '<div class="att-modal-stat" style="font-weight:700;border-bottom:none;padding-top:14px;"><span>Attendance %</span><span style="font-size:18px;color:' + (pct >= 75 ? 'var(--sa-p)' : pct >= 50 ? 'var(--sa-l)' : 'var(--sa-a)') + '">' + pct + '%</span></div>';

        var total = c.P + c.A + c.L + c.T + c.H + c.V;
        html += '<div class="att-modal-bar-wrap">';
        if (total > 0) {
            var segments = [
                {v:c.P, color:'var(--sa-p)'}, {v:c.T, color:'var(--sa-t)'},
                {v:c.L, color:'var(--sa-l)'}, {v:c.A, color:'var(--sa-a)'},
                {v:c.H, color:'var(--sa-h)'}, {v:c.V, color:'var(--sa-v)'}
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
            if (!CAN_EDIT) {
                showToast('View-only access — bulk marking is disabled.', 'error');
                return;
            }
            var mark = btn.getAttribute('data-bulk');
            var day = parseInt(elDayPick.value, 10);
            if (day < 1 || day > state.daysInMonth) {
                showToast('Invalid day number.', 'error');
                return;
            }
            // Block bulk on future dates, Sundays, and declared holidays.
            // Sundays/holidays are already off-days — re-stamping them
            // would either be a no-op or override the school calendar.
            var lock = dateLockReason(day);
            if (lock) {
                showToast(lockToastMessage(lock, day), 'error');
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
    /**
     * Walk the dirty set and collect every (studentId, day) cell whose value
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
                    var stu = null;
                    for (var k = 0; k < state.students.length; k++) {
                        if (state.students[k].id === sid) { stu = state.students[k]; break; }
                    }
                    edits.push({ sid: sid, name: stu ? stu.name : sid, day: i + 1 });
                }
            }
        });
        return edits;
    }

    function performSave() {
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
        elSaveBtn.classList.add('is-loading');

        postData('attendance/save_student', {
            'class': cls,
            section: sec,
            month: mon,
            attendance: JSON.stringify(attObj),
            late: JSON.stringify(lateObj)
        })
        .then(function(res) {
            elSaveBtn.classList.remove('is-loading');
            if (res && res.status === 'success') {
                // Strip the rejected students from the local dirty set so
                // their unsaved edits stay highlighted; only commit the
                // ones the server accepted to `state.original`.
                var skipped = Array.isArray(res.skipped) ? res.skipped : [];
                var savedCount = (typeof res.saved === 'number') ? res.saved : 0;
                var skippedIds = {};
                skipped.forEach(function(s){ if (s && s.studentId) skippedIds[s.studentId] = true; });

                state.dirty.forEach(function(sid) {
                    if (!skippedIds[sid]) {
                        state.original[sid] = state.attendance[sid].join('');
                    }
                });
                // Keep skipped sids in `state.dirty` so the user can fix them
                state.dirty = new Set(Object.keys(skippedIds));
                updateSaveBtn();
                // Remove dirty highlights only on rows the server saved
                elBody.querySelectorAll('.sa-dirty').forEach(function(c){
                    var sid = c.getAttribute('data-sid');
                    if (sid && !skippedIds[sid]) c.classList.remove('sa-dirty');
                });

                if (skipped.length > 0) {
                    showSkippedModal(skipped, savedCount);
                } else {
                    showToast('Attendance saved successfully!', 'success');
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
    var pcStep = 0;   // 0 = idle, 1 = list shown, 2 = "absolutely sure" shown

    function closePastConfirm() {
        elPC.classList.remove('open');
        pcStep = 0;
    }
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
                  + '<span style="color:var(--sa-a);font-weight:700">' + names.length + ' student' + (names.length === 1 ? '' : 's') + '</span></div>';
        });
        elPCTitle.textContent = 'Confirm edits to past dates';
        elPCBody.innerHTML =
            '<p style="font-family:var(--sa-font);font-size:13px;color:var(--sa-t2);margin:0 0 14px 0;">'
          + 'You are about to modify attendance for <strong>' + edits.length + ' past-date entr' + (edits.length === 1 ? 'y' : 'ies') + '</strong>. '
          + 'Past-date changes are visible to parents immediately and recorded in the audit log.</p>'
          + rows;
        elPCNext.innerHTML = 'Continue';
        elPCNext.onclick = function(){ openPastConfirmStep2(edits); };
        pcStep = 1;
        elPC.classList.add('open');
    }

    function openPastConfirmStep2(edits) {
        // Step 2 — final "are you absolutely sure?" gate before POST.
        elPCTitle.textContent = 'Are you absolutely sure?';
        elPCBody.innerHTML =
            '<p style="font-family:var(--sa-font);font-size:13px;color:var(--sa-t2);margin:0 0 8px 0;">'
          + 'This will overwrite <strong>' + edits.length + '</strong> past-date attendance mark' + (edits.length === 1 ? '' : 's') + '. '
          + 'There is no undo — only another correction will reverse it.</p>'
          + '<p style="font-family:var(--sa-font);font-size:12px;color:var(--sa-t3);margin:0;">Click <strong>Yes, save changes</strong> to proceed, or Cancel to review.</p>';
        elPCNext.innerHTML = '<i class="fa fa-check"></i> Yes, save changes';
        elPCNext.onclick = function(){
            closePastConfirm();
            performSave();
        };
        pcStep = 2;
    }

    elSaveBtn.addEventListener('click', function() {
        if (!CAN_EDIT) return;   // hardened: view-only can never save
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
     * students didn't sync and why. This is what surfaces the silent-skip
     * bug class (e.g., student's Firestore doc has Status='Active' in legacy
     * PascalCase shape and got dropped by the roster gate). Without this
     * modal the admin would see "Saved" toast and discover hours later that
     * parent/teacher views are empty for those students.
     */
    function reasonText(reason) {
        switch (reason) {
            case 'not_in_active_roster':
                return 'Not in active roster (check that the student\'s Firestore profile has status=Active and matches this Class/Section).';
            case 'firestore_write_failed':
                return 'Firestore write failed (network/permissions — retry, or check server logs).';
            case 'invalid_id_format':
                return 'Invalid student-ID format.';
            default:
                return reason || 'Unknown reason.';
        }
    }

    function showSkippedModal(skipped, savedCount) {
        elPCTitle.textContent = (savedCount > 0)
            ? ('Saved ' + savedCount + ' · ' + skipped.length + ' could NOT be saved')
            : (skipped.length + ' student' + (skipped.length === 1 ? '' : 's') + ' could NOT be saved');

        var rows = '';
        skipped.forEach(function(s) {
            rows += '<div class="att-modal-stat" style="align-items:flex-start;">'
                  +   '<span><strong>' + esc(s.name || s.studentId) + '</strong>'
                  +     '<div style="font-size:11px;color:var(--sa-t3);margin-top:2px;">' + esc(s.studentId) + '</div></span>'
                  +   '<span style="color:var(--sa-a);font-size:11px;max-width:55%;text-align:right;line-height:1.3;">'
                  +     esc(reasonText(s.reason))
                  +   '</span>'
                  + '</div>';
        });

        elPCBody.innerHTML =
            '<p style="font-family:var(--sa-font);font-size:13px;color:var(--sa-t2);margin:0 0 14px 0;">'
          + 'Their marks are still highlighted (yellow) — fix the underlying student records, then Save again. '
          + 'The other ' + savedCount + ' mark' + (savedCount === 1 ? '' : 's') + ' synced successfully to parent and teacher apps.</p>'
          + rows;

        // Single-button mode: just dismiss.
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

})();
</script>

<!-- Phase 2/3 — Stage + Lock + Pending corrections (read-only banner) -->
<script>
(function(){
    'use strict';
    var BASE = '<?= base_url() ?>';
    var TODAY = new Date().toISOString().slice(0,10);
    var TODAY_MONTH = (new Date().toLocaleString('en-US', { month: 'long' })) + ' ' + new Date().getFullYear();

    var $strip   = document.getElementById('saStageStrip');
    var $pill    = document.getElementById('saStagePill');
    var $msg     = document.getElementById('saStageMsg');
    var $corr    = document.getElementById('saCorrBadge');
    var $corrN   = document.getElementById('saCorrCount');
    var $link    = document.getElementById('saStageOpenPanel');
    var $cls     = document.getElementById('attClass');
    var $sec     = document.getElementById('attSection');
    var $month   = document.getElementById('attMonth');
    if (!$strip || !$cls || !$sec || !$month) return;

    function setStage(stage, lock) {
        $strip.className = 'att-stage-strip visible';
        var cls = 'unknown', text = 'Status unavailable.';
        switch (stage) {
            case 'S1_FREE':
                cls = 's1';
                text = 'Open — free edit. Reason not required until 10:30 AM.';
                $link.className = 'att-stage-link';
                $link.textContent = 'Open Control Panel ↗';
                break;
            case 'S2_RESTRICTED':
                cls = 's2';
                if (lock && lock.unlockedAt) {
                    text = 'Admin-unlocked window. Reason required on every save.';
                } else {
                    text = 'Restricted edit window (10:30 AM – 6 PM). Reason required.';
                }
                $link.className = 'att-stage-link warn';
                $link.textContent = 'Open Control Panel ↗';
                break;
            case 'S3_LOCKED':
                cls = 's3';
                if (lock && lock.locked && lock.lockedBy) {
                    text = 'Locked by ' + lock.lockedBy + (lock.lockReason ? ' — ' + lock.lockReason : '') + '. File a correction request.';
                } else {
                    text = 'Locked. Direct edits rejected; use the correction flow.';
                }
                $link.className = 'att-stage-link danger';
                $link.textContent = 'Request Correction ↗';
                $link.href = BASE + 'attendance/approvals#corrections';
                break;
            default:
                cls = 'unknown';
                text = 'Stage unknown.';
        }
        $strip.className = 'att-stage-strip visible ' + cls;
        $pill.textContent = (stage || '—').replace('_', ' ');
        $msg.textContent  = text;
    }

    function setCorrCount(n) {
        if (n > 0) {
            $corr.style.display = '';
            $corrN.textContent  = n;
        } else {
            $corr.style.display = 'none';
        }
    }

    function isCurrentMonth() {
        var picked = ($month && $month.value) || '';
        if (!picked) return false;
        // Existing dropdown values are month names; current year is implicit.
        // Also accept "Month YYYY" labels.
        var nowMonth = new Date().toLocaleString('en-US', { month: 'long' });
        return picked === nowMonth || picked === TODAY_MONTH;
    }

    function refresh() {
        var c = $cls.value, s = $sec.value;
        if (!c || !s) {
            $strip.className = 'att-stage-strip unknown visible';
            $pill.textContent = '—';
            $msg.textContent  = 'Pick a class and section to see today\'s status.';
            setCorrCount(0);
            return;
        }
        if (!isCurrentMonth()) {
            // Past/future month — strip stays minimal; today's lock isn't relevant
            $strip.className = 'att-stage-strip unknown visible';
            $pill.textContent = 'Past view';
            $msg.textContent  = 'Stage gate applies only to today. Past corrections go through Control Panel.';
            setCorrCount(0);
            return;
        }
        // Fetch lock + stage for today
        attBusyStart();
        fetch(BASE + 'attendance/lock?class=' + encodeURIComponent(c)
                  + '&section=' + encodeURIComponent(s)
                  + '&date='    + encodeURIComponent(TODAY),
              { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(j){
                if (!j || j.status !== 'success') return setStage(null, null);
                setStage(j.stage, j.lock || {});
            })
            .catch(function(){ setStage(null, null); })
            .finally(function(){ attBusyEnd(); });

        // Fetch pending correction count for this date
        attBusyStart();
        fetch(BASE + 'attendance/correction/list?status=pending&date=' + encodeURIComponent(TODAY) + '&limit=100',
              { credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(j){
                var n = (j && j.requests) ? j.requests.length : 0;
                setCorrCount(n);
            })
            .catch(function(){ setCorrCount(0); })
            .finally(function(){ attBusyEnd(); });
    }

    $cls.addEventListener('change',  refresh);
    $sec.addEventListener('change',  refresh);
    $month.addEventListener('change', refresh);
    // Initial state
    refresh();
})();
</script>
