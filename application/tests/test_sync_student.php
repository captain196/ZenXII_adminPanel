<?php
/**
 * Standalone test harness for Entity_firestore_sync::syncStudent().
 *
 * Runs out of CodeIgniter — stubs BASEPATH + log_message, fakes the
 * Firebase library so firestoreSet() records the doc payload instead
 * of writing it. Exercises every call-site shape used by Sis.php and
 * asserts the partial-merge contract holds: missing/blank/null inputs
 * MUST NOT appear in the patch.
 *
 * Usage:  php application/tests/test_sync_student.php
 */

define('BASEPATH', __DIR__);
if (!function_exists('log_message')) {
    function log_message($level, $msg) { /* no-op */ }
}

require __DIR__ . '/../libraries/Entity_firestore_sync.php';

/** Fake Firebase that captures the last firestoreSet() call. */
class FakeFirebase
{
    public $lastCollection;
    public $lastDocId;
    public $lastData;
    public $lastMerge;
    public $callCount = 0;

    public function firestoreSet($collection, $docId, $data, $merge = false)
    {
        $this->lastCollection = $collection;
        $this->lastDocId      = $docId;
        $this->lastData       = $data;
        $this->lastMerge      = $merge;
        $this->callCount++;
        return true;
    }

    public $lastDeleteCollection;
    public $lastDeleteDocId;
    public $deleteCallCount = 0;

    public function firestoreDelete($collection, $docId)
    {
        $this->lastDeleteCollection = $collection;
        $this->lastDeleteDocId      = $docId;
        $this->deleteCallCount++;
        return true;
    }
}

// ─── Test runner ───────────────────────────────────────────────────────
$passed = 0;
$failed = 0;
$failures = [];

function ok($name) {
    global $passed;
    $passed++;
    echo "  PASS  {$name}\n";
}
function fail($name, $why) {
    global $failed, $failures;
    $failed++;
    $failures[] = "{$name}: {$why}";
    echo "  FAIL  {$name}\n        {$why}\n";
}

function assertEq($name, $expected, $actual) {
    if ($expected === $actual) { ok($name); return; }
    fail($name, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function assertHasKey($name, $key, $arr) {
    if (is_array($arr) && array_key_exists($key, $arr)) { ok($name); return; }
    fail($name, "key '{$key}' missing from " . var_export($arr, true));
}
function assertNoKey($name, $key, $arr) {
    if (is_array($arr) && !array_key_exists($key, $arr)) { ok($name); return; }
    fail($name, "key '{$key}' should be ABSENT but is present (value=" . var_export($arr[$key] ?? null, true) . ")");
}
function assertKeyEq($name, $key, $expected, $arr) {
    if (!is_array($arr) || !array_key_exists($key, $arr)) {
        fail($name, "key '{$key}' missing"); return;
    }
    if ($arr[$key] === $expected) { ok($name); return; }
    fail($name, "key '{$key}' expected " . var_export($expected, true) . ", got " . var_export($arr[$key], true));
}

function makeSync($fb) {
    $sync = new Entity_firestore_sync();
    $sync->init($fb, 'SCH_TEST_001', '2025-26', '10005');
    return $sync;
}

echo "\n=== syncStudent() partial-merge contract tests ===\n\n";

// ─── 1. Identity invariants & merge flag ──────────────────────────────
echo "[1] Identity invariants on every call\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0001', ['Name' => 'Alice']);

assertEq('writes to students collection', 'students', $fb->lastCollection);
assertEq('docId uses {schoolId}_{studentId}', 'SCH_TEST_001_STU0001', $fb->lastDocId);
assertEq('merge flag is true', true, $fb->lastMerge);
assertKeyEq('schoolId always set', 'schoolId', 'SCH_TEST_001', $fb->lastData);
assertKeyEq('schoolCode always set', 'schoolCode', '10005', $fb->lastData);
assertKeyEq('studentId always set', 'studentId', 'STU0001', $fb->lastData);
assertKeyEq('userId always set', 'userId', 'STU0001', $fb->lastData);
assertKeyEq('parentDbKey always set', 'parentDbKey', '10005', $fb->lastData);
assertKeyEq('session always set', 'session', '2025-26', $fb->lastData);
assertHasKey('updatedAt always set', 'updatedAt', $fb->lastData);

// ─── 2. The bug fix: name-only update does NOT wipe other fields ──────
echo "\n[2] name-only update emits ONLY name (the original bug)\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0001', ['Name' => 'Alice Updated']);

assertKeyEq('name is set', 'name', 'Alice Updated', $fb->lastData);
foreach (['dob','gender','category','bloodGroup','religion','nationality',
          'className','section','classOrder','sectionCode','sectionKey',
          'rollNo','admissionDate','phone','phoneNumber','email','address',
          'fatherName','fatherOccupation','motherName','motherOccupation',
          'guardContact','guardRelation','preClass','preSchool','preMarks',
          'profilePic','documents','status'] as $k) {
    assertNoKey("'{$k}' NOT emitted on partial Name-only update", $k, $fb->lastData);
}

// ─── 3. Status-only update (change_status) ────────────────────────────
echo "\n[3] Status-only update — change_status() shape\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0001', ['Status' => 'Inactive']);

assertKeyEq('status set to Inactive', 'status', 'Inactive', $fb->lastData);
assertNoKey('name NOT emitted', 'name', $fb->lastData);
assertNoKey('className NOT emitted', 'className', $fb->lastData);
assertNoKey('phone NOT emitted', 'phone', $fb->lastData);
assertNoKey('address NOT emitted', 'address', $fb->lastData);

// ─── 4. TC issue / cancel / withdraw shape ────────────────────────────
echo "\n[4] TC issue shape — Name+Class+Section+Status\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0001', [
    'Name'    => 'Bob',
    'Class'   => 'Class 10th',
    'Section' => 'Section A',
    'Status'  => 'TC',
]);

assertKeyEq('name set', 'name', 'Bob', $fb->lastData);
assertKeyEq('className normalised', 'className', 'Class 10th', $fb->lastData);
assertKeyEq('section normalised', 'section', 'Section A', $fb->lastData);
assertKeyEq('classOrder set', 'classOrder', 10, $fb->lastData);
assertKeyEq('sectionCode set', 'sectionCode', 'A', $fb->lastData);
assertKeyEq('sectionKey set', 'sectionKey', 'Class 10th/Section A', $fb->lastData);
assertKeyEq('status set to TC', 'status', 'TC', $fb->lastData);
assertNoKey('address preserved (NOT in patch)', 'address', $fb->lastData);
assertNoKey('fatherName preserved', 'fatherName', $fb->lastData);
assertNoKey('motherName preserved', 'motherName', $fb->lastData);
assertNoKey('dob preserved', 'dob', $fb->lastData);

// ─── 5. Full save_student shape ───────────────────────────────────────
echo "\n[5] Full save_student shape — all fields populated\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0002', [
    'Name'              => 'Carol',
    'DOB'               => '2010-05-15',
    'Admission Date'    => '2025-04-01',
    'Class'             => 'Class 8th',
    'Section'           => 'Section B',
    'Phone Number'      => '9988776655',
    'Email'             => 'carol@example.com',
    'Category'          => 'GEN',
    'Gender'            => 'F',
    'Blood Group'       => 'O+',
    'Religion'          => 'Hindu',
    'Nationality'       => 'Indian',
    'Father Name'       => 'David',
    'Father Occupation' => 'Engineer',
    'Mother Name'       => 'Eve',
    'Mother Occupation' => 'Doctor',
    'Guard Contact'     => '8877665544',
    'Guard Relation'    => 'Uncle',
    'Pre Class'         => 'Class 7th',
    'Pre School'        => 'Old School',
    'Pre Marks'         => '85%',
    'Address'           => ['Street'=>'1 Main','City'=>'Delhi'],
    'Profile Pic'       => 'http://x/pic.jpg',
    'Doc'               => ['Photo' => ['url'=>'http://x/p.jpg']],
    'Roll No'           => '12',
    'Status'            => 'Active',
]);

assertKeyEq('name', 'name', 'Carol', $fb->lastData);
assertKeyEq('dob', 'dob', '2010-05-15', $fb->lastData);
assertKeyEq('gender', 'gender', 'F', $fb->lastData);
assertKeyEq('phone (canonical)', 'phone', '9988776655', $fb->lastData);
assertKeyEq('phoneNumber (back-compat mirror)', 'phoneNumber', '9988776655', $fb->lastData);
assertKeyEq('email', 'email', 'carol@example.com', $fb->lastData);
assertKeyEq('fatherName', 'fatherName', 'David', $fb->lastData);
assertKeyEq('motherName', 'motherName', 'Eve', $fb->lastData);
assertKeyEq('className', 'className', 'Class 8th', $fb->lastData);
assertKeyEq('section', 'section', 'Section B', $fb->lastData);
assertKeyEq('rollNo', 'rollNo', '12', $fb->lastData);
assertKeyEq('status', 'status', 'Active', $fb->lastData);
assertKeyEq('profilePic', 'profilePic', 'http://x/pic.jpg', $fb->lastData);
assertHasKey('documents present', 'documents', $fb->lastData);
assertEq('address (nested array preserved)', ['Street'=>'1 Main','City'=>'Delhi'], $fb->lastData['address']);

// ─── 6. Empty string inputs do NOT clear ──────────────────────────────
echo "\n[6] Empty-string inputs must NOT wipe (preserve existing)\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0003', [
    'Name' => 'Frank',
    'DOB'  => '',
    'Gender' => '',
    'Father Name' => '',
    'Address' => '',
]);

assertKeyEq('name (provided)', 'name', 'Frank', $fb->lastData);
assertNoKey('blank DOB skipped', 'dob', $fb->lastData);
assertNoKey('blank Gender skipped', 'gender', $fb->lastData);
assertNoKey('blank Father Name skipped', 'fatherName', $fb->lastData);
assertNoKey('blank Address skipped', 'address', $fb->lastData);

// ─── 7. Null inputs do NOT clear ──────────────────────────────────────
echo "\n[7] Null inputs must NOT wipe\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0004', [
    'Name'    => 'Grace',
    'DOB'     => null,
    'Gender'  => null,
    'Address' => null,
]);

assertKeyEq('name (provided)', 'name', 'Grace', $fb->lastData);
assertNoKey('null DOB skipped', 'dob', $fb->lastData);
assertNoKey('null Gender skipped', 'gender', $fb->lastData);
assertNoKey('null Address skipped', 'address', $fb->lastData);

// ─── 8. CamelCase aliases accepted ────────────────────────────────────
echo "\n[8] camelCase aliases work the same as Title Case\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0005', [
    'name'        => 'Helen',
    'dob'         => '2011-01-01',
    'fatherName'  => 'Ivan',
    'phoneNumber' => '7766554433',
    'className'   => 'Class 5th',
    'section'     => 'Section C',
]);

assertKeyEq('camelCase name', 'name', 'Helen', $fb->lastData);
assertKeyEq('camelCase dob', 'dob', '2011-01-01', $fb->lastData);
assertKeyEq('camelCase fatherName', 'fatherName', 'Ivan', $fb->lastData);
assertKeyEq('phoneNumber → phone', 'phone', '7766554433', $fb->lastData);
assertKeyEq('phoneNumber mirror', 'phoneNumber', '7766554433', $fb->lastData);
assertKeyEq('className', 'className', 'Class 5th', $fb->lastData);
assertKeyEq('section', 'section', 'Section C', $fb->lastData);

// ─── 9. Class without Section, Section without Class ──────────────────
echo "\n[9] Class-only / Section-only — partial pair still normalises\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0006', ['Class' => 'Class 12th']);
assertKeyEq('Class-only emits className', 'className', 'Class 12th', $fb->lastData);
assertHasKey('Class-only emits section (may be empty)', 'section', $fb->lastData);
assertHasKey('Class-only emits classOrder', 'classOrder', $fb->lastData);
assertEq('Class-only classOrder=12', 12, $fb->lastData['classOrder']);

$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0006', ['Section' => 'Section D']);
assertHasKey('Section-only emits className key', 'className', $fb->lastData);
assertKeyEq('Section-only emits section', 'section', 'Section D', $fb->lastData);

// ─── 10. Profile pic via nested Doc.ProfilePic ────────────────────────
echo "\n[10] profilePic falls back to Doc.ProfilePic\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0007', [
    'Doc' => ['ProfilePic' => 'http://x/pp.jpg', 'Photo' => ['url'=>'http://x/p.jpg']],
]);
assertKeyEq('profilePic from Doc.ProfilePic', 'profilePic', 'http://x/pp.jpg', $fb->lastData);
assertHasKey('documents emitted', 'documents', $fb->lastData);

// ─── 11. Empty Doc array does NOT wipe documents ──────────────────────
echo "\n[11] Empty Doc array is treated as absent\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0008', ['Name' => 'Jack', 'Doc' => []]);
assertKeyEq('name set', 'name', 'Jack', $fb->lastData);
assertNoKey('empty Doc → documents NOT emitted', 'documents', $fb->lastData);

// ─── 12. Phone alias resolution priority ──────────────────────────────
echo "\n[12] Phone alias resolution\n";
foreach ([
    ['Phone Number' => '111111'],
    ['phoneNumber'  => '222222'],
    ['Phone'        => '333333'],
    ['phone'        => '444444'],
] as $i => $input) {
    $fb = new FakeFirebase();
    $sync = makeSync($fb);
    $sync->syncStudent('STU_PH', $input);
    $expected = reset($input);
    assertKeyEq("variant ".key($input)." → phone", 'phone', $expected, $fb->lastData);
    assertKeyEq("variant ".key($input)." → phoneNumber", 'phoneNumber', $expected, $fb->lastData);
}

// ─── 13. Bug-regression scenario: edit one field on existing student ──
echo "\n[13] Regression: update_profile shape with just Phone Number\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
$sync->syncStudent('STU0009', [
    'Phone Number' => '9000000000',
    'updatedAt'    => '2026-04-30T12:00:00+00:00',
]);
assertKeyEq('phone updated', 'phone', '9000000000', $fb->lastData);
assertKeyEq('phoneNumber mirror updated', 'phoneNumber', '9000000000', $fb->lastData);
foreach (['name','dob','gender','address','fatherName','motherName',
          'className','section','status'] as $k) {
    assertNoKey("'{$k}' NOT in patch (preserved on doc)", $k, $fb->lastData);
}

// ─── 14. deleteStudent docId symmetry with syncStudent ────────────────
// Pre-fix this used `{schoolCode}_…` and would orphan the doc when
// schoolId ≠ schoolCode. Now must match `{schoolId}_…`.
echo "\n[14] deleteStudent uses {schoolId}_{studentId} (matches syncStudent)\n";
$fb = new FakeFirebase();
$sync = makeSync($fb);
// First write — confirms syncStudent docId
$sync->syncStudent('STU_DEL_1', ['Name' => 'X']);
$writeDocId = $fb->lastDocId;

// Then delete
$ok = $sync->deleteStudent('STU_DEL_1');
assertEq('delete returns true', true, $ok);
assertEq('firestoreDelete called once', 1, $fb->deleteCallCount);
assertEq("delete collection = 'students'", 'students', $fb->lastDeleteCollection);
assertEq('delete docId matches syncStudent docId', $writeDocId, $fb->lastDeleteDocId);
assertEq('delete docId is {schoolId}_{stuId}', 'SCH_TEST_001_STU_DEL_1', $fb->lastDeleteDocId);

// ─── 15. deleteStudent stays Firestore-only with diverged schoolId/schoolCode ──
echo "\n[15] deleteStudent uses schoolId even when schoolId != schoolCode\n";
$fb = new FakeFirebase();
$sync = new Entity_firestore_sync();
// schoolId="SCH_DIVERGED", schoolCode="10005" (login code, different)
$sync->init($fb, 'SCH_DIVERGED', '2025-26', '10005');
$sync->deleteStudent('STU_X');
assertEq(
    'delete uses schoolId, NOT schoolCode',
    'SCH_DIVERGED_STU_X',
    $fb->lastDeleteDocId
);

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
