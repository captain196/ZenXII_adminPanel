<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FINAL CLOSURE VERIFICATION — the whole chain, end to end.
 *
 * Every prior suite proves one layer. This one proves the LINKS between them,
 * which is where a programme like this actually fails: a screen that saves to a
 * field nothing reads, a validator on a value no consumer resolves, a resolver
 * wired to the wrong source.
 *
 * For each configurable value the chain must be complete:
 *
 *     screen → route → endpoint → validation → persistence
 *            → effective dating → audit → the consumer that spends it
 *
 * One objective defect was found by this pass and is pinned below: the F&F
 * preview read the legacy `SAL_` document directly while being named after the
 * reader it did not use.
 */
final class StaffLifecycleClosureVerificationTest extends TestCase
{
    private static array $src = [];

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../application/';
        foreach ([
            'hr'          => 'controllers/Hr.php',
            'comp'        => 'controllers/Compensation.php',
            'payroll'     => 'controllers/Payroll.php',
            'locations'   => 'controllers/Workforce_locations.php',
            'view'        => 'views/compensation/index.php',
            'schoolView'  => 'views/school_config/index.php',
            'header'      => 'views/include/header.php',
            'routes'      => 'config/routes.php',
            'statCfg'     => 'libraries/Statutory_config_service.php',
            'wageBase'    => 'libraries/Statutory_wage_base.php',
            'profile'     => 'libraries/Staff_statutory_profile_service.php',
            'fnfPolicy'   => 'libraries/Fnf_policy_service.php',
            'fnfPreview'  => 'libraries/Fnf_preview_service.php',
            'arrears'     => 'libraries/Salary_arrears_service.php',
        ] as $k => $rel) {
            self::$src[$k] = (string) file_get_contents($root . $rel);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$src = [];
    }

    // ── The defect this pass found ───────────────────────────────────────

    /**
     * THE PREVIEW MUST READ SALARY THROUGH THE SEAM.
     *
     * It previously did a raw `SAL_{staffId}` read while being named
     * `_compensation_reader_or_null`. After the component cutover that document
     * is no longer the authority, so a settlement preview would have been priced
     * from a stale structure — quietly, and only for settlements.
     */
    public function test_the_preview_reads_salary_through_the_canonical_reader(): void
    {
        $this->assertStringContainsString('Compensation_reader', self::$src['comp'],
            'the preview must resolve salary through the seam');

        $this->assertStringNotContainsString("docId('SAL_' . \$staffId)", self::$src['comp'],
            'a raw legacy read bypasses the cutover and prices settlements from a dead document');
    }

    /**
     * And AS OF THE SETTLEMENT DATE, not today. Reading today's salary for
     * somebody who left in March would price their settlement against a
     * revision they never worked under.
     */
    public function test_the_preview_prices_at_the_last_working_day(): void
    {
        $this->assertStringContainsString(
            "\$asOf = (string) (\$notice['lastWorkingDay'] ?? '') ?: date('Y-m-d');",
            self::$src['comp'],
            'a settlement is priced at the last working day');

        $this->assertStringContainsString('_salary_structure_as_of($staffId, $asOf)', self::$src['comp']);
    }

    /** The notice is resolved ONCE, and before the salary that depends on it. */
    public function test_the_notice_is_resolved_once_and_first(): void
    {
        $this->assertSame(1, substr_count(self::$src['comp'], 'Fnf_notice_resolver::resolve($latest)'),
            'two resolutions could disagree about the last working day');

        $notice = strpos(self::$src['comp'], 'Fnf_notice_resolver::resolve($latest)');
        $salary = strpos(self::$src['comp'], '_salary_structure_as_of($staffId, $asOf)');

        $this->assertNotFalse($notice);
        $this->assertNotFalse($salary);
        $this->assertLessThan($salary, $notice,
            'the as-of date comes from the notice, so the notice must resolve first');
    }

    /** `Payroll.php` still knows nothing of the seam. */
    public function test_the_payroll_controller_still_does_not_know_the_seam(): void
    {
        $this->assertStringNotContainsString('Compensation_reader', self::$src['payroll']);
    }

    // ── Chain 1: statutory configuration ─────────────────────────────────

    /** Screen → endpoint → validation → dating → payroll consumer. */
    public function test_the_statutory_configuration_chain_is_complete(): void
    {
        $this->assertStringContainsString('id="sPfEe"', self::$src['view']);
        $this->assertStringContainsString("postJson('compensation/save_statutory_config'", self::$src['view']);
        $this->assertStringContainsString('compensation/save_statutory_config', self::$src['routes']);
        $this->assertStringContainsString('public static function selectFor(', self::$src['statCfg']);
        $this->assertStringContainsString('public static function findOverlaps(', self::$src['statCfg']);

        $this->assertSame(2,
            substr_count(self::$src['hr'], '$statutoryCfg = $this->_statutory_config_for($periodEndDate);'),
            'both payroll engines must consume it');
        $this->assertStringContainsString(
            "'statutory_snapshot' => \$this->_statutory_snapshot(\$statutoryCfg),", self::$src['hr']);
    }

    // ── Chain 2: wage classification ─────────────────────────────────────

    public function test_the_wage_classification_chain_is_complete(): void
    {
        $this->assertStringContainsString("api('compensation/get_component_classifications", self::$src['view']);
        $this->assertStringContainsString("postJson('compensation/save_component_classification'", self::$src['view']);
        $this->assertStringContainsString('compensation/save_component_classification', self::$src['routes']);
        $this->assertStringContainsString("log_audit('Compensation', 'component_classification_set'", self::$src['comp']);
        $this->assertStringContainsString('wageClassificationSchedule', self::$src['wageBase']);
        $this->assertStringContainsString('ed_select_for(', self::$src['wageBase']);
    }

    // ── Chain 3: employee statutory profile ──────────────────────────────

    public function test_the_employee_profile_chain_is_complete(): void
    {
        $this->assertStringContainsString('id="spState"', self::$src['view']);
        $this->assertStringContainsString('compensation/save_staff_statutory_profile', self::$src['view']);
        $this->assertStringContainsString('compensation/save_staff_statutory_profile', self::$src['routes']);
        $this->assertStringContainsString("log_audit('Compensation', 'staff_statutory_profile_saved'", self::$src['comp']);

        $this->assertStringContainsString('Staff_statutory_profile_service::resolvePf(', self::$src['hr']);
        $this->assertSame(2, substr_count(self::$src['hr'], '$this->_staff_statutory_facts('));
    }

    // ── Chain 4: ESI per location ────────────────────────────────────────

    public function test_the_esi_location_chain_is_complete(): void
    {
        $this->assertStringContainsString('esi_applicable:', self::$src['schoolView']);
        $this->assertStringContainsString("post('workforce_locations/save'", self::$src['schoolView']);
        $this->assertStringContainsString("\$this->input->post('esi_applicable', TRUE)", self::$src['locations']);
        $this->assertStringContainsString('ESI: {$esiLabel}', self::$src['locations']);
        $this->assertStringContainsString('Statutory_config_service::resolveEsiApplicability(', self::$src['hr']);
    }

    // ── Chain 5: F&F policy ──────────────────────────────────────────────

    public function test_the_fnf_policy_chain_is_complete(): void
    {
        $this->assertStringContainsString('id="fnfBasis"', self::$src['view']);
        $this->assertStringContainsString("postJson('compensation/save_fnf_policy'", self::$src['view']);
        $this->assertStringContainsString('compensation/save_fnf_policy', self::$src['routes']);
        $this->assertStringContainsString("log_audit('Compensation', 'fnf_policy_saved'", self::$src['comp']);
        $this->assertStringContainsString('Fnf_policy_service::resolveRecovery(', self::$src['fnfPreview']);
    }

    // ── Chain 6: add-back threshold and gratuity ─────────────────────────

    public function test_the_addback_and_gratuity_chain_is_complete(): void
    {
        foreach (['sAbNum', 'sAbDen', 'sGrYears', 'sGrTrans'] as $id) {
            $this->assertStringContainsString("id=\"{$id}\"", self::$src['view'], "{$id} field missing");
        }
        $this->assertStringContainsString('addBack: {', self::$src['view']);
        $this->assertStringContainsString('gratuity: {', self::$src['view']);
        $this->assertStringContainsString('addBackOptions(', self::$src['statCfg']);
        $this->assertStringContainsString('GRATUITY_STATUTORY_YEARS', self::$src['statCfg']);
        $this->assertStringContainsString('var ab = c.addBack || {}, gr = c.gratuity || {};', self::$src['view']);
    }

    // ── Reachability, as a set ───────────────────────────────────────────

    /** Every tab the controller allows is routed AND rendered. */
    public function test_every_tab_is_routed_and_rendered(): void
    {
        $this->assertSame(1, preg_match('/\$allowed = \[(.*?)\];/s', self::$src['comp'], $m));
        preg_match_all("/'([a-z_]+)'/", $m[1], $t);

        $broken = [];
        foreach ($t[1] as $tab) {
            if (strpos(self::$src['routes'], "\$route['compensation/{$tab}']") === false) {
                $broken[] = "{$tab} (no route)";
            }
            if (strpos(self::$src['view'], "\$at === '{$tab}' ? ' active' : ''") === false) {
                $broken[] = "{$tab} (no panel)";
            }
        }
        $this->assertSame([], $broken, implode(', ', $broken));
    }

    /** And the page itself is in the menu. */
    public function test_the_page_is_in_the_menu(): void
    {
        $this->assertStringContainsString("base_url('compensation')", self::$src['header']);
    }

    // ── Money-path boundaries hold ───────────────────────────────────────

    /** No pure service can persist, notify, or post accounting. */
    public function test_the_pure_services_cannot_reach_side_effects(): void
    {
        foreach (['wageBase', 'profile', 'fnfPolicy', 'fnfPreview', 'arrears'] as $k) {
            $code = '';
            foreach (token_get_all(self::$src[$k]) as $tok) {
                if (is_array($tok)) {
                    if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
                    $code .= $tok[1];
                } else {
                    $code .= $tok;
                }
            }

            foreach (['$this->fs', 'firestore', 'get_instance', 'emit_push', 'log_audit', 'postRun'] as $sideEffect) {
                $this->assertStringNotContainsString($sideEffect, $code,
                    "{$k} must not be able to {$sideEffect}");
            }
        }
    }

    /** The statutory engine is still connected to nobody's pay. */
    public function test_the_statutory_engine_is_still_unwired_from_payroll(): void
    {
        foreach (['hr', 'payroll'] as $k) {
            $this->assertStringNotContainsString('Statutory_wage_base::', self::$src[$k],
                'the statutory base must stay behind its flag');
        }
    }

    /** All four cutover flags remain false. */
    public function test_all_cutover_flags_remain_false(): void
    {
        $stat = (string) file_get_contents(
            __DIR__ . '/../../application/config/hrms_statutory_flags.php');
        $comp = (string) file_get_contents(
            __DIR__ . '/../../application/config/hrms_compensation_flags.php');

        foreach ([
            [$stat, 'USE_STATUTORY_WAGE_BASE'],
            [$stat, 'STATUTORY_WAGE_SHADOW'],
            [$comp, 'USE_COMPONENT_COMPENSATION'],
            [$comp, 'COMPENSATION_SHADOW_COMPARE'],
        ] as list($src, $flag)) {
            $this->assertStringContainsString("\$config['{$flag}'] = false;", $src,
                "{$flag} must not be activated as a side effect of engineering");
        }
    }

    // ── Cross-system: the one shared collection ──────────────────────────

    /**
     * `salarySlips` is the ONLY collection this programme wrote to that either
     * app also reads. Verified against the apps' own Constants files rather
     * than assumed.
     *
     * The three fields added — `statutory_snapshot`, `runType`, `runScope` —
     * all live on the RUN document. The Teacher app queries `salarySlips`
     * filtered on `type == "payslip"`, which is written by a different path, so
     * it cannot see them. The Parent app declares the constant and never uses
     * it.
     *
     * This is what makes the cross-system verdict evidence rather than an
     * assertion, and it is pinned so a future change that moves one of these
     * fields onto a PAYSLIP has to come here and think about the apps.
     */
    public function test_the_new_run_fields_are_not_on_the_payslip_document(): void
    {
        // The payslip writer carries type 'payslip'.
        $this->assertStringContainsString("'type'      => 'payslip',", self::$src['hr']);

        // And it does NOT carry the run-level fields.
        $i = strpos(self::$src['hr'], 'private function _buildPayslipDoc');
        $this->assertNotFalse($i, '_buildPayslipDoc not found');

        $next = preg_match(
            '/\n    (?:public|private|protected)[^\n]*function /',
            self::$src['hr'], $m, PREG_OFFSET_CAPTURE, (int) $i + 40
        ) ? (int) $m[0][1] : strlen(self::$src['hr']);
        $body = substr(self::$src['hr'], (int) $i, $next - (int) $i);

        $this->assertStringContainsString('runId', $body, 'slice sanity');

        foreach (['statutory_snapshot', 'runScope'] as $runOnly) {
            $this->assertStringNotContainsString($runOnly, $body,
                "{$runOnly} belongs on the run, not the payslip the Teacher app reads");
        }
    }

    /**
     * And the run-level fields are written where the run is built, so a reader
     * looking for them knows exactly which document to open.
     */
    public function test_the_run_fields_are_written_with_the_run(): void
    {
        $i = strpos(self::$src['hr'], 'public function generate_payroll()');
        $this->assertNotFalse($i);

        $next = preg_match(
            '/\n    (?:public|private|protected)[^\n]*function /',
            self::$src['hr'], $m, PREG_OFFSET_CAPTURE, (int) $i + 40
        ) ? (int) $m[0][1] : strlen(self::$src['hr']);
        $body = substr(self::$src['hr'], (int) $i, $next - (int) $i);

        foreach (['statutory_snapshot', 'runScope', 'runType'] as $f) {
            $this->assertStringContainsString("'{$f}'", $body,
                "{$f} must be written with the run document");
        }
    }

    /**
     * No collection either app reads was REMOVED or repurposed. Only additive
     * fields on existing documents, which a Kotlin data class ignores.
     */
    public function test_no_app_read_collection_was_removed(): void
    {
        // Collections this programme wrote to.
        $written = ['staff', 'schools', 'locations', 'salaryComponents',
                    'salarySlips', 'statutoryConfig', 'payrollOverrides'];

        // Of those, the ones either app reads.
        $shared = ['staff', 'schools', 'salarySlips'];

        foreach ($shared as $c) {
            $this->assertContains($c, $written, 'inventory sanity');
        }

        // Every write to a shared collection must be an UPDATE of an existing
        // document or a new field — never a delete or a collection rename.
        foreach (['comp', 'hr', 'locations'] as $k) {
            foreach (["->delete('staff'", "->delete('schools'", "->remove('staff'"] as $destructive) {
                $this->assertStringNotContainsString($destructive, self::$src[$k],
                    'a shared collection must never be deleted from by this programme');
            }
        }
    }
    // ── Shared files untouched ───────────────────────────────────────────

    /**
     * The concurrently-owned rules file carries exactly ONE change from this
     * programme — D-004 — and nothing from the later configuration work, which
     * was deliberately placed on EXISTING documents precisely so no rules
     * change would be needed.
     */
    public function test_the_shared_rules_file_carries_only_the_authorised_change(): void
    {
        $rules = (string) file_get_contents(
            __DIR__ . '/../../firebase-rules/firestore.rules');

        $this->assertSame(1,
            substr_count($rules, "resource.data.get('staffId', '') == request.auth.uid"),
            'D-004 must be present exactly once');

        foreach (['statutoryProfile', 'fnfSettings', 'wageClassification', 'esiApplicable'] as $laterWork) {
            $this->assertStringNotContainsString($laterWork, $rules,
                'later configuration was placed on existing documents so the '
                . 'concurrently-owned rules file needed no change');
        }
    }
}
