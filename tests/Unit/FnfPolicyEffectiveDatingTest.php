<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Fnf_policy_service.php';

/**
 * FNF-1 — effective dating and historical reproducibility of the F&F policy.
 *
 * WHAT THE AUDIT FOUND ALREADY BUILT, and sound: a validated, tenant-configurable
 * policy covering notice recovery, approvals and clearance; a notice basis that
 * ships UNSET so no school inherits another's contract terms; a days divisor
 * taken from the tenant's own `salaryDefaults.days_basis`; per-leave-type
 * encashability that is fail-closed. Vendor research agrees with those choices —
 * the notice base is contract-dependent, and the 30-vs-26 divisor materially
 * changes the recovery, so both belong to the tenant rather than the platform.
 *
 * WHAT WAS MISSING: every save overwrote the policy in place. No record of what
 * the terms USED to be, no date a change took effect, and so no way to reproduce
 * a settlement computed under earlier terms. Change the basis from basic to
 * gross and every past calculation silently re-prices.
 *
 * Settlements are not persisted yet, so nothing re-prices today. That is exactly
 * why this belongs in now: the machinery to pin a policy has to exist BEFORE the
 * first settlement is stored, not after.
 */
final class FnfPolicyEffectiveDatingTest extends TestCase
{
    // ── Effective dating ─────────────────────────────────────────────────

    /** No date supplied means "from today" — the ordinary case. */
    public function test_a_blank_effective_date_means_today(): void
    {
        [$ok, $date, $err] = Fnf_policy_service::validateEffectiveFrom('', [], '2026-08-23');

        $this->assertTrue($ok, $err);
        $this->assertSame('2026-08-23', $date);
    }

    /**
     * Forward dating is allowed: a school that agrees new notice terms in March
     * starting 1 April must be able to record that when it is agreed.
     */
    public function test_a_policy_may_be_dated_forward(): void
    {
        [$ok, $date] = Fnf_policy_service::validateEffectiveFrom(
            '2026-09-01', ['effectiveFrom' => '2026-08-01'], '2026-08-23');

        $this->assertTrue($ok);
        $this->assertSame('2026-09-01', $date);
    }

    /**
     * THE ONE THAT PROTECTS SETTLED MONEY. Back-dating a policy behind one
     * already in force would change the terms under which earlier settlements
     * were computed — silently, after the fact. Refused, with a reason that
     * says why rather than "invalid date".
     */
    public function test_back_dating_behind_the_policy_in_force_is_refused(): void
    {
        [$ok, , $err] = Fnf_policy_service::validateEffectiveFrom(
            '2026-07-01', ['effectiveFrom' => '2026-08-01'], '2026-08-23');

        $this->assertFalse($ok);
        $this->assertStringContainsString('cannot be earlier', $err);
        $this->assertStringContainsString('earlier settlements', $err,
            'the refusal must say what is at stake, not merely that it is invalid');
    }

    /** A first policy may take any date — there is nothing behind it to protect. */
    public function test_the_first_policy_may_be_dated_freely(): void
    {
        [$ok, $date] = Fnf_policy_service::validateEffectiveFrom('2026-04-01', [], '2026-08-23');

        $this->assertTrue($ok);
        $this->assertSame('2026-04-01', $date);
    }

    /** A malformed date is refused rather than coerced into something plausible. */
    public function test_a_malformed_date_is_refused(): void
    {
        foreach (['23-08-2026', 'April', '2026-13-01x', '2026/08/23'] as $bad) {
            [$ok] = Fnf_policy_service::validateEffectiveFrom($bad, [], '2026-08-23');
            $this->assertFalse($ok, "'{$bad}' should not be accepted as a date");
        }
    }

    // ── Versioning and history ───────────────────────────────────────────

    /** The outgoing policy is kept, and the version increments. */
    public function test_saving_keeps_the_outgoing_policy(): void
    {
        $current = ['effectiveFrom' => '2026-08-01', 'version' => 3,
                    'noticeRecovery' => ['basis' => 'basic']];

        [$new, $history] = Fnf_policy_service::withHistory(
            ['effectiveFrom' => '2026-09-01', 'noticeRecovery' => ['basis' => 'gross']],
            $current, []
        );

        $this->assertSame(4, $new['version'], 'the version increments from what was stored');
        $this->assertCount(1, $history);
        $this->assertSame('basic', $history[0]['noticeRecovery']['basis'],
            'the terms that applied before must survive the change');
    }

    /**
     * The version increments from the STORED number, not from the history
     * length — otherwise truncating history would make two versions share a
     * number, and a settlement pinned to "version 7" would be ambiguous.
     */
    public function test_the_version_survives_truncation(): void
    {
        $current = ['effectiveFrom' => '2026-08-01', 'version' => 40];
        $history = array_fill(0, 30, ['effectiveFrom' => '2026-01-01', 'version' => 1]);

        [$new, $kept] = Fnf_policy_service::withHistory(
            ['effectiveFrom' => '2026-09-01'], $current, $history);

        $this->assertSame(41, $new['version']);
        $this->assertLessThanOrEqual(Fnf_policy_service::HISTORY_LIMIT, count($kept));
    }

    /** History is bounded, and the truncation is RECORDED rather than silent. */
    public function test_history_is_bounded_and_truncation_is_visible(): void
    {
        $history = array_fill(0, 30, ['effectiveFrom' => '2026-01-01']);

        [$new, $kept] = Fnf_policy_service::withHistory(
            ['effectiveFrom' => '2026-09-02'],
            ['effectiveFrom' => '2026-08-01', 'version' => 1],
            $history
        );

        $this->assertSame(Fnf_policy_service::HISTORY_LIMIT, count($kept));
        $this->assertGreaterThan(0, $new['historyTruncated'] ?? 0,
            'a document that quietly drops its own history is worse than one that says so');
    }

    /** A policy that was never in force is not preserved as though it had been. */
    public function test_an_undated_current_policy_is_not_archived(): void
    {
        [, $history] = Fnf_policy_service::withHistory(
            ['effectiveFrom' => '2026-09-01'], ['effectiveFrom' => ''], []);

        $this->assertSame([], $history);
    }

    // ── Historical reproducibility ───────────────────────────────────────

    /**
     * THE POINT OF ALL OF IT. Asked what applied in August, the service returns
     * the August terms — not today's. This is what makes a settlement computed
     * under earlier terms reproducible.
     */
    public function test_the_policy_in_force_on_a_date_is_recoverable(): void
    {
        $current = ['effectiveFrom' => '2026-09-01', 'version' => 2,
                    'noticeRecovery' => ['basis' => 'gross']];
        $history = [['effectiveFrom' => '2026-08-01', 'version' => 1,
                     'noticeRecovery' => ['basis' => 'basic']]];

        $august = Fnf_policy_service::asOf('2026-08-15', $current, $history);
        $this->assertSame('basic', $august['noticeRecovery']['basis'],
            'a settlement dated in August must reprice on August terms, or not at all');

        $september = Fnf_policy_service::asOf('2026-09-15', $current, $history);
        $this->assertSame('gross', $september['noticeRecovery']['basis']);
    }

    /** The newest version that had taken effect wins, not merely the first match. */
    public function test_the_newest_applicable_version_wins(): void
    {
        $current = ['effectiveFrom' => '2026-10-01', 'noticeRecovery' => ['basis' => 'ctc']];
        $history = [
            ['effectiveFrom' => '2026-06-01', 'noticeRecovery' => ['basis' => 'basic']],
            ['effectiveFrom' => '2026-08-01', 'noticeRecovery' => ['basis' => 'gross']],
        ];

        $r = Fnf_policy_service::asOf('2026-09-15', $current, $history);
        $this->assertSame('gross', $r['noticeRecovery']['basis']);
    }

    /**
     * Asked about a date before ANY policy existed, it flags that rather than
     * quietly handing back today's terms — which would be a plausible-looking
     * lie on a settlement.
     */
    public function test_a_date_before_any_policy_is_flagged_not_guessed(): void
    {
        $current = ['effectiveFrom' => '2026-09-01', 'noticeRecovery' => ['basis' => 'gross']];

        $r = Fnf_policy_service::asOf('2020-01-01', $current, []);
        $this->assertTrue(!empty($r['_asOfUnavailable']),
            'returning the current policy unmarked would misrepresent what applied then');
    }

    /** With no history at all, the current policy has always been the policy. */
    public function test_with_no_history_the_current_policy_applies(): void
    {
        $current = ['effectiveFrom' => '2026-01-01', 'noticeRecovery' => ['basis' => 'basic']];

        $r = Fnf_policy_service::asOf('2026-06-01', $current, []);
        $this->assertSame('basic', $r['noticeRecovery']['basis']);
        $this->assertArrayNotHasKey('_asOfUnavailable', $r);
    }

    /** A meaningless date returns the current policy rather than throwing. */
    public function test_a_junk_date_degrades_to_the_current_policy(): void
    {
        $current = ['effectiveFrom' => '2026-01-01', 'noticeRecovery' => ['basis' => 'basic']];

        $this->assertSame($current, Fnf_policy_service::asOf('', $current, []));
        $this->assertSame($current, Fnf_policy_service::asOf('not-a-date', $current, []));
    }

    // ── The shape ────────────────────────────────────────────────────────

    /** The defaults carry the dating fields, so an old policy upgrades cleanly. */
    public function test_the_policy_shape_carries_its_dating(): void
    {
        $d = Fnf_policy_service::defaults();

        $this->assertArrayHasKey('effectiveFrom', $d);
        $this->assertArrayHasKey('version', $d);
        $this->assertSame('', $d['effectiveFrom']);
        $this->assertSame(0, $d['version']);
    }

    /**
     * The notice basis still ships UNSET. Research confirms it is
     * contract-dependent, so a default would put one school's contract terms
     * into every tenant — the hard-coding this whole pass exists to avoid.
     */
    public function test_the_notice_basis_still_ships_unset(): void
    {
        $d = Fnf_policy_service::defaults();

        $this->assertSame(Fnf_policy_service::BASIS_UNSET, $d['noticeRecovery']['basis']);

        // And an unset basis refuses to produce a number rather than guessing.
        $r = Fnf_policy_service::noticePerDayPaise($d, ['basicPaise' => 5000000], 30.0);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('policy_required', $r['reason']);
    }
}
