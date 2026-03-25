<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Minimal Report Card Template — Ultra-clean, no heavy tables.
 * Typography-driven with inline data, maximum whitespace, no borders.
 *
 * UNIQUE STRUCTURE: No table element used. Subjects displayed as
 * horizontal rows with inline marks. Pure div-based layout with
 * clean separators. Focus on readability and negative space.
 */
include(APPPATH . 'views/result/templates/_report_card_data.php');
?>
<?php if (empty($batch_mode)): ?>
<div class="mn-toolbar">
  <a href="<?= site_url('result') ?>" onclick="if(history.length>1){history.back();return false;}" class="mn-btn mn-btn-back">&#8592; Back</a>
  <a href="<?= site_url('result/download_pdf/' . urlencode($userId ?? '') . '/' . urlencode($examId ?? '')) ?>" class="mn-btn mn-btn-print" style="text-decoration:none">&#8681; PDF</a>
  <button class="mn-btn mn-btn-print" onclick="window.print()">Print</button>
</div>
<?php endif; ?>

<div class="mn-wrapper">
  <div class="mn-page">

    <!-- ── HEADER — Table-based 3-column layout ─────────────────────── -->
    <table class="mn-hdr-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="mn-hdr-logo-cell">
          <?php if ($schoolLogoUrl): ?>
            <img src="<?= htmlspecialchars($schoolLogoUrl) ?>" alt="" class="mn-logo-img">
          <?php else: ?>
            <div class="mn-logo-ph"><?= htmlspecialchars(strtoupper(substr($schoolDisplayName, 0, 2))) ?></div>
          <?php endif; ?>
        </td>
        <td class="mn-hdr-center">
          <div class="mn-school"><?= htmlspecialchars($schoolDisplayName) ?></div>
          <?php if ($schoolAddress): ?>
            <div class="mn-school-addr"><?= htmlspecialchars($schoolAddress) ?><?= $schoolCity ? ', ' . htmlspecialchars($schoolCity) : '' ?></div>
          <?php endif; ?>
          <?php
            $metaParts = [];
            if ($schoolBoard) $metaParts[] = htmlspecialchars($schoolBoard);
            if ($schoolAffNo) $metaParts[] = 'Aff. No: ' . htmlspecialchars($schoolAffNo);
            if ($schoolCode)  $metaParts[] = 'Code: ' . htmlspecialchars($schoolCode);
          ?>
          <?php if ($metaParts): ?>
            <div class="mn-meta"><?= implode('&ensp;&middot;&ensp;', $metaParts) ?></div>
          <?php endif; ?>
        </td>
        <td class="mn-hdr-badge-cell">
          <?php if ($schoolBoard): ?>
            <div class="mn-board-badge"><?= htmlspecialchars(strtoupper($schoolBoard)) ?></div>
          <?php else: ?>
            <div class="mn-board-badge">RC</div>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <div class="mn-sep"></div>

    <!-- ── EXAM TAG ───────────────────────────────────────────────── -->
    <div class="mn-exam">
      <span class="mn-exam-name"><?= htmlspecialchars($examName) ?><?= $examType ? ' — ' . htmlspecialchars($examType) : '' ?></span>
      <span class="mn-exam-year"><?= htmlspecialchars($sessionYear ?: '') ?></span>
    </div>

    <!-- ── STUDENT INFO — Simple two-column key/value ─────────────── -->
    <div class="mn-info">
      <div class="mn-info-main">
        <div class="mn-name"><?= htmlspecialchars($studentName ?: '—') ?></div>
        <div class="mn-class-line">
          Class <?= htmlspecialchars($classNameRaw) ?><?= $sectionLetter ? ' / Section ' . htmlspecialchars($sectionLetter) : '' ?>
          <?= $rollNo ? '&emsp;Roll: ' . htmlspecialchars($rollNo) : '' ?>
        </div>
      </div>
      <div class="mn-info-grid">
        <?php if ($fatherName): ?>
          <div class="mn-kv"><span class="mn-k">Father</span><span class="mn-v"><?= htmlspecialchars($fatherName) ?></span></div>
        <?php endif; ?>
        <?php if ($motherName): ?>
          <div class="mn-kv"><span class="mn-k">Mother</span><span class="mn-v"><?= htmlspecialchars($motherName) ?></span></div>
        <?php endif; ?>
        <?php if ($dob): ?>
          <div class="mn-kv"><span class="mn-k">DOB</span><span class="mn-v"><?= htmlspecialchars($dob) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="mn-sep"></div>

    <!-- ── SUBJECTS — Horizontal rows, NO table ───────────────────── -->
    <?php if (empty($subjectRows)): ?>
      <div class="mn-empty">No result data available.</div>
    <?php else: ?>

      <!-- Column labels -->
      <div class="mn-col-hdr">
        <span class="mn-col-subj">Subject</span>
        <span class="mn-col-marks">Marks</span>
        <span class="mn-col-grade">Grade</span>
        <span class="mn-col-status">Status</span>
      </div>

      <div class="mn-subjects">
        <?php foreach ($subjectRows as $idx => $row): ?>
          <div class="mn-subj-row<?= $row['absent'] ? ' mn-subj-absent' : '' ?>">
            <div class="mn-subj-name"><?= htmlspecialchars($row['subject']) ?></div>
            <div class="mn-subj-marks">
              <?php if ($row['absent']): ?>
                <span class="mn-ab">Absent</span>
              <?php else: ?>
                <span class="mn-marks-num"><?= htmlspecialchars($row['total']) ?></span>
                <span class="mn-marks-of">/ <?= htmlspecialchars($row['maxMarks']) ?></span>
              <?php endif; ?>
            </div>
            <div class="mn-subj-grade">
              <?= htmlspecialchars($row['grade'] ?: ($row['absent'] ? '—' : '—')) ?>
            </div>
            <div class="mn-subj-status mn-st-<?= strtolower($row['passFail']) ?>">
              <?= htmlspecialchars($row['absent'] ? 'AB' : ($row['passFail'] ?: '—')) ?>
            </div>
          </div>

          <?php if (!empty($row['comps']) && !$row['absent']): ?>
            <div class="mn-comp-row">
              <?php foreach ($row['comps'] as $cn => $val): ?>
                <span class="mn-comp"><?= htmlspecialchars($cn) ?>: <?= htmlspecialchars($val) ?><?php if (isset($allCompDefs[$cn])): ?><span class="mn-comp-max">/<?= $allCompDefs[$cn] ?></span><?php endif; ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <div class="mn-sep mn-sep-thick"></div>

      <!-- ── SUMMARY — Inline stats, no boxes ──────────────────────── -->
      <div class="mn-summary">
        <div class="mn-sum-primary">
          <span class="mn-sum-total"><?= htmlspecialchars($grandTotal) ?><span class="mn-sum-of">/<?= htmlspecialchars($grandMax) ?></span></span>
          <span class="mn-sum-pct"><?= htmlspecialchars($grandPct) ?>%</span>
        </div>
        <div class="mn-sum-secondary">
          <?php if ($grandGrade): ?>
            <span class="mn-sum-tag">Grade: <strong><?= htmlspecialchars($grandGrade) ?></strong></span>
          <?php endif; ?>
          <?php if ($rank): ?>
            <span class="mn-sum-tag">Rank: <strong><?= htmlspecialchars($rank) ?></strong></span>
          <?php endif; ?>
          <span class="mn-sum-tag mn-st-<?= strtolower($grandPass) ?>">
            <?= htmlspecialchars($grandPass ?: '—') ?>
          </span>
        </div>
      </div>

      <!-- ── GRADE LEGEND ─────────────────────────────────────────── -->
      <?php if ($gradeLegend): ?>
        <div class="mn-legend"><?= $gradeLegend ?></div>
      <?php endif; ?>

    <?php endif; ?>

    <!-- ── RESULT ─────────────────────────────────────────────────── -->
    <div class="mn-result mn-result-<?= $grandPass === 'Pass' ? 'pass' : 'fail' ?>">
      <?= htmlspecialchars($resultText) ?>
    </div>

    <div class="mn-sep"></div>

    <!-- ── SIGNATURES — Minimal lines ─────────────────────────────── -->
    <div class="mn-sigs">
      <div class="mn-sig">
        <div class="mn-sig-line"></div>
        <span>Class Teacher</span>
      </div>
      <div class="mn-sig">
        <div class="mn-sig-line"></div>
        <span>Principal</span>
      </div>
      <div class="mn-sig">
        <div class="mn-sig-line"></div>
        <span>Parent / Guardian</span>
      </div>
    </div>

  </div>
</div>

<?php if (empty($batch_css_emitted)): ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   MINIMAL Report Card — Ultra-clean, No Tables, Pure Typography
   All classes prefixed with mn-
   ═══════════════════════════════════════════════════════════════ */
.mn-wrapper *,.mn-wrapper *::before,.mn-wrapper *::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;font-size:13px;color:#1a1a1a;background:#f4f4f5;-webkit-font-smoothing:antialiased}

/* Toolbar */
.mn-toolbar{max-width:720px;margin:20px auto 14px;display:flex;justify-content:flex-end;gap:8px;padding:0 16px}
.mn-btn{padding:8px 20px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:inherit}
.mn-btn-back{background:#fff;color:#2563eb;border:1.5px solid #2563eb!important}
.mn-btn-back:hover{background:#eff6ff}
.mn-btn-print{background:#2563eb;color:#fff}
.mn-btn-print:hover{background:#1d4ed8}

/* Page */
.mn-wrapper{padding:0 0 40px}
.mn-page{max-width:720px;margin:0 auto;background:#fff;padding:48px 52px;border:1px solid #e4e4e7;border-radius:2px}

/* ── Separator ──────────────────────────────── */
.mn-sep{height:1px;background:#e4e4e7;margin:24px 0}
.mn-sep-thick{height:2px;background:#18181b;margin:20px 0}

/* ── Header (table-based 3-column) ──────────── */
.mn-hdr-tbl {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  margin-bottom: 4px;
}
.mn-hdr-logo-cell {
  width: 56px;
  text-align: center;
  vertical-align: middle;
  padding: 0 4px 0 0;
}
.mn-logo-img {
  width: 44px;
  height: 44px;
  border-radius: 4px;
}
.mn-logo-ph {
  width: 44px;
  height: 44px;
  margin: 0 auto;
  border: 1.5px solid #d4d4d8;
  border-radius: 4px;
  background: #fafafa;
  text-align: center;
  line-height: 44px;
  font-size: 16px;
  font-weight: 700;
  color: #71717a;
}
.mn-hdr-center {
  text-align: center;
  vertical-align: middle;
  padding: 4px 8px;
}
.mn-school {
  font-size: 20px;
  font-weight: 700;
  color: #18181b;
  letter-spacing: -0.01em;
  line-height: 1.2;
}
.mn-school-addr {
  font-size: 10px;
  color: #a1a1aa;
  margin-top: 3px;
}
.mn-meta {
  font-size: 10px;
  color: #71717a;
  margin-top: 4px;
  letter-spacing: 0.03em;
}
.mn-hdr-badge-cell {
  width: 56px;
  text-align: center;
  vertical-align: middle;
  padding: 0 0 0 4px;
}
.mn-board-badge {
  width: 44px;
  height: 44px;
  margin: 0 auto;
  border: 1.5px solid #e4e4e7;
  border-radius: 4px;
  background: #fafafa;
  text-align: center;
  line-height: 44px;
  font-size: 10px;
  font-weight: 700;
  color: #52525b;
  letter-spacing: 0.5px;
}

/* ── Exam Tag ────────────────────────────────── */
.mn-exam{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:24px}
.mn-exam-name{font-size:13px;font-weight:600;color:#2563eb;letter-spacing:.02em}
.mn-exam-year{font-size:12px;color:#a1a1aa}

/* ── Student Info ────────────────────────────── */
.mn-info{margin-bottom:4px}
.mn-info-main{margin-bottom:14px}
.mn-name{font-size:24px;font-weight:700;color:#18181b;letter-spacing:-.02em;line-height:1.1}
.mn-class-line{font-size:13px;color:#52525b;margin-top:4px;font-weight:500}
.mn-info-grid{display:flex;gap:28px;flex-wrap:wrap}
.mn-kv{display:flex;flex-direction:column;gap:1px}
.mn-k{font-size:10px;font-weight:600;color:#a1a1aa;text-transform:uppercase;letter-spacing:.06em}
.mn-v{font-size:13px;color:#3f3f46;font-weight:500}

/* ── Column Header ───────────────────────────── */
.mn-col-hdr{display:flex;align-items:center;padding:0 0 8px;margin-bottom:4px;border-bottom:2px solid #18181b;font-size:10px;font-weight:600;color:#a1a1aa;text-transform:uppercase;letter-spacing:.08em}
.mn-col-subj{flex:1}
.mn-col-marks{width:110px;text-align:right;padding-right:16px}
.mn-col-grade{width:60px;text-align:center}
.mn-col-status{width:60px;text-align:center}

/* ── Subject Rows (NO TABLE) ─────────────────── */
.mn-subjects{margin-bottom:4px}
.mn-subj-row{display:flex;align-items:center;padding:10px 0;border-bottom:1px solid #f4f4f5;transition:background .1s}
.mn-subj-row:last-child{border-bottom:none}
.mn-subj-name{flex:1;font-size:14px;font-weight:600;color:#18181b}
.mn-subj-marks{width:110px;text-align:right;padding-right:16px}
.mn-marks-num{font-size:18px;font-weight:700;color:#18181b}
.mn-marks-of{font-size:12px;color:#a1a1aa;font-weight:400;margin-left:2px}
.mn-subj-grade{width:60px;text-align:center;font-size:14px;font-weight:700;color:#2563eb}
.mn-subj-status{width:60px;text-align:center;font-size:11px;font-weight:600}
.mn-st-pass{color:#16a34a}
.mn-st-fail{color:#dc2626}
.mn-st-{color:#a1a1aa}
.mn-ab{font-size:12px;color:#a1a1aa;font-style:italic}
.mn-subj-absent{opacity:.55}

/* Component breakdown */
.mn-comp-row{display:flex;gap:8px;flex-wrap:wrap;padding:0 0 10px 16px;border-bottom:1px solid #f4f4f5}
.mn-comp{font-size:11px;color:#71717a;background:#fafafa;padding:2px 8px;border-radius:4px}
.mn-comp-max{font-size:10px;color:#a1a1aa}

/* ── Summary ─────────────────────────────────── */
.mn-summary{display:flex;align-items:baseline;justify-content:space-between;padding:20px 0 16px;flex-wrap:wrap;gap:12px}
.mn-sum-primary{display:flex;align-items:baseline;gap:16px}
.mn-sum-total{font-size:36px;font-weight:800;color:#18181b;letter-spacing:-.03em;line-height:1}
.mn-sum-of{font-size:16px;font-weight:400;color:#a1a1aa}
.mn-sum-pct{font-size:28px;font-weight:700;color:#2563eb;line-height:1}
.mn-sum-secondary{display:flex;align-items:center;gap:12px}
.mn-sum-tag{font-size:12px;font-weight:600;color:#52525b;background:#f4f4f5;padding:4px 12px;border-radius:20px}
.mn-sum-tag strong{color:#18181b}
.mn-sum-tag.mn-st-pass{background:#f0fdf4;color:#16a34a}
.mn-sum-tag.mn-st-fail{background:#fef2f2;color:#dc2626}

/* ── Legend ───────────────────────────────────── */
.mn-legend{font-size:10px;color:#a1a1aa;line-height:1.7;padding:8px 0}

/* ── Result ──────────────────────────────────── */
.mn-result{text-align:center;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;padding:16px 0}
.mn-result-pass{color:#16a34a}
.mn-result-fail{color:#dc2626}

/* ── Signatures ──────────────────────────────── */
.mn-sigs{display:flex;gap:40px;padding:40px 0 8px}
.mn-sig{flex:1;display:flex;flex-direction:column;align-items:center;gap:6px}
.mn-sig-line{width:80%;border-top:1px solid #d4d4d8}
.mn-sig span{font-size:11px;font-weight:500;color:#71717a}

/* ── Empty state ─────────────────────────────── */
.mn-empty{text-align:center;padding:40px;color:#a1a1aa;font-style:italic}

/* ── Print ────────────────────────────────────── */
@media print{
  body{background:#fff!important}
  .mn-toolbar{display:none!important}
  .mn-wrapper{padding:0}
  .mn-page{max-width:100%;margin:0;border:none;padding:32px 40px;border-radius:0}
  .mn-sep-thick{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .mn-subj-row{page-break-inside:avoid}
  .mn-sum-tag,.mn-result{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  @page{size:A4 portrait;margin:12mm}
}
</style>
<?php endif; ?>
