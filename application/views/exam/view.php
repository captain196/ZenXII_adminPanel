<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<?php
  $examName     = htmlspecialchars($exam['Name']          ?? 'Untitled');
  $examType     = htmlspecialchars($exam['Type']          ?? '—');
  $examStatus   = htmlspecialchars($exam['Status']        ?? 'Draft');
  $gradingScale = htmlspecialchars($exam['GradingScale']  ?? '—');
  $passingPct   = $exam['PassingPercent'] ?? null;
  $startDate    = htmlspecialchars($exam['StartDate']     ?? '—');
  $endDate      = htmlspecialchars($exam['EndDate']       ?? '—');
  $createdBy    = htmlspecialchars($exam['CreatedBy']     ?? '—');
  $createdAt    = !empty($exam['CreatedAt'])
                    ? date('d M Y, h:i A', (int)($exam['CreatedAt'] / 1000))
                    : '—';
  $instructions = is_array($exam['GeneralInstructions'] ?? null)
                    ? $exam['GeneralInstructions'] : [];
  $schedule     = is_array($exam['Schedule'] ?? null) ? $exam['Schedule'] : [];

  $statusCls = match($examStatus) {
    'Published' => 'ev-s-pub',
    'Completed' => 'ev-s-done',
    default     => 'ev-s-draft',
  };

  // Scales that don't use a numeric passing %
  $scalesNoPass = ['A-F Grades', 'O-E Grades', 'Pass/Fail'];
  $showPassPct  = !in_array($exam['GradingScale'] ?? '', $scalesNoPass, true);

  // Flatten schedule: Schedule[classKey][sectionKey][date][subject] = {...}
  $byDate       = [];
  $allClasses   = [];
  $totalEntries = 0;
  foreach ($schedule as $classKey => $sectionData) {
    if (!is_array($sectionData)) continue;
    foreach ($sectionData as $sectionKey => $dateData) {
      if (!is_array($dateData)) continue;
      foreach ($dateData as $dateKey => $subjectData) {
        if (!is_array($subjectData)) continue;
        foreach ($subjectData as $subject => $details) {
          $byDate[$dateKey][$classKey][$sectionKey][$subject] = $details;
          $allClasses[] = $classKey;
          $totalEntries++;
        }
      }
    }
  }
  // EX-3 FIX: Sort dates chronologically, not lexicographically (DD-MM-YYYY string sort is wrong)
  uksort($byDate, function($a, $b) {
      $dA = DateTime::createFromFormat('d-m-Y', $a);
      $dB = DateTime::createFromFormat('d-m-Y', $b);
      return ($dA ? $dA->getTimestamp() : 0) <=> ($dB ? $dB->getTimestamp() : 0);
  });
  $uniqueClasses = count(array_unique($allClasses));
  $totalDates    = count($byDate);
?>

<div class="content-wrapper">
<div class="ev-wrap">

  <!-- ── Hero Banner ────────────────────────────────────────────────────── -->
  <div class="ev-hero">
    <div class="ev-hero-inner">
      <div class="ev-hero-left">
        <div class="ev-hero-title">
          <i class="fa fa-file-text-o"></i>
          <?= $examName ?>
        </div>
        <ol class="ev-breadcrumb">
          <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
          <li><a href="<?= base_url('exam') ?>">Exams</a></li>
          <li><?= $examName ?></li>
        </ol>
        <div class="ev-hero-badges">
          <span class="ev-badge ev-badge-type"><?= $examType ?></span>
          <span class="ev-badge <?= $statusCls ?>"><?= $examStatus ?></span>
          <span class="ev-badge ev-badge-id"><i class="fa fa-tag"></i> <?= htmlspecialchars($examId) ?></span>
        </div>
      </div>
      <div class="ev-hero-actions">
        <a href="<?= base_url('exam') ?>" class="ev-btn-back">
          <i class="fa fa-arrow-left"></i> Back
        </a>
        <button type="button" class="ev-btn-del" onclick="evConfirmDelete()">
          <i class="fa fa-trash"></i> Delete
        </button>
      </div>
    </div>
  </div>

  <!-- ── Quick Stats ─────────────────────────────────────────────────────── -->
  <div class="ev-stats">
    <div class="ev-stat-card">
      <div class="ev-stat-icon-wrap ev-si-teal"><i class="fa fa-calendar-o"></i></div>
      <div class="ev-stat-info">
        <div class="ev-stat-val"><?= $startDate ?></div>
        <div class="ev-stat-sub">to <?= $endDate ?></div>
        <div class="ev-stat-label">Exam Period</div>
      </div>
    </div>
    <div class="ev-stat-card">
      <div class="ev-stat-icon-wrap ev-si-blue"><i class="fa fa-graduation-cap"></i></div>
      <div class="ev-stat-info">
        <div class="ev-stat-val"><?= $uniqueClasses ?></div>
        <div class="ev-stat-label">Classes Covered</div>
      </div>
    </div>
    <div class="ev-stat-card">
      <div class="ev-stat-icon-wrap ev-si-purple"><i class="fa fa-book"></i></div>
      <div class="ev-stat-info">
        <div class="ev-stat-val"><?= $totalEntries ?></div>
        <div class="ev-stat-label">Schedule Entries</div>
      </div>
    </div>
    <div class="ev-stat-card">
      <div class="ev-stat-icon-wrap ev-si-amber"><i class="fa fa-bar-chart"></i></div>
      <div class="ev-stat-info">
        <div class="ev-stat-val"><?= $showPassPct ? (($passingPct ?? '—') . '%') : 'N/A' ?></div>
        <div class="ev-stat-label"><?= $gradingScale ?></div>
      </div>
    </div>
  </div>

  <!-- ── Status Update Bar ──────────────────────────────────────────────── -->
  <div class="ev-status-bar">
    <span class="ev-status-bar-label"><i class="fa fa-refresh"></i> Change Status:</span>
    <select id="statusSelect" class="ev-status-sel">
      <option value="Draft"     <?= $examStatus === 'Draft'     ? 'selected' : '' ?>>Draft</option>
      <option value="Published" <?= $examStatus === 'Published' ? 'selected' : '' ?>>Published</option>
      <option value="Completed" <?= $examStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
    </select>
    <button type="button" class="ev-btn-update-status" id="updateStatusBtn" onclick="evUpdateStatus()">
      <i class="fa fa-check"></i> Update
    </button>
    <span class="ev-status-msg" id="statusMsg"></span>
  </div>

  <!-- ── Two-Column Layout ──────────────────────────────────────────────── -->
  <div class="ev-layout">

    <!-- ══ LEFT ══════════════════════════════════════════════════════════ -->
    <div class="ev-left">

      <!-- Schedule -->
      <div class="ev-card">
        <div class="ev-card-head">
          <i class="fa fa-calendar"></i> Exam Schedule
          <span class="ev-head-chip"><?= $totalDates ?> date<?= $totalDates !== 1 ? 's' : '' ?></span>
        </div>
        <div class="ev-card-body ev-sched-body">
          <?php if (empty($byDate)): ?>
          <div class="ev-empty">
            <i class="fa fa-calendar-o"></i>
            <p>No schedule entries found for this exam.</p>
          </div>
          <?php else: ?>
          <?php foreach ($byDate as $date => $classData):
            // count subjects in this date block
            $dateCnt = 0;
            foreach ($classData as $sData) { foreach ($sData as $subs) { $dateCnt += count($subs); } }
          ?>
          <div class="ev-date-block">
            <div class="ev-date-label">
              <i class="fa fa-calendar-check-o"></i>
              <?= htmlspecialchars($date) ?>
              <span class="ev-date-chip"><?= $dateCnt ?> subject<?= $dateCnt !== 1 ? 's' : '' ?></span>
            </div>
            <?php foreach ($classData as $classKey => $sectionData): ?>
            <?php foreach ($sectionData as $sectionKey => $subjects): ?>
            <div class="ev-class-label">
              <i class="fa fa-graduation-cap"></i>
              <?= htmlspecialchars($classKey) ?> &mdash; <?= htmlspecialchars($sectionKey) ?>
              <span class="ev-class-chip"><?= count($subjects) ?> subject<?= count($subjects) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="ev-table-wrap">
              <table class="ev-sched-table">
                <thead>
                  <tr>
                    <th>Subject &amp; Time</th>
                    <th style="text-align:center">Total Marks</th>
                    <?php if ($showPassPct): ?><th style="text-align:center">Passing Marks</th><?php endif; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($subjects as $subject => $details): ?>
                  <tr>
                    <td>
                      <div class="ev-subj-name">
                        <i class="fa fa-circle ev-subj-dot"></i>
                        <?= htmlspecialchars($subject) ?>
                      </div>
                      <div class="ev-subj-time">
                        <i class="fa fa-clock-o"></i>
                        <?= htmlspecialchars($details['Time'] ?? '—') ?>
                      </div>
                    </td>
                    <td style="text-align:center">
                      <span class="ev-mark ev-mark-total">
                        <?= htmlspecialchars((string)($details['TotalMarks'] ?? '—')) ?>
                      </span>
                    </td>
                    <?php if ($showPassPct): ?>
                    <td style="text-align:center">
                      <span class="ev-mark ev-mark-pass">
                        <?= htmlspecialchars((string)($details['PassingMarks'] ?? '—')) ?>
                      </span>
                    </td>
                    <?php endif; ?>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- General Instructions -->
      <?php if (!empty($instructions)): ?>
      <div class="ev-card">
        <div class="ev-card-head"><i class="fa fa-list-ul"></i> General Instructions</div>
        <div class="ev-card-body">
          <ol class="ev-instructions">
            <?php foreach ($instructions as $item): ?>
            <li><?= htmlspecialchars((string) $item) ?></li>
            <?php endforeach; ?>
          </ol>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /.ev-left -->

    <!-- ══ RIGHT — Metadata ═══════════════════════════════════════════════ -->
    <div class="ev-right">
      <div class="ev-meta-card">
        <div class="ev-meta-head"><i class="fa fa-info-circle"></i> Exam Details</div>
        <div class="ev-meta-body">

          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-tag"></i> Exam ID</span>
            <span class="ev-meta-val ev-mono"><?= htmlspecialchars($examId) ?></span>
          </div>
          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-list-alt"></i> Type</span>
            <span class="ev-meta-val">
              <span class="ev-mini-badge ev-mini-type"><?= $examType ?></span>
            </span>
          </div>
          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-signal"></i> Status</span>
            <span class="ev-meta-val">
              <span class="ev-mini-badge <?= $statusCls ?>"><?= $examStatus ?></span>
            </span>
          </div>
          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-bar-chart"></i> Grading</span>
            <span class="ev-meta-val"><?= $gradingScale ?></span>
          </div>
          <?php if ($showPassPct): ?>
          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-percent"></i> Passing %</span>
            <span class="ev-meta-val ev-meta-pct"><?= htmlspecialchars((string)($passingPct ?? '—')) ?>%</span>
          </div>
          <?php endif; ?>

          <div class="ev-meta-divider"></div>

          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-play-circle"></i> Start</span>
            <span class="ev-meta-val"><?= $startDate ?></span>
          </div>
          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-stop-circle"></i> End</span>
            <span class="ev-meta-val"><?= $endDate ?></span>
          </div>

          <div class="ev-meta-divider"></div>

          <div class="ev-meta-row">
            <span class="ev-meta-label"><i class="fa fa-user-circle-o"></i> Created By</span>
            <span class="ev-meta-val ev-mono"><?= $createdBy ?></span>
          </div>
          <div class="ev-meta-row ev-meta-row-col">
            <span class="ev-meta-label"><i class="fa fa-clock-o"></i> Created At</span>
            <span class="ev-meta-val ev-meta-date"><?= $createdAt ?></span>
          </div>

        </div>
      </div>
    </div><!-- /.ev-right -->

  </div><!-- /.ev-layout -->

</div><!-- /.ev-wrap -->
</div><!-- /.content-wrapper -->


<!-- ── Delete Confirm Modal ──────────────────────────────────────────────── -->
<div id="evDelModal" class="ev-modal-overlay" style="display:none;">
  <div class="ev-modal">
    <div class="ev-modal-icon"><i class="fa fa-exclamation-triangle"></i></div>
    <div class="ev-modal-title">Delete Exam?</div>
    <div class="ev-modal-body">
      You are about to permanently delete <strong><?= $examName ?></strong>.
      All per-section schedule copies will also be removed. This cannot be undone.
    </div>
    <div class="ev-modal-actions">
      <button type="button" class="ev-modal-cancel" onclick="evCloseModal()">Cancel</button>
      <a href="<?= base_url('exam/delete/' . urlencode($examId)) ?>" class="ev-modal-confirm">
        <i class="fa fa-trash"></i> Delete
      </a>
    </div>
  </div>
</div>

<!-- Toast container -->
<div id="evToastWrap" class="ev-toast-wrap"></div>


<script>
(function () {
  'use strict';

  /* ── Delete modal ── */
  window.evConfirmDelete = function () {
    document.getElementById('evDelModal').style.display = 'flex';
  };
  window.evCloseModal = function () {
    document.getElementById('evDelModal').style.display = 'none';
  };
  document.getElementById('evDelModal').addEventListener('click', function (e) {
    if (e.target === this) evCloseModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') evCloseModal();
  });

  /* ── Status update ── */
  window.evUpdateStatus = function () {
    var btn    = document.getElementById('updateStatusBtn');
    var status = document.getElementById('statusSelect').value;
    var msg    = document.getElementById('statusMsg');

    var csrfNameMeta = document.querySelector('meta[name="csrf-name"]');
    var csrfTokenMeta= document.querySelector('meta[name="csrf-token"]');
    var csrfName  = csrfNameMeta  ? csrfNameMeta.getAttribute('content')  : '';
    var csrfToken = csrfTokenMeta ? csrfTokenMeta.getAttribute('content') : '';

    var fd = new FormData();
    if (csrfName) fd.append(csrfName, csrfToken);
    fd.append('examId', '<?= htmlspecialchars($examId) ?>');
    fd.append('status', status);

    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Updating…';
    msg.textContent = '';

    fetch('<?= base_url('exam/update_status') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Update';
        if (res.status === 'success') {
          showEvToast(res.message || 'Status updated.', 'success');
          if (res.csrf_token && csrfTokenMeta) csrfTokenMeta.setAttribute('content', res.csrf_token);
        } else {
          showEvToast(res.message || 'Failed to update status.', 'error');
          if (res.csrf_token && csrfTokenMeta) csrfTokenMeta.setAttribute('content', res.csrf_token);
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Update';
        showEvToast('Server error. Please try again.', 'error');
      });
  };

  /* ── Toast ── */
  function showEvToast(msg, type) {
    var wrap  = document.getElementById('evToastWrap');
    var el    = document.createElement('div');
    var icons = { success:'check-circle', error:'times-circle', warning:'exclamation-triangle', info:'info-circle' };
    el.className = 'ev-toast ev-toast-' + (type || 'info');
    el.innerHTML = '<i class="fa fa-' + (icons[type] || 'info-circle') + '"></i> ' + msg;
    wrap.appendChild(el);
    setTimeout(function () {
      el.classList.add('ev-toast-fade');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 400);
    }, 3500);
  }

})();
</script>


<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Exam View — .ev-*
═══════════════════════════════════════════════════════════ */
.ev-wrap { max-width: 1140px; margin: 0 auto; padding: 0 16px 56px; }

/* ── Hero ── */
.ev-hero {
  background: linear-gradient(135deg, var(--gold) 0%, var(--gold2) 100%);
  border-radius: 0 0 16px 16px;
  margin: 0 -16px 24px;
  padding: 28px 24px 24px;
  position: relative;
  overflow: hidden;
}
.ev-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.ev-hero-inner {
  position: relative;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
}
.ev-hero-title {
  font-size: 1.6rem;
  font-weight: 800;
  color: #fff;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
  line-height: 1.2;
}
.ev-hero-title i { opacity: .85; }
.ev-breadcrumb {
  list-style: none; margin: 0 0 12px; padding: 0;
  display: flex; gap: 5px; font-size: .78rem;
  color: rgba(255,255,255,.65);
}
.ev-breadcrumb li + li::before { content: '›'; margin-right: 5px; }
.ev-breadcrumb a { color: rgba(255,255,255,.85); text-decoration: none; }
.ev-breadcrumb a:hover { color: #fff; text-decoration: underline; }

.ev-hero-badges { display: flex; gap: 7px; flex-wrap: wrap; }
.ev-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 4px 12px; border-radius: 20px; font-size: .74rem; font-weight: 700;
}
.ev-badge-type  { background: rgba(37,99,235,.85); color: #fff; backdrop-filter: blur(4px); }
.ev-badge-id    { background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.3); font-family: monospace; }
.ev-s-pub       { background: #16a34a; color: #fff; }
.ev-s-draft     { background: #d97706; color: #fff; }
.ev-s-done      { background: rgba(255,255,255,.25); color: #fff; border: 1px solid rgba(255,255,255,.35); }

.ev-hero-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }
.ev-btn-back {
  padding: 8px 18px;
  background: rgba(255,255,255,.18);
  color: #fff;
  border: 1px solid rgba(255,255,255,.3);
  border-radius: 7px;
  text-decoration: none;
  font-size: .86rem;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background .18s;
  backdrop-filter: blur(4px);
}
.ev-btn-back:hover { background: rgba(255,255,255,.28); color: #fff; }
.ev-btn-del {
  padding: 8px 18px;
  background: rgba(239,68,68,.2);
  color: #fca5a5;
  border: 1px solid rgba(239,68,68,.4);
  border-radius: 7px;
  font-size: .86rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background .18s;
  backdrop-filter: blur(4px);
}
.ev-btn-del:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

/* ── Quick Stats ── */
.ev-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 18px;
}
@media (max-width: 860px) { .ev-stats { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px) { .ev-stats { grid-template-columns: 1fr; } }

.ev-stat-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: var(--sh);
  transition: transform .18s, box-shadow .18s;
}
.ev-stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px var(--gold-ring); }

.ev-stat-icon-wrap {
  width: 44px; height: 44px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.15rem; flex-shrink: 0;
}
.ev-si-teal   { background: var(--gold-dim); color: var(--gold); }
.ev-si-blue   { background: rgba(37,99,235,.1); color: #2563eb; }
.ev-si-purple { background: rgba(124,58,237,.1); color: #7c3aed; }
.ev-si-amber  { background: rgba(217,119,6,.1); color: #d97706; }

.ev-stat-val   { font-size: 1.1rem; font-weight: 700; color: var(--t1); line-height: 1.2; }
.ev-stat-sub   { font-size: .72rem; color: var(--t3); }
.ev-stat-label { font-size: .74rem; color: var(--t3); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; margin-top: 1px; }

/* ── Status bar ── */
.ev-status-bar {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 20px;
  box-shadow: var(--sh);
}
.ev-status-bar-label { font-size: .84rem; font-weight: 600; color: var(--t2); white-space: nowrap; display: flex; align-items: center; gap: 6px; }
.ev-status-sel {
  padding: 7px 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .86rem;
}
.ev-status-sel:focus { outline: none; border-color: var(--gold); }
.ev-btn-update-status {
  padding: 7px 18px;
  background: var(--gold);
  color: #fff;
  border: none;
  border-radius: 6px;
  font-size: .86rem;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: background .18s;
}
.ev-btn-update-status:hover:not(:disabled) { background: var(--gold2); }
.ev-btn-update-status:disabled { opacity: .65; cursor: not-allowed; }
.ev-status-msg { font-size: .82rem; color: var(--t3); }

/* ── Layout ── */
.ev-layout { display: flex; gap: 20px; align-items: flex-start; }
.ev-left  { flex: 1; min-width: 0; }
.ev-right { width: 280px; flex-shrink: 0; position: sticky; top: 16px; }
@media (max-width: 820px) {
  .ev-layout { flex-direction: column; }
  .ev-right  { width: 100%; position: static; }
}

/* ── Cards ── */
.ev-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  margin-bottom: 20px;
  overflow: hidden;
  box-shadow: var(--sh);
}
.ev-card-head {
  background: var(--gold);
  color: #fff;
  font-size: .92rem;
  font-weight: 600;
  padding: 11px 18px;
  display: flex;
  align-items: center;
  gap: 9px;
}
.ev-head-chip {
  margin-left: auto;
  background: rgba(255,255,255,.2);
  padding: 2px 10px;
  border-radius: 20px;
  font-size: .74rem;
  font-weight: 700;
}
.ev-card-body { padding: 20px; }
.ev-sched-body { padding: 0 !important; }

/* ── Schedule ── */
.ev-date-block { border-bottom: 1px solid var(--border); }
.ev-date-block:last-child { border-bottom: none; }

.ev-date-label {
  background: var(--gold-dim);
  border-bottom: 1px solid var(--border);
  padding: 9px 18px;
  font-size: .86rem;
  font-weight: 700;
  color: var(--gold);
  display: flex;
  align-items: center;
  gap: 8px;
}
.ev-date-chip {
  margin-left: auto;
  background: var(--gold);
  color: #fff;
  font-size: .7rem;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 20px;
}

.ev-class-label {
  padding: 7px 18px;
  font-size: .82rem;
  font-weight: 600;
  color: var(--t2);
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 7px;
}
.ev-class-chip {
  margin-left: auto;
  font-size: .7rem;
  color: var(--t3);
  font-weight: 500;
}

.ev-table-wrap { overflow-x: auto; }
.ev-sched-table { width: 100%; border-collapse: collapse; min-width: 420px; }
.ev-sched-table th {
  background: var(--bg3);
  color: var(--t2);
  font-size: .75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
  padding: 8px 16px;
  text-align: left;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.ev-sched-table td {
  padding: 10px 16px;
  border-bottom: 1px solid var(--border);
  font-size: .88rem;
  color: var(--t1);
  vertical-align: middle;
}
.ev-sched-table tr:last-child td { border-bottom: none; }
.ev-sched-table tbody tr:hover td { background: var(--gold-dim); }

.ev-subj-name {
  font-weight: 600;
  color: var(--t1);
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 3px;
}
.ev-subj-dot { font-size: .5rem; color: var(--gold); flex-shrink: 0; }
.ev-subj-time {
  font-size: .8rem;
  color: var(--t3);
  display: flex;
  align-items: center;
  gap: 5px;
  padding-left: 14px;
}

.ev-mark {
  display: inline-block;
  padding: 3px 11px;
  border-radius: 20px;
  font-size: .82rem;
  font-weight: 700;
  min-width: 44px;
  text-align: center;
}
.ev-mark-total { background: var(--gold-dim); color: var(--gold); border: 1px solid var(--gold-ring); }
.ev-mark-pass  { background: rgba(22,163,74,.1); color: #16a34a; border: 1px solid rgba(22,163,74,.2); }

/* ── Instructions ── */
.ev-instructions { margin: 0; padding-left: 20px; }
.ev-instructions li {
  padding: 6px 0;
  font-size: .9rem;
  color: var(--t1);
  line-height: 1.65;
  border-bottom: 1px dashed var(--border);
}
.ev-instructions li:last-child { border-bottom: none; }

/* ── Empty state ── */
.ev-empty { padding: 40px 24px; text-align: center; color: var(--t3); }
.ev-empty i { font-size: 2.2rem; display: block; margin-bottom: 12px; color: var(--gold-ring); }
.ev-empty p { margin: 0; font-size: .9rem; }

/* ── Metadata card ── */
.ev-meta-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  box-shadow: var(--sh);
}
.ev-meta-head {
  background: var(--gold);
  color: #fff;
  font-size: .9rem;
  font-weight: 600;
  padding: 11px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.ev-meta-body { padding: 4px 0; }
.ev-meta-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 9px 16px;
  border-bottom: 1px solid var(--border);
}
.ev-meta-row:last-child { border-bottom: none; }
.ev-meta-row-col { flex-direction: column; align-items: flex-start; gap: 3px; }

.ev-meta-label {
  font-size: .74rem;
  font-weight: 600;
  color: var(--t3);
  text-transform: uppercase;
  letter-spacing: .04em;
  white-space: nowrap;
  display: flex;
  align-items: center;
  gap: 5px;
}
.ev-meta-val { font-size: .87rem; color: var(--t1); font-weight: 500; text-align: right; }
.ev-meta-pct { color: var(--gold); font-weight: 700; font-size: .95rem; }
.ev-meta-date { font-size: .82rem; color: var(--t2); }
.ev-mono { font-family: monospace; font-size: .83rem; letter-spacing: .02em; }
.ev-meta-divider { border-top: 1px solid var(--border); margin: 2px 0; }

.ev-mini-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: .74rem;
  font-weight: 700;
  color: #fff;
}
.ev-mini-type  { background: #2563eb; }
.ev-s-pub.ev-mini-badge  { background: #16a34a; }
.ev-s-draft.ev-mini-badge{ background: #d97706; }
.ev-s-done.ev-mini-badge { background: var(--gold); }

/* ── Delete Modal ── */
.ev-modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,.52);
  z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 16px;
}
.ev-modal {
  background: var(--bg2); border-radius: 14px; padding: 32px 28px 24px;
  max-width: 420px; width: 100%; text-align: center;
  box-shadow: 0 12px 48px rgba(0,0,0,.4); animation: ev-modal-in .2s ease;
}
@keyframes ev-modal-in { from{transform:scale(.9);opacity:0} to{transform:scale(1);opacity:1} }
.ev-modal-icon { font-size: 2.4rem; color: #ef4444; margin-bottom: 14px; }
.ev-modal-title { font-size: 1.15rem; font-weight: 700; color: var(--t1); margin-bottom: 10px; }
.ev-modal-body { font-size: .9rem; color: var(--t2); line-height: 1.6; margin-bottom: 22px; }
.ev-modal-actions { display: flex; gap: 10px; justify-content: center; }
.ev-modal-cancel {
  padding: 9px 22px; border: 1px solid var(--border); border-radius: 7px;
  background: var(--bg3); color: var(--t2); font-size: .9rem; font-weight: 600;
  cursor: pointer; transition: background .18s;
}
.ev-modal-cancel:hover { background: var(--border); }
.ev-modal-confirm {
  padding: 9px 22px; background: #ef4444; color: #fff; border: none;
  border-radius: 7px; font-size: .9rem; font-weight: 600; cursor: pointer;
  text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
  transition: background .18s;
}
.ev-modal-confirm:hover { background: #dc2626; color: #fff; }

/* ── Toast ── */
.ev-toast-wrap { position: fixed; bottom: 24px; right: 24px; display: flex; flex-direction: column; gap: 10px; z-index: 9999; }
.ev-toast { padding: 11px 18px; border-radius: 8px; font-size: .86rem; font-weight: 500; color: #fff; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.25); animation: ev-slide-in .3s ease; min-width: 240px; }
.ev-toast-success { background: #0f766e; }
.ev-toast-error   { background: #dc2626; }
.ev-toast-warning { background: #d97706; }
.ev-toast-info    { background: #2563eb; }
.ev-toast-fade    { opacity: 0; transition: opacity .4s; }
@keyframes ev-slide-in { from{transform:translateX(60px);opacity:0} to{transform:translateX(0);opacity:1} }
</style>
