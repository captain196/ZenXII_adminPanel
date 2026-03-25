<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * CBSE Report Card Template — Strict CBSE/Board format.
 * Grade-centric layout with co-scholastic areas, discipline section,
 * and descriptive indicators. Two-panel student info.
 *
 * UNIQUE STRUCTURE: Left sidebar student panel + grade-dominant table +
 * co-scholastic activity grid + health/discipline rows.
 */
include(APPPATH . 'views/result/templates/_report_card_data.php');
?>
<?php if (empty($batch_mode)): ?>
<div class="cb-toolbar">
  <a href="<?= site_url('result') ?>" onclick="if(history.length>1){history.back();return false;}" class="cb-tbtn cb-tbtn-back">&#8592; Back</a>
  <a href="<?= site_url('result/download_pdf/' . urlencode($userId ?? '') . '/' . urlencode($examId ?? '')) ?>" class="cb-tbtn cb-tbtn-print" style="text-decoration:none">&#8681; Download PDF</a>
  <button class="cb-tbtn cb-tbtn-print" onclick="window.print()">Print Report Card</button>
</div>
<?php endif; ?>

<div class="cb-wrapper">
  <div class="cb-page">

    <!-- ═══════════════════════════════════════════════════════════════
         HEADER — Centered school info with logo + CBSE badge
         ═══════════════════════════════════════════════════════════════ -->
    <table class="cb-hdr-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="cb-hdr-logo-cell">
          <?php if ($schoolLogoUrl): ?>
            <img src="<?= htmlspecialchars($schoolLogoUrl) ?>" alt="" class="cb-logo-img">
          <?php else: ?>
            <div class="cb-logo-ph"><?= htmlspecialchars(strtoupper(substr($schoolDisplayName, 0, 2))) ?></div>
          <?php endif; ?>
        </td>
        <td class="cb-hdr-center">
          <div class="cb-school-name"><?= htmlspecialchars(strtoupper($schoolDisplayName)) ?></div>
          <?php if ($schoolAddress): ?>
            <div class="cb-school-addr"><?= htmlspecialchars($schoolAddress) ?><?= $schoolCity ? ', ' . htmlspecialchars($schoolCity) : '' ?></div>
          <?php endif; ?>
          <?php if ($schoolBoard || $schoolAffNo): ?>
            <div class="cb-school-board">
              <?= $schoolBoard ? 'Affiliated to ' . htmlspecialchars(strtoupper($schoolBoard)) : '' ?>
              <?= $schoolAffNo ? ($schoolBoard ? ' | ' : '') . 'Aff. No: ' . htmlspecialchars($schoolAffNo) : '' ?>
              <?= $schoolCode ? ' | School Code: ' . htmlspecialchars($schoolCode) : '' ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="cb-hdr-badge-cell">
          <?php if ($schoolBoard): ?>
            <div class="cb-board-badge">
              <div class="cb-badge-name"><?= htmlspecialchars(strtoupper($schoolBoard)) ?></div>
              <div class="cb-badge-sub">AFFILIATED</div>
            </div>
          <?php else: ?>
            <div class="cb-board-badge">
              <div class="cb-badge-name">REPORT</div>
              <div class="cb-badge-sub">CARD</div>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <div class="cb-exam-strip">
      <table cellpadding="0" cellspacing="0" style="width:100%">
        <tr>
          <td style="text-align:left;padding:8px 16px;font-size:12px;font-weight:700;color:#fff;letter-spacing:1px">
            <?= htmlspecialchars(strtoupper($examName)) ?><?= $examType ? ' (' . htmlspecialchars(strtoupper($examType)) . ')' : '' ?>
          </td>
          <td style="text-align:right;padding:8px 16px;font-size:11px;color:rgba(255,255,255,.8)">
            Session: <?= htmlspecialchars($sessionYear ?: '---') ?>
          </td>
        </tr>
      </table>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         STUDENT INFO — Two-panel: left details grid, right photo + class
         ═══════════════════════════════════════════════════════════════ -->
    <div class="cb-student">
      <div class="cb-stu-details">
        <div class="cb-stu-row">
          <span class="cb-stu-k">Student's Name</span>
          <span class="cb-stu-v"><strong><?= htmlspecialchars(strtoupper($studentName ?: '---')) ?></strong></span>
        </div>
        <div class="cb-stu-row">
          <span class="cb-stu-k">Father's Name</span>
          <span class="cb-stu-v"><?= htmlspecialchars(strtoupper($fatherName ?: '---')) ?></span>
        </div>
        <div class="cb-stu-row">
          <span class="cb-stu-k">Mother's Name</span>
          <span class="cb-stu-v"><?= htmlspecialchars(strtoupper($motherName ?: '---')) ?></span>
        </div>
        <div class="cb-stu-pair">
          <div class="cb-stu-row cb-stu-half">
            <span class="cb-stu-k">Date of Birth</span>
            <span class="cb-stu-v"><?= htmlspecialchars($dob ?: '---') ?></span>
          </div>
          <div class="cb-stu-row cb-stu-half">
            <span class="cb-stu-k">Gender</span>
            <span class="cb-stu-v"><?= htmlspecialchars($gender ?: '---') ?></span>
          </div>
        </div>
        <?php if ($address): ?>
        <div class="cb-stu-row">
          <span class="cb-stu-k">Address</span>
          <span class="cb-stu-v"><?= htmlspecialchars($address) ?></span>
        </div>
        <?php endif; ?>
      </div>
      <div class="cb-stu-right">
        <div class="cb-photo-box">
          <?php if ($photoUrl): ?>
            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Photo" class="cb-photo">
          <?php else: ?>
            <div class="cb-photo-ph">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="#9e9e9e"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
              <span>Photo</span>
            </div>
          <?php endif; ?>
        </div>
        <div class="cb-class-badge">
          <div class="cb-class-num"><?= htmlspecialchars($classNameRaw) ?></div>
          <div class="cb-class-sec"><?= $sectionLetter ? 'Sec ' . htmlspecialchars($sectionLetter) : '' ?></div>
        </div>
        <div class="cb-roll-badge">Roll: <?= htmlspecialchars($rollNo ?: '---') ?></div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         PART A — SCHOLASTIC AREAS (Grade-dominant table)
         ═══════════════════════════════════════════════════════════════ -->
    <div class="cb-part-hdr">
      <span class="cb-part-num">A</span>
      <span class="cb-part-title">SCHOLASTIC AREAS</span>
    </div>

    <?php if (empty($subjectRows)): ?>
      <p class="cb-no-data">No result data found. Please enter marks and compute results first.</p>
    <?php else:
      $compKeys = array_keys($allCompDefs);
      $numComps = count($compKeys);
    ?>
      <table class="cb-tbl">
        <thead>
          <tr class="cb-tbl-h1">
            <th class="cb-th-sno" rowspan="2">S.No.</th>
            <th class="cb-th-subj" rowspan="2">SUBJECT</th>
            <?php if ($numComps > 0): ?>
              <?php foreach ($compKeys as $cn): ?>
                <th class="cb-th-comp"><?= htmlspecialchars($cn) ?><div class="cb-th-max">(<?= $allCompDefs[$cn] ?>)</div></th>
              <?php endforeach; ?>
            <?php endif; ?>
            <th class="cb-th-marks" rowspan="2">MARKS<div class="cb-th-max">(<?= htmlspecialchars($grandMax) ?>)</div></th>
            <th class="cb-th-grade" rowspan="2">GRADE</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subjectRows as $idx => $row): ?>
            <tr class="<?= $row['absent'] ? 'cb-absent-row' : '' ?>">
              <td class="cb-td-sno"><?= $idx + 1 ?></td>
              <td class="cb-td-subj"><?= htmlspecialchars($row['subject']) ?></td>
              <?php foreach ($compKeys as $cn): ?>
                <td class="cb-td-comp">
                  <?= $row['absent'] ? '<em class="cb-ab">AB</em>' : htmlspecialchars($row['comps'][$cn] ?? '---') ?>
                </td>
              <?php endforeach; ?>
              <td class="cb-td-marks">
                <?php if ($row['absent']): ?>
                  <em class="cb-ab">AB</em>
                <?php else: ?>
                  <?= htmlspecialchars($row['total']) ?> / <?= htmlspecialchars($row['maxMarks']) ?>
                <?php endif; ?>
              </td>
              <td class="cb-td-grade">
                <div class="cb-grade-pill cb-gp-<?= strtolower($row['passFail']) ?>">
                  <?= htmlspecialchars($row['grade'] ?: ($row['absent'] ? 'AB' : '---')) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="cb-tbl-total">
            <td colspan="<?= 2 + $numComps ?>" class="cb-total-label">Overall Result</td>
            <td class="cb-td-marks"><strong><?= htmlspecialchars($grandTotal) ?> / <?= htmlspecialchars($grandMax) ?></strong></td>
            <td class="cb-td-grade">
              <div class="cb-grade-pill cb-grade-big cb-gp-<?= strtolower($grandPass) ?>">
                <?= htmlspecialchars($grandGrade ?: '---') ?>
              </div>
            </td>
          </tr>
        </tfoot>
      </table>

      <!-- Grade scale -->
      <?php if ($gradeLegend): ?>
        <div class="cb-grade-legend">
          <strong>Grading Scale (<?= htmlspecialchars($gradingScale) ?>):</strong> <?= $gradeLegend ?>
        </div>
      <?php endif; ?>

      <!-- OVERALL RESULT STRIP — horizontal stats -->
      <div class="cb-result-strip">
        <div class="cb-rs-item">
          <div class="cb-rs-val"><?= htmlspecialchars($grandTotal) ?><span>/<?= htmlspecialchars($grandMax) ?></span></div>
          <div class="cb-rs-lbl">Total Marks</div>
        </div>
        <div class="cb-rs-item">
          <div class="cb-rs-val"><?= htmlspecialchars($grandPct) ?><span>%</span></div>
          <div class="cb-rs-lbl">Percentage</div>
        </div>
        <div class="cb-rs-item cb-rs-grade">
          <div class="cb-rs-val"><?= htmlspecialchars($grandGrade ?: '---') ?></div>
          <div class="cb-rs-lbl">Grade</div>
        </div>
        <div class="cb-rs-item">
          <div class="cb-rs-val"><?= htmlspecialchars($rank ?: '---') ?></div>
          <div class="cb-rs-lbl">Rank</div>
        </div>
        <div class="cb-rs-item">
          <div class="cb-rs-val cb-pf-<?= strtolower($grandPass) ?>"><?= htmlspecialchars($grandPass ?: '---') ?></div>
          <div class="cb-rs-lbl">Result</div>
        </div>
      </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════
         PART B — CO-SCHOLASTIC AREAS (Activity grid cards)
         ═══════════════════════════════════════════════════════════════ -->
    <div class="cb-part-hdr">
      <span class="cb-part-num">B</span>
      <span class="cb-part-title">CO-SCHOLASTIC AREAS</span>
    </div>

    <div class="cb-coscho-grid">
      <?php
        $coActivities = [
          ['name' => 'Art Education', 'icon' => 'palette', 'desc' => 'Visual & Performing Arts'],
          ['name' => 'Health & Physical Education', 'icon' => 'fitness', 'desc' => 'Sports & Fitness'],
          ['name' => 'Work Education', 'icon' => 'tools', 'desc' => 'Vocational Skills'],
          ['name' => 'Discipline', 'icon' => 'star', 'desc' => 'Behaviour & Conduct'],
        ];
        $iconMap = [
          'palette' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10c1.38 0 2.5-1.12 2.5-2.5 0-.61-.23-1.2-.64-1.64-.25-.3-.4-.67-.4-1.07 0-.83.67-1.5 1.5-1.5H17c2.76 0 5-2.24 5-5C22 5.82 17.51 2 12 2zM6.5 13c-.83 0-1.5-.67-1.5-1.5S5.67 10 6.5 10 8 10.67 8 11.5 7.33 13 6.5 13zm3-4C8.67 9 8 8.33 8 7.5S8.67 6 9.5 6s1.5.67 1.5 1.5S10.33 9 9.5 9zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 6 14.5 6s1.5.67 1.5 1.5S15.33 9 14.5 9zm3 4c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
          'fitness' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M20.57 14.86L22 13.43 20.57 12 17 15.57 8.43 7 12 3.43 10.57 2 9.14 3.43 7.71 2 5.57 4.14 4.14 2.71 2.71 4.14l1.43 1.43L2 7.71l1.43 1.43L2 10.57 3.43 12 7 8.43 15.57 17 12 20.57 13.43 22l1.43-1.43L16.29 22l2.14-2.14 1.43 1.43 1.43-1.43-1.43-1.43L22 16.29z"/></svg>',
          'tools' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/></svg>',
          'star' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>',
        ];
      ?>
      <?php foreach ($coActivities as $act): ?>
        <div class="cb-coscho-card">
          <div class="cb-coscho-icon"><?= $iconMap[$act['icon']] ?></div>
          <div class="cb-coscho-name"><?= $act['name'] ?></div>
          <div class="cb-coscho-desc"><?= $act['desc'] ?></div>
          <div class="cb-coscho-grade">---</div>
          <div class="cb-coscho-note">Not graded</div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         PART C — ATTENDANCE & REMARKS
         ═══════════════════════════════════════════════════════════════ -->
    <div class="cb-part-hdr">
      <span class="cb-part-num">C</span>
      <span class="cb-part-title">ATTENDANCE &amp; REMARKS</span>
    </div>
    <div class="cb-att-row">
      <div class="cb-att-item">
        <span class="cb-att-k">Total Working Days</span>
        <span class="cb-att-v">---</span>
      </div>
      <div class="cb-att-item">
        <span class="cb-att-k">Days Present</span>
        <span class="cb-att-v">---</span>
      </div>
      <div class="cb-att-item">
        <span class="cb-att-k">Days Absent</span>
        <span class="cb-att-v">---</span>
      </div>
      <div class="cb-att-item cb-att-wide">
        <span class="cb-att-k">Teacher's Remark</span>
        <span class="cb-att-v cb-att-remark">---</span>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         RESULT DECLARATION
         ═══════════════════════════════════════════════════════════════ -->
    <div class="cb-result <?= $grandPass === 'Pass' ? 'cb-result-pass' : 'cb-result-fail' ?>">
      <div class="cb-result-icon"><?= $grandPass === 'Pass' ? '&#10003;' : '&#10007;' ?></div>
      <div class="cb-result-text"><?= htmlspecialchars($resultText) ?></div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         SIGNATURES
         ═══════════════════════════════════════════════════════════════ -->
    <div class="cb-sigs">
      <div class="cb-sig">
        <div class="cb-sig-space"></div>
        <div class="cb-sig-line"></div>
        <div class="cb-sig-label">Class Teacher</div>
        <div class="cb-sig-date">Date: ___________</div>
      </div>
      <div class="cb-sig">
        <div class="cb-sig-space"></div>
        <div class="cb-sig-line"></div>
        <div class="cb-sig-label">Exam. Controller</div>
      </div>
      <div class="cb-sig cb-sig-main">
        <div class="cb-sig-space"></div>
        <div class="cb-seal">SCHOOL<br>SEAL</div>
        <div class="cb-sig-line"></div>
        <div class="cb-sig-label">Principal</div>
        <div class="cb-sig-date">Date: ___________</div>
      </div>
    </div>

    <div class="cb-footer">
      This is a computer-generated report card. No signature is required for electronic copies.
    </div>

  </div>
</div>

<?php if (empty($batch_css_emitted)): ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   CBSE Report Card — Grade-Centric Board Format
   All classes prefixed with cb-
   ═══════════════════════════════════════════════════════════════ */
.cb-wrapper *,.cb-wrapper *::before,.cb-wrapper *::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,Georgia,serif;font-size:12px;color:#1a1a2e;background:#c5c5c5}

/* Toolbar */
.cb-toolbar{max-width:830px;margin:14px auto 10px;display:flex;justify-content:flex-end;gap:8px;padding:0 4px}
.cb-tbtn{display:inline-flex;align-items:center;gap:6px;padding:8px 22px;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;font-family:Arial,sans-serif}
.cb-tbtn-back{background:#fff;color:#1a237e;border:1.5px solid #1a237e!important}
.cb-tbtn-back:hover{background:#e8eaf6}
.cb-tbtn-print{background:#1a237e;color:#fff}
.cb-tbtn-print:hover{background:#0d1259}

/* Page */
.cb-wrapper{padding:0 0 36px}
.cb-page{background:#fff;max-width:830px;margin:0 auto;border:3px solid #1a237e;font-size:11.5px}

/* ── Header (table-based 3-column) ──────────── */
.cb-hdr-tbl {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  background: #1a237e;
  color: #fff;
}
.cb-hdr-logo-cell {
  width: 80px;
  text-align: center;
  vertical-align: middle;
  padding: 14px 10px;
}
.cb-logo-img {
  width: 62px;
  height: 62px;
  border: 2px solid rgba(255,255,255,.4);
  border-radius: 6px;
}
.cb-logo-ph {
  width: 62px;
  height: 62px;
  margin: 0 auto;
  border: 2px solid rgba(255,255,255,.4);
  border-radius: 6px;
  background: rgba(255,255,255,.12);
  text-align: center;
  line-height: 62px;
  font-size: 22px;
  font-weight: 900;
  color: #fff;
  letter-spacing: 2px;
}
.cb-hdr-center {
  text-align: center;
  vertical-align: middle;
  padding: 14px 8px 10px;
}
.cb-school-name {
  font-size: 22px;
  font-weight: 900;
  letter-spacing: 2px;
  line-height: 1.15;
  color: #fff;
}
.cb-school-addr {
  font-size: 10px;
  color: rgba(255,255,255,.85);
  margin-top: 3px;
}
.cb-school-board {
  font-size: 9px;
  color: rgba(255,255,255,.7);
  margin-top: 2px;
  letter-spacing: 0.5px;
}
.cb-hdr-badge-cell {
  width: 80px;
  text-align: center;
  vertical-align: middle;
  padding: 14px 10px;
}
.cb-board-badge {
  width: 62px;
  height: 62px;
  margin: 0 auto;
  border: 2px solid rgba(255,255,255,.4);
  border-radius: 6px;
  background: rgba(255,255,255,.1);
  text-align: center;
  padding-top: 16px;
}
.cb-badge-name {
  font-size: 13px;
  font-weight: 900;
  color: #fff;
  letter-spacing: 1px;
  line-height: 1.1;
}
.cb-badge-sub {
  font-size: 7px;
  font-weight: 600;
  color: rgba(255,255,255,.7);
  letter-spacing: 2px;
  margin-top: 2px;
}
.cb-exam-strip {
  background: #283593;
  border-top: 1px solid rgba(255,255,255,.2);
  border-bottom: 2px solid #1a237e;
}

/* ── Student Panel ──────────────────────────────── */
.cb-student{display:flex;border-bottom:2px solid #1a237e}
.cb-stu-details{flex:1;padding:12px 16px;display:flex;flex-direction:column;gap:5px}
.cb-stu-row{display:flex;align-items:baseline;font-size:11.5px;gap:4px}
.cb-stu-k{font-weight:700;color:#1a237e;min-width:110px;white-space:nowrap}
.cb-stu-k::after{content:':';margin-left:4px}
.cb-stu-v{color:#1a1a2e;flex:1}
.cb-stu-pair{display:flex;gap:20px}
.cb-stu-half{flex:1}
.cb-stu-right{width:140px;border-left:2px solid #1a237e;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:10px;gap:8px;background:#f5f5ff}
.cb-photo-box{width:80px;height:90px;border:2px solid #1a237e;overflow:hidden;background:#fff;display:flex;align-items:center;justify-content:center}
.cb-photo{width:100%;height:100%;object-fit:cover}
.cb-photo-ph{display:flex;flex-direction:column;align-items:center;gap:2px;color:#9e9e9e;font-size:8px}
.cb-class-badge{text-align:center}
.cb-class-num{font-size:18px;font-weight:900;color:#1a237e;line-height:1}
.cb-class-sec{font-size:10px;color:#3949ab;font-weight:600;letter-spacing:1px}
.cb-roll-badge{font-size:10px;font-weight:600;color:#5c6bc0;background:#e8eaf6;padding:2px 10px;border-radius:10px}

/* ── Part Headers ──────────────────────────────── */
.cb-part-hdr{display:flex;align-items:center;gap:10px;background:#1a237e;padding:4px 14px}
.cb-part-num{display:inline-flex;width:24px;height:24px;border-radius:50%;background:#fff;color:#1a237e;font-weight:900;font-size:13px;align-items:center;justify-content:center}
.cb-part-title{color:#fff;font-weight:700;font-size:11.5px;letter-spacing:1px;text-transform:uppercase}

/* ── Marks Table (Grade-Dominant) ─────────────── */
.cb-tbl{width:100%;border-collapse:collapse;font-size:11px}
.cb-tbl th,.cb-tbl td{border:1px solid #bdbdbd;padding:6px 5px;text-align:center;vertical-align:middle}
.cb-th-sno{width:40px;background:#283593;color:#fff;font-weight:700}
.cb-th-subj{text-align:left;padding-left:12px!important;min-width:150px;background:#283593;color:#fff;font-weight:700}
.cb-th-comp{background:#3949ab;color:#fff;font-weight:600;font-size:10.5px}
.cb-th-marks{width:90px;background:#283593;color:#fff;font-weight:700}
.cb-th-grade{width:80px;background:#0d47a1;color:#fff;font-weight:900;font-size:12px}
.cb-th-max{display:block;font-size:9px;font-weight:400;opacity:.7;margin-top:1px}
.cb-td-sno{font-size:10px;color:#666}
.cb-td-subj{text-align:left;padding-left:12px!important;font-weight:700;color:#1a1a2e}
.cb-td-comp{font-size:11px}
.cb-td-marks{font-weight:600}
.cb-td-grade{padding:4px}
.cb-grade-pill{display:inline-block;padding:3px 14px;border-radius:14px;font-weight:900;font-size:13px;letter-spacing:1px;min-width:40px}
.cb-grade-big{font-size:16px;padding:4px 16px}
.cb-gp-pass{background:#e8f5e9;color:#1b5e20}
.cb-gp-fail{background:#ffebee;color:#b71c1c}
.cb-gp-{background:#f5f5f5;color:#666}
.cb-absent-row td{background:#fafafa!important}
.cb-ab{color:#999;font-style:italic;font-size:10px}
.cb-tbl tbody tr:nth-child(even) td{background:#f8f9ff}
.cb-tbl-total td{background:#e8eaf6!important;border-top:2px solid #1a237e}
.cb-total-label{text-align:right!important;padding-right:12px!important;font-weight:700;font-size:12px;color:#1a237e}
.cb-no-data{text-align:center;padding:20px;color:#999;font-style:italic}

/* ── Grade Legend ─────────────────────────────── */
.cb-grade-legend{font-size:9.5px;color:#333;padding:5px 14px;background:#fff8e1;border-top:1px solid #ffe082;border-bottom:1px solid #ffe082;font-style:italic}

/* ── Result Strip ─────────────────────────────── */
.cb-result-strip{display:flex;background:#f5f5ff;border-bottom:2px solid #1a237e}
.cb-rs-item{flex:1;text-align:center;padding:10px 6px;border-right:1px solid #c5cae9}
.cb-rs-item:last-child{border-right:none}
.cb-rs-val{font-size:20px;font-weight:900;color:#1a237e;line-height:1.1}
.cb-rs-val span{font-size:12px;font-weight:500;color:#5c6bc0}
.cb-rs-grade .cb-rs-val{font-size:24px;background:linear-gradient(135deg,#1a237e,#3949ab);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.cb-rs-lbl{font-size:9px;font-weight:600;color:#7986cb;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;font-family:Arial,sans-serif}
.cb-pf-pass{color:#2e7d32!important}
.cb-pf-fail{color:#c62828!important}

/* ── Co-Scholastic Grid ──────────────────────── */
.cb-coscho-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.cb-coscho-card{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #e0e0e0;border-right:1px solid #e0e0e0}
.cb-coscho-card:nth-child(2n){border-right:none}
.cb-coscho-icon{width:36px;height:36px;border-radius:50%;background:#e8eaf6;color:#3949ab;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.cb-coscho-name{font-weight:700;font-size:11px;color:#1a237e;flex:1}
.cb-coscho-desc{display:none}
.cb-coscho-grade{font-size:16px;font-weight:900;color:#9e9e9e;min-width:40px;text-align:center}
.cb-coscho-note{font-size:8px;color:#bdbdbd;writing-mode:vertical-rl;transform:rotate(180deg)}

/* ── Attendance Row ──────────────────────────── */
.cb-att-row{display:flex;flex-wrap:wrap;border-bottom:1px solid #bdbdbd}
.cb-att-item{flex:1;padding:8px 12px;border-right:1px solid #e0e0e0;min-width:150px}
.cb-att-item:last-child{border-right:none}
.cb-att-wide{flex:2}
.cb-att-k{display:block;font-size:9px;font-weight:700;color:#1a237e;text-transform:uppercase;letter-spacing:.5px;font-family:Arial,sans-serif}
.cb-att-v{display:block;font-size:12px;font-weight:600;color:#1a1a2e;margin-top:2px}
.cb-att-remark{font-style:italic;color:#666}

/* ── Result Declaration ──────────────────────── */
.cb-result{display:flex;align-items:center;justify-content:center;gap:12px;padding:12px 16px;font-size:14px;font-weight:900;text-transform:uppercase;letter-spacing:1px}
.cb-result-pass{background:#e8f5e9;color:#1b5e20;border-top:2px solid #4caf50;border-bottom:2px solid #4caf50}
.cb-result-fail{background:#ffebee;color:#b71c1c;border-top:2px solid #ef5350;border-bottom:2px solid #ef5350}
.cb-result-icon{font-size:22px}
.cb-result-text{flex:1;text-align:center}

/* ── Signatures ──────────────────────────────── */
.cb-sigs{display:flex;min-height:100px}
.cb-sig{flex:1;text-align:center;padding:14px 12px 10px;border-right:1px solid #bdbdbd;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:4px}
.cb-sig:last-child{border-right:none}
.cb-sig-space{flex:1}
.cb-sig-line{width:60%;border-top:1.5px solid #333;margin-bottom:4px}
.cb-sig-label{font-size:10.5px;font-weight:700;color:#1a237e}
.cb-sig-date{font-size:8.5px;color:#9e9e9e;font-style:italic;margin-top:1px}
.cb-seal{width:58px;height:58px;border:2px dashed #bdbdbd;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:8px;color:#bdbdbd;text-align:center;line-height:1.5;margin-bottom:4px;font-family:Arial,sans-serif}

/* Footer */
.cb-footer{text-align:center;padding:5px 10px;font-size:8.5px;color:#9e9e9e;font-style:italic;font-family:Arial,sans-serif;border-top:1px solid #e0e0e0}

/* ── Print ──────────────────────────────────── */
@media print{
  body{background:#fff!important}
  .cb-toolbar{display:none!important}
  .cb-wrapper{padding:0}
  .cb-page{max-width:100%;margin:0;border-width:2px;box-shadow:none}
  .cb-school-name{font-size:22px}
  .cb-tbl{font-size:10px;table-layout:auto}
  .cb-tbl tr{page-break-inside:avoid}
  .cb-tbl thead{display:table-header-group}
  .cb-hdr-tbl,.cb-exam-strip,.cb-part-hdr,.cb-result,.cb-result-strip,.cb-grade-pill,.cb-tbl thead th,.cb-tbl tbody tr:nth-child(even) td,.cb-tbl-total td,.cb-coscho-icon{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .cb-footer{display:none}
  @page{size:A4 portrait;margin:8mm}
}
</style>
<?php endif; ?>
