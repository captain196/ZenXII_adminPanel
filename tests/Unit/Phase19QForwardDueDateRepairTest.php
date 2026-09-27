<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 19Q — FEES-D7-DUE, Option C: forward re-date of stranded demands.
 *
 * THE PROBLEM WITH THE OBVIOUS FIX. `planDueDateBackfill()` reconstructs the
 * date a demand WOULD have had. Applying that to an unpaid demand months old
 * makes it **instantly finable** — penalising a family for a payment window
 * they never had the opportunity to meet. That is option A, and it is why the
 * planner has always been proposal-only.
 *
 * Option C gives a FAIR NEW date instead: the next future occurrence of the
 * school's OWN configured due day (`getDueDay()`). It does not pretend a
 * historical due date existed; it makes a stranded demand actionable from
 * today forward.
 *
 * NO NEW MONEY-POLICY SETTING WAS INVENTED — `getDueDay()` is the same value
 * the live generator already uses. `late_fee_grace_days` was deliberately NOT
 * reused: it means "days after due before a fine", and overloading it would
 * give one setting two unrelated meanings.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 · STU0047 → SRNO 12
 */
class Phase19QForwardDueDateRepairTest extends TestCase
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

    /** Mirrors Fee_late_fee_service::nextDueDate(). */
    private function nextDueDate(int $dueDay, string $today): string
    {
        $ts        = strtotime($today);
        $candidate = date('Y-m-', $ts) . sprintf('%02d', $dueDay);
        if ($candidate > $today) return $candidate;
        $nextMonth = date('Y-m-01', strtotime(date('Y-m-01', $ts) . ' +1 month'));
        return date('Y-m-', strtotime($nextMonth)) . sprintf('%02d', $dueDay);
    }

    /** Mirrors Fee_late_fee_service::cutoffDate() + fineFor()'s date gate. */
    private function wouldBeFined(string $dueDate, string $today, int $graceDays = 0): bool
    {
        if ($dueDate === '') return false;                      // dateless = never fined
        $cutoff = $graceDays <= 0 ? $today : date('Y-m-d', strtotime("{$today} -{$graceDays} days"));
        return $dueDate < $cutoff;
    }

    /** Mirrors the eligibility rule in forwardDueDateRepair(). */
    private function isEligible(array $d): bool
    {
        if (trim((string) ($d['dueDate'] ?? '')) !== '') return false;
        if (!in_array(strtolower((string) $d['status']), ['unpaid', 'partial'], true)) return false;
        if ((float) $d['balance'] <= 0.005) return false;
        return true;
    }

    // ══ A. The forward date is strictly forward ════════════════════════

    public function test_the_new_date_is_always_in_the_future(): void
    {
        foreach (['2026-04-01', '2026-04-09', '2026-04-10', '2026-04-11', '2026-04-30'] as $today) {
            $d = $this->nextDueDate(10, $today);
            $this->assertGreaterThan($today, $d, "from {$today} the new due date must be future");
        }
    }

    public function test_landing_on_the_due_day_rolls_to_next_month(): void
    {
        // Otherwise a demand re-dated on the 10th is due "today" and could be
        // fined the same evening the repair runs.
        $this->assertSame('2026-05-10', $this->nextDueDate(10, '2026-04-10'));
    }

    public function test_it_reuses_the_schools_own_due_day(): void
    {
        $this->assertSame('2026-04-15', $this->nextDueDate(15, '2026-04-01'));
        $this->assertSame('2026-05-05', $this->nextDueDate(5,  '2026-04-20'));
        $this->assertSame('2026-05-28', $this->nextDueDate(28, '2026-04-28'));
    }

    public function test_it_rolls_across_a_year_boundary(): void
    {
        $this->assertSame('2027-01-10', $this->nextDueDate(10, '2026-12-20'));
    }

    public function test_no_new_grace_setting_was_invented(): void
    {
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function nextDueDate(');
        $body = substr($code, $at, 800);
        $this->assertStringContainsString('resolveDueDay()', $body,
            'must reuse the canonical due day');
        $this->assertStringNotContainsString('late_fee_grace_days', $body,
            'grace means "days after due before a fine" — it must not be overloaded');
        $this->assertDoesNotMatchRegularExpression('/\+\s*\d+\s*days/', $body,
            'no hard-coded day offset');
    }

    // ══ B. LATE-FEE SAFETY — the point of Option C (Part 6) ════════════

    /** @dataProvider fineTimeline */
    public function test_no_fine_before_the_new_due_date(string $label, string $when, bool $expectFine): void
    {
        $newDue = $this->nextDueDate(10, '2026-04-01');   // → 2026-04-10
        $this->assertSame($expectFine, $this->wouldBeFined($newDue, $when), $label);
    }

    public static function fineTimeline(): array
    {
        return [
            ['repair day',        '2026-04-01', false],
            ['TODAY + 1',         '2026-04-02', false],
            ['day before due',    '2026-04-09', false],
            ['ON the due date',   '2026-04-10', false],
            ['DUE + 1',           '2026-04-11', true],
            ['well past due',     '2026-05-01', true],
        ];
    }

    public function test_the_repaired_demand_is_not_finable_the_day_it_is_repaired(): void
    {
        // The single most important property: repairing must never itself
        // create a penalty.
        $today  = '2026-04-01';
        $newDue = $this->nextDueDate(10, $today);
        $this->assertFalse($this->wouldBeFined($newDue, $today));
    }

    public function test_the_old_reconstructed_date_WOULD_have_fined_immediately(): void
    {
        // Documents exactly why option A was rejected. A demand for April 2025
        // reconstructed to 2025-04-10 is instantly overdue.
        $this->assertTrue($this->wouldBeFined('2025-04-10', '2026-04-01'),
            'option A would penalise a family retroactively');
    }

    public function test_grace_days_still_delay_the_fine_afterwards(): void
    {
        $newDue = $this->nextDueDate(10, '2026-04-01');
        $this->assertFalse($this->wouldBeFined($newDue, '2026-04-12', 5));
        $this->assertTrue($this->wouldBeFined($newDue,  '2026-04-16', 5));
    }

    public function test_normal_rules_resume_after_the_new_due_date(): void
    {
        $newDue = $this->nextDueDate(10, '2026-04-01');
        $this->assertTrue($this->wouldBeFined($newDue, '2026-06-01'),
            'once the fair window has passed, ordinary late-fee rules apply');
    }

    public function test_a_dateless_demand_is_never_fined(): void
    {
        // The pre-repair state — stranded, but at least never penalised.
        $this->assertFalse($this->wouldBeFined('', '2026-04-01'));
    }

    // ══ C. Eligibility (Part 4) ════════════════════════════════════════

    /** @dataProvider eligibilityCases */
    public function test_eligibility(string $label, array $demand, bool $expected): void
    {
        $this->assertSame($expected, $this->isEligible($demand), $label);
    }

    public static function eligibilityCases(): array
    {
        return [
            ['unpaid, outstanding',   ['status' => 'unpaid',   'balance' => 1000.0, 'dueDate' => ''], true],
            ['partial, outstanding',  ['status' => 'partial',  'balance' => 600.0,  'dueDate' => ''], true],
            ['fine-only outstanding', ['status' => 'partial',  'balance' => 200.0,  'dueDate' => ''], true],
            ['already dated',         ['status' => 'unpaid',   'balance' => 1000.0, 'dueDate' => '2026-04-10'], false],
            ['PAID',                  ['status' => 'paid',     'balance' => 0.0,    'dueDate' => ''], false],
            ['ARCHIVED',              ['status' => 'archived', 'balance' => 500.0,  'dueDate' => ''], false],
            ['REVERSED',              ['status' => 'reversed', 'balance' => 0.0,    'dueDate' => ''], false],
            ['cancelled',             ['status' => 'cancelled','balance' => 300.0,  'dueDate' => ''], false],
            ['unknown status',        ['status' => 'weird',    'balance' => 300.0,  'dueDate' => ''], false],
            ['nothing outstanding',   ['status' => 'unpaid',   'balance' => 0.0,    'dueDate' => ''], false],
        ];
    }

    public function test_closed_statuses_are_refused_even_with_a_balance(): void
    {
        // An archived or reversed demand carrying a stale balance must stay
        // untouched — it was deliberately closed.
        foreach (['archived', 'reversed', 'cancelled'] as $s) {
            $this->assertFalse($this->isEligible(['status' => $s, 'balance' => 900.0, 'dueDate' => '']));
        }
    }

    public function test_a_self_contradicting_demand_is_requires_review(): void
    {
        // status=unpaid but paidAmount>0. Never guess at money.
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $this->assertStringContainsString('REQUIRES_REVIEW', $code);
        $this->assertStringContainsString("status says unpaid but a payment exists", $code);
    }

    // ══ D. Financial safety (Part 3) ═══════════════════════════════════

    public function test_only_the_due_date_is_ever_written(): void
    {
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function forwardDueDateRepair(');
        $this->assertNotFalse($at);
        $body = substr($code, $at, 6000);

        // Assert against the WRITE CALL, not the whole body: the method
        // legitimately READS and REPORTS balance/status/paidAmount into its
        // dry-run rows. A blanket "body must not contain 'status'" cannot
        // tell reporting from writing and fails against correct code.
        $this->assertSame(1, substr_count($body, 'updateDemand('),
            'exactly one write call');
        $this->assertStringContainsString("updateDemand(\$demandId, ['due_date' => \$newDue])", $body,
            'and its payload must be the due date alone');

        // No other write primitive anywhere in the method.
        foreach (['firestoreSet', 'firestoreCreate', 'firestoreDelete', 'writeDemand(',
                  'commitBatch', 'feeReceipts', 'accountingLedger'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body,
                "the repair must never touch {$forbidden}");
        }
    }

    public function test_it_is_dry_run_unless_commit_is_explicit(): void
    {
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $this->assertStringContainsString('function forwardDueDateRepair(bool $commit = false', $code);
        $at   = strpos($code, 'function forwardDueDateRepair(');
        $body = substr($code, $at, 6000);
        $this->assertStringContainsString('if ($commit) {', $body);
    }

    public function test_the_cli_requires_a_typed_commit_flag(): void
    {
        $code = self::codeOnly('application/controllers/Fees_late_fee_cron.php');
        $at   = strpos($code, 'function redate_outstanding_due_dates(');
        $this->assertNotFalse($at);
        $body = substr($code, $at, 3000);
        $this->assertStringContainsString("in_array('--commit', \$argv, true)", $body);
        $this->assertStringContainsString('DRY-RUN ONLY', $body);
    }

    public function test_the_original_planner_is_untouched_and_still_write_free(): void
    {
        // Option A remains available as evidence, and remains proposal-only.
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function planDueDateBackfill(');
        $body = substr($code, $at, 4000);
        foreach (['updateDemand', 'firestoreSet', '->set('] as $w) {
            $this->assertStringNotContainsString($w, $body);
        }
    }

    public function test_the_repair_is_session_scoped(): void
    {
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function forwardDueDateRepair(');
        $body = substr($code, $at, 6000);
        $this->assertStringContainsString("'session', '==', \$this->session", $body);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        // After the first run the demand HAS a dueDate, so it becomes
        // ineligible — the repair is naturally idempotent.
        $after = ['status' => 'unpaid', 'balance' => 1000.0, 'dueDate' => '2026-04-10'];
        $this->assertFalse($this->isEligible($after));
    }

    // ══ E. Summary architecture unchanged (Part 7) ═════════════════════

    public function test_db011_still_uses_the_canonical_aggregation(): void
    {
        $code = self::codeOnly('application/libraries/Fee_integrity_checker.php');
        $this->assertStringContainsString('aggregateDemands(', $code);
        $at = strpos($code, 'function runAll(');
        $this->assertStringContainsString('checkSummaryDivergence()', substr($code, $at, 1200));
    }

    public function test_there_is_still_exactly_one_summary_implementation(): void
    {
        $code = self::codeOnly('application/libraries/Fee_summary_writer.php');
        $this->assertSame(1, substr_count($code, 'function _aggregateStudentDemands('));
    }

    public function test_the_repair_route_still_exists(): void
    {
        $this->assertStringContainsString("'fees/backfill_fee_summaries'",
            self::src('application/config/routes.php'));
    }

    // ══ F. ID card regression (Part 12) ════════════════════════════════

    public function test_id_card_replacement_fee_chain_is_untouched(): void
    {
        $policy = self::codeOnly('application/libraries/Id_card_fee_policy.php');
        $this->assertStringContainsString("'due_date'    => \$this->dueDate", $policy);
        $this->assertStringContainsString('function feeHeadFor(', $policy);
        // Assert the MAPPING, not the row's exact bytes — SC-ACCT-1 extended
        // the same 4140 row with cost-recovery terms for Student Charges, which
        // leaves ID-card resolution identical. See the fuller note in
        // Phase19NIdCardReplacementChargeTest.
        $acct = self::codeOnly('application/libraries/Operations_accounting.php');

        $this->assertSame(1, preg_match("/'4140'\s*=>\s*\[([^\]]*)\]/", $acct, $m),
            'the 4140 Miscellaneous Income mapping row is gone — an ID-card replacement '
            . 'would fall through to the 4010 Tuition default');
        $this->assertStringContainsString("'id card'", $m[1],
            "the 'id card' keyword was removed from the 4140 row");
    }

    public function test_an_id_card_demand_is_not_re_dated_by_this_repair(): void
    {
        // It already carries a dueDate (ID-DUE-1), so it is ineligible.
        $this->assertFalse($this->isEligible(
            ['status' => 'unpaid', 'balance' => 150.0, 'dueDate' => '2026-05-16']));
    }

    // ══ G. SRNO (Part 13) ══════════════════════════════════════════════

    public function test_the_repair_keys_on_student_id_never_roll_no(): void
    {
        $code = self::codeOnly('application/libraries/Fee_late_fee_service.php');
        $at   = strpos($code, 'function forwardDueDateRepair(');
        $body = substr($code, $at, 6000);
        $this->assertStringContainsString("'studentId'", $body);
        $this->assertStringNotContainsString('rollNo', $body);
        $this->assertStringNotContainsString('srNo', $body);
    }

    public function test_the_two_swapped_identity_students_stay_distinct(): void
    {
        $this->assertNotSame(self::STU_A, self::STU_B);
    }

    // ══ H. No RTDB ═════════════════════════════════════════════════════

    public function test_no_rtdb(): void
    {
        foreach (['application/libraries/Fee_late_fee_service.php',
                  'application/controllers/Fees_late_fee_cron.php'] as $rel) {
            foreach (['getDatabase', 'firebaseio', 'database()->'] as $n) {
                $this->assertStringNotContainsString($n, self::codeOnly($rel), $rel);
            }
        }
    }
}
