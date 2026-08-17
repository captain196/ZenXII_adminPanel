<?php
/**
 * G0.4 — layout strategy proof. THE LOAD-BEARING GATE.
 *
 * Accept (EXECUTION_PLAN_v1.1.md):
 *   All cases render as designed AND page overflow is detectable.
 *   If block-flow-inside-absolute is unreliable, §0 is reversed and the
 *   measurement pass returns. That reversal is the point of this gate.
 *
 * Architecture §0 claims an anchor chain can be emitted as ONE absolutely
 * positioned container whose members are ordinary block children, letting the
 * renderer do the measurement. This harness tries to break that claim.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g04_layout.php
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

const LOREM = 'The pupil named herein has been a bona fide student of this institution and is '
            . 'hereby released from its rolls with effect from the date stated below. ';

function mk(string $tempDir, string $fontDir, array $opts = []): \Mpdf\Mpdf
{
    $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $df = (new \Mpdf\Config\FontVariables())->getDefaults();
    $m = new \Mpdf\Mpdf(array_merge([
        'mode'        => 'utf-8',
        'format'      => 'A4',
        'orientation' => 'P',
        'margin_left' => 15, 'margin_right' => 15,
        'margin_top'  => 15, 'margin_bottom' => 15,
        'tempDir'     => $tempDir,
        'fontDir'     => array_merge($dc['fontDir'], [$fontDir]),
        'fontdata'    => $df['fontdata'] + [
            'lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF],
        ],
        'useOTL'      => 0xFF,
    ], $opts));
    $m->SetCompression(false);
    return $m;
}

$results = [];
$note = static function (string $id, string $what, string $verdict, string $detail = '') use (&$results): void {
    $results[] = compact('id', 'what', 'verdict', 'detail');
};

// ═══════════════════════════════════════════════════════════════════════
// PART A — visual sheet: the layout constructs
// ═══════════════════════════════════════════════════════════════════════
$sheet = $outDir . '/g04_layout.pdf';
try {
    $m = mk($tempDir, $fontDir);

    // C1 — block flow inside an absolutely positioned container.
    $h = '<div style="position:absolute; left:0mm; top:0mm; width:180mm;">'
       . '<div style="font-size:12pt; font-weight:bold;">C1 block-flow inside absolute</div>'
       . '<div style="margin-top:3mm; border:0.2mm solid #999; padding:1mm;">child A (auto height)</div>'
       . '<div style="margin-top:3mm; border:0.2mm solid #999; padding:1mm;">child B — '
       . str_repeat(LOREM, 2) . '</div>'
       . '<div style="margin-top:3mm; border:0.2mm solid #c00; padding:1mm;">child C — must sit BELOW B\'s grown height</div>'
       . '</div>';

    // C2 — chain 4 deep, each anchored 4mm below the previous.
    $h .= '<div style="position:absolute; left:0mm; top:75mm; width:180mm;">'
        . '<div style="font-size:12pt; font-weight:bold;">C2 chain 4 deep</div>';
    foreach (['one', 'two — ' . LOREM, 'three', 'four (deepest)'] as $i => $t) {
        $h .= '<div style="margin-top:4mm; border:0.2mm solid #06c; padding:1mm;">'
            . ($i + 1) . ': ' . $t . '</div>';
    }
    $h .= '</div>';

    // C3 — fixed + auto siblings in one chain.
    $h .= '<div style="position:absolute; left:0mm; top:150mm; width:180mm;">'
        . '<div style="font-size:12pt; font-weight:bold;">C3 fixed + auto siblings</div>'
        . '<div style="margin-top:3mm; height:12mm; border:0.2mm solid #090; padding:1mm;">FIXED 12mm — must not grow</div>'
        . '<div style="margin-top:3mm; border:0.2mm solid #999; padding:1mm;">AUTO — ' . str_repeat(LOREM, 2) . '</div>'
        . '<div style="margin-top:3mm; border:0.2mm solid #c00; padding:1mm;">follower — below both</div>'
        . '</div>';

    $m->WriteHTML($h);

    // C7 — table inside an absolute container (plan says confirm or scope out).
    $m->AddPage();
    $t = '<div style="position:absolute; left:0mm; top:0mm; width:180mm;">'
       . '<div style="font-size:12pt; font-weight:bold;">C7 table inside absolute container</div>'
       . '<table border="1" style="width:100%; margin-top:3mm; border-collapse:collapse;">'
       . '<tr><td style="width:30%; padding:1mm;">Field</td><td style="padding:1mm;">Value</td></tr>'
       . '<tr><td style="padding:1mm;">Name</td><td style="padding:1mm;">Aarav Sharma</td></tr>'
       . '<tr><td style="padding:1mm;">Reason</td><td style="padding:1mm;">' . str_repeat(LOREM, 2) . '</td></tr>'
       . '</table></div>';

    // C8 image scaling + C11 mixed bold/size, both inside an absolute box.
    $t .= '<div style="position:absolute; left:0mm; top:80mm; width:180mm;">'
        . '<div style="font-size:12pt; font-weight:bold;">C8/C11 image scale + mixed inline</div>'
        . '<div style="margin-top:3mm;">normal '
        . '<span style="font-weight:bold;">BOLD</span> '
        . '<span style="font-size:18pt;">18pt</span> '
        . '<span style="font-size:6pt;">6pt</span> '
        . '<span style="font-family:lohitdeva;">कक्षा</span> mixed in one line</div>'
        . '</div>';

    $m->WriteHTML($t);
    $m->Output($sheet, \Mpdf\Output\Destination::FILE);
    $sheetPages = $m->page;
    unset($m);
    $note('A', 'visual sheet rendered', 'OK', $sheetPages . ' pages');
} catch (\Throwable $e) {
    $note('A', 'visual sheet', 'FATAL', $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════
// PART B — C4: does OVERFLOWING ABSOLUTE content increment $mpdf->page?
// This is the plan's assumed overflow-detection mechanism. Test it honestly.
// ═══════════════════════════════════════════════════════════════════════
try {
    $d = $tempDir . '/c4'; @mkdir($d, 0755, true);
    $m = mk($d, $fontDir);
    // Absolute container starting low on the page with far more content than fits.
    $m->WriteHTML('<div style="position:absolute; left:0mm; top:200mm; width:180mm;">'
        . '<div>OVERFLOW PROBE</div>'
        . '<div>' . str_repeat(LOREM, 40) . '</div></div>');
    $m->Output($outDir . '/g04_c4_abs_overflow.pdf', \Mpdf\Output\Destination::FILE);
    $pages = $m->page;
    unset($m);
    $note('C4', 'absolute overflow -> $mpdf->page', $pages > 1 ? 'DETECTED' : 'NOT DETECTED',
        'pages=' . $pages . ' (content far exceeds page bottom)');
} catch (\Throwable $e) {
    $note('C4', 'absolute overflow', 'FATAL', $e->getMessage());
}

// Control: the SAME content in NORMAL FLOW must paginate.
try {
    $d = $tempDir . '/c4b'; @mkdir($d, 0755, true);
    $m = mk($d, $fontDir);
    $m->WriteHTML('<div>FLOW CONTROL</div><div>' . str_repeat(LOREM, 40) . '</div>');
    $m->Output($outDir . '/g04_c4b_flow_control.pdf', \Mpdf\Output\Destination::FILE);
    $pages = $m->page;
    unset($m);
    $note('C4b', 'same content in NORMAL FLOW', $pages > 1 ? 'PAGINATES' : 'DID NOT PAGINATE',
        'pages=' . $pages);
} catch (\Throwable $e) {
    $note('C4b', 'flow control', 'FATAL', $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════
// PART C — C5/C10: flow region between repeating header and footer
// ═══════════════════════════════════════════════════════════════════════
try {
    $d = $tempDir . '/c5'; @mkdir($d, 0755, true);
    $m = mk($d, $fontDir, ['margin_top' => 32, 'margin_bottom' => 24, 'margin_header' => 8, 'margin_footer' => 8]);
    $m->SetHTMLHeader('<div style="border-bottom:0.3mm solid #333; font-weight:bold;">'
        . 'ZENXII MODEL SCHOOL — repeating letterhead</div>');
    $m->SetHTMLFooter('<div style="border-top:0.3mm solid #333; font-size:8pt;">'
        . 'Page {PAGENO} of {nbpg} — signature line</div>');
    $m->WriteHTML('<div style="font-size:11pt;">' . str_repeat(LOREM, 45) . '</div>');
    $m->Output($outDir . '/g04_c5_flowregion.pdf', \Mpdf\Output\Destination::FILE);
    $pages = $m->page;
    unset($m);
    $note('C5', 'flow region + repeating header/footer', $pages > 1 ? 'PAGINATES' : 'SINGLE PAGE',
        'pages=' . $pages);
    $note('C10', '{PAGENO}/{nbpg} in footer', 'SEE PDF', 'visual check on g04_c5_flowregion.pdf');
} catch (\Throwable $e) {
    $note('C5', 'flow region', 'FATAL', $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════
// PART D — C6: table inside the flow region, across a page break
// ═══════════════════════════════════════════════════════════════════════
try {
    $d = $tempDir . '/c6'; @mkdir($d, 0755, true);
    $m = mk($d, $fontDir, ['margin_top' => 30, 'margin_bottom' => 22]);
    $m->SetHTMLHeader('<div style="border-bottom:0.3mm solid #333;">header</div>');
    $m->SetHTMLFooter('<div style="font-size:8pt;">Page {PAGENO}</div>');
    $rows = '';
    for ($i = 1; $i <= 45; $i++) {
        $rows .= '<tr><td style="padding:1mm;">' . $i . '</td><td style="padding:1mm;">Subject ' . $i
               . '</td><td style="padding:1mm;">' . (50 + $i % 40) . '</td></tr>';
    }
    $m->WriteHTML('<table border="1" style="width:100%; border-collapse:collapse;">'
        . '<thead><tr><th>#</th><th>Subject</th><th>Marks</th></tr></thead><tbody>'
        . $rows . '</tbody></table>');
    $m->Output($outDir . '/g04_c6_table_flow.pdf', \Mpdf\Output\Destination::FILE);
    $pages = $m->page;
    unset($m);
    $note('C6', 'table in flow region across page break', $pages > 1 ? 'PAGINATES' : 'SINGLE PAGE',
        'pages=' . $pages);
} catch (\Throwable $e) {
    $note('C6', 'table in flow region', 'FATAL', $e->getMessage());
}

// ═══════════════════════════════════════════════════════════════════════
echo "=== G0.4 — layout strategy proof ===\n";
echo 'mpdf: ' . \Mpdf\Mpdf::VERSION . "\n\n";
printf("%-5s %-42s %-14s %s\n", 'ID', 'CASE', 'VERDICT', 'DETAIL');
echo str_repeat('-', 100) . "\n";
foreach ($results as $r) {
    printf("%-5s %-42s %-14s %s\n", $r['id'], $r['what'], $r['verdict'], $r['detail']);
}

$fatal = array_filter($results, static fn($r) => $r['verdict'] === 'FATAL');
echo "\nPDFs written to out/ — visual inspection required for C1,C2,C3,C7,C8,C11,C10.\n";
echo 'fatals: ' . count($fatal) . "\n";
exit($fatal ? 1 : 0);
