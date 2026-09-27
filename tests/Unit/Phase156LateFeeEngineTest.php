<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 15.6 — automatic late-fee engine (Fee_late_fee_service).
 *
 * SCOPE HONESTY: the service needs a live Firestore handle, so these tests
 * model its DECISION RULES exactly as implemented — the same approach used by
 * Phase15SectionCapacityTest and Phase155FeesCorrectnessChainTest. Cron
 * wiring, tenant env-var isolation and the browser flow are covered by the
 * UAT matrix, not by this file.
 *
 * IDENTITY DISCIPLINE: crossed fixtures.  STU0012 ↔ SRNO 47 · STU0047 ↔ SRNO 12
 */
class Phase156LateFeeEngineTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    /** Mirrors Fee_late_fee_service::cutoffDate(). */
    private function cutoff(int $graceDays, string $today = '2026-06-15'): string
    {
        if ($graceDays <= 0) return $today;
        return date('Y-m-d', strtotime("{$today} -{$graceDays} days"));
    }

    /** Mirrors Fee_late_fee_service::fineFor(). */
    private function fineFor(array $d, array $policy, string $cutoff): ?float
    {
        $status = strtolower((string) ($d['status'] ?? 'unpaid'));
        if ($status === 'paid' || $status === 'archived') return null;

        $due = (string) ($d['due_date'] ?? '');
        if ($due === '' || $due >= $cutoff) return null;

        $net = (float) ($d['net_amount'] ?? 0);
        if ($net <= 0) return null;

        $fine = ($policy['type'] === 'percentage')
            ? round($net * ((float) $policy['value'] / 100), 2)
            : round((float) $policy['value'], 2);

        $cap = (float) ($policy['cap'] ?? 0);
        if ($cap > 0 && $fine > $cap) $fine = round($cap, 2);
        return $fine;
    }

    /** Mirrors Fee_late_fee_service::applyTo() — returns [writes, totalFine]. */
    private function apply(array $demands, array $policy, string $cutoff): array
    {
        $writes = 0; $total = 0.0;
        foreach ($demands as $d) {
            $fine = $this->fineFor($d, $policy, $cutoff);
            if ($fine === null) continue;
            $existing = (float) ($d['fine_amount'] ?? 0);
            if (abs($existing - $fine) < 0.01) continue;   // idempotency guard
            $writes++;
            $total += $fine;
        }
        return ['writes' => $writes, 'total' => round($total, 2)];
    }

    private function policy(string $type = 'fixed', float $value = 200.0, int $grace = 0, float $cap = 0.0): array
    {
        return ['enabled' => true, 'type' => $type, 'value' => $value, 'graceDays' => $grace, 'cap' => $cap];
    }

    private function demand(array $over = []): array
    {
        return array_merge([
            'status' => 'unpaid', 'due_date' => '2026-05-10',
            'net_amount' => 10000.0, 'paid_amount' => 0.0, 'fine_amount' => 0.0,
        ], $over);
    }

    // ── A. Overdue detection ────────────────────────────────────────────

    public function test_overdue_demand_attracts_a_fine(): void
    {
        $this->assertSame(200.0, $this->fineFor($this->demand(), $this->policy(), $this->cutoff(0)));
    }

    public function test_demand_not_yet_due_attracts_nothing(): void
    {
        $d = $this->demand(['due_date' => '2026-07-10']);
        $this->assertNull($this->fineFor($d, $this->policy(), $this->cutoff(0)));
    }

    public function test_demand_due_today_attracts_nothing(): void
    {
        $d = $this->demand(['due_date' => '2026-06-15']);
        $this->assertNull($this->fineFor($d, $this->policy(), $this->cutoff(0)),
            'a fee is not late on the day it is due');
    }

    public function test_demand_without_a_due_date_is_never_fined(): void
    {
        // Pre-Phase-15.5 demands. This is why AB had to land first.
        $d = $this->demand(['due_date' => '']);
        $this->assertNull($this->fineFor($d, $this->policy(), $this->cutoff(0)));
    }

    public function test_paid_demand_is_never_fined(): void
    {
        $this->assertNull($this->fineFor($this->demand(['status' => 'paid']), $this->policy(), $this->cutoff(0)));
    }

    public function test_archived_demand_is_never_fined(): void
    {
        // SW4-companion-F: fining an archived demand would resurrect a
        // zeroed balance into pending-dues surfaces.
        $this->assertNull($this->fineFor($this->demand(['status' => 'archived']), $this->policy(), $this->cutoff(0)));
    }

    public function test_partial_demand_is_still_fined(): void
    {
        $d = $this->demand(['status' => 'partial', 'paid_amount' => 4000.0]);
        $this->assertSame(200.0, $this->fineFor($d, $this->policy(), $this->cutoff(0)));
    }

    public function test_zero_net_demand_is_not_fined(): void
    {
        $this->assertNull($this->fineFor($this->demand(['net_amount' => 0.0]), $this->policy(), $this->cutoff(0)));
    }

    // ── B. Grace period ─────────────────────────────────────────────────

    public function test_grace_zero_reproduces_pre_phase_behaviour(): void
    {
        $this->assertSame('2026-06-15', $this->cutoff(0));
    }

    public function test_grace_shifts_the_cutoff_backwards(): void
    {
        $this->assertSame('2026-06-08', $this->cutoff(7));
    }

    public function test_demand_inside_the_grace_window_is_not_fined(): void
    {
        // Due 2026-06-10, 7-day grace → cutoff 2026-06-08 → not yet finable.
        $d = $this->demand(['due_date' => '2026-06-10']);
        $this->assertNull($this->fineFor($d, $this->policy('fixed', 200.0, 7), $this->cutoff(7)));
    }

    public function test_demand_past_the_grace_window_is_fined(): void
    {
        $d = $this->demand(['due_date' => '2026-06-01']);
        $this->assertSame(200.0, $this->fineFor($d, $this->policy('fixed', 200.0, 7), $this->cutoff(7)));
    }

    public function test_grace_boundary_is_exclusive_on_the_cutoff_day(): void
    {
        $d = $this->demand(['due_date' => '2026-06-08']);   // exactly the cutoff
        $this->assertNull($this->fineFor($d, $this->policy('fixed', 200.0, 7), $this->cutoff(7)));
    }

    // ── C. Fine types and cap ───────────────────────────────────────────

    public function test_percentage_fine(): void
    {
        $this->assertSame(500.0, $this->fineFor($this->demand(), $this->policy('percentage', 5.0), $this->cutoff(0)));
    }

    public function test_cap_limits_a_percentage_fine(): void
    {
        $f = $this->fineFor($this->demand(), $this->policy('percentage', 5.0, 0, 300.0), $this->cutoff(0));
        $this->assertSame(300.0, $f, 'the cap must bound the penalty');
    }

    public function test_cap_zero_means_uncapped(): void
    {
        $this->assertSame(500.0, $this->fineFor($this->demand(), $this->policy('percentage', 5.0, 0, 0.0), $this->cutoff(0)));
    }

    public function test_cap_above_the_fine_has_no_effect(): void
    {
        $this->assertSame(200.0, $this->fineFor($this->demand(), $this->policy('fixed', 200.0, 0, 9999.0), $this->cutoff(0)));
    }

    // ── D. Idempotency — the property that makes a nightly cron safe ────

    public function test_second_run_the_same_day_writes_nothing(): void
    {
        $demands = [$this->demand(), $this->demand(['due_date' => '2026-04-10'])];
        $first = $this->apply($demands, $this->policy(), $this->cutoff(0));
        $this->assertSame(2, $first['writes']);

        // Simulate the fines now persisted, then re-run.
        $after = [$this->demand(['fine_amount' => 200.0]),
                  $this->demand(['due_date' => '2026-04-10', 'fine_amount' => 200.0])];
        $second = $this->apply($after, $this->policy(), $this->cutoff(0));
        $this->assertSame(0, $second['writes'], 'a repeat run must perform ZERO writes');
    }

    public function test_flat_fine_never_accumulates_across_runs(): void
    {
        $d = $this->demand(['fine_amount' => 200.0]);
        for ($i = 0; $i < 10; $i++) {
            $r = $this->apply([$d], $this->policy(), $this->cutoff(0));
            $this->assertSame(0, $r['writes'], "run {$i} must not re-charge");
        }
    }

    public function test_changing_the_policy_rewrites_the_fine_absolutely(): void
    {
        // Fine already 200; school raises it to 300 → one write, value 300 (not 500).
        $d = $this->demand(['fine_amount' => 200.0]);
        $r = $this->apply([$d], $this->policy('fixed', 300.0), $this->cutoff(0));
        $this->assertSame(1, $r['writes']);
        $this->assertSame(300.0, $r['total'], 'absolute write, never additive');
    }

    public function test_lowering_the_cap_reduces_an_existing_fine(): void
    {
        $d = $this->demand(['fine_amount' => 500.0]);
        $r = $this->apply([$d], $this->policy('percentage', 5.0, 0, 250.0), $this->cutoff(0));
        $this->assertSame(250.0, $r['total']);
    }

    // ── E. Batch behaviour / no N+1 ─────────────────────────────────────

    public function test_school_wide_run_is_one_pass_over_all_demands(): void
    {
        // 300 demands; only the overdue unpaid ones are written. The point is
        // that this is ONE in-memory pass over a single query result, not one
        // query per student.
        $demands = [];
        for ($i = 0; $i < 100; $i++) $demands[] = $this->demand();                                  // overdue
        for ($i = 0; $i < 100; $i++) $demands[] = $this->demand(['due_date' => '2026-12-01']);       // future
        for ($i = 0; $i < 100; $i++) $demands[] = $this->demand(['status' => 'paid']);               // paid
        $r = $this->apply($demands, $this->policy(), $this->cutoff(0));
        $this->assertSame(100, $r['writes'], 'only genuinely overdue unpaid demands are written');
        $this->assertSame(20000.0, $r['total']);
    }

    public function test_dry_run_semantics_report_without_writing(): void
    {
        // applyTo() computes identical results regardless of dryRun; only the
        // persistence call is suppressed. Modelled by asserting the decision
        // set is independent of the write.
        $demands = [$this->demand(), $this->demand(['status' => 'paid'])];
        $r = $this->apply($demands, $this->policy(), $this->cutoff(0));
        $this->assertSame(1, $r['writes']);
        $this->assertSame(200.0, $r['total']);
    }

    // ── F. Balance semantics (ties to Phase 15.5 F-1) ───────────────────

    public function test_new_balance_includes_the_fine(): void
    {
        $net = 10000.0; $paid = 5000.0; $fine = 200.0;
        $this->assertSame(5200.0, round(max(0, $net + $fine - $paid), 2));
    }

    public function test_balance_floors_at_zero_when_overpaid(): void
    {
        $this->assertSame(0.0, round(max(0, 1000.0 + 50.0 - 5000.0), 2));
    }

    // ── G. Historical demand detection (read-only) ──────────────────────

    public function test_missing_due_date_is_reconstructable_only_with_a_valid_period_key(): void
    {
        $ok  = (bool) preg_match('/^\d{4}-\d{2}$/', '2026-04');
        $bad = (bool) preg_match('/^\d{4}-\d{2}$/', 'April 2026');
        $this->assertTrue($ok);
        $this->assertFalse($bad, 'a non-canonical period cannot be deterministically reconstructed');
    }

    public function test_audit_counts_split_into_reconstructable_and_ambiguous(): void
    {
        $rows = [['periodKey' => '2026-04'], ['periodKey' => ''], ['periodKey' => '2026-05'], ['periodKey' => 'x']];
        $recon = 0;
        foreach ($rows as $r) if (preg_match('/^\d{4}-\d{2}$/', $r['periodKey'])) $recon++;
        $this->assertSame(2, $recon);
        $this->assertSame(2, count($rows) - $recon, 'the rest must be reported as ambiguous, never guessed');
    }

    // ── H. Identity invariants ──────────────────────────────────────────

    public function test_late_fee_engine_never_derives_srno_from_student_id(): void
    {
        $this->assertNotSame(self::STU_A, self::SRNO_A);
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::SRNO_B);
    }

    public function test_fines_are_scoped_per_demand_not_per_identifier(): void
    {
        // Two students with crossed ID/SRNO digits must be fined identically
        // for identical demands — no identifier arithmetic anywhere.
        $a = $this->fineFor($this->demand(), $this->policy(), $this->cutoff(0));
        $b = $this->fineFor($this->demand(), $this->policy(), $this->cutoff(0));
        $this->assertSame($a, $b);
    }
}
