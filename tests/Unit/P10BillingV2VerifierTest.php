<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Billing_v2_verifier.php';

/**
 * P10 — the Billing Engine V2 production-readiness verifier.
 *
 * V2 is not a new engine; it is the COMPOSITION of M5 classification, SE-V2
 * entitlements, the P4 allocation ledger, canonical generation and the P7
 * accounting feed. What was missing was one place that answers whether the
 * composition is coherent. This is the certification instrument for the
 * activation gate — it must run clean before activation is even proposed.
 *
 * Every invariant is exercised in BOTH directions: a healthy estate must pass,
 * and each specific corruption must be caught. A verifier only proven on clean
 * data is worthless.
 *
 * LIVE RESULT (2026-08-19, read-only): SCH_218AAF5C23 1,118 demands PASS ·
 * SCH_B56BB9A401 140 demands PASS (26 legacy pre-M5, counted not flagged) ·
 * zero violations estate-wide.
 */
class P10BillingV2VerifierTest extends TestCase
{
    private function v(): Billing_v2_verifier { return new Billing_v2_verifier(); }

    /** The real STU0185 Tuition shape — healthy. */
    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => 'D1', 'schoolId' => 'SCH_B56BB9A401', 'session' => '2026-27',
            'feeHead' => 'Tuition Fee', 'feeHeadRef' => 'FH_DF3C200439E2',
            'category' => 'academic', 'categorySource' => 'registry',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
            'fineAmount' => 0.0, 'paidAmount' => 0.0, 'balance' => 540.0,
        ], $over);
    }

    private function alloc(array $over = []): array
    {
        return array_merge([
            'allocationId' => 'A1', 'demandId' => 'D1', 'entitlementId' => 'E1',
            'allocatedAmount' => 60.0, 'basisAmount' => 600.0, 'status' => 'active',
        ], $over);
    }

    // ══ THE HEALTHY CASE ════════════════════════════════════════════════

    public function test_a_healthy_demand_with_its_allocation_passes(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand()], [$this->alloc()], ['m5' => true]);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['violations']);
        $this->assertStringStartsWith('PASS', Billing_v2_verifier::verdict($r));
    }

    /** An undiscounted demand with no allocations is the common healthy case. */
    public function test_an_undiscounted_demand_passes(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['discountAmount' => 0.0, 'netAmount' => 600.0,
                                    'balance' => 600.0])], [], ['m5' => true]);
        $this->assertTrue($r['ok']);
    }

    // ══ EACH INVARIANT CAUGHT ═══════════════════════════════════════════

    /** I1 — the DEC-012/DEC-013 shape. */
    public function test_I1_catches_money_incoherence(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand(['netAmount' => 600.0])], []);
        $this->assertSame('I1', $r['violations'][0]['invariant']);
        $this->assertSame('money_incoherent', $r['violations'][0]['code']);
    }

    /** I2 — allocations that do not explain the discount. */
    public function test_I2_catches_a_projection_mismatch(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand()],
            [$this->alloc(['allocatedAmount' => 30.0])]);
        $this->assertSame('I2', $r['violations'][0]['invariant']);
        $this->assertSame(-30.0, $r['violations'][0]['detail']['delta']);
    }

    /** I2 — a discount with no ledger row at all (DEC-013). */
    public function test_I2_catches_a_discount_with_no_allocation(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand()], [$this->alloc(['demandId' => 'OTHER'])]);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('discount_without_allocation', $codes);
    }

    /** I3 — over-allocation, reported AHEAD of the projection mismatch. */
    public function test_I3_catches_over_allocation_and_outranks_I2(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['discountAmount' => 700.0, 'netAmount' => -100.0])],
            [$this->alloc(['allocatedAmount' => 700.0])]);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('over_allocated', $codes);
        $this->assertNotContains('projection_mismatch', $codes,
            'the more serious fault is the one reported');
    }

    /** I4 — Transport repricing leaves the allocation basis stale. */
    public function test_I4_catches_a_stale_allocation_basis(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand()],
            [$this->alloc(['basisAmount' => 2400.0])]);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('stale_allocation_basis', $codes,
            'a repriced charge must invalidate the row, not silently keep it');
    }

    /** I5 — the settlement identity, including the fine. */
    public function test_I5_catches_an_incoherent_balance(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand(['balance' => 999.0])], [$this->alloc()]);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('balance_incoherent', $codes);
    }

    /** I5 — a fine belongs in the balance and must not read as a violation. */
    public function test_I5_accepts_a_fine_in_the_balance(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['fineAmount' => 50.0, 'balance' => 590.0])],
            [$this->alloc()]);
        $this->assertTrue($r['ok'], 'balance = net 540 + fine 50 - paid 0');
    }

    /** I6 — more collected than was ever charged. */
    public function test_I6_catches_over_collection(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['paidAmount' => 900.0, 'balance' => 0.0])], [$this->alloc()]);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('over_collected', $codes);
    }

    /** I7 — an allocation about a demand that does not exist. */
    public function test_I7_catches_an_orphan_allocation(): void
    {
        $r = $this->v()->verify([], [$this->alloc()]);
        $this->assertSame('I7', $r['violations'][0]['invariant']);
        $this->assertSame('A1', $r['violations'][0]['detail']['allocationId']);
    }

    // ══ I8 — THE CALIBRATION THAT FIXED A FALSE FAIL ════════════════════

    /**
     * A demand CLAIMING registry provenance must carry the identity that claim
     * implies — that is a genuine stamping failure.
     */
    public function test_I8_catches_a_registry_stamped_demand_missing_its_ref(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['feeHeadRef' => ''])], [$this->alloc()], ['m5' => true]);
        $codes = array_column($r['violations'], 'code');
        $this->assertContains('missing_fee_head_ref', $codes);
    }

    /**
     * THE FALSE FAIL THIS FIXED. A pre-M5 demand legitimately has no ref. The
     * first version flagged 52 such demands as violations — all STU0012 rows
     * created 2026-06-13, two months BEFORE the M5 grant. Retro-stamping them
     * would be a BACKFILL (protected), not a fix.
     */
    public function test_I8_does_not_flag_a_legacy_pre_M5_demand(): void
    {
        $legacy = $this->demand(['feeHeadRef' => '', 'category' => '', 'categorySource' => '']);
        $r = $this->v()->verify(['D1' => $legacy], [$this->alloc()], ['m5' => true]);
        $codes = array_column($r['violations'], 'code');
        $this->assertNotContains('missing_fee_head_ref', $codes,
            'a demand written before the registry existed is not a defect');
        $this->assertNotContains('missing_category', $codes);
    }

    /** But legacy demands are COUNTED — visible for the migration decision. */
    public function test_legacy_demands_are_counted_not_hidden(): void
    {
        $legacy = $this->demand(['feeHeadRef' => '', 'categorySource' => '']);
        $r = $this->v()->verify(['D1' => $legacy], [$this->alloc()], ['m5' => true]);
        $this->assertSame(1, $r['legacyPreM5'],
            'counted so the migration decision is informed, never silently dropped');
    }

    /** I8 does not apply to a tenant without M5. */
    public function test_I8_is_inert_without_M5(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['feeHeadRef' => '', 'category' => ''])],
            [$this->alloc()], ['m5' => false]);
        $this->assertTrue($r['ok']);
    }

    // ══ REPORTING QUALITY ═══════════════════════════════════════════════

    /** Every violation names the demand — a count nobody can act on is useless. */
    public function test_every_violation_identifies_its_demand(): void
    {
        $r = $this->v()->verify(['D1' => $this->demand(['netAmount' => 600.0, 'balance' => 999.0])], []);
        $this->assertNotEmpty($r['violations']);
        foreach ($r['violations'] as $x) {
            $this->assertArrayHasKey('demandId', $x);
            $this->assertArrayHasKey('invariant', $x);
            $this->assertArrayHasKey('detail', $x);
            $this->assertNotSame('', $x['demandId']);
        }
    }

    public function test_the_verdict_is_blunt_in_both_directions(): void
    {
        $pass = $this->v()->verify(['D1' => $this->demand()], [$this->alloc()]);
        $this->assertStringStartsWith('PASS', Billing_v2_verifier::verdict($pass));

        $fail = $this->v()->verify(['D1' => $this->demand(['netAmount' => 1.0])], []);
        $this->assertStringStartsWith('FAIL', Billing_v2_verifier::verdict($fail));
    }

    /** Reversed allocations do not count toward the projection. */
    public function test_reversed_allocations_are_excluded(): void
    {
        $r = $this->v()->verify(
            ['D1' => $this->demand(['discountAmount' => 0.0, 'netAmount' => 600.0,
                                    'balance' => 600.0])],
            [$this->alloc(['status' => 'reversed'])]);
        $this->assertTrue($r['ok'], 'an unwound grant must not keep explaining a discount');
    }

    // ══ IT MUST NEVER REPAIR ════════════════════════════════════════════

    /** A verifier that fixes what it finds destroys the evidence. */
    public function test_the_verifier_never_repairs_or_writes(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Billing_v2_verifier.php');
        // Target WRITE VERBS, not the substring "firestore" — the verifier
        // legitimately reuses `Fee_firestore_txn::moneyIncoherence()`, which is
        // a pure predicate and is the whole point of test_I1_reuses_... below.
        foreach (['firestoreSet', 'firestoreCreateStatus', 'firestoreDelete',
                  'createIfAbsent', 'batchWrite', 'setEntity', 'removeEntity',
                  'writeDemand', 'updateDemand', '->persist(', 'get_instance'] as $verb) {
            $this->assertStringNotContainsString($verb, $src,
                "the verifier must observe, never mutate: found '{$verb}'");
        }
        // And it must hold no live handle it could write through.
        $this->assertStringNotContainsString('$this->firebase', $src);
        $this->assertStringNotContainsString('$this->fs', $src);
    }

    /** It reuses the WRITE BOUNDARY's own predicate, so the two cannot disagree. */
    public function test_I1_reuses_the_write_boundary_predicate(): void
    {
        $this->assertStringContainsString('Fee_firestore_txn::moneyIncoherence',
            file_get_contents(APPPATH . 'libraries/Billing_v2_verifier.php'),
            'a second definition of "coherent" would let the writer and the '
            . 'verifier drift apart');
    }
}
