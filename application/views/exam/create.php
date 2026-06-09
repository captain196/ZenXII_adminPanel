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

            <!-- UX-2.0.1-B P1 — class scope (feature-flagged; drives the datesheet builder) -->
            <div class="zxb-scope" id="zxb-scope" style="display:none;">
              <span class="ec-status-label">Classes in scope:</span>
              <div class="zxb-scope-chips" id="zxb-scope-chips"></div>
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
        <!-- Card 2 — Schedule Builder (LEGACY spreadsheet; hidden when datesheet builder is active) -->
        <div class="ex-card" id="ec-legacy-schedule">
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

        <!-- UX-2.0.1-B P1 — Datesheet Builder (feature-flagged; shown only when ZXB_ON) -->
        <div class="ex-card zxb-card" id="zxb-builder" style="display:none;">
          <div class="ex-card-head"><i class="fa fa-calendar-check-o"></i> Exam Datesheet
            <span class="zxb-head-hint">subject-first · applies to all sections of each class</span>
          </div>
          <div class="ex-card-body">
            <div class="zxb-bulkbar">
              <span class="zxb-bulk-label"><i class="fa fa-sliders"></i> Bulk defaults</span>
              <label>Total <input type="number" id="zxb-def-total" class="zxb-mini" min="1" max="9999" value="100"></label>
              <label>Passing <input type="number" id="zxb-def-pass" class="zxb-mini" min="0" max="9999" value="33"></label>
              <label>Duration <input type="number" id="zxb-def-dur" class="zxb-mini" min="0" max="600" value="120">m</label>
              <button type="button" class="zx-btn zx-btn--secondary zx-btn--sm" id="zxb-apply-all">Apply to all</button>
            </div>
            <div class="zxb-rows" id="zxb-rows"></div>
            <div class="zxb-foot" id="zxb-foot"></div>
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
  // UX-1.4.1: in-page subject + section maps (replaces the per-row
  // get_subjects AJAX). subjectMap[class] = [subject,…]; sectionMap[class]
  // = [sectionLetter,…] (read-only, from the controller's $structure).
  var subjectMap   = <?= json_encode($subjects ?? []) ?>;
  var sectionMap   = <?= json_encode($sections ?? []) ?>;
  var cbSeq        = 0; // unique-id source for zx-combobox instances

  /* ===== UX-2.0.1-B P1 — Datesheet Builder state (feature-flagged) =====
     ZXB_ON gates the whole new UX. When OFF the legacy spreadsheet path is
     used verbatim (full backward compatibility). Toggle via ?ux=datesheet or
     localStorage 'zxb'='1'. Declared EARLY so edit-prefill + validateStep see it. */
  var ZXB_ON = (function () {
    try {
      if (/[?&]ux=datesheet\b/.test(location.search)) return true;
      if (/[?&]ux=legacy\b/.test(location.search))    return false;
      return localStorage.getItem('zxb') === '1';
    } catch (e) { return false; }
  })();
  var zxbModel    = { scope: { classes: [] },
                      defaults: { total: 100, passing: 33, durationMins: 120 },
                      calendar: { holidays: [], weekend: [0] },   // reserved hook (P6-B); unused in P1
                      subjects: {} };
  var zxbExpanded = {}; // subjects whose per-class override panel is open

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
    // UX-2.0.1-B: re-render the datesheet so the Passing column shows/hides too.
    if (typeof ZXB_ON !== 'undefined' && ZXB_ON && typeof zxbRender === 'function') zxbRender();
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
    var cbId    = 'zxcb' + (++cbSeq);

    var classOpts = '<option value="">— Class —</option>';
    classList.forEach(function (c) {
      classOpts += '<option value="' + esc(c) + '">' + esc(c) + '</option>';
    });

    // UX-1.4.2: data-label attributes drive the mobile card layout (CSS ::before).
    // Additive only — cell classes + .value selectors are unchanged, so the
    // serializer/validator/combobox contracts are untouched.
    return '<tr class="ec-sched-row">' +
      '<td data-label="Date"><input type="date" class="ex-time ec-date-in"' +
           (dval    ? ' value="' + esc(dval) + '"' : '') +
           (dateMin ? ' min="' + esc(dateMin) + '"' : '') +
           (dateMax ? ' max="' + esc(dateMax) + '"' : '') + '></td>' +
      '<td class="ec-cls-cell" data-label="Class"><select class="ex-sel cls-sel" onchange="ecUpdateSubjects(this)">' + classOpts + '</select>' +
        '<div class="ec-fanout" style="display:none;"></div></td>' +
      '<td data-label="Subject">' +
        '<div class="zx-combobox" data-cb>' +
          '<input type="text" class="zx-cb-input" id="' + cbId + '-in" role="combobox"' +
                ' aria-expanded="false" aria-autocomplete="list" aria-controls="' + cbId + '-list"' +
                ' autocomplete="off" placeholder="— Select class first —" disabled>' +
          '<ul class="zx-cb-list" id="' + cbId + '-list" role="listbox" aria-label="Subjects" hidden></ul>' +
          '<select class="subj-sel zx-sr-only" tabindex="-1" aria-hidden="true"><option value="">— Select Class —</option></select>' +
        '</div></td>' +
      '<td data-label="Start"><input type="time" class="ex-time start-time"></td>' +
      '<td data-label="End"><input type="time" class="ex-time end-time"></td>' +
      '<td data-label="Total Marks"><input type="number" class="ex-marks total-marks" value="100" min="1" max="9999" oninput="ecAutoPassMks(this)"></td>' +
      '<td data-label="Passing Marks"><input type="number" class="ex-marks pass-marks" value="' + Math.round(100 * parseInt(pctIn.value||33) / 100) + '" min="0" max="9999"></td>' +
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
    var cbInput = row.querySelector('.zx-cb-input');

    // UX-1.4.1: source subjects from the in-page map (no get_subjects AJAX).
    // The hidden <select class="subj-sel"> stays the canonical value model —
    // serializer + validator read its .value, so this rebuilds it exactly as
    // before, just from a synchronous source.
    var subs = (cls && subjectMap[cls]) ? subjectMap[cls] : [];

    subjSel.innerHTML = '';
    var ph = document.createElement('option');
    ph.value = '';
    ph.textContent = cls ? '— Select Subject —' : '— Select Class —';
    subjSel.appendChild(ph);
    subs.forEach(function (s) {
      var o = document.createElement('option');
      o.value = o.textContent = s;
      subjSel.appendChild(o);
    });

    // Edit-mode (Phase 3.5): re-select the saved subject; if it's no longer in
    // the class's subject list, keep it as an explicit option so the saved
    // value round-trips unchanged.
    if (preselect) {
      if (subs.indexOf(preselect) === -1) {
        var po = document.createElement('option');
        po.value = po.textContent = preselect;
        subjSel.appendChild(po);
      }
      subjSel.value = preselect;
    } else {
      subjSel.value = '';
    }

    // Sync the visible combobox view to the hidden select.
    zxComboSync(row);
    if (cbInput) {
      cbInput.disabled = !cls;
      cbInput.placeholder = !cls
        ? '— Select class first —'
        : ((subs.length || preselect) ? 'Search subject…' : 'No subjects for this class');
    }

    zxFanoutSync(row, cls);
    updateSummary();
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

    // Count unique classes and total entries.
    // UX-2.0.1-B: when the datesheet builder is active, count from its model.
    if (typeof ZXB_ON !== 'undefined' && ZXB_ON) {
      sumClasses.textContent = zxbModel.scope.classes.length;
      sumEntries.textContent = (typeof zxbSerialize === 'function') ? zxbSerialize().length : 0;
      return;
    }
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

  /* ── Phase 3.5: edit-mode prefill of schedule rows (skipped in create) ──
     UX-2.0.1-B: skipped entirely when the datesheet builder is active — the
     builder hydrates its own model from ecEdit.rows instead (see zxbInit). */
  if (ecEdit && !ZXB_ON) {
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
  // UX-2.0.1-B: Step-1 validator is shared; Step-2 routes to the builder validator when active.
  window.zxExam = { validateStep: function (n) {
    if (n === 1) return zxValidateStep1();
    if (n === 2) return ZXB_ON ? zxbValidateStep2() : zxValidateStep2();
    return true;
  } };

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

  /* ── UX-1.4.1: zx-combobox searchable subject picker ───────────────
     Presentation layer ONLY. The hidden <select class="subj-sel"> remains the
     canonical value model (serializer L~640 + validator L~579 read its .value).
     This builds a searchable input + floating listbox synced to that select;
     committing a choice writes select.value and fires a bubbling `change` so
     the existing zxCellClear / summary logic runs unchanged. (function
     declarations are hoisted, so ecUpdateSubjects above can call these.) */
  function zxComboSync(row) {
    var sel   = row.querySelector('.subj-sel');
    var input = row.querySelector('.zx-cb-input');
    var list  = row.querySelector('.zx-cb-list');
    if (!sel || !input || !list) return;
    input.value = sel.value || '';
    list.innerHTML = '';
    Array.prototype.forEach.call(sel.options, function (o, i) {
      if (o.value === '') return;
      var li = document.createElement('li');
      li.className = 'zx-cb-opt';
      li.setAttribute('role', 'option');
      li.id = list.id + '-opt' + i;
      li.dataset.value = o.value;
      li.textContent = o.value;
      if (o.value === sel.value) li.setAttribute('aria-selected', 'true');
      list.appendChild(li);
    });
  }

  // UX-1.4.3 — fan-out visibility: clearly convey WHICH class, HOW MANY sections,
  // and WHICH sections will receive the exam. Presentation only (no contract impact).
  function zxFanoutSync(row, cls) {
    var host = row.querySelector('.ec-fanout');
    if (!host) return;
    var secs = (cls && sectionMap[cls] && sectionMap[cls].length) ? sectionMap[cls] : null;
    if (!cls) { host.style.display = 'none'; host.className = 'ec-fanout'; host.innerHTML = ''; return; }
    host.style.display = '';
    if (!secs) {
      host.className = 'ec-fanout ec-fanout--warn';
      host.setAttribute('role', 'note');
      host.innerHTML = '<i class="fa fa-exclamation-triangle"></i> '
        + '<span>No sections configured for <strong>' + esc(cls) + '</strong> — it will not receive this exam.</span>';
      return;
    }
    host.className = 'ec-fanout';
    host.setAttribute('role', 'note');
    var n = secs.length;
    var chips = secs.map(function (s) {
      return '<span class="ec-fanout-chip">' + esc(String(s)) + '</span>';
    }).join('');
    // Screen-reader friendly sentence + visual chips.
    host.setAttribute('aria-label',
      esc(cls) + ' — applies to ' + n + ' section' + (n > 1 ? 's' : '') + ': ' + secs.join(', '));
    host.innerHTML =
      '<span class="ec-fanout-lead"><i class="fa fa-sitemap" aria-hidden="true"></i> '
        + 'Applies to <strong>' + n + '</strong> section' + (n > 1 ? 's' : '') + '</span>'
      + '<span class="ec-fanout-chips" aria-hidden="true">' + chips + '</span>';
  }

  (function zxComboController() {
    if (!tbody) return;
    var openInput = null, openList = null, activeIdx = -1;

    function visibleOpts(list) {
      return Array.prototype.filter.call(list.children, function (li) {
        return li.classList.contains('zx-cb-opt') && !li.classList.contains('zx-cb-hidden');
      });
    }
    function position(input, list) {
      var r = input.getBoundingClientRect();
      list.style.left  = r.left + 'px';
      list.style.width = r.width + 'px';
      var below = window.innerHeight - r.bottom;
      if (below < 240 && r.top > below) { list.style.top = 'auto'; list.style.bottom = (window.innerHeight - r.top + 2) + 'px'; }
      else { list.style.bottom = 'auto'; list.style.top = (r.bottom + 2) + 'px'; }
    }
    function setActive(list, idx) {
      var opts = visibleOpts(list);
      opts.forEach(function (o) { o.classList.remove('zx-cb-active'); });
      activeIdx = idx;
      if (idx >= 0 && idx < opts.length) {
        opts[idx].classList.add('zx-cb-active');
        try { opts[idx].scrollIntoView({ block: 'nearest' }); } catch (e) {}
        if (openInput) openInput.setAttribute('aria-activedescendant', opts[idx].id);
      } else if (openInput) {
        openInput.removeAttribute('aria-activedescendant');
      }
    }
    function filter(input, list) {
      var q = (input.value || '').trim().toLowerCase();
      var any = false;
      Array.prototype.forEach.call(list.children, function (li) {
        if (!li.classList.contains('zx-cb-opt')) return;
        var match = !q || li.dataset.value.toLowerCase().indexOf(q) !== -1;
        li.classList.toggle('zx-cb-hidden', !match);
        if (match) any = true;
      });
      var empty = list.querySelector('.zx-cb-empty');
      if (!any) {
        if (!empty) { empty = document.createElement('li'); empty.className = 'zx-cb-empty'; empty.textContent = 'No match'; list.appendChild(empty); }
        empty.style.display = '';
      } else if (empty) { empty.style.display = 'none'; }
      setActive(list, any ? 0 : -1);
    }
    function open(input) {
      var combo = input.closest('.zx-combobox'); if (!combo) return;
      var list = combo.querySelector('.zx-cb-list'); if (!list) return;
      close(); // close any other open instance
      openInput = input; openList = list;
      input.value = ''; // blank for immediate typing; committed value restored on close
      Array.prototype.forEach.call(list.children, function (li) { li.classList.remove('zx-cb-hidden'); });
      var empty = list.querySelector('.zx-cb-empty'); if (empty) empty.style.display = 'none';
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      position(input, list);
      setActive(list, 0);
    }
    function close() {
      if (!openInput || !openList) return;
      var input = openInput, sel = input.closest('.zx-combobox').querySelector('.subj-sel');
      openList.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      input.value = sel ? (sel.value || '') : ''; // restore canonical value
      openInput = null; openList = null; activeIdx = -1;
    }
    function commit(input, value) {
      var sel = input.closest('.zx-combobox').querySelector('.subj-sel');
      if (sel) {
        var has = Array.prototype.some.call(sel.options, function (o) { return o.value === value; });
        if (!has) { var o = document.createElement('option'); o.value = o.textContent = value; sel.appendChild(o); }
        sel.value = value;
        sel.dispatchEvent(new Event('change', { bubbles: true })); // fires zxCellClear + summary
      }
      close();
      input.value = value;
    }

    tbody.addEventListener('focusin', function (e) {
      var input = e.target.closest ? e.target.closest('.zx-cb-input') : null;
      // Guard openInput!==input so a mousedown that already opened it does not
      // immediately re-open (which would flicker the list).
      if (input && !input.disabled && openInput !== input) open(input);
    });
    tbody.addEventListener('input', function (e) {
      var input = e.target.closest ? e.target.closest('.zx-cb-input') : null;
      if (input && openInput === input) filter(input, openList);
    });
    tbody.addEventListener('mousedown', function (e) {
      var opt = e.target.closest ? e.target.closest('.zx-cb-opt') : null;
      if (opt && openInput) { e.preventDefault(); commit(openInput, opt.dataset.value); return; }
      // BUGFIX (UX-1.4.3): open on click even when the input already has focus.
      // After a selection, commit()→close() leaves focus on the input, so a
      // second click fired no `focusin` and the list never reopened (the user
      // had to click outside the row first). Opening on mousedown fixes that.
      // No preventDefault here — the input must still receive focus/caret.
      var input = e.target.closest ? e.target.closest('.zx-cb-input') : null;
      if (input && !input.disabled && openInput !== input) open(input);
    });
    tbody.addEventListener('keydown', function (e) {
      var input = e.target.closest ? e.target.closest('.zx-cb-input') : null;
      if (!input) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault(); e.stopPropagation();
        if (openInput !== input) { open(input); return; }
        setActive(openList, Math.min(activeIdx + 1, visibleOpts(openList).length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault(); e.stopPropagation();
        if (openInput === input) setActive(openList, Math.max(activeIdx - 1, 0));
      } else if (e.key === 'Enter') {
        e.preventDefault(); e.stopPropagation(); // never let it advance the wizard
        if (openInput === input) {
          var opts = visibleOpts(openList);
          if (activeIdx >= 0 && opts[activeIdx]) commit(input, opts[activeIdx].dataset.value);
        } else { open(input); }
      } else if (e.key === 'Escape') {
        if (openInput === input) { e.stopPropagation(); close(); }
      } else if (e.key === 'Tab') {
        if (openInput === input) close();
      }
    });
    document.addEventListener('mousedown', function (e) {
      if (openInput && !(e.target.closest && e.target.closest('.zx-combobox'))) close();
    });
    window.addEventListener('scroll', function () { if (openInput) close(); }, true);
    window.addEventListener('resize', function () { if (openInput) close(); });
  })();

  /* ═══ UX-2.0.1-B P1 — Datesheet Builder module (feature-flagged) ═══════════
     Subject-first builder over the SAME backend contract. zxbSerialize() emits
     row objects byte-identical to the legacy spreadsheet serializer; one
     scheduled (subject × applied class) → one row. No backend/serializer change. */
  function zxbClassHas(cls, subject){ var l = subjectMap[cls] || []; return l.indexOf(subject) !== -1; }
  function zxbSubjectUnion(){ var seen={}, out=[]; zxbModel.scope.classes.forEach(function(c){ (subjectMap[c]||[]).forEach(function(s){ if(!seen[s]){seen[s]=1; out.push(s);} }); }); return out; }
  function zxbOrderedSubjects(){ var u=zxbSubjectUnion(), seen={}; u.forEach(function(s){seen[s]=1;}); var extra=Object.keys(zxbModel.subjects).filter(function(s){return !seen[s];}); return u.concat(extra); }
  function zxbSubjectAt(i){ return zxbOrderedSubjects()[i]; }
  function zxbAppliesDefault(s){ return zxbModel.scope.classes.filter(function(c){ return zxbClassHas(c,s); }); }
  function zxbEnsureSubjects(){
    var union = zxbSubjectUnion();
    union.forEach(function(s){
      var S=zxbModel.subjects[s];
      if(!S){ zxbModel.subjects[s]={ appliesTo:zxbAppliesDefault(s), base:{date:'',start:'',end:'',total:zxbModel.defaults.total,passing:zxbModel.defaults.passing}, overrides:{} }; }
      else {
        S.appliesTo=(S.appliesTo||[]).filter(function(c){ return zxbModel.scope.classes.indexOf(c)!==-1; }); // scope-only prune (keeps hydrated subjects)
        if(!S.appliesTo.length) S.appliesTo=zxbAppliesDefault(s);
        Object.keys(S.overrides||{}).forEach(function(c){ if(S.appliesTo.indexOf(c)===-1) delete S.overrides[c]; });
      }
    });
    Object.keys(zxbModel.subjects).forEach(function(s){ if(union.indexOf(s)===-1 && !zxbModel.subjects[s]._pinned) delete zxbModel.subjects[s]; });
    return union;
  }
  function zxbEff(S, cls){ var b=S.base||{}, o=(S.overrides&&S.overrides[cls])||{};
    function pick(k){ return (o[k]!=null && o[k]!=='') ? o[k] : b[k]; }
    return { date:pick('date'), start:pick('start'), end:pick('end'), total:pick('total'), passing:pick('passing') }; }
  // ── date mutator abstraction (P6-B auto-sequence/shift will reuse this) ──
  function zxbSetField(subject, field, value, cls){ var S=zxbModel.subjects[subject]; if(!S) return;
    if(cls){ S.overrides=S.overrides||{}; S.overrides[cls]=S.overrides[cls]||{}; S.overrides[cls][field]=value; }
    else { S.base=S.base||{}; S.base[field]=value; } }
  function zxbSetDate(subject, date, cls){ zxbSetField(subject,'date',date,cls); }
  function zxbAddMins(hhmm,mins){ var p=String(hhmm).split(':'); if(p.length<2) return hhmm; var t=parseInt(p[0])*60+parseInt(p[1])+mins; t=((t%1440)+1440)%1440; var h=Math.floor(t/60),m=t%60; return (h<10?'0':'')+h+':'+(m<10?'0':'')+m; }
  function zxbIsWorkingDay(d){ return true; } // reserved hook (P6-B consults zxbModel.calendar / Firestore calendarEvents)

  function zxbSerialize(){
    var out=[]; var subs=zxbOrderedSubjects();
    zxbModel.scope.classes.forEach(function(cls){
      subs.forEach(function(s){
        var S=zxbModel.subjects[s]; if(!S || S.appliesTo.indexOf(cls)===-1) return;
        var e=zxbEff(S,cls); if(!e.date) return;                 // unscheduled → skip
        var p=String(e.date).split('-');
        out.push({ date:p[2]+'/'+p[1]+'/'+p[0], className:cls, subject:s,
          startTime:e.start, endTime:e.end,
          totalMarks:parseInt(e.total), passingMarks:parseInt(isPassScale()?(e.passing||'0'):'0') });
      });
    });
    return out;
  }
  function zxbHydrate(rows){ rows=rows||[]; var classes=[];
    rows.forEach(function(r){ var cls=r.className, s=r.subject; if(classes.indexOf(cls)===-1) classes.push(cls);
      if(!zxbModel.subjects[s]) zxbModel.subjects[s]={appliesTo:[],base:null,overrides:{},_pinned:true};
      var S=zxbModel.subjects[s]; S._pinned=true; if(S.appliesTo.indexOf(cls)===-1) S.appliesTo.push(cls);
      var slot={date:r.date||'',start:r.startTime||'',end:r.endTime||'',total:(r.totalMarks!=null?r.totalMarks:zxbModel.defaults.total),passing:(r.passingMarks!=null?r.passingMarks:zxbModel.defaults.passing)};
      if(!S.base){ S.base=slot; }
      else if(slot.date!==S.base.date||slot.start!==S.base.start||slot.end!==S.base.end||String(slot.total)!==String(S.base.total)||String(slot.passing)!==String(S.base.passing)){ S.overrides[cls]=slot; }
    });
    zxbModel.scope.classes=classes; zxbEnsureSubjects();
  }
  function zxbValidateStep2(){
    zxbClearErrors(); var ok=true, firstBad=null, any=false, pass=isPassScale();
    zxbOrderedSubjects().forEach(function(s){ var S=zxbModel.subjects[s]; if(!S) return;
      S.appliesTo.forEach(function(cls){ var e=zxbEff(S,cls); if(!e.date) return; any=true; var bad=false;
        if(!e.start||!e.end||e.end<=e.start) bad=true;
        if(!(parseInt(e.total)>=1)) bad=true;
        if(pass && (e.passing===''||e.passing==null)) bad=true;
        if(startIn.value && e.date < startIn.value) bad=true;
        if(endIn.value && e.date > endIn.value) bad=true;
        if(bad){ ok=false; zxbMarkBad(s); if(!firstBad) firstBad=s; }
      });
    });
    if(!any){ zxbFootError('Schedule at least one subject (set a date).'); return false; }
    if(!ok){ zxbFootError('Some scheduled subjects have missing/invalid date, time or marks (highlighted).'); if(firstBad) zxbFocus(firstBad); }
    return ok;
  }
  function zxbCss(s){ return String(s).replace(/"/g,'\\"'); }
  function zxbClearErrors(){ var h=document.getElementById('zxb-rows'); if(h) Array.prototype.forEach.call(h.querySelectorAll('.zxb-row.bad'),function(r){r.classList.remove('bad');}); }
  function zxbMarkBad(s){ var h=document.getElementById('zxb-rows'); if(!h) return; var r=h.querySelector('.zxb-row[data-subject="'+zxbCss(s)+'"]'); if(r) r.classList.add('bad'); }
  function zxbFootError(msg){ var f=document.getElementById('zxb-foot'); if(f){ f.classList.add('zxb-foot-err'); f.innerHTML='<span class="zxb-warn">'+esc(msg)+'</span>'; } }
  function zxbFocus(s){ var h=document.getElementById('zxb-rows'); if(!h) return; var r=h.querySelector('.zxb-row[data-subject="'+zxbCss(s)+'"]'); if(r){ var d=r.querySelector('.zxb-date'); if(d){ try{d.focus();}catch(e){} } } }

  function zxbRowHtml(s,i){
    var S=zxbModel.subjects[s], b=S.base||{}; var scheduled=!!b.date || Object.keys(S.overrides||{}).length>0;
    var eligible=zxbModel.scope.classes.filter(function(c){ return zxbClassHas(c,s) || S.appliesTo.indexOf(c)!==-1; });
    var chips=eligible.map(function(c){ var on=S.appliesTo.indexOf(c)!==-1; return '<button type="button" class="zxb-applychip'+(on?' on':'')+'" data-apply="'+i+'" data-cls="'+esc(c)+'" aria-pressed="'+on+'">'+esc(c)+'</button>'; }).join('');
    var ovCount=Object.keys(S.overrides||{}).length, expanded=!!zxbExpanded[s];
    var html='<div class="zxb-row '+(scheduled?'is-sched':'is-unsched')+'" data-si="'+i+'" data-subject="'+esc(s)+'" role="group" aria-label="'+esc(s)+' schedule">'
      +'<div class="zxb-row-main">'
        +'<span class="zxb-status" aria-hidden="true">'+(scheduled?'✓':'·')+'</span>'
        +'<span class="zxb-subj">'+esc(s)+'</span>'
        +'<input type="date" class="zxb-in zxb-date" data-si="'+i+'" data-f="date" aria-label="Date" value="'+esc(b.date||'')+'"'+(startIn.value?(' min="'+esc(startIn.value)+'"'):'')+(endIn.value?(' max="'+esc(endIn.value)+'"'):'')+'>'
        +'<input type="time" class="zxb-in zxb-start" data-si="'+i+'" data-f="start" aria-label="Start" value="'+esc(b.start||'')+'">'
        +'<span class="zxb-dash">–</span>'
        +'<input type="time" class="zxb-in zxb-end" data-si="'+i+'" data-f="end" aria-label="End" value="'+esc(b.end||'')+'">'
        +'<input type="number" class="zxb-in zxb-total" data-si="'+i+'" data-f="total" aria-label="Total marks" min="1" max="9999" value="'+esc(b.total!=null?b.total:'')+'">'
        +'<input type="number" class="zxb-in zxb-pass" data-si="'+i+'" data-f="passing" aria-label="Passing marks" min="0" max="9999" value="'+esc(b.passing!=null?b.passing:'')+'">'
        +'<button type="button" class="zxb-clear" data-clear="'+i+'" title="Clear slot" aria-label="Clear '+esc(s)+'">✕</button>'
      +'</div>'
      +'<div class="zxb-row-apply"><span class="zxb-apply-label">Applies to</span>'+chips
        +'<button type="button" class="zxb-ovtoggle'+(ovCount?' has':'')+'" data-expand="'+i+'" aria-expanded="'+expanded+'">'+(expanded?'▾':'▸')+' per-class'+(ovCount?(' ('+ovCount+')'):'')+'</button>'
      +'</div>';
    if(expanded){ html+='<div class="zxb-ovpanel">'+S.appliesTo.map(function(c){ var e=zxbEff(S,c), isov=!!(S.overrides&&S.overrides[c]);
      return '<div class="zxb-ovrow"><span class="zxb-ovcls">'+esc(c)+'</span>'
        +'<input type="date" class="zxb-in" data-si="'+i+'" data-f="date" data-cls="'+esc(c)+'" aria-label="'+esc(c)+' date" value="'+esc(e.date||'')+'">'
        +'<input type="time" class="zxb-in" data-si="'+i+'" data-f="start" data-cls="'+esc(c)+'" aria-label="'+esc(c)+' start" value="'+esc(e.start||'')+'">'
        +'<input type="time" class="zxb-in" data-si="'+i+'" data-f="end" data-cls="'+esc(c)+'" aria-label="'+esc(c)+' end" value="'+esc(e.end||'')+'">'
        +(isov?'<button type="button" class="zxb-reset" data-reset="'+i+'" data-cls="'+esc(c)+'">reset</button>':'')+'</div>'; }).join('')+'</div>'; }
    return html+'</div>';
  }
  function zxbUpdateFoot(){ var foot=document.getElementById('zxb-foot'); if(!foot) return; foot.classList.remove('zxb-foot-err');
    var ser=zxbSerialize(), subjSet={}; ser.forEach(function(r){subjSet[r.subject]=1;});
    var union=zxbOrderedSubjects().length, schedSubj=Object.keys(subjSet).length, unsched=union-schedSubj;
    foot.innerHTML='Scheduled <strong>'+schedSubj+'</strong> subject(s) · <strong>'+ser.length+'</strong> entries'+(unsched>0?(' · <span class="zxb-warn">'+unsched+' unscheduled (skipped)</span>'):''); }
  function zxbRender(){
    if(!ZXB_ON) return;
    var sc=document.getElementById('zxb-scope-chips');
    if(sc){ sc.innerHTML=classList.map(function(c){ var on=zxbModel.scope.classes.indexOf(c)!==-1; return '<button type="button" class="zxb-chip'+(on?' on':'')+'" data-scope="'+esc(c)+'" aria-pressed="'+on+'">'+(on?'✓ ':'')+esc(c)+'</button>'; }).join(''); }
    zxbEnsureSubjects(); var host=document.getElementById('zxb-rows'); if(!host) return;
    if(zxbModel.scope.classes.length===0){ host.className='zxb-rows'; host.innerHTML='<div class="zxb-empty">Select one or more classes above to begin scheduling.</div>'; zxbUpdateFoot(); updateSummary(); return; }
    host.className='zxb-rows'+(isPassScale()?'':' zxb-nopass');
    var subs=zxbOrderedSubjects(); host.innerHTML=subs.map(function(s,i){ return zxbRowHtml(s,i); }).join('');
    zxbUpdateFoot(); updateSummary();
  }
  function zxbApplyDefaults(){ var dur=zxbModel.defaults.durationMins;
    zxbOrderedSubjects().forEach(function(s){ var S=zxbModel.subjects[s]; if(!S||!S.base) return; S.base.total=zxbModel.defaults.total; S.base.passing=zxbModel.defaults.passing; if(S.base.start && dur>0) S.base.end=zxbAddMins(S.base.start,dur); }); }
  function zxbWire(){
    var scope=document.getElementById('zxb-scope-chips');
    if(scope) scope.addEventListener('click',function(e){ var b=e.target.closest&&e.target.closest('[data-scope]'); if(!b) return; var c=b.getAttribute('data-scope'); var i=zxbModel.scope.classes.indexOf(c); if(i===-1) zxbModel.scope.classes.push(c); else zxbModel.scope.classes.splice(i,1); zxbRender(); });
    var root=document.getElementById('zxb-builder'); if(!root) return;
    root.addEventListener('change',function(e){ var inp=e.target.closest&&e.target.closest('.zxb-in'); if(!inp) return; var s=zxbSubjectAt(parseInt(inp.getAttribute('data-si'))); if(s==null) return; var f=inp.getAttribute('data-f'), cls=inp.getAttribute('data-cls')||null; if(f==='date') zxbSetDate(s,inp.value,cls); else zxbSetField(s,f,inp.value,cls);
      var row=inp.closest('.zxb-row'); if(row && f==='date' && !cls){ var sched=!!zxbModel.subjects[s].base.date; row.classList.toggle('is-sched',sched); row.classList.toggle('is-unsched',!sched); var st=row.querySelector('.zxb-status'); if(st) st.textContent=sched?'✓':'·'; }
      zxbUpdateFoot(); updateSummary(); });
    root.addEventListener('click',function(e){ var t=e.target;
      var ap=t.closest&&t.closest('[data-apply]'); if(ap){ var s=zxbSubjectAt(parseInt(ap.getAttribute('data-apply'))), c=ap.getAttribute('data-cls'), S=zxbModel.subjects[s], i=S.appliesTo.indexOf(c); if(i===-1)S.appliesTo.push(c); else S.appliesTo.splice(i,1); zxbRender(); return; }
      var ex=t.closest&&t.closest('[data-expand]'); if(ex){ var s2=zxbSubjectAt(parseInt(ex.getAttribute('data-expand'))); zxbExpanded[s2]=!zxbExpanded[s2]; zxbRender(); return; }
      var cl=t.closest&&t.closest('[data-clear]'); if(cl){ var s3=zxbSubjectAt(parseInt(cl.getAttribute('data-clear'))), S3=zxbModel.subjects[s3]; S3.base={date:'',start:'',end:'',total:zxbModel.defaults.total,passing:zxbModel.defaults.passing}; S3.overrides={}; zxbRender(); return; }
      var rs=t.closest&&t.closest('[data-reset]'); if(rs){ var s4=zxbSubjectAt(parseInt(rs.getAttribute('data-reset'))), c4=rs.getAttribute('data-cls'); if(zxbModel.subjects[s4].overrides) delete zxbModel.subjects[s4].overrides[c4]; zxbRender(); return; } });
    var dt=document.getElementById('zxb-def-total'), dp=document.getElementById('zxb-def-pass'), dd=document.getElementById('zxb-def-dur'), ba=document.getElementById('zxb-apply-all');
    if(dt) dt.addEventListener('change',function(){ zxbModel.defaults.total=parseInt(dt.value)||0; });
    if(dp) dp.addEventListener('change',function(){ zxbModel.defaults.passing=parseInt(dp.value)||0; });
    if(dd) dd.addEventListener('change',function(){ zxbModel.defaults.durationMins=parseInt(dd.value)||0; });
    if(ba) ba.addEventListener('click',function(){ zxbApplyDefaults(); zxbRender(); });
  }
  function zxbInit(){
    if(!ZXB_ON) return;
    var sb=document.getElementById('zxb-builder'); if(sb) sb.style.display='';
    var sc=document.getElementById('zxb-scope'); if(sc) sc.style.display='';
    var leg=document.getElementById('ec-legacy-schedule'); if(leg) leg.style.display='none';
    var dp=document.getElementById('zxb-def-pass'); if(dp){ dp.value=parseInt(pctIn.value)||33; zxbModel.defaults.passing=parseInt(dp.value)||33; }
    var dt=document.getElementById('zxb-def-total'); if(dt) zxbModel.defaults.total=parseInt(dt.value)||100;
    if(ecEdit){ zxbHydrate(ecEdit.rows||[]); }
    zxbWire(); zxbRender();
  }
  zxbInit();

  /* ── Save ───────────────────────────────────────────────────────── */
  saveBtn.addEventListener('click', function () {
    // UX-1.3: single source of truth — same validators as the wizard Next
    // gating. On failure, jump to the offending step (errors render inline).
    if (!window.zxExam.validateStep(1)) { if (window.zxWizard) window.zxWizard.goTo(1); return; }
    if (!window.zxExam.validateStep(2)) { if (window.zxWizard) window.zxWizard.goTo(2); return; }

    // UX-2.0.1-B: when the datesheet builder is active, serialize from its model
    // (byte-identical row objects); otherwise the LEGACY table serializer runs verbatim.
    if (ZXB_ON) {
      document.getElementById('examScheduleInput').value = JSON.stringify(zxbSerialize());
    } else {
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
    } // end legacy serializer (ZXB_ON branch above)

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

/* ── UX-1.4.1 — zx-combobox searchable subject picker ───────────────── */
.zx-combobox { position: relative; width: 100%; min-width: 130px; }
.zx-cb-input {
  width: 100%; padding: 6px 24px 6px 8px; border: 1px solid var(--border);
  border-radius: 5px; background: var(--bg2); color: var(--t1);
  font-size: .84rem; box-sizing: border-box; cursor: pointer;
}
.zx-cb-input::placeholder { color: var(--t3); }
.zx-cb-input:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 2px var(--gold-ring); }
.zx-cb-input:disabled { background: var(--bg3); color: var(--t3); cursor: not-allowed; }
.zx-combobox::after {
  content: "\25BE"; position: absolute; right: 9px; top: 50%;
  transform: translateY(-50%); font-size: .7rem; color: var(--t3); pointer-events: none;
}
.zx-cb-list {
  position: fixed; z-index: 1000; max-height: 230px; overflow-y: auto;
  margin: 0; padding: 4px; list-style: none; background: var(--bg2);
  border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,.18);
}
.zx-cb-opt {
  padding: 7px 10px; border-radius: 5px; font-size: .84rem; color: var(--t1);
  cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.zx-cb-opt:hover, .zx-cb-opt.zx-cb-active { background: var(--gold-dim); color: var(--gold); }
.zx-cb-opt[aria-selected="true"] { font-weight: 700; }
.zx-cb-opt.zx-cb-hidden { display: none; }
.zx-cb-empty { padding: 8px 10px; font-size: .82rem; color: var(--t3); }
/* project the hidden select's invalid state onto the visible combobox input */
.zx-combobox:has(> .subj-sel.zx-invalid) .zx-cb-input {
  border-color: #ef4444 !important; box-shadow: 0 0 0 2px rgba(239,68,68,.15) !important;
}

/* ── UX-1.4.1 / UX-1.4.3 — section fan-out visibility ───────────────── */
.ec-cls-cell { min-width: 150px; }
.ec-fanout {
  margin-top: 7px;
  display: flex; flex-wrap: wrap; align-items: center; gap: 5px 8px;
  font-size: .72rem; line-height: 1.3; max-width: 240px;
}
.ec-fanout-lead { display: inline-flex; align-items: center; gap: 5px; color: var(--t3); }
.ec-fanout-lead i { color: var(--gold); font-size: .72rem; }
.ec-fanout-lead strong { color: var(--gold); font-weight: 700; }
.ec-fanout-chips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.ec-fanout-chip {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 19px; padding: 0 6px;
  background: var(--gold-dim); color: var(--gold);
  border-radius: 5px; font-size: .7rem; font-weight: 700; line-height: 1;
}
.ec-fanout--warn .ec-fanout-lead,
.ec-fanout--warn { color: #b45309; }
.ec-fanout--warn i { color: #d97706; }
.ec-fanout--warn strong { color: #b45309; }

/* ── UX-1.4.2 — mobile schedule card layout (≤768px) ─────────────────
   Pure CSS re-flow of the schedule table into stacked cards. No DOM/serializer
   change — labels come from each cell's data-label (added in makeRow). Desktop
   (>768px) is untouched. */
@media (max-width: 768px) {
  /* cards flow instead of horizontal-scrolling the fixed-width table */
  .ex-table-wrap   { overflow: visible; }
  .ex-sched-table  { display: block; min-width: 0; }
  .ex-sched-table thead { display: none; }
  .ex-sched-table tbody { display: block; }

  .ex-sched-table tr.ec-sched-row {
    display: block;
    margin: 0 0 12px;
    padding: 4px 12px 8px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--bg2);
    box-shadow: var(--sh);
  }
  .ex-sched-table tr.ec-sched-row:hover td { background: transparent; }

  .ex-sched-table td {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border: none;
    border-bottom: 1px solid var(--border);
  }
  .ex-sched-table tr.ec-sched-row td:last-child { border-bottom: none; }

  .ex-sched-table td::before {
    content: attr(data-label);
    flex: 0 0 40%;
    font-size: .72rem;
    font-weight: 600;
    color: var(--t2);
    text-transform: uppercase;
    letter-spacing: .03em;
  }

  /* controls take the remaining width (override fixed widths) */
  .ex-sched-table td .zx-combobox,
  .ex-sched-table td input.ex-time,
  .ex-sched-table td input.ex-marks,
  .ex-sched-table td select.ex-sel {
    flex: 1 1 auto; width: auto; min-width: 0;
  }

  /* Class cell: label + select on row 1, fan-out chip wraps full-width below */
  .ex-sched-table td.ec-cls-cell { flex-wrap: wrap; }
  .ex-sched-table td.ec-cls-cell .cls-sel  { flex: 1 1 auto; }
  .ex-sched-table td.ec-cls-cell .ec-fanout { flex: 1 1 100%; max-width: none; margin-top: 6px; }

  /* Actions: no label, right-aligned, larger tap targets */
  .ex-sched-table td.ex-row-act { justify-content: flex-end; gap: 10px; }
  .ex-sched-table td.ex-row-act::before { display: none; }
  .ex-sched-table td.ex-row-act .ex-btn-icon { width: 36px; height: 36px; }
}

/* ── UX-1.4.5 — desktop/laptop schedule density & layout (≥769px) ──────
   Root cause of the defect: an auto-layout table with min-width:780px could
   exceed the left panel (Live Summary takes 280px) → horizontal scroll that
   pushed the Actions column out of view. Fix: fluid FIXED layout that always
   fits the container; Subject (col 3) flexes, all other columns are
   proportional, Actions is always visible. Scoped ≥769px so the ≤768 card
   layout (UX-1.4.2) is untouched. No DOM/serializer/contract change. */
@media (min-width: 769px) {
  .ex-table-wrap  { overflow-x: visible; }            /* table now fits → no sideways scroll */
  .ex-sched-table { table-layout: fixed; width: 100%; min-width: 0; }

  /* proportional columns (sum < 100; Subject col 3 = auto, absorbs the rest
     incl. the freed Passing-Marks column when grading scale hides it) */
  .ex-sched-table th:nth-child(1), .ex-sched-table td:nth-child(1) { width: 12%; }  /* Date    */
  .ex-sched-table th:nth-child(2), .ex-sched-table td:nth-child(2) { width: 14%; }  /* Class   */
  /* col 3 Subject = auto */
  .ex-sched-table th:nth-child(4), .ex-sched-table td:nth-child(4) { width: 10%; }  /* Start   */
  .ex-sched-table th:nth-child(5), .ex-sched-table td:nth-child(5) { width: 10%; }  /* End     */
  .ex-sched-table th:nth-child(6), .ex-sched-table td:nth-child(6) { width: 9%;  }  /* Total   */
  .ex-sched-table th:nth-child(7), .ex-sched-table td:nth-child(7) { width: 9%;  }  /* Passing */
  .ex-sched-table th:nth-child(8), .ex-sched-table td:nth-child(8) { width: 12%; }  /* Actions */

  /* unified control sizing — one height/border/focus language across the row */
  .ex-sched-table td .ex-sel,
  .ex-sched-table td .ex-time,
  .ex-sched-table td .ex-marks,
  .ex-sched-table td .zx-cb-input {
    width: 100%; min-width: 0; box-sizing: border-box;
    height: 36px; font-size: .82rem;
    border: 1px solid var(--border); border-radius: 6px; background: var(--bg2); color: var(--t1);
  }
  .ex-sched-table td .ex-sel,
  .ex-sched-table td .ex-time,
  .ex-sched-table td .ex-marks { padding: 0 8px; }
  .ex-sched-table td .ex-marks { text-align: center; }
  .ex-sched-table td .zx-cb-input { padding: 0 24px 0 8px; }
  .ex-sched-table td .ex-sel:focus,
  .ex-sched-table td .ex-time:focus,
  .ex-sched-table td .ex-marks:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 2px var(--gold-ring); }
  .ec-cls-cell { min-width: 0; }

  /* density + hierarchy: align controls to the top so the wrapped fan-out
     flows below the Class select without vertically centring the whole row */
  .ex-sched-table th { padding: 11px 10px; }
  .ex-sched-table td { padding: 11px 8px; vertical-align: top; }
  .ex-sched-table td.ex-row-act { text-align: center; white-space: nowrap; }
  .ex-sched-table td.ex-row-act .ex-btn-icon,
  .ex-sched-table td.ex-row-act .ec-btn-dup { width: 32px; height: 32px; margin: 0 2px; }
}

/* ── UX-2.0.1-B P1 — Datesheet Builder ─────────────────────────────── */
.zxb-scope { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:6px; }
.zxb-scope-chips { display:flex; gap:6px; flex-wrap:wrap; }
.zxb-chip, .zxb-applychip {
  border:1px solid var(--border); background:var(--bg3); color:var(--t2);
  border-radius:20px; padding:5px 13px; font-size:.8rem; font-weight:600; cursor:pointer; transition:all .15s;
}
.zxb-chip.on { background:var(--gold); border-color:var(--gold); color:#fff; }
.zxb-applychip { padding:3px 10px; font-size:.74rem; border-radius:14px; }
.zxb-applychip.on { background:var(--gold-dim); border-color:var(--gold); color:var(--gold); }
.zxb-head-hint { font-size:.74rem; font-weight:400; opacity:.85; margin-left:8px; }
.zxb-bulkbar { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:10px 12px; margin-bottom:12px;
  background:var(--bg3); border:1px solid var(--border); border-radius:8px; font-size:.8rem; }
.zxb-bulk-label { font-weight:700; color:var(--t2); display:inline-flex; align-items:center; gap:6px; }
.zxb-bulk-label i { color:var(--gold); }
.zxb-bulkbar label { display:inline-flex; align-items:center; gap:5px; color:var(--t2); }
.zxb-mini { width:64px; height:30px; padding:0 6px; border:1px solid var(--border); border-radius:6px; background:var(--bg2); color:var(--t1); box-sizing:border-box; }
.zxb-rows { display:flex; flex-direction:column; gap:10px; }
.zxb-empty { text-align:center; padding:28px; color:var(--t3); font-size:.9rem; }
.zxb-row { border:1px solid var(--border); border-radius:9px; padding:10px 12px; background:var(--bg2); transition:border-color .15s; }
.zxb-row.is-unsched { background:var(--bg3); }
.zxb-row.bad { border-color:#ef4444; box-shadow:0 0 0 2px rgba(239,68,68,.12); }
.zxb-row-main { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.zxb-status { width:18px; text-align:center; color:var(--gold); font-weight:700; }
.zxb-row.is-unsched .zxb-status { color:var(--t3); }
.zxb-subj { flex:1 1 150px; min-width:120px; font-weight:600; color:var(--t1); font-size:.9rem; }
.zxb-in { height:34px; border:1px solid var(--border); border-radius:6px; background:var(--bg2); color:var(--t1); font-size:.82rem; padding:0 8px; box-sizing:border-box; }
.zxb-date { width:140px; }  .zxb-start, .zxb-end { width:96px; }  .zxb-total, .zxb-pass { width:74px; text-align:center; }
.zxb-in:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 2px var(--gold-ring); }
.zxb-dash { color:var(--t3); }
.zxb-clear { width:30px; height:30px; border:1px solid var(--border); border-radius:6px; background:var(--bg3); color:#ef4444; cursor:pointer; }
.zxb-clear:hover { background:#ef4444; color:#fff; border-color:#ef4444; }
.zxb-nopass .zxb-pass { display:none; }
.zxb-row-apply { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:8px; padding-top:8px; border-top:1px dashed var(--border); }
.zxb-apply-label { font-size:.72rem; color:var(--t3); text-transform:uppercase; letter-spacing:.03em; margin-right:2px; }
.zxb-ovtoggle { margin-left:auto; border:none; background:transparent; color:var(--t2); font-size:.74rem; cursor:pointer; font-weight:600; }
.zxb-ovtoggle.has { color:var(--gold); }
.zxb-ovpanel { margin-top:8px; padding:8px 10px; background:var(--bg3); border-radius:7px; display:flex; flex-direction:column; gap:6px; }
.zxb-ovrow { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:.8rem; }
.zxb-ovcls { width:90px; font-weight:600; color:var(--t2); }
.zxb-reset { border:none; background:transparent; color:#ef4444; font-size:.74rem; cursor:pointer; }
.zxb-foot { margin-top:12px; font-size:.82rem; color:var(--t2); }
.zxb-foot.zxb-foot-err { color:#dc2626; }
.zxb-warn { color:#b45309; font-weight:600; }
@media (max-width:768px){
  .zxb-subj { flex:1 1 100%; }
  .zxb-row-main { gap:6px; }
  .zxb-date, .zxb-start, .zxb-end, .zxb-total, .zxb-pass { flex:1 1 auto; width:auto; min-width:0; }
}
</style>
