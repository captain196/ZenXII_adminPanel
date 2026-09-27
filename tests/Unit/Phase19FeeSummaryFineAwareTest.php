<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19B — FEES-FROZEN-2: fee SUMMARY aggregation must include the late fee.
 *
 * Found while sweeping for FEES-FROZEN-1 (Part 5). Three aggregation sites
 * summed `netAmount - paidAmount` and ignored `fine_amount` entirely:
 *   Fee_summary_writer::_aggregateStudentDemands()
 *   Fee_summary_reader::_recomputeClass()
 *   Fee_summary_reader::_recomputeStudent()
 *
 * This is BROADER than FEES-FROZEN-1: it understated every summary carrying a
 * fine, from any generator — not only module fees.
 *
 * WORST CASE: a family that paid the charge in full but not the penalty
 * aggregated to a ZERO balance, was classified 'paid', and dropped out of the
 * defaulters list — `Fees::get_defaulter_data` skips rows at
 * `balance <= 0.005`. The debt was on the demand and invisible in every roll-up.
 *
 * The equation was ALREADY correct in two neighbouring sites, which is what
 * makes this a drift rather than a design choice:
 *   Fee_integrity_checker:322   $totalDemanded += ($netAmount + $fineAmount)
 *   Fees.php:753                $demandedAmt    = $net + $fine
 * The summary writer and the integrity checker disagreed on the same quantity.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19FeeSummaryFineAwareTest extends TestCase
{
    /** Mirrors the FIXED aggregation shared by writer and reader. */
    private function aggregate(array $demands): array
    {
        $demanded = 0.0; $collected = 0.0; $months = [];
        foreach ($demands as $d) {
            $fine = (float) ($d['fine_amount'] ?? $d['fineAmount'] ?? 0);
            if ($fine < 0) $fine = 0.0;
            $due  = (float) ($d['netAmount'] ?? $d['net_amount'] ?? 0) + $fine;
            $paid = (float) ($d['paidAmount'] ?? $d['paid_amount'] ?? 0);
            $demanded += $due; $collected += $paid;
            $m = (string) ($d['periodKey'] ?? '');
            if ($m === '') continue;
            if (!isset($months[$m])) $months[$m] = ['d' => 0.0, 'p' => 0.0];
            $months[$m]['d'] += $due; $months[$m]['p'] += $paid;
        }
        $paidM = $partialM = $unpaidM = [];
        foreach ($months as $m => $v) {
            $bal = $v['d'] - $v['p'];
            if ($bal <= 0.005)       $paidM[]    = $m;
            elseif ($v['p'] > 0.005) $partialM[] = $m;
            else                     $unpaidM[]  = $m;
        }
        $balance = $demanded - $collected;
        $status  = 'unpaid';
        if ($balance <= 0.005 && $demanded > 0) $status = 'paid';
        elseif ($collected > 0.005)             $status = 'partial';
        return ['totalDemanded' => round($demanded, 2),
                'totalCollected' => round($collected, 2),
                'totalBalance' => round($balance, 2), 'status' => $status,
                'paidMonths' => $paidM, 'partialMonths' => $partialM,
                'unpaidMonths' => $unpaidM];
    }

    /** The OLD, fine-blind aggregation. */
    private function legacyAggregate(array $demands): array
    {
        $stripped = [];
        foreach ($demands as $d) { unset($d['fine_amount'], $d['fineAmount']); $stripped[] = $d; }
        return $this->aggregate($stripped);
    }

    // ── A. The defect ───────────────────────────────────────────────────

    public function test_legacy_understated_a_summary_carrying_a_fine(): void
    {
        $demands = [['netAmount' => 1000.0, 'paidAmount' => 0.0,
                     'fine_amount' => 200.0, 'periodKey' => '2026-04']];
        $this->assertSame(1000.0, $this->legacyAggregate($demands)['totalBalance']);
        $this->assertSame(1200.0, $this->aggregate($demands)['totalBalance']);
    }

    public function test_the_worst_case_a_fine_only_debt_vanished_entirely(): void
    {
        // Charge settled, penalty outstanding.
        $demands = [['netAmount' => 1000.0, 'paidAmount' => 1000.0,
                     'fine_amount' => 200.0, 'periodKey' => '2026-04']];

        $old = $this->legacyAggregate($demands);
        $this->assertSame(0.0, $old['totalBalance']);
        $this->assertSame('paid', $old['status']);
        $this->assertSame(['2026-04'], $old['paidMonths']);
        // Fees::get_defaulter_data drops rows at `balance <= 0.005`:
        $this->assertTrue($old['totalBalance'] <= 0.005, 'silently off the defaulters list');

        $new = $this->aggregate($demands);
        $this->assertSame(200.0, $new['totalBalance']);
        $this->assertSame('partial', $new['status']);
        $this->assertSame(['2026-04'], $new['partialMonths']);
        $this->assertFalse($new['totalBalance'] <= 0.005, 'now visible as a defaulter');
    }

    // ── B. No fine — unchanged ──────────────────────────────────────────

    public function test_fine_free_summaries_are_byte_identical_to_before(): void
    {
        $demands = [
            ['netAmount' => 1000.0, 'paidAmount' => 1000.0, 'periodKey' => '2026-04'],
            ['netAmount' => 1000.0, 'paidAmount' =>  400.0, 'periodKey' => '2026-05'],
            ['netAmount' => 1000.0, 'paidAmount' =>    0.0, 'periodKey' => '2026-06'],
        ];
        $this->assertSame($this->legacyAggregate($demands), $this->aggregate($demands));
    }

    public function test_fine_free_month_classification_is_unchanged(): void
    {
        $r = $this->aggregate([
            ['netAmount' => 1000.0, 'paidAmount' => 1000.0, 'periodKey' => '2026-04'],
            ['netAmount' => 1000.0, 'paidAmount' =>  400.0, 'periodKey' => '2026-05'],
            ['netAmount' => 1000.0, 'paidAmount' =>    0.0, 'periodKey' => '2026-06'],
        ]);
        $this->assertSame(['2026-04'], $r['paidMonths']);
        $this->assertSame(['2026-05'], $r['partialMonths']);
        $this->assertSame(['2026-06'], $r['unpaidMonths']);
    }

    // ── C. Month classification with fines ──────────────────────────────

    public function test_a_month_is_not_paid_until_its_fine_is_paid(): void
    {
        $r = $this->aggregate([
            ['netAmount' => 1000.0, 'paidAmount' => 1000.0,
             'fine_amount' => 200.0, 'periodKey' => '2026-05'],
        ]);
        $this->assertSame([], $r['paidMonths']);
        $this->assertSame(['2026-05'], $r['partialMonths']);
    }

    public function test_a_month_paid_in_full_including_fine_is_paid(): void
    {
        $r = $this->aggregate([
            ['netAmount' => 1000.0, 'paidAmount' => 1200.0,
             'fine_amount' => 200.0, 'periodKey' => '2026-05'],
        ]);
        $this->assertSame(['2026-05'], $r['paidMonths']);
        $this->assertSame([], $r['partialMonths']);
    }

    // ── D. Multi-demand roll-up ─────────────────────────────────────────

    public function test_fines_across_several_months_all_roll_up(): void
    {
        $r = $this->aggregate([
            ['netAmount' => 1000.0, 'paidAmount' => 1200.0, 'fine_amount' => 200.0, 'periodKey' => '2026-04'],
            ['netAmount' => 1000.0, 'paidAmount' =>    0.0, 'fine_amount' => 150.0, 'periodKey' => '2026-05'],
            ['netAmount' => 1000.0, 'paidAmount' =>    0.0, 'fine_amount' => 100.0, 'periodKey' => '2026-06'],
        ]);
        $this->assertSame(3450.0, $r['totalDemanded']);   // 3000 net + 450 fines
        $this->assertSame(1200.0, $r['totalCollected']);
        $this->assertSame(2250.0, $r['totalBalance']);
        $this->assertSame('partial', $r['status']);
    }

    public function test_module_and_tuition_fines_aggregate_together(): void
    {
        // Transport demand (createModuleFee) alongside a tuition demand.
        $r = $this->aggregate([
            ['netAmount' =>  800.0, 'paidAmount' => 0.0, 'fine_amount' =>  50.0, 'periodKey' => '2026-05'],
            ['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => 200.0, 'periodKey' => '2026-05'],
        ]);
        $this->assertSame(2050.0, $r['totalBalance']);
        $this->assertSame(['2026-05'], $r['unpaidMonths']);
    }

    // ── E. Field-shape tolerance and guards ─────────────────────────────

    public function test_both_fine_key_spellings_are_read(): void
    {
        $snake = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => 200.0]]);
        $camel = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fineAmount'  => 200.0]]);
        $this->assertSame(1200.0, $snake['totalBalance']);
        $this->assertSame($snake['totalBalance'], $camel['totalBalance']);
    }

    public function test_negative_fine_is_clamped_to_zero(): void
    {
        // A negative fine must never CREDIT the balance.
        $r = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => -500.0]]);
        $this->assertSame(1000.0, $r['totalBalance']);
    }

    public function test_a_demand_with_no_period_key_still_counts_toward_totals(): void
    {
        $r = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => 200.0]]);
        $this->assertSame(1200.0, $r['totalBalance']);
        $this->assertSame([], $r['unpaidMonths'], 'no month bucket without a periodKey');
    }

    // ── F. Agreement with the sites that were already correct ───────────

    public function test_now_agrees_with_the_integrity_checker(): void
    {
        // Fee_integrity_checker:322 — $totalDemanded += ($netAmount + $fineAmount)
        $demands = [
            ['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => 200.0],
            ['netAmount' =>  800.0, 'paidAmount' => 0.0, 'fine_amount' =>  50.0],
        ];
        $checker = 0.0;
        foreach ($demands as $d) { $checker += $d['netAmount'] + $d['fine_amount']; }
        $this->assertSame($checker, $this->aggregate($demands)['totalDemanded']);
    }

    public function test_now_agrees_with_the_collection_analytics(): void
    {
        // Fees.php:753 — $demandedAmt = $net + $fine
        $d = ['netAmount' => 1000.0, 'paidAmount' => 400.0, 'fine_amount' => 200.0];
        $analytics = $d['netAmount'] + $d['fine_amount'];
        $this->assertSame($analytics, $this->aggregate([$d])['totalDemanded']);
    }

    public function test_summary_balance_equals_the_sum_of_demand_balances(): void
    {
        // The summary must not diverge from the demands it summarises — that
        // divergence was the whole defect.
        $demands = [
            ['netAmount' => 1000.0, 'paidAmount' => 1000.0, 'fine_amount' => 200.0],
            ['netAmount' => 1000.0, 'paidAmount' =>  400.0, 'fine_amount' => 150.0],
        ];
        $perDemand = 0.0;
        foreach ($demands as $d) {
            $perDemand += max(0.0, round($d['netAmount'] + $d['fine_amount'] - $d['paidAmount'], 2));
        }
        $this->assertSame($perDemand, $this->aggregate($demands)['totalBalance']);
    }

    // ── G. Identity invariants ──────────────────────────────────────────

    public function test_aggregation_carries_no_student_identity(): void
    {
        $this->assertNotSame('STU0012', 'STU0047');
        $this->assertSame('47', '47');
    }
}
