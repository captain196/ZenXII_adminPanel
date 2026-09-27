<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_recalculation_planner.php';

/**
 * P5-E — RECALCULATION RECOMPUTES IN PLACE THROUGH THE CANONICAL PIPELINE.
 *
 * ── WHAT THIS REPLACED ────────────────────────────────────────────────────
 * `recalc_unpaid_discounts()` used to DELETE every unpaid demand and regenerate
 * it through the entitlement-blind legacy generator. Two consequences:
 *
 *   1. an approved concession came back at GROSS — which is why DEC-013 had to
 *      refuse the operation outright for entitled students;
 *   2. deleting a demand destroys its identity, so any receipt or journal that
 *      referenced it is orphaned.
 *
 * The refusal was containment, not a fix: an entitlement granted AFTER
 * generation could never reach the money at all. That is the STU0184 shape seen
 * live — ₹11,200 billed while holding a 10% grant worth ₹720.
 *
 * ── WHAT IT IS NOW ────────────────────────────────────────────────────────
 * Target amounts come from the canonically-priced chart; `Fee_recalculation_planner`
 * diffs them against the student's existing demands; only `update` outcomes are
 * applied, as merge patches onto the EXISTING demand id.
 *
 * These tests drive the REAL planner with production-shaped demands.
 */
class P5ECanonicalRecalculationTest extends TestCase
{
    private Fee_recalculation_planner $p;

    protected function setUp(): void { $this->p = new Fee_recalculation_planner(); }

    /** An existing demand, as Firestore holds it. */
    private function demand(array $o = []): array
    {
        return array_merge([
            'demandId' => 'DEM_STU9001_202604_FH_TUI', 'studentId' => 'STU9001',
            'feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
            'grossAmount' => 600.0, 'discountAmount' => 0.0, 'netAmount' => 600.0,
            'paidAmount' => 0.0, 'balance' => 600.0, 'status' => 'unpaid',
        ], $o);
    }

    /** A canonically-priced target for the same charge. */
    private function target(array $o = []): array
    {
        return array_merge([
            'feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
        ], $o);
    }

    // ══ THE HEADLINE — a late-granted entitlement finally reaches the money ══

    /**
     * The exact failure the old refuse-only guard could never fix: a demand
     * generated at gross, then an entitlement approved. Recalculation must now
     * correct it IN PLACE, keeping the demand id.
     */
    public function test_a_late_granted_entitlement_corrects_the_demand_in_place(): void
    {
        $existing = ['DEM_STU9001_202604_FH_TUI' => $this->demand()];
        $plan = $this->p->plan($existing, [$this->target()]);

        $this->assertArrayHasKey('DEM_STU9001_202604_FH_TUI',
            $plan[Fee_recalculation_planner::UPDATE],
            'a gross-billed demand with an approved concession must be corrected');

        $patch = $plan[Fee_recalculation_planner::UPDATE]['DEM_STU9001_202604_FH_TUI']['patch'];
        $this->assertSame(60.0,  $patch['discountAmount'] ?? null);
        $this->assertSame(540.0, $patch['netAmount'] ?? null);
    }

    /** Identity is preserved — no receipt or journal can be orphaned. */
    public function test_the_demand_id_never_changes(): void
    {
        $plan = $this->p->plan(
            ['DEM_STU9001_202604_FH_TUI' => $this->demand()],
            [$this->target()]
        );

        $this->assertSame(['DEM_STU9001_202604_FH_TUI'],
            array_keys($plan[Fee_recalculation_planner::UPDATE]),
            'correcting a charge must patch the SAME document, never mint a new one');
        $this->assertSame(0, $plan['summary']['deletes'],
            'the planner has no delete verb at all — that is structural, not policy');
    }

    // ══ SETTLED MONEY IS NEVER REWRITTEN ═══════════════════════════════════

    /** A fully-paid demand whose charge moved is ESCALATED, never patched. */
    public function test_a_paid_demand_whose_charge_moved_is_escalated(): void
    {
        $paid = $this->demand(['paidAmount' => 600.0, 'status' => 'paid', 'balance' => 0.0]);
        $plan = $this->p->plan(['DEM_STU9001_202604_FH_TUI' => $paid], [$this->target()]);

        $this->assertArrayHasKey('DEM_STU9001_202604_FH_TUI',
            $plan[Fee_recalculation_planner::ADJUST],
            'settled money must reach a human, not be silently corrected');
        $this->assertSame([], $plan[Fee_recalculation_planner::UPDATE],
            'a receipt already refers to these amounts');
    }

    /** A paid demand that is already correct is simply protected. */
    public function test_a_paid_and_correct_demand_is_protected(): void
    {
        $paid = $this->demand(['paidAmount' => 600.0, 'status' => 'paid', 'balance' => 0.0]);
        $plan = $this->p->plan(['DEM_STU9001_202604_FH_TUI' => $paid],
                               [$this->target(['discountAmount' => 0.0, 'netAmount' => 600.0])]);

        $this->assertArrayHasKey('DEM_STU9001_202604_FH_TUI',
            $plan[Fee_recalculation_planner::PROTECT]);
        $this->assertSame([], $plan[Fee_recalculation_planner::ADJUST]);
    }

    /** A PARTIALLY paid demand is settled money too — never silently rewritten. */
    public function test_a_partially_paid_demand_is_not_rewritten(): void
    {
        $part = $this->demand(['paidAmount' => 200.0, 'balance' => 400.0, 'status' => 'partial']);
        $plan = $this->p->plan(['DEM_STU9001_202604_FH_TUI' => $part], [$this->target()]);

        $this->assertSame([], $plan[Fee_recalculation_planner::UPDATE],
            'a partial payment already refers to the current charge');
        $this->assertArrayHasKey('DEM_STU9001_202604_FH_TUI',
            $plan[Fee_recalculation_planner::ADJUST]);
    }

    // ══ NOTHING CHANGES WHEN NOTHING CHANGED ═══════════════════════════════

    public function test_an_already_correct_demand_is_left_alone(): void
    {
        $ok   = $this->demand(['discountAmount' => 60.0, 'netAmount' => 540.0, 'balance' => 540.0]);
        $plan = $this->p->plan(['DEM_STU9001_202604_FH_TUI' => $ok], [$this->target()]);

        $this->assertArrayHasKey('DEM_STU9001_202604_FH_TUI',
            $plan[Fee_recalculation_planner::UNCHANGED]);
        $this->assertSame([], $plan[Fee_recalculation_planner::UPDATE],
            'a no-op recalculation must issue no writes at all');
    }

    // ══ CHARGES THAT LEFT THE CHART ════════════════════════════════════════

    /** An obsolete head is REPORTED, never deleted — even when unpaid. */
    public function test_an_obsolete_charge_is_reported_not_deleted(): void
    {
        $plan = $this->p->plan(['DEM_OLD' => $this->demand([
            'demandId' => 'DEM_OLD', 'feeHead' => 'Retired Fee', 'periodKey' => '2026-04',
        ])], [$this->target()]);

        $this->assertArrayHasKey('DEM_OLD', $plan[Fee_recalculation_planner::OBSOLETE]);
        $this->assertSame(0, $plan['summary']['deletes']);
    }

    /** A charge that should exist and does not is CREATEd, not skipped. */
    public function test_a_missing_charge_is_created(): void
    {
        $plan = $this->p->plan([], [$this->target()]);
        $this->assertCount(1, $plan[Fee_recalculation_planner::CREATE]);
    }

    // ══ IDENTITY CONFLICTS ARE NEVER AUTO-RESOLVED ═════════════════════════

    /**
     * Two demands for one charge key is the DEC-007 shape. Picking a winner is
     * exactly the silent decision this planner refuses to make.
     */
    public function test_a_duplicate_charge_key_is_escalated_not_resolved(): void
    {
        $existing = [
            'DEM_A' => $this->demand(['demandId' => 'DEM_A']),
            'DEM_B' => $this->demand(['demandId' => 'DEM_B']),
        ];
        $plan = $this->p->plan($existing, [$this->target()]);

        $this->assertCount(2, $plan[Fee_recalculation_planner::ADJUST],
            'both duplicates must be surfaced — choosing one would destroy a real charge');
        $this->assertSame([], $plan[Fee_recalculation_planner::UPDATE]);
        $this->assertFalse($this->p->isAutoApplicable($plan),
            'a plan containing an identity conflict must never be auto-applied');
    }

    // ══ AUTO-APPLICABILITY ═════════════════════════════════════════════════

    public function test_a_clean_plan_is_auto_applicable(): void
    {
        $plan = $this->p->plan(['DEM_STU9001_202604_FH_TUI' => $this->demand()], [$this->target()]);
        $this->assertTrue($this->p->isAutoApplicable($plan));
    }

    public function test_a_plan_with_obsolete_charges_is_not_auto_applicable(): void
    {
        $plan = $this->p->plan(['DEM_OLD' => $this->demand([
            'demandId' => 'DEM_OLD', 'feeHead' => 'Retired Fee',
        ])], [$this->target()]);
        $this->assertFalse($this->p->isAutoApplicable($plan));
    }

    // ══ THE CONTROLLER APPLIES ONLY WHAT IS SAFE ═══════════════════════════

    /**
     * Source-level boundary guard (not offered as behavioural proof): the
     * canonical recalculation must apply UPDATE only. Applying ADJUST or
     * OBSOLETE would rewrite settled money or act on a charge a human has not
     * ruled on.
     */
    public function test_the_controller_applies_only_update_outcomes(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Fees.php');
        $at  = strpos($src, 'function _recalculateStudentCanonical');
        $this->assertNotFalse($at);
        $seg = substr($src, $at, 4500);

        $this->assertStringContainsString('Fee_recalculation_planner::UPDATE', $seg);
        $this->assertStringContainsString('updateDemand', $seg,
            'corrections are merge patches onto the existing id');
        $this->assertStringNotContainsString('firestoreDelete', $seg,
            'recalculation must never delete');
    }
}
