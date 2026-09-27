<?php
/**
 * One-page visual preview of all report templates — open in a browser:
 *   http://localhost/ZenX/school/samples/reporting/preview.php
 *
 * Each report is rendered in its own <iframe srcdoc> so template styles stay isolated
 * (exactly what dompdf/mPDF/Chrome each receive). Links to the generated PDF/XLSX too.
 */
require __DIR__ . '/_bootstrap.php';

$receiptHtml = render_template(__DIR__ . '/templates/fee_receipt.php', compact('SCHOOL', 'FEE_RECEIPT'));
$reportHtml  = render_template(__DIR__ . '/templates/report_card.php', compact('SCHOOL', 'REPORT_CARD'));
$idHtml      = render_template(__DIR__ . '/templates/id_card.php', compact('SCHOOL', 'ID_CARD'));

$certBody = strtr($CERT_BODY_BONAFIDE, $CERT_TOKENS);
$certHtml = render_template(__DIR__ . '/templates/certificate.php', [
    'SCHOOL' => $SCHOOL, 'CERT_TOKENS' => $CERT_TOKENS, 'BODY' => $certBody, 'CERT_TITLE' => 'Bonafide Certificate',
]);

$admitQr   = real_qr_data_uri('ADMIT|' . $ADMIT_CARD['student_id'] . '|' . $ADMIT_CARD['exam']);
$admitHtml = render_template(__DIR__ . '/templates/admit_card.php', ['SCHOOL' => $SCHOOL, 'ADMIT_CARD' => $ADMIT_CARD, 'qrDataUri' => $admitQr]);

$totalDue = array_sum(array_column($DEFAULTERS, 'due'));
$defaulterHtml = render_template(__DIR__ . '/templates/defaulter_report.php', compact('SCHOOL', 'DEFAULTERS', 'totalDue'));

function frame(string $html, int $h): string {
    return '<iframe style="width:100%;height:' . $h . 'px;border:0;border-radius:10px;background:#fff;box-shadow:0 6px 24px rgba(0,0,0,.10)" srcdoc="' . htmlspecialchars($html, ENT_QUOTES) . '"></iframe>';
}
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>ZenX — Report design previews</title><style>
  body { margin:0; font-family:'Segoe UI',system-ui,sans-serif; background:#f0f7f5; color:#0c1e38; padding:28px; }
  h1 { color:#0f766e; margin:0 0 4px; }
  .sub { color:#1a5c56; margin:0 0 10px; font-size:14px; max-width:960px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(380px,1fr)); gap:22px; max-width:1280px; }
  .card { background:#fff; border:1px solid rgba(15,118,110,.15); border-radius:14px; padding:16px; }
  .tag { display:inline-block; font-size:11px; font-weight:700; color:#fff; background:#0f766e; border-radius:20px; padding:3px 10px; }
  .tag.alt { background:#b45309; } .tag.alt2 { background:#1d4ed8; } .tag.alt3 { background:#7c3aed; }
  .new { display:inline-block; font-size:10px; font-weight:800; color:#fff; background:#dc2626; border-radius:20px; padding:2px 8px; margin-left:6px; letter-spacing:.4px; }
  .card h2 { font-size:15px; margin:10px 0 2px; }
  .card p { font-size:12px; color:#64748b; margin:0 0 12px; }
  .links { max-width:1280px; margin:0 0 20px; font-size:13px; }
  .links a { color:#0f766e; font-weight:600; }
  h3.sec { max-width:1280px; color:#0c1e38; font-size:16px; margin:30px 0 4px; padding-top:14px; border-top:1px solid #dbe7e4; }
  .sec-sub { max-width:1280px; color:#64748b; font-size:12.5px; margin:0 0 16px; }
</style></head><body>
  <h1>ZenX — Report design previews <span style="font-size:12px;color:#b45309;font-weight:700;vertical-align:middle">v2 — redesigned</span></h1>
  <p class="sub">One shared brand kit (<code>templates/_brand_kit.php</code>) across every document: a restrained palette, a serif/sans/mono type system, and hand-drawn SVG ornaments (laurel wreath, ribbon seal, corner flourish) reused everywhere — real design craft, not per-page improvisation. Marked <span class="new">NEW</span> = closes a confirmed gap (see REPORT_GENERATION_DEEP_RESEARCH.md).</p>

  <div class="links">
    Generated files &nbsp;·&nbsp;
    <a href="out/fee_receipt_dompdf.pdf" target="_blank">▸ fee_receipt_dompdf.pdf</a> ·
    <a href="out/fee_defaulters.xlsx">▸ fee_defaulters.xlsx</a> ·
    <a href="out/certificate_dompdf.pdf" target="_blank">▸ certificate_dompdf.pdf</a> ·
    <a href="out/admit_card_dompdf.pdf" target="_blank">▸ admit_card_dompdf.pdf</a> ·
    <a href="out/fee_defaulter_report.pdf" target="_blank">▸ fee_defaulter_report.pdf</a> ·
    <a href="out/trip_manifest.xlsx">▸ trip_manifest.xlsx</a>
  </div>

  <h3 class="sec">Originally built (dompdf / mPDF / Chrome basics)</h3>
  <p class="sec-sub">Fees, Exam/Result, SIS — the three most-used report families, already-installed engines vs. the two premium adds.</p>
  <div class="grid">
    <div class="card">
      <span class="tag">dompdf · Fees</span>
      <h2>Fee Receipt</h2>
      <p>Amount-in-words, PAID seal, signature line — your existing engine, elevated.</p>
      <?= frame($receiptHtml, 640) ?>
    </div>
    <div class="card">
      <span class="tag alt">mPDF · Exam</span>
      <h2>Report Card (Hindi + English)</h2>
      <p>Devanagari + grade chips + progress bar — where mPDF beats dompdf.</p>
      <?= frame($reportHtml, 680) ?>
    </div>
    <div class="card">
      <span class="tag alt2">headless Chrome · SIS</span>
      <h2>Student ID Card</h2>
      <p>Gradient text, real scannable QR (chillerlan) — pixel-perfect only in a real browser.</p>
      <div style="display:flex;align-items:center;justify-content:center;padding:24px;background:#e6f4f1;border-radius:10px">
        <?= frame($idHtml, 230) ?>
      </div>
    </div>
  </div>

  <h3 class="sec">New — closing the biggest gaps found in the deep-research pass</h3>
  <p class="sec-sub">Certificates, Exam Admit Card, Fee Defaulter Report, Transport — modules with zero or print-only reports today.</p>
  <div class="grid">
    <div class="card">
      <span class="tag">dompdf · Certificates</span><span class="new">NEW</span>
      <h2>Bonafide Certificate</h2>
      <p>Uses the EXACT {placeholder} tokens already in Certificates.php — drop-in ready.</p>
      <?= frame($certHtml, 760) ?>
    </div>
    <div class="card">
      <span class="tag">dompdf · Exam</span><span class="new">NEW</span>
      <h2>Exam Admit Card / Hall Ticket</h2>
      <p>Did not exist anywhere in the codebase. Real scannable QR for verification.</p>
      <?= frame($admitHtml, 720) ?>
    </div>
    <div class="card">
      <span class="tag">dompdf · Fees</span><span class="new">NEW</span>
      <h2>Fee Defaulter Report</h2>
      <p>Was a screen-only grid with no export button wired. Landscape, print/file-ready.</p>
      <?= frame($defaulterHtml, 560) ?>
    </div>
    <div class="card">
      <span class="tag alt3">PhpSpreadsheet · Transport</span><span class="new">NEW</span>
      <h2>Trip Manifest (Excel)</h2>
      <p>Entire Transport module (12 controllers) had ZERO reports. One exemplar, same proven pattern.</p>
      <div style="padding:18px;background:#f8fafc;border-radius:10px;font-size:12.5px;color:#475569">
        📊 Open <a href="out/trip_manifest.xlsx">trip_manifest.xlsx</a> in Excel to see: styled header,
        route/vehicle/driver info block, stop-by-stop boarding table with shortfall highlighting, totals row,
        auto-filter + frozen header.
      </div>
    </div>
  </div>
</body></html>
