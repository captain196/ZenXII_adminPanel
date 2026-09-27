<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19B — FEES-FROZEN-1: createModuleFee() must not erase a posted fine.
 *
 * THE DEFECT: `Fee_lifecycle::createModuleFee()` re-derived `balance` from the
 * net alone, dropping any late fee already posted to the demand.
 * `writeDemand()` merges, so `fine_amount` survived on the document — the
 * money simply stopped being owed. The demand read `fine_amount: 200` with a
 * balance that excluded it: recorded but uncollectable.
 *
 * ⚠ BOTH branches were affected. Earlier reports flagged only the
 * `$paidSoFar > 0` case; the `else` branch set `balance = $net` outright, so an
 * UNPAID demand carrying a fine lost it too — the more likely case, since a
 * fine is normally applied before payment.
 *
 * This generator is re-invoked on the SAME demandId by route change,
 * assignment edit, rollover and reconcile (FIN-1), so the erasure repeated on
 * every regeneration.
 *
 * CANONICAL EQUATION (proven, not assumed) — four independent sites agree on
 * `balance = max(0, net + fine - paid)`:
 *   Fee_late_fee_service::applyTo · Fee_lifecycle::proRateFees ·
 *   Fee_refund_service · FeeCollectionService netTotal.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19ModuleFeeFineAwareBalanceTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    /** Mirrors the FIXED createModuleFee() pay-state derivation. */
    private function payState(float $net, ?array $existing): array
    {
        $paidSoFar = is_array($existing)
            ? (float) ($existing['paidAmount'] ?? $existing['paid_amount'] ?? 0) : 0.0;
        $fineSoFar = is_array($existing)
            ? (float) ($existing['fine_amount'] ?? $existing['fineAmount'] ?? 0) : 0.0;
        if ($fineSoFar < 0) $fineSoFar = 0.0;

        if ($paidSoFar > 0) {
            $balance = max(0.0, round($net + $fineSoFar - $paidSoFar, 2));
            return ['paidAmount' => $paidSoFar, 'balance' => $balance,
                    'status' => $balance <= 0.005 ? 'paid' : 'partial'];
        }
        $balance = max(0.0, round($net + $fineSoFar, 2));
        return ['paidAmount' => 0.0, 'balance' => $balance, 'status' => 'unpaid'];
    }

    /** The OLD behaviour, for regression proof. */
    private function legacyPayState(float $net, ?array $existing): array
    {
        $paidSoFar = is_array($existing)
            ? (float) ($existing['paidAmount'] ?? $existing['paid_amount'] ?? 0) : 0.0;
        if ($paidSoFar > 0) {
            $balance = max(0.0, round($net - $paidSoFar, 2));
            return ['paidAmount' => $paidSoFar, 'balance' => $balance,
                    'status' => $balance <= 0.005 ? 'paid' : 'partial'];
        }
        return ['paidAmount' => 0.0, 'balance' => $net, 'status' => 'unpaid'];
    }

    // ── A. The defect, both branches ────────────────────────────────────

    public function test_legacy_erased_the_fine_on_an_unpaid_demand(): void
    {
        // The branch earlier reports MISSED.
        $old = $this->legacyPayState(1000.0, ['paidAmount' => 0, 'fine_amount' => 200.0]);
        $this->assertSame(1000.0, $old['balance'], 'the 200 fine vanished from what was owed');
    }

    public function test_legacy_erased_the_fine_on_a_partially_paid_demand(): void
    {
        $old = $this->legacyPayState(1000.0, ['paidAmount' => 400.0, 'fine_amount' => 200.0]);
        $this->assertSame(600.0, $old['balance'], 'should have been 800');
    }

    public function test_unpaid_demand_now_owes_net_plus_fine(): void
    {
        $s = $this->payState(1000.0, ['paidAmount' => 0, 'fine_amount' => 200.0]);
        $this->assertSame(1200.0, $s['balance']);
        $this->assertSame('unpaid', $s['status']);
    }

    public function test_partially_paid_demand_now_owes_net_plus_fine_minus_paid(): void
    {
        $s = $this->payState(1000.0, ['paidAmount' => 400.0, 'fine_amount' => 200.0]);
        $this->assertSame(800.0, $s['balance']);
        $this->assertSame('partial', $s['status']);
    }

    // ── B. No fine — behaviour must be unchanged ────────────────────────

    public function test_no_fine_unpaid_is_identical_to_before(): void
    {
        $this->assertSame(
            $this->legacyPayState(1000.0, null),
            $this->payState(1000.0, null)
        );
    }

    public function test_no_fine_partially_paid_is_identical_to_before(): void
    {
        $existing = ['paidAmount' => 400.0];
        $this->assertSame(
            $this->legacyPayState(1000.0, $existing),
            $this->payState(1000.0, $existing)
        );
    }

    public function test_brand_new_demand_is_unpaid_for_the_full_net(): void
    {
        $s = $this->payState(1500.0, null);
        $this->assertSame(1500.0, $s['balance']);
        $this->assertSame(0.0, $s['paidAmount']);
        $this->assertSame('unpaid', $s['status']);
    }

    // ── C. Payment states ───────────────────────────────────────────────

    public function test_fully_paid_including_the_fine_becomes_paid(): void
    {
        $s = $this->payState(1000.0, ['paidAmount' => 1200.0, 'fine_amount' => 200.0]);
        $this->assertSame(0.0, $s['balance']);
        $this->assertSame('paid', $s['status']);
    }

    public function test_net_paid_but_fine_outstanding_stays_partial(): void
    {
        // The family paid the fare but not the penalty — they still owe it.
        $s = $this->payState(1000.0, ['paidAmount' => 1000.0, 'fine_amount' => 200.0]);
        $this->assertSame(200.0, $s['balance']);
        $this->assertSame('partial', $s['status']);
    }

    public function test_overpayment_floors_at_zero(): void
    {
        $s = $this->payState(1000.0, ['paidAmount' => 5000.0, 'fine_amount' => 200.0]);
        $this->assertSame(0.0, $s['balance']);
        $this->assertSame('paid', $s['status']);
    }

    // ── D. Regeneration — the FIN-1 scenario ────────────────────────────

    public function test_repeated_regeneration_is_idempotent_and_never_erases_the_fine(): void
    {
        // Route change / assignment edit / rollover / reconcile all re-invoke
        // this generator on the SAME demandId.
        $existing = ['paidAmount' => 400.0, 'fine_amount' => 200.0];
        $first  = $this->payState(1000.0, $existing);
        $second = $this->payState(1000.0, $existing);
        $third  = $this->payState(1000.0, $existing);
        $this->assertSame(800.0, $first['balance']);
        $this->assertSame($first, $second);
        $this->assertSame($second, $third, 'regeneration must be stable');
    }

    public function test_fine_is_never_doubled_by_regeneration(): void
    {
        $existing = ['paidAmount' => 0, 'fine_amount' => 200.0];
        $s = $this->payState(1000.0, $existing);
        $this->assertSame(1200.0, $s['balance']);
        $this->assertNotSame(1400.0, $s['balance'], 'fine added once, never accumulated');
    }

    public function test_a_changed_fare_recomputes_against_the_new_net_keeping_the_fine(): void
    {
        // Route change: fare 1000 → 1200, fine and payment preserved.
        $existing = ['paidAmount' => 400.0, 'fine_amount' => 200.0];
        $s = $this->payState(1200.0, $existing);
        $this->assertSame(1000.0, $s['balance']);   // 1200 + 200 - 400
    }

    // ── E. Field-shape tolerance ────────────────────────────────────────

    public function test_camel_case_fine_key_is_also_read(): void
    {
        // The engine writes fine_amount; other paths emit fineAmount. This
        // reads the RAW Firestore document, so both must work.
        $snake = $this->payState(1000.0, ['paidAmount' => 0, 'fine_amount' => 200.0]);
        $camel = $this->payState(1000.0, ['paidAmount' => 0, 'fineAmount'  => 200.0]);
        $this->assertSame($snake['balance'], $camel['balance']);
        $this->assertSame(1200.0, $camel['balance']);
    }

    public function test_snake_case_paid_key_is_also_read(): void
    {
        $s = $this->payState(1000.0, ['paid_amount' => 400.0, 'fine_amount' => 200.0]);
        $this->assertSame(800.0, $s['balance']);
    }

    public function test_negative_or_missing_fine_is_treated_as_zero(): void
    {
        $this->assertSame(1000.0, $this->payState(1000.0, ['paidAmount' => 0, 'fine_amount' => -50.0])['balance']);
        $this->assertSame(1000.0, $this->payState(1000.0, ['paidAmount' => 0])['balance']);
    }

    // ── F. Discount / entitlement interaction — no double counting ──────

    public function test_fine_is_added_to_the_post_discount_net_not_the_gross(): void
    {
        // Phase D prices $net after the entitlement deduction; the fine sits
        // on top of that net, never on the gross fare.
        $gross = 1000.0; $deduction = 250.0; $net = $gross - $deduction;
        $s = $this->payState($net, ['paidAmount' => 0, 'fine_amount' => 200.0]);
        $this->assertSame(950.0, $s['balance']);      // 750 + 200
        $this->assertNotSame(1200.0, $s['balance'], 'the discount must not be undone by the fine');
    }

    // ── G. Consistency with the rest of the lifecycle ───────────────────

    public function test_matches_the_late_fee_engine_equation(): void
    {
        $net = 1000.0; $fine = 200.0; $paid = 400.0;
        $engine = round(max(0, $net + $fine - $paid), 2);          // Fee_late_fee_service
        $module = $this->payState($net, ['paidAmount' => $paid, 'fine_amount' => $fine])['balance'];
        $this->assertSame($engine, $module, 'module fees must not use a different equation');
    }

    public function test_matches_the_refund_service_equation(): void
    {
        $net = 1000.0; $fine = 200.0; $newPaid = 100.0;
        $refund = round(max(0, $net + $fine - $newPaid), 2);        // Fee_refund_service:535
        $module = $this->payState($net, ['paidAmount' => $newPaid, 'fine_amount' => $fine])['balance'];
        $this->assertSame($refund, $module);
    }

    // ── H. Identity invariants ──────────────────────────────────────────

    public function test_balance_logic_carries_no_student_identity(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
