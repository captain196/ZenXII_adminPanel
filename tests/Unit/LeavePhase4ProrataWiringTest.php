<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ZenX Leave Policy Programme — Phase 4 (Pro-Rata Entitlement) wiring
 * inside Hr.php and Staff.php, 2026-08-18.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeavePhase4ProrataWiringTest extends TestCase
{
    private string $hrSrc;
    private string $staffSrc;

    private function strip(string $raw): string
    {
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    protected function setUp(): void
    {
        $hr = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($hr);
        $this->hrSrc = $this->strip($hr);

        $staff = file_get_contents(__DIR__ . '/../../application/controllers/Staff.php');
        $this->assertNotFalse($staff);
        $this->staffSrc = $this->strip($staff);
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

    // ── Hr.php: display-side wrapper delegates, does not duplicate math ──

    public function test_hr_prorate_wrapper_delegates_to_shared_service(): void
    {
        $start = strpos($this->hrSrc, 'private function _prorate_entitlement(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString('\Leave_year_service::prorateEntitlement($fullDaysPerYear, $jd, $xd, $year);', $body);
        // The math itself (DateTime diff, ratio, rounding) must NOT be
        // duplicated here — only date normalization + delegation.
        $this->assertStringNotContainsString('DateTime', $body,
            'the actual pro-ration math must live in ONE place (Leave_year_service), not be re-implemented per caller.');
    }

    public function test_derive_live_balances_prorate_applies_after_effective_dating_resolves(): void
    {
        $start = strpos($this->hrSrc, 'private function _deriveLiveBalances(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $prorataPos = strpos($body, "\$this->_leave_flag('LEAVE_PRORATA_ENABLED')");
        $verPos     = strpos($body, '\Leave_policy_service::effectiveVersion($lt, $resolveDate);');
        $this->assertNotFalse($prorataPos);
        $this->assertNotFalse($verPos);
        $this->assertLessThan($prorataPos, $verPos, 'pro-rata must compose AFTER effective-dating resolves which days_per_year is in force.');
    }

    public function test_derive_live_balances_allocated_stays_int_shaped_when_prorata_off(): void
    {
        $start = strpos($this->hrSrc, 'private function _deriveLiveBalances(');
        $body = $this->extractFunctionBody($this->hrSrc, $start);
        $this->assertStringContainsString("\$allocated = (int) (\$lt['days_per_year'] ?? 0);", $body,
            'the JSON shape of `allocated` must stay identical to before this phase unless pro-rata is explicitly on — mobile app deserializers may be typed against the historical int shape.');
    }

    // ── Staff.php: the actual balance-gating choke point ────────────────

    public function test_staff_new_staff_reads_prorata_flag_before_seeding_balance(): void
    {
        // Repointed 2026-09-20: pro-rata is now a PER-SCHOOL policy, resolved
        // from schools.leavePolicy.LEAVE_PRORATA_ENABLED (same key Hr::_leave_flag
        // reads); the legacy fleet-wide config fallback was cut on owner instruction.
        $this->assertStringContainsString("\$lp['LEAVE_PRORATA_ENABLED'] ?? false;", $this->staffSrc,
            'the new-hire seed must read pro-rata from the per-school leavePolicy');
        $this->assertStringNotContainsString("\$this->config->item('LEAVE_PRORATA_ENABLED', 'leave_policy_v2_flags')", $this->staffSrc,
            'the legacy fleet-wide config read must be gone from new-staff seeding');
    }

    public function test_staff_prorata_fails_safe_to_off_on_config_error(): void
    {
        // $prorataOn is initialised false BEFORE the try/catch, so a thrown
        // read error (now the per-school schools.leavePolicy fetch, not a config
        // load) leaves it exactly as it started — fails safe, never fails open
        // into an unproven pro-ration.
        $initPos  = strpos($this->staffSrc, '$prorataOn = false;');
        $catchPos = strpos($this->staffSrc, 'catch (\Throwable $e) {', $initPos !== false ? $initPos : 0);
        $this->assertNotFalse($initPos);
        $this->assertNotFalse($catchPos);
        $this->assertLessThan($catchPos, $initPos);
    }

    public function test_staff_uses_the_same_shared_service_as_hr(): void
    {
        $this->assertStringContainsString('\Leave_year_service::prorateEntitlement(', $this->staffSrc,
            'Staff.php must call the SAME function Hr.php does — never a second, independently-written pro-ration formula.');
    }

    public function test_staff_seed_alloc_unchanged_when_flag_off(): void
    {
        // The exact pre-Phase-4 line must still be the value used when
        // prorataOn is false — confirms zero behavior change by default.
        $this->assertStringContainsString("\$alloc = (int) (\$lt['days_per_year'] ?? 0);", $this->staffSrc);
        $this->assertStringContainsString('if ($prorataOn && $joinDateForProrate) {', $this->staffSrc);
    }

    public function test_staff_prorata_only_uses_join_date_not_exit_date(): void
    {
        // A brand-new staff record cannot have an exit date yet — passing
        // null for it here is correct, not an oversight.
        $callPos = strpos($this->staffSrc, '\Leave_year_service::prorateEntitlement(');
        $this->assertNotFalse($callPos);
        $call = substr($this->staffSrc, $callPos, 160);
        $this->assertStringContainsString('(float) $alloc', $call);
        $this->assertStringContainsString('$joinDateForProrate', $call);
        $this->assertStringContainsString('null', $call);
        $this->assertStringContainsString("date('Y')", $call);
    }
}
