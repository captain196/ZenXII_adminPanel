<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('BASEPATH')) define('BASEPATH', __DIR__);
require_once __DIR__ . '/../../application/libraries/Leave_policy_service.php';

/**
 * ZenX Leave Policy Programme — Phase 2 (Effective-Dated Leave Policies),
 * 2026-08-18. Pure unit tests — Leave_policy_service has no I/O.
 */
class LeavePolicyServiceTest extends TestCase
{
    private function typeWithVersions(array $versions): array
    {
        return ['name' => 'Earned Leave', 'code' => 'EL', 'versions' => $versions];
    }

    // ── effectiveVersion() ──────────────────────────────────────────────

    public function test_picks_most_recent_version_on_or_before_date(): void
    {
        $type = $this->typeWithVersions([
            ['effectiveFrom' => '2025-04-01', 'days_per_year' => 12],
            ['effectiveFrom' => '2026-04-01', 'days_per_year' => 15],
        ]);
        $v = \Leave_policy_service::effectiveVersion($type, '2026-08-18');
        $this->assertSame(15, $v['days_per_year']);
    }

    public function test_does_not_pick_a_future_version(): void
    {
        $type = $this->typeWithVersions([
            ['effectiveFrom' => '2025-04-01', 'days_per_year' => 12],
            ['effectiveFrom' => '2026-04-01', 'days_per_year' => 15],
        ]);
        // Requested date is BEFORE the second version takes effect.
        $v = \Leave_policy_service::effectiveVersion($type, '2026-01-01');
        $this->assertSame(12, $v['days_per_year'],
            'a policy edit must be prospective — a date before its effectiveFrom must still see the OLD entitlement.');
    }

    public function test_exact_boundary_date_uses_the_new_version(): void
    {
        $type = $this->typeWithVersions([
            ['effectiveFrom' => '2025-04-01', 'days_per_year' => 12],
            ['effectiveFrom' => '2026-04-01', 'days_per_year' => 15],
        ]);
        $v = \Leave_policy_service::effectiveVersion($type, '2026-04-01');
        $this->assertSame(15, $v['days_per_year']);
    }

    public function test_no_version_effective_yet_returns_null(): void
    {
        $type = $this->typeWithVersions([
            ['effectiveFrom' => '2027-04-01', 'days_per_year' => 15],
        ]);
        $this->assertNull(\Leave_policy_service::effectiveVersion($type, '2026-08-18'));
    }

    public function test_no_versions_at_all_returns_null(): void
    {
        $this->assertNull(\Leave_policy_service::effectiveVersion(['name' => 'CL'], '2026-08-18'));
    }

    public function test_malformed_date_returns_null(): void
    {
        $type = $this->typeWithVersions([['effectiveFrom' => '2025-04-01', 'days_per_year' => 12]]);
        $this->assertNull(\Leave_policy_service::effectiveVersion($type, 'not-a-date'));
    }

    public function test_malformed_version_entries_skipped_not_fatal(): void
    {
        $type = $this->typeWithVersions([
            'not-an-array',
            ['effectiveFrom' => 'garbage', 'days_per_year' => 99],
            ['effectiveFrom' => '2025-04-01', 'days_per_year' => 12],
        ]);
        $v = \Leave_policy_service::effectiveVersion($type, '2026-08-18');
        $this->assertSame(12, $v['days_per_year']);
    }

    // ── canAppendVersion() ───────────────────────────────────────────────

    public function test_can_append_first_version(): void
    {
        $r = \Leave_policy_service::canAppendVersion([], '2026-08-18');
        $this->assertTrue($r['ok']);
    }

    public function test_refuses_duplicate_effective_from(): void
    {
        $existing = [['effectiveFrom' => '2026-08-18', 'days_per_year' => 12]];
        $r = \Leave_policy_service::canAppendVersion($existing, '2026-08-18');
        $this->assertFalse($r['ok']);
        $this->assertSame('duplicate_effective_from', $r['error']);
    }

    public function test_refuses_invalid_date(): void
    {
        $r = \Leave_policy_service::canAppendVersion([], 'next-monday');
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_date', $r['error']);
    }

    public function test_allows_a_different_effective_from(): void
    {
        $existing = [['effectiveFrom' => '2025-04-01', 'days_per_year' => 12]];
        $r = \Leave_policy_service::canAppendVersion($existing, '2026-04-01');
        $this->assertTrue($r['ok']);
    }

    // ── currentFlatSnapshot() ────────────────────────────────────────────

    public function test_current_flat_snapshot_matches_effective_version(): void
    {
        $type = $this->typeWithVersions([
            ['effectiveFrom' => '2025-04-01', 'days_per_year' => 12, 'carry_forward' => false, 'max_carry' => 0, 'paid' => true, 'status' => 'Active'],
        ]);
        $snap = \Leave_policy_service::currentFlatSnapshot($type, '2026-08-18');
        $this->assertSame(12, $snap['days_per_year']);
        $this->assertTrue($snap['paid']);
    }

    public function test_current_flat_snapshot_null_when_nothing_effective(): void
    {
        $type = $this->typeWithVersions([['effectiveFrom' => '2099-01-01', 'days_per_year' => 99]]);
        $this->assertNull(\Leave_policy_service::currentFlatSnapshot($type, '2026-08-18'));
    }
}
