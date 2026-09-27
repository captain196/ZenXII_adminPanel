<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * UAT SRNO THREE-STRUCTURE RESET — SCH_B56BB9A401 ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Approved sequence, order MANDATORY:
 *     Step 1  claims      feeCounters/_sys_SRNO_{school}_claim_1..16   (16)
 *     Step 2  srNoIndex   srNoIndex/{school}_1..16                     (16)
 *     Step 3  pointer     feeCounters/_sys_SRNO_{school}               (1)
 *   = 33 deletions, 0 writes, 0 updates.
 *
 * HARD GUARD: the pointer (step 3) may be deleted ONLY after BOTH the claim
 * documents AND the srNoIndex records are CONFIRMED GONE by a live re-read.
 * Deleting the pointer while claims survive would make allocateNext() retry
 * candidate 1 for all COUNTER_MAX_ATTEMPTS and return contention_exhausted —
 * with SRNO-F1 fail-closed, the tenant could not admit anyone.
 *
 * Every doc id is written out EXPLICITLY. No wildcard, no collection sweep,
 * no query-driven delete. Verification runs after EVERY step; any failure
 * aborts before the next destructive step.
 *
 * DRY-RUN BY DEFAULT. Writes require --commit.
 *
 * Usage:
 *   php scripts/uat_srno_three_structure_reset.php            # DRY-RUN
 *   php scripts/uat_srno_three_structure_reset.php --commit   # DELETE
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const UAT = 'SCH_B56BB9A401';
const NMAX = 16;

$COMMIT = in_array('--commit', $argv, true);

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }
function halt(string $why): void { line(); line('*** STOP — ' . $why); line('*** No further destructive step was taken.'); exit(9); }

// ── the EXACT 33 document ids, written out, no pattern expansion at delete time
$CLAIM_IDS = []; $INDEX_IDS = [];
for ($n = 1; $n <= NMAX; $n++) {
    $CLAIM_IDS[$n] = '_sys_SRNO_' . UAT . '_claim_' . $n;
    $INDEX_IDS[$n] = UAT . '_' . $n;
}
$POINTER_ID = '_sys_SRNO_' . UAT;

line('════════════════════════════════════════════════════════════════════');
line(' UAT SRNO THREE-STRUCTURE RESET   tenant=' . UAT);
line(' MODE: ' . ($COMMIT ? '*** COMMIT (deleting) ***' : 'DRY-RUN (no writes)'));
line(' utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

/** Live presence probes — always a fresh read, never a cached assumption. */
$claimsPresent = function () use ($fs, $CLAIM_IDS): array {
    $p = [];
    foreach ($CLAIM_IDS as $n => $id) if (is_array($fs->getDocument('feeCounters', $id))) $p[] = $n;
    return $p;
};
$indexPresent = function () use ($fs, $INDEX_IDS): array {
    $p = [];
    foreach ($INDEX_IDS as $n => $id) if (is_array($fs->getDocument('srNoIndex', $id))) $p[] = $n;
    return $p;
};
$pointerVal = function () use ($fs, $POINTER_ID) {
    $d = $fs->getDocument('feeCounters', $POINTER_ID);
    return is_array($d) ? (int) ($d['value'] ?? -1) : null;
};

// ── PRE-RESET SNAPSHOT ──────────────────────────────────────────────────────
head('PRE-RESET SNAPSHOT (read-only baseline for reconciliation)');

$students = [];
foreach ($fs->query('students', [['schoolId', '==', UAT]], null, 'ASC', 2000) as $r) {
    $d = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $students[$sid] = (string) ($d['srNo'] ?? ($d['srNumber'] ?? ''));
}
ksort($students);
$withSr = count(array_filter($students, fn($v) => $v !== ''));

$counts = [];
foreach (['feeDemands', 'feeReceipts', 'scholarshipAwards', 'studentEntitlements', 'admissions'] as $c) {
    $counts[$c] = count($fs->query($c, [['schoolId', '==', UAT]], null, 'ASC', 3000));
}

$snap = [
    'tenant'          => UAT,
    'utc'             => gmdate('c'),
    'studentCount'    => count($students),
    'studentIds'      => array_keys($students),
    'studentsWithSrNo' => $withSr,
    'claimsPresent'   => $claimsPresent(),
    'indexPresent'    => $indexPresent(),
    'pointer'         => $pointerVal(),
    'collections'     => $counts,
];
line('  tenant ................. ' . UAT);
line('  live students .......... ' . $snap['studentCount']);
line('  students with srNo ..... ' . $withSr . '   (must stay ' . $withSr . ')');
line('  ids .................... ' . implode(',', $snap['studentIds']));
line('  srNoIndex present ...... N = ' . implode(',', $snap['indexPresent']));
line('  claims present ......... N = ' . implode(',', $snap['claimsPresent']));
line('  pointer ................ ' . ($snap['pointer'] === null ? 'ABSENT' : 'value=' . $snap['pointer']));
foreach ($counts as $c => $n) printf("  %-22s %d\n", $c, $n);

$snapFile = __DIR__ . '/../UAT_SRNO_RESET_SNAPSHOT.json';
if ($COMMIT) {
    file_put_contents($snapFile, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    line('  snapshot written ....... ' . basename($snapFile));
}

// ── preconditions ───────────────────────────────────────────────────────────
head('PRECONDITIONS');
$okPre = true;
$okPre &= (count($snap['claimsPresent']) === NMAX);
$okPre &= (count($snap['indexPresent']) === NMAX);
$okPre &= ($snap['pointer'] === 16);
$okPre &= ($withSr === 0);
$okPre &= ($snap['studentCount'] === 23);
line('  16 claims present ...... ' . (count($snap['claimsPresent']) === NMAX ? 'yes' : 'NO'));
line('  16 index docs present .. ' . (count($snap['indexPresent']) === NMAX ? 'yes' : 'NO'));
line('  pointer == 16 .......... ' . ($snap['pointer'] === 16 ? 'yes' : 'NO'));
line('  23 students, 0 with srNo ' . ($snap['studentCount'] === 23 && $withSr === 0 ? 'yes' : 'NO'));
if (!$okPre) halt('preconditions do not match the approved plan.');
line('  PRECONDITIONS: PASS');

if (!$COMMIT) {
    head('DRY-RUN — the exact 33 documents that WOULD be deleted');
    line('  STEP 1 — feeCounters (16):');
    foreach ($CLAIM_IDS as $id) line('      ' . $id);
    line('  STEP 2 — srNoIndex (16):');
    foreach ($INDEX_IDS as $id) line('      ' . $id);
    line('  STEP 3 — feeCounters (1):');
    line('      ' . $POINTER_ID);
    line();
    line('  Nothing was deleted. Re-run with --commit to apply.');
    exit(0);
}

/** Delete one explicitly-named document, with backoff (the R5 lesson). */
$del = function (string $coll, string $id) use ($fs): bool {
    for ($a = 1; $a <= 3; $a++) {
        if ($fs->deleteDocument($coll, $id)) return true;
        usleep(200000 * $a);
    }
    return false;
};
$deleted = 0;

// ══ STEP 1 — CLAIMS ═════════════════════════════════════════════════════════
head('STEP 1 · DELETE THE 16 SRNO CLAIM DOCUMENTS');
foreach ($CLAIM_IDS as $n => $id) {
    if ($del('feeCounters', $id)) { $deleted++; line("  deleted feeCounters/{$id}"); }
    else halt("failed to delete feeCounters/{$id} (claims step).");
}
line('  deleted so far: ' . $deleted);

head('VERIFY AFTER STEP 1');
$c = $claimsPresent(); $i = $indexPresent(); $p = $pointerVal();
line('  claims 1-16 absent ..... ' . (count($c) === 0 ? 'PASS' : 'FAIL — still present: ' . implode(',', $c)));
line('  srNoIndex 1-16 present . ' . (count($i) === NMAX ? 'PASS' : 'FAIL — present: ' . implode(',', $i)));
line('  pointer still 16 ....... ' . ($p === 16 ? 'PASS' : 'FAIL — ' . var_export($p, true)));
if (count($c) !== 0 || count($i) !== NMAX || $p !== 16) halt('post-step-1 verification failed.');

// ══ STEP 2 — srNoIndex ══════════════════════════════════════════════════════
head('STEP 2 · DELETE THE 16 srNoIndex DOCUMENTS');
foreach ($INDEX_IDS as $n => $id) {
    if ($del('srNoIndex', $id)) { $deleted++; line("  deleted srNoIndex/{$id}"); }
    else halt("failed to delete srNoIndex/{$id} (index step).");
}
line('  deleted so far: ' . $deleted);

head('VERIFY AFTER STEP 2');
$c = $claimsPresent(); $i = $indexPresent(); $p = $pointerVal();
line('  claims 1-16 absent ..... ' . (count($c) === 0 ? 'PASS' : 'FAIL — ' . implode(',', $c)));
line('  srNoIndex 1-16 absent .. ' . (count($i) === 0 ? 'PASS' : 'FAIL — ' . implode(',', $i)));
line('  pointer still 16 ....... ' . ($p === 16 ? 'PASS' : 'FAIL — ' . var_export($p, true)));
if (count($c) !== 0 || count($i) !== 0 || $p !== 16) halt('post-step-2 verification failed.');

// ══ HARD GUARD before the pointer ═══════════════════════════════════════════
head('HARD GUARD · MAY THE POINTER BE DELETED?');
$c = $claimsPresent(); $i = $indexPresent();
$tenantIdx = 0;
foreach ($fs->query('srNoIndex', [['schoolId', '==', UAT]], null, 'ASC', 500) as $r) $tenantIdx++;
line('  claim documents gone ... ' . (count($c) === 0 ? 'CONFIRMED' : '*** NO'));
line('  srNoIndex ids gone ..... ' . (count($i) === 0 ? 'CONFIRMED' : '*** NO'));
line('  tenant srNoIndex query . ' . $tenantIdx . ' docs (must be 0)');
if (count($c) !== 0 || count($i) !== 0 || $tenantIdx !== 0) {
    halt('HARD GUARD REFUSED the pointer deletion — claims or index records survive. '
       . 'Deleting the pointer now would brick SRNO allocation for this tenant.');
}
line('  HARD GUARD: PASS — the pointer may be deleted.');

// ══ STEP 3 — POINTER ════════════════════════════════════════════════════════
head('STEP 3 · DELETE THE SRNO POINTER');
if ($del('feeCounters', $POINTER_ID)) { $deleted++; line("  deleted feeCounters/{$POINTER_ID}"); }
else halt("failed to delete feeCounters/{$POINTER_ID} (pointer step).");

head('VERIFY AFTER STEP 3');
$c = $claimsPresent(); $i = $indexPresent(); $p = $pointerVal();
line('  claims absent .......... ' . (count($c) === 0 ? 'PASS' : 'FAIL'));
line('  srNoIndex empty ........ ' . (count($i) === 0 ? 'PASS' : 'FAIL'));
line('  pointer absent ......... ' . ($p === null ? 'PASS' : 'FAIL — value=' . $p));
if (count($c) !== 0 || count($i) !== 0 || $p !== null) halt('post-step-3 verification failed.');

head('DELETION TOTAL');
line('  deletions performed : ' . $deleted . '   (expected 33)');
line('  ' . ($deleted === 33 ? 'RECONCILES' : '*** MISMATCH ***'));
line();
line('Reset applied. Post-verification MUST now be run from a separate process:');
line('   php scripts/uat_srno_reset_postverify.php');
line('── end ──');
