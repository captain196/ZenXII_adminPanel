<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Behavioural cover for the F&F notice-period input resolver.
 *
 * Executes `Fnf_notice_resolver` directly — it is pure. The resolver returns
 * DAYS and a recoverability verdict; what a shortfall day is *worth* is D-002
 * Q5 and is deliberately absent from the class.
 *
 * The interesting boundary is what the resolver will and will not decide:
 *
 *   `waived`   it DOES rule out recovery — to waive is to relinquish the claim,
 *              and the system offers `reduce` separately precisely because that
 *              means something else. Reading the recorded decision is not
 *              inventing policy.
 *   `reduced`  it REFUSES to rule. A reduced notice may mean the requirement was
 *              shortened (nothing owed) or that the balance was bought out
 *              (owed). ZenX stores no field telling the two apart, so the
 *              resolver returns `policy_required` rather than guessing.
 *
 * That asymmetry is the point of this suite.
 */
final class FnfNoticeResolverTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Fnf_notice_resolver.php';
    }

    /** A 30-day notice: 1 Mar → 30 Mar inclusive. */
    private function notice(string $status, ?string $actual = null): array
    {
        return [
            'status'                 => $status,
            'noticeStartDate'        => '2026-03-01',
            'expectedLastWorkingDay' => '2026-03-30',
            'actualLastWorkingDay'   => $actual,
        ];
    }

    // ── Day arithmetic ───────────────────────────────────────────────────

    public function test_inclusive_days_counts_both_endpoints(): void
    {
        $this->assertSame(1, Fnf_notice_resolver::inclusiveDays('2026-03-01', '2026-03-01'));
        $this->assertSame(30, Fnf_notice_resolver::inclusiveDays('2026-03-01', '2026-03-30'));
    }

    public function test_a_reversed_window_is_zero_not_negative(): void
    {
        $this->assertSame(0, Fnf_notice_resolver::inclusiveDays('2026-03-30', '2026-03-01'));
    }

    public function test_malformed_dates_yield_zero(): void
    {
        foreach ([['', '2026-03-30'], ['2026-3-1', '2026-03-30'], ['today', 'tomorrow']] as [$a, $b]) {
            $this->assertSame(0, Fnf_notice_resolver::inclusiveDays($a, $b));
        }
    }

    // ── Full notice served ───────────────────────────────────────────────

    public function test_an_approved_notice_has_no_shortfall(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('approved', '2026-03-30'));

        $this->assertSame(30, $r['requiredDays']);
        $this->assertSame(30, $r['servedDays']);
        $this->assertSame(0, $r['shortfallDays']);
        $this->assertFalse($r['recoverable']);
        $this->assertSame('no_shortfall', $r['recoverability']);
    }

    /** Serving longer than required is not a negative shortfall. */
    public function test_serving_beyond_the_expected_date_is_not_a_credit(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('approved', '2026-04-15'));

        $this->assertSame(30, $r['servedDays'], 'served is capped at required');
        $this->assertSame(0, $r['shortfallDays'], 'nobody owes anybody for extra notice');
    }

    // ── The last working day has one authority ───────────────────────────

    public function test_the_actual_last_working_day_wins_when_present(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('reduced', '2026-03-10'));

        $this->assertSame('2026-03-10', $r['lastWorkingDay']);
    }

    public function test_it_falls_back_to_the_expected_date_not_a_re_derivation(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('approved', null));

        $this->assertSame('2026-03-30', $r['lastWorkingDay'],
            're-deriving this from the resignation date is how two surfaces drift');
        $this->assertSame(0, $r['shortfallDays']);
    }

    // ── Shortfall ────────────────────────────────────────────────────────

    public function test_leaving_early_produces_a_day_shortfall(): void
    {
        // 1 Mar → 10 Mar is 10 days served of 30 required.
        $r = Fnf_notice_resolver::resolve($this->notice('reduced', '2026-03-10'));

        $this->assertSame(30, $r['requiredDays']);
        $this->assertSame(10, $r['servedDays']);
        $this->assertSame(20, $r['shortfallDays']);
    }

    public function test_a_pending_notice_with_a_shortfall_is_recoverable(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('pending', '2026-03-10'));

        $this->assertSame(20, $r['shortfallDays']);
        $this->assertTrue($r['recoverable']);
        $this->assertSame('shortfall_stands', $r['recoverability']);
    }

    // ── What the resolver DOES decide ────────────────────────────────────

    /**
     * To waive is to relinquish the claim. The system offers `reduce` as a
     * distinct decision precisely because it means something else, so reading
     * `waived` as "not recoverable" is reading the record, not inventing policy.
     */
    public function test_a_waived_notice_is_not_recoverable_despite_the_shortfall(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('waived', '2026-03-10'));

        $this->assertSame(20, $r['shortfallDays'], 'the days are still counted and visible');
        $this->assertFalse($r['recoverable'], 'but the employer gave up the claim');
        $this->assertSame('waived_or_void', $r['recoverability']);
    }

    public function test_a_void_notice_is_not_recoverable(): void
    {
        foreach (['rejected', 'withdrawn', 'cancelled'] as $s) {
            $r = Fnf_notice_resolver::resolve($this->notice($s, '2026-03-10'));

            $this->assertFalse($r['recoverable'], "'{$s}' means no notice ran");
            $this->assertSame('waived_or_void', $r['recoverability']);
        }
    }

    // ── What it REFUSES to decide ────────────────────────────────────────

    /**
     * THE ASYMMETRY. A reduced notice may mean the requirement was shortened
     * (nothing owed) or that the balance was bought out (owed). ZenX stores no
     * field telling those apart, so the resolver reports the days and defers.
     */
    public function test_a_reduced_notice_defers_to_policy(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('reduced', '2026-03-10'));

        $this->assertSame(20, $r['shortfallDays'], 'the fact is reported');
        $this->assertSame('policy_required', $r['recoverability'], 'the verdict is not');
        $this->assertFalse($r['recoverable'],
            'defaulting to recoverable would charge someone on an unresolved rule');
    }

    /** A reduced notice with no shortfall needs no policy at all. */
    public function test_a_reduced_notice_fully_served_needs_no_ruling(): void
    {
        $r = Fnf_notice_resolver::resolve($this->notice('reduced', '2026-03-30'));

        $this->assertSame(0, $r['shortfallDays']);
        $this->assertSame('no_shortfall', $r['recoverability']);
    }

    // ── Degenerate input ─────────────────────────────────────────────────

    public function test_an_empty_notice_yields_zeroes_not_an_error(): void
    {
        $r = Fnf_notice_resolver::resolve([]);

        $this->assertSame(0, $r['requiredDays']);
        $this->assertSame(0, $r['shortfallDays']);
        $this->assertNull($r['lastWorkingDay']);
        $this->assertFalse($r['recoverable']);
    }

    public function test_a_missing_start_date_cannot_manufacture_a_shortfall(): void
    {
        $r = Fnf_notice_resolver::resolve([
            'status'                 => 'pending',
            'expectedLastWorkingDay' => '2026-03-30',
            'actualLastWorkingDay'   => '2026-03-10',
        ]);

        $this->assertSame(0, $r['requiredDays']);
        $this->assertSame(0, $r['shortfallDays'],
            'with no start date there is no basis to charge anyone');
    }

    public function test_status_matching_is_case_insensitive_and_trimmed(): void
    {
        foreach (['WAIVED', ' waived ', 'Waived'] as $s) {
            $r = Fnf_notice_resolver::resolve($this->notice($s, '2026-03-10'));
            $this->assertSame('waived_or_void', $r['recoverability'], "'{$s}' must be recognised");
        }
    }

    // ── The boundary: days, never money ──────────────────────────────────

    /** D-002 Q5 — the recovery BASIS — must not appear in an input resolver. */
    public function test_the_resolver_computes_no_money(): void
    {
        $src = file_get_contents(__DIR__ . '/../../application/libraries/Fnf_notice_resolver.php');
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

        $this->assertStringContainsString('function resolve', $code, 'comment-stripping sanity');

        foreach (['basic', 'gross', 'ctc', 'salary', 'amount', 'paise', 'rate'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $code,
                "the recovery basis is D-002 Q5 and must not be pre-empted here ({$needle})");
        }
    }
}
