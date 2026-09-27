<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A RECEIPT MUST DESCRIBE THE PAYMENT, NOT THE FEE CHART
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT (found on the first live payment, receipt F1, 2026-08-26) ──
 * `feeBreakdown` was computed from the class fee STRUCTURE. A ₹700 payment
 * that settled ID Card Replacement ₹350 + Tuition Fee ₹350 produced a
 * receipt — and a printed PDF — reading:
 *
 *     Tuition Fee    · Monthly     Rs.   600.00
 *     Library Fee    · Monthly     Rs.    50.00
 *     Transport Fee  · Monthly     Rs.   200.00
 *     Admission Fee  · Annual      Rs. 1,000.00
 *     Net Paid                     Rs.   700.00
 *
 * Line items summing to ₹1,850 printed directly above a ₹700 total, three
 * heads the receipt never touched, and the ID Card Replacement charge that
 * WAS paid missing entirely.
 *
 * ── WHY IT MATTERS BEYOND COSMETICS ──────────────────────────────────────
 * A parent cannot reconcile it and a school office cannot defend it. Worse,
 * ad-hoc charges (Student_charge_service: ID cards, transport add-ons, fines)
 * exist in NO fee structure, so a structure-derived breakdown cannot represent
 * them by construction — it omits precisely the charges most likely to be
 * queried.
 *
 * The money itself was always correct: demands cleared, allocations totalled
 * 700, and the journal credited Misc Income 350 + Tuition 350. Only the
 * receipt's description of it was wrong — which is why no balance check
 * caught it, and why this test asserts the DESCRIPTION.
 */
final class ReceiptBreakdownFromAllocationsTest extends TestCase
{
    /** The private helper under test, reached without booting CodeIgniter. */
    private function breakdown(array $allocations): array
    {
        require_once APPPATH . 'services/FeeCollectionService.php';
        $m = new ReflectionMethod('FeeCollectionService', '_breakdownFromAllocations');
        $m->setAccessible(true);
        return $m->invoke(null, $allocations);
    }

    /** The exact allocation set behind receipt F1. */
    private function f1Allocations(): array
    {
        return [
            ['demand_id' => 'DEM_A', 'fee_head' => 'ID Card Replacement', 'period' => 'April 2026', 'allocated' => 350.0],
            ['demand_id' => 'DEM_B', 'fee_head' => 'Tuition Fee',         'period' => 'April 2026', 'allocated' => 350.0],
        ];
    }

    public function test_the_breakdown_reproduces_the_real_payment(): void
    {
        $out = $this->breakdown($this->f1Allocations());

        $this->assertSame(
            [
                ['head' => 'ID Card Replacement', 'amount' => '350.00'],
                ['head' => 'Tuition Fee',         'amount' => '350.00'],
            ],
            $out
        );
    }

    public function test_the_line_items_sum_to_the_amount_paid(): void
    {
        // The single property whose violation was visible on the printed PDF.
        $out   = $this->breakdown($this->f1Allocations());
        $total = array_sum(array_map(fn($r) => (float) $r['amount'], $out));
        $this->assertSame(700.00, round($total, 2));
    }

    public function test_an_ad_hoc_charge_is_not_dropped(): void
    {
        // ID Card Replacement appears in no fee structure. Under the old
        // structure-driven build it could never appear; here it must.
        $heads = array_column($this->breakdown($this->f1Allocations()), 'head');
        $this->assertContains('ID Card Replacement', $heads);
    }

    public function test_heads_never_touched_by_this_receipt_are_absent(): void
    {
        // Library / Transport / Admission are on the class chart but were not
        // paid. A receipt lists what it settled.
        $heads = array_column($this->breakdown($this->f1Allocations()), 'head');
        $this->assertNotContains('Library Fee', $heads);
        $this->assertNotContains('Transport Fee', $heads);
        $this->assertNotContains('Admission Fee', $heads);
    }

    public function test_repeated_heads_across_periods_are_summed(): void
    {
        // One receipt can settle several demands under the same head.
        $out = $this->breakdown([
            ['fee_head' => 'Tuition Fee', 'allocated' => 350.0],
            ['fee_head' => 'Tuition Fee', 'allocated' => 275.5],
        ]);
        $this->assertSame([['head' => 'Tuition Fee', 'amount' => '625.50']], $out);
    }

    public function test_zero_and_headless_rows_are_skipped(): void
    {
        // A zero row carries no information on a receipt; a headless one
        // cannot be rendered. Neither should become a blank line.
        $out = $this->breakdown([
            ['fee_head' => 'Tuition Fee', 'allocated' => 350.0],
            ['fee_head' => 'Late Fine',   'allocated' => 0.0],
            ['fee_head' => '',            'allocated' => 99.0],
        ]);
        $this->assertSame([['head' => 'Tuition Fee', 'amount' => '350.00']], $out);
    }

    public function test_an_empty_allocation_set_yields_no_rows(): void
    {
        // Callers rely on this: an empty result means "do not overwrite the
        // stored breakdown", so it must not return a phantom row.
        $this->assertSame([], $this->breakdown([]));
    }

    public function test_paise_are_preserved_not_rounded_away(): void
    {
        $out = $this->breakdown([['fee_head' => 'Tuition Fee', 'allocated' => 349.99]]);
        $this->assertSame('349.99', $out[0]['amount']);
    }

    public function test_the_writer_no_longer_takes_the_breakdown_from_the_structure(): void
    {
        // Control on the wiring: the helper being correct is useless if the
        // receipt still persists the structure-derived value. Both the batch
        // and the sequential path must feed from allocations.
        $src = file_get_contents(APPPATH . 'services/FeeCollectionService.php');
        $this->assertSame(2, substr_count($src, 'self::_breakdownFromAllocations('),
            'both the batch and sequential receipt paths must rebuild from allocations');
        $this->assertStringContainsString("\$patch['feeBreakdown'] = \$breakdownFromAllocations;", $src,
            'the sequential path must patch feeBreakdown alongside allocated_amount');
    }
}
