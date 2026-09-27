<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH'))  define('APPPATH', __DIR__ . '/../../application/');
if (!function_exists('log_message')) { function log_message($l, $m) {} }

require_once __DIR__ . '/../../application/libraries/Concession_journal_mapper.php';

/**
 * P7 — populating the `concessions[]` the accounting engine already reads.
 *
 * `Operations_accounting::_buildGranularLines()` already recognises income at
 * GROSS and posts each concession as a Dr contra-revenue line across eight
 * mapped accounts. It reads `$alloc['concessions']` — and **nothing in the
 * codebase writes that key**, so the loop is a permanent no-op. This mapper is
 * the missing writer.
 *
 * TYPE STRINGS ARE ROUTING KEYS, not labels: `_resolveConcessionAccount()`
 * keyword-matches them, so each must land on the intended account. Asserted
 * against the resolver's real keyword table.
 *
 * THE OPEN QUESTION IS FLAGGED, NOT DECIDED: on a partial payment, recognise the
 * concession PROPORTIONALLY (default — derived from the engine's own "income at
 * gross" invariant) or IN FULL. Both modes are implemented and tested; a FULL
 * payment is identical under both, which is the common case.
 *
 * PURE and UNWIRED — it cannot run for real until P4 allocations are persisted.
 */
class P7ConcessionJournalMapperTest extends TestCase
{
    private function demand(array $over = []): array
    {
        return array_merge([
            'demandId' => 'D1', 'feeHead' => 'Tuition Fee',
            'grossAmount' => 600.0, 'discountAmount' => 60.0, 'netAmount' => 540.0,
        ], $over);
    }

    private function ledgerRow(array $over = []): array
    {
        return array_merge([
            'allocationId' => 'SCH_B56BB9A401_D1_E1', 'demandId' => 'D1',
            'entitlementId' => 'E1', 'allocatedAmount' => 60.0,
            'concessionType' => 'scholarship', 'status' => 'active',
        ], $over);
    }

    private function alloc(array $over = []): array
    {
        return array_merge(['demandId' => 'D1', 'fee_head' => 'Tuition Fee', 'amount' => 540.0], $over);
    }

    // ══ THE ROUTING KEYS ════════════════════════════════════════════════

    /**
     * Each label must keyword-match the account the resolver intends. Asserted
     * against the resolver's REAL table, so a future edit to either side breaks
     * here rather than silently misrouting money.
     */
    public function test_type_labels_route_to_the_intended_accounts(): void
    {
        $resolver = file_get_contents(APPPATH . 'libraries/Operations_accounting.php');
        $expect = [
            'scholarship' => ['4991', ['merit', 'scholarship']],
            'sibling'     => ['4993', ['sibling']],
            'staff_ward'  => ['4994', ['staff']],
            'rte_ews'     => ['4997', ['government', 'govt', 'rte', 'ews', 'waiver']],
        ];
        foreach ($expect as $type => [$account, $keywords]) {
            $label = strtolower(Concession_journal_mapper::typeLabel($type));
            $hit = false;
            foreach ($keywords as $kw) { if (strpos($label, $kw) !== false) { $hit = true; break; } }
            $this->assertTrue($hit,
                "'{$type}' → '{$label}' must contain a keyword routing it to {$account}");
            $this->assertStringContainsString("'{$account}' => [", $resolver,
                "account {$account} must still exist in the resolver's table");
        }
    }

    /** An unknown or blank type falls back to the generic contra-revenue label. */
    public function test_an_unknown_type_falls_back_to_the_generic_label(): void
    {
        $this->assertSame('Fee Concession', Concession_journal_mapper::typeLabel('other'));
        $this->assertSame('Fee Concession', Concession_journal_mapper::typeLabel(''));
        $this->assertSame('Fee Concession', Concession_journal_mapper::typeLabel('nonsense'));
    }

    /** The generic label must NOT accidentally keyword-match a specific account. */
    public function test_the_generic_label_does_not_misroute(): void
    {
        $label = strtolower(Concession_journal_mapper::typeLabel('other'));
        foreach (['merit', 'scholarship', 'sibling', 'staff', 'government', 'rte', 'ews',
                  'waiver', 'disability', 'need'] as $kw) {
            $this->assertStringNotContainsString($kw, $label,
                "the generic label must not collide with the '{$kw}' account");
        }
    }

    // ══ FULL PAYMENT — THE COMMON CASE ══════════════════════════════════

    /** Paying the full net recognises the whole concession, income back to gross. */
    public function test_a_full_payment_recognises_the_whole_concession(): void
    {
        $out = Concession_journal_mapper::attach(
            [$this->alloc()], [$this->ledgerRow()], ['D1' => $this->demand()]);

        $this->assertCount(1, $out[0]['concessions']);
        $this->assertSame('Merit Scholarship', $out[0]['concessions'][0]['type']);
        $this->assertSame(60.0, $out[0]['concessions'][0]['amount']);
        $this->assertSame(600.0, $out[0]['amount'] + $out[0]['concessions'][0]['amount'],
            'Cr income = paid 540 + concession 60 = GROSS 600');
        $this->assertNull(Concession_journal_mapper::recognitionIncoherence($out[0], $this->demand()));
    }

    /** Both recognition modes agree on a full payment — the common case. */
    public function test_both_modes_agree_on_a_full_payment(): void
    {
        $prop = Concession_journal_mapper::attach([$this->alloc()], [$this->ledgerRow()],
            ['D1' => $this->demand()], Concession_journal_mapper::RECOGNITION_PROPORTIONAL);
        $full = Concession_journal_mapper::attach([$this->alloc()], [$this->ledgerRow()],
            ['D1' => $this->demand()], Concession_journal_mapper::RECOGNITION_FULL);
        $this->assertSame($prop[0]['concessions'], $full[0]['concessions']);
    }

    // ══ PARTIAL PAYMENT — THE FLAGGED QUESTION ══════════════════════════

    /** PROPORTIONAL: half the net paid ⇒ half the concession recognised. */
    public function test_proportional_recognition_on_a_partial_payment(): void
    {
        $out = Concession_journal_mapper::attach(
            [$this->alloc(['amount' => 270.0])], [$this->ledgerRow()], ['D1' => $this->demand()]);
        $this->assertSame(30.0, $out[0]['concessions'][0]['amount'], 'half of 60');
        $this->assertSame(300.0, $out[0]['amount'] + $out[0]['concessions'][0]['amount'],
            'Cr income = the gross share of what was actually collected');
        $this->assertNull(Concession_journal_mapper::recognitionIncoherence($out[0], $this->demand()));
    }

    /** FULL: the whole concession is recognised on the first payment. */
    public function test_full_recognition_on_a_partial_payment(): void
    {
        $out = Concession_journal_mapper::attach(
            [$this->alloc(['amount' => 270.0])], [$this->ledgerRow()], ['D1' => $this->demand()],
            Concession_journal_mapper::RECOGNITION_FULL);
        $this->assertSame(60.0, $out[0]['concessions'][0]['amount']);
        $this->assertNull(Concession_journal_mapper::recognitionIncoherence(
            $out[0], $this->demand(), Concession_journal_mapper::RECOGNITION_FULL));
    }

    /** Two instalments under PROPORTIONAL recognise the concession exactly once. */
    public function test_two_proportional_instalments_recognise_the_concession_once(): void
    {
        $a = Concession_journal_mapper::attach([$this->alloc(['amount' => 270.0])],
            [$this->ledgerRow()], ['D1' => $this->demand()]);
        $b = Concession_journal_mapper::attach([$this->alloc(['amount' => 270.0])],
            [$this->ledgerRow()], ['D1' => $this->demand()]);
        $total = $a[0]['concessions'][0]['amount'] + $b[0]['concessions'][0]['amount'];
        $this->assertSame(60.0, round($total, 2),
            'the concession must not be double-recognised across instalments');
    }

    /** Overpayment cannot recognise more than the concession. */
    public function test_recognition_is_clamped_at_the_full_concession(): void
    {
        $out = Concession_journal_mapper::attach(
            [$this->alloc(['amount' => 5000.0])], [$this->ledgerRow()], ['D1' => $this->demand()]);
        $this->assertSame(60.0, $out[0]['concessions'][0]['amount']);
    }

    // ══ LEDGER SEMANTICS ════════════════════════════════════════════════

    /** A REVERSED allocation must never post contra-revenue. */
    public function test_a_reversed_allocation_is_not_recognised(): void
    {
        $out = Concession_journal_mapper::attach([$this->alloc()],
            [$this->ledgerRow(['status' => 'reversed'])], ['D1' => $this->demand()]);
        $this->assertArrayNotHasKey('concessions', $out[0],
            'an unwound grant must not keep posting to the ledger');
    }

    /** Stacked grants become separate, individually-routed journal lines. */
    public function test_stacked_grants_become_separate_lines(): void
    {
        $rows = [
            $this->ledgerRow(['allocationId' => 'A1', 'entitlementId' => 'E1',
                              'allocatedAmount' => 60.0, 'concessionType' => 'scholarship']),
            $this->ledgerRow(['allocationId' => 'A2', 'entitlementId' => 'E2',
                              'allocatedAmount' => 30.0, 'concessionType' => 'sibling']),
        ];
        $d = $this->demand(['discountAmount' => 90.0, 'netAmount' => 510.0]);
        $out = Concession_journal_mapper::attach([$this->alloc(['amount' => 510.0])], $rows, ['D1' => $d]);

        $this->assertCount(2, $out[0]['concessions']);
        $this->assertSame(['Merit Scholarship', 'Sibling Discount'],
            array_column($out[0]['concessions'], 'type'),
            'each grant routes to its OWN account — not merged into one line');
        $this->assertSame(600.0, 510.0 + 60.0 + 30.0);
        $this->assertNull(Concession_journal_mapper::recognitionIncoherence($out[0], $d));
    }

    /** Each line carries provenance back to the grant that caused it. */
    public function test_each_line_is_traceable_to_its_grant(): void
    {
        $out = Concession_journal_mapper::attach([$this->alloc()], [$this->ledgerRow()],
            ['D1' => $this->demand()]);
        $this->assertSame('E1', $out[0]['concessions'][0]['entitlementId']);
        $this->assertSame('SCH_B56BB9A401_D1_E1', $out[0]['concessions'][0]['allocationId']);
    }

    // ══ DEGENERATE AND UNDISCOUNTED CASES ═══════════════════════════════

    /** An undiscounted allocation is returned untouched — the common path. */
    public function test_an_allocation_with_no_ledger_rows_is_untouched(): void
    {
        $in  = [$this->alloc(['demandId' => 'D9', 'amount' => 200.0])];
        $out = Concession_journal_mapper::attach($in, [], []);
        $this->assertSame($in, $out, 'byte-identical when there is nothing to recognise');
    }

    /** A zero-value or zero-payment case emits no line — the engine skips zeros. */
    public function test_zero_cases_emit_no_line(): void
    {
        $z = Concession_journal_mapper::attach([$this->alloc(['amount' => 0.0])],
            [$this->ledgerRow()], ['D1' => $this->demand()]);
        $this->assertArrayNotHasKey('concessions', $z[0]);

        $n = Concession_journal_mapper::attach([$this->alloc()],
            [$this->ledgerRow(['allocatedAmount' => 0.0])], ['D1' => $this->demand()]);
        $this->assertArrayNotHasKey('concessions', $n[0]);
    }

    /** Rows for a DIFFERENT demand must not leak onto this allocation. */
    public function test_rows_for_another_demand_do_not_leak(): void
    {
        $out = Concession_journal_mapper::attach([$this->alloc()],
            [$this->ledgerRow(['demandId' => 'D2'])], ['D1' => $this->demand()]);
        $this->assertArrayNotHasKey('concessions', $out[0]);
    }

    // ══ SHAPE, PURITY AND INERTNESS ═════════════════════════════════════

    /** The emitted shape must match what the engine documents and reads. */
    public function test_the_emitted_shape_matches_the_engine_contract(): void
    {
        $out = Concession_journal_mapper::attach([$this->alloc()], [$this->ledgerRow()],
            ['D1' => $this->demand()]);
        $c = $out[0]['concessions'][0];
        $this->assertArrayHasKey('type', $c);
        $this->assertArrayHasKey('amount', $c);
        $this->assertIsString($c['type']);
        $this->assertIsFloat($c['amount']);

        $eng = file_get_contents(APPPATH . 'libraries/Operations_accounting.php');
        $this->assertStringContainsString("\$conc['type']", $eng);
        $this->assertStringContainsString("\$conc['amount']", $eng);
        $this->assertStringContainsString("\$alloc['concessions']", $eng,
            'the engine must still read the key this mapper writes');
    }

    public function test_the_mapper_is_pure(): void
    {
        $src = file_get_contents(APPPATH . 'libraries/Concession_journal_mapper.php');
        foreach (['firestore', 'firebase', 'get_instance', 'load->', 'query('] as $v) {
            $this->assertStringNotContainsString($v, $src, "must stay pure: found '{$v}'");
        }
    }

    /** Unwired — it cannot run for real until P4 allocations are persisted. */
    public function test_the_mapper_has_no_callers_yet(): void
    {
        $callers = [];
        foreach (array_merge(glob(APPPATH . 'controllers/*.php'),
                             glob(APPPATH . 'libraries/*.php'),
                             glob(APPPATH . 'services/*.php')) as $f) {
            if (basename($f) === 'Concession_journal_mapper.php') continue;
            if (strpos(file_get_contents($f), 'Concession_journal_mapper') !== false) {
                $callers[] = basename($f);
            }
        }
        $this->assertSame([], $callers,
            'wiring this changes live accounting output and needs its own authorization: '
            . implode(', ', $callers));
    }

    /** And live accounting is still gated by its own flag, untouched. */
    public function test_the_accounting_flag_still_gates_the_engine(): void
    {
        $this->assertStringContainsString("config->item('concessions_enabled')",
            file_get_contents(APPPATH . 'libraries/Operations_accounting.php'),
            'the engine must remain flag-gated — this mapper does not change that');
    }
}
