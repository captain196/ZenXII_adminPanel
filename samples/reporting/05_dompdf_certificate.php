<?php
/**
 * OPTION 5 (NEW) — Bonafide / Transfer Certificate  (Certificates module)
 * STATUS: RUNS TODAY (dompdf, already installed)
 *
 *   php samples/reporting/05_dompdf_certificate.php   →   out/certificate_dompdf.pdf
 *
 * WHY THIS ONE MATTERS MOST: application/controllers/Certificates.php already defines
 * CERT_TYPES = ['bonafide','transfer','character','custom'] and a PLACEHOLDERS token list
 * ({student_name}, {father_name}, {admission_number}, …) — but the output is still
 * window.print() in the browser. This prototype uses the EXACT SAME token format
 * ($CERT_TOKENS in _bootstrap.php mirrors Certificates.php::PLACEHOLDERS byte-for-byte),
 * so the templates/certificate.php body is a drop-in: swap Certificates.php's client-side
 * print for `$this->pdf_generator->download($html)` and nothing else changes.
 */
require __DIR__ . '/_bootstrap.php';

$body = strtr($CERT_BODY_BONAFIDE, $CERT_TOKENS);
$html = render_template(__DIR__ . '/templates/certificate.php', [
    'SCHOOL' => $SCHOOL, 'CERT_TOKENS' => $CERT_TOKENS, 'BODY' => $body, 'CERT_TITLE' => 'Bonafide Certificate',
]);

$options = new \Dompdf\Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$tmp = __DIR__ . '/out/_dompdf_tmp'; if (!is_dir($tmp)) mkdir($tmp, 0755, true);
$options->setTempDir($tmp); $options->setFontDir($tmp); $options->setFontCache($tmp);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

file_put_contents(out_path('certificate_dompdf.pdf'), $dompdf->output());
echo "OK  →  samples/reporting/out/certificate_dompdf.pdf\n";
