<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Operations_accounting')) {
    require_once APPPATH . 'libraries/Operations_accounting.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  LIBRARY FINE PAYMENT — LIB-1 (DOUBLE JOURNAL) AND PM-1 (CASH vs BANK)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * A library fine is the one student money path that does **not** produce a
 * `feeDemand`. `Library::pay_fine()` records the money directly: a journal
 * (Dr Cash/Bank, Cr 4060 Late Fees) and a status flip on the fine document.
 *
 * That is not a second payment engine — there is no gateway, no lock, no
 * receipt, no webhook. It is an in-person cash acknowledgement. Which is
 * exactly why it had none of the protections the fee path spent this whole
 * programme acquiring.
 *
 * ── LIB-1 · TWO CLICKS, TWO JOURNALS ──────────────────────────────────────
 * The guard was `if ($status === 'paid') json_error(...)` — a plain read,
 * followed by an unconditional journal write. Two concurrent requests (a
 * double-clicked button, a retried POST, two clerks on one fine) both read
 * 'unpaid', both pass, and BOTH journal. The fine is paid once; Cr 4060 is
 * credited twice. Revenue recognised for money never received, in the one
 * record whose entire purpose is that this cannot happen.
 *
 * Same shape as WAIVE-RACE-1, closed the same way: claim under a
 * `currentDocument.updateTime` precondition, and only the winner journals.
 *
 * ORDERING. Claiming before journalling can leave a fine marked paid whose
 * journal failed — under-recognised revenue, loud (no `journal_id`) and
 * repairable. Journalling first would leave a duplicate journal on a lost
 * race — over-recognised, silent, far harder to unpick. Between an error you
 * can find and one you cannot, take the one you can find.
 *
 * ── PM-1 · THE INVERTED POLARITY ──────────────────────────────────────────
 * `($paymentMode === 'Bank') ? '1020' : '1010'` matched one literal, so UPI,
 * cheque and card all debited CASH. Three classifications had grown up
 * independently:
 *
 *     fees     an allowlist of non-cash modes → real bank account from the CoA
 *     payroll  `$mode === 'Cash' ? '1010' : '1020'`   — cash is the exception
 *     library  `$mode === 'Bank' ? '1020' : '1010'`   — INVERTED
 *
 * The journal balances either way, so nothing detected it. The only symptom is
 * a cash box that can never be reconciled against a physical count.
 */
class LibraryFinePaymentIntegrityTest extends TestCase
{
    private static string $lib = '';

    public static function setUpBeforeClass(): void
    {
        self::$lib = (string) file_get_contents(APPPATH . 'controllers/Library.php');
    }

    /** `pay_fine()` bounded at the next method, never by a fixed window. */
    private function payFine(): string
    {
        $at = strpos(self::$lib, 'public function pay_fine');
        $this->assertNotFalse($at, 'pay_fine was renamed');
        $end = strpos(self::$lib, "\n    public function ", $at + 30);
        return substr(self::$lib, $at, ($end !== false ? $end - $at : 9000));
    }

    // ══ PM-1 — BEHAVIOURAL, AGAINST THE REAL HELPER ═════════════════════════

    /** Cash is cash. The one mode that must NOT resolve to a bank account. */
    public function test_cash_is_the_only_mode_treated_as_cash(): void
    {
        foreach (['Cash', 'cash', ' CASH '] as $mode) {
            $this->assertFalse(Operations_accounting::is_non_cash_mode($mode),
                "'{$mode}' was treated as non-cash — physical notes would be debited to a bank "
                . 'account and the cash box would never reconcile');
        }
    }

    /**
     * WOULD HAVE FAILED BEFORE for every one of these: only the literal 'Bank'
     * escaped the Cash branch.
     */
    public function test_every_electronic_mode_debits_a_bank_account(): void
    {
        foreach (['Bank', 'UPI', 'upi', 'Cheque', 'NEFT', 'RTGS', 'Online', 'Card', 'DD'] as $mode) {
            $this->assertTrue(Operations_accounting::is_non_cash_mode($mode),
                "'{$mode}' resolved to CASH. Money that arrived electronically would inflate a "
                . 'physical cash count that nobody can reconcile — the journal balances, which '
                . 'is exactly why this survives undetected.');
        }
    }

    /**
     * An unrecognised mode must be treated as NON-cash. The two failure
     * directions are not symmetric: money wrongly in Bank shows up as one
     * unmatched statement line; money wrongly in Cash inflates a count where a
     * shortfall is unrecoverable.
     */
    public function test_an_unknown_mode_fails_towards_bank_not_cash(): void
    {
        foreach (['Paytm', 'IMPS', 'whatever'] as $mode) {
            $this->assertTrue(Operations_accounting::is_non_cash_mode($mode),
                "an unrecognised mode '{$mode}' was booked to Cash. A school adding a payment "
                . 'method must not silently start overstating its cash box.');
        }
    }

    /** An empty mode is not a claim that cash was received. */
    public function test_an_empty_mode_is_not_treated_as_cash(): void
    {
        $this->assertFalse(Operations_accounting::is_non_cash_mode(''),
            'an empty mode should fall back to the caller default, not assert a bank transfer');
    }

    /** The shared list must still exist — the fee path resolves against it. */
    public function test_the_mode_list_is_shared_with_the_fee_path(): void
    {
        $this->assertContains('upi', Operations_accounting::NON_CASH_MODES);
        $this->assertContains('cheque', Operations_accounting::NON_CASH_MODES);
        $this->assertNotContains('cash', Operations_accounting::NON_CASH_MODES);

        // PM-3 (2026-08-26): the classification moved into the shared
        // resolveCashBankCode(). PM-1 shared the CONSTANT but left the scan
        // duplicated three ways (fee posting + two refund journals), which is
        // the same drift risk one level down. Anchoring on the old inline
        // expression would now fail for a reason unrelated to the invariant.
        //
        // Asserted behaviourally instead, because the classifier is finally a
        // callable pure function — this proves the shared list is genuinely
        // consulted rather than merely present in the file.
        $coa = [
            '1010' => ['name' => 'Cash in Hand', 'is_bank' => false, 'status' => 'active'],
            '1020' => ['name' => 'SBI Bank A/c', 'is_bank' => true,  'status' => 'active'],
        ];
        foreach (['upi', 'cheque'] as $mode) {
            $this->assertSame('1020', Operations_accounting::resolveCashBankCode($coa, '', $mode),
                "'{$mode}' is in NON_CASH_MODES but does not reach a bank ledger — the shared "
                . 'list and the classifier have drifted apart');
        }
        $this->assertSame('1010', Operations_accounting::resolveCashBankCode($coa, '', 'cash'),
            'cash must still reach Cash in Hand, or the classifier is not discriminating at all');
    }

    /** Library must use the helper rather than its own comparison. */
    public function test_library_resolves_the_account_through_the_shared_helper(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString(
            "Operations_accounting::is_non_cash_mode(\$paymentMode) ? '1020' : '1010'", $body,
            'the library fine still classifies the payment mode itself. It was the outlier — '
            . 'the only site where UPI debited Cash.');
        // Executable lines only. The comment explaining this fix QUOTES the old
        // expression, so a raw scan matches the note describing the fix rather
        // than a regression — the same trap that has produced weak assertions
        // twice in this programme.
        $code = (string) preg_replace('!^\s*//.*$!m', '', $body);

        $this->assertStringNotContainsString("(\$paymentMode === 'Bank') ? '1020' : '1010'", $code,
            'the inverted comparison is back: every mode except the literal "Bank" would debit '
            . 'Cash again');
    }

    // ══ LIB-1 ═══════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the status check was the only guard, and it is
     * a read.
     */
    public function test_the_fine_is_claimed_under_a_precondition_before_any_journal(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString("'precondition' => ['updateTime' => \$fineUpdateTime]", $body,
            'the fine is settled without a CAS precondition, so two concurrent requests both '
            . 'pass the paid check and both create a journal — Cr 4060 twice for one payment');
        $this->assertStringContainsString('firestoreCommitBatchStatus', $body);
    }

    /**
     * The ordering is the whole design. The claim must come BEFORE
     * `create_journal`, or a lost race leaves a duplicate journal instead of a
     * refused payment.
     */
    public function test_the_claim_happens_before_the_journal_is_created(): void
    {
        $body = $this->payFine();

        $claimAt   = strpos($body, 'firestoreCommitBatchStatus');
        $journalAt = strpos($body, '$this->operations_accounting->create_journal(');

        $this->assertNotFalse($claimAt, 'the claim is gone');
        $this->assertNotFalse($journalAt, 'the journal creation is gone');
        $this->assertLessThan($journalAt, $claimAt,
            'the journal is created BEFORE the fine is claimed. A request that then loses the '
            . 'claim has already written a journal, so the duplicate this fix exists to prevent '
            . 'is created anyway — silently, and on the over-recognised side.');
    }

    /** A lost race must refuse, and must say nothing was double-charged. */
    public function test_a_lost_race_refuses_and_creates_no_journal(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString("if (\$claimStatus === 'PRECONDITION_CONFLICT')", $body,
            'a precondition conflict is no longer distinguished, so a lost race would fall '
            . 'through to the generic path');
        $this->assertStringContainsString('LIB_FINE_PAY_RACE', $body);

        // The refusal must be before create_journal.
        $conflictAt = strpos($body, "PRECONDITION_CONFLICT");
        $journalAt  = strpos($body, '$this->operations_accounting->create_journal(');
        $this->assertLessThan($journalAt, $conflictAt,
            'the conflict branch sits after the journal was already written');
    }

    /**
     * No updateTime means no precondition is possible, so the duplicate cannot
     * be prevented. Refuse rather than write blind — silently restoring the
     * race is worse than a payment the clerk retries.
     */
    public function test_a_fine_with_no_update_time_is_refused(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString("if (\$fineUpdateTime === '')", $body,
            'a fine with no __updateTime is settled without any concurrency protection');
        $this->assertStringContainsString('LIB_FINE_PAY_NO_CAS', $body);
    }

    /**
     * A journal that fails after the claim leaves money received and unrecorded.
     * That is the direction this ordering deliberately accepts, so it must be
     * LOUD and it must not be reported to the clerk as a clean success.
     */
    public function test_a_failed_journal_after_a_won_claim_is_reported_not_swallowed(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString('LIB_FINE_PAY_JOURNAL_MISSING', $body,
            'a journal failure after the fine was claimed is silent. The money is received and '
            . 'absent from the ledger, with nothing to grep for.');
        $this->assertStringContainsString('could not be created. Please inform accounts.', $body,
            'the clerk is told the payment succeeded outright, so nobody learns the accounting '
            . 'entry is missing');
    }

    // ══ WHAT DID NOT CHANGE ═════════════════════════════════════════════════

    /** The revenue account for a fine is still 4060, not tuition. */
    public function test_a_fine_still_credits_the_penalty_account(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString("['account_code' => '4060',    'dr' => 0,       'cr' => \$amount]", $body,
            'a library fine no longer credits 4060 Late Fees. A penalty is not fee income and '
            . 'must not be booked beside tuition.');
    }

    /** The cheap idempotent case must still short-circuit before any work. */
    public function test_an_already_paid_fine_is_still_refused_up_front(): void
    {
        $body = $this->payFine();

        $this->assertStringContainsString("if (\$status === 'paid') \$this->json_error('Fine already paid.');", $body,
            'the plain already-paid check was removed. The CAS claim covers the race, but this '
            . 'check is what avoids doing any work in the ordinary repeat case.');
    }

    /**
     * The recorded gap, deliberately not closed here: a library fine payment
     * issues NO RECEIPT. Every other rupee ZenX collects gets a sequential
     * number from the receipt allocator; cash collected against no sequential
     * record is the classic control gap, and it is the strongest argument in
     * the convergence design.
     *
     * Not fixed in this pass because minting a receipt for a non-demand
     * obligation would either duplicate the allocator or invent a second
     * numbering series — both of which the convergence decision exists to
     * prevent. Recorded as a test so the gap stays visible.
     */
    public function test_library_still_issues_no_receipt_recorded_as_a_known_gap(): void
    {
        $this->assertStringNotContainsString('nextCounter(\'receipt_seq\')', self::$lib,
            'Library now mints receipt numbers of its own. If library fines are to be '
            . 'receipted, they must go through the canonical allocator via a feeDemand — a '
            . 'second numbering series is exactly what the convergence design refuses.');
    }
}
