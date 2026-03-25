<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Classic Report Card Template — Premium PDF-First Design
 *
 * ARCHITECTURE: 100% table-based layout. Zero flexbox. Zero CSS grid.
 * Every horizontal arrangement uses <table> with table-layout:fixed.
 * Designed for Dompdf rendering at A4 portrait (210mm x 297mm).
 *
 * TYPOGRAPHY HIERARCHY:
 *   Level 1 — School name: 24px, bold 900, uppercase, tracked
 *   Level 2 — Section heads: 11px, bold 700, uppercase, tracked, inverted
 *   Level 3 — Labels: 10px, bold 700, uppercase or small-caps
 *   Level 4 — Body data: 11px, normal/semi-bold
 *   Level 5 — Footnotes: 9px, italic
 *
 * SPACING SCALE: 4px / 8px / 12px / 16px / 20px / 24px
 * COLOR PALETTE: #0b3d24 (dark green), #14532d (medium), #166534 (accent),
 *                #e6f4ea (light tint), #f3faf6 (zebra), #fdebd0 (warm band)
 */
include(APPPATH . 'views/result/templates/_report_card_data.php');
?>
<?php if (empty($batch_mode)): ?>
<div class="rc-toolbar">
  <a href="<?= site_url('result') ?>" onclick="if(window.history.length>1){window.history.back();return false;}" class="rc-btn rc-btn-back">&#8592; Back</a>
  <a href="<?= site_url('result/download_pdf/' . urlencode($userId ?? '') . '/' . urlencode($examId ?? '')) ?>" class="rc-btn rc-btn-dl" style="text-decoration:none">&#8681; Download PDF</a>
  <button class="rc-btn rc-btn-dl" onclick="window.print()">Print</button>
</div>
<?php endif; ?>

<div class="rc-wrapper">
  <div class="rc-page">

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 1 — SCHOOL HEADER
         Table: logo (80px) | school info (auto) | logo (80px)
         ════════════════════════════════════════════════════════════════ -->
    <table class="rc-hdr-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="rc-hdr-logo-cell">
          <?php if ($schoolLogoUrl): ?>
            <img src="<?= htmlspecialchars($schoolLogoUrl) ?>" alt="" class="rc-logo-img">
          <?php else: ?>
            <div class="rc-logo-ph"><?= htmlspecialchars(strtoupper(substr($schoolDisplayName, 0, 3))) ?></div>
          <?php endif; ?>
        </td>
        <td class="rc-hdr-center">
          <div class="rc-school-name"><?= htmlspecialchars(strtoupper($schoolDisplayName)) ?></div>
          <?php if ($schoolCity): ?>
            <div class="rc-school-city">&mdash;&ensp;<?= htmlspecialchars(strtoupper($schoolCity)) ?>&ensp;&mdash;</div>
          <?php endif; ?>
          <?php if ($schoolAddress): ?>
            <div class="rc-school-addr"><?= htmlspecialchars($schoolAddress) ?></div>
          <?php endif; ?>
          <?php if ($schoolBoard || $schoolAffNo || $schoolCode): ?>
            <div class="rc-school-meta">
              <?php
                $metaParts = [];
                if ($schoolBoard) $metaParts[] = htmlspecialchars($schoolBoard);
                if ($schoolAffNo) $metaParts[] = 'Aff. No: ' . htmlspecialchars($schoolAffNo);
                if ($schoolCode)  $metaParts[] = 'School Code: ' . htmlspecialchars($schoolCode);
                echo implode('&ensp;|&ensp;', $metaParts);
              ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="rc-hdr-badge-cell">
          <?php if ($schoolBoard): ?>
            <div class="rc-board-badge">
              <div class="rc-board-name"><?= htmlspecialchars(strtoupper($schoolBoard)) ?></div>
              <div class="rc-board-sub">AFFILIATED</div>
            </div>
          <?php else: ?>
            <div class="rc-board-badge">
              <div class="rc-board-name">REPORT</div>
              <div class="rc-board-sub">CARD</div>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 2 — EXAM TITLE BAND
         ════════════════════════════════════════════════════════════════ -->
    <div class="rc-title-band">
      <div class="rc-title-main">
        <?= htmlspecialchars(strtoupper($examName)) ?><?= $examType ? ' &mdash; ' . htmlspecialchars(strtoupper($examType)) : '' ?>
      </div>
      <div class="rc-title-label">REPORT CARD</div>
    </div>

    <div class="rc-class-strip">
      <span>Class : <?= htmlspecialchars(strtoupper($classNameRaw)) ?></span>
      <?php if ($sectionLetter): ?>
        <span>&emsp;|&emsp;Section : <?= htmlspecialchars(strtoupper($sectionLetter)) ?></span>
      <?php endif; ?>
      <span>&emsp;|&emsp;Session : <?= htmlspecialchars($sessionYear ?: '---') ?></span>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 3 — STUDENT INFORMATION
         Table: photo (100px) | student details (auto)
         ════════════════════════════════════════════════════════════════ -->
    <div class="rc-section-hdr">STUDENT DETAILS</div>

    <table class="rc-stu-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="rc-stu-photo-cell" rowspan="5">
          <?php if ($photoUrl): ?>
            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Photo" class="rc-stu-photo">
          <?php else: ?>
            <div class="rc-photo-placeholder">
              <div class="rc-photo-icon">&#9786;</div>
              <div>Photo</div>
            </div>
          <?php endif; ?>
        </td>
        <td class="rc-si-label">Student's Name</td>
        <td class="rc-si-sep">:</td>
        <td class="rc-si-value rc-si-name"><?= htmlspecialchars(strtoupper($studentName ?: '---')) ?></td>
        <td class="rc-si-gap"></td>
        <td class="rc-si-label">Roll No / SR No</td>
        <td class="rc-si-sep">:</td>
        <td class="rc-si-value"><?= htmlspecialchars($rollNo ?: '---') ?></td>
      </tr>
      <tr>
        <td class="rc-si-label">Father's Name</td>
        <td class="rc-si-sep">:</td>
        <td class="rc-si-value"><?= htmlspecialchars(strtoupper($fatherName ?: '---')) ?></td>
        <td class="rc-si-gap"></td>
        <td class="rc-si-label">Date of Birth</td>
        <td class="rc-si-sep">:</td>
        <td class="rc-si-value"><?= htmlspecialchars($dob ?: '---') ?></td>
      </tr>
      <tr>
        <td class="rc-si-label">Mother's Name</td>
        <td class="rc-si-sep">:</td>
        <td class="rc-si-value"><?= htmlspecialchars(strtoupper($motherName ?: '---')) ?></td>
        <td class="rc-si-gap"></td>
        <td class="rc-si-label">Gender</td>
        <td class="rc-si-sep">:</td>
        <td class="rc-si-value"><?= htmlspecialchars($gender ?: '---') ?></td>
      </tr>
      <?php if ($address): ?>
      <tr>
        <td class="rc-si-label" style="vertical-align:top">Address</td>
        <td class="rc-si-sep" style="vertical-align:top">:</td>
        <td class="rc-si-value" colspan="5" style="font-size:10px"><?= htmlspecialchars($address) ?></td>
      </tr>
      <?php endif; ?>
    </table>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 4 — SCHOLASTIC AREA (MARKS TABLE)
         ════════════════════════════════════════════════════════════════ -->
    <div class="rc-section-hdr">PART A &mdash; SCHOLASTIC AREA</div>

    <?php if (empty($subjectRows)): ?>
      <div class="rc-empty">No result data found. Please enter marks and compute results first.</div>
    <?php else:
      $compKeys = array_keys($allCompDefs);
      $numComps = count($compKeys);
    ?>
      <table class="rc-marks" cellpadding="0" cellspacing="0">
        <thead>
          <!-- Row 1: Main groups -->
          <tr class="rc-mh-r1">
            <th class="rc-mh-sno" rowspan="3">S.No</th>
            <th class="rc-mh-subj" rowspan="3">SUBJECT</th>
            <?php if ($numComps > 0): ?>
              <th class="rc-mh-exam" colspan="<?= $numComps ?>">
                <?= htmlspecialchars(strtoupper($examName)) ?>
                <?php if ($startDate): ?>
                  (<?= htmlspecialchars($startDate) ?><?= $endDate ? ' &ndash; ' . htmlspecialchars($endDate) : '' ?>)
                <?php endif; ?>
              </th>
            <?php endif; ?>
            <th class="rc-mh-col" rowspan="2">MARKS<br>OBTAINED</th>
            <th class="rc-mh-col" rowspan="2">GRADE</th>
            <th class="rc-mh-col" rowspan="2">RESULT</th>
          </tr>
          <!-- Row 2: Component names -->
          <tr class="rc-mh-r2">
            <?php foreach ($compKeys as $cn): ?>
              <th class="rc-mh-comp"><?= htmlspecialchars($cn) ?></th>
            <?php endforeach; ?>
          </tr>
          <!-- Row 3: Max marks -->
          <tr class="rc-mh-r3">
            <?php foreach ($compKeys as $cn): ?>
              <th class="rc-mh-max">(<?= $allCompDefs[$cn] ?>)</th>
            <?php endforeach; ?>
            <th class="rc-mh-max">(<?= htmlspecialchars($grandMax) ?>)</th>
            <th class="rc-mh-max">(<?= htmlspecialchars($gradingScale) ?>)</th>
            <th class="rc-mh-max">(&ge;<?= $passingPct ?>%)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjectRows as $idx => $row): ?>
            <tr class="rc-mr<?= ($idx % 2 === 1) ? ' rc-mr-alt' : '' ?><?= $row['absent'] ? ' rc-mr-absent' : '' ?>">
              <td class="rc-md-sno"><?= $idx + 1 ?></td>
              <td class="rc-md-subj"><?= htmlspecialchars($row['subject']) ?></td>
              <?php foreach ($compKeys as $cn): ?>
                <td class="rc-md-num"><?= $row['absent'] ? '<em class="rc-ab">AB</em>' : htmlspecialchars($row['comps'][$cn] ?? '---') ?></td>
              <?php endforeach; ?>
              <td class="rc-md-total">
                <?php if ($row['absent']): ?>
                  <em class="rc-ab">AB</em>
                <?php else: ?>
                  <strong><?= htmlspecialchars($row['total']) ?></strong><span class="rc-md-of">/<?= htmlspecialchars($row['maxMarks']) ?></span>
                <?php endif; ?>
              </td>
              <td class="rc-md-grade"><?= htmlspecialchars($row['grade'] ?: ($row['absent'] ? 'AB' : '---')) ?></td>
              <td class="rc-md-pf rc-pf-<?= strtolower($row['passFail']) ?>"><?= htmlspecialchars($row['absent'] ? 'Absent' : ($row['passFail'] ?: '---')) ?></td>
            </tr>
          <?php endforeach; ?>
          <!-- Grand total row -->
          <tr class="rc-mr-total">
            <td colspan="<?= 2 + $numComps ?>" class="rc-mt-label">GRAND TOTAL</td>
            <td class="rc-md-total"><strong><?= htmlspecialchars($grandTotal) ?></strong><span class="rc-md-of"> / <?= htmlspecialchars($grandMax) ?></span></td>
            <td class="rc-md-grade"><strong><?= htmlspecialchars($grandGrade ?: '---') ?></strong></td>
            <td class="rc-md-pf"><strong><?= htmlspecialchars($grandPct) ?>%</strong></td>
          </tr>
        </tbody>
      </table>

      <!-- Grade Legend -->
      <?php if ($gradeLegend): ?>
        <div class="rc-legend">
          <strong>Grading Scale (<?= htmlspecialchars($gradingScale) ?>) :</strong>&ensp;<?= $gradeLegend ?>
        </div>
      <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 5 — OVERALL RESULT SUMMARY
         Table: 5 columns — Marks | Percentage | Grade | Rank | Result
         ════════════════════════════════════════════════════════════════ -->
    <div class="rc-section-hdr rc-section-hdr-warm">OVERALL RESULT SUMMARY</div>

    <table class="rc-summary-tbl" cellpadding="0" cellspacing="0">
      <tr class="rc-sum-labels">
        <td>TOTAL MARKS</td>
        <td>PERCENTAGE</td>
        <td>GRADE</td>
        <td>CLASS RANK</td>
        <td>RESULT</td>
      </tr>
      <tr class="rc-sum-values">
        <td><?= htmlspecialchars($grandTotal) ?> / <?= htmlspecialchars($grandMax) ?></td>
        <td><?= htmlspecialchars($grandPct) ?> %</td>
        <td class="rc-sum-grade"><?= htmlspecialchars($grandGrade ?: '---') ?></td>
        <td><?= htmlspecialchars($rank ?: '---') ?></td>
        <td class="rc-pf-<?= strtolower($grandPass) ?>"><?= htmlspecialchars($grandPass ?: '---') ?></td>
      </tr>
    </table>

    <?php endif; ?>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 6 — RESULT DECLARATION
         ════════════════════════════════════════════════════════════════ -->
    <div class="rc-result-band rc-result-<?= $grandPass === 'Pass' ? 'pass' : 'fail' ?>">
      <?= htmlspecialchars($resultText) ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 7 — REMARKS
         ════════════════════════════════════════════════════════════════ -->
    <table class="rc-remarks-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="rc-rem-label">Teacher's Remarks</td>
        <td class="rc-rem-value">___________________________________________________________</td>
      </tr>
    </table>

    <!-- ════════════════════════════════════════════════════════════════
         SECTION 8 — SIGNATURES
         Table: 3 equal columns
         ════════════════════════════════════════════════════════════════ -->
    <table class="rc-sig-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="rc-sig-col">
          <div class="rc-sig-space"></div>
          <div class="rc-sig-line"></div>
          <div class="rc-sig-name">Class Teacher</div>
          <div class="rc-sig-date">Date: _______________</div>
        </td>
        <td class="rc-sig-col rc-sig-center">
          <div class="rc-sig-space"></div>
          <div class="rc-seal">
            <div class="rc-seal-text">SCHOOL<br>SEAL</div>
          </div>
          <div class="rc-sig-line"></div>
          <div class="rc-sig-name">Principal</div>
        </td>
        <td class="rc-sig-col">
          <div class="rc-sig-space"></div>
          <div class="rc-sig-line"></div>
          <div class="rc-sig-name">Parent / Guardian</div>
          <div class="rc-sig-date">Date: _______________</div>
        </td>
      </tr>
    </table>

    <div class="rc-footer">This is a computer-generated report card.</div>

  </div><!-- .rc-page -->
</div><!-- .rc-wrapper -->

<?php if (empty($batch_css_emitted)): ?>
<style>
/* ═══════════════════════════════════════════════════════════════════════
   CLASSIC REPORT CARD — PDF-First, Table-Based, Zero Flexbox
   Designed for Dompdf A4 rendering. All layout via <table>.
   ═══════════════════════════════════════════════════════════════════════ */

/* ── RESET ─────────────────────────────────────────────────────────── */
.rc-wrapper, .rc-wrapper * { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 11px;
  line-height: 1.35;
  color: #1a1a1a;
  background: #b8b8b8;
}

/* ── TOOLBAR (screen only, hidden in PDF) ──────────────────────────── */
.rc-toolbar {
  max-width: 760px;
  margin: 16px auto 10px;
  text-align: right;
  padding: 0 4px;
}
.rc-btn {
  display: inline-block;
  padding: 8px 22px;
  border-radius: 5px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  border: none;
  margin-left: 6px;
  vertical-align: middle;
}
.rc-btn-back { background: #fff; color: #166534; border: 1.5px solid #166534; }
.rc-btn-back:hover { background: #f0fdf4; }
.rc-btn-dl { background: #166534; color: #fff; }
.rc-btn-dl:hover { background: #14532d; }

/* ── PAGE CONTAINER ────────────────────────────────────────────────── */
.rc-wrapper { padding: 0 0 32px; }
.rc-page {
  width: 740px;       /* ~A4 at 96dpi minus margins */
  max-width: 760px;
  margin: 0 auto;
  background: #fff;
  border: 2px solid #1a1a1a;
  font-size: 11px;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION 1 — SCHOOL HEADER
   ══════════════════════════════════════════════════════════════════════ */
.rc-hdr-tbl {
  width: 100%;
  table-layout: fixed;
  border-bottom: 2px solid #1a1a1a;
  border-collapse: collapse;
}
.rc-hdr-logo-cell {
  width: 80px;
  text-align: center;
  vertical-align: middle;
  padding: 12px 8px;
}
.rc-logo-img {
  width: 64px;
  height: 64px;
  border: 2px solid #166534;
  border-radius: 4px;
}
.rc-logo-ph {
  width: 64px;
  height: 64px;
  margin: 0 auto;
  border: 2px solid #166534;
  border-radius: 4px;
  background: #f0fdf4;
  text-align: center;
  line-height: 64px;
  font-size: 16px;
  font-weight: 900;
  color: #166534;
  letter-spacing: 1px;
}
.rc-hdr-badge-cell {
  width: 80px;
  text-align: center;
  vertical-align: middle;
  padding: 12px 8px;
}
.rc-board-badge {
  width: 64px;
  height: 64px;
  margin: 0 auto;
  border: 2px solid #14532d;
  border-radius: 4px;
  background: #e6f4ea;
  text-align: center;
  padding-top: 14px;
}
.rc-board-name {
  font-size: 12px;
  font-weight: 900;
  color: #14532d;
  letter-spacing: 1px;
  line-height: 1.1;
}
.rc-board-sub {
  font-size: 8px;
  font-weight: 600;
  color: #166534;
  letter-spacing: 2px;
  margin-top: 2px;
}
.rc-hdr-center {
  text-align: center;
  vertical-align: middle;
  padding: 14px 8px 10px;
}
.rc-school-name {
  font-size: 24px;
  font-weight: 900;
  letter-spacing: 2px;
  color: #0b3d24;
  line-height: 1.15;
}
.rc-school-city {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 4px;
  color: #333;
  margin-top: 3px;
}
.rc-school-addr {
  font-size: 10px;
  color: #555;
  margin-top: 2px;
  line-height: 1.3;
}
.rc-school-meta {
  font-size: 9px;
  color: #666;
  margin-top: 3px;
  font-style: italic;
  letter-spacing: 0.3px;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION 2 — EXAM TITLE BAND
   ══════════════════════════════════════════════════════════════════════ */
.rc-title-band {
  text-align: center;
  padding: 10px 16px 8px;
  background: #14532d;
  color: #fff;
  border-bottom: 1px solid #0b3d24;
}
.rc-title-main {
  font-size: 14px;
  font-weight: 900;
  letter-spacing: 1.5px;
  text-transform: uppercase;
}
.rc-title-label {
  font-size: 10px;
  font-weight: 400;
  letter-spacing: 3px;
  text-transform: uppercase;
  margin-top: 2px;
  opacity: 0.8;
}
.rc-class-strip {
  text-align: center;
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 700;
  color: #1a1a1a;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #e6f4ea;
  border-bottom: 1px solid #c2dfc8;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION HEADERS (reusable)
   ══════════════════════════════════════════════════════════════════════ */
.rc-section-hdr {
  background: #0b3d24;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  padding: 5px 12px;
}
.rc-section-hdr-warm {
  background: #7c4a03;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION 3 — STUDENT INFORMATION
   Table: photo | 7-col info grid
   ══════════════════════════════════════════════════════════════════════ */
.rc-stu-tbl {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  border-bottom: 1px solid #c2dfc8;
}
.rc-stu-photo-cell {
  width: 100px;
  text-align: center;
  vertical-align: middle;
  padding: 10px;
  border-right: 1px solid #d0d0d0;
  background: #f8faf8;
}
.rc-stu-photo {
  width: 82px;
  height: 100px;
  border: 1px solid #bbb;
}
.rc-photo-placeholder {
  width: 82px;
  height: 100px;
  margin: 0 auto;
  border: 1px dashed #ccc;
  background: #f5f5f5;
  text-align: center;
  padding-top: 28px;
  color: #bbb;
  font-size: 9px;
}
.rc-photo-icon { font-size: 28px; color: #ccc; line-height: 1; margin-bottom: 4px; }

.rc-si-label {
  width: 105px;
  font-size: 10px;
  font-weight: 700;
  color: #333;
  padding: 5px 4px 5px 12px;
  white-space: nowrap;
  vertical-align: middle;
  text-transform: uppercase;
  letter-spacing: 0.2px;
}
.rc-si-sep {
  width: 12px;
  font-weight: 700;
  text-align: center;
  vertical-align: middle;
  padding: 5px 0;
  color: #333;
}
.rc-si-value {
  font-size: 11px;
  font-weight: 500;
  color: #1a1a1a;
  padding: 5px 6px;
  vertical-align: middle;
}
.rc-si-name { font-weight: 700; font-size: 12px; color: #000; }
.rc-si-gap {
  width: 16px;
  border-left: 1px solid #e0e0e0;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION 4 — MARKS TABLE
   ══════════════════════════════════════════════════════════════════════ */
.rc-marks {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 11px;
}

/* — Header Row 1 (dark green, group labels) — */
.rc-mh-r1 th {
  background: #14532d;
  color: #fff;
  font-weight: 700;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 7px 4px;
  border: 1px solid #0b3d24;
  text-align: center;
  vertical-align: middle;
}
.rc-mh-sno { width: 36px; }
.rc-mh-subj { width: 150px; text-align: left !important; padding-left: 10px !important; }
.rc-mh-col { width: 72px; }

/* — Header Row 2 (medium green, component names) — */
.rc-mh-r2 th {
  background: #166534;
  color: #fff;
  font-weight: 600;
  font-size: 10px;
  padding: 5px 4px;
  border: 1px solid #14532d;
  text-align: center;
  vertical-align: middle;
}
.rc-mh-comp { font-size: 9px; }

/* — Header Row 3 (light green tint, max marks) — */
.rc-mh-r3 th {
  background: #e6f4ea;
  color: #555;
  font-weight: 400;
  font-size: 9px;
  font-style: italic;
  padding: 3px 4px;
  border: 1px solid #c2dfc8;
  text-align: center;
  vertical-align: middle;
}
.rc-mh-max { font-style: italic; }

/* — Data Rows — */
.rc-mr td {
  padding: 7px 5px;
  border: 1px solid #d4d4d4;
  text-align: center;
  vertical-align: middle;
  font-size: 11px;
  color: #1a1a1a;
}
.rc-mr-alt td { background: #f3faf6; }
.rc-mr-absent td { background: #fef2f2; }

.rc-md-sno { font-size: 10px; color: #888; width: 36px; }
.rc-md-subj { text-align: left !important; padding-left: 10px !important; font-weight: 700; color: #1a1a1a; }
.rc-md-num { font-size: 11px; }
.rc-md-total { font-size: 12px; font-weight: 700; }
.rc-md-of { font-size: 9px; color: #888; font-weight: 400; }
.rc-md-grade { font-weight: 700; color: #14532d; font-size: 12px; }
.rc-md-pf { font-weight: 700; font-size: 10px; }

.rc-pf-pass { color: #166534; }
.rc-pf-fail { color: #b91c1c; }
.rc-pf- { color: #999; }

.rc-ab { color: #aaa; font-style: italic; font-size: 10px; }

/* — Grand total row — */
.rc-mr-total td {
  padding: 8px 5px;
  border: 1px solid #14532d;
  background: #dcfce7;
  text-align: center;
  vertical-align: middle;
  font-size: 12px;
  font-weight: 700;
  color: #0b3d24;
}
.rc-mt-label { text-align: right !important; padding-right: 12px !important; letter-spacing: 1px; }

/* ── Grade Legend ──────────────────────────────────────────────────── */
.rc-legend {
  font-size: 9px;
  color: #444;
  padding: 5px 12px;
  background: #fffde8;
  border-top: 1px solid #e8e0a0;
  border-bottom: 1px solid #e8e0a0;
  font-style: italic;
  line-height: 1.5;
}
.rc-legend strong { font-style: normal; color: #222; }

/* ══════════════════════════════════════════════════════════════════════
   SECTION 5 — OVERALL RESULT SUMMARY
   Table: 5 equal columns
   ══════════════════════════════════════════════════════════════════════ */
.rc-summary-tbl {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  border-bottom: 2px solid #7c4a03;
}
.rc-sum-labels td {
  background: #fef3c7;
  font-size: 9px;
  font-weight: 700;
  color: #7c4a03;
  text-transform: uppercase;
  letter-spacing: 0.8px;
  text-align: center;
  padding: 5px 4px;
  border: 1px solid #e8d590;
  vertical-align: middle;
}
.rc-sum-values td {
  background: #fffbeb;
  font-size: 16px;
  font-weight: 900;
  color: #1a1a1a;
  text-align: center;
  padding: 10px 4px;
  border: 1px solid #e8d590;
  vertical-align: middle;
}
.rc-sum-grade { color: #14532d; font-size: 20px; }

/* ══════════════════════════════════════════════════════════════════════
   SECTION 6 — RESULT DECLARATION BAND
   ══════════════════════════════════════════════════════════════════════ */
.rc-result-band {
  text-align: center;
  padding: 10px 16px;
  font-size: 13px;
  font-weight: 900;
  letter-spacing: 1px;
  text-transform: uppercase;
  border-bottom: 1px solid #d0d0d0;
}
.rc-result-pass {
  background: #dcfce7;
  color: #14532d;
  border-top: 2px solid #16a34a;
  border-bottom: 2px solid #16a34a;
}
.rc-result-fail {
  background: #fef2f2;
  color: #991b1b;
  border-top: 2px solid #dc2626;
  border-bottom: 2px solid #dc2626;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION 7 — REMARKS
   ══════════════════════════════════════════════════════════════════════ */
.rc-remarks-tbl {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
}
.rc-rem-label {
  width: 130px;
  font-size: 10px;
  font-weight: 700;
  color: #333;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  padding: 10px 12px;
  vertical-align: top;
  border-right: 1px solid #e0e0e0;
}
.rc-rem-value {
  font-size: 11px;
  color: #bbb;
  padding: 10px 12px;
  vertical-align: top;
  letter-spacing: 1px;
}

/* ══════════════════════════════════════════════════════════════════════
   SECTION 8 — SIGNATURES
   Table: 3 equal columns
   ══════════════════════════════════════════════════════════════════════ */
.rc-sig-tbl {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  border-top: 1px solid #d0d0d0;
}
.rc-sig-col {
  width: 33.333%;
  text-align: center;
  vertical-align: bottom;
  padding: 12px 16px 10px;
  border-right: 1px solid #e0e0e0;
}
.rc-sig-col:last-child { border-right: none; }
.rc-sig-space { height: 48px; }
.rc-sig-line {
  width: 65%;
  margin: 0 auto 4px;
  border-top: 1px solid #444;
}
.rc-sig-name {
  font-size: 10px;
  font-weight: 700;
  color: #1a1a1a;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.rc-sig-date {
  font-size: 8px;
  color: #999;
  font-style: italic;
  margin-top: 2px;
}
.rc-sig-center { position: relative; }
.rc-seal {
  width: 60px;
  height: 60px;
  margin: 0 auto 6px;
  border: 2px dashed #bbb;
  border-radius: 50%;
  text-align: center;
}
.rc-seal-text {
  font-size: 8px;
  font-weight: 700;
  color: #bbb;
  text-transform: uppercase;
  letter-spacing: 1px;
  line-height: 60px;
}

/* ── Footer ─────────────────────────────────────────────────────────── */
.rc-footer {
  text-align: center;
  font-size: 8px;
  color: #aaa;
  font-style: italic;
  padding: 4px 8px 5px;
  border-top: 1px solid #eee;
}

/* ── Empty state ────────────────────────────────────────────────────── */
.rc-empty {
  text-align: center;
  padding: 24px;
  color: #999;
  font-style: italic;
  font-size: 12px;
}

/* ══════════════════════════════════════════════════════════════════════
   PRINT OVERRIDES
   ══════════════════════════════════════════════════════════════════════ */
@media print {
  body { background: #fff !important; }
  .rc-toolbar { display: none !important; }
  .rc-wrapper { padding: 0; }
  .rc-page { width: 100%; max-width: 100%; margin: 0; border: 1.5px solid #000; }

  /* Preserve colored backgrounds in print */
  .rc-hdr-tbl, .rc-title-band, .rc-section-hdr,
  .rc-mh-r1 th, .rc-mh-r2 th, .rc-mh-r3 th,
  .rc-mr-alt td, .rc-mr-total td, .rc-mr-absent td,
  .rc-class-strip, .rc-sum-labels td, .rc-sum-values td,
  .rc-result-band, .rc-legend {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  /* Prevent page breaks inside critical sections */
  .rc-hdr-tbl { page-break-inside: avoid; }
  .rc-stu-tbl { page-break-inside: avoid; }
  .rc-mr { page-break-inside: avoid; }
  .rc-summary-tbl { page-break-inside: avoid; }
  .rc-sig-tbl { page-break-inside: avoid; }

  .rc-marks thead { display: table-header-group; }
  .rc-footer { display: none; }
  @page { size: A4 portrait; margin: 10mm; }
}
</style>
<?php endif; ?>
