<?php
/**
 * G0.5b — line-break divergence sweep.
 *
 * G0.5 showed mPDF and the browser agreeing to <0.04mm at one width. That is
 * not the interesting question. Both engines multiply line-count by an
 * explicitly specified line-height, so agreement is guaranteed WHENEVER they
 * choose the same number of lines.
 *
 * The real risk is therefore QUANTISED, not continuous: the engines either
 * agree exactly, or they disagree by a whole line (~5.43mm at 11pt/1.4).
 * A "+/-1.5mm tolerance" cannot express that.
 *
 * This sweeps container width across the range a designer would actually use,
 * hunting for widths where the two engines break lines differently.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g05b_sweep.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

$fontDir = __DIR__ . '/fonts';
$outDir  = __DIR__ . '/out';
$tempDir = $outDir . '/_mpdftmp/sweep';
@mkdir($tempDir, 0755, true);

const FONT_PT     = 11.0;
const LINE_HEIGHT = 1.4;
/** One line box in mm: pt * line-height / 72 * 25.4 */
const LINE_MM     = FONT_PT * LINE_HEIGHT / 72 * 25.4;

$TEXTS = [
    'latin'   => ['dejavusans', 'The pupil named herein has been a bona fide student of this institution and is hereby released from its rolls with effect from the date stated below.'],
    'deva'    => ['lohitdeva',  'इस विद्यालय का छात्र रहा है और उसे नीचे दी गई तिथि से विद्यालय से मुक्त किया जाता है।'],
    'tamil'   => ['lohittaml',  'இந்த நிறுவனத்தின் உண்மையான மாணவர் ஆவார் மற்றும் கீழே குறிப்பிடப்பட்ட தேதியிலிருந்து விடுவிக்கப்படுகிறார்.'],
    'bengali' => ['lohitbeng',  'এই প্রতিষ্ঠানের একজন প্রকৃত ছাত্র ছিলেন এবং নিচে উল্লিখিত তারিখ থেকে অব্যাহতি দেওয়া হল।'],
];

$FONTDATA = [
    'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF],
    'lohittaml' => ['R' => 'Lohit-Tamil.ttf',      'useOTL' => 0xFF],
    'lohitbeng' => ['R' => 'Lohit-Bengali.ttf',    'useOTL' => 0xFF],
];

/** Widths a real certificate body would use. */
$WIDTHS = [];
for ($w = 60; $w <= 170; $w += 5) {
    $WIDTHS[] = $w;
}

$block = static function (string $fam, string $text, int $w): string {
    return '<div style="font-family:' . $fam . '; font-size:' . FONT_PT . 'pt; width:' . $w
         . 'mm; line-height:' . LINE_HEIGHT . '; margin:0; padding:0;">'
         . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
};

// --------------------------------------------------------------- mPDF sweep
// One document, sequential writes, y-delta per block: far cheaper than an
// instance per measurement.
$mpdfLines = [];
try {
    $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $df = (new \Mpdf\Config\FontVariables())->getDefaults();
    $m = new \Mpdf\Mpdf([
        'mode'     => 'utf-8',
        'format'   => [200, 20000],       // tall enough to never paginate
        'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
        'tempDir'  => $tempDir,
        'fontDir'  => array_merge($dc['fontDir'], [$fontDir]),
        'fontdata' => $df['fontdata'] + $FONTDATA,
        'useOTL'   => 0xFF,
    ]);
    foreach ($TEXTS as $script => [$fam, $text]) {
        foreach ($WIDTHS as $w) {
            $y0 = $m->y;
            $m->WriteHTML($block($fam, $text, $w));
            $h  = $m->y - $y0;
            $mpdfLines["{$script}@{$w}"] = (int) round($h / LINE_MM);
        }
    }
    unset($m);
} catch (\Throwable $e) {
    fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
    exit(1);
}

file_put_contents($outDir . '/g05b_mpdf.json', json_encode($mpdfLines, JSON_PRETTY_PRINT));

// ---------------------------------------------------------- browser half HTML
$faces = '';
foreach ($FONTDATA as $fam => $fd) {
    $faces .= "@font-face{font-family:'$fam';src:url('../fonts/{$fd['R']}') format('truetype');"
            . "font-weight:400;font-display:block;}\n";
}
$faces .= "@font-face{font-family:'dejavusans';"
        . "src:url('../vendor/mpdf/mpdf/ttfonts/DejaVuSans.ttf') format('truetype');"
        . "font-weight:400;font-display:block;}\n";

$probes = '';
foreach ($TEXTS as $script => [$fam, $text]) {
    foreach ($WIDTHS as $w) {
        $probes .= '<div class="probe" data-id="' . $script . '@' . $w . '">'
                 . $block($fam, $text, $w) . "</div>\n";
    }
}

$lineMm = LINE_MM;
$html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>G0.5b line-break sweep — browser half</title>
<style>
{$faces}
html,body{margin:0;padding:0;background:#fff;}
.probe{margin:0;padding:0;}
</style>
{$probes}
<script>
const PX_PER_MM = 96 / 25.4, LINE_MM = {$lineMm};
async function run(){
  try { await document.fonts.ready; } catch(e) {}
  const res = {};
  document.querySelectorAll('.probe').forEach(p => {
    const mm = p.firstElementChild.getBoundingClientRect().height / PX_PER_MM;
    res[p.dataset.id] = Math.round(mm / LINE_MM);
  });
  window.__G05B__ = res;
}
run();
</script>
HTML;

file_put_contents($outDir . '/g05b_sweep.html', $html);

echo "=== G0.5b — line-break divergence sweep (mPDF half) ===\n";
echo 'line box: ' . number_format(LINE_MM, 4) . "mm   widths: " . count($WIDTHS)
   . "   scripts: " . count($TEXTS) . "   probes: " . count($mpdfLines) . "\n";
foreach ($TEXTS as $script => $_) {
    $row = [];
    foreach ($WIDTHS as $w) {
        $row[] = $mpdfLines["{$script}@{$w}"];
    }
    printf("  %-8s %s\n", $script, implode(' ', $row));
}
echo "\nwrote out/g05b_mpdf.json + out/g05b_sweep.html\n";
