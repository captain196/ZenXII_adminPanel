<?php defined('BASEPATH') OR exit('No direct script access allowed');

$components  = $template['Components'] ?? [];
ksort($components);
$totalMaxAll = (int) ($template['TotalMaxMarks'] ?? 0);
$scale       = $exam['GradingScale'] ?? 'Percentage';
$passingPct  = (int) ($exam['PassingPercent'] ?? 33);
?>

<div class="content-wrapper">
<div class="rms-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="rms-header">
    <div>
      <div class="rms-page-title">
        <i class="fa fa-table"></i>
        <?= htmlspecialchars($subject) ?> — <?= htmlspecialchars($exam['Name'] ?? $examId) ?>
      </div>
      <ol class="rms-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('result') ?>">Results</a></li>
        <li><a href="<?= base_url('result/marks_entry/' . urlencode($examId)) ?>">Marks Entry</a></li>
        <li><?= htmlspecialchars($subject) ?></li>
      </ol>
    </div>
    <div class="rms-header-meta">
      <span class="rms-meta-pill"><i class="fa fa-tag"></i> <?= htmlspecialchars($examId) ?></span>
      <span class="rms-meta-pill"><i class="fa fa-users"></i> <?= htmlspecialchars($classKey) ?> / <?= htmlspecialchars($sectionKey) ?></span>
      <span class="rms-meta-pill"><i class="fa fa-graduation-cap"></i> <?= htmlspecialchars($scale) ?> | Pass ≥ <?= $passingPct ?>%</span>
    </div>
  </div>

  <!-- ── Action bar ───────────────────────────────────────────────── -->
  <div class="rms-action-bar">
    <div id="rmsSaveMsg" class="rms-save-msg" style="display:none;"></div>
    <button id="rmsSaveBtn" class="rms-btn-save">
      <i class="fa fa-save"></i> Save All Marks
    </button>
  </div>

  <!-- ── Marks table ──────────────────────────────────────────────── -->
  <div class="rms-table-wrapper">
    <table class="rms-table" id="rmsTable">
      <thead>
        <tr class="rms-thead-main">
          <th class="rms-th-no">#</th>
          <th class="rms-th-name">Student Name</th>
          <?php foreach ($components as $comp): ?>
          <th class="rms-th-comp" title="<?= htmlspecialchars($comp['Name']) ?>">
            <?= htmlspecialchars($comp['Name']) ?><br>
            <small class="rms-max-hint">/ <?= (int)$comp['MaxMarks'] ?></small>
          </th>
          <?php endforeach; ?>
          <th class="rms-th-total">Total<br><small class="rms-max-hint">/ <?= $totalMaxAll ?></small></th>
          <th class="rms-th-grade">Grade</th>
          <th class="rms-th-pf">P/F</th>
          <th class="rms-th-absent">Absent</th>
        </tr>
      </thead>
      <tbody id="rmsBody">
        <?php if (empty($studentList)): ?>
        <tr><td colspan="<?= 5 + count($components) ?>" class="rms-no-stu">
          No students found in <?= htmlspecialchars($classKey) ?> / <?= htmlspecialchars($sectionKey) ?>.
        </td></tr>
        <?php else: ?>
        <?php $sno = 0; foreach ($studentList as $uid => $name):
          $sno++;
          $existing = $existingMarks[$uid] ?? [];
          $absent   = !empty($existing['Absent']);
        ?>
        <tr class="rms-row <?= $absent ? 'rms-row-absent' : '' ?>"
            data-uid="<?= htmlspecialchars($uid) ?>"
            data-maxall="<?= $totalMaxAll ?>">
          <td class="rms-td-no"><?= $sno ?></td>
          <td class="rms-td-name"><?= htmlspecialchars(is_string($name) ? $name : $uid) ?></td>
          <?php foreach ($components as $ci => $comp):
            $compName = $comp['Name'];
            $compMax  = (int)$comp['MaxMarks'];
            $val      = isset($existing[$compName]) ? (int)$existing[$compName] : '';
          ?>
          <td class="rms-td-comp">
            <input type="number" class="rms-input rms-comp-inp"
                   data-max="<?= $compMax ?>"
                   data-comp="<?= htmlspecialchars($compName) ?>"
                   min="0" max="<?= $compMax ?>"
                   value="<?= $absent ? '' : $val ?>"
                   placeholder="0"
                   <?= $absent ? 'disabled' : '' ?>>
          </td>
          <?php endforeach; ?>
          <td class="rms-td-total">
            <span class="rms-total-val"><?= $absent ? '0' : (isset($existing['Total']) ? (int)$existing['Total'] : 0) ?></span>
          </td>
          <td class="rms-td-grade">
            <span class="rms-grade-val">—</span>
          </td>
          <td class="rms-td-pf">
            <span class="rms-pf-val <?= $absent ? 'rms-pf-fail' : '' ?>">
              <?= $absent ? 'Fail' : '—' ?>
            </span>
          </td>
          <td class="rms-td-absent">
            <input type="checkbox" class="rms-absent-chk"
                   <?= $absent ? 'checked' : '' ?>>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- ── Bottom save + shortcut ────────────────────────────────────── -->
  <div class="rms-action-bar rms-action-bot">
    <button id="rmsSaveBtnBot" class="rms-btn-save">
      <i class="fa fa-save"></i> Save All Marks
    </button>
    <a href="<?= base_url('result/class_result/' . urlencode($examId)) ?>"
       class="rms-btn-view-results" title="Go to Class Results to compute grades & print report cards">
      <i class="fa fa-table"></i> View / Compute Results
    </a>
  </div>

</div><!-- /.rms-wrap -->
</div><!-- /.content-wrapper -->

<script>
(function () {
  'use strict';

  // ── Grading constants — thresholds auto-generated from Exam_engine.php ──
  var GRADING_SCALE    = <?= json_encode($scale) ?>;
  var PASSING_PCT      = <?= json_encode($passingPct) ?>;
  var GRADE_THRESHOLDS = <?= json_encode($this->exam_engine->get_grade_thresholds()) ?>;

  /** computeGrade — driven by PHP-generated thresholds (never out of sync) */
  function computeGrade(pct, scale) {
    var thresholds = GRADE_THRESHOLDS[scale];
    if (!thresholds || !thresholds.length) return '';
    for (var i = 0; i < thresholds.length; i++) {
      if (pct >= thresholds[i][0]) return thresholds[i][1];
    }
    return thresholds[thresholds.length - 1][1];
  }

  /** computePassFail — mirrors PHP Exam_engine::compute_pass_fail() */
  function computePassFail(pct, passingPct) {
    return pct >= passingPct ? 'Pass' : 'Fail';
  }

  function recalcRow(tr) {
    var absent = tr.querySelector('.rms-absent-chk').checked;
    var inputs = tr.querySelectorAll('.rms-comp-inp');
    var maxAll = parseInt(tr.dataset.maxall) || 0;
    var total  = 0;

    inputs.forEach(function (inp) {
      if (absent) {
        inp.value    = '';
        inp.disabled = true;
      } else {
        inp.disabled = false;
        var v = parseInt(inp.value) || 0;
        var m = parseInt(inp.dataset.max) || 0;
        if (v > m) { inp.value = m; v = m; }
        if (v < 0) { inp.value = 0; v = 0; }
        total += v;
      }
    });

    var pct      = absent ? 0 : (maxAll > 0 ? (total / maxAll * 100) : 0);
    var grade    = absent ? 'AB' : computeGrade(pct, GRADING_SCALE);
    var pf       = absent ? 'Fail' : computePassFail(pct, PASSING_PCT);

    tr.querySelector('.rms-total-val').textContent = absent ? '0' : total;
    tr.querySelector('.rms-grade-val').textContent  = grade || '—';

    var pfEl = tr.querySelector('.rms-pf-val');
    pfEl.textContent = absent ? 'Fail' : (pf || '—');
    pfEl.className   = 'rms-pf-val ' + (pf === 'Fail' || absent ? 'rms-pf-fail' : 'rms-pf-pass');

    tr.classList.toggle('rms-row-absent', absent);
  }

  // Init all rows
  document.querySelectorAll('#rmsBody .rms-row').forEach(function (tr) {
    recalcRow(tr);

    tr.querySelectorAll('.rms-comp-inp').forEach(function (inp) {
      inp.addEventListener('input', function () { recalcRow(tr); });
    });

    tr.querySelector('.rms-absent-chk').addEventListener('change', function () {
      recalcRow(tr);
    });
  });

  // ── Save ────────────────────────────────────────────────────────────
  function doSave() {
    var rows    = Array.from(document.querySelectorAll('#rmsBody .rms-row'));
    var students = [];

    rows.forEach(function (tr) {
      var uid    = tr.dataset.uid;
      var absent = tr.querySelector('.rms-absent-chk').checked;
      var marks  = {};
      tr.querySelectorAll('.rms-comp-inp').forEach(function (inp) {
        marks[inp.dataset.comp] = absent ? 0 : (parseInt(inp.value) || 0);
      });
      var total = absent ? 0 : parseInt(tr.querySelector('.rms-total-val').textContent) || 0;
      students.push({ userId: uid, absent: absent, marks: marks, total: total });
    });

    var payload = {
      examId:     '<?= addslashes($examId) ?>',
      classKey:   '<?= addslashes($classKey) ?>',
      sectionKey: '<?= addslashes($sectionKey) ?>',
      subject:    '<?= addslashes($subject) ?>',
      students:   students,
    };

    setSaving(true);

    var fd = new FormData();
    fd.append('marksData', JSON.stringify(payload));
    fd.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

    fetch('<?= base_url('result/save_marks') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        setSaving(false);
        showMsg(d.success ? d.message : (d.message || 'Save failed.'), !d.success);
      })
      .catch(function () {
        setSaving(false);
        showMsg('Network error. Please try again.', true);
      });
  }

  document.getElementById('rmsSaveBtn').addEventListener('click', doSave);
  document.getElementById('rmsSaveBtnBot').addEventListener('click', doSave);

  function setSaving(on) {
    var btns = [document.getElementById('rmsSaveBtn'), document.getElementById('rmsSaveBtnBot')];
    btns.forEach(function (b) {
      b.disabled = on;
      b.innerHTML = on
        ? '<i class="fa fa-spinner fa-spin"></i> Saving…'
        : '<i class="fa fa-save"></i> Save All Marks';
    });
  }

  function showMsg(msg, isErr) {
    var el = document.getElementById('rmsSaveMsg');
    el.textContent   = msg;
    el.className     = 'rms-save-msg ' + (isErr ? 'rms-msg-err' : 'rms-msg-ok');
    el.style.display = '';
    setTimeout(function () { el.style.display = 'none'; }, 5000);
  }
})();
</script>

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Marks Sheet — .rms-*
═══════════════════════════════════════════════════════════ */
.rms-wrap { max-width: 100%; padding: 20px 16px 56px; }

.rms-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
.rms-page-title { font-size: 1.3rem; font-weight: 700; color: var(--t1); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.rms-page-title i { color: var(--gold); }
.rms-breadcrumb { list-style: none; margin: 0; padding: 0; display: flex; gap: 6px; font-size: .83rem; color: var(--t3); }
.rms-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.rms-breadcrumb a { color: var(--gold); text-decoration: none; }
.rms-header-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.rms-meta-pill {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 10px; background: var(--gold-dim); color: var(--gold);
  border-radius: 20px; font-size: .78rem; font-weight: 600;
}

/* Action bar */
.rms-action-bar {
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  margin-bottom: 16px;
}
.rms-action-bot { margin-top: 20px; margin-bottom: 0; }
.rms-btn-save {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 9px 22px; background: var(--gold); color: #fff; border: none;
  border-radius: 8px; font-size: .9rem; font-weight: 700; cursor: pointer;
}
.rms-btn-save:hover { background: var(--gold2); }
.rms-btn-save:disabled { opacity: .5; cursor: not-allowed; }
.rms-save-msg { font-size: .88rem; padding: 7px 14px; border-radius: 7px; }
.rms-msg-ok  { background: rgba(22,163,74,.1); color: #16a34a; }
.rms-msg-err { background: rgba(239,68,68,.1); color: #dc2626; }

/* Table wrapper */
.rms-table-wrapper { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }

/* Table */
.rms-table { width: 100%; border-collapse: collapse; font-size: .85rem; min-width: 600px; }
.rms-thead-main th {
  background: var(--bg3); color: var(--t2); padding: 10px 10px;
  border-bottom: 2px solid var(--border); font-weight: 700; white-space: nowrap;
  position: sticky; top: 0; z-index: 2;
}
.rms-max-hint { font-size: .73rem; color: var(--t3); font-weight: 400; }
.rms-th-no    { width: 40px; text-align: center; }
.rms-th-name  { min-width: 160px; }
.rms-th-comp  { min-width: 90px; text-align: center; }
.rms-th-total { min-width: 80px; text-align: center; }
.rms-th-grade { width: 70px; text-align: center; }
.rms-th-pf    { width: 60px; text-align: center; }
.rms-th-absent{ width: 60px; text-align: center; }

.rms-row td { padding: 7px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.rms-row:last-child td { border-bottom: none; }
.rms-row:hover td { background: var(--gold-dim); }
.rms-row-absent td { background: rgba(239,68,68,.05) !important; }

.rms-td-no   { text-align: center; color: var(--t3); font-size: .78rem; }
.rms-td-comp { text-align: center; }
.rms-td-total{ text-align: center; font-weight: 700; color: var(--t1); }
.rms-td-grade{ text-align: center; font-weight: 700; color: var(--gold); }
.rms-td-pf   { text-align: center; }
.rms-td-absent{ text-align: center; }

.rms-input {
  width: 70px; text-align: center; padding: 5px 6px;
  border: 1px solid var(--border); border-radius: 6px;
  background: var(--bg); color: var(--t1); font-size: .85rem;
}
.rms-input:focus { border-color: var(--gold); outline: none; }
.rms-input:disabled { background: var(--bg3); color: var(--t3); }

.rms-pf-pass { color: #16a34a; font-weight: 700; }
.rms-pf-fail { color: #dc2626; font-weight: 700; }

.rms-absent-chk { width: 16px; height: 16px; cursor: pointer; accent-color: var(--gold); }

.rms-btn-view-results {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px; background: transparent; color: var(--gold);
  border: 1.5px solid var(--gold); border-radius: 8px;
  font-size: .9rem; font-weight: 600; text-decoration: none;
  transition: background .15s, color .15s;
}
.rms-btn-view-results:hover { background: var(--gold); color: #fff; }

.rms-no-stu { text-align: center; padding: 40px; color: var(--t3); }
</style>
