<?php

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', 'x');
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  P5-BF · THE WRITER'S STRUCTURAL GUARANTEES
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `LedgerBackfillPlannerTest` executes the DECISION. This file guards the
 * properties of the writer that a decision test cannot see: that writing is
 * opt-in twice, that only one collection is ever written, that the single
 * authoritative persister is reused rather than reimplemented, and that no
 * financial record outside the ledger can be touched.
 *
 * These are source-structural assertions over the executable controller. A
 * CI3 controller cannot be instantiated outside the framework; that limit is
 * stated rather than glossed, and the behavioural half is proven on the
 * emulator by an actual execute run.
 */
final class LedgerBackfillWriterTest extends TestCase
{
    private static string $code = '';

    public static function setUpBeforeClass(): void
    {
        $src = (string) file_get_contents(APPPATH . 'controllers/Ledger_backfill.php');
        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        self::$code = preg_replace("/(\r?\n)(?:[ \t]*\r?\n)+/", "\$1", $out);
    }

    // ══ WRITING IS OPT-IN, TWICE ════════════════════════════════════════════

    /**
     * The default entry point must not write. A tool whose safe mode is not the
     * default is one typo away from an unreviewed money-ledger mutation.
     */
    public function test_the_default_mode_does_not_write(): void
    {
        $this->assertStringContainsString('public function analyze($schoolId = \'\', $session = \'\')', self::$code,
            'the read-only entry point was renamed or removed');
        $this->assertStringContainsString('$this->_run($schoolId, $session, false);', self::$code,
            'analyze() no longer passes write=false — the default mode could now write');
    }

    /** `execute` alone is not enough; --commit is a second, explicit act. */
    public function test_execute_refuses_without_an_explicit_commit_flag(): void
    {
        $fn = strpos(self::$code, 'public function execute(');
        $this->assertNotFalse($fn, 'the execute entry point was renamed');
        $body = substr(self::$code, $fn, 700);

        $this->assertStringContainsString("in_array('--commit'", $body,
            'execute() no longer requires --commit, so a single mistyped command writes to a '
            . 'money ledger');
        $this->assertStringContainsString('exit(2)', $body,
            'execute() without --commit no longer exits — it would fall through and write');

        // The refusal must come BEFORE the run.
        $guard = strpos($body, 'if (!$commit)');
        $run   = strpos($body, '$this->_run(');
        $this->assertNotFalse($guard);
        $this->assertLessThan($run, $guard,
            'the --commit check runs after _run(), which means it does not gate anything');
    }

    /** The persist call must be unreachable unless $write is true. */
    public function test_persist_is_gated_behind_the_write_flag(): void
    {
        $gate    = strpos(self::$code, 'if (!$write) {');
        $persist = strpos(self::$code, '$svc->persist($rows)');

        $this->assertNotFalse($gate, 'the write gate is gone — analysis and execution have merged');
        $this->assertNotFalse($persist, 'the persist call was renamed');
        $this->assertLessThan($persist, $gate,
            'persist() is reachable before the write gate, so an analysis run would write');
    }

    // ══ ONE COLLECTION, ONE PERSISTER ═══════════════════════════════════════

    /**
     * AMOUNT PRESERVATION, ENFORCED STRUCTURALLY.
     *
     * The backfill repairs a ledger REPRESENTATION. If it can name a demand,
     * receipt, payment or accounting collection in a write, it can change money
     * that was already settled.
     */
    public function test_the_writer_touches_only_the_ledger_collection(): void
    {
        foreach ([
            'feeReceipts', 'feeReceiptAllocations', 'accountingLedger', 'accounting',
            'feeOnlinePayments', 'feeOnlineOrders', 'studentFeeSummary', 'feeDefaulters',
            'feeCarryForward', 'studentAdvanceBalances',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, self::$code,
                "the backfill references {$forbidden}. It repairs a ledger representation and "
                . 'must never be able to alter a charge, a payment or a posted journal.');
        }

        // feeDemands is READ (to plan against) but must never be written.
        $this->assertStringNotContainsString('firestoreSet(', self::$code,
            'the backfill performs a direct document write. Every write must go through '
            . 'Entitlement_allocation_service::persist(), which enforces tenant scope and '
            . 'create-if-absent semantics.');
        $this->assertStringNotContainsString('firestoreCreate', self::$code,
            'the backfill writes documents directly instead of through the allocation service');
        $this->assertStringNotContainsString('firestoreDelete', self::$code,
            'the backfill can delete documents — a repair must never remove financial history');
    }

    /**
     * No second persister, and no second definition of an allocation row.
     * `P4AllocationLedgerTest` freezes the ledger reference to the generator and
     * lifecycle; this writer must borrow that authority, not fork it.
     */
    public function test_rows_are_built_and_written_by_the_single_authority(): void
    {
        $this->assertStringContainsString('$svc->build($dec[\'demand\'], $r[\'entitlement\']', self::$code,
            'the writer no longer builds rows through Entitlement_allocation_service::build(), '
            . 'so identity, tenant/session isolation and over-allocation refusal are bypassed');
        $this->assertStringContainsString('$svc->persist($rows)', self::$code,
            'the writer no longer persists through the authoritative service');

        // A row the service refuses must be dropped, never written anyway.
        $this->assertStringContainsString("if (empty(\$b['ok'])) {", self::$code,
            'a row refused by build() is no longer skipped — a malformed ledger row is worse '
            . 'than a missing one');
    }

    // ══ TENANT ══════════════════════════════════════════════════════════════

    /** The tenant is required and never inferred from the data. */
    public function test_the_tenant_is_required_and_never_inferred(): void
    {
        $this->assertStringContainsString("if (\$schoolId === '') {", self::$code,
            'the writer no longer requires an explicit tenant');
        $this->assertStringContainsString('exit(2)', self::$code);

        // Every read is tenant-filtered.
        $fn   = strpos(self::$code, 'private function _rows(');
        $body = substr(self::$code, $fn, 600);
        $this->assertStringContainsString("[['schoolId', '==', \$schoolId]]", $body,
            'a read is no longer tenant-scoped — the backfill could plan against another '
            . "school's demands");
    }

    /** CLI only: this must not be reachable over HTTP. */
    public function test_the_writer_is_not_reachable_over_http(): void
    {
        $this->assertStringContainsString('if (!is_cli()) show_404();', self::$code,
            'the backfill controller is reachable over HTTP. A money-ledger writer must not '
            . 'have a web entry point.');
    }

    // ══ THE PLANNER IS THE DECISION ═════════════════════════════════════════

    /** The controller must not re-decide; it delegates to the pure planner. */
    public function test_the_decision_is_delegated_to_the_pure_planner(): void
    {
        $this->assertStringContainsString('Ledger_backfill_planner::plan(', self::$code,
            'the controller no longer uses the pure planner, so the decision rules are back '
            . 'inside a class no test can execute');

        // Only rows the planner marked CREATE may be built.
        $this->assertStringContainsString('Ledger_backfill_planner::CREATE', self::$code,
            'the writer no longer filters on the planner CREATE outcome — refused and skipped '
            . 'demands could be written');
    }
}
