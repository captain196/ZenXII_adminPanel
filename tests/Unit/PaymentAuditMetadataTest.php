<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AUDIT METADATA MUST SURVIVE THE WRITE THAT FOLLOWS IT
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Defects found auditing the first live payment (order for receipt F1):
 *
 *  D-1  verified_at was ''
 *       parent_verify_payment read the order BEFORE calling verify_payment(),
 *       then wrote status='paid' with array_merge($staleOrder, …) through a
 *       FULL firestoreSet(). Verification had written verified_at/verified_by
 *       onto that same document in between, so the paid-write reverted both to
 *       their creation-time values. The audit record of WHEN a payment was
 *       verified was destroyed by the write that recorded it as paid.
 *
 *       Every other paid-writer in the controller (webhook, and both retry
 *       paths) already wrote a delta with merge=true. This one did not.
 *
 *  D-2  bankLedgerCode/-Name were ''
 *       They only ever held an OPERATOR's till-side choice, which an online
 *       payment has none of. Accounting then resolved its own ledger and
 *       debited 1020 SBI Bank A/c, so receipt and journal disagreed about
 *       where the money went. AccountingReconciler's repair path states the
 *       invariant it needs — "must debit the SAME bank ledger the primary
 *       posting used" — and honoured it by passing that empty field, which
 *       drops it into a "first active bank ledger in the chart" fallback. That
 *       reproduces the original ONLY while chart iteration order is unchanged.
 *
 *  D-3  srNo read a single alias
 *       SIS canonicalises srNo / srNumber / "SR Number"; the receipt read only
 *       the first, while `rollNo` on the very next line already tolerated two.
 */
final class PaymentAuditMetadataTest extends TestCase
{
    private function controllerSource(): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents(APPPATH . 'controllers/Fee_management.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    // ── D-1 · VERIFIED_AT ────────────────────────────────────────────────

    public function test_the_paid_write_does_not_full_replace_from_a_stale_snapshot(): void
    {
        $src = $this->controllerSource();
        // The exact destroyer: merging the paid delta onto the pre-verification
        // snapshot and writing the whole document back.
        $this->assertStringNotContainsString(
            "\$patched = array_merge(\$order, [\n                'status'             => 'paid',",
            $src,
            'the paid-write must not rebuild the document from the stale $order snapshot'
        );
    }

    public function test_every_paid_write_on_an_order_uses_merge(): void
    {
        // Structural, and the property that actually matters: a full replace on
        // feeOnlineOrders can silently erase any field written by a concurrent
        // or intervening step, of which verified_at was merely the first casualty.
        $src = $this->controllerSource();
        preg_match_all(
            "/firestoreSet\(\s*'feeOnlineOrders'\s*,\s*\\\$orderDocId\s*,\s*(.*?)\)\s*;/s",
            $src, $m
        );
        $this->assertGreaterThanOrEqual(4, count($m[1]), 'expected at least 4 order writes');
        foreach ($m[1] as $args) {
            $this->assertMatchesRegularExpression('/,\s*true\s*$/', trim($args),
                'every feeOnlineOrders write must pass merge=true');
        }
    }

    public function test_verification_still_records_when_it_happened(): void
    {
        $ps = file_get_contents(APPPATH . 'libraries/Payment_service.php');
        // The producer side must keep writing it, or preserving it is pointless.
        $this->assertStringContainsString("'verified_at'        => \$now,", $ps);
        $this->assertStringContainsString("'verified_by'        => \$this->adminId,", $ps);
    }

    // ── D-2 · BANK LEDGER ────────────────────────────────────────────────

    public function test_the_bank_ledger_rule_has_exactly_one_definition(): void
    {
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $this->assertTrue(method_exists('Operations_accounting', 'resolveCashBankCode'));

        // The scan itself now lives in Payment_settlement_route, where it is
        // deterministic. What matters is that it exists in exactly ONE place
        // across the accounting surface — counting only inside
        // Operations_accounting would pass trivially once the code moved out,
        // which is how a "consolidated" rule quietly becomes two again.
        require_once APPPATH . 'libraries/Payment_settlement_route.php';
        $this->assertTrue(method_exists('Payment_settlement_route', 'firstActiveBankDeterministic'));

        $total = 0;
        foreach (['libraries/Operations_accounting.php',
                  'libraries/Payment_settlement_route.php',
                  'services/FeeCollectionService.php'] as $rel) {
            $total += substr_count((string) file_get_contents(APPPATH . $rel),
                "foreach (\$coa as \$code => \$acct) {");
        }
        $this->assertSame(1, $total,
            'the bank-ledger scan must exist exactly once across the accounting surface');
    }

    public function test_the_bank_fallback_is_deterministic_not_query_ordered(): void
    {
        // The defect this closes: the winner used to be whichever active bank
        // the chart's iteration reached first, so the SAME chart delivered in a
        // different order produced a DIFFERENT bank — and a reconciliation
        // repair could debit an account the original posting never touched.
        require_once APPPATH . 'libraries/Payment_settlement_route.php';

        $a = [
            '1090' => ['name' => 'Axis',  'is_bank' => true, 'status' => 'active'],
            '1020' => ['name' => 'SBI',   'is_bank' => true, 'status' => 'active'],
            '1050' => ['name' => 'HDFC',  'is_bank' => true, 'status' => 'active'],
        ];
        $b = array_reverse($a, true);   // same data, opposite order

        $ra = Payment_settlement_route::firstActiveBankDeterministic($a);
        $rb = Payment_settlement_route::firstActiveBankDeterministic($b);

        $this->assertSame($ra, $rb, 'chart order changed the answer — still order-dependent');
        $this->assertSame('1020', $ra, 'the lowest account code must win, by natural order');
    }

    public function test_a_group_or_inactive_account_is_never_selected(): void
    {
        require_once APPPATH . 'libraries/Payment_settlement_route.php';
        $coa = [
            '1000' => ['name' => 'Bank Accounts', 'is_bank' => true, 'status' => 'active', 'is_group' => true],
            '1010' => ['name' => 'Old A/c',       'is_bank' => true, 'status' => 'inactive'],
            '1020' => ['name' => 'SBI',           'is_bank' => true, 'status' => 'active'],
        ];
        // A group heading is not postable; posting to one corrupts the tree.
        $this->assertSame('1020', Payment_settlement_route::firstActiveBankDeterministic($coa));
    }

    public function test_no_active_bank_returns_null_rather_than_guessing(): void
    {
        require_once APPPATH . 'libraries/Payment_settlement_route.php';
        $this->assertNull(Payment_settlement_route::firstActiveBankDeterministic([]));
    }

    public function test_an_operator_selected_ledger_wins(): void
    {
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = [
            '1020' => ['name' => 'SBI Bank A/c', 'is_bank' => true, 'status' => 'active'],
            '1030' => ['name' => 'HDFC A/c',     'is_bank' => true, 'status' => 'active'],
        ];
        $this->assertSame('1030', Operations_accounting::resolveCashBankCode($coa, '1030', 'online'));
    }

    public function test_an_online_payment_never_lands_in_cash_in_hand_when_a_bank_exists(): void
    {
        // ACC-PM1: an online payment booked to 1010 Cash in Hand is a
        // regression that misstates cash on hand in the balance sheet.
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = [
            '1010' => ['name' => 'Cash in Hand', 'is_bank' => false, 'status' => 'active'],
            '1020' => ['name' => 'SBI Bank A/c', 'is_bank' => true,  'status' => 'active'],
        ];
        $this->assertSame('1020', Operations_accounting::resolveCashBankCode($coa, '', 'online'));
    }

    public function test_cash_still_goes_to_cash_in_hand(): void
    {
        // Control: the function is not simply "always return a bank".
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = ['1020' => ['name' => 'SBI', 'is_bank' => true, 'status' => 'active']];
        $this->assertSame('1010', Operations_accounting::resolveCashBankCode($coa, '', 'cash'));
    }

    public function test_an_inactive_bank_is_not_selected(): void
    {
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = [
            '1020' => ['name' => 'Closed A/c', 'is_bank' => true, 'status' => 'inactive'],
            '1030' => ['name' => 'HDFC A/c',   'is_bank' => true, 'status' => 'active'],
        ];
        $this->assertSame('1030', Operations_accounting::resolveCashBankCode($coa, '', 'online'));
    }

    public function test_a_stale_operator_code_absent_from_the_chart_is_not_trusted(): void
    {
        // A ledger deleted since collection must not be debited blindly.
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $coa = ['1030' => ['name' => 'HDFC A/c', 'is_bank' => true, 'status' => 'active']];
        $this->assertSame('1030', Operations_accounting::resolveCashBankCode($coa, '9999', 'online'));
    }

    public function test_no_bank_configured_falls_back_to_cash_not_to_a_guess(): void
    {
        require_once APPPATH . 'libraries/Operations_accounting.php';
        $this->assertSame('1010', Operations_accounting::resolveCashBankCode([], '', 'online'));
    }

    public function test_collection_records_the_effective_ledger_on_the_receipt(): void
    {
        // Without this the receipt keeps saying "no bank" while the journal
        // debits one, and the reconciler's CORE-1 invariant stays unenforceable.
        $fcs = file_get_contents(APPPATH . 'services/FeeCollectionService.php');
        // Resolution goes through the settlement route, which prefers the
        // school's CONFIGURED clearing/bank accounts and only then falls back
        // to a deterministic scan.
        $this->assertStringContainsString('Payment_settlement_route::resolveDebit(', $fcs);
        $this->assertStringContainsString("\$resolvedCode !== '1010'", $fcs,
            'an online payment must never be stamped with Cash in Hand');
        // And the receipt must record HOW it was chosen, or an auditor cannot
        // tell a school's explicit mapping from a system fallback.
        $this->assertStringContainsString("'bankLedgerSource' => \$__settlementSource,", $fcs);
    }

    // ── D-3 · SRNO ───────────────────────────────────────────────────────

    public function test_the_receipt_tolerates_every_sr_alias_sis_writes(): void
    {
        $fcs = file_get_contents(APPPATH . 'services/FeeCollectionService.php');
        preg_match_all("/'srNo'\s*=>\s*\(string\)\s*\(([^)]*?)\)\s*,/", $fcs, $m);
        $this->assertGreaterThanOrEqual(2, count($m[1]), 'expected both receipt-writing paths');
        foreach ($m[1] as $expr) {
            $this->assertStringContainsString("\$student['srNo']", $expr);
            $this->assertStringContainsString("\$student['srNumber']", $expr);
            $this->assertStringContainsString("\$student['SR Number']", $expr);
        }
    }
}
