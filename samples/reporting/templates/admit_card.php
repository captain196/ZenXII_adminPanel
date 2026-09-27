<?php
/**
 * Exam Admit Card / Hall Ticket — v2. Boarding-pass structure: a main ticket section and a
 * detachable-looking stub below a perforated divider (dashed rule + edge notches). Real
 * scannable QR. dompdf-safe (no clip-path; notches simulated with background-colored circles).
 * Vars: $SCHOOL, $ADMIT_CARD, $qrDataUri
 */
require_once __DIR__ . '/_brand_kit.php';
$P = bk_palette(); $F = bk_fonts();
$a = $ADMIT_CARD;
$monogram = strtoupper(substr($SCHOOL['name'], 0, 1));
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><style>
  * { font-family:<?= $F['sans'] ?>; }
  body { margin:0; color:<?= $P['ink'] ?>; font-size:11px; background:#fff; }
  .sheet { padding:14mm 16mm; }
  .lbl { text-transform:uppercase; letter-spacing:.1em; font-size:7.4px; color:<?= $P['muted'] ?>; font-weight:bold; }
  .ticket { border:.7px solid <?= $P['line2'] ?>; position:relative; }

  /* Main section */
  .band { padding:6mm 8mm 5mm; border-bottom:.8px solid <?= $P['ink'] ?>; }
  .band table { width:100%; border-collapse:collapse; }
  .mgram { width:9.4mm; height:9.4mm; border:.5mm solid <?= $P['teal'] ?>; border-radius:50%; text-align:center;
           line-height:8.8mm; font-family:<?= $F['serif'] ?>; font-size:4.6mm; color:<?= $P['teal'] ?>; }
  .sname { font-family:<?= $F['serif'] ?>; font-size:4.2mm; color:<?= $P['ink'] ?>; }
  .doctype { text-align:right; }
  .doctype .t { font-family:<?= $F['serif'] ?>; font-size:3.6mm; color:<?= $P['teal_d'] ?>; }
  .doctype .s { font-size:8px; color:<?= $P['muted'] ?>; margin-top:.6mm; }

  .who { padding:5mm 8mm; }
  .who table { width:100%; border-collapse:collapse; }
  .photo { width:18mm; height:22mm; border:.4mm solid <?= $P['line2'] ?>; background:<?= $P['tint'] ?>;
           color:<?= $P['teal'] ?>; text-align:center; line-height:22mm; font-size:7px; }
  .nm { font-family:<?= $F['serif'] ?>; font-size:4.2mm; color:<?= $P['ink'] ?>; }
  .kv { font-size:9px; color:<?= $P['muted'] ?>; margin-top:1.6mm; line-height:1.95; }
  .kv b { color:<?= $P['ink'] ?>; }
  .qrbox { text-align:center; }
  .qrbox img { width:20mm; height:20mm; }
  .qrcap { font-size:7px; color:<?= $P['faint'] ?>; margin-top:1mm; letter-spacing:.5px; }

  /* Perforation */
  .perf { position:relative; height:0; border-top:1px dashed <?= $P['line2'] ?>; margin:0 -0.7px; }
  .notch { position:absolute; top:-3.4mm; width:6.8mm; height:6.8mm; border-radius:50%; background:#fff;
           border:.7px solid <?= $P['line2'] ?>; }
  .notch.l { left:-3.4mm; } .notch.r { right:-3.4mm; }

  /* Stub */
  .stub { padding:5mm 8mm 6mm; background:<?= $P['tint'] ?>; }
  .stub-hd { display:table; width:100%; margin-bottom:2mm; }
  .stub-hd .a { display:table-cell; }
  .stub-hd .b { display:table-cell; text-align:right; }
  .stubtag { font-size:8px; color:<?= $P['muted'] ?>; letter-spacing:.4px; }
  .stubtag b { font-family:<?= $F['mono'] ?>; color:<?= $P['ink'] ?>; }

  table.sched { width:100%; border-collapse:collapse; margin-top:2mm; }
  .sched thead td { padding:1.8mm 0; border-bottom:.8px solid <?= $P['ink'] ?>; }
  .sched tbody td { padding:2.2mm 0; border-bottom:.5px solid <?= $P['line'] ?>; font-size:9.6px; }
  .c { text-align:center; }
  .daychip { font-family:<?= $F['mono'] ?>; font-size:8px; color:<?= $P['teal_d'] ?>; }

  .instr { margin-top:4mm; }
  .instr ul { margin:1.4mm 0 0; padding-left:4mm; font-size:8.6px; color:<?= $P['ink2'] ?>; line-height:1.9; }

  table.sigs { width:100%; border-collapse:collapse; margin-top:6mm; }
  .sigline { border-top:.5px solid <?= $P['ink'] ?>; padding-top:1.8mm; font-size:8.4px; color:<?= $P['muted'] ?>; text-align:center; }
</style></head>
<body>
  <div class="sheet"><div class="ticket">

    <div class="band"><table><tr>
      <td style="width:9.4mm"><div class="mgram"><?= $monogram ?></div></td>
      <td style="padding-left:3.4mm"><div class="sname"><?= htmlspecialchars($SCHOOL['name']) ?></div></td>
      <td class="doctype"><div class="t">Admit Card</div><div class="s">Hall Ticket · <?= htmlspecialchars($a['exam']) ?></div></td>
    </tr></table></div>

    <div class="who"><table><tr>
      <td style="width:18mm"><div class="photo">PHOTO</div></td>
      <td style="padding-left:5mm">
        <div class="nm"><?= htmlspecialchars($a['student']) ?></div>
        <div class="kv">
          Roll No <b><?= $a['roll'] ?></b> &nbsp;·&nbsp; Class <b><?= $a['class'] ?></b><br>
          Student ID <b><?= $a['student_id'] ?></b> &nbsp;·&nbsp; Father <b><?= htmlspecialchars($a['father']) ?></b><br>
          Center <b><?= htmlspecialchars($a['center']) ?></b>
        </div>
      </td>
      <td style="width:24mm" class="qrbox"><img src="<?= $qrDataUri ?>" alt="QR"><div class="qrcap">VERIFY</div></td>
    </tr></table></div>

    <div class="perf"><div class="notch l"></div><div class="notch r"></div></div>

    <div class="stub">
      <div class="stub-hd"><span class="a stubtag">EXAM SCHEDULE STUB</span>
        <span class="b stubtag">Roll <b><?= $a['roll'] ?></b></span></div>

      <table class="sched">
        <thead><tr><td class="lbl">Date</td><td class="lbl">Subject</td><td class="lbl c">Time</td><td class="lbl c">Room</td></tr></thead>
        <tbody>
        <?php foreach ($a['schedule'] as $s): ?>
          <tr>
            <td><?= $s['date'] ?> <span class="daychip">(<?= $s['day'] ?>)</span></td>
            <td><?= htmlspecialchars($s['subject']) ?></td>
            <td class="c"><?= $s['time'] ?></td>
            <td class="c"><?= $s['room'] ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <div class="instr">
        <span class="lbl">Instructions</span>
        <ul><?php foreach ($a['instructions'] as $i): ?><li><?= htmlspecialchars($i) ?></li><?php endforeach; ?></ul>
      </div>

      <table class="sigs"><tr>
        <td style="width:50%"><div class="sigline">Class Teacher</div></td>
        <td style="width:50%"><div class="sigline">Principal / Exam Controller</div></td>
      </tr></table>
    </div>

  </div></div>
</body></html>
