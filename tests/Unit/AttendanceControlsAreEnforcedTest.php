<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Controls that LOOK enforced must BE enforced.
 *
 * This suite exists because of DEV-1: the per-device active/inactive toggle was
 * stored, displayed and offered in the UI, and read by nothing that could act
 * on it — deactivating a reader did nothing at all. An adversarial sweep for
 * the same shape across the rest of Staff Attendance then found two more.
 *
 * The pattern to hunt is always the same: a setting with writers and readers
 * that are ALL storage or display, and no reader at the point where the thing
 * it claims to govern actually happens.
 *
 *   DEV-1  per-device active/inactive          fixed — see AttendanceDeviceLifecycleTest
 *   DEV-2  biometric / RFID / face switches    fixed here
 *   PAY-1  payroll period lock had no unlock   fixed here
 */
final class AttendanceControlsAreEnforcedTest extends TestCase
{
    private static string $att = '';
    private static string $hr  = '';
    private static string $set = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$att = (string) file_get_contents($root . 'application/controllers/Attendance.php');
        self::$hr  = (string) file_get_contents($root . 'application/controllers/Hr.php');
        self::$set = (string) file_get_contents($root . 'application/views/attendance/settings.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = self::$hr = self::$set = '';
    }

    private function methodOf(string $src, string $name): string
    {
        $i = strpos($src, 'function ' . $name);
        $this->assertNotFalse($i, "{$name} must exist");

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos($src, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        $this->assertNotEmpty($next);
        return substr($src, (int) $i, min($next) - (int) $i);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  DEV-2 — the attendance METHOD switches
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. `biometric_enabled`, `rfid_enabled` and
     * `face_recognition_enabled` are offered as switches on the settings
     * screen, validated and stored by save_settings, returned by get_settings,
     * echoed by the dashboard — and the canonical default for each is FALSE, so
     * a school that never configured attendance sees all three switched OFF.
     *
     * `api_punch` read none of them. Every consumer was storage or display.
     * A biometric reader punched whether the switch said on or off, and there
     * was no state in which turning it off changed anything.
     */
    public function test_the_method_switches_are_enforced_at_the_punch(): void
    {
        $punch = $this->methodOf(self::$att, 'api_punch');

        $this->assertStringContainsString('$methodBlock = $this->_punch_method_blocked(', $punch,
            'the punch must consult the method switch');
        $this->assertStringContainsString("403", $punch);

        $gate = $this->methodOf(self::$att, '_punch_method_blocked');
        foreach (['biometric_enabled', 'rfid_enabled', 'face_recognition_enabled'] as $flag) {
            $this->assertStringContainsString($flag, self::$att);
        }
        $this->assertStringContainsString('PUNCH_METHOD_FLAGS', $gate);
    }

    /** The gate refuses BEFORE anything is written. */
    public function test_a_disabled_method_is_refused_before_any_write(): void
    {
        $punch = $this->methodOf(self::$att, 'api_punch');

        $block = strpos($punch, '$methodBlock = $this->_punch_method_blocked(');
        $write = strpos($punch, 'markSingleDay(');

        $this->assertNotFalse($block);
        $this->assertNotFalse($write);
        $this->assertLessThan($write, $block);
    }

    /**
     * A school that has never saved these settings is NOT locked out.
     *
     * The declared default is false, but a school that has never opened the
     * screen never chose it. Refusing there would turn a decorative control
     * into a silent hardware outage, which is not a fix — so an absent
     * settings document allows, and says so in the log.
     */
    public function test_a_never_configured_school_is_not_locked_out(): void
    {
        $gate = $this->methodOf(self::$att, '_punch_method_blocked');

        $this->assertStringContainsString('if (!is_array($doc) || empty($doc)) {', $gate);
        $this->assertStringContainsString('allowed by default', $gate);

        // And an unreadable settings document must not take the readers down.
        $this->assertStringContainsString('unreadable in method gate', $gate);
    }

    /** An unknown device type is not blocked — that would be inventing policy. */
    public function test_an_unknown_device_type_is_not_blocked(): void
    {
        $gate = $this->methodOf(self::$att, '_punch_method_blocked');

        $this->assertStringContainsString("if (\$flag === '') {", $gate);
        $this->assertStringContainsString('return \'\';', $gate);
    }

    /**
     * The DISPLAY must not lie either. Before the fix a never-configured school
     * saw three switches reading OFF while the devices worked; enforcing the
     * flag fixes one direction of that, and this fixes the other.
     */
    public function test_the_screen_says_when_the_switches_are_not_binding(): void
    {
        $get = $this->methodOf(self::$att, 'get_settings');

        $this->assertStringContainsString("'configured' => \$configured", $get);
        $this->assertStringContainsString('methodsNotBinding', self::$set);
        $this->assertStringContainsString('These switches are not in force yet', self::$set);
    }

    /**
     * The banner uses the class the design system actually defines.
     *
     * `.att-alert` is `display:none` until it carries `.show`, so setting an
     * inline display would lose to that rule and the banner would never appear
     * — a warning nobody sees is worse than none, because it reads as absent.
     */
    public function test_the_banner_can_actually_render(): void
    {
        $this->assertStringContainsString(
            "notBinding.classList.toggle('show', r.configured === false);", self::$set);
        $this->assertStringNotContainsString("notBinding.style.display", self::$set);

        $css = (string) file_get_contents(
            __DIR__ . '/../../assets/css/attendance_design_system.css');
        $this->assertStringContainsString('.att-alert.show { display: flex; }', $css,
            'if this rule moves, the banner mechanism must be revisited');
        $this->assertStringContainsString('.att-alert-info', $css);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PAY-1 — the payroll period lock was a one-way door
    // ─────────────────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. `lock_payroll_month()` set locked=true and NOTHING ever set
     * it false — no endpoint, no path. Meanwhile `_check_payroll_lock()`
     * refused generation, regeneration and deletion with the message "Unlock
     * before making changes", instructing the operator to perform an action
     * that did not exist. A locked period was locked forever.
     */
    public function test_a_locked_payroll_period_can_be_reopened(): void
    {
        $this->assertStringContainsString('public function unlock_payroll_month', self::$hr);

        $routes = (string) file_get_contents(__DIR__ . '/../../application/config/routes.php');
        $this->assertStringContainsString("\$route['hr/unlock_payroll_month']", $routes);

        $body = $this->methodOf(self::$hr, 'unlock_payroll_month');
        $this->assertStringContainsString("'locked'        => false,", $body);
    }

    /** The enforcement message is now true — an unlock exists to be performed. */
    public function test_the_enforcement_message_is_no_longer_a_dead_end(): void
    {
        $check = $this->methodOf(self::$hr, '_check_payroll_lock');

        $this->assertStringContainsString('Unlock before making changes.', $check,
            'the message stands only because the action it names now exists');
        $this->assertStringContainsString('public function unlock_payroll_month', self::$hr);
    }

    /**
     * Re-opening a period demands a reason of at least 10 characters — the same
     * bar `unlock_payroll()` already sets for re-opening a single RUN. Derived,
     * not invented.
     */
    public function test_reopening_a_period_requires_a_reason(): void
    {
        $body = $this->methodOf(self::$hr, 'unlock_payroll_month');

        $this->assertStringContainsString("\$reason = trim((string) \$this->input->post('reason'));", $body);
        $this->assertStringContainsString('if (strlen($reason) < 10) {', $body);

        // The precedent it copies.
        $run = $this->methodOf(self::$hr, 'unlock_payroll');
        $this->assertStringContainsString('strlen($reason) < 10', $run);
    }

    /** Re-opening a period that is not locked is refused, not silently "done". */
    public function test_reopening_an_open_period_is_refused(): void
    {
        $body = $this->methodOf(self::$hr, 'unlock_payroll_month');

        $this->assertStringContainsString("if (!is_array(\$lock) || empty(\$lock['locked'])) {", $body);
        $this->assertStringContainsString('is not locked.', $body);
    }

    /** The trail survives: unlock flips a flag, it never deletes the record. */
    public function test_reopening_preserves_the_audit_trail(): void
    {
        $body = $this->methodOf(self::$hr, 'unlock_payroll_month');

        $this->assertStringContainsString('array_merge(is_array($lock) ? $lock : []', $body,
            'the original lock fields must survive, not be replaced');
        $this->assertStringContainsString("'unlocked_by'   => \$this->admin_name,", $body);
        $this->assertStringContainsString("'unlock_reason' => \$reason,", $body);
        $this->assertStringContainsString("_audit_payroll('payroll_month_unlocked'", $body);
    }

    /**
     * Re-opening the PERIOD does not silently reverse anything else.
     * `unlock_payroll()` already warns that accounting books are not reversed
     * by an unlock; doing more here would be inventing a reversal policy.
     */
    public function test_reopening_does_not_reverse_runs_or_books(): void
    {
        $body = $this->methodOf(self::$hr, 'unlock_payroll_month');

        foreach (['salarySlips', 'accounting', "'status' => 'Draft'"] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                'this endpoint re-opens a period; it must not quietly rewrite runs or books');
        }
    }

    /** Both acts are gated on the same capability the lock already required. */
    public function test_the_period_actions_share_one_gate(): void
    {
        $lock   = $this->methodOf(self::$hr, 'lock_payroll_month');
        $unlock = $this->methodOf(self::$hr, 'unlock_payroll_month');

        $this->assertStringContainsString("_require_role(self::ADMIN_ROLES, 'lock_payroll_month', 'HR', 'manage')", $lock);
        $this->assertStringContainsString("_require_role(self::ADMIN_ROLES, 'unlock_payroll_month', 'HR', 'manage')", $unlock);
    }

    // ── The period lock is now reachable ────────────────────────────────

    /** A screen can read, close and re-open a payroll period. */
    public function test_the_period_lock_has_a_ui(): void
    {
        $hrView = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');

        $this->assertStringContainsString("post('hr/payroll_lock_status'", $hrView);
        $this->assertStringContainsString("post('hr/lock_payroll_month'", $hrView);
        $this->assertStringContainsString("post('hr/unlock_payroll_month'", $hrView);

        $this->assertStringContainsString('loadPayrollLocks()', $hrView);
        $this->assertStringContainsString("if(sub==='payrollRuns'", $hrView,
            'the panel must load when its tab is opened, or it renders once and goes stale');
    }

    /** Re-open goes through the panel's own reason dialog. */
    public function test_the_reopen_uses_the_panels_existing_reason_dialog(): void
    {
        $hrView = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');

        $i = strpos($hrView, 'function reopenPayrollMonth');
        $this->assertNotFalse($i);
        $fn = substr($hrView, (int) $i, 2200);

        $this->assertStringContainsString('promptReason({', $fn,
            'the same helper unlock_payroll uses — one dialog, one 10-character bar');
        $this->assertStringContainsString('audit-logged', $fn);
        $this->assertStringContainsString('not</b> changed by this action', $fn,
            'the operator must be told what re-opening does NOT do');
    }

    /** Closing is confirmed and cannot be double-fired. */
    public function test_closing_a_period_is_confirmed_and_guarded(): void
    {
        $hrView = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');

        // UI-D3: the confirmation is now a themed zxConfirm, not the native
        // "localhost says" dialog. Still confirmed, still says what it does.
        $this->assertStringContainsString("title: 'Close payroll for ' + month", $hrView);
        $this->assertStringContainsString("can no longer be generated, regenerated or deleted", $hrView,
            'the operator must be told what closing prevents');
        $this->assertStringContainsString('if (payrollLocksBusy) return;', $hrView);
    }

    /** The manage-only controls are hidden from a viewer and enforced anyway. */
    public function test_the_controls_are_gated_and_enforced(): void
    {
        $hrView = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');

        $this->assertStringContainsString("\$('#payrollCloseRow').toggle(canManage === true);", $hrView);
        $this->assertStringContainsString('needs HR manage access', $hrView);

        $status = $this->methodOf(self::$hr, 'payroll_lock_status');
        $this->assertStringContainsString("has_permission('HR', 'manage')", $status);
    }

    /** Reading every period costs ONE document read. */
    public function test_reading_period_locks_costs_one_read(): void
    {
        $status = $this->methodOf(self::$hr, 'payroll_lock_status');

        $this->assertSame(1, substr_count($status, '$this->fs->get('),
            'every lock lives in one map on the school profile');
        $this->assertStringContainsString("\$this->fs->docId('profile')", $status,
            'tenant-scoped by docId(), like every other read of this document');
    }
}
