<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/helpers/effective_dating_helper.php';
require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';
require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_assignment_service.php';

/**
 * ZenX HRMS W2.3 — effective-dated salary assignments.
 *
 * Behavioural: `validate()`, `splitGross()`, `fromLegacyStructure()` and the
 * shared effective-dating helpers are pure, so these execute real production
 * code.
 *
 * Two groups carry most of the weight:
 *   • the BALANCING split, because a structure whose parts do not sum to gross
 *     is the thing an operator reconciles by hand every month;
 *   • the legacy mapping, because W2.3 ships a DRY-RUN migration and a wrong
 *     mapping would be discovered only after `--commit`.
 */
class SalaryAssignmentTest extends TestCase
{
    private \Salary_assignment_service $svc;

    protected function setUp(): void
    {
        $this->svc = new \Salary_assignment_service();
    }

    /** Catalogue metadata, keyed by code — the shape the service expects. */
    private function catalogue(): array
    {
        $out = [];
        foreach (\Salary_component_service::systemComponents() as $c) {
            $out[$c['code']] = $c;
        }
        return $out;
    }

    private function assignment(array $over = []): array
    {
        return array_merge([
            'staffId'       => 'STA0001',
            'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => null,
            'payBasis'      => 'MONTHLY_FIXED',
            'status'        => 'active',
            'lines'         => [
                ['componentId' => 'BASIC', 'amountPaise' => 3000000],
                ['componentId' => 'HRA',   'amountPaise' => 1200000],
            ],
            'grossPaise'    => 4200000,
        ], $over);
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_a_valid_assignment_passes(): void
    {
        [$clean, $errors] = $this->svc->validate($this->assignment(), $this->catalogue());
        $this->assertSame([], $errors);
        $this->assertSame('STA0001', $clean['staffId']);
        $this->assertCount(2, $clean['lines']);
    }

    public function test_staff_id_and_dates_are_validated(): void
    {
        $this->assertNotEmpty($this->svc->validate($this->assignment(['staffId' => '']), $this->catalogue())[1]);
        // staffId becomes part of the document id, so it must be path-safe.
        $this->assertNotEmpty($this->svc->validate($this->assignment(['staffId' => 'STA/0001']), $this->catalogue())[1]);
        $this->assertNotEmpty($this->svc->validate($this->assignment(['effectiveFrom' => '2026-13-01']), $this->catalogue())[1]);
        $this->assertNotEmpty($this->svc->validate($this->assignment(['effectiveTo' => '2026-03-31']), $this->catalogue())[1], 'to before from');
    }

    public function test_calc_type_comes_from_the_catalogue_not_the_payload(): void
    {
        // Otherwise one employee's PF could silently become a fixed amount.
        [$clean, $errors] = $this->svc->validate($this->assignment(['lines' => [
            ['componentId' => 'PF_EMPLOYEE', 'calcType' => 'FIXED', 'amountPaise' => 999, 'rate' => 12],
        ]]), $this->catalogue());
        $this->assertNotEmpty($errors, 'the catalogue says PF_EMPLOYEE is PERCENT, so amountPaise must be refused');
    }

    public function test_a_line_must_reference_a_known_component(): void
    {
        $this->assertNotEmpty(
            $this->svc->validate($this->assignment(['lines' => [
                ['componentId' => 'INVENTED', 'amountPaise' => 100],
            ]]), $this->catalogue())[1]
        );
    }

    public function test_duplicate_component_lines_are_rejected(): void
    {
        $this->assertNotEmpty(
            $this->svc->validate($this->assignment(['lines' => [
                ['componentId' => 'BASIC', 'amountPaise' => 100],
                ['componentId' => 'BASIC', 'amountPaise' => 200],
            ]]), $this->catalogue())[1]
        );
    }

    public function test_money_must_be_integer_paise_not_rupees(): void
    {
        foreach ([30000.50, '30000.50', -1] as $bad) {
            $this->assertNotEmpty(
                $this->svc->validate($this->assignment(['lines' => [
                    ['componentId' => 'BASIC', 'amountPaise' => $bad],
                ]]), $this->catalogue())[1],
                'amountPaise ' . var_export($bad, true)
            );
        }
    }

    public function test_percent_line_rate_bounds(): void
    {
        $line = fn($rate) => $this->assignment(['lines' => [['componentId' => 'PF_EMPLOYEE', 'rate' => $rate]]]);
        $this->assertNotEmpty($this->svc->validate($line(-1), $this->catalogue())[1]);
        $this->assertNotEmpty($this->svc->validate($line(101), $this->catalogue())[1]);
        $this->assertSame([], $this->svc->validate($line(12), $this->catalogue())[1]);
    }

    public function test_unwired_pay_bases_are_refused_but_still_modelled(): void
    {
        foreach (['DAILY', 'HOURLY'] as $basis) {
            $this->assertNotEmpty(
                $this->svc->validate($this->assignment(['payBasis' => $basis]), $this->catalogue())[1],
                "{$basis} has no calculator yet"
            );
            $this->assertContains($basis, \Salary_assignment_service::PAY_BASES, 'still in the model');
        }
        $this->assertSame([], $this->svc->validate($this->assignment(['payBasis' => 'MONTHLY_FIXED']), $this->catalogue())[1]);
    }

    // ── BALANCING ────────────────────────────────────────────────────────

    private function balancingCatalogue(): array
    {
        $cat = $this->catalogue();
        $cat['SPECIAL_ALLOWANCE'] = [
            'code' => 'SPECIAL_ALLOWANCE', 'name' => 'Special Allowance', 'kind' => 'earning',
            'calcType' => 'BALANCING', 'basis' => 'NONE', 'rate' => null, 'amountPaise' => null,
        ];
        return $cat;
    }

    public function test_balancing_line_must_not_carry_a_value(): void
    {
        $this->assertNotEmpty(
            $this->svc->validate($this->assignment(['lines' => [
                ['componentId' => 'BASIC', 'amountPaise' => 3000000],
                ['componentId' => 'SPECIAL_ALLOWANCE', 'amountPaise' => 500000],
            ]]), $this->balancingCatalogue())[1],
            'its value is derived; supplying one creates a second source of truth'
        );
    }

    public function test_only_one_balancing_component_is_allowed(): void
    {
        $cat = $this->balancingCatalogue();
        $cat['SECOND_BAL'] = array_merge($cat['SPECIAL_ALLOWANCE'], ['code' => 'SECOND_BAL']);
        $this->assertNotEmpty(
            $this->svc->validate($this->assignment(['lines' => [
                ['componentId' => 'BASIC', 'amountPaise' => 3000000],
                ['componentId' => 'SPECIAL_ALLOWANCE'],
                ['componentId' => 'SECOND_BAL'],
            ]]), $cat)[1],
            'two components cannot both absorb the remainder'
        );
    }

    public function test_balancing_requires_a_gross_to_balance_against(): void
    {
        $this->assertNotEmpty(
            $this->svc->validate($this->assignment([
                'grossPaise' => null,
                'lines' => [['componentId' => 'BASIC', 'amountPaise' => 3000000],
                            ['componentId' => 'SPECIAL_ALLOWANCE']],
            ]), $this->balancingCatalogue())[1]
        );
    }

    public function test_balancing_absorbs_the_remainder_exactly(): void
    {
        $a = [
            'grossPaise' => 5000000,   // ₹50,000
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED',   'amountPaise' => 2000000],
                ['componentId' => 'HRA',   'calcType' => 'PERCENT', 'basis' => 'BASIC', 'rate' => 40.0],
                ['componentId' => 'SPECIAL_ALLOWANCE', 'calcType' => 'BALANCING'],
            ],
        ];
        $r = \Salary_assignment_service::splitGross($a, $this->balancingCatalogue());

        $this->assertSame(2000000, $r['components']['BASIC']);
        $this->assertSame(800000,  $r['components']['HRA'], '40% of basic');
        $this->assertSame(2200000, $r['components']['SPECIAL_ALLOWANCE'], 'the remainder');
        $this->assertTrue($r['balanced'], 'the parts must sum exactly to gross');
        $this->assertSame(0, $r['remainderPaise']);
        $this->assertSame(5000000, array_sum($r['components']));
    }

    public function test_an_over_subscribed_structure_is_visible_not_silently_clamped(): void
    {
        // Components already exceed gross. The balancing line must not go
        // negative, and the caller must be able to SEE the shortfall.
        $a = [
            'grossPaise' => 1000000,
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED', 'amountPaise' => 1500000],
                ['componentId' => 'SPECIAL_ALLOWANCE', 'calcType' => 'BALANCING'],
            ],
        ];
        $r = \Salary_assignment_service::splitGross($a, $this->balancingCatalogue());
        $this->assertSame(0, $r['components']['SPECIAL_ALLOWANCE'], 'never negative');
        $this->assertFalse($r['balanced']);
        $this->assertLessThan(0, $r['remainderPaise'], 'the overshoot is reported, not hidden');
    }

    public function test_split_without_a_balancing_line_reports_the_shortfall(): void
    {
        $r = \Salary_assignment_service::splitGross([
            'grossPaise' => 5000000,
            'lines' => [['componentId' => 'BASIC', 'calcType' => 'FIXED', 'amountPaise' => 2000000]],
        ], $this->catalogue());
        $this->assertFalse($r['balanced']);
        $this->assertSame(3000000, $r['remainderPaise'], 'unassigned gross is surfaced');
    }

    public function test_split_only_distributes_earnings(): void
    {
        // Deductions are priced by the statutory engine and the calculator, not
        // by the gross split — including one here would double-count.
        $r = \Salary_assignment_service::splitGross([
            'grossPaise' => 3000000,
            'lines' => [
                ['componentId' => 'BASIC', 'calcType' => 'FIXED', 'amountPaise' => 3000000],
                ['componentId' => 'PF_EMPLOYEE', 'calcType' => 'PERCENT', 'basis' => 'BASIC', 'rate' => 12.0],
            ],
        ], $this->catalogue());
        $this->assertArrayNotHasKey('PF_EMPLOYEE', $r['components']);
        $this->assertTrue($r['balanced']);
    }

    // ── Effective dating (shared helper) ─────────────────────────────────

    public function test_assignment_windows_resolve_by_date(): void
    {
        $set = [
            ['effectiveFrom' => '2025-04-01', 'effectiveTo' => '2026-03-31', 'tag' => 'old'],
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,         'tag' => 'new'],
        ];
        $this->assertSame('old', ed_select_for($set, '2026-03-31')['tag']);
        $this->assertSame('new', ed_select_for($set, '2026-04-01')['tag']);
        $this->assertNull(ed_select_for($set, '2025-01-01'), 'before any assignment');
    }

    public function test_a_revision_supersedes_rather_than_overwrites(): void
    {
        // The entire reason for dating assignments: March must still resolve to
        // the old figure after an April revision is added.
        $set = [
            ['effectiveFrom' => '2025-04-01', 'effectiveTo' => '2026-03-31', 'grossPaise' => 3000000],
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,         'grossPaise' => 4200000],
        ];
        $this->assertSame(3000000, ed_select_for($set, '2026-03-15')['grossPaise']);
        $this->assertSame(4200000, ed_select_for($set, '2026-04-15')['grossPaise']);
    }

    public function test_overlapping_assignment_windows_are_detected(): void
    {
        $this->assertNotEmpty(ed_find_overlaps([
            ['effectiveFrom' => '2025-04-01', 'effectiveTo' => null],
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null],
        ], 'assignment'));
        $this->assertSame([], ed_find_overlaps([
            ['effectiveFrom' => '2025-04-01', 'effectiveTo' => '2026-03-31'],
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null],
        ], 'assignment'));
    }

    public function test_effective_dating_is_shared_with_the_statutory_engine(): void
    {
        // One implementation, not two — otherwise a period could resolve to one
        // statutory config but a different salary assignment.
        $set = [['effectiveFrom' => '2026-04-01', 'effectiveTo' => null, 'tag' => 'x']];
        $this->assertSame(
            \Statutory_config_service::selectFor($set, '2026-06-01'),
            ed_select_for($set, '2026-06-01')
        );
    }

    // ── Readiness: a setup requirement, never a silent zero ─────────────

    public function test_readiness_names_the_people_who_block_payroll(): void
    {
        $svc = new class extends \Salary_assignment_service {
            public array $byStaff = [];
            public function listForStaff(string $staffId): array { return $this->byStaff[$staffId] ?? []; }
        };
        $svc->byStaff = [
            'STA0001' => [['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
                           'status' => 'active', 'lines' => [['componentId' => 'BASIC']]]],
            'STA0002' => [],                                   // never configured
            'STA0003' => [['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
                           'status' => 'draft', 'lines' => [['componentId' => 'BASIC']]]],
            'STA0004' => [['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
                           'status' => 'active', 'lines' => []]],
        ];

        $r = $svc->readiness([
            ['staffId' => 'STA0001', 'name' => 'Anita'],
            ['staffId' => 'STA0002', 'name' => 'Bhaskar'],
            ['staffId' => 'STA0003', 'name' => 'Chandra'],
            ['staffId' => 'STA0004', 'name' => 'Devi'],
        ], '2026-06-01');

        $this->assertSame(1, $r['summary']['ready']);
        $this->assertSame(3, $r['summary']['blocked']);
        $this->assertFalse($r['summary']['payrollEligible'], 'payroll must be blocked, not run with zeros');

        // Named people with actionable reasons — not an aggregate count.
        $names = array_column($r['blocked'], 'name');
        $this->assertContains('Bhaskar', $names);
        $this->assertContains('Chandra', $names);
        $this->assertContains('Devi', $names);
        foreach ($r['blocked'] as $b) {
            $this->assertNotSame('', $b['reason']);
            $this->assertArrayHasKey('staffId', $b);
        }
    }

    public function test_payroll_is_eligible_only_when_nobody_is_blocked(): void
    {
        $svc = new class extends \Salary_assignment_service {
            public function listForStaff(string $staffId): array {
                return [['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
                         'status' => 'active', 'lines' => [['componentId' => 'BASIC']]]];
            }
        };
        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Anita']], '2026-06-01');
        $this->assertTrue($r['summary']['payrollEligible']);
        $this->assertSame([], $r['blocked']);
    }

    // ── Legacy mapping (dry-run migration input) ────────────────────────

    private function legacy(): array
    {
        return [
            'basic' => 30000, 'hra' => 12000, 'da' => 0, 'ta' => 0,
            'medical' => 0, 'other_allowances' => 0,
            'pf_employee' => 12, 'pf_employer' => 12,
            'esi_employee' => 0.75, 'esi_employer' => 3.25,
            'professional_tax' => 200, 'tds' => 0, 'other_deductions' => 0,
        ];
    }

    public function test_legacy_rupees_become_integer_paise_exactly_once(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01');
        $lines = array_column($a['lines'], null, 'componentId');

        $this->assertSame(3000000, $lines['BASIC']['amountPaise'], '₹30,000 → paise');
        $this->assertSame(1200000, $lines['HRA']['amountPaise']);
        $this->assertSame(20000,   $lines['PROFESSIONAL_TAX']['amountPaise'], '₹200 → paise');
    }

    public function test_legacy_percentages_stay_percentages(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01');
        $lines = array_column($a['lines'], null, 'componentId');

        // The percent/money split the legacy record never declared.
        $this->assertSame(12.0, $lines['PF_EMPLOYEE']['rate']);
        $this->assertNull($lines['PF_EMPLOYEE']['amountPaise'], 'a rate is not money');
        $this->assertSame(0.75, $lines['ESI_EMPLOYEE']['rate']);
        $this->assertSame(3.25, $lines['ESI_EMPLOYER']['rate']);
    }

    public function test_legacy_percent_lines_carry_the_calculator_bases(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01');
        $lines = array_column($a['lines'], null, 'componentId');

        $this->assertSame('BASIC', $lines['PF_EMPLOYEE']['basis'], 'PF is charged on basic');
        $this->assertSame('BASIC', $lines['PF_EMPLOYER']['basis']);
        $this->assertSame('GROSS', $lines['ESI_EMPLOYEE']['basis'], 'ESI is charged on gross');
        $this->assertSame('GROSS', $lines['TDS']['basis']);
    }

    public function test_legacy_gross_is_the_sum_of_earnings_only(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01');
        // 30,000 + 12,000 = ₹42,000 — deductions must not inflate gross.
        $this->assertSame(4200000, $a['grossPaise']);
    }

    public function test_migrated_assignment_passes_validation(): void
    {
        $a = \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01');
        [, $errors] = $this->svc->validate($a, $this->catalogue());
        $this->assertSame([], $errors, 'the migration must not produce a record the service would reject: ' . implode(' ', $errors));
    }

    public function test_migration_is_deterministic_for_idempotency(): void
    {
        // The doc id is {staffId}_{effectiveFrom}, so re-running maps to the
        // same document rather than creating a duplicate.
        $this->assertSame(
            \Salary_assignment_service::entityId('STA0001', '2026-04-01'),
            \Salary_assignment_service::entityId('STA0001', '2026-04-01')
        );
        $this->assertSame('STA0001_2026-04-01', \Salary_assignment_service::entityId('STA0001', '2026-04-01'));
        $this->assertEquals(
            \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01'),
            \Salary_assignment_service::fromLegacyStructure($this->legacy(), 'STA0001', '2026-04-01')
        );
    }

    // ── Boundary ─────────────────────────────────────────────────────────

    public function test_payroll_path_is_untouched_by_w2_3(): void
    {
        foreach (['Hr.php', 'Payroll.php'] as $f) {
            $src = file_get_contents(__DIR__ . '/../../application/controllers/' . $f);
            $this->assertNotFalse($src);
            $this->assertStringNotContainsString('staffSalaryAssignments', $src, "{$f} must not read assignments until W2.4");
            $this->assertStringNotContainsString('Salary_assignment_service', $src);
        }
    }

    public function test_no_rtdb_and_no_hardcoded_tenant(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Salary_assignment_service.php');
        $this->assertNotFalse($src);
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src);
    }
}
