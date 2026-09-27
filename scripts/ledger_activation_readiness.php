<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * USE_ALLOCATION_LEDGER — ACTIVATION READINESS CHECK · READ ONLY
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   php scripts/ledger_activation_readiness.php <SCHOOL_ID>
 *
 * Proves — against live production, for ONE named tenant — whether the flag's
 * activation prerequisites hold. **It cannot activate anything**: there is no
 * grant call and no write path in this file. Activation is
 * the tenant-grant surface, a separate authorized operation.
 *
 * The tenant is a REQUIRED ARGUMENT. No school id is hard-coded here, because
 * this check must stay usable for whichever tenant is being rolled out next —
 * the canary tenant is a choice made at the command line, not in the source.
 *
 * Exit 0 = every prerequisite holds (activation is *technically* unblocked;
 *          authorization is a separate matter)
 * Exit 1 = at least one prerequisite fails
 */

putenv('DISABLE_DOC_CACHE=1');
define('BASEPATH', __DIR__);
define('APPPATH', dirname(__DIR__) . '/application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

$schoolId = trim((string) ($argv[1] ?? ''));
if ($schoolId === '') {
    fwrite(STDERR, "usage: php scripts/ledger_activation_readiness.php <SCHOOL_ID>\n");
    fwrite(STDERR, "  the tenant is required — this check is deliberately not tenant-specific\n");
    exit(2);
}

require_once APPPATH . 'libraries/Firestore_rest_client.php';

$creds = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
$c     = new FirestoreRestClient($creds, 'graderadmin', '(default)');
$body  = function ($r) { return is_array($r['data'] ?? null) ? $r['data'] : $r; };

echo "USE_ALLOCATION_LEDGER — ACTIVATION READINESS\n";
echo "tenant: {$schoolId}\n";
echo str_repeat('=', 74) . "\n\n";

$checks = [];
$add = function (string $name, bool $ok, string $detail) use (&$checks) {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    printf("  [%s] %-46s %s\n", $ok ? ' OK ' : 'FAIL', $name, $detail);
};

// ── 1 · the tenant exists ─────────────────────────────────────────────────
$school = null;
try { $school = $body($c->getDocument('schools', $schoolId)); } catch (\Throwable $e) {}
$add('tenant exists', is_array($school) && $school !== [],
     is_array($school) ? ('schoolCode=' . (string) ($school['schoolCode'] ?? '(none)')) : 'NOT FOUND');

// ── 2 · rollout grants for this tenant ────────────────────────────────────
$grants = [];
try {
    $ctrl   = $body($c->getDocument('schoolControl', $schoolId));
    $grants = is_array($ctrl['rolloutFlags'] ?? null) ? $ctrl['rolloutFlags'] : [];
} catch (\Throwable $e) {}

$grantOf = function (string $flag) use ($grants) {
    if (!array_key_exists($flag, $grants)) return null;
    $v = $grants[$flag];
    return is_array($v) ? (bool) ($v['enabled'] ?? false) : (bool) $v;
};

// DEC-006: the ledger explains entitlement pricing, so entitlement billing must
// already be on for this tenant — otherwise there is no pricing to explain.
$billing = $grantOf('USE_ENTITLEMENT_BILLING');
$add('USE_ENTITLEMENT_BILLING granted (DEC-006)', $billing === true,
     $billing === null ? 'no grant' : ($billing ? 'true' : 'false'));

$registry = $grantOf('USE_FEE_HEAD_REGISTRY');
$add('USE_FEE_HEAD_REGISTRY granted (M5)', $registry === true,
     $registry === null ? 'no grant' : ($registry ? 'true' : 'false'));

// ── 3 · the flag itself must still be OFF ─────────────────────────────────
$ledger = $grantOf('USE_ALLOCATION_LEDGER');
$add('USE_ALLOCATION_LEDGER still inactive', $ledger !== true,
     $ledger === null ? 'no grant (expected)' : ($ledger ? 'ALREADY ACTIVE' : 'false'));

// ── 4 · the tenant actually has entitlements to allocate ──────────────────
$ents = 0; $entActive = 0;
try {
    foreach ($c->query('studentEntitlements', [['schoolId', '==', $schoolId]]) as $r) {
        $d = $body($r); $ents++;
        $st = strtolower((string) ($d['status'] ?? ''));
        if ($st === '' || $st === 'active' || $st === 'approved') $entActive++;
    }
} catch (\Throwable $e) {}
$add('tenant has entitlements to allocate', $ents > 0, "{$ents} total · {$entActive} active");

// ── 5 · the ledger is CONSISTENT — not necessarily empty ──────────────────
//
// LEDGER-READY-2. This asserted the collection was EMPTY, on the reasoning
// that activation itself writes nothing so a greenfield tenant should have no
// rows. That premise died the moment a historical backfill existed: after an
// authorized backfill the ledger is SUPPOSED to be populated, and this check
// would then fail forever — permanently blocking the very activation the
// backfill exists to enable, on a tenant whose data is now perfect.
//
// It is also the wrong question. "Empty" was only ever a proxy for "nothing
// inconsistent is in here". The real pre-activation property is that every row
// present is coherent, which check 5b below asserts directly against the
// demands. Emptiness is one way to satisfy that, not the requirement.
//
// So this now fails only on rows that are genuinely wrong: a row belonging to
// another tenant, or a row with no demand behind it. Those would be corruption
// regardless of how many rows exist.
$rows = [];
try { $rows = $c->query('entitlementAllocations', [['schoolId', '==', $schoolId]]); } catch (\Throwable $e) {}

$foreign = 0; $orphanKeyed = 0;
foreach ($rows as $r) {
    $a = $body($r);
    if (trim((string) ($a['schoolId'] ?? '')) !== $schoolId) { $foreign++; continue; }
    if (trim((string) ($a['demandId'] ?? '')) === '') $orphanKeyed++;
}
$add('entitlementAllocations coherent', $foreign === 0 && $orphanKeyed === 0,
     count($rows) . ' row(s)'
     . ($foreign ? " · {$foreign} FOREIGN-TENANT" : '')
     . ($orphanKeyed ? " · {$orphanKeyed} with no demandId" : '')
     . ($foreign === 0 && $orphanKeyed === 0 ? ' · all tenant-scoped and demand-keyed' : ''));

// ── 5b · LEDGER-READY-1 · THE HISTORICAL DEMANDS MUST ALREADY SATISFY I2 ──
//
// THE GAP THIS CLOSES.
//
// Every check above is STRUCTURAL — are the gates wired, is the service
// present, is the collection empty. None of them looked at the DATA the flag
// would immediately start being judged against, so this script reported
// "10 checks · 10 passed · PREREQUISITES MET" for a tenant where
// `billing_v2_certify` simultaneously reported NOT CERTIFIED with 24
// I2/discount_without_allocation violations.
//
// Two read-only tools, opposite answers, and the REASSURING one is the one an
// operator runs immediately before flipping the grant. Check 5 made it worse:
// it treats an EMPTY ledger as a pass, which is exactly the condition that
// makes every pre-existing discounted demand a violation the moment the flag
// turns on.
//
// I2 says: a demand carrying a discount must have allocation rows explaining
// it. Activating the ledger while historical discounted demands have none does
// not create the violation — it makes an existing inconsistency ENFORCED, and
// the estate is then permanently non-compliant until a backfill runs.
//
// Evaluated here rather than by shelling out to the certifier so this script
// keeps its single-command, exit-code contract. `Billing_v2_verifier` remains
// the authority on the full invariant set; this is deliberately ONLY I2, the
// one that gates activation.
$discountedNoAlloc = 0;
$demandsSeen       = 0;
try {
    // Allocation rows are keyed to a demand; collect which demands have any.
    $allocByDemand = [];
    foreach ($rows as $r) {
        $a = $body($r);
        $did = (string) ($a['demandId'] ?? '');
        $st  = strtolower((string) ($a['status'] ?? 'active'));
        if ($did !== '' && $st === 'active') $allocByDemand[$did] = true;
    }
    foreach ($c->query('feeDemands', [['schoolId', '==', $schoolId]]) as $r) {
        $d = $body($r);
        $demandsSeen++;
        $disc = (float) ($d['discountAmount'] ?? 0);
        if ($disc <= 0.005) continue;                       // no discount ⇒ I2 silent
        $did = (string) ($d['demandId'] ?? '');
        if ($did === '' || empty($allocByDemand[$did])) $discountedNoAlloc++;
    }
} catch (\Throwable $e) {
    // Unreadable demands must NOT read as clean — an unknown estate is not a
    // safe one. Force the check to fail with the reason visible.
    $discountedNoAlloc = -1;
}

$add(
    'historical demands satisfy I2 (discount has allocations)',
    $discountedNoAlloc === 0,
    $discountedNoAlloc < 0
        ? 'feeDemands unreadable — cannot certify, treating as NOT ready'
        : ($discountedNoAlloc === 0
            ? "{$demandsSeen} demand(s) checked · 0 discounted-without-allocation"
            : "{$discountedNoAlloc} of {$demandsSeen} demand(s) carry a discount with NO "
              . 'allocation row — activation would enforce a violation that already exists. '
              . 'A backfill through Fee_lifecycle is required FIRST.')
);

// ── 6 · the code is wired ─────────────────────────────────────────────────
$gen = (string) @file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
$lif = (string) @file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
$add('assembly gate present (Fee_generation_service)',
     strpos($gen, '_allocationLedgerEnabled') !== false, '_allocationLedgerEnabled()');
$add('write gate present (Fee_lifecycle)',
     strpos($lif, '_persistAllocations') !== false, '_persistAllocations()');
$add('allocation service present',
     is_file(APPPATH . 'libraries/Entitlement_allocation_service.php'), 'Entitlement_allocation_service');

// ── 7 · the global default must remain FALSE ──────────────────────────────
// Editing the config file would arm EVERY tenant; the grant is the only correct
// activation path. This check exists to catch exactly that mistake.
$cfg = (string) @file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');
$globalFalse = (bool) preg_match(
    "/\\\$config\\[.USE_ALLOCATION_LEDGER.\\]\\s*=\\s*false\\s*;/", $cfg);
$add('global default still false (no blanket arming)', $globalFalse,
     $globalFalse ? 'false' : 'GLOBAL DEFAULT IS NOT FALSE');

// NOTE — grantability is deliberately NOT checked here. `T3RolloutGrantTest`
// asserts that no file under scripts/ so much as mentions the grant service, so
// that the only way to create a tenant grant stays the authorized surface. Even
// naming the class in a read-only checker would desensitise that guard, and the
// fact is already covered by
// `P5AllocationEmissionTest::test_the_flag_is_grantable_per_tenant`.

// ── summary ───────────────────────────────────────────────────────────────
$fail = 0;
foreach ($checks as $ck) if (!$ck['ok']) $fail++;

echo "\n" . str_repeat('=', 74) . "\n";
printf("%d checks · %d passed · %d failed\n", count($checks), count($checks) - $fail, $fail);

if ($fail === 0) {
    echo "\nPREREQUISITES MET. This does NOT authorize activation.\n";
    echo "Activation is a tenant grant of USE_ALLOCATION_LEDGER for {$schoolId},\n";
    echo "issued only through the authorized rollout-grant surface, and it remains\n";
    echo "a protected operation requiring explicit approval.\n";
} else {
    echo "\nNOT READY — resolve the failing checks above.\n";
}
echo "No documents were written by this script.\n";

exit($fail === 0 ? 0 : 1);
