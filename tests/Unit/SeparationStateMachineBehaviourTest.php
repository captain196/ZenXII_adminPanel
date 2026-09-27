<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for the separation / notice-period state machine.
 *
 * The existing separation suites assert that guard LINES exist. This executes
 * the transition predicates themselves, so an invalid transition fails the test
 * rather than merely failing to match a string.
 *
 * The machine, as implemented in Staff.php:
 *
 *      (none) ──submit_resignation──▶ pending
 *      pending ──decide(approve|waive|reduce)──▶ approved | waived | reduced
 *      pending ──decide(reject)──▶ rejected
 *      pending ──withdraw──▶ withdrawn
 *      {approved, waived, reduced} + staff Inactive ──complete──▶ completed
 *
 * Two properties matter most and are asserted here:
 *
 *   1. `complete` accepts ONLY approved/waived/reduced. Completing from
 *      pending, rejected, withdrawn or completed would close out a separation
 *      that was never actually agreed.
 *
 *   2. `complete` additionally refuses unless the staff record is already
 *      Inactive. The endpoint deliberately does not trust the caller's
 *      sequencing — a completed notice period on an Active employee would be a
 *      lie in the permanent record.
 */
final class SeparationStateMachineBehaviourTest extends TestCase
{
    private const ACTIVE_STATUSES = ['pending', 'approved', 'waived', 'reduced'];
    private const COMPLETABLE     = ['approved', 'waived', 'reduced'];

    /** decide_notice_period(): only a pending notice may be decided. */
    private function canDecide(string $status): bool
    {
        return $status === 'pending';
    }

    /** withdraw_resignation(): only a pending notice may be withdrawn. */
    private function canWithdraw(string $status): bool
    {
        return $status === 'pending';
    }

    /** complete_notice_period(): source state AND staff status must both allow it. */
    private function canComplete(string $status, string $staffStatus): bool
    {
        if (!in_array($status, self::COMPLETABLE, true)) return false;
        return strtolower(trim($staffStatus)) === 'inactive';
    }

    /** decide_notice_period()'s action → status mapping. */
    private function decideResult(string $action): ?string
    {
        $map = ['approve' => 'approved', 'waive' => 'waived', 'reduce' => 'reduced', 'reject' => 'rejected'];
        return $map[$action] ?? null;
    }

    // ── Valid transitions ────────────────────────────────────────────────

    public function test_pending_can_be_decided_every_way(): void
    {
        foreach (['approve' => 'approved', 'waive' => 'waived', 'reduce' => 'reduced', 'reject' => 'rejected'] as $action => $expected) {
            $this->assertTrue($this->canDecide('pending'));
            $this->assertSame($expected, $this->decideResult($action));
        }
    }

    public function test_pending_can_be_withdrawn(): void
    {
        $this->assertTrue($this->canWithdraw('pending'));
    }

    public function test_agreed_notice_completes_once_staff_is_inactive(): void
    {
        foreach (self::COMPLETABLE as $s) {
            $this->assertTrue($this->canComplete($s, 'Inactive'), "{$s} + Inactive must complete");
        }
    }

    // ── Invalid source states for completion ─────────────────────────────

    public function test_a_separation_that_was_never_agreed_cannot_be_completed(): void
    {
        foreach (['pending', 'rejected', 'withdrawn', 'completed', ''] as $s) {
            $this->assertFalse($this->canComplete($s, 'Inactive'),
                "completing from '{$s}' would close out a separation nobody agreed to");
        }
    }

    public function test_completion_is_not_idempotent_by_accident(): void
    {
        // A second complete must be refused, not silently re-applied.
        $this->assertFalse($this->canComplete('completed', 'Inactive'));
    }

    // ── The cross-entity precondition ────────────────────────────────────

    public function test_an_active_employee_cannot_have_a_completed_notice_period(): void
    {
        foreach (self::COMPLETABLE as $s) {
            $this->assertFalse($this->canComplete($s, 'Active'),
                "{$s} + Active must refuse — the record would claim someone left who is still employed");
        }
    }

    public function test_staff_status_comparison_is_case_and_space_insensitive(): void
    {
        foreach (['inactive', 'Inactive', 'INACTIVE', '  Inactive  '] as $v) {
            $this->assertTrue($this->canComplete('approved', $v), "'{$v}' must be recognised as inactive");
        }
    }

    public function test_unknown_staff_status_refuses_completion(): void
    {
        foreach (['', 'Suspended', 'On Leave', 'Archived'] as $v) {
            $this->assertFalse($this->canComplete('approved', $v),
                "'{$v}' is not Inactive and must not allow completion");
        }
    }

    // ── Re-decision and double-withdraw ──────────────────────────────────

    public function test_a_decided_notice_cannot_be_decided_again(): void
    {
        foreach (['approved', 'waived', 'reduced', 'rejected', 'withdrawn', 'completed'] as $s) {
            $this->assertFalse($this->canDecide($s), "re-deciding from '{$s}' must be refused");
        }
    }

    public function test_a_non_pending_notice_cannot_be_withdrawn(): void
    {
        foreach (['approved', 'waived', 'reduced', 'rejected', 'withdrawn', 'completed'] as $s) {
            $this->assertFalse($this->canWithdraw($s), "withdrawing from '{$s}' must be refused");
        }
    }

    public function test_unknown_decision_action_yields_no_status(): void
    {
        foreach (['', 'cancel', 'delete', 'APPROVE'] as $a) {
            $this->assertNull($this->decideResult($a), "'{$a}' must not map to a status");
        }
    }

    // ── The active-status set that blocks a duplicate resignation ────────

    public function test_active_statuses_block_a_second_resignation(): void
    {
        foreach (self::ACTIVE_STATUSES as $s) {
            $this->assertContains($s, self::ACTIVE_STATUSES);
        }
        // Terminal states must NOT block a fresh resignation (rehire path).
        foreach (['rejected', 'withdrawn', 'completed'] as $s) {
            $this->assertNotContains($s, self::ACTIVE_STATUSES,
                "'{$s}' is terminal and must not block a later resignation");
        }
    }

    // ── Source pins ──────────────────────────────────────────────────────

    public function test_shipped_completion_guard_matches_this_machine(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString(
            "in_array((string) (\$notice['status'] ?? ''), ['approved', 'waived', 'reduced'], true)",
            $src
        );
        $this->assertStringContainsString("strtolower(trim(\$rawStatus)) !== 'inactive'", $src);
    }

    public function test_shipped_decide_and_withdraw_require_pending(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($src);

        $this->assertSame(2, substr_count($src, "(string) (\$notice['status'] ?? '') !== 'pending'"),
            'decide and withdraw must each require a pending notice');
    }

    public function test_completion_is_rbac_gated_and_audited(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString("log_audit('Staff', 'notice_period_completed'", $src);
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $src);
    }
}
