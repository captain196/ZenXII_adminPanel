<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CALENDAR HALF-DAY — source wiring + fraction-safety regression (2026-08-19).
 *
 * Behavioural proof of the model lives in CalendarHalfDayMatrixTest
 * (executed, A–V). This file pins the wiring in the real code, and pins the
 * fraction-safety defects found during the pre-implementation contract audit.
 *
 * THE CONTRACT AUDIT FOUND LIVE DEFECTS, independent of this feature:
 * day-counts are ALREADY fractional today (attendance 'M' → days_absent 0.5,
 * half-day LWP → lwp_days 0.5), yet several consumers truncated them —
 * including one that rendered a 0.5-day loss of pay as "Fully Paid".
 */
class CalendarHalfDayWiringTest extends TestCase
{
    private string $hr;
    private string $cal;
    private string $wss;
    private string $view;

    protected function setUp(): void
    {
        foreach ([
            'hr'   => 'application/controllers/Hr.php',
            'cal'  => 'application/libraries/Working_calendar_service.php',
            'wss'  => 'application/libraries/Work_schedule_service.php',
            'view' => 'application/views/hr/index.php',
        ] as $prop => $rel) {
            $raw = file_get_contents(__DIR__ . '/../../' . $rel);
            $this->assertNotFalse($raw, "missing {$rel}");
            $out = '';
            foreach (token_get_all($raw) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                    $out .= $t[1];
                } else { $out .= $t; }
            }
            $this->$prop = $out;
        }
    }

    private function body(string $src, string $fn): string
    {
        $start = strpos($src, 'function ' . $fn . '(');
        $this->assertNotFalse($start, "{$fn}() must exist.");
        $open = strpos($src, '{', $start);
        $depth = 0; $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $open, $i - $open + 1); }
        }
        $this->fail('Unbalanced braces.');
    }

    // ── Calendar engine ───────────────────────────────────────────────

    public function test_half_day_weight_defaults_to_one_so_flag_off_is_byte_identical(): void
    {
        // The single most important safety property: every existing caller
        // that does not pass a weight gets exactly the old behaviour.
        $this->assertStringContainsString('float $halfDayWeight = 1.0', $this->cal);
        $this->assertStringContainsString('float $halfDayWeight = 1.0', $this->wss);
    }

    public function test_half_day_contributes_the_weight_to_expected_work(): void
    {
        $this->assertStringContainsString('$count += ($halfDayWeight == 1.0) ? 1 : $halfDayWeight;', $this->cal);
    }

    /**
     * The accumulator deliberately stays an INT unless a fractional weight is
     * actually in play.
     *
     * First cut initialised it to 0.0, which promoted workingCount to float
     * for EVERY tenant — including the ones that never enabled the feature —
     * and broke 20 existing tests comparing with ===. That was the right
     * signal: "flag OFF is byte-identical" has to include the TYPE, not just
     * the number. Callers legitimately compare workingCount strictly, so
     * silently turning 26 into 26.0 for a school that opted out would itself
     * be a contract change.
     */
    public function test_working_count_keeps_its_integer_type_when_the_feature_is_off(): void
    {
        $this->assertStringContainsString('$count     = 0;', $this->cal);
        $this->assertStringNotContainsString('$count     = 0.0;', $this->cal);
        // …and only a genuinely fractional weight promotes it.
        $this->assertStringContainsString('($halfDayWeight == 1.0) ? 1 : $halfDayWeight', $this->cal);
    }

    public function test_half_day_remains_a_working_day_and_keeps_its_mask(): void
    {
        // Expected work changes; the day is still a working day.
        $body = $this->body($this->cal, 'resolveFromVersion');
        $this->assertStringContainsString('$isWorking[$d] = true;', $body);
        $this->assertStringContainsString('$mask .= self::MASK_HALF;', $body);
    }

    public function test_precedence_is_unchanged(): void
    {
        // SWD > weeklyOff > nth > holiday > halfDay. Half-day is still the
        // LAST branch, so it can never shorten an already-off day.
        $body = $this->body($this->cal, 'resolveFromVersion');
        $swd = strpos($body, 'REASON_SPECIAL_WORKING_DAY');
        $hol = strpos($body, 'REASON_HOLIDAY');
        $half = strpos($body, 'REASON_HALF_DAY');
        $this->assertLessThan($hol, $swd);
        $this->assertLessThan($half, $hol);
    }

    // ── Tenant flag (S4) ──────────────────────────────────────────────

    public function test_flag_is_per_tenant_and_defaults_off(): void
    {
        $body = $this->body($this->hr, '_half_day_weight');
        $this->assertStringContainsString("\$pol['calendarHalfDayPayEnabled']", $body);
        $this->assertStringContainsString("if (empty(\$memo['enabled'])) return 1.0;", $body);
        $this->assertStringContainsString("\$this->fs->get('schools', \$this->school_id)", $body,
            'must be read per-tenant from the school doc, not a deployment-wide config');
    }

    public function test_effective_date_boundary_protects_historical_payroll(): void
    {
        $body = $this->body($this->hr, '_half_day_weight');
        $this->assertStringContainsString("\$pol['calendarHalfDayPayEffectiveFrom']", $body);
        $this->assertStringContainsString('return ($periodStart >= $from) ? 0.5 : 1.0;', $body);
    }

    public function test_enabled_without_a_valid_effective_date_stays_off(): void
    {
        $body = $this->body($this->hr, '_half_day_weight');
        $this->assertStringContainsString(
            "if (\$from === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$from)) return 1.0;", $body);
    }

    public function test_flag_read_fails_closed(): void
    {
        $body = $this->body($this->hr, '_half_day_weight');
        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
        $this->assertStringContainsString("\$memo = ['enabled' => false, 'from' => ''];", $body);
    }

    // ── Both payroll engines wired symmetrically ──────────────────────

    public function test_both_engines_compute_a_per_day_expected_value(): void
    {
        foreach (['generate_payroll', 'regenerate_staff_payroll'] as $fn) {
            $body = $this->body($this->hr, $fn);
            $this->assertStringContainsString('$dayExpected = ', $body, "{$fn}() must scale by expected work");
            $this->assertStringContainsString('REASON_HALF_DAY', $body);
            $this->assertStringContainsString('$this->_half_day_weight(', $body);
        }
    }

    public function test_both_engines_scale_absence_by_expected_work(): void
    {
        foreach (['generate_payroll', 'regenerate_staff_payroll'] as $fn) {
            $body = $this->body($this->hr, $fn);
            // CONSOLIDATED 2026-08-19: absence and coverage are now one
            // expression per branch, sharing _leave_coverage_for_day(). The
            // properties asserted are unchanged — absence scales by the day's
            // expectation, 'M' costs half of it, and coverage uses the REAL
            // stored portion rather than a hard-coded 0.5 (a fractional
            // multi-day LWP makes the earliest day partial at something other
            // than a half). Only their location moved.
            $this->assertStringContainsString('$daysAbsent += max(0.0, $dayExpected - $dayCoverage);', $body,
                "{$fn}() must scale absence by the day's expected work");
            $this->assertStringContainsString('$daysAbsent += max(0.0, ($dayExpected * 0.5) - $dayCoverage);', $body,
                "{$fn}() must implement S1 — 'M' covers half the REDUCED day");
            $this->assertStringContainsString('self::_leave_coverage_for_day(', $body,
                'coverage must come from the shared rule, not a per-branch copy');
        }
    }

    public function test_S2_leave_consumption_is_weighted(): void
    {
        $body = $this->body($this->hr, '_chargeable_days_single_department');
        $this->assertStringContainsString('$hdWeight = $this->_half_day_weight(', $body);
        $this->assertStringContainsString('REASON_HALF_DAY', $body);
        $this->assertStringNotContainsString('$chargeable += 1.0;', $body,
            'the flat 1.0 per working day would over-consume balance on a half-day');
    }

    public function test_S3_no_overtime_credit_was_invented(): void
    {
        // 'W' (extra-worked) must still apply only to scheduled OFF days —
        // no new crediting path for working a full day on a half-day.
        foreach (['generate_payroll', 'regenerate_staff_payroll'] as $fn) {
            $body = $this->body($this->hr, $fn);
            $this->assertStringContainsString('$extraWorkedDays += 1.0;', $body);
            $this->assertStringNotContainsString('overtime', strtolower($body));
        }
    }

    // ── Fraction safety (contract-audit defects) ──────────────────────

    public function test_payslip_mirror_no_longer_truncates_fractional_day_counts(): void
    {
        // These were (int) casts. days_absent/lwp_days are ALREADY fractional
        // today, so the stored Teacher-app mirror said 0 while the snake_case
        // source said 0.5.
        foreach (['working_days', 'days_worked', 'days_absent', 'lwp_days', 'paid_leave_days'] as $f) {
            $this->assertStringContainsString("(float) (\$slipData['{$f}'] ?? 0)", $this->hr,
                "{$f} must be written as a float — truncating at write destroys the value permanently");
        }
    }

    public function test_run_level_working_days_is_fraction_safe(): void
    {
        // A float used as a PHP array key truncates (21.5 → 21) and can make
        // genuinely-different schedules collide into one "uniform" key.
        $this->assertStringContainsString(
            '$employeeWorkingSet[(string) $staffWorkingDays] = (float) $staffWorkingDays;', $this->hr);
        $this->assertStringContainsString('(float) reset($employeeWorkingSet)', $this->hr);
        $this->assertStringNotContainsString('(int) array_key_first($employeeWorkingSet)', $this->hr);
    }

    public function test_pay_status_badge_no_longer_reports_a_half_day_lop_as_fully_paid(): void
    {
        // The worst display defect found: parseInt floored a 0.5-day LOP to 0,
        // so it fell through both tests and rendered "Fully Paid".
        $this->assertStringContainsString('var lwp  = parseFloat(l.lwp_days||0);', $this->view);
        $this->assertStringContainsString('var days = parseFloat(l.days||0);', $this->view);
    }

    public function test_employee_can_see_a_sub_one_day_lwp_row(): void
    {
        // The gate hid the LWP line entirely while net pay was reduced.
        $this->assertStringContainsString('if(parseFloat(sl.lwp_days)>0)', $this->view);
    }

    public function test_lop_warning_toast_is_not_suppressed_for_fractional_lop(): void
    {
        $this->assertStringContainsString('parseFloat(r.lwp_days)>0', $this->view);
    }

    public function test_no_parseint_remains_on_fractional_day_fields(): void
    {
        foreach (['parseInt(l.lwp_days', 'parseInt(l.days', 'parseInt(l.paid_days',
                  'parseInt(sl.lwp_days', 'parseInt(r.lwp_days'] as $bad) {
            $this->assertStringNotContainsString($bad, $this->view,
                "leave/LWP day counts are fractional — {$bad} silently floors them");
        }
    }

    // ── Nothing unrelated moved ───────────────────────────────────────

    public function test_payslip_retention_formula_is_untouched(): void
    {
        $body = $this->body($this->hr, '_compute_payslip');
        $this->assertStringContainsString('$deductionDays = $daysAbsent + $lwpDays;', $body);
        $this->assertStringContainsString('$absentFraction = ($staffWorkingDays > 0) ? ($deductionDays / $staffWorkingDays) : 0.0;', $body);
        $this->assertStringContainsString('$retention      = 1 - $absentFraction;', $body);
    }

    public function test_no_second_calculation_engine_was_created(): void
    {
        // The weight threads through the EXISTING resolver chain.
        $this->assertSame(1, substr_count($this->cal, 'public static function resolveFromVersion('));
        $this->assertSame(1, substr_count($this->wss, 'public static function resolveEmployeeCalendar('));
        $this->assertSame(1, substr_count($this->hr, 'private function _half_day_weight('));
    }

    public function test_rbac_gates_unchanged(): void
    {
        foreach (['generate_payroll', 'regenerate_staff_payroll'] as $fn) {
            $this->assertStringContainsString("'HR', 'manage'", $this->body($this->hr, $fn));
        }
    }
}
