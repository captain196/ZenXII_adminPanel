<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Batch Report Cards — renders report_card.php for each student in a section
 * with CSS page-break-before between students. Standalone (no header/footer).
 *
 * Expects: $students[] — each item has the same keys as report_card.php expects
 *          (userId, examId, exam, profile, classKey, sectionKey, computed,
 *           templates, marks, schoolInfo, schoolName, sessionYear)
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Batch Report Cards — <?= htmlspecialchars($exam['Name'] ?? 'Exam') ?> — <?= htmlspecialchars($classKey ?? '') ?> <?= htmlspecialchars($sectionKey ?? '') ?></title>
<style>
  /* ── Batch toolbar (hidden on print) ─────────────────────────────── */
  .batch-toolbar {
    position: sticky; top: 0; z-index: 100;
    display: flex; align-items: center; gap: 14px;
    padding: 10px 20px; background: #0f766e; color: #fff;
    font-family: 'Segoe UI', sans-serif;
  }
  .batch-toolbar button {
    padding: 7px 18px; background: #fff; color: #0f766e;
    border: none; border-radius: 6px; font-weight: 700;
    font-size: .9rem; cursor: pointer;
  }
  .batch-toolbar button:hover { background: #e0f2f1; }
  .batch-toolbar .batch-info { font-size: .9rem; opacity: .9; }

  /* Page break between report cards */
  .batch-page-break { page-break-before: always; }

  /* ── Per-student error placeholder ─────────────────────────────── */
  .batch-error-card {
    max-width: 700px; margin: 40px auto; padding: 36px 30px;
    background: #fff; border: 2px dashed #fca5a5; border-radius: 10px;
    text-align: center; font-family: 'Segoe UI', sans-serif;
  }
  .batch-error-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 48px; height: 48px; border-radius: 50%;
    background: #fee2e2; color: #dc2626; font-size: 28px; font-weight: 700;
    margin-bottom: 12px;
  }
  .batch-error-title { font-size: 17px; font-weight: 700; color: #b91c1c; margin-bottom: 6px; }
  .batch-error-detail { font-size: 14px; color: #374151; margin-bottom: 4px; }
  .batch-error-msg { font-size: 12px; color: #9ca3af; word-break: break-word; margin-top: 8px; }

  /* ── Summary banner (sticky at bottom) ───────────────────────────── */
  .batch-summary-banner {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 200;
    background: #fef2f2; border-top: 2px solid #fca5a5;
    font-family: 'Segoe UI', sans-serif; font-size: 14px;
    box-shadow: 0 -2px 12px rgba(0,0,0,.1);
  }
  .batch-summary-inner {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 20px; flex-wrap: wrap;
  }
  .batch-summary-icon { font-size: 22px; color: #dc2626; }
  .batch-summary-text { flex: 1; color: #7f1d1d; }
  .batch-summary-toggle {
    padding: 4px 14px; background: #fff; color: #b91c1c;
    border: 1px solid #fca5a5; border-radius: 4px; font-size: 13px;
    cursor: pointer;
  }
  .batch-summary-toggle:hover { background: #fef2f2; }
  .batch-summary-dismiss {
    background: none; border: none; font-size: 20px; color: #b91c1c;
    cursor: pointer; padding: 0 6px; line-height: 1;
  }
  .batch-fail-list {
    margin: 0; padding: 6px 20px 10px 48px;
    list-style: disc; color: #7f1d1d; font-size: 13px;
    max-height: 150px; overflow-y: auto;
  }
  .batch-fail-list li { padding: 2px 0; }

  @media print {
    .batch-toolbar { display: none !important; }
    .batch-page-break { page-break-before: always; }
    .batch-error-card { border-color: #999; }
    .batch-error-card .batch-error-msg { display: none; }
    .batch-summary-banner { display: none !important; }
  }
</style>
</head>
<body style="margin:0; padding:0; background:#f5f5f5;">

<!-- ── Toolbar ────────────────────────────────────────────────────── -->
<div class="batch-toolbar">
  <button onclick="window.print()"><i class="fa fa-print"></i> Print All (<?= count($students) ?>)</button>
  <?php
    $examIdEnc     = urlencode($exam['id'] ?? '');
    $classKeyEnc   = urlencode($classKey ?? '');
    $sectionKeyEnc = urlencode($sectionKey ?? '');
  ?>
  <a href="<?= site_url("result/download_batch_pdf/{$examIdEnc}/{$classKeyEnc}/{$sectionKeyEnc}") ?>"
     onclick="this.textContent='Generating...';this.style.opacity='0.6';this.style.pointerEvents='none'"
     style="text-decoration:none">
    <i class="fa fa-file-pdf-o"></i> Download ZIP (<?= count($students) ?> PDFs)
  </a>
  <button onclick="window.history.back()">Back</button>
  <span class="batch-info">
    <?= htmlspecialchars($exam['Name'] ?? '') ?> &mdash;
    <?= htmlspecialchars($classKey ?? '') ?> / <?= htmlspecialchars($sectionKey ?? '') ?>
    &mdash; <?= count($students) ?> student(s)
  </span>
</div>

<?php if (empty($students)): ?>
<div style="max-width:600px;margin:60px auto;padding:30px;background:#fff;border-radius:8px;text-align:center;font-family:sans-serif;">
  <h3 style="color:#b91c1c;">No Report Cards Available</h3>
  <p style="color:#6b7280;">No computed results found for this class/section. Please compute results first.</p>
  <button onclick="window.history.back()" style="margin-top:16px;padding:8px 24px;background:#0f766e;color:#fff;border:none;border-radius:6px;cursor:pointer;">Go Back</button>
</div>
<?php else: ?>

<?php
  // Use $this->load->vars() so variables propagate through nested view loading.
  // Local PHP vars in CI3 views do NOT survive 2 levels of $this->load->view().
  $rc_template    = $rc_template ?? 'classic';
  $_batch_errors  = [];   // collect per-student failures
  $_batch_ok      = 0;    // count of successfully rendered cards

  $this->load->vars([
      'batch_mode'        => true,
      'batch_css_emitted' => false,
      'rc_template'       => $rc_template,
  ]);
?>

<?php foreach ($students as $idx => $stu):
  // ── Per-student isolation: ob_start + try/catch ──────────────────
  // If this student's render throws at ANY level (data prep, template,
  // CI3 view loader), we discard partial output, log the error, and
  // continue to the next student.
  ob_start();
  try {
      // Set per-student variables via CI3's var propagation
      $this->load->vars([
          'userId'      => $stu['userId'],
          'examId'      => $stu['examId'],
          'exam'        => $stu['exam'],
          'profile'     => $stu['profile'],
          'classKey'    => $stu['classKey'],
          'sectionKey'  => $stu['sectionKey'],
          'computed'    => $stu['computed'],
          'templates'   => $stu['templates'],
          'marks'       => $stu['marks'],
          'schoolInfo'  => $stu['schoolInfo'],
          'schoolName'  => $stu['schoolName'],
          'sessionYear' => $stu['sessionYear'],
      ]);

      if ($idx > 0) { echo '<div class="batch-page-break"></div>'; }

      $this->load->view('result/report_card');

      // After first student, flag CSS as emitted so templates skip <style> blocks
      if ($idx === 0) {
          $this->load->vars(['batch_css_emitted' => true]);
      }

      // ── Success: flush buffered HTML to the page ──
      ob_end_flush();
      $_batch_ok++;

  } catch (\Throwable $e) {
      // ── Failure: discard any partial/broken HTML this student produced ──
      ob_end_clean();

      $failedId   = htmlspecialchars($stu['userId'] ?? 'unknown');
      $failedName = htmlspecialchars($stu['profile']['Name'] ?? $failedId);

      // Log with full detail for admin debugging
      log_message('error', sprintf(
          'Batch report card FAILED — student=%s name=%s exam=%s class=%s section=%s template=%s error=%s trace=%s',
          $stu['userId'] ?? '?',
          $stu['profile']['Name'] ?? '?',
          $stu['examId'] ?? '?',
          $stu['classKey'] ?? '?',
          $stu['sectionKey'] ?? '?',
          $rc_template,
          $e->getMessage(),
          $e->getTraceAsString()
      ));

      // Collect for summary banner
      $_batch_errors[] = $failedName;

      // Render an inline error placeholder (same page flow, visible on screen, hidden on print)
      if ($idx > 0) { echo '<div class="batch-page-break"></div>'; }
      echo '<div class="batch-error-card">'
         . '<div class="batch-error-icon">!</div>'
         . '<div class="batch-error-title">Report Card Failed</div>'
         . '<div class="batch-error-detail">Student: <strong>' . $failedName . '</strong> (ID: ' . $failedId . ')</div>'
         . '<div class="batch-error-msg">' . htmlspecialchars($e->getMessage()) . '</div>'
         . '</div>';

      // Ensure CSS gets emitted if first student was the one that failed
      if ($idx === 0) {
          $this->load->vars(['batch_css_emitted' => true]);
      }
  }
endforeach; ?>

<?php
// ── Summary banner: shown only when errors occurred ────────────────
if (!empty($_batch_errors)):
    $errCount = count($_batch_errors);
    $total    = count($students);
?>
<div class="batch-summary-banner" id="batchSummaryBanner">
  <div class="batch-summary-inner">
    <span class="batch-summary-icon">&#9888;</span>
    <span class="batch-summary-text">
      <strong><?= $errCount ?> of <?= $total ?> report card<?= $errCount > 1 ? 's' : '' ?> failed</strong>
      &mdash; <?= $_batch_ok ?> rendered successfully.
    </span>
    <button class="batch-summary-toggle" onclick="document.getElementById('batchFailList').style.display=document.getElementById('batchFailList').style.display==='none'?'block':'none'">
      Details
    </button>
    <button class="batch-summary-dismiss" onclick="this.parentNode.parentNode.style.display='none'">&times;</button>
  </div>
  <ul id="batchFailList" class="batch-fail-list" style="display:none">
    <?php foreach ($_batch_errors as $fn): ?>
      <li><?= $fn ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<?php endif; ?>

</body>
</html>
