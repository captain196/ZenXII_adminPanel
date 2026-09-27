<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * How far per-component statutory classification actually reaches.
 *
 * ADDED 2026-08-22 during the PF statutory research. It changes NO production
 * behaviour — it records an architectural fact the target design turns on, so
 * that fact cannot quietly stop being true while the design is still on paper.
 *
 * THE FACT: `pfApplicable` and `esiApplicable` are captured in the compensation
 * UI, validated by `Salary_component_service`, stored on every catalogue row,
 * and then consumed by NOTHING in the calculation path.
 *
 * The chain breaks in two places, both of them deliberate and both documented
 * in the code itself:
 *
 *   1. `Compensation_reader::resolveFromComponents()` maps each assignment line
 *      onto one of six LEGACY_FIELDS and drops any component without a legacy
 *      counterpart — its own log line says the component "will not reach the
 *      calculator until the component-aware payslip gate".
 *
 *   2. `Hr::_compute_payslip()` therefore receives six rupee fields, not a
 *      classified component set, and computes PF on `$effectiveBasicP`.
 *
 * So the metadata the governing spec asks for (`docs/specs/hr-payroll-brief.md`
 * §6.1: "pf_applicable?, esi_applicable?") EXISTS at the schema layer and is
 * inert at the money layer. That is the precise gap between what ZenX has and
 * the component-classification architecture the spec §6.3 requires.
 *
 * These assertions are all "this is still inert". Every one of them is expected
 * to be INVERTED by the implementation when it is authorised — that is the
 * point. They fail loudly the moment classification starts reaching the money,
 * which is exactly when someone should be re-reading the design.
 */
final class StatutoryClassificationReachTest extends TestCase
{
    private static string $hr = '';
    private static string $reader = '';
    private static string $components = '';

    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Statutory_wage_base.php';

        $root = __DIR__ . '/../../application/';
        self::$hr         = (string) file_get_contents($root . 'controllers/Hr.php');
        self::$reader     = (string) file_get_contents($root . 'libraries/Compensation_reader.php');
        self::$components = (string) file_get_contents($root . 'libraries/Salary_component_service.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$hr = ''; self::$reader = ''; self::$components = '';
    }

    // ── The classification exists ────────────────────────────────────────

    /** The schema carries exactly the flags the governing spec §6.1 names. */
    public function test_the_component_master_carries_statutory_flags(): void
    {
        foreach (["'pfApplicable'", "'esiApplicable'", "'taxable'", "'prorated'"] as $field) {
            $this->assertStringContainsString($field, self::$components,
                "the component master must keep carrying {$field}");
        }
    }

    /** And they are immutable once set, so a reclassification is deliberate. */
    public function test_the_statutory_flags_are_locked_after_creation(): void
    {
        $i = strpos(self::$components, 'const LOCKED_FIELDS');
        $this->assertNotFalse($i);
        $block = substr(self::$components, (int) $i, 400);

        foreach (['pfApplicable', 'esiApplicable'] as $field) {
            $this->assertStringContainsString($field, $block,
                'silently reclassifying a component would silently change contributions');
        }
    }

    // ── …and reaches nothing ─────────────────────────────────────────────

    /**
     * THE LOAD-BEARING ASSERTION. No statutory classification flag is read
     * anywhere in the payroll controller.
     */
    public function test_the_payroll_engine_never_reads_a_classification_flag(): void
    {
        foreach (['pfApplicable', 'esiApplicable'] as $flag) {
            $this->assertStringNotContainsString($flag, self::$hr,
                "{$flag} is write-only metadata today — if this now appears in Hr.php, the "
                . 'component-classification cutover has begun and the design must be re-read');
        }
    }

    /** The reader collapses components onto the six legacy fields. */
    public function test_the_reader_flattens_components_to_legacy_fields(): void
    {
        $this->assertStringContainsString('$out = array_fill_keys(self::LEGACY_FIELDS, 0.0);', self::$reader,
            'component resolution currently produces a legacy-shaped structure, not a classified set');

        $this->assertStringContainsString('self::COMPONENT_TO_FIELD[$code] ?? null', self::$reader);
    }

    /**
     * And a component with no legacy counterpart is DROPPED. This is the single
     * most consequential property of the current seam: a tenant can define a
     * fully classified component and it will never reach a payslip.
     */
    public function test_a_component_without_a_legacy_field_never_reaches_the_calculator(): void
    {
        $this->assertStringContainsString(
            'it will not reach the calculator until the component-aware payslip gate.',
            self::$reader,
            'the drop must stay explicit and logged rather than becoming silent'
        );
    }

    /** The reader reads the catalogue, but only to expand BALANCING. */
    public function test_the_catalogue_is_read_only_for_balancing_expansion(): void
    {
        $this->assertStringContainsString('$catalogue = $this->componentCatalogue();', self::$reader);
        $this->assertStringContainsString('Salary_assignment_service::splitGross($assignment, $catalogue)', self::$reader,
            'the catalogue is consulted for the balancing split and for nothing statutory');
    }

    // ── What the calculator actually uses ────────────────────────────────

    /**
     * PF on basic, ESI on gross — the pre-codes model, pinned so the change is
     * visible when it happens. Both are asserted together because the labour
     * codes define ONE wage for both, so they should move together or the
     * reason they did not should be recorded.
     */
    public function test_pf_uses_basic_and_esi_uses_gross(): void
    {
        $i = strpos(self::$hr, 'private function _compute_payslip(');
        $this->assertNotFalse($i);
        $engine = substr(self::$hr, (int) $i, 9000);

        $this->assertStringContainsString(
            'Statutory_config_service::computePf($statutory, $effectiveBasicP);', $engine);
        // Feature 2 added a third argument: the applicability resolved at the
        // employee's WORK LOCATION. The BASE is still gross — which is what
        // this test is about — so the pin moves to the new call shape rather
        // than being dropped.
        $this->assertStringContainsString(
            'Statutory_config_service::computeEsi($statutory, $grossP, $esiLocationApplicable);', $engine);
    }

    /**
     * The seeded catalogue DESCRIBES that same model — PF applicable to BASIC
     * only. The seed, the engine and this test agree today; a fix must move all
     * three together or the catalogue would describe an engine that no longer
     * exists.
     */
    public function test_the_seed_describes_pf_as_basic_only(): void
    {
        $this->assertStringContainsString(
            "\$pct('PF_EMPLOYEE',  'Provident Fund (Employee)', 'deduction', 'BASIC', 'PF',  110),",
            self::$components,
            'the seeded PF component names BASIC as its basis'
        );
        $this->assertStringContainsString(
            "\$earning('DA',               'Dearness Allowance',   30, false),",
            self::$components,
            'and DA is seeded as NOT pf-applicable — the same statement the engine makes'
        );
    }

    // ── The wage engine, built and unreachable ───────────────────────────

    /**
     * W-1, FIXED 2026-08-22. The engine previously summed two named lists and
     * treated their sum as total remuneration, so a component in neither list
     * weighed zero in the wage base AND in the add-back denominator —
     * under-triggering the add-back, i.e. failing toward LESS statutory
     * liability. Section 2(y) defines wages as all remuneration MINUS named
     * exclusions, so the classified path now defaults an unknown component to
     * INCLUDE and names it in the trace.
     *
     * Inverted rather than deleted: the inclusion-list default is exactly what
     * must not come back.
     */
    public function test_an_unclassified_component_defaults_to_wages(): void
    {
        $out = Statutory_wage_base::computeFromComponents([
            ['code' => 'BASIC',           'amountPaise' => 5000000, 'classification' => 'INCLUDE'],
            ['code' => 'TENANT_INVENTED', 'amountPaise' => 1000000],   // no classification at all
        ]);

        $this->assertSame(6000000, $out['basePaise'],
            'an unclassified component must count as wages, not vanish');
        $this->assertSame(['TENANT_INVENTED'], $out['unclassified'],
            'and it must be NAMED, so the classification gap is visible rather than absorbed');
    }

    /**
     * W-2, FIXED 2026-08-22. The first proviso to s.2(y) reads "one-half, or
     * such other percentage as may be notified by the Central Government", so
     * the threshold is a dated configuration value rather than a constant.
     */
    public function test_the_add_back_threshold_is_a_parameter(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../application/libraries/Statutory_wage_base.php');
        $this->assertStringContainsString('const DEFAULT_THRESHOLD_NUM = 1;', $src);
        $this->assertStringContainsString('const DEFAULT_THRESHOLD_DEN = 2;', $src);

        $components = [
            ['code' => 'BASIC', 'amountPaise' => 3000000, 'classification' => 'INCLUDE'],
            ['code' => 'HRA',   'amountPaise' => 7000000, 'classification' => 'CONDITIONAL'],
        ];

        $half = Statutory_wage_base::computeFromComponents($components);
        $sixty = Statutory_wage_base::computeFromComponents($components,
            ['thresholdNum' => 3, 'thresholdDen' => 5]);

        $this->assertGreaterThan($sixty['addBackPaise'], $half['addBackPaise'],
            'a higher notified threshold must reduce the add-back — if the parameter '
            . 'were ignored these would be equal');
    }
}
