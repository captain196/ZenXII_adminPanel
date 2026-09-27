<?php
/**
 * Shared bootstrap for the reporting prototypes.
 * - loads the project's composer autoloader (same one index.php uses)
 * - provides self-contained SAMPLE data (so each prototype runs standalone)
 * - tiny helpers for output paths + rupee formatting
 *
 * Run any prototype from the repo root, e.g.:
 *   php samples/reporting/01_dompdf_fee_receipt.php
 * Output PDFs/XLSX land in samples/reporting/out/.
 */

require_once __DIR__ . '/../../vendor/autoload.php';   // dompdf + PhpSpreadsheet already installed here

const OUT_DIR = __DIR__ . '/out';
if (!is_dir(OUT_DIR)) { mkdir(OUT_DIR, 0755, true); }

function out_path(string $name): string { return OUT_DIR . '/' . $name; }

/** ₹ formatting like the rest of the ERP (Indian grouping). */
function inr(float $n): string {
    $s = number_format($n, 2, '.', '');
    [$int, $dec] = explode('.', $s);
    $last3 = substr($int, -3);
    $rest  = substr($int, 0, -3);
    if ($rest !== '') $last3 = ',' . $last3;
    $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    return '₹' . $rest . $last3 . '.' . $dec;
}

/** ₹ amount in words (Indian system) — a premium receipt touch. */
function inr_words(float $amount): string {
    $amount = round($amount, 2);
    $rupees = (int) floor($amount);
    $paise  = (int) round(($amount - $rupees) * 100);
    $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $two = function (int $n) use ($ones, $tens): string {
        if ($n < 20) return $ones[$n];
        return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $three = function (int $n) use ($ones, $two): string {
        $h = intdiv($n, 100); $r = $n % 100;
        return trim(($h ? $ones[$h] . ' Hundred' . ($r ? ' ' : '') : '') . $two($r));
    };
    if ($rupees === 0) { $words = 'Zero'; }
    else {
        $crore = intdiv($rupees, 10000000); $rupees %= 10000000;
        $lakh  = intdiv($rupees, 100000);   $rupees %= 100000;
        $thou  = intdiv($rupees, 1000);     $rupees %= 1000;
        $parts = [];
        if ($crore) $parts[] = $three($crore) . ' Crore';
        if ($lakh)  $parts[] = $three($lakh) . ' Lakh';
        if ($thou)  $parts[] = $three($thou) . ' Thousand';
        if ($rupees) $parts[] = $three($rupees);
        $words = implode(' ', $parts);
    }
    $out = 'Rupees ' . $words;
    if ($paise > 0) $out .= ' and ' . $two($paise) . ' Paise';
    return $out . ' Only';
}

/**
 * Real, scannable QR as an inline data: URI SVG — same technique (and same
 * library, chillerlan/php-qrcode) as the project's own
 * application/helpers/qr_token_helper.php::qr_svg_data_uri(). That helper
 * also HMAC-signs the payload for the attendance-scan flow; these prototypes
 * just encode plain text/URLs, so we call chillerlan directly rather than
 * pull in the app's CI3 bootstrap + secret machinery. In a real controller,
 * reuse qr_svg_data_uri() instead of duplicating this.
 */
function real_qr_data_uri(string $payload): string {
    $options = new \chillerlan\QRCode\QROptions([
        'outputType'   => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
        'outputBase64' => true,
        'eccLevel'     => \chillerlan\QRCode\Common\EccLevel::L,
        'scale'        => 5,
        'addQuietzone' => true,
    ]);
    return (string) (new \chillerlan\QRCode\QRCode($options))->render($payload);
}

/** Render a PHP HTML template file with $data extracted into scope → HTML string. */
function render_template(string $tplFile, array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    include $tplFile;
    return ob_get_clean();
}

// ── SAMPLE DATA (stand-ins for your Firestore docs) ─────────────────────────
$SCHOOL = [
    'name'    => 'IIT Kanpur School',
    'address' => 'Kalyanpur, Kanpur, Uttar Pradesh 208016',
    'phone'   => '+91 98765 43210',
    'session' => '2026-27',
    'accent'  => '#0f766e',   // your --gold teal
];

$FEE_RECEIPT = [
    'receipt_no'  => 'RCPT-0000123',
    'date'        => '04 Aug 2026',
    'student'     => 'Aarav Sharma',
    'student_id'  => 'STU0012',
    'class'       => 'Class 8 - A',
    'father'      => 'Rajesh Sharma',
    'mode'        => 'UPI (Razorpay)',
    'txn'         => 'pay_29QawS...c8Y',
    'lines'       => [
        ['head' => 'Tuition Fee (August)', 'amount' => 3500.00],
        ['head' => 'Transport Fee',        'amount' => 1200.00],
        ['head' => 'Computer Lab Fee',     'amount' => 300.00],
    ],
];

$DEFAULTERS = [
    ['id'=>'STU0007','name'=>'Isha Verma','class'=>'6-A','phone'=>'98xxxxxx01','due'=>7400.00,'months'=>2],
    ['id'=>'STU0012','name'=>'Aarav Sharma','class'=>'8-A','phone'=>'98xxxxxx02','due'=>3500.00,'months'=>1],
    ['id'=>'STU0021','name'=>'Kabir Khan','class'=>'9-B','phone'=>'98xxxxxx03','due'=>11250.00,'months'=>3],
    ['id'=>'STU0034','name'=>'Meera Nair','class'=>'5-C','phone'=>'98xxxxxx04','due'=>4200.00,'months'=>1],
    ['id'=>'STU0041','name'=>'Vivaan Gupta','class'=>'10-A','phone'=>'98xxxxxx05','due'=>15800.00,'months'=>4],
];

$REPORT_CARD = [
    'student'    => 'Aarav Sharma',        // English + Hindi in the template to show Devanagari
    'student_hi' => 'आरव शर्मा',
    'student_id' => 'STU0012',
    'class'      => 'Class 8 - A',
    'roll'       => '12',
    'term'       => 'Term 1 (2026-27)',
    'subjects'   => [
        ['name'=>'English',        'name_hi'=>'अंग्रेज़ी', 'max'=>100,'marks'=>88,'grade'=>'A1'],
        ['name'=>'Hindi',          'name_hi'=>'हिंदी',     'max'=>100,'marks'=>91,'grade'=>'A1'],
        ['name'=>'Mathematics',    'name_hi'=>'गणित',      'max'=>100,'marks'=>76,'grade'=>'B1'],
        ['name'=>'Science',        'name_hi'=>'विज्ञान',    'max'=>100,'marks'=>82,'grade'=>'A2'],
        ['name'=>'Social Science', 'name_hi'=>'सामाजिक विज्ञान','max'=>100,'marks'=>79,'grade'=>'B1'],
        ['name'=>'Sanskrit',       'name_hi'=>'संस्कृत',    'max'=>100,'marks'=>85,'grade'=>'A2'],
    ],
];

$ID_CARD = [
    'name'       => 'Aarav Sharma',
    'student_id' => 'STU0012',
    'class'      => 'Class 8 - A',
    'dob'        => '14 Mar 2013',
    'blood'      => 'B+',
    'father'     => 'Rajesh Sharma',
    'phone'      => '+91 98765 43210',
    'valid'      => '2026-27',
];

// ── Certificate (mirrors Certificates.php::PLACEHOLDERS — drop-in compatible) ──
$CERT_TOKENS = [
    '{student_name}'      => 'Aarav Sharma',
    '{father_name}'       => 'Rajesh Sharma',
    '{mother_name}'       => 'Priya Sharma',
    '{class}'             => 'Class 8',
    '{section}'           => 'A',
    '{admission_number}'  => 'STU0012',
    '{date_of_birth}'     => '14 March 2013',
    '{issue_date}'        => '04 August 2026',
    '{school_name}'       => 'IIT Kanpur School',
    '{school_address}'    => 'Kalyanpur, Kanpur, Uttar Pradesh 208016',
    '{certificate_number}'=> 'BC-2026-000148',
    '{session}'           => '2026-27',
    '{gender}'            => 'Male',
    '{nationality}'       => 'Indian',
    '{religion}'          => 'Hindu',
    '{caste}'             => 'General',
    '{enrollment_date}'   => '02 April 2019',
    '{leaving_date}'      => '',
    '{conduct}'           => 'Good',
    '{reason_for_leaving}'=> '',
];
$CERT_BODY_BONAFIDE = "This is to certify that <b>{student_name}</b>, son/daughter of <b>{father_name}</b> "
    . "and <b>{mother_name}</b>, is a bona fide student of this institution, studying in <b>{class} - {section}</b> "
    . "during the academic session <b>{session}</b>, bearing Admission Number <b>{admission_number}</b>.<br><br>"
    . "Date of Birth as per school records: <b>{date_of_birth}</b>. The student's conduct during the period "
    . "of study has been <b>{conduct}</b>.<br><br>This certificate is issued on the request of the "
    . "parent/guardian for official purposes.";

// ── Exam Admit Card / Hall Ticket ──
$ADMIT_CARD = [
    'student'    => 'Aarav Sharma',
    'student_id' => 'STU0012',
    'roll'       => '8A-012',
    'class'      => 'Class 8 - A',
    'father'     => 'Rajesh Sharma',
    'exam'       => 'Term 1 Examination 2026-27',
    'center'     => 'Main Campus — Block B',
    'schedule'   => [
        ['date' => '02 Sep 2026', 'day' => 'Wed', 'subject' => 'English',        'time' => '09:00 AM - 12:00 PM', 'room' => 'B-104'],
        ['date' => '04 Sep 2026', 'day' => 'Fri', 'subject' => 'Mathematics',    'time' => '09:00 AM - 12:00 PM', 'room' => 'B-104'],
        ['date' => '07 Sep 2026', 'day' => 'Mon', 'subject' => 'Science',        'time' => '09:00 AM - 12:00 PM', 'room' => 'B-104'],
        ['date' => '09 Sep 2026', 'day' => 'Wed', 'subject' => 'Social Science', 'time' => '09:00 AM - 12:00 PM', 'room' => 'B-104'],
        ['date' => '11 Sep 2026', 'day' => 'Fri', 'subject' => 'Hindi',          'time' => '09:00 AM - 12:00 PM', 'room' => 'B-104'],
    ],
    'instructions' => [
        'Report to the exam center 30 minutes before the scheduled time.',
        'This admit card must be carried to every examination and preserved till the results are declared.',
        'Electronic devices (mobile phones, smart watches) are strictly prohibited in the exam hall.',
        'Use of unfair means will lead to disqualification as per school policy.',
    ],
];

// ── Transport — Trip Manifest (module has zero reports today) ──
$TRIP_MANIFEST = [
    'route'    => 'Route 4 — Kalyanpur ⇄ Kakadeo',
    'vehicle'  => 'UP78 AT 4521 (Bus #7, 42-seater)',
    'driver'   => 'Suresh Yadav · +91 98xxxxxx11 · DL: UP32 2018 0004521',
    'attendant'=> 'Meena Devi · +91 98xxxxxx22',
    'date'     => '04 Aug 2026',
    'shift'    => 'Morning Pickup',
    'stops'    => [
        ['seq'=>1,'stop'=>'Kalyanpur Chowk','time'=>'07:05 AM','students'=>6,'boarded'=>6],
        ['seq'=>2,'stop'=>'Vikas Nagar','time'=>'07:14 AM','students'=>9,'boarded'=>8],
        ['seq'=>3,'stop'=>'Shastri Nagar','time'=>'07:26 AM','students'=>7,'boarded'=>7],
        ['seq'=>4,'stop'=>'Kakadeo Market','time'=>'07:40 AM','students'=>11,'boarded'=>10],
        ['seq'=>5,'stop'=>'School Gate','time'=>'08:00 AM','students'=>0,'boarded'=>0],
    ],
];
