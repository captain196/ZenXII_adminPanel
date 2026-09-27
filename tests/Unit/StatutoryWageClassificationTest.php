<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The three-state wage classification and the add-back it makes possible.
 *
 * ADDED 2026-08-22 alongside the W-1 / W-2 corrections to
 * `Statutory_wage_base`. These exercise the REAL library — there is no mirror
 * of its arithmetic anywhere in this file, because a mirror of a money function
 * is a second implementation that can disagree with the first.
 *
 * WHY THREE STATES AND NOT A BOOLEAN
 * ----------------------------------
 * Section 2(y) of the Code on Wages defines wages as all remuneration minus
 * clauses (a)–(i), and the first proviso measures those excluded clauses
 * against "all remuneration". So an excluded allowance is still remuneration —
 * it belongs in the denominator even though it is out of the base. A component
 * that is not remuneration at all (a reimbursement of actual expense under
 * clause (e)) belongs in neither.
 *
 * A boolean cannot express that difference, and collapsing the two would shrink
 * the denominator and under-trigger the add-back. Hence:
 *
 *   INCLUDE      in the base, in the denominator
 *   CONDITIONAL  out of the base, IN the denominator, feeds the add-back pool
 *   EXCLUDE      out of both
 *
 * Zoho Payroll ships the same three states, and its documentation records that
 * the setting "replaces the separate EPF and ESI options that were available
 * earlier" — the codes define one wage for both, so two independent booleans
 * are the previous generation of this model.
 *
 * Most assertions below are PROPERTIES rather than magic numbers. Where a
 * concrete figure appears it is derived from the statutory rule in the docblock
 * above it, never from running the code and copying the answer.
 */
final class StatutoryWageClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Statutory_wage_base.php';
    }

    /** @param array<int,array{0:string,1:int,2:?string}> $rows */
    private function compute(array $rows, array $opts = []): array
    {
        $components = [];
        foreach ($rows as $r) {
            $c = ['code' => $r[0], 'amountPaise' => $r[1]];
            if ($r[2] !== null) $c['classification'] = $r[2];
            $components[] = $c;
        }
        return Statutory_wage_base::computeFromComponents($components, $opts);
    }

    // ── The denominator: what counts as remuneration ─────────────────────

    /**
     * CONDITIONAL is remuneration. ₹30,000 basic + ₹20,000 HRA is ₹50,000 of
     * remuneration, and HRA at 40% is under half, so nothing is added back.
     */
    public function test_conditional_components_count_toward_total_remuneration(): void
    {
        $out = $this->compute([
            ['BASIC', 3000000, 'INCLUDE'],
            ['HRA',   2000000, 'CONDITIONAL'],
        ]);

        $this->assertSame(5000000, $out['totalPaise']);
        $this->assertSame(3000000, $out['basePaise'], 'HRA is not wages while it stays under the threshold');
        $this->assertFalse($out['addBackApplied']);
    }

    /**
     * EXCLUDE is NOT remuneration and must stay out of the denominator.
     *
     * THE LOAD-BEARING CASE. If a reimbursement were allowed into the
     * denominator it would inflate total remuneration, raise the threshold, and
     * suppress an add-back that should have fired — the exact direction that
     * understates a statutory liability.
     */
    public function test_excluded_components_stay_out_of_the_denominator(): void
    {
        $withReimbursement = $this->compute([
            ['BASIC',         2000000, 'INCLUDE'],
            ['HRA',           3000000, 'CONDITIONAL'],
            ['REIMBURSEMENT', 9000000, 'EXCLUDE'],
        ]);
        $without = $this->compute([
            ['BASIC', 2000000, 'INCLUDE'],
            ['HRA',   3000000, 'CONDITIONAL'],
        ]);

        $this->assertSame($without['totalPaise'], $withReimbursement['totalPaise'],
            'a reimbursement is not remuneration — it must not move the denominator');
        $this->assertSame($without['addBackPaise'], $withReimbursement['addBackPaise'],
            'and therefore must not change the add-back');
        $this->assertSame(9000000, $withReimbursement['outsidePaise'],
            'but it is still reported, so it is auditable rather than invisible');
    }

    // ── The add-back itself ──────────────────────────────────────────────

    /**
     * ₹20,000 basic + ₹80,000 HRA. Total ₹100,000; half is ₹50,000; the
     * conditional pot is ₹80,000, so ₹30,000 comes back and the base is
     * ₹50,000 — exactly half, which is what the proviso is designed to enforce.
     */
    public function test_only_the_excess_over_the_threshold_is_added_back(): void
    {
        $out = $this->compute([
            ['BASIC', 2000000,  'INCLUDE'],
            ['HRA',   8000000,  'CONDITIONAL'],
        ]);

        $this->assertTrue($out['addBackApplied']);
        $this->assertSame(3000000, $out['addBackPaise']);
        $this->assertSame(5000000, $out['basePaise'], 'the base lands at exactly the threshold');
    }

    /** A compliant structure is untouched. */
    public function test_a_compliant_structure_gets_no_add_back(): void
    {
        $out = $this->compute([
            ['BASIC', 6000000, 'INCLUDE'],
            ['HRA',   4000000, 'CONDITIONAL'],
        ]);

        $this->assertFalse($out['addBackApplied']);
        $this->assertSame(0, $out['addBackPaise']);
        $this->assertSame(6000000, $out['basePaise']);
    }

    /** Exactly at the threshold does not trigger it — the proviso says "exceeds". */
    public function test_exactly_at_the_threshold_does_not_trigger(): void
    {
        $out = $this->compute([
            ['BASIC', 5000000, 'INCLUDE'],
            ['HRA',   5000000, 'CONDITIONAL'],
        ]);

        $this->assertFalse($out['addBackApplied']);
    }

    // ── Properties, not examples ─────────────────────────────────────────

    /**
     * The add-back can never REDUCE the wage base. A rule designed to protect
     * employees must not be able to cost them.
     */
    public function test_the_add_back_never_reduces_the_base(): void
    {
        for ($basic = 0; $basic <= 10000000; $basic += 1234567) {
            for ($hra = 0; $hra <= 10000000; $hra += 987654) {
                $out = $this->compute([
                    ['BASIC', $basic, 'INCLUDE'],
                    ['HRA',   $hra,   'CONDITIONAL'],
                ]);
                $this->assertGreaterThanOrEqual($out['includedPaise'], $out['basePaise'],
                    "base fell below included wages at basic={$basic} hra={$hra}");
            }
        }
    }

    /** The base can never exceed total remuneration. */
    public function test_the_base_never_exceeds_total_remuneration(): void
    {
        for ($basic = 0; $basic <= 8000000; $basic += 811111) {
            for ($hra = 0; $hra <= 8000000; $hra += 733333) {
                $out = $this->compute([
                    ['BASIC', $basic, 'INCLUDE'],
                    ['HRA',   $hra,   'CONDITIONAL'],
                ]);
                $this->assertLessThanOrEqual($out['totalPaise'], $out['basePaise'],
                    "base exceeded remuneration at basic={$basic} hra={$hra}");
            }
        }
    }

    /**
     * Growing the conditional pot never shrinks the add-back. Monotonicity is
     * what makes the rule explainable to an employee: more allowance, never
     * less protection.
     */
    public function test_the_add_back_is_monotonic_in_the_conditional_pot(): void
    {
        $previous = -1;
        for ($hra = 0; $hra <= 20000000; $hra += 500000) {
            $out = $this->compute([
                ['BASIC', 2000000, 'INCLUDE'],
                ['HRA',   $hra,    'CONDITIONAL'],
            ]);
            $this->assertGreaterThanOrEqual($previous, $out['addBackPaise'],
                "add-back went down when the conditional pot went up, at hra={$hra}");
            $previous = $out['addBackPaise'];
        }
    }

    // ── The fail-safe default — this is W-1 ──────────────────────────────

    /**
     * W-1. An unclassified component is WAGES.
     *
     * Under s.2(y) wages are all remuneration minus the NAMED exclusions, so a
     * component nobody has classified is wages until somebody says otherwise.
     * The previous inclusion-list model gave it a weight of zero in both the
     * base and the denominator — failing toward less liability.
     */
    public function test_an_unclassified_component_is_treated_as_wages(): void
    {
        $out = $this->compute([
            ['BASIC',            5000000, 'INCLUDE'],
            ['TENANT_INVENTED',  2000000, null],
        ]);

        $this->assertSame(7000000, $out['basePaise'],
            'an unrecognised component must default INTO wages, not out of them');
        $this->assertSame(7000000, $out['totalPaise']);
    }

    /** A nonsense classification is treated the same fail-safe way. */
    public function test_a_nonsense_classification_falls_back_to_wages(): void
    {
        $out = $this->compute([
            ['BASIC',  5000000, 'INCLUDE'],
            ['WEIRD',  2000000, 'NOT_A_REAL_CLASSIFICATION'],
        ]);

        $this->assertSame(7000000, $out['basePaise']);
        $this->assertContains('WEIRD', $out['unclassified']);
    }

    /**
     * And every such component is NAMED. A fail-safe that silently absorbs the
     * problem is only half a fail-safe — the tenant has a classification gap
     * and somebody has to be able to see it.
     */
    public function test_unclassified_components_are_reported_by_name(): void
    {
        $out = $this->compute([
            ['BASIC', 5000000, 'INCLUDE'],
            ['ONE',   100000,  null],
            ['TWO',   100000,  ''],
            ['HRA',   100000,  'CONDITIONAL'],
        ]);

        $this->assertSame(['ONE', 'TWO'], $out['unclassified']);
    }

    // ── The threshold is a parameter — this is W-2 ───────────────────────

    /**
     * W-2. The first proviso says "one-half, or such other percentage as may be
     * notified by the Central Government", so the threshold must be settable.
     *
     * At a 60% threshold the same structure yields a smaller add-back, because
     * more of the allowance stack is permitted before the rule bites.
     */
    public function test_a_notified_threshold_changes_the_add_back(): void
    {
        $rows = [
            ['BASIC', 3000000, 'INCLUDE'],
            ['HRA',   7000000, 'CONDITIONAL'],
        ];

        $half  = $this->compute($rows);
        $sixty = $this->compute($rows, ['thresholdNum' => 3, 'thresholdDen' => 5]);

        $this->assertSame(2000000, $half['addBackPaise'],  'half of 100,000 is 50,000; 70,000 − 50,000');
        $this->assertSame(1000000, $sixty['addBackPaise'], 'three-fifths of 100,000 is 60,000; 70,000 − 60,000');
    }

    /** A malformed threshold falls back to the statutory default rather than dividing by zero. */
    public function test_a_malformed_threshold_falls_back_to_one_half(): void
    {
        $rows = [
            ['BASIC', 2000000, 'INCLUDE'],
            ['HRA',   8000000, 'CONDITIONAL'],
        ];
        $good = $this->compute($rows);

        foreach ([
            ['thresholdNum' => 1, 'thresholdDen' => 0],
            ['thresholdNum' => 5, 'thresholdDen' => 2],
            ['thresholdNum' => -1, 'thresholdDen' => 2],
        ] as $bad) {
            $this->assertSame($good['addBackPaise'], $this->compute($rows, $bad)['addBackPaise'],
                'an unusable threshold must not silently produce a different wage base');
        }
    }

    // ── Paise exactness ──────────────────────────────────────────────────

    /**
     * On an odd total the rounding must favour the EMPLOYEE.
     *
     * Total 3 paise, one-half is 1.5, and a 2-paise conditional pot exceeds
     * it — so the proviso applies. The trigger is decided by exact integer
     * cross-multiplication (2 × 2 > 3 × 1), with no rounding at all, and the
     * threshold subtracted is floored so the paise of excess is kept rather
     * than discarded.
     */
    public function test_an_odd_total_rounds_in_the_employees_favour(): void
    {
        $out = $this->compute([
            ['BASIC', 1, 'INCLUDE'],
            ['HRA',   2, 'CONDITIONAL'],
        ]);

        $this->assertTrue($out['addBackApplied'],
            '2 paise exceeds one-half of 3 paise');
        $this->assertSame(1, $out['addBackPaise']);
        $this->assertSame(2, $out['basePaise']);
    }

    /** One paise past the threshold does trigger it. */
    public function test_a_single_paise_over_the_threshold_triggers_it(): void
    {
        $out = $this->compute([
            ['BASIC', 100000, 'INCLUDE'],
            ['HRA',   100001, 'CONDITIONAL'],
        ]);

        $this->assertTrue($out['addBackApplied']);
        $this->assertSame(1, $out['addBackPaise']);
    }

    // ── Degenerate input ─────────────────────────────────────────────────

    public function test_an_empty_component_set_is_zero_not_an_error(): void
    {
        $out = Statutory_wage_base::computeFromComponents([]);

        $this->assertSame(0, $out['basePaise']);
        $this->assertSame(0, $out['totalPaise']);
        $this->assertFalse($out['addBackApplied']);
        $this->assertSame([], $out['unclassified']);
    }

    public function test_malformed_rows_are_skipped_rather_than_fatal(): void
    {
        $out = Statutory_wage_base::computeFromComponents([
            ['code' => 'BASIC', 'amountPaise' => 5000000, 'classification' => 'INCLUDE'],
            'not an array',
            null,
        ]);

        $this->assertSame(5000000, $out['basePaise']);
    }

    // ── The trace ────────────────────────────────────────────────────────

    /**
     * Every component appears in the trace with the classification actually
     * applied — including the defaulted one. The spec's §6.5 rule is that every
     * computed amount stores its inputs, and "why is my PF this number" is the
     * question this answers.
     */
    public function test_the_trace_records_the_classification_actually_applied(): void
    {
        $out = $this->compute([
            ['BASIC',   5000000, 'INCLUDE'],
            ['MYSTERY', 1000000, null],
        ]);

        $this->assertCount(2, $out['trace']);
        $this->assertSame(
            ['code' => 'MYSTERY', 'amountPaise' => 1000000, 'classification' => 'INCLUDE'],
            $out['trace'][1],
            'the trace must show the EFFECTIVE classification, not the missing one'
        );
    }

    // ── Backward compatibility ───────────────────────────────────────────

    /**
     * The legacy six-field entry point must keep producing the same answer, on
     * the same closed vocabulary, because it is what the shipping payroll path
     * would use if it were wired. The rewrite is a widening, not a change.
     */
    public function test_the_legacy_entry_point_is_unchanged(): void
    {
        $legacy = Statutory_wage_base::compute([
            'basic' => 20000, 'hra' => 80000,
        ]);

        $this->assertSame(3000000, $legacy['addBackPaise']);
        $this->assertSame(5000000, $legacy['basePaise']);
        $this->assertTrue(Statutory_wage_base::needsAddBack(['basic' => 10000, 'hra' => 90000]));
        $this->assertFalse(Statutory_wage_base::needsAddBack(['basic' => 60000, 'hra' => 40000]));
    }

    /** And the legacy path has no unclassified components, by construction. */
    public function test_the_legacy_path_classifies_everything_it_knows(): void
    {
        $legacy = Statutory_wage_base::compute(['basic' => 50000, 'da' => 10000, 'hra' => 40000]);

        $this->assertSame([], $legacy['unclassified'],
            'the legacy vocabulary is closed and fully enumerated — that is why it stayed safe');
    }

    // ── The statutory default classification map ─────────────────────────

    /**
     * The components s.2(y) names in its INCLUSION limb.
     *
     * These are not judgement calls: basic pay, dearness allowance and
     * retaining allowance are named in the definition itself, and DA is named
     * again in EPF Act s.6 as part of the contribution base.
     */
    public function test_the_statutory_inclusions_are_classified_include(): void
    {
        foreach (['BASIC', 'DA', 'basic', 'da', 'retaining_allowance'] as $code) {
            list($class, $explicit) = Statutory_wage_base::classify($code);
            $this->assertSame('INCLUDE', $class, "{$code} is named in the inclusion limb of s.2(y)");
            $this->assertFalse($explicit, 'a statutory default is not an explicit tenant choice');
        }
    }

    /**
     * The components s.2(y) names in its EXCLUSION clauses are CONDITIONAL —
     * out of the base, but still remuneration, so still in the denominator.
     */
    public function test_the_named_exclusions_are_classified_conditional(): void
    {
        foreach (['HRA', 'TA', 'hra', 'ta', 'conveyance', 'overtime_allowance'] as $code) {
            list($class,) = Statutory_wage_base::classify($code);
            $this->assertSame('CONDITIONAL', $class,
                "{$code} is a named exclusion but remains remuneration for the 50% test");
        }
    }

    /**
     * THE TWO THAT MATTER, and the reason the earlier "basic + DA" conclusion
     * was insufficient.
     *
     * Neither MEDICAL nor OTHER_ALLOWANCES appears in any exclusion clause, so
     * under "all remuneration minus named exclusions" they are wages. For
     * OTHER_ALLOWANCES that is reinforced by Vivekananda Vidyamandir, which
     * concerned exactly this kind of residual allowance at a school.
     *
     * Both remain per-tenant overridable, because whether a particular school's
     * medical component is an allowance or a reimbursement — and whether its
     * residual bucket really is paid to everyone — are questions of fact.
     */
    public function test_unnamed_earnings_default_to_wages(): void
    {
        foreach (['MEDICAL', 'OTHER_ALLOWANCES', 'medical', 'other_allowances'] as $code) {
            list($class,) = Statutory_wage_base::classify($code);
            $this->assertSame('INCLUDE', $class,
                "{$code} is in no exclusion clause, so s.2(y) makes it wages by default");
        }
    }

    /** Deductions and employer contributions are never remuneration. */
    public function test_deductions_and_employer_contributions_are_outside(): void
    {
        foreach ([
            'PF_EMPLOYEE', 'ESI_EMPLOYEE', 'TDS', 'PROFESSIONAL_TAX',
            'OTHER_DEDUCTIONS', 'PF_EMPLOYER', 'ESI_EMPLOYER',
        ] as $code) {
            list($class,) = Statutory_wage_base::classify($code);
            $this->assertSame('EXCLUDE', $class,
                "{$code} must never enter the wage base or the denominator");
        }
    }

    /**
     * A tenant's explicit classification OUTRANKS the statutory default.
     *
     * This is the universality test in practice: whether a residual allowance is
     * wages depends on how THIS employer pays it, so the tenant's own answer —
     * made with their CA — must win.
     */
    public function test_a_tenant_override_beats_the_statutory_default(): void
    {
        list($default,) = Statutory_wage_base::classify('OTHER_ALLOWANCES');
        $this->assertSame('INCLUDE', $default);

        list($override, $explicit) = Statutory_wage_base::classify(
            'OTHER_ALLOWANCES', ['wageClassification' => 'CONDITIONAL']
        );
        $this->assertSame('CONDITIONAL', $override,
            'a school that genuinely does not pay it universally must be able to say so');
        $this->assertTrue($explicit, 'and the override must be recorded as explicit');
    }

    /** A nonsense override is ignored in favour of the statutory default. */
    public function test_a_nonsense_override_falls_back_to_the_default(): void
    {
        list($class, $explicit) = Statutory_wage_base::classify(
            'BASIC', ['wageClassification' => 'BANANA']
        );
        $this->assertSame('INCLUDE', $class);
        $this->assertFalse($explicit);
    }

    /** An unknown component defaults into wages — the statute's own default. */
    public function test_an_unknown_component_defaults_to_include(): void
    {
        list($class,) = Statutory_wage_base::classify('SOME_NEW_TENANT_COMPONENT');
        $this->assertSame('INCLUDE', $class);
    }

    // ── Legacy structure → classified set ────────────────────────────────

    /**
     * classifyLegacyStructure() and compute() deliberately DISAGREE, and the
     * disagreement is the whole exposure.
     *
     * compute() is frozen legacy behaviour (medical and other_allowances
     * excluded); the classified path applies the statutory defaults, under which
     * both are wages. On a realistic school structure that is a materially
     * larger PF base — precisely the number the parity harness reports and the
     * owner must accept before the flag moves.
     */
    public function test_the_statutory_reading_exceeds_the_legacy_reading(): void
    {
        $structure = [
            'basic' => 25000, 'hra' => 12500, 'da' => 0,
            'ta' => 2000, 'medical' => 1250, 'other_allowances' => 9250,
        ];

        $legacy = Statutory_wage_base::compute($structure);
        $stat   = Statutory_wage_base::computeFromComponents(
            Statutory_wage_base::classifyLegacyStructure($structure)
        );

        $this->assertSame(2500000, $legacy['basePaise'], 'legacy: basic only, DA is zero here');
        $this->assertSame(3550000, $stat['basePaise'],   'statutory: plus medical plus other allowances');
        $this->assertGreaterThan($legacy['basePaise'], $stat['basePaise'],
            'a statutory correction must never REDUCE a contribution base');
    }

    /** Every legacy field present is classified; none is silently dropped. */
    public function test_every_legacy_field_reaches_the_classified_set(): void
    {
        $structure = [
            'basic' => 100, 'hra' => 100, 'da' => 100,
            'ta' => 100, 'medical' => 100, 'other_allowances' => 100,
        ];

        $set   = Statutory_wage_base::classifyLegacyStructure($structure);
        $codes = array_column($set, 'code');

        foreach (array_keys($structure) as $field) {
            $this->assertContains($field, $codes, "{$field} must not be dropped");
        }

        $out = Statutory_wage_base::computeFromComponents($set);
        $this->assertSame([], $out['unclassified'],
            'every shipped component must have a statutory default — none may fall through');
        $this->assertSame(60000, $out['totalPaise'],
            'all six count toward remuneration: 6 x 100 rupees = 60000 paise');
    }

    /** Absent fields are not invented as zero-value components. */
    public function test_absent_fields_are_omitted_not_zeroed(): void
    {
        $set = Statutory_wage_base::classifyLegacyStructure(['basic' => 100]);

        $this->assertCount(1, $set);
        $this->assertSame('basic', $set[0]['code']);
    }

    /** A tenant catalogue override flows through the legacy classifier too. */
    public function test_the_legacy_classifier_honours_tenant_overrides(): void
    {
        $structure = ['basic' => 25000, 'other_allowances' => 25000];

        $default = Statutory_wage_base::computeFromComponents(
            Statutory_wage_base::classifyLegacyStructure($structure)
        );
        $overridden = Statutory_wage_base::computeFromComponents(
            Statutory_wage_base::classifyLegacyStructure($structure, [
                'other_allowances' => ['wageClassification' => 'CONDITIONAL'],
            ])
        );

        $this->assertSame(5000000, $default['basePaise'], 'both are wages by default');
        $this->assertSame(2500000, $overridden['basePaise'],
            'the override moves it out of the base — exactly at the threshold, so no add-back');
    }
}
