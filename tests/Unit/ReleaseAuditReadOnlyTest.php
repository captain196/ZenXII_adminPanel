<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The release audit and the impact analysers must never write.
 *
 * These commands exist to inform a remediation DECISION. If one of them mutated
 * anything, the assessment would silently become the remediation — balances
 * "corrected" before anyone chose to correct them, against tenants nobody
 * authorized touching. That is precisely the failure mode the whole programme
 * has been guarding against, so it is asserted rather than intended.
 *
 * Also pinned: the audit must never call allocate_leave_balances(). That
 * reconciler is what actually rewrites stored balances, and invoking it from an
 * impact assessment would move the very numbers being measured.
 */
final class ReleaseAuditReadOnlyTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        // The UAT harness is deliberately EXCLUDED from the production release
        // (operator decision, 2026-08-20): it is CLI-gated test tooling that
        // writes to Firestore and does not belong in a shipped build. These
        // tests assert properties OF that harness, so they skip cleanly where it
        // is absent rather than failing a release build for a file that is
        // intentionally not there.
        $path = __DIR__ . '/../../application/controllers/Chd_uat.php';
        if (!is_file($path)) {
            $this->markTestSkipped('UAT harness not present in this build — excluded from the release by design.');
        }

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $this->src = $raw;
    }

    private function body(string $fn, string $vis = 'public'): string
    {
        $start = strpos($this->src, "{$vis} function {$fn}(");
        $this->assertNotFalse($start, "{$fn}() not found");

        $open = strpos($this->src, '{', $start);
        $depth = 0;
        for ($i = $open, $n = strlen($this->src); $i < $n; $i++) {
            if ($this->src[$i] === '{') $depth++;
            elseif ($this->src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($this->src, $open, $i - $open + 1);
            }
        }
        $this->fail("Unbalanced braces in {$fn}()");
    }

    /** @return string[] */
    private function writeCalls(): array
    {
        return [
            'guardedSet', 'guardedUpdate', 'guardedDelete',
            'firestoreSet', 'firestoreUpdate', 'firestoreDelete',
            '->set(', '->update(', '->delete(',
            'batchWrite', 'batchSet',
        ];
    }

    public function test_release_audit_performs_no_writes(): void
    {
        $body = $this->body('releaseaudit');
        foreach ($this->writeCalls() as $w) {
            $this->assertStringNotContainsString($w, $body,
                "releaseaudit() must be read-only — found '{$w}'");
        }
    }

    public function test_balance_analyser_performs_no_writes(): void
    {
        $body = $this->body('balanceimpact');
        foreach ($this->writeCalls() as $w) {
            $this->assertStringNotContainsString($w, $body,
                "balanceimpact() must be read-only — found '{$w}'");
        }
    }

    public function test_vacant_census_performs_no_writes(): void
    {
        $body = $this->body('vacantcensus');
        foreach ($this->writeCalls() as $w) {
            $this->assertStringNotContainsString($w, $body,
                "vacantcensus() must be read-only — found '{$w}'");
        }
    }

    public function test_provenance_probe_performs_no_writes(): void
    {
        $body = $this->body('probeacct');
        foreach ($this->writeCalls() as $w) {
            $this->assertStringNotContainsString($w, $body,
                "probeacct() must be read-only — found '{$w}'");
        }
    }

    /**
     * No analyser may invoke the reconciler that rewrites balances. Measuring
     * must never move what is being measured.
     */
    public function test_no_analyser_triggers_the_balance_reconciler(): void
    {
        // Matches an INVOCATION, not a mention: balanceimpact() deliberately
        // names the reconciler in its printed note ("balances are only rewritten
        // when allocate_leave_balances() next runs"), which is documentation,
        // not a call.
        foreach (['releaseaudit', 'balanceimpact', 'vacantcensus'] as $fn) {
            $body = $this->body($fn);
            $this->assertDoesNotMatchRegularExpression(
                '/(?:\$this->|->|::)\s*allocate_leave_balances\s*\(/',
                $body,
                "{$fn}() must not run the reconciler"
            );
        }
    }

    /**
     * The audit reports aggregates. A stray name or salary field would turn an
     * impact assessment into a data extract.
     */
    public function test_release_audit_reports_aggregates_not_records(): void
    {
        $body = $this->body('releaseaudit');

        foreach (['staff_name', 'staffName', 'net_pay', 'basic', 'gross'] as $field) {
            $this->assertStringNotContainsString("'{$field}'", $body,
                "releaseaudit() must not read per-employee field '{$field}'");
        }
    }

    /** Every write helper still asserts the school id — unchanged by this work. */
    public function test_write_guard_still_asserts_the_uat_school(): void
    {
        foreach (['guardedSet', 'guardedUpdate', 'guardedDelete'] as $fn) {
            $this->assertStringContainsString('assertUatSchool', $this->body($fn, 'private'),
                "{$fn}() must refuse writes outside the designated test school");
        }
    }
}
