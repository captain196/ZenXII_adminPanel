<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PayrollEngine.php';

/**
 * The payslip contract between the admin panel (writer) and the Teacher app (reader).
 *
 * `_buildPayslipDoc()` is the SINGLE writer for `salarySlips/{school}_SLIP_*`,
 * used by both `generate_payroll()` and `regenerate_staff_payroll()`. It emits a
 * camelCase canonical mirror explicitly "for cross-system consumers (Teacher app
 * SalarySlipDoc)" alongside the snake_case originals the admin side reads.
 *
 * This suite runs the real builder and checks the document it produces against
 * what the Teacher app actually declares.
 *
 * TWO THINGS WERE ESTABLISHED WHILE WRITING IT (2026-08-21):
 *
 *  1. A STALE COMMENT, now corrected. The builder warned that "Teacher's
 *     SalarySlipDoc still declares these as Int and will truncate on READ".
 *     That has not been true since the day fields were widened to Double on
 *     2026-08-20. Left as-is it would have told a future reader the app
 *     truncates fractional days when it no longer does.
 *
 *  2. SEVEN DECLARED-BUT-UNWRITTEN FIELDS. The Teacher model declares `empId`,
 *     `overtimeHours`, `overtimePay`, `arrears`, `bankRefNo`, `disbursedAt` and
 *     `generatedAt`; none is written anywhere in Hr.php. Kotlin data-class
 *     defaults absorb them, so nothing crashes — the app simply shows blanks
 *     and zeroes. Three of them (`overtimeHours`, `overtimePay`, `arrears`)
 *     describe features the payroll engine does not implement at all: it
 *     produces `extra_worked_pay` for a rest day worked, not overtime.
 *     Recorded rather than "fixed", because writing them would mean inventing
 *     the features they imply.
 */
final class PayslipCrossPlatformContractTest extends TestCase
{
    /** Fractional day fields — must be Double on BOTH sides, forever. */
    private const DAY_FIELDS = [
        'workingDays', 'presentDays', 'daysAbsent', 'lwpDays', 'paidLeaveDays',
    ];

    /** Declared by the Teacher model but never populated by the writer. */
    private const UNWRITTEN = [
        'empId', 'overtimeHours', 'overtimePay', 'arrears',
        'bankRefNo', 'disbursedAt', 'generatedAt',
    ];

    private const TEACHER_MODEL =
        'D:/Projects/SchoolSyncTeacher/app/src/main/java/com/schoolsync/teacher/'
        . 'data/model/firestore/SalarySlipDoc.kt';

    protected function setUp(): void
    {
        if (!PayrollEngine::available()) {
            $this->markTestSkipped('payroll engine could not be loaded on this machine');
        }
    }

    /** A slip built by the real builder from real engine output. */
    private function doc(array $over = []): array
    {
        $c = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.5, 1.0);

        $slipData = array_merge([
            'staff_name'       => 'Asha Menon',
            'department'       => 'Science',
            'month'            => 'March',
            'year'             => '2026',
            'month_key'        => '2026-03',
            'status'           => 'Generated',
            'basic'            => $c['basic'],
            'hra'              => $c['hra'],
            'da'               => $c['da'],
            'ta'               => $c['ta'],
            'medical'          => $c['medical'],
            'other_allowances' => $c['other_allowances'],
            'gross'            => $c['gross'],
            'net_pay'          => $c['net_pay'],
            'total_deductions' => $c['total_deductions'],
            'pf_employee'      => $c['pf_employee'],
            'esi_employee'     => $c['esi_employee'],
            'professional_tax' => $c['professional_tax'],
            'tds'              => $c['tds'],
            'other_deductions' => $c['other_deductions'],
            'working_days'     => 26,
            'days_worked'      => $c['days_worked'],
            'days_absent'      => 0.5,
            'lwp_days'         => 1.0,
            'paid_leave_days'  => 0.5,
        ], $over);

        return PayrollEngine::call('_buildPayslipDoc', 'RUN2026_03', 'STA0007', $slipData);
    }

    private function teacherModel(): string
    {
        $src = @file_get_contents(self::TEACHER_MODEL);
        if ($src === false) $this->markTestSkipped('Teacher app source not present on this machine');
        return $src;
    }

    // ── The camelCase mirror is produced ─────────────────────────────────

    public function test_the_builder_emits_the_canonical_camelcase_mirror(): void
    {
        $d = $this->doc();

        foreach ([
            'staffName', 'netPayable', 'grossEarnings', 'totalDeductions',
            'monthKey', 'earnings', 'deductions', 'schoolId', 'staffId', 'runId',
        ] as $key) {
            $this->assertArrayHasKey($key, $d, "the Teacher app reads '{$key}'");
        }
    }

    public function test_the_document_is_typed_as_a_payslip(): void
    {
        $this->assertSame('payslip', $this->doc()['type'],
            "the Teacher query filters type == 'payslip'");
    }

    public function test_snake_case_is_kept_alongside_for_the_admin_side(): void
    {
        $d = $this->doc();

        $this->assertArrayHasKey('net_pay', $d);
        $this->assertArrayHasKey('gross', $d);
        $this->assertEqualsWithDelta((float) $d['net_pay'], (float) $d['netPayable'], 0.0001,
            'the two spellings must never disagree about the same money');
    }

    // ── Fractional days: the whole point of the mirror ───────────────────

    /** Every day field must be a float in the written document. */
    public function test_day_fields_are_written_as_floats(): void
    {
        $d = $this->doc();

        foreach (self::DAY_FIELDS as $f) {
            $this->assertArrayHasKey($f, $d);
            $this->assertIsFloat($d[$f], "{$f} must be written as a float, not an int");
        }
    }

    /** And they must carry the true fractional value, not a truncation. */
    public function test_fractional_days_survive_the_write(): void
    {
        $d = $this->doc();

        $this->assertEqualsWithDelta(0.5, (float) $d['daysAbsent'], 0.0001,
            "an attendance 'M' is half a day and must be stored as 0.5, never 0");
        $this->assertEqualsWithDelta(1.0, (float) $d['lwpDays'], 0.0001);
        $this->assertEqualsWithDelta(0.5, (float) $d['paidLeaveDays'], 0.0001);
        $this->assertEqualsWithDelta(24.5, (float) $d['presentDays'], 0.0001);
    }

    public function test_a_half_day_lwp_is_not_truncated(): void
    {
        $d = $this->doc(['lwp_days' => 0.5]);

        $this->assertEqualsWithDelta(0.5, (float) $d['lwpDays'], 0.0001);
    }

    /** The reader must not narrow what the writer widened. */
    public function test_the_teacher_model_declares_day_fields_as_double(): void
    {
        $src = $this->teacherModel();

        foreach (self::DAY_FIELDS as $f) {
            $this->assertMatchesRegularExpression(
                '/val\s+' . preg_quote($f, '/') . '\s*:\s*Double/',
                $src,
                "{$f} must stay Double in SalarySlipDoc — Int would truncate 0.5 to 0 on READ"
            );
        }
    }

    public function test_the_money_totals_are_double_on_the_reader_side(): void
    {
        $src = $this->teacherModel();

        foreach (['grossEarnings', 'totalDeductions', 'netPayable'] as $f) {
            $this->assertMatchesRegularExpression(
                '/val\s+' . preg_quote($f, '/') . '\s*:\s*Double/',
                $src,
                "{$f} must be Double or paise are lost on read"
            );
        }
    }

    // ── Nested maps ──────────────────────────────────────────────────────

    public function test_earnings_and_deductions_are_nested_maps_of_floats(): void
    {
        $d = $this->doc();

        $this->assertIsArray($d['earnings']);
        $this->assertIsArray($d['deductions']);

        foreach (['basic', 'hra', 'da', 'ta', 'medical', 'otherAllowances'] as $k) {
            $this->assertArrayHasKey($k, $d['earnings']);
            $this->assertIsFloat($d['earnings'][$k]);
        }
        foreach (['pfEmployee', 'esiEmployee', 'professionalTax', 'tds', 'otherDeductions'] as $k) {
            $this->assertArrayHasKey($k, $d['deductions']);
            $this->assertIsFloat($d['deductions'][$k]);
        }
    }

    /** The nested earnings must still reconcile to the stated gross. */
    public function test_the_nested_earnings_sum_to_the_stated_gross(): void
    {
        $d = $this->doc();

        $this->assertEqualsWithDelta(
            (float) $d['grossEarnings'],
            array_sum(array_map('floatval', $d['earnings'])),
            0.0001,
            'the Teacher app renders these lines under this total — they must add up'
        );
    }

    // ── monthKey accepts both spellings ──────────────────────────────────

    /**
     * generate_payroll() supplies `month_key`; regenerate carries the already
     * camel `monthKey`. Routing regeneration through this builder previously
     * blanked the key it already held.
     */
    public function test_monthkey_is_taken_from_either_spelling(): void
    {
        $this->assertSame('2026-03', $this->doc()['monthKey'], 'from month_key');

        $fromCamel = $this->doc(['month_key' => null, 'monthKey' => '2026-04']);
        $this->assertSame('2026-04', $fromCamel['monthKey'], 'from monthKey');
    }

    public function test_a_missing_monthkey_yields_an_empty_string_not_null(): void
    {
        $d = $this->doc(['month_key' => null]);

        $this->assertSame('', $d['monthKey'],
            'the Teacher model declares monthKey as a non-null String');
    }

    // ── Tenant and run identity ──────────────────────────────────────────

    public function test_the_document_carries_its_run_and_staff_identity(): void
    {
        $d = $this->doc();

        $this->assertSame('RUN2026_03', $d['runId']);
        $this->assertSame('STA0007', $d['staffId']);
        $this->assertArrayHasKey('schoolId', $d, 'the Teacher query filters on schoolId');
    }

    // ── The recorded gap ─────────────────────────────────────────────────

    /**
     * Declared by the reader, never written by the writer. Asserted so the gap
     * is explicit: if any of these ever starts being written, this test fails
     * and the list gets revisited rather than silently drifting.
     */
    public function test_the_unwritten_fields_are_still_unwritten(): void
    {
        $d = $this->doc();

        foreach (self::UNWRITTEN as $f) {
            $this->assertArrayNotHasKey(
                $f,
                $d,
                "'{$f}' is declared by the Teacher model but has never been written; if that "
                . 'changed deliberately, update the UNWRITTEN list and the docblock'
            );
        }
    }

    /** They are nonetheless declared on the reader side, with safe defaults. */
    public function test_the_reader_declares_them_with_defaults_so_nothing_crashes(): void
    {
        $src = $this->teacherModel();

        foreach (self::UNWRITTEN as $f) {
            $this->assertMatchesRegularExpression(
                '/val\s+' . preg_quote($f, '/') . '\s*:/',
                $src,
                "{$f} is expected to be declared by the reader"
            );
        }

        $this->assertStringContainsString('= 0.0', $src);
        $this->assertStringContainsString('= ""', $src);
    }

    // ── The corrected comment ────────────────────────────────────────────

    /** The stale "still declares these as Int" warning must not come back. */
    public function test_the_builder_no_longer_claims_the_reader_truncates(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($src);

        $this->assertStringNotContainsString(
            "Teacher's SalarySlipDoc still declares these as Int",
            $src,
            'that warning described a state fixed on 2026-08-20'
        );
        $this->assertStringContainsString('now declares all five of these', $src);
    }
}
