<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Payroll and Leave resolve an employee's department differently.
 *
 * FOUND 2026-08-21 while auditing transfer/promotion effective dating.
 *
 *   Payroll  — generate_payroll() and regenerate_staff_payroll() both resolve
 *              department ONCE, as of $periodStart, and pass that single value
 *              into _staff_working_days() for the WHOLE month.
 *
 *   Leave    — _count_chargeable_leave_days() resolves department per day (or
 *              per span endpoint) and splits the calculation when it changes.
 *
 * They answer different questions and are each defensible in isolation. The
 * divergence only bites when BOTH of these are true:
 *
 *   1. an employee changes department mid-month, AND
 *   2. the two departments have different calendar applicability — a holiday,
 *      special working day or Calendar Half-Day scoped via `departments[]`.
 *
 * Then payroll computes expected work from the OLD department's calendar for
 * days the employee actually spent in the NEW one, while leave uses the correct
 * per-day department. Expected work is the denominator of the pay retention
 * ratio, so the two engines disagree about the same day and the daily rate is
 * computed against the wrong divisor.
 *
 * EXPOSURE MEASURED, READ-ONLY, ACROSS ALL TENANTS (2026-08-21):
 *   department timeline rows ........ 0
 *   effective mid-month ............. 0
 *   department-scoped calendarEvents. 0
 * Both preconditions are absent everywhere, so this is LATENT. No historical
 * payroll is affected and no remediation is required.
 *
 * The correct resolution is not in doubt — expected work on a given day should
 * use the department the employee was in on that day, which is what Leave
 * already does and what effective dating means everywhere else in ZenX. It is
 * not implemented because doing so requires giving _staff_working_days() the
 * multi-department treatment Leave already has, and that is real work on a path
 * no tenant uses yet. This test exists so the divergence is discovered before
 * the first transfer, not after a payslip is wrong.
 */
final class DepartmentCalendarDivergenceTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);
        $this->src = $src;
    }

    // ── What holds today, and must keep holding ──────────────────────────

    /**
     * Generation and regeneration must resolve department IDENTICALLY. If they
     * ever diverge from each other, a regenerated payslip would differ from the
     * originally generated one — a far worse defect than the divergence above.
     *
     * Since N-40 the period-start value is the FALLBACK handed to
     * `_department_segments()`, not the sole source: it is what a day resolves
     * to when the employment timeline has nothing to say, which is every day for
     * every tenant today. Both engines must still derive it the same way, hence
     * this test survives the fix unchanged.
     */
    public function test_both_payroll_engines_resolve_department_at_period_start(): void
    {
        $this->assertSame(
            2,
            substr_count($this->src, "resolveAttributeAsOf(\$staffId, 'department', \$periodStart)"),
            'generate and regenerate must agree on the as-of date'
        );
    }

    public function test_both_payroll_engines_pass_one_department_to_the_calendar(): void
    {
        $this->assertSame(
            2,
            substr_count($this->src, '_staff_working_days($monthNum, (int) $year, $holidays, $jd, $xd, $staffId, $department)'),
            'both engines feed the calendar the same single resolved department'
        );
    }

    /** Leave resolves per-day — the behaviour payroll should eventually match. */
    public function test_leave_resolves_department_per_day(): void
    {
        $this->assertStringContainsString(
            "resolveAttributeAsOf(\$staffId, 'department', \$dateISO)",
            $this->src,
            'the leave engine is the reference implementation for per-day department'
        );
    }

    /**
     * ATTENDANCE ALSO RESOLVES PER-DAY (added 2026-08-21).
     *
     * Swept every `resolveAttributeAsOf` call site in the codebase. There are
     * exactly three as-of arguments for 'department':
     *
     *   $periodStart  payroll generate + regenerate   — ONCE for the whole month
     *   $dateISO      leave engine                    — per day
     *   $date         Attendance::_department_as_of() and
     *                 Staff_attendance_finaliser::_departmentAsOf()  — per day
     *
     * So this is NOT three competing interpretations. Leave and attendance
     * already AGREE on per-day resolution; payroll is the lone outlier.
     *
     * That materially sharpens the fix guidance: adopting per-day in payroll
     * makes it consistent with the two modules that feed it, rather than
     * picking a winner between two equally-plausible designs.
     */
    public function test_attendance_agrees_with_leave_on_per_day_resolution(): void
    {
        foreach ([
            'controllers/Attendance.php',
            'libraries/Staff_attendance_finaliser.php',
        ] as $f) {
            $src = file_get_contents(__DIR__ . '/../../application/' . $f);
            $this->assertNotFalse($src, "{$f} not found");

            $this->assertStringContainsString(
                "resolveAttributeAsOf(\$staffId, 'department', \$date)",
                $src,
                "{$f} must keep resolving department per day — if it ever moves to a "
                . 'period-start resolution, attendance and leave would disagree too'
            );
        }
    }

    /** Payroll is the ONLY module using a period-start resolution. */
    public function test_period_start_resolution_is_confined_to_payroll(): void
    {
        foreach ([
            'controllers/Attendance.php',
            'libraries/Staff_attendance_finaliser.php',
        ] as $f) {
            $src = file_get_contents(__DIR__ . '/../../application/' . $f);
            $this->assertNotFalse($src);

            $this->assertStringNotContainsString(
                "resolveAttributeAsOf(\$staffId, 'department', \$periodStart)",
                $src,
                'the once-per-month pattern must not spread out of payroll'
            );
        }
    }

    /** Payroll must never silently fall back to the LIVE staff document. */
    public function test_payroll_prefers_history_over_the_live_staff_document(): void
    {
        $this->assertStringContainsString(
            "if (\$histDept !== null && \$histDept !== '') \$department = \$histDept;",
            $this->src,
            "today's department must not be used to price a historical month"
        );
    }

    // ── The outstanding divergence, recorded honestly ────────────────────

    /**
     * RESOLVED 2026-08-22 (N-40). This previously asserted the divergence
     * EXISTED and was marked incomplete. Payroll now segments the employment
     * window into consecutive same-department runs and resolves the calendar
     * once per segment, so it agrees with Leave and Attendance.
     *
     * Inverted rather than deleted: the divergence it described is exactly what
     * must not come back.
     */
    public function test_payroll_now_resolves_department_per_day_like_leave(): void
    {
        $this->assertStringContainsString('_department_segments($staffId, $year, $monthNum', $this->src,
            'payroll must split the month by department rather than taking one value for all of it');

        $this->assertStringContainsString(
            '$this->_department_as_of($staffId, $dateISO, $fallback)',
            $this->src,
            'and it must use the SAME per-day resolver Leave already uses — not a third model'
        );
    }

    /** Each segment gets its own calendar resolution, filtered for its department. */
    public function test_each_segment_resolves_its_own_calendar(): void
    {
        $this->assertStringContainsString('function _resolve_segment_calendar(', $this->src);

        $i = strpos($this->src, 'function _resolve_segment_calendar(');
        $body = substr($this->src, (int) $i, 1600);

        foreach (['holidays_in_month', 'special_working_days_in_month', 'half_days_in_month'] as $call) {
            $this->assertStringContainsString($call, $body,
                "a segment's calendar must be filtered for ITS department, not the month's first one");
        }
        $this->assertStringContainsString('resolveEmployeeCalendar(', $body);
    }

    /**
     * NOT a per-day query. `_department_as_of()` is memoised per (staffId|date)
     * and the timeline is warmed once per run, so a segmented month costs the
     * same Firestore reads as an unsegmented one.
     */
    public function test_segmentation_does_not_reintroduce_an_n_plus_1(): void
    {
        $i = strpos($this->src, 'function _department_as_of(');
        $this->assertNotFalse($i);
        $body = substr($this->src, (int) $i, 700);

        $this->assertStringContainsString('static $deptMemo = [];', $body,
            'without the memo, segmenting a 31-day month would issue 31 lookups per employee');
        $this->assertStringContainsString("\$k = \$staffId . '|' . \$dateISO;", $body);
    }

    /** A block in ANY segment blocks the employee — no partly-priced month. */
    public function test_a_block_in_one_segment_blocks_the_whole_month(): void
    {
        $this->assertStringContainsString("\$merged['blocked']     = true;", $this->src,
            'payroll must not price a month it could only partly resolve');
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
