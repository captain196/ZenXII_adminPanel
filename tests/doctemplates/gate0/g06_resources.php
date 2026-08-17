<?php
/**
 * G0.6 — resource spike (demand side).
 *
 * Accept (EXECUTION_PLAN_v1.1.md):
 *   Peak memory and p95 time recorded and within budget, OR §10 limits revised.
 *
 * SCOPE NOTE: this measures DEMAND (peak MB, wall ms) which is essentially
 * hardware-independent for PHP. It cannot measure SUPPLY (the server's
 * memory_limit, RAM, vCPUs) — those three numbers close the gate and need no
 * live session. CPU-bound timings here are from dev hardware and will be
 * optimistic versus a shared-vCPU Lightsail instance.
 *
 * Worst case modelled: A4 certificate, all 8 scripts (=8 font subsets, the
 * dominant memory driver), a long flow region spanning pages, an embedded
 * raster image, and a table.
 *
 * Also checks for accumulation across repeated renders — batch issuance would
 * amplify any per-render leak.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g06_resources.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

$fontDir = __DIR__ . '/fonts';
$outDir  = __DIR__ . '/out';
$tempDir = $outDir . '/_mpdftmp/g06';
@mkdir($tempDir, 0755, true);

const REPS = 5;

$FONTDATA = [
    'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF],
    'lohittaml' => ['R' => 'Lohit-Tamil.ttf',      'useOTL' => 0xFF],
    'lohittelu' => ['R' => 'Lohit-Telugu.ttf',     'useOTL' => 0xFF],
    'lohitgujr' => ['R' => 'Lohit-Gujarati.ttf',   'useOTL' => 0xFF],
    'lohitbeng' => ['R' => 'Lohit-Bengali.ttf',    'useOTL' => 0xFF],
    'lohitknda' => ['R' => 'Lohit-Kannada.ttf',    'useOTL' => 0xFF],
    'lohitmlym' => ['R' => 'Lohit-Malayalam.ttf',  'useOTL' => 0xFF],
];

$SAMPLES = [
    'dejavusans' => 'The pupil named herein has been a bona fide student of this institution. ',
    'lohitdeva'  => 'इस विद्यालय का छात्र रहा है और उसे मुक्त किया जाता है। ',
    'lohittaml'  => 'இந்த நிறுவனத்தின் உண்மையான மாணவர் ஆவார். ',
    'lohittelu'  => 'ఈ సంస్థ యొక్క నిజమైన విద్యార్థి. ',
    'lohitgujr'  => 'આ સંસ્થાના સાચા વિદ્યાર્થી છે. ',
    'lohitbeng'  => 'এই প্রতিষ্ঠানের একজন প্রকৃত ছাত্র ছিলেন। ',
    'lohitknda'  => 'ಈ ಸಂಸ್ಥೆಯ ನಿಜವಾದ ವಿದ್ಯಾರ್ಥಿ. ',
    'lohitmlym'  => 'ഈ സ്ഥാപനത്തിലെ യഥാർത്ഥ വിദ്യാർത്ഥി. ',
];

/** A small PNG generated in-process: avoids shipping a binary fixture. */
function makePng(string $path): void
{
    if (is_file($path)) {
        return;
    }
    $im = imagecreatetruecolor(600, 200);
    $bg = imagecolorallocate($im, 245, 245, 250);
    $fg = imagecolorallocate($im, 30, 60, 120);
    imagefilledrectangle($im, 0, 0, 599, 199, $bg);
    for ($i = 0; $i < 200; $i += 8) {
        imageline($im, 0, $i, 599, $i, $fg);
    }
    imagepng($im, $path);
    // imagedestroy() is a no-op since PHP 8.0 and deprecated in 8.5
}

$png = $tempDir . '/logo.png';
if (function_exists('imagecreatetruecolor')) {
    makePng($png);
} else {
    $png = null;
}

function buildHtml(array $samples, ?string $png): string
{
    $h = '<div style="font-family:dejavusans; font-size:11pt; line-height:1.4;">';
    if ($png !== null) {
        $h .= '<img src="' . $png . '" style="width:60mm;">';
    }
    $h .= '<div style="font-size:16pt; line-height:1.4; font-weight:bold;">TRANSFER CERTIFICATE</div>';

    // Absolute chrome block (fixed) — mirrors the real template shape.
    $h .= '<div style="position:absolute; left:120mm; top:8mm; width:60mm; font-size:8pt; line-height:1.4;">'
        . 'Book No. ____  Sl. No. ____</div>';

    // Table: the Annexure-style field grid.
    $h .= '<table border="1" style="width:100%; border-collapse:collapse; margin-top:4mm;">';
    for ($i = 1; $i <= 22; $i++) {
        $h .= '<tr><td style="width:35%; padding:1mm; font-size:9pt; line-height:1.4;">Field ' . $i
            . '</td><td style="padding:1mm; font-size:9pt; line-height:1.4;">Value ' . $i . '</td></tr>';
    }
    $h .= '</table>';

    // Long multi-script flow region — forces all 8 subsets AND pagination.
    foreach ($samples as $fam => $txt) {
        $h .= '<div style="font-family:' . $fam . '; font-size:11pt; line-height:1.4; margin-top:3mm;">'
            . htmlspecialchars(str_repeat($txt, 12), ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $h . '</div>';
}

$rows = [];
$baseline = memory_get_usage(true);

for ($r = 1; $r <= REPS; $r++) {
    $t0 = microtime(true);
    $m0 = memory_get_peak_usage(true);

    $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $df = (new \Mpdf\Config\FontVariables())->getDefaults();
    $m = new \Mpdf\Mpdf([
        'mode'        => 'utf-8',
        'format'      => 'A4',
        'margin_top'  => 28, 'margin_bottom' => 20,
        'tempDir'     => $tempDir,
        'fontDir'     => array_merge($dc['fontDir'], [$fontDir]),
        'fontdata'    => $df['fontdata'] + $FONTDATA,
        'useOTL'      => 0xFF,
    ]);
    $m->SetHTMLHeader('<div style="border-bottom:0.3mm solid #333; font-size:9pt; line-height:1.4;">ZENXII MODEL SCHOOL</div>');
    $m->SetHTMLFooter('<div style="font-size:8pt; line-height:1.4;">Page {PAGENO} of {nbpg}</div>');
    $m->WriteHTML(buildHtml($SAMPLES, $png));
    $pdf   = $m->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    $pages = $m->page;
    unset($m);

    $rows[] = [
        'rep'    => $r,
        'ms'     => (int) round((microtime(true) - $t0) * 1000),
        'peakMb' => round(memory_get_peak_usage(true) / 1048576, 1),
        'curMb'  => round(memory_get_usage(true) / 1048576, 1),
        'pages'  => $pages,
        'kb'     => (int) round(strlen($pdf) / 1024),
    ];
    gc_collect_cycles();
}

echo "=== G0.6 — resource spike (DEMAND side) ===\n";
echo 'php ' . PHP_VERSION . '  mpdf ' . \Mpdf\Mpdf::VERSION . "\n";
echo 'local memory_limit: ' . ini_get('memory_limit')
   . '   max_execution_time: ' . ini_get('max_execution_time') . "s\n";
echo 'worst case: A4, 8 scripts (8 font subsets), 22-row table, image, paginated flow region'
   . ($png === null ? ' [image SKIPPED — no GD]' : '') . "\n\n";

printf("%-5s %8s %10s %10s %7s %8s\n", 'REP', 'ms', 'peak MB', 'current MB', 'pages', 'PDF KB');
echo str_repeat('-', 56) . "\n";
foreach ($rows as $x) {
    printf("%-5d %8d %10s %10s %7d %8d\n", $x['rep'], $x['ms'], $x['peakMb'], $x['curMb'], $x['pages'], $x['kb']);
}

$times = array_column($rows, 'ms');
sort($times);
$p95 = $times[(int) floor(0.95 * (count($times) - 1))];
$peak = max(array_column($rows, 'peakMb'));
$drift = round($rows[count($rows) - 1]['curMb'] - $rows[0]['curMb'], 1);

echo "\np95 wall time : {$p95} ms   (dev hardware — optimistic vs shared vCPU)\n";
echo "peak memory   : {$peak} MB\n";
echo "memory drift  : {$drift} MB across " . REPS . " renders "
   . ($drift <= 1.0 ? '(no accumulation)' : '(<-- ACCUMULATING, matters for batch)') . "\n";
echo "\nSUPPLY still required to close the gate: server memory_limit, RAM, vCPUs.\n";
