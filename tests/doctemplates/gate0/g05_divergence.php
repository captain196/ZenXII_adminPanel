<?php
/**
 * G0.5 — preview-vs-proof divergence harness.
 *
 * Accept (EXECUTION_PLAN_v1.1.md):
 *   A published tolerance number exists (target <=1.5mm per block,
 *   <=4mm cumulative per chain). A number, not a hope.
 *
 * WHY THIS EXISTS: architecture §0.2. Once anchor chains became flow
 * containers, block heights are decided by whichever engine renders. The
 * browser preview uses the browser's shaper and metrics; the PDF uses mPDF's.
 * They will differ, and in a chain the delta accumulates downward.
 *
 * This script does the mPDF half and emits an HTML file carrying the SAME
 * blocks with @font-face parity (R1) plus a measuring script. Open that file
 * in a browser to collect the other half.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g05_divergence.php
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

const WIDTH_MM = 160.0;
const FONT_PT  = 11.0;

$EN = 'The pupil named herein has been a bona fide student of this institution and is hereby '
    . 'released from its rolls with effect from the date stated below. ';
$HI = 'इस विद्यालय का छात्र रहा है और उसे नीचे दी गई तिथि से विद्यालय से मुक्त किया जाता है। ';
$TA = 'இந்த நிறுவனத்தின் உண்மையான மாணவர் ஆவார் மற்றும் கீழே குறிப்பிடப்பட்ட தேதியிலிருந்து விடுவிக்கப்படுகிறார். ';
$BN = 'এই প্রতিষ্ঠানের একজন প্রকৃত ছাত্র ছিলেন এবং নিচে উল্লিখিত তারিখ থেকে অব্যাহতি দেওয়া হল। ';

/**
 * Blocks: id => [css font-family, text, repetitions].
 * Short and long variants of each so we see whether divergence is constant
 * (a metrics offset) or proportional (a line-breaking difference).
 */
$BLOCKS = [
    'latin_short'  => ['dejavusans', $EN, 1],
    'latin_long'   => ['dejavusans', $EN, 6],
    'deva_short'   => ['lohitdeva',  $HI, 1],
    'deva_long'    => ['lohitdeva',  $HI, 6],
    'tamil_short'  => ['lohittaml',  $TA, 1],
    'tamil_long'   => ['lohittaml',  $TA, 4],
    'bengali_short' => ['lohitbeng', $BN, 1],
    'bengali_long' => ['lohitbeng',  $BN, 4],
    'mixed'        => ['dejavusans', 'Class 10 — ', 1],
];

/** CSS family name -> [mPDF fontdata key, ttf file or null for bundled]. */
$FAMILIES = [
    'dejavusans' => [null,        null],                      // mPDF bundled
    'lohitdeva'  => ['lohitdeva', 'Lohit-Devanagari.ttf'],
    'lohittaml'  => ['lohittaml', 'Lohit-Tamil.ttf'],
    'lohitbeng'  => ['lohitbeng', 'Lohit-Bengali.ttf'],
];

$fontdataExtra = [];
foreach ($FAMILIES as $css => [$key, $file]) {
    if ($key !== null) {
        $fontdataExtra[$key] = ['R' => $file, 'useOTL' => 0xFF];
    }
}

/** Render a block in flow on an un-paginatable scratch doc; return height in mm. */
function measureMm(string $html, array $fontdataExtra, string $fontDir, string $tempDir): float
{
    $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $df = (new \Mpdf\Config\FontVariables())->getDefaults();
    $m = new \Mpdf\Mpdf([
        'mode'          => 'utf-8',
        'format'        => [WIDTH_MM + 20, 3000],   // very tall: never paginates
        // Zero margins: \$m->y starts at 0 before mPDF positions the cursor,
        // so a non-zero top margin is added into every measurement (a constant
        // +margin_top bias). Verified against the browser: with margins the
        // delta was exactly +10.000mm in all 9 blocks.
        'margin_left'   => 0, 'margin_right' => 0,
        'margin_top'    => 0, 'margin_bottom' => 0,
        'tempDir'       => $tempDir,
        'fontDir'       => array_merge($dc['fontDir'], [$fontDir]),
        'fontdata'      => $df['fontdata'] + $fontdataExtra,
        'useOTL'        => 0xFF,
    ]);
    $y0 = $m->y;
    $m->WriteHTML($html);
    $h = $m->y - $y0;
    unset($m);
    return round($h, 3);
}

$blockHtml = static function (array $spec) use (&$BLOCKS): string {
    [$fam, $text, $reps] = $spec;
    return '<div style="font-family:' . $fam . '; font-size:' . FONT_PT . 'pt; '
         . 'width:' . WIDTH_MM . 'mm; line-height:1.4; margin:0; padding:0;">'
         . htmlspecialchars(str_repeat($text, $reps), ENT_QUOTES, 'UTF-8')
         . '</div>';
};

$mpdfHeights = [];
foreach ($BLOCKS as $id => $spec) {
    try {
        $mpdfHeights[$id] = measureMm($blockHtml($spec), $fontdataExtra, $fontDir, $tempDir);
    } catch (\Throwable $e) {
        $mpdfHeights[$id] = null;
        fwrite(STDERR, "measure failed [$id]: " . $e->getMessage() . "\n");
    }
}

file_put_contents($outDir . '/g05_mpdf.json', json_encode($mpdfHeights, JSON_PRETTY_PRINT));

// ---------------------------------------------------------------- preview HTML
// R1 font parity: the browser MUST use the exact same TTFs. No system fallback,
// font-display:block so a failed load shows nothing rather than silently
// reflowing in a substitute face.
$faces = '';
foreach ($FAMILIES as $css => [$key, $file]) {
    if ($file === null) {
        continue;
    }
    $faces .= "@font-face{font-family:'$css';src:url('../fonts/$file') format('truetype');"
            . "font-weight:400;font-display:block;}\n";
}
// DejaVu comes from mPDF's own bundle so the browser uses the identical file.
$faces .= "@font-face{font-family:'dejavusans';"
        . "src:url('../vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf') format('truetype');"
        . "font-weight:400;font-display:block;}\n";

$blocksHtml = '';
foreach ($BLOCKS as $id => $spec) {
    $blocksHtml .= '<div class="probe" data-id="' . $id . '">' . $blockHtml($spec) . "</div>\n";
}

$html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>G0.5 divergence — browser half</title>
<style>
{$faces}
html,body{margin:0;padding:0;}
body{background:#fff;}
.probe{margin:0;padding:0;}
#out{font:12px ui-monospace,monospace;white-space:pre;padding:8px;background:#111;color:#0f0;}
</style>
{$blocksHtml}
<div id="out">measuring…</div>
<script>
// px -> mm at CSS 96dpi
const PX_PER_MM = 96 / 25.4;
async function run(){
  try { await document.fonts.ready; } catch(e) {}
  const res = {};
  document.querySelectorAll('.probe').forEach(p => {
    const inner = p.firstElementChild;
    res[p.dataset.id] = +(inner.getBoundingClientRect().height / PX_PER_MM).toFixed(3);
  });
  window.__G05__ = res;
  document.getElementById('out').textContent = JSON.stringify(res, null, 2);
}
run();
</script>
HTML;

file_put_contents($outDir . '/g05_preview.html', $html);

echo "=== G0.5 — divergence harness (mPDF half) ===\n";
echo 'mpdf: ' . \Mpdf\Mpdf::VERSION . "   width: " . WIDTH_MM . "mm   size: " . FONT_PT . "pt\n\n";
printf("%-16s %s\n", 'BLOCK', 'mPDF height (mm)');
echo str_repeat('-', 40) . "\n";
foreach ($mpdfHeights as $id => $h) {
    printf("%-16s %s\n", $id, $h === null ? 'ERR' : number_format($h, 3));
}
echo "\nwrote: out/g05_mpdf.json\n";
echo "wrote: out/g05_preview.html   <- open in a browser to collect the other half\n";
