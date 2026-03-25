<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html{font-size:16px !important}

/* ── Layout ── */
.ac-wrap{padding:24px 22px 52px;min-height:100vh}

/* ── Header ── */
.ac-head{display:flex;align-items:center;gap:14px;padding:18px 22px;margin-bottom:22px;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r,10px);box-shadow:var(--sh)}
.ac-head-icon{width:44px;height:44px;border-radius:10px;background:var(--gold);display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 0 18px var(--gold-glow)}
.ac-head-icon i{color:#fff;font-size:18px}
.ac-head-info{flex:1}
.ac-head-title{font-size:18px;font-weight:700;color:var(--t1);font-family:var(--font-d)}
.ac-head-sub{font-size:12px;color:var(--t3);margin-top:2px}

/* ── Tabs ── */
.ac-tabs{display:flex;gap:4px;margin-bottom:22px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:6px;box-shadow:var(--sh);flex-wrap:wrap}
.ac-tab{padding:9px 18px;border-radius:7px;font-size:12.5px;font-weight:600;cursor:pointer;color:var(--t2);transition:all .15s;display:flex;align-items:center;gap:7px;font-family:var(--font-b);user-select:none}
.ac-tab:hover{background:var(--bg3);color:var(--t1)}
.ac-tab.active{background:var(--gold);color:#fff}
.ac-tab i{font-size:13px}

/* ── Panes ── */
.ac-pane{display:none}
.ac-pane.active{display:block}

/* ── Cards / Panels ── */
.ac-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;box-shadow:var(--sh);margin-bottom:18px;overflow:hidden}
.ac-card-hd{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ac-card-hd h3{margin:0;font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.ac-card-body{padding:18px}

/* ── Form Groups ── */
.ac-fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
.ac-fg label{font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.ac-fg input,.ac-fg select,.ac-fg textarea{padding:8px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:13px;font-family:var(--font-b);outline:none;transition:border-color .15s}
.ac-fg input:focus,.ac-fg select:focus,.ac-fg textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}
.ac-fg textarea{resize:vertical;min-height:50px}
.ac-row{display:flex;gap:12px;flex-wrap:wrap}
.ac-row .ac-fg{flex:1;min-width:140px}

/* ── Buttons ── */
.ac-btn{padding:8px 18px;border:none;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:var(--font-b);transition:all .15s;display:inline-flex;align-items:center;gap:7px}
.ac-btn-p{background:var(--gold);color:#fff}
.ac-btn-p:hover{background:var(--gold2)}
.ac-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}
.ac-btn-s:hover{border-color:var(--gold);color:var(--gold)}
.ac-btn-d{background:transparent;color:#dc2626;border:1px solid #fca5a5}
.ac-btn-d:hover{background:#fee2e2}
.ac-btn:disabled{opacity:.5;cursor:not-allowed}
.ac-btn-sm{padding:5px 12px;font-size:11px}

/* ── Tables ── */
.ac-table{width:100%;border-collapse:collapse;font-size:13px}
.ac-table th{background:var(--bg3);color:var(--t3);font-family:var(--font-m);padding:9px 12px;text-align:left;border-bottom:1px solid var(--border);font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;position:sticky;top:0;z-index:1}
.ac-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);vertical-align:middle}
.ac-table tr:last-child td{border-bottom:none}
.ac-table tr:hover td{background:var(--gold-dim)}

/* ── Badges ── */
.ac-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:10.5px;font-weight:700;letter-spacing:.2px}
.ac-badge-ns{background:var(--bg3);color:var(--t3)}
.ac-badge-ip{background:rgba(234,179,8,.12);color:#a16207}
.ac-badge-done{background:rgba(22,163,74,.12);color:#16a34a}
.ac-badge-hol{background:rgba(220,38,38,.12);color:#dc2626}
.ac-badge-exam{background:rgba(37,99,235,.12);color:#2563eb}
.ac-badge-meet{background:rgba(234,88,12,.12);color:#ea580c}
.ac-badge-event{background:rgba(15,118,110,.12);color:var(--gold)}
.ac-badge-act{background:rgba(139,92,246,.12);color:#8b5cf6}
.ac-badge-asgn{background:rgba(37,99,235,.12);color:#2563eb}
.ac-badge-comp{background:rgba(22,163,74,.12);color:#16a34a}
.ac-badge-canc{background:var(--bg3);color:var(--t3)}

/* ── Timetable Grid ── */
.ac-tt-grid{overflow-x:auto;margin-top:12px}
.ac-tt{width:100%;border-collapse:collapse;font-size:12px;min-width:700px}
.ac-tt th,.ac-tt td{border:1px solid var(--border);text-align:center;padding:6px 4px;min-width:80px}
.ac-tt th{background:var(--bg3);color:var(--t2);font-family:var(--font-m);font-size:10px;text-transform:uppercase;letter-spacing:.3px;position:sticky;top:0;z-index:2}
.ac-tt th:first-child{position:sticky;left:0;z-index:3;background:var(--bg3)}
.ac-tt th .ac-th-time{display:block;font-size:8.5px;font-weight:400;color:var(--t3);text-transform:none;letter-spacing:0;margin-top:1px}
.ac-tt td:first-child{position:sticky;left:0;z-index:1;background:var(--bg2);font-weight:700;font-size:11px;color:var(--t2);white-space:nowrap}
.ac-tt td{cursor:pointer;transition:background .12s;min-height:32px}
.ac-tt td:hover{background:var(--gold-dim)}
.ac-tt td.ac-recess{background:var(--bg3) !important;color:var(--t3);font-style:italic;cursor:default;font-size:10px}
.ac-tt td.ac-empty-cell{background:rgba(217,119,6,.04)}
.ac-tt-cell{font-size:11px;line-height:1.3;color:var(--t1);padding:2px 3px;border-radius:3px}
.ac-tt-cell small{display:block;font-size:9px;color:var(--t3)}
.ac-tt-cell.has-sub{border-left:3px solid var(--sub-color,var(--gold))}
.ac-tt tr.ac-row-incomplete td:first-child{border-left:3px solid #d97706}
/* Conflict indicators */
.ac-tt td.ac-conflict{box-shadow:inset 0 0 0 2px #dc2626 !important;background:rgba(220,38,38,.06) !important}
.ac-tt td.ac-conflict-warn{box-shadow:inset 0 0 0 2px #d97706 !important;background:rgba(217,119,6,.06) !important}
.ac-conflict-icon{display:inline-block;width:14px;height:14px;line-height:14px;text-align:center;border-radius:50%;font-size:8px;font-weight:700;color:#fff;position:absolute;top:1px;right:1px}
.ac-conflict-icon.err{background:#dc2626}
.ac-conflict-icon.warn{background:#d97706}
.ac-tt td{position:relative}
.ac-conflict-bar{padding:8px 14px;border-radius:8px;margin-bottom:12px;font-size:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ac-conflict-bar.has-errors{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.25);color:#dc2626}
.ac-conflict-bar.has-warnings{background:rgba(217,119,6,.08);border:1px solid rgba(217,119,6,.25);color:#92400e}
.ac-conflict-bar.clean{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.25);color:#16a34a}
.ac-edit-warn{padding:4px 6px;border-radius:4px;font-size:10px;font-weight:600;margin-top:2px;line-height:1.3}
.ac-edit-warn.err{background:rgba(220,38,38,.1);color:#dc2626;border:1px solid rgba(220,38,38,.2)}
.ac-edit-warn.warn{background:rgba(217,119,6,.1);color:#92400e;border:1px solid rgba(217,119,6,.2)}
/* Workload Analytics */
.ac-wl-stat{flex:1;min-width:110px;padding:14px 16px;background:var(--bg3);border-radius:8px;border:1px solid var(--border);text-align:center}
.ac-wl-stat-n{font-size:22px;font-weight:700;color:var(--t1);font-family:var(--font-d)}
.ac-wl-stat-l{font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);margin-top:2px}
.ac-wl-ok{color:#16a34a !important}
.ac-wl-over{color:#dc2626 !important}
.ac-wl-free{color:var(--t3) !important}
.ac-wl-bar{height:6px;background:var(--bg3);border-radius:3px;overflow:hidden;min-width:80px}
.ac-wl-bar-fill{height:100%;border-radius:3px;transition:width .3s ease}
.ac-wl-bar-fill.ok{background:#16a34a}
.ac-wl-bar-fill.warn{background:#d97706}
.ac-wl-bar-fill.over{background:#dc2626}
.ac-table tr.ac-wl-row-over td{background:rgba(220,38,38,.04)}
.ac-table tr.ac-wl-row-over td:first-child{border-left:3px solid #dc2626}
.ac-table tr.ac-wl-row-warn td{background:rgba(217,119,6,.04)}
.ac-table tr.ac-wl-row-warn td:first-child{border-left:3px solid #d97706}
.ac-wl-day-cell{font-size:11px;text-align:center;font-weight:600;min-width:36px}
.ac-wl-day-cell.heavy{color:#dc2626}
.ac-wl-chips{display:flex;gap:4px;flex-wrap:wrap}
.ac-wl-chip{padding:1px 7px;border-radius:10px;font-size:10px;font-weight:600;background:var(--bg3);color:var(--t2);border:1px solid var(--border);white-space:nowrap}
/* Full-week view */
.ac-tt-week{width:100%;border-collapse:collapse;font-size:11px;min-width:500px}
.ac-tt-week th,.ac-tt-week td{border:1px solid var(--border);text-align:center;padding:5px 4px}
.ac-tt-week th{background:var(--bg3);color:var(--t2);font-family:var(--font-m);font-size:10px;text-transform:uppercase;position:sticky;top:0;z-index:2}
.ac-tt-week td{font-size:10.5px;min-width:55px}
.ac-tt-week td.ac-recess{background:var(--bg3) !important;color:var(--t3);font-size:9px;font-style:italic}
/* Print styles */
@media print{
    .ac-head,.ac-tabs,.ac-card-hd,.ac-day-tabs,.ac-pills,#ttViewToggle,#ttClassFilter,#ttFillRate,#ttLegend,button,.ac-toast,.content-header,.main-sidebar,.main-footer{display:none !important}
    .content-wrapper{margin-left:0 !important;padding:0 !important}
    .ac-wrap{padding:10px !important}
    .ac-card{border:none !important;box-shadow:none !important}
    .ac-tt,.ac-tt-week{font-size:10px !important}
    .ac-tt th,.ac-tt td,.ac-tt-week th,.ac-tt-week td{padding:3px 2px !important;border-color:#ccc !important}
    #ttPrintHeader{display:block !important;text-align:center;margin-bottom:12px}
    #ttPrintHeader h2{margin:0;font-size:16px}
    #ttPrintHeader p{margin:2px 0;font-size:11px;color:#666}
}

/* ── Calendar ── */
.ac-cal-nav{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.ac-cal-nav button{background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:6px 12px;cursor:pointer;color:var(--t2);font-size:13px}
.ac-cal-nav button:hover{border-color:var(--gold);color:var(--gold)}
.ac-cal-month{font-size:16px;font-weight:700;color:var(--t1);font-family:var(--font-d);min-width:180px;text-align:center}
.ac-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.ac-cal-hd{padding:8px 4px;text-align:center;font-size:10px;font-weight:700;color:var(--t3);text-transform:uppercase;font-family:var(--font-m)}
.ac-cal-day{min-height:80px;background:var(--bg2);border:1px solid var(--border);border-radius:6px;padding:4px 6px;cursor:pointer;transition:border-color .12s;position:relative}
.ac-cal-day:hover{border-color:var(--gold)}
.ac-cal-day.today{border-color:var(--gold);box-shadow:0 0 0 2px var(--gold-ring)}
.ac-cal-day.other{opacity:.35}
.ac-cal-day .num{font-size:11px;font-weight:700;color:var(--t2);margin-bottom:2px}
.ac-cal-dot{width:100%;padding:1px 3px;border-radius:3px;font-size:9px;font-weight:600;margin-bottom:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.ac-cal-dot.holiday{background:rgba(220,38,38,.15);color:#dc2626}
.ac-cal-dot.exam{background:rgba(37,99,235,.15);color:#2563eb}
.ac-cal-dot.meeting{background:rgba(234,88,12,.15);color:#ea580c}
.ac-cal-dot.event{background:rgba(15,118,110,.15);color:var(--gold)}
.ac-cal-dot.activity{background:rgba(139,92,246,.15);color:#8b5cf6}

/* ── Progress Bar ── */
.ac-progress{height:6px;background:var(--bg3);border-radius:3px;overflow:hidden;margin:8px 0}
.ac-progress-bar{height:100%;background:var(--gold);border-radius:3px;transition:width .3s ease}

/* ── Inline Form (slide-down, no modal) ── */
.ac-inline-form{display:none;padding:16px 18px;border-top:1px solid var(--border);background:var(--bg3)}
.ac-inline-form.show{display:block}

/* ── Filter Pills ── */
.ac-pills{display:flex;gap:6px;flex-wrap:wrap}
.ac-pill{padding:4px 12px;border-radius:14px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--t2);font-family:var(--font-b)}
.ac-pill:hover{border-color:var(--gold);color:var(--gold)}
.ac-pill.active{background:var(--gold-dim);border-color:var(--gold);color:var(--gold)}

/* ── Empty State ── */
.ac-empty{text-align:center;padding:50px 20px;color:var(--t3)}
.ac-empty i{font-size:2.2rem;display:block;margin-bottom:10px;opacity:.4}

/* ── Day Tabs (Timetable) ── */
.ac-day-tabs{display:flex;gap:4px;margin-bottom:14px;flex-wrap:wrap}
.ac-day-tab{padding:6px 14px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;background:var(--bg3);color:var(--t2);border:1px solid var(--border);font-family:var(--font-m)}
.ac-day-tab:hover{border-color:var(--gold);color:var(--gold)}
.ac-day-tab.active{background:var(--gold);color:#fff;border-color:var(--gold)}

/* ── Toast ── */
.ac-toast{position:fixed;bottom:24px;right:24px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;box-shadow:0 8px 24px rgba(0,0,0,.2);transform:translateY(80px);opacity:0;transition:all .3s ease;pointer-events:none}
.ac-toast.show{transform:translateY(0);opacity:1}
.ac-toast.ok{background:#16a34a}
.ac-toast.err{background:#dc2626}

@media(max-width:767px){
    .ac-head{flex-wrap:wrap}
    .ac-tabs{gap:2px;padding:4px}
    .ac-tab{padding:7px 12px;font-size:11px}
    .ac-row{flex-direction:column}
    .ac-cal-grid{font-size:10px}
    .ac-cal-day{min-height:60px}
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <!-- Header -->
    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-university"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Academic Management</div>
            <div class="ac-head-sub"><?= htmlspecialchars($school_name ?? '') ?> — Session <?= htmlspecialchars($session_year ?? '') ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="ac-tabs" id="acTabs">
        <div class="ac-tab active" data-tab="subjects"><i class="fa fa-book"></i> Subject Assignment</div>
        <div class="ac-tab" data-tab="scheduling"><i class="fa fa-clock-o"></i> Period Scheduling</div>
        <div class="ac-tab" data-tab="curriculum"><i class="fa fa-list-ol"></i> Curriculum</div>
        <div class="ac-tab" data-tab="calendar"><i class="fa fa-calendar"></i> Calendar</div>
        <div class="ac-tab" data-tab="timetable"><i class="fa fa-th"></i> Master Timetable</div>
        <div class="ac-tab" data-tab="substitutes"><i class="fa fa-exchange"></i> Substitutes</div>
        <div class="ac-tab" data-tab="workload"><i class="fa fa-bar-chart"></i> Teacher Workload</div>
    </div>

    <!-- ═══════════ TAB 1: SUBJECT ASSIGNMENT ═══════════ -->
    <div class="ac-pane active" id="pane-subjects">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-book" style="color:var(--gold);margin-right:6px"></i>Subject Assignment</h3>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select id="saClassSelect" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:12px;min-width:180px">
                        <option value="">Select Class...</option>
                    </select>
                    <?php if (in_array($admin_role ?? '', ['Admin', 'Principal', 'Super Admin', 'School Super Admin'])): ?>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.sa.copyFrom()" title="Copy assignments from another class"><i class="fa fa-copy"></i> Copy From</button>
                    <?php endif; ?>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.sa.load()"><i class="fa fa-refresh"></i> Refresh</button>
                </div>
            </div>
            <div class="ac-card-body">
                <div id="saContent">
                    <div class="ac-empty"><i class="fa fa-book"></i>Select a class to manage subject assignments</div>
                </div>

                <!-- Assign Form (shown when class selected) -->
                <div id="saAssignArea" style="display:none">
                    <!-- Summary bar -->
                    <div id="saSummary" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:14px;padding:10px 14px;background:var(--bg3);border-radius:8px;font-size:12px">
                        <span><strong id="saClassName" style="color:var(--t1)"></strong></span>
                        <span style="color:var(--t3)"><i class="fa fa-book"></i> <span id="saCount">0</span> subjects assigned</span>
                        <span style="color:var(--t3)"><i class="fa fa-clock-o"></i> <span id="saTotalPeriods">0</span> periods/week</span>
                    </div>

                    <!-- Assigned subjects table -->
                    <div id="saTable" style="margin-bottom:16px"></div>

                    <!-- Add subject area -->
                    <?php if (in_array($admin_role ?? '', ['Admin', 'Principal', 'Super Admin', 'School Super Admin'])): ?>
                    <div style="border-top:1px solid var(--border);padding-top:14px">
                        <h4 style="font-size:12px;font-weight:700;color:var(--t2);margin-bottom:10px"><i class="fa fa-plus-circle" style="color:var(--gold)"></i> Add Subject from Catalog</h4>
                        <div id="saCatalog" style="display:flex;gap:6px;flex-wrap:wrap"></div>
                        <div style="margin-top:12px">
                            <button class="ac-btn ac-btn-p" onclick="AC.sa.save()"><i class="fa fa-save"></i> Save Assignments</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 2: PERIOD SCHEDULING ═══════════ -->
    <div class="ac-pane" id="pane-scheduling">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-clock-o" style="color:var(--gold);margin-right:6px"></i>Period Scheduling</h3>
                <button class="ac-btn ac-btn-s ac-btn-sm" style="margin-left:auto" onclick="AC.ps.load()"><i class="fa fa-refresh"></i> Refresh</button>
            </div>
            <div class="ac-card-body">
                <!-- Current Schedule Summary -->
                <div id="psCurrentSummary" style="margin-bottom:20px;padding:14px 18px;background:var(--bg3);border-radius:8px;display:none">
                    <h4 style="font-size:12px;font-weight:700;color:var(--t2);margin-bottom:8px"><i class="fa fa-info-circle" style="color:var(--gold)"></i> Current Schedule</h4>
                    <div id="psSummaryContent" style="font-size:12px;color:var(--t1);line-height:1.8"></div>
                </div>

                <?php if (in_array($admin_role ?? '', ['Admin', 'Principal', 'Super Admin', 'School Super Admin'])): ?>
                <!-- Timing Configuration -->
                <div style="margin-bottom:20px">
                    <h4 style="font-size:12px;font-weight:700;color:var(--t2);margin-bottom:12px"><i class="fa fa-clock-o" style="color:var(--gold);margin-right:4px"></i> School Timings</h4>
                    <div class="ac-row">
                        <div class="ac-fg">
                            <label>School Start Time</label>
                            <input type="time" id="psStartTime" value="09:00">
                        </div>
                        <div class="ac-fg">
                            <label>School End Time</label>
                            <input type="time" id="psEndTime" value="15:00">
                        </div>
                        <div class="ac-fg">
                            <label>Number of Periods</label>
                            <input type="number" id="psPeriods" min="1" max="15" value="6">
                        </div>
                        <div class="ac-fg">
                            <label>Period Length</label>
                            <div id="psPeriodLen" style="padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:14px;font-weight:700;color:var(--gold)">— min</div>
                        </div>
                    </div>
                </div>

                <!-- Working Days -->
                <div style="margin-bottom:20px">
                    <h4 style="font-size:12px;font-weight:700;color:var(--t2);margin-bottom:12px"><i class="fa fa-calendar-check-o" style="color:var(--gold);margin-right:4px"></i> Working Days</h4>
                    <div id="psWorkingDays" style="display:flex;gap:8px;flex-wrap:wrap"></div>
                </div>

                <!-- Recesses -->
                <div style="margin-bottom:20px">
                    <h4 style="font-size:12px;font-weight:700;color:var(--t2);margin-bottom:12px"><i class="fa fa-coffee" style="color:var(--gold);margin-right:4px"></i> Recess / Break Periods</h4>
                    <div id="psRecesses"></div>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.ps.addRecess()" style="margin-top:8px"><i class="fa fa-plus"></i> Add Recess</button>
                </div>

                <!-- Period Timeline Preview -->
                <div style="margin-bottom:20px">
                    <h4 style="font-size:12px;font-weight:700;color:var(--t2);margin-bottom:12px"><i class="fa fa-align-left" style="color:var(--gold);margin-right:4px"></i> Timeline Preview</h4>
                    <div id="psTimeline" style="display:flex;gap:2px;flex-wrap:wrap;align-items:center"></div>
                </div>

                <div style="border-top:1px solid var(--border);padding-top:14px">
                    <button class="ac-btn ac-btn-p" onclick="AC.ps.save()"><i class="fa fa-save"></i> Save Schedule</button>
                </div>
                <?php else: ?>
                <div class="ac-empty"><i class="fa fa-lock"></i>Only Admin or Principal can modify the period schedule</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 3: CURRICULUM ═══════════ -->
    <div class="ac-pane" id="pane-curriculum">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-list-ol" style="color:var(--gold);margin-right:6px"></i>Curriculum Planning</h3>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <select id="curClass" class="ac-fg" style="margin:0;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:12px;min-width:160px">
                        <option value="">Select Class...</option>
                    </select>
                    <select id="curSubject" class="ac-fg" style="margin:0;padding:6px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:12px;min-width:140px">
                        <option value="">Select Subject...</option>
                    </select>
                    <button class="ac-btn ac-btn-p ac-btn-sm" onclick="AC.cur.load()"><i class="fa fa-refresh"></i> Load</button>
                </div>
            </div>
            <div class="ac-card-body">
                <!-- Progress -->
                <div id="curProgress" style="display:none">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                        <span style="font-size:12px;font-weight:600;color:var(--t2)">Syllabus Progress</span>
                        <span id="curProgressPct" style="font-size:12px;font-weight:700;color:var(--gold)">0%</span>
                    </div>
                    <div class="ac-progress"><div class="ac-progress-bar" id="curProgressBar" style="width:0%"></div></div>
                </div>

                <!-- Add Topic Form -->
                <div id="curAddForm" class="ac-inline-form" style="margin:12px -18px;border-top:none;border-bottom:1px solid var(--border)">
                    <div class="ac-row">
                        <div class="ac-fg"><label>Topic Title *</label><input type="text" id="curTopicTitle" placeholder="e.g. Quadratic Equations"></div>
                        <div class="ac-fg" style="max-width:120px"><label>Chapter</label><input type="text" id="curTopicChapter" placeholder="Ch. 4"></div>
                        <div class="ac-fg" style="max-width:100px"><label>Est. Periods</label><input type="number" id="curTopicPeriods" value="1" min="0"></div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:4px">
                        <button class="ac-btn ac-btn-p ac-btn-sm" onclick="AC.cur.addTopic()"><i class="fa fa-plus"></i> Add Topic</button>
                        <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.cur.toggleForm()">Cancel</button>
                    </div>
                </div>

                <div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.cur.toggleForm()"><i class="fa fa-plus"></i> Add Topic</button>
                    <div class="ac-pills" id="curFilter" style="margin-left:auto">
                        <button class="ac-pill active" data-f="all">All</button>
                        <button class="ac-pill" data-f="not_started">Not Started</button>
                        <button class="ac-pill" data-f="in_progress">In Progress</button>
                        <button class="ac-pill" data-f="completed">Completed</button>
                    </div>
                </div>

                <div id="curTopics">
                    <div class="ac-empty"><i class="fa fa-list-ol"></i>Select a class and subject, then click Load</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 2: CALENDAR ═══════════ -->
    <div class="ac-pane" id="pane-calendar">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-calendar" style="color:var(--gold);margin-right:6px"></i>Academic Calendar</h3>
                <button class="ac-btn ac-btn-p ac-btn-sm" style="margin-left:auto" onclick="AC.cal.showAddForm()"><i class="fa fa-plus"></i> Add Event</button>
            </div>

            <!-- Add/Edit Event Form -->
            <div id="calForm" class="ac-inline-form">
                <input type="hidden" id="calEditId" value="">
                <div class="ac-row">
                    <div class="ac-fg" style="flex:2"><label>Title *</label><input type="text" id="calTitle" placeholder="Event title"></div>
                    <div class="ac-fg"><label>Type</label>
                        <select id="calType">
                            <option value="holiday">Holiday</option>
                            <option value="exam">Exam</option>
                            <option value="meeting">Meeting</option>
                            <option value="event" selected>Event</option>
                            <option value="activity">Activity</option>
                        </select>
                    </div>
                </div>
                <div class="ac-row">
                    <div class="ac-fg"><label>Start Date *</label><input type="date" id="calStart"></div>
                    <div class="ac-fg"><label>End Date</label><input type="date" id="calEnd"></div>
                </div>
                <div class="ac-fg"><label>Description</label><textarea id="calDesc" rows="2" placeholder="Optional details..."></textarea></div>
                <div style="display:flex;gap:8px;margin-top:4px">
                    <button class="ac-btn ac-btn-p ac-btn-sm" onclick="AC.cal.saveEvent()"><i class="fa fa-check"></i> Save</button>
                    <button class="ac-btn ac-btn-d ac-btn-sm" id="calDeleteBtn" style="display:none" onclick="AC.cal.deleteEditingEvent()"><i class="fa fa-trash"></i> Delete</button>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.cal.hideForm()">Cancel</button>
                </div>
            </div>

            <div class="ac-card-body">
                <!-- Legend -->
                <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;font-size:11px">
                    <span><span class="ac-cal-dot holiday" style="display:inline-block;width:10px;height:10px;border-radius:50%;padding:0"></span> Holiday</span>
                    <span><span class="ac-cal-dot exam" style="display:inline-block;width:10px;height:10px;border-radius:50%;padding:0"></span> Exam</span>
                    <span><span class="ac-cal-dot meeting" style="display:inline-block;width:10px;height:10px;border-radius:50%;padding:0"></span> Meeting</span>
                    <span><span class="ac-cal-dot event" style="display:inline-block;width:10px;height:10px;border-radius:50%;padding:0"></span> Event</span>
                    <span><span class="ac-cal-dot activity" style="display:inline-block;width:10px;height:10px;border-radius:50%;padding:0"></span> Activity</span>
                </div>

                <div class="ac-cal-nav">
                    <button onclick="AC.cal.prevMonth()"><i class="fa fa-chevron-left"></i></button>
                    <div class="ac-cal-month" id="calMonthLabel">March 2026</div>
                    <button onclick="AC.cal.nextMonth()"><i class="fa fa-chevron-right"></i></button>
                    <button class="ac-btn ac-btn-s ac-btn-sm" style="margin-left:auto" onclick="AC.cal.goToday()">Today</button>
                </div>

                <div class="ac-cal-grid" id="calGrid"></div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 3: MASTER TIMETABLE ═══════════ -->
    <div class="ac-pane" id="pane-timetable">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-th" style="color:var(--gold);margin-right:6px"></i>Master Timetable</h3>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <!-- View Toggle -->
                    <div class="ac-pills" id="ttViewToggle">
                        <button class="ac-pill active" data-view="class"><i class="fa fa-th" style="margin-right:4px"></i>Class View</button>
                        <button class="ac-pill" data-view="teacher"><i class="fa fa-user" style="margin-right:4px"></i>Teacher View</button>
                    </div>
                    <!-- Class filter -->
                    <select id="ttClassFilter" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:12px;min-width:120px">
                        <option value="">All Classes</option>
                    </select>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.tt.copyDay()" title="Copy this day's timetable to another day"><i class="fa fa-copy"></i> Copy Day</button>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.tt.printTT()" title="Print timetable"><i class="fa fa-print"></i> Print</button>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.tt.load()"><i class="fa fa-refresh"></i> Refresh</button>
                </div>
            </div>
            <div class="ac-card-body">
                <!-- Day Tabs -->
                <div class="ac-day-tabs" id="ttDayTabs">
                    <div class="ac-day-tab active" data-day="Monday">Mon</div>
                    <div class="ac-day-tab" data-day="Tuesday">Tue</div>
                    <div class="ac-day-tab" data-day="Wednesday">Wed</div>
                    <div class="ac-day-tab" data-day="Thursday">Thu</div>
                    <div class="ac-day-tab" data-day="Friday">Fri</div>
                    <div class="ac-day-tab" data-day="Saturday">Sat</div>
                    <div class="ac-day-tab" data-day="_week" style="margin-left:8px;border-color:var(--gold);color:var(--gold)"><i class="fa fa-calendar"></i> Full Week</div>
                </div>

                <!-- Settings Summary + Fill Rate -->
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                    <div id="ttSettingsSummary" style="font-size:11px;color:var(--t3)"></div>
                    <div id="ttFillRate" style="font-size:11px;font-weight:600;display:none">
                        <span id="ttFillPct" style="color:var(--gold)">0%</span>
                        <span style="color:var(--t3)">slots filled</span>
                        <span id="ttFillEmpty" style="color:#d97706;margin-left:6px"></span>
                    </div>
                </div>

                <!-- Subject Color Legend -->
                <div id="ttLegend" style="display:none;margin-bottom:12px;padding:8px 12px;background:var(--bg3);border-radius:6px;font-size:11px;display:flex;gap:10px;flex-wrap:wrap"></div>

                <!-- Conflict Summary Bar -->
                <div id="ttConflictBar" style="display:none"></div>

                <!-- Print Header (visible only when printing) -->
                <div id="ttPrintHeader" style="display:none">
                    <h2><?= htmlspecialchars($school_name ?? '') ?> — Master Timetable</h2>
                    <p>Session: <?= htmlspecialchars($session_year ?? '') ?> | <span id="ttPrintDay"></span></p>
                </div>

                <!-- Grid -->
                <div class="ac-tt-grid" id="ttGridWrap">
                    <div class="ac-empty"><i class="fa fa-th"></i>Loading timetable...</div>
                </div>
            </div>
        </div>

    </div>

    <!-- ═══════════ TAB 4: SUBSTITUTES ═══════════ -->
    <div class="ac-pane" id="pane-substitutes">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-exchange" style="color:var(--gold);margin-right:6px"></i>Substitute Teachers</h3>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="date" id="subDateFilter" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--t1);font-size:12px">
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.sub.load()"><i class="fa fa-refresh"></i> Load</button>
                    <button class="ac-btn ac-btn-p ac-btn-sm" onclick="AC.sub.showForm()"><i class="fa fa-plus"></i> Assign Substitute</button>
                </div>
            </div>

            <!-- Add Form -->
            <div id="subForm" class="ac-inline-form">
                <input type="hidden" id="subEditId" value="">
                <div class="ac-row">
                    <div class="ac-fg"><label>Start Date *</label><input type="date" id="subDate"></div>
                    <div class="ac-fg"><label>End Date <span style="color:var(--t3);font-size:11px">(leave blank for single day)</span></label><input type="date" id="subDateEnd"></div>
                    <div class="ac-fg"><label>Absent Teacher *</label>
                        <select id="subAbsent"><option value="">Select teacher...</option></select>
                    </div>
                </div>
                <div class="ac-row">
                    <div class="ac-fg"><label>Substitute Teacher *</label>
                        <select id="subTeacher"><option value="">Select teacher...</option></select>
                    </div>
                    <div class="ac-fg"><label>Class / Section *</label>
                        <select id="subClass"><option value="">Select class...</option></select>
                    </div>
                    <div class="ac-fg"><label>Subject</label>
                        <select id="subSubject"><option value="">Select class first...</option></select>
                    </div>
                </div>
                <div class="ac-row">
                    <div class="ac-fg"><label>Periods (comma-separated) *</label><input type="text" id="subPeriods" placeholder="e.g. 3,4,5"></div>
                    <div class="ac-fg"><label>Reason</label><input type="text" id="subReason" placeholder="e.g. Medical leave"></div>
                </div>
                <!-- Teacher availability indicator -->
                <div id="subAvailability" style="display:none;padding:8px 12px;border-radius:6px;margin-bottom:8px;font-size:12px"></div>
                <div style="display:flex;gap:8px;margin-top:4px">
                    <button class="ac-btn ac-btn-p ac-btn-sm" onclick="AC.sub.save()"><i class="fa fa-check"></i> Save</button>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.sub.hideForm()">Cancel</button>
                </div>
            </div>

            <div class="ac-card-body">
                <div id="subList">
                    <div class="ac-empty"><i class="fa fa-exchange"></i>Select a date and click Load, or view all</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 7: TEACHER WORKLOAD ═══════════ -->
    <div class="ac-pane" id="pane-workload">
        <div class="ac-card">
            <div class="ac-card-hd">
                <h3><i class="fa fa-bar-chart" style="color:var(--gold);margin-right:6px"></i>Teacher Workload Analytics</h3>
                <div style="margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <div class="ac-fg" style="margin:0;flex-direction:row;align-items:center;gap:6px">
                        <label style="margin:0;white-space:nowrap">Max periods/week</label>
                        <input type="number" id="wlThreshold" min="1" max="60" value="30" style="width:60px;padding:4px 8px;font-size:12px;text-align:center" onchange="AC.wl.render()">
                    </div>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.wl.exportCSV()" title="Export to CSV"><i class="fa fa-download"></i> Export</button>
                    <button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.wl.load()"><i class="fa fa-refresh"></i> Refresh</button>
                </div>
            </div>
            <div class="ac-card-body">
                <!-- Overview Stats -->
                <div id="wlStats" style="display:none;margin-bottom:18px">
                    <div style="display:flex;gap:12px;flex-wrap:wrap">
                        <div class="ac-wl-stat">
                            <div class="ac-wl-stat-n" id="wlStatTotal">0</div>
                            <div class="ac-wl-stat-l">Teachers</div>
                        </div>
                        <div class="ac-wl-stat">
                            <div class="ac-wl-stat-n" id="wlStatAvg">0</div>
                            <div class="ac-wl-stat-l">Avg periods/week</div>
                        </div>
                        <div class="ac-wl-stat">
                            <div class="ac-wl-stat-n ac-wl-ok" id="wlStatOk">0</div>
                            <div class="ac-wl-stat-l">Within limit</div>
                        </div>
                        <div class="ac-wl-stat">
                            <div class="ac-wl-stat-n ac-wl-over" id="wlStatOver">0</div>
                            <div class="ac-wl-stat-l">Overloaded</div>
                        </div>
                        <div class="ac-wl-stat">
                            <div class="ac-wl-stat-n ac-wl-free" id="wlStatFree">0</div>
                            <div class="ac-wl-stat-l">Unassigned</div>
                        </div>
                    </div>
                </div>

                <!-- Workload Table -->
                <div id="wlTable">
                    <div class="ac-empty"><i class="fa fa-bar-chart"></i>Loading workload data...</div>
                </div>
            </div>
        </div>
    </div>

</div><!-- .ac-wrap -->
</div><!-- .content-wrapper -->

<!-- Toast -->
<div class="ac-toast" id="acToast"></div>

<script>
var BASE = '<?= base_url() ?>';
var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
var CSRF_TOKEN = '<?= $this->security->get_csrf_hash() ?>';

/* ── Helpers ── */
function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
function post(url,params){
    params = params || {};
    params[CSRF_NAME] = CSRF_TOKEN;
    return fetch(BASE+url,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body:new URLSearchParams(params).toString(),
        credentials:'include'
    }).then(function(r){return r.json()}).then(function(d){
        if(d.csrf_token) CSRF_TOKEN=d.csrf_token;
        return d;
    });
}
function toast(msg,ok){
    var t=document.getElementById('acToast');
    t.textContent=msg;t.className='ac-toast '+(ok?'ok':'err')+' show';
    setTimeout(function(){t.classList.remove('show')},2800);
}

/* ── RBAC ── */
var _role='<?= htmlspecialchars($admin_role ?? "Admin", ENT_QUOTES) ?>';
var _canEdit=(_role==='Admin'||_role==='Principal'||_role==='Super Admin'||_role==='School Super Admin');

/* ── Tab Switching ── */
document.getElementById('acTabs').addEventListener('click',function(e){
    var tab=e.target.closest('.ac-tab');
    if(!tab)return;
    document.querySelectorAll('.ac-tab').forEach(function(t){t.classList.remove('active')});
    document.querySelectorAll('.ac-pane').forEach(function(p){p.classList.remove('active')});
    tab.classList.add('active');
    document.getElementById('pane-'+tab.dataset.tab).classList.add('active');
    // Lazy-load on first visit
    var k=tab.dataset.tab;
    if(k==='subjects' && !AC.sa._loaded) AC.sa.init();
    if(k==='scheduling' && !AC.ps._loaded) AC.ps.init();
    if(k==='curriculum' && !AC.cur._loaded) AC.cur.init();
    if(k==='calendar' && !AC.cal._loaded) AC.cal.init();
    if(k==='timetable' && !AC.tt._loaded) AC.tt.load();
    if(k==='substitutes' && !AC.sub._loaded) AC.sub.init();
    if(k==='workload' && !AC.wl._loaded) AC.wl.load();
});

/* ── Shared Data Cache ── */
var _classes=[], _subjects={}, _teachers=[];
function loadSharedData(cb){
    if(_classes.length>0) return cb();
    post('academic/get_classes_subjects').then(function(d){
        if(d.status==='success'){
            _classes=d.classes||[];
            _subjects=d.subjects||{};
        }
        cb();
    });
}

/* ══════════════════════════════════════════════════════════════
   SUBJECT ASSIGNMENT
══════════════════════════════════════════════════════════════ */
var AC = {};
AC.sa = {
    _loaded:false,
    _classes:[],    // [{key, label}] — per class (not per section)
    _catalog:{},    // {classNum: [{code,name,category,stream}]}
    _assignments:{},// {fbKey: [{code,name,category,periods_week,teacher_id,teacher_name}]}
    _currentKey:'', // Firebase key for selected class (e.g. "Class 9th")
    _currentLabel:'',
    _currentSubs:[], // working copy for selected class

    init:function(){
        AC.sa._loaded=true;
        post('academic/get_subject_assignments').then(function(d){
            if(d.status!=='success'){
                document.getElementById('saContent').innerHTML='<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> '+esc(d.message||'Failed to load')+'</div>';
                return;
            }
            AC.sa._classes=d.classes||[];
            AC.sa._catalog=d.catalog||{};
            AC.sa._assignments=d.assignments||{};

            // Load teachers BEFORE enabling the class selector (fixes empty dropdown race)
            var teacherReady=(_teachers.length>0)
                ? Promise.resolve()
                : post('academic/get_all_teachers').then(function(td){
                    if(td.status==='success') _teachers=td.teachers||[];
                });

            teacherReady.then(function(){
                var sel=document.getElementById('saClassSelect');
                sel.innerHTML='<option value="">Select Class...</option>';
                AC.sa._classes.forEach(function(c){
                    var fbKey=AC.sa._toFbKey(c.key);
                    var assigned=AC.sa._assignments[fbKey]||[];
                    var count=Array.isArray(assigned)?assigned.length:0;
                    var badge=count>0?' ('+count+' subjects)':'';
                    sel.innerHTML+='<option value="'+esc(fbKey)+'" data-label="'+esc(c.label)+'" data-key="'+esc(c.key)+'">'+esc(c.label)+badge+'</option>';
                });
                sel.onchange=function(){
                    var opt=sel.options[sel.selectedIndex];
                    if(!sel.value){
                        document.getElementById('saContent').innerHTML='<div class="ac-empty"><i class="fa fa-book"></i>Select a class to manage subject assignments</div>';
                        document.getElementById('saAssignArea').style.display='none';
                        return;
                    }
                    AC.sa._currentKey=sel.value;
                    AC.sa._currentLabel=opt.dataset.label||'';
                    AC.sa._loadClass(sel.value,opt.dataset.key||'');
                };
                document.getElementById('saContent').innerHTML='';
            });
        });
    },

    load:function(){ AC.sa._loaded=false; AC.sa.init(); },

    /** Convert class key to Firebase-safe key (mirror of PHP _class_to_firebase_key) */
    _toFbKey:function(key){
        return (key||'').replace(/[.$#\[\]\/]/g,'_');
    },

    _loadClass:function(fbKey,classKey){
        document.getElementById('saContent').innerHTML='';
        document.getElementById('saAssignArea').style.display='block';
        document.getElementById('saClassName').textContent=AC.sa._currentLabel;

        // Build working copy from assignments
        var assigned=AC.sa._assignments[fbKey]||[];
        AC.sa._currentSubs=Array.isArray(assigned)?assigned.slice():[];

        AC.sa._renderTable();
        AC.sa._renderCatalog(classKey);
    },

    _renderTable:function(){
        var subs=AC.sa._currentSubs;
        document.getElementById('saCount').textContent=subs.length;
        var totalP=0;
        subs.forEach(function(s){totalP+=(s.periods_week||0)});
        document.getElementById('saTotalPeriods').textContent=totalP;

        if(subs.length===0){
            document.getElementById('saTable').innerHTML='<div style="padding:20px;text-align:center;color:var(--t3);font-size:12px"><i class="fa fa-info-circle"></i> No subjects assigned yet. Add from the catalog below.</div>';
            return;
        }

        var html='<table class="ac-table"><thead><tr><th>Subject</th><th>Code</th><th>Category</th><th>Periods/Week</th><th>Teacher</th>';
        if(_canEdit) html+='<th>Actions</th>';
        html+='</tr></thead><tbody>';

        subs.forEach(function(s,i){
            html+='<tr>';
            html+='<td style="font-weight:600">'+esc(s.name)+'</td>';
            html+='<td style="font-size:11px;color:var(--t3)">'+esc(s.code)+'</td>';
            html+='<td><span class="ac-badge '+(s.category==='Core'?'ac-badge-event':'ac-badge-act')+'">'+esc(s.category)+'</span></td>';
            if(_canEdit){
                html+='<td><input type="number" min="0" max="20" value="'+(s.periods_week||0)+'" data-idx="'+i+'" class="sa-pw-input" style="width:55px;padding:3px 6px;border:1px solid var(--border);border-radius:4px;background:var(--bg3);color:var(--t1);font-size:12px;text-align:center"></td>';
                html+='<td><select data-idx="'+i+'" class="sa-teacher-sel" style="padding:3px 6px;border:1px solid var(--border);border-radius:4px;background:var(--bg3);color:var(--t1);font-size:11px;max-width:160px"><option value="">—</option>';
                _teachers.forEach(function(t){
                    html+='<option value="'+esc(t.id)+'" data-name="'+esc(t.name)+'"'+(t.id===s.teacher_id?' selected':'')+'>'+esc(t.name)+'</option>';
                });
                html+='</select></td>';
                html+='<td><button class="ac-btn ac-btn-d ac-btn-sm" onclick="AC.sa.removeSub('+i+')" title="Remove"><i class="fa fa-times"></i></button></td>';
            } else {
                html+='<td style="text-align:center">'+(s.periods_week||'—')+'</td>';
                html+='<td>'+esc(s.teacher_name||'—')+'</td>';
            }
            html+='</tr>';
        });
        html+='</tbody></table>';
        document.getElementById('saTable').innerHTML=html;

        // Bind inline-edit events
        document.querySelectorAll('.sa-pw-input').forEach(function(inp){
            inp.addEventListener('change',function(){
                var idx=parseInt(this.dataset.idx);
                AC.sa._currentSubs[idx].periods_week=parseInt(this.value)||0;
                var totalP=0;AC.sa._currentSubs.forEach(function(s){totalP+=(s.periods_week||0)});
                document.getElementById('saTotalPeriods').textContent=totalP;
            });
        });
        document.querySelectorAll('.sa-teacher-sel').forEach(function(sel){
            sel.addEventListener('change',function(){
                var idx=parseInt(this.dataset.idx);
                var opt=this.options[this.selectedIndex];
                AC.sa._currentSubs[idx].teacher_id=this.value;
                AC.sa._currentSubs[idx].teacher_name=opt?opt.dataset.name||'':'';
            });
        });
    },

    _renderCatalog:function(classKey){
        var box=document.getElementById('saCatalog');
        if(!box)return;
        // Extract class number for catalog lookup
        var m=(classKey||'').match(/(\d+)/);
        var num=m?m[1]:'';
        var nameKey=(classKey||'').replace('Class ','');

        // Gather subjects from catalog
        var catSubs=(AC.sa._catalog[num]||[]).concat(AC.sa._catalog[nameKey]||[]);
        var seen={};
        catSubs=catSubs.filter(function(s){if(seen[s.code])return false;seen[s.code]=true;return true});

        // Filter out already assigned
        var assignedCodes={};
        AC.sa._currentSubs.forEach(function(s){assignedCodes[s.code]=true});

        var available=catSubs.filter(function(s){return!assignedCodes[s.code]});

        if(catSubs.length===0){
            box.innerHTML='<div style="color:var(--t3);font-size:12px"><i class="fa fa-info-circle"></i> No subjects found in catalog for this class. Create subjects in School Configuration first.</div>';
            return;
        }
        if(available.length===0){
            box.innerHTML='<div style="color:var(--t3);font-size:12px"><i class="fa fa-check-circle" style="color:#16a34a"></i> All catalog subjects are assigned.</div>';
            return;
        }

        var html='';
        available.forEach(function(s){
            html+='<button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.sa.addSub(\''+esc(s.code)+'\',\''+esc(s.name)+'\',\''+esc(s.category||'Core')+'\')" style="margin-bottom:4px">';
            html+='<i class="fa fa-plus"></i> '+esc(s.name);
            if(s.category&&s.category!=='Core') html+=' <span style="font-size:9px;opacity:.6">('+esc(s.category)+')</span>';
            html+='</button>';
        });
        box.innerHTML=html;
    },

    addSub:function(code,name,category){
        AC.sa._currentSubs.push({code:code,name:name,category:category||'Core',periods_week:0,teacher_id:'',teacher_name:''});
        AC.sa._renderTable();
        var sel=document.getElementById('saClassSelect');
        var opt=sel.options[sel.selectedIndex];
        AC.sa._renderCatalog(opt?opt.dataset.key||'':'');
    },

    removeSub:function(idx){
        AC.sa._currentSubs.splice(idx,1);
        AC.sa._renderTable();
        var sel=document.getElementById('saClassSelect');
        var opt=sel.options[sel.selectedIndex];
        AC.sa._renderCatalog(opt?opt.dataset.key||'':'');
    },

    save:function(){
        if(!AC.sa._currentKey){toast('Select a class first',false);return}

        post('academic/save_subject_assignments',{
            class_key:AC.sa._currentKey,
            subjects:JSON.stringify(AC.sa._currentSubs)
        }).then(function(d){
            if(d.status==='success'){
                toast('Saved '+d.count+' subject assignments',true);
                AC.sa._assignments[AC.sa._currentKey]=AC.sa._currentSubs.slice();
                // Update dropdown badge
                var sel=document.getElementById('saClassSelect');
                var opt=sel.options[sel.selectedIndex];
                if(opt&&opt.value){
                    var lbl=opt.dataset.label||'';
                    opt.textContent=lbl+' ('+d.count+' subjects)';
                }
            } else toast(d.message,false);
        });
    },

    copyFrom:function(){
        if(!AC.sa._currentKey){toast('Select a destination class first',false);return}
        var curFbKey=AC.sa._currentKey;
        var opts=AC.sa._classes.filter(function(c){return AC.sa._toFbKey(c.key)!==curFbKey});
        if(opts.length===0){toast('No other classes available',false);return}
        var list=opts.map(function(c){return c.label}).join('\n');
        var pick=prompt('Copy subject assignments FROM which class?\n\n'+list);
        if(!pick)return;
        pick=pick.trim();
        var found=opts.find(function(c){return c.label===pick||c.label.toLowerCase()===pick.toLowerCase()});
        if(!found){toast('Class not found: '+pick,false);return}
        var fromKey=AC.sa._toFbKey(found.key);

        if(!confirm('Copy all subject assignments from '+found.label+' to '+AC.sa._currentLabel+'?'))return;

        post('academic/copy_subject_assignments',{from_key:fromKey,to_key:curFbKey}).then(function(d){
            if(d.status==='success'){
                toast('Copied '+d.count+' assignments',true);
                AC.sa.load();
            } else toast(d.message,false);
        });
    }
};

/* ══════════════════════════════════════════════════════════════
   PERIOD SCHEDULING
══════════════════════════════════════════════════════════════ */
AC.ps = {
    _loaded:false,
    _settings:null,
    _allDays:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
    _dayShort:{'Monday':'Mon','Tuesday':'Tue','Wednesday':'Wed','Thursday':'Thu','Friday':'Fri','Saturday':'Sat','Sunday':'Sun'},

    init:function(){
        AC.ps._loaded=true;
        AC.ps.load();
    },

    load:function(){
        post('academic/get_timetable_settings').then(function(d){
            if(d.status!=='success')return;
            AC.ps._settings=d;
            AC.ps._renderSummary(d);
            AC.ps._fillForm(d);
            AC.ps._renderTimeline();
        });
    },

    _renderSummary:function(s){
        var box=document.getElementById('psCurrentSummary');
        var wd=(s.working_days||[]).map(function(d){return AC.ps._dayShort[d]||d}).join(', ');
        var rStr='None';
        if(s.recesses&&s.recesses.length>0){
            rStr=s.recesses.map(function(r){return 'after P'+r.after_period+' ('+r.duration+'min)'}).join(', ');
        }
        document.getElementById('psSummaryContent').innerHTML=
            '<div><i class="fa fa-clock-o" style="color:var(--gold);width:18px"></i> <strong>School Hours:</strong> '+esc(s.start_time||'—')+' to '+esc(s.end_time||'—')+'</div>'+
            '<div><i class="fa fa-th" style="color:var(--gold);width:18px"></i> <strong>Periods:</strong> '+s.no_of_periods+' periods x '+s.length_of_period+' min each</div>'+
            '<div><i class="fa fa-coffee" style="color:var(--gold);width:18px"></i> <strong>Recesses:</strong> '+esc(rStr)+'</div>'+
            '<div><i class="fa fa-calendar-check-o" style="color:var(--gold);width:18px"></i> <strong>Working Days:</strong> '+esc(wd)+'</div>';
        box.style.display='block';
    },

    _fillForm:function(s){
        var startEl=document.getElementById('psStartTime');
        var endEl=document.getElementById('psEndTime');
        if(!startEl)return; // read-only view for teachers

        startEl.value=AC.ps._ampmTo24(s.start_time||'9:00AM');
        endEl.value=AC.ps._ampmTo24(s.end_time||'3:00PM');
        document.getElementById('psPeriods').value=s.no_of_periods||6;
        document.getElementById('psPeriodLen').textContent=(s.length_of_period||45)+' min';

        // Working days checkboxes
        var wdBox=document.getElementById('psWorkingDays');
        var wdSet={};
        (s.working_days||['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']).forEach(function(d){wdSet[d]=true});
        wdBox.innerHTML='';
        AC.ps._allDays.forEach(function(day){
            var id='psWD_'+day;
            var checked=wdSet[day]?'checked':'';
            wdBox.innerHTML+='<label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:6px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);font-size:12px;font-weight:600;color:var(--t2)">'+
                '<input type="checkbox" id="'+id+'" value="'+day+'" '+checked+' onchange="AC.ps._renderTimeline()" style="accent-color:var(--gold)"> '+AC.ps._dayShort[day]+'</label>';
        });

        // Recesses
        var rBox=document.getElementById('psRecesses');
        rBox.innerHTML='';
        var recesses=s.recesses||[];
        if(recesses.length===0) recesses=[{after_period:3,duration:30}];
        recesses.forEach(function(r){AC.ps._addRecessRow(r.after_period,r.duration)});

        // Bind change events for live preview
        startEl.addEventListener('change',function(){AC.ps._renderTimeline()});
        endEl.addEventListener('change',function(){AC.ps._renderTimeline()});
        document.getElementById('psPeriods').addEventListener('change',function(){AC.ps._renderTimeline()});
    },

    _addRecessRow:function(after,dur){
        var box=document.getElementById('psRecesses');
        var row=document.createElement('div');
        row.className='ac-row';
        row.style.cssText='margin-bottom:8px;align-items:center';
        row.innerHTML='<div class="ac-fg" style="max-width:140px"><label>After Period</label><input type="number" min="1" max="14" value="'+(after||3)+'" class="ps-rec-after" onchange="AC.ps._renderTimeline()"></div>'+
            '<div class="ac-fg" style="max-width:140px"><label>Duration (min)</label><input type="number" min="5" max="60" value="'+(dur||30)+'" class="ps-rec-dur" onchange="AC.ps._renderTimeline()"></div>'+
            '<div style="padding-top:18px"><button class="ac-btn ac-btn-d ac-btn-sm" onclick="this.closest(\'.ac-row\').remove();AC.ps._renderTimeline()"><i class="fa fa-times"></i></button></div>';
        box.appendChild(row);
    },

    addRecess:function(){
        AC.ps._addRecessRow(3,30);
        AC.ps._renderTimeline();
    },

    _renderTimeline:function(){
        var box=document.getElementById('psTimeline');
        if(!box)return;

        var startEl=document.getElementById('psStartTime');
        if(!startEl){box.innerHTML='';return}
        var startMin=AC.ps._parseTime24(startEl.value);
        var endMin=AC.ps._parseTime24(document.getElementById('psEndTime').value);
        var np=parseInt(document.getElementById('psPeriods').value)||6;

        // Gather recesses
        var recesses=[];
        document.querySelectorAll('#psRecesses .ac-row').forEach(function(row){
            var after=parseInt(row.querySelector('.ps-rec-after').value)||0;
            var dur=parseInt(row.querySelector('.ps-rec-dur').value)||0;
            if(after>0&&dur>0) recesses.push({after_period:after,duration:dur});
        });
        var recMap={};
        var recessMin=0;
        recesses.forEach(function(r){recMap[r.after_period]=r.duration;recessMin+=r.duration});

        var available=endMin-startMin-recessMin;
        if(available<=0||endMin<=startMin){
            box.innerHTML='<div style="color:#dc2626;font-size:12px"><i class="fa fa-exclamation-triangle"></i> Invalid time range or recess exceeds available time</div>';
            document.getElementById('psPeriodLen').textContent='— min';
            return;
        }

        var pLen=Math.round(available/np*10)/10;
        document.getElementById('psPeriodLen').textContent=pLen+' min';

        // Build visual timeline
        var html='';
        var cur=startMin;
        for(var p=1;p<=np;p++){
            var pEnd=cur+pLen;
            html+='<div style="background:var(--gold-dim);border:1px solid var(--gold);border-radius:6px;padding:6px 10px;text-align:center;min-width:60px">'+
                '<div style="font-size:10px;font-weight:700;color:var(--gold)">P'+p+'</div>'+
                '<div style="font-size:9px;color:var(--t3)">'+AC.ps._fmtTime(cur)+' - '+AC.ps._fmtTime(pEnd)+'</div>'+
                '</div>';
            cur=pEnd;
            if(recMap[p]){
                var bEnd=cur+recMap[p];
                html+='<div style="background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);border-radius:6px;padding:6px 8px;text-align:center;min-width:50px">'+
                    '<div style="font-size:9px;font-weight:700;color:#d97706"><i class="fa fa-coffee"></i> Break</div>'+
                    '<div style="font-size:8px;color:var(--t3)">'+recMap[p]+'min</div>'+
                    '</div>';
                cur=bEnd;
            }
        }
        box.innerHTML=html;
    },

    save:function(){
        var start=document.getElementById('psStartTime').value;
        var end=document.getElementById('psEndTime').value;
        var periods=parseInt(document.getElementById('psPeriods').value)||0;
        if(!start||!end||periods<=0){toast('Fill all required fields',false);return}

        var recesses=[];
        document.querySelectorAll('#psRecesses .ac-row').forEach(function(row){
            var after=parseInt(row.querySelector('.ps-rec-after').value)||0;
            var dur=parseInt(row.querySelector('.ps-rec-dur').value)||0;
            if(after>0&&dur>0) recesses.push({after_period:after,duration:dur});
        });

        var workingDays=[];
        AC.ps._allDays.forEach(function(day){
            var cb=document.getElementById('psWD_'+day);
            if(cb&&cb.checked) workingDays.push(day);
        });
        if(workingDays.length===0){toast('Select at least one working day',false);return}

        post('academic/save_timetable_settings',{
            start_time:start,end_time:end,no_of_periods:periods,
            recesses:JSON.stringify(recesses),
            working_days:JSON.stringify(workingDays)
        }).then(function(d){
            if(d.status==='success'){
                toast('Schedule saved (period = '+d.length_of_period+' min)',true);
                AC.ps.load(); // Refresh summary
                // Refresh timetable if loaded
                if(AC.tt._loaded) AC.tt.load();
            } else toast(d.message,false);
        });
    },

    _ampmTo24:function(str){
        if(!str)return'09:00';
        str=str.toUpperCase().trim();
        var m=str.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/);
        if(!m)return'09:00';
        var h=parseInt(m[1]),min=parseInt(m[2]),ampm=m[3]||'';
        if(ampm==='PM'&&h!==12) h+=12;
        if(ampm==='AM'&&h===12) h=0;
        return String(h).padStart(2,'0')+':'+String(min).padStart(2,'0');
    },
    _parseTime24:function(str){
        if(!str||str.indexOf(':')===-1)return 540;
        var parts=str.split(':');
        return parseInt(parts[0])*60+parseInt(parts[1]);
    },
    _fmtTime:function(minutes){
        var h=Math.floor(minutes/60)%24;
        var m=Math.round(minutes%60);
        var ampm=h>=12?'PM':'AM';
        var h12=h%12||12;
        return h12+':'+String(m).padStart(2,'0')+ampm;
    }
};

/* ══════════════════════════════════════════════════════════════
   CURRICULUM
══════════════════════════════════════════════════════════════ */
AC.cur = {
    _loaded:false,
    topics: [],
    filter: 'all',
    init: function(){
        AC.cur._loaded=true;
        loadSharedData(function(){
            var sel=document.getElementById('curClass');
            sel.innerHTML='<option value="">Select Class...</option>';
            _classes.forEach(function(c){
                sel.innerHTML+='<option value="'+esc(c.class_section)+'" data-key="'+esc(c.class_key)+'">'+esc(c.label)+'</option>';
            });
            sel.onchange=AC.cur.onClassChange;
        });
        // Filter pills
        document.getElementById('curFilter').addEventListener('click',function(e){
            var pill=e.target.closest('.ac-pill');
            if(!pill)return;
            document.querySelectorAll('#curFilter .ac-pill').forEach(function(p){p.classList.remove('active')});
            pill.classList.add('active');
            AC.cur.filter=pill.dataset.f;
            AC.cur.render();
        });
    },
    onClassChange: function(){
        var cs=document.getElementById('curClass');
        var opt=cs.options[cs.selectedIndex];
        var classKey=opt?opt.dataset.key:'';
        var subSel=document.getElementById('curSubject');
        subSel.innerHTML='<option value="">Select Subject...</option>';
        if(!classKey)return;
        // Extract class num
        var m=classKey.match(/(\d+)/);
        var num=m?m[1]:'';
        var subs=_subjects[num]||[];
        subs.forEach(function(s){
            var label=s.name+(s.code&&s.code!==s.name?' ('+s.code+')':'');
            subSel.innerHTML+='<option value="'+esc(s.name)+'">'+esc(label)+'</option>';
        });
        // Also try non-numeric keys (Nursery, LKG etc)
        var nameKey=classKey.replace('Class ','');
        var subs2=_subjects[nameKey]||[];
        subs2.forEach(function(s){
            if(subs.some(function(x){return x.name===s.name}))return;
            var label=s.name+(s.code&&s.code!==s.name?' ('+s.code+')':'');
            subSel.innerHTML+='<option value="'+esc(s.name)+'">'+esc(label)+'</option>';
        });
    },
    load: function(){
        var cs=document.getElementById('curClass').value;
        var sub=document.getElementById('curSubject').value;
        if(!cs||!sub){toast('Select class and subject first',false);return}
        post('academic/get_curriculum',{class_section:cs,subject:sub}).then(function(d){
            if(d.status==='success'){
                AC.cur.topics=d.topics||[];
                AC.cur.render();
                toast('Loaded '+AC.cur.topics.length+' topics',true);
            } else toast(d.message,false);
        });
    },
    render: function(){
        var t=AC.cur.topics;
        var f=AC.cur.filter;
        // Progress
        var total=t.length, done=t.filter(function(x){return x.status==='completed'}).length;
        var pct=total>0?Math.round(done/total*100):0;
        var pg=document.getElementById('curProgress');
        pg.style.display=total>0?'block':'none';
        document.getElementById('curProgressPct').textContent=pct+'%';
        document.getElementById('curProgressBar').style.width=pct+'%';

        if(total===0){
            document.getElementById('curTopics').innerHTML='<div class="ac-empty"><i class="fa fa-list-ol"></i>No topics yet. Click "Add Topic" to start planning.</div>';
            return;
        }
        var html='<table class="ac-table"><thead><tr><th>#</th><th>Topic</th><th>Chapter</th><th>Periods</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        t.forEach(function(topic,i){
            if(f!=='all' && topic.status!==f) return;
            var badge='ac-badge-ns',lbl='Not Started';
            if(topic.status==='in_progress'){badge='ac-badge-ip';lbl='In Progress'}
            if(topic.status==='completed'){badge='ac-badge-done';lbl='Completed'}
            html+='<tr>';
            html+='<td style="color:var(--t3);font-weight:700">'+(i+1)+'</td>';
            html+='<td style="font-weight:600">'+esc(topic.title)+'</td>';
            html+='<td>'+esc(topic.chapter)+'</td>';
            html+='<td>'+((topic.est_periods||0))+'</td>';
            html+='<td><span class="ac-badge '+badge+'">'+lbl+'</span></td>';
            html+='<td style="white-space:nowrap">';
            if(topic.status!=='completed'){
                var next=topic.status==='not_started'?'in_progress':'completed';
                html+='<button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.cur.setStatus('+i+',\''+next+'\')"><i class="fa fa-arrow-right"></i></button> ';
            }
            if(topic.status==='completed'){
                html+='<button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.cur.setStatus('+i+',\'in_progress\')"><i class="fa fa-undo"></i></button> ';
            }
            html+='<button class="ac-btn ac-btn-d ac-btn-sm" onclick="AC.cur.deleteTopic('+i+')"><i class="fa fa-trash"></i></button>';
            html+='</td></tr>';
        });
        html+='</tbody></table>';
        document.getElementById('curTopics').innerHTML=html;
    },
    toggleForm: function(){
        var f=document.getElementById('curAddForm');
        f.classList.toggle('show');
        if(f.classList.contains('show')) document.getElementById('curTopicTitle').focus();
    },
    addTopic: function(){
        var title=document.getElementById('curTopicTitle').value.trim();
        if(!title){toast('Topic title required',false);return}
        AC.cur.topics.push({
            title:title,
            chapter:document.getElementById('curTopicChapter').value.trim(),
            est_periods:parseInt(document.getElementById('curTopicPeriods').value)||0,
            status:'not_started',
            completed_date:'',
            sort_order:AC.cur.topics.length
        });
        AC.cur.saveFull();
        document.getElementById('curTopicTitle').value='';
        document.getElementById('curTopicChapter').value='';
        document.getElementById('curTopicPeriods').value='1';
    },
    setStatus: function(idx,status){
        var cs=document.getElementById('curClass').value;
        var sub=document.getElementById('curSubject').value;
        post('academic/update_topic_status',{class_section:cs,subject:sub,index:idx,status:status}).then(function(d){
            if(d.status==='success'){
                AC.cur.topics[idx].status=status;
                if(status==='completed') AC.cur.topics[idx].completed_date=new Date().toISOString().slice(0,10);
                AC.cur.render();
                toast('Status updated',true);
            } else toast(d.message,false);
        });
    },
    deleteTopic: function(idx){
        if(!confirm('Delete this topic?'))return;
        var cs=document.getElementById('curClass').value;
        var sub=document.getElementById('curSubject').value;
        post('academic/delete_topic',{class_section:cs,subject:sub,index:idx}).then(function(d){
            if(d.status==='success'){
                AC.cur.topics=d.topics||[];
                AC.cur.render();
                toast('Topic deleted',true);
            } else toast(d.message,false);
        });
    },
    saveFull: function(){
        var cs=document.getElementById('curClass').value;
        var sub=document.getElementById('curSubject').value;
        if(!cs||!sub) return;
        post('academic/save_curriculum',{class_section:cs,subject:sub,topics:JSON.stringify(AC.cur.topics)}).then(function(d){
            if(d.status==='success'){
                AC.cur.topics=d.topics||[];
                AC.cur.render();
                toast('Curriculum saved',true);
            } else toast(d.message,false);
        });
    }
};

/* ══════════════════════════════════════════════════════════════
   CALENDAR
══════════════════════════════════════════════════════════════ */
AC.cal = {
    _loaded:false,
    year:new Date().getFullYear(),
    month:new Date().getMonth(),
    events:[],
    init:function(){
        AC.cal._loaded=true;
        AC.cal.loadMonth();
    },
    loadMonth:function(){
        var mm=(AC.cal.month+1).toString().padStart(2,'0');
        var ym=AC.cal.year+'-'+mm;
        document.getElementById('calMonthLabel').textContent=new Date(AC.cal.year,AC.cal.month,1).toLocaleString('en',{month:'long',year:'numeric'});
        post('academic/get_calendar_events',{month:ym}).then(function(d){
            AC.cal.events=(d.status==='success')?(d.events||[]):[];
            AC.cal.renderGrid();
        });
    },
    prevMonth:function(){AC.cal.month--;if(AC.cal.month<0){AC.cal.month=11;AC.cal.year--}AC.cal.loadMonth()},
    nextMonth:function(){AC.cal.month++;if(AC.cal.month>11){AC.cal.month=0;AC.cal.year++}AC.cal.loadMonth()},
    goToday:function(){AC.cal.year=new Date().getFullYear();AC.cal.month=new Date().getMonth();AC.cal.loadMonth()},
    renderGrid:function(){
        var y=AC.cal.year,m=AC.cal.month;
        var first=new Date(y,m,1),last=new Date(y,m+1,0);
        var startDay=first.getDay()||7; // Mon=1
        var days=last.getDate();
        var today=new Date().toISOString().slice(0,10);

        var html='';
        ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(function(d){
            html+='<div class="ac-cal-hd">'+d+'</div>';
        });

        // Prev month padding
        var prevLast=new Date(y,m,0).getDate();
        for(var i=startDay-1;i>=1;i--){
            var d=prevLast-i+1;
            html+='<div class="ac-cal-day other"><div class="num">'+d+'</div></div>';
        }

        // Current month
        for(var d=1;d<=days;d++){
            var dateStr=y+'-'+(m+1).toString().padStart(2,'0')+'-'+d.toString().padStart(2,'0');
            var isToday=dateStr===today?' today':'';
            html+='<div class="ac-cal-day'+isToday+'" data-date="'+dateStr+'" onclick="AC.cal.onDayClick(\''+dateStr+'\')">';
            html+='<div class="num">'+d+'</div>';
            // Events for this date
            AC.cal.events.forEach(function(ev){
                if(ev.start_date<=dateStr && (ev.end_date||ev.start_date)>=dateStr){
                    html+='<div class="ac-cal-dot '+esc(ev.type)+'" onclick="event.stopPropagation();AC.cal.editEvent(\''+esc(ev.id)+'\')">'+esc(ev.title)+'</div>';
                }
            });
            html+='</div>';
        }

        // Next month padding
        var totalCells=startDay-1+days;
        var remaining=totalCells%7===0?0:7-totalCells%7;
        for(var i=1;i<=remaining;i++){
            html+='<div class="ac-cal-day other"><div class="num">'+i+'</div></div>';
        }

        document.getElementById('calGrid').innerHTML=html;
    },
    onDayClick:function(date){
        document.getElementById('calEditId').value='';
        document.getElementById('calTitle').value='';
        document.getElementById('calType').value='event';
        document.getElementById('calStart').value=date;
        document.getElementById('calEnd').value=date;
        document.getElementById('calDesc').value='';
        document.getElementById('calDeleteBtn').style.display='none';
        document.getElementById('calForm').classList.add('show');
        document.getElementById('calTitle').focus();
    },
    showAddForm:function(){
        var today=new Date().toISOString().slice(0,10);
        AC.cal.onDayClick(today);
    },
    hideForm:function(){document.getElementById('calForm').classList.remove('show')},
    editEvent:function(id){
        var ev=AC.cal.events.find(function(e){return e.id===id});
        if(!ev)return;
        document.getElementById('calEditId').value=id;
        document.getElementById('calTitle').value=ev.title||'';
        document.getElementById('calType').value=ev.type||'event';
        document.getElementById('calStart').value=ev.start_date||'';
        document.getElementById('calEnd').value=ev.end_date||'';
        document.getElementById('calDesc').value=ev.description||'';
        document.getElementById('calDeleteBtn').style.display='inline-flex';
        document.getElementById('calForm').classList.add('show');
    },
    deleteEditingEvent:function(){
        var id=document.getElementById('calEditId').value;
        if(!id){toast('No event selected',false);return}
        AC.cal.deleteEvent(id);
    },
    saveEvent:function(){
        var title=document.getElementById('calTitle').value.trim();
        var start=document.getElementById('calStart').value;
        if(!title||!start){toast('Title and date required',false);return}
        post('academic/save_event',{
            id:document.getElementById('calEditId').value,
            title:title,
            type:document.getElementById('calType').value,
            start_date:start,
            end_date:document.getElementById('calEnd').value||start,
            description:document.getElementById('calDesc').value.trim()
        }).then(function(d){
            if(d.status==='success'){
                AC.cal.hideForm();
                AC.cal.loadMonth();
                toast('Event saved',true);
            } else toast(d.message,false);
        });
    },
    deleteEvent:function(id){
        if(!confirm('Delete this event?'))return;
        post('academic/delete_event',{id:id}).then(function(d){
            if(d.status==='success'){AC.cal.loadMonth();toast('Event deleted',true)}
            else toast(d.message,false);
        });
    }
};

/* ══════════════════════════════════════════════════════════════
   MASTER TIMETABLE — Production Grade
══════════════════════════════════════════════════════════════ */
AC.tt = {
    _loaded:false,
    settings:null,
    timetables:{},
    classes:[],
    _subjectAssignments:{}, // from Academic/Subject_Assignments — for period-limit checks
    day:'Monday',
    view:'class', // 'class' or 'teacher'
    filter:'',    // class filter
    _subColors:{},
    _colorPalette:['#0f766e','#2563eb','#9333ea','#ea580c','#dc2626','#0891b2','#65a30d','#c026d3','#d97706','#4f46e5','#059669','#be185d','#6d28d9','#0284c7','#ca8a04'],
    _conflicts:{teacher:[],period_limit:[],duplicate:[]},
    _conflictMap:{}, // label → day → period → [{type,severity,message}]

    init:function(){},

    load:function(){
        AC.tt._loaded=true;
        document.getElementById('ttGridWrap').innerHTML='<div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading timetable data...</div>';
        loadSharedData(function(){
            var teacherPromise=(_teachers.length>0)?Promise.resolve():post('academic/get_all_teachers').then(function(d){
                if(d.status==='success') _teachers=d.teachers||[];
            });
            var ttPromise=post('academic/get_master_timetable');
            Promise.all([teacherPromise,ttPromise]).then(function(results){
                var d=results[1];
                if(d.status==='success'){
                    AC.tt.settings=d.settings;
                    AC.tt.timetables=d.timetables||{};
                    AC.tt.classes=d.classes||[];
                    AC.tt._subjectAssignments=d.subject_assignments||{};
                    AC.tt._buildSubjectColors();
                    AC.tt._populateClassFilter();
                    AC.tt._detectAllConflicts();
                    AC.tt.renderSettings();
                    AC.tt.render();
                } else {
                    document.getElementById('ttGridWrap').innerHTML='<div class="ac-empty"><i class="fa fa-exclamation-triangle"></i> '+esc(d.message)+'</div>';
                }
            });
        });
    },

    // Extract subject name from a timetable cell (string or {subject,teacher_id,...})
    _cellSubject:function(cell){
        if(!cell) return '';
        if(typeof cell==='object' && cell.subject) return cell.subject;
        if(typeof cell==='string') return cell;
        return '';
    },
    _cellTeacher:function(cell){
        if(!cell) return {id:'',name:''};
        if(typeof cell==='object') return {id:cell.teacher_id||'',name:cell.teacher_name||''};
        return {id:'',name:''};
    },

    _buildSubjectColors:function(){
        // Collect all unique subjects across all timetables
        var allSubs={};
        var labels=Object.keys(AC.tt.timetables);
        labels.forEach(function(lbl){
            var tt=AC.tt.timetables[lbl]||{};
            Object.keys(tt).forEach(function(day){
                var periods=tt[day]||[];
                periods.forEach(function(s){
                    var name=AC.tt._cellSubject(s);
                    if(name) allSubs[name]=true;
                });
            });
        });
        var names=Object.keys(allSubs).sort();
        AC.tt._subColors={};
        names.forEach(function(name,i){
            AC.tt._subColors[name]=AC.tt._colorPalette[i%AC.tt._colorPalette.length];
        });
        // Render legend
        var leg=document.getElementById('ttLegend');
        if(names.length>0){
            var h='';
            names.forEach(function(name){
                var c=AC.tt._subColors[name];
                h+='<span style="display:inline-flex;align-items:center;gap:4px"><span style="width:10px;height:10px;border-radius:2px;background:'+c+';flex-shrink:0"></span>'+esc(name)+'</span>';
            });
            leg.innerHTML=h;
            leg.style.display='flex';
        } else {
            leg.style.display='none';
        }
    },

    _populateClassFilter:function(){
        var sel=document.getElementById('ttClassFilter');
        sel.innerHTML='<option value="">All Classes</option>';
        // Extract unique class keys
        var classKeys=[];
        AC.tt.classes.forEach(function(c){
            if(classKeys.indexOf(c.class_key)===-1) classKeys.push(c.class_key);
        });
        classKeys.forEach(function(k){
            sel.innerHTML+='<option value="'+esc(k)+'">'+esc(k)+'</option>';
        });
    },

    /* ── Conflict Detection Engine (runs entirely on cached data) ── */

    _toFbKey:function(key){
        return (key||'').replace(/[.$#\[\]\/]/g,'_');
    },

    /**
     * Scan ALL timetables and build _conflicts + _conflictMap.
     * Called after load(), and after any save that mutates local cache.
     */
    _detectAllConflicts:function(){
        var conflicts={teacher:[],period_limit:[],duplicate:[]};
        var cmap={}; // label → day → period → [{type,severity,msg}]
        var s=AC.tt.settings;
        if(!s||!s.no_of_periods){
            AC.tt._conflicts=conflicts;
            AC.tt._conflictMap=cmap;
            AC.tt._renderConflictBar();
            return;
        }
        var np=s.no_of_periods;
        var days=s.working_days||['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var labels=Object.keys(AC.tt.timetables).sort();

        function addToMap(label,day,p,type,severity,msg){
            if(!cmap[label])cmap[label]={};
            if(!cmap[label][day])cmap[label][day]={};
            if(!cmap[label][day][p])cmap[label][day][p]=[];
            cmap[label][day][p].push({type:type,severity:severity,message:msg});
        }

        // 1. Teacher conflicts: same teacher_id in same day+period across different classes
        days.forEach(function(day){
            for(var p=0;p<np;p++){
                var teacherMap={}; // tid → {name, classes:[label]}
                labels.forEach(function(label){
                    var cell=((AC.tt.timetables[label]||{})[day]||[])[p];
                    var tchr=AC.tt._cellTeacher(cell);
                    if(tchr.id){
                        if(!teacherMap[tchr.id])teacherMap[tchr.id]={name:tchr.name,classes:[]};
                        teacherMap[tchr.id].classes.push(label);
                    }
                });
                var tids=Object.keys(teacherMap);
                for(var ti=0;ti<tids.length;ti++){
                    var entry=teacherMap[tids[ti]];
                    if(entry.classes.length>1){
                        var msg=entry.name+' is double-booked in '+entry.classes.join(', ')+' on '+day+' P'+(p+1);
                        conflicts.teacher.push({day:day,period:p,teacher_id:tids[ti],teacher_name:entry.name,classes:entry.classes});
                        entry.classes.forEach(function(lbl){addToMap(lbl,day,p,'teacher','error',msg)});
                    }
                }
            }
        });

        // 2. Duplicate subject on same day in same class (>1 occurrence = warning)
        labels.forEach(function(label){
            var tt=AC.tt.timetables[label]||{};
            days.forEach(function(day){
                var periods=tt[day]||[];
                var subMap={}; // subject → [period_indices]
                for(var p=0;p<np;p++){
                    var sub=AC.tt._cellSubject(periods[p]);
                    if(sub){
                        if(!subMap[sub])subMap[sub]=[];
                        subMap[sub].push(p);
                    }
                }
                var subs=Object.keys(subMap);
                for(var si=0;si<subs.length;si++){
                    if(subMap[subs[si]].length>1){
                        var msg=subs[si]+' appears '+subMap[subs[si]].length+' times on '+day;
                        conflicts.duplicate.push({class_label:label,day:day,subject:subs[si],count:subMap[subs[si]].length,periods:subMap[subs[si]]});
                        subMap[subs[si]].forEach(function(pi){addToMap(label,day,pi,'duplicate','warning',msg)});
                    }
                }
            });
        });

        // 3. Period-limit violations (weekly subject count > periods_week)
        var sa=AC.tt._subjectAssignments;
        if(sa && typeof sa==='object'){
            labels.forEach(function(label){
                var cls=AC.tt.classes.find(function(c){return c.label===label});
                if(!cls)return;
                var fbKey=AC.tt._toFbKey(cls.class_key);
                var assigned=sa[fbKey];
                if(!assigned||typeof assigned!=='object')return;

                // Count subject occurrences across all days
                var subCounts={};
                var tt=AC.tt.timetables[label]||{};
                days.forEach(function(day){
                    var periods=tt[day]||[];
                    for(var p=0;p<periods.length;p++){
                        var sub=AC.tt._cellSubject(periods[p]);
                        if(sub) subCounts[sub]=(subCounts[sub]||0)+1;
                    }
                });

                // Compare with limits
                var codes=Object.keys(assigned);
                for(var ci=0;ci<codes.length;ci++){
                    var info=assigned[codes[ci]];
                    if(!info||typeof info!=='object')continue;
                    var limit=parseInt(info.periods_week)||0;
                    if(limit<=0)continue;
                    var name=info.name||codes[ci];
                    var actual=subCounts[name]||0;
                    if(actual>limit){
                        conflicts.period_limit.push({class_label:label,subject:name,actual:actual,limit:limit});
                    }
                }
            });
        }

        AC.tt._conflicts=conflicts;
        AC.tt._conflictMap=cmap;
        AC.tt._renderConflictBar();
    },

    _renderConflictBar:function(){
        var bar=document.getElementById('ttConflictBar');
        if(!bar)return;
        var c=AC.tt._conflicts;
        var teacherN=c.teacher.length;
        var dupN=c.duplicate.length;
        var limitN=c.period_limit.length;
        var total=teacherN+dupN+limitN;

        if(total===0){
            bar.innerHTML='<div class="ac-conflict-bar clean"><i class="fa fa-check-circle"></i> <strong>No conflicts detected</strong></div>';
            bar.style.display='block';
            return;
        }

        var cls=teacherN>0?'has-errors':'has-warnings';
        var parts=[];
        if(teacherN>0) parts.push('<i class="fa fa-exclamation-circle"></i> <strong>'+teacherN+' teacher conflict(s)</strong>');
        if(limitN>0) parts.push('<i class="fa fa-tachometer"></i> '+limitN+' period-limit violation(s)');
        if(dupN>0) parts.push('<i class="fa fa-clone"></i> '+dupN+' duplicate subject warning(s)');
        bar.innerHTML='<div class="ac-conflict-bar '+cls+'">'+parts.join(' &nbsp;|&nbsp; ')+'</div>';
        bar.style.display='block';
    },

    /** Check if a specific cell has conflicts. Returns array of {type,severity,message} or []. */
    _cellConflicts:function(label,day,period){
        var m=AC.tt._conflictMap;
        if(m[label]&&m[label][day]&&m[label][day][period]) return m[label][day][period];
        return[];
    },

    /**
     * Client-side pre-save conflict check for editCell.
     * Returns {errors:[], warnings:[]} based on cached data.
     */
    _checkCellConflict:function(classKey,section,day,periodIdx,subject,teacherId,teacherName){
        var result={errors:[],warnings:[]};
        if(!subject)return result;
        var np=(AC.tt.settings||{}).no_of_periods||6;
        var days=(AC.tt.settings||{}).working_days||['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var labels=Object.keys(AC.tt.timetables).sort();
        var ownLabel=(AC.tt.classes.find(function(c){return c.class_key===classKey&&c.section===section})||{}).label||'';

        // 1. Teacher conflict
        if(teacherId){
            labels.forEach(function(label){
                if(label===ownLabel)return;
                var cell=((AC.tt.timetables[label]||{})[day]||[])[periodIdx];
                var tchr=AC.tt._cellTeacher(cell);
                if(tchr.id&&tchr.id===teacherId){
                    result.errors.push('Teacher conflict: '+teacherName+' already in '+label+' on '+day+' P'+(periodIdx+1));
                }
            });
        }

        // 2. Duplicate subject same day
        var ownTT=AC.tt.timetables[ownLabel]||{};
        var dayPeriods=ownTT[day]||[];
        var dupCount=0;
        for(var p=0;p<np;p++){
            if(p===periodIdx)continue;
            var sub=AC.tt._cellSubject(dayPeriods[p]);
            if(sub&&sub.toLowerCase()===subject.toLowerCase()) dupCount++;
        }
        if(dupCount>0) result.warnings.push(subject+' already has '+dupCount+' other period(s) on '+day);

        // 3. Period-limit check
        var sa=AC.tt._subjectAssignments;
        if(sa&&typeof sa==='object'){
            var fbKey=AC.tt._toFbKey(classKey);
            var assigned=sa[fbKey];
            if(assigned&&typeof assigned==='object'){
                var limit=0;
                var codes=Object.keys(assigned);
                for(var i=0;i<codes.length;i++){
                    var info=assigned[codes[i]];
                    if(info&&(info.name||'').toLowerCase()===subject.toLowerCase()){
                        limit=parseInt(info.periods_week)||0;
                        break;
                    }
                }
                if(limit>0){
                    var weekCount=0;
                    days.forEach(function(d){
                        var pds=(ownTT[d]||[]);
                        for(var pi=0;pi<pds.length;pi++){
                            if(d===day&&pi===periodIdx)continue;
                            var s=AC.tt._cellSubject(pds[pi]);
                            if(s&&s.toLowerCase()===subject.toLowerCase()) weekCount++;
                        }
                    });
                    var newTotal=weekCount+1;
                    if(newTotal>limit) result.warnings.push(subject+': '+newTotal+' periods/week exceeds limit of '+limit);
                }
            }
        }

        return result;
    },

    // Calculate period time ranges from settings
    _getPeriodTimes:function(){
        var s=AC.tt.settings;
        if(!s)return[];
        // Parse start time
        var startMin=AC.tt._parseTime(s.start_time);
        var periodLen=s.length_of_period||45;
        var recMap={};
        (s.recesses||[]).forEach(function(r){recMap[r.after_period]=r.duration});

        var times=[];
        var current=startMin;
        for(var p=1;p<=s.no_of_periods;p++){
            var endMin=current+periodLen;
            times.push({start:AC.tt._fmtTime(current),end:AC.tt._fmtTime(endMin)});
            current=endMin;
            if(recMap[p]) current+=recMap[p]; // Add recess duration
        }
        return times;
    },

    _parseTime:function(str){
        if(!str)return 540; // default 9:00
        str=str.toUpperCase().trim();
        var m=str.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)?$/);
        if(!m)return 540;
        var h=parseInt(m[1]),min=parseInt(m[2]),ampm=m[3]||'';
        if(ampm==='PM'&&h!==12) h+=12;
        if(ampm==='AM'&&h===12) h=0;
        return h*60+min;
    },

    _fmtTime:function(minutes){
        var h=Math.floor(minutes/60)%24;
        var m=minutes%60;
        var ampm=h>=12?'PM':'AM';
        var h12=h%12||12;
        return h12+':'+String(m).padStart(2,'0')+ampm;
    },

    renderSettings:function(){
        var s=AC.tt.settings;
        if(!s)return;
        var rStr='';
        if(s.recesses&&s.recesses.length>0){
            rStr=s.recesses.map(function(r){return 'after P'+r.after_period+' ('+r.duration+'min)'}).join(', ');
        }
        document.getElementById('ttSettingsSummary').innerHTML=
            '<i class="fa fa-clock-o"></i> '+esc(s.start_time)+' — '+esc(s.end_time)+
            ' &nbsp;|&nbsp; '+s.no_of_periods+' periods × '+s.length_of_period+'min'+
            (rStr?' &nbsp;|&nbsp; Recess: '+esc(rStr):'');
    },

    render:function(){
        if(AC.tt.view==='teacher') AC.tt.renderTeacherView();
        else if(AC.tt.day==='_week') AC.tt.renderWeekView();
        else AC.tt.renderGrid();
    },

    renderGrid:function(){
        var s=AC.tt.settings;
        if(!s||!s.no_of_periods){
            document.getElementById('ttGridWrap').innerHTML='<div class="ac-empty"><i class="fa fa-cog"></i>No timetable settings configured. Set up periods in the Period Scheduling tab.</div>';
            document.getElementById('ttFillRate').style.display='none';
            return;
        }

        var np=s.no_of_periods;
        var recMap={};
        (s.recesses||[]).forEach(function(r){recMap[r.after_period]=r.duration});
        var day=AC.tt.day;
        var times=AC.tt._getPeriodTimes();
        var filter=AC.tt.filter;

        // Build header with time ranges
        var html='<table class="ac-tt"><thead><tr><th style="min-width:140px">Class / Section</th>';
        for(var p=1;p<=np;p++){
            var timeStr=times[p-1]?('<span class="ac-th-time">'+times[p-1].start+' — '+times[p-1].end+'</span>'):'';
            html+='<th>P'+p+timeStr+'</th>';
            if(recMap[p]) html+='<th style="width:40px;font-size:9px;color:var(--t3)">Break</th>';
        }
        html+='</tr></thead><tbody>';

        // Rows
        var labels=Object.keys(AC.tt.timetables).sort();
        var totalSlots=0,filledSlots=0,emptyClasses=[];

        if(labels.length===0){
            html+='<tr><td colspan="'+(np+1+Object.keys(recMap).length)+'" style="text-align:center;padding:30px;color:var(--t3)">No timetable data found</td></tr>';
        }

        labels.forEach(function(label){
            // Apply class filter
            var cls=AC.tt.classes.find(function(c){return c.label===label});
            if(filter && cls && cls.class_key!==filter) return;

            var classKey=cls?cls.class_key:'';
            var section=cls?cls.section:'';
            var tt=AC.tt.timetables[label]||{};
            var periods=tt[day]||[];

            var rowEmpty=0;
            for(var p=0;p<np;p++){
                totalSlots++;
                var cellSub=AC.tt._cellSubject(periods[p]);
                if(cellSub) filledSlots++;
                else rowEmpty++;
            }
            if(rowEmpty>0) emptyClasses.push(label+' ('+rowEmpty+')');

            var rowCls=rowEmpty>0?' class="ac-row-incomplete"':'';
            html+='<tr'+rowCls+'><td>'+esc(label);
            if(rowEmpty>0) html+=' <span style="font-size:9px;color:#d97706" title="'+rowEmpty+' empty slot(s)">'+rowEmpty+' empty</span>';
            html+='</td>';

            for(var p=0;p<np;p++){
                var cell=periods[p]||'';
                var sub=AC.tt._cellSubject(cell);
                var tchr=AC.tt._cellTeacher(cell);
                var color=AC.tt._subColors[sub]||'';
                // Conflict detection for this cell
                var cellConflicts=AC.tt._cellConflicts(label,day,p);
                var hasError=cellConflicts.some(function(c){return c.severity==='error'});
                var hasWarn=!hasError&&cellConflicts.length>0;
                var cellCls=hasError?' ac-conflict':(hasWarn?' ac-conflict-warn':(sub?'':' ac-empty-cell'));
                var conflictTitle=cellConflicts.map(function(c){return c.message}).join('\n');
                var titleAttr=conflictTitle||(_canEdit?'Click to edit':'View only');
                var editAttr=_canEdit?' onclick="AC.tt.editCell(\''+esc(classKey)+'\',\''+esc(section)+'\',\''+esc(day)+'\','+p+',this)"':'';
                html+='<td class="'+cellCls+'"'+editAttr+' title="'+esc(titleAttr)+'">';
                if(hasError) html+='<span class="ac-conflict-icon err" title="'+esc(conflictTitle)+'">!</span>';
                else if(hasWarn) html+='<span class="ac-conflict-icon warn" title="'+esc(conflictTitle)+'">!</span>';
                if(sub){
                    html+='<div class="ac-tt-cell has-sub" style="--sub-color:'+color+'">'+esc(sub);
                    if(tchr.name) html+='<div style="font-size:9px;color:var(--t3);margin-top:1px">'+esc(tchr.name)+'</div>';
                    html+='</div>';
                } else {
                    html+='<div class="ac-tt-cell" style="color:var(--t3);font-size:10px">—</div>';
                }
                html+='</td>';
                if(recMap[p+1]) html+='<td class="ac-recess">Break</td>';
            }
            html+='</tr>';
        });

        html+='</tbody></table>';
        document.getElementById('ttGridWrap').innerHTML=html;

        // Fill rate
        var fr=document.getElementById('ttFillRate');
        if(totalSlots>0){
            var pct=Math.round(filledSlots/totalSlots*100);
            document.getElementById('ttFillPct').textContent=pct+'%';
            var emptyCount=totalSlots-filledSlots;
            document.getElementById('ttFillEmpty').textContent=emptyCount>0?emptyCount+' empty slot(s)':'All filled!';
            document.getElementById('ttFillEmpty').style.color=emptyCount>0?'#d97706':'#16a34a';
            fr.style.display='block';
        } else {
            fr.style.display='none';
        }
    },

    renderWeekView:function(){
        var s=AC.tt.settings;
        if(!s||!s.no_of_periods){
            document.getElementById('ttGridWrap').innerHTML='<div class="ac-empty"><i class="fa fa-cog"></i>No timetable settings configured. Set up periods in the Period Scheduling tab.</div>';
            return;
        }

        var filter=AC.tt.filter;
        var days=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var np=s.no_of_periods;
        var times=AC.tt._getPeriodTimes();

        // Build one table per filtered class
        var labels=Object.keys(AC.tt.timetables).sort();
        var html='';

        labels.forEach(function(label){
            var cls=AC.tt.classes.find(function(c){return c.label===label});
            if(filter && cls && cls.class_key!==filter) return;

            html+='<div style="margin-bottom:18px"><h4 style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:6px;font-family:var(--font-b)"><i class="fa fa-graduation-cap" style="color:var(--gold);margin-right:6px"></i>'+esc(label)+'</h4>';
            html+='<table class="ac-tt-week"><thead><tr><th style="width:70px">Day</th>';
            for(var p=1;p<=np;p++){
                var timeStr=times[p-1]?('<span class="ac-th-time" style="display:block;font-size:8px;font-weight:400;color:var(--t3)">'+times[p-1].start+'</span>'):'';
                html+='<th>P'+p+timeStr+'</th>';
            }
            html+='</tr></thead><tbody>';

            var tt=AC.tt.timetables[label]||{};
            days.forEach(function(day){
                var periods=tt[day]||[];
                html+='<tr><td style="font-weight:700;font-size:10px;color:var(--t2)">'+day.substr(0,3)+'</td>';
                for(var p=0;p<np;p++){
                    var cell=periods[p]||'';
                    var sub=AC.tt._cellSubject(cell);
                    var tchr=AC.tt._cellTeacher(cell);
                    var color=AC.tt._subColors[sub]||'';
                    if(sub){
                        html+='<td><div class="ac-tt-cell has-sub" style="--sub-color:'+color+'">'+esc(sub);
                        if(tchr.name) html+='<div style="font-size:8px;color:var(--t3)">'+esc(tchr.name)+'</div>';
                        html+='</div></td>';
                    } else {
                        html+='<td style="color:var(--t3);font-size:9px">—</td>';
                    }
                }
                html+='</tr>';
            });
            html+='</tbody></table></div>';
        });

        if(!html) html='<div class="ac-empty"><i class="fa fa-th"></i>No data for selected filter</div>';
        document.getElementById('ttGridWrap').innerHTML=html;
        document.getElementById('ttFillRate').style.display='none';
    },

    renderTeacherView:function(){
        var s=AC.tt.settings;
        if(!s||!s.no_of_periods){
            document.getElementById('ttGridWrap').innerHTML='<div class="ac-empty"><i class="fa fa-cog"></i>No timetable settings configured. Set up periods in the Period Scheduling tab.</div>';
            return;
        }

        var np=s.no_of_periods;
        var day=AC.tt.day==='_week'?'Monday':AC.tt.day;
        var times=AC.tt._getPeriodTimes();
        var recMap={};
        (s.recesses||[]).forEach(function(r){recMap[r.after_period]=r.duration});

        // Build subject → period → [class labels] map, handling both string and object cells
        var subjectMap={};
        var labels=Object.keys(AC.tt.timetables).sort();
        labels.forEach(function(label){
            var tt=AC.tt.timetables[label]||{};
            var periods=tt[day]||[];
            for(var p=0;p<np;p++){
                var sub=AC.tt._cellSubject(periods[p]);
                if(!sub)continue;
                if(!subjectMap[sub])subjectMap[sub]={};
                if(!subjectMap[sub][p])subjectMap[sub][p]=[];
                subjectMap[sub][p].push(label);
            }
        });

        var subjects=Object.keys(subjectMap).sort();

        // Header
        var html='<table class="ac-tt"><thead><tr><th style="min-width:140px">Subject</th>';
        for(var p=1;p<=np;p++){
            var timeStr=times[p-1]?('<span class="ac-th-time">'+times[p-1].start+' — '+times[p-1].end+'</span>'):'';
            html+='<th>P'+p+timeStr+'</th>';
            if(recMap[p]) html+='<th style="width:40px;font-size:9px;color:var(--t3)">Break</th>';
        }
        html+='</tr></thead><tbody>';

        if(subjects.length===0){
            html+='<tr><td colspan="'+(np+1+Object.keys(recMap).length)+'" style="text-align:center;padding:30px;color:var(--t3)">No timetable data for '+esc(day)+'</td></tr>';
        }

        subjects.forEach(function(sub){
            var color=AC.tt._subColors[sub]||'var(--gold)';
            html+='<tr><td style="font-weight:700"><span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:'+color+';margin-right:6px"></span>'+esc(sub)+'</td>';
            for(var p=0;p<np;p++){
                var classes=subjectMap[sub][p]||[];
                if(classes.length>0){
                    html+='<td style="font-size:10px;line-height:1.4;text-align:left;padding-left:6px">';
                    classes.forEach(function(c){html+='<div>'+esc(c.replace('Class ','').replace(' / Section ',' '))+'</div>'});
                    html+='</td>';
                } else {
                    html+='<td style="color:var(--t3);font-size:10px">—</td>';
                }
                if(recMap[p+1]) html+='<td class="ac-recess">Break</td>';
            }
            html+='</tr>';
        });

        html+='</tbody></table>';
        html+='<div style="margin-top:10px;font-size:11px;color:var(--t3);font-style:italic"><i class="fa fa-info-circle"></i> Teacher View shows which classes have each subject per period. Assign class teachers in Classes module for full teacher-level scheduling.</div>';
        document.getElementById('ttGridWrap').innerHTML=html;
        document.getElementById('ttFillRate').style.display='none';
    },

    editCell:function(classKey,section,day,periodIdx,td){
        if(!_canEdit)return;
        if(td.querySelector('select'))return;

        // Read current cell data (may be string or object)
        var lbl=(AC.tt.classes.find(function(c){return c.class_key===classKey&&c.section===section})||{}).label||'';
        var cellData=((AC.tt.timetables[lbl]||{})[day]||[])[periodIdx]||'';
        var current=AC.tt._cellSubject(cellData);
        var currentTeacher=AC.tt._cellTeacher(cellData);

        var m=classKey.match(/(\d+)/);
        var num=m?m[1]:'';
        var nameKey=classKey.replace('Class ','');
        var subs=(_subjects[num]||[]).concat(_subjects[nameKey]||[]);
        var seen={};
        subs=subs.filter(function(s){if(seen[s.name])return false;seen[s.name]=true;return true});

        // Build inline edit container
        var wrap=document.createElement('div');
        wrap.style.cssText='display:flex;flex-direction:column;gap:3px';

        var sel=document.createElement('select');
        sel.style.cssText='width:100%;padding:3px;font-size:11px;border:1px solid var(--gold);border-radius:4px;background:var(--bg2);color:var(--t1)';
        sel.innerHTML='<option value="">— Empty —</option>';
        subs.forEach(function(s){
            var label=s.name+(s.code&&s.code!==s.name?' ('+s.code+')':'');
            sel.innerHTML+='<option value="'+esc(s.name)+'"'+(s.name===current?' selected':'')+'>'+esc(label)+'</option>';
        });
        if(current && !subs.some(function(s){return s.name===current})){
            sel.innerHTML+='<option value="'+esc(current)+'" selected>'+esc(current)+'</option>';
        }

        // Teacher dropdown
        var tSel=document.createElement('select');
        tSel.style.cssText='width:100%;padding:2px;font-size:10px;border:1px solid var(--border);border-radius:4px;background:var(--bg3);color:var(--t2)';
        tSel.innerHTML='<option value="">No teacher</option>';
        _teachers.forEach(function(t){
            tSel.innerHTML+='<option value="'+esc(t.id)+'" data-name="'+esc(t.name)+'"'+(t.id===currentTeacher.id?' selected':'')+'>'+esc(t.name)+'</option>';
        });

        // Warning box (shown inline below dropdowns)
        var warnBox=document.createElement('div');
        warnBox.style.cssText='display:none';

        wrap.appendChild(sel);
        wrap.appendChild(tSel);
        wrap.appendChild(warnBox);
        td.innerHTML='';
        td.appendChild(wrap);
        sel.focus();

        // Restore cell to its original display
        function restoreCell(){
            // Remove conflict classes from td
            td.classList.remove('ac-conflict','ac-conflict-warn');
            var cellConflicts=AC.tt._cellConflicts(lbl,day,periodIdx);
            if(cellConflicts.some(function(c){return c.severity==='error'})) td.classList.add('ac-conflict');
            else if(cellConflicts.length>0) td.classList.add('ac-conflict-warn');

            if(current){
                var color=AC.tt._subColors[current]||'';
                var tName=currentTeacher.name||'';
                td.innerHTML='<div class="ac-tt-cell has-sub" style="--sub-color:'+color+'">'+esc(current);
                if(tName) td.innerHTML+='<div style="font-size:9px;color:var(--t3);margin-top:1px">'+esc(tName)+'</div>';
                td.innerHTML+='</div>';
            } else {
                td.innerHTML='<div class="ac-tt-cell" style="color:var(--t3);font-size:10px">—</div>';
            }
            // Re-add conflict icon if needed
            if(cellConflicts.length>0){
                var hasErr=cellConflicts.some(function(c){return c.severity==='error'});
                var icon=document.createElement('span');
                icon.className='ac-conflict-icon '+(hasErr?'err':'warn');
                icon.textContent='!';
                icon.title=cellConflicts.map(function(c){return c.message}).join('\n');
                td.appendChild(icon);
            }
        }

        /** Run client-side conflict check and update warnBox. Returns true if BLOCKED (teacher conflict). */
        function checkConflicts(){
            var val=sel.value;
            var tOpt=tSel.options[tSel.selectedIndex];
            var tId=tSel.value;
            var tName=tOpt?tOpt.dataset.name||'':'';
            if(!val){warnBox.style.display='none';return false}

            var result=AC.tt._checkCellConflict(classKey,section,day,periodIdx,val,tId,tName);
            var hasIssues=(result.errors.length+result.warnings.length)>0;
            if(!hasIssues){warnBox.style.display='none';return false}

            var html='';
            result.errors.forEach(function(msg){
                html+='<div class="ac-edit-warn err"><i class="fa fa-ban"></i> '+esc(msg)+'</div>';
            });
            result.warnings.forEach(function(msg){
                html+='<div class="ac-edit-warn warn"><i class="fa fa-exclamation-triangle"></i> '+esc(msg)+'</div>';
            });
            warnBox.innerHTML=html;
            warnBox.style.display='block';
            return result.errors.length>0; // blocked if any errors
        }

        var saving=false;
        function save(){
            if(saving)return;
            // Run client-side check first
            var blocked=checkConflicts();
            if(blocked){toast('Cannot save: teacher conflict detected',false);return}

            saving=true;
            var val=sel.value;
            var tOpt=tSel.options[tSel.selectedIndex];
            var tId=tSel.value;
            var tName=tOpt?tOpt.dataset.name||'':'';
            post('academic/save_period',{class_key:classKey,section:section,day:day,period_index:periodIdx,subject:val,teacher_id:tId,teacher_name:tName}).then(function(d){
                if(d.status==='success'){
                    // Update local cache
                    if(lbl){
                        if(!AC.tt.timetables[lbl])AC.tt.timetables[lbl]={};
                        if(!AC.tt.timetables[lbl][day])AC.tt.timetables[lbl][day]=[];
                        while(AC.tt.timetables[lbl][day].length<=periodIdx)AC.tt.timetables[lbl][day].push('');
                        if(val && tId){
                            AC.tt.timetables[lbl][day][periodIdx]={subject:val,teacher_id:tId,teacher_name:tName};
                        } else {
                            AC.tt.timetables[lbl][day][periodIdx]=val;
                        }
                    }
                    if(val&&!AC.tt._subColors[val]) AC.tt._buildSubjectColors();

                    // Show server-side warnings if any
                    var serverWarns=d.warnings||[];
                    if(serverWarns.length>0){
                        toast('Saved with warnings: '+serverWarns.join('; '),true);
                    } else {
                        toast('Saved',true);
                    }

                    // Re-run conflict detection on updated cache, then re-render grid
                    AC.tt._detectAllConflicts();
                    AC.tt.renderGrid();
                } else {
                    restoreCell();
                    toast(d.message,false);
                }
                saving=false;
            });
        }

        // Live conflict preview on dropdown change
        sel.addEventListener('change',function(){
            var blocked=checkConflicts();
            if(!blocked) save();
        });
        tSel.addEventListener('change',function(){
            var blocked=checkConflicts();
            if(!blocked) save();
        });
        wrap.addEventListener('focusout',function(e){
            setTimeout(function(){
                if(!wrap.contains(document.activeElement)) restoreCell();
            },250);
        });
    },

    copyDay:function(){
        var fromDay=AC.tt.day;
        if(fromDay==='_week'){toast('Switch to a specific day first',false);return}
        var days=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var opts=days.filter(function(d){return d!==fromDay}).map(function(d){return d}).join(', ');
        var toDay=prompt('Copy '+fromDay+' timetable to which day?\n\nOptions: '+opts);
        if(!toDay)return;
        toDay=toDay.trim();
        // Normalize
        toDay=toDay.charAt(0).toUpperCase()+toDay.slice(1).toLowerCase();
        if(days.indexOf(toDay)===-1){toast('Invalid day: '+toDay,false);return}
        if(toDay===fromDay){toast('Cannot copy to same day',false);return}
        if(!confirm('Copy ALL class timetables from '+fromDay+' to '+toDay+'?\nThis will overwrite '+toDay+'\'s data.'))return;

        var labels=Object.keys(AC.tt.timetables);
        var promises=[];
        labels.forEach(function(label){
            var cls=AC.tt.classes.find(function(c){return c.label===label});
            if(!cls)return;
            var tt=AC.tt.timetables[label]||{};
            var periods=tt[fromDay]||[];
            if(periods.length===0)return;
            // Save each period (handle both string and object cells)
            periods.forEach(function(cell,p){
                var sub=AC.tt._cellSubject(cell);
                var tchr=AC.tt._cellTeacher(cell);
                promises.push(post('academic/save_period',{
                    class_key:cls.class_key,section:cls.section,day:toDay,period_index:p,subject:sub||'',teacher_id:tchr.id||'',teacher_name:tchr.name||''
                }));
            });
            // Update local cache
            if(!AC.tt.timetables[label])AC.tt.timetables[label]={};
            AC.tt.timetables[label][toDay]=periods.slice();
        });

        Promise.all(promises).then(function(){
            toast('Copied '+fromDay+' → '+toDay+' ('+promises.length+' cells)',true);
            AC.tt._detectAllConflicts();
            AC.tt.render();
        });
    },

    printTT:function(){
        var dayLabel=AC.tt.day==='_week'?'Full Week':AC.tt.day;
        document.getElementById('ttPrintDay').textContent='Day: '+dayLabel;
        window.print();
    }
};

// Day tab switching
document.getElementById('ttDayTabs').addEventListener('click',function(e){
    var tab=e.target.closest('.ac-day-tab');
    if(!tab)return;
    document.querySelectorAll('.ac-day-tab').forEach(function(t){t.classList.remove('active')});
    tab.classList.add('active');
    AC.tt.day=tab.dataset.day;
    if(AC.tt._loaded) AC.tt.render();
});

// View toggle (Class vs Teacher)
document.getElementById('ttViewToggle').addEventListener('click',function(e){
    var pill=e.target.closest('.ac-pill');
    if(!pill)return;
    document.querySelectorAll('#ttViewToggle .ac-pill').forEach(function(p){p.classList.remove('active')});
    pill.classList.add('active');
    AC.tt.view=pill.dataset.view;
    if(AC.tt._loaded) AC.tt.render();
});

// Class filter
document.getElementById('ttClassFilter').addEventListener('change',function(){
    AC.tt.filter=this.value;
    if(AC.tt._loaded) AC.tt.render();
});

/* ══════════════════════════════════════════════════════════════
   SUBSTITUTES
══════════════════════════════════════════════════════════════ */
AC.sub = {
    _loaded:false,
    records:[],
    _busyPeriods:[],
    _maxPeriods:6,
    init:function(){
        AC.sub._loaded=true;
        loadSharedData(function(){
            // Populate class dropdown
            var cs=document.getElementById('subClass');
            cs.innerHTML='<option value="">Select class...</option>';
            _classes.forEach(function(c){
                cs.innerHTML+='<option value="'+esc(c.class_section)+'" data-key="'+esc(c.class_key)+'">'+esc(c.label)+'</option>';
            });
            // Class change → populate subject dropdown
            cs.addEventListener('change',AC.sub.onClassChange);
            // Load teachers
            post('academic/get_all_teachers').then(function(d){
                if(d.status==='success'){
                    _teachers=d.teachers||[];
                    var abs=document.getElementById('subAbsent');
                    var sub=document.getElementById('subTeacher');
                    abs.innerHTML='<option value="">Select teacher...</option>';
                    sub.innerHTML='<option value="">Select teacher...</option>';
                    _teachers.forEach(function(t){
                        abs.innerHTML+='<option value="'+esc(t.id)+'" data-name="'+esc(t.name)+'">'+esc(t.name)+' ('+esc(t.id)+')</option>';
                        sub.innerHTML+='<option value="'+esc(t.id)+'" data-name="'+esc(t.name)+'">'+esc(t.name)+' ('+esc(t.id)+')</option>';
                    });
                }
            });
            // Substitute teacher change → check availability
            document.getElementById('subTeacher').addEventListener('change',AC.sub.checkAvailability);
            document.getElementById('subDate').addEventListener('change',AC.sub.checkAvailability);
            AC.sub.load();
        });
    },
    onClassChange:function(){
        var cs=document.getElementById('subClass');
        var opt=cs.options[cs.selectedIndex];
        var classKey=opt?opt.dataset.key:'';
        var subSel=document.getElementById('subSubject');
        subSel.innerHTML='<option value="">Select subject...</option>';
        if(!classKey)return;
        var m=classKey.match(/(\d+)/);
        var num=m?m[1]:'';
        var subs=_subjects[num]||[];
        subs.forEach(function(s){
            var label=s.name+(s.code&&s.code!==s.name?' ('+s.code+')':'');
            subSel.innerHTML+='<option value="'+esc(s.name)+'">'+esc(label)+'</option>';
        });
        var nameKey=classKey.replace('Class ','');
        var subs2=_subjects[nameKey]||[];
        subs2.forEach(function(s){
            if(subs.some(function(x){return x.name===s.name}))return;
            var label=s.name+(s.code&&s.code!==s.name?' ('+s.code+')':'');
            subSel.innerHTML+='<option value="'+esc(s.name)+'">'+esc(label)+'</option>';
        });
    },
    checkAvailability:function(){
        var teacherId=document.getElementById('subTeacher').value;
        var date=document.getElementById('subDate').value;
        var box=document.getElementById('subAvailability');
        if(!teacherId||!date){box.style.display='none';AC.sub._busyPeriods=[];return}
        post('academic/get_teacher_schedule',{teacher_id:teacherId,date:date}).then(function(d){
            if(d.status!=='success'){box.style.display='none';return}
            AC.sub._busyPeriods=d.busy_periods||[];
            AC.sub._maxPeriods=d.max_periods||6;
            if(AC.sub._busyPeriods.length>0){
                box.style.display='block';
                box.style.background='rgba(217,119,6,.12)';
                box.style.color='var(--t1)';
                box.style.border='1px solid rgba(217,119,6,.3)';
                box.innerHTML='<i class="fa fa-exclamation-triangle" style="color:#d97706;margin-right:6px"></i><strong>Busy periods:</strong> P'+AC.sub._busyPeriods.join(', P')+' (already covering another class on '+esc(d.day||date)+')';
            } else {
                box.style.display='block';
                box.style.background='rgba(15,118,110,.08)';
                box.style.color='var(--t1)';
                box.style.border='1px solid rgba(15,118,110,.2)';
                box.innerHTML='<i class="fa fa-check-circle" style="color:var(--gold);margin-right:6px"></i>Teacher is available for all '+AC.sub._maxPeriods+' periods on '+esc(d.day||date);
            }
        });
    },
    load:function(){
        var date=document.getElementById('subDateFilter').value||'';
        post('academic/get_substitutes',{date:date}).then(function(d){
            AC.sub.records=(d.status==='success')?(d.substitutes||[]):[];
            AC.sub.render();
        });
    },
    render:function(){
        var recs=AC.sub.records;
        if(recs.length===0){
            document.getElementById('subList').innerHTML='<div class="ac-empty"><i class="fa fa-exchange"></i>No substitute records found</div>';
            return;
        }
        var html='<table class="ac-table"><thead><tr><th>Date</th><th>Absent Teacher</th><th>Substitute</th><th>Class</th><th>Periods</th><th>Subject</th><th>Reason</th><th>Status</th><th>By</th><th>Actions</th></tr></thead><tbody>';
        recs.forEach(function(r){
            var badgeCls='ac-badge-asgn',lbl='Assigned';
            if(r.status==='completed'){badgeCls='ac-badge-comp';lbl='Completed'}
            if(r.status==='cancelled'){badgeCls='ac-badge-canc';lbl='Cancelled'}
            var periods=Array.isArray(r.periods)?r.periods.join(', '):'';
            // Date display: show range if multi-day
            var dateStr=esc(r.date);
            if(r.date_end && r.date_end!==r.date) dateStr+=' → '+esc(r.date_end);
            html+='<tr>';
            html+='<td style="white-space:nowrap">'+dateStr+'</td>';
            html+='<td>'+esc(r.absent_teacher_name)+'</td>';
            html+='<td>'+esc(r.substitute_teacher_name)+'</td>';
            html+='<td>'+esc(r.class_section)+'</td>';
            html+='<td>P'+esc(periods)+'</td>';
            html+='<td>'+esc(r.subject||'—')+'</td>';
            html+='<td>'+esc(r.reason||'—')+'</td>';
            html+='<td><span class="ac-badge '+badgeCls+'">'+lbl+'</span></td>';
            html+='<td style="font-size:11px;color:var(--t3)" title="Created: '+(r.created_at||'')+' by '+(r.created_by||'')+'">'+esc(r.updated_by||r.created_by||'')+'</td>';
            html+='<td style="white-space:nowrap">';
            if(r.status==='assigned'){
                html+='<button class="ac-btn ac-btn-s ac-btn-sm" onclick="AC.sub.setStatus(\''+esc(r.id)+'\',\'completed\')" title="Mark completed"><i class="fa fa-check"></i></button> ';
                html+='<button class="ac-btn ac-btn-d ac-btn-sm" onclick="AC.sub.setStatus(\''+esc(r.id)+'\',\'cancelled\')" title="Cancel"><i class="fa fa-times"></i></button> ';
            }
            html+='<button class="ac-btn ac-btn-d ac-btn-sm" onclick="AC.sub.del(\''+esc(r.id)+'\')" title="Delete"><i class="fa fa-trash"></i></button>';
            html+='</td></tr>';
        });
        html+='</tbody></table>';
        document.getElementById('subList').innerHTML=html;
    },
    showForm:function(){
        document.getElementById('subEditId').value='';
        document.getElementById('subDate').value=new Date().toISOString().slice(0,10);
        document.getElementById('subDateEnd').value='';
        document.getElementById('subAbsent').value='';
        document.getElementById('subTeacher').value='';
        document.getElementById('subClass').value='';
        document.getElementById('subSubject').innerHTML='<option value="">Select class first...</option>';
        document.getElementById('subPeriods').value='';
        document.getElementById('subReason').value='';
        document.getElementById('subAvailability').style.display='none';
        AC.sub._busyPeriods=[];
        document.getElementById('subForm').classList.add('show');
    },
    hideForm:function(){document.getElementById('subForm').classList.remove('show')},
    save:function(){
        var absEl=document.getElementById('subAbsent');
        var subEl=document.getElementById('subTeacher');
        var absOpt=absEl.options[absEl.selectedIndex];
        var subOpt=subEl.options[subEl.selectedIndex];
        var date=document.getElementById('subDate').value;
        var dateEnd=document.getElementById('subDateEnd').value||'';
        var cs=document.getElementById('subClass').value;
        if(!date||!absEl.value||!subEl.value||!cs){toast('Fill all required fields',false);return}
        if(absEl.value===subEl.value){toast('Absent and substitute cannot be the same teacher',false);return}

        var periodsStr=document.getElementById('subPeriods').value.trim();
        var periods=periodsStr?periodsStr.split(',').map(function(p){return parseInt(p.trim())}).filter(function(n){return!isNaN(n)&&n>=1}):[];
        if(periods.length===0){toast('Enter at least one valid period number',false);return}

        // Warn if assigning to busy periods
        if(AC.sub._busyPeriods.length>0){
            var overlap=periods.filter(function(p){return AC.sub._busyPeriods.indexOf(p)!==-1});
            if(overlap.length>0 && !confirm('Warning: Substitute teacher is already busy during period(s) '+overlap.join(', ')+'. Continue anyway?')) return;
        }

        post('academic/save_substitute',{
            id:document.getElementById('subEditId').value,
            date:date,
            date_end:dateEnd,
            absent_teacher_id:absEl.value,
            absent_teacher_name:absOpt?absOpt.dataset.name:'',
            substitute_teacher_id:subEl.value,
            substitute_teacher_name:subOpt?subOpt.dataset.name:'',
            class_section:cs,
            periods:JSON.stringify(periods),
            subject:document.getElementById('subSubject').value,
            reason:document.getElementById('subReason').value.trim()
        }).then(function(d){
            if(d.status==='success'){
                AC.sub.hideForm();
                AC.sub.load();
                toast('Substitute assigned',true);
            } else toast(d.message,false);
        });
    },
    setStatus:function(id,status){
        post('academic/update_substitute',{id:id,status:status}).then(function(d){
            if(d.status==='success'){AC.sub.load();toast('Status updated',true)}
            else toast(d.message,false);
        });
    },
    del:function(id){
        if(!confirm('Delete this record?'))return;
        post('academic/delete_substitute',{id:id}).then(function(d){
            if(d.status==='success'){AC.sub.load();toast('Deleted',true)}
            else toast(d.message,false);
        });
    }
};

/* ══════════════════════════════════════════════════════════════
   TEACHER WORKLOAD ANALYTICS
   Computed entirely from cached timetable data — zero extra Firebase calls.
══════════════════════════════════════════════════════════════ */
AC.wl = {
    _loaded:false,
    _data:[], // [{id, name, weekly, maxDay, subjects:{}, classes:{}, perDay:{Mon:N,...}}]

    load:function(){
        AC.wl._loaded=true;
        // Ensure timetable data is available
        if(!AC.tt._loaded){
            document.getElementById('wlTable').innerHTML='<div class="ac-empty"><i class="fa fa-spinner fa-spin"></i> Loading timetable data...</div>';
            loadSharedData(function(){
                var teacherPromise=(_teachers.length>0)?Promise.resolve():post('academic/get_all_teachers').then(function(d){
                    if(d.status==='success') _teachers=d.teachers||[];
                });
                var ttPromise=post('academic/get_master_timetable');
                Promise.all([teacherPromise,ttPromise]).then(function(results){
                    var d=results[1];
                    if(d.status==='success'){
                        AC.tt.settings=d.settings;
                        AC.tt.timetables=d.timetables||{};
                        AC.tt.classes=d.classes||[];
                        AC.tt._subjectAssignments=d.subject_assignments||{};
                        AC.tt._loaded=true;
                    }
                    AC.wl._compute();
                    AC.wl.render();
                });
            });
        } else {
            AC.wl._compute();
            AC.wl.render();
        }
    },

    /**
     * Walk every cell of every timetable and aggregate per teacher.
     * O(classes × days × periods) — typically < 1000 cells total.
     */
    _compute:function(){
        var s=AC.tt.settings;
        var days=(s&&s.working_days)?s.working_days:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var np=(s&&s.no_of_periods)?s.no_of_periods:6;
        var dayShort={Monday:'Mon',Tuesday:'Tue',Wednesday:'Wed',Thursday:'Thu',Friday:'Fri',Saturday:'Sat',Sunday:'Sun'};
        var labels=Object.keys(AC.tt.timetables);

        // tid → {id, name, subjects:{name:true}, classes:{label:true}, perDay:{Mon:N,...}, weekly:N, maxDay:N}
        var map={};

        // Seed with all known teachers (so unassigned ones appear too)
        _teachers.forEach(function(t){
            var pd={};
            days.forEach(function(d){pd[dayShort[d]||d]=0});
            map[t.id]={id:t.id,name:t.name,subjects:{},classes:{},perDay:pd,weekly:0,maxDay:0};
        });

        // Walk timetables
        labels.forEach(function(label){
            var tt=AC.tt.timetables[label]||{};
            days.forEach(function(day){
                var periods=tt[day]||[];
                var dk=dayShort[day]||day;
                for(var p=0;p<np;p++){
                    var cell=periods[p];
                    if(!cell)continue;
                    var tid='',tname='',sub='';
                    if(typeof cell==='object'){
                        tid=cell.teacher_id||'';
                        tname=cell.teacher_name||'';
                        sub=cell.subject||'';
                    } else if(typeof cell==='string' && cell){
                        sub=cell;
                    }
                    if(!tid)continue;

                    // Ensure entry exists (handles IDs not in _teachers list)
                    if(!map[tid]){
                        var pd={};
                        days.forEach(function(d){pd[dayShort[d]||d]=0});
                        map[tid]={id:tid,name:tname||tid,subjects:{},classes:{},perDay:pd,weekly:0,maxDay:0};
                    }
                    var entry=map[tid];
                    entry.weekly++;
                    entry.perDay[dk]=(entry.perDay[dk]||0)+1;
                    if(sub) entry.subjects[sub]=true;
                    entry.classes[label]=true;
                }
            });
        });

        // Finalize: compute maxDay
        var result=[];
        var tids=Object.keys(map);
        for(var i=0;i<tids.length;i++){
            var e=map[tids[i]];
            var pdVals=Object.values(e.perDay);
            e.maxDay=Math.max.apply(null,pdVals.length?pdVals:[0]);
            result.push(e);
        }

        // Sort: overloaded first, then by weekly desc
        var threshold=parseInt(document.getElementById('wlThreshold').value)||30;
        result.sort(function(a,b){
            var aOver=a.weekly>threshold?1:0;
            var bOver=b.weekly>threshold?1:0;
            if(aOver!==bOver) return bOver-aOver; // overloaded first
            return b.weekly-a.weekly;
        });

        AC.wl._data=result;
    },

    render:function(){
        var data=AC.wl._data;
        var threshold=parseInt(document.getElementById('wlThreshold').value)||30;
        var warnThreshold=Math.round(threshold*0.8); // 80% = warning zone
        var s=AC.tt.settings;
        var days=(s&&s.working_days)?s.working_days:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var dayShort={Monday:'Mon',Tuesday:'Tue',Wednesday:'Wed',Thursday:'Thu',Friday:'Fri',Saturday:'Sat',Sunday:'Sun'};
        var maxPerDay=((s&&s.no_of_periods)?s.no_of_periods:6);

        // Stats
        var totalTeachers=data.length;
        var assigned=data.filter(function(t){return t.weekly>0});
        var overloaded=data.filter(function(t){return t.weekly>threshold});
        var unassigned=data.filter(function(t){return t.weekly===0});
        var avgPeriods=assigned.length>0?Math.round(assigned.reduce(function(s,t){return s+t.weekly},0)/assigned.length):0;

        document.getElementById('wlStatTotal').textContent=totalTeachers;
        document.getElementById('wlStatAvg').textContent=avgPeriods;
        document.getElementById('wlStatOk').textContent=assigned.length-overloaded.length;
        document.getElementById('wlStatOver').textContent=overloaded.length;
        document.getElementById('wlStatFree').textContent=unassigned.length;
        document.getElementById('wlStats').style.display='block';

        if(data.length===0){
            document.getElementById('wlTable').innerHTML='<div class="ac-empty"><i class="fa fa-bar-chart"></i>No teacher data available. Load the Master Timetable first.</div>';
            return;
        }

        // Table
        var html='<div style="overflow-x:auto"><table class="ac-table"><thead><tr>';
        html+='<th>Teacher</th><th>Subjects</th><th>Classes</th>';
        days.forEach(function(d){html+='<th style="text-align:center;min-width:40px">'+esc(dayShort[d]||d)+'</th>'});
        html+='<th style="text-align:center">Weekly</th><th style="min-width:100px">Load</th>';
        html+='</tr></thead><tbody>';

        data.forEach(function(t){
            var isOver=t.weekly>threshold;
            var isWarn=!isOver&&t.weekly>warnThreshold;
            var rowCls=isOver?' class="ac-wl-row-over"':(isWarn?' class="ac-wl-row-warn"':'');
            html+='<tr'+rowCls+'>';

            // Name
            html+='<td style="font-weight:600;white-space:nowrap">';
            if(isOver) html+='<i class="fa fa-exclamation-circle" style="color:#dc2626;margin-right:4px" title="Overloaded"></i>';
            html+=esc(t.name);
            html+='</td>';

            // Subjects
            var subNames=Object.keys(t.subjects).sort();
            html+='<td><div class="ac-wl-chips">';
            if(subNames.length===0) html+='<span style="color:var(--t3);font-size:11px">—</span>';
            else subNames.forEach(function(s){html+='<span class="ac-wl-chip">'+esc(s)+'</span>'});
            html+='</div></td>';

            // Classes
            var classNames=Object.keys(t.classes).sort();
            html+='<td><div class="ac-wl-chips">';
            if(classNames.length===0) html+='<span style="color:var(--t3);font-size:11px">—</span>';
            else classNames.forEach(function(c){
                var short=c.replace('Class ','').replace(' / Section ',' ');
                html+='<span class="ac-wl-chip">'+esc(short)+'</span>';
            });
            html+='</div></td>';

            // Per-day cells
            days.forEach(function(d){
                var dk=dayShort[d]||d;
                var n=t.perDay[dk]||0;
                var heavy=n>=maxPerDay;
                html+='<td class="ac-wl-day-cell'+(heavy?' heavy':'')+'">'+n+'</td>';
            });

            // Weekly total
            var totalCls=isOver?' style="color:#dc2626;font-weight:700"':(isWarn?' style="color:#d97706;font-weight:700"':' style="font-weight:700"');
            html+='<td class="ac-wl-day-cell"'+totalCls+'>'+t.weekly+'</td>';

            // Load bar
            var pct=Math.min(Math.round(t.weekly/threshold*100),120);
            var barCls=isOver?'over':(isWarn?'warn':'ok');
            html+='<td><div class="ac-wl-bar"><div class="ac-wl-bar-fill '+barCls+'" style="width:'+Math.min(pct,100)+'%"></div></div>';
            html+='<div style="font-size:9px;color:var(--t3);margin-top:2px;text-align:center">'+pct+'%</div></td>';

            html+='</tr>';
        });

        html+='</tbody></table></div>';
        document.getElementById('wlTable').innerHTML=html;
    },

    exportCSV:function(){
        var data=AC.wl._data;
        if(data.length===0){toast('No data to export',false);return}
        var s=AC.tt.settings;
        var days=(s&&s.working_days)?s.working_days:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var dayShort={Monday:'Mon',Tuesday:'Tue',Wednesday:'Wed',Thursday:'Thu',Friday:'Fri',Saturday:'Sat',Sunday:'Sun'};

        var header=['Teacher','Subjects','Classes'];
        days.forEach(function(d){header.push(dayShort[d]||d)});
        header.push('Weekly Total');

        var rows=[header.join(',')];
        data.forEach(function(t){
            var cols=[];
            cols.push('"'+t.name.replace(/"/g,'""')+'"');
            cols.push('"'+Object.keys(t.subjects).sort().join('; ')+'"');
            cols.push('"'+Object.keys(t.classes).sort().map(function(c){return c.replace('Class ','').replace(' / Section ',' ')}).join('; ')+'"');
            days.forEach(function(d){cols.push(t.perDay[dayShort[d]||d]||0)});
            cols.push(t.weekly);
            rows.push(cols.join(','));
        });

        var blob=new Blob([rows.join('\n')],{type:'text/csv'});
        var a=document.createElement('a');
        a.href=URL.createObjectURL(blob);
        a.download='teacher_workload_'+(new Date().toISOString().slice(0,10))+'.csv';
        a.click();
        URL.revokeObjectURL(a.href);
        toast('CSV exported',true);
    }
};

/* ── Init on page load ── */
AC.sa.init();
</script>
