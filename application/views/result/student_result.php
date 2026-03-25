<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="rsr-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="rsr-header">
    <div>
      <div class="rsr-page-title"><i class="fa fa-user-circle-o"></i> Student Result</div>
      <ol class="rsr-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('result') ?>">Results</a></li>
        <li>Student Result</li>
      </ol>
    </div>
  </div>

  <!-- ── Student card ─────────────────────────────────────────────── -->
  <div class="rsr-student-card">
    <div class="rsr-avatar"><i class="fa fa-user-circle-o"></i></div>
    <div class="rsr-stu-info">
      <div class="rsr-stu-name"><?= htmlspecialchars($studentName) ?></div>
      <div class="rsr-stu-meta">
        <?php if ($classKey): ?>
        <span><i class="fa fa-building-o"></i> <?= htmlspecialchars($classKey) ?></span>
        <?php endif; ?>
        <?php if ($sectionKey): ?>
        <span><i class="fa fa-object-group"></i> <?= htmlspecialchars($sectionKey) ?></span>
        <?php endif; ?>
        <?php if (!empty($profile['Roll_No'])): ?>
        <span><i class="fa fa-id-card-o"></i> Roll: <?= htmlspecialchars($profile['Roll_No']) ?></span>
        <?php endif; ?>
        <span class="rsr-uid"><i class="fa fa-tag"></i> <?= htmlspecialchars($userId) ?></span>
      </div>
    </div>
    <div class="rsr-stu-actions">
      <a href="<?= base_url('student/student_profile/' . urlencode($userId)) ?>" class="rsr-btn-outline">
        <i class="fa fa-eye"></i> View Profile
      </a>
    </div>
  </div>

  <?php if (empty($exams)): ?>
  <div class="rsr-empty">
    <i class="fa fa-inbox"></i>
    <p>No active exams found.</p>
  </div>
  <?php elseif (empty($results)): ?>
  <div class="rsr-empty">
    <i class="fa fa-bar-chart"></i>
    <p>No computed results yet. Enter marks and click <strong>Compute</strong> in Class Results.</p>
  </div>
  <?php else: ?>

  <!-- ── Tabs ─────────────────────────────────────────────────────── -->
  <div class="rsr-tabs">
    <?php $first = true; foreach ($exams as $ex):
      if (!isset($results[$ex['id']])) continue;
    ?>
    <button class="rsr-tab <?= $first ? 'rsr-tab-active' : '' ?>"
            data-target="rsr-pane-<?= htmlspecialchars($ex['id']) ?>"
            onclick="rsr_switchTab(this)">
      <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?>
    </button>
    <?php $first = false; endforeach; ?>
  </div>

  <!-- ── Panes ────────────────────────────────────────────────────── -->
  <?php $first = true; foreach ($exams as $ex):
    if (!isset($results[$ex['id']])) continue;
    $res = $results[$ex['id']];
  ?>
  <div id="rsr-pane-<?= htmlspecialchars($ex['id']) ?>"
       class="rsr-pane <?= $first ? '' : 'rsr-pane-hidden' ?>">

    <!-- Summary row -->
    <div class="rsr-summary-row">
      <div class="rsr-sum-card">
        <div class="rsr-sum-val"><?= htmlspecialchars($res['TotalMarks'] ?? '—') ?></div>
        <div class="rsr-sum-lbl">Total Marks</div>
      </div>
      <div class="rsr-sum-card">
        <div class="rsr-sum-val"><?= htmlspecialchars($res['MaxMarks'] ?? '—') ?></div>
        <div class="rsr-sum-lbl">Max Marks</div>
      </div>
      <div class="rsr-sum-card rsr-sum-pct">
        <div class="rsr-sum-val"><?= htmlspecialchars($res['Percentage'] ?? '—') ?>%</div>
        <div class="rsr-sum-lbl">Percentage</div>
      </div>
      <div class="rsr-sum-card">
        <div class="rsr-sum-val"><?= htmlspecialchars($res['Grade'] ?? '—') ?></div>
        <div class="rsr-sum-lbl">Grade</div>
      </div>
      <div class="rsr-sum-card">
        <div class="rsr-sum-val rsr-sum-pf rsr-pf-<?= strtolower($res['PassFail'] ?? 'fail') ?>">
          <?= htmlspecialchars($res['PassFail'] ?? '—') ?>
        </div>
        <div class="rsr-sum-lbl">Pass / Fail</div>
      </div>
      <div class="rsr-sum-card">
        <div class="rsr-sum-val"><?= htmlspecialchars($res['Rank'] ?? '—') ?></div>
        <div class="rsr-sum-lbl">Rank</div>
      </div>
    </div>

    <!-- Subject breakdown -->
    <?php if (!empty($res['Subjects'])): ?>
    <div class="rsr-subj-table-wrap">
      <table class="rsr-subj-table">
        <thead>
          <tr>
            <th>Subject</th>
            <th>Total</th>
            <th>Max</th>
            <th>%</th>
            <th>Grade</th>
            <th>Pass/Fail</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($res['Subjects'] as $subj => $sd): ?>
          <tr class="<?= (($sd['Absent'] ?? false) || ($sd['PassFail'] ?? '') === 'Fail') ? 'rsr-row-fail' : '' ?>">
            <td class="rsr-subj-name"><?= htmlspecialchars($subj) ?></td>
            <td class="rsr-subj-num"><?= htmlspecialchars($sd['Total'] ?? '—') ?></td>
            <td class="rsr-subj-num"><?= htmlspecialchars($sd['MaxMarks'] ?? '—') ?></td>
            <td class="rsr-subj-pct"><?= htmlspecialchars($sd['Percentage'] ?? '—') ?>%</td>
            <td class="rsr-subj-grade"><?= htmlspecialchars($sd['Grade'] ?? '—') ?></td>
            <td class="rsr-subj-pf rsr-pf-<?= strtolower($sd['PassFail'] ?? 'fail') ?>">
              <?= htmlspecialchars($sd['PassFail'] ?? '—') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Report card link -->
    <div class="rsr-rc-link">
      <a href="<?= base_url('result/report_card/' . urlencode($userId) . '/' . urlencode($ex['id'])) ?>"
         class="rsr-btn-primary" target="_blank">
        <i class="fa fa-print"></i> Print Report Card
      </a>
    </div>

  </div><!-- /.rsr-pane -->
  <?php $first = false; endforeach; ?>

  <?php endif; ?>

</div><!-- /.rsr-wrap -->
</div><!-- /.content-wrapper -->

<script>
function rsr_switchTab(btn) {
  document.querySelectorAll('.rsr-tab').forEach(function (b) { b.classList.remove('rsr-tab-active'); });
  document.querySelectorAll('.rsr-pane').forEach(function (p) { p.classList.add('rsr-pane-hidden'); });
  btn.classList.add('rsr-tab-active');
  var pane = document.getElementById(btn.dataset.target);
  if (pane) pane.classList.remove('rsr-pane-hidden');
}
</script>

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Student Result — .rsr-*
═══════════════════════════════════════════════════════════ */
.rsr-wrap { max-width: 1000px; margin: 0 auto; padding: 24px 16px 56px; }

.rsr-header { margin-bottom: 24px; }
.rsr-page-title { font-size: 1.4rem; font-weight: 700; color: var(--t1); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.rsr-page-title i { color: var(--gold); }
.rsr-breadcrumb { list-style: none; margin: 0; padding: 0; display: flex; gap: 6px; font-size: .83rem; color: var(--t3); }
.rsr-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.rsr-breadcrumb a { color: var(--gold); text-decoration: none; }

/* Student card */
.rsr-student-card {
  display: flex; align-items: center; gap: 18px;
  background: var(--bg2); border: 1px solid var(--border); border-radius: 14px;
  padding: 20px 24px; margin-bottom: 24px; flex-wrap: wrap;
}
.rsr-avatar { font-size: 3.5rem; color: var(--gold); flex-shrink: 0; }
.rsr-stu-info { flex: 1; min-width: 200px; }
.rsr-stu-name { font-size: 1.4rem; font-weight: 700; color: var(--t1); margin-bottom: 6px; }
.rsr-stu-meta { display: flex; flex-wrap: wrap; gap: 10px 18px; font-size: .84rem; color: var(--t3); }
.rsr-stu-meta span { display: flex; align-items: center; gap: 5px; }
.rsr-stu-meta i { color: var(--gold); }
.rsr-uid { font-family: monospace; font-size: .8rem; }
.rsr-stu-actions { display: flex; gap: 10px; align-items: center; }
.rsr-btn-outline {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
  border: 1.5px solid var(--gold); color: var(--gold); border-radius: 8px;
  font-size: .88rem; font-weight: 600; text-decoration: none;
}
.rsr-btn-outline:hover { background: var(--gold-dim); }

/* Tabs */
.rsr-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.rsr-tab {
  padding: 8px 18px; border: 1.5px solid var(--border); border-radius: 8px;
  background: var(--bg2); color: var(--t2); font-size: .88rem; font-weight: 600;
  cursor: pointer; transition: all .18s;
}
.rsr-tab:hover { border-color: var(--gold); color: var(--gold); }
.rsr-tab-active { background: var(--gold); color: #fff; border-color: var(--gold); }

/* Pane */
.rsr-pane { animation: rsr-fadein .2s ease; }
.rsr-pane-hidden { display: none; }
@keyframes rsr-fadein { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }

/* Summary row */
.rsr-summary-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 24px; }
.rsr-sum-card {
  flex: 1; min-width: 120px; background: var(--bg2); border: 1px solid var(--border);
  border-radius: 12px; padding: 16px 14px; text-align: center;
}
.rsr-sum-val { font-size: 1.6rem; font-weight: 700; color: var(--t1); }
.rsr-sum-lbl { font-size: .78rem; color: var(--t3); margin-top: 4px; }
.rsr-sum-pct .rsr-sum-val { color: var(--gold); }
.rsr-pf-pass { color: #16a34a !important; }
.rsr-pf-fail { color: #dc2626 !important; }

/* Subject table */
.rsr-subj-table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 20px; }
.rsr-subj-table { width: 100%; border-collapse: collapse; font-size: .88rem; min-width: 500px; }
.rsr-subj-table thead th {
  background: var(--bg3); color: var(--t2); padding: 10px 12px;
  border-bottom: 2px solid var(--border); font-weight: 700; text-align: center;
}
.rsr-subj-table thead th:first-child { text-align: left; }
.rsr-subj-table tbody td { padding: 9px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.rsr-subj-table tbody tr:last-child td { border-bottom: none; }
.rsr-subj-table tbody tr:hover td { background: var(--gold-dim); }
.rsr-row-fail { background: rgba(239,68,68,.04); }
.rsr-subj-name  { font-weight: 600; color: var(--t1); }
.rsr-subj-num   { text-align: center; color: var(--t2); }
.rsr-subj-pct   { text-align: center; font-weight: 600; }
.rsr-subj-grade { text-align: center; font-weight: 700; color: var(--gold); }
.rsr-subj-pf    { text-align: center; font-weight: 700; }

/* Report card link */
.rsr-rc-link { display: flex; justify-content: flex-end; }
.rsr-btn-primary {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 22px; background: var(--gold); color: #fff; border-radius: 8px;
  font-size: .9rem; font-weight: 600; text-decoration: none;
  border: none; cursor: pointer;
}
.rsr-btn-primary:hover { background: var(--gold2); }

/* Empty */
.rsr-empty { text-align: center; padding: 48px; color: var(--t3); }
.rsr-empty i { font-size: 3rem; color: var(--border); display: block; margin-bottom: 14px; }
</style>
