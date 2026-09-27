<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 7 — off-cycle payroll runs can now actually be created.
 *
 * THE GAP. `RUN_TYPE_OFFCYCLE` was declared, and every consumer read `runType`
 * defensively with a monthly default — but NOTHING in the codebase ever
 * assigned it. The whole capability was scaffolding with no producer: guards
 * against a situation that could not arise.
 *
 * BUILT GENERICALLY, NOT AS AN F&F SPECIAL CASE. An off-cycle run is "pay these
 * specific people, outside the monthly cycle". Full & Final is one use; arrears
 * and a bonus payout are others. A mechanism that only understood settlements
 * would have to be rebuilt for each of them, which is why the directive to
 * prefer the generic capability is the right one.
 *
 * THE INVARIANT THAT MUST NOT WEAKEN. Exactly one MONTHLY run per
 * school/month/year — a second would pay the whole roster twice. Off-cycle runs
 * coexist with it; they do not replace the guard.
 */
final class OffCycleRunCreationTest extends TestCase
{
    private static string $hr = '';

    public static function setUpBeforeClass(): void
    {
        self::$hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$hr = '';
    }

    /**
     * The whole of `generate_payroll()`, bounded at the NEXT method.
     *
     * Not a character count. `generate_payroll` is long, and a fixed window
     * silently stopped short of the roster-narrowing code — failing for a
     * reason that had nothing to do with the invariant under test. A test that
     * fails for the wrong reason is worse than no test, because the next person
     * "fixes" it by weakening the assertion.
     */
    private function generateBody(): string
    {
        $i = strpos(self::$hr, 'public function generate_payroll()');
        $this->assertNotFalse($i, 'generate_payroll not found');

        $next = preg_match(
            '/\n    (?:public|private|protected)[^\n]*function /',
            self::$hr,
            $m,
            PREG_OFFSET_CAPTURE,
            (int) $i + 40
        ) ? (int) $m[0][1] : strlen(self::$hr);

        $body = substr(self::$hr, (int) $i, $next - (int) $i);
        $this->assertStringContainsString('$runType', $body, 'slice sanity');

        return $body;
    }

    // ── The gap is closed ────────────────────────────────────────────────

    /**
     * THE LOAD-BEARING TEST. The off-cycle constant is now ASSIGNED, not merely
     * compared against. Before this, every use was a defensive read.
     */
    public function test_the_off_cycle_run_type_can_actually_be_produced(): void
    {
        $body = $this->generateBody();

        $this->assertStringContainsString(
            "\$runType = strtolower(trim((string) (\$this->input->post('run_type') ?? '')));",
            $body,
            'the run type must be settable by the caller'
        );
        $this->assertStringContainsString('$runType = self::RUN_TYPE_MONTHLY;', $body,
            'an absent parameter must mean monthly');
        $this->assertStringContainsString("'runType'          => \$runType,", self::$hr,
            'and the chosen type must reach the run document');
    }

    /** An unknown run type is refused rather than silently treated as monthly. */
    public function test_an_unknown_run_type_is_refused(): void
    {
        $this->assertStringContainsString(
            "if (!in_array(\$runType, self::RUN_TYPES, true)) {", $this->generateBody(),
            'a typo must not silently create a monthly run');
        $this->assertStringContainsString('Unknown run type. Expected one of:', self::$hr);
    }

    /** `RUN_TYPES` is no longer a declared-but-unused constant. */
    public function test_the_run_types_constant_is_used(): void
    {
        $this->assertGreaterThanOrEqual(2, substr_count(self::$hr, 'self::RUN_TYPES'),
            'the vocabulary must be used for validation, not merely declared');
    }

    // ── An off-cycle run must name its people ────────────────────────────

    /**
     * THE INVARIANT PROTECTED IN ITS NEW FORM. A whole-roster off-cycle run is
     * indistinguishable from a second monthly payroll — which is precisely what
     * the uniqueness guard exists to prevent. So the guard is reinstated in the
     * only form that makes sense for this run type: an off-cycle run must name
     * its employees.
     */
    public function test_an_off_cycle_run_must_name_its_staff(): void
    {
        $body = $this->generateBody();

        $this->assertStringContainsString(
            "if (\$runType === self::RUN_TYPE_OFFCYCLE && \$subset === []) {", $body);
        $this->assertStringContainsString(
            'An off-cycle run must name the staff it covers.', self::$hr,
            'without this an off-cycle run could pay the entire roster a second time');
    }

    /** And a monthly run covers everyone, so it cannot be narrowed. */
    public function test_a_monthly_run_cannot_be_narrowed_to_a_subset(): void
    {
        $body = $this->generateBody();

        $this->assertStringContainsString(
            "if (\$runType === self::RUN_TYPE_MONTHLY && \$subset !== []) {", $body);
        $this->assertStringContainsString(
            'A monthly run covers the whole roster and cannot be limited to a subset.', self::$hr,
            'a partial monthly run would look like the month was paid when it was not');
    }

    // ── Isolation and privacy ────────────────────────────────────────────

    /**
     * The subset is applied AFTER the privacy map, so an off-cycle run can
     * never reach a record the monthly run could not.
     */
    public function test_the_subset_is_applied_after_the_privacy_map(): void
    {
        $body = $this->generateBody();

        $privacy = strpos($body, '$roster = $this->_apply_staff_private_map($roster);');
        $narrow  = strpos($body, 'if ($runType === self::RUN_TYPE_OFFCYCLE) {');

        $this->assertNotFalse($privacy);
        $this->assertNotFalse($narrow);
        $this->assertLessThan($narrow, $privacy,
            'narrowing before the privacy map would let an off-cycle run see more, not less');
    }

    /**
     * A named employee who is not in the roster is REPORTED, not silently
     * skipped. An off-cycle run that quietly dropped a leaver would be
     * discovered by the leaver.
     */
    public function test_an_unknown_staff_id_is_named_rather_than_skipped(): void
    {
        $body = $this->generateBody();

        $this->assertStringContainsString('$unknown[] = $sid;', $body);
        $this->assertStringContainsString(
            'Not in the active roster for this period: ', self::$hr,
            'paying a smaller set in silence is how somebody goes unpaid');
    }

    // ── The monthly uniqueness invariant is untouched ────────────────────

    /** Exactly one MONTHLY run per period still holds. */
    public function test_monthly_uniqueness_still_holds(): void
    {
        $this->assertStringContainsString(
            'A payroll run already exists for {$month} {$year}', self::$hr);
        $this->assertStringContainsString(
            "if (\$exType !== self::RUN_TYPE_MONTHLY) continue;   // off-cycle may coexist",
            self::$hr,
            'the guard must skip off-cycle runs, never stop applying to monthly ones');
    }

    /** The guard filters in PHP, not in the query — older docs lack the field. */
    public function test_the_guard_does_not_filter_run_type_in_the_query(): void
    {
        $this->assertStringNotContainsString("['runType', '==', 'monthly']", self::$hr,
            'querying a field older documents lack would drop them from the guard');
    }

    // ── The run records its own scope ────────────────────────────────────

    /**
     * An off-cycle run records WHO it covered. Without that, a three-person
     * arrears run is indistinguishable from a monthly payroll at a school that
     * happens to have three staff.
     */
    public function test_an_off_cycle_run_records_who_it_covered(): void
    {
        $this->assertStringContainsString("'runScope'         => \$runType === self::RUN_TYPE_OFFCYCLE", self::$hr);
        $this->assertStringContainsString("? ['staffIds' => array_keys(\$roster), 'count' => count(\$roster)]", self::$hr);
    }

    // ── Everything downstream already handled it ─────────────────────────

    /**
     * The consumers audited in the earlier off-cycle work must still be scoped
     * correctly, now that off-cycle runs can genuinely exist. These were
     * defensive before; they are load-bearing now.
     */
    public function test_the_month_lock_still_requires_a_monthly_run(): void
    {
        $i = strpos(self::$hr, 'public function lock_payroll_month()');
        $this->assertNotFalse($i);
        $body = substr(self::$hr, (int) $i, 3000);

        $this->assertStringContainsString(
            "if ((string) (\$rd['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) continue;",
            $body,
            'an off-cycle settlement must not let a month be locked whose payroll never ran');
    }

    public function test_the_dashboard_headline_is_still_the_monthly_run(): void
    {
        $i = strpos(self::$hr, 'public function get_dashboard()');
        $this->assertNotFalse($i);
        $body = substr(self::$hr, (int) $i, 6000);

        $this->assertStringContainsString(
            "if ((string) (\$r['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) continue;",
            $body);
    }

    public function test_the_monthly_export_still_selects_the_monthly_run(): void
    {
        $this->assertStringContainsString(
            "if ((string) (\$cData['runType'] ?? self::RUN_TYPE_MONTHLY) === self::RUN_TYPE_MONTHLY) {",
            self::$hr);
        $this->assertStringNotContainsString("], null, 'ASC', 1);", self::$hr,
            'a LIMIT 1 over a period that can now hold two runs picks an arbitrary one');
    }

    /** Structure deletion must still see EVERY run, including a leaver's. */
    public function test_structure_deletion_still_sees_off_cycle_runs(): void
    {
        $i = strpos(self::$hr, 'public function delete_salary_structure()');
        $this->assertNotFalse($i);
        $body = substr(self::$hr, (int) $i, 2500);

        $this->assertStringNotContainsString('RUN_TYPE_MONTHLY', $body,
            "a settled leaver's run must still protect their salary structure from deletion");
    }

    // ── Identity stays collision-free ────────────────────────────────────

    /** Run ids are opaque, so two runs can coexist for one period. */
    public function test_run_ids_remain_opaque(): void
    {
        $this->assertStringContainsString("\$runId = \$this->_next_id('PR', 'PayrollRun');", self::$hr);
        foreach (['$runId = "PR', "\$runId = 'PR"] as $derived) {
            $this->assertStringNotContainsString($derived, self::$hr,
                'a period-derived run id would make two runs per period impossible');
        }
    }

    /** Slips are run-scoped, so an off-cycle slip cannot overwrite a monthly one. */
    public function test_payslips_remain_run_scoped(): void
    {
        $this->assertStringContainsString('docId("SLIP_{$runId}_{$staffId}")', self::$hr);
    }

    /** And so are the accounting journals. */
    public function test_accounting_journals_remain_run_scoped(): void
    {
        $svc = (string) file_get_contents(
            __DIR__ . '/../../application/services/PayrollAccountingService.php'
        );

        $this->assertStringContainsString(
            "return 'JE_HR_PAYROLL_' . \$runId . '_' . \$seq;", $svc,
            'a journal id not scoped to the run would collide between the monthly run and an off-cycle one');
    }

    // ── The money engine does not branch on run kind ─────────────────────

    /**
     * Off-cycle changes uniqueness and scope only — never the arithmetic. A
     * calculation that branched on run kind would be a second engine by stealth.
     */
    public function test_the_calculation_does_not_branch_on_run_kind(): void
    {
        $i = strpos(self::$hr, 'private function _compute_payslip(');
        $this->assertNotFalse($i);
        $body = substr(self::$hr, (int) $i, 9000);

        foreach (['runType', 'RUN_TYPE_MONTHLY', 'RUN_TYPE_OFFCYCLE', 'offcycle'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'the engine must not know which kind of run it is computing for');
        }
    }
}
