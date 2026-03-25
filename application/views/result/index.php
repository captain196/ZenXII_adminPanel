<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ri-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="ri-header">
    <div>
      <div class="ri-page-title"><i class="fa fa-bar-chart"></i> Results</div>
      <ol class="ri-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Result Management</li>
      </ol>
    </div>
    <div class="ri-header-actions">
      <a href="<?= base_url('result/cumulative') ?>" class="ri-btn-outline">
        <i class="fa fa-calculator"></i> Cumulative
      </a>
    </div>
  </div>

  <!-- ── Quick Links ──────────────────────────────────────────────── -->
  <div class="ri-quick-links">
    <a href="<?= base_url('result/template_designer') ?>" class="ri-ql-card">
      <i class="fa fa-sliders"></i>
      <span>Template Designer</span>
      <small>Define mark components</small>
    </a>
    <a href="<?= base_url('result/marks_entry') ?>" class="ri-ql-card">
      <i class="fa fa-pencil-square-o"></i>
      <span>Marks Entry</span>
      <small>Enter student marks</small>
    </a>
    <a href="<?= base_url('result/class_result') ?>" class="ri-ql-card">
      <i class="fa fa-table"></i>
      <span>Class Results</span>
      <small>View computed results</small>
    </a>
    <a href="<?= base_url('result/cumulative') ?>" class="ri-ql-card">
      <i class="fa fa-line-chart"></i>
      <span>Cumulative</span>
      <small>Weighted final result</small>
    </a>
  </div>

  <!-- ── Exams List ───────────────────────────────────────────────── -->
  <div class="ri-section-title">
    <i class="fa fa-file-text-o"></i> Exams
    <small><?= count($exams) ?> active exam<?= count($exams) !== 1 ? 's' : '' ?></small>
  </div>

  <?php if (empty($exams)): ?>
  <div class="ri-empty">
    <i class="fa fa-inbox"></i>
    <p>No active exams found. <a href="<?= base_url('exam/create') ?>">Create an exam</a> first.</p>
  </div>
  <?php else: ?>
  <div class="ri-exam-grid">
    <?php foreach ($exams as $ex):
      $statusCls = match($ex['Status'] ?? '') {
        'Completed' => 'ri-status-done',
        'Published' => 'ri-status-pub',
        default     => 'ri-status-draft',
      };
    ?>
    <div class="ri-exam-card">
      <div class="ri-ec-head">
        <div class="ri-ec-name"><?= htmlspecialchars($ex['Name'] ?? 'Untitled') ?></div>
        <span class="ri-badge <?= $statusCls ?>"><?= htmlspecialchars($ex['Status'] ?? '') ?></span>
      </div>
      <div class="ri-ec-meta">
        <span><i class="fa fa-tag"></i> <?= htmlspecialchars($ex['id']) ?></span>
        <span><i class="fa fa-bookmark-o"></i> <?= htmlspecialchars($ex['Type'] ?? '') ?></span>
        <?php if (!empty($ex['StartDate'])): ?>
        <span><i class="fa fa-calendar-o"></i> <?= htmlspecialchars($ex['StartDate']) ?></span>
        <?php endif; ?>
        <span><i class="fa fa-graduation-cap"></i> <?= htmlspecialchars($ex['GradingScale'] ?? '') ?></span>
      </div>
      <div class="ri-ec-actions">
        <a href="<?= base_url('result/template_designer/' . urlencode($ex['id'])) ?>"
           class="ri-ec-btn" title="Design Templates">
          <i class="fa fa-sliders"></i> Templates
        </a>
        <a href="<?= base_url('result/marks_entry/' . urlencode($ex['id'])) ?>"
           class="ri-ec-btn" title="Enter Marks">
          <i class="fa fa-pencil"></i> Marks
        </a>
        <a href="<?= base_url('result/class_result/' . urlencode($ex['id'])) ?>"
           class="ri-ec-btn" title="View Results">
          <i class="fa fa-table"></i> Results
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /.ri-wrap -->
</div><!-- /.content-wrapper -->

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Result Index — .ri-*
═══════════════════════════════════════════════════════════ */
.ri-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 56px; }

/* Header */
.ri-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
}
.ri-page-title {
  font-size: 1.45rem; font-weight: 700; color: var(--t1);
  display: flex; align-items: center; gap: 10px; margin-bottom: 4px;
}
.ri-page-title i { color: var(--gold); }
.ri-breadcrumb {
  list-style: none; margin: 0; padding: 0; display: flex; gap: 6px;
  font-size: .83rem; color: var(--t3);
}
.ri-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.ri-breadcrumb a { color: var(--gold); text-decoration: none; }
.ri-header-actions { display: flex; gap: 8px; align-items: center; }
.ri-btn-outline {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 18px; border: 1.5px solid var(--gold); border-radius: 8px;
  color: var(--gold); font-size: .9rem; font-weight: 600; text-decoration: none;
  background: transparent; transition: background .18s, color .18s;
}
.ri-btn-outline:hover { background: var(--gold); color: #fff; }

/* Quick links */
.ri-quick-links {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 14px; margin-bottom: 32px;
}
.ri-ql-card {
  display: flex; flex-direction: column; align-items: center; text-align: center;
  gap: 8px; padding: 20px 16px; background: var(--bg2); border: 1px solid var(--border);
  border-radius: 12px; text-decoration: none; color: var(--t1);
  transition: border-color .18s, box-shadow .18s, transform .18s;
}
.ri-ql-card:hover {
  border-color: var(--gold); box-shadow: 0 4px 16px var(--gold-dim);
  transform: translateY(-2px); color: var(--t1);
}
.ri-ql-card i { font-size: 1.8rem; color: var(--gold); }
.ri-ql-card span { font-size: 1rem; font-weight: 600; }
.ri-ql-card small { font-size: .78rem; color: var(--t3); }

/* Section title */
.ri-section-title {
  font-size: 1.05rem; font-weight: 700; color: var(--t2);
  margin-bottom: 16px; display: flex; align-items: center; gap: 10px;
}
.ri-section-title i { color: var(--gold); }
.ri-section-title small { font-size: .8rem; font-weight: 400; color: var(--t3); }

/* Exam grid */
.ri-exam-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 16px;
}
.ri-exam-card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  padding: 18px 20px; display: flex; flex-direction: column; gap: 12px;
  transition: box-shadow .18s;
}
.ri-exam-card:hover { box-shadow: 0 4px 18px var(--gold-dim); }
.ri-ec-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.ri-ec-name { font-size: 1.05rem; font-weight: 700; color: var(--t1); flex: 1; }
.ri-ec-meta {
  display: flex; flex-wrap: wrap; gap: 8px 14px; font-size: .8rem; color: var(--t3);
}
.ri-ec-meta span { display: flex; align-items: center; gap: 4px; }
.ri-ec-meta i { color: var(--gold); }
.ri-ec-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.ri-ec-btn {
  flex: 1; text-align: center; padding: 7px 12px; font-size: .83rem; font-weight: 600;
  background: var(--gold-dim); color: var(--gold); border-radius: 7px;
  text-decoration: none; border: 1px solid var(--gold-ring);
  transition: background .18s, color .18s;
}
.ri-ec-btn:hover { background: var(--gold); color: #fff; }

/* Badges */
.ri-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .73rem; font-weight: 700; }
.ri-status-done  { background: rgba(22,163,74,.12); color: #16a34a; }
.ri-status-pub   { background: rgba(37,99,235,.12); color: #2563eb; }
.ri-status-draft { background: rgba(107,114,128,.12); color: #6b7280; }

/* Empty */
.ri-empty { text-align: center; padding: 48px 20px; color: var(--t3); }
.ri-empty i { font-size: 3rem; color: var(--border); display: block; margin-bottom: 14px; }
.ri-empty a { color: var(--gold); }
</style>
