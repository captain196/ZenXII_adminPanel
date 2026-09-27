<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once APPPATH . 'libraries/Lifecycle_resolver.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  B-028 — A TENANT WITH NO CONTROL DOCUMENT MUST NOT BE TREATED AS ACTIVE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Two tenants (`SCH_202512`, `SCH_D94FE8F7AD`) have no `schoolControl` document.
 * That was carried as an open item awaiting a production "repair".
 *
 * Investigating it showed the framing was backwards. `Lifecycle_resolver`
 * already fails closed: a missing document leaves the commercial state empty,
 * which is not in COMMERCIAL_ALLOW, so the verdict is TENANT_STATE_UNKNOWN.
 *
 * Both tenants are dormant — 0 students, 0 staff; one is a superseded IIT
 * Kanpur tenant, the other a probe. Writing the "missing" document would
 * ACTIVATE two tenants that should stay dormant, converting a correct refusal
 * into an operational state. So B-028 is closed as not-a-defect, and the
 * production write that was blocked on authorization should not be performed.
 *
 * That conclusion rests entirely on the fail-closed behaviour below, which is
 * now the only thing standing between a dormant tenant and normal operation.
 * These are REAL behavioural tests against the static resolver core — no
 * source-shape assertions, no mocks.
 */
class TenantStateFailClosedTest extends TestCase
{
    // ══ THE B-028 CONDITION ═════════════════════════════════════════════════

    /**
     * The exact state produced by a missing schoolControl document: resolve()
     * finds nothing to read, so the commercial state stays ''.
     */
    public function test_an_absent_control_document_is_not_an_allowed_state(): void
    {
        $verdict = Lifecycle_resolver::explain('', 'active');

        $this->assertSame('TENANT_STATE_UNKNOWN', $verdict['code'],
            'a tenant whose control document is missing no longer resolves to '
            . 'TENANT_STATE_UNKNOWN. If it now resolves to an ALLOWED state, the two '
            . 'dormant B-028 tenants have silently become operational.');
    }

    public function test_the_empty_state_is_not_in_the_allow_list(): void
    {
        $this->assertNotContains('', Lifecycle_resolver::COMMERCIAL_ALLOW,
            'the empty commercial state was added to the allow-list. That makes "we could '
            . 'not confirm this tenant is entitled to operate" equivalent to "this tenant '
            . 'is active" — the exact inversion B-028 depends on not happening.');
    }

    /**
     * The message must tell an operator what is wrong. A generic error would
     * make a dormant tenant look like an outage and invite someone to "fix" it
     * by writing the document.
     */
    public function test_the_unknown_state_is_explained_not_just_refused(): void
    {
        $verdict = Lifecycle_resolver::explain('', 'active');

        $this->assertNotSame('', trim((string) ($verdict['message'] ?? '')),
            'the unknown-tenant verdict carries no message');
        $this->assertStringContainsStringIgnoringCase('status', $verdict['message'],
            'the message should say the account STATUS could not be confirmed, so the '
            . 'condition is not mistaken for a transient failure');
    }

    // ══ THE ALLOW-LIST IS NOT ACCIDENTALLY BROAD ════════════════════════════

    /**
     * Every state that should permit operation, and nothing else. Pinned as a
     * set rather than spot-checked: a single addition here silently grants
     * operation to a class of tenant.
     */
    public function test_only_the_four_entitled_states_permit_operation(): void
    {
        $this->assertSame(
            ['trialing', 'active', 'expiring_soon', 'grace'],
            Lifecycle_resolver::COMMERCIAL_ALLOW,
            'the set of commercial states permitting operation changed. Each entry lets a '
            . 'whole class of tenant transact — including, today, accept parent payments, '
            . 'because the money path does not consult this gate at all (BILL-LC1).'
        );
    }

    /**
     * The denied states must stay denied. `suspended` in particular is the one
     * whose *billing* treatment is an open business decision (BILL-LC1) — but
     * its ACCESS treatment is settled and must not drift.
     */
    public function test_the_known_denying_states_are_all_refused(): void
    {
        foreach (Lifecycle_resolver::COMMERCIAL_DENY as $state) {
            $verdict = Lifecycle_resolver::explain($state, 'active');

            $this->assertNotSame('', (string) ($verdict['code'] ?? ''),
                "the denying state '{$state}' produced no verdict code");
            $this->assertNotContains($state, Lifecycle_resolver::COMMERCIAL_ALLOW,
                "'{$state}' appears in BOTH the allow and deny lists — the composition is "
                . 'ambiguous and whichever is checked first wins');
        }
    }

    // ══ INSTITUTION AXIS — MOST RESTRICTIVE WINS ════════════════════════════

    /**
     * The two axes are orthogonal and compose most-restrictive-wins. A
     * commercially-active school that has CLOSED must not read as operable
     * merely because the invoice is paid.
     */
    public function test_a_closed_institution_is_refused_even_when_commercially_active(): void
    {
        $verdict = Lifecycle_resolver::explain('active', 'closed');

        $this->assertSame('SCHOOL_CLOSED', $verdict['code'],
            'a closed institution resolved as operable because it was commercially active. '
            . 'The axes must compose most-restrictive-wins.');
    }

    public function test_an_archived_institution_is_refused_even_when_commercially_active(): void
    {
        $this->assertSame('SCHOOL_ARCHIVED',
            Lifecycle_resolver::explain('active', 'archived')['code']);
    }

    /**
     * And the reverse direction: an institutionally-active school whose
     * subscription lapsed must still be refused ACCESS. (Whether it may still
     * COLLECT money is the separate, undecided BILL-LC1 question.)
     */
    public function test_an_active_institution_with_a_lapsed_subscription_is_refused(): void
    {
        $verdict = Lifecycle_resolver::explain('expired', 'active');

        $this->assertNotSame('', (string) ($verdict['code'] ?? ''));
        $this->assertNotContains('expired', Lifecycle_resolver::COMMERCIAL_ALLOW);
    }

    // ══ THE HAPPY PATH MUST STILL WORK ══════════════════════════════════════

    /**
     * Without this, every assertion above could be satisfied by a resolver that
     * refuses everything — which would fail closed perfectly and serve nobody.
     */
    public function test_control_an_ordinary_active_tenant_is_permitted(): void
    {
        foreach (Lifecycle_resolver::COMMERCIAL_ALLOW as $state) {
            $verdict = Lifecycle_resolver::explain($state, 'active');

            $this->assertNotSame('TENANT_STATE_UNKNOWN', $verdict['code'] ?? '',
                "an ordinary '{$state}' tenant was refused as unknown — the resolver now "
                . 'denies everything, which would pass every fail-closed assertion above '
                . 'while breaking every real school');
        }
    }
}
