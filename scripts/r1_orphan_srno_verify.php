<?php
/**
 * R1 — ORPHAN srNoIndex CLEANUP: VERIFICATION ONLY. STRICTLY READ-ONLY.
 *
 * Runs the seven safety checks required before any of the 16 suspected orphan
 * SRNO claims may be deleted. It DELETES NOTHING. No firestoreDelete /
 * deleteDocument / firestoreSet / commitBatch appears in this file — grep it.
 *
 * Usage:  php scripts/r1_orphan_srno_verify.php
 */

define('BASEPATH', __DIR__);
define('APPPATH', __DIR__ . '/../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }
require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

$fs = new FirestoreRestClient(
    __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)'
);

function body(array $r): array { return is_array($r['data'] ?? null) ? $r['data'] : $r; }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 60 - strlen($s)))); }

line('════════════════════════════════════════════════════════════════════');
line(' R1 ORPHAN SRNO VERIFICATION — READ-ONLY   utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════');

$index    = $fs->query('srNoIndex', [], null, 'ASC', 2000);
$students = $fs->query('students',  [], null, 'ASC', 2000);

$liveIds = [];
foreach ($students as $r) {
    $d = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $liveIds[$sid] = true;
}

$candidates = [];   // docId => data
foreach ($index as $r) {
    $d   = body($r);
    $sid = (string) ($d['currentStudentId'] ?? $d['studentId'] ?? '');
    if ($sid !== '' && isset($liveIds[$sid])) continue;     // still referenced — not an orphan
    $candidates[(string) ($r['id'] ?? '?')] = $d;
}

head('CHECK 1 — every candidate points to a NONEXISTENT student');
line('srNoIndex documents total : ' . count($index));
line('orphan candidates         : ' . count($candidates));
$c1 = true;
foreach ($candidates as $docId => $d) {
    $sid = (string) ($d['currentStudentId'] ?? '');
    $ok  = ($sid !== '' && !isset($liveIds[$sid]));
    if (!$ok) $c1 = false;
    printf("  %-28s -> %-10s student_exists=%s\n", $docId, $sid, isset($liveIds[$sid]) ? 'YES' : 'no');
}
line('CHECK 1: ' . ($c1 ? 'PASS' : '*** FAIL ***'));

head('CHECK 2 — every candidate has claimedBy = P203M_UAT');
$c2 = true; $actors = [];
foreach ($candidates as $docId => $d) {
    $by = (string) ($d['claimedBy'] ?? '');
    $actors[$by] = ($actors[$by] ?? 0) + 1;
    if ($by !== 'P203M_UAT') { $c2 = false; line("  *** {$docId} claimedBy={$by}"); }
}
line('claimedBy distribution : ' . json_encode($actors));
line('CHECK 2: ' . ($c2 ? 'PASS' : '*** FAIL ***'));

head('CHECK 3 — all originate from the authorised testing activity');
$c3 = true; $reasons = []; $tenants = [];
foreach ($candidates as $docId => $d) {
    $tenants[(string) ($d['schoolId'] ?? '?')] = true;
    foreach ((array) ($d['history'] ?? []) as $h) {
        $reasons[(string) ($h['reason'] ?? '?')] = ($reasons[(string) ($h['reason'] ?? '?')] ?? 0) + 1;
        if ((string) ($h['actor'] ?? '') !== 'P203M_UAT') $c3 = false;
    }
    // Must be confined to the authorised testing tenant.
    if ((string) ($d['schoolId'] ?? '') !== 'SCH_B56BB9A401') $c3 = false;
}
line('tenants touched : ' . implode(', ', array_keys($tenants)));
line('history reasons : ' . json_encode($reasons));
line('CHECK 3: ' . ($c3 ? 'PASS — confined to SCH_B56BB9A401 / P203M_UAT' : '*** FAIL ***'));

head('CHECK 4 — no REAL student references these SRNOs');
$c4 = true;
$srnos = [];
foreach ($candidates as $d) $srnos[trim((string) ($d['srNo'] ?? ''))] = true;
foreach ($students as $r) {
    $d = body($r);
    foreach (['srNo', 'srNumber', 'SR Number', 'sr_no', 'srno'] as $k) {
        $v = trim((string) ($d[$k] ?? ''));
        if ($v !== '' && isset($srnos[$v]) && (string) ($d['schoolId'] ?? '') === 'SCH_B56BB9A401') {
            $c4 = false;
            line('  *** ' . ($d['studentId'] ?? '?') . " still carries srNo={$v}");
        }
    }
}
line('candidate SR numbers : ' . implode(', ', array_keys($srnos)));
line('CHECK 4: ' . ($c4 ? 'PASS — no live student record carries any of them' : '*** FAIL ***'));

head('CHECK 5 — no other business record depends on these SRNO claims');
$c5 = true;
$deps = ['feeDemands', 'feeReceipts', 'studentConcessions', 'studentEntitlements', 'scholarshipAwards', 'studentDiscounts'];
foreach ($deps as $col) {
    $rows = $fs->query($col, [['schoolId', '==', 'SCH_B56BB9A401']], null, 'ASC', 500);
    $hits = 0;
    foreach ($rows as $r) {
        $d = body($r);
        $sid = (string) ($d['studentId'] ?? '');
        foreach ($candidates as $cd) {
            if ($sid !== '' && $sid === (string) ($cd['currentStudentId'] ?? '')) { $hits++; break; }
        }
    }
    printf("  %-22s rows=%-5d referencing an orphaned student=%d\n", $col, count($rows), $hits);
    if ($hits > 0) $c5 = false;
}
line('CHECK 5: ' . ($c5 ? 'PASS — nothing downstream references those students' : '*** FAIL ***'));

head('CHECK 6 — deleting will not corrupt SRNO history');
$c6 = true;
foreach ($candidates as $docId => $d) {
    $hist = (array) ($d['historicalStudentIds'] ?? []);
    $live = array_values(array_filter($hist, fn($x) => isset($liveIds[(string) $x])));
    if ($live) {
        $c6 = false;
        line("  *** {$docId} historicalStudentIds still reference LIVE students: " . implode(', ', $live));
    }
}
line('all candidates have empty/dead historicalStudentIds : ' . ($c6 ? 'yes' : 'NO'));
line('CHECK 6: ' . ($c6 ? 'PASS — no live re-admission lineage would be lost' : '*** FAIL ***'));

head('CHECK 7 — is freeing these SR numbers acceptable?');
$nums = array_map('intval', array_keys($srnos));
sort($nums);
line('SR numbers that would become allocatable again : ' . implode(', ', $nums));
line('tenant : SCH_B56BB9A401 (authorised testing school)');
line('CHECK 7: OPERATOR DECISION — reuse is only acceptable if these numbers were never');
line('         issued to a real student on paper. Not decidable from data alone.');

head('VERDICT');
$auto = $c1 && $c2 && $c3 && $c4 && $c5 && $c6;
line('checks 1-6 (data-verifiable) : ' . ($auto ? 'ALL PASS' : '*** NOT ALL PASS — DO NOT DELETE ***'));
line('check 7 (business)           : requires explicit operator confirmation');
line();
line('deletion candidates (exact doc ids):');
foreach (array_keys($candidates) as $docId) line('  ' . $docId);
line();
line('── READ-ONLY: nothing was deleted or modified ──');
