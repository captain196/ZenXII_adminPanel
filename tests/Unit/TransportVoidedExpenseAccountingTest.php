<?php
/**
 * FIN-5 — a voided transport expense must never produce an accounting journal.
 *
 * THE DEFECT. `voidExpense()` decides whether to reverse a journal with:
 *
 *     $isPosted = ($ref !== '' && $ref !== 'pending' && $ref !== 'failed');
 *
 * so a void that happened BEFORE the post succeeded had nothing to reverse and
 * left the record at `accounting_journal_ref = 'pending'`. The nightly
 * reconciler queries exactly that value with NO status filter, and
 * `_postToAccounting()` had no status guard — so the next cron run would create
 * a real journal for an expense the school had explicitly voided, debiting the
 * GL with nothing to offset it and no second void to trigger a reversal.
 *
 * Three such records were sitting in the test tenant, all `status = Voided`,
 * `accounting_journal_ref = 'pending'`, each a wrong journal waiting to happen.
 * They are also why CH-TRN-10 and CH-TRN-F5-3 had been failing: the stale-pending
 * alarm fired nightly with no action available that could ever clear it.
 *
 * These are source-level assertions. The posting path needs Firestore and
 * PayrollAccountingService, so the invariants are pinned where they are decided.
 */

use PHPUnit\Framework\TestCase;

final class TransportVoidedExpenseAccountingTest extends TestCase
{
    private const SVC = __DIR__ . '/../../application/libraries/Transport_service.php';

    private function src(): string
    {
        $s = (string) file_get_contents(self::SVC);
        $this->assertNotSame('', $s, 'Transport_service.php unreadable');
        return $s;
    }

    /** Body of one method, for scoped assertions. */
    private function method(string $name): string
    {
        $src = $this->src();
        $this->assertTrue(
            (bool) preg_match('/function ' . preg_quote($name, '/') . '\(.*?\n    \}/s', $src, $m),
            "{$name}() not found"
        );
        return $m[0];
    }

    // ── the guard that actually blocks the wrong journal ─────────────────

    public function test_posting_refuses_a_non_active_expense(): void
    {
        $body = $this->method('_postToAccounting');
        $this->assertMatchesRegularExpression(
            "/\\\$status\s*=\s*\(string\)\s*\(\\\$expense\['status'\]/", $body,
            '_postToAccounting() must read the expense status'
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$status\s*!==\s*'Active'\)/", $body,
            'a non-Active expense must be refused before any journal is created'
        );
        // The refusal must come BEFORE postExpense is called, or the journal
        // already exists by the time we decline.
        $guardAt = strpos($body, "!== 'Active'");
        $postAt  = strpos($body, 'postExpense(');
        $this->assertNotFalse($guardAt);
        $this->assertNotFalse($postAt);
        $this->assertLessThan($postAt, $guardAt,
            'the status guard must run before postExpense() — otherwise the journal is already written');
    }

    public function test_refusing_settles_the_record_so_the_queue_drains(): void
    {
        $body = $this->method('_postToAccounting');
        // Without this write the record stays 'pending' and re-alarms every night.
        $this->assertMatchesRegularExpression(
            "/'accounting_journal_ref'\s*=>\s*'voided'/", $body,
            'a refused post must settle the ref to a terminal value'
        );
    }

    // ── the void path must not leave the record queued ───────────────────

    public function test_void_settles_a_pending_or_failed_post(): void
    {
        $body = $this->method('voidExpense');
        $this->assertMatchesRegularExpression(
            "/\\\$ref\s*===\s*'pending'\s*\|\|\s*\\\$ref\s*===\s*'failed'/", $body,
            'voidExpense() must recognise a not-yet-posted expense'
        );
        $this->assertMatchesRegularExpression(
            "/\\\$update\['accounting_journal_ref'\]\s*=\s*'voided'/", $body,
            'voiding an unposted expense must remove it from the pending queue'
        );
    }

    public function test_void_still_reverses_a_genuinely_posted_journal(): void
    {
        // FIN-4 must survive FIN-5: a posted expense still gets a reversal, and
        // the void is aborted if the reversal cannot be written.
        $body = $this->method('voidExpense');
        $this->assertStringContainsString('_reverseExpensePost', $body,
            'a posted expense must still have its journal reversed');
        $this->assertMatchesRegularExpression('/throw new \\\\RuntimeException/', $body,
            'a failed reversal must abort the void rather than leave it unreversed');
    }

    // ── one definition of "posted" ───────────────────────────────────────

    public function test_posted_predicate_is_centralised_and_excludes_voided(): void
    {
        $src = $this->src();
        $this->assertMatchesRegularExpression(
            '/private function _isPostedRef\(string \$ref\): bool/', $src,
            'the posted-ref predicate must have one definition'
        );
        $helper = $this->method('_isPostedRef');
        foreach (['pending', 'failed', 'voided'] as $sentinel) {
            $this->assertStringContainsString("'{$sentinel}'", $helper,
                "'{$sentinel}' must not count as a posted journal");
        }
    }

    public function test_no_call_site_retypes_the_predicate(): void
    {
        // Three hand-written copies existed. Adding the 'voided' sentinel to them
        // independently would have made a voided expense look POSTED to two of
        // them — reported and reversible as though a journal existed.
        $src = $this->src();
        $this->assertDoesNotMatchRegularExpression(
            "/!==\s*''\s*&&\s*\\\$\w+\s*!==\s*'pending'\s*&&\s*\\\$\w+\s*!==\s*'failed'/", $src,
            'a call site retyped the posted-ref test instead of using _isPostedRef()'
        );
        $this->assertGreaterThanOrEqual(3, substr_count($src, '_isPostedRef('),
            'expected the helper to be used at every former call site (plus its definition)');
    }

    // ── the admin path refuses too, with a usable message ────────────────

    public function test_manual_post_refuses_a_voided_expense_up_front(): void
    {
        $body = $this->method('postExpenseNow');
        $this->assertMatchesRegularExpression("/if\s*\(\\\$status\s*!==\s*'Active'\)/", $body,
            'postExpenseNow() must refuse a voided expense');
        // It must refuse BEFORE writing 'pending', or it briefly re-queues a
        // record that must never be posted.
        $guardAt   = strpos($body, "!== 'Active'");
        $requeueAt = strpos($body, "'accounting_journal_ref' => 'pending'");
        $this->assertNotFalse($guardAt);
        $this->assertNotFalse($requeueAt);
        $this->assertLessThan($requeueAt, $guardAt,
            'the refusal must precede the re-queue write');
    }

    public function test_reconciler_reports_settled_records(): void
    {
        $body = $this->method('reconcilePendingAccountingPosts');
        $this->assertStringContainsString("'voided'", $body,
            'a run that settles voided records should say so rather than look idle');
    }
}
