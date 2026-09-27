<?php
/**
 * OPTION 6 (NEW) — Exam Admit Card / Hall Ticket  (Exam/Examination module)
 * STATUS: RUNS TODAY (dompdf + chillerlan/php-qrcode, both already installed)
 *
 *   php samples/reporting/06_dompdf_admit_card.php   →   out/admit_card_dompdf.pdf
 *
 * GAP CLOSED: no admit-card/hall-ticket generation exists anywhere in Examination.php /
 * Result.php today. Uses a REAL scannable QR (chillerlan, same library + technique as
 * application/helpers/qr_token_helper.php::qr_svg_data_uri()) encoding a verification
 * string — in production this would encode a signed verification URL/token, exactly like
 * the existing admission-receipt QR.
 */
require __DIR__ . '/_bootstrap.php';

$verifyPayload = 'ADMIT|' . $ADMIT_CARD['student_id'] . '|' . $ADMIT_CARD['exam'];
$qrDataUri = real_qr_data_uri($verifyPayload);

$html = render_template(__DIR__ . '/templates/admit_card.php', compact('SCHOOL', 'ADMIT_CARD', 'qrDataUri'));

$options = new \Dompdf\Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$tmp = __DIR__ . '/out/_dompdf_tmp'; if (!is_dir($tmp)) mkdir($tmp, 0755, true);
$options->setTempDir($tmp); $options->setFontDir($tmp); $options->setFontCache($tmp);

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

file_put_contents(out_path('admit_card_dompdf.pdf'), $dompdf->output());
echo "OK  →  samples/reporting/out/admit_card_dompdf.pdf\n";
