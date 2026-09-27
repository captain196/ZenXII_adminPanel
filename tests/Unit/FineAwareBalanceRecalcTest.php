<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');

/**
 * FINE-AWARE BALANCE — _recalculateStudentUnpaidDemands().
 *
 * The defect: recalculation re-derived `balance` from the net alone, so a late
 * fee already posted to the demand survived on the document but stopped being
 * owed — recorded, yet uncollectable. Same class as FEES-FROZEN-1, fixed in
 * Fee_lifecycle and never propagated here.
 *
 * Canonical formula, agreed by four independent sites:  net + fine − paid.
 */
class FineAwareBalanceRecalcTest extends TestCase
{
    /** Mirrors the production eligibility guards and balance derivation. */
    private function recalc(array $demand, float $newAmount, float $discount = 0.0): ?array
    {
        if (($demand['status'] ?? '') !== 'unpaid')      return null;   // unpaid only
        if (!empty($demand['concessionApplied']))        return null;   // concession skipped
        if ($newAmount <= 0)                             return null;   // removed head
        if ($discount > $newAmount) $discount = $newAmount;

        $net = round($newAmount - $discount, 2);

        $fine = (float) ($demand['fine_amount'] ?? $demand['fineAmount'] ?? 0);
        if ($fine < 0) $fine = 0.0;
        $paid = (float) ($demand['paid_amount'] ?? $demand['paidAmount'] ?? 0);
        if ($paid < 0) $paid = 0.0;

        if (abs((float) ($demand['net_amount'] ?? 0) - $net) < 0.01) return null;  // no-op

        return [
            'original_amount' => round($newAmount, 2),
            'discount_amount' => round($discount, 2),
            'net_amount'      => $net,
            'balance'         => round($net + $fine - $paid, 2),
        ];
    }

    private function d(array $over = []): array
    {
        return array_merge(['status' => 'unpaid', 'net_amount' => 1000.0,
                            'paid_amount' => 0.0], $over);
    }

    // ══ 1-3 · the fine ════════════════════════════════════════════════════

    public function test_1_zero_fine_balance_equals_net(): void
    {
        $p = $this->recalc($this->d(['fine_amount' => 0.0]), 1200.0);
        $this->assertSame(1200.0, $p['net_amount']);
        $this->assertSame(1200.0, $p['balance']);
    }

    /** THE defect: a posted fine must remain payable. */
    public function test_2_a_positive_fine_is_preserved_in_the_balance(): void
    {
        $p = $this->recalc($this->d(['fine_amount' => 200.0]), 1200.0);
        $this->assertSame(1200.0, $p['net_amount']);
        $this->assertSame(1400.0, $p['balance'],
            'pre-fix this was 1200.00 — the fine was recorded but uncollectable');
    }

    public function test_2b_the_camelCase_spelling_is_also_honoured(): void
    {
        $p = $this->recalc($this->d(['fineAmount' => 150.0]), 1200.0);
        $this->assertSame(1350.0, $p['balance'],
            'the engine writes fine_amount, other paths emit fineAmount');
    }

    public function test_3_absent_or_null_fine_introduces_nothing(): void
    {
        foreach ([[], ['fine_amount' => null], ['fine_amount' => 0]] as $over) {
            $p = $this->recalc($this->d($over), 1200.0);
            $this->assertSame(1200.0, $p['balance'], 'no artificial fine may appear');
        }
    }

    public function test_a_negative_fine_is_clamped_not_subtracted(): void
    {
        $p = $this->recalc($this->d(['fine_amount' => -50.0]), 1200.0);
        $this->assertSame(1200.0, $p['balance']);
    }

    // ══ 4-7 · eligibility guards unchanged ════════════════════════════════

    public function test_4_concession_demands_remain_skipped(): void
    {
        $this->assertNull($this->recalc($this->d(['concessionApplied' => 100.0]), 1200.0));
    }

    public function test_5_6_7_partial_paid_and_archived_remain_untouched(): void
    {
        foreach (['partial', 'paid', 'archived'] as $status) {
            $this->assertNull($this->recalc($this->d(['status' => $status]), 1200.0),
                "{$status} demands must not be recalculated");
        }
    }

    public function test_a_removed_fee_head_does_not_produce_a_patch(): void
    {
        $this->assertNull($this->recalc($this->d(), 0.0));
    }

    // ══ 8-12 · write discipline ═══════════════════════════════════════════

    public function test_8_an_unchanged_amount_writes_nothing(): void
    {
        $this->assertNull($this->recalc($this->d(['net_amount' => 1200.0]), 1200.0),
            'a no-op must not generate a write');
    }

    public function test_9_10_11_12_only_the_intended_fields_change(): void
    {
        $p = $this->recalc($this->d(['fine_amount' => 200.0]), 1200.0);

        $this->assertSame(['original_amount', 'discount_amount', 'net_amount', 'balance'],
            array_keys($p), 'exactly the four financial fields');

        // paidAmount, fineAmount and every identity field are absent from the
        // patch, so they cannot be erased or moved.
        foreach (['paid_amount', 'paidAmount', 'fine_amount', 'fineAmount', 'status',
                  'demandId', 'feeHead', 'feeHeadId', 'canonicalFeeHeadId', 'feeHeadRef',
                  'category', 'categorySource', 'periodKey', 'dueDate'] as $f) {
            $this->assertArrayNotHasKey($f, $p);
        }
    }

    public function test_discount_is_capped_at_the_gross(): void
    {
        $p = $this->recalc($this->d(['fine_amount' => 100.0]), 500.0, 900.0);
        $this->assertSame(0.0, $p['net_amount']);
        $this->assertSame(100.0, $p['balance'], 'the fine still stands on a fully discounted head');
    }

    // ══ 13-15 · earlier phases intact ═════════════════════════════════════

    public function test_13_14_15_phases_1_2_3_remain_intact(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString(
            '($haveKeys[$dedupKey] ?? []) + ($haveKeys[$dedupKeyByName] ?? [])', $src);   // Phase 1
        $this->assertStringContainsString('ACC_FEE_DEMAND_IDENTITY_CONFLICT', $src);       // Phase 2

        $code = '';
        foreach (token_get_all($src) as $t) {
            $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
        }
        $s = strpos($code, 'function save_updated_fees(');
        $save = substr($code, $s, strpos($code, 'private function', $s + 10) - $s);
        $this->assertStringNotContainsString('_processGenerationJob', $save);             // Phase 3
    }

    public function test_the_production_site_uses_the_canonical_formula(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString(
            "'balance'         => round(\$netAmount + \$fineSoFar - \$paidSoFar, 2),", $src);
        $this->assertStringContainsString(
            "\$fineSoFar = (float) (\$demand['fine_amount'] ?? \$demand['fineAmount'] ?? 0);", $src);
    }

    /** Nothing from a later phase may have leaked in. */
    public function test_scope_held(): void
    {
        $src = file_get_contents(APPPATH . 'controllers/Fees.php');
        $this->assertStringContainsString('$this->fsTxn->newDemandId()', $src,
            'Phase 4 deterministic ids are NOT part of this fix');
        $this->assertStringContainsString('_recalculateStudentUnpaidDemands', $src,
            'the function must NOT be removed');
    }
}
