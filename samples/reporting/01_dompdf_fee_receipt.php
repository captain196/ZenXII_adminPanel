<?php
/**
 * OPTION 1 — dompdf  (Fees / Accounting module: fee receipts, vouchers, day book, ledgers)
 * STATUS: RUNS TODAY — dompdf ^2.0 is already installed and already used in Accounting.php.
 *
 *   php samples/reporting/01_dompdf_fee_receipt.php   →   out/fee_receipt_dompdf.pdf
 *
 * Pattern mirrors application/controllers/Accounting.php:4204 so it drops straight into a
 * CI3 controller (there you'd $dompdf->stream(...) to the browser instead of saving a file).
 * Best for: simple, tabular, mostly-English documents. Fast, pure-PHP, zero external deps.
 * Weak at: Devanagari/complex scripts, flexbox/grid, gradients — use mPDF or Chrome for those.
 */
require __DIR__ . '/_bootstrap.php';

$html = render_template(__DIR__ . '/templates/fee_receipt.php', compact('SCHOOL', 'FEE_RECEIPT'));

$options = new \Dompdf\Options();
$options->set('defaultFont', 'DejaVu Sans');      // DejaVu ships with dompdf (basic Unicode)
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);          // keep off in prod; embed images as data: URIs
$options->set('defaultPaperSize', 'A5');
$tmp = __DIR__ . '/out/_dompdf_tmp'; if (!is_dir($tmp)) mkdir($tmp, 0755, true);
$options->setTempDir($tmp); $options->setFontDir($tmp); $options->setFontCache($tmp);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'portrait');
$dompdf->render();

file_put_contents(out_path('fee_receipt_dompdf.pdf'), $dompdf->output());
echo "OK  →  samples/reporting/out/fee_receipt_dompdf.pdf\n";

/* In a controller you'd instead do:
     $dompdf->stream('receipt_'.$receiptNo.'.pdf', ['Attachment' => false]); exit;
*/
