<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  H1-F1 / PAY-2 / REF1-F1 — THE THREE FIXES THAT SHIPPED WITHOUT A GUARD
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Written after auditing my own coverage and finding that three fixes from the
 * payment-hardening pass — including the HIGHEST-severity one — had no
 * regression test at all. Every assertion below is stated so that it would have
 * FAILED against the pre-fix code and PASSES against the current code; where an
 * assertion could pass either way, that is called out rather than hidden.
 *
 * These are structural tests. The properties being protected are the SHAPE of
 * an atomic batch and the ORDERING of a guard — neither is reachable through a
 * unit-level call without bootstrapping CodeIgniter and a live Firestore, and a
 * mock-based "test" of an atomic commit would assert the mock, not the atomicity.
 */
class PaymentMarkerAtomicityTest extends TestCase
{
    private static string $svc  = '';
    private static string $ctrl = '';
    private static string $rest = '';

    public static function setUpBeforeClass(): void
    {
        self::$svc  = (string) file_get_contents(APPPATH . 'services/FeeCollectionService.php');
        self::$ctrl = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        self::$rest = (string) file_get_contents(APPPATH . 'libraries/Firestore_rest_client.php');
    }

    // ══ H1-F1 · THE MARKER IS IN THE MONEY BATCH ════════════════════════════

    /**
     * The invariant "one successful payment → one receipt" was NOT held by the
     * feeIdempotency layer:
     *
     *     idempKey  = md5(userId | receiptNo | months | amount)
     *     receiptNo = nextCounter('receipt_seq')   ← a NEW number every attempt
     *
     * so a retry computes a DIFFERENT hash and the slot cannot match. It only
     * guards a duplicate submission of the SAME receipt number.
     *
     * What actually held the invariant were two writes the CONTROLLER made
     * AFTER the money batch committed. A crash in between left a durable
     * receipt with nothing recording the payment as applied, so the gateway's
     * retry minted a SECOND receipt against the same money.
     *
     * WOULD HAVE FAILED BEFORE: `processedPayments` did not appear anywhere in
     * FeeCollectionService — the marker was written only by the controller.
     */
    public function test_the_payment_marker_is_written_by_the_collection_service(): void
    {
        $this->assertStringContainsString("'collection' => 'processedPayments'", self::$svc,
            'FeeCollectionService no longer writes the payment marker. Written only by the '
            . 'controller AFTER the money batch, a crash in between leaves a durable receipt '
            . 'that nothing records as applied — and the gateway retry mints a second one.');
    }

    /**
     * THE ACTUAL PROPERTY: same array, therefore same commit, therefore atomic.
     * A marker written in a SEPARATE batch would satisfy the test above while
     * leaving the exact window H1-F1 closes.
     */
    public function test_the_marker_and_the_receipt_are_in_the_same_atomic_batch(): void
    {
        $receiptOp = strpos(self::$svc, "'collection' => 'feeReceipts'");
        $markerOp  = strpos(self::$svc, "'collection' => 'processedPayments'");

        $this->assertNotFalse($receiptOp, 'the receipt write op was renamed');
        $this->assertNotFalse($markerOp,  'the marker op is missing — see the test above');

        // Both must be appended to $batchOps, not to a second ops array.
        $between = substr(self::$svc, min($receiptOp, $markerOp),
                          abs($markerOp - $receiptOp) + 400);

        $this->assertStringNotContainsString('firestoreCommitBatch', $between,
            'a commit happens BETWEEN the receipt op and the marker op, so they land in '
            . 'two different batches. They must commit together, or the window where a '
            . 'receipt exists without a marker is still open — that IS H1-F1.');

        $this->assertMatchesRegularExpression(
            '/\$batchOps\[\]\s*=\s*\[\s*\'op\'\s*=>\s*\'set\',\s*\'collection\'\s*=>\s*\'processedPayments\'/',
            self::$svc,
            'the marker is not appended to $batchOps — it must ride the same array the '
            . 'receipt, allocations and demand updates ride'
        );
    }

    /**
     * Cash and cheque collections have no gateway payment id. An unguarded
     * write would key a marker on '' — a single junk document able to suppress
     * or corrupt an unrelated payment.
     */
    public function test_the_marker_is_skipped_when_there_is_no_gateway_payment(): void
    {
        $markerOp = strpos(self::$svc, "'collection' => 'processedPayments'");
        $this->assertNotFalse($markerOp);

        $before = substr(self::$svc, max(0, $markerOp - 900), 900);

        $this->assertMatchesRegularExpression('/if\s*\(\s*\$__gwPaymentId\s*!==\s*\'\'\s*\)/', $before,
            'the marker op is not guarded by a non-empty gateway payment id. A cash '
            . "collection would then write processedPayments/'' — one junk document that "
            . 'can suppress an unrelated payment.');
    }

    /**
     * The gateway ids reach the service through `extra_receipt_fields`, which is
     * where BOTH parent paths put them. Reading only the snake_case top-level
     * names would make the marker silently dead code — which is exactly what the
     * first draft of this fix did.
     */
    public function test_the_marker_reads_the_field_the_callers_actually_send(): void
    {
        $this->assertStringContainsString("\$__extraRcpt['paymentId']", self::$svc,
            'the service reads the gateway payment id from a field the callers do not send. '
            . 'Both parent paths pass it inside extra_receipt_fields as camelCase '
            . 'paymentId/orderId; reading only $data[\'payment_id\'] makes the whole marker '
            . 'op dead code that never fires.');

        // EVERY caller must supply it. There are THREE completion paths that
        // hand a payment to FeeCollectionService, not two — parent_verify_payment,
        // _verify_and_process, and retry_payment_processing. Asserting an exact
        // count of 2 was itself a bug in this test, and finding the third path
        // is how the missing claim on the admin retry was discovered.
        $submits = substr_count(self::$ctrl, '$service->submit($this, [');
        $passes  = substr_count(self::$ctrl, "'paymentId' => \$gwPaymentId");

        $this->assertGreaterThanOrEqual(3, $submits,
            'the number of FeeCollectionService::submit() call sites shrank — re-derive '
            . 'which completion paths exist before trusting the coverage tests');
        $this->assertSame($submits, $passes,
            "{$submits} completion paths hand a payment to FeeCollectionService but only "
            . "{$passes} pass the gateway ids. A path that omits them writes no atomic "
            . 'marker and keeps the H1-F1 window open on that path.');
    }

    // ══ PAY-2 · ONE PAYMENT BELONGS TO ONE ORDER ════════════════════════════

    /**
     * A gateway payment id is globally unique and belongs to exactly one order.
     * A marker saying payment P belonged to order X, presented while processing
     * P for order Y, cannot both be true — and is NOT safe to resolve either
     * way: "processed" returns a receipt describing a different order,
     * "fresh" re-applies a payment that already landed.
     *
     * WOULD HAVE FAILED BEFORE: the shared core returned the stored receipt on
     * ANY marker hit, without comparing the order.
     */
    public function test_the_shared_core_refuses_a_marker_from_a_different_order(): void
    {
        $core = strpos(self::$ctrl, 'private function _verify_and_process');
        $this->assertNotFalse($core);
        $body = substr(self::$ctrl, $core, 14000);

        $this->assertStringContainsString("\$markerOrder = (string) (\$priorPayment['orderId'] ?? '')", $body,
            'the shared core no longer reads the order recorded on the payment marker');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$markerOrder\s*!==\s*\'\'\s*&&\s*\$markerOrder\s*!==\s*\$gwOrderId\s*\)/',
            $body,
            'the shared core does not compare the marker\'s order against the order being '
            . 'processed. One payment cannot belong to two orders; treating that as an '
            . 'ordinary replay returns a receipt that does not describe the money received.'
        );
    }

    /**
     * The comparison must be SKIPPED when the marker predates the field, or
     * every historical payment would start refusing.
     */
    public function test_the_order_check_is_skipped_for_markers_without_an_order(): void
    {
        $core = strpos(self::$ctrl, 'private function _verify_and_process');
        $body = substr(self::$ctrl, $core, 14000);

        $this->assertStringContainsString("\$markerOrder !== ''", $body,
            'the order comparison is not guarded against an empty stored order. Markers '
            . 'written before this field existed carry none, and would all begin refusing.');
    }

    /**
     * PAY-2's key decision, pinned where it is easiest to get wrong later.
     * Adding orderId to the KEY creates two keys for one payment, which permits
     * a double application — a more specific key is a WEAKER guarantee here.
     */
    public function test_the_marker_is_keyed_by_the_payment_alone(): void
    {
        $markerOp = strpos(self::$svc, "'collection' => 'processedPayments'");
        $window   = substr(self::$svc, $markerOp, 300);

        $this->assertMatchesRegularExpression('/\'docId\'\s*=>\s*\$__gwPaymentId/', $window,
            'the marker doc id is no longer the bare payment id. Composing it with the '
            . 'school or the order creates a SECOND key for the SAME payment, so the same '
            . 'money can be applied twice — each time under a pristine-looking key.');
    }

    // ══ REF1-F1 · 409 IS A CONFLICT, NOT AN UNKNOWN ═════════════════════════

    /**
     * Emulator-proven (payment_claim_cas.test.js): the two precondition forms
     * fail with DIFFERENT codes —
     *
     *     currentDocument.updateTime → 400 FAILED_PRECONDITION
     *     currentDocument.exists     → 409 ALREADY_EXISTS
     *
     * The classifier understood only the 400 form, so a 409 fell through to
     * COMMIT_FAILURE — "outcome UNKNOWN". That distinction drives opposite
     * recoveries, and the refund path must not discard its idempotency guard on
     * UNKNOWN.
     *
     * WOULD HAVE FAILED BEFORE: no 409 branch existed in commitBatchStatus().
     */
    public function test_the_commit_classifier_treats_409_as_a_conflict(): void
    {
        $fn = strpos(self::$rest, 'public function commitBatchStatus');
        $this->assertNotFalse($fn, 'commitBatchStatus was renamed');
        $body = substr(self::$rest, $fn, 4000);

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$code\s*===\s*409\s*\|\|\s*\$errStatus\s*===\s*\'ALREADY_EXISTS\'\s*\)/',
            $body,
            'commitBatchStatus does not classify 409/ALREADY_EXISTS. An `exists:false` '
            . 'precondition conflict then reports as COMMIT_FAILURE — "outcome UNKNOWN" — '
            . 'so an ordinary lock conflict becomes indistinguishable from a transport '
            . 'failure. That is the exact conflation REF-1 exists to remove.'
        );

        // It must map to CONFLICT, not to some third thing. Anchor on the `if`
        // itself — a bare strpos('409') lands in the explanatory comment above
        // it, which would make this assertion about prose rather than code.
        $ifAt = strpos($body, '$code === 409');
        $this->assertNotFalse($ifAt);
        $branch = substr($body, $ifAt, 400);

        $this->assertStringContainsString('COMMIT_CONFLICT', $branch,
            'the 409 branch does not resolve to COMMIT_CONFLICT');
    }

    /**
     * The 400 branch must survive alongside it — the updateTime CAS is what the
     * payment claim and the refund lock takeover both rely on.
     */
    public function test_the_400_precondition_branch_still_exists(): void
    {
        $fn   = strpos(self::$rest, 'public function commitBatchStatus');
        $body = substr(self::$rest, $fn, 4000);

        $this->assertStringContainsString("FAILED_PRECONDITION", $body,
            'the updateTime-precondition branch was removed; every CAS takeover in the '
            . 'payment and refund paths depends on it being classified as a conflict');
    }
}
