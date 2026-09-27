<?php
/**
 * CROSS-LANGUAGE AGREEMENT — the exam builder's JavaScript vs the PHP resolver.
 *
 * WHY. The datesheet builder decides which dates to skip in the BROWSER, using
 * `zxbIsWorkingDay()`. The server decides the same thing in PHP, through
 * Student_calendar_reader. Two implementations of one question in two languages
 * is precisely how this programme's sixth week-authority came to exist, and a
 * disagreement here is invisible to every PHP test — the user sees one thing,
 * the server believes another.
 *
 * So the REAL JavaScript is extracted from the view (never a re-typed copy —
 * that would only prove a copy agrees with itself), handed the payload the
 * server actually sends, and compared date-for-date against PHP.
 *
 * WHAT IT PROVES. That for the payload shape `Exam::_non_scheduling_dates()`
 * emits, the browser skips exactly the dates PHP considers non-teaching.
 * It does not prove the page renders, only that the two agree.
 *
 *   php scripts/calendar_js_php_agreement.php
 */

chdir(__DIR__ . '/..');

if (!defined('BASEPATH')) define('BASEPATH', 1);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($a, $b) {} }
require_once APPPATH . 'libraries/Student_calendar_service.php';

// ── 1. PHP side: the authoritative answer ────────────────────────────────
$version = [
    'effectiveFrom'  => '2026-01-01',
    'weeklyOffs'     => ['Sun'],
    'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]],
];
// A holiday, and a special working day that lands on a Sunday.
$holidayDates = ['2026-03-05' => 'Holi', '2026-10-20' => 'Diwali'];
$swdDates     = ['2026-06-07' => 'Makeup Sunday'];

$from = new DateTimeImmutable('2026-01-01');
$phpNonTeaching = [];
$allDates = [];
for ($i = 0; $i < 400; $i++) {
    $date = $from->modify("+{$i} day")->format('Y-m-d');
    $allDates[] = $date;

    $r = Student_calendar_service::resolveForDate($version, $date, [
        'holiday'           => isset($holidayDates[$date]),
        'specialWorkingDay' => isset($swdDates[$date]),
    ]);
    if ($r['attendance'] === false) $phpNonTeaching[] = $date;
}

// ── 2. Extract the REAL predicate from the view ──────────────────────────
$view = file_get_contents('application/views/exam/create.php');
if (!preg_match('~function zxbFmtDate\(.*?\n\s*\}~s', $view, $mFmt)
 || !preg_match('~function zxbIsWorkingDay\(.*?\n\s*\}~s', $view, $mWork)) {
    fwrite(STDERR, "FAILED: could not extract the predicate from the view.\n"
        . "That is itself a finding — the agreement check would silently prove nothing.\n");
    exit(1);
}

// ── 3. Drive it in node with the payload the server actually sends ───────
$payload = [
    // Exactly what Exam.php emits: weekly closures already expanded into
    // `holidays` as real dates, and `weekend` empty because of it.
    'holidays' => $phpNonTeaching,
    'weekend'  => [],
    'dates'    => $allDates,
];

$js = "const P = " . json_encode($payload) . ";\n"
    . "var zxbModel = { calendar: { holidays: P.holidays, weekend: P.weekend } };\n"
    . $mFmt[0] . "\n"
    . $mWork[0] . "\n"
    . "const jsNonTeaching = P.dates.filter(d => !zxbIsWorkingDay(d));\n"
    . "process.stdout.write(JSON.stringify(jsNonTeaching));\n";

$tmp = sys_get_temp_dir() . '/zenx_js_php_agreement.js';
file_put_contents($tmp, $js);

$out = [];
$code = 0;
exec('node ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "FAILED: node could not run the extracted predicate:\n"
        . implode("\n", $out) . "\n");
    exit(1);
}

$jsNonTeaching = json_decode(implode('', $out), true);
if (!is_array($jsNonTeaching)) {
    fwrite(STDERR, "FAILED: predicate produced no usable output.\n");
    exit(1);
}

// ── 4. Compare ───────────────────────────────────────────────────────────
sort($phpNonTeaching);
sort($jsNonTeaching);

$onlyPhp = array_values(array_diff($phpNonTeaching, $jsNonTeaching));
$onlyJs  = array_values(array_diff($jsNonTeaching, $phpNonTeaching));

echo "dates compared:            " . count($allDates) . "\n";
echo "PHP says non-teaching:     " . count($phpNonTeaching) . "\n";
echo "JS  skips:                 " . count($jsNonTeaching) . "\n";
echo "PHP-only (JS would teach): " . count($onlyPhp) . "\n";
echo "JS-only  (JS over-skips):  " . count($onlyJs) . "\n";

// Sanity: a comparison of two empty sets proves nothing.
if (count($phpNonTeaching) < 50) {
    fwrite(STDERR, "\nFAILED: only " . count($phpNonTeaching) . " non-teaching dates — "
        . "the fixture is too thin for this comparison to mean anything.\n");
    exit(1);
}

if ($onlyPhp || $onlyJs) {
    echo "\nDISAGREEMENT:\n";
    if ($onlyPhp) echo "  JS would schedule an exam on: " . implode(', ', array_slice($onlyPhp, 0, 10)) . "\n";
    if ($onlyJs)  echo "  JS needlessly skips:          " . implode(', ', array_slice($onlyJs, 0, 10)) . "\n";
    exit(1);
}

echo "\nThe browser and the server agree on every date. (Agreement only — not a render proof.)\n";
exit(0);
