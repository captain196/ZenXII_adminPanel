<?php
/**
 * G0.1 — mPDF smoke harness.
 *
 * Gate 0 task from EXECUTION_PLAN_v1.1.md.
 * Accept: PDF produced, no fatal, no warning spew, mPDF version recorded.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g01_smoke.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // captured below instead, so stdout stays clean

$diagnostics = [];
set_error_handler(function (int $no, string $str, string $file, int $line) use (&$diagnostics): bool {
    // Respect the @ operator: PHP still invokes the handler, so check the
    // active error_reporting mask ourselves or suppressed calls leak through
    // and mask genuine warnings in the later gates.
    if (!(error_reporting() & $no)) {
        return true;
    }
    $diagnostics[] = sprintf('%s: %s (%s:%d)', _errLabel($no), $str, basename($file), $line);
    return true;
});

function _errLabel(int $no): string
{
    return [
        E_WARNING     => 'WARNING',
        E_NOTICE      => 'NOTICE',
        E_DEPRECATED  => 'DEPRECATED',
        E_USER_ERROR  => 'USER_ERROR',
    ][$no] ?? 'ERR(' . $no . ')';
}

require __DIR__ . '/vendor/autoload.php';

$outDir  = __DIR__ . '/out';
$tempDir = __DIR__ . '/out/_mpdftmp';
@mkdir($outDir, 0755, true);
@mkdir($tempDir, 0755, true);

$outFile = $outDir . '/g01_smoke.pdf';
@unlink($outFile);

$started = microtime(true);
$peakBefore = memory_get_peak_usage(true);

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'        => 'utf-8',
        'format'      => 'A4',
        'orientation' => 'P',
        'margin_left' => 15,
        'margin_right'=> 15,
        'margin_top'  => 15,
        'margin_bottom' => 15,
        'tempDir'     => $tempDir,
    ]);

    // Deliberately exercises the constructs the serializer will emit (§5.4):
    // an absolutely-positioned block, and block flow inside it.
    $html = <<<'HTML'
<div style="position:absolute; left:0mm; top:0mm; width:180mm;">
  <div style="font-size:18pt; font-weight:bold;">ZenXii — G0.1 smoke</div>
  <div style="margin-top:4mm; font-size:11pt;">
    Absolute container with block-flow children. This is the construct
    §0 of the architecture depends on; G0.4 proves it properly.
  </div>
  <div style="margin-top:4mm; font-size:11pt;">Second child, flowed.</div>
</div>
HTML;

    $mpdf->WriteHTML($html);
    $mpdf->Output($outFile, \Mpdf\Output\Destination::FILE);

    $pages = $mpdf->page;
    unset($mpdf);
} catch (\Throwable $e) {
    restore_error_handler();
    fwrite(STDERR, "FATAL: " . get_class($e) . ' — ' . $e->getMessage() . "\n");
    exit(1);
}

restore_error_handler();

$elapsedMs = (int) round((microtime(true) - $started) * 1000);
$peakMb    = round((memory_get_peak_usage(true) - $peakBefore) / 1048576, 1);
$bytes     = is_file($outFile) ? filesize($outFile) : 0;

// mPDF ships its version as a class constant.
$version = defined('\Mpdf\Mpdf::VERSION') ? \Mpdf\Mpdf::VERSION : (\Mpdf\Mpdf::VERSION ?? 'unknown');

echo "=== G0.1 — mPDF smoke ===\n";
echo "php           : " . PHP_VERSION . "\n";
echo "mpdf          : " . $version . "\n";
echo "output        : " . $outFile . "\n";
echo "bytes         : " . $bytes . "\n";
echo "pages         : " . ($pages ?? '?') . "\n";
echo "render_ms     : " . $elapsedMs . "\n";
echo "peak_mem_mb   : " . $peakMb . "\n";
echo "diagnostics   : " . count($diagnostics) . "\n";
foreach (array_slice($diagnostics, 0, 15) as $d) {
    echo "  - " . $d . "\n";
}
if (count($diagnostics) > 15) {
    echo "  … " . (count($diagnostics) - 15) . " more\n";
}

$pdfOk = $bytes > 0 && is_file($outFile) && str_starts_with((string) file_get_contents($outFile, false, null, 0, 5), '%PDF-');

echo "\nACCEPT: " . ($pdfOk && $diagnostics === [] ? 'PASS' : ($pdfOk ? 'PASS (with diagnostics)' : 'FAIL')) . "\n";
exit($pdfOk ? 0 : 1);
