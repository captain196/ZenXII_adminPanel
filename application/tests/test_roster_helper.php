<?php
/**
 * Standalone test harness for Roster_helper.
 *
 * Stubs BASEPATH + log_message and uses a FakeFirestore that records the
 * schoolWhere() / get() / docId() calls and returns canned data, so tests
 * never hit Firestore.
 *
 * Usage:  php application/tests/test_roster_helper.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/..');
if (!function_exists('log_message')) {
    function log_message($level, $msg) { /* no-op */ }
}

// Stub the static normalisers from Firestore_service so we don't have to
// load the real library (which depends on the Firebase SDK + composer).
// These mirror Firestore_service::classKey()/sectionKey() one-for-one.
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

/** Fake Firestore_service that records calls and returns canned data. */
class FakeFs
{
    public $schoolId   = 'SCH_TEST_001';
    public $session    = '2025-26';
    public $whereCalls = [];
    public $getCalls   = [];
    public $whereResult = [];
    public $getResult   = null;

    public function schoolWhere(string $collection, array $extraConditions = [])
    {
        $this->whereCalls[] = [$collection, $extraConditions];
        return $this->whereResult;
    }
    public function get(string $collection, string $docId): ?array
    {
        $this->getCalls[] = [$collection, $docId];
        return $this->getResult;
    }
    public function docId(string $entityId): string
    {
        return "{$this->schoolId}_{$entityId}";
    }
}

// ─── Test runner ───────────────────────────────────────────────────────
$passed = 0; $failed = 0; $failures = [];
function ok($n) { global $passed; $passed++; echo "  PASS  {$n}\n"; }
function fail($n, $w) { global $failed, $failures; $failed++; $failures[] = "{$n}: {$w}"; echo "  FAIL  {$n}\n        {$w}\n"; }
function assertEq($n, $e, $a) { if ($e === $a) ok($n); else fail($n, 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function assertHasKey($n, $k, $a) { if (is_array($a) && array_key_exists($k, $a)) ok($n); else fail($n, "key '{$k}' missing"); }
function assertCount($n, $e, $a) { $c = is_array($a) ? count($a) : -1; if ($c === $e) ok($n); else fail($n, "expected count {$e}, got {$c}"); }

function makeHelper() {
    $fs = new FakeFs();
    $h  = new Roster_helper();
    $h->init($fs);
    return [$h, $fs];
}

echo "\n=== Roster_helper tests ===\n\n";

// ─── 1. for_class basic shape & query ─────────────────────────────────
echo "[1] for_class — query construction\n";
[$h, $fs] = makeHelper();
$fs->whereResult = [
    ['id' => 'SCH_TEST_001_STU0002', 'data' => [
        'userId' => 'STU0002', 'name' => 'Alice', 'rollNo' => '2',
        'className' => 'Class 10th', 'section' => 'Section A',
        'phone' => '999', 'email' => 'a@x', 'profilePic' => 'http://a.jpg',
        'parentDbKey' => '10005', 'status' => 'Active',
    ]],
    ['id' => 'SCH_TEST_001_STU0001', 'data' => [
        'userId' => 'STU0001', 'name' => 'Bob', 'rollNo' => '1',
        'className' => 'Class 10th', 'section' => 'Section A',
        'status' => 'Active',
    ]],
];
$out = $h->for_class('Class 10th', 'Section A');

assertEq('schoolWhere called once', 1, count($fs->whereCalls));
assertEq("collection = 'students'", 'students', $fs->whereCalls[0][0]);
$conds = $fs->whereCalls[0][1];
assertEq('3 extra conditions', 3, count($conds));
assertEq("cond[0] is className", ['className', '==', 'Class 10th'], $conds[0]);
assertEq("cond[1] is section",   ['section',   '==', 'Section A'],   $conds[1]);
assertEq("cond[2] is status",    ['status',    '==', 'Active'],      $conds[2]);

// ─── 2. Output shape & RollNo sort ────────────────────────────────────
echo "\n[2] Output shape & sort order\n";
assertCount('two students returned', 2, $out);
assertHasKey('STU0001 keyed by bare id', 'STU0001', $out);
assertHasKey('STU0002 keyed by bare id', 'STU0002', $out);
$first = array_keys($out)[0];
assertEq('Bob (Roll 1) before Alice (Roll 2)', 'STU0001', $first);
assertEq('Alice fields populated', 'Alice', $out['STU0002']['Name']);
assertEq('Alice phone', '999', $out['STU0002']['phone']);
assertEq('Alice profilePic', 'http://a.jpg', $out['STU0002']['profilePic']);
assertEq('Bob has empty RollNo? no, has 1', '1', $out['STU0001']['RollNo']);

// ─── 3. Class/Section normalisation ───────────────────────────────────
echo "\n[3] Title-Case input is normalised by classKey/sectionKey\n";
[$h, $fs] = makeHelper();
$fs->whereResult = [];
$h->for_class('10th', 'A'); // no prefix
assertEq("classKey normalised to 'Class 10th'", ['className', '==', 'Class 10th'], $fs->whereCalls[0][1][0]);
assertEq("sectionKey normalised to 'Section A'", ['section', '==', 'Section A'], $fs->whereCalls[0][1][1]);

// ─── 4. Empty inputs short-circuit ────────────────────────────────────
echo "\n[4] Empty class/section returns [] without query\n";
[$h, $fs] = makeHelper();
$out = $h->for_class('', 'Section A');
assertEq('no query made (blank class)', 0, count($fs->whereCalls));
assertEq('returns empty array', [], $out);

[$h, $fs] = makeHelper();
$out = $h->for_class('Class 10th', '');
assertEq('no query made (blank section)', 0, count($fs->whereCalls));
assertEq('returns empty array', [], $out);

// ─── 5. ready=false short-circuits ────────────────────────────────────
echo "\n[5] Helper rejects calls before init\n";
$h = new Roster_helper();
$out = $h->for_class('Class 1', 'Section A');
assertEq('returns [] without init', [], $out);
assertEq('count_for_class returns 0', 0, $h->count_for_class('Class 1', 'Section A'));
assertEq('for_student returns null', null, $h->for_student('STU0001'));

// ─── 6. _pick / _resolve_student_id fallbacks ─────────────────────────
echo "\n[6] studentId resolution falls back to docId prefix-strip\n";
[$h, $fs] = makeHelper();
$fs->whereResult = [
    // No userId/studentId field — must resolve from docId by stripping prefix
    ['id' => 'SCH_TEST_001_STU0099', 'data' => [
        'name' => 'No-Id Doc', 'className' => 'Class 5th', 'section' => 'Section B',
        'status' => 'Active',
    ]],
];
$out = $h->for_class('Class 5th', 'Section B');
assertHasKey('studentId resolved from docId', 'STU0099', $out);
assertEq('Name carried through', 'No-Id Doc', $out['STU0099']['Name']);

// ─── 7. PascalCase legacy keys still accepted ─────────────────────────
echo "\n[7] PascalCase fallback keys (legacy data)\n";
[$h, $fs] = makeHelper();
$fs->whereResult = [
    ['id' => 'SCH_TEST_001_STU0010', 'data' => [
        'studentId' => 'STU0010',
        'Name' => 'Carol', 'Roll No' => '5', 'Class' => 'Class 7th',
        'Section' => 'Section C', 'Phone Number' => '888', 'status' => 'Active',
    ]],
];
$out = $h->for_class('Class 7th', 'Section C');
assertEq('Name from PascalCase', 'Carol', $out['STU0010']['Name']);
assertEq('RollNo from "Roll No"', '5', $out['STU0010']['RollNo']);
assertEq('phone from "Phone Number"', '888', $out['STU0010']['phone']);

// ─── 8. count_for_class ───────────────────────────────────────────────
echo "\n[8] count_for_class\n";
[$h, $fs] = makeHelper();
$fs->whereResult = array_map(
    fn($i) => ['id' => "SCH_TEST_001_STU{$i}", 'data' => ['userId'=>"STU{$i}",'name'=>"S{$i}",'status'=>'Active','className'=>'Class 1','section'=>'Section A']],
    [1,2,3,4,5]
);
assertEq('count = 5', 5, $h->count_for_class('Class 1', 'Section A'));

// ─── 9. for_student ───────────────────────────────────────────────────
echo "\n[9] for_student lookup\n";
[$h, $fs] = makeHelper();
$fs->getResult = [
    'userId' => 'STU0001', 'name' => 'Dave', 'rollNo' => '7',
    'className' => 'Class 8th', 'section' => 'Section A',
    'status' => 'Active',
];
$one = $h->for_student('STU0001');
assertEq("get called with collection 'students'", 'students', $fs->getCalls[0][0]);
assertEq('docId is {schoolId}_{stuId}', 'SCH_TEST_001_STU0001', $fs->getCalls[0][1]);
assertEq('Name returned', 'Dave', $one['Name']);

// ─── 10. for_student rejects non-Active ───────────────────────────────
echo "\n[10] for_student rejects non-Active students\n";
[$h, $fs] = makeHelper();
$fs->getResult = ['userId'=>'STU_X', 'name'=>'Withdrawn', 'status'=>'Inactive'];
assertEq('Inactive → null', null, $h->for_student('STU_X'));

[$h, $fs] = makeHelper();
$fs->getResult = ['userId'=>'STU_TC', 'name'=>'Transferred', 'status'=>'TC'];
assertEq('TC → null', null, $h->for_student('STU_TC'));

// ─── 11. for_student tolerates blank status (treats as Active) ────────
echo "\n[11] for_student with blank/missing status passes through\n";
[$h, $fs] = makeHelper();
$fs->getResult = ['userId'=>'STU_OLD', 'name'=>'Legacy', /* no status */];
$o = $h->for_student('STU_OLD');
assertEq('blank status → returns the record', 'Legacy', $o['Name']);

// ─── 12. is_active ────────────────────────────────────────────────────
echo "\n[12] is_active wrapper\n";
[$h, $fs] = makeHelper();
$fs->getResult = ['userId'=>'X', 'name'=>'Y', 'status'=>'Active'];
assertEq('Active → true', true, $h->is_active('X'));
$fs->getResult = null;
assertEq('missing → false', false, $h->is_active('X'));

// ─── 13. Empty result set ─────────────────────────────────────────────
echo "\n[13] Empty result set is benign\n";
[$h, $fs] = makeHelper();
$fs->whereResult = [];
$out = $h->for_class('Class 12th', 'Section Z');
assertEq('empty class returns []', [], $out);
assertEq('count is 0', 0, $h->count_for_class('Class 12th', 'Section Z'));

// ─── 14. Exception in schoolWhere is swallowed → [] ───────────────────
echo "\n[14] schoolWhere exception → empty array (no leak to caller)\n";
class ThrowingFs extends FakeFs {
    public function schoolWhere(string $c, array $x = []) { throw new RuntimeException('boom'); }
}
$tf = new ThrowingFs();
$h = new Roster_helper();
$h->init($tf);
$out = $h->for_class('Class 1', 'Section A');
assertEq('exception caught → []', [], $out);

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
