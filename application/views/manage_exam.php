<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ex-wrap">

  <!-- ── Page Header ── -->
  <div class="ex-page-title">
    <i class="fa fa-file-text-o"></i> Create Exam
  </div>
  <ol class="ex-breadcrumb">
    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
    <li>Manage Exam</li>
  </ol>

  <form id="examForm" autocomplete="off">
    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
           value="<?= $this->security->get_csrf_hash() ?>">
    <input type="hidden" id="examScheduleInput" name="examSchedule">

    <!-- ══ CARD 1 — Exam Info ══════════════════════════════════════════ -->
    <div class="ex-card">
      <div class="ex-card-head">
        <i class="fa fa-info-circle"></i> Exam Information
      </div>
      <div class="ex-card-body">
        <div class="ex-grid">

          <div class="ex-field">
            <label for="examName">Exam Name <span class="ex-req">*</span></label>
            <input type="text" id="examName" name="examName"
                   placeholder="e.g. Mid-Term 2026" maxlength="60" required>
          </div>

          <div class="ex-field">
            <label for="gradingScale">Grading Scale <span class="ex-req">*</span></label>
            <select id="gradingScale" name="gradingScale">
              <option value="A+ to F">A+ to F</option>
              <option value="Percentage">Percentage</option>
            </select>
          </div>

          <div class="ex-field">
            <label for="startDate">Start Date <span class="ex-req">*</span></label>
            <input type="date" id="startDate" name="startDate" required>
          </div>

          <div class="ex-field">
            <label for="endDate">End Date <span class="ex-req">*</span></label>
            <input type="date" id="endDate" name="endDate" required>
          </div>

        </div>
      </div>
    </div>

    <!-- ══ CARD 2 — Schedule (injected by JS) ═════════════════════════ -->
    <div id="scheduleSection"></div>

    <!-- ══ CARD 3 — General Instructions ═════════════════════════════ -->
    <div class="ex-card">
      <div class="ex-card-head">
        <i class="fa fa-list-ul"></i> General Instructions
      </div>
      <div class="ex-card-body">
        <textarea id="generalInstructions" name="generalInstructions"
                  rows="5" placeholder="Enter each instruction on a new line..."></textarea>
      </div>
    </div>

    <!-- ══ Submit ═════════════════════════════════════════════════════ -->
    <div class="ex-actions">
      <button type="button" id="saveBtn" class="ex-btn-save">
        <i class="fa fa-save"></i> Save Exam
      </button>
    </div>

  </form>

  <!-- Toast container -->
  <div id="exToastWrap" class="ex-toast-wrap"></div>

</div><!-- /.ex-wrap -->
</div><!-- /.content-wrapper -->


<script>
(function () {
  'use strict';

  /* ── Data from PHP ─────────────────────────────────────── */
  var classList    = <?= json_encode(array_values($classNames ?? [])) ?>;
  var subjectsList = <?= json_encode((object)($subjects ?? [])) ?>;

  /* ── Element refs ─────────────────────────────────────── */
  var startIn   = document.getElementById('startDate');
  var endIn     = document.getElementById('endDate');
  var schedSec  = document.getElementById('scheduleSection');
  var saveBtn   = document.getElementById('saveBtn');
  var textarea  = document.getElementById('generalInstructions');

  /* ── Bullet-point textarea ────────────────────────────── */
  textarea.addEventListener('input', function () {
    if (this.value.length === 1 && this.value !== '•') {
      this.value = '• ' + this.value;
    }
  });
  textarea.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var pos = this.selectionStart;
      this.value = this.value.substring(0, pos) + '\n• ' + this.value.substring(pos);
      this.selectionStart = this.selectionEnd = pos + 3;
    }
  });

  /* ── Date change → regenerate schedule ───────────────── */
  function onDateChange() {
    var s = startIn.value, e = endIn.value;
    if (!s || !e) return;
    var start = new Date(s), end = new Date(e);
    if (isNaN(start) || isNaN(end)) return;
    if (start > end) { showToast('End date must be on or after start date.', 'error'); return; }
    buildSchedule(start, end);
  }
  startIn.addEventListener('change', onDateChange);
  endIn.addEventListener('change', onDateChange);

  function fmtDate(d) {
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function esc(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
      .replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  /* ── Build full schedule section ─────────────────────── */
  function buildSchedule(start, end) {
    if (!classList.length) {
      schedSec.innerHTML =
        '<div class="ex-card"><div class="ex-card-body ex-no-class">' +
        '<i class="fa fa-exclamation-circle"></i> No classes found in this session. ' +
        'Please add classes before creating an exam.</div></div>';
      return;
    }

    var html = '<div class="ex-card">' +
      '<div class="ex-card-head"><i class="fa fa-calendar"></i> Exam Schedule</div>' +
      '<div class="ex-card-body ex-schedule-body">';

    var cur = new Date(start);
    while (cur <= end) {
      var fd = fmtDate(cur);
      html += buildDateBlock(fd);
      cur.setDate(cur.getDate() + 1);
    }
    html += '</div></div>';
    schedSec.innerHTML = html;
  }

  function buildDateBlock(fd) {
    return '<div class="ex-date-block">' +
      '<div class="ex-date-label"><i class="fa fa-calendar-o"></i> ' + esc(fd) + '</div>' +
      '<div class="ex-table-wrap">' +
      '<table class="ex-sched-table"><thead><tr>' +
      '<th>Class</th><th>Subject</th><th>Start Time</th><th>End Time</th>' +
      '<th>Total Marks</th><th></th></tr></thead>' +
      '<tbody data-date="' + esc(fd) + '">' + makeRow() + '</tbody>' +
      '</table></div></div>';
  }

  function classOptions() {
    return classList.map(function (c) {
      return '<option value="' + esc(c) + '">' + esc(c) + '</option>';
    }).join('');
  }

  function makeRow() {
    return '<tr>' +
      '<td><select class="ex-sel cls-sel" onchange="exUpdateSubjects(this)">' +
      '<option value="">— Select Class —</option>' + classOptions() +
      '</select></td>' +
      '<td><select class="ex-sel subj-sel" disabled>' +
      '<option value="">— Select Class First —</option></select></td>' +
      '<td><input type="time" class="ex-time start-time"></td>' +
      '<td><input type="time" class="ex-time end-time"></td>' +
      '<td><input type="number" class="ex-marks" value="100" min="1" max="9999"></td>' +
      '<td class="ex-row-act">' +
      '<button type="button" class="ex-btn-icon ex-btn-add" onclick="exAddRow(this)">' +
      '<i class="fa fa-plus"></i></button>' +
      '<button type="button" class="ex-btn-icon ex-btn-del" onclick="exDelRow(this)">' +
      '<i class="fa fa-trash"></i></button>' +
      '</td></tr>';
  }

  /* ── Exposed to inline onclick ───────────────────────── */
  window.exUpdateSubjects = function (sel) {
    var cls     = sel.value;
    var row     = sel.closest('tr');
    var subjSel = row.querySelector('.subj-sel');
    subjSel.innerHTML = '<option value="">— Select Subject —</option>';
    subjSel.disabled  = !cls;
    if (!cls) return;

    var subs = subjectsList[cls];
    if (subs && typeof subs === 'object') {
      Object.keys(subs).forEach(function (s) {
        var o = document.createElement('option');
        o.value = s; o.textContent = s;
        subjSel.appendChild(o);
      });
    } else {
      subjSel.innerHTML = '<option value="">No subjects found</option>';
      subjSel.disabled = true;
    }
  };

  window.exAddRow = function (btn) {
    var tbody = btn.closest('tbody');
    tbody.insertAdjacentHTML('beforeend', makeRow());
  };

  window.exDelRow = function (btn) {
    var tbody = btn.closest('tbody');
    if (tbody.rows.length <= 1) {
      showToast('At least one row per date is required.', 'warning');
      return;
    }
    btn.closest('tr').remove();
  };

  /* ── Save ────────────────────────────────────────────── */
  saveBtn.addEventListener('click', function () {
    var examName    = document.getElementById('examName').value.trim();
    var startDate   = startIn.value;
    var endDate     = endIn.value;

    if (!examName)    { showToast('Please enter an exam name.', 'error'); return; }
    if (!startDate)   { showToast('Please select a start date.', 'error'); return; }
    if (!endDate)     { showToast('Please select an end date.', 'error'); return; }

    var rows = document.querySelectorAll('.ex-sched-table tbody tr');
    if (!rows.length) {
      showToast('Please set dates to generate the schedule first.', 'error');
      return;
    }

    var scheduleData = [];
    var hasError     = false;

    rows.forEach(function (row) {
      if (hasError) return;
      var tbody  = row.closest('tbody');
      var date   = tbody.dataset.date;
      var cls    = row.querySelector('.cls-sel').value;
      var subj   = row.querySelector('.subj-sel').value;
      var st     = row.querySelector('.start-time').value;
      var et     = row.querySelector('.end-time').value;
      var marks  = row.querySelector('.ex-marks').value;

      if (!cls || !subj || !st || !et || !marks) {
        showToast('Please fill in all fields in every schedule row.', 'error');
        hasError = true;
        return;
      }
      scheduleData.push({
        date:       date,
        className:  cls,
        subject:    subj,
        time:       st + ' - ' + et,
        totalMarks: marks
      });
    });

    if (hasError) return;
    if (!scheduleData.length) {
      showToast('No schedule entries found. Please add rows.', 'error');
      return;
    }

    document.getElementById('examScheduleInput').value = JSON.stringify(scheduleData);

    /* Refresh CSRF token value before sending */
    var csrfName  = '<?= $this->security->get_csrf_token_name() ?>';
    var csrfInput = document.querySelector('input[name="' + csrfName + '"]');

    var fd = new FormData(document.getElementById('examForm'));

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    fetch('<?= base_url('exam/manage_exam') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showToast('Exam saved successfully!', 'success');
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          showToast(res.message || 'Failed to save exam.', 'error');
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i class="fa fa-save"></i> Save Exam';
          /* Refresh CSRF if returned */
          if (res.csrf_token && csrfInput) csrfInput.value = res.csrf_token;
        }
      })
      .catch(function () {
        showToast('Server error. Please try again.', 'error');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa fa-save"></i> Save Exam';
      });
  });

  /* ── Toast helper ────────────────────────────────────── */
  function showToast(msg, type) {
    var wrap  = document.getElementById('exToastWrap');
    var el    = document.createElement('div');
    var icons = { success: 'check-circle', error: 'times-circle', warning: 'exclamation-triangle', info: 'info-circle' };
    el.className = 'ex-toast ex-toast-' + (type || 'info');
    el.innerHTML = '<i class="fa fa-' + (icons[type] || 'info-circle') + '"></i> ' + msg;
    wrap.appendChild(el);
    setTimeout(function () {
      el.classList.add('ex-toast-fade');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
    }, 3200);
  }

})();
</script>


<style>
/* ═══════════════════════════════════════════════════════════
   Exam Module — teal / navy global theme
═══════════════════════════════════════════════════════════ */

.ex-wrap {
  max-width: 1140px;
  margin: 0 auto;
  padding: 24px 16px 56px;
}

/* ── Header ──────────────────────────────────────────────── */
.ex-page-title {
  font-size: 1.45rem;
  font-weight: 700;
  color: var(--t1);
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.ex-page-title i { color: var(--gold); }

.ex-breadcrumb {
  list-style: none;
  margin: 0 0 22px;
  padding: 0;
  display: flex;
  gap: 6px;
  font-size: .83rem;
  color: var(--t3);
}
.ex-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.ex-breadcrumb a { color: var(--gold); text-decoration: none; }
.ex-breadcrumb a:hover { text-decoration: underline; }

/* ── Cards ───────────────────────────────────────────────── */
.ex-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  margin-bottom: 20px;
  overflow: hidden;
  box-shadow: var(--sh);
}
.ex-card-head {
  background: var(--gold);
  color: #fff;
  font-size: .92rem;
  font-weight: 600;
  padding: 11px 18px;
  display: flex;
  align-items: center;
  gap: 9px;
}
.ex-card-body { padding: 20px; }

/* ── 4-column info grid ──────────────────────────────────── */
.ex-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
}
@media (max-width: 860px) { .ex-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px) { .ex-grid { grid-template-columns: 1fr; } }

.ex-field { display: flex; flex-direction: column; gap: 5px; }
.ex-field label {
  font-size: .82rem;
  font-weight: 600;
  color: var(--t2);
  letter-spacing: .02em;
}
.ex-req { color: #ef4444; }

.ex-field input,
.ex-field select {
  padding: 8px 11px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .88rem;
  width: 100%;
  box-sizing: border-box;
  transition: border-color .18s, box-shadow .18s;
}
.ex-field input:focus,
.ex-field select:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px var(--gold-ring);
}

/* ── Schedule card body ──────────────────────────────────── */
.ex-schedule-body { padding: 0; }

.ex-date-block {
  border-bottom: 1px solid var(--border);
}
.ex-date-block:last-child { border-bottom: none; }

.ex-date-label {
  background: var(--gold-dim);
  border-bottom: 1px solid var(--border);
  padding: 9px 18px;
  font-size: .84rem;
  font-weight: 700;
  color: var(--gold);
  display: flex;
  align-items: center;
  gap: 7px;
}

.ex-table-wrap { overflow-x: auto; }

.ex-sched-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 680px;
}
.ex-sched-table th {
  background: var(--bg3);
  color: var(--t2);
  font-size: .78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .04em;
  padding: 9px 12px;
  text-align: left;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.ex-sched-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.ex-sched-table tr:last-child td { border-bottom: none; }
.ex-sched-table tr:hover td { background: var(--gold-dim); }

/* Inline selects & inputs inside the table */
.ex-sel {
  padding: 6px 10px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: var(--bg2);
  color: var(--t1);
  font-size: .84rem;
  width: 100%;
  min-width: 140px;
  box-sizing: border-box;
}
.ex-sel:focus { outline: none; border-color: var(--gold); }

.ex-time {
  padding: 6px 8px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: var(--bg2);
  color: var(--t1);
  font-size: .84rem;
  width: 100%;
  min-width: 100px;
  box-sizing: border-box;
}
.ex-time:focus { outline: none; border-color: var(--gold); }

.ex-marks {
  padding: 6px 8px;
  border: 1px solid var(--border);
  border-radius: 5px;
  background: var(--bg2);
  color: var(--t1);
  font-size: .84rem;
  width: 80px;
  box-sizing: border-box;
}
.ex-marks:focus { outline: none; border-color: var(--gold); }

/* Row action buttons */
.ex-row-act { white-space: nowrap; }
.ex-btn-icon {
  width: 28px; height: 28px;
  border: none;
  border-radius: 5px;
  cursor: pointer;
  font-size: .78rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-right: 4px;
  transition: opacity .18s, transform .1s;
}
.ex-btn-icon:active { transform: scale(.93); }
.ex-btn-add { background: var(--gold); color: #fff; }
.ex-btn-del { background: #ef4444; color: #fff; }
.ex-btn-add:hover { opacity: .85; }
.ex-btn-del:hover { opacity: .85; }

/* No-classes notice */
.ex-no-class {
  text-align: center;
  padding: 28px;
  color: var(--t3);
  font-size: .9rem;
}
.ex-no-class i { color: #d97706; margin-right: 6px; }

/* ── Instructions textarea ──────────────────────────────── */
.ex-card-body textarea {
  width: 100%;
  padding: 10px 13px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .88rem;
  resize: vertical;
  box-sizing: border-box;
  line-height: 1.6;
}
.ex-card-body textarea:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px var(--gold-ring);
}

/* ── Actions row ─────────────────────────────────────────── */
.ex-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 4px;
}
.ex-btn-save {
  padding: 10px 28px;
  background: var(--gold);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: .92rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: background .18s, transform .1s;
}
.ex-btn-save:hover:not(:disabled) { background: var(--gold2); }
.ex-btn-save:active:not(:disabled) { transform: scale(.97); }
.ex-btn-save:disabled { opacity: .65; cursor: not-allowed; }

/* ── Toast ───────────────────────────────────────────────── */
.ex-toast-wrap {
  position: fixed;
  bottom: 24px;
  right: 24px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  z-index: 9999;
}
.ex-toast {
  padding: 11px 18px;
  border-radius: 8px;
  font-size: .86rem;
  font-weight: 500;
  color: #fff;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 4px 18px rgba(0,0,0,.22);
  animation: ex-slide-in .3s ease;
  min-width: 240px;
}
.ex-toast-success { background: #0f766e; }
.ex-toast-error   { background: #dc2626; }
.ex-toast-warning { background: #d97706; }
.ex-toast-info    { background: #2563eb; }
.ex-toast-fade    { opacity: 0; transition: opacity .4s; }

@keyframes ex-slide-in {
  from { transform: translateX(60px); opacity: 0; }
  to   { transform: translateX(0);    opacity: 1; }
}
</style>
