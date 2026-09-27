<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Support\StaffMenu;

/**
 * Staff navigation, consolidated by the employment lifecycle.
 *
 * Requested by the operator 2026-08-23: "the staff features are here and there".
 * They were — four separate top-level entries, so an administrator had to know
 * which of the four held the thing they wanted:
 *
 *     Staff & Roles      (single)
 *     Staff Records      (treeview)
 *     HR & Payroll       (treeview)
 *     Staff Attendance   (single)
 *
 * Now one "Staff & HR" menu ordered hire → record → schedule → attend → pay →
 * appraise.
 *
 * THE RISK THIS SUITE GUARDS. Those four blocks had FOUR DIFFERENT permission
 * gates. Merging them under one condition would have silently removed access —
 * somebody with Attendance but not HR would have lost Staff Attendance. So each
 * item keeps its own gate, and the tests below render the menu under different
 * capability sets to prove it.
 */
final class StaffSidebarConsolidationTest extends TestCase
{
    private static string $header = '';

    public static function setUpBeforeClass(): void
    {
        self::$header = (string) file_get_contents(
            __DIR__ . '/../../application/views/include/header.php'
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$header = '';
    }

    /**
     * Render the staff block with a given capability set and return its links.
     *
     * This EXECUTES the template rather than pattern-matching it, because the
     * property under test is what a user actually sees. The renderer is shared
     * with the other two nav suites so the extraction lives in one place.
     */
    private function render(array $caps, array $features = ['Staff Management']): array
    {
        return StaffMenu::render($caps, $features);
    }

    // ── Every link survived ──────────────────────────────────────────────

    /** Nothing was dropped in the move. */
    public function test_every_staff_link_still_exists(): void
    {
        foreach ([
            'hr', 'hr/recruitment', 'ats', 'staff/all_staff', 'staff/new_staff',
            'staff_access', 'work_schedules', 'attendance/staff', 'hr/leaves',
            'hr/payroll', 'compensation', 'hr/appraisals',
        ] as $url) {
            $this->assertSame(1, substr_count(self::$header, "base_url('{$url}')"),
                "{$url} must appear exactly once — consolidation must not drop or duplicate a link");
        }
    }

    /** A full-capability user sees all twelve. */
    public function test_a_full_capability_user_sees_everything(): void
    {
        $links = $this->render(['HR', 'SIS', 'Attendance', 'Admin Users']);

        $this->assertCount(12, $links, implode(', ', $links));
    }

    // ── Each gate preserved exactly ──────────────────────────────────────

    /**
     * THE ONE THAT MATTERS MOST. Attendance-only access still reaches Staff
     * Attendance — and nothing else. A merged gate would have hidden it.
     */
    public function test_attendance_only_still_reaches_staff_attendance(): void
    {
        $this->assertSame(['attendance/staff'], $this->render(['Attendance']));
    }

    /** SIS-only still reaches the staff records, because that gate is HR OR SIS. */
    public function test_sis_only_still_reaches_staff_records(): void
    {
        $this->assertSame(['staff/all_staff', 'staff/new_staff'], $this->render(['SIS']));
    }

    /** HR-only sees the HR items and the records, but not roles or attendance. */
    public function test_hr_only_sees_hr_and_records_but_not_roles(): void
    {
        $links = $this->render(['HR']);

        $this->assertContains('hr/payroll', $links);
        $this->assertContains('compensation', $links);
        $this->assertContains('staff/all_staff', $links);
        $this->assertNotContains('staff_access', $links, 'roles need Admin Users');
        $this->assertNotContains('attendance/staff', $links, 'staff attendance needs Attendance');
    }

    /** Roles appear only with Admin Users AND the outer staff gate. */
    public function test_roles_need_both_admin_users_and_the_outer_gate(): void
    {
        $this->assertSame([], $this->render(['Admin Users']),
            'Admin Users alone never showed this menu, and must still not');

        $this->assertContains('staff_access', $this->render(['Admin Users', 'HR']));
    }

    /** No capabilities, no menu. */
    public function test_no_capabilities_hides_the_menu(): void
    {
        $this->assertSame([], $this->render([]));
    }

    /** The Staff Management feature flag still gates the whole thing. */
    public function test_the_feature_flag_still_gates_the_menu(): void
    {
        $this->assertSame([], $this->render(['HR', 'SIS', 'Attendance', 'Admin Users'], []),
            'a school without Staff Management must see no staff menu at all');
    }

    // ── Lifecycle ordering ───────────────────────────────────────────────

    /**
     * The menu reads in the order an employment actually runs. Ordering is the
     * whole point of the consolidation — a list of the same links in arbitrary
     * order would not have solved anything.
     */
    public function test_the_menu_is_ordered_by_the_employment_lifecycle(): void
    {
        $links = $this->render(['HR', 'SIS', 'Attendance', 'Admin Users']);
        $pos = array_flip($links);

        // hire → record → schedule → attend → pay → appraise
        $this->assertLessThan($pos['staff/all_staff'], $pos['hr/recruitment'], 'hire before record');
        $this->assertLessThan($pos['work_schedules'], $pos['staff_access'],    'record before schedule');
        $this->assertLessThan($pos['attendance/staff'], $pos['work_schedules'], 'schedule before attend');
        $this->assertLessThan($pos['hr/payroll'], $pos['attendance/staff'],    'attend before pay');
        $this->assertLessThan($pos['hr/appraisals'], $pos['hr/payroll'],       'pay before appraise');
    }

    /** Staff attendance is NOT inside the student attendance menu. */
    public function test_staff_attendance_is_not_merged_into_student_attendance(): void
    {
        $studentMenu = strpos(self::$header, "base_url('attendance/mark')");
        $staffLink   = strpos(self::$header, "base_url('attendance/staff')");

        $this->assertNotFalse($studentMenu);
        $this->assertNotFalse($staffLink);
        $this->assertLessThan($studentMenu, $staffLink,
            'staff and student attendance are separate systems and must not be visually merged');
    }

    // ── The dead Departments tab ─────────────────────────────────────────

    /** The HR page no longer offers a Departments tab. */
    public function test_the_hr_departments_tab_is_gone(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');
        $ctrl = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringNotContainsString("'departments' => ['panel'=>'panelDept'", $view,
            'the tab was a placeholder reading "Departments have moved"');
        $this->assertStringNotContainsString('panelDept', $view);
        $this->assertStringNotContainsString('Departments have moved', $view);

        $this->assertStringNotContainsString(
            "\$allowed = ['dashboard', 'departments', 'recruitment'", $ctrl,
            'the controller must no longer accept the tab');
    }

    /**
     * An old `/hr/departments` link REDIRECTS to where departments actually
     * live, rather than silently landing on the dashboard.
     */
    public function test_an_old_departments_link_redirects_rather_than_falling_through(): void
    {
        $ctrl = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringContainsString("if (\$tab === 'departments') {", $ctrl);
        $this->assertStringContainsString("redirect('staff_access');", $ctrl);
    }

    /**
     * The department READER stays live — five views use it for their dropdowns.
     * Removing dead UI must not remove a live endpoint.
     */
    public function test_the_department_reader_endpoint_is_untouched(): void
    {
        $ctrl = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringContainsString('function get_departments', $ctrl,
            'edit_staff, new_staff, teacher_profile and two HR tabs read this');
    }
}
