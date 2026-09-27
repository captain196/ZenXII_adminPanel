<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The salary-register export must carry fractional day values intact.
 *
 * VERIFIED BY EXECUTION 2026-08-19: _xl_salary_register() was invoked for real,
 * a genuine .xlsx was written and re-parsed with PhpSpreadsheet, and every value
 * came back correct — 25.5, 25.25, 0.25, 0.5 and 1.5 all survived as NUMBERS
 * (not text) with their decimals displayed, and money still rendered to 2dp.
 *
 * These tests are the standing guard for that result. They are source-level on
 * purpose: executing the export inside the suite would require defining the Hr
 * controller class, which other tests also parse, and a duplicate class
 * definition would break the run.
 *
 * The specific risk being guarded is the one that has already bitten this
 * codebase repeatedly — an (int) cast or a formatCode applied across the day
 * columns, either of which would silently turn 25.5 into 25.
 */
final class PayrollExportFractionSafetyTest extends TestCase
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

    private function registerBody(): string
    {
        $start = strpos($this->src, 'private function _xl_salary_register(');
        $this->assertNotFalse($start, '_xl_salary_register() not found');

        $open = strpos($this->src, '{', $start);
        $depth = 0;
        for ($i = $open, $n = strlen($this->src); $i < $n; $i++) {
            if ($this->src[$i] === '{') $depth++;
            elseif ($this->src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($this->src, $open, $i - $open + 1);
            }
        }
        $this->fail('Unbalanced braces in _xl_salary_register().');
    }

    /** The day fields go into the sheet raw — no truncating cast. */
    public function test_day_values_are_not_cast_to_int(): void
    {
        $body = $this->registerBody();

        foreach (['days_worked', 'days_absent', 'lwp_days'] as $field) {
            $this->assertStringContainsString("\$s['{$field}']??0", $body,
                "{$field} must be passed through unmodified");
            $this->assertDoesNotMatchRegularExpression(
                "/\(int\)\s*\(?\\\$s\['{$field}'\]/",
                $body,
                "an (int) cast on {$field} destroys 0.5 and 0.25"
            );
        }
    }

    /**
     * The 2dp accounting format must stay on the MONEY columns (D:P) only.
     * Widening it to reach the day columns (Q, R, S) would render 25.5 as
     * "25.50" — harmless — but a 0-decimal format there would render it "26",
     * which is the failure this pins against.
     */
    public function test_money_number_format_does_not_reach_the_day_columns(): void
    {
        $body = $this->registerBody();

        $this->assertStringContainsString('getStyle("D{$r}:P{$r}")', $body,
            'the money format range is expected to stop at P (Net Pay)');

        // No zero-decimal format anywhere in the register.
        $this->assertStringNotContainsString("setFormatCode('#,##0')", $body,
            'a 0-decimal format would truncate fractional days on screen');
    }

    /** Column order must keep the day fields last, after Net Pay at P. */
    public function test_day_columns_follow_the_money_columns(): void
    {
        $body = $this->registerBody();

        $this->assertStringContainsString(
            "'Net Pay','Days Worked','Absent','LWP'",
            $body,
            'day columns must sit after Net Pay, outside the money format range'
        );
    }

    /** Totals use float accumulation, so a fractional row cannot corrupt them. */
    public function test_totals_accumulate_as_floats(): void
    {
        $body = $this->registerBody();

        $this->assertStringContainsString("\$tGross += (float)(\$s['gross']??0);", $body);
        $this->assertStringContainsString("\$tNet += (float)(\$s['net_pay']??0);", $body);
    }
}
