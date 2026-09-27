<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/helpers/effective_dating_helper.php';
require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';
require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_assignment_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_grade_service.php';

/**
 * ZenX HRMS W2.5 — pay grades, band validation, payout readiness, CTC.
 *
 * Three behaviours here are decisions rather than mechanics, and each is
 * pinned deliberately:
 *
 *   • a band breach WARNS, it does not block — refusing deliberate exceptions
 *     gets worked around by putting people on the wrong grade;
 *   • ungraded staff produce no warning at all — noise would train operators
 *     to ignore the panel;
 *   • payout checks default OFF — bank/PAN live in the PII mirror, so an ON
 *     default would block every employee in every tenant.
 */
class SalaryGradeAndReadinessTest extends TestCase
{
    private \Salary_grade_service $grades;

    protected function setUp(): void
    {
        $this->grades = new \Salary_grade_service();
    }

    private function grade(array $over = []): array
    {
        return array_merge([
            'code' => 'C', 'name' => 'Grade C',
            'minPaise' => 2500000, 'maxPaise' => 4000000,   // ₹25,000–₹40,000
            'displayOrder' => 30, 'active' => true,
        ], $over);
    }

    // ── Grade validation ─────────────────────────────────────────────────

    public function test_a_valid_grade_passes(): void
    {
        [$clean, $errors] = $this->grades->validate($this->grade());
        $this->assertSame([], $errors);
        $this->assertSame('C', $clean['code']);
        $this->assertSame(2500000, $clean['minPaise']);
    }

    public function test_grade_code_shape_is_enforced(): void
    {
        foreach (['', '1C', 'grade c', 'C-1'] as $bad) {
            $this->assertNotEmpty($this->grades->validate($this->grade(['code' => $bad]))[1], "'{$bad}'");
        }
        // Lowercase is normalised rather than rejected.
        $this->assertSame('C', $this->grades->validate($this->grade(['code' => 'c']))[0]['code']);
    }

    public function test_grade_name_is_required(): void
    {
        $this->assertNotEmpty($this->grades->validate($this->grade(['name' => '']))[1]);
        $this->assertNotEmpty($this->grades->validate($this->grade(['name' => str_repeat('x', 200)]))[1]);
    }

    public function test_band_bounds_must_be_integer_paise(): void
    {
        foreach ([25000.50, '25000.50', -1] as $bad) {
            $this->assertNotEmpty($this->grades->validate($this->grade(['minPaise' => $bad]))[1], 'min ' . var_export($bad, true));
            $this->assertNotEmpty($this->grades->validate($this->grade(['maxPaise' => $bad]))[1], 'max ' . var_export($bad, true));
        }
    }

    public function test_band_ceiling_must_not_be_below_its_floor(): void
    {
        $this->assertNotEmpty($this->grades->validate($this->grade(['minPaise' => 4000000, 'maxPaise' => 2500000]))[1]);
        $this->assertSame([], $this->grades->validate($this->grade(['minPaise' => 2500000, 'maxPaise' => 2500000]))[1],
            'a single-point band is legitimate');
    }

    // ── Band checking ────────────────────────────────────────────────────

    public function test_salary_inside_the_band(): void
    {
        $r = \Salary_grade_service::checkBand($this->grade(), 'C', 3000000);
        $this->assertTrue($r['checked']);
        $this->assertTrue($r['inBand']);
        $this->assertSame('none', $r['severity']);
    }

    public function test_band_bounds_are_inclusive(): void
    {
        // Sitting exactly on the floor or ceiling is inside the band.
        $this->assertTrue(\Salary_grade_service::checkBand($this->grade(), 'C', 2500000)['inBand'], 'floor');
        $this->assertTrue(\Salary_grade_service::checkBand($this->grade(), 'C', 4000000)['inBand'], 'ceiling');
    }

    public function test_salary_below_the_band_warns_and_says_which_way(): void
    {
        $r = \Salary_grade_service::checkBand($this->grade(), 'C', 2000000);
        $this->assertFalse($r['inBand']);
        $this->assertSame('warning', $r['severity']);
        $this->assertStringContainsString('BELOW', $r['message']);
        $this->assertStringContainsString('25,000.00', $r['message'], 'the operator is told the actual bound');
    }

    public function test_salary_above_the_band_warns(): void
    {
        $r = \Salary_grade_service::checkBand($this->grade(), 'C', 5000000);
        $this->assertFalse($r['inBand']);
        $this->assertStringContainsString('ABOVE', $r['message']);
    }

    public function test_ungraded_staff_produce_no_warning(): void
    {
        // Ungraded staff are legitimate. Warning on them would be noise, and
        // noise trains operators to ignore the panel.
        foreach ([null, '', '   '] as $code) {
            $r = \Salary_grade_service::checkBand(null, $code, 9999999);
            $this->assertFalse($r['checked']);
            $this->assertTrue($r['inBand'], 'not a breach');
            $this->assertSame('none', $r['severity']);
        }
    }

    public function test_referencing_a_grade_that_does_not_exist_is_a_real_warning(): void
    {
        $r = \Salary_grade_service::checkBand(null, 'GHOST', 3000000);
        $this->assertSame('warning', $r['severity']);
        $this->assertStringContainsString('GHOST', $r['message']);
    }

    public function test_open_ended_ceiling_is_supported(): void
    {
        // maxPaise = 0 means "no ceiling" — the top grade in most structures.
        $r = \Salary_grade_service::checkBand($this->grade(['maxPaise' => 0]), 'C', 99999999);
        $this->assertTrue($r['inBand']);
    }

    // ── Readiness: payout prerequisites (cap 41) ────────────────────────

    /** A service whose assignment lookup is stubbed, so readiness is testable. */
    private function readinessSvc(array $byStaff): \Salary_assignment_service
    {
        return new class($byStaff) extends \Salary_assignment_service {
            private array $m;
            public function __construct(array $m) { $this->m = $m; }
            public function listForStaff(string $staffId): array { return $this->m[$staffId] ?? []; }
        };
    }

    private function payable(): array
    {
        return [['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
                 'status' => 'active', 'lines' => [['componentId' => 'BASIC']], 'grossPaise' => 3000000]];
    }

    public function test_payout_checks_are_off_by_default(): void
    {
        // Bank/PAN live in the server-only PII mirror. An ON default would
        // report every employee in every tenant as unpayable.
        $svc = $this->readinessSvc(['STA0001' => $this->payable()]);
        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Anita']], '2026-06-01');
        $this->assertTrue($r['summary']['payrollEligible']);
        $this->assertSame([], $r['blocked']);
    }

    public function test_missing_bank_blocks_when_the_caller_opts_in(): void
    {
        $svc = $this->readinessSvc(['STA0001' => $this->payable()]);
        $r = $svc->readiness(
            [['staffId' => 'STA0001', 'name' => 'Anita']],
            '2026-06-01',
            ['checkPayoutDetails' => true]
        );
        $this->assertFalse($r['summary']['payrollEligible']);
        $this->assertSame('Anita', $r['blocked'][0]['name']);
        $this->assertStringContainsString('bank account', $r['blocked'][0]['reason']);
    }

    public function test_bank_details_are_recognised_in_every_shape_the_codebase_uses(): void
    {
        // A false "missing bank account" would block a payable person — the
        // most annoying possible false positive — so the check is tolerant of
        // the shapes this field has taken over time.
        $shapes = [
            ['bankDetails' => ['accountNumber' => '123']],
            ['bankDetails' => ['account_number' => '123']],
            ['bankAccountNumber' => '123'],
            ['account_number' => '123'],
        ];
        foreach ($shapes as $i => $s) {
            $this->assertTrue(\Salary_assignment_service::hasBankDetails($s), "shape {$i}");
        }
        $this->assertFalse(\Salary_assignment_service::hasBankDetails([]));
        $this->assertFalse(\Salary_assignment_service::hasBankDetails(['bankDetails' => ['accountNumber' => '  ']]));
    }

    public function test_pan_pf_and_esi_are_each_independently_requestable(): void
    {
        $svc = $this->readinessSvc(['STA0001' => $this->payable()]);
        $staff = [['staffId' => 'STA0001', 'name' => 'Anita', 'bankDetails' => ['accountNumber' => '1']]];

        // Each is off unless the tenant's configuration actually needs it —
        // requiring a PF number where PF does not apply would be a false block.
        $this->assertTrue($svc->readiness($staff, '2026-06-01',
            ['checkPayoutDetails' => true])['summary']['payrollEligible']);

        $r = $svc->readiness($staff, '2026-06-01', ['checkPayoutDetails' => true, 'requirePan' => true]);
        $this->assertStringContainsString('PAN', $r['blocked'][0]['reason']);

        $r = $svc->readiness($staff, '2026-06-01', ['checkPayoutDetails' => true, 'requirePf' => true]);
        $this->assertStringContainsString('PF number', $r['blocked'][0]['reason']);

        $r = $svc->readiness($staff, '2026-06-01', ['checkPayoutDetails' => true, 'requireEsi' => true]);
        $this->assertStringContainsString('ESI number', $r['blocked'][0]['reason']);
    }

    public function test_multiple_missing_details_are_listed_together(): void
    {
        $svc = $this->readinessSvc(['STA0001' => $this->payable()]);
        $r = $svc->readiness(
            [['staffId' => 'STA0001', 'name' => 'Anita']],
            '2026-06-01',
            ['checkPayoutDetails' => true, 'requirePan' => true, 'requirePf' => true]
        );
        $reason = $r['blocked'][0]['reason'];
        foreach (['bank account', 'PAN', 'PF number'] as $needle) {
            $this->assertStringContainsString($needle, $reason, 'one visit should reveal everything missing');
        }
    }

    // ── Readiness: band warnings do not block ───────────────────────────

    public function test_a_band_breach_warns_but_payroll_stays_eligible(): void
    {
        $svc = $this->readinessSvc(['STA0001' => [
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
             'lines' => [['componentId' => 'BASIC']], 'grossPaise' => 9000000, 'payGrade' => 'C'],
        ]]);
        $gradeSvc = new class extends \Salary_grade_service {
            public function get(string $code): ?array {
                return ['code' => 'C', 'name' => 'Grade C', 'minPaise' => 2500000, 'maxPaise' => 4000000];
            }
        };

        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Anita']], '2026-06-01',
            ['gradeService' => $gradeSvc]);

        $this->assertTrue($r['summary']['payrollEligible'], 'a deliberate exception must not stop payroll');
        $this->assertSame(1, $r['summary']['warnings']);
        $this->assertStringContainsString('ABOVE', $r['warnings'][0]['reason']);
        $this->assertSame('Anita', $r['warnings'][0]['name'], 'warnings name people too');
    }

    public function test_in_band_salary_produces_no_warning(): void
    {
        $svc = $this->readinessSvc(['STA0001' => [
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'status' => 'active',
             'lines' => [['componentId' => 'BASIC']], 'grossPaise' => 3000000, 'payGrade' => 'C'],
        ]]);
        $gradeSvc = new class extends \Salary_grade_service {
            public function get(string $code): ?array {
                return ['code' => 'C', 'name' => 'Grade C', 'minPaise' => 2500000, 'maxPaise' => 4000000];
            }
        };
        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Anita']], '2026-06-01', ['gradeService' => $gradeSvc]);
        $this->assertSame(0, $r['summary']['warnings']);
    }

    // ── CTC derivation ───────────────────────────────────────────────────

    private function catalogue(): array
    {
        $out = [];
        foreach (\Salary_component_service::systemComponents() as $c) $out[$c['code']] = $c;
        return $out;
    }

    public function test_ctc_is_gross_plus_employer_contributions(): void
    {
        $a = [
            'grossPaise' => 4200000,   // ₹42,000
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED', 'basis' => 'NONE', 'amountPaise' => 3000000],
                ['componentId' => 'HRA',   'calcType' => 'FIXED', 'basis' => 'NONE', 'amountPaise' => 1200000],
                ['componentId' => 'PF_EMPLOYER',  'calcType' => 'PERCENT', 'basis' => 'BASIC', 'rate' => 12.0],
                ['componentId' => 'ESI_EMPLOYER', 'calcType' => 'PERCENT', 'basis' => 'GROSS', 'rate' => 3.25],
            ],
        ];
        $r = \Salary_assignment_service::deriveCtc($a, $this->catalogue());

        $this->assertSame(4200000, $r['grossPaise']);
        $this->assertSame(360000,  $r['breakdown']['PF_EMPLOYER'],  '12% of ₹30,000 basic');
        $this->assertSame(136500,  $r['breakdown']['ESI_EMPLOYER'], '3.25% of ₹42,000 gross');
        $this->assertSame(496500,  $r['employerPaise']);
        $this->assertSame(4696500, $r['ctcPaise'], 'gross + employer contributions');
    }

    public function test_ctc_excludes_employee_deductions(): void
    {
        // Employee PF reduces take-home; it does not add to employer cost.
        $a = [
            'grossPaise' => 3000000,
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED', 'basis' => 'NONE', 'amountPaise' => 3000000],
                ['componentId' => 'PF_EMPLOYEE', 'calcType' => 'PERCENT', 'basis' => 'BASIC', 'rate' => 12.0],
            ],
        ];
        $r = \Salary_assignment_service::deriveCtc($a, $this->catalogue());
        $this->assertSame(0, $r['employerPaise']);
        $this->assertSame(3000000, $r['ctcPaise'], 'CTC equals gross when there is no employer contribution');
    }

    public function test_ctc_uses_the_one_money_policy(): void
    {
        // Employer PF on an odd basic must round exactly as applyRatio does —
        // no independent arithmetic.
        $a = [
            'grossPaise' => 3333333,
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED', 'basis' => 'NONE', 'amountPaise' => 3333333],
                ['componentId' => 'PF_EMPLOYER', 'calcType' => 'PERCENT', 'basis' => 'BASIC', 'rate' => 12.0],
            ],
        ];
        $r = \Salary_assignment_service::deriveCtc($a, $this->catalogue());
        $this->assertSame(
            \Statutory_config_service::applyRatio(3333333, 0.12),
            $r['breakdown']['PF_EMPLOYER']
        );
    }

    public function test_no_rtdb_and_no_hardcoded_tenant_in_the_grade_service(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Salary_grade_service.php');
        $this->assertNotFalse($src);
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src);
    }
}
