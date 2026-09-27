<?php
/**
 * Report card — v2. Real per-subject performance bars (SVG) + a radial score gauge instead
 * of flat colored chips. Serif display headline (English run only), DejaVu for bilingual
 * body. dompdf-safe SVG (path/circle only, no filters).
 * Vars: $SCHOOL, $REPORT_CARD
 */
require_once __DIR__ . '/_brand_kit.php';
$P = bk_palette(); $F = bk_fonts();
$rc = $REPORT_CARD;
$obt = array_sum(array_column($rc['subjects'], 'marks'));
$max = array_sum(array_column($rc['subjects'], 'max'));
$pct = round($obt / $max * 100, 1);
$monogram = strtoupper(substr($SCHOOL['name'], 0, 1));

$gradeColor = function ($g) use ($P) {
    $m = ['A1'=>$P['success'],'A2'=>$P['teal'],'B1'=>'#0e6e8c','B2'=>'#9a7b1f','C1'=>'#b06a12','C2'=>$P['danger']];
    return $m[$g] ?? $P['muted'];
};
/** Thin horizontal SVG bar, subject score out of max. dompdf-safe (rect only). */
$bar = function (float $frac, string $color) {
    $w = max(2, round($frac * 100));
    return '<svg width="100" height="6" viewBox="0 0 100 6" xmlns="http://www.w3.org/2000/svg">'
         . '<rect x="0" y="1.6" width="100" height="2.8" rx="1.4" fill="#e4e8ec"/>'
         . '<rect x="0" y="1.6" width="' . $w . '" height="2.8" rx="1.4" fill="' . $color . '"/></svg>';
};
/** Radial gauge approximated with layered arcs (dompdf: path arcs render fine). */
$gauge = function (float $pct, string $color, string $track) {
    $r = 38; $cx = 44; $cy = 44; $circ = 2 * M_PI * $r;
    $offset = $circ * (1 - $pct / 100);
    return '<svg width="88" height="88" viewBox="0 0 88 88" xmlns="http://www.w3.org/2000/svg">'
         . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $track . '" stroke-width="7"/>'
         . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="7" '
         . 'stroke-linecap="round" stroke-dasharray="' . $circ . '" stroke-dashoffset="' . $offset . '" '
         . 'transform="rotate(-90 ' . $cx . ' ' . $cy . ')"/></svg>';
};
?>
<style>
  * { font-family:<?= $F['sans'] ?>; }
  body { color:<?= $P['ink'] ?>; font-size:11px; }
  .lbl { text-transform:uppercase; letter-spacing:.09em; font-size:7.6px; color:<?= $P['muted'] ?>; font-weight:bold; }
  .card { border:.7px solid <?= $P['line'] ?>; }

  .band { padding:6mm 8mm 5mm; border-bottom:.8px solid <?= $P['ink'] ?>; }
  .band table { width:100%; border-collapse:collapse; }
  .mgram { width:10mm; height:10mm; border:.5mm solid <?= $P['teal'] ?>; border-radius:50%; text-align:center;
           line-height:9.4mm; font-family:<?= $F['serif'] ?>; font-size:5mm; color:<?= $P['teal'] ?>; }
  .sname { font-family:<?= $F['serif'] ?>; font-size:4.6mm; color:<?= $P['ink'] ?>; }
  .saddr { font-size:8px; color:<?= $P['muted'] ?>; margin-top:.6mm; }
  .doctype { text-align:right; }
  .doctype .t { font-family:<?= $F['serif'] ?>; font-size:4mm; letter-spacing:.4px; color:<?= $P['teal_d'] ?>; }
  .doctype .s { font-size:8px; color:<?= $P['muted'] ?>; margin-top:.6mm; }

  .who { padding:5mm 8mm; background:<?= $P['tint'] ?>; border-bottom:.6px solid <?= $P['line'] ?>; }
  .who table { width:100%; border-collapse:collapse; }
  .photo { width:18mm; height:22mm; border:.4mm solid #fff; box-shadow:0 1mm 2mm rgba(0,0,0,.12);
           background:<?= $P['teal_t'] ?>; color:<?= $P['teal'] ?>; text-align:center; line-height:22mm; font-size:7px; }
  .who .nm { font-family:<?= $F['serif'] ?>; font-size:4.4mm; color:<?= $P['ink'] ?>; }
  .who .nm span { color:<?= $P['teal'] ?>; font-size:3.6mm; }
  .kv { font-size:9px; color:<?= $P['muted'] ?>; margin-top:1.2mm; line-height:1.85; }
  .kv b { color:<?= $P['ink'] ?>; }

  table.mk { width:100%; border-collapse:collapse; }
  .mk thead td { padding:2.6mm 8mm; border-bottom:.9px solid <?= $P['ink'] ?>; }
  .mk tbody td { padding:2.8mm 8mm; border-bottom:.5px solid <?= $P['line'] ?>; font-size:10.5px; vertical-align:middle; }
  .c { text-align:center; }
  .subhi { color:<?= $P['faint'] ?>; font-size:8.6px; }
  .gtag { font-family:<?= $F['mono'] ?>; font-size:9px; font-weight:bold; }
  .mkfoot td { padding:3mm 8mm; border-top:1px solid <?= $P['ink'] ?>; font-weight:bold; font-size:11px; }

  .sum { padding:6mm 8mm 3mm; }
  .sum table { width:100%; border-collapse:collapse; }
  .gaugebox { text-align:center; position:relative; width:88px; }
  .gaugetxt { position:absolute; top:0; left:0; width:88px; height:88px; text-align:center; }
  .gaugetxt .p { font-family:<?= $F['serif'] ?>; font-size:16px; color:<?= $P['teal_d'] ?>; margin-top:29px; }
  .gaugetxt .u { font-size:7px; color:<?= $P['muted'] ?>; }
  .result { display:inline-block; border:.6px solid <?= $P['success'] ?>; color:<?= $P['success'] ?>;
            padding:2.2mm 5mm; font-size:11px; letter-spacing:.4px; font-family:<?= $F['serif'] ?>; }
  .legend { font-size:8px; color:<?= $P['faint'] ?>; margin-top:3mm; }

  .sig { padding:8mm 8mm 6mm; }
  .sig table { width:100%; border-collapse:collapse; }
  .sigline { border-top:.5px solid <?= $P['ink'] ?>; padding-top:1.8mm; font-size:8.6px; color:<?= $P['muted'] ?>; text-align:center; width:44mm; margin:0 auto; }
</style>

<div class="card">
  <div class="band"><table><tr>
    <td style="width:10mm"><div class="mgram"><?= $monogram ?></div></td>
    <td style="padding-left:3.4mm"><div class="sname"><?= htmlspecialchars($SCHOOL['name']) ?></div>
        <div class="saddr"><?= htmlspecialchars($SCHOOL['address']) ?></div></td>
    <td class="doctype"><div class="t">Progress Report</div><div class="s">प्रगति पत्र · <?= $rc['term'] ?></div></td>
  </tr></table></div>

  <div class="who"><table><tr>
    <td style="width:18mm"><div class="photo">PHOTO</div></td>
    <td style="padding-left:4mm">
      <div class="nm"><?= htmlspecialchars($rc['student']) ?> &nbsp;<span>· <?= $rc['student_hi'] ?></span></div>
      <div class="kv">Class <b><?= $rc['class'] ?></b> &nbsp;·&nbsp; Roll <b><?= $rc['roll'] ?></b> &nbsp;·&nbsp; ID <b><?= $rc['student_id'] ?></b></div>
    </td>
  </tr></table></div>

  <table class="mk">
    <thead><tr>
      <td class="lbl">Subject</td><td class="lbl c">Marks</td><td class="lbl" style="width:34%">Performance</td><td class="lbl c">Grade</td>
    </tr></thead>
    <tbody>
    <?php foreach ($rc['subjects'] as $s): $frac = $s['marks'] / $s['max']; $col = $gradeColor($s['grade']); ?>
      <tr>
        <td><?= htmlspecialchars($s['name']) ?> <span class="subhi">· <?= $s['name_hi'] ?></span></td>
        <td class="c"><b><?= $s['marks'] ?></b><span class="subhi">/<?= $s['max'] ?></span></td>
        <td><?= $bar($frac, $col) ?></td>
        <td class="c"><span class="gtag" style="color:<?= $col ?>"><?= $s['grade'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr class="mkfoot"><td>Total</td><td class="c"><?= $obt ?>/<?= $max ?></td><td></td><td></td></tr></tfoot>
  </table>

  <div class="sum"><table><tr>
    <td style="width:100px">
      <div class="gaugebox"><?= $gauge($pct, $P['teal'], $P['line']) ?>
        <div class="gaugetxt"><div class="p"><?= $pct ?>%</div></div></div>
    </td>
    <td style="padding-left:8mm">
      <span class="lbl">Result</span><br><br>
      <span class="result">PASS · उत्तीर्ण</span>
      <div class="legend">Grading — A1 91–100 · A2 81–90 · B1 71–80 · B2 61–70 · C1 51–60 · C2 41–50</div>
    </td>
  </tr></table></div>

  <div class="sig"><table><tr>
    <td style="width:33%"><div class="sigline">Class Teacher</div></td>
    <td style="width:34%"></td>
    <td style="width:33%"><div class="sigline">Principal</div></td>
  </tr></table></div>
</div>
