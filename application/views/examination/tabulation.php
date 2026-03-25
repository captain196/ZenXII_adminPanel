<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ═══════════════════════════════════════════════════════════════════════
   ETB — Tabulation Sheet
   ═══════════════════════════════════════════════════════════════════════ */

.etb-wrap {
  padding: 24px 28px 48px;
  margin: 0;
  color: var(--t1);
}

/* ── Header ──────────────────────────────────────────────────────────── */
.etb-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 24px;
}
.etb-page-title {
  font-size: 1.55rem;
  font-weight: 700;
  font-family: var(--font-b);
  color: var(--t1);
}
.etb-page-title i { color: var(--gold); margin-right: 8px; font-size: 1.3rem; }
.etb-header-actions { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Breadcrumb ──────────────────────────────────────────────────────── */
.etb-breadcrumb {
  list-style: none;
  display: flex;
  gap: 6px;
  padding: 0;
  margin: 3px 0 0;
  font-size: 13px;
  color: var(--t3);
}
.etb-breadcrumb li + li::before { content: '/'; margin-right: 6px; color: var(--t3); }
.etb-breadcrumb a { color: var(--gold); text-decoration: none; }
.etb-breadcrumb a:hover { text-decoration: underline; }

/* ── Buttons ─────────────────────────────────────────────────────────── */
.etb-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 10px 22px;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all var(--ease);
  white-space: nowrap;
}
.etb-btn:disabled { opacity: .5; cursor: not-allowed; }
.etb-btn-primary { background: var(--gold); color: #fff; }
.etb-btn-primary:hover:not(:disabled) { background: var(--gold2); box-shadow: 0 4px 14px var(--gold-glow); }
.etb-btn-outline { background: transparent; color: var(--gold); border: 1.5px solid var(--gold); }
.etb-btn-outline:hover:not(:disabled) { background: var(--gold-dim); }
.etb-btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 6px; }

/* ── Filter bar ──────────────────────────────────────────────────────── */
.etb-filters {
  display: flex;
  align-items: flex-end;
  gap: 14px;
  flex-wrap: wrap;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 20px 24px;
  margin-bottom: 22px;
}
.etb-form-group { display: flex; flex-direction: column; gap: 5px; min-width: 150px; }
.etb-label {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--t3);
  text-transform: uppercase;
  letter-spacing: .4px;
}
.etb-select {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: var(--bg);
  color: var(--t1);
  font-size: 14px;
  outline: none;
  transition: border-color .2s;
}
.etb-select:focus { border-color: var(--gold); }

/* ── Loading / Empty ─────────────────────────────────────────────────── */
.etb-loading, .etb-empty {
  text-align: center;
  padding: 48px 24px;
  color: var(--t3);
  font-size: 14px;
}
.etb-loading i, .etb-empty i { font-size: 2rem; display: block; margin-bottom: 10px; }

/* ── Table wrapper ───────────────────────────────────────────────────── */
.etb-table-outer {
  overflow-x: auto;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: var(--bg2);
}

/* ── Tabulation table ────────────────────────────────────────────────── */
.etb-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
  white-space: nowrap;
}
.etb-table th,
.etb-table td {
  padding: 7px 10px;
  border: 1px solid var(--border);
  text-align: center;
  vertical-align: middle;
}
.etb-table thead th {
  background: var(--gold);
  color: #fff;
  font-weight: 700;
  position: sticky;
  top: 0;
  z-index: 2;
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: .3px;
}
.etb-table thead tr:nth-child(2) th { background: var(--gold2); }
.etb-table thead tr:nth-child(3) th {
  background: var(--bg3);
  color: var(--t2);
  font-weight: 600;
  font-size: 11.5px;
}
.etb-table tbody tr:nth-child(even) { background: var(--bg); }
.etb-table tbody tr:nth-child(odd) { background: var(--bg2); }
.etb-table tbody tr:hover { background: var(--gold-dim); }
.etb-table tfoot td {
  background: var(--bg3);
  font-weight: 700;
  color: var(--t1);
  border-top: 2px solid var(--gold);
}

/* Subject group separator */
.etb-subj-sep { border-left: 2.5px solid var(--gold) !important; }

/* Student name left-align */
.etb-name { text-align: left !important; font-weight: 500; }

/* Absent */
.etb-absent td { background: rgba(220,38,38,.06) !important; }
.etb-ab { font-style: italic; color: #9ca3af; }

/* Grade color */
.etb-grade { font-weight: 700; }
.etb-grade-aplus { color: #059669; }
.etb-grade-a     { color: #0d9488; }
.etb-grade-bplus { color: #2563eb; }
.etb-grade-b     { color: #6366f1; }
.etb-grade-c     { color: #d97706; }
.etb-grade-d     { color: #ea580c; }
.etb-grade-f     { color: #dc2626; }

/* Pass / Fail */
.etb-pass { color: #059669; font-weight: 700; }
.etb-fail { color: #dc2626; font-weight: 700; }

/* Rank badges */
.etb-rank { font-weight: 700; }
.etb-rank-1 { color: #d97706; }
.etb-rank-2 { color: #6b7280; }
.etb-rank-3 { color: #92400e; }

/* ── Print header (hidden on screen) ─────────────────────────────────── */
.etb-print-header {
  display: none;
  text-align: center;
  margin-bottom: 12px;
}
.etb-print-school { font-size: 1.2rem; font-weight: 800; margin-bottom: 2px; }
.etb-print-sub { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 2px; }
.etb-print-meta { font-size: 11px; color: #6b7280; }

/* ── Stats bar ───────────────────────────────────────────────────────── */
.etb-stats {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  font-size: 12px;
  font-family: var(--font-m);
  color: var(--t2);
}
.etb-stat b { color: var(--t1); }

/* ── Bulk Compute Modal ──────────────────────────────────────────────── */
.etb-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 9999;
  justify-content: center;
  align-items: center;
}
.etb-modal-overlay.etb-active { display: flex; }
.etb-modal {
  background: var(--bg2);
  border-radius: 14px;
  width: 90%;
  max-width: 480px;
  box-shadow: 0 8px 32px rgba(0,0,0,.25);
  overflow: hidden;
}
.etb-modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  background: var(--gold);
  color: #fff;
  font-weight: 700;
  font-size: 14px;
}
.etb-modal-close {
  background: none;
  border: none;
  color: #fff;
  font-size: 1.2rem;
  cursor: pointer;
  line-height: 1;
}
.etb-modal-body { padding: 18px; }
.etb-modal-body .etb-form-group { margin-bottom: 12px; }
.etb-checkbox-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--t1);
}
.etb-checkbox-row input[type="checkbox"] { width: 15px; height: 15px; accent-color: var(--gold); }
.etb-progress-area {
  margin-top: 12px;
  max-height: 180px;
  overflow-y: auto;
  font-size: 12px;
  font-family: var(--font-m);
}
.etb-progress-item {
  padding: 5px 10px;
  border-radius: 5px;
  margin-bottom: 3px;
}
.etb-progress-ok { background: rgba(5,150,105,.1); color: #059669; }
.etb-progress-err { background: rgba(220,38,38,.1); color: #dc2626; }
.etb-progress-info { background: var(--gold-dim); color: var(--t1); }

/* ═══════════════════════════════════════════════════════════════════════
   PRINT STYLES
   ═══════════════════════════════════════════════════════════════════════ */
@media print {
  body * { visibility: hidden; }
  .etb-print-zone, .etb-print-zone * { visibility: visible; }
  .etb-print-zone {
    position: absolute;
    left: 0; top: 0;
    width: 100%;
  }

  .etb-print-header { display: block !important; }
  .etb-header, .etb-filters, .etb-stats,
  .etb-modal-overlay, .etb-loading, .etb-empty,
  .content-wrapper > .main-header,
  .main-sidebar, .main-footer, .control-sidebar,
  .etb-header-actions { display: none !important; }

  .etb-table-outer {
    border: none;
    box-shadow: none;
    overflow: visible;
  }
  .etb-table {
    font-size: 8pt;
    page-break-inside: auto;
  }
  .etb-table th,
  .etb-table td {
    padding: 3px 5px;
    border: 1px solid #333 !important;
  }
  .etb-table thead th {
    background: #e5e7eb !important;
    color: #111 !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .etb-table thead tr:nth-child(2) th {
    background: #d1d5db !important;
    color: #111 !important;
  }
  .etb-table thead tr:nth-child(3) th {
    background: #f3f4f6 !important;
    color: #374151 !important;
  }
  .etb-table tbody tr:nth-child(even) { background: #f9fafb !important; }
  .etb-table tbody tr:nth-child(odd) { background: #fff !important; }
  .etb-absent td { background: #fef2f2 !important; }
  .etb-table tfoot td {
    background: #e5e7eb !important;
    border-top: 2px solid #111 !important;
  }

  @page {
    size: A4 landscape;
    margin: 10mm;
  }
}

/* ═══════════════════════════════════════════════════════════════════════
   RESPONSIVE
   ═══════════════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {
  .etb-wrap { padding: 14px 10px 36px; }
  .etb-header { flex-direction: column; align-items: flex-start; gap: 8px; }
  .etb-filters { flex-direction: column; gap: 10px; }
  .etb-form-group { min-width: 100%; }
  .etb-stats { flex-direction: column; gap: 4px; }
  .etb-page-title { font-size: 1.15rem; }
  .etb-table th, .etb-table td { padding: 4px 6px; font-size: 10px; }
}
</style>

<div class="content-wrapper">
<section class="content">
<div class="etb-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="etb-header">
    <div>
      <div class="etb-page-title"><i class="fa fa-th"></i> Tabulation Sheet</div>
      <ol class="etb-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('examination') ?>">Examination</a></li>
        <li>Tabulation Sheet</li>
      </ol>
    </div>
    <div class="etb-header-actions">
      <button class="etb-btn etb-btn-outline" onclick="window.print()" id="etbPrintBtn" disabled>
        <i class="fa fa-print"></i> Print
      </button>
      <button class="etb-btn etb-btn-primary" id="etbBulkBtn">
        <i class="fa fa-calculator"></i> Bulk Compute
      </button>
    </div>
  </div>

  <!-- ── Filter Bar ───────────────────────────────────────────────── -->
  <div class="etb-filters">
    <div class="etb-form-group">
      <label class="etb-label">Exam</label>
      <select id="etbExamSel" class="etb-select">
        <option value="">-- Select Exam --</option>
        <?php foreach ($exams as $ex): ?>
        <option value="<?= htmlspecialchars($ex['id']) ?>"
                data-grading="<?= htmlspecialchars($ex['GradingScale'] ?? 'Percentage') ?>"
                data-passing="<?= (int)($ex['PassingPercent'] ?? 33) ?>">
          <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="etb-form-group">
      <label class="etb-label">Class</label>
      <select id="etbClassSel" class="etb-select" disabled>
        <option value="">-- Select Class --</option>
        <?php foreach ($structure as $ck => $sections): ?>
        <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="etb-form-group">
      <label class="etb-label">Section</label>
      <select id="etbSectionSel" class="etb-select" disabled>
        <option value="">-- Select Section --</option>
      </select>
    </div>
    <button id="etbLoadBtn" class="etb-btn etb-btn-primary" disabled>
      <i class="fa fa-search"></i> Load
    </button>
  </div>

  <!-- ── Loading / Empty ──────────────────────────────────────────── -->
  <div id="etbLoading" class="etb-loading" style="display:none;">
    <i class="fa fa-spinner fa-spin"></i>
    <p>Loading tabulation data&hellip;</p>
  </div>
  <div id="etbEmpty" class="etb-empty" style="display:none;">
    <i class="fa fa-inbox"></i>
    <p>No data found. Ensure marks have been entered and results computed.</p>
  </div>

  <!-- ── Tabulation Table ─────────────────────────────────────────── -->
  <div id="etbTableWrap" style="display:none;">
    <div class="etb-print-zone">

      <!-- Print-only header -->
      <div class="etb-print-header">
        <div class="etb-print-school"><?= htmlspecialchars($this->school_name ?? '') ?></div>
        <div class="etb-print-sub" id="etbPrintExam"></div>
        <div class="etb-print-meta" id="etbPrintMeta"></div>
      </div>

      <!-- Stats bar -->
      <div class="etb-stats" id="etbStats"></div>

      <div class="etb-table-outer">
        <table class="etb-table" id="etbTable">
          <thead id="etbThead"></thead>
          <tbody id="etbTbody"></tbody>
          <tfoot id="etbTfoot"></tfoot>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.etb-wrap -->
</section>
</div><!-- /.content-wrapper -->

<!-- ── Bulk Compute Modal ─────────────────────────────────────────── -->
<div class="etb-modal-overlay" id="etbBulkOverlay">
  <div class="etb-modal">
    <div class="etb-modal-head">
      <span><i class="fa fa-calculator"></i> Bulk Compute Results</span>
      <button class="etb-modal-close" id="etbBulkClose">&times;</button>
    </div>
    <div class="etb-modal-body">
      <div class="etb-form-group">
        <label class="etb-label">Exam</label>
        <select id="etbBulkExam" class="etb-select" style="width:100%;">
          <option value="">-- Select Exam --</option>
          <?php foreach ($exams as $ex): ?>
          <option value="<?= htmlspecialchars($ex['id']) ?>">
            <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="etb-form-group" id="etbBulkClassGroup">
        <label class="etb-label">Class</label>
        <select id="etbBulkClass" class="etb-select" style="width:100%;">
          <option value="">-- Select Class --</option>
          <?php foreach ($structure as $ck => $sections): ?>
          <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="etb-checkbox-row">
        <input type="checkbox" id="etbBulkAll">
        <label for="etbBulkAll">All Classes (compute entire exam)</label>
      </div>
      <button class="etb-btn etb-btn-primary" id="etbBulkRun" style="width:100%;" disabled>
        <i class="fa fa-cogs"></i> Compute
      </button>
      <div class="etb-progress-area" id="etbBulkProgress"></div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';

  /* ── Config ──────────────────────────────────────────────────────── */
  var BASE_URL   = '<?= base_url() ?>';
  var STRUCTURE  = <?= json_encode($structure) ?>;
  var CSRF_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
  var csrfHash   = '<?= $this->security->get_csrf_hash() ?>';
  var SESSION    = '<?= htmlspecialchars($this->session_year ?? '') ?>';

  /* ── DOM refs ────────────────────────────────────────────────────── */
  var examSel    = document.getElementById('etbExamSel');
  var classSel   = document.getElementById('etbClassSel');
  var sectionSel = document.getElementById('etbSectionSel');
  var loadBtn    = document.getElementById('etbLoadBtn');
  var printBtn   = document.getElementById('etbPrintBtn');
  var loading    = document.getElementById('etbLoading');
  var emptyDiv   = document.getElementById('etbEmpty');
  var tableWrap  = document.getElementById('etbTableWrap');
  var thead      = document.getElementById('etbThead');
  var tbody      = document.getElementById('etbTbody');
  var tfoot      = document.getElementById('etbTfoot');
  var statsDiv   = document.getElementById('etbStats');

  /* ── Helpers ─────────────────────────────────────────────────────── */
  function esc(str) {
    if (str == null) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
  }

  function gradeClass(g) {
    if (!g) return '';
    var gl = g.replace('+','plus').replace('-','minus').toUpperCase();
    var map = { 'APLUS':'aplus','A':'a','BPLUS':'bplus','B':'b','C':'c','D':'d','E':'f','F':'f' };
    return 'etb-grade-' + (map[gl] || 'c');
  }

  /* ── Filter logic ────────────────────────────────────────────────── */
  function checkReady() {
    var ok = examSel.value && classSel.value && sectionSel.value;
    loadBtn.disabled = !ok;
  }

  examSel.addEventListener('change', function () {
    classSel.disabled = !this.value;
    checkReady();
  });

  classSel.addEventListener('change', function () {
    var letters = STRUCTURE[this.value] || [];
    sectionSel.innerHTML = '<option value="">-- Select Section --</option>';
    letters.forEach(function (s) {
      var o = document.createElement('option');
      o.value = 'Section ' + s;
      o.textContent = 'Section ' + s;
      sectionSel.appendChild(o);
    });
    sectionSel.disabled = !letters.length;
    checkReady();
  });

  sectionSel.addEventListener('change', checkReady);
  loadBtn.addEventListener('click', loadTabulation);

  /* ── Load Tabulation ─────────────────────────────────────────────── */
  function loadTabulation() {
    var examId     = examSel.value;
    var classKey   = classSel.value;
    var sectionKey = sectionSel.value;
    if (!examId || !classKey || !sectionKey) return;

    loading.style.display   = '';
    emptyDiv.style.display  = 'none';
    tableWrap.style.display = 'none';
    printBtn.disabled       = true;

    var fd = new FormData();
    fd.append('examId', examId);
    fd.append('classKey', classKey);
    fd.append('sectionKey', sectionKey);
    fd.append(CSRF_NAME, csrfHash);

    fetch(BASE_URL + 'examination/get_tabulation_data', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        loading.style.display = 'none';
        if (r.csrf_token) csrfHash = r.csrf_token;
        if (!r.status || r.status !== 'success') {
          emptyDiv.style.display = '';
          emptyDiv.querySelector('p').textContent = r.message || 'No data found.';
          return;
        }
        renderTable(r);
        tableWrap.style.display = '';
        printBtn.disabled = false;
      })
      .catch(function () {
        loading.style.display = 'none';
        emptyDiv.style.display = '';
        emptyDiv.querySelector('p').textContent = 'Network error. Please try again.';
      });
  }

  /* ── Render Table ────────────────────────────────────────────────── */
  function renderTable(data) {
    var students = data.students || [];
    var subjects = data.subjects || [];
    var marks    = data.marks    || {};
    var computed = data.computed || {};
    var examName = data.examName || '';
    var className = data.className || '';
    var sectionKey = data.sectionKey || '';
    var gradingScale = data.gradingScale || 'Percentage';
    var passingPct   = data.passingPercent || 33;

    /* Print header */
    document.getElementById('etbPrintExam').textContent =
      'Tabulation Sheet — ' + examName;
    document.getElementById('etbPrintMeta').textContent =
      className + ' / ' + sectionKey + '  |  Session: ' + SESSION;

    /* ── Build subject column map ─────────────────────────────────── */
    // Each subject has components (from template) + a Total column
    var subjCols = [];  // [{name, components:[{name, maxMarks}], maxMarks}]
    subjects.forEach(function (s) {
      var comps = [];
      if (s.components && typeof s.components === 'object') {
        var compArr = Array.isArray(s.components) ? s.components : Object.values(s.components);
        compArr.forEach(function (c) {
          if (c && typeof c === 'object') {
            comps.push({ name: c.Name || c.name || 'Comp', maxMarks: parseInt(c.MaxMarks || c.maxMarks || 0) });
          }
        });
      }
      subjCols.push({
        name: s.name,
        components: comps,
        maxMarks: parseInt(s.maxMarks || 0)
      });
    });

    /* ── Sort students by rank ────────────────────────────────────── */
    students.sort(function (a, b) {
      var ra = (computed[a.userId] || {}).rank || 9999;
      var rb = (computed[b.userId] || {}).rank || 9999;
      return ra - rb;
    });

    /* ── THEAD: 3 rows ────────────────────────────────────────────── */
    thead.innerHTML = '';

    // Row 1: main headers with colspan for each subject
    var r1 = document.createElement('tr');
    addTh(r1, '#', 3, 1);
    addTh(r1, 'Student Name', 3, 1);

    subjCols.forEach(function (s, idx) {
      var span = s.components.length > 0 ? s.components.length + 1 : 1;
      var th = addTh(r1, s.name, 1, span);
      if (idx > 0) th.classList.add('etb-subj-sep');
    });

    addTh(r1, 'Grand Total', 3, 1);
    addTh(r1, 'Max', 3, 1);
    addTh(r1, '%', 3, 1);
    addTh(r1, 'Grade', 3, 1);
    addTh(r1, 'P/F', 3, 1);
    addTh(r1, 'Rank', 3, 1);
    thead.appendChild(r1);

    // Row 2: component names + "Total" under each subject
    var r2 = document.createElement('tr');
    // Skip # and Name (covered by rowspan)
    subjCols.forEach(function (s, idx) {
      if (s.components.length > 0) {
        s.components.forEach(function (c, ci) {
          var th = addTh(r2, c.name, 1, 1);
          if (ci === 0 && idx > 0) th.classList.add('etb-subj-sep');
        });
        addTh(r2, 'Total', 1, 1);
      } else {
        // Single column — already covered by rowspan? No — if no components, row1 has rowspan=3 colspan=1
        // Actually for no-component subjects, we set rowspan=3 in row1, so skip here
      }
    });
    // Skip Grand Total..Rank (covered by rowspan)
    if (r2.children.length > 0) thead.appendChild(r2);

    // Row 3: max marks under each component + subject total
    var r3 = document.createElement('tr');
    subjCols.forEach(function (s, idx) {
      if (s.components.length > 0) {
        s.components.forEach(function (c, ci) {
          var th = addTh(r3, c.maxMarks, 1, 1);
          if (ci === 0 && idx > 0) th.classList.add('etb-subj-sep');
        });
        addTh(r3, s.maxMarks, 1, 1);
      }
    });
    if (r3.children.length > 0) thead.appendChild(r3);

    /* ── TBODY ─────────────────────────────────────────────────────── */
    tbody.innerHTML = '';
    var subjectTotals = {}; // subject => { sum, count }
    var overallSum = 0, overallCount = 0;

    students.forEach(function (stu, idx) {
      var uid = stu.userId;
      var comp = computed[uid] || {};
      var isAbsent = comp.passFail === 'Absent';
      var tr = document.createElement('tr');
      if (isAbsent) tr.classList.add('etb-absent');

      // Serial
      addTd(tr, idx + 1);
      // Name
      var nameTd = addTd(tr, esc(stu.name));
      nameTd.classList.add('etb-name');

      // Per subject
      subjCols.forEach(function (s, sIdx) {
        var stuMarks = (marks[s.name] || {})[uid] || {};
        var subjTotal = 0;
        var subjAbsent = false;

        if (stuMarks.Absent || stuMarks.absent) {
          subjAbsent = true;
        }

        if (s.components.length > 0) {
          s.components.forEach(function (c, ci) {
            var val = stuMarks[c.name] != null ? stuMarks[c.name] : (stuMarks.Components ? (stuMarks.Components[c.name] || '') : '');
            var td;
            if (subjAbsent) {
              td = addTd(tr, 'AB');
              td.classList.add('etb-ab');
            } else {
              td = addTd(tr, val !== '' ? val : '-');
            }
            if (ci === 0 && sIdx > 0) td.classList.add('etb-subj-sep');
          });
          // Subject total
          var total = stuMarks.Total != null ? stuMarks.Total : (comp.Subjects && comp.Subjects[s.name] ? comp.Subjects[s.name].Total : '');
          if (subjAbsent) {
            var td = addTd(tr, 'AB');
            td.classList.add('etb-ab');
          } else {
            addTd(tr, total !== '' ? total : '-');
            subjTotal = parseFloat(total) || 0;
          }
        } else {
          // No components — single marks column
          var val = stuMarks.Total != null ? stuMarks.Total : stuMarks.Marks || '';
          var td;
          if (subjAbsent) {
            td = addTd(tr, 'AB');
            td.classList.add('etb-ab');
          } else {
            td = addTd(tr, val !== '' ? val : '-');
            subjTotal = parseFloat(val) || 0;
          }
          if (sIdx > 0) td.classList.add('etb-subj-sep');
        }

        // Track averages
        if (!subjAbsent) {
          if (!subjectTotals[s.name]) subjectTotals[s.name] = { sum: 0, count: 0 };
          subjectTotals[s.name].sum += subjTotal;
          subjectTotals[s.name].count++;
        }
      });

      // Grand Total
      addTd(tr, comp.totalMarks != null ? comp.totalMarks : '-');

      // Max
      addTd(tr, comp.maxMarks != null ? comp.maxMarks : '-');

      // Percentage
      var pct = comp.percentage != null ? parseFloat(comp.percentage).toFixed(2) : '-';
      addTd(tr, pct);

      // Grade
      var gradeTd = addTd(tr, esc(comp.grade || '-'));
      gradeTd.classList.add('etb-grade', gradeClass(comp.grade));

      // Pass / Fail
      var pfTd = addTd(tr, esc(comp.passFail || '-'));
      if (comp.passFail === 'Pass') pfTd.classList.add('etb-pass');
      else if (comp.passFail === 'Fail') pfTd.classList.add('etb-fail');
      else if (isAbsent) { pfTd.classList.add('etb-ab'); }

      // Rank
      var rank = comp.rank || '-';
      var rankTd = addTd(tr, rank);
      rankTd.classList.add('etb-rank');
      if (rank === 1) rankTd.classList.add('etb-rank-1');
      else if (rank === 2) rankTd.classList.add('etb-rank-2');
      else if (rank === 3) rankTd.classList.add('etb-rank-3');

      tbody.appendChild(tr);

      // Overall average tracking
      if (!isAbsent && comp.percentage != null) {
        overallSum += parseFloat(comp.percentage) || 0;
        overallCount++;
      }
    });

    /* ── TFOOT: averages ──────────────────────────────────────────── */
    tfoot.innerHTML = '';
    var fr = document.createElement('tr');
    addFootTd(fr, '');         // #
    addFootTd(fr, 'Average');  // Name

    subjCols.forEach(function (s, sIdx) {
      var avg = subjectTotals[s.name]
        ? (subjectTotals[s.name].sum / subjectTotals[s.name].count).toFixed(1)
        : '-';

      if (s.components.length > 0) {
        // Blank cells for components
        s.components.forEach(function (c, ci) {
          var td = addFootTd(fr, '');
          if (ci === 0 && sIdx > 0) td.classList.add('etb-subj-sep');
        });
        addFootTd(fr, avg);
      } else {
        var td = addFootTd(fr, avg);
        if (sIdx > 0) td.classList.add('etb-subj-sep');
      }
    });

    addFootTd(fr, ''); // Grand Total
    addFootTd(fr, ''); // Max
    var classAvg = overallCount > 0 ? (overallSum / overallCount).toFixed(2) : '-';
    addFootTd(fr, classAvg); // %
    addFootTd(fr, ''); // Grade
    addFootTd(fr, ''); // P/F
    addFootTd(fr, ''); // Rank
    tfoot.appendChild(fr);

    /* ── Stats ─────────────────────────────────────────────────────── */
    var totalStu = students.length;
    var passCount = 0, failCount = 0, absentCount = 0;
    students.forEach(function (stu) {
      var c = computed[stu.userId] || {};
      if (c.passFail === 'Pass') passCount++;
      else if (c.passFail === 'Fail') failCount++;
      if (c.passFail === 'Absent') absentCount++;
    });

    statsDiv.innerHTML =
      '<span><b>' + totalStu + '</b> Students</span>' +
      '<span><b style="color:#059669">' + passCount + '</b> Passed</span>' +
      '<span><b style="color:#dc2626">' + failCount + '</b> Failed</span>' +
      (absentCount ? '<span><b style="color:#9ca3af">' + absentCount + '</b> Absent</span>' : '') +
      '<span>Class Average: <b>' + classAvg + '%</b></span>' +
      '<span>Grading: <b>' + esc(gradingScale) + '</b></span>';
  }

  /* ── TH / TD helpers ─────────────────────────────────────────────── */
  function addTh(tr, text, rowspan, colspan) {
    var th = document.createElement('th');
    th.textContent = text;
    if (rowspan > 1) th.rowSpan = rowspan;
    if (colspan > 1) th.colSpan = colspan;
    tr.appendChild(th);
    return th;
  }
  function addTd(tr, text) {
    var td = document.createElement('td');
    td.textContent = text;
    tr.appendChild(td);
    return td;
  }
  function addFootTd(tr, text) {
    var td = document.createElement('td');
    td.textContent = text;
    tr.appendChild(td);
    return td;
  }

  /* ══════════════════════════════════════════════════════════════════
     BULK COMPUTE MODAL
     ══════════════════════════════════════════════════════════════════ */
  var bulkOverlay  = document.getElementById('etbBulkOverlay');
  var bulkExam     = document.getElementById('etbBulkExam');
  var bulkClass    = document.getElementById('etbBulkClass');
  var bulkAllCb    = document.getElementById('etbBulkAll');
  var bulkRun      = document.getElementById('etbBulkRun');
  var bulkProgress = document.getElementById('etbBulkProgress');
  var bulkClassGrp = document.getElementById('etbBulkClassGroup');

  document.getElementById('etbBulkBtn').addEventListener('click', function () {
    bulkOverlay.classList.add('etb-active');
    bulkProgress.innerHTML = '';
  });
  document.getElementById('etbBulkClose').addEventListener('click', function () {
    bulkOverlay.classList.remove('etb-active');
  });
  bulkOverlay.addEventListener('click', function (e) {
    if (e.target === bulkOverlay) bulkOverlay.classList.remove('etb-active');
  });

  function checkBulkReady() {
    bulkRun.disabled = !bulkExam.value || (!bulkAllCb.checked && !bulkClass.value);
  }
  bulkExam.addEventListener('change', checkBulkReady);
  bulkClass.addEventListener('change', checkBulkReady);
  bulkAllCb.addEventListener('change', function () {
    bulkClassGrp.style.display = this.checked ? 'none' : '';
    if (this.checked) bulkClass.value = '';
    checkBulkReady();
  });

  bulkRun.addEventListener('click', bulkCompute);

  function bulkCompute() {
    var examId = bulkExam.value;
    if (!examId) return;
    var allClasses = bulkAllCb.checked;
    var classKey   = bulkClass.value;
    if (!allClasses && !classKey) return;

    bulkRun.disabled = true;
    bulkRun.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Computing&hellip;';
    bulkProgress.innerHTML = '<div class="etb-progress-item etb-progress-info"><i class="fa fa-spinner fa-spin"></i> Processing&hellip;</div>';

    var fd = new FormData();
    fd.append('examId', examId);
    if (allClasses) {
      fd.append('allClasses', '1');
    } else {
      fd.append('classKey', classKey);
    }
    fd.append(CSRF_NAME, csrfHash);

    fetch(BASE_URL + 'examination/bulk_compute', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r.csrf_token) csrfHash = r.csrf_token;

        bulkRun.disabled = false;
        bulkRun.innerHTML = '<i class="fa fa-cogs"></i> Compute';

        var html = '';

        if (r.processed && r.processed.length) {
          r.processed.forEach(function (p) {
            html += '<div class="etb-progress-item etb-progress-ok">' +
              '<i class="fa fa-check"></i> ' + esc(p.class) + ' / ' + esc(p.section) +
              ' &mdash; ' + p.count + ' student(s)</div>';
          });
        }
        if (r.errors && r.errors.length) {
          r.errors.forEach(function (e) {
            html += '<div class="etb-progress-item etb-progress-err">' +
              '<i class="fa fa-times"></i> ' + esc(e.class) + ' / ' + esc(e.section) +
              ' &mdash; ' + esc(e.reason) + '</div>';
          });
        }

        var summary = r.message || 'Complete.';
        html = '<div class="etb-progress-item etb-progress-info"><b>' + esc(summary) + '</b></div>' + html;
        bulkProgress.innerHTML = html;
      })
      .catch(function () {
        bulkRun.disabled = false;
        bulkRun.innerHTML = '<i class="fa fa-cogs"></i> Compute';
        bulkProgress.innerHTML = '<div class="etb-progress-item etb-progress-err"><i class="fa fa-times"></i> Network error.</div>';
      });
  }

})();
</script>
