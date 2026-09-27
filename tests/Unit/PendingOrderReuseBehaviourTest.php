<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  GW-ORD-1 — END TO END THROUGH create_order()
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `PendingOrderGatewayProvenanceTest` drives the reuse PREDICATE. This drives
 * the whole `create_order()` call, because refusing to reuse a dead order is
 * only half the requirement — the other half is that a fresh, usable order is
 * actually minted in its place. A predicate that returns null while the caller
 * then fails, or returns the dead id anyway, would satisfy the first test and
 * still leave the parent unable to pay.
 *
 * Equally important in the other direction: de-duplication must SURVIVE. A
 * genuine in-flight order being re-minted is how a parent gets charged twice,
 * and that is a worse failure than the one being fixed here.
 *
 * The Firestore double records every write, so "a new order was persisted" is
 * asserted against what the code actually stored, not inferred from a return
 * value.
 */
class PendingOrderReuseBehaviourTest extends TestCase
{
    /** Records writes and serves whatever query rows it is given. */
    private function firebase(array $rows): object
    {
        return new class($rows) {
            public array $rows;
            public array $writes = [];
            public function __construct(array $rows) { $this->rows = $rows; }
            public function firestoreQuery($collection, $filters = []) { return $this->rows; }
            public function firestoreSet($collection, $docId, $data)
            {
                $this->writes[] = ['collection' => $collection, 'docId' => $docId, 'data' => $data];
                return true;
            }
        };
    }

    /** A gateway adapter that mints ids with the given prefix. */
    private function gateway(string $name, string $prefix): object
    {
        return new class($name, $prefix) {
            public string $name; public string $prefix; public int $calls = 0;
            public function __construct(string $n, string $p) { $this->name = $n; $this->prefix = $p; }
            public function get_name(): string { return $this->name; }
            public function create_order(float $amount, string $receiptRef = '', array $meta = []): array
            {
                $this->calls++;
                return [
                    'order_id' => $this->prefix . 'FRESH' . $this->calls,
                    'amount'   => $amount,
                    'currency' => 'INR',
                    'status'   => 'created',
                ];
            }
        };
    }

    private function orderRow(string $gwOrderId, string $gateway, array $months, float $amount): array
    {
        return [
            'id'   => 'SCH_B56BB9A401_' . $gwOrderId,
            'data' => [
                'schoolId'   => 'SCH_B56BB9A401', 'session' => '2026-27',
                'student_id' => 'STU0012', 'amount' => $amount,
                'fee_months' => $months, 'gateway' => $gateway,
                'gateway_order_id' => $gwOrderId, 'status' => 'created',
            ],
        ];
    }

    /** @return array{0: array, 1: object, 2: object} [result, firebase, gateway] */
    private function createOrder(array $existingRows, object $gateway): array
    {
        $svc = new Payment_service();
        $fb  = $this->firebase($existingRows);

        $ref = new ReflectionClass($svc);
        foreach (['firebase' => $fb, 'gateway' => $gateway, 'schoolId' => 'SCH_B56BB9A401',
                  'session'  => '2026-27', 'adminId' => 'PARENT', 'feesBase' => ''] as $p => $v) {
            $this->assertTrue($ref->hasProperty($p),
                "Payment_service::\${$p} is gone — this harness no longer models the real object");
            $prop = $ref->getProperty($p);
            $prop->setAccessible(true);
            $prop->setValue($svc, $v);
        }

        $result = $svc->create_order([
            'student_id'   => 'STU0012',
            'student_name' => 'Yugant',
            'amount'       => 700.00,
            'fee_months'   => ['April'],
            'class'        => '9th',
            'section'      => 'A',
        ]);

        return [$result, $fb, $gateway];
    }

    // ══ (E) A GATEWAY MISMATCH MINTS A FRESH ORDER ══════════════════════════

    /**
     * The live scenario, end to end: a mock order is on file, Razorpay is now
     * active. The parent must come away with a REAL Razorpay order.
     */
    public function test_a_mock_order_on_file_yields_a_fresh_razorpay_order(): void
    {
        $rows = [$this->orderRow('ORD_E5C263912368', 'mock', ['April'], 700.00)];
        [$result, $fb, $gw] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertNotSame('ORD_E5C263912368', $result['order_id'],
            'the dead mock order id is still handed to the Razorpay checkout');
        $this->assertStringStartsWith('order_', $result['order_id'],
            'the replacement order was not minted by the active Razorpay adapter');
        $this->assertFalse($result['existing'],
            'the result claims a reused order while actually returning a new id — the '
            . 'caller cannot tell which it received');

        $this->assertSame(1, $gw->calls,
            'the active gateway was never asked to create an order, so nothing real exists '
            . 'behind the id returned to the parent');

        $this->assertCount(1, $fb->writes, 'the fresh order was not persisted');
        $this->assertSame('feeOnlineOrders', $fb->writes[0]['collection']);
        $this->assertSame('razorpay', $fb->writes[0]['data']['gateway'],
            'the new order records no gateway, or the wrong one — which would make it '
            . 'unreusable by the very check that just created it');
    }

    /** Unrecorded provenance behaves the same way: replace, do not reuse. */
    public function test_an_order_with_no_recorded_gateway_is_replaced(): void
    {
        $rows = [$this->orderRow('ORD_NOPROVENANCE', '', ['April'], 700.00)];
        [$result, , $gw] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertStringStartsWith('order_', $result['order_id']);
        $this->assertSame(1, $gw->calls);
    }

    // ══ (H) DUPLICATE PROTECTION MUST NOT BE WEAKENED ═══════════════════════

    /**
     * THE COUNTERWEIGHT. If this fails, GW-ORD-1 has traded an unpayable order
     * for a double charge — a strictly worse outcome.
     */
    public function test_a_genuine_in_flight_razorpay_order_is_still_reused(): void
    {
        $rows = [$this->orderRow('order_LIVE0001', 'razorpay', ['April'], 700.00)];
        [$result, $fb, $gw] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertSame('order_LIVE0001', $result['order_id'],
            'a real in-flight Razorpay order is no longer reused, so a second order is '
            . 'created for money already in flight — this is how a parent is charged twice');
        $this->assertTrue($result['existing']);

        $this->assertSame(0, $gw->calls,
            'a duplicate order was created at the gateway for a payment already in flight');
        $this->assertCount(0, $fb->writes,
            'a duplicate order document was written for a payment already in flight');
    }

    /** Reuse must survive a casing difference rather than mint a duplicate. */
    public function test_reuse_survives_a_casing_difference_in_the_recorded_gateway(): void
    {
        $rows = [$this->orderRow('order_LIVE0002', 'Razorpay', ['April'], 700.00)];
        [$result, $fb] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertSame('order_LIVE0002', $result['order_id']);
        $this->assertCount(0, $fb->writes, 'a casing difference caused a duplicate order');
    }

    /**
     * A dead order must not mask a live one sitting behind it. Otherwise the
     * scan aborts early and a duplicate is minted for money in flight.
     */
    public function test_a_live_order_behind_a_dead_one_is_still_reused(): void
    {
        $rows = [
            $this->orderRow('ORD_DEADMOCK0001', 'mock',     ['April'], 700.00),
            $this->orderRow('order_LIVE0003',   'razorpay', ['April'], 700.00),
        ];
        [$result, $fb] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertSame('order_LIVE0003', $result['order_id'],
            'the dead order aborts the scan, so the live in-flight order is missed and a '
            . 'duplicate is created');
        $this->assertCount(0, $fb->writes);
    }

    /** The original month/amount predicate must be intact. */
    public function test_a_different_amount_still_mints_a_separate_order(): void
    {
        $rows = [$this->orderRow('order_LIVE0004', 'razorpay', ['April'], 350.00)];
        [$result, $fb] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertStringStartsWith('order_', $result['order_id']);
        $this->assertNotSame('order_LIVE0004', $result['order_id'],
            'an order for a DIFFERENT amount is being reused — the amount predicate was '
            . 'lost while adding the gateway check');
        $this->assertCount(1, $fb->writes);
    }

    public function test_a_different_month_still_mints_a_separate_order(): void
    {
        $rows = [$this->orderRow('order_LIVE0005', 'razorpay', ['May'], 700.00)];
        [$result] = $this->createOrder($rows, $this->gateway('razorpay', 'order_'));

        $this->assertNotSame('order_LIVE0005', $result['order_id'],
            'the month predicate was lost while adding the gateway check');
    }

    // ══ THE MOCK IS NOT SPECIAL — THE RULE IS SYMMETRIC ═════════════════════

    /**
     * Running the mock (development) must not reuse a Razorpay order either.
     * A rule that only ever rejects the mock would be a special case, and the
     * next adapter added would reintroduce the defect.
     */
    public function test_a_razorpay_order_is_not_reused_when_the_mock_is_active(): void
    {
        $rows = [$this->orderRow('order_LIVE0006', 'razorpay', ['April'], 700.00)];
        [$result, , $gw] = $this->createOrder($rows, $this->gateway('mock', 'ORD_'));

        $this->assertStringStartsWith('ORD_', $result['order_id'],
            'a Razorpay order id is handed to the mock adapter — the provenance rule is a '
            . 'special case against "mock" rather than a symmetric check');
        $this->assertSame(1, $gw->calls);
    }
}
