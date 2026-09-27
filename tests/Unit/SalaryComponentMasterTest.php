<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';

/**
 * ZenX HRMS W2.1 — salary component master.
 *
 * BEHAVIOURAL, not source-level: `validate()` and `systemComponents()` are pure
 * (no Firestore, no session), so these tests execute the real production code.
 *
 * The seed assertions are the important half. The thirteen legacy salary fields
 * carry semantics that live ONLY inside `Hr::_compute_payslip()` — the record
 * itself never says whether a number is a percentage or an amount, what base a
 * rate applies to, or whether attendance prorates it. W2.2 (statutory engine)
 * and W2.4 (reader) will trust the catalogue's declared metadata instead of
 * re-deriving those rules, so a wrong seed would silently mis-describe live
 * payroll. Each assertion below pins one rule read out of the calculator.
 */
class SalaryComponentMasterTest extends TestCase
{
    private \Salary_component_service $svc;

    protected function setUp(): void
    {
        $this->svc = new \Salary_component_service();
    }

    /** @return array{0:array,1:array} */
    private function v(array $in, bool $create = true, array $known = []): array
    {
        return $this->svc->validate($in, $create, $known);
    }

    private function validEarning(array $over = []): array
    {
        return array_merge([
            'code' => 'CONVEYANCE', 'name' => 'Conveyance', 'kind' => 'earning',
            'calcType' => 'FIXED', 'basis' => 'NONE', 'amountPaise' => 250000,
            'displayOrder' => 70, 'active' => true,
        ], $over);
    }

    private function byCode(string $code): array
    {
        foreach (\Salary_component_service::systemComponents() as $c) {
            if ($c['code'] === $code) return $c;
        }
        $this->fail("System component {$code} missing from the seed.");
    }

    // ── Validation: structure ────────────────────────────────────────────

    public function test_a_valid_fixed_earning_passes(): void
    {
        [$clean, $errors] = $this->v($this->validEarning());
        $this->assertSame([], $errors);
        $this->assertSame('CONVEYANCE', $clean['code']);
        $this->assertSame(250000, $clean['amountPaise']);
        $this->assertNull($clean['rate']);
    }

    public function test_code_is_required_and_shape_checked(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['code' => '']))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['code' => '9BAD']))[1], 'must start with a letter');
        $this->assertNotEmpty($this->v($this->validEarning(['code' => 'has space']))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['code' => 'A']))[1], 'too short');
        // Lowercase input is normalised, not rejected.
        [$clean, $errors] = $this->v($this->validEarning(['code' => 'conveyance']));
        $this->assertSame([], $errors);
        $this->assertSame('CONVEYANCE', $clean['code']);
    }

    public function test_name_is_required_and_length_capped(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['name' => '']))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['name' => str_repeat('x', 200)]))[1]);
    }

    public function test_kind_calctype_basis_and_statutory_enums_are_enforced(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['kind' => 'bonus']))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['calcType' => 'MAGIC']))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['basis' => 'MOON']))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['statutory' => 'GST']))[1]);

        foreach (\Salary_component_service::STATUTORY as $s) {
            [$clean, $errors] = $this->v($this->validEarning(['statutory' => $s]));
            $this->assertSame([], $errors, "statutory {$s} should be accepted");
            $this->assertSame($s, $clean['statutory']);
        }
        $this->assertNull($this->v($this->validEarning(['statutory' => null]))[0]['statutory']);
    }

    // ── Validation: the percent-vs-money split (the core of W2.1) ────────

    public function test_percent_component_requires_a_rate_and_refuses_money(): void
    {
        $pct = ['code' => 'PF_X', 'name' => 'PF', 'kind' => 'deduction',
                'calcType' => 'PERCENT', 'basis' => 'BASIC'];

        $this->assertNotEmpty($this->v($pct)[1], 'PERCENT without rate must fail');

        [$clean, $errors] = $this->v($pct + ['rate' => 12]);
        $this->assertSame([], $errors);
        $this->assertSame(12.0, $clean['rate']);
        $this->assertNull($clean['amountPaise'], 'a rate is not money');

        // The corruption this whole model exists to prevent.
        $this->assertNotEmpty(
            $this->v($pct + ['rate' => 12, 'amountPaise' => 50000])[1],
            'PERCENT carrying amountPaise must be rejected'
        );
    }

    public function test_percent_rate_bounds(): void
    {
        $pct = ['code' => 'PF_X', 'name' => 'PF', 'kind' => 'deduction',
                'calcType' => 'PERCENT', 'basis' => 'BASIC'];
        $this->assertNotEmpty($this->v($pct + ['rate' => -1])[1]);
        $this->assertNotEmpty($this->v($pct + ['rate' => 100.01])[1]);
        $this->assertNotEmpty($this->v($pct + ['rate' => 'abc'])[1]);
        $this->assertSame([], $this->v($pct + ['rate' => 0])[1]);
        $this->assertSame([], $this->v($pct + ['rate' => 100])[1]);
    }

    public function test_percent_component_needs_a_basis(): void
    {
        $this->assertNotEmpty(
            $this->v(['code' => 'PF_X', 'name' => 'PF', 'kind' => 'deduction',
                      'calcType' => 'PERCENT', 'basis' => 'NONE', 'rate' => 12])[1],
            'a rate with nothing to apply to is meaningless'
        );
    }

    public function test_fixed_component_requires_integer_paise_not_rupees(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['amountPaise' => null]))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['amountPaise' => -1]))[1]);
        // A decimal here means someone passed rupees — the exact W1.2 regression
        // this guard exists to stop.
        $this->assertNotEmpty($this->v($this->validEarning(['amountPaise' => 2500.50]))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['amountPaise' => '2500.50']))[1]);
        $this->assertSame([], $this->v($this->validEarning(['amountPaise' => '250000']))[1]);
    }

    public function test_fixed_component_refuses_a_rate(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['rate' => 10]))[1]);
    }

    // ── Validation: basis / basisComponents ─────────────────────────────

    public function test_component_set_basis_requires_and_validates_members(): void
    {
        $base = ['code' => 'SPECIAL', 'name' => 'Special', 'kind' => 'deduction',
                 'calcType' => 'PERCENT', 'rate' => 5, 'basis' => 'COMPONENT_SET'];

        $this->assertNotEmpty($this->v($base)[1], 'empty basisComponents must fail');

        $this->assertNotEmpty(
            $this->v($base + ['basisComponents' => ['NOPE']], true, ['BASIC', 'HRA'])[1],
            'unknown component code must fail'
        );

        [$clean, $errors] = $this->v($base + ['basisComponents' => ['basic', 'hra']], true, ['BASIC', 'HRA']);
        $this->assertSame([], $errors);
        $this->assertSame(['BASIC', 'HRA'], $clean['basisComponents']);
    }

    public function test_component_cannot_reference_itself(): void
    {
        $this->assertNotEmpty(
            $this->v(['code' => 'LOOP', 'name' => 'Loop', 'kind' => 'deduction',
                      'calcType' => 'PERCENT', 'rate' => 5, 'basis' => 'COMPONENT_SET',
                      'basisComponents' => ['LOOP']], true, ['LOOP'])[1]
        );
    }

    public function test_basis_components_rejected_when_basis_is_not_component_set(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['basisComponents' => ['BASIC']]))[1]);
    }

    public function test_formula_is_modelled_but_refused_until_an_evaluator_exists(): void
    {
        $this->assertNotEmpty(
            $this->v($this->validEarning(['calcType' => 'FORMULA']))[1],
            'storing a FORMULA component with no evaluator would create uncomputable data'
        );
        $this->assertContains('FORMULA', \Salary_component_service::CALC_TYPES, 'still part of the model');
    }

    public function test_display_order_must_be_a_non_negative_integer(): void
    {
        $this->assertNotEmpty($this->v($this->validEarning(['displayOrder' => -5]))[1]);
        $this->assertNotEmpty($this->v($this->validEarning(['displayOrder' => 'abc']))[1]);
        $this->assertSame(70, $this->v($this->validEarning(['displayOrder' => '70']))[0]['displayOrder']);
    }

    public function test_system_locked_can_never_be_set_through_the_api(): void
    {
        $this->assertFalse(
            $this->v($this->validEarning(['systemLocked' => true]))[0]['systemLocked'],
            'only the seed may create system components'
        );
    }

    // ── The seed: encodes the calculator's real rules ────────────────────

    public function test_seed_has_exactly_the_thirteen_legacy_fields(): void
    {
        $codes = array_column(\Salary_component_service::systemComponents(), 'code');
        sort($codes);
        $expected = [
            'BASIC', 'DA', 'ESI_EMPLOYEE', 'ESI_EMPLOYER', 'HRA', 'MEDICAL',
            'OTHER_ALLOWANCES', 'OTHER_DEDUCTIONS', 'PF_EMPLOYEE', 'PF_EMPLOYER',
            'PROFESSIONAL_TAX', 'TA', 'TDS',
        ];
        sort($expected);
        $this->assertSame($expected, $codes);
        $this->assertCount(13, $codes);
    }

    public function test_every_seeded_component_is_system_locked_and_valid(): void
    {
        $known = array_column(\Salary_component_service::systemComponents(), 'code');
        foreach (\Salary_component_service::systemComponents() as $c) {
            $this->assertTrue($c['systemLocked'], "{$c['code']} must be systemLocked");
            $this->assertTrue($c['active'], "{$c['code']} must be active");
            // Validate the seed through the same validator the API uses, so the
            // seed can never drift into a shape the service would reject.
            [, $errors] = $this->v($c, true, $known);
            $this->assertSame([], $errors, "seed component {$c['code']} fails validation: " . implode(' ', $errors));
        }
    }

    /**
     * `_compute_payslip()`:
     *   $pfEmployeeP = _applyRatio($effectiveBasicP, $pfPct / 100)   → PERCENT of BASIC
     *   $esiEmployeeP = _applyRatio($grossP, $esiPct / 100)          → PERCENT of GROSS
     *   $tdsP        = _applyRatio($grossP, $tdsPct / 100)           → PERCENT of GROSS
     */
    public function test_percentage_components_carry_their_real_calculation_basis(): void
    {
        foreach (['PF_EMPLOYEE' => 'BASIC', 'PF_EMPLOYER' => 'BASIC',
                  'ESI_EMPLOYEE' => 'GROSS', 'ESI_EMPLOYER' => 'GROSS',
                  'TDS' => 'GROSS'] as $code => $basis) {
            $c = $this->byCode($code);
            $this->assertSame('PERCENT', $c['calcType'], "{$code} is a percentage in the calculator");
            $this->assertSame($basis, $c['basis'], "{$code} is charged on {$basis}");
            $this->assertNull($c['amountPaise'], "{$code} must not carry money");
        }
    }

    /**
     * `_compute_payslip()`:
     *   $professionalTaxP = _toPaise($sal['professional_tax'])   ← no retention
     *   $otherDeductionsP = _toPaise($sal['other_deductions'])   ← no retention
     *
     * The asymmetry most likely to be got wrong from field names alone.
     */
    public function test_flat_deductions_are_money_and_are_not_prorated(): void
    {
        foreach (['PROFESSIONAL_TAX', 'OTHER_DEDUCTIONS'] as $code) {
            $c = $this->byCode($code);
            $this->assertSame('FIXED', $c['calcType'], "{$code} is an amount, not a percentage");
            $this->assertNull($c['rate'], "{$code} must not carry a rate");
            $this->assertFalse(
                $c['prorated'],
                "{$code} is taken at face value regardless of attendance — the calculator applies no retention to it"
            );
        }
    }

    /** Earnings are scaled by the attendance retention ratio. */
    public function test_earnings_are_fixed_amounts_and_prorated(): void
    {
        foreach (['BASIC', 'HRA', 'DA', 'TA', 'MEDICAL', 'OTHER_ALLOWANCES'] as $code) {
            $c = $this->byCode($code);
            $this->assertSame('earning', $c['kind']);
            $this->assertSame('FIXED', $c['calcType']);
            $this->assertTrue($c['prorated'], "{$code} is scaled by _applyRatio(..., \$retention)");
        }
    }

    /**
     * PF's base is basic; ESI's base is gross. So only BASIC is pfApplicable,
     * while every earning is esiApplicable.
     */
    public function test_pf_and_esi_applicability_matches_the_calculation_bases(): void
    {
        $this->assertTrue($this->byCode('BASIC')['pfApplicable']);
        foreach (['HRA', 'DA', 'TA', 'MEDICAL', 'OTHER_ALLOWANCES'] as $code) {
            $this->assertFalse($this->byCode($code)['pfApplicable'], "PF is charged on basic only, not {$code}");
        }
        foreach (['BASIC', 'HRA', 'DA', 'TA', 'MEDICAL', 'OTHER_ALLOWANCES'] as $code) {
            $this->assertTrue($this->byCode($code)['esiApplicable'], "ESI is charged on gross, which includes {$code}");
        }
    }

    public function test_statutory_tagging_is_correct(): void
    {
        $this->assertSame('PF',  $this->byCode('PF_EMPLOYEE')['statutory']);
        $this->assertSame('PF',  $this->byCode('PF_EMPLOYER')['statutory']);
        $this->assertSame('ESI', $this->byCode('ESI_EMPLOYEE')['statutory']);
        $this->assertSame('ESI', $this->byCode('ESI_EMPLOYER')['statutory']);
        $this->assertSame('PT',  $this->byCode('PROFESSIONAL_TAX')['statutory']);
        $this->assertSame('TDS', $this->byCode('TDS')['statutory']);
        $this->assertNull($this->byCode('OTHER_DEDUCTIONS')['statutory'], 'not a statutory head');
        $this->assertNull($this->byCode('BASIC')['statutory']);
    }

    public function test_kinds_are_correctly_partitioned(): void
    {
        $byKind = [];
        foreach (\Salary_component_service::systemComponents() as $c) {
            $byKind[$c['kind']][] = $c['code'];
        }
        $this->assertCount(6, $byKind['earning']);
        $this->assertCount(5, $byKind['deduction']);
        $this->assertCount(2, $byKind['employer_contribution']);
        $this->assertSame(['PF_EMPLOYER', 'ESI_EMPLOYER'], $byKind['employer_contribution']);
    }

    public function test_seeded_amounts_and_rates_are_zero(): void
    {
        // The catalogue defines what a component IS. Per-employee values stay on
        // the legacy structure until W2.3 — seeding non-zero values here would
        // imply a compensation policy nobody set.
        foreach (\Salary_component_service::systemComponents() as $c) {
            if ($c['calcType'] === 'FIXED')   $this->assertSame(0, $c['amountPaise'], "{$c['code']}");
            if ($c['calcType'] === 'PERCENT') $this->assertSame(0.0, $c['rate'], "{$c['code']}");
        }
    }

    public function test_display_order_is_unique_and_groups_by_kind(): void
    {
        $orders = array_column(\Salary_component_service::systemComponents(), 'displayOrder');
        $this->assertSame(count($orders), count(array_unique($orders)), 'display order must be unambiguous');
        // earnings < employee deductions < employer contributions
        $this->assertLessThan($this->byCode('PF_EMPLOYEE')['displayOrder'], $this->byCode('OTHER_ALLOWANCES')['displayOrder']);
        $this->assertLessThan($this->byCode('PF_EMPLOYER')['displayOrder'], $this->byCode('OTHER_DEDUCTIONS')['displayOrder']);
    }

    /**
     * W2.1 GUARD: this gate must not touch the payroll path. If the calculator
     * or the legacy structure writer ever starts consulting the catalogue, that
     * is a real change of behaviour and must be a reviewed gate (W2.4), not a
     * side effect of adding components.
     */
    public function test_payroll_path_does_not_consult_the_catalogue_yet(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);
        $this->assertStringNotContainsString(
            'salaryComponents',
            $hr,
            'W2.1 is additive: Hr.php must not read the component catalogue until the W2.4 reader gate.'
        );
        $this->assertStringNotContainsString('Salary_component_service', $hr);
    }
}
