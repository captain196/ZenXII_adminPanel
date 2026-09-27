<?php
/**
 * READ-ONLY release-closure checker.
 *
 * A release is CLOSED when every method a shipped file calls on a collaborator
 * exists either in the shipped set or in the version production already runs
 * (git HEAD). An unclosed release fatals the first time the missing path is
 * executed — usually in front of a parent, mid-payment.
 *
 * This does the opposite of "ship everything to be safe": it proves which
 * files can be LEFT OUT without breaking the ones going in.
 *
 *   php scripts/release_closure_check.php
 */

$APP = __DIR__ . '/../application';

/**
 * The proposed release: files that MUST ship because the payment path
 * executes their modified behaviour.
 */
$SHIP = [
    // untracked — absent from production entirely
    'libraries/Payment_gateway_selector.php',
    'libraries/Payment_provider_config_resolver.php',
    'libraries/Payment_gateway_unavailable.php',
    'libraries/Payment_order_provenance.php',
    'libraries/PaymentProviderInterface.php',
    'libraries/Fee_payment_claim.php',
    'libraries/Payment_settlement_route.php',
    'libraries/Receipt_number.php',
    'helpers/webhook_signature_helper.php',
    // tracked but modified — production runs an older version
    'controllers/Fee_management.php',
    'libraries/Payment_service.php',
    'libraries/Operations_accounting.php',
    'services/FeeCollectionService.php',
    'config/config.php',
    'libraries/Firebase.php',
    'libraries/Fee_firestore_txn.php',
    // required to keep EXISTING production endpoints working:
    // apply_discount + award_scholarship both load it in the shipped controller
    'libraries/Scholarship_allocator.php',
];

/** Collaborator property => the class backing it. */
$COLLAB = [
    'fsTxn'          => 'libraries/Fee_firestore_txn.php',
    'fs'             => 'libraries/Firestore_service.php',
    'fsSync'         => 'libraries/Fee_firestore_sync.php',
    'firebase'       => 'libraries/Firebase.php',
    'feeIntegrity'   => 'libraries/Fee_integrity_checker.php',
    'feeDefaulter'   => 'libraries/Fee_defaulter_check.php',
    'paymentClaim'   => 'libraries/Fee_payment_claim.php',
    'feeLifecycle'   => 'libraries/Fee_lifecycle.php',
    'opsAcct'        => 'libraries/Operations_accounting.php',
    'acctFsSync'     => 'libraries/Accounting_firestore_sync.php',
];

$BASELINE = getenv('BASELINE_REF') ?: 'HEAD';
function headVersion(string $rel): ?string {
    global $BASELINE;
    $out = [];
    $code = 0;
    exec('git show ' . $BASELINE . ':application/' . escapeshellarg($rel) . ' 2>&1', $out, $code);
    // escapeshellarg wraps in quotes which git rejects inside the revspec;
    // fall back to the plain form.
    if ($code !== 0) {
        $out = []; $code = 0;
        exec('git show ' . $BASELINE . ':application/' . $rel . ' 2>&1', $out, $code);
    }
    return $code === 0 ? implode("\n", $out) : null;
}

$shipSet = array_flip($SHIP);
$problems = 0;
$checked  = 0;

echo "RELEASE CLOSURE CHECK\n";
echo str_repeat('=', 78) . "\n";
echo "Shipping " . count($SHIP) . " files. For every collaborator NOT shipping,\n";
echo "each method the shipped code calls must already exist in production.\n\n";

foreach ($SHIP as $rel) {
    $path = $APP . '/' . $rel;
    if (!is_file($path)) { echo "  MISSING LOCALLY: {$rel}\n"; $problems++; continue; }
    $src = file_get_contents($path);

    foreach ($COLLAB as $prop => $collabRel) {
        if (isset($shipSet[$collabRel])) continue;              // shipping it too
        if (!preg_match_all('/\$this->' . $prop . '->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m)) continue;

        $head = headVersion($collabRel);
        if ($head === null) {
            echo "  [{$rel}] collaborator not in production at all: {$collabRel}\n";
            $problems++;
            continue;
        }
        foreach (array_unique($m[1]) as $method) {
            $checked++;
            if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $head)) {
                echo "  ** UNCLOSED ** {$rel}\n";
                echo "                 calls \$this->{$prop}->{$method}() — absent from production's "
                   . basename($collabRel) . "\n";
                $problems++;
            }
        }
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "  collaborator method calls verified against production : {$checked}\n";
echo "  unclosed references                                   : {$problems}\n";
echo $problems === 0
    ? "  RELEASE IS CLOSED — the excluded files can stay at their production versions.\n"
    : "  RELEASE IS NOT CLOSED — the listed files must also ship, or the calls must go.\n";
exit($problems === 0 ? 0 : 2);
