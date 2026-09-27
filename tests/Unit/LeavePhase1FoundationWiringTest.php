<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 1 (Leave Year / Session Foundation)
 * wiring inside Hr.php, 2026-08-18.
 *
 * This phase is deliberately a FOUNDATION-ONLY phase: Leave_year_service is
 * fully built and unit-tested (LeaveYearServiceTest), and Hr.php gains two
 * small, read-only helpers for later phases to consume — but nothing in
 * apply_leave()/decide_leave()'s actual balance-key computation changes
 * yet. That is intentional: "do not migrate production balances yet unless
 * explicitly authorized" — flipping BAL_{staffId}_{year} to
 * BAL_{staffId}_{session} is Phase 2+ work, gated behind
 * LEAVE_SESSION_YEAR_ENABLED (default false).
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeavePhase1FoundationWiringTest extends TestCase
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

    private function functionBody(string $needle): string
    {
        $start = strpos($this->src, $needle);
        $this->assertNotFalse($start, "Expected to find: {$needle}");
        return $this->extractFunctionBody($this->src, $start);
    }

    public function test_leave_year_config_reads_optional_additive_fields_only(): void
    {
        $body = $this->functionBody('private function _leave_year_config(');
        $this->assertStringContainsString("\$school['academicYearStartMonth'] ?? 0", $body);
        $this->assertStringContainsString("\$school['sessionBoundaries'] ?? null", $body);
    }

    public function test_leave_year_config_defaults_to_the_centralised_april_constant(): void
    {
        $body = $this->functionBody('private function _leave_year_config(');
        $this->assertStringContainsString('\Leave_year_service::DEFAULT_START_MONTH', $body,
            'must reuse the one centralised constant, not a locally re-hardcoded 4.');
    }

    public function test_leave_year_config_fails_safe_to_defaults_on_read_error(): void
    {
        $body = $this->functionBody('private function _leave_year_config(');
        $tryPos = strpos($body, 'try {');
        $catchPos = strpos($body, 'catch (\Throwable $e) {');
        $this->assertNotFalse($tryPos);
        $this->assertNotFalse($catchPos);
        // $startMonth/$overrides are assigned their safe defaults BEFORE the
        // try block, so a thrown exception leaves them exactly as they were.
        $defaultAssignPos = strpos($body, '$startMonth = \Leave_year_service::DEFAULT_START_MONTH;');
        $this->assertLessThan($tryPos, $defaultAssignPos);
    }

    public function test_leave_flag_helper_resolves_purely_per_school(): void
    {
        // Repointed 2026-09-20: leave policy is now PER-SCHOOL. _leave_flag()
        // reads ONLY schools.leavePolicy[$name] (absent => OFF); the legacy
        // fleet-wide leave_policy_v2_flags fallback was CUT on the owner's
        // instruction (schools configure their own policy, multi-tenant).
        $body = $this->functionBody('private function _leave_flag(');
        $this->assertStringContainsString("\$doc['leavePolicy']", $body,
            'the flag must resolve from the per-school leavePolicy map');
        $this->assertStringContainsString('$lpBySchool[$sid][$name] ?? false', $body,
            'absent per-school value must default OFF');
        $this->assertStringNotContainsString("config->item(\$name, 'leave_policy_v2_flags')", $body,
            'the legacy fleet-wide config fallback must be gone');
    }

    public function test_apply_leave_and_decide_leave_untouched_by_phase_1(): void
    {
        // Phase 1 must not yet change balance-year keying — confirms the
        // existing raw date('Y', ...) computation in apply_leave() is
        // still exactly what decides the balance-lookup year.
        $applyBody = $this->functionBody('public function apply_leave(');
        $this->assertStringContainsString("\$year = date('Y', strtotime(\$fromDate));", $applyBody);
        $this->assertStringNotContainsString('Leave_year_service', $applyBody,
            'Phase 1 is foundation-only — apply_leave() must not consume the resolver yet.');

        $decideBody = $this->functionBody('public function decide_leave(');
        $this->assertStringContainsString("\$year     = date('Y', strtotime(\$fromDate));", $decideBody);
    }

    public function test_all_seven_phase_flags_present_and_default_off(): void
    {
        $flags = file_get_contents(__DIR__ . '/../../application/config/leave_policy_v2_flags.php');
        $this->assertNotFalse($flags);
        foreach ([
            'LEAVE_SESSION_YEAR_ENABLED', 'LEAVE_EFFECTIVE_DATING_ENABLED',
            'LEAVE_ACCRUAL_ENABLED', 'LEAVE_PRORATA_ENABLED',
            'LEAVE_WAITING_PERIOD_ENABLED', 'LEAVE_MAX_BALANCE_ENABLED',
            'LEAVE_YEAR_CLOSURE_ENABLED',
        ] as $flag) {
            $this->assertMatchesRegularExpression(
                "/\\\$config\\['{$flag}'\\]\\s*=\\s*false;/", $flags,
                "{$flag} must default false."
            );
        }
    }

    public function test_sessions_array_shape_consumers_untouched(): void
    {
        // The whole point of Phase 1's design: schools.sessions[] (bare
        // label strings) is never written by anything in this Leave
        // programme. Confirm Hr.php contains no write to that field.
        $this->assertStringNotContainsString("'sessions' =>", $this->src);
        $this->assertStringNotContainsString("'sessions']  =", $this->src);
    }
}
