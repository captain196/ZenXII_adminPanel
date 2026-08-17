<?php
/**
 * G0 CAPSTONE — Transfer Certificate specimen.
 *
 * Not a gate. This is the first artefact that looks like the PRODUCT: a real
 * CBSE Annexure-I Transfer Certificate rendered end-to-end through everything
 * Gate 0 verified —
 *
 *   G0.2  Lohit fonts registered with useOTL 0xFF
 *   G0.3  Indic shaping (the Hindi edition exercises it for real)
 *   G0.4  absolute chrome + flow body, block-flow inside absolute containers
 *   G0.5  explicit line-height on every text object (BLOCKING rule)
 *   G0.8  the 22 verified Annexure-I fields, exact labels and order
 *
 * The designer UI does not exist yet. This is the engine's output, which is
 * what the designer will eventually drive.
 *
 * ⚠ Hindi labels here are PLACEHOLDER translations for demonstration. Real
 *   label translations need native review before shipping.
 *
 * THROWAWAY. Isolated vendor tree. Not part of the application.
 * Run:  php tests/doctemplates/gate0/g09_tc_specimen.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/vendor/autoload.php';

$fontDir = __DIR__ . '/fonts';
$outDir  = __DIR__ . '/out';
$tempDir = $outDir . '/_mpdftmp/tc';
@mkdir($tempDir, 0755, true);

/** Sample bundle — stands in for the resolved data contract (S7). */
$DATA = [
    'book'      => 'B-042',
    'serial'    => '001987',
    'admission' => 'SCH_D94FE8F7AD_STU0187',
    'f1'  => 'Aarav Sharma',
    'f2'  => 'Rajesh Sharma',
    'f3'  => 'Indian',
    'f4'  => 'No',
    'f5'  => '08/04/2019, Class III',
    'f6a' => '14/03/2011', 'f6b' => 'Fourteenth March Two Thousand Eleven',
    'f7a' => 'IX',         'f7b' => 'Ninth',
    'f8'  => 'Annual Examination 2025-26 — Passed',
    'f9'  => 'No',
    'f10' => ['English', 'Hindi', 'Mathematics', 'Science', 'Social Science'],
    'f11' => 'Yes', 'f11a' => 'X', 'f11b' => 'Tenth',
    'f12' => 'March 2026',
    'f13' => 'Nil',
    'f14' => '220',
    'f15' => '206',
    'f16' => 'Scout — Rajya Puraskar',
    'f17' => 'Football (Inter-School, District level runner-up)',
    'f18' => 'Good',
    'f19' => '02/08/2026',
    'f20' => '05/08/2026',
    'f21' => 'Family relocation to another city',
    'f22' => 'All dues cleared. Character satisfactory.',
];

/** The 22 Annexure-I labels, verbatim (G0.8) + placeholder Hindi. */
$LABELS = [
    1  => ['Name of Pupil', 'छात्र का नाम'],
    2  => ["Fathers/Guardian's Name", 'पिता / संरक्षक का नाम'],
    3  => ['Nationality', 'राष्ट्रीयता'],
    4  => ['Whether the candidate belongs to Schedule Caste or Schedule Tribe', 'क्या छात्र अनुसूचित जाति या अनुसूचित जनजाति से है'],
    5  => ['Date of first admission in the School with class', 'विद्यालय में प्रथम प्रवेश की तिथि तथा कक्षा'],
    6  => ['Date of birth (in Christian Era) according to Admission Register', 'प्रवेश रजिस्टर के अनुसार जन्म तिथि'],
    7  => ['Class in which the pupil last studied', 'अंतिम अध्ययन की कक्षा'],
    8  => ['School/Board Annual examination last taken with result', 'अंतिम वार्षिक परीक्षा तथा परिणाम'],
    9  => ['Whether failed, if so once/twice in the same class', 'क्या अनुत्तीर्ण हुआ, यदि हाँ तो एक बार / दो बार'],
    10 => ['Subjects Studied', 'अध्ययन किए गए विषय'],
    11 => ['Whether qualified for promotion to the higher class', 'क्या अगली कक्षा में प्रोन्नति हेतु योग्य है'],
    12 => ['Month upto which the (pupil has paid) school dues paid', 'किस माह तक विद्यालय शुल्क जमा है'],
    13 => ['Any fee concession availed of : if so, the nature of such concession', 'शुल्क में कोई छूट, यदि हाँ तो प्रकार'],
    14 => ['Total No. of working days', 'कुल कार्य दिवस'],
    15 => ['Total No. of working days present', 'उपस्थित कार्य दिवस'],
    16 => ['Whether NCC Cadet/Boy Scout/Girl Guide (details may be given)', 'एन.सी.सी. / स्काउट / गाइड विवरण'],
    17 => ['Games played or extra curricular activities in which the pupil usually took part', 'खेल एवं सह-पाठ्यक्रम गतिविधियाँ'],
    18 => ['General conduct', 'सामान्य आचरण'],
    19 => ['Date of application for certificate', 'प्रमाणपत्र हेतु आवेदन की तिथि'],
    20 => ['Date of issue of certificate', 'प्रमाणपत्र निर्गत करने की तिथि'],
    21 => ['Reasons for leaving the school', 'विद्यालय छोड़ने का कारण'],
    22 => ['Any other remarks', 'अन्य टिप्पणी'],
];

$SIGN = [
    'en' => ['Signature of class teacher', 'Checked by', '(state full name and designation)', 'Principal', 'SEAL'],
    'hi' => ['कक्षा अध्यापक के हस्ताक्षर', 'जाँचकर्ता', '(पूरा नाम एवं पदनाम)', 'प्राचार्य', 'मुहर'],
];

$SCHOOL = [
    'en' => ['ZENXII MODEL SCHOOL', 'Sector 14, New Delhi — 110001', 'Affiliation No. 2730xxx  ·  School Code 4xxxx'],
    'hi' => ['ज़ेनज़ी मॉडल विद्यालय', 'सेक्टर 14, नई दिल्ली — 110001', 'संबद्धता क्रमांक 2730xxx  ·  विद्यालय कोड 4xxxx'],
];

$TITLE = ['en' => 'TRANSFER CERTIFICATE', 'hi' => 'स्थानांतरण प्रमाणपत्र'];

/**
 * BLOCKING rule from G0.5: every text object carries an explicit line-height.
 * Without it mPDF and the browser preview disagree (Tamil measured ~2x out).
 */
function css(string $fam, float $pt, float $lh = 1.35, string $extra = ''): string
{
    return "font-family:{$fam}; font-size:{$pt}pt; line-height:{$lh}; {$extra}";
}

function dots(int $n = 60): string
{
    return '<span style="color:#888;">' . str_repeat('.', $n) . '</span>';
}

function buildTc(string $lang, array $D, array $LABELS, array $SIGN, array $SCHOOL, array $TITLE): string
{
    $body = $lang === 'hi' ? 'lohitdeva' : 'dejavusans';
    $i    = $lang === 'hi' ? 1 : 0;
    $val  = 'dejavusans';   // sample values stay Latin in both editions

    // Chrome (letterhead/title/serials) is NOT emitted here.
    // G0 FINDING: absolute chrome does not reserve space, and mPDF collapses
    // margin-top on the first flow block, so the body collided with the
    // letterhead. Chrome therefore lives in the mPDF HEADER and margin_top
    // reserves its space -- exactly what v1.1 §0.3 P1 prescribes.
    $h = '';

    // --- flow body: the 22 fields ----------------------------------------
    $rows = '';
    $row = static function (int $n, string $label, string $value) use ($body, $val): string {
        return '<tr>'
             . '<td style="width:7mm; vertical-align:top; ' . css($val, 9.5, 1.45) . '">' . $n . '.</td>'
             . '<td style="vertical-align:top; ' . css($body, 9.5, 1.45) . '">' . $label
             . ' <span style="' . css($val, 9.5, 1.45, 'font-weight:bold;') . '">' . $value . '</span></td>'
             . '</tr>';
    };

    foreach ($LABELS as $n => $pair) {
        $L = htmlspecialchars($pair[$i], ENT_QUOTES, 'UTF-8');
        switch ($n) {
            case 6:
                $v = '(in figures) <b>' . $D['f6a'] . '</b> &nbsp; (in words) <b>' . $D['f6b'] . '</b>';
                break;
            case 7:
                $v = '(in figures) <b>' . $D['f7a'] . '</b> &nbsp; (in words) <b>' . $D['f7b'] . '</b>';
                break;
            case 10:
                $parts = [];
                foreach ($D['f10'] as $k => $s) {
                    $parts[] = ($k + 1) . '. <b>' . htmlspecialchars($s, ENT_QUOTES) . '</b>';
                }
                $v = implode(' &nbsp; ', $parts);
                break;
            case 11:
                $v = '<b>' . $D['f11'] . '</b> — If so, to which class (in fig.) <b>' . $D['f11a']
                   . '</b> &nbsp; (in words) <b>' . $D['f11b'] . '</b>';
                break;
            default:
                $v = '<b>' . htmlspecialchars((string) ($D['f' . $n] ?? ''), ENT_QUOTES, 'UTF-8') . '</b>';
        }
        $rows .= $row($n, $L, $v);
    }

    $h .= '<table style="width:100%; border-collapse:collapse;">' . $rows . '</table>';

    // --- signature block --------------------------------------------------
    $S = $SIGN[$lang];
    $h .= '<div style="margin-top:10mm;"><table style="width:100%;"><tr>'
        . '<td style="width:33%; text-align:center; ' . css($body, 8.5, 1.4) . '">'
        . '____________________<br>' . htmlspecialchars($S[0], ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="width:34%; text-align:center; ' . css($body, 8.5, 1.4) . '">'
        . '____________________<br>' . htmlspecialchars($S[1], ENT_QUOTES, 'UTF-8') . '<br>'
        . '<span style="' . css($body, 7, 1.4) . '">' . htmlspecialchars($S[2], ENT_QUOTES, 'UTF-8') . '</span></td>'
        . '<td style="width:33%; text-align:center; ' . css($body, 8.5, 1.4) . '">'
        . '____________________<br>' . htmlspecialchars($S[3], ENT_QUOTES, 'UTF-8')
        . '<br><span style="' . css($body, 8, 1.4, 'letter-spacing:0.4mm;') . '">('
        . htmlspecialchars($S[4], ENT_QUOTES, 'UTF-8') . ')</span></td>'
        . '</tr></table></div>';

    return $h;
}

/** Chrome as a repeating mPDF header; margin_top reserves its space. */
function buildHeader(string $lang, array $D, array $SCHOOL, array $TITLE): string
{
    $body = $lang === 'hi' ? 'lohitdeva' : 'dejavusans';
    $h  = '<div style="text-align:center; ' . css($body, 15, 1.3, 'font-weight:bold;') . '">'
        . htmlspecialchars($SCHOOL[$lang][0], ENT_QUOTES, 'UTF-8') . '</div>';
    $h .= '<div style="text-align:center; ' . css($body, 8.5, 1.35) . '">'
        . htmlspecialchars($SCHOOL[$lang][1], ENT_QUOTES, 'UTF-8') . '<br>'
        . htmlspecialchars($SCHOOL[$lang][2], ENT_QUOTES, 'UTF-8') . '</div>';
    $h .= '<div style="border-top:0.4mm solid #222; margin-top:1.5mm;"></div>';
    $h .= '<div style="text-align:center; ' . css($body, 12.5, 1.4, 'font-weight:bold; letter-spacing:0.6mm;') . '">'
        . htmlspecialchars($TITLE[$lang], ENT_QUOTES, 'UTF-8') . '</div>';
    $h .= '<table style="width:100%; margin-top:1mm;"><tr>'
        . '<td style="' . css('dejavusans', 8.5, 1.35) . '">Book No. <b>' . $D['book'] . '</b></td>'
        . '<td style="' . css('dejavusans', 8.5, 1.35) . '">Sl. No. <b>' . $D['serial'] . '</b></td>'
        . '<td style="' . css('dejavusans', 8.5, 1.35) . '">Admission No. <b>' . $D['admission'] . '</b></td>'
        . '</tr></table>';
    return $h;
}

$FONTDATA = ['lohitdeva' => ['R' => 'Lohit-Devanagari.ttf', 'useOTL' => 0xFF]];

$made = [];
foreach (['en', 'hi'] as $lang) {
    $dc = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $df = (new \Mpdf\Config\FontVariables())->getDefaults();
    $m = new \Mpdf\Mpdf([
        'mode'        => 'utf-8',
        'format'      => 'A4',
        'margin_left' => 15, 'margin_right' => 15,
        'margin_top'  => 42, 'margin_bottom' => 16, 'margin_header' => 8,
        'tempDir'     => $tempDir,
        'fontDir'     => array_merge($dc['fontDir'], [$fontDir]),
        'fontdata'    => $df['fontdata'] + $FONTDATA,
        'useOTL'      => 0xFF,
    ]);
    $m->SetHTMLHeader(buildHeader($lang, $DATA, $SCHOOL, $TITLE));
    $m->SetHTMLFooter('<div style="' . css('dejavusans', 7, 1.3) . ' color:#666; border-top:0.2mm solid #bbb;">'
        . 'ZenXii specimen — CBSE Examination Bye-Laws Annexure-I · Page {PAGENO} of {nbpg}</div>');
    $m->WriteHTML(buildTc($lang, $DATA, $LABELS, $SIGN, $SCHOOL, $TITLE));
    $f = $outDir . '/tc_specimen_' . $lang . '.pdf';
    $m->Output($f, \Mpdf\Output\Destination::FILE);
    $made[$lang] = ['file' => $f, 'pages' => $m->page, 'kb' => (int) round(filesize($f) / 1024)];
    unset($m);
}

echo "=== Transfer Certificate specimen ===\n";
foreach ($made as $lang => $x) {
    printf("  %-3s  %s  (%d page(s), %d KB)\n", $lang, basename($x['file']), $x['pages'], $x['kb']);
}
echo "\nRasterise:  pdftoppm -png -r 150 out/tc_specimen_hi.pdf out/tc_hi\n";
