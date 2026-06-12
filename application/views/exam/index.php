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
    <a href="<?= base_url('exam/create') ?>" class="ei-btn-create" data-spin="Opening…">
      <i class="fa fa-plus"></i> Create Exam
    </a>
  </div>

  <?php
    // ── Partition by lifecycle status (commercial-ERP segmentation) ──────
    $total     = count($exams);
    $byStatus  = ['Published' => [], 'Draft' => [], 'Completed' => []];
    foreach ($exams as $ex) {
      $st = $ex['Status'] ?? 'Draft';
      if (!isset($byStatus[$st])) $st = 'Draft';
      $byStatus[$st][] = $ex;
    }
    $published = count($byStatus['Published']);
    $draft     = count($byStatus['Draft']);
    $completed = count($byStatus['Completed']);

    // Lifecycle readiness helper: 3-stage track (Created → Published → Completed).
    $ei_stage = function (string $status): int {
      return $status === 'Completed' ? 3 : ($status === 'Published' ? 2 : 1);
    };
  ?>

  <!-- ── Stats Pills (quick status filters) ──────────────────────────── -->
  <div class="ei-stats">
    <div class="ei-stat ei-stat-all is-active" data-filter-stat="all">
      <span class="ei-stat-n" id="statTotal"><?= $total ?></span>
      <span class="ei-stat-l">All Exams</span>
    </div>
    <div class="ei-stat ei-stat-pub" data-filter-stat="Published">
      <span class="ei-stat-n" id="statPub"><?= $published ?></span>
      <span class="ei-stat-l">Published</span>
    </div>
    <div class="ei-stat ei-stat-draft" data-filter-stat="Draft">
      <span class="ei-stat-n" id="statDraft"><?= $draft ?></span>
      <span class="ei-stat-l">Draft</span>
    </div>
    <div class="ei-stat ei-stat-done" data-filter-stat="Completed">
      <span class="ei-stat-n" id="statDone"><?= $completed ?></span>
      <span class="ei-stat-l">Completed</span>
    </div>
  </div>

  <!-- ── Filter Bar ───────────────────────────────────────────────────── -->
  <div class="ei-filter-bar">
    <div class="ei-search-wrap">
      <i class="fa fa-search ei-search-icon"></i>
      <input type="text" id="searchInput" class="ei-search" placeholder="Search exams by name or ID…">
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

  <?php if (empty($exams)): ?>
  <!-- ── Global empty state ─────────────────────────────────────────── -->
  <div class="ei-empty" id="emptyState">
    <i class="fa fa-inbox"></i>
    <p>No exams yet.</p>
    <a href="<?= base_url('exam/create') ?>" class="ei-btn-create" style="display:inline-flex;margin-top:14px;">
      <i class="fa fa-plus"></i> Create First Exam
    </a>
  </div>
  <?php else: ?>

  <!-- ── Status-segmented sections ────────────────────────────────────── -->
  <?php
    $segments = [
      'Published' => ['label' => 'Published',  'icon' => 'fa-bullhorn',     'cls' => 'ei-seg-pub',   'hint' => 'Live — visible to parents; marks entry &amp; grading'],
      'Draft'     => ['label' => 'Draft',      'icon' => 'fa-pencil',       'cls' => 'ei-seg-draft', 'hint' => 'Setup in progress — not yet visible to parents'],
      'Completed' => ['label' => 'Completed',  'icon' => 'fa-check-circle', 'cls' => 'ei-seg-done',  'hint' => 'Finalised — results locked'],
    ];
    foreach ($segments as $segStatus => $seg):
      $segExams = $byStatus[$segStatus];
  ?>
  <section class="ei-segment <?= $seg['cls'] ?>" data-segment="<?= $segStatus ?>"
           style="<?= empty($segExams) ? 'display:none;' : '' ?>">
    <header class="ei-seg-head">
      <span class="ei-seg-title">
        <i class="fa <?= $seg['icon'] ?>"></i> <?= $seg['label'] ?>
        <span class="ei-seg-count"><?= count($segExams) ?></span>
      </span>
      <span class="ei-seg-hint"><?= $seg['hint'] ?></span>
    </header>

    <div class="ei-grid">
      <?php foreach ($segExams as $ex):
        $status    = $ex['Status'] ?? 'Draft';
        $statusCls = match($status) {
          'Published' => 'ei-status-pub',
          'Completed' => 'ei-status-done',
          default     => 'ei-status-draft',
        };
        $typeBg = match($ex['Type'] ?? '') {
          'Mid-Term','Final Term','Annual','Pre-Board' => 'ei-type-formal',
          default => 'ei-type-other',
        };
        $stage = $ei_stage($status);
      ?>
      <div class="ei-card"
           data-status="<?= htmlspecialchars($status) ?>"
           data-type="<?= htmlspecialchars($ex['Type'] ?? '') ?>"
           data-name="<?= htmlspecialchars(strtolower(($ex['Name'] ?? '') . ' ' . $ex['id'])) ?>">
        <div class="ei-card-accent <?= $statusCls ?>"></div>
        <div class="ei-card-body">

          <!-- top: title + status -->
          <div class="ei-card-top">
            <div class="ei-card-title" title="<?= htmlspecialchars($ex['Name'] ?? 'Untitled') ?>">
              <?= htmlspecialchars($ex['Name'] ?? 'Untitled') ?>
            </div>
            <span class="ei-badge <?= $statusCls ?>"><?= htmlspecialchars($status) ?></span>
          </div>

          <!-- meta row -->
          <div class="ei-card-meta">
            <span class="ei-badge ei-badge-type <?= $typeBg ?>"><?= htmlspecialchars($ex['Type'] ?? '—') ?></span>
            <?php if (!empty($ex['StartDate'])): ?>
            <span class="ei-meta-text"><i class="fa fa-calendar-o"></i>
              <?= htmlspecialchars($ex['StartDate']) ?><?= !empty($ex['EndDate']) ? ' – ' . htmlspecialchars($ex['EndDate']) : '' ?>
            </span>
            <?php endif; ?>
            <?php if (!empty($ex['GradingScale'])): ?>
            <span class="ei-meta-text"><i class="fa fa-graduation-cap"></i> <?= htmlspecialchars($ex['GradingScale']) ?></span>
            <?php endif; ?>
            <?php if (!empty($ex['PassingPercent'])): ?>
            <span class="ei-meta-text"><i class="fa fa-percent"></i> Pass <?= (int) $ex['PassingPercent'] ?>%</span>
            <?php endif; ?>
          </div>

          <!-- lifecycle readiness track -->
          <div class="ei-track" aria-label="Lifecycle readiness">
            <div class="ei-track-step <?= $stage >= 1 ? 'is-done' : '' ?>">
              <span class="ei-track-dot"><i class="fa fa-pencil"></i></span>
              <span class="ei-track-lbl">Created</span>
            </div>
            <div class="ei-track-bar <?= $stage >= 2 ? 'is-done' : '' ?>"></div>
            <div class="ei-track-step <?= $stage >= 2 ? 'is-done' : '' ?>">
              <span class="ei-track-dot"><i class="fa fa-bullhorn"></i></span>
              <span class="ei-track-lbl">Published</span>
            </div>
            <div class="ei-track-bar <?= $stage >= 3 ? 'is-done' : '' ?>"></div>
            <div class="ei-track-step <?= $stage >= 3 ? 'is-done' : '' ?>">
              <span class="ei-track-dot"><i class="fa fa-check"></i></span>
              <span class="ei-track-lbl">Completed</span>
            </div>
          </div>

          <!-- footer: id + actions -->
          <div class="ei-card-foot">
            <span class="ei-meta-id"><i class="fa fa-tag"></i> <?= htmlspecialchars($ex['id']) ?></span>
            <div class="ei-card-actions">
              <a href="<?= base_url('exam/view/' . urlencode($ex['id'])) ?>" class="ei-btn-view" data-spin="Opening…">
                <i class="fa fa-eye"></i> View
              </a>
              <button type="button" class="ei-btn-del"
                      data-id="<?= htmlspecialchars($ex['id']) ?>"
                      data-name="<?= htmlspecialchars($ex['Name'] ?? 'this exam') ?>"
                      data-status="<?= htmlspecialchars($status) ?>"
                      onclick="eiConfirmDelete(this)" title="Delete exam">
                <i class="fa fa-trash"></i>
              </button>
            </div>
          </div>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <!-- no-results (filter) -->
  <div class="ei-empty" id="noResults" style="display:none;">
    <i class="fa fa-search"></i>
    <p>No exams match your filters.</p>
  </div>
  <?php endif; ?>

</div><!-- /.ei-wrap -->
</div><!-- /.content-wrapper -->

<!-- ── Delete Confirm Modal ─────────────────────────────────────────── -->
<div id="eiDelModal" class="ei-modal-overlay" style="display:none;">
  <div class="ei-modal">
    <div class="ei-modal-icon"><i class="fa fa-exclamation-triangle"></i></div>
    <div class="ei-modal-title">Delete Exam?</div>
    <div class="ei-modal-body">
      You are about to delete <strong id="eiDelName"></strong>.
      This permanently removes its schedule, templates, marks and results. This action cannot be undone.
      <div id="eiDelWarn" class="ei-modal-warn" style="display:none;">
        <i class="fa fa-exclamation-circle"></i>
        This exam is <strong id="eiDelStatus"></strong> — students may already see its results.
      </div>
    </div>
    <div class="ei-modal-actions">
      <button type="button" class="ei-modal-cancel" onclick="eiCloseModal()">Cancel</button>
      <a id="eiDelLink" href="#" class="ei-modal-confirm" data-spin="Deleting…">
        <i class="fa fa-trash"></i> Delete
      </a>
    </div>
  </div>
</div>


<script>
(function () {
  'use strict';

  var cards      = Array.from(document.querySelectorAll('.ei-card'));
  var segments   = Array.from(document.querySelectorAll('.ei-segment'));
  var searchIn   = document.getElementById('searchInput');
  var typeFilter = document.getElementById('typeFilter');
  var statFilter = document.getElementById('statusFilter');
  var liveCount  = document.getElementById('liveCount');
  var noResults  = document.getElementById('noResults');
  var stats      = Array.from(document.querySelectorAll('.ei-stat'));

  // Stat pill click → set status filter + active highlight
  stats.forEach(function (pill) {
    pill.addEventListener('click', function () {
      var f = this.dataset.filterStat;
      if (statFilter) statFilter.value = (f === 'all') ? '' : f;
      runFilter();
    });
  });

  function syncActivePill() {
    var v = statFilter ? statFilter.value : '';
    stats.forEach(function (p) {
      var match = (v === '' && p.dataset.filterStat === 'all') || (p.dataset.filterStat === v);
      p.classList.toggle('is-active', match);
    });
  }

  function runFilter() {
    var q      = (searchIn   ? searchIn.value.toLowerCase().trim() : '');
    var type   = (typeFilter ? typeFilter.value : '');
    var status = (statFilter ? statFilter.value : '');
    var shown  = 0;

    cards.forEach(function (card) {
      var matchName   = !q      || (card.dataset.name || '').indexOf(q) !== -1;
      var matchType   = !type   || card.dataset.type   === type;
      var matchStatus = !status || card.dataset.status === status;
      var visible     = matchName && matchType && matchStatus;
      card.style.display = visible ? '' : 'none';
      if (visible) shown++;
    });

    // hide segments that have no visible cards
    segments.forEach(function (seg) {
      var any = Array.prototype.some.call(seg.querySelectorAll('.ei-card'), function (c) {
        return c.style.display !== 'none';
      });
      seg.style.display = any ? '' : 'none';
    });

    if (liveCount) liveCount.textContent = shown + ' exam' + (shown !== 1 ? 's' : '');
    if (noResults) noResults.style.display = (cards.length > 0 && shown === 0) ? '' : 'none';
    syncActivePill();
  }

  if (searchIn)   searchIn.addEventListener('input',  runFilter);
  if (typeFilter) typeFilter.addEventListener('change', runFilter);
  if (statFilter) statFilter.addEventListener('change', runFilter);

  // Delete modal
  window.eiConfirmDelete = function (btn) {
    var id     = btn.dataset.id;
    var name   = btn.dataset.name;
    var status = btn.dataset.status || 'Draft';
    document.getElementById('eiDelName').textContent = name;
    // Published/Completed extra warning (mirrors the server-side confirm gate)
    var warn = document.getElementById('eiDelWarn');
    var risky = (status === 'Published' || status === 'Completed');
    if (warn) {
      warn.style.display = risky ? '' : 'none';
      document.getElementById('eiDelStatus').textContent = status;
    }
    // server gate: Published/Completed require confirm_published=1
    var href = '<?= base_url('exam/delete/') ?>' + encodeURIComponent(id) + '?confirm=1';
    if (risky) href += '&confirm_published=1';
    document.getElementById('eiDelLink').href = href;
    document.getElementById('eiDelModal').style.display = 'flex';
  };

  window.eiCloseModal = function () {
    document.getElementById('eiDelModal').style.display = 'none';
  };

  document.getElementById('eiDelModal').addEventListener('click', function (e) {
    if (e.target === this) eiCloseModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') eiCloseModal();
  });
})();
</script>


<style>
/* Fix rem scale: Bootstrap 3 sets html{font-size:10px}; override so 1rem=16px */
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Exam Dashboard — .ei-*  (commercial-ERP redesign)
═══════════════════════════════════════════════════════════ */
.ei-wrap { max-width: 1180px; margin: 0 auto; padding: 24px 16px 56px; }

/* Header */
.ei-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  flex-wrap: wrap; gap: 12px; margin-bottom: 22px;
}
.ei-page-title {
  font-size: 1.5rem; font-weight: 700; color: var(--t1);
  display: flex; align-items: center; gap: 10px; margin-bottom: 4px;
}
.ei-page-title i { color: var(--gold); }
.ei-breadcrumb {
  list-style: none; margin: 0; padding: 0; display: flex; gap: 6px;
  font-size: .83rem; color: var(--t3);
}
.ei-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.ei-breadcrumb a { color: var(--gold); text-decoration: none; }
.ei-breadcrumb a:hover { text-decoration: underline; }
.ei-btn-create {
  padding: 10px 22px; background: var(--gold); color: #fff; border: none;
  border-radius: 8px; font-size: .9rem; font-weight: 600; cursor: pointer;
  text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
  transition: background .18s, transform .1s; white-space: nowrap;
  box-shadow: 0 2px 10px var(--gold-ring);
}
.ei-btn-create:hover { background: var(--gold2); color: #fff; }
.ei-btn-create:active { transform: scale(.97); }

/* Stats */
.ei-stats { display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.ei-stat {
  flex: 1; min-width: 120px; background: var(--bg2); border: 1px solid var(--border);
  border-radius: 12px; padding: 14px 18px; cursor: pointer;
  transition: border-color .18s, box-shadow .18s, transform .1s;
  display: flex; flex-direction: column; align-items: center; gap: 3px; box-shadow: var(--sh);
  position: relative;
}
.ei-stat:hover { border-color: var(--gold); transform: translateY(-1px); }
.ei-stat.is-active { border-color: var(--gold); box-shadow: 0 0 0 2px var(--gold-ring); }
.ei-stat-n { font-size: 1.7rem; font-weight: 700; line-height: 1; }
.ei-stat-l { font-size: .76rem; color: var(--t3); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.ei-stat-all   .ei-stat-n { color: var(--gold); }
.ei-stat-pub   .ei-stat-n { color: #16a34a; }
.ei-stat-draft .ei-stat-n { color: #d97706; }
.ei-stat-done  .ei-stat-n { color: #2563eb; }

/* Filter bar */
.ei-filter-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 22px; flex-wrap: wrap; }
.ei-search-wrap { position: relative; flex: 1; min-width: 200px; }
.ei-search-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: var(--t3); font-size: .85rem; }
.ei-search {
  width: 100%; padding: 9px 11px 9px 32px; border: 1px solid var(--border);
  border-radius: 8px; background: var(--bg2); color: var(--t1); font-size: .88rem; box-sizing: border-box;
}
.ei-search:focus { outline: none; border-color: var(--gold); }
.ei-filter-sel {
  padding: 9px 11px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--bg2); color: var(--t1); font-size: .86rem;
}
.ei-filter-sel:focus { outline: none; border-color: var(--gold); }
.ei-live-count { font-size: .82rem; color: var(--t3); white-space: nowrap; }

/* Segment */
.ei-segment { margin-bottom: 26px; }
.ei-seg-head {
  display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap;
  padding: 0 2px 12px; border-bottom: 1px solid var(--border); margin-bottom: 16px;
}
.ei-seg-title { font-size: 1.02rem; font-weight: 700; color: var(--t1); display: inline-flex; align-items: center; gap: 8px; }
.ei-seg-pub   .ei-seg-title i { color: #16a34a; }
.ei-seg-draft .ei-seg-title i { color: #d97706; }
.ei-seg-done  .ei-seg-title i { color: #2563eb; }
.ei-seg-count {
  font-size: .76rem; font-weight: 700; color: var(--t2); background: var(--bg3);
  border: 1px solid var(--border); border-radius: 20px; padding: 1px 9px; min-width: 22px; text-align: center;
}
.ei-seg-hint { font-size: .8rem; color: var(--t3); }

/* Card grid */
.ei-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 14px; }

/* Card */
.ei-card {
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  overflow: hidden; display: flex; box-shadow: var(--sh); transition: box-shadow .18s, transform .12s;
}
.ei-card:hover { box-shadow: 0 6px 22px var(--gold-ring); transform: translateY(-2px); }
.ei-card-accent { width: 5px; flex-shrink: 0; }
.ei-status-pub   { background: #16a34a; }
.ei-status-draft { background: #d97706; }
.ei-status-done  { background: #2563eb; }

.ei-card-body { flex: 1; padding: 15px 16px; display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.ei-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.ei-card-title {
  font-size: 1.02rem; font-weight: 700; color: var(--t1); line-height: 1.3;
  overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.ei-card-meta { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.ei-badge {
  display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: .72rem;
  font-weight: 700; letter-spacing: .03em; color: #fff; white-space: nowrap;
}
.ei-badge-type.ei-type-formal { background: #2563eb; }
.ei-badge-type.ei-type-other  { background: #7c3aed; }
.ei-badge.ei-status-pub   { background: #16a34a; }
.ei-badge.ei-status-draft { background: #d97706; }
.ei-badge.ei-status-done  { background: #2563eb; }
.ei-meta-text { font-size: .78rem; color: var(--t3); display: inline-flex; align-items: center; gap: 4px; }

/* Lifecycle readiness track */
.ei-track { display: flex; align-items: center; gap: 0; padding: 4px 2px 2px; }
.ei-track-step { display: flex; flex-direction: column; align-items: center; gap: 4px; flex-shrink: 0; }
.ei-track-dot {
  width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
  background: var(--bg3); border: 1px solid var(--border); color: var(--t3); font-size: .72rem; transition: all .18s;
}
.ei-track-lbl { font-size: .66rem; color: var(--t3); font-weight: 600; letter-spacing: .02em; }
.ei-track-bar { flex: 1; height: 2px; background: var(--border); margin: 0 4px; margin-bottom: 16px; min-width: 18px; }
.ei-track-step.is-done .ei-track-dot { background: var(--gold); border-color: var(--gold); color: #fff; }
.ei-track-step.is-done .ei-track-lbl { color: var(--t2); }
.ei-track-bar.is-done { background: var(--gold); }

/* Card footer */
.ei-card-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  border-top: 1px solid var(--border); padding-top: 11px; margin-top: 2px;
}
.ei-meta-id { font-size: .74rem; color: var(--t3); opacity: .8; display: inline-flex; align-items: center; gap: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ei-card-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.ei-btn-view {
  padding: 7px 15px; background: var(--gold-dim); color: var(--gold); border: 1px solid var(--gold-ring);
  border-radius: 7px; font-size: .82rem; font-weight: 600; text-decoration: none;
  display: inline-flex; align-items: center; gap: 6px; transition: background .18s; white-space: nowrap;
}
.ei-btn-view:hover { background: var(--gold); color: #fff; }
.ei-btn-del {
  width: 34px; height: 34px; background: rgba(239,68,68,.1); color: #ef4444; border: 1px solid rgba(239,68,68,.2);
  border-radius: 7px; cursor: pointer; font-size: .84rem; display: inline-flex; align-items: center; justify-content: center; transition: background .18s;
}
.ei-btn-del:hover { background: #ef4444; color: #fff; }

/* Empty state */
.ei-empty { text-align: center; padding: 52px 24px; color: var(--t3); }
.ei-empty i { font-size: 2.8rem; color: var(--gold-ring); display: block; margin-bottom: 14px; }
.ei-empty p { font-size: .95rem; margin: 0; }

/* Delete modal */
.ei-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px; }
.ei-modal { background: var(--bg2); border-radius: 12px; padding: 32px 28px 24px; max-width: 440px; width: 100%; text-align: center; box-shadow: 0 8px 40px rgba(0,0,0,.35); animation: ei-modal-in .2s ease; }
@keyframes ei-modal-in { from { transform: scale(.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.ei-modal-icon { font-size: 2.4rem; color: #ef4444; margin-bottom: 14px; }
.ei-modal-title { font-size: 1.15rem; font-weight: 700; color: var(--t1); margin-bottom: 10px; }
.ei-modal-body { font-size: .9rem; color: var(--t2); line-height: 1.6; margin-bottom: 22px; }
.ei-modal-warn { margin-top: 12px; padding: 9px 12px; background: rgba(217,119,6,.1); border: 1px solid rgba(217,119,6,.25); border-radius: 8px; color: #b45309; font-size: .82rem; text-align: left; }
.ei-modal-warn i { margin-right: 5px; }
.ei-modal-actions { display: flex; gap: 10px; justify-content: center; }
.ei-modal-cancel { padding: 9px 22px; border: 1px solid var(--border); border-radius: 7px; background: var(--bg3); color: var(--t2); font-size: .9rem; font-weight: 600; cursor: pointer; transition: background .18s; }
.ei-modal-cancel:hover { background: var(--border); }
.ei-modal-confirm { padding: 9px 22px; background: #ef4444; color: #fff; border: none; border-radius: 7px; font-size: .9rem; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; transition: background .18s; }
.ei-modal-confirm:hover { background: #dc2626; color: #fff; }

/* Responsive */
@media (max-width: 640px) {
  .ei-grid { grid-template-columns: 1fr; }
  .ei-stat { min-width: calc(50% - 6px); }
  .ei-track-lbl { font-size: .6rem; }
}
</style>
