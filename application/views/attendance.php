<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="at-wrap">

        <div class="at-page-title">
            <i class="fa fa-calendar-check-o"></i> Student Attendance
        </div>

        <ol class="at-breadcrumb">
            <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
            <li>Student Attendance</li>
        </ol>

        <!-- ── Filter card ── -->
        <div class="at-filter-card">
            <div class="at-filter-card-title">
                <i class="fa fa-filter"></i> Select Class, Section &amp; Month
            </div>

            <div class="at-filter-row" id="filterRow">

                <div class="at-field">
                    <label for="atClass">Class <span style="color:var(--at-red)">*</span></label>
                    <!-- BUG FIX 1: PHP block removed — JS fetch is the single source -->
                    <select id="atClass" class="at-select" required>
                        <option value="" disabled selected>Loading…</option>
                    </select>
                </div>

                <div class="at-field">
                    <label for="atSection">Section <span style="color:var(--at-red)">*</span></label>
                    <select id="atSection" class="at-select" required disabled>
                        <option value="" disabled selected>Select Class first</option>
                    </select>
                </div>

                <div class="at-field">
                    <label for="atMonth">Month <span style="color:var(--at-red)">*</span></label>
                    <select id="atMonth" class="at-select" required>
                        <option value="" disabled selected>Select Month</option>
                        <?php foreach (['April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March'] as $m): ?>
                            <option value="<?= $m ?>"><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="at-field">
                    <label>&nbsp;</label>
                    <button id="atSearchBtn" class="at-btn-search" type="button">
                        <i class="fa fa-search"></i> Search
                    </button>
                </div>

            </div>
        </div>

        <!-- ── Default / empty state ── -->
        <div class="at-state" id="atStateDefault">
            <i class="fa fa-calendar-o"></i>
            <p>Select class, section and month above to view attendance.</p>
        </div>

        <!-- ── Loading state ── -->
        <div class="at-state hidden" id="atStateLoading">
            <i class="fa fa-spinner fa-spin" style="color:var(--at-blue);"></i>
            <p>Loading attendance data…</p>
        </div>

        <!-- ── Error state ── -->
        <div class="at-state hidden" id="atStateError">
            <i class="fa fa-exclamation-circle" style="color:var(--at-red);"></i>
            <p id="atErrorMsg">Something went wrong.</p>
        </div>

        <!-- ── Result card ── -->
        <div class="at-result-card hidden" id="atResultCard">

            <div class="at-result-header">
                <div class="at-result-header-title">
                    <i class="fa fa-table"></i>
                    <span id="atResultTitle">Attendance</span>
                </div>
                <span class="at-result-hint">
                    <i class="fa fa-info-circle"></i> Double-click a row for summary
                </span>
            </div>

            <div class="at-tbl-scroll">
                <table class="at-tbl">
                    <thead id="atThead"></thead>
                    <tbody id="atTbody"></tbody>
                </table>
            </div>

            <!-- Legend -->
            <div class="at-legend">
                <div class="at-leg-item">
                    <div class="at-leg-dot leg-P"></div> P – Present
                </div>
                <div class="at-leg-item">
                    <div class="at-leg-dot leg-A"></div> A – Absent
                </div>
                <div class="at-leg-item">
                    <div class="at-leg-dot leg-L"></div> L – Leave
                </div>
                <div class="at-leg-item">
                    <div class="at-leg-dot leg-V"></div> V – Vacant / Not Marked
                </div>
            </div>

        </div>

    </div><!-- /.at-wrap -->
</div><!-- /.content-wrapper -->


<!-- ── Summary modal ── -->
<div class="at-overlay" id="atOverlay">
    <div class="at-modal">
        <div class="at-modal-head">
            <h4><i class="fa fa-bar-chart" style="margin-right:7px;"></i> Attendance Summary</h4>
            <button class="at-modal-x" id="atModalX">&times;</button>
        </div>
        <div class="at-modal-body">
            <div class="at-modal-name" id="atModalName"></div>
            <div class="at-stat-grid" id="atStatGrid"></div>
        </div>
    </div>
</div>




<script>
    /* ================================================================
   attendance.php  — all jQuery removed (BUG FIX 4), pure vanilla
================================================================ */
    (function() {
        'use strict';

        /* ── element refs ── */
        var selClass = document.getElementById('atClass');
        var selSection = document.getElementById('atSection');
        var selMonth = document.getElementById('atMonth');
        var btnSearch = document.getElementById('atSearchBtn');
        var thead = document.getElementById('atThead');
        var tbody = document.getElementById('atTbody');
        var overlay = document.getElementById('atOverlay');
        var modalName = document.getElementById('atModalName');
        var statGrid = document.getElementById('atStatGrid');

        var stDefault = document.getElementById('atStateDefault');
        var stLoading = document.getElementById('atStateLoading');
        var stError = document.getElementById('atStateError');
        var stResult = document.getElementById('atResultCard');
        var errMsg = document.getElementById('atErrorMsg');
        var resultTitle = document.getElementById('atResultTitle');

        /* ── state helpers ── */
        function showOnly(el) {
            [stDefault, stLoading, stError, stResult].forEach(function(e) {
                e.classList.add('hidden');
            });
            el.classList.remove('hidden');
        }

        function showError(msg) {
            errMsg.textContent = msg || 'An error occurred.';
            showOnly(stError);
        }

        /* ────────────────────────────────────────────────────────
           STEP 1 — Load classes on page load (JS only, BUG FIX 1)
        ──────────────────────────────────────────────────────── */
        fetch('<?= base_url("student/get_classes") ?>')
            .then(function(r) {
                return r.json();
            })
            .then(function(classes) {
                selClass.innerHTML = '<option value="" disabled selected>Select Class</option>';
                if (!Array.isArray(classes) || classes.length === 0) {
                    selClass.innerHTML = '<option value="" disabled selected>No classes found</option>';
                    return;
                }
                classes.forEach(function(cn) {
                    var o = document.createElement('option');
                    o.value = o.textContent = cn;
                    selClass.appendChild(o);
                });
            })
            .catch(function() {
                selClass.innerHTML = '<option value="" disabled selected>Error loading classes</option>';
            });

        /* ────────────────────────────────────────────────────────
           STEP 2 — Load sections when class changes
        ──────────────────────────────────────────────────────── */
        selClass.addEventListener('change', function() {
            selSection.innerHTML = '<option value="" disabled selected>Loading…</option>';
            selSection.disabled = true;

            var fd = new FormData();
            fd.append(csrfName, csrfToken);
            fd.append('class_name', this.value);
            fetch('<?= base_url("student/get_sections_by_class") ?>', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(sections) {
                    selSection.innerHTML = '<option value="" disabled selected>Select Section</option>';
                    if (!Array.isArray(sections) || sections.length === 0) {
                        selSection.innerHTML = '<option value="" disabled selected>No sections found</option>';
                        return;
                    }
                    sections.forEach(function(s) {
                        var o = document.createElement('option');
                        o.value = o.textContent = s;
                        selSection.appendChild(o);
                    });
                    selSection.disabled = false;
                })
                .catch(function() {
                    selSection.innerHTML = '<option value="" disabled selected>Error loading sections</option>';
                    selSection.disabled = false;
                });
        });

        /* ────────────────────────────────────────────────────────
           STEP 3 — Search button click
        ──────────────────────────────────────────────────────── */
        btnSearch.addEventListener('click', function() {
            var cls = selClass.value;
            var sec = selSection.value;
            var mon = selMonth.value;

            if (!cls || !sec || !mon) {
                alert('Please select Class, Section and Month.');
                return;
            }

            showOnly(stLoading);
            btnSearch.disabled = true;
            btnSearch.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Searching…';

            fetch('<?= base_url("student/fetchAttendance") ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: encodeURIComponent(csrfName) + '=' + encodeURIComponent(csrfToken) +
                        '&class=' + encodeURIComponent(cls) +
                        '&section=' + encodeURIComponent(sec) +
                        '&month=' + encodeURIComponent(mon)
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    btnSearch.disabled = false;
                    btnSearch.innerHTML = '<i class="fa fa-search"></i> Search';

                    if (data.error) {
                        showError(data.error);
                        return;
                    }

                    buildTable(data, cls, sec, mon);
                    resultTitle.textContent = cls + ' – Section ' + sec + ' · ' + mon + ' ' + data.year;
                    showOnly(stResult);
                })
                .catch(function(err) {
                    console.error(err);
                    btnSearch.disabled = false;
                    btnSearch.innerHTML = '<i class="fa fa-search"></i> Search';
                    showError('Server error — please try again.');
                });
        });

        /* ────────────────────────────────────────────────────────
           Build attendance table
        ──────────────────────────────────────────────────────── */
        var DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var MONTH_IDX = {
            January: 0,
            February: 1,
            March: 2,
            April: 3,
            May: 4,
            June: 5,
            July: 6,
            August: 7,
            September: 8,
            October: 9,
            November: 10,
            December: 11
        };

        function buildTable(data, cls, sec, mon) {
            var students = data.students;
            var days = data.daysInMonth;
            var sundays = data.sundays; // array of day numbers (1-based)
            var year = data.year; // correctly resolved year from controller
            var mIdx = MONTH_IDX[mon] !== undefined ? MONTH_IDX[mon] : 0;

            /* ── header row ── */
            var hRow = document.createElement('tr');

            ['User ID', 'Student Name'].forEach(function(t, i) {
                var th = document.createElement('th');
                th.textContent = t;
                th.style.textAlign = 'left';
                hRow.appendChild(th);
            });

            for (var d = 1; d <= days; d++) {
                var th = document.createElement('th');
                /* BUG FIX 5 — use data.year, not hardcoded 2020 */
                var dayName = DAY_NAMES[new Date(year, mIdx, d).getDay()];
                th.innerHTML = d + '<br><span style="font-size:10px;opacity:.65;">' + dayName + '</span>';
                if (sundays.indexOf(d) !== -1) th.classList.add('at-sunday');
                hRow.appendChild(th);
            }

            thead.innerHTML = '';
            thead.appendChild(hRow);

            /* ── body rows ── */
            tbody.innerHTML = '';

            students.forEach(function(student) {
                var tr = document.createElement('tr');

                /* BUG FIX 3 — store attendance in data attribute, not read from DOM */
                tr.dataset.attendance = JSON.stringify(student.attendance);
                tr.dataset.name = student.name;

                /* User ID cell */
                var tdId = document.createElement('td');
                tdId.textContent = student.userId;
                tr.appendChild(tdId);

                /* Name cell */
                var tdName = document.createElement('td');
                tdName.textContent = student.name;
                tr.appendChild(tdName);

                /* Day cells */
                for (var i = 0; i < days; i++) {
                    var td = document.createElement('td');
                    var status = (student.attendance[i] || 'V').toUpperCase();
                    td.textContent = status;
                    td.classList.add('at-' + status);
                    if (sundays.indexOf(i + 1) !== -1) td.classList.add('at-sunday');
                    tr.appendChild(td);
                }

                /* click = select row */
                tr.addEventListener('click', function() {
                    tbody.querySelectorAll('tr').forEach(function(r) {
                        r.classList.remove('at-row-selected');
                    });
                    tr.classList.add('at-row-selected');
                });

                /* double-click = summary modal */
                tr.addEventListener('dblclick', function() {
                    /* BUG FIX 3 — read from data-attribute, not DOM slice(2) */
                    var att = JSON.parse(tr.dataset.attendance || '[]');
                    var name = tr.dataset.name || 'Unknown';

                    var P = att.filter(function(a) {
                        return a === 'P';
                    }).length;
                    var A = att.filter(function(a) {
                        return a === 'A';
                    }).length;
                    var L = att.filter(function(a) {
                        return a === 'L';
                    }).length;
                    var V = att.filter(function(a) {
                        return a === 'V';
                    }).length;

                    modalName.innerHTML = '<i class="fa fa-user-circle-o"></i> ' + name;
                    statGrid.innerHTML =
                        '<div class="at-stat stat-P"><div class="num">' + P + '</div><div class="lbl">Present</div></div>' +
                        '<div class="at-stat stat-A"><div class="num">' + A + '</div><div class="lbl">Absent</div></div>' +
                        '<div class="at-stat stat-L"><div class="num">' + L + '</div><div class="lbl">Leave</div></div>' +
                        '<div class="at-stat stat-V"><div class="num">' + V + '</div><div class="lbl">Vacant</div></div>';

                    overlay.classList.add('open'); /* BUG FIX 4 — no jQuery fadeIn */
                });

                tbody.appendChild(tr);
            });
        }

        /* ── modal close — BUG FIX 4: pure vanilla, no jQuery ── */
        document.getElementById('atModalX').addEventListener('click', function() {
            overlay.classList.remove('open');
        });
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) overlay.classList.remove('open');
        });

    })();
</script>

<style>
/* ── Attendance — ERP Gold Theme (day/night aware) ── */

/* Map legacy --at-* vars → ERP theme so inline refs keep working */
:root, [data-theme="night"], [data-theme="day"] {
    --at-muted:  var(--t3);
    --at-border: var(--border);
    --at-text:   var(--t1);
    --at-white:  var(--bg2);
    --at-bg:     var(--bg);
    --at-shadow: var(--sh);
    --at-radius: 14px;
    --at-blue:   var(--gold);
    --at-sky:    var(--gold-dim);
    --at-navy:   var(--t1);
    --at-green:  #15803d;
    --at-red:    #dc2626;
    --at-amber:  #d97706;
}

/* ── Wrapper ── */
.at-wrap {
    font-family: var(--font-b);
    background: var(--bg);
    color: var(--t1);
    padding: 24px 20px 52px;
    min-height: 100vh;
}

/* ── Page title ── */
.at-page-title {
    font-family: var(--font-d);
    font-size: 22px;
    font-weight: 800;
    color: var(--t1);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.at-page-title i { color: var(--gold); }

.at-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--t3);
    font-family: var(--font-b);
    margin-bottom: 22px;
    list-style: none;
    padding: 0;
}
.at-breadcrumb li:not(:last-child)::after { content: '/'; margin-left: 6px; opacity: .5; }
.at-breadcrumb a { color: var(--gold); text-decoration: none; }

/* ── Filter card ── */
.at-filter-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    box-shadow: var(--sh);
    overflow: hidden;
    margin-bottom: 20px;
}
.at-filter-card-title {
    font-family: var(--font-b);
    font-size: 12px;
    font-weight: 700;
    color: var(--t2);
    text-transform: uppercase;
    letter-spacing: .6px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    background: var(--bg3);
    border-bottom: 1px solid var(--border);
}
.at-filter-card-title i { color: var(--gold); }

.at-filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 14px;
    align-items: end;
    padding: 18px 20px;
}

.at-field label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .55px;
    color: var(--t3);
    margin-bottom: 5px;
    font-family: var(--font-m);
}

.at-select {
    width: 100%;
    padding: 9px 32px 9px 12px;
    border: 1.5px solid var(--brd2);
    border-radius: var(--r-sm, 8px);
    font-size: 13px;
    color: var(--t1);
    background: var(--bg3);
    outline: none;
    transition: border-color var(--ease), box-shadow var(--ease);
    font-family: var(--font-b);
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 10 10'%3E%3Cpath fill='%237A6E54' d='M5 7L0 2h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 11px center;
    cursor: pointer;
}
.at-select:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(15,118,110,.15);
}
.at-select:disabled { opacity: .45; cursor: not-allowed; }

.at-btn-search {
    width: 100%;
    padding: 10px 18px;
    background: var(--gold);
    color: #ffffff;
    border: none;
    border-radius: var(--r-sm, 8px);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: all var(--ease);
    font-family: var(--font-b);
}
.at-btn-search:hover:not(:disabled) {
    background: var(--gold2, #0d6b63);
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(15,118,110,.35);
}
.at-btn-search:disabled { opacity: .5; cursor: not-allowed; }

/* ── State panels ── */
.at-state {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    box-shadow: var(--sh);
    padding: 52px 24px;
    text-align: center;
    margin-bottom: 20px;
}
.at-state i { font-size: 40px; color: var(--gold-dim); display: block; margin-bottom: 12px; opacity: .6; }
.at-state p { font-size: 14px; color: var(--t3); margin: 0; font-family: var(--font-b); }
.at-state.hidden { display: none; }

/* ── Result card ── */
.at-result-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    box-shadow: var(--sh);
    overflow: hidden;
    margin-bottom: 20px;
}
.at-result-card.hidden { display: none; }

.at-result-header {
    padding: 12px 20px;
    background: var(--bg3);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.at-result-header-title {
    font-family: var(--font-b);
    font-size: 13px;
    font-weight: 700;
    color: var(--t1);
    display: flex;
    align-items: center;
    gap: 8px;
}
.at-result-header-title i { color: var(--gold); }
.at-result-hint { font-size: 11px; color: var(--t3); font-family: var(--font-m); }

/* ── Attendance table ── */
.at-tbl-scroll { overflow-x: auto; }

.at-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    min-width: 500px;
    font-family: var(--font-b);
}
.at-tbl thead th {
    background: linear-gradient(90deg, var(--gold) 0%, var(--gold2, #0d6b63) 100%);
    color: #ffffff;
    padding: 10px 7px;
    text-align: center;
    font-weight: 700;
    white-space: nowrap;
    border-right: 1px solid rgba(0,0,0,.08);
    position: sticky;
    top: 0;
    z-index: 2;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.at-tbl thead th:first-child  { text-align: left; padding-left: 14px; min-width: 90px; }
.at-tbl thead th:nth-child(2) { text-align: left; padding-left: 12px; min-width: 140px; }
.at-tbl thead th.at-sunday    { background: var(--gold2, #0d6b63); }

.at-tbl tbody tr { transition: background var(--ease); cursor: pointer; }
.at-tbl tbody tr:hover            { background: var(--gold-dim); }
.at-tbl tbody tr.at-row-selected  { background: rgba(15,118,110,.14) !important; }

.at-tbl td {
    padding: 8px 7px;
    text-align: center;
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
    color: var(--t2);
}
.at-tbl td:first-child { text-align: left; padding-left: 14px; font-weight: 700; font-size: 11.5px; white-space: nowrap; color: var(--t1); font-family: var(--font-m); }
.at-tbl td:nth-child(2){ text-align: left; padding-left: 12px; white-space: nowrap; color: var(--t1); }
.at-tbl td.at-sunday   { background: rgba(15,118,110,.04); }

/* Status colours — semantic, keep as-is */
.at-P { background: #dcfce7; color: #15803d; font-weight: 700; border-radius: 4px; }
.at-A { background: #fee2e2; color: #dc2626; font-weight: 700; border-radius: 4px; }
.at-L { background: #fef9c3; color: #a16207; font-weight: 700; border-radius: 4px; }
.at-V { background: rgba(15,118,110,.08); color: var(--t3); font-weight: 600; border-radius: 4px; }

/* ── Legend ── */
.at-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    padding: 12px 20px;
    border-top: 1px solid var(--border);
    background: var(--bg3);
}
.at-leg-item { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: var(--t3); font-family: var(--font-b); }
.at-leg-dot  { width: 13px; height: 13px; border-radius: 3px; border: 1px solid rgba(0,0,0,.08); }
.leg-P { background: #dcfce7; }
.leg-A { background: #fee2e2; }
.leg-L { background: #fef9c3; }
.leg-V { background: rgba(15,118,110,.12); }

/* ── Summary modal ── */
.at-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.55);
    z-index: 9100;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}
.at-overlay.open { display: flex; }

.at-modal {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    width: 90%;
    max-width: 380px;
    box-shadow: 0 8px 40px rgba(0,0,0,.35);
    overflow: hidden;
    animation: at-modal-in .16s ease;
}
@keyframes at-modal-in {
    from { transform: scale(.94); opacity: 0; }
    to   { transform: scale(1);   opacity: 1; }
}

.at-modal-head {
    background: linear-gradient(130deg, #0c1e38 0%, #070f1c 100%);
    border-bottom: 2px solid var(--gold);
    padding: 14px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.at-modal-head h4 { margin: 0; font-family: var(--font-d); font-size: 16px; font-weight: 700; color: #e6f4f1; }
.at-modal-head h4 i { color: var(--gold); }
.at-modal-x {
    background: none; border: none;
    color: rgba(200,185,138,.6);
    font-size: 22px; cursor: pointer; line-height: 1; padding: 2px 6px;
    transition: color var(--ease);
}
.at-modal-x:hover { color: var(--gold); }
.at-modal-body { padding: 20px; background: var(--bg2); }

.at-modal-name {
    font-size: 14px; font-weight: 700; color: var(--t1);
    display: flex; align-items: center; gap: 7px;
    margin-bottom: 16px; font-family: var(--font-b);
}
.at-modal-name i { color: var(--gold); }

.at-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

.at-stat { border-radius: 10px; padding: 14px 10px; text-align: center; }
.at-stat .num { font-family: var(--font-d); font-size: 28px; font-weight: 800; line-height: 1; }
.at-stat .lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; font-family: var(--font-m); }

.stat-P { background: #dcfce7; }
.stat-P .num { color: #15803d; } .stat-P .lbl { color: #166534; }
.stat-A { background: #fee2e2; }
.stat-A .num { color: #dc2626; } .stat-A .lbl { color: #991b1b; }
.stat-L { background: #fef9c3; }
.stat-L .num { color: #a16207; } .stat-L .lbl { color: #713f12; }
.stat-V { background: rgba(15,118,110,.1); }
.stat-V .num { color: var(--gold); } .stat-V .lbl { color: var(--t3); }

/* ── Responsive ── */
@media (max-width: 600px) { .at-filter-row { grid-template-columns: 1fr; } }

@media print {
    .at-filter-card, .at-legend { display: none; }
    .at-tbl-scroll { overflow: visible; }
    .at-tbl { font-size: 11px; }
}
</style>