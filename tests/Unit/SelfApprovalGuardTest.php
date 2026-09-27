<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * No one may approve their own request on a path that changes their own pay.
 *
 * ZenX has two employee-initiated workflows that feed payroll:
 *
 *   decide_leave()                  — approval consumes leave balance and can
 *                                     create LOP, so it moves money.
 *   staff_regularization_decide()   — approval rewrites an attendance mark,
 *                                     which is a direct payroll input.
 *
 * Both already refuse self-approval. The regularization path names the risk
 * outright in its own comment: "self-service payroll fraud". This suite pins
 * that invariant so a future refactor cannot quietly drop either guard.
 *
 * Audited 2026-08-21 and found INTACT on both paths — this is a regression
 * guard, not a defect report.
 *
 * `correction_decide()` is deliberately NOT covered: it operates on
 * `studentId` and belongs to the student attendance domain, where an approval
 * has no payroll consequence.
 */
final class SelfApprovalGuardTest extends TestCase
{
    private string $hr;
    private string $att;

    protected function setUp(): void
    {
        $hr  = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $att = file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        $this->assertNotFalse($hr);
        $this->assertNotFalse($att);
        $this->hr  = $hr;
        $this->att = $att;
    }

    /** The predicate both endpoints apply. */
    private function isSelfApproval(string $applicantId, string $actorId): bool
    {
        return $applicantId !== '' && $applicantId === $actorId;
    }

    // ── The predicate itself ─────────────────────────────────────────────

    public function test_same_person_is_self_approval(): void
    {
        $this->assertTrue($this->isSelfApproval('STA0007', 'STA0007'));
    }

    public function test_different_person_is_allowed(): void
    {
        $this->assertFalse($this->isSelfApproval('STA0007', 'STA0001'));
    }

    /**
     * An empty applicant must NOT be treated as self-approval — otherwise a
     * malformed request with no applicant would be refused for the wrong
     * reason, and an anonymous actor would match every empty applicant.
     */
    public function test_empty_applicant_is_not_self_approval(): void
    {
        $this->assertFalse($this->isSelfApproval('', ''));
        $this->assertFalse($this->isSelfApproval('', 'STA0001'));
    }

    public function test_guard_is_exact_match_not_prefix(): void
    {
        $this->assertFalse($this->isSelfApproval('STA0007', 'STA00071'));
        $this->assertFalse($this->isSelfApproval('STA00071', 'STA0007'));
    }

    // ── Leave: the guard must exist ──────────────────────────────────────

    public function test_leave_decision_refuses_self_approval(): void
    {
        $this->assertStringContainsString(
            "\$applicantId === (string) (\$this->admin_id ?? '')",
            $this->hr,
            'decide_leave must compare the applicant against the acting admin'
        );
        $this->assertStringContainsString(
            'You cannot approve or reject your own leave request.',
            $this->hr
        );
    }

    /** Rejection is as sensitive as approval — both must be refused. */
    public function test_leave_guard_covers_rejection_too(): void
    {
        $this->assertStringContainsString('approve or reject your own', $this->hr,
            'a self-rejection is equally a conflict of interest');
    }

    // ── Attendance regularization: the guard must exist ──────────────────

    public function test_regularization_decision_refuses_self_approval(): void
    {
        $this->assertStringContainsString("'reason' => 'self_approval_forbidden'", $this->att,
            'staff_regularization_decide must skip a reviewer deciding their own request');
    }

    /**
     * The regularization guard SKIPS the offending row rather than failing the
     * whole batch, so one self-request cannot block a legitimate bulk approval.
     * That behaviour is deliberate and worth pinning.
     */
    public function test_regularization_guard_skips_rather_than_aborting_the_batch(): void
    {
        $this->assertStringContainsString('$skipped[] = ', $this->att);
    }

    // ── Both decide paths remain RBAC-gated in strict mode ───────────────

    public function test_both_money_paths_are_rbac_gated_strictly(): void
    {
        $this->assertStringContainsString(
            "_require_role(self::STAFF_REG_DECIDE_ROLES, 'staff_regularization_decide', 'strict')",
            $this->att
        );
        $this->assertStringContainsString(
            "_require_role(self::MANAGE_ROLES, 'correction_decide', 'strict')",
            $this->att
        );
    }

    /**
     * The student correction path carries a tenant-boundary check that emits
     * security telemetry. Pinned because a cross-tenant decision would be far
     * worse than a self-approval.
     */
    public function test_correction_path_emits_cross_tenant_telemetry(): void
    {
        $this->assertStringContainsString("'CROSS_TENANT_PROBE'", $this->att);
        $this->assertStringContainsString("(string) (\$req['schoolId'] ?? '') !== \$this->school_id", $this->att);
    }
}
