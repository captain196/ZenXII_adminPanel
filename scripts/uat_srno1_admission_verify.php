<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * UAT SRNO-1 ADMISSION — PRE/POST VERIFICATION.  STRICTLY READ-ONLY.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * This script does NOT perform the admission. The admission must be done by
 * the operator through the real /sis/admission UI so the actual controller
 * path (guards, CSRF, RBAC, SRNO-F1) is exercised — simulating it with a
 * script would validate nothing.
 *
 * NO WRITES anywhere. allocateNext() is never called.
 *
 * Usage:
 *   php scripts/uat_srno1_admission_verify.php --pre     # before the admission
 *   php scripts/uat_srno1_admission_verify.php --post    # after the admission
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const UAT  = 'SCH_B56BB9A401';
const SNAP = __DIR__ . '/../UAT_SRNO1_ADMISSION_SNAPSHOT.json';

$MODE = in_array('--post', $argv, true) ? 'post' : 'pre';

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }
$FAIL = 0;
function chk(bool $ok, string $label, string $detail = '') {
    global $FAIL; if (!$ok) $FAIL++;
    printf("  %-7s %-54s %s\n", $ok ? 'PASS' : '*FAIL*', $label, $detail);
}

/** Everything the assertions need, in one read pass. */
$gather = function () use ($fs): array {
    $students = [];
    foreach ($fs->query('students', [['schoolId', '==', UAT]], null, 'ASC', 2000) as $r) {
        $d = body($r);
        $sid = (string) ($d['studentId'] ?? '');
        if ($sid !== '') $students[$sid] = ['srNo' => (string) ($d['srNo'] ?? ''), 'name' => (string) ($d['name'] ?? ''),
                                            'class' => (string) ($d['className'] ?? ''), 'section' => (string) ($d['section'] ?? '')];
    }
    ksort($students);

    $demandsByStudent = []; $noDue = 0;
    foreach ($fs->query('feeDemands', [['schoolId', '==', UAT]], null, 'ASC', 3000) as $r) {
        $d = body($r);
        $sid = (string) ($d['studentId'] ?? '(none)');
        $demandsByStudent[$sid] = ($demandsByStudent[$sid] ?? 0) + 1;
        if (trim((string) ($d['dueDate'] ?? '')) === '') $noDue++;
    }
    ksort($demandsByStudent);

    $idx = [];
    foreach ($fs->query('srNoIndex', [['schoolId', '==', UAT]], null, 'ASC', 500) as $r) {
        $d = body($r); $d['__id'] = (string) ($r['id'] ?? ''); $idx[] = $d;
    }
    $claims = [];
    for ($n = 1; $n <= 25; $n++) {
        if (is_array($fs->getDocument('feeCounters', '_sys_SRNO_' . UAT . '_claim_' . $n))) $claims[] = $n;
    }
    $ptrDoc = $fs->getDocument('feeCounters', '_sys_SRNO_' . UAT);

    $other = [];
    foreach (['feeReceipts', 'scholarshipAwards', 'studentEntitlements', 'admissions', 'accounting', 'accountingLedger'] as $c) {
        $other[$c] = count($fs->query($c, [['schoolId', '==', UAT]], null, 'ASC', 3000));
    }
    $foreign = 0;
    foreach ($fs->query('feeDemands', [], null, 'ASC', 5000) as $r) {
        $d = body($r);
        if ((string) ($d['schoolId'] ?? '') === 'SCH_218AAF5C23') $foreign++;
    }
    return [
        'students' => $students, 'demandsByStudent' => $demandsByStudent, 'demandsNoDueDate' => $noDue,
        'srNoIndex' => $idx, 'claims' => $claims,
        'pointer' => is_array($ptrDoc) ? (int) ($ptrDoc['value'] ?? -1) : null,
        'other' => $other, 'foreignDemands' => $foreign, 'utc' => gmdate('c'),
    ];
};

line('════════════════════════════════════════════════════════════════════');
line(' UAT SRNO-1 ADMISSION VERIFY — ' . strtoupper($MODE) . '   (READ-ONLY)');
line(' tenant=' . UAT . '  utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

$now = $gather();

if ($MODE === 'pre') {
    head('PRECONDITIONS (plan A0)');
    chk($now['pointer'] === null,            'SRNO pointer ABSENT',        $now['pointer'] === null ? 'absent' : 'value=' . $now['pointer']);
    chk(count($now['srNoIndex']) === 0,      'srNoIndex = 0 docs',         (string) count($now['srNoIndex']));
    chk(count($now['claims']) === 0,         'claim_1..25 ABSENT',         $now['claims'] ? implode(',', $now['claims']) : 'none');
    chk(count($now['students']) === 23,      '23 existing students',       (string) count($now['students']));
    $withSr = count(array_filter($now['students'], fn($s) => $s['srNo'] !== ''));
    chk($withSr === 0,                       '0 existing students with srNo', (string) $withSr);
    chk(array_sum($now['demandsByStudent']) === 26, 'feeDemands = 26',     (string) array_sum($now['demandsByStudent']));

    file_put_contents(SNAP, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    line();
    line('  snapshot written: ' . basename(SNAP));
    line($FAIL === 0 ? '  PRECONDITIONS PASS — the operator may perform ONE admission via /sis/admission'
                     : '  *** PRECONDITIONS FAILED — do NOT perform the admission. ***');
    exit($FAIL === 0 ? 0 : 4);
}

// ── POST ────────────────────────────────────────────────────────────────────
$pre = json_decode((string) @file_get_contents(SNAP), true);
if (!is_array($pre)) { line('*** pre-admission snapshot missing — run --pre first. STOP.'); exit(9); }

$newIds = array_values(array_diff(array_keys($now['students']), array_keys($pre['students'])));
$newStu = $newIds[0] ?? '';

head('A2 · THE SRNO CHAIN');
chk(count($newIds) === 1, '1. exactly one new student admitted', $newIds ? implode(',', $newIds) : 'none');
chk($now['pointer'] === 1, '2. pointer value = 1', var_export($now['pointer'], true));
chk(in_array(1, $now['claims'], true), '3. claim_1 exists', $now['claims'] ? implode(',', $now['claims']) : 'none');

$idx1 = null;
foreach ($now['srNoIndex'] as $d) if ((string) ($d['srNo'] ?? '') === '1') $idx1 = $d;
chk(is_array($idx1), '4. srNoIndex/' . UAT . '_1 exists', is_array($idx1) ? $idx1['__id'] : 'missing');
chk(is_array($idx1) && (string) ($idx1['srNo'] ?? '') === '1', '5. record has srNo = 1');
chk(is_array($idx1) && (string) ($idx1['currentStudentId'] ?? '') === $newStu,
    '6. currentStudentId = the new student', is_array($idx1) ? (string) ($idx1['currentStudentId'] ?? '') : '');
chk(is_array($idx1) && !array_key_exists('session', $idx1),
    '7. NO session field (SRNO is school-scoped)', is_array($idx1) && isset($idx1['session']) ? '*** session present' : 'absent');
$hist = is_array($idx1) && is_array($idx1['historicalStudentIds'] ?? null) ? $idx1['historicalStudentIds'] : [];
chk(count($hist) === 0, '8. historicalStudentIds empty (first owner)', (string) count($hist));
chk($newStu !== '' && ($now['students'][$newStu]['srNo'] ?? '') === '1',
    '9. student document carries srNo = 1', $newStu !== '' ? ($now['students'][$newStu]['srNo'] ?: '(empty)') : '');
chk(count($now['srNoIndex']) === 1, '10. exactly ONE srNoIndex doc for the tenant', (string) count($now['srNoIndex']));
chk($now['claims'] === [1], '11. claim_2..25 remain ABSENT', implode(',', $now['claims']));
line('  12. resolve() school + SRNO 1 -> studentId : structurally proven by 4/5/6 above');
line('      (Sr_no_service::resolve() reads exactly srNoIndex/{school}_{srNo}.currentStudentId)');

head('A3 · SIDE EFFECTS — NOTHING ELSE MOVES');
$legacyChanged = [];
foreach ($pre['students'] as $sid => $was) {
    $isNow = $now['students'][$sid] ?? null;
    if ($isNow === null || $isNow !== $was) $legacyChanged[] = $sid;
}
chk(count($legacyChanged) === 0, '13. all 23 legacy students unchanged', $legacyChanged ? implode(',', $legacyChanged) : 'none');
$legacyWithSr = 0;
foreach ($pre['students'] as $sid => $_) if (($now['students'][$sid]['srNo'] ?? '') !== '') $legacyWithSr++;
chk($legacyWithSr === 0, '14. all 23 legacy students still have NO srNo', (string) $legacyWithSr);
chk(($now['demandsByStudent']['STU0012'] ?? 0) === 26, "15. STU0012's 26 demands unchanged",
    (string) ($now['demandsByStudent']['STU0012'] ?? 0));
$newDemands = $now['demandsByStudent'][$newStu] ?? 0;
chk($newDemands > 0, '16. the new student received demands', (string) $newDemands);
$unexpected = array_diff(array_keys($now['demandsByStudent']), array_merge(array_keys($pre['demandsByStudent']), [$newStu]));
chk(count($unexpected) === 0, '17. no demands for any unexpected student', implode(',', $unexpected));
chk($now['demandsNoDueDate'] === 0, '18. every demand has a non-empty dueDate',
    $now['demandsNoDueDate'] . ' missing');
foreach (['feeReceipts' => 19, 'scholarshipAwards' => 20, 'studentEntitlements' => 21,
          'accounting' => 22, 'accountingLedger' => 23] as $c => $num) {
    chk(($now['other'][$c] ?? 0) === ($pre['other'][$c] ?? 0), "{$num}. {$c} unchanged",
        ($now['other'][$c] ?? 0) . ' vs ' . ($pre['other'][$c] ?? 0));
}
chk($now['foreignDemands'] === $pre['foreignDemands'], '24. SCH_218AAF5C23 untouched',
    $now['foreignDemands'] . ' vs ' . $pre['foreignDemands']);

head('VERDICT');
line($FAIL === 0
    ? '  SRNO-1 END-TO-END VALIDATION: PASS. The new student holds SRNO 1.'
    : "  *** {$FAIL} ASSERTION(S) FAILED — report and STOP; do not fix forward. ***");
line('  Section strength (+1) and billing_state=billed: confirm from the admission response/log.');
line('  Rollback: STATIC verification only (code inspection + PhaseSrnoF1AdmissionIdentityTest).');
line('── end · READ-ONLY ──');
exit($FAIL === 0 ? 0 : 4);
