<?php
/**
 * G0.3 — script proof matrix.
 *
 * Accept (EXECUTION_PLAN_v1.1.md):
 *   Every script renders correct conjuncts and correct matra placement.
 *   A single reordered ि fails the gate.
 *
 * Two independent checks:
 *
 *   1. VISUAL (authoritative). Renders a labelled proof sheet per script with
 *      the cases where correct vs broken shaping is unmistakable, then
 *      rasterises via pdftoppm for inspection.
 *
 *   2. PROGRAMMATIC TRIPWIRE (cheap, CI-able). Renders each probe twice —
 *      useOTL=0xFF and useOTL=0x00 — and asserts the emitted glyph sequences
 *      DIFFER. Identical sequences mean the shaper never engaged, which is
 *      precisely the dompdf failure mode we changed renderers to escape.
 *      This does not prove correctness; only the visual check does. It is a
 *      permanent regression guard.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g03_scripts.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

$fontDir = __DIR__ . '/fonts';
$outDir  = __DIR__ . '/out';
$tempDir = $outDir . '/_mpdftmp';
@mkdir($outDir, 0755, true);
@mkdir($tempDir, 0755, true);

$FONTDATA = [
    'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF],
    'lohittaml' => ['R' => 'Lohit-Tamil.ttf',      'useOTL' => 0xFF],
    'lohittelu' => ['R' => 'Lohit-Telugu.ttf',     'useOTL' => 0xFF],
    'lohitgujr' => ['R' => 'Lohit-Gujarati.ttf',   'useOTL' => 0xFF],
    'lohitbeng' => ['R' => 'Lohit-Bengali.ttf',    'useOTL' => 0xFF],
    'lohitknda' => ['R' => 'Lohit-Kannada.ttf',    'useOTL' => 0xFF],
    'lohitmlym' => ['R' => 'Lohit-Malayalam.ttf',  'useOTL' => 0xFF],
];

/**
 * Cases chosen so that WRONG shaping is visually obvious.
 *
 * 'matra' rows are the decisive ones: the vowel sign is typed AFTER the
 * consonant but must RENDER BEFORE (to its left). An unshaped renderer emits
 * them in logical order — the exact dompdf bug.
 */
$SCRIPTS = [
    [
        'label' => 'Devanagari (Hindi)', 'family' => 'lohitdeva',
        'cases' => [
            ['matra',    'कि',            'i-matra MUST render LEFT of base ka'],
            ['conjunct', 'क्ष',            'ka+virama+ssa -> single ksha ligature'],
            ['conjunct', 'स्थानांतरण',      'real word: stha conjunct + anusvara'],
            ['both',     'शिक्षा',          'i-matra reorder AND ksha conjunct'],
            ['zwnj',     "क्\u{200C}ष",   'ZWNJ: explicit virama, NOT ligature'],
            ['mixed',    'Class कक्षा 10', 'Latin + Devanagari on one line'],
        ],
    ],
    [
        'label' => 'Devanagari (Marathi)', 'family' => 'lohitdeva',
        'cases' => [
            ['conjunct', 'विद्यार्थ्याचे',   'multi-conjunct + reph + matra'],
            ['matra',    'शिक्षण',          'i-matra before base sha'],
        ],
    ],
    [
        'label' => 'Tamil', 'family' => 'lohittaml',
        'cases' => [
            ['matra',    'கொ',            'two-part o-matra: glyphs BOTH sides of base ka'],
            ['matra',    'கி',            'i-matra attaches above/right'],
            ['conjunct', 'க்ஷ',            'ksha'],
            ['word',     'மாற்றுச் சான்றிதழ்', 'Transfer Certificate'],
        ],
    ],
    [
        'label' => 'Telugu', 'family' => 'lohittelu',
        'cases' => [
            ['conjunct', 'క్ష',            'subscript consonant below base'],
            ['matra',    'కి',            'i-matra above'],
            ['word',     'బదిలీ ధృవీకరణ',  'Transfer certification'],
        ],
    ],
    [
        'label' => 'Gujarati', 'family' => 'lohitgujr',
        'cases' => [
            ['matra',    'કિ',            'i-matra MUST render LEFT of base ka'],
            ['conjunct', 'ક્ષ',            'ksha ligature'],
            ['word',     'સ્થાનાંતર',       'Transfer'],
        ],
    ],
    [
        'label' => 'Bengali', 'family' => 'lohitbeng',
        'cases' => [
            ['matra',    'কি',            'i-matra MUST render LEFT of base ka'],
            ['conjunct', 'ক্ষ',            'ksha ligature'],
            ['word',     'স্থানান্তর',       'Transfer'],
        ],
    ],
    [
        'label' => 'Kannada', 'family' => 'lohitknda',
        'cases' => [
            ['conjunct', 'ಕ್ಷ',            'subscript below base'],
            ['matra',    'ಕಿ',            'i-matra above/right'],
            ['word',     'ವರ್ಗಾವಣೆ',       'Transfer'],
        ],
    ],
    [
        'label' => 'Malayalam', 'family' => 'lohitmlym',
        'cases' => [
            ['conjunct', 'ക്ഷ',            'ksha ligature'],
            ['conjunct', 'ന്ത',            'nta conjunct'],
            ['matra',    'കി',            'i-matra'],
            ['word',     'സ്ഥലംമാറ്റം',      'Transfer'],
        ],
    ],
];

function makeMpdf(array $fontdata, string $fontDir, string $tempDir, int $otl): \Mpdf\Mpdf
{
    $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $df = (new \Mpdf\Config\FontVariables())->getDefaults();
    // Force the requested OTL level onto every family so the tripwire is real.
    $fd = [];
    foreach ($fontdata as $k => $v) {
        $v['useOTL'] = $otl;
        $fd[$k] = $v;
    }
    $m = new \Mpdf\Mpdf([
        'mode'     => 'utf-8',
        'format'   => 'A4',
        'tempDir'  => $tempDir,
        'fontDir'  => array_merge($dc['fontDir'], [$fontDir]),
        'fontdata' => $df['fontdata'] + $fd,
        'useOTL'   => $otl,
    ]);
    $m->SetCompression(false);
    return $m;
}

/** Extract the text-showing operands from a PDF content stream. */
function glyphSeq(string $pdf): string
{
    $ops = [];
    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $pdf, $m)) {
        $ops = $m[1];
    }
    if (preg_match_all('/\((.*?)\)\s*Tj/s', $pdf, $m2)) {
        $ops = array_merge($ops, $m2[1]);
    }
    return md5(implode('|', $ops));
}

// ---------------------------------------------------------------- visual sheet
$outFile = $outDir . '/g03_scripts.pdf';
$started = microtime(true);

try {
    $mpdf = makeMpdf($FONTDATA, $fontDir, $tempDir, 0xFF);

    $html = '<div style="font-family:dejavusans; font-size:9pt;">'
          . '<div style="font-size:13pt; font-weight:bold; margin-bottom:3mm;">'
          . 'G0.3 — Indic shaping proof (mPDF ' . \Mpdf\Mpdf::VERSION . ', Lohit, useOTL=0xFF)</div>';

    foreach ($SCRIPTS as $s) {
        $html .= '<div style="margin-top:4mm; border-top:0.3mm solid #999; padding-top:1.5mm;">'
               . '<span style="font-size:10pt; font-weight:bold;">' . htmlspecialchars($s['label'], ENT_QUOTES) . '</span>'
               . ' <span style="color:#777; font-size:7.5pt;">(' . $s['family'] . ')</span></div>';

        foreach ($s['cases'] as [$kind, $text, $note]) {
            $html .= '<table style="width:100%; margin-top:0.8mm;"><tr>'
                   . '<td style="width:14mm; font-size:7pt; color:#a00;">' . strtoupper($kind) . '</td>'
                   . '<td style="width:46mm; font-family:' . $s['family'] . '; font-size:20pt;">'
                   . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td style="font-size:7.5pt; color:#555;">' . htmlspecialchars($note, ENT_QUOTES) . '</td>'
                   . '</tr></table>';
        }
    }
    $html .= '</div>';

    $mpdf->WriteHTML($html);
    $mpdf->Output($outFile, \Mpdf\Output\Destination::FILE);
    unset($mpdf);
} catch (\Throwable $e) {
    fwrite(STDERR, 'FATAL (visual sheet): ' . $e->getMessage() . "\n");
    exit(1);
}

// ------------------------------------------------------- programmatic tripwire
echo "=== G0.3 — script proof matrix ===\n";
echo 'mpdf   : ' . \Mpdf\Mpdf::VERSION . "   fonts: Lohit\n";
echo 'sheet  : ' . $outFile . ' (' . filesize($outFile) . " bytes)\n";
echo 'ms     : ' . (int) round((microtime(true) - $started) * 1000) . "\n";
echo "\nshaper tripwire (OTL on vs off — sequences MUST differ):\n";

$tripFail = [];
foreach ($SCRIPTS as $s) {
    // one representative complex probe per script
    $probe = null;
    foreach ($s['cases'] as [$kind, $text, $_]) {
        if ($kind === 'matra' || $kind === 'conjunct' || $kind === 'both') {
            $probe = $text;
            break;
        }
    }
    if ($probe === null) {
        continue;
    }

    $seqs = [];
    foreach ([0xFF, 0x00] as $otl) {
        $d = $tempDir . '/otl' . $otl;
        @mkdir($d, 0755, true);
        try {
            $m = makeMpdf($FONTDATA, $fontDir, $d, $otl);
            $m->WriteHTML('<div style="font-family:' . $s['family'] . '; font-size:20pt;">'
                . htmlspecialchars($probe, ENT_QUOTES, 'UTF-8') . '</div>');
            $seqs[$otl] = glyphSeq($m->Output('', \Mpdf\Output\Destination::STRING_RETURN));
            unset($m);
        } catch (\Throwable $e) {
            $seqs[$otl] = 'ERR:' . substr($e->getMessage(), 0, 40);
        }
    }

    $differ = ($seqs[0xFF] ?? 'a') !== ($seqs[0x00] ?? 'b');
    printf("  %-22s %-8s %s\n", $s['label'], $probe, $differ ? 'SHAPED' : 'NOT SHAPED  <-- FAIL');
    if (!$differ) {
        $tripFail[] = $s['label'];
    }
}

echo "\ntripwire: " . ($tripFail === [] ? 'PASS — shaper engaged for every script'
                                        : 'FAIL — ' . implode(', ', $tripFail)) . "\n";
echo "\nNEXT: rasterise and inspect visually — the tripwire proves the shaper ran,\n";
echo "      not that it ran correctly. Visual check is authoritative.\n";
exit($tripFail === [] ? 0 : 1);
