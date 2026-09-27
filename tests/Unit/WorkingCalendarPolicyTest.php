<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
require_once __DIR__ . '/../../application/libraries/Working_calendar_service.php';

/**
 * N-23 / P-021 — canonical working-week policy.
 *
 * THE PRODUCT ASSERTION
 * ---------------------
 * The school decides its working week; payroll consumes it. So the decisive
 * tests are the ones where the SAME month resolves to DIFFERENT calendars purely
 * because the tenant configured a different policy — with no code change.
 *
 * And the safety assertion: a tenant that has configured NOTHING is BLOCKED.
 * Never defaulted, never "no offs", never Sunday + 2nd/4th Saturday.
 *
 * Reference month throughout: MAY 2026 — 31 days, starts Friday.
 *   Sundays        : 3, 10, 17, 24, 31
 *   Saturdays      : 2 (1st), 9 (2nd), 16 (3rd), 23 (4th), 30 (5th)
 * This is the month of the PR0003 evidence, so the numbers tie back to the
 * live defect.
 */
class WorkingCalendarPolicyTest extends TestCase
{
    private const Y = 2026;
    private const M = 5;
    private const DIM = 31;

    private function fullMonthWindow(): array
    {
        return array_fill_keys(range(1, self::DIM), true);
    }

    private function policy(array $versions): array
    {
        return ['workingWeek' => ['versions' => $versions]];
    }

    private function resolve(?array $policy, array $holidays = [], ?array $window = null): array
    {
        return \Working_calendar_service::resolveForMonth(
            $policy, self::Y, self::M, $holidays, $window ?? $this->fullMonthWindow()
        );
    }

    // ── UNCONFIGURED = BLOCKED. Never defaulted. ─────────────────────────

    public function test_unconfigured_tenant_is_blocked_not_defaulted(): void
    {
        foreach ([null, [], ['workingWeek' => []], ['workingWeek' => ['versions' => []]]] as $pol) {
            $r = $this->resolve($pol);
            $this->assertTrue($r['blocked'], 'an unconfigured tenant must block');
            $this->assertFalse($r['configured']);
            $this->assertSame(0, $r['workingCount'], 'no calendar may be invented');
            $this->assertStringContainsString('not configured', $r['blockReason']);
        }
    }

    /**
     * The explicit product decision: the legacy rule must NOT reappear as a
     * hidden fallback. If it did, an unconfigured tenant would silently resolve
     * to 24 working days in May 2026 — exactly what this gate forbids.
     */
    public function test_the_legacy_rule_is_never_used_as_a_fallback(): void
    {
        $r = $this->resolve(null);
        $this->assertNotSame(24, $r['workingCount'],
            'unconfigured must NOT silently resolve to the legacy Sun + 2nd/4th Sat calendar');
        $this->assertSame('unconfigured', $r['policySource']);
    }

    public function test_a_policy_version_with_no_offs_is_treated_as_unconfigured(): void
    {
        // An empty descriptor would mean "every calendar day is payable",
        // which would pay a full month to an absent employee.
        $r = $this->resolve($this->policy([
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => [], 'nthWeekdayOffs' => []],
        ]));
        $this->assertTrue($r['blocked']);
    }

    public function test_the_service_source_contains_no_hardcoded_fallback_rule(): void
    {
        // Comments stripped: the legacy rule is legitimately DESCRIBED in the
        // class docblock and in legacyRuleDescription(). What must not exist is
        // executable logic branching on it.
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Working_calendar_service.php');
        $code = '';
        foreach (token_get_all($src) as $tok) {
            if (is_array($tok)) {
                if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) continue;
                $code .= $tok[1];
            } else {
                $code .= $tok;
            }
        }

        // The only surviving mention of the legacy rule may be the string inside
        // legacyRuleDescription(); nothing may branch on a weekday or week number.
        $exec = substr($code, 0, strpos($code, 'function legacyRuleDescription'));

        foreach (['satCount', 'Sunday', '=== 2', '=== 4'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $exec,
                "the resolver must not hardcode '{$legacy}' — the policy supplies it");
        }

        // `Sat`/`Sun` DO appear, in `const DAYS` — that is the weekday
        // VOCABULARY the policy is expressed in, not a rule about which days
        // are off. The distinction is what matters: all seven must be present
        // and none singled out.
        $this->assertSame(
            ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            \Working_calendar_service::DAYS,
            'DAYS is a complete vocabulary, not a subset encoding a policy'
        );
    }

    // ── DYNAMIC POLICY — same month, different tenants, different calendars ──

    public function test_policy_A_sunday_only(): void
    {
        $r = $this->resolve($this->policy([
            ['effectiveFrom' => '2026-01-01', 'version' => 1, 'weeklyOffs' => ['Sun']],
        ]));
        // 31 days − 5 Sundays
        $this->assertSame(26, $r['workingCount']);
        $this->assertFalse(isset($r['isWorking'][3]));   // Sunday
        $this->assertTrue(isset($r['isWorking'][9]));    // 2nd Saturday — WORKING here
    }

    public function test_policy_B_saturday_and_sunday(): void
    {
        $r = $this->resolve($this->policy([
            ['effectiveFrom' => '2026-01-01', 'version' => 1, 'weeklyOffs' => ['Sat', 'Sun']],
        ]));
        // 31 − 5 Sundays − 5 Saturdays
        $this->assertSame(21, $r['workingCount']);
        $this->assertFalse(isset($r['isWorking'][2]));   // 1st Saturday off too
    }

    public function test_policy_C_sunday_plus_2nd_and_4th_saturday(): void
    {
        $r = $this->resolve($this->policy([
            ['effectiveFrom' => '2026-01-01', 'version' => 1, 'weeklyOffs' => ['Sun'],
             'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]],
        ]));
        // 31 − 5 Sundays − Sat 9 (2nd) − Sat 23 (4th) = 24
        $this->assertSame(24, $r['workingCount'], 'matches the PR0003 evidence month');
        $this->assertFalse(isset($r['isWorking'][9]));
        $this->assertFalse(isset($r['isWorking'][23]));
        $this->assertTrue(isset($r['isWorking'][2]));    // 1st Sat working
        $this->assertTrue(isset($r['isWorking'][16]));   // 3rd Sat working
        $this->assertTrue(isset($r['isWorking'][30]));   // 5th Sat working
    }

    public function test_policy_D_sunday_plus_1st_3rd_5th_saturday(): void
    {
        $r = $this->resolve($this->policy([
            ['effectiveFrom' => '2026-01-01', 'version' => 1, 'weeklyOffs' => ['Sun'],
             'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [1, 3, 5]]]],
        ]));
        // 31 − 5 Sundays − Sat 2, 16, 30 = 23
        $this->assertSame(23, $r['workingCount']);
        $this->assertFalse(isset($r['isWorking'][2]));
        $this->assertFalse(isset($r['isWorking'][30]));
        $this->assertTrue(isset($r['isWorking'][9]));    // 2nd Sat working here
    }

    /**
     * THE PRODUCT PROOF: four tenants, one month, one engine, four answers —
     * and not a line of payroll code differs between them.
     */
    public function test_the_same_month_resolves_differently_per_tenant_policy(): void
    {
        $counts = [];
        foreach ([
            'A_sun'          => [['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']]],
            'B_sat_sun'      => [['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sat', 'Sun']]],
            'C_sun_2_4_sat'  => [['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun'],
                                  'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]]],
            'D_sun_1_3_5sat' => [['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun'],
                                  'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [1, 3, 5]]]]],
        ] as $name => $versions) {
            $counts[$name] = $this->resolve($this->policy($versions))['workingCount'];
        }

        $this->assertSame(['A_sun' => 26, 'B_sat_sun' => 21, 'C_sun_2_4_sat' => 24,
                           'D_sun_1_3_5sat' => 23], $counts);
        $this->assertCount(4, array_unique($counts), 'each policy must yield a distinct calendar');
    }

    // ── Holidays stay distinct from weekly offs (§9) ─────────────────────

    public function test_holidays_are_classified_separately_from_weekly_offs(): void
    {
        $r = $this->resolve(
            $this->policy([['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']]]),
            [15 => 'Founders Day']
        );
        $this->assertSame(\Working_calendar_service::REASON_HOLIDAY, $r['reason'][15]);
        $this->assertSame(\Working_calendar_service::REASON_WEEKLY_OFF, $r['reason'][3]);
        $this->assertSame(25, $r['workingCount']);   // 26 − 1 holiday
    }

    public function test_a_holiday_falling_on_a_weekly_off_is_not_double_counted(): void
    {
        $r = $this->resolve(
            $this->policy([['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']]]),
            [3 => 'Holiday on a Sunday']
        );
        $this->assertSame(26, $r['workingCount'], 'still 26 — the Sunday was already off');
        $this->assertSame(\Working_calendar_service::REASON_WEEKLY_OFF, $r['reason'][3]);
    }

    // ── Employment window ────────────────────────────────────────────────

    public function test_days_outside_the_employment_window_are_marked_outside(): void
    {
        $r = $this->resolve(
            $this->policy([['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']]]),
            [], array_fill_keys(range(16, 31), true)   // joined on the 16th
        );
        $this->assertSame(\Working_calendar_service::REASON_OUTSIDE, $r['reason'][1]);
        $this->assertSame(\Working_calendar_service::MASK_OUTSIDE, $r['workingMask'][0]);
        // 16..31 minus Sundays 17, 24, 31
        $this->assertSame(13, $r['workingCount']);
    }

    public function test_rehire_gap_is_outside_and_not_working(): void
    {
        $window = array_fill_keys(array_merge(range(1, 5), range(20, 31)), true);
        $r = $this->resolve(
            $this->policy([['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun']]]),
            [], $window
        );
        $this->assertSame(\Working_calendar_service::REASON_OUTSIDE, $r['reason'][10]);
        $this->assertFalse(isset($r['isWorking'][10]));
    }

    /**
     * nth-weekday counting must be month-absolute, not window-relative: the
     * "2nd Saturday" is the month's 2nd Saturday even for someone who joined
     * on the 16th.
     */
    public function test_nth_weekday_counts_from_the_month_not_the_window(): void
    {
        $r = $this->resolve(
            $this->policy([['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun'],
                            'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [4]]]]]),
            [], array_fill_keys(range(16, 31), true)
        );
        $this->assertFalse(isset($r['isWorking'][23]), 'day 23 is the month\'s 4th Saturday');
        $this->assertTrue(isset($r['isWorking'][16]), 'day 16 is the 3rd Saturday — working');
    }

    // ── Effective dating / versioning (N-29) ─────────────────────────────

    public function test_the_version_effective_for_the_period_is_selected(): void
    {
        $pol = $this->policy([
            ['effectiveFrom' => '2026-01-01', 'version' => 1, 'weeklyOffs' => ['Sun'],
             'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]],
            ['effectiveFrom' => '2026-07-01', 'version' => 2, 'weeklyOffs' => ['Sat', 'Sun']],
        ]);

        // May 2026 → V1 (V2 is not yet effective)
        $may = $this->resolve($pol);
        $this->assertSame(1, $may['policyVersion']);
        $this->assertSame(24, $may['workingCount']);

        // August 2026 → V2
        $aug = \Working_calendar_service::resolveForMonth(
            $pol, 2026, 8, [], array_fill_keys(range(1, 31), true));
        $this->assertSame(2, $aug['policyVersion']);
    }

    /**
     * The reproducibility guarantee: adding a NEW version today must not change
     * what a closed period resolves to.
     */
    public function test_adding_a_future_version_does_not_alter_a_closed_period(): void
    {
        $v1 = ['effectiveFrom' => '2026-01-01', 'version' => 1, 'weeklyOffs' => ['Sun'],
               'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]];

        $before = $this->resolve($this->policy([$v1]));
        $after  = $this->resolve($this->policy([
            $v1, ['effectiveFrom' => '2026-07-01', 'version' => 2, 'weeklyOffs' => ['Sat', 'Sun']],
        ]));

        $this->assertSame($before['workingCount'], $after['workingCount']);
        $this->assertSame($before['workingMask'], $after['workingMask']);
        $this->assertSame($before['policyVersion'], $after['policyVersion']);
    }

    public function test_a_period_before_any_policy_is_blocked(): void
    {
        $r = \Working_calendar_service::resolveForMonth(
            $this->policy([['effectiveFrom' => '2026-07-01', 'weeklyOffs' => ['Sun']]]),
            2026, 5, [], array_fill_keys(range(1, 31), true));
        $this->assertTrue($r['blocked'], 'no version in force yet — block, do not guess');
    }

    // ── working_mask ─────────────────────────────────────────────────────

    public function test_working_mask_is_positional_and_month_length(): void
    {
        $r = $this->resolve($this->policy([
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Sun'],
             'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]]],
        ]));

        $this->assertSame(self::DIM, strlen($r['workingMask']));
        // day 1 = offset 0; 1 May 2026 is a Friday → working
        $this->assertSame('1', $r['workingMask'][0]);
        $this->assertSame('0', $r['workingMask'][2]);    // day 3, Sunday
        $this->assertSame('0', $r['workingMask'][8]);    // day 9, 2nd Saturday
        $this->assertSame('1', $r['workingMask'][15]);   // day 16, 3rd Saturday
        $this->assertSame(24, substr_count($r['workingMask'], '1'));
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_validation_rejects_an_empty_or_all_off_working_week(): void
    {
        $this->assertFalse(\Working_calendar_service::validateVersion(
            ['effectiveFrom' => '2026-01-01'])['ok'], 'no offs defined');

        $this->assertFalse(\Working_calendar_service::validateVersion([
            'effectiveFrom' => '2026-01-01',
            'weeklyOffs' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
        ])['ok'], 'every day off is not a working week');
    }

    public function test_validation_rejects_bad_dates_days_and_week_numbers(): void
    {
        $bad = [
            ['effectiveFrom' => '',           'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '01-01-2026', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2026-01-01', 'weeklyOffs' => ['Funday']],
            ['effectiveFrom' => '2026-01-01', 'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [9]]]],
            ['effectiveFrom' => '2026-01-01', 'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => []]]],
        ];
        foreach ($bad as $i => $v) {
            $this->assertFalse(\Working_calendar_service::validateVersion($v)['ok'], "case #{$i}");
        }
    }

    public function test_validation_accepts_a_real_policy(): void
    {
        $r = \Working_calendar_service::validateVersion([
            'effectiveFrom' => '2026-04-01',
            'weeklyOffs' => ['Sun'],
            'nthWeekdayOffs' => [['day' => 'Sat', 'weeks' => [2, 4]]],
        ]);
        $this->assertTrue($r['ok'], implode(' ', $r['errors']));
    }

    // ── Payroll must hold no calendar logic (§9) ─────────────────────────

    public function test_payroll_contains_no_weekday_business_rules(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        $this->assertStringNotContainsString('satCount', $hr,
            'the nth-Saturday counter must be gone from payroll entirely');

        // RE-STATED for N-31: payroll now consumes the EMPLOYEE-level resolver,
        // which delegates the calendar maths to Working_calendar_service. What
        // matters is that payroll asks a resolver rather than computing a
        // calendar — and that it does not resolve one tenant-wide calendar for
        // everybody, which is what `resolveForMonth` would mean here.
        $this->assertStringContainsString('Work_schedule_service::resolveEmployeeCalendar', $hr,
            'payroll must resolve each employee\'s own calendar');
        $this->assertStringNotContainsString('Working_calendar_service::resolveForMonth', $hr,
            'payroll must not reuse one tenant calendar for every employee');
    }

    public function test_both_payroll_writers_gate_on_isWorking_with_the_W_exemption(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        // Scoped to the two known payroll-writer functions specifically —
        // not a whole-file count. Trail 2 Phase E added an unrelated
        // "!== 'W'" check inside earn_comp_off() (Comp-Off ledger eligibility
        // gate, nothing to do with payroll's worked-on-off exemption), which
        // would otherwise bump a whole-file count without actually touching
        // what this test verifies.
        $generateBody  = $this->extractFunctionBody($hr, strpos($hr, 'function generate_payroll('));
        $regenerateBody = $this->extractFunctionBody($hr, strpos($hr, 'function regenerate_staff_payroll('));

        $this->assertSame(1, substr_count($generateBody, "!== 'W'"), 'generate_payroll must keep the worked-on-off exemption');
        $this->assertSame(1, substr_count($regenerateBody, "!== 'W'"), 'regenerate must keep the worked-on-off exemption');
        $this->assertStringContainsString("!isset(\$staffIsWorking[\$dayNum])", $hr);
        $this->assertStringContainsString("!isset(\$swd['isWorking'][\$dayNum])", $hr);
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $this->assertNotFalse($functionKeywordOffset);
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
}
