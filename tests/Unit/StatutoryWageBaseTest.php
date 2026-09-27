<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * `statutory_wage_base` — the labour-codes wage definition.
 *
 * Executes the real class. It is pure and paise-exact, so no harness is needed.
 *
 * The rule under test comes from this repository's own governing spec
 * (`docs/specs/hr-payroll-brief.md` §6.3), not from recall:
 *
 *     wages = basic + DA + retaining allowance
 *     excluded: HRA, conveyance, OT allowance, employer PF, statutory bonus, gratuity
 *     "where excluded components exceed 50% of total remuneration, the excess
 *      is added back into the wage base"
 *
 * The add-back is the substance. Without it a structure can be built as a token
 * basic plus a large allowance stack, shrinking PF and gratuity liability while
 * paying the same total. Once the excluded part passes half of remuneration,
 * whatever spills over that half is pulled back in.
 *
 * NOT WIRED INTO PAYROLL. `_compute_payslip()` still computes PF on
 * `effectiveBasic`. Switching it changes what every employee at every
 * PF-configured tenant contributes and needs CA sign-off — a gated cutover,
 * not a side effect of writing the function.
 */
final class StatutoryWageBaseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Statutory_wage_base.php';
    }

    private function compute(array $s): array
    {
        return Statutory_wage_base::compute($s);
    }

    // ── The ordinary case: a compliant structure ─────────────────────────

    /** Basic at half or more of total — nothing is added back. */
    public function test_a_compliant_structure_needs_no_add_back(): void
    {
        // basic 30,000 of 50,000 total → excluded 20,000 ≤ half (25,000)
        $r = $this->compute(['basic' => 30000, 'hra' => 15000, 'ta' => 5000]);

        $this->assertFalse($r['addBackApplied']);
        $this->assertSame(3000000, $r['basePaise'], 'the base is simply basic + DA');
        $this->assertSame(0, $r['addBackPaise']);
    }

    public function test_da_and_retaining_allowance_are_wages(): void
    {
        $r = $this->compute(['basic' => 20000, 'da' => 8000, 'retaining_allowance' => 2000]);

        $this->assertSame(3000000, $r['includedPaise'], 'basic + DA + retaining are all wages');
        $this->assertFalse($r['addBackApplied'], 'with nothing excluded there is nothing to add back');
    }

    public function test_excluded_components_are_identified(): void
    {
        $r = $this->compute(['basic' => 50000, 'hra' => 10000, 'conveyance' => 2000, 'medical' => 1000]);

        $this->assertSame(1300000, $r['excludedPaise']);
        $this->assertSame(5000000, $r['includedPaise']);
    }

    // ── The add-back — the point of the rule ─────────────────────────────

    /**
     * THE CASE THE CODES EXIST FOR. A token basic with a large allowance stack:
     * 20,000 basic against 80,000 of allowances. Excluded (80,000) exceeds half
     * (50,000), so the 30,000 excess returns to the wage base.
     */
    public function test_a_token_basic_triggers_the_add_back(): void
    {
        $r = $this->compute(['basic' => 20000, 'hra' => 50000, 'other_allowances' => 30000]);

        $this->assertTrue($r['addBackApplied']);
        $this->assertSame(10000000, $r['totalPaise'], 'total remuneration is ₹1,00,000');
        $this->assertSame(3000000, $r['addBackPaise'], 'excluded 80,000 − half 50,000 = 30,000');
        $this->assertSame(5000000, $r['basePaise'],
            'the base becomes 20,000 basic + 30,000 add-back = 50,000, i.e. exactly half');
    }

    /** Only the EXCESS returns — never the whole excluded pot. */
    public function test_only_the_excess_over_half_is_added_back(): void
    {
        $r = $this->compute(['basic' => 40000, 'hra' => 60000]);

        $this->assertSame(1000000, $r['addBackPaise'], 'excluded 60,000 − half 50,000 = 10,000');
        $this->assertNotSame($r['excludedPaise'], $r['addBackPaise'],
            'adding back the entire excluded amount would over-tax every structure');
    }

    /** After an add-back the base is exactly half of total, by construction. */
    public function test_the_add_back_lands_the_base_at_exactly_half(): void
    {
        foreach ([
            ['basic' => 10000, 'hra' => 90000],
            ['basic' => 25000, 'other_allowances' => 75000],
            ['basic' => 1000,  'hra' => 60000, 'ta' => 39000],
        ] as $s) {
            $r = $this->compute($s);

            $this->assertTrue($r['addBackApplied']);
            $this->assertSame(
                intdiv($r['totalPaise'], 2) + ($r['totalPaise'] % 2),
                $r['basePaise'],
                'once the add-back fires, the base is half of remuneration'
            );
        }
    }

    // ── The boundary ─────────────────────────────────────────────────────

    /** Excluded exactly at half does NOT trip it — the rule says "exceed". */
    public function test_exactly_half_excluded_does_not_trigger_the_add_back(): void
    {
        $r = $this->compute(['basic' => 50000, 'hra' => 50000]);

        $this->assertFalse($r['addBackApplied'],
            'the spec says "exceed 50%", so parity is compliant');
        $this->assertSame(5000000, $r['basePaise']);
    }

    /** One paise over the boundary does trip it. */
    public function test_a_single_paise_over_half_triggers_it(): void
    {
        $r = $this->compute(['basic' => 49999.99, 'hra' => 50000.01]);

        $this->assertTrue($r['addBackApplied'],
            'the boundary must be exact to the paise, not to the rupee');

        // total 1,00,00,000p; half 50,00,000p; excluded 50,00,001p → excess 1p.
        $this->assertSame(1, $r['addBackPaise'],
            'a one-paise breach adds back exactly one paise — no more');
    }

    /**
     * An odd total cannot round the boundary in the employer's favour.
     *
     * CORRECTED 2026-08-22. This test's NAME was always right and its
     * assertion was always wrong. It previously required that a 3-paise total
     * with a 2-paise excluded pot produce NO add-back, on the reasoning that
     * the half should round up to 2 and 2 does not exceed 2.
     *
     * But the proviso asks whether the excluded pot exceeds one-half of
     * remuneration, and one-half of 3 is 1.5. Two paise does exceed 1.5, so an
     * add-back is due. Rounding the threshold up suppressed it — which is
     * precisely rounding the boundary in the employer's favour, the thing the
     * test is named after.
     *
     * The trigger is now an exact cross-multiplied comparison that needs no
     * rounding, and the threshold subtracted is floored, so the excess is
     * rounded up by at most a paise — in the employee's favour.
     */
    public function test_an_odd_total_rounds_the_half_in_the_employees_favour(): void
    {
        // total 3 paise; one-half is 1.5; excluded 2 exceeds it, so 2 − 1 = 1
        // paise is added back and the base becomes 1 + 1 = 2.
        $r = $this->compute(['basic' => 0.01, 'hra' => 0.02]);

        $this->assertSame(3, $r['totalPaise']);
        $this->assertTrue($r['addBackApplied'],
            '2 paise DOES exceed one-half of 3 paise — the proviso applies');
        $this->assertSame(1, $r['addBackPaise']);
        $this->assertSame(2, $r['basePaise']);
    }

    // ── Degenerate input ─────────────────────────────────────────────────

    public function test_an_empty_structure_is_zero_not_an_error(): void
    {
        $r = $this->compute([]);

        $this->assertSame(0, $r['basePaise']);
        $this->assertSame(0, $r['totalPaise']);
        $this->assertFalse($r['addBackApplied']);
    }

    public function test_a_basic_only_structure_is_entirely_wages(): void
    {
        $r = $this->compute(['basic' => 40000]);

        $this->assertSame(4000000, $r['basePaise']);
        $this->assertFalse($r['addBackApplied'], 'with no excluded components there is no excess');
    }

    /** Paise survive — the base is never rounded to rupees. */
    public function test_paise_survive_the_computation(): void
    {
        $r = $this->compute(['basic' => 20000.55, 'da' => 1000.45]);

        $this->assertSame(2100100, $r['basePaise'], '₹21,001.00 exactly');
    }

    // ── The warning helper the spec asks for ─────────────────────────────

    public function test_needs_add_back_flags_a_non_compliant_structure(): void
    {
        $this->assertTrue(Statutory_wage_base::needsAddBack(['basic' => 10000, 'hra' => 90000]));
        $this->assertFalse(Statutory_wage_base::needsAddBack(['basic' => 60000, 'hra' => 40000]));
    }

    // ── Boundaries this class must NOT cross ─────────────────────────────

    /**
     * No rate, ceiling, slab or threshold. The 50% boundary is the codes' own
     * structural definition as quoted by the spec; rates live in
     * `Statutory_config_service`, effective-dated and CA-verified.
     */
    public function test_the_class_contains_no_statutory_rate(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Statutory_wage_base.php');
        $this->assertNotFalse($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        $this->assertStringContainsString('function compute', $code, 'comment-strip sanity');

        foreach (['15000', '21000', '12%', '0.12', 'ceiling', 'slab'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $code,
                "rates and ceilings belong in Statutory_config_service, CA-verified ({$needle})");
        }
    }

    /**
     * Deliberately NOT wired. Switching PF onto this base changes what every
     * employee at every PF-configured tenant contributes — a compliance cutover
     * needing CA sign-off, not a side effect of adding a class.
     */
    public function test_it_is_not_yet_wired_into_the_payslip_engine(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);

        $this->assertStringContainsString(
            'Statutory_config_service::computePf($statutory, $effectiveBasicP)',
            $hr,
            'PF still computes on basic; if that changes, the cutover was taken and this test '
            . 'must be revisited together with the CA sign-off it requires'
        );
        $this->assertStringNotContainsString('Statutory_wage_base::compute', $hr,
            'wiring this in is a gated compliance decision, not an implementation detail');
    }
}
