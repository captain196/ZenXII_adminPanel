<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Fee_firestore_txn.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  F4 / PAY-2 — PAYMENT-LEVEL IDEMPOTENCY IS PAYMENT-LEVEL
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The money path carries FOUR different guards, and the whole point is that
 * they answer four different questions:
 *
 *   ORDER claim            feeLocks/{school}_{orderId}       is an attempt running now?
 *   PAYMENT marker         processedPayments/{paymentId}     has THIS payment been applied?
 *   WEBHOOK EVENT dedup    feeIdempotency/{school}_{hash}    has THIS delivery been handled?
 *   RECEIPT reservation    feeReceiptIndex/...               has THIS number been issued?
 *
 * Collapsing any pair of them causes money loss in one direction or duplication
 * in the other. This file pins the PAYMENT marker specifically.
 *
 * ── THE KEY DECISION, AND WHY IT IS COUNTER-INTUITIVE (PAY-2) ─────────────
 * An idempotency key must be EXACTLY the identity of the thing that may happen
 * once. A gateway payment id is globally unique and belongs to exactly one
 * order, so `paymentId` alone identifies it completely.
 *
 * Keying by `{school}_{orderId}_{paymentId}` would be WORSE: it would create
 * two keys for one payment whenever the order id differed on a retry, so the
 * same payment could be applied twice, each time under a pristine-looking key.
 * A more specific key is a WEAKER guarantee when the extra component is not
 * part of the identity.
 *
 * So orderId and schoolId are VERIFIED FIELDS, never key components — and the
 * tests below pin both halves of that: the marker must not be order-global, and
 * a marker whose order or tenant disagrees must not be trusted.
 */
class ProcessedPaymentIdempotencyTest extends TestCase
{
    private const SCHOOL  = 'SCH_IDEMPOTENT';
    private const SESSION = '2026-27';

    private function firebase()
    {
        return new class {
            public array $store = [];
            public array $reads = [];

            public function firestoreGet($collection, $docId): ?array
            {
                $this->reads[] = "{$collection}/{$docId}";
                return $this->store["{$collection}/{$docId}"] ?? null;
            }

            public function firestoreSet($collection, $docId, $data, $merge = false): bool
            {
                $key = "{$collection}/{$docId}";
                $this->store[$key] = $merge && isset($this->store[$key])
                    ? array_merge($this->store[$key], $data)
                    : $data;
                return true;
            }
        };
    }

    private function txn($firebase, string $school = self::SCHOOL): Fee_firestore_txn
    {
        $t = new Fee_firestore_txn();
        // fs is only required to be non-null for the ready flag.
        $t->init($firebase, new class {}, $school, self::SESSION);
        return $t;
    }

    // ══ THE KEY IS THE PAYMENT, NOT THE ORDER ═══════════════════════════════

    /**
     * THE CASE THE MANDATE NAMES. One gateway order may legitimately carry more
     * than one payment attempt where the provider model permits it. The marker
     * must not suppress the second one.
     */
    public function test_a_second_genuine_payment_on_the_same_order_is_not_suppressed(): void
    {
        $fb  = $this->firebase();
        $txn = $this->txn($fb);

        $txn->markPaymentProcessed('pay_FIRST', 'R001', 'FR001', 'STU1', 'order_SAME');

        $this->assertNull(
            $txn->getProcessedPayment('pay_SECOND'),
            'a DIFFERENT payment on the same order was reported as already processed. '
            . 'The marker would be order-global, and the second payment — real money '
            . 'the parent has already been charged — would never be applied.'
        );

        $txn->markPaymentProcessed('pay_SECOND', 'R002', 'FR002', 'STU1', 'order_SAME');

        $this->assertSame('FR001', $txn->getProcessedPayment('pay_FIRST')['receiptKey']);
        $this->assertSame('FR002', $txn->getProcessedPayment('pay_SECOND')['receiptKey'],
            'the two payments must resolve to their OWN receipts');
    }

    public function test_the_same_payment_presented_twice_is_suppressed(): void
    {
        $fb  = $this->firebase();
        $txn = $this->txn($fb);

        $this->assertNull($txn->getProcessedPayment('pay_ONCE'),
            'a fresh payment must not appear processed');

        $txn->markPaymentProcessed('pay_ONCE', 'R010', 'FR010', 'STU1', 'order_A');

        $marker = $txn->getProcessedPayment('pay_ONCE');
        $this->assertIsArray($marker, 'a replayed payment must be recognised');
        $this->assertSame('FR010', $marker['receiptKey'],
            'the replay must return the ORIGINAL receipt, never mint a second');
    }

    /**
     * The document id must be the bare payment id. If it ever became
     * school- or order-prefixed, the same gateway payment could be applied once
     * per prefix.
     */
    public function test_the_marker_document_is_keyed_by_the_bare_payment_id(): void
    {
        $fb  = $this->firebase();
        $this->txn($fb)->markPaymentProcessed('pay_KEYCHECK', 'R1', 'FR1', 'STU1', 'order_A');

        $this->assertArrayHasKey('processedPayments/pay_KEYCHECK', $fb->store,
            'the marker key changed shape. An idempotency key must be exactly the '
            . 'identity of the operation — adding a school or order component creates '
            . 'a second key for one payment and permits a double application.');
    }

    // ══ ORDER IS VERIFIED, NOT KEYED (PAY-2) ════════════════════════════════

    public function test_the_marker_records_the_order_it_belonged_to(): void
    {
        $fb = $this->firebase();
        $this->txn($fb)->markPaymentProcessed('pay_ORD', 'R1', 'FR1', 'STU1', 'order_XYZ');

        $this->assertSame('order_XYZ', $fb->store['processedPayments/pay_ORD']['orderId'],
            'without a recorded order the shared core cannot tell a genuine replay from '
            . 'a payment id presented against the wrong order');
    }

    public function test_a_marker_written_before_the_order_field_existed_still_resolves(): void
    {
        $fb = $this->firebase();
        $fb->store['processedPayments/pay_LEGACY'] = [
            'paymentId' => 'pay_LEGACY', 'schoolId' => self::SCHOOL,
            'receiptKey' => 'FR_OLD', 'status' => 'success',
        ];

        $marker = $this->txn($fb)->getProcessedPayment('pay_LEGACY');
        $this->assertIsArray($marker, 'a pre-existing marker must not stop being honoured');
        $this->assertSame('', (string) ($marker['orderId'] ?? ''),
            'a legacy marker has no order, so the caller must skip the order check '
            . 'rather than refuse');
    }

    // ══ TENANT SAFETY (PAY-1) ═══════════════════════════════════════════════

    /**
     * processedPayments is a GLOBAL keyspace — deliberately, because the gateway
     * identity is global. That makes the tenant check a field check, and it must
     * actually happen.
     */
    public function test_a_marker_from_another_tenant_is_not_treated_as_processed(): void
    {
        $fb = $this->firebase();
        $this->txn($fb, 'SCH_AAA')->markPaymentProcessed('pay_SHARED', 'R1', 'FR1', 'STU1', 'order_A');

        $this->assertNull(
            $this->txn($fb, 'SCH_BBB')->getProcessedPayment('pay_SHARED'),
            "another tenant's marker was accepted as this tenant's — the parent would be "
            . 'told the payment was applied while nothing was applied here'
        );

        $this->assertIsArray(
            $this->txn($fb, 'SCH_AAA')->getProcessedPayment('pay_SHARED'),
            'the owning tenant must still see its own marker'
        );
    }

    public function test_the_legacy_scoped_fallback_is_still_consulted(): void
    {
        $fb = $this->firebase();

        // Pre-migration entries lived under feeIdempotency, school-scoped, with
        // the literal id "{schoolId}_pay_{paymentId}". Note the doubled "pay_":
        // gateway payment ids ALREADY begin with "pay_", so the real key is
        // "SCH_X_pay_pay_ABC". Built here the way the code builds it rather than
        // hand-written, because an invented fixture would have made this test
        // pass or fail for the wrong reason.
        $paymentId = 'pay_OLDPATH';
        $legacyId  = self::SCHOOL . '_pay_' . $paymentId;
        $fb->store['feeIdempotency/' . $legacyId] = [
            'paymentId' => $paymentId, 'receiptKey' => 'FR_LEGACY', 'status' => 'success',
        ];

        $marker = $this->txn($fb)->getProcessedPayment($paymentId);
        $this->assertIsArray($marker,
            'the legacy fallback was dropped — payments processed before the migration '
            . 'would lose their idempotency and could be re-applied on a retry');
        $this->assertSame('FR_LEGACY', $marker['receiptKey']);
    }

    // ══ FAILURE SEMANTICS ═══════════════════════════════════════════════════

    /**
     * A marker is only meaningful when the payment SUCCEEDED. A non-success row
     * must not suppress a retry.
     */
    public function test_a_non_success_marker_does_not_suppress_the_payment(): void
    {
        $fb = $this->firebase();
        $fb->store['processedPayments/pay_FAILED'] = [
            'paymentId' => 'pay_FAILED', 'schoolId' => self::SCHOOL,
            'receiptKey' => '', 'status' => 'failed',
        ];

        $this->assertNull($this->txn($fb)->getProcessedPayment('pay_FAILED'),
            'a failed marker suppressed a legitimate retry — the parent would be charged '
            . 'with nothing applied');
    }

    public function test_an_unready_helper_reports_nothing_rather_than_guessing(): void
    {
        $txn = new Fee_firestore_txn();   // never init()-ed

        $this->assertNull($txn->getProcessedPayment('pay_X'),
            'an uninitialised helper must not claim a payment was processed');
        $this->assertFalse($txn->markPaymentProcessed('pay_X', 'R', 'FR', 'STU', 'order_A'),
            'an uninitialised helper must report that it did NOT record the marker, so '
            . 'the caller can log the gap instead of believing it is durable');
    }

    public function test_an_empty_payment_id_is_refused_in_both_directions(): void
    {
        $fb  = $this->firebase();
        $txn = $this->txn($fb);

        $this->assertNull($txn->getProcessedPayment(''));
        $this->assertFalse($txn->markPaymentProcessed('', 'R', 'FR', 'STU', 'order_A'));
        $this->assertSame([], $fb->store,
            'an empty payment id wrote a marker — a single junk document would then '
            . 'suppress or corrupt an unrelated payment');
    }
}
