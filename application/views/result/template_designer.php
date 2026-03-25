<?php defined('BASEPATH') OR exit('No direct script access allowed');
$flashError = $this->session->flashdata('error');
?>

<div class="content-wrapper">
<div class="rt-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="rt-header">
    <div>
      <div class="rt-page-title"><i class="fa fa-sliders"></i> Template Designer</div>
      <ol class="rt-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('result') ?>">Results</a></li>
        <li>Template Designer</li>
      </ol>
    </div>
  </div>

  <?php if ($flashError): ?>
  <div class="rt-alert rt-alert-err"><?= htmlspecialchars($flashError) ?></div>
  <?php endif; ?>

  <div class="rt-layout">

    <!-- ── LEFT: Selectors ──────────────────────────────────────────── -->
    <div class="rt-panel rt-panel-left">
      <div class="rt-panel-title"><i class="fa fa-filter"></i> Select</div>

      <div class="rt-form-group">
        <label class="rt-label">Exam</label>
        <select id="rtExamSel" class="rt-select">
          <option value="">-- Choose Exam --</option>
          <?php foreach ($exams as $ex): ?>
          <option value="<?= htmlspecialchars($ex['id']) ?>"
            <?= ($examId === $ex['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?>
            (<?= htmlspecialchars($ex['id']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="rt-form-group">
        <label class="rt-label">Class</label>
        <select id="rtClassSel" class="rt-select" disabled>
          <option value="">-- Choose Class --</option>
          <?php foreach ($structure as $ck => $sections): ?>
          <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="rt-form-group">
        <label class="rt-label">Section</label>
        <select id="rtSectionSel" class="rt-select" disabled>
          <option value="">-- Choose Section --</option>
        </select>
      </div>

      <div class="rt-form-group">
        <label class="rt-label">Subject</label>
        <select id="rtSubjectSel" class="rt-select" disabled>
          <option value="">-- Choose Subject --</option>
        </select>
      </div>

      <button id="rtLoadBtn" class="rt-btn-primary" disabled>
        <i class="fa fa-download"></i> Load / New
      </button>
    </div>

    <!-- ── CENTER: Component editor ─────────────────────────────────── -->
    <div class="rt-panel rt-panel-center">
      <div class="rt-panel-title">
        <span><i class="fa fa-th-list"></i> Components</span>
        <button id="rtAddRow" class="rt-btn-sm" title="Add component row">
          <i class="fa fa-plus"></i> Add
        </button>
      </div>

      <div id="rtNoSel" class="rt-center-placeholder">
        <i class="fa fa-arrow-left"></i> Select an exam, class, section, and subject first.
      </div>

      <div id="rtEditor" style="display:none;">
        <table class="rt-table" id="rtCompTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Component Name</th>
              <th>Max Marks</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="rtCompBody">
            <!-- rows injected by JS -->
          </tbody>
          <tfoot>
            <tr class="rt-tfoot-row">
              <td colspan="2" style="text-align:right; font-weight:700; color:var(--t2);">Total Max Marks:</td>
              <td id="rtTotalMax" style="font-weight:700; color:var(--gold);">0</td>
              <td></td>
            </tr>
          </tfoot>
        </table>

        <div id="rtSaveRow" class="rt-save-row">
          <div id="rtSaveMsg" class="rt-save-msg" style="display:none;"></div>
          <button id="rtSaveBtn" class="rt-btn-primary">
            <i class="fa fa-save"></i> Save Template
          </button>
        </div>
      </div>
    </div>

    <!-- ── RIGHT: Summary ────────────────────────────────────────────── -->
    <div class="rt-panel rt-panel-right">
      <div class="rt-panel-title"><i class="fa fa-info-circle"></i> Info</div>
      <div id="rtSummary" class="rt-summary">
        <div class="rt-summ-row"><span>Exam:</span><strong id="rtSumExam">—</strong></div>
        <div class="rt-summ-row"><span>Class:</span><strong id="rtSumClass">—</strong></div>
        <div class="rt-summ-row"><span>Section:</span><strong id="rtSumSection">—</strong></div>
        <div class="rt-summ-row"><span>Subject:</span><strong id="rtSumSubject">—</strong></div>
        <hr class="rt-hr">
        <div class="rt-summ-row"><span>Components:</span><strong id="rtSumComponents">0</strong></div>
        <div class="rt-summ-row"><span>Total Max:</span><strong id="rtSumTotal">0</strong></div>
        <hr class="rt-hr">
        <div class="rt-tip">
          <i class="fa fa-lightbulb-o"></i>
          Templates define what components (Theory, Practical, Internal…) are used for
          marks entry. Each component has its own max marks.
        </div>
      </div>
    </div>
  </div><!-- /.rt-layout -->

</div><!-- /.rt-wrap -->
</div><!-- /.content-wrapper -->

<script>
(function () {
  'use strict';

  var STRUCTURE = <?= json_encode($structure) ?>;

  var examSel    = document.getElementById('rtExamSel');
  var classSel   = document.getElementById('rtClassSel');
  var sectionSel = document.getElementById('rtSectionSel');
  var subjectSel = document.getElementById('rtSubjectSel');
  var loadBtn    = document.getElementById('rtLoadBtn');
  var editorDiv  = document.getElementById('rtEditor');
  var noSelDiv   = document.getElementById('rtNoSel');
  var compBody   = document.getElementById('rtCompBody');
  var totalMaxEl = document.getElementById('rtTotalMax');
  var addRowBtn  = document.getElementById('rtAddRow');
  var saveBtn    = document.getElementById('rtSaveBtn');
  var saveMsg    = document.getElementById('rtSaveMsg');

  // Summary elements
  var sumExam    = document.getElementById('rtSumExam');
  var sumClass   = document.getElementById('rtSumClass');
  var sumSection = document.getElementById('rtSumSection');
  var sumSubject = document.getElementById('rtSumSubject');
  var sumComps   = document.getElementById('rtSumComponents');
  var sumTotal   = document.getElementById('rtSumTotal');

  var rowIdx = 0;

  function checkLoadReady() {
    var ok = examSel.value && classSel.value && sectionSel.value && subjectSel.value;
    loadBtn.disabled = !ok;
  }

  examSel.addEventListener('change', function () {
    classSel.disabled = !this.value;
    checkLoadReady();
  });

  classSel.addEventListener('change', function () {
    var sections = STRUCTURE[this.value] || [];
    sectionSel.innerHTML = '<option value="">-- Choose Section --</option>';
    sections.forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = 'Section ' + s;
      opt.textContent = 'Section ' + s;
      sectionSel.appendChild(opt);
    });
    sectionSel.disabled = sections.length === 0;
    subjectSel.innerHTML = '<option value="">-- Choose Subject --</option>';
    subjectSel.disabled = true;
    checkLoadReady();
  });

  sectionSel.addEventListener('change', function () {
    if (!this.value) { subjectSel.disabled = true; checkLoadReady(); return; }
    var classKey = classSel.value;
    // Fetch subjects via AJAX
    fetch('<?= base_url('exam/get_subjects') ?>?class=' + encodeURIComponent(classKey))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        subjectSel.innerHTML = '<option value="">-- Choose Subject --</option>';
        (d.subjects || []).forEach(function (s) {
          var opt = document.createElement('option');
          opt.value = s; opt.textContent = s;
          subjectSel.appendChild(opt);
        });
        subjectSel.disabled = false;
        checkLoadReady();
      });
  });

  subjectSel.addEventListener('change', checkLoadReady);

  loadBtn.addEventListener('click', function () {
    var examId     = examSel.value;
    var classKey   = classSel.value;
    var sectionKey = sectionSel.value;
    var subject    = subjectSel.value;

    updateSummary(examId, classKey, sectionKey, subject);
    showEditor();

    // Try to load existing template
    var url = '<?= base_url('result/get_template') ?>?examId=' + encodeURIComponent(examId)
            + '&classKey=' + encodeURIComponent(classKey)
            + '&sectionKey=' + encodeURIComponent(sectionKey)
            + '&subject=' + encodeURIComponent(subject);

    fetch(url)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        compBody.innerHTML = '';
        rowIdx = 0;
        if (d.template && d.template.Components) {
          var comps = d.template.Components;
          var keys  = Object.keys(comps).sort(function (a, b) { return a - b; });
          keys.forEach(function (k) {
            addRow(comps[k].Name, comps[k].MaxMarks);
          });
        } else {
          addRow('', '');
        }
        recalcTotal();
      });
  });

  addRowBtn.addEventListener('click', function () { addRow('', ''); });

  function addRow(name, max) {
    var idx = rowIdx++;
    var tr  = document.createElement('tr');
    tr.className = 'rt-comp-row';
    tr.dataset.idx = idx;
    tr.innerHTML = '<td class="rt-seq">' + (compBody.children.length + 1) + '</td>'
      + '<td><input type="text" class="rt-input rt-inp-name" placeholder="e.g. Theory" value="'
      + escHtml(name || '') + '"></td>'
      + '<td><input type="number" class="rt-input rt-inp-max" min="1" max="999" placeholder="80" value="'
      + escHtml(String(max || '')) + '"></td>'
      + '<td><button type="button" class="rt-del-row" title="Remove"><i class="fa fa-times"></i></button></td>';
    compBody.appendChild(tr);

    tr.querySelector('.rt-inp-max').addEventListener('input', recalcTotal);
    tr.querySelector('.rt-del-row').addEventListener('click', function () {
      tr.remove(); renumberRows(); recalcTotal();
    });
  }

  function renumberRows() {
    Array.from(compBody.querySelectorAll('.rt-comp-row')).forEach(function (tr, i) {
      tr.querySelector('.rt-seq').textContent = i + 1;
    });
  }

  function recalcTotal() {
    var total = 0;
    compBody.querySelectorAll('.rt-inp-max').forEach(function (inp) {
      total += Math.max(0, parseInt(inp.value) || 0);
    });
    totalMaxEl.textContent = total;
    sumComps.textContent   = compBody.querySelectorAll('.rt-comp-row').length;
    sumTotal.textContent   = total;
  }

  function showEditor() {
    noSelDiv.style.display  = 'none';
    editorDiv.style.display = '';
  }

  function updateSummary(examId, classKey, sectionKey, subject) {
    var examOpt = examSel.options[examSel.selectedIndex];
    sumExam.textContent    = examOpt ? examOpt.textContent.trim() : examId;
    sumClass.textContent   = classKey;
    sumSection.textContent = sectionKey;
    sumSubject.textContent = subject;
  }

  saveBtn.addEventListener('click', function () {
    var examId     = examSel.value;
    var classKey   = classSel.value;
    var sectionKey = sectionSel.value;
    var subject    = subjectSel.value;

    if (!examId || !classKey || !sectionKey || !subject) {
      showMsg('Please select exam, class, section, and subject first.', true);
      return;
    }

    var rows = Array.from(compBody.querySelectorAll('.rt-comp-row'));
    if (!rows.length) {
      showMsg('Add at least one component.', true);
      return;
    }

    var components = [];
    var valid = true;
    rows.forEach(function (tr, i) {
      var name = tr.querySelector('.rt-inp-name').value.trim();
      var max  = parseInt(tr.querySelector('.rt-inp-max').value) || 0;
      if (!name || max < 1) { valid = false; }
      components.push({ name: name, maxMarks: max });
    });

    if (!valid) {
      showMsg('Each component needs a name and max marks ≥ 1.', true);
      return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('examId',     examId);
    fd.append('classKey',   classKey);
    fd.append('sectionKey', sectionKey);
    fd.append('subject',    subject);
    fd.append('components', JSON.stringify(components));
    fd.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

    fetch('<?= base_url('result/save_template') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa fa-save"></i> Save Template';
        if (d.success) {
          showMsg('Template saved! Total max: ' + d.totalMaxMarks, false);
        } else {
          showMsg(d.message || 'Save failed.', true);
        }
      })
      .catch(function () {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fa fa-save"></i> Save Template';
        showMsg('Network error.', true);
      });
  });

  function showMsg(msg, isErr) {
    saveMsg.textContent   = msg;
    saveMsg.className     = 'rt-save-msg ' + (isErr ? 'rt-msg-err' : 'rt-msg-ok');
    saveMsg.style.display = '';
    setTimeout(function () { saveMsg.style.display = 'none'; }, 4000);
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // Pre-load if examId passed in URL
  <?php if ($examId): ?>
  window.addEventListener('DOMContentLoaded', function () {
    examSel.value = '<?= addslashes($examId) ?>';
    classSel.disabled = false;
    checkLoadReady();
  });
  <?php endif; ?>
})();
</script>

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Template Designer — .rt-*
═══════════════════════════════════════════════════════════ */
.rt-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 56px; }

.rt-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.rt-page-title { font-size: 1.4rem; font-weight: 700; color: var(--t1); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.rt-page-title i { color: var(--gold); }
.rt-breadcrumb { list-style: none; margin: 0; padding: 0; display: flex; gap: 6px; font-size: .83rem; color: var(--t3); }
.rt-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.rt-breadcrumb a { color: var(--gold); text-decoration: none; }

.rt-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: .9rem; }
.rt-alert-err { background: rgba(239,68,68,.1); color: #dc2626; border: 1px solid rgba(239,68,68,.3); }

/* Layout */
.rt-layout { display: grid; grid-template-columns: 240px 1fr 230px; gap: 18px; align-items: start; }
@media (max-width: 900px) { .rt-layout { grid-template-columns: 1fr; } }

/* Panels */
.rt-panel { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
.rt-panel-title {
  font-size: .9rem; font-weight: 700; color: var(--t2); margin-bottom: 16px;
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
}
.rt-panel-title i { color: var(--gold); }

/* Forms */
.rt-form-group { margin-bottom: 14px; }
.rt-label { display: block; font-size: .82rem; font-weight: 600; color: var(--t2); margin-bottom: 5px; }
.rt-select {
  width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 7px;
  background: var(--bg3); color: var(--t1); font-size: .88rem; outline: none;
  transition: border-color .15s;
}
.rt-select:focus { border-color: var(--gold); }

/* Buttons */
.rt-btn-primary {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 20px; background: var(--gold); color: #fff; border: none;
  border-radius: 8px; font-size: .9rem; font-weight: 600; cursor: pointer;
  transition: background .18s; width: 100%; justify-content: center;
}
.rt-btn-primary:hover { background: var(--gold2); }
.rt-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.rt-btn-sm {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 12px; background: var(--gold); color: #fff; border: none;
  border-radius: 6px; font-size: .8rem; font-weight: 600; cursor: pointer;
}
.rt-btn-sm:hover { background: var(--gold2); }

/* Table */
.rt-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.rt-table th {
  background: var(--bg3); color: var(--t2); padding: 9px 10px; text-align: left;
  border-bottom: 2px solid var(--border); font-weight: 700;
}
.rt-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.rt-tfoot-row td { background: var(--bg3); padding: 10px; }
.rt-input {
  width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;
  background: var(--bg); color: var(--t1); font-size: .88rem;
}
.rt-input:focus { border-color: var(--gold); outline: none; }
.rt-del-row {
  background: rgba(239,68,68,.1); color: #dc2626; border: none; border-radius: 6px;
  padding: 5px 9px; cursor: pointer; font-size: .85rem;
}
.rt-del-row:hover { background: rgba(239,68,68,.22); }

/* Save row */
.rt-save-row { display: flex; align-items: center; gap: 14px; margin-top: 16px; flex-wrap: wrap; }
.rt-save-msg { font-size: .88rem; padding: 7px 12px; border-radius: 7px; }
.rt-msg-ok  { background: rgba(22,163,74,.1); color: #16a34a; }
.rt-msg-err { background: rgba(239,68,68,.1); color: #dc2626; }

/* Placeholder */
.rt-center-placeholder { text-align: center; padding: 40px 20px; color: var(--t3); font-size: .9rem; }
.rt-center-placeholder i { display: block; font-size: 1.8rem; margin-bottom: 10px; }

/* Summary */
.rt-summ-row { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 10px; font-size: .85rem; }
.rt-summ-row span { color: var(--t3); }
.rt-summ-row strong { color: var(--t1); text-align: right; max-width: 60%; word-break: break-all; }
.rt-hr { border: none; border-top: 1px solid var(--border); margin: 12px 0; }
.rt-tip { font-size: .8rem; color: var(--t3); line-height: 1.5; }
.rt-tip i { color: var(--gold); margin-right: 4px; }
</style>
