<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BILLING V2 · ESTATE-WIDE LEDGER READINESS · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/billing_v2_estate_readiness.php
 *
 * ZenX is a multi-tenant ERP. `ledger_activation_readiness.php` answers the
 * question for ONE named tenant, which is right for a canary but useless for
 * planning a rollout: it cannot say how many other schools would need a
 * backfill, or which ones would break if the flag were ever set globally.
 *
 * This walks every school and reports, per tenant, the numbers that decide
 * whether the allocation ledger can be granted there:
 *
 *   demands · discounted demands · allocations · I2 violations · grant state
 *
 * A tenant with discounted demands and no allocations needs a backfill BEFORE
 * a grant. A tenant with no demands at all is not a rollout candidate yet.
 *
 * There is no write path in this file.
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Firestore_rest_client.php';
$creds = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$c     = new FirestoreRestClient($creds, 'graderadmin', '(default)');
$body  = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "BILLING V2 — ESTATE-WIDE ALLOCATION LEDGER READINESS\n";
echo str_repeat('=', 96) . "\n\n";

// ── Every tenant ──────────────────────────────────────────────────────────
$schools = [];
foreach ($c->query('schools', []) as $r) {
    $d = $body($r);
    $id = trim((string) ($d['schoolId'] ?? $d['id'] ?? ($r['id'] ?? '')));
    if ($id === '') continue;
    $schools[$id] = trim((string) ($d['schoolName'] ?? $d['name'] ?? ''));
}
ksort($schools);
echo 'tenants found: ' . count($schools) . "\n\n";

// ── Per-tenant grant state ────────────────────────────────────────────────
$grants = [];
foreach ($c->query('schoolControl', []) as $r) {
    $d  = $body($r);
    $id = trim((string) ($d['schoolId'] ?? ($r['id'] ?? '')));
    $f  = is_array($d['rolloutFlags'] ?? null) ? $d['rolloutFlags'] : [];
    $g  = $f['USE_ALLOCATION_LEDGER'] ?? null;
    $grants[$id] = is_array($g) ? !empty($g['enabled']) : (bool) $g;
}

printf("%-20s %-26s %8s %8s %8s %8s  %s\n",
    'SCHOOL', 'NAME', 'DEMANDS', 'DISC', 'ALLOCS', 'I2-VIOL', 'LEDGER');
echo str_repeat('-', 96) . "\n";

$needBackfill = []; $clean = []; $noData = [];

foreach ($schools as $id => $name) {
    $demands = [];
    foreach ($c->query('feeDemands', [['schoolId', '==', $id]]) as $r) $demands[] = $body($r);

    $allocs = [];
    foreach ($c->query('entitlementAllocations', [['schoolId', '==', $id]]) as $r) $allocs[] = $body($r);

    $byDemand = [];
    foreach ($allocs as $a) {
        if (strtolower((string) ($a['status'] ?? 'active')) !== 'active') continue;
        $did = (string) ($a['demandId'] ?? '');
        if ($did !== '') $byDemand[$did] = ($byDemand[$did] ?? 0) + (float) ($a['allocatedAmount'] ?? 0);
    }

    $disc = 0; $viol = 0;
    foreach ($demands as $d) {
        $x = round((float) ($d['discountAmount'] ?? 0), 2);
        if ($x <= 0.005) continue;
        $disc++;
        if (abs(($byDemand[(string) ($d['demandId'] ?? '')] ?? 0) - $x) > 0.005) $viol++;
    }

    $granted = !empty($grants[$id]);
    printf("%-20s %-26s %8d %8d %8d %8d  %s\n",
        $id, mb_strimwidth($name, 0, 26, ''), count($demands), $disc, count($allocs), $viol,
        $granted ? 'GRANTED' : '-');

    if (count($demands) === 0)      $noData[] = $id;
    elseif ($viol > 0)              $needBackfill[] = "{$id} ({$viol})";
    else                            $clean[] = $id;
}

echo "\n" . str_repeat('=', 96) . "\n";
echo "  ledger GRANTED to        : " . (count(array_filter($grants)) ?: 0)
    . ' tenant(s) — ' . (implode(', ', array_keys(array_filter($grants))) ?: 'none') . "\n";
echo "  no demands yet           : " . (implode(', ', $noData) ?: 'none') . "\n";
echo "  clean, grantable now     : " . (implode(', ', $clean) ?: 'none') . "\n";
echo "  BACKFILL REQUIRED FIRST  : " . (implode(', ', $needBackfill) ?: 'none') . "\n\n";
echo "A tenant listed under BACKFILL REQUIRED must be backfilled and certified\n";
echo "before it is granted. Granting it first would enforce an invariant its\n";
echo "historical demands already violate.\n";
echo "No documents were written by this script.\n";
