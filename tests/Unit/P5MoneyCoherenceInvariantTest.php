<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Fee_firestore_txn.php';

/**
 * P5 — the money-coherence invariant:  netAmount == grossAmount − discountAmount
 *
 * WHY IT EXISTS. Two silent financial defects were found BY HAND this week, and
 * both would have announced themselves the instant they wrote a demand if this
 * rule had been checked:
 *   • DEC-012 — proRateFees() re-derives net from gross and leaves
 *     discountAmount stale  → gross 2400 / discount 240 / net 1200.
 *   • DEC-013 — legacy recalculation rebuilds an entitlement-priced demand at
 *     gross, dropping the discount → gross 600 / discount 60 / net 600.
 * Neither raised anything anywhere. This is the detector they lacked.
 *
 * WHY A PURE PREDICATE. The rule is `Fee_firestore_txn::moneyIncoherence()` —
 * no logging, no I/O, no mutation — so it can be asserted directly, reused by a
 * verifier or migration check, and promoted by P5 from "log" to "refuse" in
 * exactly ONE place.
 *
 * WHERE IT IS CALLED. `_buildDemandDoc()`, the single funnel every demand write
 * passes through: `writeDemand()` (single) and `commitDemandBatch()` (batch)
 * both build their document there. Anywhere else would miss a path.
 *
 * MEASURED BEFORE SHIPPING (2026-08-18, all tenants): 1,258 real demands,
 * **ZERO violations**. The only 98 violating documents are synthetic
 * `DEM_TEST_LOAD_*` load-test residue with no schoolId, session or payments.
 * So this cannot be noisy today — anything it reports is a genuine regression.
 *
 * DELIBERATELY LOG-ONLY FOR NOW. A detector must never be the thing that breaks
 * billing.
 */
class P5MoneyCoherenceInvariantTest extends TestCase
{
    private function check(float $gross, float $discount, float $net): ?array
    {
        return Fee_firestore_txn::moneyIncoherence([
            'grossAmount' => $gross, 'discountAmount' => $discount, 'netAmount' => $net,
        ]);
    }

    // ══ COHERENT DEMANDS MUST BE SILENT ═════════════════════════════════

    /** The real STU0185 Tuition shape. */
    public function test_a_correctly_discounted_demand_is_coherent(): void
    {
        $this->assertNull($this->check(600, 60, 540),
            'the UAT demand shape is correct and must never be flagged');
    }

    /** The overwhelming majority of demands carry no discount. */
    public function test_an_undiscounted_demand_is_coherent(): void
    {
        $this->assertNull($this->check(200, 0, 200));
        $this->assertNull($this->check(50, 0, 50));
        $this->assertNull($this->check(1000, 0, 1000));
    }

    /** A full exemption is coherent: gross − gross == 0. */
    public function test_a_fully_exempt_demand_is_coherent(): void
    {
        $this->assertNull($this->check(1000, 1000, 0));
    }

    /** Sub-paisa rounding must not trip it. */
    public function test_rounding_noise_within_tolerance_is_accepted(): void
    {
        $this->assertNull($this->check(333.33, 33.33, 300.001));
        $this->assertNull($this->check(100, 33.333, 66.667));
    }

    /** Just outside tolerance IS a violation — the boundary must be real. */
    public function test_the_tolerance_boundary_is_enforced(): void
    {
        $this->assertNull($this->check(100, 10, 90.005), 'exactly at tolerance: accepted');
        $this->assertNotNull($this->check(100, 10, 90.02), 'beyond tolerance: flagged');
    }

    /** Zero/absent gross is not judged — module and legacy shapes omit it. */
    public function test_a_zero_or_absent_gross_is_not_judged(): void
    {
        $this->assertNull($this->check(0, 0, 0));
        $this->assertNull($this->check(0, 0, 500), 'no gross means nothing to be coherent about');
        $this->assertNull(Fee_firestore_txn::moneyIncoherence(['netAmount' => 500]));
        $this->assertNull(Fee_firestore_txn::moneyIncoherence([]));
    }

    /** A fine lives in the balance, not the charge. */
    public function test_a_fine_does_not_break_coherence(): void
    {
        $this->assertNull(Fee_firestore_txn::moneyIncoherence([
            'grossAmount' => 600, 'discountAmount' => 60, 'netAmount' => 540,
            'fineAmount'  => 50,  'balance' => 590, 'paidAmount' => 0,
        ]), 'balance = net + fine - paid; a fine is NOT netted into the charge');
    }

    /** A partly-paid demand is still judged on its CHARGE, not its balance. */
    public function test_payment_state_does_not_affect_coherence(): void
    {
        $this->assertNull(Fee_firestore_txn::moneyIncoherence([
            'grossAmount' => 600, 'discountAmount' => 60, 'netAmount' => 540,
            'paidAmount'  => 200, 'balance' => 340, 'status' => 'partial',
        ]));
    }

    // ══ THE TWO REAL DEFECTS MUST BE CAUGHT ═════════════════════════════

    /** DEC-012's exact output: prorated net, stale discount. */
    public function test_it_catches_the_DEC012_proration_shape(): void
    {
        $r = $this->check(2400, 240, 1200);
        $this->assertNotNull($r, 'DEC-012 must be detected');
        $this->assertSame(2400.0, $r['gross']);
        $this->assertSame(240.0,  $r['discount']);
        $this->assertSame(1200.0, $r['net']);
        $this->assertSame(2160.0, $r['expectedNet'], 'what the demand SHOULD have said');
        $this->assertSame(960.0,  $r['delta'],       'the family was over-billed by 960');
    }

    /** DEC-013's exact output: entitlement dropped, net back at gross. */
    public function test_it_catches_the_DEC013_regeneration_shape(): void
    {
        $r = $this->check(600, 60, 600);
        $this->assertNotNull($r, 'DEC-013 must be detected');
        $this->assertSame(540.0, $r['expectedNet']);
        $this->assertSame(-60.0, $r['delta'], 'net is 60 too HIGH: an over-charge');
    }

    /** All twelve DEC-013 Tuition occurrences would each be caught. */
    public function test_every_occurrence_of_the_DEC013_shape_is_caught(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->assertNotNull($this->check(600, 60, 600), "occurrence {$i}");
        }
    }

    /** A discount recorded but never applied. */
    public function test_it_catches_a_discount_that_was_never_applied(): void
    {
        $this->assertNotNull($this->check(1000, 100, 1000));
    }

    /** Under-billing is a defect too, and must be just as loud. */
    public function test_it_catches_under_billing(): void
    {
        $r = $this->check(600, 0, 400);
        $this->assertNotNull($r, 'under-billing must be flagged as loudly as over-billing');
        $this->assertSame(200.0, $r['delta']);
    }

    /** A negative net can never be coherent against a positive gross. */
    public function test_it_catches_a_negative_net(): void
    {
        $this->assertNotNull($this->check(600, 60, -540));
    }

    // ══ IT IS PURE, AND CORRECTLY PLACED ════════════════════════════════

    /** The predicate must not mutate its input. */
    public function test_the_predicate_is_pure(): void
    {
        $doc = ['grossAmount' => 2400, 'discountAmount' => 240, 'netAmount' => 1200];
        $before = $doc;
        Fee_firestore_txn::moneyIncoherence($doc);
        $this->assertSame($before, $doc, 'a detector must never silently repair money');
    }

    /** It must be wired into the SHARED build path, not one write path. */
    public function test_it_is_called_from_the_shared_build_path(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $buildAt = strpos($src, 'private function _buildDemandDoc');
        $callAt  = strpos($src, 'self::moneyIncoherence($doc)');
        $logAt   = strpos($src, 'ACC_DEMAND_MONEY_INCOHERENT');
        $this->assertNotFalse($callAt, 'the rule must be called');
        $this->assertNotFalse($logAt,  'and a violation must be greppable');
        $this->assertGreaterThan($buildAt, $callAt,
            'it must live inside _buildDemandDoc, the funnel BOTH the single and '
            . 'batch write paths pass through - anywhere else misses a path');
    }

    /** Log-only: the build path must not refuse or throw on a violation. */
    public function test_the_detector_does_not_block_the_write(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Fee_firestore_txn.php');
        $seg = substr($src, strpos($src, 'self::moneyIncoherence($doc)'), 900);
        $this->assertStringNotContainsString('throw', $seg,
            'a detector must never be the thing that breaks billing');
        $this->assertStringNotContainsString('return false', $seg);
    }

    /** The demand document is returned untouched. */
    public function test_an_incoherent_demand_still_builds_unchanged(): void
    {
        $txn = (new ReflectionClass('Fee_firestore_txn'))->newInstanceWithoutConstructor();
        $m   = new ReflectionMethod('Fee_firestore_txn', '_buildDemandDoc');
        $m->setAccessible(true);
        $doc = $m->invoke($txn, 'DEM_TEST_1', [
            'studentId' => 'STU0185', 'schoolId' => 'SCH_B56BB9A401',
            'feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
            'grossAmount' => 2400, 'discountAmount' => 240, 'netAmount' => 1200,
        ]);
        $this->assertSame(2400.0, $doc['grossAmount'],    'gross must not be touched');
        $this->assertSame(240.0,  $doc['discountAmount'], 'discount must not be touched');
        $this->assertSame(1200.0, $doc['netAmount'],      'net must not be silently repaired');
    }
}
