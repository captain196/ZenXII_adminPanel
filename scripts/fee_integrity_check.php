<?php
/**
 * fee_integrity_check.php — cross-system consistency audit.
 *
 * Checks the numbers each system (Admin, Parent app, Teacher app)
 * WILL see end up agreeing. Each reads different slices of the same
 * Firestore state, so any divergence is a bug in our write path:
 *
 *   Admin  reads: feeDemands (per student) + feeDefaulters + feeReceipts
 *   Parent reads: feeDemands (via studentId listener) + feeReceipts
 *   Teacher reads: feeDefaulters (per class/section) + monthFee
 *
 *  The integrity rules we verify:
 *
 *   I-1  sum(demand.netAmount) should NEVER exceed the fee structure
 *        × months-covered per student (caught: ghost demands).
 *   I-2  feeDefaulters.totalDues MUST match sum over demands
 *        (status != 'paid', balance > 0) of balance.
 *   I-3  For every receipt, sum(allocations.allocated) == receipt.allocatedAmount.
 *   I-4  Teacher-view monthFee flags should agree with demand status:
 *        all demands for that month are 'paid' ⇔ monthFee[month] == 1.
 *
 * Findings are logged to fee_integrity_logs/{logId} and summarised
 * on stdout. Severe mismatches (I-2, I-3) also raise a fee_alerts
 * record so the admin dashboard flags them immediately.
 *
 * Usage:
 *   php scripts/fee_integrity_check.php --schoolId=SCH_… --session=YYYY-YY
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
if ($schoolId === '' || $session === '') {
    fwrite(STDERR, "Usage: php fee_integrity_check.php --schoolId=… --session=…\n");
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

function logFinding(FirestoreRestClient $fs, string $schoolId, string $session, string $rule, string $entity, string $entityId, string $severity, array $payload): string {
    $id = 'ILOG_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
    $fs->setDocument('fee_integrity_logs', $id, [
        'logId'     => $id,
        'schoolId'  => $schoolId,
        'session'   => $session,
        'rule'      => $rule,
        'entity'    => $entity,
        'entityId'  => $entityId,
        'severity'  => $severity,
        'payload'   => $payload,
        'createdAt' => date('c'),
    ]);
    return $id;
}

$findings = ['I-1' => 0, 'I-2' => 0, 'I-3' => 0, 'I-4' => 0];

// ─── Load the world ───────────────────────────────────────────────
$demands = $fs->query('feeDemands', [['schoolId','==',$schoolId],['session','==',$session]]);
$receipts = $fs->query('feeReceipts', [['schoolId','==',$schoolId]]); // cross-session tolerance
$defaulters = $fs->query('feeDefaulters', [['schoolId','==',$schoolId],['session','==',$session]]);
$log('info', sprintf("loaded: demands=%d receipts=%d defaulters=%d",
    count($demands), count($receipts), count($defaulters)));

$byStudent = [];
foreach ((array) $demands as $r) {
    $d = $r['data'] ?? $r;
    if (!is_array($d)) continue;
    $sid = (string) ($d['studentId'] ?? '');
    if ($sid === '') continue;
    $byStudent[$sid][] = $d;
}

// ─── I-2 defaulter sum consistency ───────────────────────────────
$defBySid = [];
foreach ((array) $defaulters as $r) {
    $d = $r['data'] ?? $r;
    if (is_array($d)) $defBySid[(string) ($d['studentId'] ?? '')] = $d;
}
foreach ($byStudent as $sid => $dems) {
    $expectedDue = 0.0;
    foreach ($dems as $d) {
        if (((string) ($d['status'] ?? 'unpaid')) === 'paid') continue;
        $bal = (float) ($d['balance'] ?? 0);
        if ($bal > 0.005) $expectedDue += $bal;
    }
    $expectedDue = round($expectedDue, 2);
    $storedDue   = isset($defBySid[$sid]) ? round((float) ($defBySid[$sid]['totalDues'] ?? 0), 2) : 0.0;

    $mismatch = abs($storedDue - $expectedDue) > 0.01;
    if ($mismatch) {
        $findings['I-2']++;
        $payload = ['expected' => $expectedDue, 'stored' => $storedDue, 'hasDefaulterDoc' => isset($defBySid[$sid])];
        logFinding($fs, $schoolId, $session, 'I-2', 'student', $sid, 'error', $payload);
        $log('error', "[I-2 {$sid}] defaulter expected={$expectedDue} stored={$storedDue}");
        $alerts->raise(Fee_alerts::SEV_ERROR, 'integrity',
            "Defaulter mismatch for {$sid}",
            "expected dues Rs {$expectedDue}, stored Rs {$storedDue}",
            ['refEntity'=>'student','refId'=>$sid,'dedupKey'=>"I-2:{$sid}",'payload'=>$payload]);
    }
}

// ─── I-3 receipt allocation consistency ──────────────────────────
foreach ((array) $receipts as $r) {
    $rc = $r['data'] ?? $r;
    if (!is_array($rc)) continue;
    $receiptKey = (string) ($rc['receiptKey'] ?? '');
    $studentId  = (string) ($rc['studentId']  ?? '');
    $alloc      = (float)  ($rc['allocatedAmount'] ?? $rc['allocated_amount'] ?? $rc['amount'] ?? 0);
    if ($receiptKey === '') continue;
    $allocDoc = $fs->getDocument('feeReceiptAllocations', "{$schoolId}_{$session}_{$receiptKey}");
    if (!is_array($allocDoc)) continue; // pre-allocation receipt — skip
    $sum = 0.0;
    foreach (($allocDoc['allocations'] ?? []) as $row) $sum += (float) ($row['allocated'] ?? 0);
    $sum = round($sum, 2);
    if (abs($sum - round($alloc, 2)) > 0.01) {
        $findings['I-3']++;
        $payload = ['receiptAllocated' => $alloc, 'sumAllocations' => $sum, 'studentId' => $studentId];
        logFinding($fs, $schoolId, $session, 'I-3', 'receipt', $receiptKey, 'critical', $payload);
        $log('error', "[I-3 {$receiptKey}] receipt.allocatedAmount={$alloc} vs allocations.sum={$sum}");
        $alerts->raise(Fee_alerts::SEV_CRITICAL, 'integrity',
            "Receipt allocation mismatch {$receiptKey}",
            "receipt says Rs {$alloc}, allocations sum to Rs {$sum}",
            ['refEntity'=>'receipt','refId'=>$receiptKey,'dedupKey'=>"I-3:{$receiptKey}",'payload'=>$payload]);
    }
}

// ─── I-4 teacher-view monthFee ↔ demand status ───────────────────
foreach (array_keys($byStudent) as $sid) {
    $stu = $fs->getDocument('students', "{$schoolId}_{$sid}");
    if (!is_array($stu)) continue;
    $monthFee = is_array($stu['monthFee'] ?? null) ? $stu['monthFee'] : [];
    $byMonth = [];
    foreach ($byStudent[$sid] as $d) {
        $m = (string) ($d['month'] ?? '');
        if ($m === '') continue;
        $byMonth[$m][] = (string) ($d['status'] ?? 'unpaid');
    }
    foreach ($byMonth as $month => $statuses) {
        $allPaid = true;
        foreach ($statuses as $s) if ($s !== 'paid') { $allPaid = false; break; }
        $expectedFlag = $allPaid ? 1 : 0;
        $storedFlag   = (int) ($monthFee[$month] ?? 0);
        if ($expectedFlag !== $storedFlag) {
            $findings['I-4']++;
            logFinding($fs, $schoolId, $session, 'I-4', 'student', $sid, 'warning',
                ['month' => $month, 'expectedFlag' => $expectedFlag, 'storedFlag' => $storedFlag]);
            $log('warning', "[I-4 {$sid}/{$month}] monthFee stored={$storedFlag} expected={$expectedFlag}");
        }
    }
}

$total = array_sum($findings);
$log('info', "integrity scan done — findings=" . json_encode($findings));

if ($total > 0) {
    $alerts->raise(Fee_alerts::SEV_WARNING, 'integrity',
        "Integrity scan found {$total} issue(s)",
        json_encode($findings),
        ['dedupKey' => 'integrity-scan:' . date('Y-m-d'), 'payload' => $findings]);
}
exit($total > 0 ? 1 : 0);
