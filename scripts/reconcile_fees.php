<?php
/**
 * reconcile_fees.php — nightly reconciliation for demands + defaulters.
 *
 * Purpose: cross-check every student's demand totals against their
 * receipts + the defaulter doc. Discrepancies are (a) rewritten
 * correctly and (b) logged as fee_alerts so the operator sees them.
 *
 * Invariants enforced:
 *   1. For every student:
 *        sum(demand.paidAmount)    == sum(receiptAllocations.allocated)
 *   2. feeDefaulters.totalDues for each student
 *        == sum over demands where status != 'paid' of max(0, balance)
 *   3. feeDefaulters doc exists iff (2) > 0; absent otherwise
 *
 * Safe to run concurrently with live payments — this script reads +
 * writes defaulter docs only, never touches demand payment state.
 *
 * Usage:
 *   php scripts/reconcile_fees.php --schoolId=SCH_… --session=YYYY-YY [--dry-run]
 */

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(2); }

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (strncmp($a, '--', 2) !== 0) continue;
    $a = substr($a, 2);
    $eq = strpos($a, '=');
    if ($eq === false) $opts[$a] = true;
    else $opts[substr($a, 0, $eq)] = substr($a, $eq + 1);
}
$schoolId = (string) ($opts['schoolId'] ?? '');
$session  = (string) ($opts['session']  ?? '');
$dryRun   = !empty($opts['dry-run']);
if ($schoolId === '' || $session === '') {
    fwrite(STDERR, "Usage: php reconcile_fees.php --schoolId=… --session=… [--dry-run]\n");
    exit(2);
}

$root = realpath(__DIR__ . '/..');
$credPath = $root . '/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$cred = json_decode(file_get_contents($credPath), true) ?: [];
$projectId = (string) ($cred['project_id'] ?? '');
if ($projectId === '') { fwrite(STDERR, "project_id missing\n"); exit(2); }

require_once $root . '/application/libraries/Firestore_rest_client.php';
require_once $root . '/application/libraries/Fee_alerts.php';

$fs     = new FirestoreRestClient($credPath, $projectId, '(default)');
$alerts = new Fee_alerts(new class($fs) {
    private $fs;
    public function __construct($fs) { $this->fs = $fs; }
    public function firestoreSet($c, $id, $d, $merge = false)     { return $this->fs->setDocument($c, $id, $d, $merge); }
    public function firestoreQuery($c, $w = [], $ob=null, $dir='ASC', $l=null) { return $this->fs->query($c, $w, $ob, $dir, $l); }
}, $schoolId, $session);

$log = fn($lvl, $msg) => fwrite($lvl==='error' || $lvl==='warning' ? STDERR : STDOUT,
    sprintf("[%s] [%s] %s\n", date('H:i:s'), strtoupper($lvl), $msg));

$log('info', "reconcile start: schoolId={$schoolId} session={$session} dryRun=" . ($dryRun?'true':'false'));

// Load all demands for this school+session — it's the whole "world"
// from a fees perspective. Then bucket by student in-memory.
$demands = $fs->query('feeDemands', [
    ['schoolId', '==', $schoolId],
    ['session',  '==', $session],
]);
$log('info', "loaded " . count($demands) . " demands");

$byStudent = [];
foreach ((array) $demands as $r) {
    $d = $r['data'] ?? $r;
    if (!is_array($d)) continue;
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid === '') continue;
    if (!isset($byStudent[$sid])) $byStudent[$sid] = ['demands' => [], 'name' => '', 'class' => '', 'section' => ''];
    $byStudent[$sid]['demands'][] = $d;
    $byStudent[$sid]['name']    = (string) ($d['studentName'] ?? $byStudent[$sid]['name']);
    $byStudent[$sid]['class']   = (string) ($d['className']   ?? $byStudent[$sid]['class']);
    $byStudent[$sid]['section'] = (string) ($d['section']     ?? $byStudent[$sid]['section']);
}

$stats = ['students' => 0, 'dueMismatch' => 0, 'paidMismatch' => 0, 'defaulterFixed' => 0, 'defaulterDeleted' => 0];

foreach ($byStudent as $sid => $bucket) {
    $stats['students']++;
    // (2) Expected total dues from demands.
    $dueTotal = 0.0; $unpaidMonths = []; $paidSum = 0.0;
    foreach ($bucket['demands'] as $d) {
        $paidSum += (float) ($d['paidAmount'] ?? 0);
        if (((string) ($d['status'] ?? 'unpaid')) === 'paid') continue;
        $bal = (float) ($d['balance'] ?? 0);
        if ($bal <= 0.005) continue;
        $dueTotal += $bal;
        $m = (string) ($d['month'] ?? '');
        if ($m !== '' && !in_array($m, $unpaidMonths, true)) $unpaidMonths[] = $m;
    }
    $dueTotal = round($dueTotal, 2);

    // (1) Cross-check receipt allocations.
    $alloc = 0.0;
    $allocRows = $fs->query('feeReceiptAllocations', [
        ['schoolId',  '==', $schoolId],
        ['session',   '==', $session],
        ['studentId', '==', $sid],
    ]);
    foreach ((array) $allocRows as $ar) {
        $a = $ar['data'] ?? $ar;
        if (!is_array($a) || ($a['status'] ?? '') === 'reversed') continue;
        foreach (($a['allocations'] ?? []) as $row) {
            $alloc += (float) ($row['allocated'] ?? 0);
        }
    }
    $alloc = round($alloc, 2);

    if (abs($alloc - round($paidSum, 2)) > 0.01) {
        $stats['paidMismatch']++;
        $alerts->raise(Fee_alerts::SEV_ERROR, 'integrity',
            "Paid total mismatch for {$sid}",
            "demands.paidAmount sum={$paidSum} vs receiptAllocations.allocated sum={$alloc}",
            ['refEntity' => 'student', 'refId' => $sid,
             'dedupKey'  => "paid-mismatch:{$sid}",
             'payload'   => ['demandsPaidSum' => $paidSum, 'allocatedSum' => $alloc]]);
        $log('error', "[{$sid}] paid mismatch demands={$paidSum} allocations={$alloc}");
    }

    // (3) Defaulter doc matches computed state.
    $defDocId = "{$schoolId}_{$session}_{$sid}";
    $defDoc   = $fs->getDocument('feeDefaulters', $defDocId);
    $hasDef   = is_array($defDoc);
    $storedDue = $hasDef ? (float) ($defDoc['totalDues'] ?? 0) : 0.0;

    if ($dueTotal > 0.005) {
        // Should be a defaulter.
        $needsWrite = !$hasDef || abs($storedDue - $dueTotal) > 0.01;
        if ($needsWrite) {
            $stats['dueMismatch']++;
            $log('warning', "[{$sid}] defaulter OUT OF SYNC stored={$storedDue} expected={$dueTotal} → fixing");
            if (!$dryRun) {
                $fs->setDocument('feeDefaulters', $defDocId, [
                    'schoolId'       => $schoolId,
                    'session'        => $session,
                    'studentId'      => $sid,
                    'studentName'    => $bucket['name'],
                    'className'      => $bucket['class'],
                    'section'        => $bucket['section'],
                    'totalDues'      => $dueTotal,
                    'unpaidMonths'   => $unpaidMonths,
                    'overdueMonths'  => [],
                    'examBlocked'    => false,
                    'resultWithheld' => false,
                    'flaggedAt'      => date('c'),
                    'updatedAt'      => date('c'),
                    'reconciledAt'   => date('c'),
                ]);
                $stats['defaulterFixed']++;
            }
        }
    } else {
        // Should NOT be a defaulter.
        if ($hasDef) {
            $stats['dueMismatch']++;
            $log('warning', "[{$sid}] defaulter doc stale (dues cleared) → deleting");
            if (!$dryRun) {
                $fs->deleteDocument('feeDefaulters', $defDocId);
                $stats['defaulterDeleted']++;
            }
        }
    }
}

// Emit a roll-up alert if we found anything.
$totalIssues = $stats['dueMismatch'] + $stats['paidMismatch'];
if ($totalIssues > 0) {
    $alerts->raise(Fee_alerts::SEV_WARNING, 'integrity',
        "Nightly reconcile surfaced {$totalIssues} issue(s)",
        sprintf('students=%d dueMismatch=%d paidMismatch=%d fixed=%d deleted=%d dryRun=%s',
            $stats['students'], $stats['dueMismatch'], $stats['paidMismatch'],
            $stats['defaulterFixed'], $stats['defaulterDeleted'],
            $dryRun ? 'yes' : 'no'),
        ['dedupKey' => 'reconcile-nightly:' . date('Y-m-d'), 'payload' => $stats]);
}

$log('info', "reconcile done: " . json_encode($stats));
exit($totalIssues > 0 ? 1 : 0);
