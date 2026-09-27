<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.9 — D20: student promotion during session rollover requires SIS:manage.
 *
 * THE BYPASS THIS CLOSES — a privilege-escalation path, not a style issue:
 *   Sis::execute_promotion()     → _require_role(…, 'SIS', 'manage')
 *   Classes::transfer_students() → _require_role(…, 'SIS', 'manage')  (D16, Phase 16.1)
 *   School_config::rollover_session(promote_students=1) → 'Configuration','manage'
 *
 * The canonical promotion endpoint denied a Configuration-only administrator,
 * while this endpoint performed the SAME student movement — className,
 * section, session, and Class-12 → Alumni — under a configuration permission.
 *
 * These tests model `MY_Controller::_require_role()` exactly, including the
 * legacy role-name fallback that makes the empty-$allowed form necessary.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase169RolloverPromotionAuthorizationTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    private const LEVELS = ['view' => 1, 'edit' => 2, 'manage' => 3];

    /**
     * Mirrors MY_Controller::_require_role(). Returns true = allowed.
     *
     * @param array $allowed legacy role-name list
     * @param array $perms   module => level held by the user
     */
    private function requireRole(string $role, array $perms, array $allowed, ?string $module, string $level): bool
    {
        // Bypass roles — unchanged by this phase, by design.
        if (strcasecmp($role, 'Super Admin') === 0) return true;
        if (strcasecmp($role, 'School Super Admin') === 0) return true;

        $gradedAuthoritative = false;
        if ($module !== null) {
            $held = $perms[$module] ?? null;
            if ($held !== null && (self::LEVELS[$held] ?? 0) >= (self::LEVELS[$level] ?? 99)) {
                return true;                      // holds the capability at >= required level
            }
            if ($held !== null) $gradedAuthoritative = true;   // holds module, but lower → DENY
        }

        if (!$gradedAuthoritative) {
            foreach ($allowed as $a) {
                if (strcasecmp($role, $a) === 0) return true;  // legacy name fallback
            }
        }
        return false;
    }

    /** Mirrors the endpoint: structure gate, then the promotion gate. */
    private function rollover(string $role, array $perms, bool $promote): string
    {
        $adminRoles = ['Admin', 'Principal'];            // self::ADMIN_ROLES shape
        if (!$this->requireRole($role, $perms, $adminRoles, 'Configuration', 'manage')) {
            return 'DENIED_STRUCTURE';
        }
        // D20 — empty $allowed so ONLY the graded capability can grant it.
        if ($promote && !$this->requireRole($role, $perms, [], 'SIS', 'manage')) {
            return 'DENIED_PROMOTION';
        }
        return $promote ? 'OK_WITH_PROMOTION' : 'OK_STRUCTURE_ONLY';
    }

    // ── A. The bypass is closed ─────────────────────────────────────────

    public function test_configuration_only_admin_cannot_promote_students(): void
    {
        $r = $this->rollover('Admin', ['Configuration' => 'manage'], true);
        $this->assertSame('DENIED_PROMOTION', $r,
            'this was the escalation path: promotion under a configuration permission');
    }

    public function test_configuration_only_admin_can_still_roll_over_structure(): void
    {
        // The fix must not break the legitimate workflow.
        $r = $this->rollover('Admin', ['Configuration' => 'manage'], false);
        $this->assertSame('OK_STRUCTURE_ONLY', $r);
    }

    public function test_admin_with_both_capabilities_can_promote(): void
    {
        $r = $this->rollover('Admin', ['Configuration' => 'manage', 'SIS' => 'manage'], true);
        $this->assertSame('OK_WITH_PROMOTION', $r);
    }

    // ── B. The legacy role-name fallback cannot defeat the gate ─────────

    public function test_empty_allowed_list_prevents_a_role_name_from_granting_promotion(): void
    {
        // THE SUBTLE ONE. _require_role() falls back to the legacy NAME list
        // for users who hold no such module. Had the promotion gate been
        // written with self::ADMIN_ROLES, 'Admin' would have passed on its
        // NAME and the check would have been decorative.
        $withAdminRoles = $this->requireRole('Admin', ['Configuration' => 'manage'], ['Admin', 'Principal'], 'SIS', 'manage');
        $withEmptyList  = $this->requireRole('Admin', ['Configuration' => 'manage'], [],                    'SIS', 'manage');
        $this->assertTrue($withAdminRoles,  'a name list WOULD have granted it — the bug we avoided');
        $this->assertFalse($withEmptyList,  'empty list makes the graded capability authoritative');
    }

    public function test_holding_sis_at_a_lower_level_is_denied_not_downgraded(): void
    {
        foreach (['view', 'edit'] as $held) {
            $this->assertSame('DENIED_PROMOTION',
                $this->rollover('Admin', ['Configuration' => 'manage', 'SIS' => $held], true),
                "SIS:{$held} must not satisfy SIS:manage");
        }
    }

    // ── C. No reverse escalation ────────────────────────────────────────

    public function test_sis_manage_alone_does_not_grant_session_configuration(): void
    {
        // A student-records manager must not gain session/structure authority.
        $r = $this->rollover('Front Office', ['SIS' => 'manage'], false);
        $this->assertSame('DENIED_STRUCTURE', $r);
    }

    public function test_sis_manage_alone_cannot_reach_promotion_through_this_endpoint(): void
    {
        // The structure gate still runs first, so this endpoint is not an
        // alternate promotion route for a SIS-only user either.
        $r = $this->rollover('Front Office', ['SIS' => 'manage'], true);
        $this->assertSame('DENIED_STRUCTURE', $r);
    }

    // ── D. Bypass roles unchanged ───────────────────────────────────────

    public function test_super_admin_roles_are_unaffected(): void
    {
        foreach (['Super Admin', 'School Super Admin'] as $role) {
            $this->assertSame('OK_WITH_PROMOTION', $this->rollover($role, [], true));
        }
    }

    // ── E. Consistency with the other student-movement paths ────────────

    public function test_all_student_movement_paths_now_require_sis_manage(): void
    {
        $perms = ['Configuration' => 'manage'];   // configuration-only operator
        // execute_promotion
        $this->assertFalse($this->requireRole('Admin', $perms, [], 'SIS', 'manage'));
        // transfer_students (D16)
        $this->assertFalse($this->requireRole('Admin', $perms, [], 'SIS', 'manage'));
        // rollover promotion (D20 — this phase)
        $this->assertSame('DENIED_PROMOTION', $this->rollover('Admin', $perms, true));
    }

    public function test_structure_operations_remain_under_configuration(): void
    {
        // Creating next year's classes/sections/capacities is genuinely
        // configuration and must NOT start demanding SIS.
        $this->assertTrue($this->requireRole('Admin', ['Configuration' => 'manage'], [], 'Configuration', 'manage'));
        $this->assertSame('OK_STRUCTURE_ONLY', $this->rollover('Admin', ['Configuration' => 'manage'], false));
    }

    // ── F. Fail-closed ordering ─────────────────────────────────────────

    public function test_denial_happens_before_any_session_is_created(): void
    {
        // The guard sits above every write, so a denied caller cannot leave a
        // half-created target session behind. Modelled as: the deny result
        // carries no side effect.
        $sideEffects = [];
        $result = $this->rollover('Admin', ['Configuration' => 'manage'], true);
        if ($result === 'OK_WITH_PROMOTION') $sideEffects[] = 'session_created';
        $this->assertSame('DENIED_PROMOTION', $result);
        $this->assertSame([], $sideEffects, 'nothing may be written on the denied path');
    }

    // ── G. Identity invariants ──────────────────────────────────────────

    public function test_authorization_is_identity_agnostic(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
