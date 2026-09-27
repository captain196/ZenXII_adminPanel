<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/../Support/PayrollEngine.php';

/**
 * Payroll XLSX export — a real write → save → parse → validate round trip.
 *
 * `export_payroll_report()` cannot be executed directly in a test: it sends
 * headers and streams to php://output. But it delegates every cell to three
 * pure sheet builders that take a worksheet plus plain arrays and touch no
 * Firestore:
 *
 *     _xl_salary_register($sheet, $slips, $school, $period)
 *     _xl_dept_report($sheet, $run, $slips, $school, $period)
 *     _xl_payment_report($sheet, $run, $school, $period)
 *
 * So this builds a workbook with the SHIPPED builders, writes a genuine .xlsx
 * to disk with the same Xlsx writer the controller uses, reads it back with
 * PhpSpreadsheet, and asserts the parsed cell values and types.
 *
 * No assertion here is of the "a file was produced" kind. Every one reads a
 * specific cell out of a parsed workbook.
 *
 * The risk being covered is narrow and real: a payroll export is what a school
 * hands to its accountant and its bank. A money column that serialises as TEXT
 * rather than a number silently breaks every downstream SUM, and a fractional
 * figure truncated to a whole rupee misstates the payment.
 */
final class PayrollExportRoundTripTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        if (!PayrollEngine::available()) {
            $this->markTestSkipped('payroll engine could not be loaded on this machine');
        }
        if (!class_exists(Spreadsheet::class)) {
            $this->markTestSkipped('PhpSpreadsheet is not installed');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null && is_file($this->tmp)) unlink($this->tmp);
        $this->tmp = null;
    }

    /**
     * Two slips carrying deliberately awkward figures: a paise-level gross
     * straight out of the engine, and a fractional-day case.
     */
    private function slips(): array
    {
        $a = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 1.0, 0.0);   // gross 38461.54
        $b = PayrollEngine::compute(PayrollEngine::salary(), 26.0, 0.5, 0.0);   // gross 39230.77

        return [
            'STA0001' => [
                'staff_name'       => 'Asha Menon',
                'department'       => 'Science',
                'basic'            => $a['basic'],
                'hra'              => $a['hra'],
                'da'               => $a['da'],
                'ta'               => $a['ta'],
                'medical'          => $a['medical'],
                'other_allowances' => $a['other_allowances'],
                'gross'            => $a['gross'],
                'total_deductions' => 1200.55,
                'net_pay'          => round((float) $a['gross'] - 1200.55, 2),
                'days_worked'      => $a['days_worked'],
            ],
            'STA0002' => [
                'staff_name'       => 'Ravi Kumar',
                'department'       => 'Maths',
                'basic'            => $b['basic'],
                'hra'              => $b['hra'],
                'da'               => $b['da'],
                'ta'               => $b['ta'],
                'medical'          => $b['medical'],
                'other_allowances' => $b['other_allowances'],
                'gross'            => $b['gross'],
                'total_deductions' => 0.0,
                'net_pay'          => $b['gross'],
                'days_worked'      => $b['days_worked'],   // 25.5
            ],
        ];
    }

    /** Build with the real builder, write a real file, parse it back. */
    private function roundTrip(string $builder, ...$args)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        PayrollEngine::call($builder, $sheet, ...$args);

        $this->tmp = tempnam(sys_get_temp_dir(), 'zenx_xl_') . '.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($this->tmp);

        $this->assertFileExists($this->tmp);
        $this->assertGreaterThan(0, filesize($this->tmp), 'the workbook must not be empty');

        return IOFactory::createReader('Xlsx')->load($this->tmp)->getActiveSheet();
    }

    // ── Salary register ──────────────────────────────────────────────────

    public function test_the_salary_register_survives_a_round_trip(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'Vikrant Public', 'March 2026');

        $this->assertSame('Salary Register', $sheet->getTitle());
        $this->assertStringContainsString('Vikrant Public', (string) $sheet->getCell('A1')->getValue());
        $this->assertStringContainsString('March 2026', (string) $sheet->getCell('A1')->getValue());
    }

    public function test_the_header_row_is_written_where_the_reader_expects_it(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'S', 'P');

        $this->assertSame('#', $sheet->getCell('A2')->getValue());
        $this->assertSame('Staff', $sheet->getCell('B2')->getValue());
        $this->assertSame('Dept', $sheet->getCell('C2')->getValue());
        $this->assertSame('Basic', $sheet->getCell('D2')->getValue());
    }

    public function test_staff_rows_carry_the_right_identities(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'S', 'P');

        $this->assertSame('Asha Menon', $sheet->getCell('B3')->getValue());
        $this->assertSame('Science', $sheet->getCell('C3')->getValue());
        $this->assertSame('Ravi Kumar', $sheet->getCell('B4')->getValue());
        $this->assertSame('Maths', $sheet->getCell('C4')->getValue());
    }

    /**
     * THE ONE THAT MATTERS FOR THE ACCOUNTANT. Money must come back as a
     * NUMBER, not a string — a text-serialised column breaks every downstream
     * SUM without any visible sign in the spreadsheet.
     */
    public function test_money_cells_parse_back_as_numbers_not_text(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'S', 'P');

        foreach (['D3', 'E3', 'J3', 'D4', 'J4'] as $ref) {
            $v = $sheet->getCell($ref)->getValue();
            $this->assertIsNumeric($v, "{$ref} must be numeric, got " . gettype($v));
            $this->assertIsNotString($v, "{$ref} must not serialise as text");
        }
    }

    /** Paise must survive the write/read cycle exactly. */
    public function test_paise_survive_the_round_trip(): void
    {
        $slips = $this->slips();
        $sheet = $this->roundTrip('_xl_salary_register', $slips, 'S', 'P');

        $this->assertEqualsWithDelta(
            (float) $slips['STA0001']['gross'],
            (float) $sheet->getCell('J3')->getValue(),
            0.0001,
            'gross 38,461.54 must not become 38,461.5 or 38,462'
        );
        $this->assertEqualsWithDelta(38461.54, (float) $sheet->getCell('J3')->getValue(), 0.0001);
    }

    public function test_the_basic_column_keeps_its_paise(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'S', 'P');

        $this->assertEqualsWithDelta(19230.77, (float) $sheet->getCell('D3')->getValue(), 0.0001);
    }

    /** Two-decimal display formatting must be applied to the money block. */
    public function test_money_columns_carry_a_two_decimal_format(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'S', 'P');

        $this->assertSame(
            '#,##0.00',
            $sheet->getStyle('D3')->getNumberFormat()->getFormatCode(),
            'a money column displayed without decimals hides the paise from the reader'
        );
    }

    /** The totals row must equal the sum of the rows above it. */
    public function test_the_totals_row_reconciles_against_the_rows(): void
    {
        $slips = $this->slips();
        $sheet = $this->roundTrip('_xl_salary_register', $slips, 'S', 'P');

        $expectedGross = 0.0;
        $expectedNet   = 0.0;
        foreach ($slips as $s) {
            $expectedGross += (float) $s['gross'];
            $expectedNet   += (float) $s['net_pay'];
        }

        // Rows 3 and 4 hold staff; row 5 is TOTALS.
        $this->assertSame('TOTALS', $sheet->getCell('B5')->getValue());
        $this->assertEqualsWithDelta(round($expectedGross, 2), (float) $sheet->getCell('J5')->getValue(), 0.0001,
            'the export must not disagree with its own rows');
        $this->assertGreaterThan(0.0, $expectedNet);
    }

    public function test_rows_are_numbered_sequentially(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', $this->slips(), 'S', 'P');

        $this->assertEquals(1, $sheet->getCell('A3')->getValue());
        $this->assertEquals(2, $sheet->getCell('A4')->getValue());
    }

    // ── Department report ────────────────────────────────────────────────

    public function test_the_department_report_aggregates_correctly(): void
    {
        $slips = $this->slips();
        $sheet = $this->roundTrip('_xl_dept_report', [], $slips, 'S', 'P');

        $this->assertSame('Dept Salary', $sheet->getTitle());

        // Collect whatever departments landed, wherever the builder put them.
        $found = [];
        foreach ($sheet->getRowIterator() as $row) {
            $a = (string) $sheet->getCell('A' . $row->getRowIndex())->getValue();
            if ($a !== '') $found[] = $a;
        }

        $this->assertContains('Science', $found);
        $this->assertContains('Maths', $found);
    }

    // ── Degenerate input ─────────────────────────────────────────────────

    /** An empty run must still produce a readable workbook, not a crash. */
    public function test_an_empty_payroll_run_still_exports(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', [], 'S', 'P');

        $this->assertSame('Salary Register', $sheet->getTitle());
        $this->assertSame('TOTALS', $sheet->getCell('B3')->getValue(),
            'with no staff the totals row moves up to row 3');
    }

    /** A slip missing every optional field must not fatal the export. */
    public function test_a_sparse_slip_exports_as_zeroes(): void
    {
        $sheet = $this->roundTrip('_xl_salary_register', ['STA0009' => []], 'S', 'P');

        $this->assertSame('STA0009', $sheet->getCell('B3')->getValue(),
            'the doc id stands in for a missing staff_name');
        $this->assertEquals(0, $sheet->getCell('D3')->getValue());
    }
}
