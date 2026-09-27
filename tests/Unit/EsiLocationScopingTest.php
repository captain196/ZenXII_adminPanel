<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 2 — ESI applicability is a property of a PLACE, not of an employer.
 *
 * The Employees' State Insurance scheme applies in areas the appropriate
 * government has notified as IMPLEMENTED. A school with two campuses can
 * legitimately have one inside such an area and one outside it.
 *
 * ZenX modelled applicability as a single tenant-wide `esi.applicable` boolean.
 * A multi-campus school had to choose between over-deducting at the uncovered
 * campus and under-deducting at the covered one, and neither is lawful.
 *
 * WHAT MOVED AND WHAT DID NOT
 * ---------------------------
 *   applicability   LOCAL    — depends on whether the area is implemented
 *   rates           NATIONAL — the same wherever the scheme applies
 *   wage threshold  NATIONAL — likewise
 *
 * Only applicability moved. Copying rates onto every location would invite them
 * to drift apart with no legal basis, and would multiply what a CA must verify.
 *
 * These exercise the REAL service.
 */
final class EsiLocationScopingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Statutory_config_service.php';
    }

    private function cfg(bool $tenantWide = true): array
    {
        return ['esi' => [
            'applicable'         => $tenantWide,
            'employeeRate'       => 0.75,
            'employerRate'       => 3.25,
            'wageThresholdPaise' => 2100000,
        ]];
    }

    // ── Resolution ───────────────────────────────────────────────────────

    /** A location inside an implemented area is covered. */
    public function test_a_covered_location_is_applicable(): void
    {
        $r = Statutory_config_service::resolveEsiApplicability(
            $this->cfg(), ['name' => 'Main Campus', 'esiApplicable' => true]
        );

        $this->assertTrue($r['applicable']);
        $this->assertSame('location', $r['source']);
        $this->assertStringContainsString('Main Campus', $r['reason']);
    }

    /**
     * THE CASE THE TENANT-WIDE BOOLEAN COULD NOT EXPRESS. A location outside an
     * implemented area is NOT covered, even though the tenant-wide setting says
     * ESI applies.
     */
    public function test_an_uncovered_location_overrides_the_tenant_setting(): void
    {
        $r = Statutory_config_service::resolveEsiApplicability(
            $this->cfg(true), ['name' => 'Rural Campus', 'esiApplicable' => false]
        );

        $this->assertFalse($r['applicable'],
            'a campus outside an implemented area is not covered, whatever the tenant default says');
        $this->assertSame('location', $r['source']);
    }

    /** And the converse: a covered location where the tenant default is off. */
    public function test_a_covered_location_overrides_a_tenant_default_of_off(): void
    {
        $r = Statutory_config_service::resolveEsiApplicability(
            $this->cfg(false), ['name' => 'City Campus', 'esiApplicable' => true]
        );

        $this->assertTrue($r['applicable']);
        $this->assertSame('location', $r['source']);
    }

    // ── The tri-state, which is the whole safety property ────────────────

    /**
     * A location that has said NOTHING inherits, and must never be read as
     * "not applicable". Defaulting an unconfigured location to false would
     * silently stop deducting ESI for everyone posted there the moment
     * locations were introduced — a change nobody asked for, arriving as a side
     * effect of a data model.
     */
    public function test_an_undecided_location_inherits_rather_than_excluding(): void
    {
        foreach ([
            ['name' => 'New Campus'],                      // field absent
            ['name' => 'New Campus', 'esiApplicable' => null],
            ['name' => 'New Campus', 'esiApplicable' => ''],
        ] as $loc) {
            $r = Statutory_config_service::resolveEsiApplicability($this->cfg(true), $loc);

            $this->assertTrue($r['applicable'],
                'an undecided location must inherit, not exclude');
            $this->assertSame('tenant', $r['source']);
        }
    }

    /** An employee with no work location at all falls back to the tenant setting. */
    public function test_no_location_falls_back_to_the_tenant_setting(): void
    {
        foreach ([true, false] as $tenantWide) {
            $r = Statutory_config_service::resolveEsiApplicability($this->cfg($tenantWide), null);

            $this->assertSame($tenantWide, $r['applicable']);
            $this->assertSame('tenant', $r['source']);
            $this->assertStringContainsString('no work location', $r['reason']);
        }
    }

    // ── It reaches the money ─────────────────────────────────────────────

    /** The resolved applicability actually changes the computed contribution. */
    public function test_the_determination_changes_the_contribution(): void
    {
        $covered   = Statutory_config_service::computeEsi($this->cfg(), 2000000, true);
        $uncovered = Statutory_config_service::computeEsi($this->cfg(), 2000000, false);

        $this->assertTrue($covered['covered']);
        $this->assertGreaterThan(0, $covered['employeePaise']);

        $this->assertFalse($uncovered['covered']);
        $this->assertSame(0, $uncovered['employeePaise']);
        $this->assertSame(0, $uncovered['employerPaise']);
        $this->assertStringContainsString('work location', $uncovered['reason'],
            'the reason must say WHERE the exclusion came from');
    }

    /** Null override ⇒ the tenant-wide setting, byte-for-byte as before. */
    public function test_a_null_override_preserves_the_previous_behaviour(): void
    {
        $before = Statutory_config_service::computeEsi($this->cfg(true), 2000000);
        $after  = Statutory_config_service::computeEsi($this->cfg(true), 2000000, null);

        $this->assertSame($before, $after,
            'omitting the override must be identical to the pre-Feature-2 call');

        $off = Statutory_config_service::computeEsi($this->cfg(false), 2000000, null);
        $this->assertFalse($off['covered']);
        $this->assertStringContainsString('tenant', $off['reason']);
    }

    /** The national threshold still applies at a covered location. */
    public function test_the_wage_threshold_still_applies_at_a_covered_location(): void
    {
        $r = Statutory_config_service::computeEsi($this->cfg(), 5000000, true);

        $this->assertFalse($r['covered'],
            'a covered location does not exempt an employee from the wage threshold');
        $this->assertStringContainsString('threshold', $r['reason']);
    }

    // ── Rates stayed national ────────────────────────────────────────────

    /**
     * Only applicability moved to the location. If rates had moved too, two
     * campuses could drift apart with no legal basis for the divergence.
     */
    public function test_rates_and_threshold_did_not_move_to_the_location(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Statutory_config_service.php'
        );

        $i = strpos($src, 'public static function resolveEsiApplicability(');
        $this->assertNotFalse($i);

        // Bounded at the NEXT method, not by a character count. A fixed window
        // overran into computeEsi(), where the national values legitimately
        // appear — and a test that fails for the wrong reason is worse than no
        // test, because the next person "fixes" it by widening the assertion.
        $end = strpos($src, 'public static function computeEsi(', (int) $i);
        $this->assertNotFalse($end, 'computeEsi must follow the resolver');
        $body = substr($src, (int) $i, (int) $end - (int) $i);

        $this->assertStringContainsString('resolveEsiApplicability', $body, 'slice sanity');

        foreach (['employeeRate', 'employerRate', 'wageThresholdPaise'] as $national) {
            $this->assertStringNotContainsString($national, $body,
                "{$national} is national — it must not be resolvable per location");
        }
    }

    // ── Payroll wiring ───────────────────────────────────────────────────

    /** Both payroll engines assemble the employee statutory facts. */
    public function test_both_engines_resolve_the_work_location(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertSame(2, substr_count($hr, '$this->_staff_statutory_facts('),
            'generation and regeneration must both resolve the location');
        $this->assertStringContainsString(
            'Statutory_config_service::resolveEsiApplicability(', $hr);
    }

    /** The engine honours the resolved value, distinguishing null from false. */
    public function test_the_engine_distinguishes_undecided_from_not_applicable(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringContainsString(
            "\$esiLocationApplicable = array_key_exists('_esiLocationApplicable', \$statutoryProfile)",
            $hr,
            'an absent key must mean "nobody decided", not "not applicable"'
        );
        $this->assertStringContainsString(
            'Statutory_config_service::computeEsi($statutory, $grossP, $esiLocationApplicable);', $hr);
    }

    /**
     * Locations are read ONCE each, not once per employee. A school has a
     * handful of campuses and hundreds of staff.
     */
    public function test_location_reads_are_memoised(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $i = strpos($hr, 'private function _location_doc(');
        $this->assertNotFalse($i, 'location reader not found');
        $body = substr($hr, (int) $i, 1200);

        $this->assertStringContainsString('static $memo = [];', $body);
        $this->assertStringContainsString('array_key_exists($locationId, $memo)', $body,
            'isset() would re-read on every employee for a location that resolved to null');
    }

    /** A failed location read must inherit, never exclude. */
    public function test_a_failed_location_read_inherits(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $i = strpos($hr, 'private function _location_doc(');
        $body = substr($hr, (int) $i, 1200);

        $this->assertStringContainsString('HR location read failed for', $body,
            'a silent catch would stop ESI for a whole campus without saying so');
        $this->assertStringContainsString('return $memo[$locationId] = $doc;', $body);
    }

    /** The class the facts assembler needs is loaded BEFORE it is used. */
    public function test_the_profile_class_is_loaded_before_first_use(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $load = strpos($hr, 'libraries/Staff_statutory_profile_service.php');
        $use  = strpos($hr, 'Staff_statutory_profile_service::FIELD');

        $this->assertNotFalse($load);
        $this->assertNotFalse($use);
        $this->assertLessThan($use, $load,
            'the facts assembler runs before _compute_payslip, so relying on that '
            . 'method to load the class would be a fatal that php -l cannot see');
    }

    // ── Admin surface ────────────────────────────────────────────────────

    /** The determination is settable, tri-state, and validated. */
    public function test_the_location_admin_accepts_a_tri_state_determination(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Workforce_locations.php'
        );

        $this->assertStringContainsString("\$esiRaw = \$this->input->post('esi_applicable', TRUE);", $src);
        $this->assertStringContainsString('$esiApplicable = null;', $src,
            'undecided must be representable');
        $this->assertStringContainsString(
            'ESI applicability must be yes, no, or left undecided.', $src,
            'a nonsense value must be refused rather than coerced');
    }

    /**
     * Persisted on CREATE unconditionally, and on UPDATE only when the field
     * was actually submitted.
     *
     * CORRECTED 2026-08-23. This previously required the update to write
     * `esiApplicable` unconditionally — which was the bug, not the guarantee.
     * The rename control on the school-config screen posts only
     * {location_id, name}, so an unconditional write turned every rename into a
     * silent wipe of the ESI determination, and everybody posted at that
     * location would quietly start or stop having ESI deducted.
     *
     * Absent is not cleared. That distinction is what this now asserts.
     */
    public function test_the_determination_is_persisted_safely(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Workforce_locations.php'
        );

        // Create: always stored — a new location has nothing to preserve.
        $this->assertStringContainsString("'esiApplicable' => \$esiApplicable,", $src,
            'a new location must store whatever was chosen');

        // Update: only when the field was actually submitted.
        $this->assertStringContainsString('$esiSubmitted = ($esiRaw !== null);', $src,
            'absent and explicitly-cleared must be distinguishable');
        $this->assertStringContainsString(
            "if (\$esiSubmitted) \$patch['esiApplicable'] = \$esiApplicable;", $src,
            'an unsubmitted field must leave the stored determination alone');

        // And the screen that CAN change it always sends it, so "back to
        // undecided" stays reachable once a value has been set.
        $view = (string) file_get_contents(
            __DIR__ . '/../../application/views/school_config/index.php'
        );
        $this->assertStringContainsString('esi_applicable:', $view,
            'a control that never posts its value cannot clear a determination');
    }

    /** And audited, because it decides whether a whole campus has ESI deducted. */
    public function test_the_determination_is_audited(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Workforce_locations.php'
        );

        $this->assertStringContainsString('ESI: {$esiLabel}', $src);
    }

    /**
     * THE UI-REACHABILITY TEST. The field must survive the list projection, or
     * it would be storable and configurable but invisible.
     */
    public function test_the_determination_reaches_the_admin_list(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/helpers/terminology_helper.php'
        );

        $this->assertStringContainsString("'esiApplicable' => array_key_exists('esiApplicable', \$d)", $src,
            'a field that never reaches the list is configured in the backend and '
            . 'unreachable from the UI');
        $this->assertStringNotContainsString("'esiApplicable' => !empty(\$d['esiApplicable'])", $src,
            'empty() would flatten undecided into false and lose the tri-state');
    }
}
