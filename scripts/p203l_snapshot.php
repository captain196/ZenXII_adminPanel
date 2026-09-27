<?php
/**
 * PHASE 20.3L — PRE/POST-UAT SNAPSHOT (STRICTLY READ-ONLY)
 *
 * Reads sections + students for the authorized testing tenant and prints a
 * reconcilable snapshot: per-section configured capacity, live occupancy
 * (derived the SAME way MY_Controller::_filterSectionRoster does), the
 * section's Firestore __updateTime, and the seatCasAt marker.
 *
 * NO WRITES. No setDocument / updateDocument / deleteDocument / commitBatch /
 * createDocument appears anywhere in this file — grep it before running.
 *
 * Usage:  php scripts/p203l_snapshot.php [label]
 */

define('BASEPATH', __DIR__);            // FirestoreRestClient's direct-access guard
define('APPPATH', __DIR__ . '/../application/');

if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../application/libraries/Firestore_rest_client.php';

const SCHOOL = 'SCH_B56BB9A401';        // Vikrant Public — the ONLY authorized tenant

$label = $argv[1] ?? 'snapshot';
$sa    = __DIR__ . '/../application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';

$fs = new FirestoreRestClient($sa, 'graderadmin', '(default)');

/** query() returns rows as ['id'=>…, 'data'=>…] or flat; normalise both. */
function body(array $row): array { return is_array($row['data'] ?? null) ? $row['data'] : $row; }
function rowId(array $row): string { return (string) ($row['id'] ?? $row['__id'] ?? ''); }

$sectionRows = $fs->query('sections', [['schoolId', '==', SCHOOL]]);
$studentRows = $fs->query('students', [['schoolId', '==', SCHOOL]]);

$students = array_map('body', $studentRows);

/** Occupancy rule copied verbatim from MY_Controller::_filterSectionRoster(). */
function rosterCount(array $students, string $classKey, string $sectionKey, string $session): int
{
    $c  = mb_strtolower(trim($classKey));
    $s  = mb_strtolower(trim($sectionKey));
    $ss = mb_strtolower(trim($session));
    $n  = 0;
    foreach ($students as $d) {
        $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $d['User Id'] ?? '');
        if ($sid === '') continue;
        $status = trim((string) ($d['status'] ?? $d['Status'] ?? 'Active'));
        if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
        if (mb_strtolower(trim((string) ($d['className'] ?? $d['Class'] ?? ''))) !== $c) continue;
        if (mb_strtolower(trim((string) ($d['section'] ?? $d['Section'] ?? ''))) !== $s) continue;
        $rs = mb_strtolower(trim((string) ($d['session'] ?? '')));
        if ($ss !== '' && $rs !== '' && $rs !== $ss) continue;
        $n++;
    }
    return $n;
}

// __updateTime is only returned by the document endpoint, not by runQuery.
$reqs = [];
foreach ($sectionRows as $i => $r) $reqs[$i] = ['collection' => 'sections', 'docId' => rowId($r)];
$fullSections = $fs->getDocumentsParallel($reqs);

echo "════════════════════════════════════════════════════════════════════════════════\n";
echo " PHASE 20.3L SNAPSHOT [{$label}]  tenant=" . SCHOOL . "  utc=" . gmdate('c') . "\n";
echo "════════════════════════════════════════════════════════════════════════════════\n";
echo 'sections=' . count($sectionRows) . '  students=' . count($studentRows) . "\n\n";

$rows = [];
foreach ($sectionRows as $i => $r) {
    $d    = $fullSections[$i] ?? body($r);
    $cls  = (string) ($d['className'] ?? '');
    $sec  = (string) ($d['section'] ?? '');
    $sess = (string) ($d['session'] ?? '');
    // D17: a configured POSITIVE maxStrength wins; absent / 0 / negative => 40.
    $raw  = (int) ($d['maxStrength'] ?? $d['max_strength'] ?? 0);
    $rows[] = [
        'docId'  => rowId($r),
        'class'  => $cls,
        'sec'    => $sec,
        'sess'   => $sess,
        'rawMax' => $raw,
        'eff'    => $raw > 0 ? $raw : 40,
        'occ'    => rosterCount($students, $cls, $sec, $sess),
        'cur'    => (int) ($d['currentStrength'] ?? -1),
        'utime'  => (string) ($d['__updateTime'] ?? ''),
        'casAt'  => (string) ($d['seatCasAt'] ?? ''),
    ];
}
usort($rows, fn($a, $b) => [$a['sess'], $a['class'], $a['sec']] <=> [$b['sess'], $b['class'], $b['sec']]);

printf("%-14s %-11s %-8s %6s %6s %5s %5s  %-32s %s\n",
    'CLASS', 'SECTION', 'SESSION', 'rawMax', 'effMax', 'OCC', 'curSt', '__updateTime', 'seatCasAt');
foreach ($rows as $r) {
    printf("%-14s %-11s %-8s %6d %6d %5d %5d  %-32s %s\n",
        $r['class'], $r['sec'], $r['sess'], $r['rawMax'], $r['eff'],
        $r['occ'], $r['cur'], $r['utime'], $r['casAt']);
}

// ── students, for identity reconciliation ───────────────────────────────────
$bySession = $active = [];
$maxStu = 0; $srSeen = []; $admSeen = []; $ids = [];
foreach ($students as $d) {
    $sess = (string) ($d['session'] ?? '(blank)');
    $bySession[$sess] = ($bySession[$sess] ?? 0) + 1;
    $st = trim((string) ($d['status'] ?? $d['Status'] ?? 'Active'));
    if ($st === '' || strcasecmp($st, 'Active') === 0) $active[$sess] = ($active[$sess] ?? 0) + 1;
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid !== '') $ids[] = $sid;
    if (preg_match('/(\d+)$/', $sid, $m)) $maxStu = max($maxStu, (int) $m[1]);
    foreach (['srNo', 'srNumber', 'SR Number'] as $k) {
        if (!empty($d[$k])) { $srSeen[(string) $d[$k]] = true; break; }
    }
    foreach (['admissionNo', 'admissionNumber', 'Admission No'] as $k) {
        if (!empty($d[$k])) { $admSeen[(string) $d[$k]] = true; break; }
    }
}
sort($ids);
echo "\nSTUDENTS BY SESSION (total / active):\n";
foreach ($bySession as $k => $v) printf("  %-12s %5d / %d\n", $k, $v, $active[$k] ?? 0);
printf("\nhighest numeric suffix in studentId : STU%04d\n", $maxStu);
printf("distinct srNo values present        : %d\n", count($srSeen));
printf("distinct admission numbers present  : %d\n", count($admSeen));
echo "studentIds: " . implode(', ', $ids) . "\n";

// ── identity counters / allocators ──────────────────────────────────────────
foreach ([
    ['counters', SCHOOL], ['srNoCounters', SCHOOL], ['idCounters', SCHOOL],
    ['schoolControl', SCHOOL], ['numbering', SCHOOL],
] as [$col, $id]) {
    $doc = $fs->getDocument($col, $id);
    if (is_array($doc)) {
        $keep = array_diff_key($doc, array_flip(['__updateTime']));
        echo "\nCOUNTER/CONTROL {$col}/{$id}:\n  " . json_encode($keep) . "\n";
    }
}

echo "\n── end snapshot [{$label}] ──\n";
