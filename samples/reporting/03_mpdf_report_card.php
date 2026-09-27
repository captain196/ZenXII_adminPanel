<?php
/**
 * OPTION 2 — mPDF  (Exam/Result: report cards, marksheets · Certificates: TC, Bonafide, offer letters)
 * STATUS: NEEDS INSTALL →  composer require mpdf/mpdf
 * (Pure-PHP, no JVM/Node. Adds ~40MB incl. Indian-language fonts. Windows + Ubuntu both fine.)
 *
 *   composer require mpdf/mpdf
 *   php samples/reporting/03_mpdf_report_card.php   →   out/report_card_mpdf.pdf
 *
 * WHY mPDF HERE: report cards & certificates are Unicode-heavy (Hindi/Devanagari, sometimes Urdu/
 * regional), letterhead-styled, and legally official. mPDF shapes Devanagari conjuncts correctly
 * out of the box (autoScriptToLang/autoLangToFont), supports watermarks, page headers/footers,
 * @page CSS, and better table/CSS handling than dompdf — with none of Chrome's runtime weight.
 */
if (!class_exists(\Mpdf\Mpdf::class)) {
    fwrite(STDERR, "mPDF not installed.  Run:  composer require mpdf/mpdf\n");
    exit(1);
}
require __DIR__ . '/_bootstrap.php';

$html = render_template(__DIR__ . '/templates/report_card.php', compact('SCHOOL', 'REPORT_CARD'));

$tmp = __DIR__ . '/out/_mpdf_tmp'; if (!is_dir($tmp)) mkdir($tmp, 0755, true);
$mpdf = new \Mpdf\Mpdf([
    'mode'            => 'utf-8',
    'format'          => 'A4',
    'tempDir'         => $tmp,
    'autoScriptToLang'=> true,   // ← detect Devanagari and switch to an Indic font automatically
    'autoLangToFont'  => true,
    'margin_top'      => 14,
    'margin_bottom'   => 14,
]);
$mpdf->SetTitle('Report Card — ' . $REPORT_CARD['student']);
// Optional official touch mPDF does easily and dompdf can't: a diagonal watermark
$mpdf->SetWatermarkText('IIT KANPUR SCHOOL', 0.06);
$mpdf->showWatermarkText = true;

$mpdf->WriteHTML($html);
$mpdf->Output(out_path('report_card_mpdf.pdf'), \Mpdf\Output\Destination::FILE);
echo "OK  →  samples/reporting/out/report_card_mpdf.pdf\n";

/* Controller streaming:  $mpdf->Output('report_card.pdf', \Mpdf\Output\Destination::INLINE); exit; */
