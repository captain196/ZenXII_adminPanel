<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="ei-wrap">

  <!-- ── Page Header ─────────────────────────────────────────────────── -->
  <div class="ei-header">
    <div>
      <div class="ei-page-title"><i class="fa fa-file-text-o"></i> Exams</div>
      <ol class="ei-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Manage Exams</li>
      </ol>
    </div>
    <a href="<?= base_url('exam/create') ?>" class="ei-btn-create">
      <i class="fa fa-plus"></i> Create Exam
    </a>
  </div>

  <!-- ── Stats Pills ──────────────────────────────────────────────────── -->
  <?php
    $total     = count($exams);
    $published = count(array_filter($exams, fn($e) => ($e['Status'] ?? '') === 'Published'));
    $draft     = count(array_filter($exams, fn($e) => ($e['Status'] ?? '') === 'Draft'));
    $completed = count(array_filter($exams, fn($e) => ($e['Status'] ?? '') === 'Completed'));
  ?>
  <div class="ei-stats">
    <div class="ei-stat ei-stat-all"   data-filter-stat="all">
      <span class="ei-stat-n" id="statTotal"><?= $total ?></span>
      <span class="ei-stat-l">Total</span>
    </div>
    <div class="ei-stat ei-stat-pub"   data-filter-stat="Published">
      <span class="ei-stat-n" id="statPub"><?= $published ?></span>
      <span class="ei-stat-l">Published</span>
    </div>
    <div class="ei-stat ei-stat-draft" data-filter-stat="Draft">
      <span class="ei-stat-n" id="statDraft"><?= $draft ?></span>
      <span class="ei-stat-l">Draft</span>
    </div>
    <div class="ei-stat ei-stat-done"  data-filter-stat="Completed">
      <span class="ei-stat-n" id="statDone"><?= $completed ?></span>
      <span class="ei-stat-l">Completed</span>
    </div>
  </div>

  <!-- ── Filter Bar ───────────────────────────────────────────────────── -->
  <div class="ei-filter-bar">
    <div class="ei-search-wrap">
      <i class="fa fa-search ei-search-icon"></i>
      <input type="text" id="searchInput" class="ei-search" placeholder="Search exams…">
    </div>
    <select id="typeFilter" class="ei-filter-sel">
      <option value="">All Types</option>
      <option>Mid-Term</option>
      <option>Final Term</option>
      <option>Unit Test</option>
      <option>Weekly Test</option>
      <option>Pre-Board</option>
      <option>Annual</option>
    </select>
    <select id="statusFilter" class="ei-filter-sel">
      <option value="">All Statuses</option>
      <option>Draft</option>
      <option>Published</option>
      <option>Completed</option>
    </select>
    <span class="ei-live-count" id="liveCount"><?= $total ?> exam<?= $total !== 1 ? 's' : '' ?></span>
  </div>

  <!-- ── Exam Cards ───────────────────────────────────────────────────── -->
  <div id="examList" class="ei-list">
    <?php if (empty($exams)): ?>
    <div class="ei-empty" id="emptyState">
      <i class="fa fa-inbox"></i>
      <p>No exams yet.</p>
      <a href="<?= base_url('exam/create') ?>" class="ei-btn-create" style="display:inline-flex;margin-top:14px;">
        <i class="fa fa-plus"></i> Create First Exam
      </a>
    </div>
    <?php else: ?>
    <?php foreach ($exams as $ex):
      $statusCls = match($ex['Status'] ?? 'Draft') {
        'Published' => 'ei-status-pub',
        'Completed' => 'ei-status-done',
        default     => 'ei-status-draft',
      };
      $typeBg = match($ex['Type'] ?? '') {
        'Mid-Term','Final Term','Annual','Pre-Board' => 'ei-type-formal',
        default => 'ei-type-other',
      };
    ?>
    <div class="ei-card"
         data-status="<?= htmlspecialchars($ex['Status'] ?? 'Draft') ?>"
         data-type="<?= htmlspecialchars($ex['Type'] ?? '') ?>"
         data-name="<?= htmlspecialchars(strtolower($ex['Name'] ?? '')) ?>">
      <div class="ei-card-accent <?= $statusCls ?>"></div>
      <div class="ei-card-body">
        <div class="ei-card-main">
          <div class="ei-card-title"><?= htmlspecialchars($ex['Name'] ?? 'Untitled') ?></div>
          <div class="ei-card-meta">
            <span class="ei-badge ei-badge-type <?= $typeBg ?>">
              <?= htmlspecialchars($ex['Type'] ?? '') ?>
            </span>
            <span class="ei-badge <?= $statusCls ?>">
              <?= htmlspecialchars($ex['Status'] ?? 'Draft') ?>
            </span>
            <?php if (!empty($ex['StartDate'])): ?>
            <span class="ei-meta-text">
              <i class="fa fa-calendar-o"></i>
              <?= htmlspecialchars($ex['StartDate']) ?><?= !empty($ex['EndDate']) ? ' – ' . htmlspecialchars($ex['EndDate']) : '' ?>
            </span>
            <?php endif; ?>
            <span class="ei-meta-text ei-meta-id">
              <i class="fa fa-tag"></i> <?= htmlspecialchars($ex['id']) ?>
            </span>
          </div>
        </div>
        <div class="ei-card-actions">
          <a href="<?= base_url('exam/view/' . urlencode($ex['id'])) ?>" class="ei-btn-view">
            <i class="fa fa-eye"></i> View
          </a>
          <button type="button" class="ei-btn-del"
                  data-id="<?= htmlspecialchars($ex['id']) ?>"
                  data-name="<?= htmlspecialchars($ex['Name'] ?? 'this exam') ?>"
                  onclick="eiConfirmDelete(this)">
            <i class="fa fa-trash"></i>
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <div class="ei-empty" id="noResults" style="display:none;">
      <i class="fa fa-search"></i>
      <p>No exams match your filters.</p>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /.ei-wrap -->
</div><!-- /.content-wrapper -->

<!-- ── Delete Confirm Modal ─────────────────────────────────────────── -->
<div id="eiDelModal" class="ei-modal-overlay" style="display:none;">
  <div class="ei-modal">
    <div class="ei-modal-icon"><i class="fa fa-exclamation-triangle"></i></div>
    <div class="ei-modal-title">Delete Exam?</div>
    <div class="ei-modal-body">
      You are about to delete <strong id="eiDelName"></strong>.
      This will also remove all per-section schedule copies. This action cannot be undone.
    </div>
    <div class="ei-modal-actions">
      <button type="button" class="ei-modal-cancel" onclick="eiCloseModal()">Cancel</button>
      <a id="eiDelLink" href="#" class="ei-modal-confirm">
        <i class="fa fa-trash"></i> Delete
      </a>
    </div>
  </div>
</div>


<script>
(function () {
  'use strict';

  var cards      = Array.from(document.querySelectorAll('.ei-card'));
  var searchIn   = document.getElementById('searchInput');
  var typeFilter = document.getElementById('typeFilter');
  var statFilter = document.getElementById('statusFilter');
  var liveCount  = document.getElementById('liveCount');
  var noResults  = document.getElementById('noResults');
  var emptyState = document.getElementById('emptyState');

  // Stat pill click → set status filter
  document.querySelectorAll('.ei-stat').forEach(function (pill) {
    pill.addEventListener('click', function () {
      var f = this.dataset.filterStat;
      statFilter.value = (f === 'all') ? '' : f;
      runFilter();
    });
  });

  function runFilter() {
    var q      = (searchIn  ? searchIn.value.toLowerCase()  : '');
    var type   = (typeFilter ? typeFilter.value               : '');
    var status = (statFilter ? statFilter.value               : '');
    var shown  = 0;

    cards.forEach(function (card) {
      var matchName   = !q      || (card.dataset.name || '').indexOf(q) !== -1;
      var matchType   = !type   || card.dataset.type   === type;
      var matchStatus = !status || card.dataset.status === status;
      var visible     = matchName && matchType && matchStatus;
      card.style.display = visible ? '' : 'none';
      if (visible) shown++;
    });

    if (liveCount) liveCount.textContent = shown + ' exam' + (shown !== 1 ? 's' : '');
    if (noResults) noResults.style.display = (cards.length > 0 && shown === 0) ? '' : 'none';
    if (emptyState) emptyState.style.display = (cards.length === 0) ? '' : 'none';
  }

  if (searchIn)   searchIn.addEventListener('input',  runFilter);
  if (typeFilter) typeFilter.addEventListener('change', runFilter);
  if (statFilter) statFilter.addEventListener('change', runFilter);

  // Delete modal
  window.eiConfirmDelete = function (btn) {
    var id   = btn.dataset.id;
    var name = btn.dataset.name;
    document.getElementById('eiDelName').textContent = name;
    document.getElementById('eiDelLink').href = '<?= base_url('exam/delete/') ?>' + encodeURIComponent(id);
    document.getElementById('eiDelModal').style.display = 'flex';
  };

  window.eiCloseModal = function () {
    document.getElementById('eiDelModal').style.display = 'none';
  };

  // Close modal on overlay click
  document.getElementById('eiDelModal').addEventListener('click', function (e) {
    if (e.target === this) eiCloseModal();
  });

  // Keyboard ESC closes modal
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') eiCloseModal();
  });
})();
</script>


<style>
/* Fix rem scale: Bootstrap 3 sets html{font-size:10px}; override so 1rem=16px */
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Exam Index — .ei-*
═══════════════════════════════════════════════════════════ */
.ei-wrap {
  max-width: 1100px;
  margin: 0 auto;
  padding: 24px 16px 56px;
}

/* Header */
.ei-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 22px;
}
.ei-page-title {
  font-size: 1.45rem;
  font-weight: 700;
  color: var(--t1);
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
}
.ei-page-title i { color: var(--gold); }
.ei-breadcrumb {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  gap: 6px;
  font-size: .83rem;
  color: var(--t3);
}
.ei-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.ei-breadcrumb a { color: var(--gold); text-decoration: none; }
.ei-breadcrumb a:hover { text-decoration: underline; }

.ei-btn-create {
  padding: 9px 20px;
  background: var(--gold);
  color: #fff;
  border: none;
  border-radius: 8px;
  font-size: .88rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  transition: background .18s, transform .1s;
  white-space: nowrap;
}
.ei-btn-create:hover { background: var(--gold2); color: #fff; }
.ei-btn-create:active { transform: scale(.97); }

/* Stats */
.ei-stats {
  display: flex;
  gap: 12px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.ei-stat {
  flex: 1;
  min-width: 100px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 14px 18px;
  cursor: pointer;
  transition: border-color .18s, box-shadow .18s;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  box-shadow: var(--sh);
}
.ei-stat:hover { border-color: var(--gold); }
.ei-stat-n { font-size: 1.6rem; font-weight: 700; line-height: 1; }
.ei-stat-l { font-size: .78rem; color: var(--t3); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.ei-stat-all   .ei-stat-n { color: var(--gold); }
.ei-stat-pub   .ei-stat-n { color: #16a34a; }
.ei-stat-draft .ei-stat-n { color: #d97706; }
.ei-stat-done  .ei-stat-n { color: var(--gold); }

/* Filter bar */
.ei-filter-bar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.ei-search-wrap {
  position: relative;
  flex: 1;
  min-width: 180px;
}
.ei-search-icon {
  position: absolute;
  left: 11px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--t3);
  font-size: .85rem;
}
.ei-search {
  width: 100%;
  padding: 8px 11px 8px 32px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg2);
  color: var(--t1);
  font-size: .88rem;
  box-sizing: border-box;
}
.ei-search:focus { outline: none; border-color: var(--gold); }
.ei-filter-sel {
  padding: 8px 11px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg2);
  color: var(--t1);
  font-size: .86rem;
}
.ei-filter-sel:focus { outline: none; border-color: var(--gold); }
.ei-live-count { font-size: .82rem; color: var(--t3); white-space: nowrap; }

/* Exam list */
.ei-list { display: flex; flex-direction: column; gap: 10px; }

/* Exam card */
.ei-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  box-shadow: var(--sh);
  transition: box-shadow .18s;
}
.ei-card:hover { box-shadow: 0 4px 18px var(--gold-ring); }
.ei-card-accent {
  width: 5px;
  flex-shrink: 0;
}
.ei-status-pub   { background: #16a34a; }
.ei-status-draft { background: #d97706; }
.ei-status-done  { background: var(--gold); }

.ei-card-body {
  flex: 1;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.ei-card-main { flex: 1; min-width: 0; }
.ei-card-title {
  font-size: 1rem;
  font-weight: 700;
  color: var(--t1);
  margin-bottom: 7px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ei-card-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.ei-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: .75rem;
  font-weight: 700;
  letter-spacing: .03em;
  color: #fff;
}
.ei-badge-type.ei-type-formal { background: #2563eb; }
.ei-badge-type.ei-type-other  { background: #7c3aed; }
.ei-badge.ei-status-pub   { background: #16a34a; }
.ei-badge.ei-status-draft { background: #d97706; }
.ei-badge.ei-status-done  { background: var(--gold); }

.ei-meta-text {
  font-size: .8rem;
  color: var(--t3);
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.ei-meta-id { opacity: .7; }

.ei-card-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.ei-btn-view {
  padding: 7px 16px;
  background: var(--gold-dim);
  color: var(--gold);
  border: 1px solid var(--gold-ring);
  border-radius: 6px;
  font-size: .84rem;
  font-weight: 600;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background .18s;
  white-space: nowrap;
}
.ei-btn-view:hover { background: var(--gold); color: #fff; }
.ei-btn-del {
  width: 34px;
  height: 34px;
  background: rgba(239,68,68,.1);
  color: #ef4444;
  border: 1px solid rgba(239,68,68,.2);
  border-radius: 6px;
  cursor: pointer;
  font-size: .84rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background .18s;
}
.ei-btn-del:hover { background: #ef4444; color: #fff; }

/* Empty state */
.ei-empty {
  text-align: center;
  padding: 52px 24px;
  color: var(--t3);
}
.ei-empty i { font-size: 2.8rem; color: var(--gold-ring); display: block; margin-bottom: 14px; }
.ei-empty p { font-size: .95rem; margin: 0; }

/* Delete modal */
.ei-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.ei-modal {
  background: var(--bg2);
  border-radius: 12px;
  padding: 32px 28px 24px;
  max-width: 420px;
  width: 100%;
  text-align: center;
  box-shadow: 0 8px 40px rgba(0,0,0,.35);
  animation: ei-modal-in .2s ease;
}
@keyframes ei-modal-in {
  from { transform: scale(.92); opacity: 0; }
  to   { transform: scale(1);   opacity: 1; }
}
.ei-modal-icon { font-size: 2.4rem; color: #ef4444; margin-bottom: 14px; }
.ei-modal-title { font-size: 1.15rem; font-weight: 700; color: var(--t1); margin-bottom: 10px; }
.ei-modal-body { font-size: .9rem; color: var(--t2); line-height: 1.6; margin-bottom: 22px; }
.ei-modal-actions { display: flex; gap: 10px; justify-content: center; }
.ei-modal-cancel {
  padding: 9px 22px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg3);
  color: var(--t2);
  font-size: .9rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .18s;
}
.ei-modal-cancel:hover { background: var(--border); }
.ei-modal-confirm {
  padding: 9px 22px;
  background: #ef4444;
  color: #fff;
  border: none;
  border-radius: 7px;
  font-size: .9rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  transition: background .18s;
}
.ei-modal-confirm:hover { background: #dc2626; color: #fff; }
</style>
