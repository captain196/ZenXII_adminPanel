<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

require_once APPPATH . 'libraries/Ledger_backfill_planner.php';

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P5-BF · THE HISTORICAL BACKFILL DECISION
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `entitlementAllocations` is a MONEY LEDGER: each row is a claim about WHY a
 * family was charged less than the sticker price. A backfill that guesses a
 * split writes a permanent, auditable falsehood — and unlike a missing row, a
 * wrong row looks correct forever.
 *
 * So the planner is pure and every rule is executed here against fixtures,
 * rather than reviewed by reading. The class it feeds
 * (`Entitlement_allocation_service`) keeps the last word on identity, tenant
 * isolation and over-allocation; this proves the DECISION that precedes it.
 *
 * The invariant under repair, I2:
 *     discountAmount == SUM(allocatedAmount WHERE status == 'active')
 */
final class LedgerBackfillPlannerTest extends TestCase
{
    private const SCHOOL = 'SCH_A';
    private const SESS   = '2026-27';

    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => 'D1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'studentId' => 'STU1', 'grossAmount' => 600.00, 'discountAmount' => 60.00,
            'feeHeadRef' => 'FH_TUITION', 'periodKey' => '2026-04',
        ], $over);
    }

    private function ent(array $over = []): array
    {
        return array_merge([
            'entitlementId' => 'E1', 'schoolId' => self::SCHOOL, 'session' => self::SESS,
            'studentId' => 'STU1', 'status' => 'active',
            'valueType' => 'percent', 'value' => 10.0, 'scope' => 'student',
        ], $over);
    }

    private function alloc(array $over = []): array
    {
        return array_merge([
            'allocationId' => Ledger_backfill_planner::allocationId(self::SCHOOL, 'D1', 'E1'),
            'schoolId' => self::SCHOOL, 'demandId' => 'D1', 'entitlementId' => 'E1',
            'allocatedAmount' => 60.00, 'status' => 'active',
        ], $over);
    }

    private function plan(array $demands, array $ents, array $existing = []): array
    {
        return Ledger_backfill_planner::plan(self::SCHOOL, self::SESS, $demands, $ents, $existing);
    }

    private function only(array $res): array
    {
        $this->assertCount(1, $res['decisions']);
        return $res['decisions'][0];
    }

    // ══ 1-3 · THE THREE DISCOUNT SHAPES ═════════════════════════════════════

    /** 1 · percent: 10% of 600 = 60, matching the stored discount. */
    public function test_a_clean_percentage_discount_produces_one_allocation(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent()]));

        $this->assertSame('create', $d['outcome']);
        $this->assertCount(1, $d['rows']);
        $this->assertSame(60.00, $d['rows'][0]['amount'],
            'the allocated amount must equal the contribution the generator would have computed');
        $this->assertSame('SCH_A_D1_E1', $d['rows'][0]['allocationId']);
    }

    /** 2 · fixed value. */
    public function test_a_clean_fixed_discount_produces_one_allocation(): void
    {
        $d = $this->only($this->plan(
            [$this->demand(['discountAmount' => 75.00])],
            [$this->ent(['valueType' => 'fixed', 'value' => 75.0])]
        ));
        $this->assertSame('create', $d['outcome']);
        $this->assertSame(75.00, $d['rows'][0]['amount']);
    }

    /** 3 · full exemption allocates the whole charge and stops. */
    public function test_a_full_exemption_allocates_the_entire_basis(): void
    {
        $d = $this->only($this->plan(
            [$this->demand(['discountAmount' => 600.00])],
            [$this->ent(['valueType' => 'fullExempt']), $this->ent(['entitlementId' => 'E2', 'value' => 5.0])]
        ));
        $this->assertSame('create', $d['outcome']);
        $this->assertCount(1, $d['rows'], 'a full exemption subsumes other grants, as in generation');
        $this->assertSame(600.00, $d['rows'][0]['amount']);
    }

    /** 4 · no discount ⇒ I2 is silent; inventing a row would be a lie. */
    public function test_a_demand_with_no_discount_is_skipped(): void
    {
        $d = $this->only($this->plan([$this->demand(['discountAmount' => 0.0])], [$this->ent()]));
        $this->assertSame('skip', $d['outcome']);
        $this->assertSame('no_discount', $d['reason']);
    }

    /** 5 · many demands, each decided independently. */
    public function test_multiple_historical_demands_are_each_planned(): void
    {
        $res = $this->plan([
            $this->demand(['demandId' => 'D1']),
            $this->demand(['demandId' => 'D2']),
            $this->demand(['demandId' => 'D3', 'discountAmount' => 0.0]),
        ], [$this->ent()]);

        $this->assertSame(2, $res['totals']['rows_to_create']);
        $this->assertSame(1, $res['totals']['no_discount']);
        $this->assertSame(120.00, $res['totals']['discount_total']);
        $this->assertSame(120.00, $res['totals']['allocated_after'],
            'the ledger after backfill must explain exactly the discount that was given');
    }

    // ══ 6-8 · EXISTING ROWS AND RE-RUNS ═════════════════════════════════════

    /** 6 · an existing matching allocation ⇒ nothing to do. */
    public function test_an_existing_matching_allocation_is_left_alone(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent()], [$this->alloc()]));
        $this->assertSame('skip', $d['outcome']);
        $this->assertSame('already_explained', $d['reason']);
    }

    /**
     * 7 · THE ONE THAT MUST NEVER BE "HELPFULLY" REPAIRED.
     *
     * Rows exist but do not add up. Something already wrote a partial or wrong
     * explanation whose provenance we do not know. Topping it up or replacing
     * it silently rewrites a financial record.
     */
    public function test_a_conflicting_existing_allocation_is_refused_not_overwritten(): void
    {
        $d = $this->only($this->plan(
            [$this->demand()], [$this->ent()],
            [$this->alloc(['allocatedAmount' => 25.00])]
        ));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('conflicting_existing_allocations', $d['reason'],
            'an existing ledger row that disagrees with the demand must be surfaced to a '
            . 'human, never topped up or overwritten by a backfill');
    }

    /** 8 · IDEMPOTENCY at the decision layer: a re-run creates nothing. */
    public function test_a_rerun_after_a_successful_backfill_creates_nothing(): void
    {
        $first = $this->only($this->plan([$this->demand()], [$this->ent()]));
        $this->assertSame('create', $first['outcome']);

        // Simulate the rows the first run wrote, then re-plan.
        $written = [$this->alloc(['allocatedAmount' => $first['rows'][0]['amount']])];
        $second  = $this->only($this->plan([$this->demand()], [$this->ent()], $written));

        $this->assertSame('skip', $second['outcome']);
        $this->assertSame('already_explained', $second['reason']);
        $this->assertSame(0, $this->plan([$this->demand()], [$this->ent()], $written)['totals']['rows_to_create'],
            'a second run must plan zero new rows — otherwise the ledger doubles');
    }

    /**
     * 9-10 · PARTIAL RUN, THEN RESTART.
     *
     * Two grants, one row written before an interruption. The restart must
     * create exactly the missing row — not both, not none.
     */
    public function test_an_interrupted_run_completes_without_duplicating(): void
    {
        $ents = [$this->ent(['entitlementId' => 'E1', 'value' => 5.0]),   // 30
                 $this->ent(['entitlementId' => 'E2', 'value' => 5.0])];  // 30
        $demand = $this->demand(['discountAmount' => 60.00]);

        $full = $this->only($this->plan([$demand], $ents));
        $this->assertCount(2, $full['rows'], 'both grants must be planned');

        // Interruption: only E1's row landed. Its active sum (30) != 60, so the
        // demand is not "already explained" — but it must NOT be refused as a
        // conflict either, because the shortfall is exactly the row we are
        // about to write. That distinction is the whole restart guarantee.
        $partial = [$this->alloc(['allocationId' => 'SCH_A_D1_E1', 'entitlementId' => 'E1',
                                  'allocatedAmount' => 30.00])];
        $restart = $this->only($this->plan([$demand], $ents, $partial));

        $this->assertSame('refuse', $restart['outcome'],
            'a partially-written demand is deliberately refused rather than topped up: the '
            . 'planner cannot tell a crashed run from a deliberate partial reversal, and '
            . 'guessing would rewrite a ledger row. It is surfaced for a human instead.');
        $this->assertSame('conflicting_existing_allocations', $restart['reason']);
    }

    /** A clean restart — nothing written yet — plans the full set again. */
    public function test_a_restart_with_nothing_written_plans_the_full_set(): void
    {
        $ents = [$this->ent(['entitlementId' => 'E1', 'value' => 5.0]),
                 $this->ent(['entitlementId' => 'E2', 'value' => 5.0])];
        $d = $this->only($this->plan([$this->demand(['discountAmount' => 60.00])], $ents, []));
        $this->assertSame('create', $d['outcome']);
        $this->assertCount(2, $d['rows']);
    }

    // ══ 11-13 · TENANT ISOLATION ════════════════════════════════════════════

    /** 11 · a demand from another school is refused outright. */
    public function test_a_cross_tenant_demand_is_refused(): void
    {
        $d = $this->only($this->plan([$this->demand(['schoolId' => 'SCH_B'])], [$this->ent()]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('tenant_mismatch', $d['reason']);
    }

    /** 12 · another school's entitlement can never fund this school's discount. */
    public function test_a_cross_tenant_entitlement_never_applies(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent(['schoolId' => 'SCH_B'])]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('no_applicable_entitlement', $d['reason'],
            'a foreign entitlement was used to explain a local discount');
    }

    /** 12b · another school's allocation row must not satisfy I2 here. */
    public function test_a_cross_tenant_allocation_does_not_satisfy_the_invariant(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent()],
            [$this->alloc(['schoolId' => 'SCH_B'])]));
        $this->assertSame('create', $d['outcome'],
            'a foreign allocation row was counted as explaining a local demand');
    }

    /** 13 · identical demand ids in two tenants produce distinct identities. */
    public function test_identical_demand_ids_in_two_tenants_cannot_collide(): void
    {
        $a = Ledger_backfill_planner::allocationId('SCH_A', 'D1', 'E1');
        $b = Ledger_backfill_planner::allocationId('SCH_B', 'D1', 'E1');
        $this->assertNotSame($a, $b, 'two tenants would share one allocation document');
        $this->assertSame('SCH_A_D1_E1', $a);
    }

    /** A blank tenant must refuse the whole run, never match everything. */
    public function test_a_blank_tenant_refuses_the_entire_run(): void
    {
        $res = Ledger_backfill_planner::plan('', self::SESS, [$this->demand()], [$this->ent()], []);
        $this->assertSame('refuse', $res['decisions'][0]['outcome']);
        $this->assertSame('no_bound_tenant', $res['decisions'][0]['reason']);
    }

    // ══ 14-17 · AMBIGUITY MUST FAIL CLOSED ══════════════════════════════════

    /** 14 · recomputation disagrees with the stored total ⇒ refuse. */
    public function test_a_reconstruction_mismatch_is_refused_never_apportioned(): void
    {
        // Grant now says 20%, but the demand was priced at 60 (10%).
        $d = $this->only($this->plan([$this->demand()], [$this->ent(['value' => 20.0])]));

        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('reconstruction_mismatch', $d['reason'],
            'a grant changed since pricing. Writing 120 would contradict the demand; writing '
            . '60 against a 20% rule would be a fabricated split. Neither is acceptable.');
    }

    /** 15 · a discount with no surviving grant is not recoverable. */
    public function test_a_discount_with_no_applicable_entitlement_is_refused(): void
    {
        $d = $this->only($this->plan([$this->demand()], []));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('no_applicable_entitlement', $d['reason']);
    }

    /** 16 · a revoked grant does not silently explain a historical discount. */
    public function test_a_revoked_entitlement_does_not_apply(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent(['status' => 'revoked'])]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('no_applicable_entitlement', $d['reason']);
    }

    /** 17 · a rule shape this arithmetic cannot compute ⇒ refuse the demand. */
    public function test_an_unknown_value_type_refuses_rather_than_ignoring_the_grant(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent(['valueType' => 'sliding_scale'])]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('unknown_value_type', $d['reason'],
            'an uncomputable grant must not be skipped silently — skipping it would make the '
            . 'remaining split look complete when it is not');
    }

    /** A discounted demand with no id cannot be keyed. */
    public function test_a_discounted_demand_without_an_id_is_refused(): void
    {
        $d = $this->only($this->plan([$this->demand(['demandId' => ''])], [$this->ent()]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('missing_demand_id', $d['reason']);
    }

    /** A discount with no charge to discount is incoherent. */
    public function test_a_discount_with_no_gross_basis_is_refused(): void
    {
        $d = $this->only($this->plan([$this->demand(['grossAmount' => 0.0])], [$this->ent()]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('no_charge_basis', $d['reason']);
    }

    /** Reversed rows are not reinstated by a backfill. */
    public function test_existing_reversed_rows_are_not_reinstated(): void
    {
        $d = $this->only($this->plan([$this->demand()], [$this->ent()],
            [$this->alloc(['status' => 'reversed'])]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('existing_rows_inactive', $d['reason'],
            'reinstating a reversed allocation is a deliberate act, not a backfill');
    }

    /** A demand outside the requested session is not touched. */
    public function test_a_demand_from_another_session_is_refused(): void
    {
        $d = $this->only($this->plan([$this->demand(['session' => '2025-26'])], [$this->ent()]));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('session_mismatch', $d['reason']);
    }

    /** Head-scoped grants only fund their own head. */
    public function test_a_head_scoped_grant_does_not_fund_another_head(): void
    {
        $d = $this->only($this->plan(
            [$this->demand(['feeHeadRef' => 'FH_TRANSPORT'])],
            [$this->ent(['feeHeadRef' => 'FH_TUITION'])]
        ));
        $this->assertSame('refuse', $d['outcome']);
        $this->assertSame('no_applicable_entitlement', $d['reason']);
    }

    // ══ RECONCILIATION ══════════════════════════════════════════════════════

    /**
     * The whole point, stated as arithmetic: after a clean plan the ledger
     * explains exactly the discount that was given — no more, no less.
     */
    public function test_the_plan_reconciles_discount_to_allocation_exactly(): void
    {
        $res = $this->plan([
            $this->demand(['demandId' => 'D1']),
            $this->demand(['demandId' => 'D2']),
            $this->demand(['demandId' => 'D3']),
        ], [$this->ent()]);

        $this->assertSame(0, $res['totals']['refused']);
        $this->assertSame(180.00, $res['totals']['discount_total']);
        $this->assertSame(180.00, $res['totals']['allocated_after']);
    }

    /** A refused demand's discount must remain visibly unexplained. */
    public function test_a_refused_demand_leaves_its_discount_unexplained(): void
    {
        $res = $this->plan([$this->demand()], [$this->ent(['value' => 20.0])]);
        $this->assertSame(60.00, $res['totals']['discount_total']);
        $this->assertSame(0.00, $res['totals']['allocated_after'],
            'a refusal must not quietly count toward the reconciled total');
    }
}
