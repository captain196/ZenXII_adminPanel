<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING V2 — ESTATE INVARIANT SWEEP · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/billing_v2_estate_sweep.php [SCHOOL_ID]
 *
 * Runs the REAL production predicates over the live estate:
 *
 *   • `Fee_firestore_txn::moneyIncoherence()`      — net == gross − discount
 *   • `Entitlement_allocation_service::validateForDemand()` — the P4 half of the
 *     composed invariant, wherever allocation rows actually exist
 *
 * Both are the production implementations, not restatements of them. A sweep
 * that re-implements the rule proves only that two copies of a belief agree.
 *
 * With no argument it sweeps every tenant. With a SCHOOL_ID it sweeps that
 * tenant only. **No school is hard-coded**; the canary is a command-line choice.
 *
 * This is the standing evidence for readiness gates 1 and 7, promoted out of a
 * scratch directory because evidence that cannot be re-run is not evidence.
 *
 * Exit 0 = clean · 1 = real violations found.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';
require_once APPPATH . 'libraries/Fee_firestore_txn.php';
require_once APPPATH . 'libraries/Entitlement_allocation_service.php';

$only = trim((string) ($argv[1] ?? ''));
$c    = new FirestoreRestClient(
    APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json',
    'graderadmin', '(default)');
$body = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "BILLING V2 — ESTATE INVARIANT SWEEP\n";
echo 'scope: ' . ($only !== '' ? $only : 'ALL TENANTS') . "\n";
echo str_repeat('=', 74) . "\n\n";

// ── 1 · money coherence over every demand ─────────────────────────────────
$demands = $c->query('feeDemands', $only !== '' ? [['schoolId', '==', $only]] : []);

$total = 0; $real = 0; $load = 0; $violLoad = 0;
$viol  = [];
$byTenant = [];
$demandsById = [];

foreach ($demands as $r) {
    $d  = $body($r);
    $id = (string) ($r['id'] ?? '?');
    $total++;

    // `DEM_TEST_LOAD_*` are synthetic residue from an old load test. They are
    // counted and reported separately rather than deleted or silently skipped —
    // a sweep that quietly drops rows is how a real violation stays hidden.
    $isLoad = (strpos($id, 'DEM_TEST_LOAD_') === 0);
    $isLoad ? $load++ : $real++;

    $sid = (string) ($d['schoolId'] ?? '?');
    if (!$isLoad) $byTenant[$sid] = ($byTenant[$sid] ?? 0) + 1;
    $demandsById[(string) ($d['demandId'] ?? $id)] = $d;

    $bad = Fee_firestore_txn::moneyIncoherence($d);
    if ($bad === null) continue;
    if ($isLoad) { $violLoad++; continue; }

    $viol[] = sprintf('  %s student=%s school=%s head=%s period=%s gross=%.2f disc=%.2f net=%.2f delta=%.2f',
        $id, (string) ($d['studentId'] ?? '?'), $sid,
        (string) ($d['feeHead'] ?? '?'), (string) ($d['periodKey'] ?? '?'),
        $bad['gross'], $bad['discount'], $bad['net'], $bad['delta']);
}

echo "── MONEY COHERENCE (net == gross − discount) " . str_repeat('─', 30) . "\n";
printf("  swept      : %d demands  (%d real · %d synthetic load-test)\n", $total, $real, $load);
printf("  tenants    : %d\n", count($byTenant));
foreach ($byTenant as $sid => $n) printf("      %-20s %d\n", $sid, $n);
printf("  VIOLATIONS among real demands: %d\n", count($viol));
foreach (array_slice($viol, 0, 20) as $v) echo $v . "\n";
if ($violLoad > 0) printf("  (%d violation(s) among synthetic load-test docs — ignored)\n", $violLoad);

// ── 2 · allocation ledger, wherever rows exist ────────────────────────────
echo "\n── ALLOCATION LEDGER (P4) " . str_repeat('─', 49) . "\n";
$allocRows = [];
try {
    $allocRows = $c->query('entitlementAllocations', $only !== '' ? [['schoolId', '==', $only]] : []);
} catch (\Throwable $e) {
    echo "  query failed: " . $e->getMessage() . "\n";
}
printf("  rows: %d\n", count($allocRows));

$allocViol = [];
if ($allocRows === []) {
    echo "  nothing to validate — the ledger flag has never been active.\n";
    echo "  This is the EXPECTED state, and it is precisely why readiness gate 7\n";
    echo "  cannot be closed: absence of rows is not evidence of correctness.\n";
} else {
    // Group by demand and run the real cross-row validator.
    $byDemand = [];
    foreach ($allocRows as $r) {
        $d = $body($r);
        $byDemand[(string) ($d['demandId'] ?? '')][] = $d;
    }
    foreach ($byDemand as $did => $rows) {
        $demand = $demandsById[$did] ?? null;
        if ($demand === null) {
            $allocViol[] = "  orphan: allocation rows reference unknown demand {$did}";
            continue;
        }
        $svc = (new Entitlement_allocation_service())->init(
            null, null, (string) ($demand['schoolId'] ?? ''),
            (string) ($demand['session'] ?? ''), 'sweep');
        $bad = $svc->validateForDemand($demand, $rows);
        if ($bad !== null) {
            $allocViol[] = sprintf('  %s %s expected=%.2f actual=%.2f delta=%.2f',
                $did, $bad['reason'], $bad['expected'], $bad['actual'], $bad['delta']);
        }
    }
    printf("  demands covered: %d\n", count($byDemand));
    printf("  VIOLATIONS: %d\n", count($allocViol));
    foreach (array_slice($allocViol, 0, 20) as $v) echo $v . "\n";
}

// ── verdict ───────────────────────────────────────────────────────────────
$fail = count($viol) + count($allocViol);
echo "\n" . str_repeat('=', 74) . "\n";
if ($fail === 0) {
    echo "VERDICT: the invariants hold across the swept estate.\n";
    if ($allocRows === []) {
        echo "         NOTE — this is estate cleanliness, NOT proof that the ledger\n";
        echo "         works in production. No allocation rows exist to validate.\n";
    }
} else {
    echo "VERDICT: {$fail} real violation(s) — investigate before any activation.\n";
}
echo "No documents were written by this script.\n";

exit($fail === 0 ? 0 : 1);
