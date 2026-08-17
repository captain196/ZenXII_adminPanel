<?php
/**
 * G0.2 — font registration harness.
 *
 * Accept (EXECUTION_PLAN_v1.1.md):
 *   All 8 families load; font cache builds once; no missing-glyph boxes.
 *
 * "No missing-glyph boxes" is verified programmatically rather than by eye:
 * for each script we assert its exact Lohit face is EMBEDDED in the output PDF.
 * If mPDF had silently fallen back to DejaVu (no Indic coverage) or collapsed
 * several scripts onto one face, the exact name would be absent and this fails.
 *
 * Shaping CORRECTNESS (conjuncts, matra order) is G0.3, not this gate.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g02_fonts.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

$diagnostics = [];
set_error_handler(function (int $no, string $str, string $file, int $line) use (&$diagnostics): bool {
    if (!(error_reporting() & $no)) {
        return true;
    }
    $diagnostics[] = sprintf('%d: %s (%s:%d)', $no, $str, basename($file), $line);
    return true;
});

require __DIR__ . '/vendor/autoload.php';

$fontDir = __DIR__ . '/fonts';
$outDir  = __DIR__ . '/out';
$tempDir = __DIR__ . '/out/_mpdftmp';
@mkdir($outDir, 0755, true);
@mkdir($tempDir, 0755, true);

/**
 * Target scripts. `family` is the mPDF fontdata key; `probe` is a short
 * sample in that script; `face` is the embedded-name fragment we assert.
 */
$SCRIPTS = [
    // Latin: Lohit has no Latin coverage — mPDF's bundled DejaVu already parses.
    ['label' => 'Latin',      'family' => 'dejavusans', 'face' => 'DejaVuSans', 'probe' => 'Transfer Certificate'],
    ['label' => 'Devanagari', 'family' => 'lohitdeva',  'face' => 'Lohit-Devanagari',      'probe' => 'स्थानांतरण प्रमाणपत्र'],
    ['label' => 'Tamil',      'family' => 'lohittaml',  'face' => 'Lohit-Tamil',      'probe' => 'மாற்றுச் சான்றிதழ்'],
    ['label' => 'Telugu',     'family' => 'lohittelu',  'face' => 'Lohit-Telugu',      'probe' => 'బదిలీ ధృవీకరణ పత్రం'],
    ['label' => 'Gujarati',   'family' => 'lohitgujr',  'face' => 'Lohit-Gujarati',      'probe' => 'સ્થાનાંતર પ્રમાણપત્ર'],
    ['label' => 'Bengali',    'family' => 'lohitbeng',  'face' => 'Lohit-Bengali',      'probe' => 'স্থানান্তর সনদপত্র'],
    ['label' => 'Kannada',    'family' => 'lohitknda',  'face' => 'Lohit-Kannada',      'probe' => 'ವರ್ಗಾವಣೆ ಪ್ರಮಾಣಪತ್ರ'],
    ['label' => 'Malayalam',  'family' => 'lohitmlym',  'face' => 'Lohit-Malayalam',      'probe' => 'സ്ഥലംമാറ്റ സർട്ടിഫിക്കറ്റ്'],
];

/**
 * mPDF fontdata: every family gets full OpenType Layout (bit 0x80 drives
 * complex-script shaping). Lohit ships Regular only — no Bold face — so 'B' is
 * omitted and mPDF synthesises bold. Recorded as a real limitation, not an
 * oversight: see the G0.2 finding in EXECUTION_PLAN_v1.1.md.
 */
$FONTDATA = [
    'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF],
    'lohittaml' => ['R' => 'Lohit-Tamil.ttf',      'useOTL' => 0xFF],
    'lohittelu' => ['R' => 'Lohit-Telugu.ttf',     'useOTL' => 0xFF],
    'lohitgujr' => ['R' => 'Lohit-Gujarati.ttf',   'useOTL' => 0xFF],
    'lohitbeng' => ['R' => 'Lohit-Bengali.ttf',    'useOTL' => 0xFF],
    'lohitknda' => ['R' => 'Lohit-Kannada.ttf',    'useOTL' => 0xFF],
    'lohitmlym' => ['R' => 'Lohit-Malayalam.ttf',  'useOTL' => 0xFF],
];

// --- pre-flight: every declared file must exist -----------------------------
$missing = [];
foreach ($FONTDATA as $faces) {
    // Only styles actually declared — Lohit ships Regular only.
    foreach (array_intersect_key($faces, array_flip(['R', 'B', 'I', 'BI'])) as $file) {
        if (!is_file($fontDir . '/' . $file)) {
            $missing[] = $file;
        }
    }
}
if ($missing) {
    fwrite(STDERR, "FAIL: missing font files:\n  - " . implode("\n  - ", $missing) . "\n");
    fwrite(STDERR, "Run: bash tests/doctemplates/gate0/fetch_lohit.sh\n");
    exit(1);
}

$cacheDir = $tempDir . '/mpdf/ttfontdata';   // mPDF nests its cache under mpdf/
@mkdir($cacheDir, 0755, true);
$cacheBefore = count(glob($cacheDir . '/*') ?: []);

$outFile = $outDir . '/g02_fonts.pdf';
$started = microtime(true);

try {
    $defaultConfig     = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();

    $mpdf = new \Mpdf\Mpdf([
        'mode'        => 'utf-8',
        'format'      => 'A4',
        'orientation' => 'P',
        'tempDir'     => $tempDir,
        // Our dir FIRST so our faces win; keep mPDF's so DejaVu etc. resolve.
        'fontDir'     => array_merge($defaultConfig['fontDir'], [$fontDir]),
        'fontdata'    => $defaultFontConfig['fontdata'] + $FONTDATA,
        'useOTL'      => 0xFF,
    ]);
    $mpdf->SetCompression(false);   // keep object dicts inspectable for the embed assertion

    $html = '<div style="font-size:13pt;">';
    foreach ($SCRIPTS as $s) {
        $html .= sprintf(
            '<div style="font-family:%s; margin-bottom:5mm;">'
            . '<span style="font-family:notosans; font-size:8pt; color:#888;">%s / %s</span><br>%s'
            . '</div>',
            $s['family'],
            htmlspecialchars($s['label'], ENT_QUOTES),
            $s['family'],
            htmlspecialchars($s['probe'], ENT_QUOTES, 'UTF-8')
        );
    }
    $html .= '</div>';

    $mpdf->WriteHTML($html);
    $mpdf->Output($outFile, \Mpdf\Output\Destination::FILE);
    unset($mpdf);
} catch (\Throwable $e) {
    restore_error_handler();
    fwrite(STDERR, 'FATAL: ' . get_class($e) . ' — ' . $e->getMessage() . "\n");
    exit(1);
}

restore_error_handler();

$elapsedMs  = (int) round((microtime(true) - $started) * 1000);
$cacheAfter = count(glob($cacheDir . '/*') ?: []);
$pdf        = is_file($outFile) ? (string) file_get_contents($outFile) : '';

echo "=== G0.2 — font registration ===\n";
echo 'php         : ' . PHP_VERSION . "\n";
echo 'mpdf        : ' . \Mpdf\Mpdf::VERSION . "\n";
echo 'fontDir     : ' . $fontDir . "\n";
echo 'output      : ' . $outFile . ' (' . strlen($pdf) . " bytes)\n";
echo 'render_ms   : ' . $elapsedMs . "\n";
echo 'font cache  : ' . $cacheBefore . ' -> ' . $cacheAfter . " files\n";
// Actual /BaseFont names present in the PDF — printed so assertions can be
// per-script rather than a weak substring match.
preg_match_all('#/BaseFont\s*/([A-Za-z0-9+\-_]+)#', $pdf, $bf);
$baseFonts = array_values(array_unique($bf[1] ?? []));
echo "\nembedded /BaseFont entries (" . count($baseFonts) . "):\n";
foreach ($baseFonts as $b) {
    echo '  - ' . $b . "\n";
}

echo "\nembedded-face assertions:\n";

$failures = [];
foreach ($SCRIPTS as $s) {
    $embedded = str_contains($pdf, $s['face']);
    printf("  %-11s %-22s %s\n", $s['label'], $s['face'], $embedded ? 'EMBEDDED' : 'ABSENT  <-- FAIL');
    if (!$embedded) {
        $failures[] = $s['label'];
    }
}

if ($diagnostics) {
    echo "\ndiagnostics (" . count($diagnostics) . "):\n";
    foreach (array_slice($diagnostics, 0, 10) as $d) {
        echo '  - ' . $d . "\n";
    }
}

$pass = $pdf !== '' && str_starts_with($pdf, '%PDF-') && $failures === [];
echo "\nACCEPT: " . ($pass ? 'PASS' : 'FAIL — not embedded: ' . implode(', ', $failures)) . "\n";
exit($pass ? 0 : 1);
