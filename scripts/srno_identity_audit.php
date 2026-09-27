<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * SRNO IDENTITY AUDIT — STRICTLY READ-ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Audits the student ↔ SRNO identity state against the product invariant
 * "every successfully admitted student MUST have an SRNO".
 *
 * NO WRITES. No firestoreSet / firestoreUpdate / firestoreCreate /
 * firestoreDelete / commitBatch / setDocument / updateDocument /
 * deleteDocument appears anywhere in this file — grep it before running.
 * Nothing is repaired, backfilled or deleted.
 *
 * Usage:  php scripts/srno_identity_audit.php
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
function rid(array $r): string { return (string) ($r['id'] ?? $r['__id'] ?? ''); }
function line(string $s = '') { echo $s . "\n"; }
function head(string $s) { line(); line('── ' . $s . ' ' . str_repeat('─', max(0, 62 - strlen($s)))); }

line('════════════════════════════════════════════════════════════════════════');
line(' SRNO IDENTITY AUDIT — READ-ONLY   utc=' . gmdate('c'));
line('════════════════════════════════════════════════════════════════════════');

$students = $fs->query('students',   [], null, 'ASC', 2000);
$index    = $fs->query('srNoIndex',  [], null, 'ASC', 2000);

head('1-4 · CENSUS');
line('total students                 : ' . count($students));
line('srNoIndex documents            : ' . count($index));

/** SRNO as recorded ON the student document — several historical field names. */
function studentSrno(array $d): string
{
    foreach (['srNo', 'srNumber', 'SR Number', 'sr_no', 'srno'] as $k) {
        $v = trim((string) ($d[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return '';
}

$byId = [];          // studentId => [schoolId, session, srno]
$withSrno = $withoutSrno = 0;
$perSchool = [];
foreach ($students as $r) {
    $d   = body($r);
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid === '') continue;
    $school  = (string) ($d['schoolId'] ?? '');
    $session = (string) ($d['session'] ?? '');
    $srno    = studentSrno($d);
    $byId[$sid] = ['school' => $school, 'session' => $session, 'srno' => $srno, 'status' => (string) ($d['status'] ?? '')];
    if ($srno !== '') $withSrno++; else $withoutSrno++;
    $perSchool[$school]['total'] = ($perSchool[$school]['total'] ?? 0) + 1;
    if ($srno === '') $perSchool[$school]['missing'] = ($perSchool[$school]['missing'] ?? 0) + 1;
}
line('students WITH an SRNO field    : ' . $withSrno);
line('students WITHOUT an SRNO field : ' . $withoutSrno);

head('PER-TENANT BREAKDOWN (counts only)');
ksort($perSchool);
foreach ($perSchool as $school => $c) {
    printf("  %-24s total=%-5d missingSrno=%d\n", $school ?: '(no schoolId)', $c['total'], $c['missing'] ?? 0);
}

// ── index side ──────────────────────────────────────────────────────────────
$idxBySrno = [];     // school|session|srno => [studentId,…]
$idxRows   = [];
foreach ($index as $r) {
    $d = body($r);
    $idxRows[] = ['docId' => rid($r), 'd' => $d];
    // VERIFIED SHAPE: srNoIndex keys the student as `currentStudentId` (with
    // `historicalStudentIds[]` for re-admission relinks) and carries NO session
    // field — the doc id is {schoolId}_{srNo}. An earlier version of this audit
    // assumed `studentId` + `session` and consequently reported every index row
    // as malformed; that was the audit being wrong, not the data.
    $key = ((string) ($d['schoolId'] ?? '')) . '|' . trim((string) ($d['srNo'] ?? ''));
    $idxBySrno[$key][] = (string) ($d['currentStudentId'] ?? $d['studentId'] ?? '');
}

head('5-13 · INTEGRITY VIOLATIONS');

$V = [
    'STUDENT_WITHOUT_SRNO'        => [],
    'SRNO_ON_STUDENT_NOT_INDEXED' => [],
    'INDEX_POINTS_TO_MISSING_STU' => [],
    'INDEX_STUDENT_MISMATCH'      => [],
    'DUPLICATE_SRNO'              => [],
    'DUPLICATE_INDEX_MAPPING'     => [],
    'INDEX_WRONG_SCHOOL'          => [],
    'INDEX_WRONG_SESSION'         => [],
    'INDEX_MALFORMED'             => [],
];

// 11 — SRNO on the student but absent from the index
foreach ($byId as $sid => $s) {
    if ($s['srno'] === '') { $V['STUDENT_WITHOUT_SRNO'][] = $sid; continue; }
    $key = $s['school'] . '|' . $s['srno'];
    if (!isset($idxBySrno[$key])) $V['SRNO_ON_STUDENT_NOT_INDEXED'][] = "{$sid} srno={$s['srno']}";
}

// 12 / 5 / 9 / 10 — index-side checks
foreach ($idxRows as $row) {
    $d    = $row['d'];
    $sid  = (string) ($d['currentStudentId'] ?? $d['studentId'] ?? '');
    $srno = trim((string) ($d['srNo'] ?? ''));
    $sch  = (string) ($d['schoolId'] ?? '');
    $ses  = (string) ($d['session']  ?? '');

    if ($sid === '' || $srno === '' || $sch === '') {
        $V['INDEX_MALFORMED'][] = $row['docId'];
        continue;
    }
    if (!isset($byId[$sid])) { $V['INDEX_POINTS_TO_MISSING_STU'][] = "{$row['docId']} -> {$sid}"; continue; }

    $stu = $byId[$sid];
    if ($stu['srno'] !== '' && $stu['srno'] !== $srno) {
        $V['INDEX_STUDENT_MISMATCH'][] = "{$sid} student={$stu['srno']} index={$srno}";
    }
    if ($stu['school'] !== '' && $sch !== $stu['school']) {
        $V['INDEX_WRONG_SCHOOL'][] = "{$row['docId']} idx={$sch} student={$stu['school']}";
    }
    // srNoIndex carries no session by design (SRNO is school-scoped and
    // survives re-admission across sessions via historicalStudentIds), so a
    // session mismatch is not expressible on the index side.
}

// 6 / 7 — duplicates
foreach ($idxBySrno as $key => $sids) {
    $uniq = array_values(array_unique(array_filter($sids)));
    if (count($sids) > 1) $V['DUPLICATE_INDEX_MAPPING'][] = "{$key} x" . count($sids);
    if (count($uniq) > 1) $V['DUPLICATE_SRNO'][] = "{$key} -> " . implode(', ', $uniq);
}
$seen = [];
foreach ($byId as $sid => $s) {
    if ($s['srno'] === '') continue;
    $k = $s['school'] . '|' . $s['session'] . '|' . $s['srno'];
    if (isset($seen[$k])) $V['DUPLICATE_SRNO'][] = "{$k} -> {$seen[$k]}, {$sid} (student docs)";
    $seen[$k] = $sid;
}

foreach ($V as $cls => $rows) printf("  %-30s %d\n", $cls, count($rows));

head('EXAMPLES (bounded; ids only, no personal data)');
foreach ($V as $cls => $rows) {
    if (!$rows) continue;
    line("  [{$cls}]");
    foreach (array_slice($rows, 0, 8) as $r) line('    ' . $r);
    if (count($rows) > 8) line('    … +' . (count($rows) - 8) . ' more');
}

head('VERDICT');
$total = array_sum(array_map('count', $V));
line('total integrity violations : ' . $total);
line('invariant "every admitted student has an SRNO" : '
    . (count($V['STUDENT_WITHOUT_SRNO']) === 0 ? 'HOLDS' : 'VIOLATED for ' . count($V['STUDENT_WITHOUT_SRNO']) . ' student(s)'));
line();
line('── end · READ-ONLY: nothing was modified, backfilled or deleted ──');
