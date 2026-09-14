<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A newly onboarded school's teachers must be able to VIEW the timetable in the
 * Teacher App without any manual RBAC repair.
 *
 * ── THE GAP (found in UAT, 2026-09-13) ───────────────────────────────────
 *
 * The Teacher App gates its timetable screen on the RBAC module "Academic"
 * (ModuleGate: route "timetable" → module "Academic"; capabilities come from
 * staffCapabilities/{uid}.modules, projected from schools.staffRoles by the
 * onSchoolRolesChanged Cloud Function). Yet the seed's ROLE_TEACHER and
 * ROLE_CLASS_TEACHER both carry the `can_access_timetable` FLAG — the declared
 * intent that teachers reach the timetable — while their `permissions` omitted
 * "Academic". So on every freshly onboarded school the "Time" tab was hidden,
 * and the only cure was a manual role edit per school.
 *
 * ── THE FIX, AND WHY BOTH PLACES ─────────────────────────────────────────
 *
 * New schools: ensure_unified_roles() seeds a missing role by copying the whole
 * DEFAULT_STAFF_ROLES entry, so "Academic" must be in that entry's `permissions`
 * AND its level pinned inline — because the capability CF defaults an un-levelled
 * module to 'manage', and a teacher must VIEW the timetable, not manage it.
 *
 * Existing schools: Rbac_levels_backfill's LEVEL_MAP is the repair path, so it
 * must resolve "Academic" to 'view' for the same two roles.
 *
 * Source-assertion, matching the RBAC tests already in this suite — Staff and
 * Rbac_levels_backfill are CI controllers and cannot be instantiated offline.
 */
final class TeacherRoleTimetableAccessSeedTest extends TestCase
{
    private static string $staff = '';
    private static string $levels = '';

    public static function setUpBeforeClass(): void
    {
        self::$staff = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Staff.php');
        self::$levels = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Rbac_levels_backfill.php');
    }

    /** The single seed line for a role, from DEFAULT_STAFF_ROLES. */
    private function seedEntry(string $roleId): string
    {
        $needle = "'{$roleId}'";
        $i = strpos(self::$staff, $needle . ' ');
        if ($i === false) { $i = strpos(self::$staff, $needle); }
        $this->assertNotFalse($i, "{$roleId} is gone from the seed");
        $end = strpos(self::$staff, "\n", $i);
        return substr(self::$staff, $i, ($end !== false ? $end - $i : 2000));
    }

    /** The LEVEL_MAP line for a role, from Rbac_levels_backfill. */
    private function levelEntry(string $roleId): string
    {
        $i = strpos(self::$levels, "'{$roleId}'");
        $this->assertNotFalse($i, "{$roleId} is gone from the LEVEL_MAP");
        $end = strpos(self::$levels, "\n", $i);
        return substr(self::$levels, $i, ($end !== false ? $end - $i : 800));
    }

    public function teachingRoles(): array
    {
        return [['ROLE_TEACHER'], ['ROLE_CLASS_TEACHER']];
    }

    /**
     * @dataProvider teachingRoles
     * The declared intent: both teaching roles say they can reach the timetable.
     */
    public function test_the_role_declares_timetable_access_intent(string $roleId): void
    {
        $this->assertStringContainsString('can_access_timetable', $this->seedEntry($roleId),
            "{$roleId} no longer declares can_access_timetable — the intent basis "
            . 'for granting Academic is gone');
    }

    /**
     * @dataProvider teachingRoles
     * THE FIX (module): Academic is in the role's seed permissions.
     */
    public function test_the_seed_grants_the_academic_module(string $roleId): void
    {
        $entry = $this->seedEntry($roleId);
        $this->assertMatchesRegularExpression(
            "~'permissions'\s*=>\s*\[[^\]]*'Academic'[^\]]*\]~", $entry,
            "{$roleId} does not grant the 'Academic' module, so the Teacher App "
            . 'hides the timetable screen for a newly onboarded school');
    }

    /**
     * @dataProvider teachingRoles
     * THE FIX (level): Academic is pinned to VIEW inline, so a new school does
     * not fall through to the CF's 'manage' default.
     */
    public function test_the_seed_pins_academic_to_view(string $roleId): void
    {
        $entry = $this->seedEntry($roleId);
        $this->assertMatchesRegularExpression(
            "~'permissionLevels'\s*=>\s*\[[^\]]*'Academic'\s*=>\s*'view'~", $entry,
            "{$roleId} does not pin Academic to 'view' inline. A freshly seeded "
            . "role carries no other level entries, so the capability CF would "
            . 'default Academic to manage — a teacher must VIEW the timetable, '
            . 'not manage it');

        // And never edit/manage for a teacher.
        $this->assertDoesNotMatchRegularExpression(
            "~'Academic'\s*=>\s*'(edit|manage)'~", $entry,
            "{$roleId} pins Academic above view — teachers must not edit or manage "
            . 'timetables');
    }

    /**
     * @dataProvider teachingRoles
     * The existing-school repair path resolves Academic to view too.
     */
    public function test_the_backfill_level_map_resolves_academic_to_view(string $roleId): void
    {
        $entry = $this->levelEntry($roleId);
        $this->assertMatchesRegularExpression("~'Academic'\s*=>\s*'view'~", $entry,
            "{$roleId}'s LEVEL_MAP entry does not resolve Academic to view, so "
            . 'existing schools repaired by the backfill would get the wrong level '
            . "(the '*' default is edit)");
    }

    /**
     * The change is additive: the roles that already MANAGE academics keep it.
     * A regression that dropped Academic from the coordinator/principal while
     * adding it to teachers would silently move timetable authority.
     */
    public function test_coordinator_and_principal_still_hold_academic(): void
    {
        foreach (['ROLE_ACADEMIC_COORDINATOR', 'ROLE_PRINCIPAL'] as $roleId) {
            $this->assertMatchesRegularExpression(
                "~'permissions'\s*=>\s*(self::ALL_MODULES|\[[^\]]*'Academic'[^\]]*\])~",
                $this->seedEntry($roleId),
                "{$roleId} lost the Academic module");
        }
    }

    /** The seed and the backfill must agree that teacher Academic is 'view'. */
    public function test_seed_and_backfill_do_not_drift_on_teacher_academic(): void
    {
        foreach (['ROLE_TEACHER', 'ROLE_CLASS_TEACHER'] as $roleId) {
            $seedView = (bool) preg_match("~'Academic'\s*=>\s*'view'~", $this->seedEntry($roleId));
            $mapView  = (bool) preg_match("~'Academic'\s*=>\s*'view'~", $this->levelEntry($roleId));
            $this->assertTrue($seedView && $mapView,
                "{$roleId}: the inline seed level and the backfill LEVEL_MAP level "
                . 'for Academic disagree — a new school and a repaired school would '
                . 'give teachers different timetable access');
        }
    }
}
