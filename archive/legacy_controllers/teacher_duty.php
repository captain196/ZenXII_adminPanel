<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="td-wrap">

    <!-- ── Page title + breadcrumb ── -->
    <div class="td-page-title">
        <i class="fa fa-user-circle-o"></i> Teacher Duty Assignment
    </div>
    <ol class="td-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Teacher Duty</li>
    </ol>

    <!-- ══════════════════════════════════════════════════
         ASSIGNMENT FORM CARD
    ══════════════════════════════════════════════════ -->
    <div class="td-card" id="formCard">
        <div class="td-card-head">
            <span><i class="fa fa-pencil-square-o"></i> Duty Assignment Form</span>
        </div>
        <div class="td-card-body">
            <form id="duty-form" autocomplete="off">

                <div class="td-form-grid">

                    <!-- Class -->
                    <div class="td-field">
                        <label for="tdClass">Class <span class="td-req">*</span></label>
                        <select id="tdClass" class="td-select" required>
                            <option value="" disabled selected>Select Class</option>
                            <?php
                            $uniqueClasses = [];
                            foreach ($Classes as $c) {
                                $uniqueClasses[$c['class_name']] = true;
                            }
                            ksort($uniqueClasses);
                            foreach ($uniqueClasses as $cn => $_):
                            ?>
                            <option value="<?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($cn, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Section -->
                    <div class="td-field">
                        <label for="tdSection">Section <span class="td-req">*</span></label>
                        <select id="tdSection" class="td-select" required disabled>
                            <option value="" disabled selected>Select Class first</option>
                        </select>
                    </div>

                    <!-- Subject -->
                    <div class="td-field">
                        <label for="tdSubject">Subject <span class="td-req">*</span></label>
                        <select id="tdSubject" class="td-select" required disabled>
                            <option value="" disabled selected>Select Section first</option>
                        </select>
                    </div>

                    <!-- Teacher -->
                    <div class="td-field">
                        <label for="tdTeacher">Teacher <span class="td-req">*</span></label>
                        <select id="tdTeacher" class="td-select" required>
                            <option value="" disabled selected>Select Teacher</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Duty Type -->
                    <div class="td-field">
                        <label for="tdDutyType">Duty Type <span class="td-req">*</span></label>
                        <select id="tdDutyType" class="td-select" required>
                            <option value="" disabled selected>Select Duty Type</option>
                            <option value="SubjectTeacher">Subject Teacher</option>
                            <option value="ClassTeacher">Class Teacher</option>
                        </select>
                    </div>

                    <!-- Time -->
                    <div class="td-field td-time-field">
                        <label>Duty Time <span class="td-muted">(optional)</span></label>
                        <div class="td-time-row">
                            <div class="td-time-group">
                                <span class="td-time-lbl">From</span>
                                <input type="time" id="tdStart" class="td-input-time">
                            </div>
                            <div class="td-time-sep">—</div>
                            <div class="td-time-group">
                                <span class="td-time-lbl">To</span>
                                <input type="time" id="tdEnd" class="td-input-time">
                            </div>
                        </div>
                    </div>

                </div><!-- /.td-form-grid -->

                <div class="td-form-actions">
                    <button type="button" class="td-btn td-btn-ghost" id="tdResetBtn">
                        <i class="fa fa-times"></i> Reset
                    </button>
                    <button type="button" class="td-btn td-btn-primary" id="tdAssignBtn" disabled>
                        <i class="fa fa-check"></i> Assign Duty
                    </button>
                    <button type="button" class="td-btn td-btn-warn" id="tdUpdateBtn" style="display:none;">
                        <i class="fa fa-refresh"></i> Update Duty
                    </button>
                </div>

            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════
         ASSIGNED DUTIES TABLE CARD
    ══════════════════════════════════════════════════ -->
    <div class="td-card">
        <div class="td-card-head">
            <span><i class="fa fa-list-ul"></i> Assigned Duties</span>
            <span class="td-count"><?= count($duties ?? []) ?> record<?= count($duties ?? []) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="td-card-body td-card-body--table">
            <div class="td-tbl-wrap">
                <table class="td-tbl" id="dutyTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Duty Type</th>
                            <th>Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($duties)): ?>
                        <?php foreach ($duties as $i => $duty): ?>
                        <tr data-class-section="<?= htmlspecialchars($duty['class_section'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <td class="td-num"><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars((string)($duty['class'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($duty['section'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string)($duty['subject'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="td-teacher"><?= htmlspecialchars((string)($duty['teacher_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <span class="td-badge <?= ($duty['duty_type'] ?? '') === 'ClassTeacher' ? 'td-badge--class' : 'td-badge--subject' ?>">
                                    <?= htmlspecialchars((string)($duty['duty_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="td-time-cell"><?= htmlspecialchars((string)($duty['duty_time'] ?? '') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="td-actions">
                                <button class="td-act-btn td-act-edit" onclick="tdFillForm(this)" title="Edit">
                                    <i class="fa fa-pencil"></i>
                                </button>
                                <button class="td-act-btn td-act-del" onclick="tdMarkInactive(this)" title="Remove">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="td-empty">
                                <i class="fa fa-inbox"></i><br>No duties assigned yet
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.td-wrap -->
</div><!-- /.content-wrapper -->


<script>
/* ================================================================
   teacher_duty.php — complete JS (all bugs fixed)
================================================================ */
(function () {
    'use strict';

    /* ── Element refs ── */
    var selClass   = document.getElementById('tdClass');
    var selSection = document.getElementById('tdSection');
    var selSubject = document.getElementById('tdSubject');
    var selTeacher = document.getElementById('tdTeacher');
    var selDuty    = document.getElementById('tdDutyType');
    var inpStart   = document.getElementById('tdStart');
    var inpEnd     = document.getElementById('tdEnd');
    var btnAssign  = document.getElementById('tdAssignBtn');
    var btnUpdate  = document.getElementById('tdUpdateBtn');
    var btnReset   = document.getElementById('tdResetBtn');

    /* ── State ── */
    var isUpdating  = false;
    var selectedRow = null;
    var originalData = {};

    /* ── CSRF: build FormData with token already appended ── */
    function csrfFd() {
        var fd = new FormData();
        fd.append(csrfName, csrfToken);
        return fd;
    }

    /* ── Toast notification ── */
    function toast(type, msg) {
        var accent = { success: 'var(--gold)', error: '#e05c6f', warning: '#f59e0b', info: 'var(--t3)' };
        var el = document.createElement('div');
        el.textContent = msg;
        el.style.cssText = [
            'position:fixed;top:22px;right:22px;z-index:9999',
            'padding:12px 18px',
            'background:var(--bg2)',
            'color:var(--t1)',
            'border-left:4px solid ' + (accent[type] || accent.info),
            'border-radius:10px',
            'box-shadow:var(--sh)',
            'font-size:13px',
            'font-family:var(--font-b)',
            'max-width:320px',
            'pointer-events:none'
        ].join(';');
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 3500);
    }

    /* ── Enable/disable Assign button ── */
    function checkForm() {
        var ok = selClass.value && selSection.value && selSubject.value &&
                 selTeacher.value && selDuty.value;
        btnAssign.disabled = !ok;
    }

    /* ════════════════════════════════════════════════════
       STEP 1 — CLASS CHANGE → LOAD SECTIONS
    ════════════════════════════════════════════════════ */
    selClass.addEventListener('change', function () {
        selSection.innerHTML = '<option value="" disabled selected>Loading…</option>';
        selSection.disabled  = true;
        selSubject.innerHTML = '<option value="" disabled selected>Select Section first</option>';
        selSubject.disabled  = true;
        checkForm();

        var fd = csrfFd();
        fd.append('class_name', this.value);
        fetch(BASE_URL + 'student/get_sections_by_class', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (sections) {
            selSection.innerHTML = '<option value="" disabled selected>Select Section</option>';
            if (!Array.isArray(sections) || !sections.length) {
                selSection.innerHTML = '<option value="" disabled selected>No sections found</option>';
                return;
            }
            sections.forEach(function (s) {
                var o = document.createElement('option');
                o.value = o.textContent = s;
                selSection.appendChild(o);
            });
            selSection.disabled = false;
        })
        .catch(function () {
            selSection.innerHTML = '<option value="" disabled selected>Error loading</option>';
        });
    });

    /* ════════════════════════════════════════════════════
       STEP 2 — SECTION CHANGE → LOAD SUBJECTS
    ════════════════════════════════════════════════════ */
    selSection.addEventListener('change', function () {
        selSubject.innerHTML = '<option value="" disabled selected>Loading…</option>';
        selSubject.disabled  = true;
        checkForm();
        loadSubjects(selClass.value, this.value, null);
    });

    function loadSubjects(className, section, callback) {
        var fd = csrfFd();
        fd.append('class_name', className);
        fd.append('section', section);
        fetch(BASE_URL + 'staff/fetch_subjects', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (subjects) {
            selSubject.innerHTML = '<option value="" disabled selected>Select Subject</option>';
            if (!Array.isArray(subjects) || !subjects.length) {
                selSubject.innerHTML = '<option value="" disabled selected>No subjects found</option>';
            } else {
                subjects.forEach(function (sub) {
                    var o = document.createElement('option');
                    o.value = o.textContent = sub;
                    selSubject.appendChild(o);
                });
                selSubject.disabled = false;
            }
            checkForm();
            if (typeof callback === 'function') callback(subjects);
        })
        .catch(function () {
            selSubject.innerHTML = '<option value="" disabled selected>Error loading</option>';
        });
    }

    /* Watch other dropdowns for form validation */
    [selTeacher, selDuty].forEach(function (el) {
        el.addEventListener('change', checkForm);
    });

    /* ════════════════════════════════════════════════════
       STEP 3 — ASSIGN DUTY
    ════════════════════════════════════════════════════ */
    btnAssign.addEventListener('click', function () {
        if (!selClass.value || !selSection.value || !selSubject.value || !selTeacher.value || !selDuty.value) {
            toast('warning', 'Please fill all required fields.');
            return;
        }

        var timeSlot = '';
        if (inpStart.value && inpEnd.value) {
            timeSlot = fmtTime(inpStart.value) + '-' + fmtTime(inpEnd.value);
        }

        btnAssign.disabled     = true;
        btnAssign.innerHTML    = '<i class="fa fa-spinner fa-spin"></i> Assigning…';

        var fd = csrfFd();
        fd.append('class_section', selClass.value + " '" + selSection.value + "'");
        fd.append('subject',       selSubject.value);
        fd.append('teacher_name',  selTeacher.value);
        fd.append('duty_type',     selDuty.value);
        fd.append('time_slot',     timeSlot);

        fetch(BASE_URL + 'staff/assign_duty', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.status === 'success') {
                toast('success', 'Duty assigned successfully.');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                toast('error', (res && res.message) || 'Assignment failed.');
                btnAssign.disabled  = false;
                btnAssign.innerHTML = '<i class="fa fa-check"></i> Assign Duty';
            }
        })
        .catch(function () {
            toast('error', 'Server error. Please try again.');
            btnAssign.disabled  = false;
            btnAssign.innerHTML = '<i class="fa fa-check"></i> Assign Duty';
        });
    });

    /* ════════════════════════════════════════════════════
       STEP 4 — FILL FORM FOR UPDATE (called from table)
    ════════════════════════════════════════════════════ */
    window.tdFillForm = function (btn) {
        isUpdating  = true;
        selectedRow = btn.closest('tr');

        var cells        = selectedRow.cells;
        var classSection = selectedRow.dataset.classSection;   // "Class 9th 'A'"
        var className    = cells[1].textContent.trim();         // "Class 9th"
        var section      = cells[2].textContent.trim();         // "A"
        var subject      = cells[3].textContent.trim();
        var teacher      = cells[4].textContent.trim();
        var dutyType     = cells[5].querySelector('.td-badge') ? cells[5].querySelector('.td-badge').textContent.trim() : cells[5].textContent.trim();
        var dutyTime     = cells[6].textContent.trim();

        originalData = {
            class_section: classSection,
            subject:       subject,
            teacher_name:  teacher,
            duty_type:     dutyType
        };

        /* Populate class */
        selClass.value = className;

        /* Load sections → then load subjects → then set values */
        var fd = csrfFd();
        fd.append('class_name', className);
        fetch(BASE_URL + 'student/get_sections_by_class', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (sections) {
            selSection.innerHTML = '<option value="" disabled selected>Select Section</option>';
            sections.forEach(function (s) {
                var o = document.createElement('option');
                o.value = o.textContent = s;
                selSection.appendChild(o);
            });
            selSection.disabled = false;
            selSection.value    = section;

            loadSubjects(className, section, function () {
                selSubject.value = subject;
                checkForm();
            });
        })
        .catch(function (e) { console.error('fillForm error', e); });

        /* Set teacher + duty type */
        selTeacher.value = teacher;
        selDuty.value    = dutyType;

        /* Set time fields */
        var tm = dutyTime.match(/(\d{1,2}:\d{2}\s*[APMapm]+)\s*-\s*(\d{1,2}:\d{2}\s*[APMapm]+)/);
        if (tm) {
            inpStart.value = to24h(tm[1].trim());
            inpEnd.value   = to24h(tm[2].trim());
        } else {
            inpStart.value = inpEnd.value = '';
        }

        /* Toggle buttons */
        btnAssign.style.display = 'none';
        btnUpdate.style.display = '';
        btnUpdate.disabled      = false;
        btnUpdate.innerHTML     = '<i class="fa fa-refresh"></i> Update Duty';

        /* Scroll form into view */
        document.getElementById('formCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    /* ════════════════════════════════════════════════════
       STEP 5 — UPDATE DUTY
    ════════════════════════════════════════════════════ */
    btnUpdate.addEventListener('click', function () {
        if (!selectedRow) { toast('error', 'No duty selected.'); return; }
        if (!selClass.value || !selSection.value || !selSubject.value || !selTeacher.value || !selDuty.value) {
            toast('warning', 'Please fill all required fields.');
            return;
        }

        var timeSlot = '';
        if (inpStart.value && inpEnd.value) {
            timeSlot = fmtTime(inpStart.value) + '-' + fmtTime(inpEnd.value);
        }

        btnUpdate.disabled  = true;
        btnUpdate.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating…';

        var fd = csrfFd();
        fd.append('class_section', selClass.value + " '" + selSection.value + "'");
        fd.append('subject',       selSubject.value);
        fd.append('teacher_name',  selTeacher.value);
        fd.append('duty_type',     selDuty.value);
        fd.append('time_slot',     timeSlot);

        fetch(BASE_URL + 'staff/assign_duty', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.status === 'success') {
                toast('success', 'Duty updated successfully.');
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                toast('error', (res && res.message) || 'Update failed.');
                btnUpdate.disabled  = false;
                btnUpdate.innerHTML = '<i class="fa fa-refresh"></i> Update Duty';
            }
        })
        .catch(function () {
            toast('error', 'Server error.');
            btnUpdate.disabled  = false;
            btnUpdate.innerHTML = '<i class="fa fa-refresh"></i> Update Duty';
        });
    });

    /* ════════════════════════════════════════════════════
       STEP 6 — MARK INACTIVE / REMOVE DUTY
    ════════════════════════════════════════════════════ */
    window.tdMarkInactive = function (btn) {
        if (!confirm('Remove this duty assignment? This cannot be undone.')) return;

        var row          = btn.closest('tr');
        var classSection = row.dataset.classSection;   // full "Class 9th 'A'"
        var subject      = row.cells[3].textContent.trim();
        var teacher      = row.cells[4].textContent.trim();

        var fd = csrfFd();
        fd.append('class_name',   classSection);
        fd.append('subject',      subject);
        fd.append('teacher_name', teacher);

        fetch(BASE_URL + 'staff/markInactive_duty', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.status === 'success') {
                toast('success', 'Duty removed successfully.');
                row.style.transition = 'opacity .3s';
                row.style.opacity    = '0';
                setTimeout(function () { row.remove(); }, 300);
            } else {
                toast('error', (res && res.message) || 'Failed to remove duty.');
            }
        })
        .catch(function () { toast('error', 'Server error.'); });
    };

    /* ════════════════════════════════════════════════════
       STEP 7 — RESET FORM
    ════════════════════════════════════════════════════ */
    btnReset.addEventListener('click', function () {
        document.getElementById('duty-form').reset();
        selSection.innerHTML = '<option value="" disabled selected>Select Class first</option>';
        selSection.disabled  = true;
        selSubject.innerHTML = '<option value="" disabled selected>Select Section first</option>';
        selSubject.disabled  = true;
        btnAssign.disabled      = true;
        btnAssign.style.display = '';
        btnAssign.innerHTML     = '<i class="fa fa-check"></i> Assign Duty';
        btnUpdate.style.display = 'none';
        isUpdating  = false;
        selectedRow = null;
    });

    /* ── Helpers ── */
    function fmtTime(t) {
        var p = t.split(':'), h = parseInt(p[0], 10), m = p[1];
        var ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ap;
    }
    function to24h(str) {
        var mt = str.match(/(\d{1,2}):(\d{2})\s*([APMapm]+)/);
        if (!mt) return '';
        var h = parseInt(mt[1], 10), mn = mt[2], mer = mt[3].toUpperCase();
        if (mer === 'PM' && h < 12) h += 12;
        else if (mer === 'AM' && h === 12) h = 0;
        return h.toString().padStart(2, '0') + ':' + mn;
    }

})();
</script>


<style>
/* ── Teacher Duty — ERP Gold Theme (day/night aware) ── */

:root, [data-theme="night"], [data-theme="day"] {
    --td-bg:     var(--bg);
    --td-card:   var(--bg2);
    --td-border: var(--border);
    --td-t1:     var(--t1);
    --td-t2:     var(--t2);
    --td-t3:     var(--t3);
    --td-gold:   var(--gold);
    --td-dim:    var(--gold-dim);
    --td-sh:     var(--sh);
    --td-r:      14px;
}

/* ── Wrapper ── */
.td-wrap {
    font-family: var(--font-b);
    background: var(--td-bg);
    color: var(--td-t1);
    padding: 24px 20px 52px;
    min-height: 100vh;
}

/* ── Page title ── */
.td-page-title {
    font-family: var(--font-d);
    font-size: 22px;
    font-weight: 800;
    color: var(--td-t1);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.td-page-title i { color: var(--td-gold); }

/* ── Breadcrumb ── */
.td-breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: var(--td-t3);
    font-family: var(--font-b);
    margin-bottom: 22px;
    list-style: none;
    padding: 0;
}
.td-breadcrumb li:not(:last-child)::after { content: '/'; margin-left: 6px; opacity: .5; }
.td-breadcrumb a { color: var(--td-gold); text-decoration: none; }

/* ── Card ── */
.td-card {
    background: var(--td-card);
    border: 1px solid var(--td-border);
    border-radius: var(--td-r);
    box-shadow: var(--td-sh);
    overflow: hidden;
    margin-bottom: 22px;
}
.td-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 48px;
    background: linear-gradient(90deg, var(--td-gold) 0%, var(--gold2, #0d6b63) 100%);
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    font-family: var(--font-b);
}
.td-count {
    font-size: 11px;
    font-family: var(--font-m);
    background: rgba(255,255,255,.15);
    padding: 3px 10px;
    border-radius: 20px;
}
.td-card-body { padding: 26px 24px; }
.td-card-body--table { padding: 0; }

/* ── Form grid ── */
.td-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
}
.td-field { display: flex; flex-direction: column; gap: 6px; }
.td-field label {
    font-size: 12px;
    font-weight: 700;
    color: var(--td-t2);
    text-transform: uppercase;
    letter-spacing: .5px;
    font-family: var(--font-b);
}
.td-req { color: #e05c6f; }
.td-muted { color: var(--td-t3); font-weight: 400; text-transform: none; letter-spacing: 0; }

/* ── Selects ── */
.td-select {
    height: 42px;
    padding: 0 12px;
    border: 1px solid var(--td-border);
    border-radius: 10px;
    background: var(--bg3, var(--bg));
    color: var(--td-t1);
    font-size: 13px;
    font-family: var(--font-b);
    transition: border-color .2s, box-shadow .2s;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
    width: 100%;
}
.td-select:focus {
    outline: none;
    border-color: var(--td-gold);
    box-shadow: 0 0 0 3px var(--td-dim);
}
.td-select:disabled { opacity: .5; cursor: not-allowed; }

/* ── Time field ── */
.td-time-field { grid-column: span 2; }
.td-time-row { display: flex; align-items: center; gap: 12px; }
.td-time-group { display: flex; align-items: center; gap: 8px; }
.td-time-lbl { font-size: 12px; color: var(--td-t3); font-family: var(--font-m); }
.td-input-time {
    height: 42px;
    padding: 0 12px;
    border: 1px solid var(--td-border);
    border-radius: 10px;
    background: var(--bg3, var(--bg));
    color: var(--td-t1);
    font-size: 13px;
    font-family: var(--font-m);
    transition: border-color .2s, box-shadow .2s;
}
.td-input-time:focus {
    outline: none;
    border-color: var(--td-gold);
    box-shadow: 0 0 0 3px var(--td-dim);
}
.td-time-sep { font-size: 16px; color: var(--td-t3); }

/* ── Form actions ── */
.td-form-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--td-border);
}

/* ── Buttons ── */
.td-btn {
    height: 40px;
    padding: 0 22px;
    border-radius: 20px;
    border: none;
    font-size: 13px;
    font-weight: 600;
    font-family: var(--font-b);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all .2s;
}
.td-btn:disabled { opacity: .5; cursor: not-allowed; }
.td-btn-primary {
    background: linear-gradient(135deg, var(--td-gold) 0%, var(--gold2, #0d6b63) 100%);
    color: #ffffff;
}
.td-btn-primary:not(:disabled):hover { opacity: .9; transform: translateY(-1px); }
.td-btn-warn {
    background: var(--td-dim);
    color: var(--td-gold);
    border: 1px solid var(--td-gold);
}
.td-btn-warn:not(:disabled):hover { background: var(--td-gold); color: #ffffff; }
.td-btn-ghost {
    background: transparent;
    color: var(--td-t3);
    border: 1px solid var(--td-border);
}
.td-btn-ghost:hover { border-color: var(--td-t2); color: var(--td-t1); }

/* ── Table ── */
.td-tbl-wrap { overflow-x: auto; }
.td-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.td-tbl thead th {
    background: linear-gradient(90deg, var(--td-gold) 0%, var(--gold2, #0d6b63) 100%);
    color: #ffffff;
    padding: 13px 16px;
    font-size: 12px;
    font-weight: 700;
    font-family: var(--font-b);
    text-align: left;
    white-space: nowrap;
}
.td-tbl tbody tr { border-bottom: 1px solid var(--td-border); transition: background .15s; }
.td-tbl tbody tr:hover { background: var(--td-dim); }
.td-tbl tbody td {
    padding: 13px 16px;
    color: var(--td-t1);
    vertical-align: middle;
}
.td-num { color: var(--td-t3); font-family: var(--font-m); font-size: 12px; width: 40px; }
.td-teacher { font-size: 12px; color: var(--td-t2); }
.td-time-cell { font-family: var(--font-m); font-size: 12px; color: var(--td-t2); }
.td-empty {
    text-align: center;
    padding: 48px 16px;
    color: var(--td-t3);
    font-size: 14px;
    line-height: 2;
}

/* ── Badges ── */
.td-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    font-family: var(--font-b);
    white-space: nowrap;
}
.td-badge--subject {
    background: var(--td-dim);
    color: var(--td-gold);
    border: 1px solid rgba(15,118,110,.3);
}
.td-badge--class {
    background: rgba(61,214,140,.1);
    color: #3DD68C;
    border: 1px solid rgba(61,214,140,.3);
}

/* ── Action buttons ── */
.td-actions { white-space: nowrap; }
.td-act-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid var(--td-border);
    background: transparent;
    cursor: pointer;
    font-size: 13px;
    transition: all .2s;
    margin-right: 4px;
}
.td-act-edit { color: var(--td-gold); }
.td-act-edit:hover { background: var(--td-dim); border-color: var(--td-gold); }
.td-act-del { color: #e05c6f; }
.td-act-del:hover { background: rgba(224,92,111,.1); border-color: #e05c6f; }

@media (max-width: 600px) {
    .td-time-field { grid-column: span 1; }
    .td-time-row { flex-direction: column; align-items: flex-start; }
}
</style>
