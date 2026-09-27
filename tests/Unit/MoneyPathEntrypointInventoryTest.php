<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE MONEY-PATH ENTRYPOINT INVENTORY — DERIVED, NEVER MAINTAINED BY HAND
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Twice now a payment path has escaped hardening because the inventory was
 * written from a docblock or from a single file:
 *
 *   B025-F2  the claim was added to _verify_and_process() because its docblock
 *            says "called by BOTH verify_payment AND payment_webhook" — but the
 *            Parent app posts to parent_verify_payment, a THIRD method with its
 *            own submit().
 *   B025-F3  the coverage test counted submit() sites in Fee_management.php and
 *            found three, missing that Fees.php has a fourth.
 *
 * The lesson is not "count more carefully". It is that any hand-maintained list
 * of money entrypoints will drift, so the list must be DERIVED FROM SOURCE and
 * the test must fail when the derivation stops matching what is classified.
 *
 * Every FeeCollectionService::submit() caller falls into exactly one class:
 *
 *   GATEWAY   — completes a payment captured by a payment gateway. Must own the
 *               order claim before writing money, and must record a
 *               processedPayments marker keyed by the gateway payment id.
 *   MANUAL    — a cashier collecting cash/cheque at the counter. There is no
 *               gateway order and no gateway payment id, so an order claim is
 *               meaningless; exclusion is the per-student lock inside the
 *               service itself.
 *
 * Misclassifying a GATEWAY path as MANUAL is precisely B025-F3.
 */
class MoneyPathEntrypointInventoryTest extends TestCase
{
    /**
     * Files known to call FeeCollectionService::submit(), with the number of
     * call sites and their class. Derived by inspection, ENFORCED by the tests
     * below — if the counts drift, the suite fails rather than silently
     * covering fewer paths.
     */
    private const INVENTORY = [
        'controllers/Fee_management.php' => ['sites' => 3, 'class' => 'GATEWAY'],
        'controllers/Fees.php'           => ['sites' => 1, 'class' => 'MANUAL'],
    ];

    /** @return array<string,int> file => submit() call count, across the app */
    private function discoverSubmitCallers(): array
    {
        $found = [];
        $dirs  = [APPPATH . 'controllers', APPPATH . 'libraries', APPPATH . 'services', APPPATH . 'models'];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') continue;
                $src = (string) file_get_contents($file->getPathname());

                // The service's OWN definition is not a call site.
                if (strpos($src, 'class FeeCollectionService') !== false) continue;

                $n = preg_match_all('/\$\w*service\w*->submit\s*\(/i', $src);
                if ($n > 0) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(APPPATH)));
                    $found[$rel] = $n;
                }
            }
        }
        ksort($found);
        return $found;
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    public function test_control_the_discovery_finds_the_known_callers(): void
    {
        $found = $this->discoverSubmitCallers();

        $this->assertNotEmpty($found,
            'the discovery found NO submit() callers at all — the pattern drifted and every '
            . 'assertion below would pass vacuously');
        $this->assertArrayHasKey('controllers/Fee_management.php', $found,
            'the discovery cannot even find the primary gateway payment controller');
    }

    // ══ THE INVENTORY ═══════════════════════════════════════════════════════

    /**
     * A new money entrypoint anywhere in the application fails this test. That
     * is the point: it must be classified and hardened deliberately, not
     * discovered later by an audit.
     */
    public function test_no_unclassified_money_entrypoint_exists(): void
    {
        $found      = $this->discoverSubmitCallers();
        $unexpected = array_diff_key($found, self::INVENTORY);

        $this->assertSame([], $unexpected,
            'UNCLASSIFIED money entrypoint(s): ' . implode(', ', array_keys($unexpected)) . '. '
            . 'Every FeeCollectionService::submit() caller writes real money and must be '
            . 'classified GATEWAY (needs the order claim + payment marker) or MANUAL (cash '
            . 'counter, no gateway identity). B025-F3 was exactly this: a path nobody had '
            . 'classified, running without concurrency protection.');
    }

    public function test_the_call_site_counts_have_not_drifted(): void
    {
        $found = $this->discoverSubmitCallers();

        foreach (self::INVENTORY as $file => $spec) {
            $this->assertArrayHasKey($file, $found, "{$file} no longer calls submit() — re-derive the inventory");
            $this->assertSame($spec['sites'], $found[$file],
                "{$file} now has {$found[$file]} submit() call sites, not {$spec['sites']}. A new "
                . 'payment completion path was added. Classify it and give it the protection its '
                . 'class requires before updating this count.');
        }
    }

    /**
     * Every GATEWAY call site must sit downstream of a claim acquisition.
     * Counted, not spot-checked: three sites, three claims.
     */
    public function test_every_gateway_entrypoint_owns_the_order_claim(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');

        $submits = preg_match_all('/\$service\s*->submit\s*\(/', $src);
        $claims  = substr_count($src, 'paymentClaim->acquire(');

        $this->assertSame($submits, $claims,
            "{$submits} gateway payment paths but only {$claims} claim acquisitions. Every path "
            . 'that completes a gateway payment must own the order first — otherwise the parent '
            . 'callback, the webhook and the admin retry can each apply the same payment.');
    }

    /**
     * The MANUAL path must NOT carry gateway identity. If a cash collection ever
     * passed a payment id, it would write a processedPayments marker keyed on a
     * non-gateway value and could suppress a real gateway payment.
     */
    public function test_the_manual_counter_path_carries_no_gateway_identity(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $submitAt = strpos($src, '$service->submit($this, [');
        $this->assertNotFalse($submitAt, 'the manual collection submit() was renamed');
        $payload = substr($src, $submitAt, 800);

        $this->assertStringNotContainsString('paymentId', $payload,
            'the cash-counter collection now passes a gateway payment id. That would key a '
            . 'processedPayments marker on a non-gateway value, and could suppress a genuine '
            . 'gateway payment that later arrives with the same id.');
    }

    // ══ FEE-S1 · THE DESTRUCTIVE ENDPOINTS FAIL CLOSED ══════════════════════

    /**
     * `Fees::void_test_receipt()` hard-deletes feeReceipts + feeReceiptIndex and
     * reverses the journal — it erases the evidence that a payment happened. Its
     * only production fence is `_reject_in_production()`.
     *
     * index.php resolves the environment as:
     *
     *     define('ENVIRONMENT', isset($_SERVER['CI_ENV']) ? $_SERVER['CI_ENV'] : 'development');
     *
     * so the fence depends on `SetEnv CI_ENV production` living in the production
     * Apache config — a file deliberately NOT in git and unverifiable from here.
     * Missing, mistyped, or lost in a vhost change ⇒ ENVIRONMENT silently becomes
     * 'development' and the fence becomes a no-op ON THE LIVE SERVER.
     *
     * WOULD HAVE FAILED BEFORE: the guard read `ENVIRONMENT === 'production'`.
     */
    public function test_the_destructive_fee_endpoints_deny_unless_explicitly_development(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $fn = strpos($src, 'private function _reject_in_production');
        $this->assertNotFalse($fn, '_reject_in_production was renamed or removed');
        $body = substr($src, $fn, 400);

        $this->assertStringNotContainsString("ENVIRONMENT === 'production'", $body,
            'the guard permits everything EXCEPT an explicitly-production environment. '
            . 'ENVIRONMENT defaults to "development" when CI_ENV is unset, so an unset or '
            . 'mistyped server variable turns a receipt-deletion endpoint live in production. '
            . 'A destructive money endpoint must fail CLOSED.');

        $this->assertStringContainsString("ENVIRONMENT !== 'development'", $body,
            'the guard must permit ONLY an explicitly-development environment, so that an '
            . 'unset, misspelt or unexpected value denies rather than allows');
    }

    /**
     * The fence must still be applied at every destructive site — hardening the
     * function is worthless if a site stops calling it.
     */
    public function test_every_destructive_fee_endpoint_still_invokes_the_fence(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $calls = substr_count($src, '$this->_reject_in_production();');
        $this->assertGreaterThanOrEqual(5, $calls,
            "only {$calls} destructive endpoints invoke _reject_in_production(). One of the "
            . 'dev-only fee tools — receipt voiding, data reset — is now reachable without the '
            . 'environment fence.');
    }

    /**
     * The same fail-open shape exists in config bootstrap. NOT fixed here (wider
     * blast radius than a fees change), but pinned so it cannot be forgotten:
     * if these ever stop being production-gated the risk changes shape entirely.
     */
    public function test_the_sibling_fail_open_config_guards_are_still_documented(): void
    {
        $fees = (string) file_get_contents(APPPATH . 'controllers/Fees.php');

        $this->assertStringContainsString('auth_api.php', $fees,
            'the FEE-S1 note naming the sibling fail-open guards was removed. Those guards — '
            . 'a missing internal API key and a missing encryption key being TOLERATED when '
            . 'ENVIRONMENT is not "production" — share this defect and remain unfixed.');
    }
}
