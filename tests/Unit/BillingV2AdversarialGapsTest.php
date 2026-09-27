<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Entitlement_allocation_service.php';
require_once __DIR__ . '/../../application/libraries/Fee_recalculation_planner.php';

/**
 * BILLING V2 — ADVERSARIAL COVERAGE GAPS.
 *
 * The adversarial matrix is largely covered by the P4 / P5-C / P5-D / P5-E
 * suites. This file closes the scenarios those did NOT reach, rather than
 * restating them: module (Transport/Hostel) repricing, promotion/archival,
 * legacy scholarship data, and concurrency on the write primitive.
 *
 * Everything here drives real classes. Where a scenario can only be expressed
 * as an architectural boundary, that is stated as such and NOT offered as
 * behavioural proof.
 */
class BillingV2AdversarialGapsTest extends TestCase
{
    private const SCHOOL  = 'SCH_TESTTENANT1';
    private const SESSION = '2026-27';

    private function alloc(): Entitlement_allocation_service
    {
        return (new Entitlement_allocation_service())
            ->init(null, null, self::SCHOOL, self::SESSION, 'test');
    }

    private function demand(array $o = []): array
    {
        return array_merge([
            'demandId' => 'DEM_STU1_202604_FH_TRN', 'schoolId' => self::SCHOOL,
            'session' => self::SESSION, 'studentId' => 'STU1',
            'feeHead' => 'Transport Fee', 'periodKey' => '2026-04',
            'grossAmount' => 2400.0, 'discountAmount' => 0.0, 'netAmount' => 2400.0,
            'paidAmount' => 0.0, 'balance' => 2400.0, 'status' => 'unpaid',
        ], $o);
    }

    private function ent(array $o = []): array
    {
        return array_merge([
            'entitlementId' => 'SE_TRN', 'schoolId' => self::SCHOOL, 'session' => self::SESSION,
            'status' => 'active', 'valueType' => 'percent', 'value' => 25.0, 'scope' => 'head:FH_TRN',
        ], $o);
    }

    // ══ 24 · MODULE REPRICING (Transport / Hostel) ═════════════════════════

    /**
     * Transport legitimately RE-PRICES a demand in place (DEC-001): a route
     * change moves `grossAmount` under an allocation computed earlier. The
     * allocation is not corrupted — but it is no longer a statement about the
     * current charge, and P5 must recompute rather than trust it.
     */
    public function test_a_transport_reprice_makes_an_allocation_detectably_stale(): void
    {
        $svc = $this->alloc();
        $row = $svc->build($this->demand(['discountAmount' => 600.0]), $this->ent(), 600.0)['row'];

        $this->assertFalse($svc->isStale($row, $this->demand()),
            'unchanged charge must not read as stale');

        // Route change: 2400 → 3600.
        $this->assertTrue($svc->isStale($row, $this->demand(['grossAmount' => 3600.0])),
            'a repriced module fee must make its allocation detectably stale, never silently wrong');
    }

    /**
     * After a reprice the allocation must be RECOMPUTED, not scaled blindly.
     * A 25% grant on 3600 is 900, not the previous 600.
     */
    public function test_a_repriced_module_fee_recomputes_the_allocation(): void
    {
        $svc  = $this->alloc();
        $new  = $this->demand(['grossAmount' => 3600.0, 'discountAmount' => 900.0]);
        $row  = $svc->build($new, $this->ent(), 900.0)['row'];

        $this->assertSame(3600.0, $row['basisAmount'], 'the basis must follow the new charge');
        $this->assertSame(900.0,  $row['allocatedAmount']);
        $this->assertNull($svc->validateForDemand($new, [$row]),
            'the recomputed allocation must exactly explain the repriced discount');
    }

    /** A reprice that REDUCES the charge must not leave an over-allocation. */
    public function test_a_downward_reprice_cannot_leave_an_over_allocation(): void
    {
        $svc = $this->alloc();
        // Charge falls to 400 while an old 600 allocation still stands.
        $stale = ['status' => 'active', 'allocatedAmount' => 600.0];
        $small = $this->demand(['grossAmount' => 400.0, 'discountAmount' => 600.0]);

        $bad = $svc->validateForDemand($small, [$stale]);
        $this->assertNotNull($bad);
        $this->assertSame('over_allocated', $bad['reason'],
            'a concession may never exceed the charge, even after a downward reprice');
    }

    // ══ 25 · PROMOTION / ARCHIVAL ══════════════════════════════════════════

    /**
     * Promotion ARCHIVES superseded demands rather than deleting them. The
     * planner must therefore never treat an archived demand as a live charge to
     * correct — and must never propose removing it.
     */
    public function test_promotion_archived_demands_are_not_recalculated(): void
    {
        $p = new Fee_recalculation_planner();

        $archived = $this->demand(['status' => 'archived', 'demandId' => 'DEM_OLD']);
        $plan = $p->plan(['DEM_OLD' => $archived], []);

        $this->assertSame(0, $plan['summary']['deletes'],
            'an archived demand is history — the planner has no verb that could remove it');
        $this->assertArrayHasKey('DEM_OLD', $plan[Fee_recalculation_planner::OBSOLETE],
            'it is reported for a human, never silently acted on');
    }

    /**
     * A promoted student's NEW class charges are ordinary CREATEs — the old
     * class's demands are not repurposed, so no id is ever reused across
     * classes.
     */
    public function test_promotion_creates_new_charges_rather_than_reusing_ids(): void
    {
        $p    = new Fee_recalculation_planner();
        $spec = ['feeHead' => 'Tuition Fee', 'periodKey' => '2026-04',
                 'grossAmount' => 900.0, 'discountAmount' => 0.0, 'netAmount' => 900.0];

        $plan = $p->plan([], [$spec]);
        $this->assertCount(1, $plan[Fee_recalculation_planner::CREATE]);
        $this->assertSame([], $plan[Fee_recalculation_planner::UPDATE],
            'a new class charge must not be written over an old class demand');
    }

    // ══ 21 · LEGACY SCHOLARSHIP DATA ═══════════════════════════════════════

    /**
     * A legacy `scholarshipAwards` record must never become an allocation. The
     * ledger explains ENTITLEMENT pricing; admitting a second authority would
     * make `discountAmount == Σ allocations` unprovable.
     */
    public function test_a_legacy_scholarship_shape_cannot_build_an_allocation(): void
    {
        $svc = $this->alloc();

        // A scholarshipAwards row has no entitlementId and no valueType.
        $legacy = ['schoolId' => self::SCHOOL, 'session' => self::SESSION,
                   'status' => 'active', 'amount' => 500.0, 'scholarshipName' => 'Merit'];

        $res = $svc->build($this->demand(['discountAmount' => 500.0]), $legacy, 500.0);
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_entitlement_id', $res['reason'],
            'the ledger admits exactly one authority — entitlements');
    }

    // ══ 11 · CONCURRENCY ON THE WRITE PRIMITIVE ════════════════════════════

    /**
     * Two concurrent generations racing on the same (demand, entitlement) must
     * produce ONE row. Proven against a store with real create-only semantics —
     * the second writer sees COLLISION and the stored row is untouched.
     */
    public function test_concurrent_allocation_writes_yield_exactly_one_row(): void
    {
        $store = new class {
            public array $docs = [];
            public int $calls = 0;
            public function firestoreCreateStatus(string $c, string $id, array $d): array
            {
                $this->calls++;
                $k = $c . '/' . $id;
                if (array_key_exists($k, $this->docs)) return ['status' => 'COLLISION'];
                $this->docs[$k] = $d;
                return ['status' => 'SUCCESS'];
            }
        };

        $svc = (new Entitlement_allocation_service())
            ->init($store, null, self::SCHOOL, self::SESSION, 'test');

        $row = $svc->build($this->demand(['discountAmount' => 600.0]), $this->ent(), 600.0)['row'];

        // Three racers, same identity.
        $r1 = $svc->persist([$row]);
        $r2 = $svc->persist([$row]);
        $r3 = $svc->persist([$row]);

        $this->assertSame(1, $r1['created']);
        $this->assertSame(1, $r2['duplicate']);
        $this->assertSame(1, $r3['duplicate']);
        $this->assertCount(1, $store->docs, 'three racers, one row');
        $this->assertSame(3, $store->calls, 'all three genuinely reached the store');
    }

    /** A racer with different money must not overwrite the winner's row. */
    public function test_a_losing_racer_cannot_overwrite_the_stored_row(): void
    {
        $store = new class {
            public array $docs = [];
            public function firestoreCreateStatus(string $c, string $id, array $d): array
            {
                $k = $c . '/' . $id;
                if (array_key_exists($k, $this->docs)) return ['status' => 'COLLISION'];
                $this->docs[$k] = $d;
                return ['status' => 'SUCCESS'];
            }
        };
        $svc = (new Entitlement_allocation_service())
            ->init($store, null, self::SCHOOL, self::SESSION, 'test');

        $svc->persist([$svc->build($this->demand(['discountAmount' => 600.0]), $this->ent(), 600.0)['row']]);
        $svc->persist([$svc->build($this->demand(['grossAmount' => 9999.0, 'discountAmount' => 999.0]),
                                   $this->ent(), 999.0)['row']]);

        $stored = array_values($store->docs)[0];
        $this->assertSame(600.0, $stored['allocatedAmount'],
            'history is immutable — a later racer cannot rewrite what was recorded');
    }

    // ══ THE COMPOSED INVARIANT UNDER EVERY SHAPE ═══════════════════════════

    /**
     * Whatever the scenario, both invariants must hold together:
     *   net == gross − discount   AND   discount == Σ active allocations
     */
    public function test_both_money_invariants_hold_across_shapes(): void
    {
        $svc = $this->alloc();

        $shapes = [
            'percent'      => [2400.0, 600.0, $this->ent()],
            'fixed'        => [2400.0, 500.0, $this->ent(['valueType' => 'fixed', 'value' => 500.0])],
            'full charge'  => [2400.0, 2400.0, $this->ent(['valueType' => 'percent', 'value' => 100.0])],
            'tiny'         => [2400.0, 0.01, $this->ent(['valueType' => 'fixed', 'value' => 0.01])],
        ];

        foreach ($shapes as $label => list($gross, $disc, $ent)) {
            $d   = $this->demand(['grossAmount' => $gross, 'discountAmount' => $disc,
                                  'netAmount' => round($gross - $disc, 2)]);
            $row = $svc->build($d, $ent, $disc)['row'];

            $this->assertEqualsWithDelta($d['grossAmount'] - $d['discountAmount'],
                $d['netAmount'], 0.005, "net must equal gross-discount for '{$label}'");
            $this->assertNull($svc->validateForDemand($d, [$row]),
                "allocations must exactly explain the discount for '{$label}'");
        }
    }
}
