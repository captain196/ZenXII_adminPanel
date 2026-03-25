<?php defined('BASEPATH') OR exit('No direct script access allowed');
$flashError = $this->session->flashdata('error');
?>

<div class="content-wrapper">
<div class="rme-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="rme-header">
    <div>
      <div class="rme-page-title"><i class="fa fa-pencil-square-o"></i> Marks Entry</div>
      <ol class="rme-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('result') ?>">Results</a></li>
        <li>Marks Entry</li>
      </ol>
    </div>
  </div>

  <?php if ($flashError): ?>
  <div class="rme-alert rme-alert-err"><?= htmlspecialchars($flashError) ?></div>
  <?php endif; ?>

  <!-- ── Selectors ────────────────────────────────────────────────── -->
  <div class="rme-selectors">
    <div class="rme-form-group">
      <label class="rme-label">Exam</label>
      <select id="rmeExamSel" class="rme-select">
        <option value="">-- Select Exam --</option>
        <?php foreach ($exams as $ex): ?>
        <option value="<?= htmlspecialchars($ex['id']) ?>"
          <?= ($examId === $ex['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?> (<?= htmlspecialchars($ex['id']) ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="rme-form-group">
      <label class="rme-label">Class</label>
      <select id="rmeClassSel" class="rme-select" disabled>
        <option value="">-- Select Class --</option>
        <?php foreach ($structure as $ck => $sections): ?>
        <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="rme-form-group">
      <label class="rme-label">Section</label>
      <select id="rmeSectionSel" class="rme-select" disabled>
        <option value="">-- Select Section --</option>
      </select>
    </div>

    <button id="rmeLoadBtn" class="rme-btn-primary" disabled>
      <i class="fa fa-search"></i> Load Subjects
    </button>
  </div>

  <!-- ── Subject grid ─────────────────────────────────────────────── -->
  <div id="rmeSubjectGrid" class="rme-subject-grid" style="display:none;"></div>

  <div id="rmeGridEmpty" style="display:none;" class="rme-empty">
    <i class="fa fa-exclamation-circle"></i>
    <p>No templates found for this class/section in this exam.<br>
      <a href="<?= base_url('result/template_designer') ?>">Design templates first.</a>
    </p>
  </div>

  <div id="rmeGridLoading" style="display:none;" class="rme-loading">
    <i class="fa fa-spinner fa-spin"></i> Loading subjects…
  </div>

</div><!-- /.rme-wrap -->
</div><!-- /.content-wrapper -->

<script>
(function () {
  'use strict';

  var STRUCTURE = <?= json_encode($structure) ?>;
  var EXAM_URL  = '<?= base_url('result/marks_sheet') ?>/';
  var STATUS_URL = '<?= base_url('result/get_exam_status') ?>';

  var examSel    = document.getElementById('rmeExamSel');
  var classSel   = document.getElementById('rmeClassSel');
  var sectionSel = document.getElementById('rmeSectionSel');
  var loadBtn    = document.getElementById('rmeLoadBtn');
  var grid       = document.getElementById('rmeSubjectGrid');
  var gridEmpty  = document.getElementById('rmeGridEmpty');
  var gridLoad   = document.getElementById('rmeGridLoading');

  function checkReady() {
    loadBtn.disabled = !(examSel.value && classSel.value && sectionSel.value);
  }

  examSel.addEventListener('change', function () {
    classSel.disabled = !this.value;
    checkReady();
  });

  classSel.addEventListener('change', function () {
    var sections = STRUCTURE[this.value] || [];
    sectionSel.innerHTML = '<option value="">-- Select Section --</option>';
    sections.forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = 'Section ' + s; opt.textContent = 'Section ' + s;
      sectionSel.appendChild(opt);
    });
    sectionSel.disabled = sections.length === 0;
    grid.style.display = 'none';
    gridEmpty.style.display = 'none';
    checkReady();
  });

  sectionSel.addEventListener('change', function () {
    grid.style.display = 'none';
    gridEmpty.style.display = 'none';
    checkReady();
  });

  loadBtn.addEventListener('click', loadSubjects);

  function loadSubjects() {
    var examId     = examSel.value;
    var classKey   = classSel.value;
    var sectionKey = sectionSel.value;

    grid.style.display      = 'none';
    gridEmpty.style.display = 'none';
    gridLoad.style.display  = '';

    // Fetch subjects from exam's get_subjects, then check status for each
    var subjectsUrl = '<?= base_url('exam/get_subjects') ?>?class=' + encodeURIComponent(classKey);

    Promise.all([
      fetch(subjectsUrl).then(function (r) { return r.json(); }),
      fetch(STATUS_URL + '?examId=' + encodeURIComponent(examId)
          + '&classKey=' + encodeURIComponent(classKey)
          + '&sectionKey=' + encodeURIComponent(sectionKey)).then(function (r) { return r.json(); }),
    ]).then(function (results) {
      var subjects  = results[0].subjects || [];
      var statusObj = (results[1].status) || {};

      gridLoad.style.display = 'none';

      if (!subjects.length) {
        gridEmpty.style.display = '';
        return;
      }

      // Build per-subject mark counts by fetching marks
      var marksCount  = statusObj.marks  || 0; // # subjects with marks
      var templateCount = statusObj.templates || 0;

      // We need per-subject info — fetch individual mark status
      // For simplicity: show each subject with link to marks sheet.
      // The status endpoint returns aggregated counts; we show progress indicator.
      grid.innerHTML = '';

      subjects.forEach(function (subj) {
        var card = document.createElement('div');
        card.className = 'rme-subj-card';
        var href = EXAM_URL
          + encodeURIComponent(examId) + '/'
          + encodeURIComponent(classKey) + '/'
          + encodeURIComponent(sectionKey) + '/'
          + encodeURIComponent(subj);

        card.innerHTML = '<div class="rme-subj-icon"><i class="fa fa-book"></i></div>'
          + '<div class="rme-subj-name">' + escHtml(subj) + '</div>'
          + '<a href="' + href + '" class="rme-subj-btn">'
          + '<i class="fa fa-pencil"></i> Enter Marks</a>';
        grid.appendChild(card);
      });

      grid.style.display = '';
      if (grid.children.length === 0) {
        gridEmpty.style.display = '';
      }
    }).catch(function () {
      gridLoad.style.display = 'none';
      gridEmpty.style.display = '';
    });
  }

  function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  // Auto-load if examId in URL
  <?php if ($examId): ?>
  window.addEventListener('DOMContentLoaded', function () {
    examSel.value = '<?= addslashes($examId) ?>';
    classSel.disabled = false;
    checkReady();
  });
  <?php endif; ?>
})();
</script>

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Marks Entry — .rme-*
═══════════════════════════════════════════════════════════ */
.rme-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 56px; }

.rme-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
.rme-page-title { font-size: 1.4rem; font-weight: 700; color: var(--t1); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.rme-page-title i { color: var(--gold); }
.rme-breadcrumb { list-style: none; margin: 0; padding: 0; display: flex; gap: 6px; font-size: .83rem; color: var(--t3); }
.rme-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.rme-breadcrumb a { color: var(--gold); text-decoration: none; }

.rme-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: .9rem; }
.rme-alert-err { background: rgba(239,68,68,.1); color: #dc2626; border: 1px solid rgba(239,68,68,.3); }

/* Selectors */
.rme-selectors {
  display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  padding: 18px 20px; margin-bottom: 24px;
}
.rme-form-group { flex: 1; min-width: 170px; }
.rme-label { display: block; font-size: .82rem; font-weight: 600; color: var(--t2); margin-bottom: 5px; }
.rme-select {
  width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 7px;
  background: var(--bg3); color: var(--t1); font-size: .88rem; outline: none;
}
.rme-select:focus { border-color: var(--gold); }
.rme-btn-primary {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 20px; background: var(--gold); color: #fff; border: none;
  border-radius: 8px; font-size: .9rem; font-weight: 600; cursor: pointer;
  white-space: nowrap; align-self: flex-end;
}
.rme-btn-primary:hover { background: var(--gold2); }
.rme-btn-primary:disabled { opacity: .5; cursor: not-allowed; }

/* Subject grid */
.rme-subject-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;
}
.rme-subj-card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  padding: 20px 16px; display: flex; flex-direction: column; align-items: center;
  text-align: center; gap: 10px; transition: box-shadow .18s;
}
.rme-subj-card:hover { box-shadow: 0 4px 16px var(--gold-dim); }
.rme-subj-icon { font-size: 2rem; color: var(--gold); }
.rme-subj-name { font-size: 1rem; font-weight: 700; color: var(--t1); }
.rme-subj-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px; background: var(--gold); color: #fff;
  border-radius: 7px; font-size: .85rem; font-weight: 600; text-decoration: none;
  transition: background .15s;
}
.rme-subj-btn:hover { background: var(--gold2); }

/* Loading / Empty */
.rme-loading { text-align: center; padding: 40px; color: var(--t3); font-size: 1.1rem; }
.rme-empty   { text-align: center; padding: 40px; color: var(--t3); }
.rme-empty i { font-size: 2.5rem; color: var(--border); display: block; margin-bottom: 12px; }
.rme-empty a { color: var(--gold); }
</style>
