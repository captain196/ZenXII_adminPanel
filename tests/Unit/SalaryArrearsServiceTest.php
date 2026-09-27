<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FEATURE 8 — retro-effective salary revisions generate arrears.
 *
 * REQUIRED BY ZENX'S OWN SPEC, not by a competitor. `hr-payroll-brief.md` §6.1:
 * "retro-effective revisions auto-generate arrears across affected past
 * periods", and §6.4: "Run locked/paid → never mutate history. Raise an arrear
 * … in the next period, linked to the original run."
 *
 * ON `payoutFrom`. Asanify separates an "effective date" from a "payout from"
 * date, and that was recorded as a possible ZenX gap. Reading ZenX's spec shows
 * it is not one: those are two ways of expressing the same thing, and ZenX's
 * chosen expression is arrears. Adding a `payoutFrom` field on top would be a
 * second way to say it, consumed by nothing. The market observation established
 * that the PROBLEM is real; the spec decided the SOLUTION.
 *
 * These exercise the REAL service.
 */
final class SalaryArrearsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
        require_once __DIR__ . '/../../application/libraries/Salary_arrears_service.php';
    }

    /** Three months already paid. */
    private function paidPeriods(): array
    {
        return [
            ['year' => 2026, 'month' => 4, 'runId' => 'PR001', 'status' => 'Paid'],
            ['year' => 2026, 'month' => 5, 'runId' => 'PR002', 'status' => 'Paid'],
            ['year' => 2026, 'month' => 6, 'runId' => 'PR003', 'status' => 'Paid'],
        ];
    }

    /** A ₹5,000/month rise, resolved per period by the caller. */
    private function gross(): array
    {
        return [
            '2026-04' => ['oldPaise' => 5000000, 'newPaise' => 5500000],
            '2026-05' => ['oldPaise' => 5000000, 'newPaise' => 5500000],
            '2026-06' => ['oldPaise' => 5000000, 'newPaise' => 5500000],
        ];
    }

    // ── Which periods are reached ────────────────────────────────────────

    /** A revision effective before everything reaches every period. */
    public function test_a_revision_reaches_every_period_from_its_effective_date(): void
    {
        $a = Salary_arrears_service::affectedPeriods('2026-04-01', $this->paidPeriods());

        $this->assertCount(3, $a);
    }

    /** A revision effective mid-stream reaches only from there on. */
    public function test_earlier_periods_are_not_reached(): void
    {
        $a = Salary_arrears_service::affectedPeriods('2026-06-01', $this->paidPeriods());

        $this->assertCount(1, $a);
        $this->assertSame(6, (int) $a[0]['month']);
    }

    /** A revision effective mid-month still reaches that month. */
    public function test_a_mid_month_revision_reaches_that_month(): void
    {
        $a = Salary_arrears_service::affectedPeriods('2026-05-16', $this->paidPeriods());

        $this->assertCount(2, $a, 'May is partly affected and June wholly');
        $this->assertSame(5, (int) $a[0]['month']);
    }

    /** A future revision reaches nothing already paid. */
    public function test_a_future_revision_generates_no_arrears(): void
    {
        $a = Salary_arrears_service::affectedPeriods('2026-12-01', $this->paidPeriods());

        $this->assertSame([], $a);
    }

    /** Periods come back oldest first, so a statement reads chronologically. */
    public function test_periods_are_returned_oldest_first(): void
    {
        $shuffled = array_reverse($this->paidPeriods());
        $a = Salary_arrears_service::affectedPeriods('2026-04-01', $shuffled);

        $this->assertSame([4, 5, 6], array_map(static fn($p) => (int) $p['month'], $a));
    }

    /** A malformed effective date reaches nothing rather than guessing. */
    public function test_a_malformed_effective_date_reaches_nothing(): void
    {
        foreach (['2026-02-30', 'next tuesday', '01-04-2026', ''] as $bad) {
            $this->assertSame([], Salary_arrears_service::affectedPeriods($bad, $this->paidPeriods()));
        }
    }

    // ── The arithmetic ───────────────────────────────────────────────────

    /** A whole period on the new terms owes the whole difference. */
    public function test_a_full_period_owes_the_whole_difference(): void
    {
        $line = Salary_arrears_service::arrearForPeriod(
            '2026-04-01', ['year' => 2026, 'month' => 4], 5000000, 5500000
        );

        $this->assertSame(500000, $line['arrearPaise']);
        $this->assertSame(30, $line['proratedDays']);
        $this->assertStringContainsString('full period', $line['note']);
    }

    /**
     * A mid-month revision is PRORATED by the days of THAT month.
     *
     * 16 May to 31 May is 16 days of 31. ₹5,000 × 16/31 = ₹2,580.65.
     */
    public function test_a_mid_month_revision_is_prorated_by_that_months_days(): void
    {
        $line = Salary_arrears_service::arrearForPeriod(
            '2026-05-16', ['year' => 2026, 'month' => 5], 5000000, 5500000
        );

        $this->assertSame(16, $line['proratedDays']);
        $this->assertSame(31, $line['daysInPeriod']);
        $this->assertSame((int) round(500000 * 16 / 31), $line['arrearPaise']);
    }

    /**
     * THE REASON THE DIVISOR IS THE MONTH'S OWN LENGTH. February and May with
     * the same start day owe different shares, because the months differ.
     */
    public function test_the_divisor_is_the_months_own_length(): void
    {
        $feb = Salary_arrears_service::arrearForPeriod(
            '2026-02-16', ['year' => 2026, 'month' => 2], 5000000, 5500000
        );
        $may = Salary_arrears_service::arrearForPeriod(
            '2026-05-16', ['year' => 2026, 'month' => 5], 5000000, 5500000
        );

        $this->assertSame(28, $feb['daysInPeriod']);
        $this->assertSame(31, $may['daysInPeriod']);
        $this->assertNotSame($feb['arrearPaise'], $may['arrearPaise'],
            'a fixed 30-day divisor would pay 31/30ths of a difference in a 31-day month');
    }

    /** The last day of a month owes exactly one day. */
    public function test_the_last_day_of_a_month_owes_one_day(): void
    {
        $line = Salary_arrears_service::arrearForPeriod(
            '2026-04-30', ['year' => 2026, 'month' => 4], 5000000, 5500000
        );

        $this->assertSame(1, $line['proratedDays']);
        $this->assertSame((int) round(500000 / 30), $line['arrearPaise']);
    }

    // ── The whole position ───────────────────────────────────────────────

    public function test_the_total_sums_the_periods(): void
    {
        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $this->gross());

        $this->assertTrue($r['ok']);
        $this->assertCount(3, $r['periods']);
        $this->assertSame(1500000, $r['totalArrearPaise'], 'three whole months at ₹5,000');
        $this->assertFalse($r['isRecovery']);
    }

    /**
     * Per-period figures come from the CALLER, resolved through the real
     * engine. A period whose figures are missing is NAMED, never skipped — an
     * arrear that silently ignored a month somebody was on unpaid leave would
     * overpay them.
     */
    public function test_a_period_with_unresolvable_figures_is_named_not_skipped(): void
    {
        $gross = $this->gross();
        unset($gross['2026-05']);

        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $gross);

        $this->assertFalse($r['ok']);
        $this->assertSame(['2026-05'], $r['missingPeriods']);
        $this->assertCount(2, $r['periods'], 'the resolvable periods are still reported');
    }

    /**
     * Gross is per period, not a flat salary difference — because a period's
     * gross depends on that period's attendance too.
     */
    public function test_a_period_with_lower_attendance_owes_less(): void
    {
        $gross = $this->gross();
        // May: employee was on unpaid leave for half the month.
        $gross['2026-05'] = ['oldPaise' => 2500000, 'newPaise' => 2750000];

        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $gross);

        $may = null;
        foreach ($r['periods'] as $p) if ($p['periodKey'] === '2026-05') $may = $p;

        $this->assertNotNull($may);
        $this->assertSame(250000, $may['arrearPaise'],
            'the arrear must follow what the month would actually have paid');
    }

    /**
     * A DOWNWARD revision produces a negative total, surfaced as a recovery
     * rather than silently clamped — clamping would quietly forgive money.
     * Whether to pursue it is the caller's decision, and the deduction ceiling
     * still applies to it.
     */
    public function test_a_downward_revision_is_reported_as_a_recovery(): void
    {
        $gross = [
            '2026-04' => ['oldPaise' => 5500000, 'newPaise' => 5000000],
            '2026-05' => ['oldPaise' => 5500000, 'newPaise' => 5000000],
            '2026-06' => ['oldPaise' => 5500000, 'newPaise' => 5000000],
        ];

        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $gross);

        $this->assertTrue($r['isRecovery']);
        $this->assertSame(-1500000, $r['totalArrearPaise']);
        $this->assertStringContainsString('recovery', $r['note']);
    }

    /** No affected periods produces an empty, valid position. */
    public function test_a_future_revision_produces_an_empty_position(): void
    {
        $r = Salary_arrears_service::compute('2026-12-01', $this->paidPeriods(), $this->gross());

        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['periods']);
        $this->assertSame(0, $r['totalArrearPaise']);
    }

    // ── History is never repriced ────────────────────────────────────────

    /**
     * THE INVARIANT THAT KEEPS HISTORICAL PAYROLL REPRODUCIBLE. This service
     * computes a difference to be paid LATER. It has no capability to touch a
     * closed payslip, re-open a run or alter a journal — the ability is absent,
     * not merely unused.
     */
    public function test_the_service_cannot_touch_historical_payroll(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Salary_arrears_service.php'
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

        $this->assertStringContainsString('class Salary_arrears_service', $code, 'strip sanity');

        foreach ([
            '$this->fs', 'firestore', 'firebase', 'get_instance',
            '->set(', '->update(', '->delete(', 'unlock', 'postRun',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "an arrears calculation must not be able to {$forbidden}");
        }
    }

    // ── It uses the mechanism that already existed ───────────────────────

    /**
     * The money reaches a payslip through the EXISTING override service, whose
     * `ARREARS` type is already an earning that flows into the engine's
     * `$extraComponents`. A second path to a payslip line would be a second
     * money engine by stealth.
     */
    public function test_arrears_become_overrides_of_the_existing_type(): void
    {
        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $this->gross());
        $overrides = Salary_arrears_service::toOverrideInputs($r, 'STA0007', 'PR004');

        $this->assertCount(3, $overrides);
        foreach ($overrides as $o) {
            $this->assertSame('ARREARS', $o['type'],
                'the type must be one the override service already supports');
            $this->assertSame('STA0007', $o['staffId']);
            $this->assertSame('PR004', $o['runId'], 'arrears are paid in a LATER run');
        }
    }

    /** ARREARS really is a supported override type — checked, not assumed. */
    public function test_the_arrears_override_type_exists(): void
    {
        $src = (string) file_get_contents(
            __DIR__ . '/../../application/libraries/Payroll_override_service.php'
        );

        $this->assertStringContainsString(
            "'ARREARS'            => ['kind' => 'earning',", $src,
            'the arrears line must already be an earning the engine understands');
    }

    /**
     * ONE LINE PER PERIOD, not a lump. A payslip saying "Arrears ₹12,400" is
     * unauditable; "Arrears — 2026-05" beside "Arrears — 2026-06" can be
     * checked against those months.
     */
    public function test_each_period_becomes_its_own_labelled_line(): void
    {
        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $this->gross());
        $overrides = Salary_arrears_service::toOverrideInputs($r, 'STA0007', 'PR004');

        $keys = array_column($overrides, 'periodKey');
        $this->assertSame(['2026-04', '2026-05', '2026-06'], $keys);

        foreach ($overrides as $o) {
            $this->assertStringContainsString('arrears for ' . $o['periodKey'], $o['reason']);
            $this->assertStringContainsString('effective 2026-04-01', $o['reason']);
        }
    }

    /** Each line is linked to the run it corrects, per spec §6.4. */
    public function test_each_line_is_linked_to_the_original_run(): void
    {
        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $this->gross());
        $overrides = Salary_arrears_service::toOverrideInputs($r, 'STA0007', 'PR004');

        $this->assertSame(['PR001', 'PR002', 'PR003'], array_column($overrides, 'sourceRunId'));
    }

    /** A zero-value period produces no line at all. */
    public function test_a_zero_arrear_produces_no_line(): void
    {
        $gross = $this->gross();
        $gross['2026-05'] = ['oldPaise' => 5000000, 'newPaise' => 5000000];

        $r = Salary_arrears_service::compute('2026-04-01', $this->paidPeriods(), $gross);
        $overrides = Salary_arrears_service::toOverrideInputs($r, 'STA0007', 'PR004');

        $this->assertCount(2, $overrides, 'a ₹0 arrears line on a payslip is noise');
        $this->assertNotContains('2026-05', array_column($overrides, 'periodKey'));
    }

    // ── payoutFrom was deliberately not added ────────────────────────────

    /**
     * No `payoutFrom` field was introduced anywhere. The Asanify observation
     * established that the problem is real; ZenX's spec decided the solution is
     * arrears, and a second way to express the same thing would be a field
     * nothing consumes.
     */
    public function test_no_redundant_payout_from_field_was_introduced(): void
    {
        foreach ([
            'libraries/Salary_arrears_service.php',
            'libraries/Salary_assignment_service.php',
        ] as $rel) {
            $path = __DIR__ . '/../../application/' . $rel;
            if (!is_file($path)) continue;

            $src = (string) file_get_contents($path);
            $code = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
                    $code .= $t[1];
                } else {
                    $code .= $t;
                }
            }

            $this->assertStringNotContainsString('payoutFrom', $code,
                "{$rel} must not carry a field nothing consumes");
        }
    }
}
