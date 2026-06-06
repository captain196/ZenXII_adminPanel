<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── UX-1.2 Wizard shell chrome (presentation only) ── */
.ec-stepind { display:flex; gap:8px; margin:0 0 18px; flex-wrap:wrap; }
.ec-stepind-item { display:flex; align-items:center; gap:8px; padding:8px 14px; border-radius:9px; background:var(--bg3,#f1f5f9); color:var(--t2,#64748b); font-size:.85rem; font-weight:600; cursor:pointer; border:1px solid var(--border,#e2e8f0); transition:all .15s; }
.ec-stepind-num { width:22px; height:22px; border-radius:50%; background:var(--border,#cbd5e1); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.78rem; flex:0 0 auto; }
.ec-stepind-item.active { background:var(--gold,#0d9488); color:#fff; border-color:transparent; }
.ec-stepind-item.active .ec-stepind-num { background:rgba(255,255,255,.3); }
.ec-stepind-item.done .ec-stepind-num { background:#16a34a; }
.ec-wizfoot { position:sticky; bottom:0; z-index:50; display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:18px; padding:12px 16px; background:var(--bg2,#fff); border-top:1px solid var(--border,#e2e8f0); box-shadow:0 -4px 18px rgba(0,0,0,.06); }
.ec-wizfoot-right { display:flex; gap:10px; align-items:center; }
.ec-wiz-btn { padding:10px 20px; border-radius:9px; font-size:.9rem; font-weight:600; cursor:pointer; border:1px solid var(--border,#e2e8f0); display:inline-flex; align-items:center; gap:8px; }
.ec-wiz-back { background:var(--bg3,#f1f5f9); color:var(--t2,#64748b); }
.ec-wiz-back:hover { background:var(--border,#e2e8f0); }
.ec-wiz-next { background:var(--gold,#0d9488); color:#fff; border-color:transparent; }

/* ── UX-1.3 ZenX primitives (DS-ready; reusable, not page-specific) ── */
.zx-sr-only { position:absolute!important; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
.zx-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 18px; border-radius:9px; font-size:.9rem; font-weight:600; line-height:1; cursor:pointer; border:1px solid transparent; transition:background .15s,box-shadow .15s,border-color .15s,filter .15s; text-decoration:none; }
.zx-btn:focus-visible { outline:2px solid var(--gold,#0d9488); outline-offset:2px; }
.zx-btn:disabled { opacity:.6; cursor:not-allowed; }
.zx-btn--sm { padding:7px 12px; font-size:.82rem; }
.zx-btn--primary { background:var(--gold,#0d9488); color:#fff; }
.zx-btn--primary:hover:not(:disabled) { filter:brightness(1.06); }
.zx-btn--secondary { background:var(--bg3,#f1f5f9); color:var(--t2,#475569); border-color:var(--border,#e2e8f0); }
.zx-btn--secondary:hover:not(:disabled) { background:var(--border,#e2e8f0); }
.zx-btn--ghost { background:transparent; color:var(--t2,#64748b); }
.zx-btn--ghost:hover:not(:disabled) { background:var(--bg3,#f1f5f9); }
.zx-btn--danger { background:#ef4444; color:#fff; }
.zx-btn--danger:hover:not(:disabled) { background:#dc2626; }
.zx-field--invalid input, .zx-field--invalid select, input.zx-invalid, select.zx-invalid { border-color:#ef4444!important; box-shadow:0 0 0 2px rgba(239,68,68,.15)!important; }
.zx-field-error { display:block; margin-top:5px; color:#dc2626; font-size:.78rem; font-weight:600; }
.zx-field-error::before { content:"\26A0"; margin-right:5px; }
.zx-field-error:empty { display:none; }
.zx-loading { opacity:.6; }
.zx-empty-state { text-align:center; padding:24px 16px; color:var(--t3,#94a3b8); font-size:.88rem; }
.zx-stepind-invalid { box-shadow:inset 0 0 0 1px #ef4444; }
/* enterprise density (this page only) */
.ec-page-title { margin-bottom:6px; }
</style>
<div class="content-wrapper">
<div class="ec-wrap">

  <!-- ── Page Header ─────────────────────────────────────────────────── -->
  <div class="ec-page-title"><i class="fa fa-plus-square-o"></i> <?= !empty($editExam) ? 'Edit Exam' : 'Create Exam' ?></div>
  <ol class="ec-breadcrumb">
    <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
    <li><a href="<?= base_url('exam') ?>">Exams</a></li>
    <li><?= !empty($editExam) ? 'Edit' : 'Create' ?></li>
  </ol>

  <!-- ══ WIZARD STEP INDICATOR (UX-1.2) ══ -->
  <div class="ec-stepind">
    <div class="ec-stepind-item active" data-step="1"><span class="ec-stepind-num">1</span> Details</div>
    <div class="ec-stepind-item" data-step="2"><span class="ec-stepind-num">2</span> Schedule</div>
    <div class="ec-stepind-item" data-step="3"><span class="ec-stepind-num">3</span> Review &amp; Save</div>
  </div>

  <form id="examForm" autocomplete="off" novalidate>
    <input type="hidden" name="<?= $this->security->get_csrf_token_name() ?>"
           value="<?= $this->security->get_csrf_hash() ?>" id="csrfInput">
    <input type="hidden" id="examScheduleInput" name="examSchedule">

    <div class="ec-layout">

      <!-- ══ LEFT PANEL ══════════════════════════════════════════════ -->
      <div class="ec-left">

        <!-- ══ STEP 1 — Exam Details ══ -->
        <div class="ec-step" data-step="1">
        <!-- Card 1 — Exam Identity -->
        <div class="ex-card">
          <div class="ex-card-head"><i class="fa fa-info-circle"></i> Exam Information</div>
          <div class="ex-card-body">
            <div class="ec-grid6">

              <div class="ex-field ec-span2">
                <label for="examName">Exam Name <span class="ex-req">*</span></label>
                <input type="text" id="examName" name="examName"
                       placeholder="e.g. Mid-Term 2026" maxlength="80" required
                       value="<?= !empty($editExam) ? htmlspecialchars($editExam['name']) : '' ?>">
              </div>

              <div class="ex-field">
                <label for="examType">Type <span class="ex-req">*</span></label>
                <select id="examType" name="examType">
                  <?php $etSel = !empty($editExam) ? $editExam['type'] : ''; foreach (['Mid-Term','Final Term','Unit Test','Weekly Test','Pre-Board','Annual'] as $t): ?>
                  <option<?= $etSel === $t ? ' selected' : '' ?>><?= $t ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="ex-field">
                <label for="gradingScale">Grading Scale <span class="ex-req">*</span></label>
                <select id="gradingScale" name="gradingScale">
                  <?php $gsSel = !empty($editExam) ? $editExam['scale'] : ''; foreach (['Percentage','A-F Grades','O-E Grades','10-Point','Pass/Fail'] as $gs): ?>
                  <option value="<?= $gs ?>"<?= $gsSel === $gs ? ' selected' : '' ?>><?= $gs ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="ex-field" id="passingPctField">
                <label for="passingPercent">Passing % <span class="ex-req">*</span></label>
                <input type="number" id="passingPercent" name="passingPercent"
                       value="<?= !empty($editExam) ? (int) $editExam['passingPercent'] : 33 ?>" min="1" max="100">
              </div>

              <div class="ex-field">
                <label for="startDate">Start Date <span class="ex-req">*</span></label>
                <input type="date" id="startDate" name="startDate" required
                       value="<?= !empty($editExam) ? htmlspecialchars($editExam['startDate']) : '' ?>">
              </div>

              <div class="ex-field">
                <label for="endDate">End Date <span class="ex-req">*</span></label>
                <input type="date" id="endDate" name="endDate" required
                       value="<?= !empty($editExam) ? htmlspecialchars($editExam['endDate']) : '' ?>">
              </div>

            </div>

            <!-- Status pills — Phase 3.5: hidden in edit mode (status is not
                 editable here; it is owned exclusively by update_status). -->
            <?php if (empty($editExam)): ?>
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
            <?php endif; ?>
          </div>
        </div>

        </div><!-- /.ec-step (1) -->
        <!-- ══ STEP 2 — Schedule ══ -->
        <div class="ec-step" data-step="2" style="display:none;">
        <!-- Card 2 — Schedule Builder -->
        <div class="ex-card">
          <div class="ex-card-head">
            <i class="fa fa-calendar"></i> Exam Schedule
            <div class="ec-sched-btns">
              <button type="button" class="ec-sched-btn-add zx-btn zx-btn--secondary zx-btn--sm" id="addRowBtn">
                <i class="fa fa-plus"></i> Add Row
              </button>
              <button type="button" class="ec-sched-btn-clear zx-btn zx-btn--ghost zx-btn--sm" id="clearAllBtn">
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

        </div><!-- /.ec-step (2) -->
        <!-- ══ STEP 3 — Instructions + Review + Save ══ -->
        <div class="ec-step" data-step="3" style="display:none;">
        <!-- Card 3 — Instructions -->
        <div class="ex-card">
          <div class="ex-card-head"><i class="fa fa-list-ul"></i> General Instructions</div>
          <div class="ex-card-body">
            <textarea id="generalInstructions" name="generalInstructions"
                      rows="5" placeholder="Enter each instruction on a new line…"><?= !empty($editExam) ? htmlspecialchars($editExam['instructions']) : '' ?></textarea>
          </div>
        </div>

        </div><!-- /.ec-step (3) -->
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
          <!-- Save button moved to the wizard sticky footer (UX-1.2, shown on Step 3) -->
        </div>
      </div><!-- /.ec-right -->

    </div><!-- /.ec-layout -->

    <!-- ══ WIZARD STICKY FOOTER NAV (UX-1.2) ══ -->
    <div class="ec-wizfoot">
      <button type="button" id="ecBackBtn" class="ec-wiz-btn ec-wiz-back zx-btn zx-btn--secondary" style="visibility:hidden;"><i class="fa fa-arrow-left"></i> Back</button>
      <div class="ec-wizfoot-right">
        <button type="button" id="ecNextBtn" class="ec-wiz-btn ec-wiz-next zx-btn zx-btn--primary">Next <i class="fa fa-arrow-right"></i></button>
        <button type="button" id="saveBtn" class="ec-btn-save zx-btn zx-btn--primary" style="display:none;"><i class="fa fa-save"></i> Save Exam</button>
      </div>
    </div>
  </form>

  <!-- Toast container -->
  <div id="exToastWrap" class="ex-toast-wrap"></div>
  <div id="zx-live" class="zx-sr-only" aria-live="polite" aria-atomic="true"></div>

</div><!-- /.ec-wrap -->
</div><!-- /.content-wrapper -->


<script>
(function () {
  'use strict';

  /* ── PHP data ───────────────────────────────────────────────────── */
  var classList    = <?= json_encode(array_values($classNames ?? [])) ?>;
  // Phase 3.5: edit-mode payload (null in create mode → all edit logic skipped).
  var ecEdit       = <?= json_encode($editExam ?? null) ?>;

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
        '<button type="button" class="ex-btn-icon ec-btn-dup zx-btn zx-btn--ghost zx-btn--sm" onclick="ecDupRow(this)" title="Duplicate"><i class="fa fa-copy"></i></button>' +
        '<button type="button" class="ex-btn-icon ex-btn-del zx-btn zx-btn--danger zx-btn--sm" onclick="ecDelRow(this)" title="Remove"><i class="fa fa-trash"></i></button>' +
      '</td></tr>';
  }

  function toggleEmpty() {
    var hasRows = tbody.rows.length > 0;
    schedEmpty.style.display = hasRows ? 'none' : '';
    updateSummary();
  }

  /* ── Exposed to inline handlers ─────────────────────────────────── */
  window.ecUpdateSubjects = function (sel, preselect) {
    var cls     = sel.value;
    var row     = sel.closest('tr');
    var subjSel = row.querySelector('.subj-sel');
    subjSel.innerHTML = '<option value="">— Select Class —</option>';
    subjSel.disabled  = true;
    if (!cls) { updateSummary(); return; }
    subjSel.innerHTML = '<option value="">Loading…</option>';
    subjSel.classList.add('zx-loading'); subjSel.setAttribute('aria-busy', 'true');
    fetch('<?= base_url('exam/get_subjects') ?>?class=' + encodeURIComponent(cls))
      .then(function (r) { return r.json(); })
      .then(function (res) {
        subjSel.classList.remove('zx-loading'); subjSel.removeAttribute('aria-busy');
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
        // Phase 3.5 edit-mode: re-select the saved subject after load.
        if (preselect) {
          var exists = Array.prototype.some.call(subjSel.options, function (o) { return o.value === preselect; });
          if (!exists) { var po = document.createElement('option'); po.value = po.textContent = preselect; subjSel.appendChild(po); subjSel.disabled = false; }
          subjSel.value = preselect;
        }
        updateSummary();
      })
      .catch(function () {
        subjSel.classList.remove('zx-loading'); subjSel.removeAttribute('aria-busy');
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

  /* ── Phase 3.5: edit-mode prefill of schedule rows (skipped in create) ── */
  if (ecEdit) {
    if (saveBtn) saveBtn.innerHTML = '<i class="fa fa-save"></i> Update Exam';
    (ecEdit.rows || []).forEach(function (r) {
      tbody.insertAdjacentHTML('beforeend', makeRow(r.date || ''));
      var row = tbody.lastElementChild;
      if (!row) return;
      var dateEl = row.querySelector('.ec-date-in'); if (dateEl) dateEl.value = r.date || '';
      var stEl   = row.querySelector('.start-time'); if (stEl)   stEl.value   = r.startTime || '';
      var etEl   = row.querySelector('.end-time');   if (etEl)   etEl.value   = r.endTime || '';
      var tmEl   = row.querySelector('.total-marks');if (tmEl)   tmEl.value   = (r.totalMarks   != null ? r.totalMarks   : '');
      var pmEl   = row.querySelector('.pass-marks'); if (pmEl)   pmEl.value   = (r.passingMarks != null ? r.passingMarks : '');
      var clsEl  = row.querySelector('.cls-sel');
      if (clsEl) { clsEl.value = r.className || ''; if (r.className) window.ecUpdateSubjects(clsEl, r.subject || ''); }
    });
    toggleEmpty();
    updateSummary();
  }

  /* ── UX-1.3 inline validation (SINGLE source of truth — used by both
        the wizard Next gating and the Save handler) ───────────────────── */
  function zxFieldError(id, msg) {
    var input = document.getElementById(id); if (!input) return;
    var wrap = input.closest('.ex-field') || input.parentNode;
    if (wrap) wrap.classList.add('zx-field--invalid');
    input.setAttribute('aria-invalid', 'true');
    var errId = id + '-err';
    var err = document.getElementById(errId);
    if (!err) {
      err = document.createElement('span');
      err.id = errId; err.className = 'zx-field-error'; err.setAttribute('role', 'alert');
      (wrap || input.parentNode).appendChild(err);
    }
    err.textContent = msg;
    input.setAttribute('aria-describedby', errId);
  }
  function zxClearField(id) {
    var input = document.getElementById(id); if (!input) return;
    var wrap = input.closest('.ex-field') || input.parentNode;
    if (wrap) wrap.classList.remove('zx-field--invalid');
    input.removeAttribute('aria-invalid');
    var err = document.getElementById(id + '-err'); if (err) err.textContent = '';
  }
  ['examName', 'passingPercent', 'startDate', 'endDate'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function () { zxClearField(id); });
  });
  function zxSchedError(msg) {
    var host = document.getElementById('scheduleCardBody') || (tbody && tbody.closest('.ex-card-body'));
    var err = document.getElementById('schedError');
    if (!err && host) {
      err = document.createElement('div');
      err.id = 'schedError'; err.className = 'zx-field-error'; err.setAttribute('role', 'alert');
      host.appendChild(err);
    }
    if (err) err.textContent = msg || '';
  }
  function zxValidateStep1() {
    var ok = true;
    ['examName', 'passingPercent', 'startDate', 'endDate'].forEach(zxClearField);
    var name = examNameIn.value.trim();
    if (!name) { zxFieldError('examName', 'Exam name is required.'); ok = false; }
    else if (!/^[\w\s\-\.]{2,80}$/.test(name)) { zxFieldError('examName', 'Use letters, digits, spaces, hyphens or dots (2–80).'); ok = false; }
    if (isPassScale()) {
      var pct = parseInt(pctIn.value, 10);
      if (isNaN(pct) || pct < 1 || pct > 100) { zxFieldError('passingPercent', 'Passing % must be between 1 and 100.'); ok = false; }
    }
    if (!startIn.value) { zxFieldError('startDate', 'Start date is required.'); ok = false; }
    if (!endIn.value)   { zxFieldError('endDate', 'End date is required.'); ok = false; }
    if (startIn.value && endIn.value && startIn.value > endIn.value) { zxFieldError('endDate', 'End date must be on or after the start date.'); ok = false; }
    return ok;
  }
  function zxValidateStep2() {
    zxSchedError('');
    document.querySelectorAll('#schedTbody .zx-invalid').forEach(function (el) { el.classList.remove('zx-invalid'); });
    var rows = Array.prototype.slice.call(document.querySelectorAll('#schedTbody .ec-sched-row'));
    if (!rows.length) { zxSchedError('Add at least one subject to the schedule.'); return false; }
    var firstBad = null, bad = 0;
    rows.forEach(function (row) {
      var cells = {
        date:  row.querySelector('.ec-date-in'),
        cls:   row.querySelector('.cls-sel'),
        subj:  row.querySelector('.subj-sel'),
        st:    row.querySelector('.start-time'),
        et:    row.querySelector('.end-time'),
        total: row.querySelector('.total-marks')
      };
      var rowBad = false;
      Object.keys(cells).forEach(function (k) {
        var el = cells[k];
        if (el && !el.value) { el.classList.add('zx-invalid'); rowBad = true; if (!firstBad) firstBad = el; }
      });
      if (isPassScale()) {
        var p = row.querySelector('.pass-marks');
        if (p && p.value === '') { p.classList.add('zx-invalid'); rowBad = true; if (!firstBad) firstBad = p; }
      }
      if (cells.st && cells.et && cells.st.value && cells.et.value && cells.et.value <= cells.st.value) {
        cells.et.classList.add('zx-invalid'); rowBad = true; if (!firstBad) firstBad = cells.et;
      }
      if (rowBad) bad++;
    });
    if (bad) {
      zxSchedError(bad + (bad > 1 ? ' schedule rows have' : ' schedule row has') + ' missing or invalid values (highlighted in red).');
      if (firstBad) { try { firstBad.focus(); } catch (e) {} }
      return false;
    }
    return true;
  }
  function zxValidateStep(n) { return n === 1 ? zxValidateStep1() : n === 2 ? zxValidateStep2() : true; }
  window.zxExam = { validateStep: zxValidateStep };

  // Clear a schedule cell's red state the moment the user edits it (don't wait
  // for the next validate). Delegated so it also covers dynamically-added rows.
  if (tbody) {
    var zxCellClear = function (e) {
      var t = e.target;
      if (t && t.classList && t.classList.contains('zx-invalid')) {
        t.classList.remove('zx-invalid');
        if (!document.querySelector('#schedTbody .zx-invalid')) zxSchedError('');
      }
    };
    tbody.addEventListener('input', zxCellClear);
    tbody.addEventListener('change', zxCellClear);
  }

  /* ── Save ───────────────────────────────────────────────────────── */
  saveBtn.addEventListener('click', function () {
    // UX-1.3: single source of truth — same validators as the wizard Next
    // gating. On failure, jump to the offending step (errors render inline).
    if (!window.zxExam.validateStep(1)) { if (window.zxWizard) window.zxWizard.goTo(1); return; }
    if (!window.zxExam.validateStep(2)) { if (window.zxWizard) window.zxWizard.goTo(2); return; }

    // Serializer UNCHANGED — build the identical examSchedule payload.
    var rows = Array.from(document.querySelectorAll('#schedTbody .ec-sched-row'));
    var scheduleData = [];
    rows.forEach(function (row) {
      var date  = row.querySelector('.ec-date-in').value;
      var cls   = row.querySelector('.cls-sel').value;
      var subj  = row.querySelector('.subj-sel').value;
      var st    = row.querySelector('.start-time').value;
      var et    = row.querySelector('.end-time').value;
      var total = row.querySelector('.total-marks').value;
      var pass  = isPassScale() ? row.querySelector('.pass-marks').value : '0';
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

    document.getElementById('examScheduleInput').value = JSON.stringify(scheduleData);

    var csrfName  = '<?= $this->security->get_csrf_token_name() ?>';
    var fd = new FormData(document.getElementById('examForm'));

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    // Phase 3.5: edit mode posts to edit_exam/{id}; create mode unchanged.
    var ecSaveUrl = ecEdit
      ? '<?= base_url('exam/edit_exam/') ?>' + encodeURIComponent(ecEdit.id)
      : '<?= base_url('exam/create') ?>';

    fetch(ecSaveUrl, { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.status === 'success') {
          showToast(res.message || (ecEdit ? 'Exam updated!' : 'Exam created!'), 'success');
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
    var live = document.getElementById('zx-live'); if (live) live.textContent = msg;
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

<!-- ══ UX-1.2 Wizard step navigation (standalone; presentation only — no contract/serializer/IIFE changes) ══ -->
<script>
(function () {
  'use strict';
  var steps   = document.querySelectorAll('.ec-step');
  var total   = steps.length || 3;
  var cur     = 1;
  var backBtn = document.getElementById('ecBackBtn');
  var nextBtn = document.getElementById('ecNextBtn');
  var saveBtn = document.getElementById('saveBtn');
  var inds    = document.querySelectorAll('.ec-stepind-item');

  // a11y: label each step group once.
  steps.forEach(function (el) {
    el.setAttribute('role', 'group');
    el.setAttribute('aria-label', 'Step ' + el.getAttribute('data-step') + ' of ' + total);
  });

  function focusFirst() {
    var panel = document.querySelector('.ec-step[data-step="' + cur + '"]');
    if (!panel) return;
    var f = panel.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea');
    if (f) { try { f.focus(); } catch (e) {} }
  }

  function show(n) {
    cur = Math.max(1, Math.min(total, n));
    steps.forEach(function (el) { el.style.display = (parseInt(el.getAttribute('data-step'), 10) === cur) ? '' : 'none'; });
    inds.forEach(function (el) {
      var s = parseInt(el.getAttribute('data-step'), 10);
      el.classList.toggle('active', s === cur);
      el.classList.toggle('done', s < cur);
      if (s === cur) el.setAttribute('aria-current', 'step'); else el.removeAttribute('aria-current');
    });
    if (backBtn) backBtn.style.visibility = (cur === 1) ? 'hidden' : 'visible';
    if (nextBtn) nextBtn.style.display = (cur === total) ? 'none' : '';
    if (saveBtn) saveBtn.style.display = (cur === total) ? '' : 'none';
    try { window.scrollTo({ top: 0, behavior: 'smooth' }); } catch (e) {}
    focusFirst();
  }

  // Step gating: validate the current step before advancing (shared validators).
  function tryNext() {
    if (window.zxExam && typeof window.zxExam.validateStep === 'function' && !window.zxExam.validateStep(cur)) {
      var item = document.querySelector('.ec-stepind-item[data-step="' + cur + '"]');
      if (item) { item.classList.add('zx-stepind-invalid'); setTimeout(function () { item.classList.remove('zx-stepind-invalid'); }, 1600); }
      return;
    }
    show(cur + 1);
  }

  if (nextBtn) nextBtn.addEventListener('click', tryNext);
  if (backBtn) backBtn.addEventListener('click', function () { show(cur - 1); });
  inds.forEach(function (el) {
    el.addEventListener('click', function () {
      var target = parseInt(el.getAttribute('data-step'), 10);
      if (target > cur && window.zxExam) {
        for (var s = cur; s < target; s++) { if (!window.zxExam.validateStep(s)) { show(s); return; } }
      }
      show(target);
    });
  });

  // Enter-to-advance (never from a textarea/button; form has no submit button).
  var form = document.getElementById('examForm');
  if (form) form.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && cur < total) {
      var t = e.target;
      if (t && t.tagName !== 'TEXTAREA' && t.tagName !== 'BUTTON') { e.preventDefault(); tryNext(); }
    }
  });

  // Expose for the Save handler to jump to a failing step (single nav source).
  window.zxWizard = { goTo: show };

  show(1);
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
