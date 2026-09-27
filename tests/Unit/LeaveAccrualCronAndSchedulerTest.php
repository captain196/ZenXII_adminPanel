<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__); // satisfy the file's direct-access guard
require_once __DIR__ . '/../../application/libraries/Leave_accrual_service.php';

/**
 * Platform leave-accrual cron + scheduler wiring (2026-09-20).
 *
 * run_leave_accrual is now driven fleet-wide by Leave_accrual_cron (a session-
 * less CI_Controller) via the platform scheduler job `leave_accrual` in
 * scripts/run_scheduled.php. Both the HR web path and the cron delegate to the
 * SINGLE authority Leave_accrual_service — no duplicated rules.
 *
 * The cron/scheduler cannot execute offline against real Firestore, so their
 * tenant-safety / idempotency / failure-isolation guarantees are asserted from
 * source (comment-stripped); the accrue→consume→next-period ARITHMETIC is
 * EXECUTED against the shipped pure installment authority.
 */
final class LeaveAccrualCronAndSchedulerTest extends TestCase
{
    private static string $cron = '';
    private static string $sched = '';
    private static string $svc = '';

    public static function setUpBeforeClass(): void
    {
        self::$cron  = self::strip(__DIR__ . '/../../application/controllers/Leave_accrual_cron.php');
        self::$svc   = self::strip(__DIR__ . '/../../application/libraries/Leave_accrual_service.php');
        self::$sched = (string) file_get_contents(__DIR__ . '/../../scripts/run_scheduled.php');
    }
    public static function tearDownAfterClass(): void { self::$cron = self::$svc = self::$sched = ''; }

    private static function strip(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $out .= $t[1]; }
            else { $out .= $t; }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    // ── Cron is a session-less platform controller, CLI-only ──────────────
    public function test_cron_is_session_less_and_cli_only(): void
    {
        $this->assertStringContainsString('class Leave_accrual_cron extends CI_Controller', self::$cron,
            'must NOT extend MY_Controller (its session-auth constructor cannot run under cron)');
        $this->assertStringContainsString('is_cli_request()', self::$cron);
        $this->assertStringContainsString("show_error('Leave_accrual_cron is CLI-only.', 403)", self::$cron,
            'a web request must be refused');
    }

    // ── Fleet discovery uses the shared canonical rule (no name blacklist) ─
    public function test_tenant_discovery_reuses_the_canonical_authority(): void
    {
        $this->assertStringContainsString('Timetable_projection_cron::tenantFromSchoolDoc(', self::$cron,
            'tenant eligibility must reuse the single shared discovery rule');
        $this->assertStringContainsString("firestoreQueryOrFail('schools'", self::$cron);
    }

    // ── Cross-tenant isolation: per-tenant fs context + per-tenant try/catch ─
    public function test_each_tenant_runs_in_its_own_isolated_context(): void
    {
        $this->assertStringContainsString('$this->fs->init($schoolId, $session)', self::$cron,
            'each tenant must be processed under its OWN fs context — no cross-tenant read/write');
        $this->assertStringContainsString('new Leave_accrual_service())->init($this->firebase, $this->fs, $schoolId, $session)', self::$cron,
            'a FRESH service per tenant (the service holds no cross-tenant cache)');
        // The per-tenant body sits inside a try/catch so one school cannot abort the run.
        $this->assertMatchesRegularExpression('~try \{.*accrueSchool\(\$throughMonth\).*\} catch \(\\\\Throwable~s', self::$cron,
            'a tenant that throws must be isolated, logged, and the loop must continue');
        $this->assertStringContainsString('$t[\'failed\']++', self::$cron);
    }

    // ── Lifecycle gate + per-school opt-in gate ───────────────────────────
    public function test_cron_gates_on_lifecycle_and_per_school_optin(): void
    {
        $this->assertStringContainsString('lifecycle_guard_check($this->firebase, $schoolId, \\Lifecycle_resolver::JOB_OPERATIONAL)', self::$cron,
            'suspended/closing/closed schools must be gated (lifecycle_guard_check, not _or_skip)');
        $this->assertStringContainsString('if (!$svc->schoolAccrualEnabled()) { $t[\'off\']++; continue; }', self::$cron,
            'a school that has not opted in must be skipped with zero writes');
    }

    // ── Failure isolation drives the exit code (so the scheduler can alert) ─
    public function test_exit_code_reflects_any_tenant_failure(): void
    {
        $this->assertStringContainsString("exit(\$t['failed'] > 0 ? 1 : 0)", self::$cron,
            'a non-zero exit lets run_scheduled record failure + raise an alert');
        $this->assertStringContainsString('exit(2)', self::$cron,
            'an unreadable tenant list is a distinct hard failure');
    }

    // ── Scheduler registration (audit + alerting inherited from the wrapper) ─
    public function test_scheduler_registers_the_job(): void
    {
        $this->assertStringContainsString("case 'leave_accrual':", self::$sched);
        $this->assertMatchesRegularExpression("~case 'leave_accrual':.*'leave_accrual_cron', 'run_all'~s", self::$sched,
            'the job must dispatch to the platform cron controller');
        $this->assertStringContainsString('leave_accrual', $this->uiUsage(),
            'the --job usage string must advertise leave_accrual');
    }
    private function uiUsage(): string
    {
        // The usage line lists the supported jobs.
        return preg_match('~--job=\{[^}]*\}~', self::$sched, $m) ? $m[0] : '';
    }

    // ── Idempotency: re-running the same month credits nothing ────────────
    public function test_engine_resumes_after_last_credited_month_not_from_scratch(): void
    {
        // Source guarantee: the credit loop starts strictly AFTER lastAccrualMonth.
        $this->assertStringContainsString("? \$this->nextMonth(\$lastMonth) : \$earliest", self::$svc,
            'a re-run must resume after the last credited month — never re-credit a covered month');
    }

    // ── Enable → accrue → consume → next-period (ARITHMETIC, executed) ────
    public function test_full_lifecycle_arithmetic(): void
    {
        // A school with EL = 12 days/yr, monthly accrual, Jan start (window 12).
        $dpy = 12.0; $window = 12;
        $avail = function (float $accrued, float $carried, float $used) use ($dpy): float {
            // Mirrors the apply_leave / _deriveLiveBalances cap: available is
            // capped at accrued-so-far, floored at 0.
            return max(0.0, min($dpy, $accrued) + $carried - $used);
        };

        // Month 1..3 accrue.
        $accrued = 0.0;
        for ($m = 1; $m <= 3; $m++) $accrued += Leave_accrual_service::installment($dpy, $m, $window);
        $this->assertGreaterThan(0.0, $accrued, 'accrual credits month by month');
        $afterQ1 = $avail($accrued, 0.0, 0.0);
        $this->assertEqualsWithDelta($accrued, $afterQ1, 1e-9, 'available equals what has accrued so far');
        $this->assertLessThan($dpy, $afterQ1, 'NOT the full annual up front — only accrued-so-far');

        // CONSUME 1 day.
        $used = 1.0;
        $afterConsume = $avail($accrued, 0.0, $used);
        $this->assertEqualsWithDelta($accrued - 1.0, $afterConsume, 1e-9, 'consumption reduces availability');

        // NEXT PERIOD: months 4..12 accrue; year-end total is EXACTLY the annual.
        for ($m = 4; $m <= 12; $m++) $accrued += Leave_accrual_service::installment($dpy, $m, $window);
        $this->assertEqualsWithDelta($dpy, $accrued, 1e-9, 'a full year accrues exactly the configured annual');
        $yearEnd = $avail($accrued, 0.0, $used);
        $this->assertEqualsWithDelta($dpy - 1.0, $yearEnd, 1e-9, 'year-end available = annual − consumed');
    }

    // ── OFF / brand-new school: default is annual up-front, zero drip ─────
    public function test_off_and_new_school_default_is_fail_closed(): void
    {
        // The service reads schools.leavePolicy.accrualEnabled and defaults OFF,
        // failing closed on any read error — a brand-new school (no leavePolicy)
        // and every non-opted-in school get the full annual, byte-identical.
        $this->assertMatchesRegularExpression("~schoolAccrualEnabled[^{]*\{.*\\\$enabled = false;.*leavePolicy~s", self::$svc,
            'default is OFF: $enabled starts false and only a truthy leavePolicy.accrualEnabled turns it on');
        $this->assertMatchesRegularExpression("~catch[^}]*\\\$enabled = false~s", self::$svc,
            'a read error must fail closed to OFF, never accidentally start drip-gating leave');
    }
}
