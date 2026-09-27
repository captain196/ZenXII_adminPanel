<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regularization policy: tenant-configurable, and the decision notification.
 *
 * BUILT FROM PRIMARY VENDOR DOCUMENTATION (fetched 2026-08-23), not from
 * recollection, and deliberately WITHOUT importing anybody's numbers:
 *
 *   • Asanify — "Validation Rules" under the Attendance tab restrict
 *     regularization requests by the "Sum or Count of days" over a period
 *     (their example: 4 days per month), with a CUSTOMIZABLE rejection message
 *     shown when the rule fails.
 *     help.asanify.com/en/articles/11776316
 *
 *   • Pocket HRMS — the employee is notified of the outcome, as
 *     "Regularization Accepted" / "Regularization Rejected".
 *     docs.pockethrms.com/ess-portal/employee/leave-and-attendance/attendance-regularization
 *
 * THE CEILING ITSELF IS NOT SHIPPED. Asanify's "4 days per month" is Asanify's
 * example, and a school's tolerance for missed punches is that school's policy.
 * So the setting ships at 0 — no limit — which is precisely how the queue
 * behaved before it existed, and a school sets its own number in the panel.
 * These tests exist partly to keep it that way.
 */
final class RegularizationPolicyConfigTest extends TestCase
{
    private static string $att  = '';
    private static string $set  = '';
    private static string $view = '';
    private static string $fns  = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$att  = (string) file_get_contents($root . 'application/controllers/Attendance.php');
        self::$set  = (string) file_get_contents($root . 'application/views/attendance/settings.php');
        self::$view = (string) file_get_contents($root . 'application/views/attendance/staff_regularization.php');
        self::$fns  = (string) file_get_contents($root . 'functions/index.js');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = self::$set = self::$view = self::$fns = '';
    }

    private function methodOf(string $name): string
    {
        $i = strpos(self::$att, 'function ' . $name);
        $this->assertNotFalse($i, "{$name} must exist");

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos(self::$att, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        $this->assertNotEmpty($next);
        return substr(self::$att, (int) $i, min($next) - (int) $i);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  The ceiling is the SCHOOL's, and ships off
    // ─────────────────────────────────────────────────────────────────────

    /**
     * THE MOST IMPORTANT TEST IN THIS FILE. No ceiling ships. A default of any
     * other number would put one organisation's attendance policy into every
     * tenant, and a school that configures nothing must behave exactly as it did
     * before this setting existed.
     */
    public function test_no_limit_ships_by_default(): void
    {
        $this->assertStringContainsString(
            "'regularization'           => ['maxDaysPerMonth' => 0, 'limitMessage' => ''],", self::$att,
            'the shipped default must be NO ceiling');

        $resolver = $this->methodOf('_regularization_policy');
        $this->assertStringContainsString("(int) (\$raw['maxDaysPerMonth'] ?? 0)", $resolver);

        $check = $this->methodOf('_regularization_over_limit');
        $this->assertStringContainsString("if (\$pol['maxDaysPerMonth'] <= 0) {", $check);
        $this->assertStringContainsString("return '';", $check,
            'zero must mean no ceiling, not a ceiling of zero');
    }

    /** The number a school picks is honoured, and bounded to something sane. */
    public function test_the_school_sets_its_own_number(): void
    {
        $save = $this->methodOf('save_settings');

        $this->assertStringContainsString("\$regMax = \$this->input->post('reg_max_days_per_month');", $save);
        $this->assertStringContainsString('if ($max < 0)  $max = 0;', $save);
        $this->assertStringContainsString('if ($max > 31) $max = 31;', $save,
            'a month cannot have more days than this; an absurd value would freeze the queue');
    }

    /**
     * The ceiling counts APPROVED days, not submitted ones. Counting pending
     * requests would make the ceiling depend on the order a reviewer happens to
     * work the queue in.
     */
    public function test_the_ceiling_counts_approved_days_only(): void
    {
        $check = $this->methodOf('_regularization_over_limit');

        $this->assertStringContainsString("['status',     '==', 'approved'],", $check);
        $this->assertStringContainsString("['staffId',    '==', \$staffId],", $check);
        $this->assertStringContainsString("['personType', '==', 'staff'],", $check);
        $this->assertStringContainsString("substr(\$date, 0, 7)", $check,
            'the window is the request\'s own month');
    }

    /** It is tenant-scoped — a ceiling must never count another school's days. */
    public function test_the_count_is_tenant_scoped(): void
    {
        $check = $this->methodOf('_regularization_over_limit');

        $this->assertStringContainsString("schoolWhere('attendanceRegularizations'", $check);
        $this->assertStringNotContainsString('firestoreQuery(', $check);
    }

    /**
     * An administrative ceiling that cannot be counted must not block the queue.
     * This is a school's own policy control, not a safety one — unlike the
     * payroll lock, which deliberately fails closed.
     */
    public function test_an_uncountable_ceiling_does_not_block_the_queue(): void
    {
        $check = $this->methodOf('_regularization_over_limit');

        $i = strpos($check, 'catch (\Throwable $e)');
        $this->assertNotFalse($i);
        $catch = substr($check, (int) $i, 320);
        $this->assertStringContainsString("return '';", $catch);

        // Contrast: the payroll guard fails CLOSED, on purpose.
        $pay = $this->methodOf('_payroll_month_locked');
        $this->assertStringContainsString("return 'payroll_lock_unreadable';", $pay,
            'the two guards fail in opposite directions, deliberately');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  The school's own wording reaches the reviewer
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Asanify makes the failure message customisable for a good reason: the
     * useful sentence ("refer to the HOD") is organisation-specific. A generic
     * refusal leaves the reviewer with no next step.
     */
    public function test_the_schools_own_message_travels_with_the_refusal(): void
    {
        $body = $this->methodOf('staff_regularization_decide');

        $this->assertStringContainsString("'message' => \$pol['limitMessage'],", $body);
        $this->assertStringContainsString(
            "if (sk && sk.message) { out.push(esc(sk.message)); return; }", self::$view,
            'a server-supplied message must win over the generic code map');
    }

    /** And it is escaped — it is operator-supplied text rendered into HTML. */
    public function test_the_message_is_escaped(): void
    {
        $i = strpos(self::$view, 'if (sk && sk.message)');
        $this->assertNotFalse($i);
        $line = substr(self::$view, (int) $i, 90);

        $this->assertStringContainsString('esc(sk.message)', $line,
            'tenant-authored text reaching innerHTML unescaped is stored XSS');
    }

    /** There is still a fallback sentence when no message is configured. */
    public function test_a_blank_message_still_explains_itself(): void
    {
        $resolver = $this->methodOf('_regularization_policy');
        $this->assertStringContainsString("if (\$msg === '') {", $resolver);

        $this->assertStringContainsString('over_monthly_limit:', self::$view,
            'the code map must carry a fallback for a school that configured none');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  The setting is usable from the panel
    // ─────────────────────────────────────────────────────────────────────

    /** A school can actually set this without a developer. */
    public function test_the_policy_is_editable_in_the_admin_panel(): void
    {
        $this->assertStringContainsString('id="regMaxDays"', self::$set);
        $this->assertStringContainsString('id="regLimitMsg"', self::$set);

        // Loaded...
        $this->assertStringContainsString('var regPol = d.regularization || {};', self::$set);
        // ...and saved.
        $this->assertStringContainsString('reg_max_days_per_month:', self::$set);
        $this->assertStringContainsString('reg_limit_message:', self::$set);

        // And the endpoint returns it, or the field would always load blank.
        $get = $this->methodOf('get_settings');
        $this->assertStringContainsString("'regularization'           => \$settings['regularization'],", $get);
    }

    /** The screen states what 0 means, since a blank ceiling is ambiguous. */
    public function test_the_screen_explains_the_zero(): void
    {
        $this->assertStringContainsString('for no limit', self::$set);
        $this->assertStringContainsString('approved', self::$set,
            'the operator must know the count is of approved days, not requests');
    }

    /** Only a manager may change it — same gate as the rest of the screen. */
    public function test_changing_the_policy_needs_manage(): void
    {
        $this->assertStringContainsString(
            "_require_role(self::MANAGE_ROLES, 'save_settings', 'strict')", self::$att);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  The requester is told the outcome
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Two events, on decision — the pattern Pocket HRMS ships as
     * "Regularization Accepted" / "Regularization Rejected".
     */
    public function test_the_requester_is_notified_of_the_decision(): void
    {
        $body = $this->methodOf('staff_regularization_decide');

        $this->assertStringContainsString(
            "\$this->_notify_regularization_decision(\$staffId, \$rid, \$date, 'approved', \$finalMark);", $body);
        $this->assertStringContainsString(
            "\$this->_notify_regularization_decision(\$staffId, \$rid, \$date, 'rejected', '');", $body);
    }

    /** It goes through the ONE dispatcher, never a second sender. */
    public function test_it_uses_the_canonical_push_registry(): void
    {
        $emit = $this->methodOf('_notify_regularization_decision');

        $this->assertStringContainsString("\$this->emit_push('STAFF_REG_DECIDED'", $emit);
        $this->assertStringContainsString('STAFF_REG_DECIDED:', self::$fns,
            'the mark must exist in MARK_REGISTRY or the push resolves to nobody');
        $this->assertStringContainsString("idField: 'staffId'", self::$fns);
    }

    /** A retried decision cannot notify twice. */
    public function test_the_notification_is_deduped(): void
    {
        $emit = $this->methodOf('_notify_regularization_decision');

        $this->assertStringContainsString("'reg_' . \$rid . '_' . \$decision", $emit,
            'request plus decision: a retry dedupes, but a later opposite decision '
            . 'on a re-filed request still gets through');
    }

    /** A failed push never fails the decision — the register is already written. */
    public function test_a_failed_push_does_not_fail_the_decision(): void
    {
        $emit = $this->methodOf('_notify_regularization_decision');

        $this->assertStringContainsString('} catch (\Throwable $e) {', $emit);
        $this->assertStringContainsString("log_message('error'", $emit);
    }

    /**
     * NO reminder or nag cadence is emitted. None of the surveyed vendor
     * documentation establishes one for this workflow, and choosing an interval
     * would be inventing policy.
     */
    public function test_no_reminder_cadence_was_invented(): void
    {
        $this->assertStringNotContainsString('STAFF_REG_REMINDER', self::$fns);
        $this->assertStringNotContainsString('STAFF_REG_PENDING', self::$fns);

        $i = strpos(self::$fns, 'STAFF_REG_DECIDED');
        $this->assertNotFalse($i);
        $block = substr(self::$fns, max(0, (int) $i - 500), 700);
        $this->assertStringContainsString('No reminder/nag event', $block,
            'the omission must be recorded as deliberate, not look like a gap');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Withdrawal is visible when it becomes possible
    // ─────────────────────────────────────────────────────────────────────

    /** A withdrawn request is never invisible to the admin. */
    public function test_the_queue_can_show_withdrawn_requests(): void
    {
        $this->assertStringContainsString('data-status="cancelled"', self::$view);
        $this->assertStringContainsString('Withdrawn', self::$view);

        // The endpoint already accepts the filter.
        $list = $this->methodOf('staff_regularization_list');
        $this->assertStringContainsString("'cancelled'", $list);
    }
}
