<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 6 — the F&F preview, and the guarantees that make it safe.
 *
 * Several F&F inputs are the school's contractual choices, and they move real
 * money. An owner cannot reasonably be asked to commit to one without seeing
 * what it does — so this projects a settlement WITHOUT creating one, and shows
 * current beside proposed.
 *
 * The two properties that matter:
 *
 *   1. It CANNOT write. Not "does not" — cannot. There is no Firestore handle,
 *      no CI instance and no persistence in the class at all.
 *   2. It refuses to guess. An unconfigured policy produces `policy_required`
 *      and an incomplete projection, never a plausible substituted default.
 *
 * These exercise the REAL service composed with the REAL resolvers.
 */
final class FnfPreviewServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Fnf_policy_service.php';
        require_once __DIR__ . '/../../application/libraries/Fnf_preview_service.php';
    }

    private function inputs(array $over = []): array
    {
        return array_replace([
            'policy'     => Fnf_policy_service::defaults(),
            'notice'     => ['requiredDays' => 30, 'servedDays' => 20, 'shortfallDays' => 10,
                             'recoverable' => true, 'recoverability' => 'shortfall_stands'],
            'encashment' => ['types' => [
                ['code' => 'EL', 'days' => 12.0, 'encashable' => true],
                ['code' => 'CL', 'days' => 5.0,  'encashable' => false],
            ]],
            'tenure'     => ['totalDays' => 1200, 'continuousDays' => 1200,
                             'episodeCount' => 1, 'hasBreak' => false],
            'clearance'  => ['departments' => ['HR' => ['status' => 'cleared']]],
            'monthly'    => ['basicPaise' => 3000000, 'grossPaise' => 5000000, 'ctcPaise' => 5600000],
            'daysBasis'  => 30.0,
            'unpaidSalaryPaise' => 2000000,
        ], $over);
    }

    private function configured(string $basis = 'basic'): array
    {
        $p = Fnf_policy_service::defaults();
        $p['noticeRecovery']['basis'] = $basis;
        return $p;
    }

    // ── It cannot write ──────────────────────────────────────────────────

    /**
     * THE SAFETY PROPERTY. The ability to persist is ABSENT, not merely unused.
     * A preview that could accidentally create a settlement, post a journal or
     * notify an employee would be worse than no preview.
     */
    public function test_the_preview_cannot_write_anything(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_preview_service.php'
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

        $this->assertStringContainsString('class Fnf_preview_service', $code, 'strip sanity');

        foreach ([
            '$this->fs', 'firestore', 'firebase', 'get_instance', 'log_audit',
            '->set(', '->update(', '->delete(', 'emit_push', 'postRun',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "a preview must not be able to {$forbidden}");
        }
    }

    // ── It refuses to guess ──────────────────────────────────────────────

    /**
     * An unconfigured notice basis produces an INCOMPLETE projection naming the
     * gap — never a plausible substituted default.
     */
    public function test_an_unconfigured_policy_produces_an_incomplete_projection(): void
    {
        $p = Fnf_preview_service::project($this->inputs());

        $this->assertFalse($p['complete']);
        $this->assertNotEmpty($p['blockers']);
        $this->assertStringContainsString('policy_required', implode(' ', $p['blockers']));

        foreach ($p['lines'] as $line) {
            if ($line['code'] === 'notice_recovery') {
                $this->assertSame(0, $line['amountPaise'],
                    'an unresolvable recovery must show zero and say why, not a guess');
            }
        }
    }

    /** With a basis configured the projection completes. */
    public function test_a_configured_policy_completes(): void
    {
        $p = Fnf_preview_service::project($this->inputs(['policy' => $this->configured()]));

        $this->assertTrue($p['complete'], implode(' ', $p['blockers']));
        $this->assertSame([], $p['blockers']);
    }

    // ── The arithmetic ───────────────────────────────────────────────────

    /**
     * Earnings, deductions and net line up.
     *
     * ₹20,000 unpaid salary + 12 days encashment at ₹1,000/day (basic 30,000 /
     * 30) = ₹32,000 earnings; 10 days notice shortfall at the same ₹1,000/day
     * = ₹10,000 deduction; net ₹22,000.
     */
    public function test_the_projection_adds_up(): void
    {
        $p = Fnf_preview_service::project($this->inputs(['policy' => $this->configured()]));

        $this->assertSame(3200000, $p['earningsPaise']);
        $this->assertSame(1000000, $p['deductionsPaise']);
        $this->assertSame(2200000, $p['netPayablePaise']);
        $this->assertSame(0, $p['receivablePaise']);
    }

    /** Only encashable leave types are paid. */
    public function test_non_encashable_leave_is_not_paid(): void
    {
        $p = Fnf_preview_service::project($this->inputs(['policy' => $this->configured()]));

        foreach ($p['lines'] as $line) {
            if ($line['code'] === 'leave_encashment') {
                $this->assertSame(1200000, $line['amountPaise'],
                    'only the 12 encashable days count — the 5 non-encashable ones must not');
            }
        }
    }

    /**
     * Net is CLAMPED at zero and the excess becomes a receivable, matching the
     * payroll engine and the Payment of Wages Act position: a settlement cannot
     * pay a negative amount, but the school does not thereby forgive the money.
     */
    public function test_an_excess_recovery_clamps_net_and_raises_a_receivable(): void
    {
        $p = Fnf_preview_service::project($this->inputs([
            'policy'            => $this->configured('gross'),
            'unpaidSalaryPaise' => 0,
            'encashment'        => ['types' => []],
            'notice'            => ['requiredDays' => 60, 'servedDays' => 0, 'shortfallDays' => 60,
                                    'recoverable' => true, 'recoverability' => 'shortfall_stands'],
        ]));

        $this->assertSame(0, $p['netPayablePaise'],
            'a settlement must never pay a negative amount');
        $this->assertGreaterThan(0, $p['receivablePaise'],
            'and the excess must survive as a claim rather than being forgiven');
    }

    // ── Governance is projected too ──────────────────────────────────────

    public function test_clearance_state_is_projected(): void
    {
        $p = Fnf_preview_service::project($this->inputs([
            'policy'    => $this->configured(),
            'clearance' => ['departments' => ['HR' => ['status' => 'flagged']]],
        ]));

        $this->assertFalse($p['clearance']['canApprove']);
        $this->assertTrue($p['clearance']['canCompute'],
            'a preview must still be produced while clearance is outstanding — '
            . 'that is precisely when somebody asks what they are owed');
    }

    public function test_the_approval_requirement_is_projected(): void
    {
        $policy = $this->configured();
        $policy['approval']['mode'] = 'dual_above_threshold';
        $policy['approval']['dualThresholdPaise'] = 1000000;   // ₹10,000

        $p = Fnf_preview_service::project($this->inputs(['policy' => $policy]));

        $this->assertSame(2, $p['approvalsRequired']['count'],
            'a ₹22,000 net exceeds the ₹10,000 threshold');
    }

    /** Tenure is passed through from the history service, not recomputed. */
    public function test_tenure_is_reported_from_the_history_service(): void
    {
        $p = Fnf_preview_service::project($this->inputs(['policy' => $this->configured()]));

        $this->assertSame(1200, $p['tenure']['totalDays']);
        $this->assertSame(1, $p['tenure']['episodeCount']);
        $this->assertFalse($p['tenure']['hasBreak']);
    }

    /** The configuration that produced the numbers is recorded with them. */
    public function test_the_projection_records_which_configuration_produced_it(): void
    {
        $p = Fnf_preview_service::project($this->inputs(['policy' => $this->configured('ctc')]));

        $this->assertSame('ctc', $p['configurationUsed']['noticeBasis']);
        $this->assertSame(30.0, $p['configurationUsed']['daysBasis']);
    }

    // ── Current vs proposed ──────────────────────────────────────────────

    /**
     * THE OWNER-FACING FEATURE. Two policies, same employee, per-line deltas —
     * because "the net moved by ₹8,000" does not tell an owner which decision
     * moved it.
     */
    public function test_compare_shows_per_line_deltas_not_only_a_total(): void
    {
        $c = Fnf_preview_service::compare(
            $this->inputs(['policy' => $this->configured('basic')]),
            $this->configured('gross')
        );

        $this->assertNotEmpty($c['lineDeltas']);

        $codes = array_column($c['lineDeltas'], 'code');
        $this->assertContains('notice_recovery', $codes,
            'switching the basis must show up on the recovery line');
        $this->assertContains('leave_encashment', $codes,
            'and on encashment, because both use the same daily rate');

        $this->assertNotSame(0, $c['netDelta']);
    }

    /** Comparing identical policies produces no deltas. */
    public function test_comparing_identical_policies_shows_no_change(): void
    {
        $policy = $this->configured();
        $c = Fnf_preview_service::compare($this->inputs(['policy' => $policy]), $policy);

        $this->assertSame([], $c['lineDeltas']);
        $this->assertSame(0, $c['netDelta']);
    }

    /** Comparing against a configured policy reports that it completes. */
    public function test_compare_reports_when_a_proposal_resolves_the_blockers(): void
    {
        $c = Fnf_preview_service::compare($this->inputs(), $this->configured());

        $this->assertFalse($c['current']['complete']);
        $this->assertTrue($c['proposed']['complete']);
        $this->assertTrue($c['becomesComplete'],
            'an owner should be told that configuring this unblocks the settlement');
    }

    // ── It composes rather than reimplements ─────────────────────────────

    /**
     * THE PHASE-E CHECK. Every figure must come from the resolver that owns it.
     * A preview that computed its own numbers would eventually disagree with
     * the settlement it previews — the one thing a preview must never do.
     */
    public function test_the_preview_delegates_to_the_canonical_resolvers(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Fnf_preview_service.php'
        );

        foreach ([
            'Fnf_policy_service::resolveRecovery(',
            'Fnf_policy_service::resolveClearance(',
            'Fnf_policy_service::approvalsRequired(',
            'Fnf_policy_service::noticePerDayPaise(',
        ] as $delegate) {
            $this->assertStringContainsString($delegate, $src,
                'the preview must delegate, not reimplement');
        }

        // And must NOT rederive the things those resolvers own.
        foreach (['inclusiveDays', 'noticeStartDate', 'expectedLastWorkingDay'] as $rederive) {
            $this->assertStringNotContainsString($rederive, $src,
                'the notice position belongs to Fnf_notice_resolver');
        }
    }

    /** Encashment and notice share one daily rate, so they cannot disagree. */
    public function test_encashment_and_notice_use_the_same_daily_rate(): void
    {
        $p = Fnf_preview_service::project($this->inputs([
            'policy'     => $this->configured('basic'),
            'encashment' => ['types' => [['code' => 'EL', 'days' => 10.0, 'encashable' => true]]],
            'notice'     => ['requiredDays' => 30, 'servedDays' => 20, 'shortfallDays' => 10,
                             'recoverable' => true, 'recoverability' => 'shortfall_stands'],
            'unpaidSalaryPaise' => 0,
        ]));

        $encash = 0; $recover = 0;
        foreach ($p['lines'] as $l) {
            if ($l['code'] === 'leave_encashment') $encash = $l['amountPaise'];
            if ($l['code'] === 'notice_recovery')  $recover = -$l['amountPaise'];
        }

        $this->assertSame($encash, $recover,
            'ten days encashed and ten days short must be the same amount — '
            . 'a day cannot be worth two different things in one settlement');
        $this->assertSame(0, $p['netPayablePaise']);
    }
}
