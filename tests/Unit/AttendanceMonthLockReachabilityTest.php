<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Can an administrator actually reach the month locks?
 *
 * BOTH GAPS ARE NOW CLOSED. This file keeps asking the reachability question,
 * because "endpoint shipped, UI never did" is how both of them arose.
 *
 *   GAP-1  (staff attendance month lock) — closed 2026-08-23. The endpoints
 *          existed, routed and permission-gated, with no caller anywhere. The
 *          staff register is now the caller.
 *
 *   GAP-1b (payroll period lock) — closed 2026-08-23. Worse than GAP-1: not
 *          only did `lock_payroll_month()` have no caller, there was no UNLOCK
 *          AT ALL — no endpoint, no path — while `_check_payroll_lock()`
 *          refused generation, regeneration and deletion with the message
 *          "Unlock before making changes". A locked period was locked forever
 *          and the operator was told to do something impossible. That defect is
 *          tracked as PAY-1.
 *
 * What each screen must DO is pinned elsewhere; this file only asserts that the
 * endpoints have callers at all.
 *
 * @see AttendanceMonthLockUiTest             the attendance lock screen
 * @see AttendanceControlsAreEnforcedTest     PAY-1 and the payroll period screen
 */
final class AttendanceMonthLockReachabilityTest extends TestCase
{
    /** Every view and asset file that could plausibly call an endpoint. */
    private function frontEndSources(): string
    {
        $roots = [
            __DIR__ . '/../../application/views',
            __DIR__ . '/../../assets',
        ];

        $all = '';
        foreach ($roots as $root) {
            if (!is_dir($root)) { continue; }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if (!in_array($f->getExtension(), ['php', 'js', 'html'], true)) { continue; }
                $all .= (string) file_get_contents($f->getPathname());
            }
        }

        $this->assertNotSame('', $all, 'no front-end sources were read — the scan proves nothing');
        return $all;
    }

    /** The attendance lock endpoints exist and are wired up. */
    public function test_the_attendance_lock_endpoints_exist_and_are_routed(): void
    {
        $att    = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');

        $this->assertStringContainsString('public function lock_staff_attendance', $att);
        $this->assertStringContainsString('public function unlock_staff_attendance', $att);
        $this->assertStringContainsString("\$route['attendance/lock_staff_attendance']", $routes);
        $this->assertStringContainsString("\$route['attendance/unlock_staff_attendance']", $routes);

        $this->assertStringContainsString("firestoreSet('staffAttendanceLocks'", $att,
            'the endpoint must still write the lock the writer checks');
    }

    /** And the writer enforces that lock. */
    public function test_the_writer_enforces_the_attendance_lock(): void
    {
        $writer = (string) file_get_contents(__DIR__ . '/../../application/libraries/Staff_attendance_writer.php');

        $this->assertStringContainsString('is_locked($this->schoolId, $this->sessionYear, $monthKey)', $writer);
        $this->assertStringContainsString('throw new MonthLockedException', $writer);
    }

    /** GAP-1 closed — a screen reaches all three attendance lock endpoints. */
    public function test_a_screen_can_lock_and_unlock_an_attendance_month(): void
    {
        $ui = $this->frontEndSources();

        $this->assertStringContainsString('attendance/lock_staff_attendance', $ui);
        $this->assertStringContainsString('attendance/unlock_staff_attendance', $ui);
        $this->assertStringContainsString('attendance/staff_lock_status', $ui,
            'lock state must be readable by a screen, or the locks are invisible again');
    }

    /**
     * GAP-1b closed — the payroll period lock has both a counterpart and a UI.
     *
     * The unlock assertion is the important one: a lock with no unlock is not a
     * control, it is a trapdoor.
     */
    public function test_a_screen_can_close_and_reopen_a_payroll_period(): void
    {
        $hr     = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $ui     = $this->frontEndSources();

        $this->assertStringContainsString('public function unlock_payroll_month', $hr,
            'a lock with no unlock leaves the period closed forever');
        $this->assertStringContainsString("\$route['hr/unlock_payroll_month']", $routes);

        $this->assertStringContainsString('hr/lock_payroll_month', $ui);
        $this->assertStringContainsString('hr/unlock_payroll_month', $ui);
        $this->assertStringContainsString('hr/payroll_lock_status', $ui);
    }

    /**
     * The two locks remain SEPARATE systems, and each is reachable on its own.
     * Closing payroll does not close the register, which is exactly why the
     * regularization path checks the payroll lock explicitly.
     */
    public function test_the_two_locks_stay_independent(): void
    {
        $hr  = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $att = (string) file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');

        $this->assertStringNotContainsString('staffAttendanceLocks', $hr,
            'if payroll ever starts writing the attendance lock, the explicit guard '
            . 'in the regularization path should be revisited');
        $this->assertStringContainsString('private function _payroll_month_locked', $att,
            'so the regularization path reads the payroll lock directly');
    }
}
