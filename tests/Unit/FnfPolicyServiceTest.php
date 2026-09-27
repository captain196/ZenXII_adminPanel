<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURES 3 & 4 — F&F notice-recovery basis and approval authority, modelled.
 *
 * DR-09 and DR-10 were repeatedly recorded as "blocked on the owner". That was a
 * category error: the VALUE is the school's to choose — from their appointment
 * letters and their delegation of authority — but the MACHINERY to represent,
 * validate and consume that choice is ordinary engineering, and its absence is
 * what actually blocked the feature.
 *
 * So the axes are modelled and the values are refused, not invented.
 *
 * These exercise the REAL service.
 */
final class FnfPolicyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Fnf_policy_service.php';
    }

    private function monthly(): array
    {
        // ₹30,000 basic, ₹50,000 gross, ₹56,000 CTC — in paise.
        return ['basicPaise' => 3000000, 'grossPaise' => 5000000, 'ctcPaise' => 5600000];
    }

    private function shortfall(int $days = 10): array
    {
        return ['shortfallDays' => $days, 'recoverable' => true, 'recoverability' => 'shortfall_stands'];
    }

    // ── Nothing is invented ──────────────────────────────────────────────

    /**
     * THE LOAD-BEARING TEST. An unconfigured basis REFUSES. Picking "basic"
     * because it is common would be inventing a term of somebody's employment
     * contract.
     */
    public function test_an_unconfigured_basis_refuses_rather_than_guessing(): void
    {
        $r = Fnf_policy_service::resolveRecovery(
            Fnf_policy_service::defaults(), $this->shortfall(), $this->monthly(), 30.0
        );

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['amountPaise'],
            'a settlement must show "configuration required", never a plausible wrong number');
        $this->assertStringContainsString('policy_required', $r['reason']);
    }

    /** The default really is unset, not a disguised default. */
    public function test_the_default_basis_is_unset(): void
    {
        $d = Fnf_policy_service::defaults();

        $this->assertSame('unset', $d['noticeRecovery']['basis']);
        $this->assertFalse(Fnf_policy_service::completeness($d)['complete']);
        $this->assertContains('Notice recovery basis', Fnf_policy_service::completeness($d)['missing']);
    }

    // ── Each configured basis produces its own answer ────────────────────

    /**
     * The three bases give materially different answers, which is exactly why
     * the choice cannot be made for the school.
     */
    public function test_each_basis_produces_its_own_recovery(): void
    {
        $expected = [
            'basic' => 1000000,   // 30,00,000 / 30 = 1,00,000 per day x 10
            'gross' => 1666670,   // round(50,00,000 / 30) = 1,66,667 x 10
            'ctc'   => 1866670,   // round(56,00,000 / 30) = 1,86,667 x 10
        ];

        foreach ($expected as $basis => $amount) {
            $policy = Fnf_policy_service::defaults();
            $policy['noticeRecovery']['basis'] = $basis;

            $r = Fnf_policy_service::resolveRecovery($policy, $this->shortfall(), $this->monthly(), 30.0);

            $this->assertTrue($r['ok'], $r['reason']);
            $this->assertSame($amount, $r['amountPaise'], "basis {$basis}");
            $this->assertSame($basis, $r['basis']);
        }
    }

    /** Gross recovery always exceeds basic recovery for the same shortfall. */
    public function test_gross_recovers_more_than_basic(): void
    {
        $basic = Fnf_policy_service::defaults();
        $basic['noticeRecovery']['basis'] = 'basic';
        $gross = Fnf_policy_service::defaults();
        $gross['noticeRecovery']['basis'] = 'gross';

        $this->assertGreaterThan(
            Fnf_policy_service::resolveRecovery($basic, $this->shortfall(), $this->monthly(), 30.0)['amountPaise'],
            Fnf_policy_service::resolveRecovery($gross, $this->shortfall(), $this->monthly(), 30.0)['amountPaise'],
            'the difference between the bases is the reason the school must choose'
        );
    }

    // ── The divisor is shared, not reinvented ────────────────────────────

    /**
     * The caller supplies the tenant's existing `days_basis`. A second divisor
     * would make a day of notice worth a different amount than a day of leave
     * for the same employee in the same month.
     */
    public function test_the_days_basis_divisor_changes_the_daily_rate(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'basic';

        $thirty = Fnf_policy_service::noticePerDayPaise($policy, $this->monthly(), 30.0);
        $twentySix = Fnf_policy_service::noticePerDayPaise($policy, $this->monthly(), 26.0);

        $this->assertSame(100000, $thirty['perDayPaise']);
        $this->assertGreaterThan($thirty['perDayPaise'], $twentySix['perDayPaise'],
            'a smaller divisor must produce a larger daily rate');
    }

    /** A zero divisor refuses rather than dividing by zero. */
    public function test_a_zero_divisor_refuses(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'basic';

        $r = Fnf_policy_service::noticePerDayPaise($policy, $this->monthly(), 0.0);

        $this->assertFalse($r['ok']);
        $this->assertSame(0, $r['perDayPaise']);
    }

    /** A basis the structure cannot supply refuses rather than reading zero. */
    public function test_a_basis_absent_from_the_structure_refuses(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'ctc';

        $r = Fnf_policy_service::noticePerDayPaise(
            $policy, ['basicPaise' => 3000000, 'grossPaise' => 5000000], 30.0
        );

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('not available', $r['reason'],
            'a missing CTC must not silently recover nothing');
    }

    // ── Nothing recoverable ──────────────────────────────────────────────

    /** A waived notice recovers nothing, even with a basis configured. */
    public function test_a_waived_notice_recovers_nothing(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'gross';

        $r = Fnf_policy_service::resolveRecovery($policy, [
            'shortfallDays' => 10, 'recoverable' => false, 'recoverability' => 'waived_or_void',
        ], $this->monthly(), 30.0);

        $this->assertTrue($r['ok'], 'waiving is a decision, not a configuration gap');
        $this->assertSame(0, $r['amountPaise']);
        $this->assertStringContainsString('waived_or_void', $r['reason']);
    }

    /** No shortfall recovers nothing. */
    public function test_no_shortfall_recovers_nothing(): void
    {
        $policy = Fnf_policy_service::defaults();
        $policy['noticeRecovery']['basis'] = 'gross';

        $r = Fnf_policy_service::resolveRecovery($policy, [
            'shortfallDays' => 0, 'recoverable' => false, 'recoverability' => 'no_shortfall',
        ], $this->monthly(), 30.0);

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $r['amountPaise']);
    }

    /**
     * The shortfall comes from the notice resolver and is NOT recomputed here.
     * Two implementations of "how many days short" would eventually disagree,
     * and the disagreement would be a number on somebody's settlement.
     */
    public function test_the_shortfall_is_taken_not_recomputed(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_policy_service.php'
        );

        $this->assertStringContainsString("\$shortfall = (int) (\$notice['shortfallDays'] ?? 0);", $src);
        foreach (['noticeStartDate', 'expectedLastWorkingDay', 'inclusiveDays'] as $recompute) {
            $this->assertStringNotContainsString($recompute, $src,
                'the policy service must not derive the shortfall itself');
        }
    }

    // ── Approval (DR-10) ─────────────────────────────────────────────────

    /** Single approval is the default, matching what the state machine does. */
    public function test_single_approval_is_the_default(): void
    {
        $r = Fnf_policy_service::approvalsRequired(Fnf_policy_service::defaults(), 99999999);

        $this->assertSame(1, $r['count'],
            'defaulting to dual would silently block every settlement at a school '
            . 'that never configured anything');
    }

    /** Always-dual requires two regardless of amount. */
    public function test_always_dual_requires_two(): void
    {
        $p = Fnf_policy_service::defaults();
        $p['approval']['mode'] = 'always_dual';

        $this->assertSame(2, Fnf_policy_service::approvalsRequired($p, 1)['count']);
    }

    /** A threshold policy switches at the configured amount. */
    public function test_a_threshold_policy_switches_at_the_amount(): void
    {
        $p = Fnf_policy_service::defaults();
        $p['approval']['mode'] = 'dual_above_threshold';
        $p['approval']['dualThresholdPaise'] = 10000000;   // ₹1,00,000

        $this->assertSame(1, Fnf_policy_service::approvalsRequired($p, 10000000)['count'],
            'at the threshold exactly is not ABOVE it');
        $this->assertSame(2, Fnf_policy_service::approvalsRequired($p, 10000001)['count']);
    }

    /**
     * A threshold mode with NO threshold must FAIL CLOSED. Treating it as
     * single approval would silently remove the control the school believed it
     * had configured.
     */
    public function test_a_threshold_mode_without_a_threshold_fails_closed(): void
    {
        $p = Fnf_policy_service::defaults();
        $p['approval']['mode'] = 'dual_above_threshold';
        $p['approval']['dualThresholdPaise'] = null;

        $r = Fnf_policy_service::approvalsRequired($p, 1);

        $this->assertSame(2, $r['count'],
            'an unconfigured threshold must require MORE approval, never less');
        $this->assertStringContainsString('not configured', $r['reason']);
    }

    /** And validation refuses to store that incoherent combination. */
    public function test_validation_refuses_a_threshold_mode_without_a_threshold(): void
    {
        list($ok, , $errors) = Fnf_policy_service::validate([
            'approval' => ['mode' => 'dual_above_threshold'],
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('requires the threshold amount', implode(' ', $errors));
    }

    // ── Separation of duties is NOT configurable ─────────────────────────

    /**
     * The one control a school must not be able to configure away is a person
     * approving their own payout. It stays in the state machine and is absent
     * from the policy surface entirely.
     */
    public function test_separation_of_duties_is_not_a_configurable_axis(): void
    {
        $d = Fnf_policy_service::defaults();

        $this->assertArrayNotHasKey('separationOfDuties', $d['approval']);
        $this->assertArrayNotHasKey('allowSelfApproval', $d['approval']);

        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_policy_service.php'
        );
        $this->assertStringNotContainsString('allowSelfApproval', $src,
            'making self-approval switchable would let a school configure away '
            . 'the one control that stops somebody approving their own settlement');
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_an_invalid_basis_is_refused(): void
    {
        list($ok, $clean, $errors) = Fnf_policy_service::validate([
            'noticeRecovery' => ['basis' => 'whatever_hr_feels_like'],
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('basis must be one of', implode(' ', $errors));
        $this->assertSame('unset', $clean['noticeRecovery']['basis'],
            'an invalid basis must normalise to unset, never to a working default');
    }

    public function test_a_negative_threshold_is_refused(): void
    {
        list($ok, , $errors) = Fnf_policy_service::validate([
            'approval' => ['mode' => 'dual_above_threshold', 'dualThresholdPaise' => -100],
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('non-negative', implode(' ', $errors));
    }

    public function test_a_fractional_threshold_is_refused(): void
    {
        list($ok, , $errors) = Fnf_policy_service::validate([
            'approval' => ['mode' => 'dual_above_threshold', 'dualThresholdPaise' => 100.5],
        ]);

        $this->assertFalse($ok);
        $this->assertStringContainsString('whole number of paise', implode(' ', $errors));
    }

    public function test_a_valid_policy_is_accepted_and_normalised(): void
    {
        list($ok, $clean, $errors) = Fnf_policy_service::validate([
            'noticeRecovery' => ['basis' => 'GROSS', 'note' => 'per clause 7 of the appointment letter'],
            'approval'       => ['mode' => 'dual_above_threshold', 'dualThresholdPaise' => '5000000'],
            'clearance'      => ['required' => ['Library', 'IT', 'Library', '']],
        ]);

        $this->assertTrue($ok, implode(' ', $errors));
        $this->assertSame('gross', $clean['noticeRecovery']['basis'], 'basis must lower-case');
        $this->assertSame(5000000, $clean['approval']['dualThresholdPaise'], 'threshold must be an int');
        $this->assertSame(['Library', 'IT'], $clean['clearance']['required'],
            'duplicates and blanks must be dropped');
    }

    // ── Clearance defaults (Feature 5 groundwork) ────────────────────────

    /**
     * Clearance blocks APPROVAL, not computation, by default — computing is not
     * paying, and refusing to compute makes it impossible to tell an employee
     * what they are owed while a library book is outstanding.
     */
    public function test_clearance_blocks_approval_not_computation_by_default(): void
    {
        $this->assertTrue(Fnf_policy_service::defaults()['clearance']['blockApprovalOnly']);
    }

    // ── Purity ───────────────────────────────────────────────────────────

    /** No persistence, no statutory values, no rates of its own. */
    public function test_the_service_is_pure(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_policy_service.php'
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

        $this->assertStringContainsString('class Fnf_policy_service', $code, 'strip sanity');

        foreach (['$this->fs', 'firestore', 'firebase', 'get_instance'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "the policy service must stay free of {$forbidden}");
        }
    }
}
