<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * STAFF-D1 — the Staff Role picker dead-ended silently.
 *
 * Roles in the new-staff wizard are scoped to the chosen department:
 * hr/get_departments returns a role_ids[] per department, and
 * allowedRoleIds() resolves the picker's options from it.
 *
 * A department with NO role_ids mapped returned [] and fell straight through
 * to the normal branch, rendering just "+ Add role..." with nothing under it.
 * Staff Role is REQUIRED, so the operator was blocked on a control that gave
 * no reason and no route forward — it simply looked broken.
 *
 * Hit for real: the tenant's teachers sit in a custom "Academic" department
 * with role_ids = [], while the system "Teaching" department carries
 * ROLE_TEACHER/ROLE_CLASS_TEACHER and has no staff. Converting a candidate
 * prefills the department from the job posting, so the wizard opened straight
 * onto the empty case.
 *
 * The empty state must now explain itself and name where to fix it.
 */
final class StaffRolePickerEmptyStateTest extends TestCase
{
    private string $fn;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/views/new_staff.php';
        $this->assertFileExists($p);
        $src = (string) file_get_contents($p);

        $i = strpos($src, 'function refreshRoleOptions()');
        $this->assertNotFalse($i, 'refreshRoleOptions() is gone');
        $end = strpos($src, "\n    }", $i);
        $this->assertNotFalse($end);
        $this->fn = substr($src, $i, $end - $i);
    }

    /** DEFECT STAFF-D1 — zero mapped roles must be an explained state. */
    public function test_an_unmapped_department_explains_itself(): void
    {
        $this->assertMatchesRegularExpression(
            '~if\s*\(\s*!allowed\.length\s*\)\s*\{~', $this->fn,
            'a department with no mapped roles still falls through to the normal branch');

        $i = strpos($this->fn, '!allowed.length');
        $branch = substr($this->fn, (int) $i, 420);

        $this->assertStringContainsString('No roles mapped to this department', $branch,
            'the empty state must say WHY the picker is empty');
        $this->assertStringContainsString('Departments', $branch,
            'the empty state must name where to fix it');
        $this->assertStringContainsString('return;', $branch,
            'the empty branch must not fall through to the populated branch');
    }

    /**
     * The two dead ends are different and must stay distinguishable:
     * "no department chosen yet" is not "this department has no roles".
     */
    public function test_the_two_empty_states_are_distinct(): void
    {
        $this->assertStringContainsString('Select a department first', $this->fn,
            'the no-department-chosen state was lost');
        $this->assertStringContainsString('No roles mapped to this department', $this->fn);

        $this->assertNotSame(
            strpos($this->fn, 'Select a department first'),
            strpos($this->fn, 'No roles mapped to this department'),
            'the two states collapsed into one message');
    }

    /** A control that cannot be used must not look usable. */
    public function test_the_picker_is_disabled_in_both_dead_ends(): void
    {
        $this->assertSame(2, preg_match_all('~sel\.disabled = true;~', $this->fn),
            'both dead-end branches must disable the picker');
        $this->assertSame(1, preg_match_all('~sel\.disabled = false;~', $this->fn),
            'exactly one branch may enable it — the one that has options');
    }

    /** The happy path must survive: real roles still render. */
    public function test_mapped_roles_are_still_rendered(): void
    {
        $this->assertStringContainsString("sel.innerHTML = '<option value=\"\">+ Add role...</option>'", $this->fn);
        $this->assertMatchesRegularExpression('~allowed\.forEach\(~', $this->fn);
        $this->assertStringContainsString('r.label', $this->fn);
    }
}
