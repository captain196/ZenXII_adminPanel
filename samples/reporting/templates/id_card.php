<?php
/**
 * Student ID card — v2. Chrome-only, so no compromises: LIVE brand fonts (@import — Syne
 * for display, Plus Jakarta Sans for body, JetBrains Mono for IDs — the exact family stack
 * your live app already uses via Google Fonts), a real fine-noise texture via SVG
 * feTurbulence (Chrome renders this; dompdf can't), a holographic sheen strip, a
 * contactless-chip accent, and a real scannable QR (chillerlan). Exact CR80 size.
 * Vars: $SCHOOL, $ID_CARD
 */
$c = $ID_CARD; $teal = '#0e5c53'; $teal2 = '#083a34'; $gold = '#b89550';
$qrDataUri = function_exists('real_qr_data_uri') ? real_qr_data_uri('STUDENT|' . $c['student_id'] . '|' . $c['valid']) : '';
?>
<!DOCTYPE html><html><head><meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  @page { size:85.6mm 54mm; margin:0; }
  * { margin:0; box-sizing:border-box; }
  body { width:85.6mm; height:54mm; font-family:'Plus Jakarta Sans', sans-serif; }

  .card { width:85.6mm; height:54mm; position:relative; overflow:hidden; color:#fff; border-radius:3.4mm;
          box-shadow:0 10px 30px rgba(6,20,18,.35), 0 2px 6px rgba(6,20,18,.25); }

  /* Base gradient (deep teal → near-black), a soft top-right glow, and a fine noise texture
     layered on top via SVG feTurbulence — this trio is what makes it read as a "hard card"
     rather than a flat rectangle. Chrome-only technique. */
  .bg { position:absolute; inset:0;
        background:
          radial-gradient(120% 90% at 88% -10%, rgba(184,149,80,.16), transparent 55%),
          radial-gradient(140% 120% at -10% 120%, rgba(255,255,255,.05), transparent 50%),
          linear-gradient(135deg, <?= $teal ?> 0%, #0a4640 46%, <?= $teal2 ?> 100%); }
  .noise { position:absolute; inset:0; opacity:.05; mix-blend-mode:overlay; }
  .sheen { position:absolute; top:-30%; left:-20%; width:60%; height:180%;
           background:linear-gradient(100deg, transparent 30%, rgba(255,255,255,.10) 48%, transparent 66%);
           transform:rotate(8deg); }

  .content { position:relative; z-index:2; height:100%; display:flex; flex-direction:column; }

  .bar { padding:3.2mm 4.4mm 0; display:flex; align-items:center; gap:2.4mm; }
  .mgram { width:7.4mm; height:7.4mm; border-radius:50%; background:rgba(255,255,255,.10);
           border:.35mm solid rgba(255,255,255,.5); display:flex; align-items:center; justify-content:center;
           font-family:'Syne',sans-serif; font-weight:800; font-size:3.4mm; }
  .brand b { font-family:'Syne',sans-serif; font-weight:800; font-size:3.05mm; letter-spacing:.1px; display:block; }
  .brand span { display:block; font-size:1.75mm; opacity:.72; letter-spacing:1.6px; margin-top:.3mm; }
  .valid { margin-left:auto; text-align:right; font-size:1.85mm; opacity:.8; }
  .valid b { font-family:'JetBrains Mono',monospace; font-weight:500; display:block; }

  .chip { position:absolute; top:12.6mm; left:4.4mm; width:7.4mm; height:5.6mm; border-radius:1.1mm;
          background:linear-gradient(150deg, #e8d9ae, <?= $gold ?> 55%, #8a6d2e); opacity:.9; }
  .chip::before, .chip::after { content:''; position:absolute; left:0; right:0; height:.28mm; background:rgba(10,20,18,.35); }
  .chip::before { top:1.9mm; } .chip::after { top:3.5mm; }

  .main { flex:1; display:flex; align-items:flex-end; gap:3.2mm; padding:0 4.4mm 3mm; margin-top:4mm; }
  .photo { width:18mm; height:22mm; border-radius:2mm; flex-shrink:0;
           background:linear-gradient(160deg,#dcece8,#a9c9c2); border:.5mm solid rgba(255,255,255,.85);
           box-shadow:0 2.4mm 5mm rgba(0,0,0,.28); display:flex; align-items:flex-end; justify-content:center;
           color:#0a4640; font-size:2mm; font-weight:700; padding-bottom:1.4mm; }
  .info { flex:1; min-width:0; }
  .nm { font-family:'Syne',sans-serif; font-weight:700; font-size:4mm; line-height:1.06; color:#fff; }
  .sid { font-family:'JetBrains Mono',monospace; font-size:2.15mm; color:rgba(255,255,255,.72); margin:.9mm 0 1.8mm; letter-spacing:.4px; }
  .row { display:flex; font-size:2.35mm; line-height:1.95; }
  .row .k { width:13mm; color:rgba(255,255,255,.52); text-transform:uppercase; letter-spacing:.4px; font-size:1.9mm; }
  .row .v { font-weight:600; color:#fff; }

  .qr { position:absolute; right:4.2mm; bottom:9.5mm; text-align:center; }
  .qr img { width:14mm; height:14mm; border-radius:1mm; background:#fff; padding:.9mm; }
  .qr .cap { font-size:1.65mm; opacity:.65; letter-spacing:.6px; margin-top:.8mm; }

  .foot { height:6.4mm; background:rgba(0,0,0,.22); backdrop-filter:blur(2px); display:flex; align-items:center;
          justify-content:space-between; padding:0 4.4mm; font-size:2mm; }
  .foot .pill { background:<?= $gold ?>; color:#241c08; border-radius:3mm; padding:.5mm 2.4mm; font-weight:700; letter-spacing:.3px; font-size:1.9mm; }
</style></head>
<body>
  <div class="card">
    <div class="bg"></div>
    <svg class="noise" width="100%" height="100%"><filter id="n"><feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch"/></filter><rect width="100%" height="100%" filter="url(#n)"/></svg>
    <div class="sheen"></div>

    <div class="content">
      <div class="bar">
        <div class="mgram"><?= strtoupper(substr($SCHOOL['name'],0,1)) ?></div>
        <div class="brand"><b><?= htmlspecialchars($SCHOOL['name']) ?></b><span>STUDENT IDENTITY CARD</span></div>
        <div class="valid">Valid<br><b><?= $c['valid'] ?></b></div>
      </div>

      <div class="chip"></div>

      <div class="main">
        <div class="photo">PHOTO</div>
        <div class="info">
          <div class="nm"><?= htmlspecialchars($c['name']) ?></div>
          <div class="sid"><?= $c['student_id'] ?></div>
          <div class="row"><span class="k">Class</span><span class="v"><?= $c['class'] ?></span></div>
          <div class="row"><span class="k">DOB</span><span class="v"><?= $c['dob'] ?></span></div>
          <div class="row"><span class="k">Blood</span><span class="v"><?= $c['blood'] ?></span></div>
        </div>
      </div>

      <?php if ($qrDataUri): ?>
      <div class="qr"><img src="<?= $qrDataUri ?>" alt="QR"><div class="cap">SCAN</div></div>
      <?php endif; ?>

      <div class="foot"><span><?= $c['phone'] ?></span><span class="pill">IF FOUND, RETURN TO SCHOOL</span></div>
    </div>
  </div>
</body></html>
