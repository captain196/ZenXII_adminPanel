<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APPPATH . 'libraries/Student_calendar_service.php';

/**
 * SCS-1 … SCS-4 — four defects found by adversarially probing the Phase 4
 * implementation, AFTER 39 tests and a 14-mutant battery had passed.
 *
 * Every one of them slipped through because the original suite only ever fed
 * the resolver well-formed input assembled the intended way. None was a missing
 * assertion about the happy path; all four were about what happens when a caller
 * does something slightly wrong. That is the gap this file closes, and the
 * reason it exists separately: it is the record of what the first battery could
 * not see.
 */
final class StudentCalendarRegressionTest extends TestCase
{
    private const SESSION = ['start' => '2026-04-01', 'end' => '2027-03-31'];

    // ── SCS-1 ────────────────────────────────────────────────────────────

    /**
     * `configured` is the field a caller reads to decide whether to apply the
     * owner's unconfigured-tenant policy. Reporting true for a tenant that has
     * configured nothing routes them down the configured path and silently skips
     * that policy — the one thing the NULL contract exists to prevent.
     */
    public function test_outside_session_does_not_claim_an_unconfigured_tenant_is_configured(): void
    {
        $r = Student_calendar_service::resolveForDate(null, '2026-03-31', [], self::SESSION);

        $this->assertFalse($r['configured'],
            'an unconfigured tenant must never be reported as configured, on any path');
        $this->assertSame(Student_calendar_service::REASON_OUTSIDE_SESSION, $r['reason'],
            'the date is still correctly outside the session');
    }

    public function test_outside_session_still_reports_configured_when_a_pattern_exists(): void
    {
        $v = ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']];
        $r = Student_calendar_service::resolveForDate($v, '2027-04-01', [], self::SESSION);

        $this->assertTrue($r['configured']);
        $this->assertSame('2026-04-01', $r['policyEffectiveFrom']);
    }

    // ── SCS-2 ────────────────────────────────────────────────────────────

    /**
     * A window that is present but unparseable was silently dropped, so a date
     * years outside the session resolved as an ordinary teaching day.
     */
    public function test_a_malformed_session_window_is_not_silently_ignored(): void
    {
        $v = ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']];
        $r = Student_calendar_service::resolveForDate(
            $v, '2020-01-01', [], ['start' => 'not-a-date', 'end' => 'also-bad']);

        $this->assertNull($r['attendance'],
            'an unusable session window must not resolve to a teaching day');
        $this->assertFalse($r['configured']);
        $this->assertSame('invalid_session_window', $r['source']);
    }

    public function test_a_partially_malformed_window_is_also_refused(): void
    {
        $v = ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']];
        $r = Student_calendar_service::resolveForDate(
            $v, '2026-06-10', [], ['start' => '2026-04-01', 'end' => '31-03-2027']);

        $this->assertNull($r['attendance'], 'one bad bound is enough to refuse');
    }

    public function test_an_absent_session_window_remains_perfectly_valid(): void
    {
        $v = ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']];
        $r = Student_calendar_service::resolveForDate($v, '2026-06-10', [], []);

        $this->assertTrue($r['attendance'],
            'no window supplied is not the same as a broken window');
    }

    // ── SCS-3 ────────────────────────────────────────────────────────────

    /**
     * effectiveVersion() filters expired versions out, but resolveForDate() is
     * public and was trusting whatever it was handed. An expired week pattern
     * resolved as a normal teaching day.
     */
    public function test_an_expired_version_passed_directly_does_not_apply(): void
    {
        $expired = [
            'effectiveFrom' => '2020-01-01',
            'effectiveTo'   => '2020-12-31',
            'weeklyOffs'    => ['Sun'],
        ];

        $this->assertNull(
            Student_calendar_service::effectiveVersion([$expired], '2026-06-10'),
            'the front door already excluded it');

        $r = Student_calendar_service::resolveForDate($expired, '2026-06-10');
        $this->assertNull($r['attendance'],
            'and the resolver must reach the same answer when handed it directly, '
            . 'rather than trusting every caller to have used the front door');
        $this->assertFalse($r['configured']);
    }

    public function test_a_version_not_yet_in_force_does_not_apply(): void
    {
        $future = ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Sun']];
        $r = Student_calendar_service::resolveForDate($future, '2026-06-10');

        $this->assertNull($r['attendance'],
            'a pattern that has not started yet cannot classify an earlier date');
    }

    public function test_a_version_inside_its_own_bounds_still_applies(): void
    {
        $bounded = [
            'effectiveFrom' => '2026-04-01',
            'effectiveTo'   => '2027-03-31',
            'weeklyOffs'    => ['Sun'],
        ];
        $this->assertTrue(
            Student_calendar_service::resolveForDate($bounded, '2026-06-10')['attendance']);
        $this->assertFalse(
            Student_calendar_service::resolveForDate($bounded, '2026-06-07')['attendance']);
    }

    /** The bounds are inclusive at both ends. */
    public function test_effective_to_is_inclusive(): void
    {
        $b = ['effectiveFrom' => '2026-04-01', 'effectiveTo' => '2026-06-10',
              'weeklyOffs' => ['Sun']];
        $this->assertTrue(
            Student_calendar_service::resolveForDate($b, '2026-06-10')['attendance'],
            'the effectiveTo date itself is still covered');
        $this->assertNull(
            Student_calendar_service::resolveForDate($b, '2026-06-11')['attendance'],
            'the day after is not');
    }

    // ── SCS-4 ────────────────────────────────────────────────────────────

    /**
     * Validation compared the proposal against the stored list but never checked
     * the stored list against itself, so pre-existing ambiguity stayed invisible.
     */
    public function test_pre_existing_duplicate_effective_dates_are_surfaced(): void
    {
        $ambiguous = [
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sat', 'Sun']],
        ];

        $r = Student_calendar_service::validateVersionAgainst(
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Sun']], $ambiguous, '');

        $this->assertFalse($r['ok'],
            'saving on top of already-ambiguous data must be refused');
        $this->assertStringContainsString('ambiguous', implode(' ', $r['errors']));
    }

    public function test_a_clean_stored_list_is_not_falsely_flagged(): void
    {
        $clean = [
            ['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']],
            ['effectiveFrom' => '2026-09-01', 'weeklyOffs' => ['Sat', 'Sun']],
        ];

        $r = Student_calendar_service::validateVersionAgainst(
            ['effectiveFrom' => '2027-01-01', 'weeklyOffs' => ['Sun']], $clean, '');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }

    /**
     * The append-only shape must still be accepted — this is the defect the
     * Phase 4 run found, and it must not regress: open-ended predecessors are
     * normal, not conflicts.
     */
    public function test_appending_to_open_ended_versions_is_still_allowed(): void
    {
        $existing = [['effectiveFrom' => '2026-04-01', 'weeklyOffs' => ['Sun']]];
        $r = Student_calendar_service::validateVersionAgainst(
            ['effectiveFrom' => '2026-09-01', 'weeklyOffs' => ['Sat', 'Sun']],
            $existing, '2026-08-31');

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
    }
}
