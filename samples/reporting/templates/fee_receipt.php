<?php
/**
 * Fee receipt — v2. Invoice hierarchy modeled on real fintech receipts (Stripe/Razorpay):
 * lead with the amount, quiet typographic system, restrained color, a real ink-stamp (not
 * a bright sticker), hairline ledger rules instead of heavy borders. dompdf-safe.
 * Vars: $SCHOOL, $FEE_RECEIPT
 */
require_once __DIR__ . '/_brand_kit.php';
$P = bk_palette(); $F = bk_fonts();
$total = array_sum(array_column($FEE_RECEIPT['lines'], 'amount'));
$monogram = strtoupper(substr($SCHOOL['name'], 0, 1));
$qr = function_exists('real_qr_data_uri') ? real_qr_data_uri('RCPT|' . $FEE_RECEIPT['receipt_no']) : '';
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><style>
  * { font-family:<?= $F['sans'] ?>; }
  @page { margin:0; }
  body { margin:0; color:<?= $P['ink'] ?>; font-size:11px; background:#fff; }
  .sheet { padding:20mm 18mm 16mm; position:relative; }

  .lbl { text-transform:uppercase; letter-spacing:.11em; font-size:7.6px; color:<?= $P['muted'] ?>; font-weight:bold; }
  .mono { font-family:<?= $F['mono'] ?>; }
  .serif { font-family:<?= $F['serif'] ?>; }

  /* Lockup */
  table.hd { width:100%; border-collapse:collapse; }
  .mk { width:9mm; height:9mm; border:1px solid <?= $P['teal'] ?>; border-radius:1.4px; text-align:center;
        line-height:9mm; font-family:<?= $F['serif'] ?>; font-size:4.6mm; color:<?= $P['teal'] ?>; }
  .sname { font-family:<?= $F['serif'] ?>; font-size:5mm; color:<?= $P['ink'] ?>; letter-spacing:.2px; }
  .saddr { font-size:8.5px; color:<?= $P['muted'] ?>; margin-top:.8mm; }
  .doctag { text-align:right; }
  .doctag .t { font-size:9px; letter-spacing:.16em; color:<?= $P['teal'] ?>; font-weight:bold; }
  .doctag .n { font-size:9.5px; color:<?= $P['muted'] ?>; margin-top:1.2mm; }
  .doctag .n b { color:<?= $P['ink'] ?>; }

  .rule { border-bottom:.6px solid <?= $P['line'] ?>; margin:6mm 0; }
  .rule2 { border-bottom:1px solid <?= $P['ink'] ?>; }

  /* Party grid */
  table.party { width:100%; border-collapse:collapse; }
  table.party td { vertical-align:top; padding:0 5mm 0 0; width:33%; }
  .pv { font-size:12px; font-weight:bold; margin-top:1.2mm; color:<?= $P['ink'] ?>; }
  .pv.sm { font-size:11px; }

  /* Hero amount */
  .hero { margin:8mm 0 7mm; padding:6mm 7mm; background:<?= $P['tint'] ?>; border:.6px solid <?= $P['line'] ?>; border-left:2.2px solid <?= $P['teal'] ?>; }
  .hero table { width:100%; border-collapse:collapse; }
  .hero .big { font-family:<?= $F['serif'] ?>; font-size:9mm; color:<?= $P['teal_d'] ?>; letter-spacing:-.2px; }
  .hero .capk { font-size:7.6px; letter-spacing:.14em; text-transform:uppercase; color:<?= $P['muted'] ?>; font-weight:bold; }
  .stamp { text-align:center; }
  .stampring { display:inline-block; width:19mm; height:19mm; border:1.1px solid <?= $P['teal'] ?>; border-radius:50%;
               transform:rotate(-11deg); text-align:center; line-height:19mm; opacity:.85; }
  .stampring b { font-family:<?= $F['serif'] ?>; font-size:9.5px; letter-spacing:.14em; color:<?= $P['teal'] ?>; }

  /* Items */
  table.items { width:100%; border-collapse:collapse; margin-top:1mm; }
  table.items thead td { border-bottom:1px solid <?= $P['ink'] ?>; padding:0 0 2.2mm; }
  table.items tbody td { padding:3mm 0; border-bottom:.6px solid <?= $P['line'] ?>; font-size:11px; }
  .r { text-align:right; } .amt { font-family:<?= $F['mono'] ?>; }
  .idx { color:<?= $P['faint'] ?>; width:6mm; font-family:<?= $F['mono'] ?>; }

  table.tot { width:62mm; border-collapse:collapse; margin-top:3mm; float:right; }
  table.tot td { padding:1.4mm 0; font-size:10.5px; }
  .totrow td { border-top:1.1px solid <?= $P['ink'] ?>; padding-top:2.6mm; font-size:14px; }
  .totrow .k { font-family:<?= $F['serif'] ?>; }
  .totrow .amt { font-family:<?= $F['serif'] ?>; color:<?= $P['teal_d'] ?>; }

  .words { clear:both; margin-top:56mm; padding:3mm 4mm; border-top:.6px solid <?= $P['line'] ?>; border-bottom:.6px solid <?= $P['line'] ?>; }
  .words .lbl { display:inline; margin-right:2mm; }
  .words .v { font-size:10px; color:<?= $P['ink2'] ?>; font-style:italic; }

  table.foot { width:100%; border-collapse:collapse; margin-top:9mm; }
  .qrbox img { width:16mm; height:16mm; }
  .qrcap { font-size:7px; color:<?= $P['faint'] ?>; text-align:center; margin-top:1mm; letter-spacing:.4px; }
  .sig { text-align:right; vertical-align:bottom; }
  .sigline { border-top:.6px solid <?= $P['ink'] ?>; display:inline-block; padding-top:1.6mm; width:48mm;
             font-size:8.4px; color:<?= $P['muted'] ?>; }
  .legal { margin-top:8mm; text-align:center; font-size:7.6px; color:<?= $P['faint'] ?>; letter-spacing:.03em; line-height:1.7; }
</style></head>
<body>
  <div class="sheet">

    <table class="hd"><tr>
      <td style="width:9mm"><div class="mk"><?= $monogram ?></div></td>
      <td style="padding-left:3.2mm">
        <div class="sname"><?= htmlspecialchars($SCHOOL['name']) ?></div>
        <div class="saddr"><?= htmlspecialchars($SCHOOL['address']) ?> &nbsp;·&nbsp; <?= htmlspecialchars($SCHOOL['phone']) ?></div>
      </td>
      <td class="doctag">
        <div class="t">FEE RECEIPT</div>
        <div class="n">No. <b class="mono"><?= $FEE_RECEIPT['receipt_no'] ?></b></div>
        <div class="n"><?= $FEE_RECEIPT['date'] ?> &nbsp;·&nbsp; Session <?= $SCHOOL['session'] ?></div>
      </td>
    </tr></table>

    <div class="rule"></div>

    <table class="party"><tr>
      <td><span class="lbl">Received From</span><div class="pv"><?= htmlspecialchars($FEE_RECEIPT['student']) ?></div>
          <div class="saddr">Guardian — <?= htmlspecialchars($FEE_RECEIPT['father']) ?></div></td>
      <td><span class="lbl">Student · Class</span><div class="pv sm mono"><?= $FEE_RECEIPT['student_id'] ?></div>
          <div class="saddr"><?= $FEE_RECEIPT['class'] ?></div></td>
      <td><span class="lbl">Payment Mode</span><div class="pv sm"><?= $FEE_RECEIPT['mode'] ?></div>
          <div class="saddr mono"><?= $FEE_RECEIPT['txn'] ?></div></td>
    </tr></table>

    <div class="hero"><table><tr>
      <td style="width:64%">
        <span class="capk">Amount Paid</span>
        <div class="big"><?= inr($total) ?></div>
      </td>
      <td class="stamp"><div class="stampring"><b>PAID</b></div></td>
    </tr></table></div>

    <table class="items">
      <thead><tr><td class="lbl idx">#</td><td class="lbl">Fee Head</td><td class="lbl r">Amount</td></tr></thead>
      <tbody>
      <?php foreach ($FEE_RECEIPT['lines'] as $i => $l): ?>
        <tr><td class="idx"><?= sprintf('%02d', $i + 1) ?></td>
            <td><?= htmlspecialchars($l['head']) ?></td>
            <td class="r amt"><?= inr($l['amount']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <table class="tot">
      <tr><td class="lbl">Subtotal</td><td class="r amt"><?= inr($total) ?></td></tr>
      <tr><td class="lbl">Discount</td><td class="r amt"><?= inr(0) ?></td></tr>
      <tr class="totrow"><td class="k">Total</td><td class="r amt"><?= inr($total) ?></td></tr>
    </table>

    <div class="words"><span class="lbl">In Words</span><span class="v"><?= inr_words($total) ?></span></div>

    <table class="foot"><tr>
      <td style="width:20mm">
        <?php if ($qr): ?><div class="qrbox"><img src="<?= $qr ?>" alt="QR"></div><div class="qrcap">VERIFY</div><?php endif; ?>
      </td>
      <td></td>
      <td class="sig"><span class="sigline">Authorised Signatory</span></td>
    </tr></table>

    <div class="legal">This is a system-generated receipt and is valid without a physical signature.<br>
      For queries, contact the fee office · <?= htmlspecialchars($SCHOOL['phone']) ?></div>
  </div>
</body></html>
