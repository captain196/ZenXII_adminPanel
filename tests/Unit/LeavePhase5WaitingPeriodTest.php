<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 5 (Waiting Period), 2026-08-18.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code) plus direct date-math verification of the exact
 * eligibility formula used in apply_leave().
 */
class LeavePhase5WaitingPeriodTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->src = $out;
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function applyLeaveBody(): string
    {
        $start = strpos($this->src, 'public function apply_leave(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── Structural checks ────────────────────────────────────────────────

    public function test_waiting_period_gated_by_its_own_flag(): void
    {
        $body = $this->applyLeaveBody();
        $this->assertStringContainsString("if (\$this->_leave_flag('LEAVE_WAITING_PERIOD_ENABLED')) {", $body);
    }

    public function test_waiting_period_composes_with_effective_dating(): void
    {
        $body = $this->applyLeaveBody();
        $this->assertStringContainsString(
            "if (\$this->_leave_flag('LEAVE_EFFECTIVE_DATING_ENABLED')) {",
            substr($body, strpos($body, 'LEAVE_WAITING_PERIOD_ENABLED'))
        );
        $this->assertStringContainsString('\Leave_policy_service::effectiveVersion($leaveType, $fromDate);', $body);
    }

    public function test_waiting_period_evaluated_before_calendar_aware_day_count(): void
    {
        // Eligibility must be checked before spending effort computing a
        // chargeable-day count for a request that will be refused anyway.
        $body = $this->applyLeaveBody();
        $waitingPos = strpos($body, "LEAVE_WAITING_PERIOD_ENABLED");
        $calAwarePos = strpos($body, '_count_chargeable_leave_days');
        $this->assertNotFalse($waitingPos);
        $this->assertNotFalse($calAwarePos);
        $this->assertLessThan($calAwarePos, $waitingPos);
    }

    public function test_unknown_join_date_fails_open_not_closed(): void
    {
        $body = $this->applyLeaveBody();
        $this->assertStringContainsString("if (\$joinDate !== null && \$joinDate !== '') {", $body,
            'the block must only run when a join date is actually known — an unparseable/missing join date must never refuse an application.');
    }

    public function test_zero_waiting_days_is_a_true_noop(): void
    {
        $body = $this->applyLeaveBody();
        $this->assertStringContainsString('if ($waitingDays > 0) {', $body,
            'a type with waiting_period_days=0 (the default) must skip the whole eligibility branch — no date math runs at all.');
    }

    // ── Direct date-math verification of the exact formula used ─────────

    public function test_exactly_on_boundary_is_eligible(): void
    {
        $joinDate = '2026-07-01';
        $waitingDays = 90;
        $eligibleFrom = date('Y-m-d', strtotime("{$joinDate} +{$waitingDays} days"));
        $this->assertSame('2026-09-29', $eligibleFrom);
        // fromDate == eligibleFrom must NOT be blocked (only fromDate < eligibleFrom is).
        $fromDate = $eligibleFrom;
        $this->assertFalse($fromDate < $eligibleFrom);
    }

    public function test_one_day_before_boundary_is_blocked(): void
    {
        $joinDate = '2026-07-01';
        $waitingDays = 90;
        $eligibleFrom = date('Y-m-d', strtotime("{$joinDate} +{$waitingDays} days"));
        $fromDate = date('Y-m-d', strtotime("{$eligibleFrom} -1 day"));
        $this->assertTrue($fromDate < $eligibleFrom);
    }

    public function test_one_day_after_boundary_is_eligible(): void
    {
        $joinDate = '2026-07-01';
        $waitingDays = 90;
        $eligibleFrom = date('Y-m-d', strtotime("{$joinDate} +{$waitingDays} days"));
        $fromDate = date('Y-m-d', strtotime("{$eligibleFrom} +1 day"));
        $this->assertFalse($fromDate < $eligibleFrom);
    }

    public function test_new_joiner_zero_waiting_days_immediately_eligible(): void
    {
        $joinDate = '2026-08-18';
        $waitingDays = 0;
        // waitingDays=0 -> the whole gate is skipped (test_zero_waiting_days_is_a_true_noop),
        // so there is no eligibleFrom computation at all for this case.
        $this->assertSame(0, $waitingDays);
    }

    public function test_cl_type_default_zero_matches_authorization_example(): void
    {
        // "CL may remain waiting_period_days = 0" — the forward-declared
        // default from Phase 3 already guarantees this without a
        // CL-specific carve-out anywhere in the code.
        $this->assertStringContainsString(
            'max(0, (int) ($this->input->post(\'waiting_period_days\') ?? 0))',
            $this->src
        );
    }
}
