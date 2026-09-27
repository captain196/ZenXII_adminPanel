<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/helpers/effective_dating_helper.php';
require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';
require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_assignment_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_grade_service.php';
require_once __DIR__ . '/../../application/libraries/Salary_template_service.php';

/**
 * ZenX HRMS W2.6 — salary structures and end-to-end compensation integration.
 *
 * The second half of this file is the point: it walks the whole chain the
 * product promises —
 *
 *   template → gross-down → assignment → validation → split → CTC → band
 *            → statutory resolution → readiness
 *
 * — and asserts that each stage consumes the previous one's real output rather
 * than a convenient fixture. A chain that only works when each link is tested
 * in isolation is not an integration.
 */
class SalaryTemplateAndIntegrationTest extends TestCase
{
    private \Salary_template_service $tpl;
    private \Salary_assignment_service $asg;

    protected function setUp(): void
    {
        $this->tpl = new \Salary_template_service();
        $this->asg = new \Salary_assignment_service();
    }

    /** System catalogue plus a balancing allowance, which real structures need. */
    private function catalogue(): array
    {
        $out = [];
        foreach (\Salary_component_service::systemComponents() as $c) $out[$c['code']] = $c;
        $out['SPECIAL_ALLOWANCE'] = [
            'code' => 'SPECIAL_ALLOWANCE', 'name' => 'Special Allowance', 'kind' => 'earning',
            'calcType' => 'BALANCING', 'basis' => 'NONE', 'rate' => null, 'amountPaise' => null,
            'prorated' => true, 'active' => true,
        ];
        return $out;
    }

    private function template(array $over = []): array
    {
        return array_merge([
            'code' => 'TEACHING', 'name' => 'Teaching Staff', 'active' => true,
            'lines' => [
                ['componentId' => 'BASIC', 'amountPaise' => 2000000],
                ['componentId' => 'HRA',   'amountPaise' => 800000],
                ['componentId' => 'SPECIAL_ALLOWANCE'],
                ['componentId' => 'PF_EMPLOYEE',  'rate' => 12.0],
                ['componentId' => 'PF_EMPLOYER',  'rate' => 12.0],
                ['componentId' => 'ESI_EMPLOYEE', 'rate' => 0.75],
                ['componentId' => 'ESI_EMPLOYER', 'rate' => 3.25],
            ],
        ], $over);
    }

    // ── Template validation ──────────────────────────────────────────────

    public function test_a_valid_template_passes(): void
    {
        [$clean, $errors] = $this->tpl->validate($this->template(), $this->catalogue());
        $this->assertSame([], $errors);
        $this->assertSame('TEACHING', $clean['code']);
        $this->assertCount(7, $clean['lines']);
    }

    public function test_template_code_and_name_are_validated(): void
    {
        foreach (['', '1X', 'has space', 'A'] as $bad) {
            $this->assertNotEmpty($this->tpl->validate($this->template(['code' => $bad]), $this->catalogue())[1], "'{$bad}'");
        }
        $this->assertNotEmpty($this->tpl->validate($this->template(['name' => '']), $this->catalogue())[1]);
    }

    public function test_calc_type_comes_from_the_catalogue_not_the_template(): void
    {
        // Otherwise a structure could redefine what a component IS.
        [, $errors] = $this->tpl->validate($this->template(['lines' => [
            ['componentId' => 'PF_EMPLOYEE', 'amountPaise' => 500000],   // PF is PERCENT
        ]]), $this->catalogue());
        $this->assertNotEmpty($errors);
    }

    public function test_template_needs_at_least_one_earning(): void
    {
        // A structure of pure deductions cannot describe a salary.
        $this->assertNotEmpty($this->tpl->validate($this->template(['lines' => [
            ['componentId' => 'PF_EMPLOYEE', 'rate' => 12.0],
        ]]), $this->catalogue())[1]);
        $this->assertNotEmpty($this->tpl->validate($this->template(['lines' => []]), $this->catalogue())[1]);
    }

    public function test_only_one_balancing_component_per_template(): void
    {
        $cat = $this->catalogue();
        $cat['SECOND_BAL'] = array_merge($cat['SPECIAL_ALLOWANCE'], ['code' => 'SECOND_BAL']);
        $this->assertNotEmpty($this->tpl->validate($this->template(['lines' => [
            ['componentId' => 'BASIC', 'amountPaise' => 100],
            ['componentId' => 'SPECIAL_ALLOWANCE'],
            ['componentId' => 'SECOND_BAL'],
        ]]), $cat)[1]);
    }

    public function test_balancing_line_in_a_template_carries_no_value(): void
    {
        $this->assertNotEmpty($this->tpl->validate($this->template(['lines' => [
            ['componentId' => 'BASIC', 'amountPaise' => 100],
            ['componentId' => 'SPECIAL_ALLOWANCE', 'amountPaise' => 500],
        ]]), $this->catalogue())[1]);
    }

    public function test_duplicate_and_unknown_components_are_rejected(): void
    {
        $this->assertNotEmpty($this->tpl->validate($this->template(['lines' => [
            ['componentId' => 'BASIC', 'amountPaise' => 1], ['componentId' => 'BASIC', 'amountPaise' => 2],
        ]]), $this->catalogue())[1], 'duplicate');
        $this->assertNotEmpty($this->tpl->validate($this->template(['lines' => [
            ['componentId' => 'INVENTED', 'amountPaise' => 1],
        ]]), $this->catalogue())[1], 'unknown');
    }

    // ── apply(): the gross-down step ─────────────────────────────────────

    public function test_apply_turns_a_template_into_an_assignment(): void
    {
        $a = \Salary_template_service::apply($this->template(), 'STA0001', '2026-04-01', 5000000);
        $this->assertSame('STA0001', $a['staffId']);
        $this->assertSame('TEACHING', $a['templateId'], 'provenance is recorded');
        $this->assertSame(5000000, $a['grossPaise']);
        $this->assertCount(7, $a['lines']);
    }

    public function test_a_generated_assignment_starts_as_a_draft(): void
    {
        // A structure generated from a template is reviewed before it pays.
        $a = \Salary_template_service::apply($this->template(), 'STA0001', '2026-04-01', 5000000);
        $this->assertSame('draft', $a['status']);
    }

    public function test_percent_lines_keep_their_rate_rather_than_being_frozen(): void
    {
        // If the catalogue later changes a basis, the assignment should follow
        // it — freezing a computed amount here would silently keep a stale one.
        $a = \Salary_template_service::apply($this->template(), 'STA0001', '2026-04-01', 5000000);
        $lines = array_column($a['lines'], null, 'componentId');
        $this->assertSame(12.0, $lines['PF_EMPLOYEE']['rate']);
        $this->assertNull($lines['PF_EMPLOYEE']['amountPaise']);
    }

    public function test_an_applied_template_passes_assignment_validation(): void
    {
        // The template path must not be able to produce something the
        // assignment service would reject.
        $a = \Salary_template_service::apply($this->template(), 'STA0001', '2026-04-01', 5000000);
        [, $errors] = $this->asg->validate($a, $this->catalogue());
        $this->assertSame([], $errors, implode(' ', $errors));
    }

    // ── END-TO-END: template → … → readiness ─────────────────────────────

    public function test_the_whole_compensation_chain_holds_together(): void
    {
        $cat = $this->catalogue();

        // 1. template + one gross figure
        $a = \Salary_template_service::apply($this->template(), 'STA0001', '2026-04-01', 5000000);
        $a['status'] = 'active';

        // 2. validation
        [$clean, $errors] = $this->asg->validate($a, $cat);
        $this->assertSame([], $errors);

        // 3. gross split — the balancing line absorbs the remainder EXACTLY
        $split = \Salary_assignment_service::splitGross($clean, $cat);
        $this->assertSame(2000000, $split['components']['BASIC']);
        $this->assertSame(800000,  $split['components']['HRA']);
        $this->assertSame(2200000, $split['components']['SPECIAL_ALLOWANCE']);
        $this->assertTrue($split['balanced']);
        $this->assertSame(5000000, array_sum($split['components']), 'parts sum to the whole');

        // 4. CTC = gross + employer contributions
        $ctc = \Salary_assignment_service::deriveCtc($clean, $cat);
        $this->assertSame(5000000, $ctc['grossPaise']);
        $this->assertSame(240000,  $ctc['breakdown']['PF_EMPLOYER'],  '12% of ₹20,000 basic');
        $this->assertSame(162500,  $ctc['breakdown']['ESI_EMPLOYER'], '3.25% of ₹50,000 gross');
        $this->assertSame(5402500, $ctc['ctcPaise']);

        // 5. band check
        $band = \Salary_grade_service::checkBand(
            ['code' => 'C', 'minPaise' => 2500000, 'maxPaise' => 4000000], 'C', 5000000);
        $this->assertFalse($band['inBand'], '₹50,000 is above the ₹40,000 ceiling');
        $this->assertSame('warning', $band['severity']);

        // 6. statutory resolution consumes the SAME bases the components declare
        $cfg = [
            'effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
            'pf'  => ['employeeRate' => 12.0, 'employerRate' => 12.0,
                      'wageCeilingPaise' => 1500000, 'applyCeiling' => true, 'eligibleAboveCeiling' => true],
            'esi' => ['employeeRate' => 0.75, 'employerRate' => 3.25,
                      'wageThresholdPaise' => 2100000, 'applicable' => true],
            'pt' => ['state' => '', 'slabs' => []], 'tds' => ['mode' => 'FLAT_PERCENT', 'rate' => 0.0],
        ];
        $this->assertSame($cfg, \Statutory_config_service::selectFor([$cfg], '2026-06-01'),
            'the policy in force for the assignment period');

        $pf = \Statutory_config_service::computePf($cfg, $split['components']['BASIC']);
        $this->assertTrue($pf['covered']);
        $this->assertSame(1500000, $pf['basePaise'], 'basic ₹20,000 is above the ₹15,000 ceiling, so it caps');
        $this->assertSame(180000, $pf['employeePaise']);

        // Gross ₹50,000 exceeds the ESI threshold, so this employee is not covered.
        $esi = \Statutory_config_service::computeEsi($cfg, $ctc['grossPaise']);
        $this->assertFalse($esi['covered']);
        $this->assertSame(0, $esi['employeePaise']);

        // 7. readiness sees a payable employee
        $svc = new class extends \Salary_assignment_service {
            public array $a = [];
            public function listForStaff(string $staffId): array { return $this->a; }
        };
        $svc->a = [$clean];
        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Anita']], '2026-06-01');
        $this->assertTrue($r['summary']['payrollEligible']);
    }

    public function test_a_draft_from_a_template_blocks_payroll_until_activated(): void
    {
        // The default draft status must actually be enforced downstream,
        // otherwise "review before it pays" is decoration.
        $a = \Salary_template_service::apply($this->template(), 'STA0001', '2026-04-01', 5000000);
        [$clean] = $this->asg->validate($a, $this->catalogue());

        $svc = new class extends \Salary_assignment_service {
            public array $a = [];
            public function listForStaff(string $staffId): array { return $this->a; }
        };
        $svc->a = [$clean];
        $r = $svc->readiness([['staffId' => 'STA0001', 'name' => 'Anita']], '2026-06-01');

        $this->assertFalse($r['summary']['payrollEligible']);
        $this->assertStringContainsString('draft', $r['blocked'][0]['reason']);
    }

    public function test_statutory_and_compensation_share_one_effective_dating(): void
    {
        // A period must not resolve to one statutory policy but a different
        // salary — that is the failure the shared helper prevents.
        $windows = [
            ['effectiveFrom' => '2025-04-01', 'effectiveTo' => '2026-03-31', 'tag' => 'old'],
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,         'tag' => 'new'],
        ];
        foreach (['2026-03-31' => 'old', '2026-04-01' => 'new'] as $date => $expect) {
            $this->assertSame($expect, ed_select_for($windows, $date)['tag']);
            $this->assertSame($expect, \Statutory_config_service::selectFor($windows, $date)['tag']);
        }
    }

    public function test_no_rtdb_and_no_hardcoded_tenant(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Salary_template_service.php');
        $this->assertNotFalse($src);
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src);
    }

    /**
     * W3.1 RE-STATED. `Compensation_reader` left this list deliberately: the
     * payroll run now resolves compensation through the seam, so referencing it
     * is required, not forbidden. Everything else here still holds — templates
     * and assignments remain invisible to the payroll controllers.
     */
    public function test_payroll_path_is_still_untouched(): void
    {
        foreach (['Hr.php', 'Payroll.php'] as $f) {
            $src = (string) file_get_contents(__DIR__ . '/../../application/controllers/' . $f);
            foreach (['salaryStructureTemplates', 'Salary_template_service',
                      'staffSalaryAssignments'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$f} must not reference {$needle} yet");
            }
        }

        // Payroll.php is not part of the W3.1 seam and must stay clear of it.
        $payroll = (string) file_get_contents(__DIR__ . '/../../application/controllers/Payroll.php');
        $this->assertStringNotContainsString('Compensation_reader', $payroll);
    }
}
