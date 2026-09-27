<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * CONFIGURATION COMPLETENESS — can a school actually supply every value?
 *
 * The rule this suite enforces: a tenant value must be settable through the
 * Admin panel, validated server-side, effective-dated where a statute can
 * change it, audited, and tenant-scoped. A value that requires editing a PHP
 * file, a Firestore console or a developer is not configurable.
 *
 * Two gaps found on 2026-08-23 and fixed here:
 *
 *   CA-4  the add-back threshold lived ONLY in `config/hrms_statutory_flags.php`
 *         — a developer setting: tenant-wide, undated, code-only. The proviso
 *         to s.2(y) says the percentage may be NOTIFIED, so a change must apply
 *         from its own date and must not reprice earlier periods.
 *
 *   CA-8  ESI applicability per location was accepted, persisted and projected
 *         — with no form field anywhere, so no school could set it.
 *
 * And one defect introduced by the CA-8 work itself: renaming a location would
 * have WIPED its ESI determination, because an absent form field was being
 * treated as "set to undecided".
 */
final class ConfigurationCompletenessTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
        require_once __DIR__ . '/../../application/libraries/Statutory_wage_base.php';
    }

    private function svc(): Statutory_config_service
    {
        return new Statutory_config_service();
    }

    // ── CA-4: the threshold is tenant configuration, not code ────────────

    /** It is part of the dated statutory configuration. */
    public function test_the_add_back_threshold_is_part_of_the_dated_configuration(): void
    {
        list($clean, $errors) = $this->svc()->validate([
            'effectiveFrom' => '2026-04-01',
            'addBack' => ['thresholdNum' => 3, 'thresholdDen' => 5, 'source' => 'Notification 12/2026'],
        ]);

        $this->assertSame([], $errors, implode(' ', $errors));
        $this->assertSame(3, $clean['addBack']['thresholdNum']);
        $this->assertSame(5, $clean['addBack']['thresholdDen']);
        $this->assertSame('Notification 12/2026', $clean['addBack']['source'],
            'a statutory value with no recorded provenance is unauditable');
    }

    /** It defaults to one-half, the current position. */
    public function test_it_defaults_to_one_half(): void
    {
        list($clean, $errors) = $this->svc()->validate(['effectiveFrom' => '2026-04-01']);

        $this->assertSame([], $errors);
        $this->assertSame(1, $clean['addBack']['thresholdNum']);
        $this->assertSame(2, $clean['addBack']['thresholdDen']);
    }

    /** Nonsense is refused and falls back rather than being stored. */
    public function test_an_impossible_threshold_is_refused(): void
    {
        foreach ([
            ['thresholdNum' => 1, 'thresholdDen' => 0],     // divide by zero
            ['thresholdNum' => 5, 'thresholdDen' => 2],     // over 100%
            ['thresholdNum' => -1, 'thresholdDen' => 2],    // negative
            ['thresholdNum' => 1.5, 'thresholdDen' => 2],   // fractional
            ['thresholdNum' => 'half', 'thresholdDen' => 2],
        ] as $bad) {
            list($clean, $errors) = $this->svc()->validate([
                'effectiveFrom' => '2026-04-01', 'addBack' => $bad,
            ]);

            $this->assertNotEmpty($errors, 'must be reported: ' . json_encode($bad));
            $this->assertSame(1, $clean['addBack']['thresholdNum'],
                'and must fall back to one-half rather than storing the nonsense');
            $this->assertSame(2, $clean['addBack']['thresholdDen']);
        }
    }

    /** A tenant configuration OUTRANKS the PHP fallback. */
    public function test_a_tenant_threshold_outranks_the_php_fallback(): void
    {
        $cfg = ['addBack' => ['thresholdNum' => 3, 'thresholdDen' => 5]];

        $o = Statutory_config_service::addBackOptions($cfg, 1, 2);

        $this->assertSame(3, $o['thresholdNum']);
        $this->assertSame(5, $o['thresholdDen']);
        $this->assertSame('tenant', $o['source']);
    }

    /** And an unconfigured tenant falls back, saying so. */
    public function test_an_unconfigured_tenant_falls_back_and_says_so(): void
    {
        foreach ([null, [], ['addBack' => []], ['addBack' => ['thresholdDen' => 0]]] as $cfg) {
            $o = Statutory_config_service::addBackOptions($cfg, 1, 2);

            $this->assertSame(1, $o['thresholdNum']);
            $this->assertSame(2, $o['thresholdDen']);
            $this->assertSame('fallback', $o['source'],
                'the caller must be able to tell a configured value from a default');
        }
    }

    /**
     * THE POINT OF MOVING IT. Because it now lives in the dated configuration,
     * a notified change applies from its own date and cannot reprice an
     * earlier period.
     */
    public function test_a_notified_change_does_not_reprice_an_earlier_period(): void
    {
        $versions = [
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-08-31',
             'addBack' => ['thresholdNum' => 1, 'thresholdDen' => 2]],
            ['effectiveFrom' => '2026-09-01', 'effectiveTo' => null,
             'addBack' => ['thresholdNum' => 3, 'thresholdDen' => 5]],
        ];

        $components = [
            ['code' => 'BASIC', 'amountPaise' => 3000000, 'classification' => 'INCLUDE'],
            ['code' => 'HRA',   'amountPaise' => 7000000, 'classification' => 'CONDITIONAL'],
        ];

        $july = Statutory_wage_base::computeFromComponents($components,
            Statutory_config_service::addBackOptions(
                Statutory_config_service::selectFor($versions, '2026-07-31')));

        $sept = Statutory_wage_base::computeFromComponents($components,
            Statutory_config_service::addBackOptions(
                Statutory_config_service::selectFor($versions, '2026-09-30')));

        $this->assertSame(2000000, $july['addBackPaise'], 'one-half of 100,000 is 50,000');
        $this->assertSame(1000000, $sept['addBackPaise'], 'three-fifths is 60,000');
        $this->assertNotSame($july['addBackPaise'], $sept['addBackPaise'],
            'the two periods must be able to differ, which is the whole reason for dating it');
    }

    /** The PHP file survives only as a documented fallback. */
    public function test_the_php_file_is_only_a_fallback(): void
    {
        $flags = (string) file_get_contents(
            __DIR__ . '/../../application/config/hrms_statutory_flags.php');

        $this->assertStringContainsString('STATUTORY_ADDBACK_THRESHOLD_NUM', $flags,
            'the fallback must still exist for a tenant with no statutory configuration');

        $harness = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Hrms_w2_parity.php');

        $this->assertStringContainsString('Statutory_config_service::addBackOptions(', $harness,
            'consumers must resolve through the tenant-first helper, not read the flag directly');
    }

    // ── CA-8: ESI per location is settable from a screen ─────────────────

    /** The field exists in the locations screen, as a TRI-STATE control. */
    public function test_the_location_esi_field_is_on_the_screen(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/school_config/index.php');

        $this->assertStringContainsString('ESI applicable', $view,
            'a column the school can see');
        $this->assertStringContainsString("['', 'Use school setting']", $view,
            'undecided must be selectable, or every location is forced on or off');
        $this->assertStringContainsString("['1', 'Applicable here']", $view);
        $this->assertStringContainsString("['0', 'Not applicable here']", $view);
    }

    /** The screen explains why a location might differ. */
    public function test_the_locations_screen_explains_itself(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/school_config/index.php');

        $this->assertStringContainsString('areas the government has notified', $view);
    }

    /** And it actually sends the field. */
    public function test_the_locations_screen_sends_the_field(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/school_config/index.php');

        $this->assertStringContainsString('esi_applicable:', $view,
            'a control that never posts its value is decoration');
        $this->assertStringContainsString('function saveLocation(locationId, name, esiApplicable)', $view);
    }

    /**
     * THE DEFECT THE CA-8 WORK ITSELF INTRODUCED, now fixed.
     *
     * The rename control posts only {location_id, name}. Writing
     * `esiApplicable` unconditionally on update meant renaming a location
     * WIPED its determination — and everybody posted there would quietly start
     * or stop having ESI deducted.
     */
    public function test_an_absent_field_leaves_the_determination_alone(): void
    {
        $ctrl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Workforce_locations.php');

        $this->assertStringContainsString('$esiSubmitted = ($esiRaw !== null);', $ctrl,
            'absent and cleared must be distinguishable');
        $this->assertStringContainsString(
            "if (\$esiSubmitted) \$patch['esiApplicable'] = \$esiApplicable;", $ctrl,
            'an unsubmitted field must not be written');

        $this->assertStringNotContainsString(
            "'esiApplicable' => \$esiApplicable,\n                'updatedAt'", $ctrl,
            'the unconditional write is what wiped the determination');
    }

    /** The audit line distinguishes "unchanged" from "set to inherit". */
    public function test_the_audit_line_distinguishes_unchanged_from_inherit(): void
    {
        $ctrl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Workforce_locations.php');

        $this->assertStringContainsString("!\$esiSubmitted ? 'unchanged'", $ctrl,
            'an audit trail that reports a change which did not happen is worse than none');
    }

    // ── CA-7: gratuity policy is capturable, and the floor is enforced ───

    /** Gratuity policy is part of the same dated configuration. */
    public function test_gratuity_policy_is_part_of_the_dated_configuration(): void
    {
        list($clean, $errors) = $this->svc()->validate([
            'effectiveFrom' => '2026-04-01',
            'gratuity' => [
                'eligibilityYears'    => 5,
                'maxPayablePaise'     => 200000000,
                'transitionTreatment' => 'split_at_transition',
                'source'              => 'CA advice 2026-08',
            ],
        ]);

        $this->assertSame([], $errors, implode(' ', $errors));
        $this->assertSame(5, $clean['gratuity']['eligibilityYears']);
        $this->assertSame(200000000, $clean['gratuity']['maxPayablePaise']);
        $this->assertSame('split_at_transition', $clean['gratuity']['transitionTreatment']);
        $this->assertSame('CA advice 2026-08', $clean['gratuity']['source']);
    }

    /**
     * A school may pay EARLIER than the statutory floor — many do, and that is
     * lawful generosity.
     */
    public function test_a_school_may_pay_gratuity_earlier_than_the_floor(): void
    {
        list($clean, $errors) = $this->svc()->validate([
            'effectiveFrom' => '2026-04-01',
            'gratuity' => ['eligibilityYears' => 3],
        ]);

        $this->assertSame([], $errors);
        $this->assertSame(3, $clean['gratuity']['eligibilityYears']);
    }

    /**
     * THE ONE THAT MATTERS. A school may NOT require longer than the statutory
     * qualifying period — that would be configuring something unlawful.
     */
    public function test_a_school_cannot_require_longer_than_the_statutory_period(): void
    {
        list($clean, $errors) = $this->svc()->validate([
            'effectiveFrom' => '2026-04-01',
            'gratuity' => ['eligibilityYears' => 7],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('never later', implode(' ', $errors));
        $this->assertSame(5, $clean['gratuity']['eligibilityYears'],
            'and it must clamp back to the floor rather than storing the unlawful value');
    }

    /**
     * The Nov 2025 transition treatment starts UNSET, because the position was
     * still settling and picking one would invent somebody's entitlement.
     */
    public function test_the_transition_treatment_starts_unset(): void
    {
        list($clean, $errors) = $this->svc()->validate(['effectiveFrom' => '2026-04-01']);

        $this->assertSame([], $errors);
        $this->assertSame('unset', $clean['gratuity']['transitionTreatment']);
    }

    /** An unknown treatment is refused rather than coerced to a working one. */
    public function test_an_unknown_transition_treatment_is_refused(): void
    {
        list($clean, $errors) = $this->svc()->validate([
            'effectiveFrom' => '2026-04-01',
            'gratuity' => ['transitionTreatment' => 'whatever_hr_prefers'],
        ]);

        $this->assertNotEmpty($errors);
        $this->assertSame('unset', $clean['gratuity']['transitionTreatment']);
    }

    /**
     * The death/disablement waiver is RECORDED, not offered. Section 4(1)
     * waives the qualifying period unconditionally, so making it switchable
     * would let a school configure something unlawful.
     */
    public function test_the_death_waiver_is_not_configurable(): void
    {
        list($clean) = $this->svc()->validate([
            'effectiveFrom' => '2026-04-01',
            'gratuity' => ['waivedOnDeathOrDisablement' => false],
        ]);

        $this->assertTrue($clean['gratuity']['waivedOnDeathOrDisablement'],
            'a school must not be able to switch off a statutory waiver');
    }

    /** The statutory floor is a named, sourced constant — not a scattered literal. */
    public function test_the_statutory_period_is_a_named_constant_with_a_source(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Statutory_config_service.php');

        $this->assertStringContainsString('const GRATUITY_STATUTORY_YEARS = 5;', $src);
        $this->assertStringContainsString('Payment of Gratuity Act 1972, s.4(1)', $src,
            'a statutory number with no citation cannot be re-verified');
        $this->assertStringContainsString('TODO: verify with CA', $src);
    }
    // ── CA-4 / CA-7 must be REACHABLE, not merely storable ───────────────

    /**
     * Both new configuration blocks have form fields on the statutory tab.
     *
     * Caught 2026-08-23 during this same pass: the validation and storage were
     * built and the screen was not, which is exactly the defect the operator
     * had already reported once. Checked here so it cannot happen a third time.
     */
    public function test_the_new_statutory_blocks_have_form_fields(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/compensation/index.php');

        foreach ([
            'sAbNum'    => 'add-back threshold numerator',
            'sAbDen'    => 'add-back threshold denominator',
            'sAbSrc'    => 'add-back source',
            'sGrYears'  => 'gratuity qualifying years',
            'sGrCap'    => 'gratuity maximum',
            'sGrTrans'  => 'gratuity transition treatment',
            'sGrSrc'    => 'gratuity source',
        ] as $id => $label) {
            $this->assertStringContainsString("id=\"{$id}\"", $view,
                "{$label} has no field — a school cannot supply it");
        }
    }

    /** And the save actually sends them. */
    public function test_the_statutory_save_sends_the_new_blocks(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/compensation/index.php');

        $this->assertStringContainsString('addBack: {', $view,
            'a field that never posts its value is decoration');
        $this->assertStringContainsString('gratuity: {', $view);
        $this->assertStringContainsString("transitionTreatment: document.getElementById('sGrTrans').value", $view);
    }

    /** And the table shows them once saved, so they are not write-only. */
    public function test_the_statutory_table_shows_the_new_blocks(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/compensation/index.php');

        $this->assertStringContainsString('<th class="num">Add-back</th><th>Gratuity</th>', $view);
        $this->assertStringContainsString('var ab = c.addBack || {}, gr = c.gratuity || {};', $view);
        $this->assertStringContainsString('(transition not set)', $view,
            'an unset transition must be visible, not silently blank');
    }

    /** The screen explains that the threshold is notifiable, not a constant. */
    public function test_the_screen_explains_the_threshold_is_notifiable(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/compensation/index.php');

        // Contiguous fragments only: the sentence wraps across lines in the
        // template, so the full phrase never appears as one string. Asserting
        // the wrapped form is how a correct screen fails a test.
        $this->assertStringContainsString('the Central Government may', $view);
        $this->assertStringContainsString('notify another', $view);
        $this->assertStringContainsString('Confirm the figure with your accountant', $view);
    }

    /** And that gratuity may be paid earlier but never later. */
    public function test_the_screen_explains_the_gratuity_floor(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/compensation/index.php');

        $this->assertStringContainsString('but never later', $view);
    }
    // ── Every tenant value is reachable ──────────────────────────────────

    /**
     * The standing rule, checked as a set: each configurable value must have a
     * screen. Listed explicitly so a new one cannot be added without appearing
     * here.
     */
    public function test_every_tenant_configurable_value_has_a_screen(): void
    {
        $compensation = (string) file_get_contents(
            __DIR__ . '/../../application/views/compensation/index.php');
        $schoolConfig = (string) file_get_contents(
            __DIR__ . '/../../application/views/school_config/index.php');

        $surfaces = [
            'component wage classification' => [$compensation, 'save_component_classification'],
            'F&F notice recovery basis'     => [$compensation, 'fnfBasis'],
            'F&F approval threshold'        => [$compensation, 'fnfThreshold'],
            'F&F clearance scope'           => [$compensation, 'fnfClearance'],
            'employee PF membership'        => [$compensation, 'spState'],
            'employee UAN'                  => [$compensation, 'spUan'],
            'F&F preview'                   => [$compensation, 'pvRun'],
            'ESI per location'              => [$schoolConfig, 'esi_applicable'],
        ];

        foreach ($surfaces as $label => list($view, $needle)) {
            $this->assertStringContainsString($needle, $view,
                "{$label} has no screen — a school cannot supply it");
        }
    }
}
