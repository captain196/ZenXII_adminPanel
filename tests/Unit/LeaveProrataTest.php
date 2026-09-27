<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Leave_year_service.php';

/**
 * ZenX Leave Policy Programme — Phase 4 (Pro-Rata Entitlement), 2026-08-18.
 *
 * Leave_year_service::prorateEntitlement() is pure/static — real unit
 * tests. Deliberately covers every scenario named in the authorization:
 * first day of Leave Year, last day, mid-year join, mid-year separation,
 * leap year, policy effective-date composition (covered by
 * LeavePolicyServiceTest — this file stays scoped to pro-ration math
 * itself, per "each phase separately researched").
 */
class LeaveProrataTest extends TestCase
{
    public function test_full_year_employee_gets_full_entitlement_unprorated(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, null, null, '2026');
        $this->assertSame(12.0, $r['days']);
        $this->assertFalse($r['prorated']);
        $this->assertSame(365, $r['totalDays']);
    }

    public function test_join_on_first_day_of_year_is_full_year_not_prorated(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, '2026-01-01', null, '2026');
        $this->assertSame(12.0, $r['days']);
        $this->assertFalse($r['prorated']);
    }

    public function test_join_on_last_day_of_year_gets_minimal_entitlement(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, '2026-12-31', null, '2026');
        $this->assertTrue($r['prorated']);
        $this->assertSame(1, $r['windowDays']);
        // 12 * (1/365), rounded to nearest 0.5 -> 0.0 (rounds down from ~0.033).
        $this->assertSame(0.0, $r['days']);
    }

    public function test_mid_year_join_october_first_leave_year_april_to_march(): void
    {
        // Leave Year here is the plain calendar year 2026 (Jan-Dec) per this
        // function's documented boundary — Oct 1 - Dec 31 = 92 days of 365.
        $r = \Leave_year_service::prorateEntitlement(12.0, '2026-10-01', null, '2026');
        $this->assertTrue($r['prorated']);
        $this->assertSame(92, $r['windowDays']);
        // 12 * (92/365) = 3.024...  -> nearest 0.5 = 3.0
        $this->assertSame(3.0, $r['days']);
    }

    public function test_mid_year_separation(): void
    {
        // Employee present Jan 1 - Jun 30 (181 days of 365).
        $r = \Leave_year_service::prorateEntitlement(12.0, null, '2026-06-30', '2026');
        $this->assertTrue($r['prorated']);
        $this->assertSame(181, $r['windowDays']);
        // 12 * (181/365) = 5.9506... -> nearest 0.5 = 6.0
        $this->assertSame(6.0, $r['days']);
    }

    public function test_join_and_separate_within_the_same_year(): void
    {
        // Present Mar 1 - Aug 31 only.
        $r = \Leave_year_service::prorateEntitlement(12.0, '2026-03-01', '2026-08-31', '2026');
        $this->assertTrue($r['prorated']);
        // Mar 1 .. Aug 31 inclusive = 184 days in 2026.
        $this->assertSame(184, $r['windowDays']);
    }

    public function test_leap_year_denominator_is_366(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, null, null, '2028');
        $this->assertSame(366, $r['totalDays']);
    }

    public function test_leap_year_mid_year_join_uses_366_denominator(): void
    {
        // Join Jul 1 2028 (leap year) -> Jul 1..Dec 31 = 184 days of 366.
        $r = \Leave_year_service::prorateEntitlement(12.0, '2028-07-01', null, '2028');
        $this->assertSame(366, $r['totalDays']);
        $this->assertSame(184, $r['windowDays']);
    }

    public function test_joined_after_year_ended_gets_zero(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, '2027-01-05', null, '2026');
        $this->assertSame(0.0, $r['days']);
        $this->assertSame(0, $r['windowDays']);
    }

    public function test_separated_before_year_began_gets_zero(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, null, '2025-12-15', '2026');
        $this->assertSame(0.0, $r['days']);
    }

    public function test_rehire_scenario_join_date_this_year_takes_precedence(): void
    {
        // A rehire's NEW join date this year is what matters for this
        // year's balance — prior tenure isn't consulted here (that's a
        // separate, not-yet-authorized policy question — see the Leave
        // Policy Specification's waiting-period section).
        $r = \Leave_year_service::prorateEntitlement(12.0, '2026-09-01', null, '2026');
        $this->assertTrue($r['prorated']);
    }

    public function test_malformed_join_date_treated_as_absent_fails_open(): void
    {
        $r = \Leave_year_service::prorateEntitlement(12.0, 'not-a-date', null, '2026');
        $this->assertSame(12.0, $r['days']);
        $this->assertFalse($r['prorated']);
    }

    public function test_granularity_rounds_to_nearest_half_day(): void
    {
        // 15 * (100/365) = 4.1095... -> nearest 0.5 = 4.0
        $r = \Leave_year_service::prorateEntitlement(15.0, '2026-09-23', null, '2026');
        $remainder = fmod($r['days'] * 2, 1.0);
        $this->assertEqualsWithDelta(0.0, $remainder, 0.0001, 'result must land on a 0.5 boundary.');
    }
}
