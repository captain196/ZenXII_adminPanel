<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE BILLING → PAYMENT BOUNDARY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Payment must SETTLE what billing decided. It must never decide anything
 * about billing itself, and it must never describe a payment using the class
 * fee chart.
 *
 *   Billing (demand generation, entitlements, student charges)
 *        ↓   feeDemands   ← the only authority payment reads
 *   Payment (order, verification, claim)
 *        ↓   feeReceiptAllocations   ← the only authority for what was settled
 *   Receipt · Accounting · Reconciliation
 *
 * ── THE DEFECT THIS CLOSES (found 2026-08-26, after F1) ──────────────────
 * The chart-derived breakdown was removed from the receipt in an earlier fix,
 * but it survived as a FALLBACK: both collection paths kept
 *
 *     if (!empty($breakdownFromAllocations)) { $breakdown = ...; }
 *
 * so an empty allocation set left the previous, fee-chart-derived value in
 * place — and that value was written to the receipt. The F1 shape could still
 * occur on the one edge case nobody exercises: line items drawn from the class
 * chart, summing to something other than the amount paid, with ad-hoc charges
 * missing because they exist in no chart by construction.
 *
 * The assignment is now unconditional and the chart-reading blocks are gone.
 * An empty breakdown is honest; a chart-derived one is a false statement about
 * what the payment settled.
 */
final class BillingPaymentBoundaryContractTest extends TestCase
{
    private function stripped(string $rel): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . $rel)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    // ── PAYMENT MUST NOT GENERATE BILLING ────────────────────────────────

    public function test_payment_never_creates_a_demand(): void
    {
        // Payment settles demands. A payment path that can mint one is a
        // second billing engine, however small.
        foreach (['controllers/Fee_management.php', 'services/FeeCollectionService.php'] as $rel) {
            $src = $this->stripped($rel);
            $this->assertDoesNotMatchRegularExpression(
                "/firestoreSet\(\s*'feeDemands'/", $src,
                "{$rel} writes a demand document directly — billing owns that"
            );
            foreach (['createModuleFee', 'generateDemand', 'createDemand'] as $minter) {
                $this->assertStringNotContainsString($minter . '(', $src,
                    "{$rel} calls {$minter}() — payment must not generate billing");
            }
        }
    }

    public function test_demands_are_read_through_the_one_authoritative_method(): void
    {
        // Both the order path and the allocation path must consult the same
        // reader, so they cannot disagree about what is owed.
        foreach (['controllers/Fee_management.php', 'services/FeeCollectionService.php'] as $rel) {
            $this->assertStringContainsString('demandsForStudentStrict(', $this->stripped($rel),
                "{$rel} no longer reads demands through the strict reader");
        }
    }

    // ── RECEIPT TRUTH MUST COME FROM ALLOCATIONS, NEVER THE CHART ────────

    public function test_the_collection_path_no_longer_reads_the_fee_chart_for_a_receipt(): void
    {
        $src = $this->stripped('services/FeeCollectionService.php');

        // The chart-derived builders are gone. `feeStructures` may still appear
        // once, as a preload map entry, but nothing may CONSUME its feeHeads to
        // build receipt content.
        $this->assertStringNotContainsString("\$struct['feeHeads']", $src,
            'a fee-chart head list is being read again in the collection path');
        $this->assertDoesNotMatchRegularExpression(
            "/\\\$breakdown\[\]\s*=\s*\['head'/", $src,
            'a breakdown row is being built from the fee chart again'
        );
    }

    public function test_the_breakdown_assignment_is_unconditional(): void
    {
        // The precise defect: a !empty() guard here silently preserves a
        // chart-derived value.
        $src = $this->stripped('services/FeeCollectionService.php');
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*!empty\(\s*\$breakdownFromAllocations\s*\)\s*\)/', $src,
            'the allocation-derived breakdown is conditional again — an empty '
            . 'allocation set would leave a stale breakdown on the receipt'
        );
        $this->assertSame(2, substr_count($src, 'self::_breakdownFromAllocations('),
            'both collection paths must build the breakdown from allocations');
    }

    public function test_an_empty_allocation_set_blanks_the_breakdown(): void
    {
        // Behavioural: the helper returns nothing rather than inventing a row,
        // and the caller now writes that nothing straight through.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $m = new ReflectionMethod('FeeCollectionService', '_breakdownFromAllocations');
        $m->setAccessible(true);
        $this->assertSame([], $m->invoke(null, []));
        $this->assertSame([], $m->invoke(null, [['fee_head' => 'Tuition Fee', 'allocated' => 0]]));
    }

    public function test_an_ad_hoc_charge_survives_the_boundary(): void
    {
        // The case a chart can never represent. Student Charges and
        // Entitlements both produce demands with heads that exist in no
        // structure; the receipt has to show them.
        require_once APPPATH . 'services/FeeCollectionService.php';
        $m = new ReflectionMethod('FeeCollectionService', '_breakdownFromAllocations');
        $m->setAccessible(true);
        $rows = $m->invoke(null, [
            ['fee_head' => 'ID Card Replacement', 'allocated' => 350.0],
            ['fee_head' => 'Tuition Fee',         'allocated' => 350.0],
        ]);
        $this->assertSame(['ID Card Replacement', 'Tuition Fee'], array_column($rows, 'head'));
        $this->assertSame(700.0, array_sum(array_map(fn($r) => (float) $r['amount'], $rows)));
    }

    // ── NO PROVIDER NAME MAY REACH BILLING OR ACCOUNTING ─────────────────

    public function test_no_gateway_name_is_hardcoded_into_billing_or_accounting(): void
    {
        // The architecture requirement: a second gateway must not require
        // touching the financial engine.
        foreach (['services/FeeCollectionService.php', 'libraries/Operations_accounting.php'] as $rel) {
            $src = $this->stripped($rel);
            foreach (['razorpay', 'cashfree', 'payu', 'stripe', 'paytm'] as $provider) {
                $this->assertDoesNotMatchRegularExpression(
                    "/['\"]" . $provider . "['\"]/i", $src,
                    "{$rel} names the provider '{$provider}' — billing and accounting "
                    . 'must be provider-neutral'
                );
            }
        }
    }

    public function test_the_ledger_is_chosen_by_configuration_not_by_provider_name(): void
    {
        // Settlement routing is keyed on a school+provider DOCUMENT, so adding
        // a gateway is data, not code.
        require_once APPPATH . 'libraries/Payment_settlement_route.php';
        $src = $this->stripped('libraries/Payment_settlement_route.php');
        foreach (['razorpay', 'cashfree'] as $provider) {
            $this->assertDoesNotMatchRegularExpression("/['\"]" . $provider . "['\"]/i", $src,
                'the settlement resolver knows a provider by name');
        }
        $this->assertStringContainsString('paymentSettlementRoutes', $src);
    }
}
