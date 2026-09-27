<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-ORD-1 — A PENDING ORDER IS ONLY REUSABLE ON THE GATEWAY THAT MINTED IT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── HOW THIS WAS FOUND ────────────────────────────────────────────────────
 * A live payment failed the instant the Razorpay sheet rendered — before any
 * card was entered — with `step: payment_authentication`, which reads exactly
 * like a declined card. It was not.
 *
 * The order row for that student, read out of Firestore, carried:
 *
 *     gateway_order_id: "ORD_E5C263912368"
 *
 * Razorpay order ids begin with `order_`. `ORD_` + 12 hex is what
 * `Payment_gateway_mock::create_order()` mints. The order had never reached
 * Razorpay at all, and the checkout was being opened against an id the gateway
 * had never issued.
 *
 * ── WHY IT COULD NOT HEAL ─────────────────────────────────────────────────
 * `create_order()` de-duplicates in-flight orders on
 * school + session + student + months + amount. It did NOT check which adapter
 * produced the id it was handing back. So once one unusable order existed, the
 * dedupe returned that same dead id on every retry: fixing the gateway did not
 * fix the payment, and never would have. The combination was permanently
 * poisoned, and the failure was indistinguishable from a bank decline.
 *
 * That is the part worth guarding. A wrong order id is a bug; a wrong order id
 * that survives the fix is a trap.
 *
 * ── WHAT THIS TEST DRIVES ─────────────────────────────────────────────────
 * The real `Payment_service::_find_pending_order()`, reached by reflection
 * because `init()` requires a CodeIgniter instance. The Firestore double
 * returns rows in the shape the real client returns them, including the row
 * observed live.
 */
class PendingOrderGatewayProvenanceTest extends TestCase
{
    /** Returns whatever rows it is given, in the real client's shape. */
    private function firebaseReturning(array $rows): object
    {
        return new class($rows) {
            public array $rows;
            public array $queriedCollections = [];
            public function __construct(array $rows) { $this->rows = $rows; }
            public function firestoreQuery($collection, $filters = [])
            {
                $this->queriedCollections[] = $collection;
                return $this->rows;
            }
        };
    }

    /** One order row as Payment_service::create_order() writes it. */
    private function orderRow(string $gwOrderId, string $gateway, array $months, float $amount): array
    {
        return [
            'id'   => 'SCH_B56BB9A401_' . $gwOrderId,
            'data' => [
                'schoolId'         => 'SCH_B56BB9A401',
                'session'          => '2026-27',
                'student_id'       => 'STU0012',
                'amount'           => $amount,
                'fee_months'       => $months,
                'gateway'          => $gateway,
                'gateway_order_id' => $gwOrderId,
                'status'           => 'created',
            ],
        ];
    }

    /**
     * Calls the real private method with the real object graph.
     *
     * @return array|null the reused order, or null when none may be reused
     */
    private function findPending(array $rows, string $activeGateway): ?array
    {
        $svc = new Payment_service();

        $ref = new ReflectionClass($svc);
        foreach (['firebase' => $this->firebaseReturning($rows),
                  'schoolId' => 'SCH_B56BB9A401',
                  'session'  => '2026-27'] as $prop => $value) {
            if (!$ref->hasProperty($prop)) {
                $this->fail("Payment_service::\${$prop} is gone — this test no longer "
                          . 'models the object it claims to test');
            }
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $value);
        }

        $m = $ref->getMethod('_find_pending_order');
        $m->setAccessible(true);

        return $m->invoke($svc, 'STU0012', ['April'], 700.00, $activeGateway);
    }

    // ══ THE CONTROL ═════════════════════════════════════════════════════════

    /**
     * Proves the double can produce a reuse at all. Without this, every
     * "returns null" assertion below would pass on a broken fixture.
     */
    public function test_control_a_matching_order_on_the_same_gateway_is_reused(): void
    {
        $found = $this->findPending(
            [$this->orderRow('order_MtX8q1LpZk2ab', 'razorpay', ['April'], 700.00)],
            'razorpay'
        );

        $this->assertNotNull($found,
            'a genuine in-flight Razorpay order is no longer reused. GW-ORD-1 must not '
            . 'break de-duplication — creating a second order for money already in flight '
            . 'is how a parent gets charged twice.');
        $this->assertSame('order_MtX8q1LpZk2ab', $found['gateway_order_id']);
    }

    // ══ THE LIVE DEFECT ═════════════════════════════════════════════════════

    /**
     * The exact row observed in production. A mock-minted id must never be
     * handed to the Razorpay checkout.
     */
    public function test_the_live_mock_order_is_not_reused_against_razorpay(): void
    {
        $found = $this->findPending(
            [$this->orderRow('ORD_E5C263912368', 'mock', ['April'], 700.00)],
            'razorpay'
        );

        $this->assertNull($found,
            'the mock-minted order ORD_E5C263912368 is being returned to a Razorpay '
            . 'checkout. Razorpay refuses an id it never issued the moment the sheet '
            . 'opens, and the parent sees a generic "Payment failed" that looks exactly '
            . 'like a declined card.');
    }

    /**
     * The trap, stated directly: repairing the gateway must actually repair the
     * payment. If the stale row still wins, every retry fails identically.
     */
    public function test_fixing_the_gateway_actually_unblocks_the_student(): void
    {
        $rows = [$this->orderRow('ORD_E5C263912368', 'mock', ['April'], 700.00)];

        // Gateway has since been corrected to Razorpay. The next attempt must
        // NOT be answered with the dead id.
        $this->assertNull($this->findPending($rows, 'razorpay'),
            'the stale order survives the gateway fix, so this student+months+amount '
            . 'combination stays permanently unpayable — the failure outlives its cause');
    }

    // ══ PROVENANCE THAT CANNOT BE ESTABLISHED IS NOT PROVENANCE ═════════════

    /**
     * An order with no recorded gateway cannot be proven to belong to the
     * active one. Reusing it is a coin flip with a parent's payment.
     */
    public function test_an_order_with_no_recorded_gateway_is_not_reused(): void
    {
        $row = $this->orderRow('ORD_UNKNOWNORIGIN', '', ['April'], 700.00);

        $this->assertNull($this->findPending([$row], 'razorpay'),
            'an order whose minting gateway was never recorded is reused anyway. Its '
            . 'provenance is unknown, so it cannot be shown to be payable.');
    }

    // ══ IT MUST NOT OVER-REJECT ═════════════════════════════════════════════

    /** Adapter names are recorded by hand in places; casing must not strand an order. */
    public function test_gateway_matching_is_case_insensitive(): void
    {
        $found = $this->findPending(
            [$this->orderRow('order_MtX8q1LpZk2ab', 'Razorpay', ['April'], 700.00)],
            'razorpay'
        );

        $this->assertNotNull($found,
            'a casing difference in the recorded gateway name discards a perfectly '
            . 'valid in-flight order and creates a duplicate');
    }

    /**
     * A usable order sitting behind an unusable one must still be found —
     * the mismatch has to `continue`, not abort the scan.
     */
    public function test_a_valid_order_behind_a_stale_one_is_still_found(): void
    {
        $found = $this->findPending([
            $this->orderRow('ORD_E5C263912368', 'mock',     ['April'], 700.00),
            $this->orderRow('order_REALONE001', 'razorpay', ['April'], 700.00),
        ], 'razorpay');

        $this->assertNotNull($found,
            'the stale row aborts the scan instead of being skipped, so a real in-flight '
            . 'order is missed and a duplicate is created for money already in flight');
        $this->assertSame('order_REALONE001', $found['gateway_order_id']);
    }

    /** The months/amount predicate must keep working — GW-ORD-1 only narrows it. */
    public function test_a_different_amount_is_still_not_reused(): void
    {
        $this->assertNull(
            $this->findPending(
                [$this->orderRow('order_MtX8q1LpZk2ab', 'razorpay', ['April'], 350.00)],
                'razorpay'
            ),
            'the amount predicate was lost while adding the gateway check'
        );
    }

    // ══ THE CALLER MUST ACTUALLY PASS THE ACTIVE GATEWAY ════════════════════

    /**
     * The guard is inert if `create_order()` never supplies the argument — the
     * default is an empty string, which disables the check by design.
     */
    public function test_create_order_passes_the_active_gateway_to_the_dedupe(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_service.php');

        $this->assertStringContainsString(
            '$this->_find_pending_order($studentId, $feeMonths, $amount, $activeGateway)',
            $src,
            'create_order() no longer passes the active gateway, so the provenance check '
            . 'silently defaults to disabled and the trap returns');

        $callAt  = strpos($src, '$this->_find_pending_order($studentId');
        $resolve = strpos($src, '$activeGateway = method_exists($this->gateway');
        $this->assertNotFalse($resolve, 'the active-gateway resolution is gone');
        $this->assertLessThan($callAt, $resolve,
            'the active gateway is resolved after it is used');
    }
}
