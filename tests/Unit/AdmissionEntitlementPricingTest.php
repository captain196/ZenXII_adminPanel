<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  ADM-ENT-1 — A CONCESSION TAKEN AT ADMISSION WAS RECORDED BUT NOT PRICED
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The Admission → Entitlement → Fees chain is correctly ORDERED. `Sis.php`
 * resolves the entitlement after identity and before pricing, and says why:
 *
 *   "Placement is load-bearing: after _claimIdentityAttributes() so the student
 *    is complete, and before assignInitialFees() so the demand is priced once,
 *    correctly, rather than written and then repaired."
 *
 * But ordering was only half the problem. The two ends write and read
 * DIFFERENT STORES:
 *
 *   WRITE  Entitlement_service::COL = 'studentEntitlements'   (canonical)
 *          — and the admission call is NOT gated by any flag.
 *   READ   Fee_lifecycle picks its pricing reader FROM
 *          USE_ENTITLEMENT_BILLING: canonical when true,
 *          `studentConcessions` when false.
 *
 * That flag is FALSE for 8 of the 9 tenants. On those, a concession agreed at
 * the admission desk becomes an ACTIVE entitlement in a store the pricing
 * engine never reads, `assignInitialFees()` prices at full rate — and the
 * admission response said:
 *
 *     "Concession recorded and applied to the initial demands."
 *
 * which was false. That is the exact failure `Sis.php`'s own header describes
 * as already fixed: *"The operator saw 'Admission successful', the parent saw a
 * full-price invoice, and nothing anywhere reported a problem."* The ordering
 * fix did not close it; the store mismatch reproduces the same symptom.
 *
 * ── WHY THE FIX IS A TRUTHFUL STATE, NOT A FALLBACK READ ──────────────────
 * The tempting fix — have pricing check the other store too — is forbidden.
 * The architecture enforces exactly ONE pricing authority ("NEVER BOTH"), and a
 * fallback would resurrect the split-authority hazard that rule exists to
 * prevent. The real resolution is the per-tenant grant, which is rollout and is
 * gated on the Transport proration defect (DEC-012). Until then the honest
 * behaviour is to report that the demands are full price — loudly enough that
 * nobody collects against them.
 */
class AdmissionEntitlementPricingTest extends TestCase
{
    private static string $sis = '';

    public static function setUpBeforeClass(): void
    {
        self::$sis = (string) file_get_contents(APPPATH . 'controllers/Sis.php');
    }

    private function executable(string $src): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', $src);
        $src = preg_replace('!^\s*//.*$!m', '', $src);
        return (string) preg_replace('!^\s*\*.*$!m', '', $src);
    }

    // ══ CONTROL — THE TWO ENDS REALLY DO DIVERGE ════════════════════════════

    /**
     * If the writer ever became flag-gated, or the reader stopped branching,
     * ADM-ENT-1 would not exist and this file should be re-derived rather than
     * trusted.
     */
    public function test_control_the_writer_is_canonical_and_ungated(): void
    {
        $svc = (string) file_get_contents(APPPATH . 'libraries/Entitlement_service.php');

        $this->assertStringContainsString("const COL = 'studentEntitlements'", $svc,
            'the entitlement service no longer writes to the canonical store');
        $this->assertStringNotContainsString('USE_ENTITLEMENT_BILLING', $this->executable($svc),
            'the entitlement WRITE is now flag-gated. If it follows the same flag as the '
            . 'reader the two ends can no longer diverge, and ADM-ENT-1 is obsolete.');
    }

    public function test_control_the_pricing_reader_branches_on_the_flag(): void
    {
        $lifecycle = $this->executable((string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'));

        $this->assertStringContainsString('Fee_entitlement_reader', $lifecycle);
        $this->assertStringContainsString('Fee_concession_reader', $lifecycle,
            'pricing no longer has a legacy reader — if there is only one authority, '
            . 'the divergence this file guards cannot happen');
    }

    // ══ THE FIX ═════════════════════════════════════════════════════════════

    /**
     * WOULD HAVE FAILED BEFORE: the resolver returned 'applied' for any active
     * entitlement, regardless of which store pricing reads.
     */
    public function test_an_unpriced_entitlement_is_reported_as_recorded_not_priced(): void
    {
        $src = $this->executable(self::$sis);

        // Assert the RESOLVER RETURNS it — not merely that the string appears
        // somewhere in the file.
        //
        // The first version of this test checked only that
        // "'recorded_not_priced'" existed in Sis.php. Reverting the resolver's
        // return left the suite GREEN, because the two branches in the CALLER
        // still mentioned the string. A test that passes against both the fixed
        // and the broken code proves nothing; found by running the revert
        // rather than assuming the assertion was sound.
        $this->assertMatchesRegularExpression(
            "/return \[\s*'status'\s*=>\s*'recorded_not_priced',/",
            $src,
            'the admission resolver no longer RETURNS the "recorded but not priced" state. '
            . 'Without it an unpriceable concession is reported as \'applied\', and the '
            . 'operator is told it was applied to demands that are actually at full price.'
        );

        // And the guard that decides it must still be there.
        $this->assertMatchesRegularExpression(
            '/if \(\$__isActive && !\$__canonicalPricing\)/',
            $src,
            'the condition distinguishing "priced" from "merely recorded" is gone, so every '
            . 'active entitlement reports as applied again regardless of which store this '
            . 'tenant prices from'
        );

        $this->assertStringContainsString("USE_ENTITLEMENT_BILLING", $src,
            'the admission resolver no longer consults the pricing authority flag, so it '
            . 'cannot know whether the entitlement it just wrote is readable by pricing');
    }

    /**
     * An unknown flag state must resolve to "not priced". Assuming the
     * favourable answer is what produces a confident false success message.
     */
    public function test_an_unreadable_flag_is_treated_as_not_priced(): void
    {
        $src = $this->executable(self::$sis);

        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable [^)]*\)\s*\{\s*\$__canonicalPricing = false;/',
            $src,
            'a failure to read the pricing-authority flag does not default to "not priced". '
            . 'Defaulting the other way claims a concession was applied when nothing '
            . 'verified that it could be.'
        );
    }

    /**
     * The operator message must not claim the concession was applied.
     */
    public function test_the_operator_is_told_the_demands_are_full_price(): void
    {
        $src = self::$sis;

        $at = strpos($src, "'recorded_not_priced'");
        $this->assertNotFalse($at);

        $this->assertStringContainsString('NOT applied to these demands', $src,
            'the admission response still implies the concession was applied. The operator '
            . 'has no other signal that the invoice the parent receives is at full price.');

        $this->assertStringContainsString('Do not collect against them', $src,
            'the billing note does not warn against collecting. A full-price demand that '
            . 'everyone believes is discounted will be collected in good faith.');
    }

    /**
     * It must be downgraded to a pending billing state, exactly as a failed
     * entitlement already is — the operator consequence is identical.
     */
    public function test_it_downgrades_the_billing_state_like_a_failed_entitlement(): void
    {
        $src = $this->executable(self::$sis);

        $this->assertMatchesRegularExpression(
            "/'recorded_not_priced'\)\s*\{\s*\\\$billingState = 'billing_pending';/",
            $src,
            "an unpriced concession leaves billingState as 'billed'. A demand that is at "
            . 'full price when a concession was agreed is not a completed billing outcome.'
        );
    }

    /**
     * And it must be logged with a greppable marker — this is the condition an
     * operator will need to find retrospectively across a tenant.
     */
    public function test_the_condition_is_logged_greppably(): void
    {
        $this->assertStringContainsString('ACC_ADMISSION_ENTITLEMENT_NOT_PRICED', self::$sis,
            'there is no greppable marker for this condition. Finding every affected '
            . 'admission after the fact is exactly what a log marker is for.');
    }

    // ══ THE FORBIDDEN FIX ═══════════════════════════════════════════════════

    /**
     * Pricing must NOT be "fixed" by reading both stores. The architecture
     * enforces one authority; a fallback reintroduces split authority, which is
     * a worse defect than the one being solved.
     */
    public function test_pricing_still_reads_exactly_one_authority(): void
    {
        $lifecycle = $this->executable((string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php'));

        // ── ANCHORED ON THE BRANCH, NOT THE FIRST MENTION (2026-08-30) ──────
        // This was strpos(…, 'useEntitlementAuthority') + a 900-char window.
        // The first MENTION of the variable is its declaration, and the safety
        // guard that now sits between the declaration and the branch (a school
        // holding active legacy concessions stays on the legacy authority)
        // pushed both reader assignments past 900 characters. The test then
        // reported 0 assignments — while the property it guards, exactly one
        // reader per branch, was completely intact.
        //
        // Anchor on the branch itself so the window is the if/else that
        // actually selects the reader, however much guarding precedes it.
        $at = strrpos($lifecycle, 'if ($useEntitlementAuthority) {');
        $this->assertNotFalse($at, 'the pricing authority selection was restructured');
        $window = substr($lifecycle, $at, 900);

        // One reader assigned in each branch — never both merged.
        $this->assertSame(1, substr_count($window, '$pricingReader = $CI->entitlementReader;'));
        $this->assertSame(1, substr_count($window, '$pricingReader = $CI->concessionReader;'));
        $this->assertStringNotContainsString('array_merge', $window,
            'the pricing reader merges results from both stores. ADM-ENT-1 must NOT be '
            . 'solved this way: the architecture enforces one authority, and reading both '
            . 'resurrects the split-authority hazard that rule exists to prevent.');
    }
}
