<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Leave_year_service.php';

/**
 * ZenX Leave Policy Programme — Phase 1 (Leave Year / Session Foundation),
 * 2026-08-18.
 *
 * Leave_year_service is pure/static — real unit tests, not source-regression
 * string matching (unlike the controller-touching tests elsewhere in this
 * suite, this class has no Firestore/CI dependency to work around).
 *
 * The April-start default is NOT an invented assumption — it centralizes an
 * already-existing, already-duplicated convention independently confirmed
 * at Sis.php (~8409), Attendance.php (~5218), and Fees.php (~1739/2649/4597),
 * each of which computes `$monthNumber >= 4 ? $startYear : $endYear` against
 * a session label today.
 */
class LeaveYearServiceTest extends TestCase
{
    // ── boundsForSession() ──────────────────────────────────────────────

    public function test_bounds_default_april_start(): void
    {
        $b = \Leave_year_service::boundsForSession('2026-27');
        $this->assertSame('2026-04-01', $b['startDate']);
        $this->assertSame('2027-03-31', $b['endDate']);
    }

    public function test_bounds_leap_year_correct(): void
    {
        // 2027-28 session (Apr 2027 - Mar 2028); 2028 is a leap year, so
        // Feb 2028 has 29 days — the end date must still land on Mar 31,
        // proving real date math is used, not a fixed 365-day offset.
        $b = \Leave_year_service::boundsForSession('2027-28');
        $this->assertSame('2027-04-01', $b['startDate']);
        $this->assertSame('2028-03-31', $b['endDate']);
        $this->assertSame(366, \Leave_year_service::totalDaysInSession('2027-28'));
    }

    public function test_bounds_configurable_start_month(): void
    {
        // A hypothetical January-start tenant.
        $b = \Leave_year_service::boundsForSession('2026-27', [], 1);
        $this->assertSame('2026-01-01', $b['startDate']);
        $this->assertSame('2026-12-31', $b['endDate']);
    }

    public function test_bounds_override_wins_over_derived(): void
    {
        $overrides = ['2026-27' => ['startDate' => '2026-06-01', 'endDate' => '2027-05-31']];
        $b = \Leave_year_service::boundsForSession('2026-27', $overrides);
        $this->assertSame('2026-06-01', $b['startDate']);
        $this->assertSame('2027-05-31', $b['endDate']);
    }

    public function test_bounds_malformed_override_falls_back_to_derived(): void
    {
        $overrides = ['2026-27' => ['startDate' => 'not-a-date', 'endDate' => '2027-05-31']];
        $b = \Leave_year_service::boundsForSession('2026-27', $overrides);
        $this->assertSame('2026-04-01', $b['startDate'], 'a malformed override must never silently corrupt the derived bounds');
    }

    public function test_bounds_malformed_label_returns_null(): void
    {
        $this->assertNull(\Leave_year_service::boundsForSession('2026'));
        $this->assertNull(\Leave_year_service::boundsForSession('not-a-session'));
    }

    public function test_bounds_invalid_start_month_falls_back_to_default(): void
    {
        $b = \Leave_year_service::boundsForSession('2026-27', [], 13);
        $this->assertSame('2026-04-01', $b['startDate']);
        $b2 = \Leave_year_service::boundsForSession('2026-27', [], 0);
        $this->assertSame('2026-04-01', $b2['startDate']);
    }

    // ── sessionForDate() ─────────────────────────────────────────────────

    public function test_session_for_date_after_start_month(): void
    {
        $this->assertSame('2026-27', \Leave_year_service::sessionForDate('2026-04-01'));
        $this->assertSame('2026-27', \Leave_year_service::sessionForDate('2026-12-25'));
    }

    public function test_session_for_date_before_start_month_belongs_to_prior_session(): void
    {
        $this->assertSame('2025-26', \Leave_year_service::sessionForDate('2026-03-31'));
        $this->assertSame('2025-26', \Leave_year_service::sessionForDate('2026-01-01'));
    }

    public function test_session_for_date_exact_boundary_days(): void
    {
        $this->assertSame('2025-26', \Leave_year_service::sessionForDate('2026-03-31'));
        $this->assertSame('2026-27', \Leave_year_service::sessionForDate('2026-04-01'));
    }

    public function test_session_for_date_override_beats_derived(): void
    {
        // A custom June-start override for one specific session.
        $overrides = ['2026-27' => ['startDate' => '2026-06-01', 'endDate' => '2027-05-31']];
        $this->assertSame('2026-27', \Leave_year_service::sessionForDate('2026-05-15', $overrides));
    }

    public function test_session_for_date_invalid_input_returns_null(): void
    {
        $this->assertNull(\Leave_year_service::sessionForDate('not-a-date'));
        $this->assertNull(\Leave_year_service::sessionForDate('2026/04/01'));
    }

    // ── splitRangeBySession() — the exact worked example from the authorization ──

    public function test_split_range_crossing_boundary_matches_worked_example(): void
    {
        $segments = \Leave_year_service::splitRangeBySession('2026-03-28', '2026-04-04');
        $this->assertCount(2, $segments);
        $this->assertSame(['session' => '2025-26', 'from' => '2026-03-28', 'to' => '2026-03-31'], $segments[0]);
        $this->assertSame(['session' => '2026-27', 'from' => '2026-04-01', 'to' => '2026-04-04'], $segments[1]);
    }

    public function test_split_range_single_session_returns_one_segment(): void
    {
        $segments = \Leave_year_service::splitRangeBySession('2026-06-01', '2026-06-05');
        $this->assertCount(1, $segments);
        $this->assertSame('2026-27', $segments[0]['session']);
        $this->assertSame('2026-06-01', $segments[0]['from']);
        $this->assertSame('2026-06-05', $segments[0]['to']);
    }

    public function test_split_range_single_day_at_boundary(): void
    {
        $segments = \Leave_year_service::splitRangeBySession('2026-03-31', '2026-03-31');
        $this->assertCount(1, $segments);
        $this->assertSame('2025-26', $segments[0]['session']);
    }

    public function test_split_range_invalid_when_to_before_from(): void
    {
        $this->assertSame([], \Leave_year_service::splitRangeBySession('2026-04-05', '2026-04-01'));
    }

    public function test_split_range_invalid_input_returns_empty(): void
    {
        $this->assertSame([], \Leave_year_service::splitRangeBySession('bad', '2026-04-01'));
    }

    public function test_split_range_spanning_three_sessions(): void
    {
        // 2025-01-15 (in 2024-25) .. 2026-06-01 (in 2026-27) — a
        // deliberately extreme, multi-year span exercising the loop guard
        // and multi-segment output beyond the two-segment common case.
        $segments = \Leave_year_service::splitRangeBySession('2025-01-15', '2026-06-01');
        $this->assertCount(3, $segments);
        $this->assertSame('2024-25', $segments[0]['session']);
        $this->assertSame('2025-26', $segments[1]['session']);
        $this->assertSame('2026-27', $segments[2]['session']);
        $this->assertSame('2025-01-15', $segments[0]['from']);
        $this->assertSame('2026-06-01', $segments[2]['to']);
    }

    // ── totalDaysInSession() ─────────────────────────────────────────────

    public function test_total_days_non_leap_session(): void
    {
        // 2026-27: Apr 2026 - Mar 2027, no Feb-29 in that window.
        $this->assertSame(365, \Leave_year_service::totalDaysInSession('2026-27'));
    }

    public function test_total_days_malformed_label_returns_null(): void
    {
        $this->assertNull(\Leave_year_service::totalDaysInSession('garbage'));
    }
}
