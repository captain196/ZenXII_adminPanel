<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

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

    --att-p-bg: rgba(22,163,74,.15);
    --att-a-bg: rgba(220,38,38,.15);
    --att-l-bg: rgba(217,119,6,.15);
    --att-t-bg: rgba(37,99,235,.15);
    --att-h-bg: rgba(124,58,237,.15);
    --att-v-bg: rgba(156,163,175,.12);

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
.att-filter-bar select:focus { outline: none; border-color: var(--att-primary); box-shadow: 0 0 0 3px rgba(15,118,110,.15); }
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
.att-toolbar {
    display: none; flex-wrap: wrap; gap: 10px; align-items: center;
    padding: 14px 20px; border-radius: var(--att-r);
    background: var(--att-card); border: 1px solid var(--att-border);
    box-shadow: var(--att-shadow); margin-bottom: 16px;
}
.att-toolbar.visible { display: flex; }
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
.att-grid {
    width: max-content; min-width: 100%; border-collapse: separate;
    border-spacing: 0; font-family: var(--att-font);
}
.att-grid thead { position: sticky; top: 0; z-index: 10; }
.att-grid th {
    background: var(--att-bg3); color: var(--att-text2); font-size: 11px;
    font-weight: 600; text-transform: uppercase; letter-spacing: .3px;
    padding: 10px 6px; text-align: center; white-space: nowrap;
    border-bottom: 2px solid var(--att-border);
}
.att-grid th.att-th-name { text-align: left; padding-left: 14px; min-width: 160px; }
.att-grid th.att-th-id { text-align: left; min-width: 90px; }
.att-grid th.att-th-summary { min-width: 110px; }
.att-grid th.att-th-day { width: 36px; }
.att-grid th.att-col-sunday { background: rgba(239,68,68,.10); color: #dc2626; }
.att-grid th.att-col-holiday { background: rgba(139,92,246,.14); color: #7c3aed; }

.att-grid td {
    padding: 4px; text-align: center; border-bottom: 1px solid var(--att-border);
    font-size: 13px; color: var(--att-text); vertical-align: middle;
}
.att-grid td.att-td-idx { font-family: var(--att-font-m); font-size: 12px; color: var(--att-text3); padding: 4px 8px; }
.att-grid td.att-td-name {
    text-align: left; padding-left: 14px; font-weight: 500;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 180px;
}
.att-grid td.att-td-id {
    text-align: left; font-family: var(--att-font-m); font-size: 11px; color: var(--att-text3);
}
.att-grid td.att-td-summary {
    font-family: var(--att-font-m); font-size: 11px; white-space: nowrap;
}
.att-grid td.att-col-sunday { background: var(--att-sunday); border-left: 1px solid rgba(239,68,68,.10); border-right: 1px solid rgba(239,68,68,.10); }
.att-grid td.att-col-holiday { background: var(--att-holiday); border-left: 1px solid rgba(139,92,246,.10); border-right: 1px solid rgba(139,92,246,.10); }

.att-grid tbody tr:hover td { background: var(--att-bg3); }
.att-grid tbody tr:hover td.att-col-sunday { background: rgba(239,68,68,.12); }
.att-grid tbody tr:hover td.att-col-holiday { background: rgba(139,92,246,.16); }

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
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="container-fluid">

    <!-- Page Header -->
    <div style="margin-bottom:18px;">
        <h4 style="font-family:var(--att-font);font-weight:700;color:var(--att-text);margin:0 0 4px;">
            <i class="fa fa-id-badge" style="color:var(--att-primary);margin-right:6px;"></i>
            Staff Attendance
        </h4>
        <p style="font-family:var(--att-font);font-size:13px;color:var(--att-text3);margin:0;">
            Mark daily attendance for staff members
        </p>
    </div>

    <!-- Filter Bar -->
    <div class="att-filter-bar">
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
            <button type="button" class="att-btn att-btn-primary" id="attLoadBtn">
                <i class="fa fa-search"></i> Load
            </button>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="att-toolbar" id="attToolbar">
        <div class="att-toolbar-section">
            <button type="button" class="att-btn att-btn-primary att-btn-sm" id="attSaveBtn" disabled>
                <i class="fa fa-save"></i> Save Changes
            </button>
        </div>
        <div class="att-toolbar-divider"></div>
        <div class="att-toolbar-section">
            <label style="font-family:var(--att-font);font-size:12px;font-weight:600;color:var(--att-text3);">Day:</label>
            <input type="number" id="attDayPicker" class="att-day-picker" min="1" max="31" value="1">
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="P">
                <i class="fa fa-check"></i> All Present
            </button>
            <button type="button" class="att-btn att-btn-outline att-btn-sm" data-bulk="H">
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
        <p>Select a month and click Load to view staff attendance.</p>
    </div>

    <!-- Grid -->
    <div class="att-grid-wrap" id="attGridWrap" style="display:none;">
        <table class="att-grid" id="attGrid">
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

</div>
</section>
</div>

<script>
(function(){
    "use strict";

    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var BASE = '<?= base_url() ?>';

    /* ── State ── */
    var state = {
        staff: [],
        daysInMonth: 0,
        sundays: [],
        holidays: {},
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

    var CYCLE = ['V','P','A','L','T','H'];

    function nextMark(v) {
        var i = CYCLE.indexOf(v);
        return CYCLE[(i + 1) % CYCLE.length];
    }

    function showToast(msg, type) {
        elToast.textContent = msg;
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

    /* ── Load ── */
    elLoadBtn.addEventListener('click', loadAttendance);

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

        postData('attendance/fetch_staff', { month: mon })
            .then(function(res) {
                elLoading.classList.remove('visible');
                if (!res || res.status === 'error') {
                    showToast(res ? res.message : 'Failed to load data.', 'error');
                    elEmpty.style.display = 'block';
                    return;
                }
                state.staff = res.staff || [];
                state.daysInMonth = parseInt(res.daysInMonth, 10) || 30;
                state.sundays = res.sundays || [];
                state.holidays = res.holidays || {};
                state.month = res.month || mon;
                state.year = parseInt(res.year, 10) || new Date().getFullYear();
                state.dirty = new Set();

                if (state.staff.length === 0) {
                    showToast('No staff found for this month.', 'error');
                    elEmpty.style.display = 'block';
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

                elDayPick.max = state.daysInMonth;
                if (parseInt(elDayPick.value, 10) > state.daysInMonth) elDayPick.value = 1;

                renderGrid();
                elGridWrap.style.display = 'block';
                elToolbar.classList.add('visible');
                updateSaveBtn();
            })
            .catch(function() {
                elLoading.classList.remove('visible');
                showToast('Network error loading attendance.', 'error');
                elEmpty.style.display = 'block';
            });
    }

    /* ── Render Grid ── */
    function renderGrid() {
        var sundaySet = {};
        state.sundays.forEach(function(d){ sundaySet[d] = true; });
        var holidaySet = {};
        Object.keys(state.holidays).forEach(function(d){ holidaySet[parseInt(d,10)] = state.holidays[d]; });

        /* Header */
        var dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var hHtml = '<tr><th class="att-th-idx">#</th><th class="att-th-name">Staff Name</th><th class="att-th-id">Staff ID</th>';
        for (var d = 1; d <= state.daysInMonth; d++) {
            var cls = 'att-th-day';
            var isSun = sundaySet[d];
            if (isSun) cls += ' att-col-sunday';
            if (holidaySet[d]) cls += ' att-col-holiday';
            var dt = new Date(state.year, getMonthIndex(state.month), d);
            var dn = dayNames[dt.getDay()];
            hHtml += '<th class="' + cls + '" title="' + dn + ', ' + state.month + ' ' + d + '">';
            hHtml += '<div style="line-height:1.2' + (isSun ? ';color:#dc2626;font-weight:900' : '') + '">' + d + '</div>';
            hHtml += '<div style="font-size:8px;font-weight:' + (isSun ? '700' : '500') + ';letter-spacing:0;' + (isSun ? 'color:#dc2626;opacity:1' : 'opacity:.6') + '">' + dn.charAt(0) + '</div>';
            hHtml += '</th>';
        }
        hHtml += '<th class="att-th-summary">Summary</th></tr>';
        elHead.innerHTML = hHtml;

        /* Body */
        var bHtml = '';
        state.staff.forEach(function(s, idx) {
            var att = state.attendance[s.id];
            bHtml += '<tr data-sid="' + esc(s.id) + '">';
            bHtml += '<td class="att-td-idx">' + (idx + 1) + '</td>';
            bHtml += '<td class="att-td-name" title="' + esc(s.name) + '">' + esc(s.name) + '</td>';
            bHtml += '<td class="att-td-id">' + esc(s.id) + '</td>';
            for (var d = 0; d < state.daysInMonth; d++) {
                var day = d + 1;
                var v = att[d];
                var tdCls = '';
                if (sundaySet[day]) tdCls += ' att-col-sunday';
                if (holidaySet[day]) tdCls += ' att-col-holiday';
                var dirtyMark = state.dirty.has(s.id) && att[d] !== state.original[s.id].charAt(d) ? ' att-dirty' : '';
                var clock = v === 'T' ? '<span class="att-clock"><i class="fa fa-clock-o"></i></span>' : '';
                bHtml += '<td class="' + tdCls + '">';
                bHtml += '<span class="att-cell' + dirtyMark + '" data-v="' + v + '" data-sid="' + esc(s.id) + '" data-d="' + d + '">';
                bHtml += v + clock + '</span></td>';
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
        elSaveBtn.disabled = state.dirty.size === 0;
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

    /* ── Cell Click ── */
    elBody.addEventListener('click', function(e) {
        var cell = e.target.closest('.att-cell');
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
            var mark = btn.getAttribute('data-bulk');
            var day = parseInt(elDayPick.value, 10);
            if (day < 1 || day > state.daysInMonth) {
                showToast('Invalid day number.', 'error');
                return;
            }
            var d = day - 1;
            state.staff.forEach(function(s) {
                state.attendance[s.id][d] = mark;
                updateCell(s.id, d);
                markDirty(s.id);
            });
        });
    });

    /* ── Save ── */
    elSaveBtn.addEventListener('click', function() {
        if (state.dirty.size === 0) return;

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
        elSaveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

        postData('attendance/save_staff', {
            month: mon,
            attendance: JSON.stringify(attObj),
            late: JSON.stringify(lateObj)
        })
        .then(function(res) {
            elSaveBtn.innerHTML = '<i class="fa fa-save"></i> Save Changes';
            if (res && res.status === 'success') {
                /* Update originals */
                state.dirty.forEach(function(sid) {
                    state.original[sid] = state.attendance[sid].join('');
                });
                state.dirty = new Set();
                updateSaveBtn();
                /* Remove dirty highlights */
                elBody.querySelectorAll('.att-dirty').forEach(function(c){ c.classList.remove('att-dirty'); });
                showToast('Staff attendance saved successfully!', 'success');
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
