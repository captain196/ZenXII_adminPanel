<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Modern Report Card Template — Dashboard / Analytics style.
 * Cards, CSS circular gauges, progress bars, visual indicators.
 *
 * UNIQUE STRUCTURE: Subject performance cards with circular percentage
 * gauges, stacked component bars, dashboard metric row, visual
 * data-driven layout. No traditional table.
 */
include(APPPATH . 'views/result/templates/_report_card_data.php');
?>
<?php if (empty($batch_mode)): ?>
<div class="md-toolbar">
  <a href="<?= site_url('result') ?>" onclick="if(history.length>1){history.back();return false;}" class="md-btn md-btn-outline">&#8592; Back</a>
  <a href="<?= site_url('result/download_pdf/' . urlencode($userId ?? '') . '/' . urlencode($examId ?? '')) ?>" class="md-btn md-btn-primary" style="text-decoration:none">&#8681; Download PDF</a>
  <button class="md-btn md-btn-primary" onclick="window.print()">Print Report Card</button>
</div>
<?php endif; ?>

<div class="md-wrapper">

  <!-- ═══════════════════════════════════════════════════════════════
       HEADER — Table-based 3-column with gradient background
       ═══════════════════════════════════════════════════════════════ -->
  <div class="md-header">
    <table class="md-hdr-tbl" cellpadding="0" cellspacing="0">
      <tr>
        <td class="md-hdr-logo-cell">
          <?php if ($schoolLogoUrl): ?>
            <img src="<?= htmlspecialchars($schoolLogoUrl) ?>" alt="" class="md-logo-img">
          <?php else: ?>
            <div class="md-logo-ph"><?= htmlspecialchars(strtoupper(substr($schoolDisplayName, 0, 2))) ?></div>
          <?php endif; ?>
        </td>
        <td class="md-hdr-center">
          <div class="md-school-name"><?= htmlspecialchars(strtoupper($schoolDisplayName)) ?></div>
          <?php if ($schoolCity || $schoolAddress): ?>
            <div class="md-school-loc"><?= htmlspecialchars($schoolAddress ?: $schoolCity) ?><?= $schoolAddress && $schoolCity ? ', ' . htmlspecialchars($schoolCity) : '' ?></div>
          <?php endif; ?>
          <?php if ($schoolBoard || $schoolAffNo || $schoolCode): ?>
            <div class="md-school-meta">
              <?php if ($schoolBoard): ?><span class="md-pill"><?= htmlspecialchars($schoolBoard) ?></span><?php endif; ?>
              <?php if ($schoolAffNo): ?><span class="md-pill">Aff: <?= htmlspecialchars($schoolAffNo) ?></span><?php endif; ?>
              <?php if ($schoolCode): ?><span class="md-pill">Code: <?= htmlspecialchars($schoolCode) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        </td>
        <td class="md-hdr-badge-cell">
          <?php if ($schoolBoard): ?>
            <div class="md-board-badge">
              <div class="md-badge-name"><?= htmlspecialchars(strtoupper($schoolBoard)) ?></div>
              <div class="md-badge-sub">AFFILIATED</div>
            </div>
          <?php else: ?>
            <div class="md-board-badge">
              <div class="md-badge-name">REPORT</div>
              <div class="md-badge-sub">CARD</div>
            </div>
          <?php endif; ?>
        </td>
      </tr>
    </table>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════
       EXAM + STUDENT — Side-by-side cards
       ═══════════════════════════════════════════════════════════════ -->
  <div class="md-top-row">
    <!-- Exam Badge -->
    <div class="md-exam-card">
      <div class="md-exam-badge">
        <?= htmlspecialchars(strtoupper($examName)) ?><?= $examType ? ' — ' . htmlspecialchars(strtoupper($examType)) : '' ?>
      </div>
      <div class="md-exam-meta">
        Class <?= htmlspecialchars($classNameRaw) ?><?= $sectionLetter ? ' | Sec ' . htmlspecialchars($sectionLetter) : '' ?>
        | Session <?= htmlspecialchars($sessionYear ?: '---') ?>
      </div>
    </div>
  </div>

  <!-- Student Card -->
  <div class="md-card md-student-card">
    <div class="md-stu-left">
      <div class="md-stu-photo">
        <?php if ($photoUrl): ?>
          <img src="<?= htmlspecialchars($photoUrl) ?>" alt="photo">
        <?php else: ?>
          <div class="md-photo-ph">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="#cbd5e1"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
          </div>
        <?php endif; ?>
      </div>
      <div class="md-stu-info">
        <div class="md-stu-name"><?= htmlspecialchars($studentName ?: '—') ?></div>
        <div class="md-stu-detail"><?= htmlspecialchars($rollNo ?: '') ?><?= $rollNo && $dob ? ' &middot; ' : '' ?><?= htmlspecialchars($dob ?: '') ?></div>
      </div>
    </div>
    <div class="md-stu-right">
      <div class="md-stu-field">
        <span class="md-sf-k">Father</span>
        <span class="md-sf-v"><?= htmlspecialchars($fatherName ?: '—') ?></span>
      </div>
      <div class="md-stu-field">
        <span class="md-sf-k">Mother</span>
        <span class="md-sf-v"><?= htmlspecialchars($motherName ?: '—') ?></span>
      </div>
      <?php if ($gender): ?>
      <div class="md-stu-field">
        <span class="md-sf-k">Gender</span>
        <span class="md-sf-v"><?= htmlspecialchars($gender) ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════
       OVERALL DASHBOARD — Large circular gauge + metric cards
       ═══════════════════════════════════════════════════════════════ -->
  <?php if (!empty($subjectRows)): ?>
  <div class="md-section-title">Performance Overview</div>
  <div class="md-dashboard">
    <!-- Central circular gauge -->
    <div class="md-gauge-card">
      <?php
        $pctVal = min(100, max(0, (float)$grandPct));
        $dashArray = 251.2; // 2 * PI * 40
        $dashOffset = $dashArray - ($dashArray * $pctVal / 100);
      ?>
      <svg class="md-gauge" viewBox="0 0 100 100">
        <circle class="md-gauge-bg" cx="50" cy="50" r="40" />
        <circle class="md-gauge-fill" cx="50" cy="50" r="40"
                stroke-dasharray="<?= $dashArray ?>"
                stroke-dashoffset="<?= $dashOffset ?>"
                style="<?= $grandPass === 'Fail' ? 'stroke:#ef4444' : '' ?>" />
      </svg>
      <div class="md-gauge-label">
        <span class="md-gauge-pct"><?= htmlspecialchars($grandPct) ?>%</span>
        <span class="md-gauge-sub">Percentage</span>
      </div>
    </div>

    <!-- Metric cards -->
    <div class="md-metrics">
      <div class="md-metric">
        <div class="md-metric-val"><?= htmlspecialchars($grandTotal) ?><small>/<?= htmlspecialchars($grandMax) ?></small></div>
        <div class="md-metric-lbl">Total Marks</div>
      </div>
      <div class="md-metric md-metric-accent">
        <div class="md-metric-val"><?= htmlspecialchars($grandGrade ?: '—') ?></div>
        <div class="md-metric-lbl">Overall Grade</div>
      </div>
      <div class="md-metric">
        <div class="md-metric-val"><?= htmlspecialchars($rank ?: '—') ?></div>
        <div class="md-metric-lbl">Class Rank</div>
      </div>
      <div class="md-metric">
        <div class="md-metric-val md-pf-<?= strtolower($grandPass) ?>"><?= htmlspecialchars($grandPass ?: '—') ?></div>
        <div class="md-metric-lbl">Result</div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════
       SUBJECT CARDS — Each with mini progress + component bars
       ═══════════════════════════════════════════════════════════════ -->
  <div class="md-section-title">Subject-wise Performance</div>
  <div class="md-subj-grid">
    <?php foreach ($subjectRows as $row): ?>
      <div class="md-card md-subj-card<?= $row['absent'] ? ' md-subj-abs' : '' ?>">
        <!-- Card header -->
        <div class="md-sc-header">
          <div class="md-sc-name"><?= htmlspecialchars($row['subject']) ?></div>
          <?php if ($row['absent']): ?>
            <span class="md-badge md-badge-ab">Absent</span>
          <?php else: ?>
            <span class="md-badge md-badge-grade"><?= htmlspecialchars($row['grade'] ?: '—') ?></span>
          <?php endif; ?>
        </div>

        <!-- Marks with progress bar -->
        <div class="md-sc-marks">
          <?php if ($row['absent']): ?>
            <span class="md-sc-big md-sc-ab-text">AB</span>
            <span class="md-sc-max">/ <?= htmlspecialchars($row['maxMarks']) ?></span>
          <?php else: ?>
            <span class="md-sc-big"><?= htmlspecialchars($row['total']) ?></span>
            <span class="md-sc-max">/ <?= htmlspecialchars($row['maxMarks']) ?></span>
          <?php endif; ?>
        </div>

        <!-- Progress bar -->
        <?php $pct = $row['absent'] ? 0 : min(100, max(0, $row['pct'])); ?>
        <div class="md-sc-track">
          <div class="md-sc-bar<?= $row['absent'] ? ' md-bar-muted' : ($row['passFail'] === 'Fail' ? ' md-bar-fail' : '') ?>" style="width:<?= $pct ?>%"></div>
        </div>

        <div class="md-sc-footer">
          <span class="md-sc-pct"><?= htmlspecialchars($row['pct']) ?>%</span>
          <span class="md-sc-pf md-pf-<?= strtolower($row['passFail']) ?>">
            <?= htmlspecialchars($row['absent'] ? 'Absent' : ($row['passFail'] ?: '—')) ?>
          </span>
        </div>

        <!-- Component breakdown as mini bars -->
        <?php if (!empty($row['comps']) && !$row['absent']): ?>
          <div class="md-sc-comps">
            <?php foreach ($row['comps'] as $cn => $val):
              $cMax = $allCompDefs[$cn] ?? 1;
              $cPct = $cMax > 0 ? min(100, max(0, (float)$val / $cMax * 100)) : 0;
            ?>
              <div class="md-sc-comp-item">
                <div class="md-sc-comp-hdr">
                  <span class="md-sc-comp-name"><?= htmlspecialchars($cn) ?></span>
                  <span class="md-sc-comp-val"><?= htmlspecialchars($val) ?>/<?= $cMax ?></span>
                </div>
                <div class="md-sc-comp-track">
                  <div class="md-sc-comp-bar" style="width:<?= $cPct ?>%"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════
       GRADE LEGEND
       ═══════════════════════════════════════════════════════════════ -->
  <?php if ($gradeLegend): ?>
    <div class="md-card md-legend-card">
      <div class="md-legend-title">Grading Scale — <?= htmlspecialchars($gradingScale) ?></div>
      <div class="md-legend-text"><?= $gradeLegend ?></div>
    </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════
       RESULT BANNER
       ═══════════════════════════════════════════════════════════════ -->
  <div class="md-result md-result-<?= $grandPass === 'Pass' ? 'pass' : 'fail' ?>">
    <div class="md-result-icon"><?= $grandPass === 'Pass' ? '&#10003;' : '&#10007;' ?></div>
    <?= htmlspecialchars($resultText) ?>
  </div>

  <?php else: ?>
    <div class="md-card"><p class="md-no-data">No result data found. Please enter marks and compute results first.</p></div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════
       SIGNATURES
       ═══════════════════════════════════════════════════════════════ -->
  <div class="md-sigs">
    <div class="md-sig">
      <div class="md-sig-line"></div>
      <div class="md-sig-label">Class Teacher</div>
    </div>
    <div class="md-sig md-sig-center">
      <div class="md-seal">SEAL</div>
      <div class="md-sig-line"></div>
      <div class="md-sig-label">Principal</div>
    </div>
    <div class="md-sig">
      <div class="md-sig-line"></div>
      <div class="md-sig-label">Parent / Guardian</div>
    </div>
  </div>

</div>

<?php if (empty($batch_css_emitted)): ?>
<style>
/* ═══════════════════════════════════════════════════════════════
   MODERN Report Card — Dashboard / Analytics Style
   All classes prefixed with md-
   ═══════════════════════════════════════════════════════════════ */
.md-wrapper *,.md-wrapper *::before,.md-wrapper *::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;font-size:14px;color:#1e293b;background:#f1f5f9;-webkit-font-smoothing:antialiased}

/* Toolbar */
.md-toolbar{max-width:860px;margin:16px auto 12px;display:flex;justify-content:flex-end;gap:10px;padding:0 8px}
.md-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 24px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .2s}
.md-btn-outline{background:#fff;color:#667eea;border:1.5px solid #667eea}
.md-btn-outline:hover{background:#f0f0ff}
.md-btn-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
.md-btn-primary:hover{opacity:.9}

/* Wrapper */
.md-wrapper{max-width:860px;margin:0 auto;padding:0 8px 40px}

/* Card base */
.md-card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:18px;margin-bottom:14px}

/* ── Header (table-based 3-column) ──────────── */
.md-header {
  background: #667eea;
  border-radius: 12px;
  padding: 0;
  margin-bottom: 14px;
  overflow: hidden;
}
.md-hdr-tbl {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
}
.md-hdr-logo-cell {
  width: 76px;
  text-align: center;
  vertical-align: middle;
  padding: 18px 10px;
}
.md-logo-img {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  border: 2px solid rgba(255,255,255,.5);
}
.md-logo-ph {
  width: 56px;
  height: 56px;
  margin: 0 auto;
  border-radius: 50%;
  border: 2px solid rgba(255,255,255,.5);
  background: rgba(255,255,255,.2);
  text-align: center;
  line-height: 56px;
  font-size: 18px;
  font-weight: 800;
  color: #fff;
  letter-spacing: 1px;
}
.md-hdr-center {
  text-align: center;
  vertical-align: middle;
  padding: 18px 8px;
  color: #fff;
}
.md-school-name {
  font-size: 20px;
  font-weight: 800;
  letter-spacing: 0.5px;
  line-height: 1.2;
  color: #fff;
}
.md-school-loc {
  font-size: 11px;
  color: rgba(255,255,255,.85);
  margin-top: 3px;
}
.md-school-meta {
  margin-top: 8px;
}
.md-pill {
  display: inline-block;
  font-size: 9px;
  background: rgba(255,255,255,.18);
  padding: 2px 10px;
  border-radius: 20px;
  color: #fff;
  margin: 0 2px;
}
.md-hdr-badge-cell {
  width: 76px;
  text-align: center;
  vertical-align: middle;
  padding: 18px 10px;
}
.md-board-badge {
  width: 56px;
  height: 56px;
  margin: 0 auto;
  border-radius: 50%;
  border: 2px solid rgba(255,255,255,.5);
  background: rgba(255,255,255,.12);
  text-align: center;
  padding-top: 14px;
}
.md-badge-name {
  font-size: 11px;
  font-weight: 800;
  color: #fff;
  letter-spacing: 0.5px;
  line-height: 1.1;
}
.md-badge-sub {
  font-size: 6px;
  font-weight: 600;
  color: rgba(255,255,255,.7);
  letter-spacing: 1.5px;
  margin-top: 1px;
}

/* ── Top Row (Exam) ──────────────────────────── */
.md-top-row{margin-bottom:14px}
.md-exam-card{text-align:center}
.md-exam-badge{display:inline-block;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:14px;font-weight:700;letter-spacing:.5px;padding:8px 28px;border-radius:24px}
.md-exam-meta{margin-top:6px;font-size:13px;color:#64748b;font-weight:500}

/* ── Student Card ────────────────────────────── */
.md-student-card{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;gap:20px}
.md-stu-left{display:flex;align-items:center;gap:14px}
.md-stu-photo{width:56px;height:56px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#f8fafc;border:2px solid #e2e8f0}
.md-stu-photo img{width:100%;height:100%;object-fit:cover}
.md-photo-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center}
.md-stu-name{font-size:18px;font-weight:700;color:#0f172a}
.md-stu-detail{font-size:12px;color:#94a3b8;margin-top:1px}
.md-stu-right{display:flex;gap:20px}
.md-stu-field{display:flex;flex-direction:column}
.md-sf-k{font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px}
.md-sf-v{font-size:13px;color:#334155;font-weight:500}

/* ── Section Title ───────────────────────────── */
.md-section-title{font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin:20px 0 10px 4px}

/* ── Dashboard — Gauge + Metrics ─────────────── */
.md-dashboard{display:flex;gap:14px;margin-bottom:14px;align-items:stretch}
.md-gauge-card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:20px;width:180px;display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;flex-shrink:0}
.md-gauge{width:120px;height:120px;transform:rotate(-90deg)}
.md-gauge-bg{fill:none;stroke:#e2e8f0;stroke-width:7}
.md-gauge-fill{fill:none;stroke:url(#md-grad);stroke-width:7;stroke-linecap:round;transition:stroke-dashoffset .6s ease}
.md-gauge-label{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center}
.md-gauge-pct{display:block;font-size:28px;font-weight:800;color:#1e293b;line-height:1}
.md-gauge-sub{display:block;font-size:10px;color:#94a3b8;font-weight:500;margin-top:2px}
.md-metrics{flex:1;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.md-metric{background:#fff;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:16px 12px;text-align:center}
.md-metric-accent{background:linear-gradient(135deg,#f0f0ff,#faf5ff);border:1px solid #e0e0ff}
.md-metric-val{font-size:26px;font-weight:800;color:#1e293b;line-height:1.1}
.md-metric-val small{font-size:13px;font-weight:500;color:#94a3b8}
.md-metric-lbl{font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;margin-top:4px}
.md-pf-pass{color:#16a34a!important}
.md-pf-fail{color:#dc2626!important}

/* ── Subject Grid — Cards with progress ──────── */
.md-subj-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.md-subj-card{margin-bottom:0;padding:16px}
.md-subj-abs{opacity:.6}
.md-sc-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.md-sc-name{font-size:14px;font-weight:700;color:#1e293b}
.md-badge{font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px}
.md-badge-grade{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
.md-badge-ab{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.md-sc-marks{display:flex;align-items:baseline;gap:3px;margin-bottom:6px}
.md-sc-big{font-size:26px;font-weight:800;color:#1e293b;line-height:1}
.md-sc-ab-text{color:#94a3b8}
.md-sc-max{font-size:13px;color:#94a3b8;font-weight:500}
.md-sc-track{width:100%;height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-bottom:6px}
.md-sc-bar{height:100%;border-radius:3px;background:linear-gradient(90deg,#667eea,#764ba2);transition:width .4s}
.md-bar-muted{background:#cbd5e1}
.md-bar-fail{background:linear-gradient(90deg,#f87171,#dc2626)}
.md-sc-footer{display:flex;justify-content:space-between;align-items:center}
.md-sc-pct{font-size:12px;font-weight:600;color:#64748b}
.md-sc-pf{font-size:11px;font-weight:700}

/* Component mini bars */
.md-sc-comps{margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;display:flex;flex-direction:column;gap:6px}
.md-sc-comp-item{display:flex;flex-direction:column;gap:2px}
.md-sc-comp-hdr{display:flex;justify-content:space-between;font-size:10px}
.md-sc-comp-name{color:#64748b;font-weight:500}
.md-sc-comp-val{color:#94a3b8;font-weight:600}
.md-sc-comp-track{width:100%;height:3px;background:#f1f5f9;border-radius:2px;overflow:hidden}
.md-sc-comp-bar{height:100%;border-radius:2px;background:#667eea;transition:width .3s}

/* ── Legend ──────────────────────────────────── */
.md-legend-card{background:#f8fafc;border:1px solid #e2e8f0;box-shadow:none}
.md-legend-title{font-size:11px;font-weight:700;color:#64748b;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}
.md-legend-text{font-size:12px;color:#475569;line-height:1.6}

/* ── Result Banner ──────────────────────────── */
.md-result{display:flex;align-items:center;justify-content:center;gap:10px;padding:16px 20px;border-radius:12px;font-size:15px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;margin-bottom:14px}
.md-result-icon{font-size:22px}
.md-result-pass{background:linear-gradient(135deg,#dcfce7,#bbf7d0);color:#15803d;border:1px solid #86efac}
.md-result-fail{background:linear-gradient(135deg,#fef2f2,#fecaca);color:#b91c1c;border:1px solid #fca5a5}

/* ── No data ─────────────────────────────────── */
.md-no-data{text-align:center;padding:28px;color:#94a3b8;font-style:italic}

/* ── Signatures ──────────────────────────────── */
.md-sigs{display:flex;gap:24px;padding:28px 0 0}
.md-sig{flex:1;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;min-height:80px}
.md-sig-center{position:relative}
.md-seal{width:52px;height:52px;border:2px dashed #cbd5e1;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;color:#94a3b8;font-weight:700;letter-spacing:1px;margin-bottom:10px}
.md-sig-line{width:60%;border-top:1.5px solid #94a3b8;margin-bottom:6px}
.md-sig-label{font-size:12px;font-weight:600;color:#64748b}

/* ── SVG gradient definition (injected once) ── */
/* We use a CSS trick: the gradient is defined in the first SVG */

/* ── Print ──────────────────────────────────── */
@media print{
  body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .md-toolbar{display:none!important}
  .md-wrapper{max-width:100%;padding:0}
  .md-card{box-shadow:none;border:1px solid #e2e8f0}
  .md-header{background:#667eea!important;box-shadow:none;border-radius:0;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .md-subj-grid{gap:8px}
  .md-subj-card{padding:12px;page-break-inside:avoid}
  .md-dashboard{page-break-inside:avoid}
  .md-gauge-fill,.md-sc-bar,.md-sc-comp-bar,.md-badge-grade,.md-result-pass,.md-result-fail,.md-metric-accent{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  @page{size:A4 portrait;margin:8mm}
}
@media(max-width:700px){
  .md-subj-grid{grid-template-columns:1fr}
  .md-dashboard{flex-direction:column}
  .md-gauge-card{width:100%}
  .md-student-card{flex-direction:column;align-items:flex-start}
  .md-stu-right{flex-direction:column;gap:8px}
}
</style>
<!-- SVG gradient definition for circular gauge -->
<svg width="0" height="0" style="position:absolute">
  <defs>
    <linearGradient id="md-grad" x1="0%" y1="0%" x2="100%" y2="0%">
      <stop offset="0%" stop-color="#667eea"/>
      <stop offset="100%" stop-color="#764ba2"/>
    </linearGradient>
  </defs>
</svg>
<?php endif; ?>
