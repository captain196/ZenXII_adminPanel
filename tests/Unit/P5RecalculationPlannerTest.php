<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_recalculation_planner.php';
require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';

/**
 * P5 — RC-1 replacement: recompute IN PLACE, never delete.
 *
 * `Fees::recalc_unpaid_discounts()` recalculates by DELETING a student's
 * untouched demands and regenerating them. That design caused two defects:
 *   • DEC-013 — regeneration runs through an entitlement-BLIND path, so an
 *     approved concession is silently rebuilt at gross. (Contained today by a
 *     fail-closed guard, which is debt this planner exists to retire.)
 *   • Demand identity is destroyed — `newDemandId()` mints fresh ids, so every
 *     external reference breaks and nothing reconciles across a recalculation.
 *
 * The planner compares what EXISTS against what SHOULD exist and emits a PLAN:
 * same demand, same id, amounts corrected in place. **It has no delete verb at
 * all** — an obsolete demand is REPORTED, because dropping a charge is a
 * financial act that must be a human decision, not a side effect of pressing
 * "recalculate".
 *
 * NOT WIRED. RC-1 keeps its current behaviour behind the DEC-013 guard until P5
 * executes this plan.
 */
class P5RecalculationPlannerTest extends TestCase
{
    private function planner(): Fee_recalculation_planner
    {
        return new Fee_recalculation_planner();
    }

    /** An existing demand doc, in the live STU0185 shape. */
    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => 'D1', 'studentId' => 'STU0185',
            'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27',
            'feeHead' => 'Tuition Fee', 'periodKey' => '2026-06',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
            'balance' => 540.0, 'paidAmount' => 0.0, 'fineAmount' => 0.0,
            'status' => 'unpaid',
        ], $over);
    }

    /** An expected spec entry, as the generator emits it. */
    private function spec(array $over = []): array
    {
        return ['data' => array_merge([
            'feeHead' => 'Tuition Fee', 'periodKey' => '2026-06',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
        ], $over)];
    }

    // ══ THE CENTRAL GUARANTEE ═══════════════════════════════════════════

    /**
     * THE WHOLE POINT: no plan, under any input, may contain a delete. The
     * summary carries a hard `deletes => 0` so this cannot regress silently.
     */
    public function test_no_plan_can_ever_contain_a_delete(): void
    {
        $plans = [
            $this->planner()->plan([], []),
            $this->planner()->plan(['D1' => $this->demand()], []),
            $this->planner()->plan([], [$this->spec()]),
            $this->planner()->plan(['D1' => $this->demand()], [$this->spec(['netAmount' => 999.0])]),
        ];
        foreach ($plans as $i => $p) {
            $this->assertSame(0, $p['summary']['deletes'], "plan {$i} must contain no deletes");
            $this->assertArrayNotHasKey('delete', $p, "plan {$i} must have no delete bucket");
        }
        // Assert on EXECUTABLE code only. The prose legitimately says "never
        // delete" and "deleting a charge is a financial act" — a test must not
        // forbid the documentation from naming what it forbids.
        $exec = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'libraries/Fee_recalculation_planner.php')) as $t) {
            $exec .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        foreach (['firestoreDelete', 'removeEntity', 'batchRemove', 'unset(', "'delete'", '"delete"'] as $verb) {
            $this->assertStringNotContainsString($verb, $exec,
                "the planner must have no delete verb in executable code: found '{$verb}'");
        }
    }

    /** DEMAND IDENTITY IS PRESERVED — the id is the plan key, never reissued. */
    public function test_the_demand_id_is_preserved_on_correction(): void
    {
        $p = $this->planner()->plan(
            ['04e23493e0f322235383' => $this->demand()],
            [$this->spec(['discountAmount' => 0.0, 'netAmount' => 600.0])]
        );
        $this->assertArrayHasKey('04e23493e0f322235383', $p['update'],
            'the EXISTING demand is corrected — a fresh id would break every reference');
        $this->assertSame([], $p['create'], 'and nothing is recreated alongside it');
    }

    // ══ SETTLED MONEY IS NEVER REWRITTEN ════════════════════════════════

    /** A partly-paid demand whose charge changed needs a human, not a rewrite. */
    public function test_a_partly_paid_demand_is_never_rewritten(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['paidAmount' => 200.0, 'balance' => 340.0, 'status' => 'partial'])],
            [$this->spec(['discountAmount' => 0.0, 'netAmount' => 600.0])]
        );
        $this->assertSame([], $p['update'], 'settled money must not be silently restated');
        $this->assertArrayHasKey('D1', $p['adjust']);
        $this->assertSame('paid_amount_changed', $p['adjust']['D1']['reason']);
        $this->assertSame(200.0, $p['adjust']['D1']['paid']);
    }

    /** A fully-paid demand that is already correct is simply left alone. */
    public function test_a_paid_and_correct_demand_is_protected(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['paidAmount' => 540.0, 'balance' => 0.0, 'status' => 'paid'])],
            [$this->spec()]
        );
        $this->assertArrayHasKey('D1', $p['protect']);
        $this->assertSame('paid_and_correct', $p['protect']['D1']['reason']);
        $this->assertSame([], $p['update']);
    }

    /** Snake-case payment state is honoured too — legacy docs must not slip through. */
    public function test_legacy_snake_case_payment_state_still_protects(): void
    {
        $d = $this->demand();
        unset($d['paidAmount']);
        $d['paid_amount'] = 100.0;
        $p = $this->planner()->plan(['D1' => $d], [$this->spec(['netAmount' => 600.0, 'discountAmount' => 0.0])]);
        $this->assertSame([], $p['update'], 'a legacy-shaped payment must still block a rewrite');
        $this->assertArrayHasKey('D1', $p['adjust']);
    }

    // ══ THE ORDINARY CASES ══════════════════════════════════════════════

    /** Unpaid and already correct → nothing happens. */
    public function test_an_unpaid_correct_demand_is_unchanged(): void
    {
        $p = $this->planner()->plan(['D1' => $this->demand()], [$this->spec()]);
        $this->assertArrayHasKey('D1', $p['unchanged']);
        $this->assertSame([], $p['update']);
        $this->assertSame([], $p['adjust']);
    }

    /** Unpaid and wrong → corrected in place, with the diff recorded. */
    public function test_an_unpaid_incorrect_demand_is_corrected_in_place(): void
    {
        // The DEC-013 shape: the concession was lost, so net sits at gross.
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['discountAmount' => 0.0, 'netAmount' => 600.0, 'balance' => 600.0])],
            [$this->spec()]
        );
        $this->assertArrayHasKey('D1', $p['update']);
        $u = $p['update']['D1'];
        $this->assertSame(['from' => 0.0,   'to' => 60.0],  $u['diff']['discountAmount']);
        $this->assertSame(['from' => 600.0, 'to' => 540.0], $u['diff']['netAmount']);
        $this->assertArrayNotHasKey('grossAmount', $u['diff'], 'gross was already right');
        $this->assertSame(540.0, $u['patch']['netAmount']);
        $this->assertSame(540.0, $u['patch']['balance']);
    }

    /** A missing charge is created — recalculation still fills real gaps. */
    public function test_a_missing_demand_is_created(): void
    {
        $p = $this->planner()->plan([], [$this->spec()]);
        $this->assertCount(1, $p['create']);
        $this->assertSame('Tuition Fee', $p['create'][0]['feeHead']);
        $this->assertSame([], $p['update']);
    }

    // ══ THE PATCH ═══════════════════════════════════════════════════════

    /** The patch touches the charge and the balance — never paidAmount. */
    public function test_the_patch_never_contains_payment_state(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['netAmount' => 600.0, 'discountAmount' => 0.0])],
            [$this->spec()]
        );
        $patch = $p['update']['D1']['patch'];
        $this->assertSame(['grossAmount', 'discountAmount', 'netAmount', 'balance'],
            array_keys($patch), 'exactly the charge plus the derived balance');
        $this->assertArrayNotHasKey('paidAmount', $patch);
        $this->assertArrayNotHasKey('status', $patch);
        $this->assertArrayNotHasKey('demandId', $patch, 'the id must never be reissued');
    }

    /**
     * A fine is PRESERVED in the recomputed balance. Proration once dropped
     * fines silently (Phase 15.5 F-1); recalculation must not repeat it.
     */
    public function test_a_fine_survives_the_recomputed_balance(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['netAmount' => 600.0, 'discountAmount' => 0.0,
                                    'fineAmount' => 50.0, 'balance' => 650.0])],
            [$this->spec()]
        );
        $this->assertSame(590.0, $p['update']['D1']['patch']['balance'],
            'balance = net 540 + fine 50 - paid 0; the penalty must not be erased');
    }

    /** Every patch satisfies the P5 money invariant. */
    public function test_every_patch_is_money_coherent(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['netAmount' => 600.0, 'discountAmount' => 0.0])],
            [$this->spec()]
        );
        $this->assertNull(Fee_firestore_txn::moneyIncoherence($p['update']['D1']['patch']),
            'gross - discount == net must hold on anything the planner would write');
    }

    // ══ IDENTITY CONFLICTS AND OBSOLETE CHARGES ═════════════════════════

    /** Two demands for one charge is the DEC-007 shape — never auto-resolved. */
    public function test_an_identity_conflict_is_escalated_not_resolved(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(), 'D2' => $this->demand(['demandId' => 'D2'])],
            [$this->spec(['netAmount' => 500.0])]
        );
        $this->assertSame([], $p['update'], 'picking one of two real charges to overwrite is not automatable');
        $this->assertArrayHasKey('D1', $p['adjust']);
        $this->assertArrayHasKey('D2', $p['adjust']);
        $this->assertSame('identity_conflict', $p['adjust']['D1']['reason']);
    }

    /** A charge the chart no longer produces is REPORTED, never removed. */
    public function test_an_obsolete_demand_is_reported_not_deleted(): void
    {
        $p = $this->planner()->plan(['D1' => $this->demand()], []);
        $this->assertArrayHasKey('D1', $p['obsolete']);
        $this->assertSame('obsolete_unpaid', $p['obsolete']['D1']['reason']);
        $this->assertSame(0, $p['summary']['deletes']);
    }

    /** An obsolete demand that was PAID is flagged distinctly — money left it. */
    public function test_an_obsolete_paid_demand_is_flagged_distinctly(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['paidAmount' => 540.0, 'status' => 'paid'])], []);
        $this->assertSame('obsolete_but_paid', $p['obsolete']['D1']['reason']);
        $this->assertSame(540.0, $p['obsolete']['D1']['paid']);
    }

    // ══ MATCHING ════════════════════════════════════════════════════════

    /** Charges match on feeHead|periodKey, case-insensitively — not on id. */
    public function test_matching_is_by_charge_key_not_demand_id(): void
    {
        $this->assertSame('tuition fee|2026-06',
            Fee_recalculation_planner::chargeKey(['feeHead' => '  Tuition Fee ', 'periodKey' => '2026-06']));
        // A different id for the same charge must still match in place.
        $p = $this->planner()->plan(
            ['SOME_OTHER_ID' => $this->demand(['netAmount' => 600.0, 'discountAmount' => 0.0])],
            [$this->spec()]
        );
        $this->assertArrayHasKey('SOME_OTHER_ID', $p['update']);
        $this->assertSame([], $p['create'], 'it must not create a duplicate alongside the existing charge');
    }

    /** Different periods of the same head are different charges. */
    public function test_periods_are_not_conflated(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['periodKey' => '2026-06'])],
            [$this->spec(['periodKey' => '2026-07'])]
        );
        $this->assertCount(1, $p['create'], 'July is a new charge');
        $this->assertArrayHasKey('D1', $p['obsolete'], 'June is no longer expected');
    }

    /** Sub-paisa noise is not a difference. */
    public function test_rounding_noise_is_not_treated_as_a_change(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['netAmount' => 540.004])], [$this->spec()]);
        $this->assertArrayHasKey('D1', $p['unchanged']);
        $this->assertSame([], $p['update']);
    }

    // ══ THE SAFETY GATE ═════════════════════════════════════════════════

    /** A clean plan may be applied automatically. */
    public function test_a_clean_plan_is_auto_applicable(): void
    {
        $p = $this->planner()->plan(
            ['D1' => $this->demand(['netAmount' => 600.0, 'discountAmount' => 0.0])],
            [$this->spec()]
        );
        $this->assertTrue($this->planner()->isAutoApplicable($p));
    }

    /** Anything touching settled money or abandoning a charge needs a human. */
    public function test_adjust_or_obsolete_blocks_auto_application(): void
    {
        $withAdjust = $this->planner()->plan(
            ['D1' => $this->demand(['paidAmount' => 100.0])],
            [$this->spec(['netAmount' => 600.0, 'discountAmount' => 0.0])]
        );
        $this->assertFalse($this->planner()->isAutoApplicable($withAdjust));

        $withObsolete = $this->planner()->plan(['D1' => $this->demand()], []);
        $this->assertFalse($this->planner()->isAutoApplicable($withObsolete));
    }

    // ══ PURITY AND INERTNESS ════════════════════════════════════════════

    /** The planner reads and writes nothing. */
    public function test_the_planner_performs_no_io(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_recalculation_planner.php');
        foreach (['firestore', 'firebase', 'get_instance', 'load->', 'query(', 'schoolWhere'] as $verb) {
            $this->assertStringNotContainsString($verb, $src,
                "the planner must stay pure: found '{$verb}'");
        }
    }

    /** It is not wired anywhere yet — RC-1 still runs its old path behind the guard. */
    /**
     * UPDATED BY P5-E (2026-08-20). This asserted the planner had NO callers —
     * correct while executing a plan was unauthorized. It is now wired into
     * `Fees::_recalculateStudentCanonical()`, which is the reason it was built:
     * recalculation recomputes in place through the planner's safe outcome set
     * instead of deleting and regenerating.
     *
     * The assertion is INVERTED rather than deleted, so an accidental SECOND
     * caller — a second recalculation authority — still fails loudly.
     */
    public function test_it_is_wired_only_into_the_canonical_recalculation(): void
    {
        $callers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php')) as $f) {
            if (basename($f) === 'Fee_recalculation_planner.php') continue;
            if (strpos(file_get_contents($f), 'Fee_recalculation_planner') !== false) {
                $callers[] = basename($f);
            }
        }
        $this->assertSame(['Fees.php'], $callers,
            'the planner must be reachable from exactly one path — a second caller would be '
            . 'a second recalculation authority: ' . implode(', ', $callers));
    }

    /** And the DEC-013 guard it will eventually retire is still in force. */
    /**
     * UPDATED BY P5-E. This asserted the DEC-013 refusal was still present,
     * with the reason "the guard must stay until this planner actually replaces
     * RC-1". That condition is now satisfied — the planner DOES drive
     * recalculation — so the guard is retired and the assertion inverts.
     */
    public function test_the_DEC013_guard_is_retired_now_that_the_planner_drives_recalculation(): void
    {
        $code = file_get_contents(APPPATH . 'controllers/Fees.php');

        $this->assertStringNotContainsString('ACC_RECALC_REFUSED_ENTITLEMENT', $code,
            'the refusal is retired because the destructive path it guarded no longer exists');
        $this->assertStringNotContainsString("firestoreDelete('feeDemands'", $code,
            'and the delete it protected against is gone — that is what makes retirement safe');
    }
}
