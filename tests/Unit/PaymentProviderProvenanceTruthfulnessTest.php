<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  PA-CORE-1 — PROVIDER PROVENANCE MUST BE OBSERVED, NEVER FABRICATED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Found during the provider-agnostic architecture audit (2026-08-24). Three
 * money-record writers in Fee_management.php wrote the LITERAL string
 * 'razorpay' into the payment/receipt record's `gateway` field, regardless of
 * which adapter actually processed the payment:
 *
 *     'gateway'   => 'razorpay',
 *
 * `Payment_service::create_order()` already stores the TRUE value
 * (`$this->gateway->get_name()`) on the order document — the same field GW-ORD-1
 * trusts for provenance matching. So the field had two authorities: the order
 * is truthful, the payment/receipt was a constant. Harmless while Razorpay is
 * the only real provider; a second provider would make every receipt lie about
 * which gateway collected the money, and `fetch_online_payments()`'s
 * `?? 'razorpay'` default painted UNRECORDED provenance as the safe case —
 * exactly the condition GW-ORD-1 treats as unsafe to reuse.
 *
 * ── PART A — the SOURCE of truth is real ─────────────────────────────────
 * `Payment_service::verify_payment()` already computes a truthful
 * `gateway_name` via `get_name()`. Driven here through the real method by
 * reflection (same technique as `PendingOrderGatewayProvenanceTest`), with a
 * fake adapter that is deliberately NOT Razorpay — proving the source survives
 * a second provider, not merely that Razorpay's name passes through.
 *
 * ── PART B — the WRITE SITES actually use it ─────────────────────────────
 * A truthful source that nothing reads is worthless. These assert the three
 * write sites reference the computed variable and contain no `'razorpay'`
 * literal in the `gateway` field position. Weaker than driving the controller
 * directly (a CI3 controller cannot be instantiated outside the framework —
 * the same limitation `GatewayFallbackSafetyTest` documents), but anchored on
 * the ABSENCE of the literal plus the PRESENCE of the variable, which a
 * revert must break both halves of to pass.
 */
class PaymentProviderProvenanceTruthfulnessTest extends TestCase
{
    // ══ PART A — Payment_service::verify_payment() gateway_name is real ═════

    /** A gateway adapter that is NOT Razorpay and NOT the mock. */
    private function nonRazorpayAdapter(string $name): object
    {
        return new class($name) {
            public string $name;
            public function __construct(string $n) { $this->name = $n; }
            public function get_name(): string { return $this->name; }
            public function verify_signature(string $o, string $p, string $s): bool { return true; }
            public function fetch_payment(string $paymentId): array
            {
                return ['amount' => 700.00, 'amount_paise' => 70000, 'status' => 'captured'];
            }
        };
    }

    private function firebaseWithOrder(array $order): object
    {
        return new class($order) {
            public array $order; public array $writes = [];
            public function __construct(array $o) { $this->order = $o; }
            public function firestoreGet($col, $docId) { return $this->order; }
            public function firestoreSet($col, $docId, $data) { $this->writes[] = $data; return true; }
        };
    }

    private function verify(object $gateway, array $order): array
    {
        $svc = new Payment_service();
        $ref = new ReflectionClass($svc);
        foreach (['firebase' => $this->firebaseWithOrder($order), 'gateway' => $gateway,
                  'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27', 'adminId' => 'ADM'] as $p => $v) {
            $prop = $ref->getProperty($p); $prop->setAccessible(true); $prop->setValue($svc, $v);
        }
        return $svc->verify_payment('order_X', 'pay_X', 'sig_X');
    }

    /**
     * THE CONTROL. If this fails, the harness itself is broken and nothing
     * below can be trusted.
     */
    public function test_control_razorpay_adapter_reports_its_own_name(): void
    {
        $result = $this->verify($this->nonRazorpayAdapter('razorpay'), [
            'status' => 'created', 'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27',
        ]);
        $this->assertSame('razorpay', $result['gateway_name'] ?? null);
    }

    /**
     * THE PROOF. A second, real, non-Razorpay provider's name survives
     * verify_payment() untouched. This is what PA-CORE-1 depends on existing:
     * if this returned 'razorpay' for a provider named 'cashfree_live', the
     * fix at the call sites would have nothing truthful to read.
     */
    public function test_a_non_razorpay_provider_name_survives_verification(): void
    {
        $result = $this->verify($this->nonRazorpayAdapter('cashfree_live'), [
            'status' => 'created', 'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27',
        ]);

        $this->assertSame('cashfree_live', $result['gateway_name'] ?? null,
            'Payment_service::verify_payment() no longer reports the ACTIVE adapter\'s own '
            . 'name — it is either hardcoded, defaulted to razorpay, or lost. Every '
            . 'downstream write site depends on this field being truthful.');
        $this->assertNotSame('razorpay', $result['gateway_name'] ?? null,
            'a non-Razorpay adapter is being reported as razorpay');
    }

    // ══ PART B — the write sites consume the truthful value, not a literal ══

    /**
     * The body of one method, delimited by BRACE MATCHING rather than by a
     * fixed character window.
     *
     * It was a fixed 20,000-character window, and that quietly made the tests
     * below depend on how much PROSE the method carries: adding forensic
     * comments to `_verify_and_process` pushed `extra_receipt_fields` past the
     * cutoff, and the provenance assertion started failing with "anchor not
     * found" — reporting a missing receipt field that was present the whole
     * time. A guard that a comment can break is not guarding what it claims.
     *
     * `$window` is kept as a hard upper bound (and as the fallback if the
     * braces cannot be matched) so existing call sites are unaffected.
     */
    private function fnBody(string $marker, int $window = 20000): string
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $at = strpos($src, $marker);
        $this->assertNotFalse($at, "anchor not found: {$marker}");

        $open = strpos($src, '{', $at);
        if ($open === false) return substr($src, $at, $window);

        $depth = 0;
        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $at, $i - $at + 1);
            }
        }
        return substr($src, $at, $window);
    }

    /**
     * parent_verify_payment: the receipt-provenance field must read the
     * variable computed from verify_payment()'s gateway_name, not a literal.
     */
    public function test_parent_verify_payment_writes_the_derived_gateway_not_a_literal(): void
    {
        $body = $this->fnBody('public function parent_verify_payment');

        $this->assertMatchesRegularExpression(
            '/\$gatewayNameParent\s*=.*verifyResult\[.gateway_name.\]/s',
            $body,
            'the truthful gateway_name is no longer read from verify_payment()\'s result'
        );

        $extraFieldsAt = strpos($body, "'extra_receipt_fields'");
        $this->assertNotFalse($extraFieldsAt);
        $receiptBlock = substr($body, $extraFieldsAt, 1400);

        $this->assertStringContainsString("'gateway'   => \$gatewayNameParent,", $receiptBlock,
            'parent_verify_payment writes the receipt gateway field from something other than '
            . 'the derived $gatewayNameParent — PA-CORE-1 has regressed');
        $this->assertStringNotContainsString("'gateway'   => 'razorpay',", $receiptBlock,
            'parent_verify_payment hardcodes the receipt gateway field to \'razorpay\' again');
    }

    /** _verify_and_process: same defect, same fix, second call site. */
    public function test_verify_and_process_writes_the_derived_gateway_not_a_literal(): void
    {
        $body = $this->fnBody('private function _verify_and_process');

        $this->assertMatchesRegularExpression(
            '/\$gatewayName\s*=.*verifyResult\[.gateway_name.\]/s',
            $body,
            'the truthful gateway_name is no longer read from verify_payment()\'s result'
        );

        $extraFieldsAt = strpos($body, "'extra_receipt_fields'");
        $this->assertNotFalse($extraFieldsAt);
        $receiptBlock = substr($body, $extraFieldsAt, 1400);

        $this->assertStringContainsString("'gateway'   => \$gatewayName,", $receiptBlock,
            '_verify_and_process no longer writes the receipt gateway field from the derived '
            . '$gatewayName — PA-CORE-1 has regressed');
        $this->assertStringNotContainsString("'gateway'   => 'razorpay',", $receiptBlock,
            '_verify_and_process hardcodes the receipt gateway field to \'razorpay\' again');
    }

    /**
     * retry_payment_processing: does NOT call verify_payment() (documented in
     * its own source), so there is no fresh gateway_name — the order document's
     * OWN recorded gateway (written truthfully by create_order) is the correct
     * source here, with 'unknown' as the fallback. Never 'razorpay'.
     */
    public function test_retry_processing_reads_the_orders_own_gateway_not_a_literal(): void
    {
        $body = $this->fnBody('public function retry_payment_processing', 9000);

        $extraFieldsAt = strpos($body, "'extra_receipt_fields'");
        $this->assertNotFalse($extraFieldsAt);
        $receiptBlock = substr($body, $extraFieldsAt, 1400);

        $this->assertStringContainsString(
            "'gateway'   => (string) (\$order['gateway'] ?? 'unknown'),",
            $receiptBlock,
            'retry_payment_processing no longer reads the order\'s own recorded gateway, or '
            . 'no longer falls back to \'unknown\' — PA-CORE-1 has regressed'
        );
        $this->assertStringNotContainsString("'gateway'   => 'razorpay',", $receiptBlock,
            'retry_payment_processing hardcodes the receipt gateway field to \'razorpay\' again');
    }

    // ══ MISSING PROVENANCE MUST STAY VISIBLY MISSING ════════════════════════

    /**
     * fetch_online_payments(): an order with no recorded gateway must display
     * as 'unknown', never as 'razorpay'. This is the admin-facing list; a
     * silent default to 'razorpay' masks the exact condition GW-ORD-1 treats
     * as unsafe to reuse, displaying it as though it were the safe case.
     */
    public function test_fetch_online_payments_defaults_missing_provenance_to_unknown(): void
    {
        $body = $this->fnBody('public function fetch_online_payments');

        $this->assertStringContainsString(
            "'gateway'      => (string) (\$o['gateway']      ?? 'unknown'),",
            $body,
            'fetch_online_payments() no longer defaults an unrecorded gateway to \'unknown\''
        );
        $this->assertStringNotContainsString(
            "'gateway'      => (string) (\$o['gateway']      ?? 'razorpay'),",
            $body,
            'fetch_online_payments() defaults missing provenance to \'razorpay\' again — this '
            . 'masks exactly the condition GW-ORD-1 treats as unsafe to reuse'
        );
    }
}
