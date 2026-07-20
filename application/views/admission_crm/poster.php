<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Admission QR poster — "SCAN TO APPLY". Printable (browser print → any size),
 * PDF (?pdf=1 via dompdf), and PNG (the QR image itself). The QR encodes ONLY
 * the school's public admission URL — no secrets. $want_pdf hides the toolbar.
 */
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$url  = (string) ($admission_url ?? '');
$name = (string) ($school_name ?? 'School');
$logo = (string) ($logo_url ?? '');
$addr = (string) ($address ?? '');
$nameOk = !empty($name_configured);
$want = !empty($want_pdf);
// Locally-rendered QR as a data: URI (SVG) — no external QR API. External hosts
// are blocked by our CSP img-src in-browser and break offline; the local SVG is
// vector so it prints crisp at any poster / banner / newspaper size. Works in the
// browser AND in dompdf, both of which accept data URIs.
$qr = (string) ($qr_svg ?? '');
?>
<style>
  .admposter-wrap { max-width: 720px; margin: 20px auto; }
  .admposter-toolbar { text-align:center; margin-bottom:14px; }
  .admposter-toolbar a, .admposter-toolbar button {
    display:inline-block; margin:0 4px; padding:9px 16px; border-radius:8px; border:1px solid #0f766e;
    background:#0f766e; color:#fff; font-weight:600; font-size:13px; cursor:pointer; text-decoration:none;
  }
  .admposter-toolbar a.sec { background:#fff; color:#0f766e; }
  .admposter {
    background:#ffffff; border:3px solid #0f766e; border-radius:16px; padding:34px 28px 30px;
    text-align:center; font-family: DejaVu Sans, Arial, sans-serif; color:#0f172a;
  }
  .admposter .logo { height:80px; width:auto; margin-bottom:10px; }
  .admposter .school { font-size:30px; font-weight:900; color:#0f766e; letter-spacing:.5px; line-height:1.1; }
  .admposter .scan { font-size:22px; font-weight:800; letter-spacing:3px; margin:22px 0 14px; color:#111827; }
  .admposter .qrbox { display:inline-block; padding:14px; border:2px dashed #99f6e4; border-radius:14px; background:#f0fdfa; }
  .admposter .qrbox img { width:300px; height:300px; display:block; }
  .admposter .oa { font-size:18px; font-weight:700; margin:18px 0 6px; color:#0f766e; }
  .admposter .url { font-size:14px; word-break:break-all; color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; display:inline-block; margin-top:4px; }
  .admposter .hint { margin-top:16px; font-size:12px; color:#64748b; }
  @media print {
    .admposter-toolbar, .content-header, .main-header, .main-sidebar, .main-footer { display:none !important; }
    .admposter-wrap { margin:0; max-width:100%; }
    body { background:#fff; }
  }
</style>

<div class="admposter-wrap">
  <?php if (!$want): ?>
  <?php if (!$nameOk): ?>
  <div style="max-width:720px;margin:0 auto 12px;padding:10px 14px;background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;color:#92400e;font-size:13px;text-align:center;">
    <i class="fa fa-info-circle"></i> Your school name, logo &amp; address aren't set yet. Add them in
    <b>School Config → School Profile</b> for a fully branded poster.
  </div>
  <?php endif; ?>
  <div class="admposter-toolbar">
    <button onclick="window.print()"><i class="fa fa-print"></i> Print</button>
    <a class="sec" href="<?= $e(base_url('admission_crm/poster?pdf=1')) ?>" target="_blank"><i class="fa fa-file-pdf-o"></i> Download PDF</a>
    <?php if ($qr !== ''): ?><a class="sec" href="<?= $e($qr) ?>" download="admission_qr.svg"><i class="fa fa-image"></i> Download QR (SVG)</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="admposter">
    <?php if ($logo !== ''): ?><img class="logo" src="<?= $e($logo) ?>" alt=""><?php endif; ?>
    <div class="school"<?= $nameOk ? '' : ' style="color:#94a3b8;"' ?>><?= $e($name) ?></div>
    <?php if ($addr !== ''): ?><div style="font-size:14px;color:#475569;margin-top:4px;"><?= $e($addr) ?></div><?php endif; ?>
    <div class="scan">SCAN TO APPLY</div>
    <div class="qrbox"><img src="<?= $e($qr) ?>" alt="Admission QR"></div>
    <div class="oa">Online Admission</div>
    <div class="url"><?= $e($url) ?></div>
    <div class="hint">Point your phone camera at the QR code to open the admission form.</div>
  </div>
</div>
