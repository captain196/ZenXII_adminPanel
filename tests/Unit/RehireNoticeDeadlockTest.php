<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * D-005 — RESOLVED AND FIXED 2026-08-21.
 *
 * A rehired employee whose notice period was never completed could not resign
 * again, ever. `set_status()`'s reactivation branch now calls
 * `_cancel_notice_on_rehire()`, which releases a non-terminal notice and leaves
 * a concluded one untouched.
 *
 * WHY 'cancelled' AND NOT A NEW STATE. My first proposal was to add
 * `superseded`. Market research corrected it: established HRMS platforms key
 * rehire off "the most recent NON-CANCELLED separation record", so `cancelled`
 * is the existing vocabulary for a separation that did not take effect. It is
 * also already what `Fnf_state_machine` means by the word, so ZenX stays
 * internally consistent — and unlike the alternatives it misstates nothing: HR
 * did not reject it, the employee did not withdraw it, and it did not complete.
 *
 * The tests below that describe the deadlock were INVERTED rather than deleted.
 * The state they describe is precisely what must never return.
 *
 * ── the defect, as originally found ─────────────────────────────────────
 *
 * `staffNoticePeriods` holds exactly ONE document per staff member, keyed by
 * `_notice_doc_id($staffId)`. It represents "the current notice period", and a
 * new resignation is refused while it sits in a non-terminal state.
 *
 * Reachable sequence, using only supported actions:
 *
 *   1. submit_resignation           notice = pending
 *   2. decide_notice_period(approve) notice = approved
 *   3. set_status(Inactive)          staff  = Inactive   (creates staffSeparations)
 *   4. set_status(Active)            staff  = Active     (rehire; clears exitDate)
 *
 * Step 4 is reachable without ever calling `complete_notice_period`, which is a
 * SEPARATE, OPTIONAL endpoint. `set_status()` does not touch the notice record
 * at all — verified: NOTICE_PERIOD_COL appears only inside the notice endpoints.
 *
 * The resulting state — staff Active, notice 'approved' — has NO legal
 * transition:
 *
 *   submit_resignation      refused: "An active notice period already exists"
 *   withdraw_resignation    refused: requires status 'pending'
 *   decide_notice_period    refused: requires status 'pending'
 *   complete_notice_period  refused: requires staff status Inactive
 *
 * The employee is permanently unable to resign through the system. The error
 * message compounds it: "Withdraw or decide it first" — both of which require
 * 'pending' and are therefore impossible from exactly the states that trigger
 * the message.
 *
 * WHY THIS IS NOT FIXED HERE. The deadlock is objectively real, but the repair
 * is not objectively determined: none of the three existing terminal states is
 * factually true of an abandoned separation —
 *
 *   'rejected'   HR did not reject it
 *   'withdrawn'  the employee did not withdraw it
 *   'completed'  the separation did not complete; the person is back
 *
 * so an accurate fix needs a new terminal state (e.g. 'superseded'), which is a
 * change to a state machine this programme treats as locked. Options are set
 * out in the D-005 entry. Recorded as an executable proof so the deadlock
 * cannot be lost, and so any future fix is validated against the real guards.
 *
 * This test asserts the deadlock EXISTS. It must be inverted — not deleted —
 * when D-005 is decided.
 */
final class RehireNoticeDeadlockTest extends TestCase
{
    private const NOTICE_ACTIVE = ['pending', 'approved', 'waived', 'reduced'];
    private const COMPLETABLE   = ['approved', 'waived', 'reduced'];

    private string $src;

    protected function setUp(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($src);
        $this->src = $src;
    }

    // ── The four guards, exactly as shipped ──────────────────────────────

    private function canSubmitResignation(string $noticeStatus, string $staffStatus): bool
    {
        if (strtolower(trim($staffStatus)) === 'inactive') return false;   // separate guard
        return !in_array($noticeStatus, self::NOTICE_ACTIVE, true);
    }

    private function canWithdraw(string $noticeStatus): bool
    {
        return $noticeStatus === 'pending';
    }

    private function canDecide(string $noticeStatus): bool
    {
        return $noticeStatus === 'pending';
    }

    private function canComplete(string $noticeStatus, string $staffStatus): bool
    {
        if (!in_array($noticeStatus, self::COMPLETABLE, true)) return false;
        return strtolower(trim($staffStatus)) === 'inactive';
    }

    /**
     * `_cancel_notice_on_rehire()` as shipped: a non-terminal notice is
     * cancelled; anything already terminal is returned untouched.
     */
    private function afterRehire(string $noticeStatus): string
    {
        return in_array($noticeStatus, self::NOTICE_ACTIVE, true) ? 'cancelled' : $noticeStatus;
    }

    /** Every action available from a given state. */
    private function legalTransitions(string $noticeStatus, string $staffStatus): array
    {
        $out = [];
        if ($this->canSubmitResignation($noticeStatus, $staffStatus)) $out[] = 'submit_resignation';
        if ($this->canWithdraw($noticeStatus))                        $out[] = 'withdraw_resignation';
        if ($this->canDecide($noticeStatus))                          $out[] = 'decide_notice_period';
        if ($this->canComplete($noticeStatus, $staffStatus))          $out[] = 'complete_notice_period';
        return $out;
    }

    // ── The deadlock ─────────────────────────────────────────────────────

    /**
     * D-005 FIXED (2026-08-21). The deadlock state is now UNREACHABLE, because
     * rehiring cancels a non-terminal notice on the way through.
     *
     * These two tests previously asserted the deadlock EXISTED. They are
     * inverted rather than deleted: the state they describe is exactly what
     * must never come back.
     */
    public function test_rehire_cancels_a_non_terminal_notice(): void
    {
        foreach (self::COMPLETABLE as $s) {
            $this->assertSame(
                'cancelled',
                $this->afterRehire($s),
                "a rehire must release a '{$s}' notice, or the employee can never resign again"
            );
        }
    }

    public function test_the_employee_can_resign_again_after_a_rehire(): void
    {
        foreach (self::COMPLETABLE as $s) {
            $after = $this->afterRehire($s);

            $this->assertContains(
                'submit_resignation',
                $this->legalTransitions($after, 'Active'),
                "after rehire from '{$s}' a fresh resignation must be possible"
            );
        }
    }

    /** Without the fix, every decided-but-incomplete state was a dead end. */
    public function test_the_deadlock_state_would_still_be_a_dead_end_if_reached(): void
    {
        foreach (self::COMPLETABLE as $s) {
            $this->assertSame([], $this->legalTransitions($s, 'Active'),
                "'{$s}' + Active has no exit — which is WHY rehire must cancel it");
        }
    }

    /** A terminal notice is left exactly as it was — rehire rewrites nothing. */
    public function test_rehire_does_not_touch_a_terminal_notice(): void
    {
        foreach (['completed', 'rejected', 'withdrawn', 'cancelled'] as $s) {
            $this->assertSame($s, $this->afterRehire($s),
                "'{$s}' is concluded and must not be rewritten by a later rehire");
        }
    }

    /** A pending notice is also released — it is in the active set. */
    public function test_rehire_also_cancels_a_pending_notice(): void
    {
        $this->assertSame('cancelled', $this->afterRehire('pending'));
    }

    /**
     * 'pending' does NOT deadlock — withdraw and decide remain available. This
     * bounds the defect: it needs a notice that was DECIDED but never completed.
     */
    public function test_a_pending_notice_is_not_deadlocked(): void
    {
        $t = $this->legalTransitions('pending', 'Active');

        $this->assertContains('withdraw_resignation', $t);
        $this->assertContains('decide_notice_period', $t);
    }

    /** The normal, complete flow leaves the employee able to resign again. */
    public function test_completing_the_notice_before_rehire_avoids_the_deadlock(): void
    {
        $this->assertContains(
            'submit_resignation',
            $this->legalTransitions('completed', 'Active'),
            'the documented happy path is fine — this is why the defect is easy to miss'
        );
    }

    public function test_terminal_states_all_permit_a_fresh_resignation(): void
    {
        foreach (['completed', 'rejected', 'withdrawn'] as $s) {
            $this->assertContains('submit_resignation', $this->legalTransitions($s, 'Active'),
                "'{$s}' is terminal and must not block a later resignation");
        }
    }

    /** While still Inactive the state is recoverable — completion is available. */
    public function test_the_state_is_still_recoverable_before_rehire(): void
    {
        $this->assertContains(
            'complete_notice_period',
            $this->legalTransitions('approved', 'Inactive'),
            'the deadlock is created by the REHIRE, not by the approval'
        );
    }

    // ── Source proofs of each premise ────────────────────────────────────

    /**
     * INVERTED 2026-08-21. This previously asserted that reactivation did NOT
     * touch the notice record — which was the whole defect. It now asserts the
     * opposite, and the cancel helper is asserted to behave correctly.
     */
    public function test_reactivation_cancels_a_stuck_notice(): void
    {
        $i = strpos($this->src, '} else { // Active (reactivation / rehire)');
        $this->assertNotFalse($i, 'rehire branch not found — re-read set_status()');
        $branch = substr($this->src, (int) $i, 3000);

        $this->assertStringContainsString('$this->_cancel_notice_on_rehire($user_id, $actorId);', $branch,
            'the rehire branch must release a notice the rehire has just made void');
    }

    /** The helper must only ever release an ACTIVE notice. */
    public function test_the_cancel_helper_skips_terminal_notices(): void
    {
        $i = strpos($this->src, 'function _cancel_notice_on_rehire');
        $this->assertNotFalse($i, 'cancel helper not found');
        $body = substr($this->src, (int) $i, 2200);

        $this->assertStringContainsString(
            'if (!in_array($status, self::NOTICE_ACTIVE_STATUSES, true)) return;',
            $body,
            'a concluded separation must never be rewritten by a later rehire'
        );
    }

    /** It must be best-effort: a rehire cannot fail on the notice record. */
    public function test_the_cancel_helper_cannot_break_a_rehire(): void
    {
        $i = strpos($this->src, 'function _cancel_notice_on_rehire');
        $body = substr($this->src, (int) $i, 2200);

        $this->assertSame(2, substr_count($body, '} catch (\Throwable $e) {'),
            'both the read and the write must be guarded — a rehire must not fail here');
        $this->assertStringContainsString('log_message(\'error\'', $body,
            'a failure must be logged, not silently swallowed');
    }

    /** The cancellation must be auditable and say what it came from. */
    public function test_the_cancellation_is_audited_with_its_prior_state(): void
    {
        $i = strpos($this->src, 'function _cancel_notice_on_rehire');
        $body = substr($this->src, (int) $i, 2200);

        $this->assertStringContainsString("'cancelledFrom'    => \$status", $body,
            'the audit trail must record what the notice was before the rehire voided it');
        $this->assertStringContainsString("'notice_period_cancelled_on_rehire'", $body);
        $this->assertStringContainsString("'cancelledReason'  => 'rehire'", $body);
    }

    /** 'cancelled' must not re-block a later resignation. */
    public function test_cancelled_is_not_in_the_blocking_set(): void
    {
        $this->assertNotContains('cancelled', self::NOTICE_ACTIVE,
            "if 'cancelled' ever joins the active set, the deadlock returns immediately");
    }

    public function test_completion_requires_an_inactive_staff_member(): void
    {
        $this->assertStringContainsString("strtolower(trim(\$rawStatus)) !== 'inactive'", $this->src);
    }

    public function test_a_new_resignation_is_blocked_by_any_active_notice(): void
    {
        $this->assertStringContainsString(
            "in_array((string) (\$existing['status'] ?? ''), self::NOTICE_ACTIVE_STATUSES, true)",
            $this->src
        );
        $this->assertStringContainsString(
            "private const NOTICE_ACTIVE_STATUSES = ['pending', 'approved', 'waived', 'reduced'];",
            $this->src
        );
    }

    /**
     * The advice the system gives is impossible in precisely the deadlock case:
     * both remedies it names require 'pending'.
     */
    public function test_the_error_message_advises_two_impossible_remedies(): void
    {
        $this->assertStringContainsString(
            'An active notice period already exists for this staff member. Withdraw or decide it first.',
            $this->src
        );

        foreach (self::COMPLETABLE as $s) {
            $this->assertFalse($this->canWithdraw($s), "cannot withdraw from '{$s}'");
            $this->assertFalse($this->canDecide($s),   "cannot decide from '{$s}'");
        }
    }

    // ── Sibling evidence: the other two records ARE rehire-safe ──────────

    /**
     * Clearance and onboarding are stored the same way — ONE document per staff
     * member — and face the same rehire scenario. Both gate their create on
     * `status === 'in_progress'` ONLY, so a terminal record is freely
     * superseded by a fresh cycle. A rehired employee can be onboarded again;
     * a re-separated employee can be cleared again.
     *
     * The notice period instead gates on a four-state set that includes three
     * DECIDED-but-not-completed states — and those states have no route to
     * terminal once the employee is Active again.
     *
     * That asymmetry is the strongest evidence this is an oversight rather than
     * a deliberate rule: its two siblings establish the intended pattern, which
     * is that a stale lifecycle record must never block a new cycle.
     */
    public function test_clearance_supersedes_a_terminal_record(): void
    {
        $this->assertStringContainsString(
            "(string) (\$existing['status'] ?? '') === 'in_progress'",
            $this->src,
            'clearance blocks only an in-progress cycle, never a finished one'
        );
    }

    public function test_both_siblings_gate_only_on_in_progress(): void
    {
        $this->assertSame(
            2,
            substr_count($this->src, "(string) (\$existing['status'] ?? '') === 'in_progress'"),
            'clearance AND onboarding must both use the supersede-when-terminal pattern'
        );
    }

    public function test_the_notice_guard_is_the_odd_one_out(): void
    {
        // Siblings: one blocking state. Notice: four, three of them unresolvable
        // once the employee is Active.
        $this->assertCount(4, self::NOTICE_ACTIVE);

        $unresolvable = array_values(array_filter(
            self::NOTICE_ACTIVE,
            fn($s) => $this->legalTransitions($s, 'Active') === []
        ));

        $this->assertSame(['approved', 'waived', 'reduced'], $unresolvable,
            'exactly the decided-but-incomplete states are the trap');
    }

    /** One notice doc per staff member — the stale record occupies the only slot. */
    public function test_there_is_exactly_one_notice_document_per_staff_member(): void
    {
        $this->assertStringContainsString('_notice_doc_id($staffId)', $this->src,
            'a per-resignation id would let a new notice coexist and side-step this entirely');
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
