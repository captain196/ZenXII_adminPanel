<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * IDC-CSS-1 — the ID card's appearance, defined ONCE.
 *
 * Two consumers, one source:
 *   - sis/id_card.php echoes this into its <style> block;
 *   - cardCSS() returns it JSON-encoded for the bulk-print window, which is a
 *     blank document and inherits nothing from the page.
 *
 * Before this, the print window carried a hand-maintained paraphrase that had
 * already drifted: it still styled `.idc-sid*`, elements the card stopped
 * rendering when SR No. became the printed identity. A card that looks one way
 * on screen and another on paper is the failure this removes.
 *
 * Geometry lives here too (CR80 ratio, fixed height, print-color-adjust), so
 * the printed artifact cannot diverge from the previewed one.
 */
$IDC_CARD_CSS = <<<'IDCCSS'
/* IDC-CSS-1 — physical geometry, declared ONCE and shipped to both consumers.
     CR80 portrait   53.98 x 85.6mm — what badge holders and card printers take
     on screen       260 x 412px    — the same 1:1.5858 ratio
     print scale     53.98mm / 68.79mm (260px) = 0.785
   These used to live in the page only, so the bulk-print window declared its
   own copy and a size change in one place printed at the old scale in the
   other. */
:root { --idc-w:53.98mm; --idc-h:85.6mm; --idc-print-scale:0.785; }

/* ══════════════════════════════════════════════════════
   THE ID CARD
   ══════════════════════════════════════════════════════ */
/* IDC-PRINT-1 (2026-09-19) — THE CARD IS A PHYSICAL OBJECT.

   Height used to be content-driven, so a student WITH a blood group produced a
   taller card than one without and a printed sheet came out as mismatched
   rectangles that would not stack after cutting. It is now fixed to the CR80
   portrait ratio (53.98 x 85.6mm = 1:1.5858), which is what the @media print
   block scales to. 260 x 412px is that ratio exactly.

   flex-column + .idc-info{flex:1} makes the info block absorb the slack, so the
   footer is pinned to the bottom edge on EVERY card regardless of how many
   optional rows a student has. overflow:hidden is the hard guarantee that no
   unusually long name can push the geometry back out again. */
.idc-card {
    width:260px; height:412px; border-radius:10px; overflow:hidden; background:var(--bg2);
    box-shadow:0 2px 12px rgba(0,0,0,.08); font-family:sans-serif;
    page-break-inside:avoid; transition:transform .2s, box-shadow .2s;
    display:flex; flex-direction:column;
    /* Browsers drop background colours when printing unless asked not to. The
       header and footer ARE the school's branding — without this the card
       prints with a white band where the school name should be. */
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
}
.idc-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.12); }

/* ── Header: Logo left + School name & address right ── */
.idc-hdr {
    background:var(--idc); padding:10px 12px; display:flex; align-items:center; gap:8px;
}
.idc-hdr-logo {
    width:36px; height:36px; border-radius:50%; object-fit:contain; background:#fff; padding:2px; flex-shrink:0;
}
.idc-hdr-info { flex:1; min-width:0; }
.idc-hdr-name { color:#fff; font-size:.74rem; font-weight:700; line-height:1.25;
    overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.idc-hdr-addr { color:rgba(255,255,255,.72); font-size:.6rem; line-height:1.3; margin-top:1px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ── Photo — sits below header, no overlap ── */
.idc-photo-area { display:flex; justify-content:center; padding:12px 0 6px; background:var(--bg2); }
.idc-photo {
    width:72px; height:72px; border-radius:50%; object-fit:cover;
    border:3px solid var(--idc); box-shadow:0 2px 8px rgba(0,0,0,.1); background:var(--bg3);
}
.idc-photo-fb {
    width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    border:3px solid var(--idc); box-shadow:0 2px 8px rgba(0,0,0,.1);
    background:var(--idc-lt); color:var(--idc); font-size:1.6rem; font-weight:700; font-family:var(--font-b);
}

/* ── Body ── */
.idc-body { padding:4px 14px 10px; text-align:center; }

.idc-name { font-size:.92rem; font-weight:700; color:var(--t1); font-family:var(--font-b);
    line-height:1.3; margin-bottom:1px; word-break:break-word; }
.idc-class { font-size:.72rem; color:var(--t2); margin-bottom:6px; font-weight:500; }

/* QR — local SVG render via chillerlan/php-qrcode embedded as a data
   URI. Vector means no pixelation at any zoom level — print-friendly
   by default. Slightly smaller (76px) than the previous 92px because
   the user wanted a tighter card layout; SVG stays sharp regardless. */
.idc-qr-wrap { display:flex; justify-content:center; margin:4px 0 6px; }
.idc-qr-wrap img { width:76px; height:76px; }

/* ── Info rows ── */
/* flex:1 — absorbs whatever vertical slack the optional rows leave, which is
   what keeps the footer on the bottom edge of every card in a batch. */
.idc-info { padding:0 14px 8px; flex:1 1 auto; }
.idc-info-row { display:flex; align-items:center; gap:6px; padding:3px 0;
    border-bottom:1px solid var(--border); font-size:.73rem; }
.idc-info-row:last-child { border-bottom:none; }
.idc-info-ico { color:var(--idc); width:14px; text-align:center; flex-shrink:0; font-size:.7rem; }
.idc-info-lbl { color:var(--t3); min-width:48px; font-size:.68rem; }
.idc-info-val { color:var(--t1); font-weight:600; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.idc-info-val.idc-blood { color:#dc2626; }
.idc-info-val.idc-phone { color:#2563eb; }

/* ── Footer ── */
.idc-ftr { background:var(--idc); padding:6px 12px; display:flex; align-items:center; justify-content:space-between; }
.idc-ftr-label { font-size:.6rem; color:rgba(255,255,255,.9); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.idc-ftr-valid { font-size:.58rem; color:rgba(255,255,255,.65); }
IDCCSS;
