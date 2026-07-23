<?php
/**
 * READ-ONLY tenant-gate + assignment inspector.
 * Checks the docs that Firestore rules + the Teacher app read to decide
 * access for a given school / staff member. Does NOT write anything.
 *
 * Usage: php tools/inspect_tenant.php <SCHOOL_ID> [STAFF_ID]
 * Example: php tools/inspect_tenant.php SCH_54D0424022 STA0072
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$root = dirname(__DIR__);
if (!defined('APPPATH')) define('APPPATH', $root . '/application/');
if (!defined('BASEPATH')) define('BASEPATH', $root . '/system/');
require $root . '/vendor/autoload.php';
require $root . '/application/libraries/Firestore_rest_client.php';

$saPath = $root . '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';

if ($argc < 2) { fwrite(STDERR, "Usage: php tools/inspect_tenant.php <SCHOOL_ID> [STAFF_ID]\n"); exit(2); }
$schoolId = trim($argv[1]);
$staffId  = isset($argv[2]) ? trim($argv[2]) : null;

$db = new FirestoreRestClient($saPath, 'graderadmin', '(default)');

function dump($label, $data) {
    echo "── $label ─────────────────────\n";
    if ($data === null) { echo "  <NOT FOUND / null>\n\n"; return; }
    echo "  " . str_replace("\n", "\n  ", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n\n";
}

echo "\n================ TENANT GATE for $schoolId ================\n\n";

$ctrl = $db->getDocument('schoolControl', $schoolId);
dump("schoolControl/$schoolId  (lifecycle.state must be active/trialing/expiring_soon/grace)", $ctrl);

$sch = $db->getDocument('schools', $schoolId);
// only surface the gate-relevant field to keep output small
$adminDisabled = is_array($sch) ? ($sch['adminDisabled'] ?? '(field absent → OK)') : null;
echo "── schools/$schoolId.adminDisabled (true → whole tenant blocked) ──\n  " .
     json_encode($adminDisabled, JSON_UNESCAPED_SLASHES) . "\n\n";

// verdict on tenant gate
$state = is_array($ctrl) ? ($ctrl['lifecycle']['state'] ?? null) : null;
$activeStates = ['active','trialing','expiring_soon','grace'];
echo "TENANT VERDICT: ";
if (!is_array($ctrl))                echo "schoolControl doc MISSING → tenantActive()=false → EVERYONE in this school is denied everywhere.\n";
elseif (!in_array($state, $activeStates, true)) echo "lifecycle.state='" . var_export($state, true) . "' NOT active → EVERYONE denied everywhere.\n";
elseif ($adminDisabled === true || (is_array($adminDisabled) && ($adminDisabled['value'] ?? null) === true)) echo "adminDisabled=true → EVERYONE denied.\n";
else                                 echo "OK — tenant gate is open. 'Denied everywhere' is NOT tenant-wide; it's per-account (stale token) or a specific-module authz.\n";
echo "\n";

if ($staffId) {
    echo "================ STAFF $staffId ================\n\n";
    // staff record — try common collections
    foreach (['staff', 'staffProfiles', 'staff_records', 'users'] as $coll) {
        $doc = $db->getDocument($coll, $schoolId . '_' . $staffId);
        if ($doc === null) $doc = $db->getDocument($coll, $staffId);
        if ($doc !== null) { dump("$coll/{$staffId}", $doc); break; }
    }

    // subjectAssignments — drives student-attendance read-only gate (isClassTeacher)
    try {
        $sa = $db->query('subjectAssignments', [['schoolId','==',$schoolId],['teacherId','==',$staffId]], null, 'ASC', 25);
        echo "── subjectAssignments (schoolId=$schoolId, teacherId=$staffId) → " . count($sa) . " row(s) ──\n";
        if (empty($sa)) echo "  <NONE — teacher has NO subject/class assignments → student-attendance is read-only; not a '403 not permitted'>\n";
        foreach ($sa as $r) {
            $f = $r['fields'] ?? $r;
            echo "  • session=" . json_encode($f['session'] ?? null) .
                 " class=" . json_encode($f['className'] ?? $f['class'] ?? null) .
                 " section=" . json_encode($f['section'] ?? null) .
                 " isClassTeacher=" . json_encode($f['isClassTeacher'] ?? null) .
                 " archived=" . json_encode($f['archived'] ?? null) . "\n";
        }
        echo "\n";
    } catch (\Throwable $e) {
        echo "  subjectAssignments query error: " . $e->getMessage() . "\n\n";
    }
}
echo "===========================================================\n";
