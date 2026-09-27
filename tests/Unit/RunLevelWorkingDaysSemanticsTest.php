<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Run-level `working_days` is an AGGREGATE with a sentinel, not an employee's
 * working-day count.
 *
 * generate_payroll() records the shared value when every priced employee agrees,
 * and 0 — meaning "varies by schedule" — when they do not. Reporting one
 * schedule's number as if it applied to everyone would be wrong the moment two
 * schedules exist.
 *
 * INVESTIGATED 2026-08-19 after a live run reported working_days = 0 while both
 * employees had correct per-slip values (25.5 and 26). That is the sentinel
 * behaving correctly, not a defect — Calendar Half-Day simply makes the varying
 * case far more common, because one department can have a half-day while
 * another does not.
 *
 * These tests pin the two properties that keep it safe:
 *   1. the sentinel is accompanied by an explicit boolean, so it cannot be
 *      mistaken for a real zero;
 *   2. no UI reads the run-level value — every surface reads the SLIP-level
 *      per-employee value.
 */
final class RunLevelWorkingDaysSemanticsTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;
    }

    public function test_aggregate_is_the_shared_value_only_when_all_agree(): void
    {
        $this->assertStringContainsString(
            "\$workingDays = (count(\$employeeWorkingSet) === 1)",
            $this->src
        );
    }

    /** Fraction-safe: reads the VALUE, and does not cast to int. */
    public function test_aggregate_is_not_truncated(): void
    {
        $this->assertStringContainsString("? (float) reset(\$employeeWorkingSet)", $this->src);
        $this->assertStringNotContainsString("(int) array_key_first(\$employeeWorkingSet)", $this->src);
    }

    /** The sentinel is stated as data, not left implicit in a magic 0. */
    public function test_varies_flag_is_recorded_on_the_run(): void
    {
        $this->assertStringContainsString(
            "\$workingDaysVaries = (count(\$employeeWorkingSet) !== 1);",
            $this->src
        );
        $this->assertStringContainsString("'working_days_varies' => \$workingDaysVaries,", $this->src);
    }

    /**
     * No UI may read the run-level value. Every working_days reference in the HR
     * views must be slip-scoped (`sl.` or `p.`). A bare `run.working_days` would
     * render the sentinel as though it were an employee's working days.
     */
    public function test_no_hr_view_reads_a_run_level_working_days(): void
    {
        $view = file_get_contents(__DIR__ . '/../../application/views/hr/index.php');
        $this->assertNotFalse($view);

        $this->assertSame(0, preg_match_all('/\brun\s*\.\s*working_days/', $view),
            'run-level working_days must never be rendered');
        $this->assertSame(0, preg_match_all('/\brunData\s*\.\s*working_days/', $view));

        // Every occurrence that IS present must be slip- or payslip-scoped.
        preg_match_all('/(\w+)\s*\.\s*working_days/', $view, $m);
        foreach ($m[1] as $owner) {
            $this->assertContains($owner, ['sl', 'p'],
                "working_days read from '{$owner}' — only slip-scoped reads are correct");
        }
    }
}
