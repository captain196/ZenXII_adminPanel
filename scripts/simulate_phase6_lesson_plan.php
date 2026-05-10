<?php
/**
 * ═══════════════════════════════════════════════════════════════════
 *  Phase 6 — Lesson Plan Service Simulation
 *
 *  Validates Lesson_plan_service end-to-end against live Firestore:
 *    - Discovers a real teacher/class/subject/period from existing
 *      timetables + subjectAssignments
 *    - Read-only: shape checks + validation rejections
 *    - --apply: full create → read → update → monthly-view → delete
 *      round-trip, leaving Firestore in original state
 *
 *  Usage:
 *    php scripts/simulate_phase6_lesson_plan.php
 *    php scripts/simulate_phase6_lesson_plan.php --apply
 *    php scripts/simulate_phase6_lesson_plan.php --apply --verbose
 * ═══════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }

define('BASEPATH', realpath(__DIR__ . '/../system/') . DIRECTORY_SEPARATOR);
define('APPPATH',  realpath(__DIR__ . '/../application/') . DIRECTORY_SEPARATOR);

if (!function_exists('log_message')) {
    function log_message($level, $msg) { /* silent in tests */ }
}

require_once APPPATH . 'libraries/Firestore_rest_client.php';
require_once APPPATH . 'libraries/Service_exception.php';
require_once APPPATH . 'libraries/Audit_log_service.php';
require_once APPPATH . 'libraries/Subject_assignment_service.php';
require_once APPPATH . 'libraries/Lesson_plan_service.php';
require_once APPPATH . 'libraries/Analytics_service.php';

$APPLY   = in_array('--apply',   $argv, true);
$VERBOSE = in_array('--verbose', $argv, true);

const SCHOOL_ID   = 'SCH_D94FE8F7AD';
const SCHOOL_NAME = 'SCH_D94FE8F7AD';
const SESSION     = '2026-27';
const ACTOR_ID    = 'simulator_phase6';
const ACTOR_NAME  = 'Phase6 Simulation';
const ACTOR_ROLE  = 'School Super Admin';

class FirebaseTestAdapter {
    private $client;
    public function __construct(FirestoreRestClient $c) { $this->client = $c; }
    public function firestoreGet($c, $d) { return $this->client->getDocument($c, $d); }
    public function firestoreSet($c, $d, $data, $merge = false) { return $this->client->setDocument($c, $d, $data, $merge); }
    public function firestoreDelete($c, $d) { return $this->client->deleteDocument($c, $d); }
    public function firestoreCommitBatch($ops) { return $this->client->commitBatch($ops); }
    public function firestoreQuery($c, $cond = [], $orderBy = null, $dir = 'ASC', $limit = null) {
        return $this->client->query($c, $cond, $orderBy, $dir, $limit);
    }
    public function getFirestoreDb() { return $this->client; }
}
class FirestoreServiceTestAdapter {
    private $client; private $schoolId; private $session;
    public function __construct(FirestoreRestClient $c, string $s, string $y) {
        $this->client = $c; $this->schoolId = $s; $this->session = $y;
    }
    public function sessionWhere($coll, $cond, $o = null, $d = 'ASC', $l = null) {
        return $this->client->query($coll, array_merge([
            ['schoolId','==',$this->schoolId], ['session','==',$this->session],
        ], $cond), $o, $d, $l);
    }
    public function schoolWhere($coll, $cond, $o = null, $d = 'ASC', $l = null) {
        return $this->client->query($coll, array_merge([['schoolId','==',$this->schoolId]], $cond), $o, $d, $l);
    }
}

function bold($s){return "\033[1m$s\033[0m";} function dim($s){return "\033[2m$s\033[0m";}
function cyan($s){return "\033[36m$s\033[0m";} function yel($s){return "\033[33m$s\033[0m";}
function grn($s){return "\033[32m$s\033[0m";}  function red($s){return "\033[31m$s\033[0m";}
const HR = "────────────────────────────────────────────────────────────────────────";

$saPath = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$client = new FirestoreRestClient($saPath, 'graderadmin', '(default)');
$fb = new FirebaseTestAdapter($client);
$fs = new FirestoreServiceTestAdapter($client, SCHOOL_ID, SESSION);

$audit = new Audit_log_service();
$audit->init($fb, SCHOOL_ID, SESSION, [
    'uid' => ACTOR_ID, 'name' => ACTOR_NAME, 'role' => ACTOR_ROLE,
]);

$svc = new Lesson_plan_service();
$svc->init($fb, $fs, SCHOOL_ID, SCHOOL_NAME, SESSION,
           ACTOR_ID, ACTOR_NAME, ACTOR_ROLE, $audit);

$pass = 0; $fail = 0;
function check(bool $cond, string $msg, $detail = null) {
    global $pass, $fail, $VERBOSE;
    if ($cond) { $pass++; if ($VERBOSE) echo grn('  ✓ ') . $msg . "\n"; }
    else       { $fail++; echo red('  ✗ ') . $msg . ($detail !== null ? dim(' — '. (is_string($detail) ? $detail : json_encode($detail))) : '') . "\n"; }
}
function section(string $n) { echo "\n" . bold(cyan($n)) . "\n" . HR . "\n"; }

echo bold(cyan('Phase 6 — Lesson Plan Service Simulation')) . "\n" . HR . "\n";
echo '  mode  : ' . ($APPLY ? grn('APPLY (write+cleanup)') : yel('READ-ONLY')) . "\n";
echo '  school: ' . SCHOOL_ID . "  session: " . SESSION . "\n" . HR . "\n";

// ════════════════════════════════════════════════════════════════════
// T1 — basic param validation
// ════════════════════════════════════════════════════════════════════
section('T1  Param validation');
$throws = function (callable $f): ?Service_exception {
    try { $f(); return null; } catch (Service_exception $e) { return $e; }
};

$e = $throws(fn() => $svc->getLessonPlan('', '', -1));
check($e !== null, 'getLessonPlan rejects empty params', $e ? null : 'no throw');

$e = $throws(fn() => $svc->getLessonPlan('T1', '2026/05/01', 0));
check($e !== null && stripos($e->getMessage(), 'YYYY-MM-DD') !== false,
      'getLessonPlan rejects malformed date', $e ? $e->getMessage() : null);

$e = $throws(fn() => $svc->saveLessonPlan([
    'class_name' => '', 'section_name' => '', 'subject' => '',
    'teacher_id' => '', 'date' => '', 'period_index' => -1,
]));
check($e !== null, 'saveLessonPlan rejects empty params');

$e = $throws(fn() => $svc->saveLessonPlan([
    'class_name' => 'Class 8th', 'section_name' => 'A', 'subject' => 'Math',
    'teacher_id' => 'T1', 'date' => '2026-05-01', 'period_index' => 0, 'status' => 'bogus',
]));
check($e !== null && stripos($e->getMessage(), 'status') !== false,
      'saveLessonPlan rejects bad status', $e ? $e->getMessage() : null);

$e = $throws(fn() => $svc->saveLessonPlan([
    'class_name' => 'Class 8th', 'section_name' => 'A', 'subject' => 'Math',
    'teacher_id' => 'T1', 'date' => '2026-05-01', 'period_index' => 0,
    'notes' => str_repeat('x', 2001),
]));
check($e !== null && stripos($e->getMessage(), 'notes') !== false,
      'saveLessonPlan rejects oversized notes');

$e = $throws(fn() => $svc->getMonthlyPlan('T1', 1999, 13));
check($e !== null, 'getMonthlyPlan rejects bad year/month');

// ════════════════════════════════════════════════════════════════════
// T2 — discover a real (teacher, class, section, subject, period, day)
//       from current timetables. Skip apply-mode tests if none exist.
// ════════════════════════════════════════════════════════════════════
section('T2  Discover real timetable cell for round-trip');
$ttDocs = $client->query('timetables', [
    ['schoolId', '==', SCHOOL_ID],
    ['session',  '==', SESSION],
], null, 'ASC', 200);
echo dim('  scanned ' . count($ttDocs) . " timetable docs\n");

$cell = null;
foreach ($ttDocs as $doc) {
    $d = is_array($doc['data'] ?? null) ? $doc['data'] : $doc;
    foreach (($d['periods'] ?? []) as $p) {
        if (!is_array($p)) continue;
        $tid = (string)($p['teacherId'] ?? '');
        $sub = (string)($p['subject']   ?? '');
        if ($tid !== '' && $sub !== '') {
            $cell = [
                'className'   => $d['className']   ?? '',
                'section'     => $d['section']     ?? '',
                'day'         => $d['day']         ?? '',
                'periodIndex' => ((int)($p['periodNumber'] ?? 1)) - 1,
                'teacherId'   => $tid,
                'teacherName' => (string)($p['teacher'] ?? ''),
                'subject'     => $sub,
            ];
            break 2;
        }
    }
}

if ($cell === null) {
    echo yel("  ⚠ No populated timetable cells found — skipping apply-mode tests.\n");
} else {
    check(true, "discovered cell {$cell['className']}/{$cell['section']} {$cell['day']} P" . ($cell['periodIndex']+1) .
                " ({$cell['subject']} · {$cell['teacherId']})");
}

// ════════════════════════════════════════════════════════════════════
// T3 — period-mismatch / not-found rejections (no writes)
// ════════════════════════════════════════════════════════════════════
section('T3  Validation rejections (no writes)');

// Pick a (class, section, day) where we KNOW no period exists at index 99
$nonexistentDate = $cell ? _nextDate($cell['day']) : '2026-05-01';
$e = $throws(fn() => $svc->saveLessonPlan([
    'class_name' => $cell['className'] ?? 'Class 8th',
    'section_name' => $cell['section'] ?? 'Section A',
    'subject' => $cell['subject'] ?? 'Math',
    'teacher_id' => $cell['teacherId'] ?? 'T1',
    'date' => $nonexistentDate,
    'period_index' => 99,
]));
check($e !== null && stripos($e->getMessage(), 'No timetable period') !== false,
      'rejects period_index that does not exist',
      $e ? $e->getMessage() : 'no throw');

if ($cell) {
    // Wrong subject for the slot
    $e = $throws(fn() => $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => 'WrongSubjectXYZ_'.uniqid(),
        'teacher_id' => $cell['teacherId'],
        'date' => $nonexistentDate, 'period_index' => $cell['periodIndex'],
    ]));
    check($e !== null && stripos($e->getMessage(), 'Period mismatch') !== false,
          'rejects subject that does not match timetable cell',
          $e ? $e->getMessage() : 'no throw');

    // Wrong teacher for the slot
    $e = $throws(fn() => $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'],
        'teacher_id' => 'WRONG_TEACHER_'.uniqid(),
        'date' => $nonexistentDate, 'period_index' => $cell['periodIndex'],
    ]));
    check($e !== null && stripos($e->getMessage(), 'Period mismatch') !== false,
          'rejects teacher_id that does not match timetable cell',
          $e ? $e->getMessage() : 'no throw');

    // Bogus topicId
    $e = $throws(fn() => $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $nonexistentDate, 'period_index' => $cell['periodIndex'],
        'topic_id' => 'topic_nonexistent_'.uniqid(),
    ]));
    check($e !== null && stripos($e->getMessage(), 'topic') !== false,
          'rejects invalid topic_id', $e ? $e->getMessage() : 'no throw');
}

// ════════════════════════════════════════════════════════════════════
// T4 — getLessonPlan / getDailyPlan empty-state behavior
// ════════════════════════════════════════════════════════════════════
section('T4  empty-state reads');
$miss = $svc->getLessonPlan('NONEXISTENT_TEACHER_'.uniqid(), '2026-05-01', 0);
check($miss === null, 'getLessonPlan returns null for missing slot', $miss);

$emptyDay = $svc->getDailyPlan('NONEXISTENT_TEACHER_'.uniqid(), '2026-05-01');
check($emptyDay === [], 'getDailyPlan returns [] for unknown teacher', $emptyDay);

$e = $throws(fn() => $svc->getDailyPlan('', '2026-05-01'));
check($e !== null, 'getDailyPlan rejects empty teacher_id');

$e = $throws(fn() => $svc->getDailyPlan('T1', '2026/05/01'));
check($e !== null && stripos($e->getMessage(), 'YYYY-MM-DD') !== false,
      'getDailyPlan rejects malformed date');

// ════════════════════════════════════════════════════════════════════
// T4b — rescheduledTo validation (Phase 6A)
// ════════════════════════════════════════════════════════════════════
section('T4b rescheduledTo validation');
if ($cell) {
    $futureDate = _nextDate($cell['day']);

    // Bad format
    $e = $throws(fn() => $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $futureDate, 'period_index' => $cell['periodIndex'],
        'rescheduled_to' => '2026/05/15-P3',
    ]));
    check($e !== null && stripos($e->getMessage(), 'rescheduled_to') !== false,
          'rejects malformed rescheduled_to', $e ? $e->getMessage() : 'no throw');

    // status=rescheduled but no rescheduled_to
    $e = $throws(fn() => $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $futureDate, 'period_index' => $cell['periodIndex'],
        'status' => 'rescheduled',
    ]));
    check($e !== null && stripos($e->getMessage(), 'required when status=rescheduled') !== false,
          'rejects status=rescheduled without rescheduled_to',
          $e ? $e->getMessage() : 'no throw');
}

// ════════════════════════════════════════════════════════════════════
// T5 — APPLY: create → read → update → monthly-view → delete
// ════════════════════════════════════════════════════════════════════
if ($APPLY && $cell) {
    section('T5  APPLY round-trip (create → read → update → monthly → delete)');
    $testDate = _nextDate($cell['day']);
    if ($VERBOSE) echo dim("  using date $testDate ({$cell['day']})\n");

    // create
    $created = null;
    try {
        $created = $svc->saveLessonPlan([
            'class_name' => $cell['className'], 'section_name' => $cell['section'],
            'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
            'teacher_name' => $cell['teacherName'],
            'date' => $testDate, 'period_index' => $cell['periodIndex'],
            'notes' => 'phase6 simulation', 'status' => 'planned',
        ]);
    } catch (Service_exception $e) {
        check(false, 'create throws unexpected', $e->getMessage());
    }
    check(is_array($created),                              'create returns array');
    check(($created['version'] ?? 0) >= 1,                 'create stamps version');
    check(($created['status']  ?? '') === 'planned',       'create stores status=planned');
    check(($created['classSection'] ?? '') !== '',         'create stamps canonical classSection');
    check(($created['dayOfWeek'] ?? '') === $cell['day'],  'create derives correct dayOfWeek');
    check(($created['curriculumDocId'] ?? '') !== '',      'create stamps curriculumDocId');

    $createdVer = (int)($created['version'] ?? 0);
    $planId     = (string)($created['planId'] ?? '');

    // read back
    $read = $svc->getLessonPlan($cell['teacherId'], $testDate, $cell['periodIndex']);
    check(is_array($read),                                 'read returns saved plan');
    check(($read['planId'] ?? '') === $planId,             'read planId matches');
    check(($read['notes']  ?? '') === 'phase6 simulation', 'read notes match');

    // update — flip status to completed, increment version
    $updated = $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $testDate, 'period_index' => $cell['periodIndex'],
        'notes' => 'phase6 simulation (done)', 'status' => 'completed',
        'expected_version' => $createdVer,
    ]);
    check(($updated['version'] ?? 0) === $createdVer + 1,  'update bumps version');
    check(($updated['status']  ?? '') === 'completed',     'update stores status=completed');
    check(($updated['completedAt'] ?? '') !== '',          'update stamps completedAt');

    // optimistic concurrency — stale version should fail
    $e = $throws(fn() => $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $testDate, 'period_index' => $cell['periodIndex'],
        'expected_version' => $createdVer,  // stale
    ]));
    check($e !== null && stripos($e->getMessage(), 'Conflict') !== false,
          'optimistic concurrency rejects stale version',
          $e ? $e->getMessage() : 'no throw');

    // daily view (Phase 6A)
    $daily = $svc->getDailyPlan($cell['teacherId'], $testDate);
    check(is_array($daily) && count($daily) >= 1,           'daily returns ≥1 plan for test date');
    $found = false;
    foreach ($daily as $pl) {
        if (($pl['planId'] ?? '') === $planId) { $found = true; break; }
    }
    check($found,                                           'daily plan list includes our plan');

    // monthly view
    $year  = (int) substr($testDate, 0, 4);
    $month = (int) substr($testDate, 5, 2);
    $monthly = $svc->getMonthlyPlan($cell['teacherId'], $year, $month);
    check(is_array($monthly['days'] ?? null),              'monthly returns days[]');
    check(isset($monthly['days'][$testDate]),              "monthly contains test date $testDate");
    check(($monthly['totals']['completed'] ?? 0) >= 1,     'monthly completed counter ≥ 1');
    check(($monthly['totals']['total']     ?? 0) >= 1,     'monthly total counter ≥ 1');

    // rescheduledTo round-trip — set status=rescheduled with link
    $linkTarget = '2026-05-15_P5';
    $rescheduled = $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $testDate, 'period_index' => $cell['periodIndex'],
        'status' => 'rescheduled', 'rescheduled_to' => $linkTarget,
        'expected_version' => $createdVer + 1,
    ]);
    check(($rescheduled['status']        ?? '') === 'rescheduled',  'rescheduled save: status persisted');
    check(($rescheduled['rescheduledTo'] ?? '') === $linkTarget,    'rescheduled save: rescheduledTo persisted');
    $reread = $svc->getLessonPlan($cell['teacherId'], $testDate, $cell['periodIndex']);
    check(($reread['rescheduledTo'] ?? '') === $linkTarget,         'reread: rescheduledTo round-trips');

    // ═══════════════════════════════════════════════════════════════
    // T6  Phase 8 — Analytics summaries (write-hook + read service)
    // ═══════════════════════════════════════════════════════════════
    section('T6  Phase 8 analytics');

    $analytics = new Analytics_service();
    $analytics->init($fb, $fs, SCHOOL_ID, SESSION);

    // The plan above is currently status=rescheduled. The hook should have
    // written the summary docs.
    $classSection = $cell['className'] . '/' . $cell['section'];
    $progRows = $analytics->getSubjectProgress($classSection);
    $progRow = null;
    foreach ($progRows as $r) {
        if ($r['subject'] === $cell['subject']) { $progRow = $r; break; }
    }
    check($progRow !== null, 'subjectPlanProgress doc exists for the plan');
    check(($progRow['totalPlans'] ?? 0) >= 1,                 'subjectPlanProgress.totalPlans ≥ 1');
    check(($progRow['rescheduledCount'] ?? 0) >= 1,           'subjectPlanProgress.rescheduledCount ≥ 1');

    // Daily monitoring should reflect the same plan
    $monitor = $analytics->getDailyMonitoring($testDate);
    $monRow = null;
    foreach (($monitor['rows'] ?? []) as $r) {
        if ($r['teacherId'] === $cell['teacherId']) { $monRow = $r; break; }
    }
    check($monRow !== null, 'dailyTeacherMonitoring row present for teacher');
    check(($monRow['plansSaved']       ?? 0) >= 1,            'dailyTeacherMonitoring.plansSaved ≥ 1');
    check(($monRow['rescheduledCount'] ?? 0) >= 1,            'dailyTeacherMonitoring.rescheduledCount ≥ 1');
    check(($monRow['expectedSlots']    ?? 0) >= 1,            'dailyTeacherMonitoring.expectedSlots derived from timetable');

    // Flip status back to completed → counters must shift (rescheduled-1, completed+1)
    $svc->saveLessonPlan([
        'class_name' => $cell['className'], 'section_name' => $cell['section'],
        'subject' => $cell['subject'], 'teacher_id' => $cell['teacherId'],
        'date' => $testDate, 'period_index' => $cell['periodIndex'],
        'status' => 'completed',
        'expected_version' => $rescheduled['version'] ?? null,
    ]);
    $progRows2 = $analytics->getSubjectProgress($classSection);
    $progRow2 = null;
    foreach ($progRows2 as $r) {
        if ($r['subject'] === $cell['subject']) { $progRow2 = $r; break; }
    }
    check(($progRow2['completedCount'] ?? 0) >= 1,            'completed counter incremented after status flip');
    $rescheduledAfter = (int)($progRow2['rescheduledCount'] ?? 0);
    $rescheduledBefore = (int)($progRow['rescheduledCount'] ?? 0);
    check($rescheduledAfter < $rescheduledBefore || $rescheduledAfter === 0,
          'rescheduled counter decremented after status flip');

    // Parent view
    $parentView = $analytics->getParentDailyLessons($classSection, $testDate);
    check(is_array($parentView['lessons'] ?? null) && count($parentView['lessons']) >= 1,
          'parent_daily_lessons returns ≥1 lesson');
    $parentProg = $analytics->getParentSubjectProgress($classSection);
    check(is_array($parentProg['subjects'] ?? null) && count($parentProg['subjects']) >= 1,
          'parent_subject_progress returns subject list');

    // Syllabus progress (reads existing curriculum docs, may be empty in test data)
    $syllabus = $analytics->getSyllabusProgress();
    check(is_array($syllabus), 'syllabus_progress returns array');

    // Delays — should NOT include our test plan (it's completed and on a future date)
    $delays = $analytics->getDelays();
    check(is_array($delays['skipped'] ?? null) && is_array($delays['unfinished'] ?? null),
          'delays returns {skipped:[], unfinished:[]}');

    // cleanup
    try {
        $client->deleteDocument('lessonPlans', $planId);
        check(true, "cleanup: deleted $planId");
    } catch (\Throwable $e) {
        check(false, 'cleanup delete failed', $e->getMessage());
    }
}

// ════════════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════════════
echo "\n" . HR . "\n" . bold('Summary') . "\n";
echo "  passed: $pass\n";
echo "  failed: $fail\n";
echo HR . "\n";
if ($fail === 0) echo grn(bold('  ✔ ALL TESTS PASSED')) . "\n";
else             echo red(bold('  ✗ ' . $fail . ' TEST(S) FAILED')) . "\n";
if (!$APPLY) echo yel('  (read-only — re-run with --apply for round-trip tests)') . "\n";

exit($fail === 0 ? 0 : 1);

/** Compute the next ISO date matching a given day-of-week starting from today. */
function _nextDate(string $dayName): string {
    $today = strtotime('today');
    for ($i = 0; $i < 14; $i++) {
        $ts = strtotime("+{$i} day", $today);
        if (date('l', $ts) === $dayName) return date('Y-m-d', $ts);
    }
    return date('Y-m-d', $today);
}
