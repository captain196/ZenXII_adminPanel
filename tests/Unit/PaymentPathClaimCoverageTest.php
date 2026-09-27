<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  EVERY GATEWAY-PAYMENT COMPLETION PATH MUST TAKE THE ORDER CLAIM
 * ══════════════════════════════════════════════════════════════════════════
 *
 * B-025's fix was first applied to `_verify_and_process()` alone, on the
 * strength of that method's own docblock:
 *
 *     "Called by BOTH verify_payment (frontend) AND payment_webhook (server).
 *      Single source of truth for the payment → fees flow."
 *
 * That sentence is true and still misleading. `verify_payment()` is the
 * admin/legacy endpoint. The PARENT APP posts to `parent_verify_payment`
 * (Parent app `FeesApi`: `index.php/fee_management/parent_verify_payment`),
 * which has its OWN FeeCollectionService::submit() and never reaches the
 * shared core.
 *
 * So the race that actually happens in production — the parent's Razorpay
 * callback against the webhook Razorpay fires at the same moment — ran with no
 * order-level exclusivity at all, while the fix protected a pair that races far
 * less often. The only thing between them was FeeCollectionService's inline
 * per-student lock, which is itself read-then-blind-set (REF-1b) and therefore
 * not exclusive either.
 *
 * The lesson this file encodes: a "single source of truth" comment is a claim
 * about the code, not evidence about it. Coverage is asserted here against the
 * actual call graph, so adding a fourth completion path without a claim fails.
 */
class PaymentPathClaimCoverageTest extends TestCase
{
    /**
     * Every method that hands a gateway payment to FeeCollectionService.
     *
     * This list was WRONG when first written — it held two entries, because
     * `_verify_and_process()`'s docblock reads like an exhaustive statement of
     * the payment paths. There are THREE: the parent callback, the shared core,
     * and the admin retry. The third was found only by counting
     * `$service->submit(` call sites, and it was missing the claim entirely.
     *
     * test_the_known_completion_paths_are_still_exhaustive() below re-derives
     * the count from source so a FOURTH path cannot be added silently.
     */
    private const COMPLETION_PATHS = [
        'parent_verify_payment',
        '_verify_and_process',
        'retry_payment_processing',
    ];

    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
    }

    /** Body of a controller method, up to the next `public|private function`. */
    private function methodBody(string $name): string
    {
        $start = strpos(self::$src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "method {$name}() was renamed or removed");

        $next = preg_match('/\n    (?:public|private|protected) function /', self::$src, $m,
            PREG_OFFSET_CAPTURE, $start + 10);

        return $next === 1
            ? substr(self::$src, $start, $m[0][1] - $start)
            : substr(self::$src, $start);
    }

    // ══ CONTROL — THE EXTRACTION IS REAL ════════════════════════════════════

    public function test_control_method_extraction_isolates_one_method(): void
    {
        $body = $this->methodBody('parent_verify_payment');

        $this->assertNotEmpty($body);
        $this->assertStringContainsString('parent_verify_payment', $body);
        $this->assertStringNotContainsString('function payment_webhook(', $body,
            'the extraction ran past the end of the method, so every assertion below '
            . 'could be satisfied by a DIFFERENT method\'s code');
    }

    // ══ THE INVARIANT ═══════════════════════════════════════════════════════

    /**
     * THE PATH THE PARENT APP ACTUALLY USES.
     */
    public function test_the_parent_callback_acquires_the_order_claim(): void
    {
        $body = $this->methodBody('parent_verify_payment');

        $this->assertStringContainsString('paymentClaim->acquire(', $body,
            'parent_verify_payment() completes a gateway payment without acquiring the '
            . 'order claim. This is the endpoint the Parent app calls, and it races the '
            . 'webhook by design — Razorpay fires the webhook while the client callback '
            . 'is still running.');

        $this->assertStringContainsString('Fee_payment_claim::ACQUIRED', $body,
            'the claim result must be compared against ACQUIRED. Anything else — '
            . 'IN_PROGRESS or UNKNOWN — must not proceed into the money path.');
    }

    /**
     * A claim that is never released blocks the order for the whole lease.
     * Correct but degraded, so both exits must free it.
     */
    public function test_the_parent_callback_releases_the_claim_on_success_and_failure(): void
    {
        $body = $this->methodBody('parent_verify_payment');

        $releases = substr_count($body, 'paymentClaim->release(');
        $this->assertGreaterThanOrEqual(2, $releases,
            'parent_verify_payment() releases the claim on fewer than two paths. Both the '
            . 'success exit and the service-failure exit must free the order — otherwise a '
            . 'failed attempt blocks the webhook that would have completed the payment for '
            . 'the remainder of the 120s lease.');
    }

    /**
     * The list above must stay exhaustive. Re-derived from source rather than
     * maintained by hand, because maintaining it by hand is exactly how the
     * admin retry path went unprotected.
     */
    public function test_the_known_completion_paths_are_still_exhaustive(): void
    {
        $submits = substr_count(self::$src, '$service->submit($this, [');

        $this->assertSame(count(self::COMPLETION_PATHS), $submits,
            'the number of FeeCollectionService::submit() call sites (' . $submits . ') no '
            . 'longer matches the list of payment completion paths this file checks ('
            . count(self::COMPLETION_PATHS) . '). A new path has been added, or one removed. '
            . 'Every such path must own the order before it writes money — add it to '
            . 'COMPLETION_PATHS and give it a claim.');
    }

    /**
     * The admin retry is the third path. It does NOT call verify_payment(), so
     * it never gets that method's already_paid short-circuit — the payment
     * marker must be consulted here explicitly, or an order still marked
     * fees_failed after the webhook succeeded would be collected twice.
     */
    public function test_the_admin_retry_path_checks_the_payment_marker(): void
    {
        $body = $this->methodBody('retry_payment_processing');

        $this->assertStringContainsString('getProcessedPayment(', $body,
            'retry_payment_processing() does not check whether the payment was already '
            . 'applied. It skips verify_payment(), so nothing else stops it re-collecting '
            . 'a payment the webhook already processed.');
    }

    public function test_the_shared_core_acquires_the_order_claim(): void
    {
        $body = $this->methodBody('_verify_and_process');

        $this->assertStringContainsString('paymentClaim->acquire(', $body,
            '_verify_and_process() — used by the webhook and the legacy verify endpoint — '
            . 'must acquire the order claim');
        $this->assertStringContainsString('Fee_payment_claim::IN_PROGRESS', $body,
            'the shared core must distinguish IN_PROGRESS (someone else owns it) from '
            . 'UNKNOWN (ownership undetermined); they require different responses');
    }

    /**
     * THE GENERAL RULE, not just the two known paths. Any method that hands a
     * gateway payment to FeeCollectionService must first own the order.
     */
    public function test_every_gateway_payment_submit_is_preceded_by_a_claim(): void
    {
        $uncovered = [];

        foreach (self::COMPLETION_PATHS as $method) {
            $body = $this->methodBody($method);
            if (strpos($body, 'service->submit(') === false) continue;

            $claimAt  = strpos($body, 'paymentClaim->acquire(');
            $submitAt = strpos($body, 'service->submit(');

            if ($claimAt === false || $claimAt > $submitAt) $uncovered[] = $method;
        }

        $this->assertSame([], $uncovered,
            'these methods hand a payment to FeeCollectionService without first owning the '
            . 'order: [' . implode(', ', $uncovered) . ']. Ordering matters — a claim taken '
            . 'AFTER the money write protects nothing.');
    }

    /**
     * The claim is keyed by ORDER. Both paths must use the same key, or they
     * would take two different documents and never contend.
     */
    public function test_both_paths_claim_the_same_order_key(): void
    {
        foreach (self::COMPLETION_PATHS as $method) {
            $body = $this->methodBody($method);
            if (strpos($body, 'paymentClaim->acquire(') === false) continue;

            $this->assertMatchesRegularExpression(
                '/paymentClaim->acquire\(\s*\$gwOrderId\s*,/',
                $body,
                "{$method}() claims on something other than \$gwOrderId. Two paths claiming "
                . 'different keys hold two different documents and never exclude each other, '
                . 'which is indistinguishable from having no claim at all.'
            );
        }
    }

    /**
     * The claim must be initialised with the resolved tenant, never a hardcoded
     * one, on every path that uses it.
     */
    public function test_the_claim_is_initialised_with_the_resolved_tenant(): void
    {
        foreach (self::COMPLETION_PATHS as $method) {
            $body = $this->methodBody($method);
            if (strpos($body, 'paymentClaim->init(') === false) continue;

            $this->assertStringContainsString(
                'paymentClaim->init($this->firebase, $this->school_name)',
                $body,
                "{$method}() initialises the claim with something other than the resolved "
                . 'tenant. A wrong or empty school id addresses another document entirely.'
            );
            $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{8,}/', $body,
                "{$method}() contains a hardcoded tenant id — the UAT school is a canary, "
                . 'never a code path.');
        }
    }
}
