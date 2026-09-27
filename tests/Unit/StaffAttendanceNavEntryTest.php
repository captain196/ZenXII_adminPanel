<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\StaffMenu;

/**
 * Admin sidebar — Staff Attendance nav entry. Closes a gap found during the
 * Staff Lifecycle completeness audit: Attendance::staff_attendance() (routes
 * + view already existed, gated Attendance/view) had no sidebar link
 * anywhere — reachable only by direct URL. Added as its OWN link under the
 * "Staff" section, deliberately NOT nested inside the Student "Attendance"
 * treeview — Staff Attendance and Student Attendance are separate systems
 * per this codebase's own convention and must not be visually merged.
 *
 * Source-regression test (a sidebar partial has no behavioral logic to test
 * beyond string/structure presence).
 */
class StaffAttendanceNavEntryTest extends TestCase
{
    private string $headerSrc;

    protected function setUp(): void
    {
        $this->headerSrc = file_get_contents(__DIR__ . '/../../application/views/include/header.php');
        $this->assertNotFalse($this->headerSrc);
    }

    public function test_staff_attendance_link_present(): void
    {
        $this->assertStringContainsString("base_url('attendance/staff')", $this->headerSrc);
        $this->assertStringContainsString('Staff Attendance', $this->headerSrc);
    }

    /**
     * The link appears for exactly the capability the controller requires.
     *
     * Asserted by RENDERING the menu rather than by looking for a gate
     * expression sitting near the link: the 2026-08-23 consolidation lifted the
     * gates into named variables, which broke the old proximity scan while
     * changing nobody's access. Who sees the link is what matters.
     */
    public function test_gated_same_as_controller_attendance_view_capability(): void
    {
        $link = 'attendance/staff';

        $this->assertTrue(StaffMenu::reaches($link, ['Attendance']),
            'Attendance access must reach staff attendance — the controller allows it');

        $this->assertFalse(StaffMenu::reaches($link, ['HR', 'SIS', 'Admin Users']),
            'without Attendance the controller refuses, so the link must not show');

        $this->assertFalse(StaffMenu::reaches($link, ['Attendance'], []),
            'the Staff Management feature flag still gates it');
    }

    public function test_not_nested_inside_the_student_attendance_treeview(): void
    {
        $staffAttPos   = strpos($this->headerSrc, "base_url('attendance/staff')");
        $studentAttPos = strpos($this->headerSrc, "base_url('attendance/mark')");
        $this->assertNotFalse($staffAttPos);
        $this->assertNotFalse($studentAttPos);
        // The staff link must appear as its own sidebar-single, well before
        // the student Attendance treeview's own <ul> of sub-items begins.
        $this->assertLessThan($studentAttPos, $staffAttPos,
            'Staff Attendance must be a separate top-level link, not merged into the Student Attendance treeview.');
        $between = substr($this->headerSrc, $staffAttPos, $studentAttPos - $staffAttPos);
        $this->assertStringNotContainsString('treeview-menu', substr($this->headerSrc, $staffAttPos - 50, 80),
            'Staff Attendance link must not be inside the Student Attendance <ul class="treeview-menu">.');
    }

    /**
     * ONE door to the staff register.
     *
     * REPORTED BY THE OPERATOR 2026-08-23 with a screenshot of
     * /attendance/mark#staff: the Mark Attendance shell carried a "Staff
     * Attendance" tab that embedded /attendance/staff — the same page the
     * sidebar opens under Staff & HR. One register, two doors.
     *
     * My first pass missed it because I checked only the sidebar's Attendance
     * treeview and concluded nothing was merged. The duplication was a tab
     * INSIDE the page, which no sidebar assertion could ever have seen. Hence
     * this test looks at the shell's own tab list.
     */
    public function test_the_mark_shell_does_not_also_embed_the_staff_register(): void
    {
        $ctrl = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');

        $i = strpos($ctrl, "'title'    => 'Mark Attendance'");
        $this->assertNotFalse($i, 'the Mark shell must still exist');

        $end = strpos($ctrl, '];', $i);
        $this->assertNotFalse($end);
        $shell = substr($ctrl, $i, $end - $i);

        $this->assertStringNotContainsString("base_url('attendance/staff')", $shell,
            'the staff register must have exactly one door — the Staff & HR menu');
        $this->assertStringNotContainsString("'key' => 'staff'", $shell);

        // …and the student register is still there, so the tab was removed
        // rather than the whole shell being emptied.
        $this->assertStringContainsString("'key' => 'student'", $shell);
    }

    /**
     * Nothing links at the tab that no longer exists.
     *
     * The Attendance dashboard's "Staff Present Today" card pointed at
     * /attendance/mark#staff. Left alone it would have silently opened the
     * STUDENT register — a card whose number says staff and whose click says
     * students.
     */
    public function test_nothing_still_links_at_the_removed_tab(): void
    {
        foreach (['application/controllers', 'application/views'] as $dir) {
            $root = __DIR__ . '/../../' . $dir;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $f) {
                if ($f->getExtension() !== 'php') { continue; }
                $src = (string) file_get_contents($f->getPathname());

                // Strip comments so this file's own explanatory note, and the
                // controller's, don't trip the scan.
                $code = '';
                foreach (token_get_all($src) as $tok) {
                    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                    $code .= is_array($tok) ? $tok[1] : $tok;
                }

                $this->assertStringNotContainsString("attendance/mark') ?>#staff", $code,
                    $f->getFilename() . ' links at the removed staff tab');
            }
        }
    }

    /** The dashboard card opens the staff register itself. */
    public function test_the_staff_kpi_card_opens_the_staff_register(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/attendance/index.php'
        );

        $i = strpos($view, 'Open the staff register');
        $this->assertNotFalse($i, 'the Staff Present Today card must still be clickable');

        $card = substr($view, max(0, $i - 200), 260);
        $this->assertStringContainsString("base_url('attendance/staff')", $card);
    }
}