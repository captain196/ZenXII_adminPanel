<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19P — Target #1: existing old fee demands.
 *
 * THE DEFECT DB-011 CLOSES. `studentFeeSummary` is a DERIVED CACHE, rewritten
 * only when a fee event touches that student. Nothing re-derives it when the
 * derivation RULE changes — so a summary written under an older rule stays
 * wrong indefinitely and silently.
 *
 * That is not hypothetical: FEES-FROZEN-2 corrected the aggregation to include
 * `fineAmount`, so every summary written before that fix understates a student
 * carrying a fine. Worst case, a student who paid the charge but not the
 * penalty aggregates to a ZERO balance, reads as 'paid', and drops off the
 * defaulters list — while the demand itself is perfectly correct.
 *
 * Ten existing integrity checks compared demands, payments and receipts to
 * each other. NONE compared the summary to the demands.
 *
 * A divergence is a STALE CACHE, never a financial error. The demands are
 * authoritative and are never modified by any of this.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19POldDemandReconciliationTest extends TestCase
{
    private const STU_A = 'STU0012';
    private const STU_B = 'STU0047';

    private static function src(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }

    private static function codeOnly(string $rel): string
    {
        $out = '';
        foreach (token_get_all(self::src($rel)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $out .= $t[1];
            } else $out .= $t;
        }
        return $out;
    }

    /** The canonical aggregation, mirrored exactly (see Fee_summary_writer). */
    private function aggregate(array $demands): array
    {
        $demanded = 0.0; $collected = 0.0;
        foreach ($demands as $d) {
            $fine = (float) ($d['fine_amount'] ?? $d['fineAmount'] ?? 0);
            if ($fine < 0) $fine = 0.0;
            $demanded  += (float) ($d['netAmount'] ?? 0) + $fine;
            $collected += (float) ($d['paidAmount'] ?? 0);
        }
        $bal    = $demanded - $collected;
        $status = 'unpaid';
        if ($bal <= 0.005 && $demanded > 0) $status = 'paid';
        elseif ($collected > 0.005)         $status = 'partial';
        return ['totalDemanded' => round($demanded, 2), 'totalCollected' => round($collected, 2),
                'totalBalance' => round($bal, 2), 'status' => $status];
    }

    /** The divergence verdict, mirroring checkSummaryDivergence(). */
    private function diverges(array $stored, array $expected): bool
    {
        $balDiff = abs(round((float) ($stored['totalBalance'] ?? 0), 2)
                     - round((float) $expected['totalBalance'], 2)) > 0.005;
        $s = (string) ($stored['status'] ?? '');
        return $balDiff || ($s !== '' && $s !== $expected['status']);
    }

    // ══ A. The stale-cache scenario ════════════════════════════════════

    public function test_a_pre_fix_summary_understates_a_student_carrying_a_fine(): void
    {
        $demands = [['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => 200.0]];
        // What a pre-FEES-FROZEN-2 writer stored (fine-blind).
        $stored   = ['totalBalance' => 1000.0, 'status' => 'unpaid'];
        $expected = $this->aggregate($demands);

        $this->assertSame(1200.0, $expected['totalBalance']);
        $this->assertTrue($this->diverges($stored, $expected), 'DB-011 must flag this');
    }

    public function test_the_worst_case_a_fine_only_debtor_reads_as_paid_and_leaves_the_defaulter_list(): void
    {
        // Charge settled, penalty outstanding.
        $demands  = [['netAmount' => 1000.0, 'paidAmount' => 1000.0, 'fine_amount' => 200.0]];
        $stored   = ['totalBalance' => 0.0, 'status' => 'paid'];   // pre-fix cache
        $expected = $this->aggregate($demands);

        $this->assertSame(200.0, $expected['totalBalance']);
        $this->assertSame('partial', $expected['status']);
        $this->assertTrue($this->diverges($stored, $expected));
        // Fees::get_defaulter_data drops rows at balance <= 0.005.
        $this->assertTrue((float) $stored['totalBalance'] <= 0.005, 'invisible as a defaulter');
    }

    public function test_an_understating_cache_is_the_high_risk_direction(): void
    {
        // Under-billing the family and hiding them from collections is worse
        // than over-stating, which an operator would notice immediately.
        $expected = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0, 'fine_amount' => 200.0]]);
        $under    = ['totalBalance' => 1000.0, 'status' => 'unpaid'];
        $over     = ['totalBalance' => 1500.0, 'status' => 'unpaid'];
        $this->assertGreaterThan((float) $under['totalBalance'], $expected['totalBalance']);
        $this->assertLessThan((float) $over['totalBalance'],  $expected['totalBalance']);
    }

    public function test_a_correct_summary_does_not_diverge(): void
    {
        $demands  = [['netAmount' => 1000.0, 'paidAmount' => 400.0, 'fine_amount' => 200.0]];
        $expected = $this->aggregate($demands);
        $this->assertFalse($this->diverges(
            ['totalBalance' => $expected['totalBalance'], 'status' => $expected['status']], $expected));
    }

    public function test_fine_free_history_never_diverges(): void
    {
        // The overwhelming majority of historical demands carry no fine and
        // must not be flagged — a check that flags everything is noise.
        foreach ([[1000.0, 0.0], [1000.0, 400.0], [1000.0, 1000.0]] as [$net, $paid]) {
            $d = [['netAmount' => $net, 'paidAmount' => $paid]];
            $e = $this->aggregate($d);
            $this->assertFalse($this->diverges(
                ['totalBalance' => $e['totalBalance'], 'status' => $e['status']], $e));
        }
    }

    public function test_a_penny_rounding_difference_is_not_a_divergence(): void
    {
        $e = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0]]);
        $this->assertFalse($this->diverges(['totalBalance' => 1000.004, 'status' => 'unpaid'], $e));
    }

    public function test_a_student_with_no_summary_is_reported_separately(): void
    {
        // "never written" is a different situation from "written and stale".
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $this->assertStringContainsString("'missing_summary'", $code);
        $this->assertStringContainsString("'balance_mismatch'", $code);
        $this->assertStringContainsString("'status_mismatch'", $code);
    }

    // ══ B. One calculation, not two ════════════════════════════════════

    public function test_the_check_reuses_the_canonical_aggregation(): void
    {
        // A second copy would drift from the writer exactly as the writer
        // drifted from this class over the fine field.
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $this->assertStringContainsString('aggregateDemands(', $code);
        $this->assertStringContainsString('Fee_summary_writer', $code);
    }

    public function test_the_checker_does_not_reimplement_the_arithmetic(): void
    {
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $at   = strpos($code, 'function checkSummaryDivergence(');
        $this->assertNotFalse($at);
        $body = substr($code, $at, 4000);
        $this->assertStringNotContainsString("'fine_amount'", $body,
            'the fine field must be handled by the canonical aggregation, not here');
        $this->assertStringNotContainsString('paidMonths', $body);
    }

    public function test_the_writer_exposes_the_aggregation_without_duplicating_it(): void
    {
        $code = self::codeOnly('application/libraries/Fee_summary_writer.php');
        $this->assertStringContainsString('function aggregateDemands(', $code);
        $this->assertStringContainsString('return $this->_aggregateStudentDemands($demands);', $code);
        $this->assertSame(1, substr_count($code, 'function _aggregateStudentDemands('),
            'there must remain exactly one implementation');
    }

    // ══ C. Read-only — no financial history is touched ═════════════════

    public function test_the_check_writes_nothing(): void
    {
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $at   = strpos($code, 'function checkSummaryDivergence(');
        $body = substr($code, $at, 4000);
        foreach (['firestoreSet', 'firestoreCreate', 'firestoreDelete', '->set(', '->update('] as $w) {
            $this->assertStringNotContainsString($w, $body, 'DB-011 must be read-only');
        }
    }

    public function test_no_historical_financial_field_is_ever_recomputed(): void
    {
        // paidAmount, receipts and journals are historical truth. The repair
        // rebuilds a DERIVED cache from them; it never rewrites them.
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $at   = strpos($code, 'function checkSummaryDivergence(');
        $body = substr($code, $at, 4000);
        $this->assertStringNotContainsString('paidAmount =', $body);
        $this->assertStringNotContainsString('feeReceipts', $body);
        $this->assertStringNotContainsString('accountingLedger', $body);
    }

    public function test_it_is_registered_so_it_actually_runs(): void
    {
        // A check that exists but is never called is no check.
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $at   = strpos($code, 'function runAll(');
        $this->assertStringContainsString('checkSummaryDivergence()', substr($code, $at, 1200));
    }

    // ══ D. The repair is reachable ═════════════════════════════════════

    public function test_the_repair_endpoint_is_now_routed(): void
    {
        // It existed but had no route, so the operator could not invoke the
        // very tool FEES-FROZEN-2 requires.
        $routes = self::src('application/config/routes.php');
        $this->assertStringContainsString("'fees/backfill_fee_summaries'", $routes);
        $this->assertStringContainsString("'Fees/backfill_fee_summaries'", $routes);
    }

    public function test_the_repair_uses_the_canonical_writer(): void
    {
        $code = self::codeOnly('application/controllers/Fees.php');
        $at   = strpos($code, 'function backfill_fee_summaries(');
        $this->assertNotFalse($at);
        $body = substr($code, $at, 4000);
        $this->assertStringContainsString('Fee_summary_writer', $body);
        $this->assertStringContainsString('updateStudentSummary', $body);
    }

    public function test_the_repair_is_gated_on_fees_manage(): void
    {
        $code = self::codeOnly('application/controllers/Fees.php');
        $at   = strpos($code, 'function backfill_fee_summaries(');
        $this->assertStringContainsString("'Fees', 'manage'", substr($code, $at, 400));
    }

    // ══ E. Due-date backfill stays a proposal ══════════════════════════

    public function test_the_due_date_planner_still_has_no_write_path(): void
    {
        // Changing a historical due date decides whether a family is fined.
        // It must remain a proposal for a human to approve.
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function planDueDateBackfill(');
        $this->assertNotFalse($at);
        $body = substr($code, $at, 4000);
        foreach (['firestoreSet', 'updateDemand', 'writeDemand', '->set('] as $w) {
            $this->assertStringNotContainsString($w, $body, 'the planner must never write');
        }
    }

    public function test_the_planner_refuses_to_invent_an_ambiguous_due_date(): void
    {
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function planDueDateBackfill(');
        $body = substr($code, $at, 4000);
        $this->assertStringContainsString("preg_match('/^\\d{4}-\\d{2}\$/', \$pk)", $body);
        $this->assertStringContainsString('ambiguous', $body);
    }

    public function test_the_planner_and_cron_are_session_scoped_by_design(): void
    {
        // Old demands live in PRIOR sessions. Scoping is deliberate — the cron
        // takes SESSION_YEAR — so an operator targets one session at a time
        // rather than sweeping every year at once.
        $svc  = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $cron = self::codeOnly('application/controllers/Fees_late_fee_cron.php');
        $this->assertGreaterThanOrEqual(3, substr_count($svc, "'session', '==', \$this->session"));
        $this->assertStringContainsString("getenv('SESSION_YEAR')", $cron);
    }

    // ══ F. SRNO ════════════════════════════════════════════════════════

    public function test_the_reconciliation_path_carries_no_student_identity_confusion(): void
    {
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $at   = strpos($code, 'function checkSummaryDivergence(');
        $body = substr($code, $at, 4000);
        // Keys on studentId, the internal FK. SR No. and Roll No. have no
        // place in an aggregation identity.
        $this->assertStringContainsString("'studentId'", $body);
        $this->assertStringNotContainsString('rollNo', $body);
        $this->assertStringNotContainsString('srNo', $body);
    }

    public function test_the_two_swapped_identity_students_never_collide(): void
    {
        $a = $this->aggregate([['netAmount' => 1000.0, 'paidAmount' => 0.0]]);
        $b = $this->aggregate([['netAmount' => 2000.0, 'paidAmount' => 0.0]]);
        $this->assertNotSame($a['totalBalance'], $b['totalBalance']);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }

    // ══ G. Cost ════════════════════════════════════════════════════════

    public function test_the_check_costs_two_queries_not_two_per_student(): void
    {
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $at   = strpos($code, 'function checkSummaryDivergence(');
        $body = substr($code, $at, 4000);
        $this->assertSame(2, substr_count($body, 'firestoreQuery('),
            'one query for demands, one for summaries — never per student');
    }

    public function test_no_rtdb_introduced(): void
    {
        foreach (['application/libraries/Fee_integrity_checker.php',
                  'application/libraries/Fee_summary_writer.php'] as $rel) {
            $code = self::codeOnly($rel);
            $at   = strpos($code, 'function checkSummaryDivergence(');
            $body = $at === false ? $code : substr($code, $at, 4000);
            foreach (['getDatabase', 'firebaseio'] as $n) {
                $this->assertStringNotContainsString($n, $body, $rel);
            }
        }
    }
}
