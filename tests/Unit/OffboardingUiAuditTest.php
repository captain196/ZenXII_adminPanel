<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Offboarding / F&F field-level UI audit, and the mobile pass.
 *
 * Two questions drove this file:
 *
 *   1. Is any field WRITE-ONLY — collected by the form, stored, and read by
 *      nothing? A field like that looks like data capture and is really a
 *      place for an operator to waste their time.
 *
 *   2. Does a narrow screen scroll the TABLE or the whole PAGE? A page that
 *      scrolls sideways on a phone is the classic responsive failure, and this
 *      panel already has a house convention for avoiding it.
 *
 * The separation form passes the first: all five fields it posts are read, and
 * `exitDate` in particular flows into leave proration and payroll period
 * eligibility. That cross-module link is what makes the date validation below
 * worth having.
 */
final class OffboardingUiAuditTest extends TestCase
{
    private static string $staff = '';
    private static string $modal = '';
    private static string $hr    = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$staff = (string) file_get_contents($root . 'application/controllers/Staff.php');
        self::$modal = (string) file_get_contents($root . 'application/views/partials/staff_separation_modal.php');
        self::$hr    = (string) file_get_contents($root . 'application/controllers/Hr.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$staff = self::$modal = self::$hr = '';
    }

    // ── No write-only fields ─────────────────────────────────────────────

    /**
     * Every field the separation form posts is read by the endpoint it posts to.
     *
     * Derived from the form itself rather than a hand-written list, so adding a
     * field to the modal without wiring it up fails here.
     */
    public function test_no_separation_field_is_write_only(): void
    {
        preg_match_all("~body\.set\('([a-z_]+)'~", self::$modal, $m);
        $posted = array_values(array_unique($m[1]));

        $this->assertNotEmpty($posted, 'the modal must still post fields');

        $writeOnly = [];
        foreach ($posted as $field) {
            if ($field === 'status') { continue; }   // read via the URL segment/status arg
            if (strpos(self::$staff, "post('{$field}')") === false) {
                $writeOnly[] = $field;
            }
        }

        $this->assertSame([], $writeOnly,
            'these fields are collected from the operator and read by nobody: '
            . implode(', ', $writeOnly));
    }

    /**
     * And `exitDate` is not merely stored — it reaches leave proration and
     * payroll period eligibility. That is why its correctness matters.
     */
    public function test_the_exit_date_reaches_payroll_and_leave(): void
    {
        $this->assertStringContainsString("\$patch['exitDate']", self::$staff,
            'the separation must persist it');

        $this->assertStringContainsString('_prorate_entitlement', self::$hr);
        $this->assertStringContainsString("\$staffMeta['exitDate']", self::$hr,
            'leave entitlement is prorated against it');
        $this->assertStringContainsString("\$d['exitDate']", self::$hr,
            'payroll period eligibility reads it');
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. The server validated the FORMAT of both dates and their
     * ORDER not at all, so a last working day AFTER the exit date was accepted.
     *
     * That is not a policy question — nobody works after their employment has
     * ended — and the pair feeds leave proration and payroll eligibility, so an
     * inverted one produces a wrong entitlement and a wrong payslip with
     * nothing downstream to catch it.
     */
    public function test_an_impossible_date_pair_is_refused(): void
    {
        $this->assertStringContainsString(
            'if (strtotime($lastWork) > strtotime($exitDate)) {', self::$staff);
        $this->assertStringContainsString(
            'The last working day cannot be after the exit date.', self::$staff);
    }

    /**
     * FUTURE dates stay allowed. A notice period means resigning today and
     * exiting next month, which is the ordinary case — blocking it would break
     * the workflow this validation is meant to protect.
     */
    public function test_a_future_dated_separation_is_still_allowed(): void
    {
        $i = strpos(self::$staff, 'if (strtotime($lastWork) > strtotime($exitDate)) {');
        $this->assertNotFalse($i);
        $near = substr(self::$staff, max(0, (int) $i - 700), 900);

        $this->assertStringContainsString('Future dates are DELIBERATELY still allowed', $near);

        // Nothing compares either date against today.
        $this->assertStringNotContainsString('> strtotime(date(', $near);
    }

    /** The operator is told before a round-trip, and the server refuses anyway. */
    public function test_the_form_checks_it_too(): void
    {
        $this->assertStringContainsString(
            'lastWork.value > exitDate.value', self::$modal,
            'the operator should not need a round-trip to learn this');
        $this->assertStringContainsString(
            'The last working day cannot be after the exit date.', self::$modal);

        // And the client check returns WITHOUT disabling the button, so the
        // operator can correct the date and submit again.
        $i   = strpos(self::$modal, 'lastWork.value > exitDate.value');
        $end = strpos(self::$modal, '}', (int) $i);
        $guard = substr(self::$modal, (int) $i, (int) $end - (int) $i);

        // The guard RETURNS, so the submit sequence that follows it is never
        // reached — and the button is never disabled on a validation refusal.
        $this->assertStringContainsString('return;', $guard);
        $this->assertStringNotContainsString('submit.disabled', $guard);
    }

    /** Malformed dates still fall back rather than throwing the form away. */
    public function test_a_malformed_date_falls_back(): void
    {
        $this->assertStringContainsString(
            "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$exitDateIn) ? \$exitDateIn : date('Y-m-d')", self::$staff);
    }

    // ── The modal's states ───────────────────────────────────────────────

    /** Opening resets every field — a reused modal must not leak the last person. */
    public function test_the_modal_resets_on_open(): void
    {
        $i = strpos(self::$modal, 'function open(');
        $this->assertNotFalse($i);
        $fn = substr(self::$modal, (int) $i, 700);

        foreach (["typeSel.value = 'Other'", 'reasonTa.value', 'msg.textContent', 'submit.disabled = false'] as $reset) {
            $this->assertStringContainsString($reset, $fn,
                'a modal reopened for a different employee must not show the previous one\'s input');
        }
    }

    /** Submitting disables the button and says what is happening. */
    public function test_duplicate_submit_is_prevented(): void
    {
        $this->assertStringContainsString('submit.disabled = true;', self::$modal);
        $this->assertStringContainsString("submit.textContent = 'Saving…';", self::$modal);
    }

    /** A failure re-enables it, so the operator can retry rather than reload. */
    public function test_a_failure_restores_the_button(): void
    {
        // THREE occurrences: open() resets the label, and both failure paths
        // restore it. Two would mean a path that leaves the button reading
        // "Saving..." forever after a failure.
        $this->assertSame(3, substr_count(self::$modal, "submit.textContent = 'Deactivate';"),
            'open() plus both failure paths must set the label');
    }

    /** Success is only claimed when the server says so. */
    public function test_success_is_not_assumed(): void
    {
        $this->assertStringContainsString("json && json.status === 'success'", self::$modal);
        $this->assertStringContainsString('Server returned non-JSON.', self::$modal,
            'a non-JSON response must not be mistaken for success');
    }

    /** It can be dismissed three ways, and dismissal clears its state. */
    public function test_the_modal_can_be_dismissed(): void
    {
        $this->assertStringContainsString("cancel.addEventListener('click', close)", self::$modal);
        $this->assertStringContainsString("e.key === 'Escape'", self::$modal);
        $this->assertStringContainsString('e.target === backdrop', self::$modal);

        $i = strpos(self::$modal, 'function close(');
        $fn = substr(self::$modal, (int) $i, 200);
        $this->assertStringContainsString('currentUserId = null', $fn);
    }

    /** The endpoint is POST-only and validates the id before touching anything. */
    public function test_the_endpoint_guards_its_inputs(): void
    {
        $i = strpos(self::$staff, 'public function set_status');
        $this->assertNotFalse($i);
        $body = substr(self::$staff, (int) $i, 1400);

        $this->assertStringContainsString("'POST only.', 405", $body);
        $this->assertStringContainsString("preg_match('/^[A-Za-z0-9_]+$/', \$user_id)", $body);
        $this->assertStringContainsString("'Status must be Active or Inactive.', 400", $body);
    }

    // ── Mobile ───────────────────────────────────────────────────────────

    /**
     * DEFECT FIXED. A wide table with no overflow guard scrolls the whole PAGE
     * sideways on a phone rather than scrolling itself. This panel already had
     * the convention; the notification table I added did not follow it.
     */
    public function test_wide_tables_scroll_themselves_not_the_page(): void
    {
        $cfg = (string) file_get_contents(
            __DIR__ . '/../../application/views/school_config/index.php');

        $this->assertStringContainsString(
            '<div style="overflow-x:auto;"><table class="sc-table">', $cfg,
            'the notification table must scroll inside its own container');

        // The payroll-periods table uses the HR panel's equivalent convention.
        $hrView = (string) file_get_contents(
            __DIR__ . '/../../application/views/hr/index.php');
        $this->assertStringContainsString(
            "'<div class=\"hr-table-wrap\"><table class=\"hr-table\">", $hrView);
        $this->assertStringContainsString('.hr-table-wrap{overflow-x:auto}', $hrView);
    }

    /** The attendance register — 31 day columns — scrolls inside its own frame. */
    public function test_the_attendance_grid_scrolls_inside_itself(): void
    {
        $reg = (string) file_get_contents(
            __DIR__ . '/../../application/views/attendance/staff.php');

        $i = strpos($reg, '.att-grid-wrap {');
        $this->assertNotFalse($i);
        $rule = substr($reg, (int) $i, 220);

        $this->assertStringContainsString('overflow: auto', $rule,
            'a month grid is far wider than a phone; it must not push the page');
    }

    /** Every Staff Lifecycle page inherits the responsive viewport. */
    public function test_the_viewport_is_declared(): void
    {
        $header = (string) file_get_contents(
            __DIR__ . '/../../application/views/include/header.php');

        $this->assertStringContainsString('viewport', $header);
        $this->assertStringContainsString('width=device-width', $header,
            'without this a phone renders the desktop layout scaled down');
    }
}
