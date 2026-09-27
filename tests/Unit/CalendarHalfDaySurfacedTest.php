<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase C, 2026-08-18.
 *
 * Phase A's reconciliation found that Work_schedule_service::resolveEmployeeCalendar()
 * computes a `halfDayCount` (Calendar Half Day) but Hr.php::_staff_working_days()
 * silently dropped it — never included in the function's return array, never
 * read by either call site. This is deliberately a PURE SURFACING fix: the
 * value becomes visible in the return array, but 'working' and every other
 * existing key are byte-for-byte unchanged, so payroll's payable-days math is
 * NOT altered. Whether/how a Calendar Half Day should reduce payable days is
 * an open financial-policy question, explicitly not decided or invented here.
 *
 * Source-regression test (Firestore_service has no behavioral mock for
 * controller code).
 */
class CalendarHalfDaySurfacedTest extends TestCase
{
    private string $controllerSrc;

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
        $this->controllerSrc = $out;
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function staffWorkingDaysBody(): string
    {
        $start = strpos($this->controllerSrc, 'private function _staff_working_days(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    public function test_half_day_count_is_now_surfaced_in_the_return_array(): void
    {
        $body = $this->staffWorkingDaysBody();
        $this->assertStringContainsString("'halfDayCount' => \$cal['halfDayCount'] ?? 0,", $body);
    }

    public function test_existing_returned_keys_are_unchanged(): void
    {
        // The fix must be purely additive — every pre-existing key stays
        // exactly as it was, so payroll's payable-days math cannot shift.
        $body = $this->staffWorkingDaysBody();
        foreach ([
            "'working'   => \$cal['workingCount'],",
            "'isWorking'    => \$cal['isWorking'],",
            "'workingMask'  => \$cal['workingMask'],",
            "'calBlocked'   => \$cal['blocked'],",
        ] as $line) {
            $this->assertStringContainsString($line, $body);
        }
    }

    public function test_both_call_sites_read_named_keys_only_never_iterate_the_array(): void
    {
        // Confirms the additive key is safe: neither caller loops over $swd
        // blindly (which would have silently ingested the new numeric key).
        $callSites = substr_count($this->controllerSrc, '_staff_working_days($monthNum, (int) $year, $holidays, $jd, $xd, $staffId, $department)');
        $this->assertEquals(2, $callSites, 'Expected exactly the two known payroll call sites — a new caller must be reviewed for safe $swd access before this assumption holds.');
        $this->assertStringNotContainsString('foreach ($swd as', $this->controllerSrc);
    }

    public function test_no_payable_days_formula_was_invented(): void
    {
        // This phase must not decide the financial treatment of a Calendar
        // Half Day — no arithmetic combining halfDayCount into a payable/
        // working-day total should exist anywhere in this function yet.
        $body = $this->staffWorkingDaysBody();
        $this->assertStringNotContainsString('halfDayCount * 0.5', $body);
        $this->assertStringNotContainsString('- $halfDayCount', $body);
        $this->assertStringNotContainsString('workingCount\'] - ', $body);
    }
}
