<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * R5 POST-VERIFICATION + R1 RE-VERIFICATION — STRICTLY READ-ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Independent of scripts/r5_orphan_feedemand_cleanup.php: separate file,
 * separate process, separate client. The cleanup script's own post-check is
 * NOT trusted — on the first R5 run it reported "0 remaining" while 280
 * actually remained.
 *
 * NO WRITES. grep this file: no deleteDocument / setDocument / updateDocument /
 * createDocument / commitBatch appears anywhere. R1 DELETION IS NOT PERFORMED.
 *
 * Usage:  php scripts/r5_r1_independent_verify.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const TENANT  = 'SCH_B56BB9A401';
const ALLOWED = [
    'STU0168', 'STU0169', 'STU0170', 'STU0171', 'STU0172', 'STU0173', 'STU0174',
    'STU0175', 'STU0176', 'STU0177', 'STU0178', 'STU0179', 'STU0181',
];

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }
function verdict(bool $ok, string $s) { line(($ok ? '  PASS  ' : '  FAIL  ') . $s); return $ok; }

line('════════════════════════════════════════════════════════════════════');
line(' R5 POST-VERIFICATION + R1 RE-VERIFICATION — READ-ONLY, FRESH PROCESS');
line(' utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

$allowed = array_flip(ALLOWED);
$allPass = true;

// ══ R5 POST-VERIFICATION ════════════════════════════════════════════════════
head('R5 · F1 — PLATFORM-WIDE feeDemands CENSUS');

$all = $fs->query('feeDemands', [], null, 'ASC', 5000);
$byTenant = []; $tenantDocs = [];
foreach ($all as $r) {
    $d   = body($r);
    $sch = (string) ($d['schoolId'] ?? '');
    $key = $sch === '' ? '(no schoolId)' : $sch;
    $byTenant[$key] = ($byTenant[$key] ?? 0) + 1;
    if ($sch === TENANT) $tenantDocs[] = $d;
}
ksort($byTenant);
line('  feeDemands PLATFORM-WIDE : ' . count($all));
foreach ($byTenant as $k => $n) printf("     %-26s %d\n", $k, $n);

head('R5 · F2 — TENANT BREAKDOWN BY STUDENT');

$live = [];
foreach ($fs->query('students', [['schoolId', '==', TENANT]], null, 'ASC', 2000) as $r) {
    $d = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $live[$sid] = $d;
}
line('  live students in tenant : ' . count($live));

$perStudent = [];
foreach ($tenantDocs as $d) {
    $sid = (string) ($d['studentId'] ?? '(none)');
    $perStudent[$sid] = ($perStudent[$sid] ?? 0) + 1;
}
ksort($perStudent);
$orphansLeft = 0; $liveDemands = 0; $unexpected = [];
foreach ($perStudent as $sid => $n) {
    $isLive = isset($live[$sid]);
    $isAuth = isset($allowed[$sid]);
    if (!$isLive && $isAuth)  $orphansLeft += $n;
    if ($isLive)              $liveDemands += $n;
    if (!$isLive && !$isAuth) $unexpected[$sid] = $n;
    printf("     %-10s %3d  %s\n", $sid, $n,
        $isLive ? 'LIVE' : ($isAuth ? '*** AUTHORISED ORPHAN STILL PRESENT ***' : '*** UNEXPECTED ***'));
}

head('R5 · VERDICT');
$allPass &= verdict($orphansLeft === 0,
    "1. zero orphan feeDemands for the 13 authorised ids  (found {$orphansLeft})");
$allPass &= verdict($liveDemands === 26,
    "2. live students' feeDemands intact                  (found {$liveDemands}, expected 26)");
$allPass &= verdict(($byTenant['SCH_218AAF5C23'] ?? 0) === 1118,
    '3. other tenant SCH_218AAF5C23 untouched             (' . ($byTenant['SCH_218AAF5C23'] ?? 0) . ', expected 1118)');
$allPass &= verdict(($byTenant['(no schoolId)'] ?? 0) === 98,
    '4. schoolId-less demands untouched                   (' . ($byTenant['(no schoolId)'] ?? 0) . ', expected 98)');
$allPass &= verdict(count($unexpected) === 0,
    '5. no unexpected student ids affected                (' . count($unexpected) . ')');
$allPass &= verdict(count($tenantDocs) === 26,
    '6. tenant total = 26                                 (found ' . count($tenantDocs) . ')');

head('R5 · RECONCILIATION (independent of the cleanup script)');
line('  original authorised candidates ....... 481');
line('  deleted in run 1 ..................... 227');
line('  deleted in run 2 ..................... 254');
line('  ------------------------------------------');
printf("  total deleted ........................ %d\n", 227 + 254);
printf("  authorised orphans remaining ......... %d   (must be 0)\n", $orphansLeft);
printf("  507 original tenant demands - 481 orphans = %d live   observed %d\n", 507 - 481, $liveDemands);
$reconciles = ((227 + 254) === 481) && $orphansLeft === 0 && $liveDemands === 26;
$allPass &= verdict($reconciles, 'arithmetic reconciles end-to-end');

// ══ R1 RE-VERIFICATION — READ ONLY, NO DELETION ═════════════════════════════
head('R1 · RE-VERIFICATION (READ-ONLY — NOTHING IS DELETED)');

$idx = $fs->query('srNoIndex', [], null, 'ASC', 2000);
line('  srNoIndex documents PLATFORM-WIDE : ' . count($idx));

$c1 = $c2 = $c6 = 0; $srNums = []; $rows = []; $tenantIdx = 0;
foreach ($idx as $r) {
    $d     = body($r);
    $docId = (string) ($r['id'] ?? '');
    $sch   = (string) ($d['schoolId'] ?? '');
    if ($sch !== TENANT) continue;
    $tenantIdx++;

    $srNo   = (string) ($d['srNo'] ?? '');
    $cur    = (string) ($d['currentStudentId'] ?? '');
    $by     = (string) ($d['claimedBy'] ?? '');
    $hist   = is_array($d['historicalStudentIds'] ?? null) ? $d['historicalStudentIds'] : [];
    $exists = $cur !== '' && is_array($fs->getDocument('students', TENANT . '_' . $cur));

    if (!$exists) $c1++;
    if ($by === 'P203M_UAT') $c2++;
    if (count($hist) === 0) $c6++;
    $srNums[] = $srNo;
    $rows[] = sprintf('     srNo=%-4s doc=%-24s current=%-9s exists=%-3s claimedBy=%-12s hist=%d',
        $srNo, $docId, $cur !== '' ? $cur : '(none)', $exists ? 'YES' : 'no', $by !== '' ? $by : '(none)', count($hist));
}
foreach ($rows as $r) line($r);

// check 4 — does any LIVE student carry an srNo in the claimed range?
sort($srNums, SORT_NUMERIC);
$claimedSet = array_flip($srNums);
$liveRefs = [];
foreach ($live as $sid => $d) {
    $sr = (string) ($d['srNo'] ?? ($d['srNumber'] ?? ''));
    if ($sr !== '' && isset($claimedSet[$sr])) $liveRefs[$sid] = $sr;
}

// check 5 — does any other business record reference these students?
$depends = [];
foreach (['feeDemands', 'feeReceipts', 'scholarshipAwards', 'studentEntitlements', 'admissions'] as $coll) {
    $n = 0;
    foreach ($fs->query($coll, [['schoolId', '==', TENANT]], null, 'ASC', 3000) as $r) {
        $d   = body($r);
        $sid = (string) ($d['studentId'] ?? '');
        $sr  = (string) ($d['srNo'] ?? '');
        if (!isset($live[$sid]) && ($sid !== '' && isset($allowed[$sid]))) $n++;
        elseif ($sr !== '' && isset($claimedSet[$sr]) && !isset($live[$sid])) $n++;
    }
    $depends[$coll] = $n;
}

head('R1 · VERDICT (INVESTIGATION ONLY — DELETION NOT AUTHORISED)');
line('  srNoIndex docs in tenant : ' . $tenantIdx);
verdict($c1 === $tenantIdx, "1. all point to NONEXISTENT students        ({$c1}/{$tenantIdx})");
verdict($c2 === $tenantIdx, "2. all claimedBy = P203M_UAT                ({$c2}/{$tenantIdx})");
verdict($c2 === $tenantIdx, "3. all originated from authorised testing   (via claimedBy provenance)");
verdict(count($liveRefs) === 0, '4. no LIVE student references these srNos   (' . count($liveRefs) . ')');
foreach ($liveRefs as $sid => $sr) line("        *** {$sid} references srNo {$sr}");
$depTotal = array_sum($depends);
verdict($depTotal === 0, "5. no business record depends on the claims ({$depTotal})");
foreach ($depends as $c => $n) printf("        %-22s %d\n", $c, $n);
verdict($c6 === $tenantIdx, "6. historicalStudentIds[] empty for all     ({$c6}/{$tenantIdx})");
line('  7. REUSE SAFETY — reported below, NOT decided here.');
line('     srNos claimed: ' . implode(', ', $srNums));

head('SUMMARY');
line('  R5 : ' . ($allPass ? 'VERIFIED COMPLETE' : '*** NOT COMPLETE — see FAIL rows ***'));
line('  R1 : investigation only. NO srNoIndex record was read-modified or deleted.');
line('       SRNO 1-16 reuse remains UNAUTHORISED and undecided.');
line('── end · READ-ONLY ──');
