<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!function_exists('log_message')) { function log_message($l, $m) {} }

if (!class_exists('Fee_recalculation_planner')) {
    require_once APPPATH . 'libraries/Fee_recalculation_planner.php';
}

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P5-E RECALCULATION — CAN IT DESTROY SETTLED MONEY?
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fees::recalculate_student_fees()` used to DELETE a student's unpaid demands
 * and regenerate them through the legacy generator. DEC-013 had to refuse the
 * whole operation for any student holding an entitlement, because regeneration
 * priced from `_getStudentDiscounts()` — which cannot see `studentEntitlements`
 * — so an approved concession came back at gross. That refusal was containment,
 * not a fix: an entitlement granted AFTER generation could never reach the
 * money at all.
 *
 * P5-E replaced it: price the full chart through the ONE routed authority, diff
 * against what exists, and apply only in-place corrections. `Fee_recalculation_planner`
 * is that diff, and it is the boundary that decides whether settled money is
 * touched.
 *
 * ── THE ASSUMPTION UNDER TEST ─────────────────────────────────────────────
 * `_patch()` computes the corrected balance as:
 *
 *     'balance' => round($net + $fine, 2),   // "paid is zero here"
 *
 * There is no `paidAmount` term. If a demand carrying a payment ever reached
 * `_patch()`, the new balance would be overstated by exactly what the family
 * had already paid — they would be re-billed for money already received, and
 * the demand would still balance internally.
 *
 * That assumption is load-bearing and lives in a COMMENT. These tests drive the
 * real planner and prove it structurally instead.
 *
 * The class reads nothing and writes nothing, so this is a genuine behavioural
 * test rather than a source scan.
 */
class RecalculationMoneySafetyTest extends TestCase
{
    private Fee_recalculation_planner $planner;

    protected function setUp(): void
    {
        $this->planner = new Fee_recalculation_planner();
    }

    private function demand(array $over = []): array
    {
        return array_merge([
            'feeHead'        => 'Tuition Fee',
            'periodKey'      => '2026-04',
            'grossAmount'    => 5000.0,
            'discountAmount' => 0.0,
            'netAmount'      => 5000.0,
            'paidAmount'     => 0.0,
            'balance'        => 5000.0,
            'status'         => 'unpaid',
        ], $over);
    }

    /** What the canonical pricing authority says this charge SHOULD be. */
    private function spec(array $over = []): array
    {
        return ['data' => array_merge([
            'feeHead'        => 'Tuition Fee',
            'periodKey'      => '2026-04',
            'grossAmount'    => 5000.0,
            'discountAmount' => 500.0,
            'netAmount'      => 4500.0,
        ], $over)];
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    /**
     * An unpaid demand priced wrongly IS corrected — otherwise every assertion
     * below could pass simply because the planner does nothing.
     */
    public function test_control_an_unpaid_wrong_demand_is_corrected_in_place(): void
    {
        $plan = $this->planner->plan(['DEM_A' => $this->demand()], [$this->spec()]);

        $this->assertArrayHasKey('DEM_A', $plan[Fee_recalculation_planner::UPDATE],
            'the planner corrects nothing, so the money-safety assertions below are vacuous');

        $patch = $plan[Fee_recalculation_planner::UPDATE]['DEM_A']['patch'];
        $this->assertSame(4500.0, $patch['netAmount']);
        $this->assertSame(500.0, $patch['discountAmount']);
        $this->assertSame(4500.0, $patch['balance']);
    }

    // ══ SETTLED MONEY IS NEVER REWRITTEN ════════════════════════════════════

    /**
     * A demand carrying ANY payment must never reach `_patch()`. This is the
     * invariant the `paid is zero here` comment depends on.
     */
    public function test_a_demand_with_a_payment_never_reaches_the_patch_path(): void
    {
        foreach ([0.01, 100.0, 4500.0, 5000.0] as $paid) {
            $plan = $this->planner->plan(
                ['DEM_A' => $this->demand(['paidAmount' => $paid, 'status' => 'partial'])],
                [$this->spec()]
            );

            $this->assertArrayNotHasKey('DEM_A', $plan[Fee_recalculation_planner::UPDATE],
                "a demand carrying a payment of {$paid} was scheduled for an in-place rewrite. "
                . '_patch() computes balance as net + fine with NO paidAmount term, so the '
                . 'family would be re-billed exactly what they had already paid — and the '
                . 'demand would still balance internally.');

            $this->assertArrayHasKey('DEM_A', $plan[Fee_recalculation_planner::ADJUST],
                'a paid demand whose charge moved must be escalated for a human, not silently '
                . 'left alone — a receipt already refers to the old amounts');
        }
    }

    /** A paid demand that is already correct is protected, not adjusted. */
    public function test_a_paid_and_correct_demand_is_protected(): void
    {
        $plan = $this->planner->plan(
            ['DEM_A' => $this->demand(['paidAmount' => 4500.0, 'discountAmount' => 500.0,
                                       'netAmount' => 4500.0, 'status' => 'paid'])],
            [$this->spec()]
        );

        $this->assertArrayHasKey('DEM_A', $plan[Fee_recalculation_planner::PROTECT]);
        $this->assertArrayNotHasKey('DEM_A', $plan[Fee_recalculation_planner::UPDATE]);
    }

    /** The snake alias must be honoured, or a legacy demand looks unpaid. */
    public function test_a_snake_case_paid_amount_still_counts_as_settled(): void
    {
        $d = $this->demand();
        unset($d['paidAmount']);
        $d['paid_amount'] = 4500.0;

        $plan = $this->planner->plan(['DEM_A' => $d], [$this->spec()]);

        $this->assertArrayNotHasKey('DEM_A', $plan[Fee_recalculation_planner::UPDATE],
            'a demand recording its payment under the snake alias was treated as UNPAID and '
            . 'scheduled for rewrite — settled money rewritten because of a field name');
    }

    // ══ THE PATCH ITSELF ════════════════════════════════════════════════════

    /**
     * The patch must not carry payment state. Even reaching the right demands,
     * emitting `paidAmount` or `status` would let a merge write reset them —
     * the BUG-075 shape.
     */
    public function test_the_patch_never_carries_payment_state(): void
    {
        $plan  = $this->planner->plan(['DEM_A' => $this->demand()], [$this->spec()]);
        $patch = $plan[Fee_recalculation_planner::UPDATE]['DEM_A']['patch'];

        foreach (['paidAmount', 'paid_amount', 'status', 'lastReceipt'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $patch,
                "the recalculation patch emits '{$forbidden}'. writeDemand merges, so this "
                . 'would reset payment state on a demand that already carries a payment — the '
                . 'exact defect BUG-075 closed.');
        }

        $this->assertSame(
            ['grossAmount', 'discountAmount', 'netAmount', 'balance'],
            array_keys($patch),
            'the recalculation patch changed shape; re-derive what it can now overwrite');
    }

    /**
     * An existing late fee must survive the correction. Proration once dropped
     * it silently (Phase 15.5 F-1) and the fine became uncollectable while
     * still recorded on the document.
     */
    public function test_an_existing_fine_survives_the_correction(): void
    {
        $plan = $this->planner->plan(
            ['DEM_A' => $this->demand(['fineAmount' => 200.0])],
            [$this->spec()]
        );
        $patch = $plan[Fee_recalculation_planner::UPDATE]['DEM_A']['patch'];

        $this->assertSame(4700.0, $patch['balance'],
            'the corrected balance dropped the Rs.200 late fee. The fine stays on the document '
            . 'while ceasing to be owed — recorded but uncollectable.');
    }

    /** Both field shapes of the fine must be read. */
    public function test_a_snake_case_fine_also_survives(): void
    {
        $plan = $this->planner->plan(
            ['DEM_A' => $this->demand(['fine_amount' => 200.0])],
            [$this->spec()]
        );

        $this->assertSame(4700.0, $plan[Fee_recalculation_planner::UPDATE]['DEM_A']['patch']['balance']);
    }

    // ══ NO DELETE VERB ══════════════════════════════════════════════════════

    /**
     * A charge that has left the chart is REPORTED, never removed. Deleting a
     * demand orphans any receipt and journal that refer to it, which is why
     * DEC-013 existed at all.
     */
    public function test_a_charge_no_longer_in_the_chart_is_reported_not_deleted(): void
    {
        $plan = $this->planner->plan(
            ['DEM_OLD' => $this->demand(['feeHead' => 'Retired Fee'])],
            [$this->spec()]
        );

        $this->assertArrayHasKey('DEM_OLD', $plan[Fee_recalculation_planner::OBSOLETE]);
        $this->assertSame(0, $plan['summary']['deletes'],
            'the planner reports a delete count above zero. It must have no delete verb at '
            . 'all — removing a charge orphans its receipt and its journal.');
    }

    /** An obsolete demand that was PAID must be flagged as such. */
    public function test_an_obsolete_but_paid_charge_is_distinguished(): void
    {
        $plan = $this->planner->plan(
            ['DEM_OLD' => $this->demand(['feeHead' => 'Retired Fee', 'paidAmount' => 5000.0])],
            [$this->spec()]
        );

        $this->assertSame('obsolete_but_paid',
            $plan[Fee_recalculation_planner::OBSOLETE]['DEM_OLD']['reason'],
            'a paid charge that left the chart is indistinguishable from an unpaid one. The '
            . 'first has a receipt behind it and cannot simply be written off.');
    }

    // ══ DUPLICATE IDENTITY ══════════════════════════════════════════════════

    /**
     * Two demands for one charge key is the DEC-007 shape. Picking one to
     * overwrite is a silent decision about real money, so both are escalated.
     */
    public function test_two_demands_for_one_charge_are_escalated_not_merged(): void
    {
        $plan = $this->planner->plan(
            ['DEM_A' => $this->demand(), 'DEM_B' => $this->demand()],
            [$this->spec()]
        );

        $this->assertArrayHasKey('DEM_A', $plan[Fee_recalculation_planner::ADJUST]);
        $this->assertArrayHasKey('DEM_B', $plan[Fee_recalculation_planner::ADJUST]);
        $this->assertSame('identity_conflict', $plan[Fee_recalculation_planner::ADJUST]['DEM_A']['reason']);
        $this->assertEmpty($plan[Fee_recalculation_planner::UPDATE],
            'one of two demands sharing a charge key was chosen and rewritten — a silent '
            . 'decision about which of two real charges survives');
    }

    // ══ A MISSING CHARGE ════════════════════════════════════════════════════

    /**
     * An entitlement granted after generation, or a head added to the chart,
     * produces a CREATE. That is the case the DEC-013 refusal could never
     * serve.
     */
    public function test_a_charge_that_should_exist_and_does_not_is_created(): void
    {
        $plan = $this->planner->plan([], [$this->spec()]);

        $this->assertCount(1, $plan[Fee_recalculation_planner::CREATE]);
        $this->assertSame(4500.0, $plan[Fee_recalculation_planner::CREATE][0]['netAmount']);
    }

    /** Charge identity is head + period, matched case-insensitively on the head. */
    public function test_the_charge_key_is_case_insensitive_on_the_head(): void
    {
        $plan = $this->planner->plan(
            ['DEM_A' => $this->demand(['feeHead' => 'TUITION FEE'])],
            [$this->spec(['feeHead' => 'Tuition Fee'])]
        );

        $this->assertArrayNotHasKey(0, $plan[Fee_recalculation_planner::CREATE],
            'a head differing only in capitalisation was treated as a DIFFERENT charge, so the '
            . 'student would be billed twice for one fee');
        $this->assertArrayHasKey('DEM_A', $plan[Fee_recalculation_planner::UPDATE]);
    }
}
