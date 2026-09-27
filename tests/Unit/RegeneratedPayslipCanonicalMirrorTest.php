<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * REGRESSION — a regenerated payslip must be written by the SHARED builder.
 *
 * DEFECT (found by real UAT against the designated test school, 2026-08-19):
 *
 *   generate_payroll() writes its slips through _buildPayslipDoc(), which adds
 *   the canonical camelCase mirror the Teacher app reads (SalarySlipDoc:
 *   workingDays / presentDays / daysAbsent / lwpDays / paidLeaveDays).
 *
 *   regenerate_staff_payroll() built `$newSlip` as an array_merge OVER THE OLD
 *   SLIP and wrote it raw. Nothing in that payload restated the camelCase
 *   mirror, so the mirror survived from the ORIGINAL run: admin-side money was
 *   recomputed while the employee's app kept showing the pre-correction day
 *   counts. `daily_salary` had the same problem — the engine recomputed it into
 *   $dailySalary and the payload never restated it, so the payslip rendered
 *   basic ÷ the PREVIOUS working-day count.
 *
 *   Observed live: after regeneration the slip read working_days 25.5 but
 *   workingDays 26.0, with daily_salary 769.23 (20000/26) instead of 784.31
 *   (20000/25.5).
 *
 *   This is NOT specific to Calendar Half-Day. Regeneration is the ordinary
 *   correction path — fix attendance, add LWP, adjust leave — so the divergence
 *   applied to every correction ever made.
 *
 * Source-level assertions: this write path cannot be executed without Firestore,
 * and Firestore_service has no behavioural mock. Comments are stripped first so
 * the prose above can never satisfy an assertion.
 */
final class RegeneratedPayslipCanonicalMirrorTest extends TestCase
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

    private function regenBody(): string
    {
        $start = strpos($this->src, 'function regenerate_staff_payroll(');
        $this->assertNotFalse($start, 'regenerate_staff_payroll() not found');
        return $this->extractFunctionBody($this->src, $start);
    }

    /** THE FIX: the slip write goes through the shared builder. */
    public function test_regeneration_writes_through_the_shared_builder(): void
    {
        $this->assertStringContainsString(
            "\$this->fs->set('salarySlips', \$slipDocId, \$this->_buildPayslipDoc(\$runId, \$staffId, \$newSlip), true);",
            $this->regenBody(),
            'regeneration must not write $newSlip raw — that bypasses the camelCase mirror'
        );
    }

    /** THE DEFECT: writing the raw array must never come back. */
    public function test_regeneration_never_writes_the_raw_array_again(): void
    {
        $this->assertStringNotContainsString(
            "\$this->fs->set('salarySlips', \$slipDocId, \$newSlip, true);",
            $this->regenBody()
        );
    }

    /** The recomputed daily rate is actually persisted. */
    public function test_recomputed_daily_salary_is_restated_in_the_payload(): void
    {
        $body = $this->regenBody();
        $this->assertStringContainsString("\$dailySalary    = \$calc['daily_salary'];", $body,
            'the engine value must still be read');
        $this->assertStringContainsString("'daily_salary'     => \$dailySalary,", $body,
            'and it must be written, or the old rate survives the array_merge');
    }

    /**
     * EMPLOYER STATUTORY COST — money on the employer side.
     *
     * _compute_payslip() recomputes pf_employer and esi_employer on every
     * regeneration; they derive from effectiveBasic and gross, both of which
     * move with attendance. Regeneration never read or wrote them, so the
     * array_merge over the old slip left employer PF/ESI frozen at the
     * pre-correction figures beside fully recomputed employee-side numbers.
     */
    public function test_employer_statutory_contributions_are_recomputed_and_stored(): void
    {
        $body = $this->regenBody();

        $this->assertStringContainsString("\$pfEmployer     = \$calc['pf_employer'];", $body,
            'the engine value must be read');
        $this->assertStringContainsString("\$esiEmployer    = \$calc['esi_employer'];", $body);
        $this->assertStringContainsString("'pf_employer'      => \$pfEmployer,", $body,
            'and written, or the pre-correction employer liability survives');
        $this->assertStringContainsString("'esi_employer'     => \$esiEmployer,", $body);
    }

    /**
     * The slip must not name one department while its numbers came from another.
     * $department here is the PERIOD-EFFECTIVE value this recomputation used for
     * the calendar and the GL expense classification.
     */
    public function test_department_is_restated_from_the_period_effective_value(): void
    {
        $this->assertStringContainsString("'department'       => \$department,", $this->regenBody());
    }

    /**
     * Guards the whole class of defect rather than the three instances found:
     * every money field the engine returns and generation stores must also be
     * restated on regeneration, because $newSlip merges over the OLD slip and
     * anything omitted silently survives.
     */
    public function test_every_engine_money_field_is_restated_on_regeneration(): void
    {
        $body = $this->regenBody();

        foreach ([
            'basic', 'hra', 'da', 'ta', 'medical', 'other_allowances', 'gross',
            'pf_employee', 'esi_employee', 'pf_employer', 'esi_employer',
            'tds', 'professional_tax', 'other_deductions', 'total_deductions',
            'net_pay', 'lwp_deduction', 'daily_salary',
        ] as $field) {
            $this->assertMatchesRegularExpression(
                "/'{$field}'\s*=>/",
                $body,
                "{$field} must be restated on regeneration or the old value survives the merge"
            );
        }
    }

    /**
     * The builder accepts either spelling of the month key. generate_payroll()
     * supplies `month_key`; regeneration carries `monthKey`. Without the
     * fallback, routing regeneration through the builder would BLANK the key.
     */
    public function test_builder_accepts_either_month_key_spelling(): void
    {
        $start = strpos($this->src, 'private function _buildPayslipDoc(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->src, $start);

        $this->assertStringContainsString(
            "'monthKey'        => (string) (\$slipData['month_key'] ?? \$slipData['monthKey'] ?? ''),",
            $body
        );
    }

    /**
     * The mirror the Teacher app reads is defined in exactly ONE place. If a
     * second definition ever appears, the two builders can drift again — which
     * is the failure this whole test file exists to prevent.
     */
    public function test_camelcase_mirror_is_defined_exactly_once(): void
    {
        foreach (['workingDays', 'presentDays', 'daysAbsent', 'lwpDays', 'paidLeaveDays'] as $field) {
            $this->assertSame(
                1,
                substr_count($this->src, "'{$field}'     => (float)")
                    + substr_count($this->src, "'{$field}'      => (float)")
                    + substr_count($this->src, "'{$field}'   => (float)"),
                "camelCase mirror field {$field} must be written from one builder only"
            );
        }
    }

    /** Every mirror field stays a float — truncation destroys half-days. */
    public function test_mirror_fields_are_floats_not_ints(): void
    {
        $start = strpos($this->src, 'private function _buildPayslipDoc(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->src, $start);

        foreach (['workingDays', 'presentDays', 'daysAbsent', 'lwpDays', 'paidLeaveDays'] as $field) {
            $this->assertMatchesRegularExpression(
                "/'{$field}'\s*=>\s*\(float\)/",
                $body,
                "{$field} must be cast (float) — an (int) cast silently destroys 0.5"
            );
            $this->assertDoesNotMatchRegularExpression("/'{$field}'\s*=>\s*\(int\)/", $body);
        }
    }
}
