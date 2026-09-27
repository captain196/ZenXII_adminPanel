<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * PHASE 2 / N-35 — finaliser on the employee-specific calendar.
 *
 * `Staff_attendance_finaliser` needs CI, Firestore and the holiday service, so
 * behaviour is asserted through `Policy_assignment_service` (the same authority
 * the finaliser now calls) plus literal-source guards on the finaliser itself.
 *
 * Covers the D2 acceptance list: 1, 4, 5, 9, 10, 11, 13, 14.
 * Tests 2, 3, 6, 7, 8 belong to the punch path (Phase 2 step 2);
 * test 15 belongs to the grid (step 3).
 */
class FinaliserEmployeeCalendarTest extends TestCase
{
    private const SRC = __DIR__ . '/../../application/libraries/Staff_attendance_finaliser.php';

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
        require_once __DIR__ . '/../../application/libraries/Policy_assignment_service.php';
    }

    private function source(): string { return (string) file_get_contents(self::SRC); }

    private function code(): string
    {
        $out = '';
        foreach (token_get_all($this->source()) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    /** Two schedules that differ on Sunday — the whole point of N-35. */
    private function catalogue(): array
    {
        return [
            'SCH_ACAD' => ['name' => 'Academic', 'weeklyOffs' => ['Sun']],
            'SCH_SUPP' => ['name' => 'Support',  'weeklyOffs' => []],
        ];
    }

    private function resolveFor(string $staffId, string $dept, array $assignments, string $date): array
    {
        return \Policy_assignment_service::resolve([
            'policies'             => $this->catalogue(),
            'assignments'          => $assignments,
            'staffId'              => $staffId,
            'department'           => $dept,
            'organisationPolicyId' => null,
        ], $date);
    }

    private function deptAssignment(string $dept, string $policyId): array
    {
        return [
            'attachmentType' => 'department', 'attachmentId' => $dept,
            'policyId' => $policyId, 'policyType' => 'work_schedule',
            'effectiveFrom' => '2026-01-01', 'effectiveTo' => '',
        ];
    }

    // ── Acceptance 1 · scheduled off + no work → O ───────────────────────

    public function test_1_scheduled_off_resolves_as_non_working(): void
    {
        // 2026-08-09 is a Sunday.
        $r = $this->resolveFor('STA0003', 'Academic',
            [$this->deptAssignment('Academic', 'SCH_ACAD')], '2026-08-09');

        $this->assertNull($r['block']);
        $this->assertContains('Sun', $r['policy']['weeklyOffs'],
            'a Sunday must resolve as non-working for Academic — the finaliser writes O');
    }

    // ── Acceptance 4 · working day + no attendance → A ───────────────────

    public function test_4_scheduled_working_day_resolves_as_working(): void
    {
        // 2026-08-10 is a Monday.
        $r = $this->resolveFor('STA0003', 'Academic',
            [$this->deptAssignment('Academic', 'SCH_ACAD')], '2026-08-10');

        $this->assertNull($r['block']);
        $this->assertNotContains('Mon', $r['policy']['weeklyOffs'],
            'a Monday is a working day for Academic — an unmarked day becomes A');
    }

    // ── Acceptance 5 · two employees, same Sunday, different answers ─────

    public function test_5_two_employees_differ_on_the_same_sunday(): void
    {
        $assignments = [
            $this->deptAssignment('Academic', 'SCH_ACAD'),
            $this->deptAssignment('Support',  'SCH_SUPP'),
        ];

        $academic = $this->resolveFor('STA0003', 'Academic', $assignments, '2026-08-09');
        $support  = $this->resolveFor('STA0001', 'Support',  $assignments, '2026-08-09');

        $this->assertContains('Sun',    $academic['policy']['weeklyOffs']);
        $this->assertNotContains('Sun', $support['policy']['weeklyOffs']);
        $this->assertNotSame($academic['policyId'], $support['policyId'],
            'ONE school, ONE Sunday, TWO answers — a tenant-wide list cannot express this');
    }

    // ── Acceptance 10 / 11 · no fallback, no silent default ──────────────

    public function test_10_unassigned_employee_blocks_rather_than_defaulting(): void
    {
        $r = $this->resolveFor('STA9999', 'Unknown',
            [$this->deptAssignment('Academic', 'SCH_ACAD')], '2026-08-09');

        $this->assertNotNull($r['block']);
        $this->assertNull($r['policy'], 'an unresolved employee must yield NO calendar, not a default');
    }

    /**
     * Operator rule: a period predating every assignment is NOT "no days off" —
     * it is unknown, and must be flagged rather than invented.
     */
    public function test_11_period_before_any_assignment_is_unresolved_not_empty(): void
    {
        $later = $this->deptAssignment('Academic', 'SCH_ACAD');
        $later['effectiveFrom'] = '2026-08-01';

        $r = $this->resolveFor('STA0003', 'Academic', [$later], '2026-04-15');

        $this->assertNotNull($r['block'],
            'April predates the assignment — unknown, never assumed');
        $this->assertNull($r['policy']);
    }

    // ── Source guards ────────────────────────────────────────────────────

    /** Acceptance 9 — no hardcoded weekday rule anywhere in the finaliser. */
    public function test_9_no_hardcoded_weekday_rule(): void
    {
        $code = $this->code();
        $this->assertSame(0, substr_count($code, 'satCount'));
        $this->assertSame(0, substr_count($code, "'Sunday'"));
        $this->assertSame(0, substr_count($code, "date('w'"));
    }

    /** Acceptance 10 — the tenant-wide list is no longer read. */
    public function test_10_tenant_wide_weekly_offs_is_not_read(): void
    {
        $code = $this->code();
        $this->assertSame(0, substr_count($code, "\$policy['weeklyOffs']"),
            'the tenant-wide calendar must not be consulted — resolve per employee');
    }

    /** Acceptance 13 — a genuine A on a rest day is never rewritten to O. */
    public function test_13_genuine_absent_is_never_rewritten(): void
    {
        $code = $this->code();
        // The overlay may only write O where the day is currently 'V'.
        $this->assertMatchesRegularExpression(
            "/\\\$ch === 'V'.*?nonWorkingDays\[\\\$d\]\) \? 'O' : 'A'/s", $code,
            "O may only replace an unmarked day — rewriting a recorded 'A' destroys evidence");
        $this->assertSame(0, substr_count($code, "'A' => 'O'"));
    }

    /** Acceptance 14 — W, O and H remain distinct and untouched by the overlay. */
    public function test_14_w_o_h_semantics_intact(): void
    {
        $code = $this->code();
        // Only V and A enter the overlay; P/L/T/M/W/O are never eligible.
        $this->assertStringContainsString("\$ch !== 'V' && \$ch !== 'A'", $code,
            'genuine marks including W must be excluded from the overlay');
    }

    // ── Fail-closed wiring ───────────────────────────────────────────────

    public function test_unresolvable_employee_is_skipped_not_defaulted(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('$nonWorking === null', $code);
        $this->assertMatchesRegularExpression(
            "/\\\$nonWorking === null\)\s*\{\s*\\\$out\['skipped'\]\+\+;\s*continue;/", $code,
            'an unresolved employee must be skipped entirely, never marked A on unknown days');
    }

    /** Resolution must be loaded once, not queried per employee. */
    public function test_schedule_index_is_bulk_loaded(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('_loadScheduleIndex()', $code);
        // The bulk load must sit OUTSIDE the month/staff loops.
        $this->assertLessThan(
            strpos($code, 'foreach ($monthKeys'),
            strpos($code, '$this->_loadScheduleIndex();'),
            'the index must load before the loops — inside them it is N round-trips');
    }

    /** Acceptance 12 — the finaliser uses the SAME authority payroll uses. */
    public function test_12_same_canonical_authority_as_payroll(): void
    {
        $this->assertStringContainsString(
            'Policy_assignment_service::resolve', $this->code(),
            'attendance and payroll must resolve through one authority, not two');
    }
}
