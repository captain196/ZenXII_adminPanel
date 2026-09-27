<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The payroll→ledger boundary, and why F&F needs no accounting code of its own.
 *
 * `PayrollAccountingService` is the only sanctioned bridge between the payroll
 * domain and the immutable ledger. Its own docblock records why it exists: a
 * historical imbalance (`JE_20260414193106_f55be96d`) was produced by a legacy
 * HR module performing direct `firestoreSet('accounting', …)` writes, bypassing
 * the canonical engine's CAS, period-lock, idempotency and balance-batch
 * protections. The service was built to make that "structurally impossible".
 *
 * `STAFF_FNF_SETTLEMENT_DESIGN.md` §4.6 concludes that an F&F settlement posts
 * exactly as any run posts, with no F&F-specific accounting path. That
 * conclusion depends on properties asserted here — if any of them changes, the
 * design must be revisited before F&F posts anything.
 *
 * These are structural assertions by necessity: the service's only sink is the
 * canonical engine, so exercising it behaviourally would mean standing up the
 * whole accounting stack. What CAN be verified without that is the boundary
 * itself — that no bypass exists — which is precisely the property the historical
 * incident turned on.
 */
final class PayrollAccountingBoundaryTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../application/services/PayrollAccountingService.php';
        if (!is_file($path)) $this->markTestSkipped('PayrollAccountingService not present');

        $src = file_get_contents($path);
        $this->assertNotFalse($src);
        $this->src = $src;
    }

    /** Executable code only — the docblock names the very pattern it forbids. */
    private function code(): string
    {
        $code = '';
        foreach (token_get_all($this->src) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                $code .= $t[1];
            } else {
                $code .= $t;
            }
        }
        return $code;
    }

    // ── The boundary that the incident was about ─────────────────────────

    /**
     * THE ONE THAT MATTERS. A direct write to an accounting collection is what
     * caused the historical imbalance. The service must have no such path.
     */
    public function test_the_service_never_writes_an_accounting_collection_directly(): void
    {
        $code = $this->code();
        $this->assertStringContainsString('class PayrollAccountingService', $code, 'strip sanity');

        foreach ([
            "firestoreSet('accounting'",
            'firestoreSet("accounting"',
            "firestoreSet('accountingLedger'",
            "firestoreUpdate('accounting'",
            "->set('accounting'",
        ] as $bypass) {
            $this->assertStringNotContainsString($bypass, $code,
                'a direct ledger write bypasses CAS, period-lock, idempotency and balance checks — '
                . 'this is exactly what produced JE_20260414193106_f55be96d');
        }
    }

    /** Reads are fine — reversal must locate the original entry. */
    public function test_reads_are_permitted_so_reversal_can_find_its_original(): void
    {
        $this->assertStringContainsString("firestoreGet('accounting'", $this->code(),
            'postReversal must be able to read the entry it reverses');
    }

    // ── Journal identity is run-derived ──────────────────────────────────

    /**
     * An off-cycle F&F run gets its own journals with no collision, because the
     * id carries the runId. Third place the opaque runId pays off, after slip
     * ids and the run document.
     */
    public function test_journal_ids_are_derived_from_the_run(): void
    {
        $this->assertStringContainsString(
            "return 'JE_HR_PAYROLL_' . \$runId . '_' . \$seq;",
            $this->src,
            'a journal id not scoped to the run would collide between the monthly run and F&F'
        );
    }

    public function test_the_accrual_journal_id_is_also_run_scoped(): void
    {
        $this->assertStringContainsString('function accrualJournalIdFor(string $runId', $this->src);
    }

    // ── Idempotency across retries ───────────────────────────────────────

    /**
     * A settlement that retries a posting must not double-post. The service
     * builds deterministic source refs precisely so this holds.
     */
    public function test_idempotency_is_provided_by_deterministic_source_refs(): void
    {
        $this->assertMatchesRegularExpression(
            '/deterministic source_refs so idempotency holds across retries/i',
            $this->src,
            'if this guarantee is removed, F&F retry handling needs its own protection'
        );
    }

    // ── The correction model already exists at the ledger ────────────────

    /**
     * `paid` and `closed` are terminal in Fnf_state_machine, and corrections are
     * compensating rather than mutations. Accounting had already implemented
     * that shape — the design agreed with it rather than inventing it.
     */
    public function test_reversal_exists_for_compensating_corrections(): void
    {
        $this->assertStringContainsString(
            'public function postReversal(string $originalEntryId, string $reason',
            $this->src,
            'without reversal, a wrong settlement could only be fixed by rewriting history'
        );
    }

    public function test_an_adjustment_path_exists_for_arrears_and_recovery(): void
    {
        $this->assertStringContainsString('public function postRunAdjustment(array $adj)', $this->src);
    }

    /** The run-level entries an off-cycle F&F run would use. */
    public function test_the_run_level_posting_entrypoints_exist(): void
    {
        foreach (['postRunAccrual', 'postRunPayment'] as $fn) {
            $this->assertStringContainsString("public function {$fn}(array ", $this->src,
                "F&F posts through {$fn}() exactly as a monthly run does");
        }
    }

    // ── Money integrity ──────────────────────────────────────────────────

    /** Gross must reconcile against net plus deductions before anything posts. */
    public function test_a_posting_is_balance_checked_before_it_reaches_the_ledger(): void
    {
        $this->assertStringContainsString(
            'abs(round($gross - $netTakeHome - $totalDeductions, 2)) > 0.01',
            $this->src,
            'an unbalanced payroll journal must be refused at the boundary, not discovered later'
        );
    }

    // ── The convergence debt ─────────────────────────────────────────────

    /**
     * The `accounting` / `accountingLedger` convergence debt does NOT reach the
     * payroll path — checked, not assumed.
     *
     * That debt is real, but it lives in the Fees domain and the sync library
     * (`Fees.php`, `Accounting_firestore_sync.php`, the ledger probes).
     * `PayrollAccountingService` names neither collection in its own writes,
     * because its only sink is the canonical engine — so the engine, not this
     * service, decides where a journal lands.
     *
     * The practical consequence for F&F: a settlement POSTS through one path and
     * inherits whatever the engine does. But a settlement RECONCILIATION screen
     * is a consumer, and consumers are exactly what the convergence debt bites —
     * such a screen must query both collections or it will under-report.
     */
    public function test_the_payroll_path_does_not_carry_the_ledger_convergence_debt(): void
    {
        $this->assertStringNotContainsString('accountingLedger', $this->code(),
            'the payroll service must keep routing through the engine rather than choosing '
            . 'a ledger collection itself — that choice is the engine\'s');
    }

    /** And the debt is still live elsewhere, so a consumer cannot assume one collection. */
    public function test_the_convergence_debt_still_exists_in_the_wider_codebase(): void
    {
        $sync = @file_get_contents(__DIR__ . '/../../application/libraries/Accounting_firestore_sync.php');
        if ($sync === false) $this->markTestSkipped('Accounting_firestore_sync not present');

        $this->assertStringContainsString('accountingLedger', $sync,
            'if this collection has genuinely been retired the debt is closed, and any F&F '
            . 'reconciliation screen can safely read a single ledger'
        );
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
