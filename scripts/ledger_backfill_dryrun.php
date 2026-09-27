<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING V2 ALLOCATION LEDGER — HISTORICAL BACKFILL · DRY RUN · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/ledger_backfill_dryrun.php <SCHOOL_ID> [SESSION]
 *
 * Answers, against real data and WITHOUT WRITING ANYTHING: exactly which
 * allocation rows a historical backfill would create, which demands it would
 * refuse, and whether the totals reconcile to the penny.
 *
 * ── WHY A DRY RUN EXISTS AT ALL, AND FIRST ────────────────────────────────
 * `entitlementAllocations` is a MONEY LEDGER. A backfill that guesses a split
 * writes a permanent, auditable falsehood about why a family was charged what
 * they were charged. The dry run is the artefact that makes the subsequent
 * authorized write a confirmation rather than a leap.
 *
 * ── THE MODEL (reconstructed from source, not assumed) ────────────────────
 * Invariant I2 (Billing_v2_verifier): a demand carrying `discountAmount > 0`
 * must have allocation rows that EXACTLY explain it —
 *     discountAmount == SUM(allocatedAmount WHERE status == 'active')
 *
 * An allocation row's identity is deterministic
 * (Entitlement_allocation_service::allocationId):
 *     {schoolId}_{demandId}_{entitlementId}
 * which is what makes any backfill idempotent by construction: a re-run
 * collides on the same id rather than duplicating.
 *
 * The per-entitlement split is NOT stored on the demand — `discountAmount` is
 * a total. It is RECOMPUTED with the same arithmetic the generator uses
 * (Fee_generation_service, "PER-ENTITLEMENT CONTRIBUTIONS"):
 *     percent     → gross * (value / 100)
 *     fixed       → value
 *     fullExempt  → the whole charge
 * applied only by entitlements whose scope matches the demand.
 *
 * ── THE AMBIGUITY RULE — THIS IS THE HEART OF IT ──────────────────────────
 * Recomputation uses TODAY's entitlements. If a grant was edited, revoked or
 * added since the demand was priced, the recomputed split will not equal the
 * stored `discountAmount`. That demand's history is then NOT recoverable from
 * present state, and this tool REFUSES it — it is reported as an anomaly for a
 * human, never rounded, never apportioned, never "close enough".
 *
 * Writing a plausible-but-wrong split would satisfy the invariant while
 * recording a lie. A missing row is visible; a wrong row is not.
 *
 * ── SAFETY ────────────────────────────────────────────────────────────────
 * There is no write path in this file: no createDocument, no patchDocument, no
 * commitBatch. It reads `feeDemands`, `studentEntitlements` and
 * `entitlementAllocations` for ONE named tenant and prints. The tenant is a
 * required argument — never hardcoded.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

$schoolId = trim((string) ($argv[1] ?? ''));
$session  = trim((string) ($argv[2] ?? ''));
if ($schoolId === '') {
    fwrite(STDERR, "usage: php scripts/ledger_backfill_dryrun.php <SCHOOL_ID> [SESSION]\n");
    exit(2);
}

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$creds = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$c     = new FirestoreRestClient($creds, 'graderadmin', '(default)');
$body  = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

const EPS = 0.005;   // same tolerance the allocation service uses

echo "BILLING V2 LEDGER BACKFILL — DRY RUN (READ ONLY)\n";
echo "tenant : {$schoolId}" . ($session !== '' ? "  session: {$session}" : '') . "\n";
echo str_repeat('=', 74) . "\n\n";

// ── Load the tenant's entitlements ────────────────────────────────────────
$ents = [];
foreach ($c->query('studentEntitlements', [['schoolId', '==', $schoolId]]) as $r) {
    $e = $body($r);
    $ents[] = $e;
}
$activeEnts = array_values(array_filter($ents, function ($e) {
    return strtolower(trim((string) ($e['status'] ?? ''))) === 'active';
}));
echo "entitlements          : " . count($ents) . " total · " . count($activeEnts) . " active\n";

// ── Existing allocations, indexed by the deterministic id ─────────────────
$existing = [];
foreach ($c->query('entitlementAllocations', [['schoolId', '==', $schoolId]]) as $r) {
    $a = $body($r);
    $id = (string) ($a['allocationId'] ?? '');
    if ($id !== '') $existing[$id] = $a;
}
echo "existing allocations  : " . count($existing) . "\n";

// ── The generator's own arithmetic, mirrored ──────────────────────────────
// Deliberately a MIRROR, and said so plainly: the production algorithm lives
// inside a private method on Fee_generation_service and cannot be called
// without the framework. The WRITER must reuse the real service rather than
// this copy; this copy exists only to predict, and any disagreement between
// prediction and the writer's own build() is itself a finding.
$typeOf  = function (array $e) { return strtolower(trim((string) ($e['valueType'] ?? ''))); };
$valueOf = function (array $e) { return (float) ($e['value'] ?? 0); };

/** Does this grant apply to this demand? Scope-matching, conservative. */
$applies = function (array $e, array $d) {
    $eStu = trim((string) ($e['studentId'] ?? ''));
    if ($eStu !== '' && $eStu !== trim((string) ($d['studentId'] ?? ''))) return false;

    $eSess = trim((string) ($e['session'] ?? ''));
    $dSess = trim((string) ($d['session'] ?? ''));
    if ($eSess !== '' && $dSess !== '' && $eSess !== $dSess) return false;

    // Head scoping, when the grant names one.
    $eHead = trim((string) ($e['feeHeadRef'] ?? $e['feeHeadId'] ?? ''));
    if ($eHead !== '') {
        $dHead = trim((string) ($d['feeHeadRef'] ?? $d['feeHeadId'] ?? ''));
        if ($dHead !== '' && $eHead !== $dHead) return false;
    }
    return true;
};

$demands = [];
$filters = [['schoolId', '==', $schoolId]];
if ($session !== '') $filters[] = ['session', '==', $session];
foreach ($c->query('feeDemands', $filters) as $r) $demands[] = $body($r);
echo "demands scanned       : " . count($demands) . "\n\n";

$eligible = 0; $wouldCreate = 0; $alreadyOk = 0; $noDiscount = 0;
$anomalies = []; $plan = []; $sumDiscount = 0.0; $sumAllocated = 0.0;

foreach ($demands as $d) {
    $did  = trim((string) ($d['demandId'] ?? ''));
    $disc = round((float) ($d['discountAmount'] ?? 0), 2);
    $gross = round((float) ($d['grossAmount'] ?? 0), 2);

    if ($disc <= EPS) { $noDiscount++; continue; }
    $eligible++;
    $sumDiscount += $disc;

    if ($did === '') {
        $anomalies[] = ['demandId' => '(missing)', 'reason' => 'demand has no demandId — cannot key an allocation'];
        continue;
    }

    // Already explained?
    $have = 0.0;
    foreach ($existing as $id => $a) {
        if ((string) ($a['demandId'] ?? '') !== $did) continue;
        if (strtolower((string) ($a['status'] ?? 'active')) !== 'active') continue;
        $have += (float) ($a['allocatedAmount'] ?? 0);
    }
    if (abs($have - $disc) <= EPS) { $alreadyOk++; $sumAllocated += $have; continue; }

    // Recompute the split from present entitlements.
    $contribs = [];
    $total = 0.0; $fullExempt = false;
    foreach ($activeEnts as $e) {
        if (!$applies($e, $d)) continue;
        $t = $typeOf($e); $v = $valueOf($e);
        if ($t === 'fullexempt') { $fullExempt = true; $contribs = [['ent' => $e, 'amount' => $gross]]; $total = $gross; break; }
        if ($t === 'percent')    { $p = round($gross * ($v / 100.0), 2); $contribs[] = ['ent' => $e, 'amount' => $p]; $total += $p; continue; }
        if ($t === 'fixed')      { $contribs[] = ['ent' => $e, 'amount' => round($v, 2)]; $total += round($v, 2); continue; }
        $anomalies[] = ['demandId' => $did, 'reason' => "entitlement valueType '{$t}' is not an algorithm this tool can apply"];
    }
    $total = round($total, 2);

    if ($contribs === []) {
        $anomalies[] = ['demandId' => $did, 'reason' => "carries a discount of {$disc} but NO current entitlement applies — history not recoverable from present state"];
        continue;
    }
    if (abs($total - $disc) > EPS) {
        $anomalies[] = ['demandId' => $did, 'reason' => "recomputed split {$total} != stored discountAmount {$disc} — a grant changed since pricing; REFUSED (never apportioned)"];
        continue;
    }

    foreach ($contribs as $ct) {
        $eid = trim((string) ($ct['ent']['entitlementId'] ?? ''));
        if ($eid === '') { $anomalies[] = ['demandId' => $did, 'reason' => 'entitlement has no entitlementId']; continue 2; }
        $allocId = $schoolId . '_' . $did . '_' . $eid;
        if (isset($existing[$allocId])) continue;          // idempotent: already there
        $plan[] = ['allocationId' => $allocId, 'demandId' => $did, 'entitlementId' => $eid,
                   'amount' => $ct['amount'], 'basis' => $gross];
        $wouldCreate++;
        $sumAllocated += $ct['amount'];
    }
}

echo "── PLAN ──────────────────────────────────────────────────────────────\n";
echo "  demands with no discount (I2 silent) : {$noDiscount}\n";
echo "  demands carrying a discount          : {$eligible}\n";
echo "  already fully explained              : {$alreadyOk}\n";
echo "  allocation rows WOULD BE CREATED     : {$wouldCreate}\n";
echo "  anomalies (REFUSED, need a human)    : " . count($anomalies) . "\n\n";

if ($plan) {
    echo "── ROWS THAT WOULD BE WRITTEN (first 30) ─────────────────────────────\n";
    foreach (array_slice($plan, 0, 30) as $p) {
        printf("  %s  demand=%s ent=%s  amount=%.2f of basis %.2f\n",
            $p['allocationId'], $p['demandId'], $p['entitlementId'], $p['amount'], $p['basis']);
    }
    if (count($plan) > 30) echo '  … and ' . (count($plan) - 30) . " more\n";
    echo "\n";
}

if ($anomalies) {
    echo "── ANOMALIES · NOTHING WILL BE WRITTEN FOR THESE ─────────────────────\n";
    foreach (array_slice($anomalies, 0, 30) as $a) {
        echo "  demand={$a['demandId']}\n    {$a['reason']}\n";
    }
    if (count($anomalies) > 30) echo '  … and ' . (count($anomalies) - 30) . " more\n";
    echo "\n";
}

echo "── RECONCILIATION ────────────────────────────────────────────────────\n";
printf("  sum(discountAmount) over discounted demands : %.2f\n", $sumDiscount);
printf("  sum(allocatedAmount) after backfill         : %.2f\n", $sumAllocated);
$delta = round($sumDiscount - $sumAllocated, 2);
printf("  unexplained remainder                       : %.2f%s\n", $delta,
    abs($delta) <= EPS ? '  (reconciles exactly)' : '  <-- carried by the refused demands above');
echo "\n";

$ready = ($anomalies === []);
echo $ready
    ? "DRY RUN CLEAN — a backfill would fully satisfy I2 for this tenant.\n"
    : "DRY RUN INCOMPLETE — " . count($anomalies) . " demand(s) cannot be explained from present state.\n";
echo "No documents were written by this script.\n";
exit($ready ? 0 : 1);
