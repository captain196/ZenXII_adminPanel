<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING V2 · FINANCIAL SNAPSHOT · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/billing_v2_financial_snapshot.php <SCHOOL_ID> [SESSION] [OUT.json]
 *
 * Captures everything the allocation backfill must NOT change, so that "no
 * historical money moved" is a comparison rather than an assurance.
 *
 * A backfill repairs the LEDGER REPRESENTATION of discounts already given. The
 * only collection it may add to is `entitlementAllocations`. Every figure
 * below — charges, discounts, payments, balances, receipts, vouchers — must be
 * byte-identical before and after. This file makes that checkable by diffing
 * two snapshots rather than by trusting the writer's own report.
 *
 * Per-demand fingerprints are included, not just totals: two demands could
 * offset each other and leave a total unchanged while both were altered.
 *
 * There is no write path in this file.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

$schoolId = trim((string) ($argv[1] ?? ''));
$session  = trim((string) ($argv[2] ?? ''));
$out      = trim((string) ($argv[3] ?? ''));
if ($schoolId === '') {
    fwrite(STDERR, "usage: php scripts/billing_v2_financial_snapshot.php <SCHOOL_ID> [SESSION] [OUT.json]\n");
    exit(2);
}

require_once APPPATH . 'libraries/Firestore_rest_client.php';
$creds = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$c     = new FirestoreRestClient($creds, 'graderadmin', '(default)');
$bodyOf = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

/**
 * ── SNAP-1 · AN EMPTY READ IS NOT EVIDENCE OF AN EMPTY COLLECTION ─────────
 *
 * The first run of this script reported 0 demands, 0 receipts, 0 of
 * everything — with `error = null` — for a tenant that demonstrably has 76
 * demands. A second, identical run returned all 76. The read had failed
 * transiently and returned an empty list instead of raising.
 *
 * That is the single most dangerous failure mode a before/after snapshot can
 * have. Had the empty result been taken as the BEFORE baseline, every
 * post-backfill comparison would have "passed" — 0 unchanged to 0, totals
 * matching trivially — and the proof that no historical money moved would have
 * been worthless while looking impeccable.
 *
 * It is the same silent-`[]` class this codebase has been bitten by before:
 * a query that never throws makes every piece of negative reasoning fail open.
 *
 * So each collection is read TWICE and the two reads must agree on their row
 * count. A disagreement means the transport is unreliable right now, which is
 * itself a reason not to proceed to a financial write.
 */
$read = function (string $col) use ($c, $schoolId, $session, $bodyOf) {
    $f = [['schoolId', '==', $schoolId]];
    if ($session !== '') $f[] = ['session', '==', $session];

    $once = function () use ($c, $col, $f, $bodyOf) {
        $rows = [];
        foreach ($c->query($col, $f) as $r) $rows[] = $bodyOf($r);
        return $rows;
    };

    try {
        $a = $once();
        $b = $once();
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage(), 'rows' => [], 'unstable' => false];
    }

    if (count($a) !== count($b)) {
        return ['error' => sprintf('UNSTABLE READ: two reads returned %d and %d rows',
            count($a), count($b)), 'rows' => $a, 'unstable' => true];
    }
    return ['error' => null, 'rows' => $a, 'unstable' => false];
};

$sum = function (array $rows, string $field) {
    $t = 0.0;
    foreach ($rows as $r) $t += (float) ($r[$field] ?? 0);
    return round($t, 2);
};

$snap = ['schoolId' => $schoolId, 'session' => $session, 'takenAt' => date('c')];

// ── Demands: the money the backfill must not touch ────────────────────────
$d = $read('feeDemands');
$demands = $d['rows'];
$fp = [];
foreach ($demands as $r) {
    $id = (string) ($r['demandId'] ?? '(none)');
    // Every monetary field plus the state that governs collectability.
    $fp[$id] = sprintf('g=%.2f d=%.2f n=%.2f p=%.2f b=%.2f f=%.2f s=%s',
        (float) ($r['grossAmount'] ?? 0), (float) ($r['discountAmount'] ?? 0),
        (float) ($r['netAmount'] ?? 0),   (float) ($r['paidAmount'] ?? 0),
        (float) ($r['balance'] ?? 0),     (float) ($r['fineAmount'] ?? 0),
        (string) ($r['status'] ?? ''));
}
ksort($fp);
$snap['demands'] = [
    'error' => $d['error'], 'count' => count($demands),
    'gross' => $sum($demands, 'grossAmount'), 'discount' => $sum($demands, 'discountAmount'),
    'net' => $sum($demands, 'netAmount'), 'paid' => $sum($demands, 'paidAmount'),
    'balance' => $sum($demands, 'balance'),
    'fingerprint' => hash('sha256', json_encode($fp)),
    'perDemand' => $fp,
];

// ── The ledger itself: the ONLY thing expected to change ──────────────────
$a = $read('entitlementAllocations');
$active = array_values(array_filter($a['rows'], fn($r) => strtolower((string) ($r['status'] ?? 'active')) === 'active'));
$snap['allocations'] = [
    'error' => $a['error'], 'count' => count($a['rows']),
    'active' => count($active), 'activeTotal' => $sum($active, 'allocatedAmount'),
    'ids' => array_values(array_map(fn($r) => (string) ($r['allocationId'] ?? ''), $a['rows'])),
];
sort($snap['allocations']['ids']);

// ── Everything else must be identical ─────────────────────────────────────
foreach ([
    'feeReceipts'            => ['amount', 'totalAmount'],
    'feeReceiptAllocations'  => ['amount', 'allocatedAmount'],
    'accountingLedger'       => ['amount', 'debit', 'credit'],
    'feeOnlinePayments'      => ['amount'],
    'feeDefaulters'          => ['outstanding', 'balance'],
] as $col => $fields) {
    $r = $read($col);
    $entry = ['error' => $r['error'], 'count' => count($r['rows'])];
    foreach ($fields as $f) $entry['sum_' . $f] = $sum($r['rows'], $f);
    $snap[$col] = $entry;
}

// ── I2 right now ──────────────────────────────────────────────────────────
$allocByDemand = [];
foreach ($active as $r) {
    $did = (string) ($r['demandId'] ?? '');
    if ($did !== '') $allocByDemand[$did] = ($allocByDemand[$did] ?? 0) + (float) ($r['allocatedAmount'] ?? 0);
}
$viol = 0;
foreach ($demands as $r) {
    $disc = round((float) ($r['discountAmount'] ?? 0), 2);
    if ($disc <= 0.005) continue;
    if (abs(($allocByDemand[(string) ($r['demandId'] ?? '')] ?? 0) - $disc) > 0.005) $viol++;
}
$snap['i2Violations'] = $viol;

// ── SNAP-1 GATE · REFUSE TO PRODUCE A BASELINE WE DO NOT TRUST ────────────
//
// A snapshot is only useful as the fixed point of a before/after comparison.
// If any read was unstable, or if the tenant reports zero demands (which is
// not a state a backfill target can legitimately be in), emitting the file
// anyway would hand the next phase a baseline that proves nothing.
//
// Exit non-zero and write NOTHING, so a caller chaining commands stops here.
$unstable = [];
foreach ($snap as $k => $v) {
    if (is_array($v) && !empty($v['unstable'])) $unstable[] = $k . ' (' . $v['error'] . ')';
    elseif (is_array($v) && !empty($v['error'])) $unstable[] = $k . ' (' . $v['error'] . ')';
}
if ($unstable !== []) {
    fwrite(STDERR, "REFUSING TO EMIT A SNAPSHOT — unreliable read(s):\n  "
        . implode("\n  ", $unstable) . "\nRe-run. Do not use a partial snapshot as a baseline.\n");
    exit(4);
}
if ($snap['demands']['count'] === 0) {
    fwrite(STDERR, "REFUSING TO EMIT A SNAPSHOT — 0 demands for {$schoolId}"
        . ($session !== '' ? " / {$session}" : '') . ".\n"
        . "An empty read is indistinguishable from an empty tenant, and a baseline of zero\n"
        . "makes every later comparison pass trivially. Verify the tenant/session, re-run.\n");
    exit(4);
}

$json = json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($out !== '') { file_put_contents($out, $json); }

printf("SNAPSHOT %s%s\n", $schoolId, $session !== '' ? " / {$session}" : '');
printf("  demands      : %d  gross=%.2f discount=%.2f net=%.2f paid=%.2f balance=%.2f\n",
    $snap['demands']['count'], $snap['demands']['gross'], $snap['demands']['discount'],
    $snap['demands']['net'], $snap['demands']['paid'], $snap['demands']['balance']);
printf("  demand fingerprint : %s\n", substr($snap['demands']['fingerprint'], 0, 32));
printf("  allocations  : %d (%d active, total %.2f)\n",
    $snap['allocations']['count'], $snap['allocations']['active'], $snap['allocations']['activeTotal']);
foreach (['feeReceipts', 'feeReceiptAllocations', 'accountingLedger', 'feeOnlinePayments', 'feeDefaulters'] as $col) {
    printf("  %-22s: %d\n", $col, $snap[$col]['count']);
}
printf("  I2 violations: %d\n", $snap['i2Violations']);
if ($out !== '') echo "  written to   : {$out}\n";
echo "No documents were written by this script.\n";
