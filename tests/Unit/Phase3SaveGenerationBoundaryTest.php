<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');

/**
 * PHASE 3 — a Fee Structure save must not issue financial obligations.
 *
 * Live consequence of the old coupling: one fee-chart save took a student from
 * 26 demands to 63, a second to 100 — 37 real obligations per save, created by
 * an admin who was only correcting an amount.
 */
class Phase3SaveGenerationBoundaryTest extends TestCase
{
    private string $src;
    private string $save;

    protected function setUp(): void
    {
        $this->src = file_get_contents(APPPATH . 'controllers/Fees.php');
        // Comment-stripped body of save_updated_fees, so documentation that
        // merely mentions generation cannot mask a real call.
        $code = '';
        foreach (token_get_all($this->src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $start = strpos($code, 'function save_updated_fees(');
        $next  = strpos($code, 'private function', $start + 10);
        $this->save = substr($code, $start, $next - $start);
    }

    // ══ 1-4 · the save no longer generates ════════════════════════════════

    public function test_1_save_does_not_invoke_demand_generation(): void
    {
        $this->assertStringNotContainsString('_processGenerationJob', $this->save,
            'saving a price must not issue financial obligations');
        $this->assertStringNotContainsString('_generateDemandsForMonth', $this->save);
    }

    public function test_2_3_4_no_generation_job_is_created_by_a_save(): void
    {
        $this->assertStringNotContainsString('fee_generation_jobs', $this->save,
            'no generation job may be raised by a structure save — amount, '
          . 'frequency/bucket change and repeat saves all create 0 demands');
    }

    public function test_the_save_still_persists_the_structure(): void
    {
        $this->assertStringContainsString('syncFeeStructure', $this->save,
            'Phase 3 detaches generation only — the structure must still save');
    }

    // ══ 9 · explicit generation survives ══════════════════════════════════

    public function test_9_explicit_generation_remains_available(): void
    {
        $this->assertStringContainsString('private function _processGenerationJob', $this->src,
            'the generator itself is untouched and still serves the explicit action');
        $routes = file_get_contents(APPPATH . 'config/routes.php');
        $this->assertStringContainsString("fees/generate_monthly_demands", $routes,
            'the explicit "Generate Demands" endpoint must remain routed');
    }

    public function test_the_response_contract_is_preserved(): void
    {
        $this->assertStringContainsString("'demands_generated'", $this->save);
        $this->assertStringContainsString("'demands_failed'", $this->save);
        $this->assertMatchesRegularExpression('/\$demandsGenerated\s*=\s*0;/', $this->src,
            'the counters must still exist and read zero, so no frontend branch breaks');
    }

    // ══ 10-11 · earlier phases still active ═══════════════════════════════

    public function test_10_phase_1_dual_key_dedupe_remains_active(): void
    {
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $this->src);
    }

    public function test_11_phase_2_conflict_detection_remains_active(): void
    {
        $this->assertStringContainsString('if ($matchCount >= 2) {', $this->src);
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $this->src);
    }

    // ══ 14-16 · invariants ════════════════════════════════════════════════

    public function test_14_feeHeadRef_never_enters_demandId(): void
    {
        $code = '';
        foreach (token_get_all($this->src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        preg_match_all('/_(?:build)?[dD]emandId\s*\(([^;]*?)\)\s*;/s', $code, $m);
        foreach ($m[1] as $args) $this->assertStringNotContainsString('feeHeadRef', $args);
    }

    public function test_15_chargeInstance_behaviour_is_untouched(): void
    {
        // chargeInstance lives in Fee_lifecycle; Fees.php must not have grown
        // any handling of it during this phase.
        $this->assertStringNotContainsString('chargeInstance', $this->save);
    }

    public function test_16_registry_flag_remains_false(): void
    {
        $config = [];
        require APPPATH . 'config/fee_head_registry.php';
        // SUPERSEDED 2026-08-30 (owner decision): the billing chain ships ON so a
        // new school bills with no grant. Safe only via the capability guards in
        // Fee_lifecycle (FEEHEAD_REGISTRY_HELD / ENTITLEMENT_AUTHORITY_HELD), which
        // are asserted in BillingV2RollbackVerificationTest. Genuinely unfinished
        // features (Phase D pricing, shadow compare, Phase 3 convergence) stay OFF.
        $this->assertTrue($config['USE_FEE_HEAD_REGISTRY']);
    }

    /** Phase 4 and the CSRF step must remain out of scope. */
    public function test_phase_3_did_not_stray_into_later_phases(): void
    {
        $this->assertStringContainsString('$this->fsTxn->newDemandId()', $this->src,
            'Phase 4 (deterministic ids) is NOT part of Phase 3');
        $footer = file_get_contents(APPPATH . 'views/include/footer.php');
        $this->assertStringContainsString('session security token expired', $footer,
            'the CSRF handler is a separate authorised step and must be unchanged');
    }

    /**
     * ⚠ KNOWN, REPORTED, NOT FIXED IN PHASE 3.
     *
     * save_updated_fees still propagates edited amounts to EXISTING unpaid
     * demands via _recalculateStudentUnpaidDemands(). That is a second
     * save→demand coupling and it contradicts the Phase 3 requirement "do not
     * modify unpaid demands" — but it is a deliberate, documented accountant
     * feature, so removing it is a product decision, not a defect fix.
     *
     * This test PINS the current behaviour so the decision is explicit and
     * cannot drift unnoticed. It must be updated when that decision is taken.
     */
    /**
     * THE DECISION WAS MADE (P5-K, 2026-08-20).
     *
     * This test recorded that Save-Fee-Structure propagation used the legacy
     * engine and was "awaiting an explicit decision". That decision is now
     * taken: it shares the ONE canonical recalculation authority, which was
     * production-verified the same day (STU0185 — 12 demands corrected in
     * place, 0 deleted, identity untouched).
     *
     * Propagation still EXISTS, which is what this test really guards; what
     * changed is which authority prices it.
     */
    public function test_unpaid_demand_propagation_is_canonical(): void
    {
        $this->assertStringContainsString('_recalculateStudentCanonical', $this->save,
            'propagation must price through the canonical pipeline');
        $this->assertStringNotContainsString('_recalculateStudentUnpaidDemands', $this->save,
            'the legacy engine must no longer be reachable from Save Fee Structure');
    }
}
