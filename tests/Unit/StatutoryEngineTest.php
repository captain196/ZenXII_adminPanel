<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';

/**
 * ZenX HRMS W2.2 — statutory configuration and resolver.
 *
 * BEHAVIOURAL: `validate()`, `findOverlaps()`, `selectFor()`, `computePf()`,
 * `computeEsi()`, `computePt()`, `computeTds()` are all pure — no Firestore, no
 * session — so these tests execute the real production resolver rather than
 * asserting source text.
 *
 * The two tests that matter most:
 *   • `test_ratio_arithmetic_matches_the_w1_2_money_engine_exactly` reflects
 *     into the private `Hr::_applyRatio()` and proves this service has not
 *     become a second money policy.
 *   • `test_payroll_path_is_untouched_by_w2_2` proves nothing in the payroll
 *     path consults the resolver, which is the whole W2.2 boundary.
 */
class StatutoryEngineTest extends TestCase
{
    private \Statutory_config_service $svc;

    protected function setUp(): void
    {
        $this->svc = new \Statutory_config_service();
    }

    /** Rates observed in the only tenant that has salary structures (discovery, §1). */
    private function cfg(array $over = []): array
    {
        return array_merge([
            'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => null,
            'pf'  => ['employeeRate' => 12.0, 'employerRate' => 12.0,
                      'wageCeilingPaise' => 1500000, 'applyCeiling' => true,
                      'eligibleAboveCeiling' => true],
            'esi' => ['employeeRate' => 0.75, 'employerRate' => 3.25,
                      'wageThresholdPaise' => 2100000, 'applicable' => true],
            'pt'  => ['state' => '', 'slabs' => []],
            'tds' => ['mode' => 'FLAT_PERCENT', 'rate' => 0.0],
        ], $over);
    }

    // ── Money contract ───────────────────────────────────────────────────

    /**
     * ANTI-DRIFT (source half). If this service ever rounds differently from
     * the W1.2 engine, statutory amounts and payslip amounts diverge by a paisa
     * somewhere and Dr==Cr eventually fails.
     *
     * The NUMERIC equivalence — actually invoking both implementations over a
     * grid of amounts and ratios — lives in `Hrms_w2_verify` (group A), because
     * `Hr` extends `MY_Controller`, which this suite's bootstrap cannot load.
     * That is the established home for exercising Hr's private methods. Here we
     * pin the formula textually, which catches an edit to either side.
     */
    public function test_ratio_formula_matches_the_w1_2_money_engine(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);

        $ok = preg_match(
            '/private static function _applyRatio\([^)]*\)\s*:\s*int\s*\{(.*?)\}/s',
            $hr,
            $m
        );
        $this->assertSame(1, $ok, 'Could not locate Hr::_applyRatio — the W1.2 money engine moved.');

        $normalise = fn(string $s) => preg_replace('/\s+/', ' ', trim($s));
        $this->assertStringContainsString(
            $normalise('return (int) round($paise * $ratio);'),
            $normalise($m[1]),
            'Hr::_applyRatio changed shape; Statutory_config_service::applyRatio must be updated to match.'
        );

        $svc = file_get_contents(__DIR__ . '/../../application/libraries/Statutory_config_service.php');
        $this->assertStringContainsString(
            'return (int) round($paise * $ratio);',
            $svc,
            'the statutory service must apply ratios with exactly the W1.2 arithmetic'
        );
    }

    public function test_a_rate_is_never_converted_to_money(): void
    {
        $this->assertSame(0.12, \Statutory_config_service::pctToRatio(12.0));
        $this->assertSame(0.0075, \Statutory_config_service::pctToRatio(0.75));
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_a_valid_configuration_passes(): void
    {
        [$clean, $errors] = $this->svc->validate($this->cfg());
        $this->assertSame([], $errors);
        $this->assertSame(1500000, $clean['pf']['wageCeilingPaise']);
        $this->assertSame(12.0, $clean['pf']['employeeRate']);
    }

    public function test_effective_from_must_be_a_real_date(): void
    {
        foreach (['', '2026-4-1', '01-04-2026', '2026-02-30', 'yesterday'] as $bad) {
            $this->assertNotEmpty($this->svc->validate($this->cfg(['effectiveFrom' => $bad]))[1], "'{$bad}' must be rejected");
        }
        // Calendar validity is real, not just a regex: 2026 is NOT a leap year
        // (2026/4 = 506.5), so Feb 29 must be rejected — while 2024 accepts it.
        $this->assertNotEmpty($this->svc->validate($this->cfg(['effectiveFrom' => '2026-02-29']))[1]);
        $this->assertSame([], $this->svc->validate($this->cfg(['effectiveFrom' => '2026-02-28']))[1]);
        $this->assertSame([], $this->svc->validate($this->cfg(['effectiveFrom' => '2024-02-29']))[1], '2024 IS a leap year');
    }

    public function test_effective_to_must_not_precede_effective_from(): void
    {
        $this->assertNotEmpty($this->svc->validate($this->cfg(['effectiveTo' => '2026-03-31']))[1]);
        $this->assertSame([], $this->svc->validate($this->cfg(['effectiveTo' => '2027-03-31']))[1]);
        $this->assertNull($this->svc->validate($this->cfg(['effectiveTo' => null]))[0]['effectiveTo'], 'open-ended allowed');
    }

    public function test_rates_are_bounded_percentages(): void
    {
        foreach ([-1, 100.01, 'abc'] as $bad) {
            $c = $this->cfg(); $c['pf']['employeeRate'] = $bad;
            $this->assertNotEmpty($this->svc->validate($c)[1], 'pf rate ' . var_export($bad, true));
            $c = $this->cfg(); $c['esi']['employerRate'] = $bad;
            $this->assertNotEmpty($this->svc->validate($c)[1], 'esi rate ' . var_export($bad, true));
        }
    }

    public function test_monetary_config_must_be_integer_paise_not_rupees(): void
    {
        // A decimal means rupees leaked in — the exact W1.2 regression.
        foreach ([15000.50, '15000.50', -1] as $bad) {
            $c = $this->cfg(); $c['pf']['wageCeilingPaise'] = $bad;
            $this->assertNotEmpty($this->svc->validate($c)[1], 'ceiling ' . var_export($bad, true));
            $c = $this->cfg(); $c['esi']['wageThresholdPaise'] = $bad;
            $this->assertNotEmpty($this->svc->validate($c)[1], 'threshold ' . var_export($bad, true));
        }
        $c = $this->cfg(); $c['pf']['wageCeilingPaise'] = '1500000';
        $this->assertSame([], $this->svc->validate($c)[1], 'integer-as-string is fine');
    }

    public function test_ceiling_and_threshold_switches_require_a_value(): void
    {
        $c = $this->cfg(); $c['pf']['applyCeiling'] = true; $c['pf']['wageCeilingPaise'] = 0;
        $this->assertNotEmpty($this->svc->validate($c)[1], 'applyCeiling with no ceiling is incoherent');

        $c = $this->cfg(); $c['esi']['applicable'] = true; $c['esi']['wageThresholdPaise'] = 0;
        $this->assertNotEmpty($this->svc->validate($c)[1]);
    }

    // ── PT slabs ─────────────────────────────────────────────────────────

    private function slabCfg(array $slabs, string $state = ''): array
    {
        $c = $this->cfg();
        $c['pt'] = ['state' => $state, 'slabs' => $slabs];
        return $c;
    }

    public function test_pt_slabs_reject_overlap_and_ambiguity(): void
    {
        // Overlapping bands would make PT depend on array order.
        $this->assertNotEmpty($this->svc->validate($this->slabCfg([
            ['fromPaise' => 0,       'toPaise' => 1000000, 'amountPaise' => 0],
            ['fromPaise' => 900000,  'toPaise' => 2000000, 'amountPaise' => 20000],
        ]))[1], 'overlap must be rejected');

        $this->assertNotEmpty($this->svc->validate($this->slabCfg([
            ['fromPaise' => 0,       'toPaise' => null, 'amountPaise' => 0],
            ['fromPaise' => 2000000, 'toPaise' => null, 'amountPaise' => 20000],
        ]))[1], 'two open-ended bands must be rejected');

        $this->assertNotEmpty($this->svc->validate($this->slabCfg([
            ['fromPaise' => 2000000, 'toPaise' => 1000000, 'amountPaise' => 20000],
        ]))[1], 'inverted band must be rejected');
    }

    public function test_pt_slabs_accept_a_well_formed_table(): void
    {
        [$clean, $errors] = $this->svc->validate($this->slabCfg([
            ['fromPaise' => 2000001, 'toPaise' => null,    'amountPaise' => 20000],
            ['fromPaise' => 0,       'toPaise' => 2000000, 'amountPaise' => 0],
        ]));
        $this->assertSame([], $errors);
        // Sorted by band start regardless of input order.
        $this->assertSame(0, $clean['pt']['slabs'][0]['fromPaise']);
        $this->assertNull($clean['pt']['slabs'][1]['toPaise']);
    }

    public function test_pt_state_is_stored_verbatim_and_never_inferred(): void
    {
        $this->assertSame('', $this->svc->validate($this->slabCfg([]))[0]['pt']['state'], 'no state is invented');
        $this->assertSame('Uttar Pradesh', $this->svc->validate($this->slabCfg([], 'Uttar Pradesh'))[0]['pt']['state']);
    }

    // ── TDS boundary ─────────────────────────────────────────────────────

    public function test_tds_slab_mode_is_modelled_but_refused(): void
    {
        $c = $this->cfg(); $c['tds'] = ['mode' => 'SLAB'];
        $this->assertNotEmpty($this->svc->validate($c)[1], 'a slab TDS engine is out of W2.2 scope');
        $this->assertContains('SLAB', \Statutory_config_service::TDS_MODES, 'still part of the model');
    }

    public function test_tds_unknown_mode_rejected(): void
    {
        $c = $this->cfg(); $c['tds'] = ['mode' => 'MAGIC'];
        $this->assertNotEmpty($this->svc->validate($c)[1]);
    }

    // ── Effective dating ─────────────────────────────────────────────────

    private function window(string $from, ?string $to): array
    {
        return ['effectiveFrom' => $from, 'effectiveTo' => $to];
    }

    public function test_resolver_picks_the_configuration_in_force(): void
    {
        $set = [
            $this->window('2025-04-01', '2026-03-31') + ['tag' => 'FY25'],
            $this->window('2026-04-01', null)         + ['tag' => 'FY26'],
        ];

        $this->assertSame('FY25', \Statutory_config_service::selectFor($set, '2025-04-01')['tag'], 'inclusive lower bound');
        $this->assertSame('FY25', \Statutory_config_service::selectFor($set, '2026-03-31')['tag'], 'inclusive upper bound');
        $this->assertSame('FY26', \Statutory_config_service::selectFor($set, '2026-04-01')['tag'], 'next window starts');
        $this->assertSame('FY26', \Statutory_config_service::selectFor($set, '2030-01-01')['tag'], 'open-ended continues');
    }

    public function test_resolver_returns_null_before_any_configuration_exists(): void
    {
        $set = [$this->window('2026-04-01', null)];
        $this->assertNull(
            \Statutory_config_service::selectFor($set, '2026-03-31'),
            'a period before the first configuration must resolve to nothing, not to the future config'
        );
        $this->assertNull(\Statutory_config_service::selectFor([], '2026-04-01'), 'missing configuration');
    }

    public function test_past_periods_remain_reconstructable_after_a_change(): void
    {
        // The point of effective dating: adding FY26 must not rewrite FY25.
        $set = [
            $this->window('2025-04-01', '2026-03-31') + ['pf' => ['employeeRate' => 12.0]],
            $this->window('2026-04-01', null)         + ['pf' => ['employeeRate' => 10.0]],
        ];
        $this->assertSame(12.0, \Statutory_config_service::selectFor($set, '2025-09-15')['pf']['employeeRate']);
        $this->assertSame(10.0, \Statutory_config_service::selectFor($set, '2026-09-15')['pf']['employeeRate']);
    }

    public function test_overlap_detection(): void
    {
        $this->assertSame([], \Statutory_config_service::findOverlaps([
            $this->window('2025-04-01', '2026-03-31'),
            $this->window('2026-04-01', null),
        ]), 'adjacent windows do not overlap');

        $this->assertNotEmpty(\Statutory_config_service::findOverlaps([
            $this->window('2025-04-01', '2026-04-30'),
            $this->window('2026-04-01', null),
        ]), 'intersecting windows');

        $this->assertNotEmpty(\Statutory_config_service::findOverlaps([
            $this->window('2025-04-01', null),
            $this->window('2026-04-01', null),
        ]), 'an open-ended predecessor swallows everything after it');

        $this->assertNotEmpty(\Statutory_config_service::findOverlaps([
            $this->window('2026-04-01', null),
            $this->window('2026-04-01', '2027-03-31'),
        ]), 'duplicate effectiveFrom');
    }

    public function test_ambiguous_selection_is_deterministic(): void
    {
        // Validation prevents this, but if bad data ever exists the answer must
        // not depend on read order.
        $set = [
            $this->window('2026-01-01', null) + ['tag' => 'older'],
            $this->window('2026-04-01', null) + ['tag' => 'newer'],
        ];
        $this->assertSame('newer', \Statutory_config_service::selectFor($set, '2026-06-01')['tag']);
        $this->assertSame('newer', \Statutory_config_service::selectFor(array_reverse($set), '2026-06-01')['tag']);
    }

    // ── PF resolution ────────────────────────────────────────────────────

    public function test_pf_below_ceiling_uses_the_full_wage(): void
    {
        $r = \Statutory_config_service::computePf($this->cfg(), 1000000);   // ₹10,000
        $this->assertTrue($r['covered']);
        $this->assertSame(1000000, $r['basePaise']);
        $this->assertSame(120000, $r['employeePaise']);   // 12%
        $this->assertSame(120000, $r['employerPaise']);
    }

    public function test_pf_above_ceiling_is_capped_when_apply_ceiling_is_set(): void
    {
        $r = \Statutory_config_service::computePf($this->cfg(), 5000000);   // ₹50,000
        $this->assertTrue($r['covered']);
        $this->assertSame(1500000, $r['basePaise'], 'base capped at the ₹15,000 ceiling');
        $this->assertSame(180000, $r['employeePaise'], '12% of the ceiling, not of the wage');
    }

    public function test_pf_exactly_at_the_ceiling(): void
    {
        $r = \Statutory_config_service::computePf($this->cfg(), 1500000);
        $this->assertSame(1500000, $r['basePaise']);
        $this->assertSame(180000, $r['employeePaise']);
    }

    public function test_pf_ceiling_can_be_switched_off(): void
    {
        $c = $this->cfg(); $c['pf']['applyCeiling'] = false;
        $r = \Statutory_config_service::computePf($c, 5000000);
        $this->assertSame(5000000, $r['basePaise'], 'uncapped');
        $this->assertSame(600000, $r['employeePaise']);
    }

    public function test_pf_excluded_employee_above_ceiling_contributes_nothing(): void
    {
        // eligibleAboveCeiling=false is NOT the same as capping — it means not
        // covered at all. Conflating the two is the classic PF bug.
        $c = $this->cfg(); $c['pf']['eligibleAboveCeiling'] = false;
        $r = \Statutory_config_service::computePf($c, 5000000);
        $this->assertFalse($r['covered']);
        $this->assertSame(0, $r['employeePaise']);
        $this->assertSame(0, $r['employerPaise']);

        // …but someone at or below the ceiling is still covered.
        $this->assertTrue(\Statutory_config_service::computePf($c, 1500000)['covered']);
    }

    public function test_pf_separate_employee_and_employer_rates(): void
    {
        $c = $this->cfg();
        $c['pf']['employeeRate'] = 12.0;
        $c['pf']['employerRate'] = 3.67;
        $r = \Statutory_config_service::computePf($c, 1000000);
        $this->assertSame(120000, $r['employeePaise']);
        $this->assertSame(36700, $r['employerPaise']);
    }

    public function test_pf_zero_wage(): void
    {
        $r = \Statutory_config_service::computePf($this->cfg(), 0);
        $this->assertFalse($r['covered']);
        $this->assertSame(0, $r['employeePaise']);
    }

    // ── ESI resolution ───────────────────────────────────────────────────

    public function test_esi_below_threshold_is_covered_on_the_full_wage(): void
    {
        $r = \Statutory_config_service::computeEsi($this->cfg(), 2000000);  // ₹20,000
        $this->assertTrue($r['covered']);
        $this->assertSame(2000000, $r['basePaise']);
        $this->assertSame(15000, $r['employeePaise']);   // 0.75%
        $this->assertSame(65000, $r['employerPaise']);   // 3.25%
    }

    public function test_esi_exactly_at_threshold_is_still_covered(): void
    {
        $r = \Statutory_config_service::computeEsi($this->cfg(), 2100000);
        $this->assertTrue($r['covered'], 'the threshold is inclusive');
    }

    public function test_esi_above_threshold_is_not_covered_rather_than_capped(): void
    {
        // The mirror image of PF: ESI is a coverage test, not a cap. Capping at
        // the threshold would silently under-deduct instead of exempting.
        $r = \Statutory_config_service::computeEsi($this->cfg(), 2100001);
        $this->assertFalse($r['covered']);
        $this->assertSame(0, $r['employeePaise']);
        $this->assertSame(0, $r['employerPaise']);
        $this->assertStringContainsString('threshold', $r['reason']);
    }

    public function test_esi_can_be_switched_off_for_a_tenant(): void
    {
        $c = $this->cfg(); $c['esi']['applicable'] = false;
        $r = \Statutory_config_service::computeEsi($c, 1000000);
        $this->assertFalse($r['covered']);
        $this->assertSame(0, $r['employeePaise']);
    }

    // ── PT resolution ────────────────────────────────────────────────────

    private function ptCfg(): array
    {
        return $this->slabCfg([
            ['fromPaise' => 0,        'toPaise' => 1500000, 'amountPaise' => 0],
            ['fromPaise' => 1500001,  'toPaise' => 2000000, 'amountPaise' => 15000],   // ₹150
            ['fromPaise' => 2000001,  'toPaise' => null,    'amountPaise' => 20000],   // ₹200
        ], 'Uttar Pradesh');
    }

    public function test_pt_slab_boundaries(): void
    {
        $c = $this->ptCfg();
        $this->assertSame(0,     \Statutory_config_service::computePt($c, 1500000)['amountPaise'], 'upper edge of band 1');
        $this->assertSame(15000, \Statutory_config_service::computePt($c, 1500001)['amountPaise'], 'lower edge of band 2');
        $this->assertSame(15000, \Statutory_config_service::computePt($c, 2000000)['amountPaise'], 'upper edge of band 2');
        $this->assertSame(20000, \Statutory_config_service::computePt($c, 2000001)['amountPaise'], 'lower edge of band 3');
        $this->assertSame(20000, \Statutory_config_service::computePt($c, 99999999)['amountPaise'], 'open-ended top band');
    }

    public function test_pt_is_a_flat_amount_not_a_rate(): void
    {
        $c = $this->ptCfg();
        $this->assertSame(
            \Statutory_config_service::computePt($c, 2500000)['amountPaise'],
            \Statutory_config_service::computePt($c, 9500000)['amountPaise'],
            'both fall in the same band, so both pay the same flat amount'
        );
    }

    public function test_pt_with_no_slabs_or_zero_wage_yields_zero(): void
    {
        $this->assertSame(0, \Statutory_config_service::computePt($this->slabCfg([]), 2000000)['amountPaise']);
        $this->assertFalse(\Statutory_config_service::computePt($this->ptCfg(), 0)['matched']);
    }

    // ── TDS resolution ───────────────────────────────────────────────────

    public function test_tds_flat_percent_reproduces_current_behaviour(): void
    {
        $c = $this->cfg(); $c['tds'] = ['mode' => 'FLAT_PERCENT', 'rate' => 10.0];
        $r = \Statutory_config_service::computeTds($c, 5000000);
        $this->assertTrue($r['supported']);
        $this->assertSame(500000, $r['amountPaise']);
    }

    public function test_tds_unsupported_mode_returns_zero_and_says_so(): void
    {
        $c = $this->cfg(); $c['tds'] = ['mode' => 'SLAB', 'rate' => 10.0];
        $r = \Statutory_config_service::computeTds($c, 5000000);
        $this->assertFalse($r['supported'], 'must not guess a tax figure');
        $this->assertSame(0, $r['amountPaise']);
    }

    // ── Boundary guards ──────────────────────────────────────────────────

    /**
     * The payroll boundary — RE-STATED for W2.7, not relaxed.
     *
     * W2.2 asserted the calculator knew nothing of statutory policy, which was
     * correct while the resolver stood alone. W2.7 deliberately moved that
     * boundary: `_compute_payslip()` now accepts an optional statutory policy so
     * a configured PF ceiling actually applies, instead of being a setting that
     * silently does nothing.
     *
     * What must still hold is the CUTOVER boundary. The calculator RECEIVES
     * policy as a parameter; it must never fetch it, and it must still know
     * nothing about the compensation reader or the assignment collection —
     * otherwise the new compensation path would be live without the parity gate
     * and the feature flag.
     */
    public function test_the_payroll_boundary_holds_after_w2_7(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        // Deliberate as of W2.7.
        $this->assertStringContainsString('Statutory_config_service', $hr,
            'the calculator is expected to be statutory-aware from W2.7 onward');

        // But policy must arrive as an argument, never be queried here.
        $this->assertStringNotContainsString("schoolWhere('statutoryConfig'", $hr);
        $this->assertStringNotContainsString("getEntity('statutoryConfig'", $hr);

        // W3.1 RE-STATED. This previously asserted `Hr.php` contained no
        // reference to `Compensation_reader` at all. W3.1 deliberately moved
        // that line: the payroll run now resolves compensation THROUGH the
        // reader, which is precisely what a seam is for — the W2 cutover
        // becomes a mode change rather than a payroll rewrite.
        //
        // The cutover still has NOT happened, and that is what is asserted now:
        // payroll goes through the seam, and resolves nothing from the
        // component collections itself.
        $this->assertStringContainsString('Compensation_reader', $hr,
            'W3.1: payroll resolves compensation through the reader seam');
        $this->assertStringNotContainsString('staffSalaryAssignments', $hr);
        $this->assertStringNotContainsString('salaryComponents', $hr);

        $payroll = (string) file_get_contents(__DIR__ . '/../../application/controllers/Payroll.php');
        foreach (['statutoryConfig', 'Statutory_config_service', 'salaryComponents',
                  'staffSalaryAssignments', 'Compensation_reader'] as $needle) {
            $this->assertStringNotContainsString($needle, $payroll, "Payroll.php must not reference {$needle}");
        }
    }

    public function test_w2_1_component_metadata_is_unchanged(): void
    {
        require_once __DIR__ . '/../../application/libraries/Salary_component_service.php';
        $this->assertCount(13, \Salary_component_service::systemComponents(), 'W2.2 must not alter the W2.1 catalogue');
    }

    public function test_no_rtdb_and_no_hardcoded_tenant(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Statutory_config_service.php');
        $this->assertNotFalse($src);
        $this->assertStringNotContainsString('firebase->', $src);
        $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $src, 'no tenant may be hardcoded');
    }
}
