<?php

use PHPUnit\Framework\TestCase;

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE ADMISSION FEE — A DOCUMENTED NON-BILLING-V2 MONEY PATH
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Section 13 of the standing directive: *"No payment path may bypass canonical
 * receipt creation unless explicitly classified as non-Billing-V2 with a
 * documented lifecycle."* The admission fee is such a path, and this file is
 * the record of the trace that established it.
 *
 * `Admission_collection` writes **no feeDemand at all** — verified by search,
 * not by reading its docblock. The admission fee is collected at the counter or
 * through the public admission gateway flow, receipted, and journaled to 4020.
 * There is no "unpaid admission fee" state, which is why there is no demand:
 * the money is taken before the student exists.
 *
 * ── THE WAIVER IS PRE-COLLECTION, WHICH IS WHY IT IS SAFE ─────────────────
 * `Sis::_admission_fee_apply()` returns `status: 'waived'` as a RESPONSE value,
 * not a Firestore write. When waived, `Admission_collection` is never invoked —
 * so no receipt, no journal, no demand. The obligation is never raised rather
 * than raised-and-cancelled.
 *
 * That is the correct shape, and it is what makes "a paid demand must never
 * become waived" trivially true here: at the moment of the waiver decision
 * there is nothing to have been paid. Compare `Student_charge_service::waive()`,
 * where the demand already exists and a concurrent payment CAN land — which is
 * exactly why WAIVE-RACE-1 needed a CAS precondition and this path does not.
 *
 * ── A DEDICATED RECEIPT SERIES IS DELIBERATE ──────────────────────────────
 * It mints ADM receipt numbers through the same atomic `feeCounters` mechanism
 * the fee series uses, on a separate series. One numbering MECHANISM, two
 * series — normal ERP practice, and not the "second numbering series" the
 * Library convergence decision refuses (that would have been a second
 * mechanism).
 */
class AdmissionFeeCollectionContractTest extends TestCase
{
    private static string $col = '';
    private static string $sis = '';

    public static function setUpBeforeClass(): void
    {
        self::$col = (string) file_get_contents(APPPATH . 'libraries/Admission_collection.php');
        self::$sis = (string) file_get_contents(APPPATH . 'controllers/Sis.php');
    }

    private function code(string $src): string
    {
        $s = preg_replace('!/\*.*?\*/!s', '', $src);
        return (string) preg_replace('!^\s*//.*$!m', '', (string) $s);
    }

    // ══ THE CLASSIFICATION ══════════════════════════════════════════════════

    /**
     * The premise. If this ever raises a demand it stops being a separate
     * lifecycle and must be re-certified as part of Billing V2.
     */
    public function test_the_admission_fee_raises_no_fee_demand(): void
    {
        $code = $this->code(self::$col);

        foreach (['feeDemands', 'writeDemand', 'createModuleFee', 'updateDemand'] as $needle) {
            $this->assertStringNotContainsString($needle, $code,
                "Admission_collection now touches '{$needle}'. It was classified as a "
                . 'receipt-only path precisely because it raises no demand — if it now does, '
                . 'it belongs inside Billing V2 and needs the demand, allocation, defaulter '
                . 'and Parent-visibility contracts that go with that.');
        }
    }

    /**
     * A waiver here must remain pre-collection. If it ever waived AFTER
     * collection it would need the WAIVE-RACE-1 treatment — a CAS precondition
     * against a concurrent payment.
     */
    public function test_a_waived_admission_fee_never_reaches_the_collection_path(): void
    {
        $at = strpos(self::$sis, 'private function _admission_fee_apply');
        $this->assertNotFalse($at, '_admission_fee_apply was renamed');

        $end  = strpos(self::$sis, "\n    private function ", $at + 30);
        $body = substr(self::$sis, $at, ($end !== false ? $end - $at : 6000));

        $waiveAt   = strpos($body, "if (!empty(\$plan['waived'])) {");
        $collectAt = strpos($body, 'admissionCol');

        $this->assertNotFalse($waiveAt, 'the waiver branch is gone');
        $this->assertNotFalse($collectAt, 'the collection call is gone');
        $this->assertLessThan($collectAt, $waiveAt,
            'the waiver check no longer precedes collection. A waiver decided AFTER the money '
            . 'is taken is a refund, not a waiver, and would need the concurrency protection '
            . 'WAIVE-RACE-1 added to the Student Charge path.');

        // And the waiver branch must RETURN, not fall through into collection.
        $branch = substr($body, $waiveAt, max(0, $collectAt - $waiveAt));
        $this->assertStringContainsString('return [', $branch,
            'the waiver branch falls through to the collection call, so a waived admission '
            . 'fee would still be charged');
    }

    /** A waiver must still be audited — an unaudited exception is indistinguishable from a skip. */
    public function test_a_waiver_is_audited(): void
    {
        $at   = strpos(self::$sis, 'private function _admission_fee_apply');
        $body = substr(self::$sis, $at, 3000);

        $this->assertStringContainsString("log_audit('Fees', 'admission_fee_waived'", $body,
            'an admission-fee waiver is no longer audited, so waiving is indistinguishable '
            . 'from silently never charging');
        $this->assertStringContainsString('ADMISSION_FEE_WAIVED', $body,
            'the waiver no longer appears in the student history');
    }

    // ══ ADM-IDEM-1 · THE DEDUP KEY ══════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the last-resort key was
     * `'R' . bin2hex(random_bytes(6))`.
     *
     * A random key is unique by construction, so the claim ALWAYS succeeds and
     * the gate stops existing. That gate is what closes the callback-vs-webhook
     * double-reconcile (C1) and the double offline-collect (M2) — and
     * `Admission_public` passes `source: 'callback' | 'webhook'`, so both really
     * do fire for one payment.
     *
     * Not reachable from either production caller today, verified by tracing
     * both. Fixed anyway: a fail-OPEN default on the identity a money guard
     * keys on removes the protection with no error and no log the moment a
     * third caller appears.
     */
    public function test_a_missing_identity_refuses_instead_of_inventing_one(): void
    {
        $code = $this->code(self::$col);

        $this->assertStringNotContainsString('bin2hex(random_bytes(6))', $code,
            'the idempotency key can still fall back to a random value. A random key always '
            . 'wins its claim, so the duplicate-receipt guard silently ceases to exist.');

        $this->assertStringContainsString(
            "\$idempSource = \$appId !== '' ? \$appId : (string) (\$p['payment_id'] ?? '');", $code,
            'the identity resolution changed — confirm it still prefers app_id and still has '
            . 'a deterministic fallback');
        $this->assertStringContainsString("if (\$idempSource === '') {", $code,
            'a payload with neither app_id nor payment_id is accepted again');
    }

    /** Both production callers must keep supplying a stable identity. */
    public function test_both_callers_supply_a_stable_identity(): void
    {
        $sisAt   = strpos(self::$sis, '$rc = $this->admissionCol->record_payment([');
        $this->assertNotFalse($sisAt, 'the SIS collection call was renamed');
        $sisBody = substr(self::$sis, $sisAt, 700);

        $this->assertStringContainsString("'app_id'         => \$leadId !== '' ? \$leadId : \$userId,", $sisBody,
            'the SIS admission path no longer passes a guaranteed-non-empty app_id, so its '
            . 'double-collect guard would depend on a value that can be blank');

        $pub = (string) file_get_contents(APPPATH . 'controllers/Admission_public.php');
        $at  = strpos($pub, '$rc = $this->admissionCol->record_payment([');
        $this->assertNotFalse($at);
        $body = substr($pub, $at, 900);

        $this->assertStringContainsString("'app_id'             => \$appId,", $body);
        $this->assertStringContainsString("'payment_id'         => \$paymentId,", $body,
            'the public path no longer passes payment_id. app_id there is read with `?? \'\'` '
            . 'from a payment document, so payment_id is the fallback that keeps callback and '
            . 'webhook keyed to the same claim.');
    }

    // ══ THE CLAIM OUTCOMES MUST STAY DISTINCT ═══════════════════════════════

    /**
     * Section 15: SUCCESS / CONFLICT / UNKNOWN / STALE must never collapse into
     * one result. This path distinguishes all four, and each does something
     * different — dedup returns the ORIGINAL receipt, in-progress skips,
     * an error fails closed.
     */
    public function test_every_claim_outcome_is_handled_separately(): void
    {
        $code = $this->code(self::$col);

        $this->assertStringContainsString("if (!empty(\$claim['dedup'])) {", $code,
            'a deduped retry no longer returns the original receipt, so a caller would think '
            . 'no receipt exists and could mint a second');
        $this->assertStringContainsString("if (!empty(\$claim['in_progress'])) {", $code,
            'an in-progress claim is no longer distinguished from a free slot');
        $this->assertStringContainsString("if (isset(\$claim['error'])) {", $code,
            'a claim error is no longer handled, so an unknown outcome would proceed to mint');
    }

    /** An unknown claim outcome must fail closed, never proceed. */
    public function test_a_claim_error_fails_closed(): void
    {
        $at = strpos(self::$col, "if (isset(\$claim['error'])) {");
        $this->assertNotFalse($at);
        $body = substr(self::$col, $at, 400);

        $this->assertStringContainsString('return null;', $body,
            'a failed idempotency claim no longer refuses. Proceeding without the gate risks '
            . 'a double mint, which is the one outcome that cannot be undone automatically.');
    }

    // ══ THE RECEIPT SERIES ══════════════════════════════════════════════════

    /**
     * A dedicated ADM series is deliberate. Pinned because "unify the receipt
     * numbers" looks like a tidy-up and would collide admission receipts with
     * fee receipts — two documents sharing one number.
     */
    public function test_the_admission_series_stays_separate_from_the_fee_series(): void
    {
        $code = $this->code(self::$col);

        $this->assertStringContainsString('next_receipt_no()', $code,
            'the admission receipt allocator was removed');
        $this->assertStringNotContainsString("nextCounter('receipt_seq')", $code,
            'the admission path now draws from the FEE receipt series. Those are separate '
            . 'document types with separate series by design — sharing one counter would give '
            . 'an admission receipt and a fee receipt the same number.');
    }

    /**
     * The allocator must THROW rather than invent a number, matching RCPT-1's
     * rule for the fee series: a receipt number is an allocation, never a guess.
     */
    public function test_the_allocator_throws_rather_than_inventing_a_number(): void
    {
        $this->assertStringContainsString('throws on allocator failure', self::$col,
            'the admission receipt allocator no longer documents failing loudly — confirm it '
            . 'still refuses rather than minting an unallocated number');
    }
}
