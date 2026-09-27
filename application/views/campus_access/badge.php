<?php
// Visitor Management — printable gate pass (MVP, 2026-08-09).
// Standalone page (no admin header/footer/sidebar) — meant to be opened in
// a new tab and printed directly from the browser. Same rendering approach
// as Sis.php's ID-card views (plain HTML page, browser print — no Dompdf
// round-trip needed for a one-off badge).
$status = (string) ($pass['status'] ?? '');
$statusLabel = [
    'checked_in' => 'ON CAMPUS', 'overstay' => 'OVERSTAY',
    'checked_out' => 'CHECKED OUT', 'denied' => 'DENIED', 'pending_approval' => 'PENDING',
][$status] ?? strtoupper($status);

/**
 * A stored field can be an empty string (e.g. Host left blank at check-in),
 * not just a missing key — `??` alone only catches the latter, so a blank
 * string silently passed through to the printed pass as a blank cell. This
 * normalizes both cases to the same fallback.
 */
function badge_val($val, string $fallback = '—'): string
{
    $val = trim((string) ($val ?? ''));
    return $val !== '' ? $val : $fallback;
}

function badge_fmt_datetime($iso): string
{
    $iso = (string) ($iso ?? '');
    if ($iso === '') return '—';
    try {
        return (new DateTime($iso))->format('d M Y, g:i A');
    } catch (\Exception $e) {
        return $iso; // unparsable — show the raw value rather than hide it
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Visitor Pass — <?= htmlspecialchars((string) ($pass['visitorNameSnapshot'] ?? '')) ?></title>
<style>
  body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:#EEF0EC;margin:0;padding:32px;color:#1C2230}
  .badge{width:340px;margin:0 auto;border-radius:16px;overflow:hidden;border:1px solid #D7DAD1;background:#fff;box-shadow:0 12px 30px -14px rgba(0,0,0,.25)}
  .bhead{background:#1C2230;color:#fff;padding:16px 20px}
  .bhead .sch{font-size:11px;letter-spacing:.1em;text-transform:uppercase;opacity:.7}
  .bhead .lbl{font-size:18px;font-weight:700;margin-top:2px}
  .bbody{padding:18px 20px;display:flex;gap:14px;align-items:center}
  .bbody .n{font-size:16px;font-weight:700}
  .bbody .r{font-size:12px;color:#767C88;margin-top:2px}
  .bphoto{border-radius:10px;object-fit:cover;flex:none;border:1px solid #D7DAD1}
  .kv{padding:0 20px 14px;display:grid;grid-template-columns:auto 1fr;gap:5px 12px;font-size:12.5px}
  .kv dt{color:#767C88} .kv dd{margin:0;font-weight:600}
  .status{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(34,197,94,.14);color:#22c55e}
  .qrrow{display:flex;align-items:center;gap:14px;padding:16px 20px;border-top:1px dashed #D7DAD1}
  .code{font-family:Consolas,monospace;font-size:10.5px;color:#767C88;line-height:1.5;word-break:break-all}
  .printbar{width:340px;margin:16px auto 0;text-align:center}
  .printbar button{padding:8px 16px;border-radius:7px;border:1px solid #BC5A3C;background:#BC5A3C;color:#fff;font-weight:600;cursor:pointer}
  @media print{.printbar{display:none} body{background:#fff;padding:0}}
</style>
</head>
<body>
  <div class="badge">
    <div class="bhead">
      <div class="sch"><?= htmlspecialchars((string) $schoolName) ?></div>
      <div class="lbl">VISITOR PASS</div>
    </div>
    <div class="bbody">
      <?php $photoUrl = (string) ($pass['visitorPhotoUrl'] ?? ''); ?>
      <?php if ($photoUrl !== ''): ?>
        <img src="<?= htmlspecialchars($photoUrl) ?>" alt="" class="bphoto" width="52" height="52">
      <?php endif; ?>
      <div>
        <div class="n"><?= htmlspecialchars((string) ($pass['visitorNameSnapshot'] ?? '')) ?></div>
        <?php
        $purpose = (string) ($pass['purpose'] ?? '');
        $purposeNote = trim((string) ($pass['purposeNote'] ?? ''));
        $purposeDisplay = ($purpose === 'Other' && $purposeNote !== '') ? ($purpose . ' — ' . $purposeNote) : $purpose;
        ?>
        <div class="r"><?= htmlspecialchars($purposeDisplay) ?></div>
      </div>
    </div>
    <dl class="kv">
      <dt>Status</dt><dd><span class="status"><?= htmlspecialchars($statusLabel) ?></span></dd>
      <dt>Phone</dt><dd><?= htmlspecialchars(badge_val($pass['visitorPhoneSnapshot'] ?? null)) ?></dd>
      <dt>Host</dt><dd><?= htmlspecialchars(badge_val($pass['hostStaffNameSnapshot'] ?? null)) ?></dd>
      <?php $vehicle = badge_val($pass['vehicleNumber'] ?? null, ''); if ($vehicle !== ''): ?>
      <dt>Vehicle</dt><dd><?= htmlspecialchars($vehicle) ?></dd>
      <?php endif; ?>
      <?php $accompanying = (int) ($pass['accompanying'] ?? 0); if ($accompanying > 0): ?>
      <dt>Accompanying</dt><dd><?= $accompanying ?> more</dd>
      <?php endif; ?>
      <dt>Check-in</dt><dd><?= htmlspecialchars(badge_fmt_datetime($pass['checkInAt'] ?? null)) ?></dd>
      <dt>Pass no.</dt><dd><?= htmlspecialchars((string) ($pass['id'] ?? '')) ?></dd>
    </dl>
    <div class="qrrow">
      <?php if (!empty($qrDataUri)): ?>
        <img src="<?= $qrDataUri ?>" alt="Gate pass QR" width="76" height="76">
      <?php endif; ?>
      <div class="code">Present at the gate on exit — front desk scans it to look up your pass, then confirms checkout in person.<br>badgeCode: <?= htmlspecialchars((string) ($pass['badgeCode'] ?? '')) ?></div>
    </div>
  </div>
  <div class="printbar"><button onclick="window.print()">Print pass</button></div>
</body>
</html>
