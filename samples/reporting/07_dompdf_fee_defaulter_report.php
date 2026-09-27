<?php
/**
 * OPTION 7 (NEW) — Fee Defaulter Report  (Fees module)
 * STATUS: RUNS TODAY (dompdf, already installed)
 *
 *   php samples/reporting/07_dompdf_fee_defaulter_report.php   →   out/fee_defaulter_report.pdf
 *
 * GAP CLOSED: Fees.php::defaulter_report() renders a JSON-fed grid on screen with no
 * export button wired — the office can't print/share/file this list. Reuses the same
 * $DEFAULTERS sample data as 02_phpspreadsheet_fee_defaulters.php (Excel for
 * sort/filter; this PDF for print/file/share) — two formats, one data source.
 */
require __DIR__ . '/_bootstrap.php';

$totalDue = array_sum(array_column($DEFAULTERS, 'due'));
$html = render_template(__DIR__ . '/templates/defaulter_report.php', compact('SCHOOL', 'DEFAULTERS', 'totalDue'));

$options = new \Dompdf\Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultPaperOrientation', 'landscape');
$tmp = __DIR__ . '/out/_dompdf_tmp'; if (!is_dir($tmp)) mkdir($tmp, 0755, true);
$options->setTempDir($tmp); $options->setFontDir($tmp); $options->setFontCache($tmp);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

file_put_contents(out_path('fee_defaulter_report.pdf'), $dompdf->output());
echo "OK  →  samples/reporting/out/fee_defaulter_report.pdf\n";
