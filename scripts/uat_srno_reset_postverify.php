<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * UAT SRNO RESET — POST-VERIFICATION + READ-ONLY "NEXT IS SRNO 1" PROOF
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Separate file, separate process, separate client from the reset script.
 * The reset tool's own post-check is NOT evidence (the R5 lesson).
 *
 * STRICTLY READ-ONLY. No deleteDocument / setDocument / updateDocument /
 * createDocument / commitBatch anywhere. allocateNext() is NEVER called —
 * a live allocation would WRITE claim_1 + srNoIndex_1 + a student srNo and
 * consume SRNO 1, which is exactly what must NOT happen. The proof below is
 * derived from live state plus the allocator's own source.
 *
 * Usage:  php scripts/uat_srno_reset_postverify.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const UAT = 'SCH_B56BB9A401';

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
    printf("  %-7s %-50s %s\n", $ok ? 'PASS' : '*FAIL*', $label, $detail);
}

$snap = json_decode((string) @file_get_contents(__DIR__ . '/../UAT_SRNO_RESET_SNAPSHOT.json'), true);
if (!is_array($snap)) { line('*** snapshot missing — cannot reconcile. STOP.'); exit(9); }

line('════════════════════════════════════════════════════════════════════');
line(' UAT SRNO RESET — POST-VERIFICATION (READ-ONLY, FRESH PROCESS)');
line(' tenant=' . UAT . '  utc=' . gmdate('c'));
line(' baseline snapshot utc=' . ($snap['utc'] ?? '?'));
line('════════════════════════════════════════════════════════════════════');

// ── 1-3 · allocator state ───────────────────────────────────────────────────
head('ALLOCATOR STATE');

$idxAll = $fs->query('srNoIndex', [], null, 'ASC', 3000);
$idxUat = 0; $idxOther = [];
foreach ($idxAll as $r) {
    $d = body($r);
    if ((string) ($d['schoolId'] ?? '') === UAT) $idxUat++;
    else $idxOther[(string) ($d['schoolId'] ?? '(none)')] = true;
}
chk($idxUat === 0, 'srNoIndex for tenant = 0', "found {$idxUat}");
chk(count($idxAll) === 0, 'srNoIndex platform-wide dropped 16 -> 0', 'found ' . count($idxAll));

$ptr = $fs->getDocument('feeCounters', '_sys_SRNO_' . UAT);
chk(!is_array($ptr), 'SRNO pointer ABSENT', is_array($ptr) ? 'value=' . ($ptr['value'] ?? '?') : 'absent');

$still = [];
for ($n = 1; $n <= 25; $n++) {
    if (is_array($fs->getDocument('feeCounters', '_sys_SRNO_' . UAT . '_claim_' . $n))) $still[] = $n;
}
chk(count($still) === 0, 'claim_1 .. claim_25 all ABSENT', $still ? 'present: ' . implode(',', $still) : 'none');

// ── 4-5 · students untouched ────────────────────────────────────────────────
head('STUDENTS — MUST BE UNCHANGED');

$now = [];
foreach ($fs->query('students', [['schoolId', '==', UAT]], null, 'ASC', 2000) as $r) {
    $d = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $now[$sid] = (string) ($d['srNo'] ?? ($d['srNumber'] ?? ''));
}
ksort($now);
$withSr = count(array_filter($now, fn($v) => $v !== ''));
chk(count($now) === (int) $snap['studentCount'], 'student count unchanged',
    count($now) . ' vs baseline ' . $snap['studentCount']);
chk(array_keys($now) === $snap['studentIds'], 'student ids identical to baseline',
    'set comparison');
chk($withSr === 0, 'all students still have NO srNo', "withSrNo={$withSr}");

// ── 6-10 · dependent collections ────────────────────────────────────────────
head('DEPENDENT COLLECTIONS — MUST BE UNCHANGED');
foreach ($snap['collections'] as $coll => $was) {
    $isNow = count($fs->query($coll, [['schoolId', '==', UAT]], null, 'ASC', 3000));
    chk($isNow === (int) $was, "{$coll} unchanged", "{$isNow} vs baseline {$was}");
}

// ── 11 · other tenants ──────────────────────────────────────────────────────
head('OTHER TENANTS — MUST BE UNTOUCHED');
$otherSrnoCounters = 0;
foreach ($fs->query('feeCounters', [['kind', '==', 'SRNO']], null, 'ASC', 500) as $r) {
    $d = body($r);
    if ((string) ($d['schoolId'] ?? '') !== UAT) $otherSrnoCounters++;
}
chk(count($idxOther) === 0, 'no other tenant holds srNoIndex docs', count($idxOther) . ' tenants');
chk($otherSrnoCounters === 0, 'no other tenant holds SRNO counters', (string) $otherSrnoCounters);
$fd = $fs->query('feeDemands', [], null, 'ASC', 5000);
$byT = [];
foreach ($fd as $r) { $d = body($r); $byT[(string) ($d['schoolId'] ?? '(none)')] = ($byT[(string) ($d['schoolId'] ?? '(none)')] ?? 0) + 1; }
chk(($byT['SCH_218AAF5C23'] ?? 0) === 1118, 'SCH_218AAF5C23 feeDemands untouched', (string) ($byT['SCH_218AAF5C23'] ?? 0));
chk(($byT['(none)'] ?? 0) === 98, 'schoolId-less feeDemands untouched', (string) ($byT['(none)'] ?? 0));

// ── 12 · deletion reconciliation ────────────────────────────────────────────
head('DELETION RECONCILIATION');
$gone = (16 - count($still)) + (16 - $idxUat) + (is_array($ptr) ? 0 : 1);
line("  claims removed   : " . (16 - count($still)) . " / 16");
line("  index removed    : " . (16 - $idxUat) . " / 16");
line("  pointer removed  : " . (is_array($ptr) ? 0 : 1) . " / 1");
chk($gone === 33, 'exactly 33 deletions accounted for', "{$gone}");

// ── READ-ONLY PROOF: THE NEXT ALLOCATION IS SRNO 1 ──────────────────────────
head('READ-ONLY PROOF — NEXT ALLOCATION IS DETERMINISTICALLY SRNO 1');
line('  allocateNext() is NOT called. The proof is live state + allocator source.');
line();

$src = file_get_contents(APPPATH . 'libraries/Sr_no_service.php');
$fact = [
    'candidate = pointer + 1'
        => (bool) preg_match('/\$candidate\s*=\s*\$pointer\s*\+\s*1\s*;/', $src),
    'pointer read falls back to a scan of srNoIndex when the doc is absent'
        => (bool) preg_match('/_findMaxExistingSrNoForSchool\s*\(\s*\$schoolId\s*\)/', $src),
    'the scan returns 0 when the school has no srNoIndex rows'
        => (bool) preg_match('/\$max\s*=\s*0\s*;/', $src),
    'the scan writes a pointer ONLY when max > 0'
        => (bool) preg_match('/if\s*\(\s*\$maxExisting\s*>\s*0\s*\)/', $src),
    'candidate N is reserved via create-if-not-exists on claim_{N}'
        => (bool) preg_match('/firestoreCreate\s*\(\s*self::COUNTER_COL/', $src),
];
foreach ($fact as $label => $ok) chk($ok, $label, 'source assertion');

line();
line('  LIVE STATE                                  PROOF STEP');
line('  pointer doc ABSENT ....................... _readSrNoCounterPointer() falls through');
line('  srNoIndex has 0 rows for this school ..... _findMaxExistingSrNoForSchool() returns 0');
line('  (max = 0, so NO pointer is written) ...... returns 0 — same as a brand-new school');
line('  therefore pointer = 0 .................... candidate = 0 + 1 = 1');
line('  claim_1 ABSENT ........................... firestoreCreate(claim_1) SUCCEEDS');
line('  srNoIndex/' . UAT . '_1 ABSENT ..... claim() finds no duplicate');
line('  ==> NEXT ALLOCATION = SRNO 1');

$proof = !is_array($ptr) && $idxUat === 0 && count($still) === 0 && !in_array(false, $fact, true);
chk($proof, 'NEXT GENUINE ALLOCATION WILL BE SRNO 1', 'deterministic');

head('VERDICT');
line($FAIL === 0
    ? '  UAT SRNO RESET: VERIFIED COMPLETE. Next allocation = SRNO 1.'
    : "  *** {$FAIL} CHECK(S) FAILED — do not claim completion. ***");
line('  No allocation was performed. No student was modified. SRNO 1 remains UNCONSUMED.');
line('── end · READ-ONLY ──');
exit($FAIL === 0 ? 0 : 4);
