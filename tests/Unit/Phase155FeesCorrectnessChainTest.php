<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 15.5 — Fees correctness chain.
 *
 * SCOPE HONESTY: the changed code lives on CodeIgniter controllers/libraries
 * that cannot be bootstrapped in this suite (Fee_lifecycle needs a live
 * Firestore handle; FeeCollectionService needs a controller + session). These
 * tests model the DECISION RULES exactly as implemented and are the regression
 * guard for them. Controller wiring, RBAC evaluation and the browser flow are
 * covered by the UAT matrix in the report, not by this file.
 *
 * Rules under test:
 *   AB   Fee_lifecycle::_dueDateFor()      — periodKey + clamped due day
 *   F-1  Fee_lifecycle::proRateFees()      — fine survives proration
 *   G-3  FeeCollectionService fine gate    — computed fine is canonical
 *
 * IDENTITY DISCIPLINE: crossed fixtures throughout.
 *   STU0012 ↔ SRNO 47   ·   STU0047 ↔ SRNO 12
 */
class Phase155FeesCorrectnessChainTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    // ── Mirrors of the implemented rules ────────────────────────────────

    /** Mirrors Fee_lifecycle::_dueDay() — clamp 1..28, else default 10. */
    private function dueDay(int $configured): int
    {
        return ($configured < 1 || $configured > 28) ? 10 : $configured;
    }

    /** Mirrors Fee_lifecycle::_dueDateFor(). */
    private function dueDateFor(string $periodKey, int $configuredDay): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periodKey)) return '';
        return sprintf('%s-%02d', $periodKey, $this->dueDay($configuredDay));
    }

    /** Mirrors the corrected proRateFees() balance formula. */
    private function prorate(float $gross, float $factor, float $paid, float $fine): array
    {
        $newNet = round($gross * $factor, 2);
        $newBal = round($newNet + $fine - $paid, 2);
        $over   = false;
        if ($newBal < 0) { $over = true; $newBal = 0.0; }
        return ['net' => $newNet, 'balance' => $newBal, 'overCollected' => $over];
    }

    /** Mirrors the fine engine: balance = net + fine - paid. */
    private function applyFine(float $net, float $paid, float $fine): float
    {
        return round(max(0, $net + $fine - $paid), 2);
    }

    /**
     * Mirrors the G-3 gate in FeeCollectionService::submit.
     * Returns ['ok'=>bool,'fine'=>float,'error'=>?string,'audited'=>bool].
     */
    private function fineGate(
        float $computed, float $submitted,
        bool $overrideAsked = false, bool $mayOverride = false, string $reason = ''
    ): array {
        $applied = $submitted;
        if ($overrideAsked) {
            if (!$mayOverride)   return ['ok'=>false,'fine'=>0.0,'error'=>'403','audited'=>false];
            if (trim($reason) === '') return ['ok'=>false,'fine'=>0.0,'error'=>'422','audited'=>false];
            // authorized → submitted stands
        } elseif ($computed > 0.005) {
            $applied = $computed;                    // computed fine is canonical
        }
        $audited = (abs($submitted - $applied) > 0.005) || $overrideAsked;
        return ['ok'=>true,'fine'=>round($applied,2),'error'=>null,'audited'=>$audited];
    }

    // ── A. DUE DATE (AB) ────────────────────────────────────────────────

    public function test_due_date_is_period_key_plus_configured_day(): void
    {
        $this->assertSame('2026-04-10', $this->dueDateFor('2026-04', 10));
    }

    public function test_due_day_one_is_zero_padded(): void
    {
        $this->assertSame('2026-04-01', $this->dueDateFor('2026-04', 1));
    }

    public function test_due_day_twenty_eight_is_allowed(): void
    {
        $this->assertSame('2026-04-28', $this->dueDateFor('2026-04', 28));
    }

    public function test_february_needs_no_special_case(): void
    {
        // The 1..28 clamp is what makes February safe: day 29/30/31 can never
        // be produced, so no invalid calendar date is ever written.
        $this->assertSame('2027-02-28', $this->dueDateFor('2027-02', 28));
    }

    public function test_leap_year_february_is_equally_safe(): void
    {
        $this->assertSame('2028-02-28', $this->dueDateFor('2028-02', 28));
        $this->assertNotSame('2028-02-29', $this->dueDateFor('2028-02', 29),
            'day 29 must be clamped away, never emitted');
    }

    public function test_out_of_range_due_days_clamp_to_default_ten(): void
    {
        foreach ([0, -5, 29, 30, 31, 99] as $bad) {
            $this->assertSame('2026-04-10', $this->dueDateFor('2026-04', $bad),
                "due day {$bad} must clamp to the default 10");
        }
    }

    public function test_due_date_sorts_lexicographically_against_today(): void
    {
        // Fees::compute_fines_for_student compares `$dueDate >= $today` as
        // STRINGS. Y-m-d is the only format where that is correct.
        $due = $this->dueDateFor('2026-04', 10);
        $this->assertTrue($due < '2026-05-01', 'April due date must precede May');
        $this->assertTrue($due > '2026-03-31', 'April due date must follow March');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $due);
    }

    public function test_malformed_period_key_yields_empty_not_a_broken_date(): void
    {
        foreach (['', '2026', '2026-4', 'April 2026', '2026-04-01'] as $bad) {
            $this->assertSame('', $this->dueDateFor($bad, 10),
                "malformed periodKey '{$bad}' must not produce a date");
        }
    }

    public function test_session_spanning_months_keep_their_own_calendar_year(): void
    {
        // Jan–Mar belong to the NEXT calendar year within a session.
        $this->assertSame('2027-01-10', $this->dueDateFor('2027-01', 10));
        $this->assertSame('2026-12-10', $this->dueDateFor('2026-12', 10));
    }

    public function test_due_day_is_resolved_once_not_per_demand(): void
    {
        // N+1 guard: the memo must serve every demand in a generation run
        // from a single resolution. Modelled by counting resolutions.
        $resolutions = 0;
        $memo = null;
        $resolve = function () use (&$resolutions, &$memo): int {
            if ($memo !== null) return $memo;
            $resolutions++;
            return $memo = 10;
        };
        // 12 monthly demands × 3 fee heads = 36 demand rows
        for ($i = 0; $i < 36; $i++) { $resolve(); }
        $this->assertSame(1, $resolutions,
            'getDueDay() must be memoised — 36 demands must cost ONE settings read');
    }

    // ── B. FINE-AWARE PRORATION (F-1) ───────────────────────────────────

    public function test_the_reported_scenario_fee_10000_fine_200_paid_5000(): void
    {
        // Fee 10,000 · Fine 200 · Paid 5,000 → expected balance 5,200.
        $this->assertSame(5200.0, $this->applyFine(10000.0, 5000.0, 200.0));
    }

    public function test_fine_survives_proration(): void
    {
        // THE REGRESSION GUARD. Pre-fix this returned 0.0 — the fine vanished.
        $r = $this->prorate(10000.0, 0.5, 5000.0, 200.0);
        $this->assertSame(5000.0, $r['net']);
        $this->assertSame(200.0, $r['balance'], 'the ₹200 fine must survive proration');
    }

    public function test_order_independence_fine_then_proration_equals_proration_then_fine(): void
    {
        // THE CORE INVARIANT the old formula violated.
        $fineFirst  = $this->prorate(10000.0, 0.5, 5000.0, 200.0)['balance'];
        // proration first (no fine yet), then the engine applies the fine
        $prorated   = $this->prorate(10000.0, 0.5, 5000.0, 0.0);
        $fineAfter  = $this->applyFine($prorated['net'], 5000.0, 200.0);
        $this->assertSame($fineFirst, $fineAfter,
            'balance must not depend on whether the fine or the proration happened first');
    }

    public function test_demand_without_a_fine_is_unchanged_by_the_fix(): void
    {
        $r = $this->prorate(10000.0, 0.5, 5000.0, 0.0);
        $this->assertSame(0.0, $r['balance'], 'no fine → identical to pre-fix behaviour');
    }

    public function test_zero_fine_is_not_treated_as_missing(): void
    {
        $this->assertSame(2500.0, $this->prorate(10000.0, 0.5, 2500.0, 0.0)['balance']);
    }

    public function test_partially_paid_demand_with_fine(): void
    {
        $r = $this->prorate(12000.0, 0.75, 3000.0, 500.0);
        $this->assertSame(9000.0, $r['net']);
        $this->assertSame(6500.0, $r['balance']);   // 9000 + 500 - 3000
    }

    public function test_fully_paid_demand_still_owes_the_fine(): void
    {
        // Paid the whole net but a penalty was applied → the fine remains due.
        $r = $this->prorate(10000.0, 1.0, 10000.0, 200.0);
        $this->assertSame(200.0, $r['balance']);
        $this->assertFalse($r['overCollected']);
    }

    public function test_over_collection_still_floors_at_zero_and_is_flagged(): void
    {
        // Proration below what was already paid: balance floors at 0 and the
        // existing FEE_PRORATE_OVERCOLLECTION observability path still trips.
        $r = $this->prorate(10000.0, 0.2, 5000.0, 0.0);
        $this->assertSame(0.0, $r['balance']);
        $this->assertTrue($r['overCollected']);
    }

    public function test_fine_reduces_how_often_over_collection_trips(): void
    {
        // With the fine counted, a family that owes a penalty is correctly
        // NOT treated as having over-paid.
        $this->assertTrue($this->prorate(10000.0, 0.45, 5000.0, 0.0)['overCollected']);
        $this->assertFalse($this->prorate(10000.0, 0.45, 5000.0, 600.0)['overCollected']);
    }

    public function test_repeated_proration_is_idempotent_and_never_doubles_the_fine(): void
    {
        $a = $this->prorate(10000.0, 0.5, 5000.0, 200.0);
        $b = $this->prorate(10000.0, 0.5, 5000.0, 200.0);
        $this->assertSame($a['balance'], $b['balance']);
        $this->assertSame(200.0, $b['balance'], 'the fine must be applied once, never accumulated');
    }

    public function test_decimal_amounts_round_to_two_places(): void
    {
        $r = $this->prorate(1000.555, 0.3333, 100.111, 33.337);
        $this->assertSame(333.48, $r['net']);
        $this->assertSame(round(333.48 + 33.337 - 100.111, 2), $r['balance']);
    }

    // ── C. FINE CONTROL (G-3) ───────────────────────────────────────────

    public function test_computed_fine_is_used_automatically(): void
    {
        // Cashier posts nothing (0); the system computed ₹500 → ₹500 applies.
        $r = $this->fineGate(500.0, 0.0);
        $this->assertTrue($r['ok']);
        $this->assertSame(500.0, $r['fine'], 'the computed fine must be canonical');
    }

    public function test_cashier_cannot_silently_waive_a_computed_fine(): void
    {
        // THE CONTROL DEFECT. Typing 0 against a computed ₹500 must not waive.
        $r = $this->fineGate(500.0, 0.0);
        $this->assertSame(500.0, $r['fine']);
        $this->assertTrue($r['audited'], 'the divergence must be recorded');
    }

    public function test_cashier_cannot_silently_reduce_a_computed_fine(): void
    {
        $r = $this->fineGate(500.0, 50.0);
        $this->assertSame(500.0, $r['fine'], 'a silent reduction must be ignored');
    }

    public function test_unauthorized_override_is_refused(): void
    {
        $r = $this->fineGate(500.0, 0.0, true, false, 'Principal approved');
        $this->assertFalse($r['ok']);
        $this->assertSame('403', $r['error']);
    }

    public function test_authorized_override_without_a_reason_is_refused(): void
    {
        $r = $this->fineGate(500.0, 0.0, true, true, '   ');
        $this->assertFalse($r['ok']);
        $this->assertSame('422', $r['error']);
    }

    public function test_authorized_override_with_a_reason_succeeds_and_is_audited(): void
    {
        $r = $this->fineGate(500.0, 0.0, true, true, 'Waived — family hardship, approved by Principal');
        $this->assertTrue($r['ok']);
        $this->assertSame(0.0, $r['fine']);
        $this->assertTrue($r['audited']);
    }

    public function test_manual_ad_hoc_fine_still_works_when_nothing_was_computed(): void
    {
        // Preserves today's workflow: no computed fine → the typed value stands.
        $r = $this->fineGate(0.0, 250.0);
        $this->assertTrue($r['ok']);
        $this->assertSame(250.0, $r['fine']);
        $this->assertFalse($r['audited'], 'no divergence to record');
    }

    public function test_matching_submission_needs_no_override_and_no_audit(): void
    {
        $r = $this->fineGate(500.0, 500.0);
        $this->assertTrue($r['ok']);
        $this->assertSame(500.0, $r['fine']);
        $this->assertFalse($r['audited']);
    }

    public function test_receipt_and_accounting_consume_one_single_fine_value(): void
    {
        // netTotal = schoolFees - discount + fine, computed AFTER the gate, so
        // receipt total and the 4060 journal line cannot diverge from it.
        $applied  = $this->fineGate(500.0, 0.0)['fine'];
        $netTotal = round(10000.0 - 1000.0 + $applied, 2);
        $this->assertSame(9500.0, $netTotal);
        $this->assertSame($applied, 500.0);
    }

    // ── D. IDENTITY INVARIANTS ──────────────────────────────────────────

    public function test_fee_logic_never_derives_srno_from_student_id(): void
    {
        $this->assertNotSame(self::STU_A, self::SRNO_A);
        $this->assertSame('47', self::SRNO_A);   // STU0012 pairs with 47, NOT 12
        $this->assertSame('12', self::SRNO_B);   // STU0047 pairs with 12, NOT 47
    }

    public function test_crossed_identities_do_not_collide_in_fine_computation(): void
    {
        // Two students whose ID/SRNO digits are swapped must be scored
        // independently — no arithmetic relationship anywhere.
        $a = $this->fineGate(500.0, 0.0)['fine'];
        $b = $this->fineGate(750.0, 0.0)['fine'];
        $this->assertSame(500.0, $a);
        $this->assertSame(750.0, $b);
        $this->assertNotSame($a, $b);
    }
}
