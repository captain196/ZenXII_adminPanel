<?php
/**
 * CALENDAR COMMIT-ISOLATION ANALYSER.
 *
 * The Calendar Programme cannot be committed wholesale: every shared file it
 * touches also carries uncommitted work from Placement, SIS, HRMS, Substitute
 * and Lesson Planner. Asserting "it is isolatable" is not evidence, so this
 * measures it.
 *
 * For each shared file it walks the `git diff -U0` hunks and classifies each as
 * CALENDAR (contains a Calendar marker), OTHER, or MIXED — a hunk holding both.
 * A MIXED hunk is the only thing that actually blocks isolation, because it
 * cannot be applied or reverted without carrying somebody else's change.
 *
 * It WRITES NOTHING to the repository and stages nothing. Patches are emitted to
 * a directory you name, for a human to apply deliberately.
 *
 *   php scripts/calendar_commit_isolation.php
 *   php scripts/calendar_commit_isolation.php --emit=/path/to/patches
 */

chdir(__DIR__ . '/..');

/** Markers that identify a line as Calendar-programme work. */
$MARKERS = [
    'CAL-D1', 'CAL-D9', 'CAL-D10', 'CAL-D11', 'CAL-B1', 'CAL-5E', 'CAL-P3',
    'CAL-P3B', 'CAL-P4', 'CAL-P4b', 'CAL-P4c', 'CAL-PERF-1', 'SCS-',
    'student_calendar', 'Student_calendar', '_weekly_closure_days',
    'preserveExplicitMarks', '_non_scheduling_dates', 'examCal', 'weekendDays',
    'subCalendarNote', '_sendCover', 'School Week', '_calendar_readiness',
    '_session_window', '_invalidateCache', '_cachedDocs', '_cacheKey',
    '_cacheFile', 'CALENDAR PROGRAMME', 'confirm_non_instruction',
    '_non_instruction_cover_gate', '_student_calendar_for_date',
    'Leave_year_service::boundsForSession', 'scReadiness',
];

/**
 * Markers belonging to OTHER programmes, taken from their own in-tree comments.
 * A hunk carrying one of these plus a Calendar marker is genuinely mixed.
 */
$OTHER_MARKERS = [
    'Placement', 'Section_seat_guard', 'placement authority', 'seat authority',
    'SEC-1', 'SEC-2', 'SEC-3', 'SEC-6', 'SEC-8',
    'HRMS', 'W3.2', 'Compensation', 'compensation',
    'Substitute programme', 'substitutePolicy',
    'Lesson Planner', 'lessonPlan', 'Lesson_plan',
    'SIS ', 'Sr_no_service', 'Apaar', 'APAAR', 'Admission_no_service',
    'Student_write_service', 'Tenant_capability', 'capability_helper',
    'PAY-OPS', 'Razorpay', 'feeDemands', 'Fee_', 'TRN-', 'Transport',
    'TT-C8', 'TT-D1', 'TT-D2', 'timetableVersions',
    'ACAD-D1', 'ACAD-D17', 'ACAD-D18', 'P20', 'Phase 19', 'Phase19',
];

$SHARED = [
    'application/controllers/Attendance.php',
    'application/controllers/Academic.php',
    'application/controllers/Exam.php',
    'application/controllers/Staff_role_check.php',
    'application/libraries/Calendar_service.php',
    'application/helpers/attendance_helper.php',
    'application/views/academic/index.php',
    'application/views/exam/create.php',
    'application/views/include/header.php',
    'application/config/routes.php',
    'firebase-rules/firestore.rules',
];

$emitDir = '';
foreach ($argv as $a) {
    if (strpos($a, '--emit=') === 0) $emitDir = substr($a, 7);
}
if ($emitDir !== '' && !is_dir($emitDir)) @mkdir($emitDir, 0775, true);

function isCalendarLine(string $line, array $markers): bool
{
    foreach ($markers as $m) if (strpos($line, $m) !== false) return true;
    return false;
}

$totals = ['cal' => 0, 'other' => 0, 'mixed' => 0];
$blockers = [];

printf("%-46s %6s %6s %6s %6s\n", 'file', 'hunks', 'CAL', 'OTHER', 'MIXED');
printf("%s\n", str_repeat('-', 76));

foreach ($SHARED as $file) {
    if (!is_file($file)) continue;

    $out = [];
    exec('git diff -U0 -- ' . escapeshellarg($file) . ' 2>&1', $out);
    if (empty($out)) { printf("%-46s %6s\n", basename($file), 'clean'); continue; }

    $hunks = [];
    $cur = null;
    foreach ($out as $line) {
        if (strpos($line, '@@') === 0) {
            if ($cur !== null) $hunks[] = $cur;
            $cur = ['header' => $line, 'lines' => []];
            continue;
        }
        if ($cur !== null && (isset($line[0]) && ($line[0] === '+' || $line[0] === '-'))) {
            $cur['lines'][] = $line;
        }
    }
    if ($cur !== null) $hunks[] = $cur;

    $cal = $other = $mixed = 0;
    $calHunks = [];
    foreach ($hunks as $h) {
        // CLASSIFIER NOTE — the first version of this was wrong and produced a
        // misleading answer. It called a hunk MIXED whenever ANY line lacked a
        // Calendar marker, but most lines in a Calendar hunk are ordinary code
        // and prose that carry no marker at all. Every one of my own hunks was
        // therefore reported as a blocker.
        //
        // A hunk is only genuinely mixed when it carries a marker from Calendar
        // AND a marker from another programme. Unmarked lines belong to whichever
        // programme's marker the hunk carries.
        $hasCal = $hasOther = false;
        foreach ($h['lines'] as $l) {
            $body = substr($l, 1);
            $trim = trim($body);
            if ($trim === '') continue;

            if (isCalendarLine($body, $GLOBALS['MARKERS'])) $hasCal = true;

            // A COMMENT naming another programme is provenance, not shared code.
            // Calendar hunks deliberately cite ACAD-D18, ACAD-D13, SEC-3 and
            // TT-C8 to explain what they continue or restore — and counting
            // those as foreign made 10 of 11 "mixed" hunks false blockers, every
            // one of them wholly Calendar's. Only CODE bearing another
            // programme's marker makes a hunk genuinely mixed.
            $isComment = (strpos($trim, '//') === 0) || (strpos($trim, '*') === 0)
                      || (strpos($trim, '/*') === 0) || (strpos($trim, '#') === 0);
            if (!$isComment && isCalendarLine($body, $GLOBALS['OTHER_MARKERS'])) {
                $hasOther = true;
            }
        }
        // A hunk with no marker at all is not ours.
        if (!$hasCal && !$hasOther) $hasOther = true;
        if ($hasCal && $hasOther) {
            $mixed++;
            $blockers[] = basename($file) . ' ' . $h['header'];
        } elseif ($hasCal) { $cal++; $calHunks[] = $h; }
        else { $other++; }
    }

    $totals['cal'] += $cal; $totals['other'] += $other; $totals['mixed'] += $mixed;
    printf("%-46s %6d %6d %6d %6d%s\n", basename($file), count($hunks), $cal, $other, $mixed,
        $mixed > 0 ? '   <-- BLOCKS ISOLATION' : '');

    if ($emitDir !== '' && $calHunks) {
        $patch = "--- a/{$file}\n+++ b/{$file}\n";
        foreach ($calHunks as $h) {
            $patch .= $h['header'] . "\n" . implode("\n", $h['lines']) . "\n";
        }
        file_put_contents($emitDir . '/' . str_replace('/', '_', $file) . '.calendar.patch', $patch);
    }
}

printf("%s\n", str_repeat('-', 76));
printf("%-46s %6s %6d %6d %6d\n", 'TOTAL', '', $totals['cal'], $totals['other'], $totals['mixed']);

echo "\n";
if ($totals['mixed'] === 0) {
    echo "ISOLATABLE: every hunk is wholly Calendar or wholly another programme's.\n"
       . "A clean Calendar-only commit is technically possible once the owners of the\n"
       . "OTHER hunks agree the sequencing.\n";
} else {
    echo "NOT CLEANLY ISOLATABLE — " . $totals['mixed'] . " mixed hunk(s):\n";
    foreach (array_slice($blockers, 0, 12) as $b) echo "  {$b}\n";
    echo "\nEach mixed hunk needs manual splitting, or the neighbouring work landed first.\n";
}
echo "\nNOTHING was staged, committed or modified by this script.\n";
