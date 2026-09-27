<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Payment_provider_config_resolver.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P4 — SCHOOL-SPECIFIC MULTI-PROVIDER CONFIGURATION RESOLUTION
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The most important property this file proves is the one the P4 gate itself
 * demands before wiring anything into a live money path: that a school with
 * NO `paymentProviders` registry entry gets EXACTLY what a direct
 * `feeSettings` read would have returned — byte for byte. There is exactly
 * one real gateway configuration across all nine ZenX tenants (the
 * architecture audit's own census), and this suite proves that tenant's
 * behaviour is unchanged by this file existing.
 */
class PaymentProviderConfigResolverTest extends TestCase
{
    private function firebase(array $legacyDoc = null, array $registryRows = []): object
    {
        return new class($legacyDoc, $registryRows) {
            public ?array $legacyDoc;
            public array $registryRows;
            public array $legacyReadCalls = [];
            public function __construct(?array $legacyDoc, array $registryRows) {
                $this->legacyDoc = $legacyDoc;
                $this->registryRows = $registryRows;
            }
            public function firestoreGet($collection, $docId) {
                $this->legacyReadCalls[] = [$collection, $docId];
                return $this->legacyDoc;
            }
            public function firestoreQuery($collection, $filters = []) {
                return $this->registryRows;
            }
        };
    }

    // ══ THE CRITICAL BACKWARD-COMPATIBILITY PROOF ═══════════════════════════

    /**
     * THE proof this gate cannot ship without. No paymentProviders rows at
     * all → the resolver must return EXACTLY the legacy document, and must
     * have called firestoreGet with the EXACT same collection/docId a direct
     * read would have used.
     */
    public function test_falls_back_byte_identically_when_no_paymentProviders_doc_exists(): void
    {
        $legacyDoc = ['provider' => 'razorpay', 'mode' => 'test', 'active' => true,
                      'api_key' => 'rzp_test_TTSDT123', 'api_secret' => 'secret123'];
        $fb = $this->firebase($legacyDoc, []);

        $result = Payment_provider_config_resolver::resolve(
            $fb, 'SCH_B56BB9A401', 'vikrantpublic', '2026-27'
        );

        $this->assertSame($legacyDoc, $result,
            'the resolved config differs from the legacy document — this would change '
            . 'behaviour for the one real configured tenant with zero registry migration');
        $this->assertSame([['feeSettings', 'vikrantpublic_2026-27_gateway']], $fb->legacyReadCalls,
            'the legacy read used a different collection/docId than the existing direct-read '
            . 'call sites — this would silently stop finding the real tenant\'s config');
    }

    public function test_falls_back_when_registry_query_throws(): void
    {
        $fb = new class {
            public function firestoreGet($c, $d) { return ['provider' => 'razorpay']; }
            public function firestoreQuery($c, $f = []) { throw new \Exception('index missing'); }
        };
        $result = Payment_provider_config_resolver::resolve($fb, 'SCH_X', 'x', '2026-27');
        $this->assertSame('razorpay', $result['provider'] ?? null);
    }

    public function test_returns_null_when_neither_source_has_anything(): void
    {
        $fb = $this->firebase(null, []);
        $this->assertNull(Payment_provider_config_resolver::resolve($fb, 'SCH_X', 'x', '2026-27'));
    }

    // ══ THE NEW MODEL — SCHOOL-SPECIFIC ACTIVE PROVIDER ═══════════════════

    public function test_resolves_the_single_default_active_registry_row(): void
    {
        $row = ['schoolId' => 'SCH_A', 'providerId' => 'cashfree', 'provider' => 'cashfree',
                'active' => true, 'isDefault' => true, 'api_key' => 'cf_key'];
        $fb = $this->firebase(['provider' => 'razorpay'], [$row]);

        $result = Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'schoolA', '2026-27');

        $this->assertSame('cashfree', $result['provider'] ?? null,
            'the registry entry exists and is the sole default+active row, but the legacy '
            . 'config was returned instead — the new model is not taking precedence');
    }

    /**
     * ── INVARIANT CHANGED, DELIBERATELY (2026-08-24) ─────────────────────
     * This test previously asserted the resolver FELL BACK to the legacy
     * config here. That was the defect, not the contract: a school with
     * configured providers, none marked default, would silently resume
     * collecting through the legacy gateway it had moved away from.
     *
     * Once a school has ANY row in paymentProviders it is migrated, and the
     * registry is authoritative — including when its answer is "none of
     * these is usable". Refusing is the safe outcome; falling back is not.
     */
    public function test_configured_but_no_default_refuses_rather_than_falling_back(): void
    {
        $row = ['schoolId' => 'SCH_A', 'provider' => 'providerB', 'active' => true, 'isDefault' => false];
        $fb = $this->firebase(['provider' => 'razorpay'], [$row]);

        $this->assertNull(
            Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'schoolA', '2026-27'),
            'a migrated school with no default provider fell back to its legacy gateway '
            . 'instead of refusing — it would collect through a provider it moved away from'
        );
    }

    /**
     * ── THE DEACTIVATION CASE — the reproduced defect ────────────────────
     * A school that DEACTIVATES its provider is making a deliberate decision:
     * stop collecting. Previously the resolver fell back to the legacy
     * gateway here, so the school silently resumed collecting through the
     * very provider it had switched off. Reproduced directly before the fix.
     *
     * The row must not be selected AND must not fall back. Both halves matter:
     * a test asserting only "not selected" passed against the defect.
     */
    public function test_a_deactivated_provider_refuses_and_does_not_fall_back(): void
    {
        $row = ['schoolId' => 'SCH_A', 'provider' => 'providerB', 'active' => false, 'isDefault' => true];
        $fb = $this->firebase(['provider' => 'razorpay'], [$row]);

        $result = Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'schoolA', '2026-27');

        $this->assertNotSame('providerB', $result['provider'] ?? null,
            'an inactive row marked isDefault was selected — active must be required too');
        $this->assertNull($result,
            'a school that deliberately DEACTIVATED its provider silently fell back to its '
            . 'legacy gateway and resumed collecting money through it. Deactivation must '
            . 'refuse, not reroute.');
    }

    /**
     * Two rows both claiming default is a data-integrity failure. Refusing is
     * the fail-closed choice — an arbitrary pick could silently activate the
     * wrong provider for real money, and falling back to legacy would be a
     * third, equally unintended provider.
     */
    public function test_multiple_default_rows_refuses_to_guess(): void
    {
        $rowA = ['schoolId' => 'SCH_A', 'provider' => 'providerA', 'active' => true, 'isDefault' => true];
        $rowB = ['schoolId' => 'SCH_A', 'provider' => 'providerB', 'active' => true, 'isDefault' => true];
        $fb = $this->firebase(['provider' => 'razorpay'], [$rowA, $rowB]);

        $this->assertNull(
            Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'schoolA', '2026-27'),
            'an ambiguous multi-default registry state resolved to something anyway instead '
            . 'of refusing — whichever row Firestore returned first, or the legacy config, '
            . 'would silently become the money path'
        );
    }

    /**
     * A query is not a substitute for re-asserting tenant identity. A row
     * belonging to another school must be discarded, and its presence must
     * not be mistaken for "this school is migrated".
     */
    public function test_a_foreign_tenant_row_is_discarded_and_does_not_imply_migration(): void
    {
        $foreign = ['schoolId' => 'SCH_OTHER', 'provider' => 'evilpay', 'active' => true, 'isDefault' => true];
        $fb = $this->firebase(['provider' => 'razorpay'], [$foreign]);

        $result = Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'schoolA', '2026-27');

        $this->assertNotSame('evilpay', $result['provider'] ?? null,
            'School A resolved to ANOTHER school\'s configured provider');
        $this->assertSame('razorpay', $result['provider'] ?? null,
            'School A has no rows of its own, so it is genuinely unmigrated and must fall '
            . 'back to its legacy config — the foreign row must be discarded, not counted');
    }

    // ══ SCHOOL A / SCHOOL B ISOLATION (structural half of the dynamic test) ═

    public function test_school_a_and_school_b_resolve_independently(): void
    {
        $fbA = $this->firebase(null, [
            ['schoolId' => 'SCH_A', 'provider' => 'razorpay', 'active' => true, 'isDefault' => true],
        ]);
        $fbB = $this->firebase(null, [
            ['schoolId' => 'SCH_B', 'provider' => 'cashfree', 'active' => true, 'isDefault' => true],
        ]);

        $resultA = Payment_provider_config_resolver::resolve($fbA, 'SCH_A', 'schoolA', '2026-27');
        $resultB = Payment_provider_config_resolver::resolve($fbB, 'SCH_B', 'schoolB', '2026-27');

        $this->assertSame('razorpay', $resultA['provider']);
        $this->assertSame('cashfree', $resultB['provider']);
    }

    // ══ WIRING PROOF — _init_payment_service actually calls the resolver ═══

    public function test_init_payment_service_calls_the_resolver(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fee_management.php');
        $fn = strpos($src, 'private function _init_payment_service');
        $end = strpos($src, "\n    private function ", $fn + 30);
        $body = substr($src, $fn, ($end !== false ? $end - $fn : 4000));

        $this->assertStringContainsString('Payment_provider_config_resolver::resolve(', $body,
            '_init_payment_service() no longer resolves config through the school-specific '
            . 'multi-provider resolver — this is the ONE call site that decides which adapter '
            . 'processes real money, and it must use the same resolution as everything else');
    }
}
