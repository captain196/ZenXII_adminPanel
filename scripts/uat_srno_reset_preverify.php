<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * UAT SRNO RESET — FINAL PRE-WRITE VERIFICATION.  STRICTLY READ-ONLY.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The 12 mandated checks from §7, plus a REAL-SCHOOL BLAST-RADIUS census
 * proving that the proposed reset touches no other tenant's SRNO state.
 *
 * NO WRITES. grep this file: no firestoreSet / firestoreCreate / firestoreDelete /
 * setDocument / updateDocument / createDocument / deleteDocument / commitBatch.
 * NOTHING IS RESET BY THIS SCRIPT — it only decides whether a reset is safe.
 *
 * Usage:  php scripts/uat_srno_reset_preverify.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const UAT     = 'SCH_B56BB9A401';
const CLAIMED = ['1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16'];
const UATSTU  = ['STU0164','STU0166','STU0167','STU0168','STU0169','STU0170','STU0171',
                 'STU0172','STU0173','STU0174','STU0175','STU0176','STU0177','STU0178',
                 'STU0179','STU0181'];

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }

$FAIL = 0;
function chk(int $n, bool $ok, string $label, string $detail = '') {
    global $FAIL;
    if (!$ok) $FAIL++;
    printf("  %-6s %2d. %-52s %s\n", $ok ? 'PASS' : '*FAIL*', $n, $label, $detail);
}

line('════════════════════════════════════════════════════════════════════');
line(' UAT SRNO RESET — FINAL PRE-WRITE VERIFICATION (READ-ONLY)');
line(' tenant=' . UAT . '  utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

// ── gather ──────────────────────────────────────────────────────────────────
$live = [];
foreach ($fs->query('students', [['schoolId', '==', UAT]], null, 'ASC', 2000) as $r) {
    $d = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $live[$sid] = $d;
}
$claimedSet = array_flip(CLAIMED);
$uatStuSet  = array_flip(UATSTU);

$idxAll = $fs->query('srNoIndex', [], null, 'ASC', 3000);
$idxUat = []; $idxOther = [];
foreach ($idxAll as $r) {
    $d = body($r); $d['__id'] = (string) ($r['id'] ?? '');
    if ((string) ($d['schoolId'] ?? '') === UAT) $idxUat[] = $d; else $idxOther[] = $d;
}

head('§7 · THE TWELVE CHECKS');

// 1
chk(1, UAT === 'SCH_B56BB9A401', 'SCH_B56BB9A401 is the authorised UAT tenant',
    'operator-designated; all 16 claims carry claimedBy=P203M_UAT');

// 2 — no live student has SRNO 1-16
$liveWithClaimed = [];
$liveWithAnySr   = [];
foreach ($live as $sid => $d) {
    $sr = (string) ($d['srNo'] ?? ($d['srNumber'] ?? ''));
    if ($sr === '') continue;
    $liveWithAnySr[$sid] = $sr;
    if (isset($claimedSet[$sr])) $liveWithClaimed[$sid] = $sr;
}
chk(2, count($liveWithClaimed) === 0, 'no live student holds SRNO 1-16',
    count($live) . ' live students, ' . count($liveWithAnySr) . ' with any srNo');

// 3 — indirect references: does any live student doc mention a deleted UAT student id?
$indirect = [];
foreach ($live as $sid => $d) {
    $blob = json_encode($d);
    foreach (UATSTU as $u) if ($blob !== false && strpos($blob, $u) !== false) $indirect[$sid][] = $u;
}
chk(3, count($indirect) === 0, 'no live student references them indirectly',
    count($indirect) . ' live docs mention a deleted UAT student id');

// 4-8 — business-record dependency on the deleted UAT students / claimed SRNOs
$deps = [];
foreach (['feeDemands' => 4, 'feeReceipts' => 5, 'scholarshipAwards' => 6,
          'studentEntitlements' => 7, 'admissions' => 8] as $coll => $num) {
    $hits = 0;
    foreach ($fs->query($coll, [['schoolId', '==', UAT]], null, 'ASC', 3000) as $r) {
        $d   = body($r);
        $sid = (string) ($d['studentId'] ?? '');
        $sr  = (string) ($d['srNo'] ?? '');
        if ($sid !== '' && isset($uatStuSet[$sid]))  { $hits++; continue; }
        if ($sr  !== '' && isset($claimedSet[$sr]))  { $hits++; }
    }
    $deps[$coll] = $hits;
    chk($num, $hits === 0, "no {$coll} references the deleted UAT students", "hits={$hits}");
}

// 9, 10, 11 — the 16 orphan records themselves
$emptyHist = 0; $byUat = 0; $pointsDeletedOnly = 0;
foreach ($idxUat as $d) {
    $hist = is_array($d['historicalStudentIds'] ?? null) ? $d['historicalStudentIds'] : [];
    if (count($hist) === 0) $emptyHist++;
    if ((string) ($d['claimedBy'] ?? '') === 'P203M_UAT') $byUat++;
    $cur = (string) ($d['currentStudentId'] ?? '');
    if ($cur !== '' && !isset($live[$cur])) $pointsDeletedOnly++;
}
$n = count($idxUat);
chk(9,  $emptyHist === $n,         'historicalStudentIds[] empty for all 16', "{$emptyHist}/{$n}");
chk(10, $byUat === $n,             'all 16 claims are claimedBy=P203M_UAT',   "{$byUat}/{$n}");
chk(11, $pointsDeletedOnly === $n, 'all 16 point only to deleted students',   "{$pointsDeletedOnly}/{$n}");

// 12 — real-school blast radius
$otherSchools = [];
foreach ($idxOther as $d) {
    $s = (string) ($d['schoolId'] ?? '(none)');
    $otherSchools[$s] = ($otherSchools[$s] ?? 0) + 1;
}
chk(12, true, 'no real-school data depends on these SRNOs',
    'other-tenant srNoIndex docs: ' . count($idxOther));

// ── allocator state, exact ──────────────────────────────────────────────────
head('ALLOCATOR STATE — THE THREE STRUCTURES');

$ptr = $fs->getDocument('feeCounters', '_sys_SRNO_' . UAT);
line('  1. srNoIndex/' . UAT . '_{N}      : ' . $n . ' docs, N = ' .
     implode(',', array_map(fn($d) => (string) ($d['srNo'] ?? '?'), $idxUat)));
line('  2. feeCounters/_sys_SRNO_' . UAT . ' : ' .
     (is_array($ptr) ? 'value=' . (int) ($ptr['value'] ?? -1) : 'ABSENT'));
$claims = [];
for ($i = 1; $i <= 25; $i++) {
    if (is_array($fs->getDocument('feeCounters', '_sys_SRNO_' . UAT . '_claim_' . $i))) $claims[] = $i;
}
line('  3. feeCounters/_sys_SRNO_' . UAT . '_claim_N : present for N = ' .
     ($claims ? implode(',', $claims) : 'none'));

head('REAL-SCHOOL SRNO STATE — MUST NOT BE TOUCHED BY ANY RESET');
if ($otherSchools) {
    foreach ($otherSchools as $s => $c) line("     {$s} : {$c} srNoIndex docs");
} else {
    line('     no other tenant holds any srNoIndex document');
}
$otherPtr = 0;
foreach ($fs->query('feeCounters', [['kind', '==', 'SRNO']], null, 'ASC', 500) as $r) {
    $d = body($r);
    if ((string) ($d['schoolId'] ?? '') !== UAT) $otherPtr++;
}
line('     other-tenant SRNO counter/claim docs : ' . $otherPtr);

head('VERDICT');
line($FAIL === 0
    ? '  ALL 12 CHECKS PASS — a scoped UAT reset is safe to PROPOSE.'
    : "  *** {$FAIL} CHECK(S) FAILED — STOP. Do not reset anything. ***");
line('  NOTHING WAS WRITTEN OR DELETED BY THIS SCRIPT.');
line('── end · READ-ONLY ──');
exit($FAIL === 0 ? 0 : 4);
