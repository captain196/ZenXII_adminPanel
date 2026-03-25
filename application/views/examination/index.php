<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<section class="content">
<div class="exm-wrap">

  <!-- ── Header Bar ─────────────────────────────────────────────── -->
  <div class="exm-header">
    <div>
      <div class="exm-page-title"><i class="fa fa-graduation-cap"></i> Examination Hub</div>
      <ol class="exm-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Examinations</li>
      </ol>
    </div>
    <div class="exm-header-actions">
      <a href="<?= base_url('exam/create') ?>" class="exm-btn-primary">
        <i class="fa fa-plus-circle"></i> Create Exam
      </a>
      <a href="<?= base_url('result/marks_entry') ?>" class="exm-btn-outline">
        <i class="fa fa-pencil-square-o"></i> Enter Marks
      </a>
    </div>
  </div>

  <!-- ── Stats Cards ────────────────────────────────────────────── -->
  <div class="exm-stats-row">
    <div class="exm-stat-card">
      <div class="exm-stat-icon"><i class="fa fa-file-text-o"></i></div>
      <div class="exm-stat-body">
        <div class="exm-stat-count"><?= (int)($stats['total'] ?? 0) ?></div>
        <div class="exm-stat-label">Total Exams</div>
      </div>
    </div>
    <div class="exm-stat-card exm-stat-green">
      <div class="exm-stat-icon"><i class="fa fa-check-circle"></i></div>
      <div class="exm-stat-body">
        <div class="exm-stat-count"><?= (int)($stats['published'] ?? 0) ?></div>
        <div class="exm-stat-label">Published</div>
      </div>
    </div>
    <div class="exm-stat-card exm-stat-gold">
      <div class="exm-stat-icon"><i class="fa fa-trophy"></i></div>
      <div class="exm-stat-body">
        <div class="exm-stat-count"><?= (int)($stats['completed'] ?? 0) ?></div>
        <div class="exm-stat-label">Completed</div>
      </div>
    </div>
    <div class="exm-stat-card exm-stat-amber">
      <div class="exm-stat-icon"><i class="fa fa-pencil"></i></div>
      <div class="exm-stat-body">
        <div class="exm-stat-count"><?= (int)($stats['draft'] ?? 0) ?></div>
        <div class="exm-stat-label">Draft</div>
      </div>
    </div>
  </div>

  <!-- ── Quick Actions Grid ─────────────────────────────────────── -->
  <div class="exm-section-title">
    <i class="fa fa-th-large"></i> Quick Actions
  </div>
  <div class="exm-quick-grid">
    <a href="<?= base_url('exam/create') ?>" class="exm-qa-card">
      <i class="fa fa-plus-circle"></i>
      <span>Create Exam</span>
      <small>Set up a new examination</small>
    </a>
    <a href="<?= base_url('exam') ?>" class="exm-qa-card">
      <i class="fa fa-list-alt"></i>
      <span>Manage Exams</span>
      <small>Edit or delete exams</small>
    </a>
    <a href="<?= base_url('result/marks_entry') ?>" class="exm-qa-card">
      <i class="fa fa-pencil-square-o"></i>
      <span>Marks Entry</span>
      <small>Enter student marks</small>
    </a>
    <a href="<?= base_url('result/class_result') ?>" class="exm-qa-card">
      <i class="fa fa-table"></i>
      <span>Class Results</span>
      <small>View computed results</small>
    </a>
    <a href="<?= base_url('examination/merit_list') ?>" class="exm-qa-card">
      <i class="fa fa-sort-amount-asc"></i>
      <span>Merit Lists</span>
      <small>Rank-wise student lists</small>
    </a>
    <a href="<?= base_url('examination/analytics') ?>" class="exm-qa-card">
      <i class="fa fa-bar-chart"></i>
      <span>Performance Analytics</span>
      <small>Charts and insights</small>
    </a>
    <a href="<?= base_url('examination/tabulation') ?>" class="exm-qa-card">
      <i class="fa fa-th"></i>
      <span>Tabulation Sheets</span>
      <small>Full mark sheets</small>
    </a>
    <a href="<?= base_url('result/cumulative') ?>" class="exm-qa-card">
      <i class="fa fa-line-chart"></i>
      <span>Cumulative Results</span>
      <small>Weighted final result</small>
    </a>
  </div>

  <!-- ── Recent Activity ────────────────────────────────────────── -->
  <div class="exm-section-title">
    <i class="fa fa-clock-o"></i> Recent Activity
    <small><?= count($recentExams) ?> recent exam<?= count($recentExams) !== 1 ? 's' : '' ?></small>
  </div>

  <?php if (empty($recentExams)): ?>
  <div class="exm-card">
    <div class="exm-empty">
      <i class="fa fa-inbox"></i>
      <p>No recent exams. <a href="<?= base_url('exam/create') ?>">Create one</a> to get started.</p>
    </div>
  </div>
  <?php else: ?>
  <div class="exm-card">
    <div class="exm-recent-list">
      <?php foreach ($recentExams as $rx):
        $rxStatus = $rx['Status'] ?? 'Draft';
        if ($rxStatus === 'Published') { $rxStatusCls = 'exm-badge-green'; }
        elseif ($rxStatus === 'Completed') { $rxStatusCls = 'exm-badge-teal'; }
        else { $rxStatusCls = 'exm-badge-amber'; }
        $rxType = $rx['Type'] ?? '';
      ?>
      <a href="<?= base_url('exam/view/' . urlencode($rx['id'] ?? '')) ?>" class="exm-recent-item">
        <div class="exm-recent-info">
          <div class="exm-recent-name"><?= htmlspecialchars($rx['Name'] ?? 'Untitled Exam') ?></div>
          <div class="exm-recent-meta">
            <span><i class="fa fa-tag"></i> <?= htmlspecialchars($rx['id'] ?? '') ?></span>
            <?php if (!empty($rx['StartDate'])): ?>
            <span><i class="fa fa-calendar-o"></i> <?= htmlspecialchars($rx['StartDate']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="exm-recent-badges">
          <?php if ($rxType): ?>
          <span class="exm-badge exm-badge-type"><?= htmlspecialchars($rxType) ?></span>
          <?php endif; ?>
          <span class="exm-badge <?= $rxStatusCls ?>"><?= htmlspecialchars($rxStatus) ?></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Exam Overview Table ────────────────────────────────────── -->
  <div class="exm-section-title">
    <i class="fa fa-file-text-o"></i> All Exams
    <small><?= count($exams) ?> total</small>
  </div>

  <?php if (empty($exams)): ?>
  <div class="exm-card">
    <div class="exm-empty">
      <i class="fa fa-inbox"></i>
      <p>No exams found. <a href="<?= base_url('exam/create') ?>">Create an exam</a> to begin.</p>
    </div>
  </div>
  <?php else: ?>
  <div class="exm-card">
    <div class="exm-table-toolbar">
      <div class="exm-search-box">
        <i class="fa fa-search"></i>
        <input type="text" id="exmSearchInput" placeholder="Search exams..." autocomplete="off">
      </div>
    </div>
    <div class="exm-table-wrap">
      <table class="exm-table" id="exmTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Exam Name</th>
            <th>Type</th>
            <th>Status</th>
            <th>Grading</th>
            <th>Dates</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php $idx = 0; foreach ($exams as $ex):
            $idx++;
            $exStatus = $ex['Status'] ?? 'Draft';
            if ($exStatus === 'Published') { $exStatusCls = 'exm-badge-green'; }
            elseif ($exStatus === 'Completed') { $exStatusCls = 'exm-badge-teal'; }
            else { $exStatusCls = 'exm-badge-amber'; }
          ?>
          <tr class="exm-table-row">
            <td class="exm-td-num"><?= $idx ?></td>
            <td class="exm-td-name"><?= htmlspecialchars($ex['Name'] ?? 'Untitled') ?></td>
            <td><span class="exm-badge exm-badge-type"><?= htmlspecialchars($ex['Type'] ?? '-') ?></span></td>
            <td><span class="exm-badge <?= $exStatusCls ?>"><?= htmlspecialchars($exStatus) ?></span></td>
            <td class="exm-td-grading"><?= htmlspecialchars($ex['GradingScale'] ?? '-') ?></td>
            <td class="exm-td-dates">
              <?php if (!empty($ex['StartDate']) || !empty($ex['EndDate'])): ?>
                <?= htmlspecialchars($ex['StartDate'] ?? '') ?><?= (!empty($ex['StartDate']) && !empty($ex['EndDate'])) ? ' - ' : '' ?><?= htmlspecialchars($ex['EndDate'] ?? '') ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td class="exm-td-actions">
              <a href="<?= base_url('exam/view/' . urlencode($ex['id'] ?? '')) ?>" class="exm-act-btn" title="View">
                <i class="fa fa-eye"></i>
              </a>
              <a href="<?= base_url('result/class_result/' . urlencode($ex['id'] ?? '')) ?>" class="exm-act-btn" title="Results">
                <i class="fa fa-table"></i>
              </a>
              <a href="<?= base_url('examination/analytics') ?>" class="exm-act-btn" title="Analytics">
                <i class="fa fa-bar-chart"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /.exm-wrap -->
</section>
</div><!-- /.content-wrapper -->

<!-- ── Styles ─────────────────────────────────────────────────── -->
<style>
/* ═══════════════════════════════════════════════════════════
   Examination Dashboard — .exm-*
   Production ERP standard — FULL WIDTH layout
═══════════════════════════════════════════════════════════ */
.exm-wrap { margin: 0; padding: 24px 28px 48px; }

/* ── Header ─────────────────────────────────────────────── */
.exm-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  flex-wrap: wrap; gap: 12px; margin-bottom: 26px;
}
.exm-page-title {
  font-size: 1.55rem; font-weight: 700; color: var(--t1);
  display: flex; align-items: center; gap: 10px; margin-bottom: 4px;
  font-family: var(--font-b);
}
.exm-page-title i { color: var(--gold); font-size: 1.3rem; }
.exm-breadcrumb {
  list-style: none; margin: 0; padding: 0; display: flex; gap: 6px;
  font-size: 13px; color: var(--t3);
}
.exm-breadcrumb li + li::before { content: '\203A'; margin-right: 6px; }
.exm-breadcrumb a { color: var(--gold); text-decoration: none; }
.exm-breadcrumb a:hover { text-decoration: underline; }
.exm-header-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

.exm-btn-primary {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 10px 22px; background: var(--gold); color: #fff;
  border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none;
  border: 1.5px solid var(--gold); transition: background var(--ease), box-shadow var(--ease);
}
.exm-btn-primary:hover { background: var(--gold2); box-shadow: 0 4px 14px var(--gold-glow); color: #fff; }

.exm-btn-outline {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 10px 22px; border: 1.5px solid var(--gold); border-radius: 10px;
  color: var(--gold); font-size: 14px; font-weight: 600; text-decoration: none;
  background: transparent; transition: background var(--ease), color var(--ease);
}
.exm-btn-outline:hover { background: var(--gold-dim); }

/* ── Stats Row ──────────────────────────────────────────── */
.exm-stats-row {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 16px; margin-bottom: 28px;
}
.exm-stat-card {
  display: flex; align-items: center; gap: 16px;
  padding: 20px 22px; background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; transition: box-shadow var(--ease), transform var(--ease);
}
.exm-stat-card:hover { box-shadow: 0 6px 20px var(--sh); transform: translateY(-2px); }
.exm-stat-icon {
  width: 50px; height: 50px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem; background: var(--gold-dim); color: var(--gold);
  flex-shrink: 0;
}
.exm-stat-green .exm-stat-icon { background: rgba(22,163,74,.1); color: #16a34a; }
.exm-stat-gold .exm-stat-icon  { background: var(--gold-dim); color: var(--gold); }
.exm-stat-amber .exm-stat-icon { background: rgba(217,119,6,.1); color: #d97706; }

.exm-stat-count { font-size: 1.75rem; font-weight: 800; color: var(--t1); line-height: 1.2; font-family: var(--font-b); }
.exm-stat-label { font-size: 12.5px; color: var(--t3); font-weight: 600; text-transform: uppercase; letter-spacing: .3px; margin-top: 2px; }

/* ── Section Titles ─────────────────────────────────────── */
.exm-section-title {
  font-size: 16px; font-weight: 700; color: var(--t1);
  margin-bottom: 14px; display: flex; align-items: center; gap: 10px;
  font-family: var(--font-b);
}
.exm-section-title i { color: var(--gold); font-size: 15px; }
.exm-section-title small { font-size: 12.5px; font-weight: 400; color: var(--t3); margin-left: 4px; }

/* ── Card ───────────────────────────────────────────────── */
.exm-card {
  background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; padding: 0; margin-bottom: 26px;
  overflow: hidden;
}

/* ── Quick Actions Grid ─────────────────────────────────── */
.exm-quick-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 14px; margin-bottom: 28px;
}
.exm-qa-card {
  display: flex; flex-direction: column; align-items: center; text-align: center;
  gap: 8px; padding: 24px 16px; background: var(--bg2); border: 1px solid var(--border);
  border-radius: 14px; text-decoration: none; color: var(--t1);
  transition: border-color var(--ease), box-shadow var(--ease), transform var(--ease);
}
.exm-qa-card:hover {
  border-color: var(--gold); box-shadow: 0 6px 20px var(--gold-dim);
  transform: translateY(-2px); color: var(--t1);
}
.exm-qa-card i { font-size: 1.6rem; color: var(--gold); }
.exm-qa-card span { font-size: 14px; font-weight: 600; }
.exm-qa-card small { font-size: 12.5px; color: var(--t3); line-height: 1.4; }

/* ── Recent Activity ────────────────────────────────────── */
.exm-recent-list { padding: 0; }
.exm-recent-item {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; padding: 14px 22px; text-decoration: none; color: var(--t1);
  border-bottom: 1px solid var(--border);
  transition: background .15s;
}
.exm-recent-item:last-child { border-bottom: none; }
.exm-recent-item:hover { background: var(--gold-dim); color: var(--t1); }
.exm-recent-info { flex: 1; min-width: 0; }
.exm-recent-name { font-size: 14.5px; font-weight: 600; color: var(--t1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.exm-recent-meta {
  display: flex; gap: 12px; font-size: 12.5px; color: var(--t3); margin-top: 3px;
}
.exm-recent-meta i { color: var(--gold); margin-right: 3px; }
.exm-recent-badges { display: flex; gap: 8px; flex-shrink: 0; }

/* ── Badges ─────────────────────────────────────────────── */
.exm-badge {
  display: inline-block; padding: 4px 12px; border-radius: 20px;
  font-size: 11.5px; font-weight: 700; white-space: nowrap;
}
.exm-badge-green { background: rgba(22,163,74,.12); color: #16a34a; }
.exm-badge-teal  { background: var(--gold-dim); color: var(--gold); }
.exm-badge-amber { background: rgba(217,119,6,.1); color: #d97706; }
.exm-badge-type  { background: rgba(99,102,241,.1); color: #6366f1; }

/* ── Table ──────────────────────────────────────────────── */
.exm-table-toolbar {
  padding: 14px 22px; border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 14px;
}
.exm-search-box {
  display: flex; align-items: center; gap: 8px;
  background: var(--bg); border: 1px solid var(--border); border-radius: 10px;
  padding: 9px 14px; flex: 1; max-width: 360px;
}
.exm-search-box i { color: var(--t3); font-size: 13px; }
.exm-search-box input {
  border: none; outline: none; background: transparent;
  font-size: 14px; color: var(--t1); width: 100%;
}
.exm-search-box input::placeholder { color: var(--t3); }

.exm-table-wrap { overflow-x: auto; }
.exm-table {
  width: 100%; border-collapse: collapse; font-size: 14px;
}
.exm-table thead th {
  padding: 12px 16px; text-align: left; font-weight: 700;
  color: var(--t3); font-size: 12px; text-transform: uppercase;
  letter-spacing: .5px; border-bottom: 2px solid var(--border);
  background: var(--bg3); white-space: nowrap;
}
.exm-table tbody td {
  padding: 12px 16px; border-bottom: 1px solid var(--border);
  color: var(--t1); vertical-align: middle;
}
.exm-table-row { transition: background .12s; }
.exm-table-row:hover { background: var(--gold-dim); }
.exm-table-row:last-child td { border-bottom: none; }

.exm-td-num { color: var(--t3); font-size: 13px; font-weight: 500; width: 44px; text-align: center; }
.exm-td-name { font-weight: 600; font-size: 14px; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.exm-td-grading { color: var(--t2); font-size: 13px; }
.exm-td-dates { font-size: 13px; color: var(--t3); white-space: nowrap; }
.exm-td-actions { white-space: nowrap; }

.exm-act-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 34px; height: 34px; border-radius: 8px;
  color: var(--gold); background: var(--gold-dim); border: 1px solid var(--gold-ring);
  text-decoration: none; font-size: 14px; margin-right: 5px;
  transition: background var(--ease), color var(--ease);
}
.exm-act-btn:hover { background: var(--gold); color: #fff; }
.exm-act-btn:last-child { margin-right: 0; }

/* ── Empty State ────────────────────────────────────────── */
.exm-empty { text-align: center; padding: 48px 24px; color: var(--t3); }
.exm-empty i { font-size: 2.6rem; color: var(--border); display: block; margin-bottom: 12px; }
.exm-empty p { font-size: 14px; }
.exm-empty a { color: var(--gold); text-decoration: none; }
.exm-empty a:hover { text-decoration: underline; }

/* ── Responsive ─────────────────────────────────────────── */
@media (max-width: 767px) {
  .exm-wrap { padding: 16px 14px 40px; }
  .exm-header { flex-direction: column; gap: 10px; }
  .exm-header-actions { width: 100%; }
  .exm-btn-primary, .exm-btn-outline { flex: 1; justify-content: center; padding: 10px 14px; }
  .exm-stats-row { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  .exm-stat-card { padding: 16px; gap: 12px; }
  .exm-stat-icon { width: 42px; height: 42px; font-size: 1.1rem; border-radius: 10px; }
  .exm-stat-count { font-size: 1.4rem; }
  .exm-quick-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
  .exm-qa-card { padding: 18px 12px; }
  .exm-qa-card i { font-size: 1.3rem; }
  .exm-recent-item { padding: 12px 16px; flex-wrap: wrap; }
  .exm-recent-badges { width: 100%; margin-top: 6px; }
  .exm-table-toolbar { padding: 12px 16px; }
  .exm-search-box { max-width: none; }
  .exm-table thead th, .exm-table tbody td { padding: 10px 12px; }
  .exm-td-name { max-width: 160px; }
}
</style>

<!-- ── Search Script ───────────────────────────────────────────── -->
<script>
(function(){
  var input = document.getElementById('exmSearchInput');
  if (!input) return;
  input.addEventListener('input', function(){
    var q = this.value.toLowerCase();
    var rows = document.querySelectorAll('#exmTable tbody .exm-table-row');
    for (var i = 0; i < rows.length; i++) {
      var text = rows[i].textContent.toLowerCase();
      rows[i].style.display = text.indexOf(q) !== -1 ? '' : 'none';
    }
  });
})();
</script>
