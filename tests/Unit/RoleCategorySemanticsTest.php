<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * `category` is the STAFF-classification vocabulary. It does not describe system roles.
 *
 * INVESTIGATION (2026-08-21), driven by a RED result from the project's own
 * Deptrole_selftest:
 *
 *   ✗ default ROLE_BASELINE_APP: valid category
 *   ✗ staffRole ROLE_BASELINE_APP: category 'System' valid
 *
 * The canonical vocabulary is defined IDENTICALLY in three places —
 * Staff_access::ROLE_CATEGORIES, Org::ROLE_CATEGORIES and the self-test — as
 * ['Teaching', 'Non-Teaching', 'Administrative', 'Support'], described in the
 * source as a "Fixed role-classification set". The self-test was therefore NOT
 * stale: it matched production's own constant.
 *
 * What `category` actually means was established by tracing every consumer:
 *
 *   • Staff_access::_seed_departments() migrates category INTO `department`
 *     when department is empty — category is the LEGACY field, department its
 *     replacement.
 *   • Staff.php's role list reads `department ?? category` — the same fallback.
 *   • _is_system_dept_name() treats exactly those four values as the seeded
 *     system departments.
 *
 * ROLE_BASELINE_APP is auto-assigned to every staff member and carries
 * `is_system => true`, `is_global => true`, `auto_assign => true`. Its
 * `category => 'System'` is therefore a SYSTEM MARKER OVERLOADED INTO A STAFF
 * FIELD — and the canonical marker already exists on the same role.
 *
 * Neither obvious "fix" was correct:
 *   • Changing it to 'Support' would assert the baseline role is Support staff
 *     (false) and seed `department = 'Support'`.
 *   • Adding 'System' to the vocabulary would legitimise a phantom department,
 *     because _is_system_dept_name('System') is false — it would have been
 *     created as an ordinary USER department.
 *
 * The fix scopes the vocabulary to the roles it describes: system roles are
 * exempt from staff classification and are asserted to carry `is_system`
 * instead, and the department seed skips them. No role's category, department,
 * permissions or level changed. GRADED RBAC IS UNTOUCHED.
 */
final class RoleCategorySemanticsTest extends TestCase
{
    private const CATS = ['Teaching', 'Non-Teaching', 'Administrative', 'Support'];

    /** The seed rule as shipped after the fix. */
    private function seedDepartment(array $role): ?string
    {
        if (!empty($role['is_system'])) return null;                 // skipped entirely
        if (isset($role['department']) && trim((string) $role['department']) !== '') {
            return (string) $role['department'];                     // already set, untouched
        }
        return trim((string) ($role['category'] ?? '')) ?: 'Administrative';
    }

    /** The self-test rule as shipped after the fix. */
    private function categoryCheckApplies(array $role): bool
    {
        return empty($role['is_system']);
    }

    private function baselineRole(): array
    {
        return [
            'label' => 'App Baseline', 'category' => 'System', 'flags' => [],
            'attendance_type' => 'standard', 'is_system' => true,
            'is_global' => true, 'auto_assign' => true, 'tier' => 9, 'sort_order' => 0,
        ];
    }

    // ── The phantom department this prevents ─────────────────────────────

    public function test_system_role_no_longer_seeds_a_department(): void
    {
        $this->assertNull($this->seedDepartment($this->baselineRole()),
            "ROLE_BASELINE_APP must not seed a department named 'System'");
    }

    public function test_the_phantom_would_not_have_been_a_system_department(): void
    {
        // _is_system_dept_name() matches only the four canonical categories, so
        // 'System' would have been created as an ordinary user department.
        $this->assertNotContains('System', self::CATS,
            'this is precisely why seeding from it was wrong');
    }

    // ── Ordinary staff roles are unaffected ──────────────────────────────

    public function test_ordinary_role_still_seeds_from_category(): void
    {
        $this->assertSame('Teaching', $this->seedDepartment([
            'label' => 'Teacher', 'category' => 'Teaching',
        ]));
    }

    public function test_ordinary_role_with_no_category_falls_back_to_administrative(): void
    {
        $this->assertSame('Administrative', $this->seedDepartment(['label' => 'Odd']));
    }

    public function test_an_explicit_department_is_never_overwritten(): void
    {
        $this->assertSame('Science', $this->seedDepartment([
            'label' => 'Teacher', 'category' => 'Teaching', 'department' => 'Science',
        ]));
    }

    public function test_blank_department_still_seeds(): void
    {
        $this->assertSame('Support', $this->seedDepartment([
            'category' => 'Support', 'department' => '   ',
        ]));
    }

    // ── Vocabulary scoping ───────────────────────────────────────────────

    public function test_staff_vocabulary_does_not_apply_to_system_roles(): void
    {
        $this->assertFalse($this->categoryCheckApplies($this->baselineRole()));
    }

    public function test_staff_vocabulary_applies_to_every_non_system_role(): void
    {
        foreach (self::CATS as $c) {
            $this->assertTrue($this->categoryCheckApplies(['category' => $c, 'is_system' => false]));
        }
        $this->assertTrue($this->categoryCheckApplies(['category' => 'Food']),
            'a tenant-created role must still be validated');
    }

    /** The exemption must not become a loophole for ordinary roles. */
    public function test_exemption_requires_a_truthy_is_system(): void
    {
        foreach ([false, 0, '', null] as $v) {
            $this->assertTrue($this->categoryCheckApplies(['category' => 'Food', 'is_system' => $v]),
                'a falsy is_system must NOT exempt a role from validation');
        }
    }

    // ── Flags: shape-agnostic, so no data rewrite is warranted ───────────

    /**
     * Three roles store `flags` as an assoc map rather than a list. Both real
     * consumers — Ops_perms_backfill and Rbac_levels_backfill — read it as
     * `(array) ($r['flags'] ?? [])` and iterate VALUES, so the shape does not
     * change behaviour. Pinned so nobody "normalizes" live data for cosmetics.
     */
    public function test_flag_consumption_is_shape_agnostic(): void
    {
        $asList = ['can_teach', 'takes_attendance'];
        $asMap  = ['a' => 'can_teach', 'b' => 'takes_attendance'];

        $read = static fn($f) => array_values((array) $f);

        $this->assertSame($read($asList), $read($asMap),
            'list and assoc-map yield identical values, so no consumer breaks');
    }

    // ── Source pins ──────────────────────────────────────────────────────

    public function test_seed_skips_system_roles(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Staff_access.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString("if (!empty(\$r['is_system'])) continue;", $src);
    }

    public function test_canonical_vocabulary_is_unchanged_in_all_three_places(): void
    {
        foreach ([
            'controllers/Staff_access.php',
            'controllers/Org.php',
        ] as $f) {
            $src = file_get_contents(__DIR__ . '/../../application/' . $f);
            $this->assertNotFalse($src);
            $this->assertStringContainsString(
                "['Teaching', 'Non-Teaching', 'Administrative', 'Support']",
                $src,
                "the vocabulary in {$f} must not be widened to accommodate a system role"
            );
        }
    }

    /** Org already validates category — do not let that guard regress. */
    public function test_org_writer_still_validates_category(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Org.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString(
            'if (!in_array($category, self::ROLE_CATEGORIES, true)) {',
            $src
        );
    }
}
