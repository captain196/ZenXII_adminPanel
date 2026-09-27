<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Staff Lifecycle: is every routed surface actually reachable?
 *
 * This programme has now found the SAME defect shape five times — substantial,
 * correct, carefully-hardened server code with no way into it:
 *
 *   DEV-1    device active/inactive toggle, stored and never enforced
 *   DEV-2    biometric / RFID / face switches, stored and never read
 *   GAP-1    staff attendance month lock, no screen called it
 *   PAY-1    payroll period lock, no screen AND no unlock at all
 *   LEAVE-1  leave cancellation, hardened four times over, no caller
 *
 * Every one was found by asking the same question, and every one was invisible
 * to the rest of the suite, because unit tests exercise the code that exists
 * rather than the paths a user can travel. This file asks the question
 * automatically so the sixth is caught by CI instead of by an audit.
 *
 * It is deliberately narrow: it pins the endpoints whose unreachability would
 * cost a school something real. A broad "every route must be linked" assertion
 * would fail on app-only APIs and deep-link tab routes, and a test that cries
 * wolf gets deleted.
 */
final class StaffLifecycleReachabilityTest extends TestCase
{
    /** Every view + asset, concatenated: the "is anything calling this" corpus. */
    private static string $ui = '';

    public static function setUpBeforeClass(): void
    {
        $all = '';
        foreach (['application/views', 'assets'] as $rel) {
            $root = __DIR__ . '/../../' . $rel;
            if (!is_dir($root)) { continue; }

            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if (!in_array($f->getExtension(), ['php', 'js', 'html'], true)) { continue; }
                $all .= (string) file_get_contents($f->getPathname());
            }
        }
        self::$ui = $all;
    }

    public static function tearDownAfterClass(): void
    {
        self::$ui = '';
    }

    /** The scan must actually be reading something. */
    public function test_the_scan_reads_the_front_end(): void
    {
        $this->assertGreaterThan(500000, strlen(self::$ui),
            'the corpus is too small to be the real front end — this scan would pass vacuously');

        // Control group: an endpoint that IS wired, so an empty result later is
        // a real absence rather than a broken scan.
        $this->assertStringContainsString('hr/get_leave_requests', self::$ui);
    }

    /**
     * Actions a school must be able to perform without a developer.
     *
     * Each entry is here because its absence costs something concrete: a day
     * not returned to someone's balance, a month that cannot be closed, a
     * period that cannot be re-opened, a reader that cannot be revoked.
     *
     * @return array<string, string>  endpoint => what a school loses without it
     */
    public static function operableActions(): array
    {
        return [
            // LEAVE-1 — restores the employee's balance.
            'hr/cancel_leave'                    => 'cancel a leave and give the days back',

            // GAP-1 — the control that protects finalised payroll.
            'attendance/lock_staff_attendance'   => 'close an attendance month',
            'attendance/unlock_staff_attendance' => 're-open an attendance month',
            'attendance/staff_lock_status'       => 'see which months are closed',

            // PAY-1 — a lock with no unlock is a trapdoor.
            'hr/lock_payroll_month'              => 'close a payroll period',
            'hr/unlock_payroll_month'            => 're-open a payroll period',
            'hr/payroll_lock_status'             => 'see which periods are closed',

            // The regularization queue.
            'attendance/staff_regularization/list'   => 'see attendance requests',
            'attendance/staff_regularization/decide' => 'decide an attendance request',

            // Per-school notification control.
            'school_config/get_notification_settings' => 'see which alerts this school sends',
            'school_config/save_notification_setting' => 'switch an alert off',

            // Leave decisions. NOTE: hr/approve_leave and hr/reject_leave are also
            // routed but SUPERSEDED — the panel posts both outcomes through one
            // decide endpoint. Listing the legacy pair here would assert a
            // reachability the product deliberately does not need.
            'hr/decide_leave'                    => 'approve or reject a leave',
        ];
    }

    /**
     * Every action above is reachable from a screen.
     *
     * Written as one test rather than a data-provider case per row so the
     * failure message lists everything that regressed at once — a school losing
     * three abilities should not need three runs to discover it.
     */
    public function test_every_operable_action_has_a_caller(): void
    {
        $unreachable = [];

        foreach (self::operableActions() as $endpoint => $whatIsLost) {
            if (strpos(self::$ui, $endpoint) === false) {
                $unreachable[] = "{$endpoint} — a school cannot {$whatIsLost}";
            }
        }

        $this->assertSame([], $unreachable,
            "These endpoints exist, are routed and are permission-gated, but NO screen "
            . "calls them. That is the defect shape this file exists to catch:\n  - "
            . implode("\n  - ", $unreachable));
    }

    /**
     * LEAVE-1 specifically: cancellation is offered for an APPROVED leave, not
     * only a pending one.
     *
     * A cancel button that appears only while pending would leave the balance
     * restoration — the whole reason the endpoint is hardened — unreachable in
     * the case that actually matters.
     */
    public function test_leave_cancellation_covers_approved_leave(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');

        $this->assertStringContainsString("if(_st==='pending'||_st==='approved'){", $view,
            'an approved leave is exactly the case where the balance needs returning');
        $this->assertStringContainsString('HR.cancelLeave(', $view);

        // And the server agrees on that boundary — the UI must mirror it, not
        // widen it.
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringContainsString(
            "if (!in_array(\$currentStatus, ['pending', 'approved'], true)) {", $hr);
    }

    /** Cancelling is confirmed, and the confirmation says the balance moves. */
    public function test_leave_cancellation_is_confirmed_and_explains_itself(): void
    {
        $view = (string) file_get_contents(__DIR__ . '/../../application/views/hr/index.php');

        $i = strpos($view, 'function cancelLeave(');
        $this->assertNotFalse($i);
        $fn = substr($view, (int) $i, 1400);

        // UI-D3: themed dialog rather than the native confirm().
        $this->assertStringContainsString('zxConfirm(', $fn);
        $this->assertStringContainsString('added back to', $fn,
            'the operator must know the balance moves before they click');
        $this->assertStringContainsString('if(_cancellingLeave) return;', $fn);
        $this->assertStringContainsString('loadLeaveRequests();', $fn,
            'the new state must be re-read, never assumed');
    }

    /**
     * The server remains the authority. The UI guard against a double-click is
     * a courtesy; the endpoint's own idempotency is what prevents restoring a
     * balance twice.
     */
    public function test_the_server_still_owns_the_safety(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $i  = strpos($hr, 'public function cancel_leave');
        $this->assertNotFalse($i);
        $body = substr($hr, (int) $i, 4000);

        $this->assertStringContainsString("in_array(\$currentStatus, ['cancelled', 'cancelling'], true)", $body,
            'idempotency: a repeated call must never restore the balance twice');
        $this->assertStringContainsString("!== (string) \$this->school_id", $body,
            'cross-tenant IDOR guard, before any mutation');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Cross-module: tenant isolation on staff-lifecycle data
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Staff-lifecycle collections are read by MANY modules — Events, Fees,
     * Notices, PTM, payroll reconciliation and Attendance all query `staff` or
     * one of its satellites. Each is a separate opportunity to forget the
     * tenant predicate, and forgetting it once puts one school's employees on
     * another school's screen.
     *
     * `schoolWhere()` adds the predicate automatically. A RAW `firestoreQuery`
     * does not, so every raw call must carry `schoolId` itself.
     *
     * Verifier / UAT / probe controllers and the platform console are excluded
     * deliberately: they look across tenants by design and are not user
     * surfaces.
     */
    public function test_no_user_surface_queries_staff_data_without_a_tenant_predicate(): void
    {
        $collections = [
            'staff', 'staffAttendance', 'staffAttendanceSummary', 'staffAttendanceLocks',
            'attendanceRegularizations', 'leaveApplications', 'salarySlips',
            'staffSeparations', 'hrApplicants', 'attendanceDevices', 'attendanceDeviceKeys',
        ];

        $unscoped = [];
        $checked  = 0;

        foreach (glob(__DIR__ . '/../../application/controllers/*.php') as $file) {
            $name = basename($file);

            if (preg_match('~_verify\.php$|_uat\.php$|^Superadmin|_probe|verifier~i', $name)) {
                continue;
            }

            $src = (string) file_get_contents($file);
            $off = 0;

            while (($i = strpos($src, 'firestoreQuery(', $off)) !== false) {
                $off   = $i + 15;
                $chunk = substr($src, $i, 320);

                if (!preg_match('~firestoreQuery\(\s*.([A-Za-z]+).~', $chunk, $m)) {
                    continue;
                }
                if (!in_array($m[1], $collections, true)) {
                    continue;
                }

                $checked++;
                if (strpos($chunk, 'schoolId') === false && strpos($chunk, 'school_id') === false) {
                    $unscoped[] = $name . " → firestoreQuery('" . $m[1] . "') with no tenant predicate";
                }
            }
        }

        // The scan must have examined something, or it proves nothing.
        $this->assertGreaterThan(0, $checked,
            'no raw staff-data queries were examined — this scan would pass vacuously');

        $this->assertSame([], $unscoped,
            "A raw Firestore query on staff-lifecycle data with no tenant predicate puts "
            . "one school's people on another school's screen:\n  - "
            . implode("\n  - ", $unscoped));
    }
}
