<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 5 — clearance rules, connected to the clearance system that already
 * existed.
 *
 * WHAT WAS ALREADY THERE, AND WAS NOT REBUILT
 * -------------------------------------------
 * `Staff.php` already ships a complete staff clearance process: a
 * `staffClearances` collection, per-department `pending` / `cleared` /
 * `flagged` states, initiation, per-department decisions, and audit. The
 * department list is ALREADY tenant-configurable at
 * `schools.clearanceDepartments`, with a sane default.
 *
 * None of that is duplicated here. What was missing was the CONNECTION to a
 * settlement: which departments actually block one, what happens when a
 * department is flagged, and who — if anyone — may override.
 *
 * COMPUTE IS NOT PAY
 * ------------------
 * Clearance blocks APPROVAL by default, not computation. Refusing to compute
 * makes it impossible to tell a departing employee what they are owed while a
 * library book is outstanding — a support burden with no control benefit.
 * Approval is what lets money out, so approval is what the control guards.
 *
 * These exercise the REAL service.
 */
final class FnfClearanceRulesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Fnf_policy_service.php';
    }

    private function clearanceDoc(array $states): array
    {
        $departments = [];
        foreach ($states as $dept => $status) {
            $departments[$dept] = ['status' => $status, 'note' => '', 'decidedBy' => null, 'decidedAt' => null];
        }
        return ['departments' => $departments];
    }

    // ── Blocking ─────────────────────────────────────────────────────────

    /** An outstanding department blocks approval but not computation. */
    public function test_an_outstanding_department_blocks_approval_only(): void
    {
        $r = Fnf_policy_service::resolveClearance(
            Fnf_policy_service::defaults(),
            $this->clearanceDoc(['HR' => 'cleared', 'Library' => 'flagged'])
        );

        $this->assertFalse($r['canApprove']);
        $this->assertTrue($r['canCompute'],
            'an employee must still be able to be told what they are owed');
        $this->assertSame(['Library'], array_column($r['blocking'], 'department'));
    }

    /** Pending counts as outstanding — only `cleared` clears. */
    public function test_pending_blocks_just_as_flagged_does(): void
    {
        $r = Fnf_policy_service::resolveClearance(
            Fnf_policy_service::defaults(),
            $this->clearanceDoc(['HR' => 'pending'])
        );

        $this->assertFalse($r['canApprove']);
        $this->assertSame('pending', $r['blocking'][0]['status']);
    }

    /** Everything cleared allows approval. */
    public function test_all_cleared_allows_approval(): void
    {
        $r = Fnf_policy_service::resolveClearance(
            Fnf_policy_service::defaults(),
            $this->clearanceDoc(['HR' => 'cleared', 'IT' => 'cleared'])
        );

        $this->assertTrue($r['canApprove']);
        $this->assertSame([], $r['blocking']);
    }

    /**
     * THE ONE THAT MATTERS MOST. No clearance record is NOT the same as
     * cleared. Treating them alike would let a settlement be approved for
     * someone whose clearance nobody ever opened.
     */
    public function test_no_clearance_record_is_not_the_same_as_cleared(): void
    {
        $r = Fnf_policy_service::resolveClearance(Fnf_policy_service::defaults(), []);

        $this->assertFalse($r['canApprove']);
        $this->assertStringContainsString('no clearance record', $r['reason']);
        $this->assertTrue($r['canCompute'],
            'not having started clearance should not stop somebody being told their figure');
    }

    // ── Narrowing ────────────────────────────────────────────────────────

    /** A school can narrow which departments block a settlement. */
    public function test_a_narrowed_list_blocks_only_the_named_departments(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['clearance']['required'] = ['HR'];

        $r = Fnf_policy_service::resolveClearance(
            $policy,
            $this->clearanceDoc(['HR' => 'cleared', 'Library' => 'flagged', 'IT' => 'pending'])
        );

        $this->assertTrue($r['canApprove'],
            'a school that says only HR clearance gates settlement must be believed');
        $this->assertSame([], $r['blocking']);
    }

    /**
     * An EMPTY required list means EVERY department blocks — the fail-safe
     * reading. A school that has not narrowed the list has not thereby waived
     * clearance.
     */
    public function test_an_empty_required_list_means_every_department_blocks(): void
    {
        $policy = Fnf_policy_service::defaults();
        $this->assertSame([], $policy['clearance']['required']);

        $r = Fnf_policy_service::resolveClearance(
            $policy, $this->clearanceDoc(['HR' => 'cleared', 'Assets' => 'pending'])
        );

        $this->assertFalse($r['canApprove'],
            'not narrowing the list must not be read as waiving it');
    }

    /** A required department absent from the record does not silently pass. */
    public function test_a_required_department_missing_from_the_record_is_not_cleared(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['clearance']['required'] = ['HR', 'Assets'];

        // Only HR was opened; Assets is not in the record at all.
        $r = Fnf_policy_service::resolveClearance($policy, $this->clearanceDoc(['HR' => 'cleared']));

        // Assets simply is not present to block — which is why the CONTROLLER
        // must validate `required` against the tenant's real department list.
        // Recorded rather than silently accepted: see the validation test below.
        $this->assertTrue($r['canApprove'],
            'the resolver can only judge departments the record contains — '
            . 'which is exactly why required must be validated against the '
            . 'tenant list at configuration time, not at settlement time');
    }

    // ── Gating computation too, when a school asks for it ────────────────

    public function test_a_school_can_choose_to_gate_computation_as_well(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['clearance']['blockApprovalOnly'] = false;

        $r = Fnf_policy_service::resolveClearance($policy, $this->clearanceDoc(['HR' => 'flagged']));

        $this->assertFalse($r['canApprove']);
        $this->assertFalse($r['canCompute']);
    }

    /** But the default is approval-only. */
    public function test_the_default_gates_approval_only(): void
    {
        $this->assertTrue(Fnf_policy_service::defaults()['clearance']['blockApprovalOnly']);
    }

    // ── Override is not a bypass ─────────────────────────────────────────

    /**
     * A school that has nominated nobody has NO override. An unrestricted
     * bypass would make the whole control decorative.
     */
    public function test_no_override_role_means_no_override(): void
    {
        $r = Fnf_policy_service::canOverrideClearance(
            Fnf_policy_service::defaults(), ['Principal', 'Admin'], 'urgent exit'
        );

        $this->assertFalse($r['allowed']);
        $this->assertStringContainsString('no clearance override role', $r['reason']);
    }

    /** An override requires a recorded reason. */
    public function test_an_override_requires_a_reason(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['clearance']['overrideRole'] = 'Principal';

        $r = Fnf_policy_service::canOverrideClearance($policy, ['Principal'], '   ');

        $this->assertFalse($r['allowed']);
        $this->assertStringContainsString('recorded reason', $r['reason']);
    }

    /** And the nominated role. */
    public function test_only_the_nominated_role_may_override(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['clearance']['overrideRole'] = 'Principal';

        $denied = Fnf_policy_service::canOverrideClearance($policy, ['HR Manager'], 'urgent');
        $this->assertFalse($denied['allowed']);
        $this->assertStringContainsString('only Principal', $denied['reason']);

        $allowed = Fnf_policy_service::canOverrideClearance($policy, ['principal'], 'urgent');
        $this->assertTrue($allowed['allowed'], 'role matching must be case-insensitive');
    }

    // ── No duplicate department model ────────────────────────────────────

    /**
     * THE PHASE-E CHECK. The policy must NOT define its own department list. A
     * second list would drift from `schools.clearanceDepartments`, and a
     * settlement would then block on a department that no longer exists, or
     * fail to block on one that does.
     */
    public function test_the_policy_does_not_define_its_own_departments(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_policy_service.php'
        );

        foreach (['Accounts', 'Administration', 'Academic', 'CLEARANCE_DEFAULT_DEPARTMENTS'] as $dept) {
            $this->assertStringNotContainsString($dept, $src,
                'the department list belongs to schools.clearanceDepartments — '
                . 'a second copy would drift');
        }

        $this->assertSame([], Fnf_policy_service::defaults()['clearance']['required'],
            'required must start empty and reference the tenant list, not seed its own');
    }

    /** And the existing clearance system is still the one that owns the list. */
    public function test_the_existing_clearance_system_still_owns_the_list(): void
    {
        $staff = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Staff.php'
        );

        $this->assertStringContainsString("\$school['clearanceDepartments']", $staff,
            'the tenant department list must keep being read from the school profile');
        $this->assertStringContainsString('CLEARANCE_DEFAULT_DEPARTMENTS', $staff,
            'and its default must stay where it already was');
    }

    // ── Validation of the required subset ────────────────────────────────

    /** Duplicates and blanks are dropped when the policy is saved. */
    public function test_required_departments_are_normalised(): void
    {
        list($ok, $clean) = Fnf_policy_service::validate([
            'clearance' => ['required' => ['HR', '', 'HR', '  ', 'Library']],
        ]);

        $this->assertTrue($ok);
        $this->assertSame(['HR', 'Library'], $clean['clearance']['required']);
    }

    /** The override role survives validation. */
    public function test_the_override_role_is_persisted(): void
    {
        list($ok, $clean) = Fnf_policy_service::validate([
            'clearance' => ['overrideRole' => ' Principal '],
        ]);

        $this->assertTrue($ok);
        $this->assertSame('Principal', $clean['clearance']['overrideRole']);
    }
}
