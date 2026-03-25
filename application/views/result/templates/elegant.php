<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Elegant Report Card Template — Certificate / Formal Award style.
 * Centered layout with decorative borders, ornamental dividers,
 * dotted-leader student info, serif typography, gold/cream scheme.
 *
 * UNIQUE STRUCTURE: Everything is centered. Marks displayed in a
 * refined centered list with decorative markers instead of a heavy table.
 * Double ornamental border frame. Watermark background.
 * Designed to look like a printed certificate.
 */
include(APPPATH . 'views/result/templates/_report_card_data.php');
?>
<?php if (empty($batch_mode)): ?>
<div class="el-toolbar">
  <a href="<?= site_url('result') ?>" onclick="if(history.length>1){history.back();return false;}" class="el-btn-back">&#8592; Back</a>
  <a href="<?= site_url('result/download_pdf/' . urlencode($userId ?? '') . '/' . urlencode($examId ?? '')) ?>" class="el-btn-print" style="text-decoration:none">&#8681; Download PDF</a>
  <button class="el-btn-print" onclick="window.print()">Print Report Card</button>
</div>
<?php endif; ?>

<div class="el-wrapper">
  <div class="el-page">
    <!-- Watermark -->
    <div class="el-watermark"><?= htmlspecialchars(strtoupper($schoolDisplayName)) ?></div>

    <div class="el-inner">

      <!-- ═══════════════════════════════════════════════════════════════
           HEADER — Table-based 3-column, certificate style
           ═══════════════════════════════════════════════════════════════ -->
      <table class="el-hdr-tbl" cellpadding="0" cellspacing="0">
        <tr>
          <td class="el-hdr-logo-cell">
            <?php if ($schoolLogoUrl): ?>
              <img src="<?= htmlspecialchars($schoolLogoUrl) ?>" alt="" class="el-logo-img">
            <?php else: ?>
              <div class="el-logo-ph"><?= htmlspecialchars(strtoupper(substr($schoolDisplayName, 0, 2))) ?></div>
            <?php endif; ?>
          </td>
          <td class="el-hdr-center">
            <div class="el-school-name"><?= htmlspecialchars(strtoupper($schoolDisplayName)) ?></div>
            <?php if ($schoolCity): ?>
              <div class="el-city"><?= htmlspecialchars(strtoupper($schoolCity)) ?></div>
            <?php endif; ?>
            <div class="el-ornament">&#9830;&ensp;&#9830;&ensp;&#9830;</div>
            <?php if ($schoolAddress): ?>
              <div class="el-addr"><?= htmlspecialchars($schoolAddress) ?></div>
            <?php endif; ?>
            <?php if ($schoolBoard || $schoolAffNo): ?>
              <div class="el-board-line">
                <?php if ($schoolBoard): ?><em><?= htmlspecialchars($schoolBoard) ?></em><?php endif; ?>
                <?php if ($schoolAffNo): ?><?= $schoolBoard ? '&ensp;|&ensp;' : '' ?><em>Affiliation No: <?= htmlspecialchars($schoolAffNo) ?></em><?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="el-hdr-badge-cell">
            <?php if ($schoolBoard): ?>
              <div class="el-board-badge">
                <div class="el-badge-name"><?= htmlspecialchars(strtoupper($schoolBoard)) ?></div>
                <div class="el-badge-sub">AFFILIATED</div>
              </div>
            <?php else: ?>
              <div class="el-board-badge">
                <div class="el-badge-name">&#9830;</div>
                <div class="el-badge-sub">REPORT CARD</div>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      </table>

      <!-- ═══════════════════════════════════════════════════════════════
           TITLE — Ornamental exam title
           ═══════════════════════════════════════════════════════════════ -->
      <div class="el-divider"></div>
      <div class="el-title">
        <div class="el-title-main"><?= htmlspecialchars(strtoupper($examName)) ?><?= $examType ? ' — ' . htmlspecialchars(strtoupper($examType)) : '' ?></div>
        <div class="el-title-sub">Academic Report Card</div>
        <div class="el-title-class">
          Class <?= htmlspecialchars(strtoupper($classNameRaw)) ?>
          <?= $sectionLetter ? '&ensp;&bull;&ensp;Section ' . htmlspecialchars(strtoupper($sectionLetter)) : '' ?>
          <?= $sessionYear ? '&ensp;&bull;&ensp;Session ' . htmlspecialchars($sessionYear) : '' ?>
        </div>
      </div>
      <div class="el-divider"></div>

      <!-- ═══════════════════════════════════════════════════════════════
           STUDENT — Centered card with photo + dotted-leader fields
           ═══════════════════════════════════════════════════════════════ -->
      <div class="el-student">
        <?php if ($photoUrl): ?>
          <div class="el-photo">
            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Student Photo">
          </div>
        <?php endif; ?>

        <div class="el-stu-fields">
          <div class="el-field">
            <span class="el-field-k">Name of Student</span>
            <span class="el-field-dots"></span>
            <span class="el-field-v"><strong><?= htmlspecialchars(strtoupper($studentName ?: '---')) ?></strong></span>
          </div>
          <div class="el-field">
            <span class="el-field-k">Father's Name</span>
            <span class="el-field-dots"></span>
            <span class="el-field-v"><?= htmlspecialchars(strtoupper($fatherName ?: '---')) ?></span>
          </div>
          <div class="el-field">
            <span class="el-field-k">Mother's Name</span>
            <span class="el-field-dots"></span>
            <span class="el-field-v"><?= htmlspecialchars(strtoupper($motherName ?: '---')) ?></span>
          </div>
          <div class="el-field-pair">
            <div class="el-field el-field-half">
              <span class="el-field-k">Date of Birth</span>
              <span class="el-field-dots"></span>
              <span class="el-field-v"><?= htmlspecialchars($dob ?: '---') ?></span>
            </div>
            <div class="el-field el-field-half">
              <span class="el-field-k">Roll No.</span>
              <span class="el-field-dots"></span>
              <span class="el-field-v"><?= htmlspecialchars($rollNo ?: '---') ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════
           SCHOLASTIC RECORD — Centered decorated subject list
           No heavy table. Each subject as a centered row with markers.
           ═══════════════════════════════════════════════════════════════ -->
      <div class="el-section-hdr">
        <span class="el-section-orn">&#9827;</span>
        Scholastic Record
        <span class="el-section-orn">&#9827;</span>
      </div>

      <?php if (empty($subjectRows)): ?>
        <div class="el-empty">No result data available.</div>
      <?php else: ?>

        <!-- Subject list header -->
        <div class="el-subj-hdr">
          <span class="el-sh-name">Subject</span>
          <?php if (!empty($allCompDefs)): ?>
            <?php foreach (array_keys($allCompDefs) as $cn): ?>
              <span class="el-sh-comp"><?= htmlspecialchars($cn) ?></span>
            <?php endforeach; ?>
          <?php endif; ?>
          <span class="el-sh-marks">Marks</span>
          <span class="el-sh-grade">Grade</span>
        </div>

        <div class="el-subj-list">
          <?php foreach ($subjectRows as $idx => $row): ?>
            <div class="el-subj-item<?= $row['absent'] ? ' el-subj-absent' : '' ?><?= ($idx % 2 === 0) ? ' el-subj-even' : '' ?>">
              <span class="el-si-marker">&#9670;</span>
              <span class="el-si-name"><?= htmlspecialchars($row['subject']) ?></span>
              <?php $compKeys = array_keys($allCompDefs); ?>
              <?php foreach ($compKeys as $cn): ?>
                <span class="el-si-comp">
                  <?= $row['absent'] ? '<em>AB</em>' : htmlspecialchars($row['comps'][$cn] ?? '—') ?>
                </span>
              <?php endforeach; ?>
              <span class="el-si-marks">
                <?php if ($row['absent']): ?>
                  <em>Absent</em>
                <?php else: ?>
                  <?= htmlspecialchars($row['total']) ?> <small>/ <?= htmlspecialchars($row['maxMarks']) ?></small>
                <?php endif; ?>
              </span>
              <span class="el-si-grade">
                <?= htmlspecialchars($row['grade'] ?: ($row['absent'] ? 'AB' : '—')) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             GRADE LEGEND
             ═══════════════════════════════════════════════════════════════ -->
        <?php if ($gradeLegend): ?>
          <div class="el-legend"><?= $gradeLegend ?></div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════════════
             OVERALL — Centered decorative summary
             ═══════════════════════════════════════════════════════════════ -->
        <div class="el-divider el-div-thin"></div>
        <div class="el-overall">
          <div class="el-ov-row">
            <div class="el-ov-item el-ov-wide">
              <span class="el-ov-label">Total Marks</span>
              <span class="el-ov-value"><?= htmlspecialchars($grandTotal) ?> / <?= htmlspecialchars($grandMax) ?></span>
            </div>
            <div class="el-ov-item el-ov-wide">
              <span class="el-ov-label">Percentage</span>
              <span class="el-ov-value"><?= htmlspecialchars($grandPct) ?>%</span>
            </div>
          </div>
          <div class="el-ov-row">
            <div class="el-ov-item">
              <span class="el-ov-label">Grade</span>
              <span class="el-ov-value el-ov-grade"><?= htmlspecialchars($grandGrade ?: '---') ?></span>
            </div>
            <div class="el-ov-item">
              <span class="el-ov-label">Rank</span>
              <span class="el-ov-value"><?= htmlspecialchars($rank ?: '---') ?></span>
            </div>
            <div class="el-ov-item">
              <span class="el-ov-label">Result</span>
              <span class="el-ov-value el-pf-<?= strtolower($grandPass) ?>"><?= htmlspecialchars($grandPass ?: '---') ?></span>
            </div>
          </div>
        </div>

      <?php endif; ?>

      <!-- ═══════════════════════════════════════════════════════════════
           RESULT DECLARATION — Centered in decorative frame
           ═══════════════════════════════════════════════════════════════ -->
      <div class="el-result-frame">
        <div class="el-result-corners"></div>
        <div class="el-result-text el-result-<?= $grandPass === 'Pass' ? 'pass' : 'fail' ?>">
          <?= htmlspecialchars($resultText) ?>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════════════════
           SIGNATURES — With date fields and ornamental seal
           ═══════════════════════════════════════════════════════════════ -->
      <div class="el-sigs">
        <div class="el-sig-col">
          <div class="el-sig-space"></div>
          <div class="el-sig-line"></div>
          <div class="el-sig-title">Class Teacher</div>
          <div class="el-sig-date">Date: _______________</div>
        </div>
        <div class="el-sig-col el-sig-mid">
          <div class="el-sig-space"></div>
          <div class="el-seal">
            <div class="el-seal-inner">School<br>Seal</div>
          </div>
        </div>
        <div class="el-sig-col">
          <div class="el-sig-space"></div>
          <div class="el-sig-line"></div>
          <div class="el-sig-title">Principal</div>
          <div class="el-sig-date">Date: _______________</div>
        </div>
      </div>

      <div class="el-footer">
        This is a computer-generated report card. No signature is required for digital copies.
      </div>

    </div><!-- .el-inner -->
  </div><!-- .el-page -->
</div><!-- .el-wrapper -->

<?php if (empty($batch_css_emitted)): ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   ELEGANT Report Card — Certificate / Formal Award Style
   All classes prefixed with el-
   Serif typography, gold/cream scheme, centered everything.
   ═══════════════════════════════════════════════════════════════ */
.el-wrapper *,.el-wrapper *::before,.el-wrapper *::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:Georgia,'Times New Roman','Palatino Linotype',serif;font-size:12px;color:#3E2723;background:#d6cfc4}

/* Toolbar */
.el-toolbar{max-width:830px;margin:14px auto 10px;display:flex;justify-content:flex-end;gap:8px;padding:0 4px}
.el-btn-back,.el-btn-print{display:inline-flex;align-items:center;gap:6px;padding:9px 24px;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:inherit}
.el-btn-back{background:#FFFDF5;color:#8B6914;border:1.5px solid #8B6914!important}
.el-btn-back:hover{background:#FFF8E7}
.el-btn-print{background:#8B6914;color:#FFFDF5}
.el-btn-print:hover{background:#6d5310}

/* Page frame — double ornamental border */
.el-wrapper{padding:0 0 36px;max-width:830px;margin:0 auto}
.el-page{background:#FFFDF5;max-width:830px;margin:0 auto;border:3px solid #8B6914;outline:2px solid #3E2723;outline-offset:4px;position:relative;overflow:hidden}
.el-inner{border:1.5px solid #c4a35a;margin:6px;padding:0;min-height:100%;position:relative;z-index:1}

/* Watermark */
.el-watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-35deg);font-size:68px;font-weight:900;color:#3E2723;opacity:0;pointer-events:none;white-space:nowrap;letter-spacing:8px;z-index:0}

/* ── Dividers ──────────────────────────────── */
.el-divider{height:6px;border-top:2px solid #8B6914;border-bottom:1px solid #c4a35a}
.el-div-thin{height:3px;border-top:1px solid #c4a35a;border-bottom:none}

/* ── Header (table-based 3-column) ──────────── */
.el-hdr-tbl {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
}
.el-hdr-logo-cell {
  width: 84px;
  text-align: center;
  vertical-align: middle;
  padding: 20px 10px;
}
.el-logo-img {
  width: 64px;
  height: 64px;
  border: 2px solid #8B6914;
  border-radius: 6px;
}
.el-logo-ph {
  width: 64px;
  height: 64px;
  margin: 0 auto;
  border: 2px solid #8B6914;
  border-radius: 6px;
  background: #FFF8E7;
  text-align: center;
  line-height: 64px;
  font-size: 22px;
  font-weight: 900;
  color: #8B6914;
  letter-spacing: 2px;
}
.el-hdr-center {
  text-align: center;
  vertical-align: middle;
  padding: 20px 8px 16px;
}
.el-school-name {
  font-size: 26px;
  font-weight: 900;
  letter-spacing: 4px;
  color: #3E2723;
  line-height: 1.15;
}
.el-city {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 6px;
  color: #8B6914;
  margin-top: 4px;
}
.el-ornament {
  font-size: 12px;
  color: #c4a35a;
  margin: 6px 0;
  letter-spacing: 6px;
}
.el-addr {
  font-size: 10px;
  color: #5D4037;
  font-style: italic;
}
.el-board-line {
  font-size: 10px;
  color: #6D4C41;
  margin-top: 3px;
  font-style: italic;
}
.el-hdr-badge-cell {
  width: 84px;
  text-align: center;
  vertical-align: middle;
  padding: 20px 10px;
}
.el-board-badge {
  width: 64px;
  height: 64px;
  margin: 0 auto;
  border: 2px solid #c4a35a;
  border-radius: 6px;
  background: #FFF8E7;
  text-align: center;
  padding-top: 16px;
}
.el-badge-name {
  font-size: 13px;
  font-weight: 900;
  color: #8B6914;
  letter-spacing: 1px;
  line-height: 1.1;
}
.el-badge-sub {
  font-size: 7px;
  font-weight: 700;
  color: #c4a35a;
  letter-spacing: 1.5px;
  margin-top: 3px;
}

/* ── Title Area ────────────────────────────── */
.el-title{text-align:center;padding:14px 16px 12px;background:linear-gradient(180deg,#FFF8E7 0%,#FFFDF5 100%)}
.el-title-main{font-size:18px;font-weight:900;letter-spacing:2px;color:#3E2723;text-transform:uppercase}
.el-title-sub{font-size:14px;font-weight:400;font-style:italic;color:#8B6914;margin-top:2px;letter-spacing:1px}
.el-title-class{font-size:12px;font-weight:700;color:#5D4037;margin-top:6px}

/* ── Student — Centered with photo + dotted leaders ── */
.el-student{padding:18px 24px;text-align:center}
.el-photo{width:90px;height:110px;margin:0 auto 14px;border:2px solid #8B6914;border-radius:6px;overflow:hidden;background:#FFF8E7}
.el-photo img{width:100%;height:100%;object-fit:cover}
.el-stu-fields{max-width:560px;margin:0 auto;text-align:left}
.el-field{display:flex;align-items:baseline;gap:4px;font-size:12px;margin-bottom:5px}
.el-field-k{font-weight:700;color:#5D4037;white-space:nowrap;min-width:130px}
.el-field-dots{flex:1;border-bottom:1px dotted #c4a35a;margin:0 4px;min-width:20px;position:relative;top:-3px}
.el-field-v{font-weight:600;color:#3E2723;white-space:nowrap}
.el-field-pair{display:flex;gap:24px}
.el-field-half{flex:1}

/* ── Section Header ────────────────────────── */
.el-section-hdr{text-align:center;padding:8px 16px;background:#3E2723;color:#FFF8E7;font-size:13px;font-weight:700;letter-spacing:3px;text-transform:uppercase}
.el-section-orn{font-size:10px;color:#c4a35a;margin:0 6px}

/* ── Subject List (centered, NO heavy table) ── */
.el-subj-hdr{display:flex;align-items:center;padding:6px 24px;background:#FFF8E7;border-bottom:1px solid #c4a35a;font-size:10px;font-weight:700;color:#8B6914;text-transform:uppercase;letter-spacing:.5px}
.el-sh-name{flex:1;padding-left:22px}
.el-sh-comp{width:70px;text-align:center}
.el-sh-marks{width:90px;text-align:center}
.el-sh-grade{width:60px;text-align:center}

.el-subj-list{margin:0}
.el-subj-item{display:flex;align-items:center;padding:7px 24px;border-bottom:1px solid #efe4ce;font-size:12px;transition:background .1s}
.el-subj-even{background:#FFFDF5}
.el-subj-item:not(.el-subj-even){background:#FFF8E7}
.el-subj-absent{opacity:.6}
.el-si-marker{color:#c4a35a;font-size:8px;margin-right:8px;flex-shrink:0}
.el-si-name{flex:1;font-weight:700;color:#3E2723}
.el-si-comp{width:70px;text-align:center;font-size:11px;color:#5D4037}
.el-si-comp em{color:#bbb;font-style:italic;font-size:10px}
.el-si-marks{width:90px;text-align:center;font-weight:700;color:#3E2723}
.el-si-marks small{font-weight:400;color:#8D6E63;font-size:10px}
.el-si-marks em{color:#aaa;font-style:italic;font-size:11px}
.el-si-grade{width:60px;text-align:center;font-weight:900;font-size:14px;color:#8B6914}

/* ── Legend ────────────────────────────────── */
.el-legend{font-size:9.5px;color:#5D4037;font-style:italic;padding:5px 24px;background:#FFF8E7;border-bottom:1px solid #c4a35a;line-height:1.6}

/* ── Overall Summary ─────────────────────── */
.el-overall{padding:16px 24px;text-align:center}
.el-ov-row{display:flex;justify-content:center;gap:16px;margin-bottom:10px}
.el-ov-row:last-child{margin-bottom:0}
.el-ov-item{background:linear-gradient(135deg,#FFF8E7,#FFFDF5);border:1px solid #c4a35a;border-radius:8px;padding:10px 20px;text-align:center;min-width:100px}
.el-ov-wide{min-width:160px}
.el-ov-label{display:block;font-size:9px;font-weight:700;color:#8B6914;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px}
.el-ov-value{display:block;font-size:18px;font-weight:900;color:#3E2723}
.el-ov-grade{font-size:24px;color:#8B6914}
.el-pf-pass{color:#2E7D32!important}
.el-pf-fail{color:#C62828!important}

/* ── Result Frame ────────────────────────── */
.el-result-frame{margin:16px 24px;padding:16px 20px;border:2px solid #8B6914;border-radius:4px;text-align:center;position:relative;background:#FFFDF5}
.el-result-frame::before,.el-result-frame::after{content:'';position:absolute;width:16px;height:16px;border:2px solid #c4a35a}
.el-result-frame::before{top:-3px;left:-3px;border-right:none;border-bottom:none}
.el-result-frame::after{bottom:-3px;right:-3px;border-left:none;border-top:none}
.el-result-text{font-size:15px;font-weight:900;font-style:italic;letter-spacing:1px;text-transform:uppercase}
.el-result-pass{color:#2E7D32}
.el-result-fail{color:#C62828}

/* ── Signatures ──────────────────────────── */
.el-sigs{display:flex;min-height:110px;padding:0 20px}
.el-sig-col{flex:1;text-align:center;padding:16px 14px 10px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px}
.el-sig-mid{justify-content:center}
.el-sig-space{flex:1}
.el-sig-line{width:60%;border-top:1.5px solid #3E2723;margin-bottom:4px}
.el-sig-title{font-size:11px;font-weight:700;color:#3E2723}
.el-sig-date{font-size:9px;color:#8D6E63;font-style:italic;margin-top:2px}
.el-seal{width:72px;height:72px;border:3px double #8B6914;border-radius:50%;display:flex;align-items:center;justify-content:center}
.el-seal-inner{font-size:10px;font-weight:700;color:#c4a35a;text-align:center;line-height:1.5;letter-spacing:1px;text-transform:uppercase}

/* Footer */
.el-footer{text-align:center;font-size:8.5px;color:#a0896a;font-style:italic;padding:5px 10px 8px;border-top:1px solid #e0d0b0}

/* ── Empty ──────────────────────────────── */
.el-empty{text-align:center;padding:30px;color:#aaa;font-style:italic}

/* ── Print ──────────────────────────────── */
@media print{
  body{background:#fff!important}
  .el-toolbar{display:none!important}
  .el-wrapper{padding:0;max-width:100%}
  .el-page{max-width:100%;margin:0;box-shadow:none;border:3px solid #8B6914;outline:2px solid #3E2723;outline-offset:4px}
  .el-watermark{opacity:.03!important}
  .el-school-name{font-size:26px}
  .el-subj-item{page-break-inside:avoid}
  .el-overall,.el-result-frame,.el-sigs{page-break-inside:avoid}
  .el-section-hdr,.el-subj-even,.el-subj-item:not(.el-subj-even),.el-subj-hdr,.el-legend,.el-ov-item,.el-result-frame,.el-divider{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .el-footer{display:none}
  @page{size:A4 portrait;margin:8mm}
}
</style>
<?php endif; ?>
