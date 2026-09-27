<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/libraries/Attendance_policy.php';
require_once __DIR__ . '/../../application/libraries/Policy_assignment_service.php';

/**
 * PHASE 2 / N-35 — punch path on the employee-specific calendar.
 *
 * `Attendance_policy::evaluate()` is pure and constructor-free, so acceptance
 * tests 2, 3, 6 and 7 drive the REAL classification engine rather than a stand-in.
 * Test 8 (resolution precedes classification) is a source-order guard, because
 * ordering is a property of the call site, not of a return value.
 *
 * D2 acceptance covered here: 2, 3, 6, 7, 8 — plus the fail-closed refusal.
 */
class PunchPathEmployeeCalendarTest extends TestCase
{
    private const CTRL = __DIR__ . '/../../application/controllers/Staff_attendance.php';

    private function engine(): \Attendance_policy { return new \Attendance_policy(); }

    private function code(): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(self::CTRL)) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    /** A tenant that permits working on a rest day — required for `W` to exist. */
    private function policy(): array
    {
        return [
            'allowWorkOnOff' => true,
            'autoAbsent'     => true,
            'graceMinutes'   => 10,
            'enabledMethods' => ['gps', 'manual'],
            'shifts'         => ['default' => ['start' => '08:30', 'end' => '15:30']],
            // Gate 1 rejects `gps_disabled` without at least one ACTIVE fence.
            'gps' => ['geofences' => [[
                'active' => true, 'centerLat' => 26.5, 'centerLng' => 80.2,
                'radius' => 500, 'id' => 'G1', 'name' => 'Main',
            ]]],
        ];
    }

    private function request(string $direction = 'in'): array
    {
        // Inside the fence, with an accuracy the engine accepts — otherwise the
        // punch is rejected before it ever reaches the rest-day gate.
        return [
            'staffId' => 'STA0003', 'direction' => $direction, 'method' => 'gps',
            'lat' => 26.5, 'lng' => 80.2, 'accuracy' => 10, 'shiftId' => 'default',
        ];
    }

    /** @param bool $isWeeklyOff what the RESOLVED employee calendar said */
    private function context(bool $isWeeklyOff, string $mark = 'V'): array
    {
        return [
            'isWeeklyOff'  => $isWeeklyOff,
            'isHoliday'    => false,
            'currentMark'  => $mark,
            'serverTime'   => '2026-08-09 09:00:00',
            'nowMinutes'   => 540,
        ];
    }

    // ── Acceptance 2 / 6 · scheduled off + employee works → W ────────────

    public function test_2_scheduled_off_and_employee_works_stamps_W(): void
    {
        $d = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context(true));

        $this->assertSame(\Attendance_policy::STATUS_EXTRA, $d['mark'] ?? null,
            'working a scheduled rest day must record W (Work-off), never A');
        $this->assertNotSame('A', $d['mark'] ?? null);
    }

    public function test_6_employee_A_sunday_off_and_works_gets_W(): void
    {
        $d = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context(true));
        $this->assertSame('W', (string) ($d['mark'] ?? ''));
    }

    // ── Acceptance 3 / 7 · working day → normal classification, NOT W ────

    public function test_3_scheduled_working_day_uses_normal_classification(): void
    {
        $d = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context(false));

        $this->assertNotSame('W', (string) ($d['mark'] ?? ''),
            'a scheduled working day must never produce W');
        $this->assertContains((string) ($d['mark'] ?? ''), ['P', 'T', 'M'],
            'a working-day punch classifies through the normal evaluator');
    }

    public function test_7_employee_B_sunday_is_working_so_no_W(): void
    {
        // Same calendar date, same punch — only the resolved schedule differs.
        $a = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context(true));
        $b = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context(false));

        $this->assertSame('W', (string) ($a['mark'] ?? ''));
        $this->assertNotSame('W', (string) ($b['mark'] ?? ''));
    }

    /** Acceptance 5, punch-side: one Sunday, two employees, two outcomes. */
    public function test_5_two_employees_one_sunday_two_outcomes(): void
    {
        $catalogue = [
            'SCH_ACAD' => ['name' => 'Academic', 'weeklyOffs' => ['Sun']],
            'SCH_SUPP' => ['name' => 'Support',  'weeklyOffs' => []],
        ];
        $mk = static fn(string $dept, string $pid): array => [
            'attachmentType' => 'department', 'attachmentId' => $dept,
            'policyId' => $pid, 'policyType' => 'work_schedule',
            'effectiveFrom' => '2026-01-01', 'effectiveTo' => '',
        ];
        $assignments = [$mk('Academic', 'SCH_ACAD'), $mk('Support', 'SCH_SUPP')];

        $resolve = static function (string $staffId, string $dept) use ($catalogue, $assignments): bool {
            $r = \Policy_assignment_service::resolve([
                'policies' => $catalogue, 'assignments' => $assignments,
                'staffId' => $staffId, 'department' => $dept,
                'organisationPolicyId' => null,
            ], '2026-08-09');   // a Sunday
            return in_array('Sun', (array) ($r['policy']['weeklyOffs'] ?? []), true);
        };

        $academicOff = $resolve('STA0003', 'Academic');
        $supportOff  = $resolve('STA0001', 'Support');
        $this->assertTrue($academicOff);
        $this->assertFalse($supportOff);

        $academic = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context($academicOff));
        $support  = $this->engine()->evaluate($this->policy(), $this->request('in'), $this->context($supportOff));

        $this->assertSame('W', (string) ($academic['mark'] ?? ''),
            'Academic staff working their Sunday off → W');
        $this->assertNotSame('W', (string) ($support['mark'] ?? ''),
            'Support staff work Sundays, so the same punch is an ordinary working day');
    }

    // ── Acceptance 8 · resolution PRECEDES classification ────────────────

    /**
     * Ordering is the whole safety property. If evaluate() ran first, Gate 6b
     * would receive a stale flag and W could never be stamped correctly — the
     * rest-day fact would be lost at source, unrecoverable afterwards.
     */
    public function test_8_calendar_is_resolved_before_classification(): void
    {
        $code    = $this->code();
        $resolve = strpos($code, '_resolve_employee_calendar($staffId, $dateISO)');
        $classify= strpos($code, 'attendance_policy');

        $this->assertNotFalse($resolve, 'the punch path must resolve the employee calendar');
        $this->assertNotFalse($classify);
        $this->assertLessThan($classify, $resolve,
            'schedule resolution must precede the classification engine');
    }

    // ── Fail closed · no fallback, no default ───────────────────────────

    public function test_unresolvable_schedule_refuses_the_punch(): void
    {
        $code = $this->code();
        $this->assertMatchesRegularExpression(
            "/\\\$cal\['block'\] !== null\)\s*\{\s*return \\\$this->json_error\(/", $code,
            'an unresolvable calendar must REFUSE the punch, not classify it');
    }

    public function test_punch_path_reads_no_tenant_wide_calendar(): void
    {
        $code = $this->code();
        $this->assertSame(0, substr_count($code, "\$policy['weeklyOffs']"));
        $this->assertSame(0, substr_count($code, "\$pol['weeklyOffs']"));
    }

    public function test_no_hardcoded_weekday_rule_in_punch_path(): void
    {
        $code = $this->code();
        $this->assertSame(0, substr_count($code, 'satCount'));
        $this->assertSame(0, substr_count($code, "date('w'"));
    }

    /** Acceptance 12 — one authority, shared with payroll and the finaliser. */
    public function test_12_uses_the_same_canonical_authority(): void
    {
        $this->assertStringContainsString('Policy_assignment_service::resolve', $this->code());
    }

    /**
     * A month must not repeat the three Firestore reads per day. The loaded
     * context is cached per employee; only the per-date resolution repeats.
     */
    public function test_month_resolution_does_not_re_read_per_day(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('$ctxMemo[$staffId]', $code,
            'loaded data must be cached per employee, not per employee-and-date');
    }

    /** An unreadable calendar is not "a normal working day". */
    public function test_read_failure_blocks_rather_than_defaulting(): void
    {
        $this->assertStringContainsString("'calendar_unreadable'", $this->code());
    }
}
