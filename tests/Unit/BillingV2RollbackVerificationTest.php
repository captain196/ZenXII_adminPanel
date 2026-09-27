<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Billing_v2_verifier.php';
require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';

/**
 * BILLING V2 — ROLLBACK VERIFICATION.
 *
 * "Rollback is proven" is a production-readiness gate in its own right, and it
 * is the one most often asserted and least often tested. A feature that cannot
 * be switched off cleanly is not ready, however well it works switched on.
 *
 * The rollback contract for every V2 flag:
 *   1. OFF is the shipped default and the arithmetic is byte-identical.
 *   2. Revoking a flag must never require touching DATA to restore correctness.
 *   3. Data written while ON must remain valid, inert and non-alarming after OFF.
 *   4. The verifier must not report a clean rollback as a corruption.
 *
 * Point 4 is the subtle one and the reason this file exists. After
 * `USE_ALLOCATION_LEDGER` is revoked, new demands are still priced but emit no
 * allocation rows. A naive invariant ("every discount must have a ledger row")
 * would flag every one of them — making a correct rollback look like data
 * corruption, and pressuring an operator into re-enabling a flag purely to
 * silence an alarm. That is how a rollback path stops being usable in practice.
 */
class BillingV2RollbackVerificationTest extends TestCase
{
    private function v(): Billing_v2_verifier { return new Billing_v2_verifier(); }

    private function demand(string $id = 'D1', array $over = []): array
    {
        return array_merge([
            'demandId' => $id, 'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27',
            'feeHead' => 'Tuition Fee', 'feeHeadRef' => 'FH_DF3C200439E2',
            'category' => 'academic', 'categorySource' => 'registry',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
            'fineAmount' => 0.0, 'paidAmount' => 0.0, 'balance' => 540.0,
        ], $over);
    }

    private function alloc(string $demandId = 'D1', array $over = []): array
    {
        return array_merge([
            'allocationId' => 'SCH_B56BB9A401_' . $demandId . '_E1', 'demandId' => $demandId,
            'entitlementId' => 'E1', 'allocatedAmount' => 60.0, 'basisAmount' => 600.0,
            'status' => 'active', 'schoolId' => 'SCH_B56BB9A401',
        ], $over);
    }

    // ══ 1 · OFF IS THE DEFAULT, AND IS BYTE-IDENTICAL ═══════════════════

    /**
     * ── SUPERSEDED 2026-08-30 BY OWNER DECISION ─────────────────────────────
     * This asserted every V2 flag shipped FALSE, because activation had to be a
     * deliberate act rather than a side effect. The owner has since required
     * that a newly sold school bills correctly with NO flag action at all, so
     * the billing chain now ships ON.
     *
     * The safety property did not disappear, it MOVED: default-on is safe only
     * because capability guards refuse to honour a flag the tenant cannot yet
     * support. This test now pins BOTH halves — the defaults AND the guards.
     * Dropping either half is the regression; the flag value alone never was
     * the invariant.
     *
     * Still ships FALSE: the two genuinely unfinished features. Phase D pricing
     * has no completed implementation behind it and the shadow comparator is a
     * diagnostic — neither may switch on as a side effect of this decision.
     */
    public function test_every_v2_flag_ships_false(): void
    {
        $cfg = file_get_contents(APPPATH . 'config/fees_exemption_v2_flags.php');

        // The billing chain must ship ENABLED.
        foreach (['USE_ENTITLEMENT_BILLING', 'USE_ALLOCATION_LEDGER'] as $flag) {
            $this->assertMatchesRegularExpression(
                '/\$config\[\'' . $flag . '\'\]\s*=\s*true/', $cfg,
                "{$flag} must ship ENABLED so a new school bills without a grant");
        }

        // The guards that make default-on safe. Without them, shipping the flags
        // on silently unbills every school that has not finished setup.
        $lifecycle = (string) file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('FEEHEAD_REGISTRY_HELD', $lifecycle,
            'the registry capability guard is gone — registry mode would be honoured for a '
            . 'school whose registry does not cover its chart, generating ZERO demands');
        $this->assertStringContainsString('ENTITLEMENT_AUTHORITY_HELD', $lifecycle,
            'the legacy-concession guard is gone — a school with live concessions would be '
            . 'switched authorities silently, stopping discounts that reduce real bills');

                // SUPERSEDED 2026-08-30: Phase D ships ON after SVC-1 was reproduced and
        // closed (scripts/service_entitlement_proof.js, 16/16). Its old
        // prerequisite was a LEGACY migration that a new school never has.
        foreach (['ENTITLEMENT_SHADOW_COMPARE'] as $flag) {
            $this->assertMatchesRegularExpression(
                '/\$config\[\'' . $flag . '\'\]\s*=\s*false/', $cfg,
                "{$flag} must ship disabled");
        }
        $this->assertStringContainsString("\$config['USE_FEE_HEAD_REGISTRY'] = true",
            file_get_contents(APPPATH . 'config/fee_head_registry.php'));
    }

    /** With the ledger off the generator assembles nothing, so nothing persists. */
    public function test_ledger_off_means_no_emission_and_no_persistence(): void
    {
        $gen = file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$this->_allocationLedgerEnabled\(\)\s*\)\s*\{[^}]*_buildAllocations/s',
            $gen, 'emission must sit behind the flag');

        $lif = file_get_contents(APPPATH . 'libraries/Fee_lifecycle.php');
        $this->assertStringContainsString('if ($rows === []) return;', $lif,
            'with nothing attached, persistence returns before touching Firestore');
    }

    /** The gate fails CLOSED, so an unreadable flag cannot accidentally enable it. */
    public function test_an_unreadable_flag_stays_off(): void
    {
        $gen = (string) file_get_contents(APPPATH . 'libraries/Fee_generation_service.php');
        $at  = strpos($gen, 'function _allocationLedgerEnabled');
        $this->assertNotFalse($at, 'the ledger flag resolver was renamed or removed');

        // ── WINDOW REPLACED BY BRACE MATCHING (2026-08-29) ──────────────────
        // This read a fixed 1,200-character slice. That made the assertion
        // depend on how much PROSE the method carries: documenting
        // LEDGER-RESOLVE-1 pushed the code past the cutoff and the test failed
        // while the property it guards — fail closed on an unreadable flag —
        // was still perfectly true. A guard a comment can break is not
        // guarding what it claims. Same fix as
        // PaymentProviderProvenanceTruthfulnessTest::fnBody().
        $open  = strpos($gen, '{', $at);
        $depth = 0; $end = null;
        for ($i = $open, $n = strlen($gen); $i < $n; $i++) {
            if ($gen[$i] === '{') $depth++;
            elseif ($gen[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
        }
        $this->assertNotNull($end, 'could not brace-match _allocationLedgerEnabled()');
        $seg = substr($gen, $at, $end - $at + 1);

        $this->assertStringContainsString('catch', $seg,
            'the resolver no longer catches — an unreadable flag would raise instead of '
            . 'quietly staying off, and a fatal in the pricing path is worse than a flag '
            . 'that reads false');
        $this->assertStringContainsString('return false;', $seg,
            'the resolver no longer fails closed. An unreadable flag must never be treated '
            . 'as ON: that would emit ledger rows nobody authorised.');
    }

    // ══ 2 · ROLLBACK REQUIRES NO DATA REPAIR ════════════════════════════

    /**
     * THE CENTRAL ROLLBACK PROPERTY. Demands priced while the ledger was ON stay
     * correct after it is revoked: the discount lives on the demand itself, and
     * the ledger only ever EXPLAINED it. Nothing must be rewritten to roll back.
     */
    public function test_demands_priced_while_on_stay_valid_after_rollback(): void
    {
        $demands = ['D1' => $this->demand('D1')];
        $rows    = [$this->alloc('D1')];

        $whileOn = $this->v()->verify($demands, $rows, ['m5' => true, 'ledgerActive' => true]);
        $this->assertTrue($whileOn['ok']);

        // Rolled back: the rows survive, untouched, and remain coherent.
        $afterOff = $this->v()->verify($demands, $rows, ['m5' => true, 'ledgerActive' => false]);
        $this->assertTrue($afterOff['ok'],
            'revoking the flag must not require touching a single demand');
    }

    /**
     * THE SUBTLE ONE. A demand priced AFTER the rollback has a discount and no
     * ledger row. That is correct, and must not be reported as corruption.
     */
    public function test_a_post_rollback_demand_is_not_reported_as_corruption(): void
    {
        $demands = ['D1' => $this->demand('D1'), 'D2' => $this->demand('D2')];
        $rows    = [$this->alloc('D1')];          // D2 priced after the rollback

        $off = $this->v()->verify($demands, $rows, ['m5' => true, 'ledgerActive' => false]);
        $this->assertTrue($off['ok'],
            'a correct rollback must not look like data corruption — otherwise the '
            . 'only way to silence the alarm is to re-enable the flag');

        // While ACTIVE the same shape IS a violation — the DEC-013 detection
        // must not be lost in exchange for rollback safety.
        $on = $this->v()->verify($demands, $rows, ['m5' => true, 'ledgerActive' => true]);
        $this->assertFalse($on['ok']);
        $this->assertContains('discount_without_allocation', array_column($on['violations'], 'code'));
    }

    /** With no explicit flag state, an empty ledger infers "not active". */
    public function test_the_default_inference_is_rollback_safe(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand()], [], ['m5' => true]);
        $this->assertTrue($r['ok'], 'no allocations at all ⇒ the ledger is not live');
    }

    // ══ 3 · DATA WRITTEN WHILE ON REMAINS INERT AND REVERSIBLE ══════════

    /** Reversal unwinds a grant without deleting its history. */
    public function test_reversal_unwinds_without_deleting(): void
    {
        $svc = (new Entitlement_allocation_service())
            ->init(null, null, 'SCH_B56BB9A401', '2026-27', 'SSA0011');
        $patch = $svc->buildReversal($this->alloc(), 'rollback');

        $this->assertSame('reversed', $patch['status']);
        $this->assertArrayHasKey('reversedAt', $patch);
        $this->assertArrayHasKey('reversalReason', $patch);
        $this->assertArrayNotHasKey('delete', $patch);

        // A reversed row stops explaining the discount, so the demand must be
        // restated to gross for the pair to remain coherent.
        $reversed = $this->alloc('D1', ['status' => 'reversed']);
        $r = $this->v()->verify(
            ['D1' => $this->demand('D1', ['discountAmount' => 0.0, 'netAmount' => 600.0,
                                          'balance' => 600.0])],
            [$reversed], ['m5' => true, 'ledgerActive' => true]);
        $this->assertTrue($r['ok']);
    }

    /** Rows left behind by a rollback are never orphaned — their demands remain. */
    public function test_left_behind_rows_are_not_orphans(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand('D1')], [$this->alloc('D1')],
            ['ledgerActive' => false]);
        $this->assertNotContains('orphan_allocation', array_column($r['violations'], 'code'));
    }

    // ══ 4 · THE M5 / BILLING ROLLBACK PAIR ══════════════════════════════

    /**
     * DEC-006 dependency: billing may not be granted without M5. That ordering
     * also defines the SAFE ROLLBACK ORDER — billing off first, then M5 —
     * because M5-off with billing-on is the combination DEC-006 exists to prevent.
     */
    public function test_the_dec006_dependency_still_defines_the_rollback_order(): void
    {
        $grant = file_get_contents(APPPATH . 'libraries/Rollout_grant_service.php');
        $this->assertStringContainsString('USE_FEE_HEAD_REGISTRY', $grant);
        $this->assertStringContainsString('USE_ENTITLEMENT_BILLING', $grant);
        $this->assertStringContainsString('$current[$flag] = $entry;', $grant,
            'revoking one flag must never delete its siblings — the rollout incident');
    }

    /** Revoking one grant must leave the others intact. */
    public function test_revoking_one_flag_preserves_the_others(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Rollout_grant_service.php');
        $this->assertStringNotContainsString('[self::FIELD => [$flag => $entry]]', $src,
            'the single-key payload deleted a live grant once — it must not return');
    }

    // ══ 5 · WHAT ROLLBACK CANNOT UNDO — stated, not glossed ═════════════

    /**
     * Honest limit. Revoking the ledger stops NEW rows; it does not un-price
     * demands already issued. Those keep their discount — which is correct, and
     * is why no data repair is needed — but it does mean a rollback is not a
     * time machine. The verifier proves the resulting state is coherent, not
     * that it is identical to never having enabled the flag.
     */
    public function test_rollback_does_not_reprice_already_issued_demands(): void
    {
        $demands = ['D1' => $this->demand('D1')];
        $after = $this->v()->verify($demands, [$this->alloc('D1')], ['ledgerActive' => false]);
        $this->assertTrue($after['ok']);
        $this->assertSame(60.0, $demands['D1']['discountAmount'],
            'the issued discount survives the rollback — by design, not by accident');
    }
}
