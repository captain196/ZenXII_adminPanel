<?php
/**
 * RELEASE STAGING GUARD — run immediately before committing the payment release.
 *
 * Reads the git index and refuses anything that is not part of the certified
 * payment manifest. Nothing is staged, unstaged or written by this script; it
 * only inspects and reports.
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * The working tree carries six workstreams: Student Lifecycle, Entitlements,
 * Billing V2, RBAC, Compensation/F&F and Attendance — 132 modified and ~1000
 * untracked files. One `git add -A` ships all of it.
 *
 * The index is ALSO not always composed by whoever is doing the release: at the
 * time of writing three RBAC files were staged by a different session. A commit
 * made without checking would have carried them into a payment release.
 *
 *   php scripts/release_staging_guard.php
 *
 * exit 0 = the index contains only manifest files
 * exit 2 = something else is staged — DO NOT COMMIT
 */

/** The certified payment release. Must match PRODUCTION_RELEASE_MANIFEST.md. */
const MANIFEST = [
    'application/libraries/Payment_gateway_selector.php',
    'application/libraries/Payment_provider_config_resolver.php',
    'application/libraries/Payment_gateway_unavailable.php',
    'application/libraries/Payment_order_provenance.php',
    'application/libraries/PaymentProviderInterface.php',
    'application/libraries/Fee_payment_claim.php',
    'application/libraries/Payment_settlement_route.php',
    'application/libraries/Receipt_number.php',
    'application/helpers/webhook_signature_helper.php',
    'application/controllers/Fee_management.php',
    'application/libraries/Payment_service.php',
    'application/libraries/Operations_accounting.php',
    'application/services/FeeCollectionService.php',
    'application/libraries/Firebase.php',
    'application/libraries/Fee_firestore_txn.php',
    'application/libraries/Scholarship_allocator.php',
    'application/config/config.php',
];

/**
 * Paths that may legitimately accompany the release: tests that certify it,
 * the audit documents, and the read-only tooling. None of these execute in
 * production, so they cannot change runtime behaviour.
 */
const ALLOWED_PATTERNS = [
    '#^tests/Unit/[A-Za-z0-9_]+Test\.php$#',
    '#^\.claude/zenx/[A-Za-z0-9_.]+\.md$#',
    '#^scripts/[A-Za-z0-9_]+\.(php|js)$#',
    '#^\.gitignore$#',
];

/** Never releasable with payment, whatever else is true. */
const FORBIDDEN_PATTERNS = [
    '#^firebase-rules/#'                        => 'a Firestore rules file — the release deploys NO rules',
    '#^application/config/routes\.php$#'         => 'routes.php — excluded; the webhook route is already live',
    '#^application/views/#'                      => 'a view — other workstreams are actively editing these',
    '#Fee_lifecycle\.php$#'                      => 'Fee_lifecycle — excluding it is what keeps Entitlements out',
    '#Fee_generation_service\.php$#'             => 'fee generation — not on the payment path',
    '#Entitlement_#'                             => 'a parked Entitlements library',
    '#Fee_entitlement_reader\.php$#'             => 'a parked Entitlements library',
    '#Rollout_flag_service\.php$#'               => 'a parked rollout-flag service',
    '#Student_charge_service\.php$#'             => 'Student Charges service — shipping it ACTIVATES the feature',
    '#Fee_head_resolver\.php$#'                  => 'Student Charges dependency',
    '#rbac_helper\.php$|deptrole_helper\.php$|Admin_login\.php$#' => 'RBAC — a separate workstream',
    '#^snapshots/.*(gateway|secret|credential)#' => 'a credential-bearing snapshot',
];

$staged = [];
exec('git diff --cached --name-status 2>&1', $staged);

$manifest = array_flip(MANIFEST);
$problems = [];
$seen     = [];

echo "RELEASE STAGING GUARD\n" . str_repeat('=', 74) . "\n";

if (!$staged || (count($staged) === 1 && trim($staged[0]) === '')) {
    echo "  nothing is staged.\n";
    echo "  Stage the manifest explicitly — never `git add -A`.\n";
    exit(2);
}

foreach ($staged as $line) {
    if (trim($line) === '') continue;
    $parts  = preg_split('/\s+/', trim($line), 2);
    $status = $parts[0] ?? '?';
    $path   = str_replace('\\', '/', $parts[1] ?? '');
    if ($path === '') continue;
    $seen[$path] = $status;

    // REMEDIATION FIRST. Removing a credential-bearing snapshot is the whole
    // point of the fix; the forbidden-pattern list below is about what must
    // not be ADDED. Checking forbidden first made this guard refuse the very
    // action it exists to encourage — a guard that cries wolf on correct work
    // teaches people to bypass it.
    if ($status === 'D' && preg_match('#^snapshots/#', $path)) {
        printf("  OK         %s %s   (credential remediation)\n", $status, $path);
        continue;
    }
    foreach (FORBIDDEN_PATTERNS as $re => $why) {
        if (preg_match($re, $path)) {
            $problems[] = "FORBIDDEN  {$status} {$path}\n             {$why}";
            continue 2;
        }
    }
    if (isset($manifest[$path])) {
        printf("  OK         %s %s\n", $status, $path);
        continue;
    }
    foreach (ALLOWED_PATTERNS as $re) {
        if (preg_match($re, $path)) {
            printf("  OK (aux)   %s %s\n", $status, $path);
            continue 2;
        }
    }
    $problems[] = "UNEXPECTED {$status} {$path}\n             not in the manifest and not an allowed auxiliary path";
}

$missing = array_values(array_filter(MANIFEST, static fn($p) => !isset($seen[$p])));

echo str_repeat('-', 74) . "\n";
if ($missing) {
    echo "  MANIFEST FILES NOT STAGED (" . count($missing) . "):\n";
    foreach ($missing as $m) echo "    - {$m}\n";
    echo "    A partial release fatals in production the first time the missing\n";
    echo "    dependency is reached. Stage all 17 or none.\n";
}
if ($problems) {
    echo "\n  REFUSED:\n";
    foreach ($problems as $p) echo "    {$p}\n";
}

echo str_repeat('=', 74) . "\n";
if (!$problems && !$missing) {
    echo "  INDEX IS CLEAN — exactly the payment release. Safe to commit.\n";
    exit(0);
}
echo "  DO NOT COMMIT.\n";
exit(2);
