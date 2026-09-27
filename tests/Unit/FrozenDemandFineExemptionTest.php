<?php

use PHPUnit\Framework\TestCase;

if (!class_exists('Fee_late_fee_service')) {
    require_once APPPATH . 'libraries/Fee_late_fee_service.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  FRZ-1 — A WITHDRAWN STUDENT'S DEBT KEPT GROWING
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_lifecycle::freezeFeesOnSoftDelete()` stamps `status: 'frozen'` on every
 * collectable demand when a student is WITHDRAWN or soft-deleted. Its own
 * vocabulary docblock defines the status as "student soft-deleted, do not
 * collect". It is live from three call sites: `Sis::withdraw_student`,
 * `Sis::delete_student`, and `Hostel.php:402`.
 *
 * `fineFor()` decided fine eligibility with a DENYLIST:
 *
 *     if ($status === 'paid' || $status === 'archived') return null;
 *
 * 'frozen' is not in that list, so it fell through and was fined.
 *
 * ── WHY IT REACHES PRODUCTION ─────────────────────────────────────────────
 * `computeForSchool()` deliberately queries EVERY demand in the session in one
 * go — "deliberately NOT foreach student -> computeForStudent(): that is an
 * N+1" — and applies no student-status filter, because it never loads the
 * student. A withdrawn student's demands are simply still in the result set.
 *
 * `applyTo()` then writes `fine_amount` AND recomputes
 * `balance = netAmount + fine - paidAmount`. So each run grew a live balance
 * against someone who had already left the school.
 *
 * ── THE DENYLIST HAD ALREADY FAILED ONCE ──────────────────────────────────
 * 'archived' is not in that condition by original design — SW4-companion-F
 * added it reactively after archived demands started accruing fines and
 * "re-introducing a non-zero balance onto a demand the archive operation
 * deliberately set to balance=0". Identical mechanism, one status earlier.
 * Two misses is enough to conclude the denylist SHAPE is the defect, not the
 * missing entry, so the fix inverts it to an allowlist.
 *
 * The correct shape was already in the same file: `REDATE_ELIGIBLE_STATUSES`.
 *
 * ── THIS TEST DRIVES THE REAL CLASS ───────────────────────────────────────
 * `Phase156LateFeeEngineTest` states plainly that it "models its DECISION
 * RULES" with a private copy of `fineFor()`. That copy still carries the old
 * denylist and passes regardless of what the library does — which is precisely
 * why the defect survived alongside a green late-fee suite.
 *
 * So this file instantiates the REAL `Fee_late_fee_service` via
 * `newInstanceWithoutConstructor()` and calls the REAL `fineFor()`. It needs no
 * Firestore handle because `fineFor()` is pure: it reads only its arguments and
 * a class constant. A copy cannot drift from the source if there is no copy.
 */
class FrozenDemandFineExemptionTest extends TestCase
{
    /** @var Fee_late_fee_service */
    private $svc;

    /** A policy that fines every eligible overdue demand a flat Rs.100. */
    private array $policy = ['enabled' => true, 'type' => 'flat', 'value' => 100, 'cap' => 0];

    private const CUTOFF = '2026-06-15';

    protected function setUp(): void
    {
        // No constructor: fineFor() touches no collaborator, only its
        // arguments and self::FINEABLE_STATUSES.
        $this->svc = (new ReflectionClass('Fee_late_fee_service'))
            ->newInstanceWithoutConstructor();
    }

    private function demand(string $status): array
    {
        return [
            'status'     => $status,
            'due_date'   => '2026-05-01',   // comfortably past the cutoff
            'net_amount' => 5000.0,
        ];
    }

    // ══ CONTROL — THE FIXTURE REALLY IS FINEABLE ════════════════════════════

    /**
     * Without this, every assertion below could pass because the demand was
     * rejected for a reason that has nothing to do with its status — no due
     * date, a future due date, a zero net.
     */
    public function test_control_an_ordinary_overdue_demand_is_fined(): void
    {
        $this->assertSame(100.0, $this->svc->fineFor($this->demand('unpaid'), $this->policy, self::CUTOFF),
            'the fixture is not fineable at all, so the status assertions below would be vacuous');
        $this->assertSame(100.0, $this->svc->fineFor($this->demand('partial'), $this->policy, self::CUTOFF));
    }

    // ══ FRZ-1 ═══════════════════════════════════════════════════════════════

    /** WOULD HAVE FAILED BEFORE: 'frozen' fell through the denylist and was fined. */
    public function test_a_frozen_demand_is_never_fined(): void
    {
        $this->assertNull($this->svc->fineFor($this->demand('frozen'), $this->policy, self::CUTOFF),
            'a demand frozen by freezeFeesOnSoftDelete() was fined. That status means '
            . '"do not collect" — the student has been withdrawn or soft-deleted — yet each '
            . 'late-fee run adds a fine and recomputes balance = netAmount + fine - paidAmount, '
            . 'growing a live debt against someone who has left the school.');
    }

    /**
     * The freeze writer only skips demands already in a closed state, so the
     * statuses that can actually CARRY 'frozen' are the fineable ones — which
     * is exactly why this was reachable rather than theoretical.
     */
    public function test_the_freeze_writer_targets_precisely_the_fineable_statuses(): void
    {
        $lib = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $at  = strpos($lib, 'function freezeFeesOnSoftDelete');
        $this->assertNotFalse($at, 'freezeFeesOnSoftDelete was renamed');

        $body = substr($lib, $at, 900);
        $this->assertStringContainsString("'status'    => 'frozen'", $body,
            'the freeze no longer writes the frozen status — re-derive this test');
        $this->assertStringContainsString("in_array(\$st, ['paid', 'archived', 'frozen'], true)", $body,
            'the freeze skip-list changed; confirm which statuses can still become frozen');
    }

    // ══ THE SHAPE, NOT JUST THE INSTANCE ════════════════════════════════════

    /**
     * The point of the fix. A denylist missed 'archived', then missed 'frozen';
     * an allowlist makes the NEXT status added to the vocabulary safe by
     * default. This asserts the shape so the fix cannot be reverted to a
     * denylist that merely adds 'frozen'.
     */
    public function test_fine_eligibility_is_an_allowlist(): void
    {
        $this->assertSame(['unpaid', 'partial'], Fee_late_fee_service::FINEABLE_STATUSES);

        $src = (string) file_get_contents(APPPATH . 'libraries/Fee_late_fee_service.php');
        $at  = strpos($src, 'public function fineFor');
        $this->assertNotFalse($at);
        $body = substr($src, $at, 2600);

        $this->assertStringContainsString('!in_array($status, self::FINEABLE_STATUSES, true)', $body,
            'fine eligibility is no longer decided by the allowlist. A denylist has already '
            . 'missed two closed statuses in this exact position.');
    }

    /**
     * The whole written demand vocabulary, checked at once. Every status that
     * is not an ACTIVE, collectable one must be exempt — including the two the
     * denylist happened to cover, so this also pins that the fix did not
     * regress them.
     */
    public function test_every_closed_status_in_the_demand_vocabulary_is_exempt(): void
    {
        // From Fee_lifecycle's own "Demand status vocabulary" docblock.
        foreach (['paid', 'archived', 'frozen', 'reversed'] as $closed) {
            $this->assertNull($this->svc->fineFor($this->demand($closed), $this->policy, self::CUTOFF),
                "a '{$closed}' demand attracted a late fee");
        }
    }

    /**
     * An unrecognised status must be treated as closed. This is the property a
     * denylist cannot have, and the reason the fix is worth more than adding
     * one entry: whoever adds the next status inherits the safe behaviour
     * without knowing this file exists.
     */
    public function test_an_unrecognised_status_fails_closed(): void
    {
        foreach (['cancelled', 'writeoff', 'disputed', ''] as $unknown) {
            $this->assertNull($this->svc->fineFor($this->demand($unknown), $this->policy, self::CUTOFF),
                "an unrecognised status '{$unknown}' was treated as fineable. Fine eligibility "
                . 'must fail closed — money must not be added to a demand whose state this '
                . 'engine does not understand.');
        }
    }

    /**
     * Case-insensitivity is load-bearing: `fineFor()` lowercases before
     * matching, so a writer that stamps 'Unpaid' must still be fineable.
     */
    public function test_the_allowlist_is_matched_case_insensitively(): void
    {
        $this->assertSame(100.0, $this->svc->fineFor($this->demand('Unpaid'), $this->policy, self::CUTOFF),
            'a capitalised active status stopped being fineable — the lowercasing step was lost, '
            . 'which would silently exempt real dues from late fees');
    }

    // ══ WHAT WAS DELIBERATELY LEFT ALONE ════════════════════════════════════

    /**
     * `Fee_defaulter_check::_checkFeesClearance()` counts a frozen demand as an
     * outstanding due, and that is CORRECT — it is not the same defect.
     *
     * Freezing stops the debt being pursued automatically; it does not forgive
     * it. A withdrawn student who still owes money must not come back "fees
     * clear" on an exit/TC clearance check. Only the automatic GROWTH of that
     * debt was wrong, which is what FRZ-1 fixed.
     *
     * The same reasoning covers `FeeCollectionService`, which will still accept
     * a payment against a frozen demand: if a family returns to settle what
     * they owe, refusing the money is worse than taking it.
     *
     * Recorded as a test so this stays a decision rather than something a later
     * reader "fixes" by making the two paths consistent for their own sake.
     */
    public function test_clearance_still_counts_a_frozen_demand_as_owed(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Fee_defaulter_check.php');
        $at  = strpos($src, 'function _checkFeesClearance');
        $this->assertNotFalse($at, '_checkFeesClearance was renamed');
        $body = substr($src, $at, 2200);

        $this->assertStringContainsString("\$status === 'paid' || \$status === 'archived'", $body,
            'the clearance check now exempts more statuses. If frozen demands were excluded '
            . 'here, a withdrawn student owing money would report FEES CLEAR — freezing stops '
            . 'collection, it does not cancel the debt.');
    }
}
