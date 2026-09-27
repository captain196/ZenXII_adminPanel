<?php
/**
 * Transport_billing_period — REAL ARITHMETIC, not source assertions.
 *
 * This is the calculator behind TRN-PRORATE-INVERT-1. Every other transport
 * test in this suite reads source; this one runs the maths, because the defect
 * it replaces was never a wiring problem — the wiring was fine and the ANSWER
 * was wrong:
 *
 *     proRateFees($studentId, $joinDate) answers "you JOINED in month X, pay
 *     the rest of the year". Transport fed it the STOP date. A student who
 *     rode August to January and left on 1 February was billed February and
 *     March — 2,000 — where 6,000 was owed.
 *
 * The worked example from the product decision is test one.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../application/libraries/Transport_billing_period.php';

final class TransportBillingPeriodTest extends TestCase
{
    private const S = '2026-27';

    private function seg(string $from, string $to = '', float $fee = 1000.0): array
    {
        return ['from' => $from, 'to' => $to, 'monthly_fee' => $fee];
    }

    // ══ THE PRODUCT DECISION, VERBATIM ══════════════════════════════════════

    /** Rs 1,000/month, starts August, stops 1 February → Aug..Jan → Rs 6,000. */
    public function test_the_worked_example_from_the_product_decision(): void
    {
        $months = Transport_billing_period::billableMonths(
            self::S, [$this->seg('2026-08-01', '2027-02-01')]
        );

        $this->assertSame(
            ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'],
            array_keys($months),
            'the months billed must be the months travelled — not the months remaining after '
            . 'the student left, which is what the old proration produced');

        $this->assertSame(6000.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2026-08-01', '2027-02-01')]));
    }

    /**
     * THE OLD ANSWER, PINNED AS WRONG. Under the inverted model this same
     * student was billed 2,000 (February and March). If the calculator ever
     * returns that again, the defect is back.
     */
    public function test_it_never_returns_the_months_after_the_student_left(): void
    {
        $gross = Transport_billing_period::grossFor(
            self::S, [$this->seg('2026-08-01', '2027-02-01')]
        );
        $this->assertNotEquals(2000.0, $gross,
            'billing the months AFTER the stop date is TRN-PRORATE-INVERT-1 exactly');
    }

    // ══ BOUNDARIES ══════════════════════════════════════════════════════════

    /** A full session is twelve months. */
    public function test_a_full_session(): void
    {
        $this->assertSame(12000.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2026-04-01')]));
    }

    /**
     * `to` is EXCLUSIVE, and that is the whole difference between billing six
     * months and seven. Stopping ON the first of a month gives no service that
     * month; stopping part-way through it does, because the existing product
     * convention is whole months and the family had a bus that month.
     */
    public function test_the_stop_date_is_exclusive_at_the_month_boundary(): void
    {
        $this->assertSame(6000.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2026-08-01', '2027-02-01')]), 'stop on the 1st: February not billed');

        $this->assertSame(7000.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2026-08-01', '2027-02-15')]), 'stop mid-month: February billed');
    }

    /** Joining late in a month still bills that whole month. */
    public function test_joining_on_the_last_day_bills_that_month(): void
    {
        $this->assertSame(8000.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2026-08-31')]));
    }

    /** Service entirely outside the session bills nothing. */
    public function test_service_outside_the_session_bills_nothing(): void
    {
        $this->assertSame(0.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2025-01-01', '2025-06-01')]));
        $this->assertSame(0.0, Transport_billing_period::grossFor(
            self::S, [$this->seg('2028-01-01')]));
    }

    /** A malformed session bills nothing rather than guessing a year. */
    public function test_a_malformed_session_bills_nothing(): void
    {
        $this->assertSame([], Transport_billing_period::sessionMonths('nonsense'));
        $this->assertSame(0.0, Transport_billing_period::grossFor('nonsense', [$this->seg('2026-04-01')]));
    }

    /** April → March, crossing the calendar year correctly. */
    public function test_the_session_runs_april_to_march(): void
    {
        $m = Transport_billing_period::sessionMonths(self::S);
        $this->assertCount(12, $m);
        $this->assertSame('2026-04', $m[0]);
        $this->assertSame('2026-12', $m[8]);
        $this->assertSame('2027-01', $m[9]);
        $this->assertSame('2027-03', $m[11]);
    }

    // ══ MULTIPLE SEGMENTS ═══════════════════════════════════════════════════

    /**
     * Suspend then resume. The suspended months are not billed, and the month
     * both segments touch is billed ONCE — a student suspended and resumed
     * inside one month had one bus that month, not two.
     */
    public function test_a_suspension_removes_only_the_suspended_months(): void
    {
        $segments = [
            $this->seg('2026-04-01', '2026-07-01'),   // Apr, May, Jun
            $this->seg('2026-10-01'),                 // Oct .. Mar
        ];
        $months = Transport_billing_period::billableMonths(self::S, $segments);

        $this->assertSame(
            ['2026-04', '2026-05', '2026-06', '2026-10', '2026-11', '2026-12',
             '2027-01', '2027-02', '2027-03'],
            array_keys($months), 'July, August and September were not served');
        $this->assertSame(9000.0, Transport_billing_period::grossFor(self::S, $segments));
    }

    /**
     * Overlapping segments carry DIFFERENT fares here on purpose. With equal
     * fares the "first segment wins" rule is invisible — a mutation removing it
     * survived a test that could not tell the two apart. June is served by
     * both; it must be billed once, at the fare it was actually being served
     * at when the month began.
     */
    public function test_an_overlapping_month_is_billed_once_at_the_earlier_fare(): void
    {
        $segments = [
            $this->seg('2026-04-01', '2026-06-15', 1000.0),
            $this->seg('2026-06-02', '',           2000.0),
        ];
        $months = Transport_billing_period::billableMonths(self::S, $segments);

        $this->assertSame(1000.0, $months['2026-06'],
            'June was already being served at 1,000 when it began; the later segment must not '
            . 'retro-reprice it');
        $this->assertSame(2000.0, $months['2026-07'], 'July belongs wholly to the later segment');

        // Apr..Jun at 1000 (3,000) + Jul..Mar at 2000 (18,000)
        $this->assertSame(21000.0, Transport_billing_period::grossFor(self::S, $segments));
    }

    /**
     * A route change is a FARE change. Months already served keep the fare
     * they were served at — the previous code rewrote the whole session at the
     * new fare, retro-repricing months the family had already consumed.
     */
    public function test_a_fare_change_does_not_reprice_months_already_served(): void
    {
        $segments = [
            $this->seg('2026-04-01', '2026-09-01', 1000.0),   // Apr..Aug  5 x 1000
            $this->seg('2026-09-01', '',           1500.0),   // Sep..Mar  7 x 1500
        ];
        $this->assertSame(15500.0, Transport_billing_period::grossFor(self::S, $segments));
    }

    // ══ SEGMENT MAINTENANCE ═════════════════════════════════════════════════

    /** Re-opening on identical terms must not append a second segment. */
    public function test_reopening_on_the_same_terms_is_idempotent(): void
    {
        $a = Transport_billing_period::open([], '2026-08-01', 1000.0);
        $b = Transport_billing_period::open($a, '2026-08-01', 1000.0);

        $this->assertCount(1, $b,
            'a retried assignment must not append a duplicate segment — two open segments '
            . 'from the same day would bill the same months twice');
        $this->assertSame($a, $b);
    }

    /** Opening closes whatever was running, so segments never overlap forward. */
    public function test_opening_closes_the_running_segment(): void
    {
        $s = Transport_billing_period::open([], '2026-04-01', 1000.0);
        $s = Transport_billing_period::open($s, '2026-09-01', 1500.0);

        $this->assertCount(2, $s);
        $this->assertSame('2026-09-01', $s[0]['to']);
        $this->assertSame('',           $s[1]['to']);
    }

    /**
     * A close that predates the segment's own start would invert it. Service
     * that never happened must not appear as a negative period — and must
     * certainly not be billed.
     */
    public function test_a_close_before_the_start_drops_the_segment(): void
    {
        $s = Transport_billing_period::open([], '2026-09-01', 1000.0);
        $s = Transport_billing_period::close($s, '2026-06-01');

        $this->assertSame([], $s);
        $this->assertSame(0.0, Transport_billing_period::grossFor(self::S, $s));
    }

    public function test_isOpen_reports_whether_service_is_running(): void
    {
        $this->assertFalse(Transport_billing_period::isOpen([]));
        $this->assertTrue(Transport_billing_period::isOpen([$this->seg('2026-04-01')]));
        $this->assertFalse(Transport_billing_period::isOpen([$this->seg('2026-04-01', '2026-09-01')]));
    }
}
