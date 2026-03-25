<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ec-wrap">

  <!-- ── Page Header ─────────────────────────────────────────────────── -->
  <div class="ec-page-title"><i class="fa fa-plus-square-o"></i> Create Exam</div>
  <ol class="ec-breadcrumb">
    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
    <li><a href="<?= base_url('exam') ?>">Exams</a></li>
    <li>Create</li>
  </ol>

  <form id="examForm" autocomplete="off" novalidate>
    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
           value="<?= $this->security->get_csrf_hash() ?>" id="csrfInput">
    <input type="hidden" id="examScheduleInput" name="examSchedule">

    <div class="ec-layout">

      <!-- ══ LEFT PANEL ══════════════════════════════════════════════ -->
      <div class="ec-left">

        <!-- Card 1 — Exam Identity -->
        <div class="ex-card">
          <div class="ex-card-head"><i class="fa fa-info-circle"></i> Exam Information</div>
          <div class="ex-card-body">
            <div class="ec-grid6">

              <div class="ex-field ec-span2">
                <label for="examName">Exam Name <span class="ex-req">*</span></label>
                <input type="text" id="examName" name="examName"
                       placeholder="e.g. Mid-Term 2026" maxlength="80" required>
              </div>

              <div class="ex-field">
                <label for="examType">Type <span class="ex-req">*</span></label>
                <select id="examType" name="examType">
                  <option>Mid-Term</option>
                  <option>Final Term</option>
                  <option>Unit Test</option>
                  <option>Weekly Test</option>
                  <option>Pre-Board</option>
                  <option>Annual</option>
                </select>
              </div>

              <div class="ex-field">
                <label for="gradingScale">Grading Scale <span class="ex-req">*</span></label>
                <select id="gradingScale" name="gradingScale">
                  <option value="Percentage">Percentage</option>
                  <option value="A-F Grades">A-F Grades</option>
                  <option value="O-E Grades">O-E Grades</option>
                  <option value="10-Point">10-Point</option>
                  <option value="Pass/Fail">Pass/Fail</option>
                </select>
              </div>

              <div class="ex-field" id="passingPctField">
                <label for="passingPercent">Passing % <span class="ex-req">*</span></label>
                <input type="number" id="passingPercent" name="passingPercent"
                       value="33" min="1" max="100">
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

            <!-- Status pills -->
            <div class="ec-status-row">
              <span class="ec-status-label">Status:</span>
              <label class="ec-status-pill">
                <input type="radio" name="examStatus" value="Draft" checked>
                <span>Draft</span>
              </label>
              <label class="ec-status-pill">
                <input type="radio" name="examStatus" value="Published">
                <span>Published</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Card 2 — Schedule Builder -->
        <div class="ex-card">
          <div class="ex-card-head">
            <i class="fa fa-calendar"></i> Exam Schedule
            <div class="ec-sched-btns">
              <button type="button" class="ec-sched-btn-add" id="addRowBtn">
                <i class="fa fa-plus"></i> Add Row
              </button>
              <button type="button" class="ec-sched-btn-clear" id="clearAllBtn">
                <i class="fa fa-times"></i> Clear All
              </button>
            </div>
          </div>
          <div class="ex-card-body ec-sched-body" id="scheduleCardBody">
            <div class="ex-table-wrap">
              <table class="ex-sched-table" id="schedTable">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Total Marks</th>
                    <th>Passing Marks</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody id="schedTbody"></tbody>
              </table>
            </div>
            <div class="ec-sched-empty" id="schedEmpty">
              <i class="fa fa-calendar-o"></i>
              <span>No schedule rows yet. Click <strong>Add Row</strong> to begin.</span>
            </div>
          </div>
        </div>

        <!-- Card 3 — Instructions -->
        <div class="ex-card">
          <div class="ex-card-head"><i class="fa fa-list-ul"></i> General Instructions</div>
          <div class="ex-card-body">
            <textarea id="generalInstructions" name="generalInstructions"
                      rows="5" placeholder="Enter each instruction on a new line…"></textarea>
          </div>
        </div>

      </div><!-- /.ec-left -->

      <!-- ══ RIGHT PANEL — Live Summary ══════════════════════════════ -->
      <div class="ec-right">
        <div class="ec-summary">
          <div class="ec-sum-head"><i class="fa fa-eye"></i> Live Summary</div>
          <div class="ec-sum-body">
            <div class="ec-sum-name" id="sumName">—</div>
            <div class="ec-sum-badges" id="sumBadges">
              <span class="ec-sum-badge ec-sum-badge-type" id="sumType">—</span>
              <span class="ec-sum-badge ec-sum-badge-status" id="sumStatus">Draft</span>
            </div>
            <div class="ec-sum-row" id="sumDates">
              <i class="fa fa-calendar-o"></i> <span>No dates set</span>
            </div>
            <div class="ec-sum-divider"></div>
            <div class="ec-sum-stat">
              <span class="ec-sum-stat-label">Classes</span>
              <span class="ec-sum-stat-val" id="sumClasses">0</span>
            </div>
            <div class="ec-sum-stat">
              <span class="ec-sum-stat-label">Schedule Entries</span>
              <span class="ec-sum-stat-val" id="sumEntries">0</span>
            </div>
            <div class="ec-sum-stat">
              <span class="ec-sum-stat-label">Passing %</span>
              <span class="ec-sum-stat-val" id="sumPct">33%</span>
            </div>
          </div>
          <div class="ec-sum-foot">
            <button type="button" id="saveBtn" class="ec-btn-save">
              <i class="fa fa-save"></i> Save Exam
            </button>
          </div>
        </div>
      </div><!-- /.ec-right -->

    </div><!-- /.ec-layout -->
  </form>

  <!-- Toast container -->
  <div id="exToastWrap" class="ex-toast-wrap"></div>

</div><!-- /.ec-wrap -->
</div><!-- /.content-wrapper -->


<script>
(function () {
  'use strict';

  /* ── PHP data ───────────────────────────────────────────────────── */
  var classList    = <?= json_encode(array_values($classNames ?? [])) ?>;

  /* ── DOM refs ──────────────────────────────────────────────────── */
  var examNameIn   = document.getElementById('examName');
  var typeSelect   = document.getElementById('examType');
  var statusRadios = document.querySelectorAll('input[name="examStatus"]');
  var scaleSelect  = document.getElementById('gradingScale');
  var pctIn        = document.getElementById('passingPercent');
  var startIn      = document.getElementById('startDate');
  var endIn        = document.getElementById('endDate');
  var instrTA      = document.getElementById('generalInstructions');
  var tbody        = document.getElementById('schedTbody');
  var addRowBtn    = document.getElementById('addRowBtn');
  var clearAllBtn  = document.getElementById('clearAllBtn');
  var schedEmpty   = document.getElementById('schedEmpty');
  var saveBtn      = document.getElementById('saveBtn');

  /* ── Live summary refs ─────────────────────────────────────────── */
  var sumName    = document.getElementById('sumName');
  var sumType    = document.getElementById('sumType');
  var sumStatus  = document.getElementById('sumStatus');
  var sumDates   = document.getElementById('sumDates');
  var sumClasses = document.getElementById('sumClasses');
  var sumEntries = document.getElementById('sumEntries');
  var sumPct     = document.getElementById('sumPct');

  /* ── Grading-scale helpers ───────────────────────────────────────── */
  var scalesNoPass = ['A-F Grades', 'O-E Grades', 'Pass/Fail'];
  var pctField     = document.getElementById('passingPctField');
  var schedTable   = document.getElementById('schedTable');

  function isPassScale() {
    return scalesNoPass.indexOf(scaleSelect.value) === -1;
  }

  function togglePassingPct() {
    var relevant = isPassScale();
    if (pctField)   pctField.style.display = relevant ? '' : 'none';
    if (schedTable) schedTable.classList.toggle('hide-pass-col', !relevant);
    updateSummary();
  }

  /* ── Helpers ────────────────────────────────────────────────────── */
  function esc(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/"/g,'&quot;')
      .replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmtDate(d) {
    return pad(d.getDate()) + '/' + pad(d.getMonth()+1) + '/' + d.getFullYear();
  }

  /* ── Bullet textarea ────────────────────────────────────────────── */
  instrTA.addEventListener('input', function () {
    if (this.value.length === 1 && this.value !== '•') {
      this.value = '• ' + this.value;
    }
  });
  instrTA.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var pos = this.selectionStart;
      this.value = this.value.substring(0, pos) + '\n• ' + this.value.substring(pos);
      this.selectionStart = this.selectionEnd = pos + 3;
    }
  });

  /* ── Row HTML builder ───────────────────────────────────────────── */
  function makeRow(defaultDate) {
    var dateMin = startIn.value || '';
    var dateMax = endIn.value   || '';
    var dval    = defaultDate   || '';

    var classOpts = '<option value="">— Class —</option>';
    classList.forEach(function (c) {
      classOpts += '<option value="' + esc(c) + '">' + esc(c) + '</option>';
    });

    return '<tr class="ec-sched-row">' +
      '<td><input type="date" class="ex-time ec-date-in"' +
           (dval    ? ' value="' + esc(dval) + '"' : '') +
           (dateMin ? ' min="' + esc(dateMin) + '"' : '') +
           (dateMax ? ' max="' + esc(dateMax) + '"' : '') + '></td>' +
      '<td><select class="ex-sel cls-sel" onchange="ecUpdateSubjects(this)">' + classOpts + '</select></td>' +
      '<td><select class="ex-sel subj-sel" disabled><option value="">— Select Class —</option></select></td>' +
      '<td><input type="time" class="ex-time start-time"></td>' +
      '<td><input type="time" class="ex-time end-time"></td>' +
      '<td><input type="number" class="ex-marks total-marks" value="100" min="1" max="9999" oninput="ecAutoPassMks(this)"></td>' +
      '<td><input type="number" class="ex-marks pass-marks" value="' + Math.round(100 * parseInt(pctIn.value||33) / 100) + '" min="0" max="9999"></td>' +
      '<td class="ex-row-act">' +
        '<button type="button" class="ex-btn-icon ec-btn-dup" onclick="ecDupRow(this)" title="Duplicate"><i class="fa fa-copy"></i></button>' +
        '<button type="button" class="ex-btn-icon ex-btn-del" onclick="ecDelRow(this)" title="Remove"><i class="fa fa-trash"></i></button>' +
      '</td></tr>';
  }

  function toggleEmpty() {
    var hasRows = tbody.rows.length > 0;
    schedEmpty.style.display = hasRows ? 'none' : '';
    updateSummary();
  }

  /* ── Exposed to inline handlers ─────────────────────────────────── */
  window.ecUpdateSubjects = function (sel) {
    var cls     = sel.value;
    var row     = sel.closest('tr');
    var subjSel = row.querySelector('.subj-sel');
    subjSel.innerHTML = '<option value="">— Select Class —</option>';
    subjSel.disabled  = true;
    if (!cls) { updateSummary(); return; }
    subjSel.innerHTML = '<option value="">Loading…</option>';
    fetch('<?= base_url('exam/get_subjects') ?>?class=' + encodeURIComponent(cls))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        subjSel.innerHTML = '<option value="">— Select Subject —</option>';
        if (res.subjects && res.subjects.length) {
          res.subjects.forEach(function (s) {
            var o = document.createElement('option');
            o.value = o.textContent = s;
            subjSel.appendChild(o);
          });
          subjSel.disabled = false;
        } else {
          subjSel.innerHTML = '<option value="">No subjects found</option>';
        }
        updateSummary();
      })
      .catch(function () {
        subjSel.innerHTML = '<option value="">Error loading subjects</option>';
      });
  };

  window.ecAutoPassMks = function (totalIn) {
    var row  = totalIn.closest('tr');
    var pass = row.querySelector('.pass-marks');
    if (!isPassScale()) { pass.value = 0; updateSummary(); return; }
    var pct  = parseInt(pctIn.value) || 33;
    pass.value = Math.round(parseInt(totalIn.value || 0) * pct / 100);
    updateSummary();
  };

  window.ecDelRow = function (btn) {
    btn.closest('tr').remove();
    toggleEmpty();
  };

  window.ecDupRow = function (btn) {
    var row    = btn.closest('tr');
    var clone  = row.cloneNode(true);
    row.parentNode.insertBefore(clone, row.nextSibling);
    toggleEmpty();
  };

  /* ── Re-calc all passing marks when % changes ───────────────────── */
  pctIn.addEventListener('input', function () {
    if (!isPassScale()) return;
    var pct = parseInt(this.value) || 33;
    document.querySelectorAll('#schedTbody .ec-sched-row').forEach(function (row) {
      var tm = parseInt(row.querySelector('.total-marks').value || 0);
      row.querySelector('.pass-marks').value = Math.round(tm * pct / 100);
    });
    updateSummary();
  });

  /* ── Update date constraints on all rows when exam dates change ── */
  function updateRowDateConstraints() {
    var dmin = startIn.value || '';
    var dmax = endIn.value   || '';
    document.querySelectorAll('#schedTbody .ec-date-in').forEach(function (inp) {
      if (dmin) inp.min = dmin;
      if (dmax) inp.max = dmax;
    });
  }
  startIn.addEventListener('change', function () { updateRowDateConstraints(); updateSummary(); });
  endIn.addEventListener('change',   function () { updateRowDateConstraints(); updateSummary(); });

  /* ── Add row / clear all ────────────────────────────────────────── */
  addRowBtn.addEventListener('click', function () {
    tbody.insertAdjacentHTML('beforeend', makeRow(startIn.value || ''));
    toggleEmpty();
  });
  clearAllBtn.addEventListener('click', function () {
    tbody.innerHTML = '';
    toggleEmpty();
  });

  /* ── Live summary updater ───────────────────────────────────────── */
  function getSelectedStatus() {
    for (var i = 0; i < statusRadios.length; i++) {
      if (statusRadios[i].checked) return statusRadios[i].value;
    }
    return 'Draft';
  }

  function updateSummary() {
    var name   = examNameIn.value.trim() || '—';
    var type   = typeSelect.value        || '—';
    var status = getSelectedStatus();
    var pct    = isPassScale() ? (pctIn.value || '33') + '%' : 'N/A';
    var sd     = startIn.value;
    var ed     = endIn.value;

    sumName.textContent   = name;
    sumType.textContent   = type;
    sumStatus.textContent = status;
    sumStatus.className   = 'ec-sum-badge ec-sum-badge-status ec-sum-status-' + status.toLowerCase().replace(' ','-');
    sumPct.textContent    = pct;

    if (sd || ed) {
      var sd2 = sd ? new Date(sd).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '?';
      var ed2 = ed ? new Date(ed).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) : '?';
      sumDates.innerHTML = '<i class="fa fa-calendar-o"></i> <span>' + sd2 + ' – ' + ed2 + '</span>';
    } else {
      sumDates.innerHTML = '<i class="fa fa-calendar-o"></i> <span>No dates set</span>';
    }

    // Count unique classes and total entries
    var classSet = {};
    var entries  = 0;
    document.querySelectorAll('#schedTbody .ec-sched-row').forEach(function (row) {
      var cls = row.querySelector('.cls-sel').value;
      if (cls) { classSet[cls] = true; entries++; }
    });
    sumClasses.textContent = Object.keys(classSet).length;
    sumEntries.textContent = entries;
  }

  examNameIn.addEventListener('input', updateSummary);
  typeSelect.addEventListener('change', updateSummary);
  scaleSelect.addEventListener('change', togglePassingPct);
  statusRadios.forEach(function (r) { r.addEventListener('change', updateSummary); });
  updateSummary();
  togglePassingPct();

  /* ── Save ───────────────────────────────────────────────────────── */
  saveBtn.addEventListener('click', function () {
    var name    = examNameIn.value.trim();
    var startDt = startIn.value;
    var endDt   = endIn.value;

    if (!name)    { showToast('Please enter an exam name.', 'error'); return; }
    if (!startDt) { showToast('Please select a start date.', 'error'); return; }
    if (!endDt)   { showToast('Please select an end date.', 'error'); return; }
    if (startDt > endDt) { showToast('End date must be on or after start date.', 'error'); return; }

    var rows = Array.from(document.querySelectorAll('#schedTbody .ec-sched-row'));
    if (!rows.length) {
      showToast('Please add at least one schedule row.', 'error');
      return;
    }

    var scheduleData = [];
    var hasError     = false;

    rows.forEach(function (row) {
      if (hasError) return;
      var date  = row.querySelector('.ec-date-in').value;
      var cls   = row.querySelector('.cls-sel').value;
      var subj  = row.querySelector('.subj-sel').value;
      var st    = row.querySelector('.start-time').value;
      var et    = row.querySelector('.end-time').value;
      var total = row.querySelector('.total-marks').value;
      var pass  = isPassScale() ? row.querySelector('.pass-marks').value : '0';

      if (!date || !cls || !subj || !st || !et || !total) {
        showToast('Please fill in all fields in every schedule row.', 'error');
        hasError = true;
        return;
      }
      // Convert date from YYYY-MM-DD to DD/MM/YYYY for server
      var dtParts = date.split('-');
      var fmtDate = dtParts[2] + '/' + dtParts[1] + '/' + dtParts[0];
      scheduleData.push({
        date:         fmtDate,
        className:    cls,
        subject:      subj,
        startTime:    st,
        endTime:      et,
        totalMarks:   parseInt(total),
        passingMarks: parseInt(pass || '0')
      });
    });

    if (hasError) return;

    document.getElementById('examScheduleInput').value = JSON.stringify(scheduleData);

    var csrfName  = '<?= $this->security->get_csrf_token_name() ?>';
    var fd = new FormData(document.getElementById('examForm'));

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    fetch('<?= base_url('exam/create') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showToast(res.message || 'Exam created!', 'success');
          setTimeout(function () {
            window.location.href = '<?= base_url('exam') ?>';
          }, 1400);
        } else {
          showToast(res.message || 'Failed to save exam.', 'error');
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i class="fa fa-save"></i> Save Exam';
          // Refresh CSRF
          var csrfIn = document.getElementById('csrfInput');
          if (res.csrf_token && csrfIn) csrfIn.value = res.csrf_token;
        }
      })
      .catch(function () {
        showToast('Server error. Please try again.', 'error');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa fa-save"></i> Save Exam';
      });
  });

  /* ── Toast ──────────────────────────────────────────────────────── */
  function showToast(msg, type) {
    var wrap  = document.getElementById('exToastWrap');
    var el    = document.createElement('div');
    var icons = { success:'check-circle', error:'times-circle', warning:'exclamation-triangle', info:'info-circle' };
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
/* Fix rem scale: Bootstrap 3 sets html{font-size:10px}; override so 1rem=16px */
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Exam Create — .ec-* additions (reuses .ex-* from manage_exam)
═══════════════════════════════════════════════════════════ */

/* Inherited .ex-* styles inline below (full set so no dependency) */
.ec-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 56px; }

.ec-page-title {
  font-size: 1.45rem; font-weight: 700; color: var(--t1);
  margin-bottom: 4px; display: flex; align-items: center; gap: 10px;
}
.ec-page-title i { color: var(--gold); }
.ec-breadcrumb {
  list-style: none; margin: 0 0 22px; padding: 0;
  display: flex; gap: 6px; font-size: .83rem; color: var(--t3);
}
.ec-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.ec-breadcrumb a { color: var(--gold); text-decoration: none; }
.ec-breadcrumb a:hover { text-decoration: underline; }

/* Two-panel layout */
.ec-layout { display: flex; gap: 20px; align-items: flex-start; }
.ec-left  { flex: 1; min-width: 0; }
.ec-right { width: 280px; flex-shrink: 0; position: sticky; top: 16px; }

@media (max-width: 860px) {
  .ec-layout  { flex-direction: column; }
  .ec-right   { width: 100%; position: static; }
}

/* 6-col grid */
.ec-grid6 {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 14px;
  margin-bottom: 14px;
}
.ec-span2 { grid-column: span 2; }
@media (max-width: 860px) {
  .ec-grid6 { grid-template-columns: repeat(3,1fr); }
  .ec-span2 { grid-column: span 3; }
}
@media (max-width: 520px) {
  .ec-grid6 { grid-template-columns: repeat(2,1fr); }
  .ec-span2 { grid-column: span 2; }
}

/* Status pills */
.ec-status-row {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
.ec-status-label { font-size: .82rem; font-weight: 600; color: var(--t2); }
.ec-status-pill { display: inline-flex; align-items: center; cursor: pointer; }
.ec-status-pill input[type="radio"] { display: none; }
.ec-status-pill span {
  padding: 5px 16px;
  border: 1px solid var(--border);
  border-radius: 20px;
  font-size: .82rem;
  font-weight: 600;
  color: var(--t2);
  transition: all .18s;
}
.ec-status-pill input[type="radio"]:checked + span {
  background: var(--gold);
  border-color: var(--gold);
  color: #fff;
}

/* Schedule card — buttons in head */
.ex-card-head { display: flex; align-items: center; gap: 9px; }
.ec-sched-btns { margin-left: auto; display: flex; gap: 7px; }
.ec-sched-btn-add, .ec-sched-btn-clear {
  padding: 4px 12px;
  border: none;
  border-radius: 5px;
  font-size: .78rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: opacity .18s;
}
.ec-sched-btn-add   { background: rgba(255,255,255,.25); color: #fff; }
.ec-sched-btn-clear { background: rgba(239,68,68,.25);  color: #fff; }
.ec-sched-btn-add:hover   { opacity: .8; }
.ec-sched-btn-clear:hover { opacity: .8; }

.ec-sched-body  { padding: 0 !important; }
.ec-sched-empty {
  padding: 28px;
  text-align: center;
  color: var(--t3);
  font-size: .88rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
}
.ec-sched-empty i { font-size: 1.2rem; color: var(--gold); }

.ec-btn-dup {
  width: 28px; height: 28px;
  border: none; border-radius: 5px;
  cursor: pointer; font-size: .78rem;
  display: inline-flex; align-items: center; justify-content: center;
  margin-right: 4px;
  background: var(--gold-dim); color: var(--gold);
  transition: opacity .18s, transform .1s;
}
.ec-btn-dup:hover { opacity: .8; }

/* Summary panel */
.ec-summary {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  box-shadow: var(--sh);
}
.ec-sum-head {
  background: var(--gold);
  color: #fff;
  font-size: .9rem;
  font-weight: 600;
  padding: 11px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.ec-sum-body { padding: 16px; }
.ec-sum-name {
  font-size: 1rem;
  font-weight: 700;
  color: var(--t1);
  margin-bottom: 10px;
  word-break: break-word;
}
.ec-sum-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.ec-sum-badge {
  padding: 3px 10px;
  border-radius: 20px;
  font-size: .72rem;
  font-weight: 700;
  color: #fff;
}
.ec-sum-badge-type { background: #2563eb; }
.ec-sum-badge-status { background: #d97706; }
.ec-sum-status-published { background: #16a34a !important; }
.ec-sum-status-draft     { background: #d97706 !important; }
.ec-sum-status-completed { background: var(--gold) !important; }

.ec-sum-row { font-size: .82rem; color: var(--t3); display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.ec-sum-divider { border-top: 1px solid var(--border); margin: 12px 0; }
.ec-sum-stat { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.ec-sum-stat-label { font-size: .8rem; color: var(--t3); }
.ec-sum-stat-val { font-size: .92rem; font-weight: 700; color: var(--gold); }
.ec-sum-foot { padding: 12px 16px 16px; }
.ec-btn-save {
  width: 100%;
  padding: 10px;
  background: var(--gold);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: .92rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  transition: background .18s, transform .1s;
}
.ec-btn-save:hover:not(:disabled) { background: var(--gold2); }
.ec-btn-save:active:not(:disabled) { transform: scale(.97); }
.ec-btn-save:disabled { opacity: .65; cursor: not-allowed; }

/* ── Reuse ex-* base styles ──────────────────────────────── */
.ex-card { background:var(--bg2); border:1px solid var(--border); border-radius:10px; margin-bottom:20px; overflow:hidden; box-shadow:var(--sh); }
.ex-card-head { background:var(--gold); color:#fff; font-size:.92rem; font-weight:600; padding:11px 18px; }
.ex-card-body { padding:20px; }
.ex-field { display:flex; flex-direction:column; gap:5px; }
.ex-field label { font-size:.82rem; font-weight:600; color:var(--t2); letter-spacing:.02em; }
.ex-req { color:#ef4444; }
.ex-field input, .ex-field select {
  padding:8px 11px; border:1px solid var(--border); border-radius:6px;
  background:var(--bg3); color:var(--t1); font-size:.88rem; width:100%; box-sizing:border-box;
  transition:border-color .18s, box-shadow .18s;
}
.ex-field input:focus, .ex-field select:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }
.ex-card-body textarea { width:100%; padding:10px 13px; border:1px solid var(--border); border-radius:6px; background:var(--bg3); color:var(--t1); font-size:.88rem; resize:vertical; box-sizing:border-box; line-height:1.6; }
.ex-card-body textarea:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }
.ex-table-wrap { overflow-x:auto; }
.ex-sched-table { width:100%; border-collapse:collapse; min-width:780px; }
.ex-sched-table th { background:var(--bg3); color:var(--t2); font-size:.78rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; padding:9px 12px; text-align:left; border-bottom:1px solid var(--border); white-space:nowrap; }
.ex-sched-table td { padding:7px 8px; border-bottom:1px solid var(--border); vertical-align:middle; }
.ex-sched-table tr:last-child td { border-bottom:none; }
.ex-sched-table tr:hover td { background:var(--gold-dim); }
.ex-sel { padding:6px 8px; border:1px solid var(--border); border-radius:5px; background:var(--bg2); color:var(--t1); font-size:.84rem; width:100%; min-width:120px; box-sizing:border-box; }
.ex-sel:focus { outline:none; border-color:var(--gold); }
.ex-time { padding:6px 8px; border:1px solid var(--border); border-radius:5px; background:var(--bg2); color:var(--t1); font-size:.84rem; width:100%; min-width:95px; box-sizing:border-box; }
.ex-time:focus { outline:none; border-color:var(--gold); }
.ex-marks { padding:6px 8px; border:1px solid var(--border); border-radius:5px; background:var(--bg2); color:var(--t1); font-size:.84rem; width:80px; box-sizing:border-box; }
.ex-marks:focus { outline:none; border-color:var(--gold); }
.ex-row-act { white-space:nowrap; }
.ex-btn-icon { width:28px; height:28px; border:none; border-radius:5px; cursor:pointer; font-size:.78rem; display:inline-flex; align-items:center; justify-content:center; margin-right:4px; transition:opacity .18s, transform .1s; }
.ex-btn-icon:active { transform:scale(.93); }
.ex-btn-del { background:#ef4444; color:#fff; }
.ex-btn-del:hover { opacity:.85; }
.ex-toast-wrap { position:fixed; bottom:24px; right:24px; display:flex; flex-direction:column; gap:10px; z-index:9999; }
.ex-toast { padding:11px 18px; border-radius:8px; font-size:.86rem; font-weight:500; color:#fff; display:flex; align-items:center; gap:8px; box-shadow:0 4px 18px rgba(0,0,0,.22); animation:ex-slide-in .3s ease; min-width:240px; }
.ex-toast-success { background:#0f766e; }
.ex-toast-error   { background:#dc2626; }
.ex-toast-warning { background:#d97706; }
.ex-toast-info    { background:#2563eb; }
.ex-toast-fade    { opacity:0; transition:opacity .4s; }
@keyframes ex-slide-in { from{transform:translateX(60px);opacity:0} to{transform:translateX(0);opacity:1} }

/* Hide Passing Marks column when scale is letter/pass-fail */
.hide-pass-col th:nth-child(7),
.hide-pass-col td:nth-child(7) { display: none; }
</style>
