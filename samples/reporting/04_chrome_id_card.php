<?php
/**
 * OPTION 3 — headless Chrome via Browsershot  (SIS: ID cards · Admission: posters ·
 *            Analytics: dashboard/chart snapshots that must look EXACTLY like the browser)
 * STATUS: NEEDS INSTALL (heaviest option — only use where fidelity truly matters):
 *   1) composer require spatie/browsershot
 *   2) npm install -g puppeteer          (or: npm i puppeteer in a known dir)
 *   3) a Chrome/Chromium must exist (puppeteer downloads one; or point to system Chrome)
 *
 *   php samples/reporting/04_chrome_id_card.php   →   out/id_card_chrome.pdf  +  out/id_card_chrome.png
 *
 * WHY CHROME HERE: an ID card uses real flexbox, CSS gradients, background-clip:text, rounded
 * photos, box-shadows and web fonts — Chrome renders these pixel-perfect (it IS a browser);
 * dompdf/mPDF flatten or drop them. Same reason to use it for analytics dashboards → PDF/PNG,
 * or any marketing-grade output. Trade-off: needs Node + a ~150MB Chromium + ~0.5-1s/render,
 * so reserve it for the handful of reports where design fidelity is the whole point.
 */
if (!class_exists(\Spatie\Browsershot\Browsershot::class)) {
    fwrite(STDERR, "Browsershot not installed.  Run:\n  composer require spatie/browsershot\n  npm install -g puppeteer\n");
    exit(1);
}
require __DIR__ . '/_bootstrap.php';

use Spatie\Browsershot\Browsershot;

$html = render_template(__DIR__ . '/templates/id_card.php', compact('SCHOOL', 'ID_CARD'));

// PDF at exact CR80 card size
Browsershot::html($html)
    ->paperSize(85.6, 54, 'mm')      // ISO CR80 ID card
    ->margins(0, 0, 0, 0)
    ->showBackground()               // keep the gradient background
    // ->setNodeBinary('C:\\Program Files\\nodejs\\node.exe')   // set if node isn't on PATH
    // ->setChromePath('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe') // or system Chrome
    ->savePdf(out_path('id_card_chrome.pdf'));

// Also a crisp PNG (great for parent-app / previews)
Browsershot::html($html)
    ->windowSize(324, 204)           // 85.6mm x 54mm @ ~96dpi
    ->deviceScaleFactor(3)           // retina-sharp
    ->fullPage()
    ->save(out_path('id_card_chrome.png'));

echo "OK  →  out/id_card_chrome.pdf  +  out/id_card_chrome.png\n";
