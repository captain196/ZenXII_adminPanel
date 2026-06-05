<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Professional Report Card Template — fully customisable flagship.
 *
 * Honours every per-school customisation value exposed by
 * _report_card_data.php:
 *   - $rcAccent / $rcAccentDark / $rcAccentTint  (theme colour)
 *   - $rcShowPhoto / $rcShowResultStrip / $rcShowLegend /
 *     $rcShowCoScholastic / $rcShowAttendance / $rcShowSignatures
 *   - $rcTitle / $rcFooter / $rcPrincipalName / $rcControllerName /
 *     $rcClassTeacherNm / $rcDefaultRemark
 *   - $rcLogoUrl / $rcPrincipalSignUrl / $rcTeacherSignUrl
 *
 * Theme colour is driven by CSS custom properties set inline on the page
 * wrapper, so a single accent value re-themes the entire card.
 */
include(APPPATH . 'views/result/templates/_report_card_data.php');
?>
<?php if (empty($batch_mode)): ?>
<div class="pr-toolbar">
  <a href="<?= site_url('result') ?>" onclick="if(history.length>1){history.back();return false;}" class="pr-tbtn pr-tbtn-back">&#8592; Back</a>
  <a href="<?= site_url('result/download_pdf/' . urlencode($userId ?? '') . '/' . urlencode($examId ?? '')) ?>" class="pr-tbtn pr-tbtn-print" style="text-decoration:none">&#8681; Download PDF</a>
  <button class="pr-tbtn pr-tbtn-print" onclick="window.print()">Print Report Card</button>
</div>
<?php endif; ?>

<div class="pr-wrapper" style="--rc-accent:<?= htmlspecialchars($rcAccent) ?>;--rc-accent-dark:<?= htmlspecialchars($rcAccentDark) ?>;--rc-accent-tint:<?= htmlspecialchars($rcAccentTint) ?>;">
  <div class="pr-page">

    <!-- ═══ HEADER ═══════════════════════════════════════════════════ -->
    <div class="pr-head">
      <div class="pr-head-logo">
        <?php if ($rcLogoUrl): ?>
          <img src="<?= htmlspecialchars($rcLogoUrl) ?>" alt="" class="pr-logo-img">
        <?php else: ?>
          <div class="pr-logo-ph"><?= htmlspecialchars(strtoupper(substr($schoolDisplayName, 0, 2))) ?></div>
        <?php endif; ?>
      </div>
      <div class="pr-head-center">
        <div class="pr-school-name"><?= htmlspecialchars(strtoupper($schoolDisplayName ?: 'School Name')) ?></div>
        <?php if ($schoolAddress || $schoolCity): ?>
          <div class="pr-school-addr"><?= htmlspecialchars(trim($schoolAddress . ($schoolAddress && $schoolCity ? ', ' : '') . $schoolCity)) ?></div>
        <?php endif; ?>
        <?php if ($schoolBoard || $schoolAffNo || $schoolCode): ?>
          <div class="pr-school-meta">
            <?= $schoolBoard ? 'Affiliated to ' . htmlspecialchars(strtoupper($schoolBoard)) : '' ?><?= $schoolAffNo ? ($schoolBoard ? ' &nbsp;|&nbsp; ' : '') . 'Affiliation No: ' . htmlspecialchars($schoolAffNo) : '' ?><?= $schoolCode ? ' &nbsp;|&nbsp; School Code: ' . htmlspecialchars($schoolCode) : '' ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="pr-head-right">
        <?php if ($schoolBoard): ?>
          <div class="pr-board-chip"><?= htmlspecialchars(strtoupper($schoolBoard)) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══ TITLE STRIP ══════════════════════════════════════════════ -->
    <div class="pr-title-strip">
      <span class="pr-title"><?= htmlspecialchars($rcTitle ?: 'Academic Report Card') ?></span>
      <span class="pr-title-sub">
        <?= htmlspecialchars(strtoupper($examName)) ?><?= $examType ? ' &middot; ' . htmlspecialchars(strtoupper($examType)) : '' ?>
        <?= $sessionYear ? ' &middot; Session ' . htmlspecialchars($sessionYear) : '' ?>
      </span>
    </div>

    <!-- ═══ STUDENT INFO ═════════════════════════════════════════════ -->
    <div class="pr-student">
      <div class="pr-stu-grid">
        <div class="pr-stu-row"><span class="pr-k">Student Name</span><span class="pr-v"><strong><?= htmlspecialchars(strtoupper($studentName ?: '---')) ?></strong></span></div>
        <div class="pr-stu-row"><span class="pr-k">Roll / ID</span><span class="pr-v"><?= htmlspecialchars($rollNo ?: '---') ?></span></div>
        <div class="pr-stu-row"><span class="pr-k">Father's Name</span><span class="pr-v"><?= htmlspecialchars($fatherName ?: '---') ?></span></div>
        <div class="pr-stu-row"><span class="pr-k">Mother's Name</span><span class="pr-v"><?= htmlspecialchars($motherName ?: '---') ?></span></div>
        <div class="pr-stu-row"><span class="pr-k">Class &amp; Section</span><span class="pr-v"><?= htmlspecialchars($gradeLabel ?: '---') ?></span></div>
        <div class="pr-stu-row"><span class="pr-k">Date of Birth</span><span class="pr-v"><?= htmlspecialchars($dob ?: '---') ?></span></div>
        <?php if ($address): ?>
          <div class="pr-stu-row pr-stu-wide"><span class="pr-k">Address</span><span class="pr-v"><?= htmlspecialchars($address) ?></span></div>
        <?php endif; ?>
      </div>
      <?php if ($rcShowPhoto): ?>
        <div class="pr-photo-box">
          <?php if ($photoUrl): ?>
            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Photo" class="pr-photo">
          <?php else: ?>
            <div class="pr-photo-ph">
              <svg width="34" height="34" viewBox="0 0 24 24" fill="#b8c0cc"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
              <span>Photo</span>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ═══ SCHOLASTIC MARKS ═════════════════════════════════════════ -->
    <?php if (empty($subjectRows)): ?>
      <p class="pr-no-data">No result data found. Please enter marks and compute results first.</p>
    <?php else:
      $compKeys = array_keys($allCompDefs);
      $numComps = count($compKeys);
    ?>
      <div class="pr-section-label">Scholastic Performance</div>
      <table class="pr-tbl">
        <thead>
          <tr>
            <th class="pr-th-sno">#</th>
            <th class="pr-th-subj">Subject</th>
            <?php foreach ($compKeys as $cn): ?>
              <th class="pr-th-comp"><?= htmlspecialchars($cn) ?><span class="pr-th-max">(<?= (int)$allCompDefs[$cn] ?>)</span></th>
            <?php endforeach; ?>
            <th class="pr-th-marks">Total<span class="pr-th-max">(<?= htmlspecialchars($grandMax) ?>)</span></th>
            <th class="pr-th-pct">%</th>
            <th class="pr-th-grade">Grade</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjectRows as $idx => $row): ?>
            <tr class="<?= $row['absent'] ? 'pr-absent' : '' ?>">
              <td class="pr-td-sno"><?= $idx + 1 ?></td>
              <td class="pr-td-subj"><?= htmlspecialchars($row['subject']) ?></td>
              <?php foreach ($compKeys as $cn): ?>
                <td class="pr-td-comp"><?= $row['absent'] ? '<em class="pr-ab">AB</em>' : htmlspecialchars($row['comps'][$cn] ?? '—') ?></td>
              <?php endforeach; ?>
              <td class="pr-td-marks"><?= $row['absent'] ? '<em class="pr-ab">AB</em>' : htmlspecialchars($row['total']) . ' / ' . htmlspecialchars($row['maxMarks']) ?></td>
              <td class="pr-td-pct"><?= $row['absent'] ? '—' : htmlspecialchars($row['pct']) . '%' ?></td>
              <td class="pr-td-grade">
                <span class="pr-pill pr-pill-<?= strtolower($row['passFail']) ?>"><?= htmlspecialchars($row['grade'] ?: ($row['absent'] ? 'AB' : '—')) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="pr-tbl-total">
            <td colspan="<?= 2 + $numComps ?>" class="pr-total-lbl">Grand Total</td>
            <td class="pr-td-marks"><strong><?= htmlspecialchars($grandTotal) ?> / <?= htmlspecialchars($grandMax) ?></strong></td>
            <td class="pr-td-pct"><strong><?= htmlspecialchars($grandPct) ?>%</strong></td>
            <td class="pr-td-grade"><span class="pr-pill pr-pill-big pr-pill-<?= strtolower($grandPass) ?>"><?= htmlspecialchars($grandGrade ?: '—') ?></span></td>
          </tr>
        </tfoot>
      </table>

      <?php if ($rcShowResultStrip): ?>
        <div class="pr-strip">
          <div class="pr-strip-item"><div class="pr-strip-val"><?= htmlspecialchars($grandTotal) ?><span>/<?= htmlspecialchars($grandMax) ?></span></div><div class="pr-strip-lbl">Total Marks</div></div>
          <div class="pr-strip-item"><div class="pr-strip-val"><?= htmlspecialchars($grandPct) ?><span>%</span></div><div class="pr-strip-lbl">Percentage</div></div>
          <div class="pr-strip-item pr-strip-accent"><div class="pr-strip-val"><?= htmlspecialchars($grandGrade ?: '—') ?></div><div class="pr-strip-lbl">Grade</div></div>
          <div class="pr-strip-item"><div class="pr-strip-val"><?= htmlspecialchars($rank ?: '—') ?></div><div class="pr-strip-lbl">Rank</div></div>
          <div class="pr-strip-item"><div class="pr-strip-val pr-pf-<?= strtolower($grandPass) ?>"><?= htmlspecialchars($grandPass ?: '—') ?></div><div class="pr-strip-lbl">Result</div></div>
        </div>
      <?php endif; ?>

      <?php if ($rcShowLegend && $gradeLegend): ?>
        <div class="pr-legend"><strong>Grading Scale (<?= htmlspecialchars($gradingScale) ?>):</strong> <?= $gradeLegend ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <!-- ═══ CO-SCHOLASTIC ════════════════════════════════════════════ -->
    <?php if ($rcShowCoScholastic): ?>
      <div class="pr-section-label">Co-Scholastic Areas</div>
      <div class="pr-cosch">
        <?php foreach (['Art Education', 'Health &amp; Physical Education', 'Work Education', 'Discipline'] as $act): ?>
          <div class="pr-cosch-item"><span class="pr-cosch-name"><?= $act ?></span><span class="pr-cosch-grade">—</span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ═══ ATTENDANCE & REMARKS ═════════════════════════════════════ -->
    <?php if ($rcShowAttendance): ?>
      <div class="pr-section-label">Attendance &amp; Remarks</div>
      <div class="pr-att">
        <div class="pr-att-item"><span class="pr-att-k">Working Days</span><span class="pr-att-v">—</span></div>
        <div class="pr-att-item"><span class="pr-att-k">Days Present</span><span class="pr-att-v">—</span></div>
        <div class="pr-att-item"><span class="pr-att-k">Days Absent</span><span class="pr-att-v">—</span></div>
      </div>
      <div class="pr-remark"><span class="pr-att-k">Teacher's Remark</span><span class="pr-remark-v"><?= htmlspecialchars($rcDefaultRemark ?: '—') ?></span></div>
    <?php endif; ?>

    <!-- ═══ RESULT DECLARATION ═══════════════════════════════════════ -->
    <?php if (!empty($subjectRows)): ?>
      <div class="pr-result pr-result-<?= $grandPass === 'Pass' ? 'pass' : 'fail' ?>">
        <span class="pr-result-icon"><?= $grandPass === 'Pass' ? '&#10003;' : '&#10007;' ?></span>
        <span class="pr-result-text"><?= htmlspecialchars($resultText) ?></span>
      </div>
    <?php endif; ?>

    <!-- ═══ SIGNATURES ═══════════════════════════════════════════════ -->
    <?php if ($rcShowSignatures): ?>
      <div class="pr-sigs">
        <div class="pr-sig">
          <?php if ($rcTeacherSignUrl): ?><img src="<?= htmlspecialchars($rcTeacherSignUrl) ?>" class="pr-sig-img" alt=""><?php else: ?><div class="pr-sig-space"></div><?php endif; ?>
          <div class="pr-sig-line"></div>
          <div class="pr-sig-label"><?= htmlspecialchars($rcClassTeacherNm ?: 'Class Teacher') ?></div>
          <div class="pr-sig-role"><?= $rcClassTeacherNm ? 'Class Teacher' : '' ?></div>
        </div>
        <div class="pr-sig">
          <div class="pr-sig-space"></div>
          <div class="pr-sig-line"></div>
          <div class="pr-sig-label"><?= htmlspecialchars($rcControllerName ?: 'Exam Controller') ?></div>
          <div class="pr-sig-role"><?= $rcControllerName ? 'Exam Controller' : '' ?></div>
        </div>
        <div class="pr-sig">
          <?php if ($rcPrincipalSignUrl): ?><img src="<?= htmlspecialchars($rcPrincipalSignUrl) ?>" class="pr-sig-img" alt=""><?php else: ?><div class="pr-sig-space"></div><?php endif; ?>
          <div class="pr-sig-line"></div>
          <div class="pr-sig-label"><?= htmlspecialchars($rcPrincipalName ?: 'Principal') ?></div>
          <div class="pr-sig-role"><?= $rcPrincipalName ? 'Principal' : '' ?></div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($rcFooter): ?>
      <div class="pr-footer"><?= htmlspecialchars($rcFooter) ?></div>
    <?php endif; ?>

  </div>
</div>

<?php if (empty($batch_css_emitted)): ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   Professional Report Card — accent-themed via --rc-accent vars.
   All classes prefixed pr-
   ═══════════════════════════════════════════════════════════════ */
.pr-wrapper *,.pr-wrapper *::before,.pr-wrapper *::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;color:#1f2733;background:#e9edf2}

/* Toolbar */
.pr-toolbar{max-width:840px;margin:16px auto 10px;display:flex;justify-content:flex-end;gap:8px;padding:0 4px}
.pr-tbtn{display:inline-flex;align-items:center;gap:6px;padding:9px 22px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none}
.pr-tbtn-back{background:#fff;color:#334155;border:1.5px solid #cbd5e1!important}
.pr-tbtn-back:hover{background:#f1f5f9}
.pr-tbtn-print{background:var(--rc-accent,#1a3a6b);color:#fff}
.pr-tbtn-print:hover{filter:brightness(.92)}

/* Page */
.pr-wrapper{padding:0 0 40px}
.pr-page{background:#fff;max-width:840px;margin:0 auto;border:1px solid #d8dee6;border-top:5px solid var(--rc-accent,#1a3a6b);box-shadow:0 6px 28px rgba(15,23,42,.12);overflow:hidden}

/* Header */
.pr-head{display:flex;align-items:center;gap:16px;padding:18px 24px 14px}
.pr-head-logo{width:72px;flex-shrink:0}
.pr-logo-img{width:72px;height:72px;object-fit:contain;border-radius:8px}
.pr-logo-ph{width:72px;height:72px;border-radius:8px;background:var(--rc-accent-tint,#eef2f7);color:var(--rc-accent,#1a3a6b);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;letter-spacing:1px}
.pr-head-center{flex:1;text-align:center}
.pr-school-name{font-size:25px;font-weight:800;letter-spacing:.5px;color:var(--rc-accent-dark,#13294b);line-height:1.1}
.pr-school-addr{font-size:11px;color:#64748b;margin-top:3px}
.pr-school-meta{font-size:10px;color:#94a3b8;margin-top:3px;letter-spacing:.3px}
.pr-head-right{width:72px;flex-shrink:0;text-align:right}
.pr-board-chip{display:inline-block;background:var(--rc-accent,#1a3a6b);color:#fff;font-size:10px;font-weight:700;letter-spacing:1px;padding:4px 9px;border-radius:5px}

/* Title strip */
.pr-title-strip{background:var(--rc-accent,#1a3a6b);color:#fff;text-align:center;padding:7px 16px}
.pr-title{display:block;font-size:14px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.pr-title-sub{display:block;font-size:10px;color:rgba(255,255,255,.85);letter-spacing:.5px;margin-top:1px}

/* Student info */
.pr-student{display:flex;gap:16px;padding:16px 24px;border-bottom:1px solid #e6eaf0}
.pr-stu-grid{flex:1;display:grid;grid-template-columns:1fr 1fr;gap:8px 24px;align-content:center}
.pr-stu-row{display:flex;align-items:baseline;gap:6px;font-size:12px;border-bottom:1px dotted #e2e8f0;padding-bottom:5px}
.pr-stu-wide{grid-column:1 / -1}
.pr-k{font-weight:600;color:#64748b;min-width:104px;flex-shrink:0}
.pr-k::after{content:':'}
.pr-v{color:#1f2733;flex:1}
.pr-photo-box{width:104px;height:124px;border:1px solid #d8dee6;border-radius:6px;overflow:hidden;background:#f8fafc;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.pr-photo{width:100%;height:100%;object-fit:cover}
.pr-photo-ph{display:flex;flex-direction:column;align-items:center;gap:3px;color:#b8c0cc;font-size:9px}

/* Section labels */
.pr-section-label{background:var(--rc-accent-tint,#eef2f7);color:var(--rc-accent-dark,#13294b);font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;padding:6px 24px;border-left:4px solid var(--rc-accent,#1a3a6b)}

/* Marks table */
.pr-tbl{width:100%;border-collapse:collapse;font-size:11.5px}
.pr-tbl th{background:var(--rc-accent,#1a3a6b);color:#fff;font-weight:600;padding:8px 6px;text-align:center;border:1px solid var(--rc-accent-dark,#13294b)}
.pr-tbl td{padding:7px 6px;text-align:center;border:1px solid #e2e8f0;vertical-align:middle}
.pr-th-subj{text-align:left;padding-left:14px!important;min-width:150px}
.pr-th-max{display:block;font-size:8.5px;font-weight:400;opacity:.8;margin-top:1px}
.pr-th-sno{width:34px}.pr-th-marks{width:96px}.pr-th-pct{width:54px}.pr-th-grade{width:70px}
.pr-td-subj{text-align:left;padding-left:14px!important;font-weight:600;color:#1f2733}
.pr-td-sno{color:#94a3b8;font-size:10px}
.pr-td-marks{font-weight:600}
.pr-tbl tbody tr:nth-child(even) td{background:#f8fafc}
.pr-absent td{background:#fafafa!important;color:#94a3b8}
.pr-ab{color:#94a3b8;font-style:italic;font-size:10px}
.pr-pill{display:inline-block;min-width:34px;padding:3px 11px;border-radius:12px;font-weight:700;font-size:11.5px;letter-spacing:.5px}
.pr-pill-big{font-size:14px;padding:4px 14px}
.pr-pill-pass{background:#e7f6ed;color:#15803d}
.pr-pill-fail{background:#fdeaea;color:#b91c1c}
.pr-pill-{background:#f1f5f9;color:#64748b}
.pr-tbl-total td{background:var(--rc-accent-tint,#eef2f7)!important;border-top:2px solid var(--rc-accent,#1a3a6b);font-size:12.5px}
.pr-total-lbl{text-align:right!important;padding-right:14px!important;font-weight:700;color:var(--rc-accent-dark,#13294b)}
.pr-no-data{text-align:center;padding:24px;color:#94a3b8;font-style:italic}

/* Result strip */
.pr-strip{display:flex;border-bottom:1px solid #e6eaf0}
.pr-strip-item{flex:1;text-align:center;padding:12px 6px;border-right:1px solid #eef1f5}
.pr-strip-item:last-child{border-right:none}
.pr-strip-val{font-size:21px;font-weight:800;color:var(--rc-accent-dark,#13294b);line-height:1.05}
.pr-strip-val span{font-size:12px;font-weight:500;color:#94a3b8}
.pr-strip-accent .pr-strip-val{color:var(--rc-accent,#1a3a6b)}
.pr-strip-lbl{font-size:9px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.6px;margin-top:4px}
.pr-pf-pass{color:#15803d!important}
.pr-pf-fail{color:#b91c1c!important}

/* Legend */
.pr-legend{font-size:9.5px;color:#475569;padding:7px 24px;background:#fffbeb;border-top:1px solid #fde68a;border-bottom:1px solid #fde68a;font-style:italic}

/* Co-scholastic */
.pr-cosch{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e6eaf0}
.pr-cosch-item{display:flex;align-items:center;justify-content:space-between;padding:9px 24px;border-bottom:1px solid #eef1f5;border-right:1px solid #eef1f5}
.pr-cosch-item:nth-child(2n){border-right:none}
.pr-cosch-name{font-size:11.5px;font-weight:600;color:#334155}
.pr-cosch-grade{font-size:14px;font-weight:800;color:#cbd5e1}

/* Attendance */
.pr-att{display:flex;border-bottom:1px solid #eef1f5}
.pr-att-item{flex:1;padding:9px 24px;border-right:1px solid #eef1f5}
.pr-att-item:last-child{border-right:none}
.pr-att-k{display:block;font-size:9px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.pr-att-v{display:block;font-size:13px;font-weight:600;color:#1f2733;margin-top:2px}
.pr-remark{padding:9px 24px;border-bottom:1px solid #e6eaf0}
.pr-remark-v{display:block;font-size:12px;color:#475569;font-style:italic;margin-top:2px;min-height:18px}

/* Result declaration */
.pr-result{display:flex;align-items:center;justify-content:center;gap:12px;padding:11px 16px;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:1px}
.pr-result-pass{background:#e7f6ed;color:#15803d;border-top:1px solid #86efac;border-bottom:1px solid #86efac}
.pr-result-fail{background:#fdeaea;color:#b91c1c;border-top:1px solid #fca5a5;border-bottom:1px solid #fca5a5}
.pr-result-icon{font-size:18px}

/* Signatures */
.pr-sigs{display:flex;padding:8px 0 14px}
.pr-sig{flex:1;text-align:center;padding:0 16px;display:flex;flex-direction:column;align-items:center;justify-content:flex-end}
.pr-sig-space{height:46px}
.pr-sig-img{height:46px;max-width:130px;object-fit:contain;margin-bottom:2px}
.pr-sig-line{width:78%;border-top:1.4px solid #94a3b8;margin:6px 0 5px}
.pr-sig-label{font-size:11.5px;font-weight:700;color:var(--rc-accent-dark,#13294b)}
.pr-sig-role{font-size:9px;color:#94a3b8;letter-spacing:.5px;margin-top:1px;min-height:11px}

/* Footer */
.pr-footer{text-align:center;padding:8px 16px;font-size:9px;color:#94a3b8;font-style:italic;border-top:1px solid #eef1f5}

/* Print */
@media print{
  body{background:#fff!important}
  .pr-toolbar{display:none!important}
  .pr-wrapper{padding:0}
  .pr-page{max-width:100%;margin:0;border:none;border-top:5px solid var(--rc-accent,#1a3a6b);box-shadow:none}
  .pr-tbl tr{page-break-inside:avoid}
  .pr-tbl thead{display:table-header-group}
  .pr-head,.pr-title-strip,.pr-section-label,.pr-tbl th,.pr-tbl tbody tr:nth-child(even) td,.pr-tbl-total td,.pr-strip,.pr-result,.pr-pill,.pr-board-chip,.pr-logo-ph,.pr-strip-accent{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  @page{size:A4 portrait;margin:9mm}
}
</style>
<?php endif; ?>
