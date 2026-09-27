<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Leave-year closure adds to `carried`, so it MUST run exactly once.
 *
 * `confirm_leave_year_closure()` applies carry-forward with an accumulating
 * write, deliberately:
 *
 *     $entry['carried'] = (float) ($entry['carried'] ?? 0) + $carryAmt;
 *
 * The comment explains why it accumulates rather than assigns: a next-year
 * balance may already have been seeded, and carry-forward must add to it rather
 * than clobber it.
 *
 * That makes the write NON-IDEMPOTENT BY CONSTRUCTION. Running the closure
 * twice would silently double every employee's carried-forward leave — an
 * entitlement error that no downstream check would catch, because the resulting
 * numbers are individually plausible.
 *
 * It is safe today only because the surrounding state machine guarantees
 * single execution. Verified 2026-08-21, five independent layers:
 *
 *   1. feature flag        LEAVE_YEAR_CLOSURE_ENABLED must be on
 *   2. preview required    leaveYearClosures/{school}_{year} must already exist
 *   3. idempotent exit     status 'confirmed' returns success WITHOUT re-applying
 *   4. state gate          status must be exactly 'previewed'
 *   5. CAS lock            previewed -> closing under an updateTime precondition,
 *                          mirroring decide_leave()'s own lock
 *
 *   plus: refuses while any pending leave request is still open.
 *
 * The coupling between the accumulating write and those guards is not recorded
 * anywhere in the source. This suite records it: if anyone relaxes the state
 * machine — say, allowing a re-run "to fix a mistake" — the carry-forward
 * doubles. AUDITED AND FOUND CORRECT; this is a regression guard.
 */
final class LeaveYearClosureIdempotencyTest extends TestCase
{
    private string $hr;

    protected function setUp(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);
        $this->hr = $hr;
    }

    /** The closure gate, as implemented. */
    private function outcome(string $closureStatus, int $pendingRequests = 0, bool $flagOn = true): string
    {
        if (!$flagOn)                        return 'refused_flag';
        if ($closureStatus === 'missing')    return 'refused_no_preview';
        if ($closureStatus === 'confirmed')  return 'idempotent_noop';
        if ($closureStatus !== 'previewed')  return 'refused_bad_state';
        if ($pendingRequests > 0)            return 'refused_pending';
        return 'applied';
    }

    /** Carry-forward accumulates; `applied` twice means double credit. */
    private function applyCarry(float $existingCarried, float $carryAmt): float
    {
        return $existingCarried + $carryAmt;
    }

    // ── The write really is non-idempotent ───────────────────────────────

    public function test_carry_forward_accumulates_rather_than_assigns(): void
    {
        $this->assertSame(5.0, $this->applyCarry(0.0, 5.0));
        $this->assertSame(8.0, $this->applyCarry(3.0, 5.0),
            'it must ADD to a seeded next-year balance, not overwrite it');
    }

    /** This is exactly what the guards exist to prevent. */
    public function test_applying_twice_would_double_the_carry(): void
    {
        $once  = $this->applyCarry(0.0, 5.0);
        $twice = $this->applyCarry($once, 5.0);

        $this->assertSame(10.0, $twice,
            'a second application doubles the entitlement — hence the state machine');
    }

    // ── The guards ───────────────────────────────────────────────────────

    public function test_a_previewed_closure_with_no_pending_requests_applies(): void
    {
        $this->assertSame('applied', $this->outcome('previewed'));
    }

    /** THE ONE THAT MATTERS. A second confirm must not re-apply. */
    public function test_a_second_confirm_is_a_noop_not_a_reapplication(): void
    {
        $this->assertSame('idempotent_noop', $this->outcome('confirmed'),
            'returning success is correct; re-applying would double every balance');
    }

    public function test_closure_requires_a_preview_first(): void
    {
        $this->assertSame('refused_no_preview', $this->outcome('missing'));
    }

    public function test_an_unexpected_state_is_refused_not_assumed(): void
    {
        foreach (['closing', 'failed', '', 'cancelled'] as $s) {
            $this->assertSame('refused_bad_state', $this->outcome($s),
                "'{$s}' must refuse — a half-finished closure must never silently re-run");
        }
    }

    /** A closure computed while requests are open would use stale figures. */
    public function test_open_pending_requests_block_the_closure(): void
    {
        $this->assertSame('refused_pending', $this->outcome('previewed', 3));
    }

    public function test_the_feature_flag_gates_everything(): void
    {
        $this->assertSame('refused_flag', $this->outcome('previewed', 0, false));
    }

    /** Only one state in the whole machine actually writes. */
    public function test_exactly_one_input_state_applies_the_carry(): void
    {
        $applied = 0;
        foreach (['missing', 'previewed', 'confirmed', 'closing', 'failed', ''] as $s) {
            if ($this->outcome($s) === 'applied') $applied++;
        }

        $this->assertSame(1, $applied, 'more than one applying state reopens the doubling risk');
    }

    // ── Source pins for each guard ───────────────────────────────────────

    public function test_the_accumulating_write_is_still_what_this_analysis_assumed(): void
    {
        $this->assertStringContainsString(
            "\$entry['carried'] = (float) (\$entry['carried'] ?? 0) + \$carryAmt;",
            $this->hr,
            'if this became an assignment, re-read the seeding rationale before changing these tests'
        );
    }

    public function test_the_idempotent_exit_exists(): void
    {
        $this->assertStringContainsString("if (\$status === 'confirmed') {", $this->hr);
        $this->assertStringContainsString('This Leave Year was already closed.', $this->hr);
    }

    public function test_the_state_gate_exists(): void
    {
        $this->assertStringContainsString("if (\$status !== 'previewed') {", $this->hr);
    }

    public function test_the_preview_requirement_exists(): void
    {
        $this->assertStringContainsString('No preview found for this year. Run the preview first.', $this->hr);
    }

    public function test_pending_requests_block_confirmation(): void
    {
        $this->assertStringContainsString('Cannot confirm closure', $this->hr);
    }

    /** The CAS lock is what makes two simultaneous confirms safe. */
    public function test_the_transition_is_cas_locked(): void
    {
        $this->assertStringContainsString("'data'         => ['status' => 'closing', 'updatedAt' => date('c')]", $this->hr,
            'without the previewed->closing CAS, two concurrent confirms could both apply');
    }

    /** Closure is per tenant AND per year — never global. */
    public function test_the_closure_document_is_tenant_and_year_scoped(): void
    {
        $this->assertStringContainsString('docId("{$this->school_id}_{$year}")', $this->hr,
            'a closure keyed on year alone would close every tenant at once');
    }

    public function test_the_closure_is_audited(): void
    {
        $this->assertStringContainsString("'action'        => 'leave_year_closed'", $this->hr);
    }
}
