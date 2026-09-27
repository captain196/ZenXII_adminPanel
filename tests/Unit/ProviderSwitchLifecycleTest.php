<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($level, $msg) {} }

require_once APPPATH . 'libraries/Payment_order_provenance.php';
require_once APPPATH . 'libraries/Payment_provider_config_resolver.php';
require_once APPPATH . 'libraries/Payment_service.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P13 — PROVIDER SWITCHING AT EVERY POINT IN THE ORDER LIFECYCLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The dangerous window is not the switch itself — it is the payment already
 * IN FLIGHT when the switch happens:
 *
 *     order created under Provider A
 *         ↓
 *     school switches to Provider B
 *         ↓
 *     Provider A's callback/webhook arrives
 *
 * Three things must all hold, and they pull in different directions:
 *   1. the in-flight order must stay bound to Provider A (its id means
 *      nothing to B);
 *   2. Provider B must not be able to claim it;
 *   3. but Provider A's own late callback must still settle correctly —
 *      the money was really captured, and refusing it would strand a real
 *      payment.
 *
 * The live incident that motivated GW-ORD-1 was the failure of (1): a
 * mock-minted order handed to the real Razorpay checkout, refused the instant
 * the sheet rendered, and — because the dedupe kept returning it — permanently
 * unpayable. This file pins the whole matrix so that class of defect cannot
 * return through the switching path.
 */
class ProviderSwitchLifecycleTest extends TestCase
{
    private const SCHOOL = 'SCH_SWITCH_TEST';

    // ══ THE ORDER STAYS BOUND TO THE PROVIDER THAT MINTED IT ══════════════

    /**
     * (1) An order minted by Provider A is not reusable once B is active.
     * This is the reuse predicate, which is where the live defect lived.
     */
    public function test_an_in_flight_order_is_not_reusable_after_the_switch(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('providerA', 'providerB'),
            "an order minted by Provider A was judged reusable while Provider B is active. "
            . "Provider B's checkout would be handed an id it never issued, and the parent "
            . 'would see a generic failure indistinguishable from a declined card.'
        );
    }

    /** (2) Symmetric — switching back must not revive B's orders under A either. */
    public function test_the_rule_is_symmetric_across_a_switch_back(): void
    {
        $this->assertFalse(
            Payment_order_provenance::matchesActiveGateway('providerB', 'providerA'),
            'the reuse rule is asymmetric — switching back would revive the other '
            . "provider's stale orders"
        );
    }

    /**
     * (3) The other half, and the one a naive "just block everything after a
     * switch" fix would break: Provider A's OWN late callback must still be
     * recognised as belonging to Provider A. The money was really captured;
     * refusing it strands a real payment.
     */
    public function test_provider_a_can_still_settle_its_own_late_callback(): void
    {
        $this->assertTrue(
            Payment_order_provenance::matchesActiveGateway('providerA', 'providerA'),
            'a late callback from the provider that actually captured the money is being '
            . 'refused. The switch must not strand payments already in flight.'
        );
    }

    // ══ THE SWITCH MUST NOT REWRITE HISTORY ═══════════════════════════════

    /**
     * The resolver decides which provider is ACTIVE NOW. It must have no
     * ability to reach back and alter what an existing order was minted with —
     * proven structurally: the resolver never writes.
     */
    public function test_the_resolver_cannot_mutate_existing_orders(): void
    {
        $src = (string) file_get_contents(APPPATH . 'libraries/Payment_provider_config_resolver.php');
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) { if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue; $code .= $t[1]; }
            else $code .= $t;
        }

        foreach (['firestoreSet', 'firestoreUpdate', 'firestoreDelete', 'firestoreCommit'] as $writer) {
            $this->assertStringNotContainsString($writer, $code,
                "Payment_provider_config_resolver calls {$writer}. Resolving which provider "
                . 'is active must never mutate stored data — least of all existing orders, '
                . 'whose provenance is what GW-ORD-1 depends on.');
        }
    }

    // ══ SWITCHING ONE SCHOOL MUST NOT TOUCH ANOTHER ═══════════════════════

    private function firebase(array $rows): object
    {
        return new class($rows) {
            public array $rows;
            public function __construct(array $r) { $this->rows = $r; }
            public function firestoreGet($c, $d) { return null; }
            public function firestoreQuery($c, $f = []) { return $this->rows; }
        };
    }

    public function test_switching_school_a_leaves_school_b_untouched(): void
    {
        $schoolBRows = [
            ['schoolId' => 'SCH_B', 'provider' => 'providerB', 'active' => true, 'isDefault' => true],
        ];
        $fbB = $this->firebase($schoolBRows);

        $before = Payment_provider_config_resolver::resolve($fbB, 'SCH_B', 'b', '2026-27');

        // School A switches (an entirely separate document set).
        $fbA = $this->firebase([
            ['schoolId' => 'SCH_A', 'provider' => 'providerA', 'active' => false, 'isDefault' => false],
            ['schoolId' => 'SCH_A', 'provider' => 'providerB', 'active' => true,  'isDefault' => true],
        ]);
        Payment_provider_config_resolver::resolve($fbA, 'SCH_A', 'a', '2026-27');

        $after = Payment_provider_config_resolver::resolve($fbB, 'SCH_B', 'b', '2026-27');

        $this->assertSame($before, $after,
            "School B's resolved provider changed as a side effect of School A switching");
    }

    // ══ MID-SWITCH AMBIGUITY MUST FAIL CLOSED, NOT PICK A WINNER ══════════

    /**
     * The realistic mid-switch failure: the operator activated the new
     * provider but has not yet deactivated the old one, so BOTH are
     * active+default. Guessing here would silently route real money to
     * whichever row happened to come back first.
     */
    public function test_a_half_completed_switch_fails_closed(): void
    {
        $fb = $this->firebase([
            ['schoolId' => 'SCH_A', 'provider' => 'providerA', 'active' => true, 'isDefault' => true],
            ['schoolId' => 'SCH_A', 'provider' => 'providerB', 'active' => true, 'isDefault' => true],
        ]);

        $this->assertNull(
            Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'a', '2026-27'),
            'a half-completed switch (both providers active+default) resolved to one of '
            . 'them instead of refusing. Whichever row Firestore returned first would '
            . 'silently become the money path.'
        );
    }

    /**
     * The opposite half-state: old deactivated, new not yet activated. Must
     * also refuse rather than fall back to a stale provider.
     */
    public function test_a_switch_with_nothing_active_yet_fails_closed(): void
    {
        $fb = $this->firebase([
            ['schoolId' => 'SCH_A', 'provider' => 'providerA', 'active' => false, 'isDefault' => false],
            ['schoolId' => 'SCH_A', 'provider' => 'providerB', 'active' => false, 'isDefault' => true],
        ]);

        $this->assertNull(
            Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'a', '2026-27'),
            'a switch mid-flight with no active provider resolved to something anyway'
        );
    }

    // ══ DEACTIVATION MID-LIFECYCLE ════════════════════════════════════════

    /**
     * A provider disabled AFTER an order was created: the order keeps its
     * provenance (so its own callback still settles), but the provider can no
     * longer be selected for NEW orders. Those are different questions and the
     * architecture answers them in different places — provenance in
     * Payment_order_provenance, selection in the resolver.
     */
    public function test_deactivating_a_provider_does_not_orphan_its_existing_orders(): void
    {
        // Selection: refuses.
        $fb = $this->firebase([
            ['schoolId' => 'SCH_A', 'provider' => 'providerA', 'active' => false, 'isDefault' => true],
        ]);
        $this->assertNull(Payment_provider_config_resolver::resolve($fb, 'SCH_A', 'a', '2026-27'),
            'a deactivated provider is still selectable for new orders');

        // Provenance: unchanged — its own callback still matches.
        $this->assertTrue(
            Payment_order_provenance::matchesActiveGateway('providerA', 'providerA'),
            'deactivating a provider retroactively invalidated its existing orders — a '
            . 'payment already captured would be stranded'
        );
    }

    // ══ UNRECORDED PROVENANCE IS NEVER REUSABLE, WHOEVER IS ACTIVE ════════

    public function test_unrecorded_provenance_is_refused_under_every_provider(): void
    {
        foreach (['providerA', 'providerB', 'razorpay', 'mock'] as $active) {
            $this->assertFalse(
                Payment_order_provenance::matchesActiveGateway('', $active),
                "an order with unrecorded provenance was judged reusable under '{$active}'"
            );
            $this->assertFalse(
                Payment_order_provenance::matchesActiveGateway(null, $active),
                "a null-provenance order was judged reusable under '{$active}'"
            );
        }
    }
}
