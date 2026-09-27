<?php
/**
 * Certificate — v2. Real hand-drawn ornamentation (laurel wreath, ribbon seal, corner
 * flourishes) from _brand_kit.php instead of a plain double-border box. Serif display
 * headline, muted antique-gold accent, quiet watermark. dompdf-safe.
 * Vars: $SCHOOL, $CERT_TOKENS, $BODY, $CERT_TITLE
 */
require_once __DIR__ . '/_brand_kit.php';
$P = bk_palette(); $F = bk_fonts();
$monogram = strtoupper(substr($SCHOOL['name'], 0, 1));
$laurelL = bk_laurel($P['gold'], 92);
$laurelR = bk_laurel($P['gold'], 92);
$seal    = bk_seal($P['teal'], 76);
$flourish= bk_corner_flourish($P['gold'], 40);
$rosette = bk_rosette($P['gold'], 11);
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><style>
  * { font-family:<?= $F['sans'] ?>; }
  @page { margin:0; }
  body { margin:0; color:<?= $P['ink'] ?>; background:#fff; }
  .outer { padding:14mm; }
  .frame { border:.9mm solid <?= $P['teal'] ?>; padding:5mm; position:relative; }
  .frame-in { border:.28mm solid <?= $P['gold_l'] ?>; padding:12mm 16mm 14mm; min-height:238mm; position:relative; }

  .corner { position:absolute; z-index:2; }
  .c-tl { top:3mm; left:3mm; }
  .c-tr { top:3mm; right:3mm; transform:scaleX(-1); }
  .c-bl { bottom:3mm; left:3mm; transform:scaleY(-1); }
  .c-br { bottom:3mm; right:3mm; transform:scale(-1,-1); }

  .watermark { position:absolute; top:96mm; left:0; right:0; text-align:center; z-index:0; }
  .watermark span { font-family:<?= $F['serif'] ?>; font-size:64mm; color:<?= $P['teal'] ?>; opacity:.035; letter-spacing:2mm; }

  .content { position:relative; z-index:1; }
  .hd { text-align:center; }
  .mgram { display:inline-block; width:13mm; height:13mm; border:.5mm solid <?= $P['teal'] ?>; border-radius:50%;
           font-family:<?= $F['serif'] ?>; font-size:6.4mm; color:<?= $P['teal'] ?>; line-height:12mm; margin-bottom:3mm; }
  .sname { font-family:<?= $F['serif'] ?>; font-size:6mm; letter-spacing:.4px; color:<?= $P['ink'] ?>; }
  .saddr { font-size:9px; color:<?= $P['muted'] ?>; margin-top:1.4mm; letter-spacing:.02em; }

  .hrule { width:100%; text-align:center; margin:5mm 0; }
  .hrule .l, .hrule .r { display:inline-block; width:26mm; height:0; border-top:.4px solid <?= $P['gold_l'] ?>; vertical-align:middle; }
  .hrule .m { display:inline-block; vertical-align:middle; margin:0 2.4mm; }

  .meta { text-align:center; font-size:8.6px; color:<?= $P['muted'] ?>; letter-spacing:.03em; margin-bottom:7mm; }
  .meta b { color:<?= $P['ink'] ?>; font-family:<?= $F['mono'] ?>; }

  .title-wrap { text-align:center; margin:0 0 9mm; }
  .laurel { display:inline-block; vertical-align:middle; }
  .title { display:inline-block; vertical-align:middle; margin:0 5mm; }
  .title .t1 { font-family:<?= $F['serif'] ?>; font-size:9.4mm; color:<?= $P['teal_d'] ?>; letter-spacing:1px; text-transform:uppercase; }
  .title .t2 { font-size:8.6px; color:<?= $P['gold'] ?>; letter-spacing:5px; margin-top:1mm; }

  .body { font-size:11.5px; line-height:2.15; text-align:justify; padding:0 4mm; color:<?= $P['ink2'] ?>; }
  .body b { color:<?= $P['ink'] ?>; }

  .sealwrap { text-align:center; margin:12mm 0 8mm; position:relative; }
  .sealtext { position:absolute; left:0; right:0; top:11.5mm; text-align:center; }
  .sealtext b { font-family:<?= $F['serif'] ?>; font-size:7px; letter-spacing:1.4px; color:#fff; }

  table.sigs { width:100%; border-collapse:collapse; margin-top:10mm; }
  .sigline { border-top:.5px solid <?= $P['ink'] ?>; padding-top:2.2mm; font-size:9px; color:<?= $P['muted'] ?>;
             text-align:center; width:52mm; margin:0 auto; }
  .sigs td { text-align:center; }

  .foot { position:absolute; bottom:9mm; left:16mm; right:16mm; text-align:center; z-index:1;
          font-size:7.4px; color:<?= $P['faint'] ?>; border-top:.3px dashed <?= $P['line'] ?>; padding-top:3mm; }
</style></head>
<body>
  <div class="outer"><div class="frame"><div class="frame-in">

    <div class="corner c-tl"><?= $flourish ?></div>
    <div class="corner c-tr"><?= $flourish ?></div>
    <div class="corner c-bl"><?= $flourish ?></div>
    <div class="corner c-br"><?= $flourish ?></div>
    <div class="watermark"><span><?= $monogram ?></span></div>

    <div class="content">
      <div class="hd">
        <div class="mgram"><?= $monogram ?></div>
        <div class="sname"><?= htmlspecialchars($SCHOOL['name']) ?></div>
        <div class="saddr"><?= htmlspecialchars($CERT_TOKENS['{school_address}']) ?></div>
      </div>

      <div class="meta">Certificate No. <b><?= $CERT_TOKENS['{certificate_number}'] ?></b>
        &nbsp;&nbsp;·&nbsp;&nbsp; Session <b><?= $CERT_TOKENS['{session}'] ?></b>
        &nbsp;&nbsp;·&nbsp;&nbsp; Issued <b><?= $CERT_TOKENS['{issue_date}'] ?></b></div>

      <div class="title-wrap">
        <span class="laurel"><?= $laurelL ?></span><span class="title"><span class="t1"><?= $CERT_TITLE ?></span><br><span class="t2">प्रमाण पत्र</span></span><span class="laurel" style="transform:scaleX(-1)"><?= $laurelR ?></span>
      </div>

      <div class="body"><?= $BODY ?></div>

      <div class="sealwrap">
        <?= $seal ?>
        <div class="sealtext"><b>OFFICIAL SEAL</b></div>
      </div>

      <table class="sigs"><tr>
        <td><div class="sigline">Class Teacher</div></td>
        <td><div class="sigline">Principal</div></td>
      </tr></table>
    </div>

    <div class="foot">This certificate is issued electronically and is valid without a physical signature unless required for official submission.</div>
  </div></div></div>
</body></html>
