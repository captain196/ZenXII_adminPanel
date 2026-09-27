<?php

declare(strict_types=1);

namespace Tests\Unit;

use Admission_age_policy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ADM-AGE-1 — behavioural tests for the class-vs-age rule.
 *
 * Unlike the source-guards in AdmissionInputGateTest, these call the real code
 * with real dates. That distinction is the point of extracting the policy: the
 * cut-off comparison decides who gets a seat, and "the file contains diff()" is
 * not assurance that it compares the right two things in the right direction.
 */
final class AdmissionAgePolicyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Admission_age_policy.php';
    }

    /** "6+ as on 31 March", the shape almost every Indian school uses. */
    private function policy(string $mode = 'block'): array
    {
        return [
            'mode'   => $mode,
            'cutoff' => '03-31',
            'bands'  => [
                'Class 1'  => ['min' => 6, 'max' => 8],
                'Nursery'  => ['min' => 3, 'max' => 5],
                'Class 11' => ['min' => 15, 'max' => null],   // min only
                'Class 12' => ['min' => null, 'max' => 20],   // max only
            ],
        ];
    }

    private function dob(string $ymd): DateTimeImmutable
    {
        return new DateTimeImmutable($ymd . ' 00:00:00');
    }

    // ══ THE BOUNDARY — this is the test that earns the extraction ════════

    /**
     * A child born ON the cut-off is exactly 6 and is admitted. A child born
     * one day later is 5 "as on 31 March" and waits a year.
     *
     * If age were measured from TODAY instead of the cut-off, both would pass
     * for most of the year and this school would be quietly out of step with
     * every other one in the state — and with its own UDISE+ return.
     */
    public function test_the_cutoff_boundary_is_exact(): void
    {
        $p = $this->policy();

        // Born 31-03-2020 -> exactly 6 on 31-03-2026. Inside the band.
        $this->assertNull(
            Admission_age_policy::evaluate($p, 'Class 1', $this->dob('2020-03-31'), '2026-27'),
            'a child who turns 6 ON the cut-off must be admitted'
        );

        // Born 01-04-2020 -> 5 on 31-03-2026. One day out.
        $v = Admission_age_policy::evaluate($p, 'Class 1', $this->dob('2020-04-01'), '2026-27');
        $this->assertIsArray($v, 'a child who turns 6 the DAY AFTER the cut-off is 5 for admission');
        $this->assertSame(5, $v['age']);
        $this->assertSame('2026-03-31', $v['cutoff']);
    }

    /** The cut-off follows the SESSION, never the calendar. */
    public function test_the_cutoff_year_comes_from_the_session(): void
    {
        $p = $this->policy();

        // Born June 2020: five at the 2026 cut-off (turns six that June), six at
        // the 2027 one. The same child, the same policy — only the session moves,
        // and it is the session that decides. This case was chosen after an
        // earlier version of this test asserted the wrong year and failed: the
        // arithmetic is genuinely easy to get wrong by one, which is the reason
        // this logic was extracted to be testable at all.
        $v2026 = Admission_age_policy::evaluate($p, 'Class 1', $this->dob('2020-06-01'), '2026-27');
        $v2027 = Admission_age_policy::evaluate($p, 'Class 1', $this->dob('2020-06-01'), '2027-28');

        $this->assertIsArray($v2026, 'five at the 2026 cut-off — below a 6-8 band');
        $this->assertSame(5, $v2026['age']);
        $this->assertSame('2026-03-31', $v2026['cutoff']);

        $this->assertNull($v2027, 'six at the 2027 cut-off — the same child now qualifies');
    }

    // ══ MODES ═══════════════════════════════════════════════════════════

    public function test_off_is_the_default_and_says_nothing(): void
    {
        $this->assertNull(Admission_age_policy::evaluate([], 'Class 1', $this->dob('2010-01-01'), '2026-27'));
        $this->assertNull(Admission_age_policy::evaluate(
            ['mode' => 'off'] + $this->policy(), 'Class 1', $this->dob('2010-01-01'), '2026-27'
        ));
    }

    /** Warn must report the same mismatch, at a severity that does not refuse. */
    public function test_warn_reports_without_refusing(): void
    {
        $v = Admission_age_policy::evaluate($this->policy('warn'), 'Class 1', $this->dob('2022-01-01'), '2026-27');

        $this->assertSame('warn', $v['severity']);
        $this->assertStringContainsString('Admitted anyway', $v['message']);
    }

    public function test_block_tells_the_operator_what_to_do(): void
    {
        $v = Admission_age_policy::evaluate($this->policy('block'), 'Class 1', $this->dob('2022-01-01'), '2026-27');

        $this->assertSame('block', $v['severity']);
        $this->assertStringContainsString('age-appropriate class', $v['message']);
    }

    // ══ BAND SHAPES ═════════════════════════════════════════════════════

    public function test_a_minimum_only_band_has_no_upper_limit(): void
    {
        $p = $this->policy();
        // 40 years old — no maximum was set for Class 11, so nothing to say.
        $this->assertNull(Admission_age_policy::evaluate($p, 'Class 11', $this->dob('1986-01-01'), '2026-27'));
        // 14 — below the minimum.
        $v = Admission_age_policy::evaluate($p, 'Class 11', $this->dob('2012-01-01'), '2026-27');
        $this->assertSame('15 or above', $this->bandTextOf($v));
    }

    public function test_a_maximum_only_band_has_no_lower_limit(): void
    {
        $p = $this->policy();
        $this->assertNull(Admission_age_policy::evaluate($p, 'Class 12', $this->dob('2020-01-01'), '2026-27'));
        $v = Admission_age_policy::evaluate($p, 'Class 12', $this->dob('2000-01-01'), '2026-27');
        $this->assertSame('20 or below', $this->bandTextOf($v));
    }

    /** A class with no band is exempt — that is how a school opts one out. */
    public function test_an_unbanded_class_is_exempt(): void
    {
        $this->assertNull(
            Admission_age_policy::evaluate($this->policy(), 'Class 7', $this->dob('1990-01-01'), '2026-27')
        );
    }

    /**
     * Admission passes "Class 9th"; a school may have banded "9th". Matching
     * only one spelling would give a policy that silently checks nothing —
     * which looks exactly like a policy that works.
     */
    public function test_both_class_spellings_match(): void
    {
        $bare = ['mode' => 'block', 'cutoff' => '03-31', 'bands' => ['9th' => ['min' => 13, 'max' => 16]]];
        $full = ['mode' => 'block', 'cutoff' => '03-31', 'bands' => ['Class 9th' => ['min' => 13, 'max' => 16]]];

        foreach ([$bare, $full] as $p) {
            $v = Admission_age_policy::evaluate($p, 'Class 9th', $this->dob('2020-01-01'), '2026-27');
            $this->assertIsArray($v, 'both "9th" and "Class 9th" must resolve to the same band');
        }
    }

    // ══ THE POLICY ITSELF MAY BE BAD ════════════════════════════════════

    /**
     * A cut-off that silently moves is worse than one that is ignored: 02-31
     * would roll to 3 March and shift every decision by two days without ever
     * saying so.
     */
    public function test_an_impossible_cutoff_is_refused_not_rolled(): void
    {
        $this->assertNull(
            Admission_age_policy::cutoffFor(['cutoff' => '02-31'], '2026-27'),
            '02-31 must be refused, never rolled forward to 3 March'
        );
        $this->assertNull(Admission_age_policy::cutoffFor(['cutoff' => '13-01'], '2026-27'));
        $this->assertNull(Admission_age_policy::cutoffFor(['cutoff' => 'nonsense'], '2026-27'));
    }

    /** An unreadable policy must never block an admission. */
    public function test_a_broken_policy_has_no_opinion(): void
    {
        $p = ['mode' => 'block', 'cutoff' => '02-31', 'bands' => ['Class 1' => ['min' => 6, 'max' => 8]]];
        $this->assertNull(
            Admission_age_policy::evaluate($p, 'Class 1', $this->dob('2022-01-01'), '2026-27'),
            'a policy that cannot be read must degrade to "no opinion", not to a refusal'
        );
    }

    public function test_a_missing_cutoff_defaults_to_31_march(): void
    {
        $this->assertSame('2026-03-31', Admission_age_policy::cutoffFor([], '2026-27')->format('Y-m-d'));
    }

    /** A DOB after the cut-off cannot report a positive age via diff(). */
    public function test_a_dob_after_the_cutoff_reports_zero_not_an_absolute_value(): void
    {
        $v = Admission_age_policy::evaluate($this->policy(), 'Class 1', $this->dob('2030-01-01'), '2026-27');

        $this->assertIsArray($v);
        $this->assertSame(0, $v['age'],
            'DateInterval reports absolute values, so a future DOB would otherwise read '
            . 'as a positive age and could fall INSIDE the band');
    }

    /** Helper: pull the band phrasing out of the message. */
    private function bandTextOf(array $v): string
    {
        $this->assertMatchesRegularExpression('~ is (.+?)\. ~', $v['message']);
        preg_match('~ is (.+?)\. ~', $v['message'], $m);
        return $m[1];
    }
}
