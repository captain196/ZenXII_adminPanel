<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  THE CERTIFICATION RUNNER MUST NOT BE ABLE TO LIE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Billing_v2_verifier` is the gate on Billing V2 activation — its own docblock
 * says it "must run CLEAN before activation is even proposed". Until now its
 * only callers were two unit test files, so it had never certified a real
 * estate. `Billing_v2_certify` is the CLI, read-only runner that closes that.
 *
 * These tests exist because that runner is the kind of tool that fails
 * silently in the dangerous direction: one which reads the wrong collection,
 * or treats an empty scope as a pass, produces a CLEAN-looking result over a
 * broken estate. That is worse than no tool, because it would be used to
 * justify flipping a flag over live money.
 *
 * ── THE BUG THAT MOTIVATED THE FIRST TEST ────────────────────────────────
 * The first version queried `feeDiscountAllocations`. The collection the
 * writer actually writes is `entitlementAllocations`. A wrong name returns
 * zero rows and reports a perfectly clean ledger no matter how many
 * allocations exist — and it did.
 *
 * ── WHY THE DRIFT CHECK LIVES HERE AND NOT IN THE RUNNER ─────────────────
 * The obvious fix was for the runner to read
 * `Entitlement_allocation_service::COL`. That violated a deliberate freeze:
 * `P4AllocationLedgerTest::test_only_the_generator_and_lifecycle_reference_
 * the_ledger` permits exactly two files to reference that service, and names
 * "a controller … a report" as exactly what must not. The runner is a report.
 *
 * So the runner keeps a literal, and THIS test — which is not bound by that
 * freeze — asserts the literal still equals the service's constant. The two
 * cannot diverge silently and the architecture is preserved.
 */
final class BillingV2CertificationToolingTest extends TestCase
{
    private static string $certify = '';
    private static string $allocSvc = '';

    private static function strip(string $path): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return (string) preg_replace('/\s+/', ' ', $out);
    }

    public static function setUpBeforeClass(): void
    {
        self::$certify  = self::strip(APPPATH . 'controllers/Billing_v2_certify.php');
        self::$allocSvc = self::strip(APPPATH . 'libraries/Entitlement_allocation_service.php');
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Chosen because it appears ONLY in prose in the runner.
        $this->assertStringNotContainsString('feeDiscountAllocations', self::$certify,
            'the wrong collection name survives outside comments');
    }

    // ── THE RUNNER MUST READ WHAT THE WRITER WRITES ──────────────────────

    public function test_the_runner_reads_the_collection_the_writer_writes(): void
    {
        // Extract the literal the runner uses and the constant the writer
        // defines, and require them to agree. This is the drift protection
        // that the architectural freeze forbids expressing as a code
        // dependency.
        $this->assertMatchesRegularExpression(
            "/\\\$allocCol = '([a-zA-Z]+)';/", self::$certify,
            'the runner no longer names an allocation collection');
        preg_match("/\\\$allocCol = '([a-zA-Z]+)';/", self::$certify, $runner);

        $this->assertMatchesRegularExpression(
            "/const COL = '([a-zA-Z]+)';/", self::$allocSvc,
            'the allocation service no longer declares its collection');
        preg_match("/const COL = '([a-zA-Z]+)';/", self::$allocSvc, $writer);

        $this->assertSame($writer[1], $runner[1],
            "the certifier reads '{$runner[1]}' but the writer writes '{$writer[1]}' — "
            . 'the runner would report a clean ledger over a broken estate');
    }

    public function test_the_runner_does_not_breach_the_ledger_scope_freeze(): void
    {
        // P4AllocationLedgerTest permits exactly Fee_generation_service and
        // Fee_lifecycle to reference the allocation service. A report that
        // couples to it is scope running ahead of its gates — and that test
        // would fail, which is how this was caught.
        $this->assertStringNotContainsString('Entitlement_allocation_service', self::$certify,
            'the certifier references the allocation service, breaking the deliberate '
            . 'two-file freeze in P4AllocationLedgerTest');
    }

    // ── THE RUNNER MUST NOT BE ABLE TO WRITE ─────────────────────────────

    public function test_the_certifier_has_no_write_path(): void
    {
        // The verifier's docblock is explicit that a verifier which repairs
        // what it finds destroys the evidence of how the estate got there.
        foreach (['firestoreSet', 'firestoreCreate', 'firestoreUpdate', 'firestoreDelete',
                  'commitBatch', 'setEntity', 'removeEntity', 'persist('] as $writer) {
            $this->assertStringNotContainsString($writer, self::$certify,
                "the certifier can call {$writer} — it must be structurally read-only");
        }
    }

    // ── THE WHAT-IF IS THE QUESTION ACTIVATION ASKS ──────────────────────

    public function test_the_whatif_asserts_the_ledger_is_active(): void
    {
        // Certifying with the flag OFF skips I2/I3/I4/I7 — exactly the
        // invariants activation switches on. A status report that omits them
        // is not an activation decision.
        $this->assertStringContainsString('function whatif', self::$certify,
            'there is no pre-activation what-if — the runner can only report today');
        $this->assertMatchesRegularExpression(
            '/\$m5 = \$assumeLedger \? true/', self::$certify,
            'the what-if does not force the ledger invariants on');
    }

    public function test_a_clean_run_and_an_empty_run_are_distinguished(): void
    {
        // "0 demands" must never read as "certified". An empty scope is the
        // most common way a certification tool produces a false all-clear.
        $this->assertStringContainsString('EMPTY result, not a clean one', self::$certify,
            'an empty estate is reported as if it had been certified');
    }

    public function test_a_violation_reports_which_invariant_failed(): void
    {
        // The first version grouped on a non-existent `rule` key and printed
        // "?: 24", hiding that I2 was the failing invariant.
        $this->assertStringContainsString("\$v['invariant']", self::$certify,
            'violations are not grouped by the invariant the verifier actually emits');
    }

    public function test_a_failed_certification_exits_nonzero(): void
    {
        // So it can gate an activation script rather than being read by eye.
        $this->assertStringContainsString('exit($ok ? 0 : 2)', self::$certify,
            'the runner does not signal failure through its exit status');
    }
}
