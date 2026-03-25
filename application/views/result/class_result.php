<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="rcr-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="rcr-header">
    <div>
      <div class="rcr-page-title"><i class="fa fa-table"></i> Class Results</div>
      <ol class="rcr-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('result') ?>">Results</a></li>
        <li>Class Results</li>
      </ol>
    </div>
  </div>

  <!-- ── Selectors ────────────────────────────────────────────────── -->
  <div class="rcr-selectors">
    <div class="rcr-form-group">
      <label class="rcr-label">Exam</label>
      <select id="rcrExamSel" class="rcr-select">
        <option value="">-- Select Exam --</option>
        <?php foreach ($exams as $ex): ?>
        <option value="<?= htmlspecialchars($ex['id']) ?>"
          <?= ($examId === $ex['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?> (<?= htmlspecialchars($ex['id']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rcr-form-group">
      <label class="rcr-label">Class</label>
      <select id="rcrClassSel" class="rcr-select" disabled>
        <option value="">-- Select Class --</option>
        <?php foreach ($structure as $ck => $sections): ?>
        <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rcr-form-group">
      <label class="rcr-label">Section</label>
      <select id="rcrSectionSel" class="rcr-select" disabled>
        <option value="">-- Select Section --</option>
      </select>
    </div>
    <button id="rcrLoadBtn" class="rcr-btn-primary" disabled>
      <i class="fa fa-search"></i> Load Results
    </button>
    <button id="rcrComputeBtn" class="rcr-btn-outline" disabled title="Compute / Recompute grades and ranks">
      <i class="fa fa-calculator"></i> Compute
    </button>
  </div>

  <!-- ── Result table ─────────────────────────────────────────────── -->
  <div id="rcrLoading" style="display:none;" class="rcr-loading">
    <i class="fa fa-spinner fa-spin"></i> Loading results…
  </div>
  <div id="rcrEmpty" style="display:none;" class="rcr-empty">
    <i class="fa fa-inbox"></i>
    <p>No computed results found. Click <strong>Compute</strong> after entering marks.</p>
  </div>
  <div id="rcrTableWrap" style="display:none;">
    <div class="rcr-table-toolbar">
      <input type="text" id="rcrSearch" class="rcr-search" placeholder="Search student…">
      <span id="rcrCount" class="rcr-count"></span>
      <button id="rcrPrintBtn" class="rcr-btn-sm" onclick="window.print()">
        <i class="fa fa-print"></i> Print
      </button>
      <a id="rcrBatchPrintBtn" href="#" target="_blank" class="rcr-btn-sm" style="text-decoration:none;display:none">
        <i class="fa fa-print"></i> Print All
      </a>
      <a id="rcrBatchPdfBtn" href="#" class="rcr-btn-sm rcr-btn-pdf" style="text-decoration:none;display:none"
         onclick="this.textContent='Generating...';this.style.opacity='0.6';this.style.pointerEvents='none'">
        <i class="fa fa-file-pdf-o"></i> Download All PDFs
      </a>
    </div>
    <div class="rcr-table-outer">
      <table class="rcr-table" id="rcrTable">
        <thead id="rcrThead"></thead>
        <tbody id="rcrTbody"></tbody>
      </table>
    </div>
  </div>

  <div id="rcrComputeMsg" class="rcr-compute-msg" style="display:none;"></div>

</div><!-- /.rcr-wrap -->
</div><!-- /.content-wrapper -->

<script>
(function () {
  'use strict';

  var STRUCTURE   = <?= json_encode($structure) ?>;
  var COMPUTED_URL = '<?= base_url('result') ?>'; // base
  var examSel    = document.getElementById('rcrExamSel');
  var classSel   = document.getElementById('rcrClassSel');
  var sectionSel = document.getElementById('rcrSectionSel');
  var loadBtn    = document.getElementById('rcrLoadBtn');
  var computeBtn = document.getElementById('rcrComputeBtn');
  var loading    = document.getElementById('rcrLoading');
  var emptyDiv   = document.getElementById('rcrEmpty');
  var tableWrap  = document.getElementById('rcrTableWrap');
  var computeMsg = document.getElementById('rcrComputeMsg');
  var searchInp  = document.getElementById('rcrSearch');
  var countEl    = document.getElementById('rcrCount');
  var thead      = document.getElementById('rcrThead');
  var tbody      = document.getElementById('rcrTbody');

  function checkReady() {
    var ok = examSel.value && classSel.value && sectionSel.value;
    loadBtn.disabled    = !ok;
    computeBtn.disabled = !ok;
  }

  examSel.addEventListener('change', function () {
    classSel.disabled = !this.value; checkReady();
  });
  classSel.addEventListener('change', function () {
    var sections = STRUCTURE[this.value] || [];
    sectionSel.innerHTML = '<option value="">-- Select Section --</option>';
    sections.forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = 'Section ' + s; opt.textContent = 'Section ' + s;
      sectionSel.appendChild(opt);
    });
    sectionSel.disabled = !sections.length; checkReady();
  });
  sectionSel.addEventListener('change', checkReady);

  loadBtn.addEventListener('click', loadResults);

  computeBtn.addEventListener('click', function () {
    if (!examSel.value || !classSel.value || !sectionSel.value) return;
    computeBtn.disabled = true;
    computeBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Computing…';

    var fd = new FormData();
    fd.append('examId',     examSel.value);
    fd.append('classKey',   classSel.value);
    fd.append('sectionKey', sectionSel.value);
    fd.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

    fetch('<?= base_url('result/compute_results') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        computeBtn.disabled = false;
        computeBtn.innerHTML = '<i class="fa fa-calculator"></i> Compute';
        showComputeMsg(d.success ? d.message : (d.message || 'Error'), !d.success);
        if (d.success) loadResults();
      })
      .catch(function () {
        computeBtn.disabled = false;
        computeBtn.innerHTML = '<i class="fa fa-calculator"></i> Compute';
        showComputeMsg('Network error.', true);
      });
  });

  function loadResults() {
    var examId     = examSel.value;
    var classKey   = classSel.value;
    var sectionKey = sectionSel.value;

    loading.style.display   = '';
    emptyDiv.style.display  = 'none';
    tableWrap.style.display = 'none';

    // Fetch computed results via AJAX
    fetch('<?= base_url('result/get_class_result_data') ?>?examId=' + encodeURIComponent(examId)
        + '&classKey=' + encodeURIComponent(classKey)
        + '&sectionKey=' + encodeURIComponent(sectionKey))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        loading.style.display = 'none';
        if (!d.students || !d.students.length) {
          emptyDiv.style.display = '';
          return;
        }
        renderTable(d.students, d.subjects || []);
      })
      .catch(function () {
        loading.style.display = 'none';
        emptyDiv.style.display = '';
      });
  }

  var CURRENT_EXAM_ID = '';

  function renderTable(students, subjects) {
    CURRENT_EXAM_ID = examSel.value;

    // Build header
    thead.innerHTML = '';
    var trH = document.createElement('tr');
    ['Rank','Name'].concat(subjects).concat(['Total','Max','%','Grade','P/F','Actions']).forEach(function (col) {
      var th = document.createElement('th');
      th.textContent = col; th.className = 'rcr-th';
      trH.appendChild(th);
    });
    thead.appendChild(trH);

    // Build rows
    tbody.innerHTML = '';
    students.forEach(function (stu) {
      var tr = document.createElement('tr');
      tr.className = 'rcr-row';
      tr.dataset.name = (stu.name || '').toLowerCase();

      function cell(v, cls) {
        var td = document.createElement('td');
        td.textContent = v != null ? v : '—';
        if (cls) td.className = cls;
        return td;
      }

      tr.appendChild(cell(stu.rank,      'rcr-td-rank'));
      tr.appendChild(cell(stu.name,      'rcr-td-name'));

      subjects.forEach(function (s) {
        var sData = (stu.subjects || {})[s] || {};
        var td = document.createElement('td');
        td.className = 'rcr-td-subj';
        td.innerHTML = '<span class="rcr-subj-total">' + (sData.Total != null ? sData.Total : '—') + '</span>'
          + '<span class="rcr-subj-max">/' + (sData.MaxMarks || '—') + '</span>';
        tr.appendChild(td);
      });

      tr.appendChild(cell(stu.total,    'rcr-td-total'));
      tr.appendChild(cell(stu.maxMarks, 'rcr-td-max'));
      var pctTd = cell(stu.pct != null ? stu.pct + '%' : '—', 'rcr-td-pct');
      tr.appendChild(pctTd);
      var gradeTd = cell(stu.grade, 'rcr-td-grade');
      tr.appendChild(gradeTd);
      var pfTd = cell(stu.passFail, 'rcr-td-pf');
      pfTd.classList.add(stu.passFail === 'Pass' ? 'rcr-pf-pass' : 'rcr-pf-fail');
      tr.appendChild(pfTd);

      // Actions cell — Report Card + Student Result links
      var actTd = document.createElement('td');
      actTd.className = 'rcr-td-actions';
      var rcUrl  = '<?= base_url('result/report_card') ?>/' + encodeURIComponent(stu.uid) + '/' + encodeURIComponent(CURRENT_EXAM_ID);
      var srUrl  = '<?= base_url('result/student_result') ?>/' + encodeURIComponent(stu.uid);
      var pdfUrl = '<?= base_url('result/download_pdf') ?>/' + encodeURIComponent(stu.uid) + '/' + encodeURIComponent(CURRENT_EXAM_ID);
      actTd.innerHTML =
        '<a href="' + rcUrl + '" target="_blank" class="rcr-act-btn rcr-act-rc" title="Print Report Card">'
        + '<i class="fa fa-print"></i></a>'
        + '<a href="' + pdfUrl + '" class="rcr-act-btn rcr-act-pdf" title="Download PDF">'
        + '<i class="fa fa-file-pdf-o"></i></a>'
        + '<a href="' + srUrl + '" class="rcr-act-btn rcr-act-sr" title="Student Result">'
        + '<i class="fa fa-bar-chart"></i></a>';
      tr.appendChild(actTd);

      tbody.appendChild(tr);
    });

    tableWrap.style.display = '';
    updateCount();

    // Update batch buttons with correct URLs
    var batchPrintUrl = '<?= base_url('result/batch_report_cards') ?>/'
      + encodeURIComponent(CURRENT_EXAM_ID) + '/'
      + encodeURIComponent(classSel.value) + '/'
      + encodeURIComponent(sectionSel.value);
    var batchPdfUrl = '<?= base_url('result/download_batch_pdf') ?>/'
      + encodeURIComponent(CURRENT_EXAM_ID) + '/'
      + encodeURIComponent(classSel.value) + '/'
      + encodeURIComponent(sectionSel.value);

    var batchPrintBtn = document.getElementById('rcrBatchPrintBtn');
    var batchPdfBtn   = document.getElementById('rcrBatchPdfBtn');
    batchPrintBtn.href = batchPrintUrl;
    batchPrintBtn.style.display = '';
    batchPdfBtn.href = batchPdfUrl;
    batchPdfBtn.style.display = '';
  }

  searchInp.addEventListener('input', function () {
    var q = this.value.toLowerCase();
    Array.from(tbody.querySelectorAll('.rcr-row')).forEach(function (tr) {
      tr.style.display = (!q || (tr.dataset.name || '').indexOf(q) !== -1) ? '' : 'none';
    });
    updateCount();
  });

  function updateCount() {
    var visible = Array.from(tbody.querySelectorAll('.rcr-row')).filter(function (r) {
      return r.style.display !== 'none';
    }).length;
    countEl.textContent = visible + ' student' + (visible !== 1 ? 's' : '');
  }

  function showComputeMsg(msg, isErr) {
    computeMsg.textContent   = msg;
    computeMsg.className     = 'rcr-compute-msg ' + (isErr ? 'rcr-msg-err' : 'rcr-msg-ok');
    computeMsg.style.display = '';
    setTimeout(function () { computeMsg.style.display = 'none'; }, 5000);
  }

  // Pre-select if examId given
  <?php if ($examId): ?>
  examSel.value = '<?= addslashes($examId) ?>';
  classSel.disabled = false;
  checkReady();
  <?php endif; ?>
})();
</script>

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Class Result — .rcr-*
═══════════════════════════════════════════════════════════ */
.rcr-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 56px; }

.rcr-header { margin-bottom: 24px; }
.rcr-page-title { font-size: 1.4rem; font-weight: 700; color: var(--t1); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.rcr-page-title i { color: var(--gold); }
.rcr-breadcrumb { list-style: none; margin: 0; padding: 0; display: flex; gap: 6px; font-size: .83rem; color: var(--t3); }
.rcr-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.rcr-breadcrumb a { color: var(--gold); text-decoration: none; }

/* Selectors */
.rcr-selectors {
  display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  padding: 18px 20px; margin-bottom: 24px;
}
.rcr-form-group { flex: 1; min-width: 170px; }
.rcr-label { display: block; font-size: .82rem; font-weight: 600; color: var(--t2); margin-bottom: 5px; }
.rcr-select {
  width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 7px;
  background: var(--bg3); color: var(--t1); font-size: .88rem;
}
.rcr-btn-primary {
  display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px;
  background: var(--gold); color: #fff; border: none; border-radius: 8px;
  font-size: .9rem; font-weight: 600; cursor: pointer; align-self: flex-end;
}
.rcr-btn-primary:hover { background: var(--gold2); }
.rcr-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.rcr-btn-outline {
  display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px;
  background: transparent; color: var(--gold); border: 1.5px solid var(--gold);
  border-radius: 8px; font-size: .9rem; font-weight: 600; cursor: pointer; align-self: flex-end;
}
.rcr-btn-outline:hover { background: var(--gold-dim); }
.rcr-btn-outline:disabled { opacity: .5; cursor: not-allowed; }

/* Table toolbar */
.rcr-table-toolbar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
.rcr-search {
  flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 8px; background: var(--bg2); color: var(--t1); font-size: .88rem;
}
.rcr-search:focus { border-color: var(--gold); outline: none; }
.rcr-count { font-size: .82rem; color: var(--t3); white-space: nowrap; }
.rcr-btn-sm {
  display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px;
  background: var(--bg3); border: 1px solid var(--border); border-radius: 7px;
  color: var(--t2); font-size: .85rem; cursor: pointer;
}
.rcr-btn-sm:hover { background: var(--gold-dim); color: var(--gold); }

/* Table */
.rcr-table-outer { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }
.rcr-table { width: 100%; border-collapse: collapse; font-size: .84rem; min-width: 700px; }
.rcr-th {
  background: var(--bg3); color: var(--t2); padding: 10px; text-align: center;
  border-bottom: 2px solid var(--border); font-weight: 700; white-space: nowrap;
  position: sticky; top: 0;
}
.rcr-row td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.rcr-row:last-child td { border-bottom: none; }
.rcr-row:hover td { background: var(--gold-dim); }

.rcr-td-rank  { text-align: center; font-weight: 700; color: var(--gold); }
.rcr-td-name  { font-weight: 600; color: var(--t1); }
.rcr-td-subj  { text-align: center; white-space: nowrap; }
.rcr-subj-total { font-weight: 600; color: var(--t1); }
.rcr-subj-max   { font-size: .75rem; color: var(--t3); }
.rcr-td-total { text-align: center; font-weight: 700; color: var(--t1); }
.rcr-td-max   { text-align: center; color: var(--t3); }
.rcr-td-pct   { text-align: center; font-weight: 600; }
.rcr-td-grade { text-align: center; font-weight: 700; color: var(--gold); }
.rcr-td-pf    { text-align: center; font-weight: 700; }
.rcr-pf-pass  { color: #16a34a; }
.rcr-pf-fail  { color: #dc2626; }

.rcr-td-actions { text-align: center; white-space: nowrap; }
.rcr-act-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border-radius: 6px; font-size: .82rem;
  text-decoration: none; margin: 0 2px; transition: background .15s;
}
.rcr-act-rc { background: rgba(15,118,110,.12); color: #0f766e; }
.rcr-act-rc:hover { background: #0f766e; color: #fff; }
.rcr-act-sr { background: rgba(37,99,235,.10); color: #2563eb; }
.rcr-act-sr:hover { background: #2563eb; color: #fff; }
.rcr-act-pdf { background: rgba(220,38,38,.10); color: #dc2626; }
.rcr-act-pdf:hover { background: #dc2626; color: #fff; }
.rcr-btn-pdf { background: rgba(220,38,38,.08); color: #dc2626; border: 1px solid #fca5a5; }
.rcr-btn-pdf:hover { background: #dc2626; color: #fff; }

/* States */
.rcr-loading { text-align: center; padding: 40px; color: var(--t3); font-size: 1.05rem; }
.rcr-empty   { text-align: center; padding: 40px; color: var(--t3); }
.rcr-empty i { font-size: 2.5rem; color: var(--border); display: block; margin-bottom: 12px; }

.rcr-compute-msg { padding: 10px 16px; border-radius: 8px; margin-top: 14px; font-size: .88rem; }
.rcr-msg-ok  { background: rgba(22,163,74,.1); color: #16a34a; }
.rcr-msg-err { background: rgba(239,68,68,.1); color: #dc2626; }

@media print {
  .rcr-selectors, .rcr-header, .rcr-table-toolbar, .rcr-btn-sm { display: none !important; }
  .rcr-wrap { padding: 0; }
  .rcr-table-outer { border: none; }
  .rcr-th { background: #f5f5f5 !important; color: #000 !important; }
}
</style>
