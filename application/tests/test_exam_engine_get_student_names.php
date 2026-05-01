<?php
/**
 * Standalone test for Exam_engine::get_student_names() — R2 migration.
 *
 * Verifies the [uid => name] output contract is preserved when:
 *   1. CI's $CI->roster is available and returns Roster_helper data
 *   2. CI is unavailable (CLI/cron) and the direct firestoreQuery fallback runs
 *
 * Stubs BASEPATH/log_message + a fake Firebase library + a fake $CI instance.
 *
 * Usage:  php application/tests/test_exam_engine_get_student_names.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/..');
if (!function_exists('log_message')) {
    function log_message($level, $msg) { /* no-op */ }
}

// Stub Firestore_service statics that Roster_helper uses.
if (!class_exists('Firestore_service')) {
    class Firestore_service {
        public static function classKey(string $val): string {
            $s = trim($val);
            return ($s !== '' && stripos($s, 'Class ') !== 0) ? "Class {$s}" : $s;
        }
        public static function sectionKey(string $val): string {
            $s = trim($val);
            return ($s !== '' && stripos($s, 'Section ') !== 0) ? "Section {$s}" : $s;
        }
    }
}

require __DIR__ . '/../libraries/Roster_helper.php';
require __DIR__ . '/../libraries/Exam_engine.php';

/** Fake Firestore_service for Roster_helper. */
class FakeFs
{
    public $schoolId   = 'SCH_TEST_001';
    public $whereResult = [];
    public function schoolWhere(string $c, array $x = []) { return $this->whereResult; }
    public function get(string $c, string $d): ?array { return null; }
    public function docId(string $e): string { return "{$this->schoolId}_{$e}"; }
}

/** Fake Firebase that the engine's fallback path uses. */
class FakeFirebase
{
    public $firestoreQueryArgs = [];
    public $firestoreQueryResult = [];
    public function firestoreQuery(string $col, array $conds = [], $orderBy = null, $direction = 'ASC', $limit = null): array {
        $this->firestoreQueryArgs[] = [$col, $conds];
        return $this->firestoreQueryResult;
    }
}

/** Fake CI controller exposing $this->roster. */
class FakeCI
{
    public $roster;
    public function __construct($r) { $this->roster = $r; }
}

if (!function_exists('get_instance')) {
    function get_instance() { global $__CI_FAKE; return $__CI_FAKE; }
}

// ─── Runner ────────────────────────────────────────────────────────────
$passed = 0; $failed = 0; $failures = [];
function ok($n) { global $passed; $passed++; echo "  PASS  {$n}\n"; }
function fail($n, $w) { global $failed, $failures; $failed++; $failures[] = "{$n}: {$w}"; echo "  FAIL  {$n}\n        {$w}\n"; }
function assertEq($n, $e, $a) { if ($e === $a) ok($n); else fail($n, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function assertHasKey($n, $k, $a) { if (is_array($a) && array_key_exists($k, $a)) ok($n); else fail($n, "key '{$k}' missing"); }

echo "\n=== Exam_engine::get_student_names tests ===\n\n";

// ─── 1. Happy path via CI->roster ─────────────────────────────────────
echo "[1] \$CI->roster path returns [uid => name]\n";
global $__CI_FAKE;
$fs = new FakeFs();
$fs->whereResult = [
    ['id'=>'SCH_TEST_001_STU0001', 'data'=>['userId'=>'STU0001','name'=>'Alice','rollNo'=>'1','className'=>'Class 9th','section'=>'Section A','status'=>'Active']],
    ['id'=>'SCH_TEST_001_STU0002', 'data'=>['userId'=>'STU0002','name'=>'Bob',  'rollNo'=>'2','className'=>'Class 9th','section'=>'Section A','status'=>'Active']],
];
$roster = (new Roster_helper())->init($fs);
$__CI_FAKE = new FakeCI($roster);

$fb  = new FakeFirebase();
$eng = new Exam_engine();
$eng->init($fb, 'SCH_TEST_001', '2025-26');

$names = $eng->get_student_names('Class 9th', 'Section A');
assertEq('returns [uid => name] map (2 entries)', 2, count($names));
assertEq('Alice keyed by uid', 'Alice', $names['STU0001'] ?? null);
assertEq('Bob keyed by uid',   'Bob',   $names['STU0002'] ?? null);
assertEq('values are strings', 'string', gettype($names['STU0001']));
assertEq('did NOT touch firestoreQuery (CI path used)', 0, count($fb->firestoreQueryArgs));

// ─── 2. Empty roster ──────────────────────────────────────────────────
echo "\n[2] Empty roster returns []\n";
$fs->whereResult = [];
$names = $eng->get_student_names('Class 9th', 'Section A');
assertEq('empty array', [], $names);

// ─── 3. Title-Case input is normalised ────────────────────────────────
echo "\n[3] Bare class/section input passes through normaliser\n";
$fs->whereResult = [
    ['id'=>'SCH_TEST_001_STU0003', 'data'=>['userId'=>'STU0003','name'=>'Carol','className'=>'Class 5th','section'=>'Section B','status'=>'Active']],
];
$names = $eng->get_student_names('5th', 'B'); // unprefixed
assertEq("Carol present", 'Carol', $names['STU0003'] ?? null);

// ─── 4. CI->roster missing → fallback to firestoreQuery ───────────────
echo "\n[4] No \$CI->roster → direct firestoreQuery fallback\n";
$__CI_FAKE = new stdClass(); // no ->roster property

$fb = new FakeFirebase();
$fb->firestoreQueryResult = [
    ['id'=>'SCH_TEST_001_STU0010', 'data'=>['userId'=>'STU0010','name'=>'Dave','className'=>'Class 8th','section'=>'Section A','status'=>'Active']],
    ['id'=>'SCH_TEST_001_STU0011', 'data'=>['name'=>'Eve','className'=>'Class 8th','section'=>'Section A','status'=>'Active']], // no userId, must strip prefix
];
$eng = new Exam_engine();
$eng->init($fb, 'SCH_TEST_001', '2025-26');
$names = $eng->get_student_names('Class 8th', 'Section A');

assertEq('firestoreQuery called once', 1, count($fb->firestoreQueryArgs));
$call = $fb->firestoreQueryArgs[0];
assertEq("collection = 'students'", 'students', $call[0]);
$conds = $call[1];
assertEq('4 conditions', 4, count($conds));
assertEq('cond[0] schoolId',  ['schoolId',  '==', 'SCH_TEST_001'], $conds[0]);
assertEq('cond[1] className', ['className', '==', 'Class 8th'],    $conds[1]);
assertEq('cond[2] section',   ['section',   '==', 'Section A'],    $conds[2]);
assertEq('cond[3] status',    ['status',    '==', 'Active'],       $conds[3]);

assertEq('Dave present', 'Dave', $names['STU0010'] ?? null);
assertEq('Eve uid resolved by prefix-strip', 'Eve', $names['STU0011'] ?? null);

// ─── 5. PascalCase legacy data via fallback ──────────────────────────
echo "\n[5] Fallback handles legacy PascalCase Name field\n";
$fb = new FakeFirebase();
$fb->firestoreQueryResult = [
    ['id'=>'SCH_TEST_001_STU0020', 'data'=>['userId'=>'STU0020', 'Name'=>'Frank']],
];
$eng = new Exam_engine();
$eng->init($fb, 'SCH_TEST_001', '2025-26');
$names = $eng->get_student_names('Class 1', 'Section A');
assertEq('PascalCase Name picked up', 'Frank', $names['STU0020'] ?? null);

// ─── 6. Empty class/section short-circuits ────────────────────────────
echo "\n[6] Blank class or section returns []\n";
$fb = new FakeFirebase();
$eng = new Exam_engine();
$eng->init($fb, 'SCH_TEST_001', '2025-26');
assertEq('blank class', [], $eng->get_student_names('', 'Section A'));
assertEq('blank section', [], $eng->get_student_names('Class 1', ''));
assertEq('no firestoreQuery call', 0, count($fb->firestoreQueryArgs));

// ─── 7. firestoreQuery exception → [] (no leak) ───────────────────────
echo "\n[7] firestoreQuery throws → []\n";
class ThrowingFb extends FakeFirebase {
    public function firestoreQuery(string $c, array $x = [], $o = null, $d = 'ASC', $l = null): array {
        throw new RuntimeException('boom');
    }
}
$eng = new Exam_engine();
$eng->init(new ThrowingFb(), 'SCH_TEST_001', '2025-26');
$names = $eng->get_student_names('Class 1', 'Section A');
assertEq('exception swallowed', [], $names);

// ─── Summary ──────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    exit(1);
}
echo "\nAll tests passed.\n";
exit(0);
