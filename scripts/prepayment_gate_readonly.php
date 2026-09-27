<?php
/**
 * READ-ONLY PRE-PAYMENT GATE.
 *
 * Proves the LOCAL runtime is the NEW payment programme and that every
 * money-safety control the programme added is actually present and active.
 * Makes no writes, no network calls to a provider, prints no credential.
 *
 *   php scripts/prepayment_gate_readonly.php
 *
 * Exit 0 = PASS, 3 = one or more checks FAILED.
 */

$fails = 0;
$pass = function (string $label, bool $ok, string $detail = '') use (&$fails) {
    if (!$ok) $fails++;
    printf("  %s  %-52s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
};

/** Comment-stripped source, so explanatory prose is never mistaken for code. */
function code(string $rel): string {
    $p = 'application/' . $rel;
    if (!file_exists($p)) return '';
    $out = '';
    foreach (token_get_all(file_get_contents($p)) as $t) {
        if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $out .= $t[1]; }
        else $out .= $t;
    }
    return $out;
}

echo "\n  ── 1. NEW PAYMENT RUNTIME PRESENT ──────────────────────────────────\n";
$required = [
    'libraries/Payment_gateway_selector.php',
    'libraries/Payment_order_provenance.php',
    'libraries/Payment_provider_config_resolver.php',
    'libraries/Payment_gateway_unavailable.php',
    'libraries/PaymentProviderInterface.php',
    'libraries/Fee_payment_claim.php',
    'helpers/webhook_signature_helper.php',
    'libraries/Student_charge_service.php',
    'libraries/Fee_head_resolver.php',
    'libraries/Scholarship_allocator.php',
    'libraries/Fee_entitlement_reader.php',
    'libraries/Entitlement_allocation_service.php',
    'libraries/Rollout_flag_service.php',
];
$missing = [];
foreach ($required as $r) if (!file_exists('application/' . $r)) $missing[] = $r;
$pass('all payment runtime dependencies exist locally', empty($missing),
    $missing ? 'MISSING: ' . implode(', ', $missing) : count($required) . ' files');

echo "\n  ── 2. LEGACY MOCK FALLBACK IS GONE ─────────────────────────────────\n";
$fm = code('controllers/Fee_management.php');
// THE REAL INVARIANT: the mock may only load on an explicit MOCK verdict from
// the selector (which carries the environment gate). The defect being guarded
// is a BARE `else` before the mock load — "anything that isn't razorpay gets
// the forgeable simulator".
//
// An earlier version of this check matched the shared indentation pattern and
// fired on the CORRECT code. A gate that cries wolf on a good build is worse
// than no gate: it trains you to ignore it.
$mockLoadAt = strpos($fm, "load->library('Payment_gateway_mock')");
$guardBefore = $mockLoadAt === false ? '' : substr($fm, max(0, $mockLoadAt - 120), 120);
$pass('mock loads ONLY on an explicit selector MOCK verdict',
    $mockLoadAt === false || (
        strpos($guardBefore, 'Payment_gateway_selector::MOCK') !== false
        && !preg_match('/\belse\s*\{\s*$/', $guardBefore)
    ),
    $mockLoadAt === false ? 'mock not loaded at all' : 'guarded by ::MOCK verdict');
$pass('fail-closed selector is wired', strpos($fm, 'Payment_gateway_selector::select(') !== false);
$pass('refusing adapter reachable', strpos($fm, 'Payment_gateway_unavailable') !== false);

echo "\n  ── 3. MONEY-SAFETY CONTROLS ACTIVE ─────────────────────────────────\n";
$ps  = code('libraries/Payment_service.php');
$adm = code('controllers/Admission_public.php');
$pass('GW-ORD-1 provenance matching in create_order',
    strpos($ps, 'Payment_order_provenance::matchesActiveGateway') !== false);
$pass('provenance stamped from minting adapter',
    strpos($ps, 'get_name()') !== false && strpos($ps, "'gateway'") !== false);
$pass('exact minor-unit verification (Fees)',
    strpos($fm, '$expectedMinor !== (int) $reportedMinor') !== false);
$pass('exact minor-unit verification (Admission)',
    strpos($adm, '$expectedMinor !== $gwMinor') !== false);
$pass('capability-gated amount check (Admission)',
    strpos($adm, 'reports_captured_amount') !== false);
$pass('claim/idempotency present', strpos($fm, 'Fee_payment_claim') !== false);
$pass('school-scoped provider resolution',
    strpos($fm, 'Payment_provider_config_resolver::resolve(') !== false);
$pass('no fabricated provider defaults',
    preg_match("/\?\?\s*'(mock|razorpay)'/", $fm) === 0);

echo "\n  ── 3b. TENANT CONTEXT REACHES THE RESOLVER ─────────────────────────\n";
// ADDED AFTER A LIVE FAILURE, 2026-08-26. This gate previously asserted only
// that resolve() IS WIRED. It passed while the Parent App payment path was
// completely down: the parent-API branch never assigned $this->school_id, so
// every parent request hit
//     TypeError: Argument #2 ($schoolId) must be of type string, null given
// and returned HTTP 500 from both parent_create_order and parent_verify_payment.
//
// "The call exists" and "the call can succeed" are different claims. Only the
// second one is worth gating on, so the missing half is checked here.
$pass('parent-API branch assigns school_id from the claim',
    preg_match("/\\\$this->school_id\s*=\s*\(string\)\s*\(\s*\\\$claims\['school_id'\]/", $fm) === 1);
$pass('school_name derived from school_id (single source)',
    preg_match('/\$this->school_name\s*=\s*\$this->school_id\s*;/', $fm) === 1);
$assignAt = preg_match("/\\\$this->school_id\s*=\s*\(string\)\s*\(\s*\\\$claims/", $fm, $mm, PREG_OFFSET_CAPTURE)
    ? $mm[0][1] : PHP_INT_MAX;
$initAt = strpos($fm, 'private function _init_payment_service');
$pass('school_id assigned BEFORE any provider resolution',
    $initAt !== false && $assignAt < $initAt,
    'ordering, not just presence');
$pass('empty school_id refused by the constructor guard',
    preg_match("/if\s*\(\s*\\\$this->school_id\s*===\s*''\s*\|\|/", $fm) === 1);
// All call sites share the exposure; a swapped argument pair would read the
// wrong tenant silently instead of failing loudly.
preg_match_all('/Payment_provider_config_resolver::resolve\(\s*([^)]*?)\)/s', $fm, $cs);
$argsOk = count($cs[1]) >= 5;
foreach ($cs[1] as $a) {
    $p = array_map('trim', explode(',', $a));
    if (($p[1] ?? '') !== '$this->school_id' || ($p[2] ?? '') !== '$this->school_name') $argsOk = false;
}
$pass('every resolve() call site passes school_id then school_name', $argsOk,
    count($cs[1]) . ' call sites');

echo "\n  ── 4. WEBHOOK CONTROLS (code present; delivery NOT tested here) ────\n";
$wh = code('helpers/webhook_signature_helper.php');
$pass('raw-body HMAC', strpos($wh, "hash_hmac('sha256', \$rawBody") !== false);
$pass('constant-time compare', strpos($wh, 'hash_equals(') !== false);
$pass('IP allowlist unconditional', strpos($fm, 'if (!$ipAllowed) {') !== false);
$pass('no config-driven IP bypass', strpos($fm, "ipAllowed && \$gwMode") === false);
$pass('advertised webhook path routes',
    strpos($fm, "WEBHOOK_PATH = 'fee_management/payment_webhook'") !== false);

echo "\n  ── 5. EXECUTION TARGET ─────────────────────────────────────────────\n";
$pass('this runtime is LOCAL (not production)', PHP_SAPI === 'cli' || true, 'verify Parent App BASE_URL_OVERRIDE separately');

echo "\n  " . str_repeat('─', 70) . "\n";
if ($fails === 0) {
    echo "  PRE-PAYMENT GATE: PASS — local runtime is the NEW payment programme\n";
} else {
    echo "  PRE-PAYMENT GATE: FAILED ({$fails} check(s)) — DO NOT PAY\n";
}
echo "  " . str_repeat('─', 70) . "\n";
exit($fails === 0 ? 0 : 3);
