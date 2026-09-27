<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURES 11 & 12 — the integration audit and the adversarial pass.
 *
 * Everything built in this programme has been tested in isolation. This suite
 * attacks the SEAMS: the places where two correct components can still combine
 * into a wrong answer, and the cases nobody reaches on a happy path.
 *
 * The question asked of every seam is the one that matters: where could a
 * policy be ignored, re-read using today's value, bypassed, or defaulted
 * incorrectly?
 */
final class StaffLifecycleAdversarialTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

        foreach ([
            'Statutory_wage_base', 'Statutory_config_service', 'Staff_statutory_profile_service',
            'Fnf_policy_service', 'Fnf_preview_service', 'Salary_arrears_service',
        ] as $c) {
            require_once __DIR__ . '/../../application/libraries/' . $c . '.php';
        }
    }

    // ── Old configuration + new configuration ────────────────────────────

    /**
     * A period BETWEEN two statutory versions must resolve the earlier one, not
     * fall into a gap and resolve nothing.
     */
    public function test_a_period_inside_an_open_ended_version_resolves_it(): void
    {
        $versions = [
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => null,
             'pf' => ['employeeRate' => 12.0, 'wageCeilingPaise' => 1500000, 'applyCeiling' => true]],
        ];

        foreach (['2026-04-01', '2026-08-15', '2030-01-01'] as $d) {
            $this->assertNotNull(Statutory_config_service::selectFor($versions, $d),
                "an open-ended version must cover {$d}");
        }
    }

    /**
     * A GAP between two closed versions resolves to nothing rather than to the
     * nearest — applying rates that were not in force would be worse than
     * refusing.
     */
    public function test_a_gap_between_versions_resolves_to_nothing(): void
    {
        $versions = [
            ['effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-06-30', 'pf' => []],
            ['effectiveFrom' => '2026-09-01', 'effectiveTo' => null,        'pf' => []],
        ];

        $this->assertNull(Statutory_config_service::selectFor($versions, '2026-07-15'),
            'a July period must not silently borrow June or September rates');
    }

    // ── Employee crossing the PF ceiling ─────────────────────────────────

    /**
     * THE CASE THE WHOLE PROFILE EXISTS FOR, exercised end to end: an employee
     * joins below the ceiling, becomes a member, and later earns above it.
     */
    public function test_a_member_who_crosses_the_ceiling_keeps_contributing(): void
    {
        $profile = ['pf' => [
            'state' => 'member', 'basis' => 'below_ceiling_at_joining',
            'uan' => '123456789012', 'memberSince' => '2024-04-01', 'epsEligible' => true,
        ]];

        $cfg = ['pf' => ['employeeRate' => 12.0, 'employerRate' => 12.0,
                         'wageCeilingPaise' => 1500000, 'applyCeiling' => true,
                         'eligibleAboveCeiling' => false]];

        // Wage-versus-ceiling alone would exclude them.
        $derived = Statutory_config_service::computePf($cfg, 4000000);
        $this->assertFalse($derived['covered'],
            'the derivation alone drops them out the month they cross');

        // The recorded profile keeps them in.
        $decision = Staff_statutory_profile_service::resolvePf($profile, '2026-08-31', false);
        $this->assertTrue($decision['covered']);
        $this->assertSame('profile', $decision['source']);
    }

    /** A Para 26(6) joiner is a member with no pension scheme, at any wage. */
    public function test_a_para_26_6_joiner_never_gets_the_pension_scheme(): void
    {
        foreach (['2026-04-30', '2027-12-31'] as $asOf) {
            $d = Staff_statutory_profile_service::resolvePf([
                'pf' => ['state' => 'member', 'basis' => 'above_ceiling_election',
                         'memberSince' => '2026-01-01', 'epsEligible' => true],
            ], $asOf, false);

            $this->assertTrue($d['covered']);
            $this->assertFalse($d['epsEligible'], 'statutory, not an employer choice');
        }
    }

    // ── Rehire: an employee with a break in service ──────────────────────

    /**
     * A rehired employee's profile must not silently carry membership across a
     * gap it does not cover. Membership starting after the period is refused,
     * whatever the current state says.
     */
    public function test_membership_never_applies_before_it_began(): void
    {
        $rejoined = ['pf' => [
            'state' => 'member', 'basis' => 'transferred_member',
            'memberSince' => '2026-06-01', 'uan' => '123456789012',
        ]];

        $before = Staff_statutory_profile_service::resolvePf($rejoined, '2026-03-31', false);
        $after  = Staff_statutory_profile_service::resolvePf($rejoined, '2026-06-30', false);

        $this->assertFalse($before['covered'], 'the earlier episode must not inherit later membership');
        $this->assertTrue($after['covered']);
    }

    // ── Salary revision mid-month, and after a lock ──────────────────────

    /**
     * A mid-month revision produces a PART-month arrear, not a whole one.
     */
    public function test_a_mid_month_revision_does_not_owe_a_whole_month(): void
    {
        $line = Salary_arrears_service::arrearForPeriod(
            '2026-07-20', ['year' => 2026, 'month' => 7], 5000000, 5500000
        );

        $this->assertLessThan(500000, $line['arrearPaise'],
            'twelve days of a rise is not a month of it');
        $this->assertSame(12, $line['proratedDays']);
    }

    /**
     * A revision effective in a CLOSED period produces an arrear rather than
     * repricing it. The arrears service has no capability to touch that period
     * — asserted elsewhere — so this checks the arithmetic side: the closed
     * period's own figures are inputs, never outputs.
     */
    public function test_a_closed_period_is_an_input_not_an_output(): void
    {
        $r = Salary_arrears_service::compute(
            '2026-04-01',
            [['year' => 2026, 'month' => 4, 'runId' => 'PR001', 'status' => 'Paid']],
            ['2026-04' => ['oldPaise' => 5000000, 'newPaise' => 5500000]]
        );

        $this->assertSame(500000, $r['totalArrearPaise']);
        $this->assertSame('PR001', $r['periods'][0]['runId'],
            'the arrear must point AT the closed run, not rewrite it');
    }

    // ── Off-cycle and monthly colliding ──────────────────────────────────

    /** The two run kinds are distinguishable everywhere it matters. */
    public function test_off_cycle_and_monthly_are_distinguishable(): void
    {
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');

        // Guards that MUST scope to monthly.
        foreach ([
            'lock_payroll_month',      // else an off-cycle run unlocks the month
            'get_dashboard',           // else it becomes the headline figure
        ] as $fn) {
            $i = strpos($hr, 'public function ' . $fn . '(');
            $this->assertNotFalse($i, "{$fn} not found");
            $body = substr($hr, (int) $i, 6000);
            $this->assertStringContainsString('RUN_TYPE_MONTHLY', $body,
                "{$fn} must distinguish the run kind");
        }

        // A guard that must NOT scope — a leaver's run still protects them.
        $i = strpos($hr, 'public function delete_salary_structure(');
        $this->assertStringNotContainsString('RUN_TYPE_MONTHLY', substr($hr, (int) $i, 2500));
    }

    // ── Clearance override cannot become a bypass ────────────────────────

    /** Every path to an override requires BOTH a nominated role and a reason. */
    public function test_an_override_cannot_be_obtained_without_role_and_reason(): void
    {
        $withRole = Fnf_policy_service::defaults();
        $withRole['clearance']['overrideRole'] = 'Principal';

        $cases = [
            [Fnf_policy_service::defaults(), ['Principal'], 'good reason'],  // no role configured
            [$withRole, ['Principal'], ''],                                 // no reason
            [$withRole, [], 'good reason'],                                 // no roles at all
            [$withRole, ['HR Manager'], 'good reason'],                     // wrong role
        ];

        foreach ($cases as $i => list($policy, $roles, $reason)) {
            $r = Fnf_policy_service::canOverrideClearance($policy, $roles, $reason);
            $this->assertFalse($r['allowed'], "case {$i} must be refused");
        }

        $ok = Fnf_policy_service::canOverrideClearance($withRole, ['Principal'], 'urgent relocation');
        $this->assertTrue($ok['allowed'], 'the one legitimate path must still work');
    }

    // ── Approval threshold boundaries ────────────────────────────────────

    /** Exactly at the threshold is not ABOVE it. */
    public function test_the_approval_threshold_boundary_is_exclusive(): void
    {
        $p = Fnf_policy_service::defaults();
        $p['approval']['mode'] = 'dual_above_threshold';
        $p['approval']['dualThresholdPaise'] = 5000000;

        $this->assertSame(1, Fnf_policy_service::approvalsRequired($p, 4999999)['count']);
        $this->assertSame(1, Fnf_policy_service::approvalsRequired($p, 5000000)['count']);
        $this->assertSame(2, Fnf_policy_service::approvalsRequired($p, 5000001)['count']);
    }

    /** A zero threshold means every settlement needs two — and that is valid. */
    public function test_a_zero_threshold_means_always_dual(): void
    {
        $p = Fnf_policy_service::defaults();
        $p['approval']['mode'] = 'dual_above_threshold';
        $p['approval']['dualThresholdPaise'] = 0;

        $this->assertSame(2, Fnf_policy_service::approvalsRequired($p, 1)['count']);
        $this->assertSame(1, Fnf_policy_service::approvalsRequired($p, 0)['count'],
            'a zero settlement is not above zero');
    }

    // ── Idempotency and determinism ──────────────────────────────────────

    /** Every pure resolver returns the same answer for the same input. */
    public function test_the_resolvers_are_deterministic(): void
    {
        $components = [
            ['code' => 'BASIC', 'amountPaise' => 3000000, 'classification' => 'INCLUDE'],
            ['code' => 'HRA',   'amountPaise' => 7000000, 'classification' => 'CONDITIONAL'],
        ];

        $a = Statutory_wage_base::computeFromComponents($components);
        $b = Statutory_wage_base::computeFromComponents($components);
        $this->assertSame($a, $b);

        $notice = ['shortfallDays' => 7, 'recoverable' => true, 'recoverability' => 'shortfall_stands'];
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'gross';
        $monthly = ['basicPaise' => 3000000, 'grossPaise' => 5000000, 'ctcPaise' => 5600000];

        $this->assertSame(
            Fnf_policy_service::resolveRecovery($policy, $notice, $monthly, 30.0),
            Fnf_policy_service::resolveRecovery($policy, $notice, $monthly, 30.0)
        );
    }

    /** Recomputing arrears from the same inputs gives the same money. */
    public function test_arrears_are_reproducible(): void
    {
        $args = [
            '2026-05-16',
            [['year' => 2026, 'month' => 5, 'runId' => 'PR002', 'status' => 'Paid']],
            ['2026-05' => ['oldPaise' => 5000000, 'newPaise' => 5500000]],
        ];

        $this->assertSame(
            Salary_arrears_service::compute(...$args),
            Salary_arrears_service::compute(...$args)
        );
    }

    // ── Degenerate and hostile input ─────────────────────────────────────

    /** No resolver may fatal on empty input. */
    public function test_nothing_fatals_on_empty_input(): void
    {
        $this->assertIsArray(Statutory_wage_base::computeFromComponents([]));
        $this->assertIsArray(Fnf_policy_service::validate([])[1]);
        $this->assertIsArray(Fnf_policy_service::resolveClearance([], []));
        $this->assertIsArray(Staff_statutory_profile_service::resolvePf([], '', null));
        $this->assertIsArray(Salary_arrears_service::compute('', [], []));
        $this->assertIsArray(Fnf_preview_service::project([]));
    }

    /** Nor on structurally hostile input. */
    public function test_nothing_fatals_on_malformed_input(): void
    {
        $this->assertIsArray(Statutory_wage_base::computeFromComponents([
            null, 'string', 42, ['code' => 'X'], ['amountPaise' => 'not a number'],
        ]));

        $this->assertIsArray(Fnf_policy_service::resolveClearance(
            ['clearance' => ['required' => 'not-an-array']],
            ['departments' => 'not-an-array']
        ));

        $this->assertIsArray(Salary_arrears_service::affectedPeriods('2026-04-01', [
            null, 'x', ['year' => 'abc', 'month' => 99],
        ]));
    }

    /**
     * A preview of nothing is an empty projection, not a crash.
     *
     * And it reports COMPLETE, which is correct rather than lax: `complete`
     * means "no policy gap blocked this projection", and with no shortfall
     * there is nothing to recover, so an unset recovery basis cannot block
     * anything. Reporting incomplete here would tell a school with no notice
     * shortfall to configure a value that could not affect them.
     *
     * The unset basis blocks only when it actually bites — asserted next.
     */
    public function test_a_preview_of_nothing_is_an_empty_projection(): void
    {
        $p = Fnf_preview_service::project([]);

        $this->assertSame(0, $p['netPayablePaise']);
        $this->assertSame(0, $p['earningsPaise']);
        $this->assertTrue($p['complete'],
            'nothing to recover means no policy gap — the basis is irrelevant here');
    }

    /**
     * THE COMPLEMENT, and the one that matters: the moment there IS a shortfall
     * to recover, an unset basis blocks the projection.
     */
    public function test_an_unset_basis_blocks_only_once_there_is_something_to_recover(): void
    {
        $inputs = [
            'policy'  => Fnf_policy_service::defaults(),
            'monthly' => ['basicPaise' => 3000000, 'grossPaise' => 5000000, 'ctcPaise' => 5600000],
            'daysBasis' => 30.0,
        ];

        $noShortfall = Fnf_preview_service::project(array_replace($inputs, [
            'notice' => ['shortfallDays' => 0, 'recoverable' => false,
                         'recoverability' => 'no_shortfall'],
        ]));
        $withShortfall = Fnf_preview_service::project(array_replace($inputs, [
            'notice' => ['shortfallDays' => 10, 'recoverable' => true,
                         'recoverability' => 'shortfall_stands'],
        ]));

        $this->assertTrue($noShortfall['complete']);
        $this->assertFalse($withShortfall['complete'],
            'an unconfigured basis must block precisely when it would decide money');
        $this->assertStringContainsString('policy_required', implode(' ', $withShortfall['blockers']));
    }

    // ── Money invariants across the whole surface ────────────────────────

    /** No projection can ever produce a negative net payable. */
    public function test_no_projection_ever_pays_a_negative_amount(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'ctc';

        for ($shortfall = 0; $shortfall <= 120; $shortfall += 7) {
            $p = Fnf_preview_service::project([
                'policy'  => $policy,
                'notice'  => ['shortfallDays' => $shortfall, 'recoverable' => true,
                              'recoverability' => 'shortfall_stands'],
                'monthly' => ['basicPaise' => 3000000, 'grossPaise' => 5000000, 'ctcPaise' => 5600000],
                'daysBasis' => 30.0,
                'unpaidSalaryPaise' => 1000000,
                'clearance' => ['departments' => ['HR' => ['status' => 'cleared']]],
            ]);

            $this->assertGreaterThanOrEqual(0, $p['netPayablePaise'],
                "net went negative at a {$shortfall}-day shortfall");
            $this->assertGreaterThanOrEqual(0, $p['receivablePaise']);
        }
    }

    /** The wage base is never negative and never exceeds remuneration. */
    public function test_the_wage_base_stays_within_remuneration(): void
    {
        for ($basic = 0; $basic <= 6000000; $basic += 1300000) {
            for ($hra = 0; $hra <= 6000000; $hra += 1700000) {
                $out = Statutory_wage_base::computeFromComponents([
                    ['code' => 'BASIC', 'amountPaise' => $basic, 'classification' => 'INCLUDE'],
                    ['code' => 'HRA',   'amountPaise' => $hra,   'classification' => 'CONDITIONAL'],
                ]);

                $this->assertGreaterThanOrEqual(0, $out['basePaise']);
                $this->assertLessThanOrEqual($out['totalPaise'], $out['basePaise']);
            }
        }
    }

    // ── Tenant and employee isolation ────────────────────────────────────

    /**
     * None of the pure services can reach tenant data on their own — they are
     * given what they need. That is what makes cross-tenant leakage impossible
     * at this layer rather than merely unlikely.
     */
    public function test_no_pure_service_can_reach_tenant_data(): void
    {
        foreach ([
            'Statutory_wage_base', 'Staff_statutory_profile_service',
            'Fnf_policy_service', 'Fnf_preview_service', 'Salary_arrears_service',
        ] as $class) {
            $src = (string) file_get_contents(
                __DIR__ . '/../../application/libraries/' . $class . '.php'
            );

            $code = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                    $code .= $t[1];
                } else {
                    $code .= $t;
                }
            }

            foreach (['$this->fs', 'firestoreGet', 'firestoreSet', 'get_instance', 'schoolWhere'] as $reach) {
                $this->assertStringNotContainsString($reach, $code,
                    "{$class} must be given its data, never fetch it");
            }

            $this->assertDoesNotMatchRegularExpression('/SCH_[A-Z0-9]{6,}/', $code,
                "{$class} must contain no hard-coded tenant");
        }
    }

    // ── The safety flags are still off ───────────────────────────────────

    /** Nothing built in this programme has been switched on. */
    public function test_every_cutover_flag_is_still_false(): void
    {
        $statutory = (string) file_get_contents(
            __DIR__ . '/../../application/config/hrms_statutory_flags.php');
        $compensation = (string) file_get_contents(
            __DIR__ . '/../../application/config/hrms_compensation_flags.php');

        foreach ([
            [$statutory,    "\$config['USE_STATUTORY_WAGE_BASE'] = false;"],
            [$statutory,    "\$config['STATUTORY_WAGE_SHADOW'] = false;"],
            [$compensation, "\$config['USE_COMPONENT_COMPENSATION'] = false;"],
            [$compensation, "\$config['COMPENSATION_SHADOW_COMPARE'] = false;"],
        ] as list($src, $needle)) {
            $this->assertStringContainsString($needle, $src,
                'a cutover flag must never be flipped as a side effect of engineering');
        }
    }
}
