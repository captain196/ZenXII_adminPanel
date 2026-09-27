<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The properties that let F&F run as an OFF-CYCLE payroll run.
 *
 * `STAFF_FNF_SETTLEMENT_DESIGN.md` §3a concludes that F&F is a payroll RUN TYPE
 * rather than a parallel money system, on the strength of the governing spec
 * (`docs/specs/hr-payroll-brief.md` line 369 lists off-cycle runs as "F&F, bonus
 * payout, incentive payout, arrears-only") and the Asanify coverage matrix,
 * which sequences capability 70 behind capability 50.
 *
 * That conclusion rests on four properties of the SHIPPED engine, each verified
 * by reading it. They are pinned here because any of them could be changed by
 * someone with no idea F&F depends on it, and the design would rot silently:
 *
 *   1. `runId` is opaque and sequential, NOT derived from month/year — so two
 *      runs can coexist for the same period without colliding on identity.
 *   2. Slips are run-scoped (`SLIP_{runId}_{staffId}`) and stamped with `runId`.
 *   3. There is exactly ONE calculation engine, and it is pure.
 *   4. The only thing blocking a second run for a period is a single uniqueness
 *      guard scoped on month + year.
 *
 * Property 4 is the entire architectural delta. It is asserted so the change
 * stays a known, bounded edit rather than being rediscovered later.
 *
 * This suite does NOT assert that off-cycle runs exist — they do not. It
 * asserts that the ground they will stand on has not moved.
 */
final class OffCyclePayrollFeasibilityTest extends TestCase
{
    private string $hr;

    protected function setUp(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);
        $this->hr = $hr;
    }

    // ── 1. Run identity is opaque ────────────────────────────────────────

    /**
     * THE LOAD-BEARING ONE. If `runId` ever became month-derived (e.g.
     * "PR_2026_03"), a second run for the same period would collide and F&F
     * could not be a run at all.
     */
    public function test_run_id_is_a_sequential_counter_not_a_period_key(): void
    {
        $this->assertStringContainsString(
            "\$runId = \$this->_next_id('PR', 'PayrollRun');",
            $this->hr,
            'an opaque run id is what allows an off-cycle run to coexist with the monthly one'
        );
    }

    public function test_the_run_id_is_not_built_from_month_or_year(): void
    {
        foreach (['$runId = "PR', "\$runId = 'PR", '$runId = $month', '$runId = $year'] as $bad) {
            $this->assertStringNotContainsString($bad, $this->hr,
                'deriving the run id from the period would make two runs per period impossible');
        }
    }

    // ── 2. Slips are run-scoped ──────────────────────────────────────────

    public function test_slips_are_scoped_to_their_run(): void
    {
        $this->assertStringContainsString('docId("SLIP_{$runId}_{$staffId}")', $this->hr,
            'an off-cycle slip must not overwrite the monthly slip for the same employee');
    }

    public function test_every_slip_records_which_run_produced_it(): void
    {
        $i = strpos($this->hr, 'function _buildPayslipDoc');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 5000);

        $this->assertStringContainsString("'runId'     => \$runId", $body,
            'a settlement must be able to name the run its figures came from');
    }

    // ── 3. One calculation engine ────────────────────────────────────────

    /**
     * An off-cycle run reuses `_compute_payslip()` unchanged. That is what
     * keeps F&F from drifting on half-days, LOP and Calendar Half-Day.
     */
    public function test_there_is_exactly_one_calculation_engine(): void
    {
        $this->assertSame(
            1,
            substr_count($this->hr, 'private function _compute_payslip('),
            'a second engine would let F&F and monthly payroll disagree about the same day'
        );

        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->hr, '$this->_compute_payslip($sal,'),
            'both existing payroll paths must keep calling the one engine'
        );
    }

    /** Pure computation — no Firestore — so a run type cannot change its answer. */
    public function test_the_engine_stays_free_of_persistence(): void
    {
        $i = strpos($this->hr, 'private function _compute_payslip(');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 9000);

        foreach (['$this->fs->', '$this->firebase->'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'the engine must remain pure, or an off-cycle run could read different data');
        }
    }

    // ── 4. The single blocker, precisely located ─────────────────────────

    /**
     * The uniqueness guard is correct for MONTHLY runs and is the one thing
     * that must gain a run-type scope. Pinned so the architectural delta stays
     * exactly this size.
     */
    public function test_the_period_uniqueness_guard_is_the_only_blocker(): void
    {
        $this->assertStringContainsString(
            "['type', '==', 'run'], ['month', '==', \$month], ['year', '==', \$year],",
            $this->hr,
            'this guard blocks a second run for a period — off-cycle needs it scoped to monthly'
        );
        $this->assertStringContainsString('A payroll run already exists for', $this->hr);
    }

    // ── Off-cycle capability, implemented 2026-08-22 ─────────────────────

    /** Every run declares its kind, defaulting to monthly. */
    public function test_runs_declare_their_type(): void
    {
        $this->assertStringContainsString(
            "'runType'   => (string) (\$runData['runType'] ?? self::RUN_TYPE_MONTHLY),",
            $this->hr,
            'a run written before this field existed must read as monthly'
        );
        $this->assertStringContainsString("private const RUN_TYPE_MONTHLY  = 'monthly';", $this->hr);
        $this->assertStringContainsString("private const RUN_TYPE_OFFCYCLE = 'offcycle';", $this->hr);
    }

    /**
     * THE INVARIANT THAT MUST NOT WEAKEN. Exactly one MONTHLY run per
     * school/month/year — a second would pay the whole roster twice.
     */
    public function test_monthly_uniqueness_is_still_enforced(): void
    {
        $this->assertStringContainsString(
            'A payroll run already exists for {$month} {$year}',
            $this->hr,
            'the duplicate-monthly-run refusal must survive'
        );
        $this->assertSame(
            2,
            substr_count($this->hr, "if (\$exType !== self::RUN_TYPE_MONTHLY) continue;   // off-cycle may coexist")
            + substr_count($this->hr, "(string) (\$dData['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY"),
            'generate AND preflight must both scope the guard the same way'
        );
    }

    /**
     * The guard filters in PHP, not in the query. A `['runType','==','monthly']`
     * clause would not match documents written before the field existed, so the
     * guard would silently stop protecting every pre-existing run.
     */
    public function test_the_guard_does_not_filter_run_type_in_the_query(): void
    {
        $this->assertStringNotContainsString("['runType', '==', 'monthly']", $this->hr,
            'querying on a field older documents lack would silently drop them from the guard');
    }

    /**
     * Introducing a second run per period made `$fsRuns[0]` non-deterministic:
     * exporting "the March salary register" could have returned one leaver's
     * settlement instead.
     */
    public function test_the_monthly_export_resolves_its_run_deterministically(): void
    {
        $this->assertStringNotContainsString("], null, 'ASC', 1);", $this->hr,
            'a LIMIT 1 over a period that can now hold two runs picks an arbitrary one');
        $this->assertStringContainsString(
            "if ((string) (\$cData['runType'] ?? self::RUN_TYPE_MONTHLY) === self::RUN_TYPE_MONTHLY) {",
            $this->hr,
            'the month/year export path must select the MONTHLY run explicitly'
        );
    }

    /** Off-cycle changes uniqueness only — never the money. */
    public function test_run_type_does_not_reach_the_calculation(): void
    {
        $i = strpos($this->hr, 'private function _compute_payslip(');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 9000);

        foreach (['runType', 'RUN_TYPE_MONTHLY', 'RUN_TYPE_OFFCYCLE', 'offcycle'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'the engine must not branch on run kind — that would be a second engine by stealth');
        }
    }

    // ── Section G: what a second run per period does to every CONSUMER ───

    /**
     * G-1 (found 2026-08-22, fixed same day). Making two runs per period
     * possible silently changed the meaning of nine existing consumers that all
     * query `type == 'run'`. Eight were fine or already scoped; this one was a
     * real latent defect.
     *
     * `lock_payroll_month()` locks a month once a run for it is Finalized or
     * Paid. An off-cycle F&F settlement for ONE leaver reaches exactly those
     * statuses — so it would satisfy the precondition and let an admin lock a
     * month whose MONTHLY payroll had never been run, freezing out everyone
     * else's pay behind a period lock.
     *
     * Latent, not live: no off-cycle run exists in any tenant. Fixed before
     * off-cycle runs can be created rather than after a month is wrongly locked.
     */
    public function test_only_a_monthly_run_can_satisfy_the_month_lock(): void
    {
        $i = strpos($this->hr, 'public function lock_payroll_month()');
        $this->assertNotFalse($i, 'lock_payroll_month not found');
        $body = substr($this->hr, (int) $i, 3000);

        $this->assertStringContainsString(
            "if ((string) (\$rd['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) continue;",
            $body,
            'an off-cycle settlement for one leaver must not unlock the month lock '
            . 'for a payroll that was never run'
        );

        // Same absent-field-reads-as-monthly discipline as the generate guard:
        // a query filter would drop every pre-existing run out of the check.
        $this->assertStringNotContainsString("['runType', '==', 'monthly']", $body);
    }

    /**
     * G-2. The dashboard's headline "last payroll" picked the newest run by
     * created_at. An off-cycle settlement is newer than the monthly run that
     * preceded it, so the figure shown would be one leaver's settlement total
     * presented as the school's latest payroll — understating the month by the
     * entire rest of the roster.
     */
    public function test_the_dashboard_headline_means_the_last_monthly_run(): void
    {
        $i = strpos($this->hr, 'public function get_dashboard()');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 6000);

        $this->assertStringContainsString(
            "if ((string) (\$r['runType'] ?? self::RUN_TYPE_MONTHLY) !== self::RUN_TYPE_MONTHLY) continue;",
            $body,
            "an off-cycle run must not become the dashboard's headline payroll figure"
        );
    }

    /**
     * The COUNT is deliberately NOT scoped. A count of payroll runs should
     * include off-cycle runs, because they are payroll runs. Asserted so nobody
     * "fixes" it into agreement with the headline and makes settlements
     * invisible in the run tally.
     */
    public function test_the_run_count_still_includes_off_cycle_runs(): void
    {
        $this->assertStringContainsString(
            '$payrollRuns = is_array($runs) ? count($runs) : 0;',
            $this->hr,
            'off-cycle runs are runs — the tally must not hide them'
        );
    }

    /**
     * The consumers where including off-cycle runs is CORRECT, pinned so they
     * are not "fixed" by analogy with G-1 and G-2.
     *
     * `delete_salary_structure()` refuses to delete a structure that any
     * Finalized/Paid run paid against. A settled leaver is precisely the case
     * where that protection matters most, so it must keep seeing every run.
     */
    public function test_structure_deletion_is_blocked_by_any_run_including_off_cycle(): void
    {
        $i = strpos($this->hr, 'public function delete_salary_structure()');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 2500);

        $this->assertStringContainsString("schoolWhere('salarySlips', [['type', '==', 'run']])", $body);
        $this->assertStringNotContainsString('RUN_TYPE_MONTHLY', $body,
            "a leaver's settled run must still protect their salary structure from deletion");
    }

    /**
     * Every run document carries its type, so a consumer that needs to
     * distinguish them can — including `get_payroll_runs()`, which returns the
     * raw run data to the UI and therefore already exposes `runType` without a
     * code change.
     */
    public function test_the_runs_list_returns_the_type_to_its_caller(): void
    {
        $i = strpos($this->hr, 'public function get_payroll_runs()');
        $this->assertNotFalse($i);
        $body = substr($this->hr, (int) $i, 1200);

        $this->assertStringContainsString("\$r = is_array(\$doc['data'] ?? null) ? \$doc['data'] : [];", $body,
            'the list passes run data through whole, so runType reaches the UI unmodified');
    }
    // ── The settlement side is already independent of all this ───────────

    /**
     * The state machine carries no money and no persistence, so it ships ahead
     * of off-cycle runs.
     *
     * Comments are stripped before scanning: the class docblock legitimately
     * says "No Firestore, no CI dependencies", and flagging that sentence would
     * penalise the very documentation that states the boundary.
     */
    public function test_the_settlement_machine_does_not_depend_on_payroll(): void
    {
        $path = __DIR__ . '/../../application/libraries/Fnf_state_machine.php';
        $src  = file_get_contents($path);
        $this->assertNotFalse($src);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }

        $this->assertStringContainsString('const TRANSITIONS', $code, 'comment stripping sanity');

        foreach (['_compute_payslip', 'salarySlips', 'runId', 'Firestore', '$this->fs'] as $needle) {
            $this->assertStringNotContainsString($needle, $code,
                'the lifecycle must remain shippable ahead of the calculation');
        }
    }

    /** One open settlement per staff member replaces the period guard for F&F. */
    public function test_the_settlement_machine_enforces_its_own_uniqueness(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Fnf_state_machine.php');
        $this->assertNotFalse($src);

        $this->assertStringContainsString('public function isOpen(', $src);
        $this->assertStringContainsString('settlement_already_open', $src,
            'off-cycle F&F runs are bounded by this, not by month/year uniqueness');
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }
}
