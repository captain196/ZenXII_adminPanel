<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Operations_accounting')) {
    require_once APPPATH . 'libraries/Operations_accounting.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  REFUNDS — REF-1 CONCURRENCY AND PM-2 LEDGER CLASSIFICATION
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Re-derived after this session's changes to the shared write boundary
 * (PROV-1, ROLL-2) and the payment-mode consolidation (PM-1), because both
 * touch code the refund path runs through.
 *
 * ── REF-1 · THREE OUTCOMES, NOT TWO ───────────────────────────────────────
 * `acquireLock()` returned `''` for a live lock, for a thrown exception AND for
 * an uninitialised helper. The caller deleted its idempotency guard on all
 * three — and that guard is the only thing preventing a SECOND DISBURSEMENT.
 *
 *   CONFLICT → the lock write definitely did NOT land. Nothing of ours is in
 *              flight, so releasing the guard is correct and lets the admin
 *              retry cleanly.
 *   UNKNOWN  → the write MAY have landed. The guard MUST survive: deleting it
 *              would let a retry disburse a refund already being processed by a
 *              request we lost track of.
 *
 * A stuck slot is recoverable; a duplicate disbursement is not.
 *
 * ── PM-2 · THE FOURTH COPY OF THE PAYMENT-MODE LIST ───────────────────────
 * The refund journals carried their own copy —
 * `['bank_transfer','cheque','online','upi','neft']` — already **diverged**
 * from the canonical set: `bank` and `rtgs` were missing. A refund in either
 * mode credited CASH while a PAYMENT in the same mode debits the BANK. Money in
 * through the bank and out through the cash box: the cash count goes negative
 * and neither side reconciles.
 *
 * Latent rather than live — the documented refund vocabulary
 * (`cash|bank_transfer|cheque|online`) is covered either way. Fixed because it
 * is the same shape PM-1 found in Library, and PM-1 consolidated three copies
 * precisely because one had drifted. This was the fourth.
 */
class RefundConcurrencyAndLedgerTest extends TestCase
{
    private static string $svc = '';
    private static string $acct = '';

    public static function setUpBeforeClass(): void
    {
        self::$svc  = (string) file_get_contents(APPPATH . 'libraries/Fee_refund_service.php');
        self::$acct = (string) file_get_contents(APPPATH . 'libraries/Operations_accounting.php');
    }

    // ══ REF-1 ═══════════════════════════════════════════════════════════════

    /** The lock must report a STATUS, not an empty string that means three things. */
    public function test_the_lock_reports_a_distinguishable_status(): void
    {
        $this->assertStringContainsString('$lockRes   = $this->fsTxn->acquireLockStatus($studentId, 120);', self::$svc,
            'the refund path went back to the boolean/empty-string lock, which cannot tell a '
            . 'live lock from a thrown exception from an uninitialised helper');
    }

    /**
     * WOULD HAVE FAILED BEFORE: the guard was deleted on every non-owner
     * outcome.
     */
    public function test_only_a_definite_conflict_releases_the_idempotency_guard(): void
    {
        $at = strpos(self::$svc, "if (\$lockRes['status'] !== Fee_firestore_txn::LOCK_OWNER) {");
        $this->assertNotFalse($at, 'the non-owner branch was restructured');
        $body = substr(self::$svc, $at, 1200);

        $this->assertStringContainsString('=== Fee_firestore_txn::LOCK_CONFLICT', $body,
            'the CONFLICT case is no longer distinguished, so an UNKNOWN outcome would take '
            . 'the same branch');

        $conflictAt = strpos($body, 'LOCK_CONFLICT');
        $deleteAt   = strpos($body, 'deleteIdempotency');
        $this->assertNotFalse($deleteAt, 'the guard is never released, so a genuine conflict '
            . 'would leave the admin unable to retry');
        $this->assertGreaterThan($conflictAt, $deleteAt,
            'the idempotency guard is deleted before the outcome is known to be a CONFLICT. '
            . 'On an UNKNOWN outcome that permits a SECOND DISBURSEMENT of the same refund.');
    }

    /** UNKNOWN must refuse, retain the guard, and say nothing was disbursed. */
    public function test_an_unknown_outcome_retains_the_guard_and_refuses(): void
    {
        $at   = strpos(self::$svc, "if (\$lockRes['status'] !== Fee_firestore_txn::LOCK_OWNER) {");
        $body = substr(self::$svc, $at, 1200);

        $this->assertStringContainsString('RETAINING the idempotency guard', $body,
            'the UNKNOWN branch no longer records that it kept the guard');
        $this->assertStringContainsString('Nothing has been disbursed.', $body,
            'the refusal no longer tells the admin nothing was paid out. Without that they '
            . 'may assume a partial disbursement and try to correct state that is fine.');

        // And it must be logged at a level that survives the production threshold.
        $logAt = strpos($body, 'RETAINING the idempotency guard');
        $before = substr($body, max(0, $logAt - 200), min(200, $logAt));
        $this->assertStringContainsString("log_message('error'", $before,
            'the UNKNOWN outcome is logged below the production threshold, so the one event '
            . 'that leaves a lock slot stuck would be invisible');
    }

    /**
     * Stale-lock recovery is a FOURTH state and must stay separate from a fresh
     * acquisition — section 15's requirement that SUCCESS / CONFLICT / UNKNOWN /
     * STALE never collapse.
     */
    public function test_a_recovered_stale_lock_is_distinguished_from_a_fresh_one(): void
    {
        $this->assertStringContainsString("if (!empty(\$lockRes['recovered'])) {", self::$svc,
            'taking over an expired lock is no longer distinguished from acquiring a free '
            . 'one, so a refund that died mid-flight leaves no trace');
    }

    /**
     * Recorded, not changed: that recovery is logged at 'info', which the
     * production threshold (1 = errors only) discards.
     *
     * Deliberately left alone. Unlike `log_message('warning')` — which CI3
     * discards ALWAYS, at any threshold, because no such level exists — 'info'
     * is a real level that appears the moment an operator raises the threshold.
     * A by-design, recoverable event does not belong at 'error', where it would
     * dilute the entries that do.
     */
    public function test_the_stale_recovery_is_info_not_a_discarded_level(): void
    {
        $at = strpos(self::$svc, 'recovered an expired student lock');
        $this->assertNotFalse($at);
        $before = substr(self::$svc, max(0, $at - 200), min(200, $at));

        $this->assertStringContainsString("log_message('info'", $before,
            'the stale-recovery level changed. If it moved to a level CodeIgniter does not '
            . 'define it writes nothing at all — check it is not now \'warning\'.');
    }

    // ══ PM-2 ════════════════════════════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: two inline copies, both missing bank and rtgs. */
    public function test_the_refund_ledger_uses_the_shared_payment_mode_list(): void
    {
        // PM-3 (2026-08-26): PM-2 shared the mode CONSTANT but each refund
        // journal still carried its own copy of the classification, as did the
        // fee posting — three copies of one rule. They are consolidated into
        // resolveCashBankCode(), so the anchor moves from the in_array test to
        // the call. Same invariant, one fewer place for it to drift.
        $this->assertSame(2, substr_count(self::$acct, "self::resolveCashBankCode(\$coa, '', \$refundMode)"),
            'a refund journal still classifies the payment mode itself. Both refund journals — '
            . 'the granular per-head one and the single-line fallback — must resolve through '
            . 'the one shared classifier.');

        $this->assertStringNotContainsString("['bank_transfer', 'cheque', 'online', 'upi', 'neft']", self::$acct,
            'the diverged inline refund list is back');

        // The consequence PM-2 actually cared about: `bank` and `rtgs` were
        // the modes missing from the diverged copy, so a refund in either
        // credited CASH while the matching payment debited a bank. Asserted
        // behaviourally, since the classifier is now callable.
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = [
            '1010' => ['name' => 'Cash in Hand', 'is_bank' => false, 'status' => 'active'],
            '1020' => ['name' => 'SBI Bank A/c', 'is_bank' => true,  'status' => 'active'],
        ];
        foreach (['bank', 'rtgs', 'bank_transfer', 'cheque', 'online', 'upi', 'neft'] as $mode) {
            $this->assertSame('1020', Operations_accounting::resolveCashBankCode($coa, '', $mode),
                "a '{$mode}' refund credits Cash in Hand while the matching payment debits the "
                . 'bank — money out through the cash box that never went in through it');
        }
    }

    /**
     * The concrete consequence: a bank refund must credit a bank ledger, the
     * same side the matching payment debited.
     */
    public function test_a_bank_refund_is_not_paid_out_of_the_cash_box(): void
    {
        foreach (['bank', 'rtgs', 'bank_transfer', 'cheque', 'online', 'upi', 'neft'] as $mode) {
            $this->assertContains($mode, Operations_accounting::NON_CASH_MODES,
                "refund mode '{$mode}' is not treated as non-cash, so the refund credits Cash "
                . 'while the original payment debited the bank — the cash box goes negative by '
                . 'the refund amount and neither ledger reconciles.');
        }
    }

    /** Cash refunds must still come out of cash. */
    public function test_a_cash_refund_still_credits_cash(): void
    {
        $this->assertNotContains('cash', Operations_accounting::NON_CASH_MODES,
            'a cash refund would be credited to a bank account, so the bank statement gains a '
            . 'withdrawal that never happened');
    }

    // ══ SEMANTIC REVERSAL, NOT JUST A BALANCED ONE ══════════════════════════

    /**
     * A refund must reverse the INCOME accounts that were credited, per head —
     * not net a lump sum against one account. Dr = Cr holds either way, which
     * is exactly why this needs asserting separately.
     */
    public function test_the_reversal_is_per_head_not_a_lump_sum(): void
    {
        $at = strpos(self::$acct, 'public function create_refund_journal_granular');
        $this->assertNotFalse($at, 'the granular refund journal was removed');

        $head = substr(self::$acct, max(0, $at - 900), 900);
        $this->assertStringContainsString('Dr 4010 Tuition Fee', $head,
            'the granular reversal no longer documents reversing each income credit. A lump-sum '
            . 'reversal balances but leaves per-head revenue overstated for every head except '
            . 'the one it was netted against.');
    }

    /**
     * The granular path needs allocations; without them it must fall back to
     * the single-line journal rather than post nothing.
     */
    public function test_a_refund_without_allocations_still_posts_a_journal(): void
    {
        $at   = strpos(self::$acct, 'public function create_refund_journal_granular');
        $body = substr(self::$acct, $at, 600);

        $this->assertStringContainsString('if (empty($allocations)) {', $body,
            'a refund with no recoverable allocations no longer falls back, so the money '
            . 'leaves the school with no ledger entry at all');
        $this->assertStringContainsString('return $this->create_refund_journal($params);', $body);
    }

    /**
     * A failed journal must be reported and retryable — money has already left,
     * so silence would mean an unrecorded disbursement.
     */
    public function test_a_failed_reversal_journal_is_reported_and_retryable(): void
    {
        $this->assertStringContainsString('public function retryJournal(string $refId)', self::$svc,
            'the journal retry path is gone. A refund whose journal failed would stay '
            . 'permanently unrecorded with no operator remedy.');
        $this->assertStringContainsString("'error' => 'Journal post returned null", self::$svc,
            'a null journal result is no longer surfaced to the caller');
    }
}
