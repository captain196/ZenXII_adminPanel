<?php
namespace Tests\Unit;

use Tests\Support\TestCase;

/**
 * DashboardStatsTest
 *
 * Tests the stat aggregation logic that powers the Global SaaS Dashboard:
 *  - Correct school counts by status
 *  - Student / staff count aggregation
 *  - Revenue calculation from payments
 *  - New school count in last 30 days
 *  - Summary cache write to System/Stats/Summary
 *  - Chart data arrays (status distribution, plan distribution)
 *  - Edge cases: zero schools, all suspended, missing subscription nodes
 */
class DashboardStatsTest extends TestCase
{
    private DashboardStatsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardStatsService($this->firebase);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. School counts
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function total_schools_count_matches_firebase_entries(): void
    {
        $this->seedSchool('School A', 'active',    null, 'SA1');
        $this->seedSchool('School B', 'inactive',  null, 'SB1');
        $this->seedSchool('School C', 'suspended', null, 'SC1');

        $stats = $this->service->computeSummary();

        $this->assertSame(3, $stats['total_schools']);
    }

    /** @test */
    public function active_schools_count_excludes_inactive_and_suspended(): void
    {
        $this->seedSchool('Active 1',    'active',    null, 'A1');
        $this->seedSchool('Active 2',    'active',    null, 'A2');
        $this->seedSchool('Inactive 1',  'inactive',  null, 'I1');
        $this->seedSchool('Suspended 1', 'suspended', null, 'S1');

        $stats = $this->service->computeSummary();

        $this->assertSame(2, $stats['active_schools']);
    }

    /** @test */
    public function zero_schools_returns_all_zeros_without_error(): void
    {
        $stats = $this->service->computeSummary();

        $this->assertSame(0, $stats['total_schools']);
        $this->assertSame(0, $stats['active_schools']);
        $this->assertSame(0, $stats['total_students']);
        $this->assertSame(0, $stats['total_staff']);
        $this->assertSame(0, $stats['total_revenue']);
    }

    /** @test */
    public function all_suspended_schools_yields_zero_active(): void
    {
        $this->seedSchool('S1', 'suspended', null, 'X1');
        $this->seedSchool('S2', 'suspended', null, 'X2');

        $stats = $this->service->computeSummary();

        $this->assertSame(0, $stats['active_schools']);
        $this->assertSame(2, $stats['total_schools']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Revenue calculation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function revenue_sums_only_paid_payments(): void
    {
        $this->firebase->seed('System/Payments/P1', ['school_name'=>'S1','amount'=>5000,'status'=>'paid']);
        $this->firebase->seed('System/Payments/P2', ['school_name'=>'S1','amount'=>3000,'status'=>'paid']);
        $this->firebase->seed('System/Payments/P3', ['school_name'=>'S2','amount'=>2000,'status'=>'pending']); // should NOT count
        $this->firebase->seed('System/Payments/P4', ['school_name'=>'S2','amount'=>1000,'status'=>'failed']);  // should NOT count

        $stats = $this->service->computeSummary();

        $this->assertSame(8000, $stats['total_revenue'], 'Only paid payments should sum.');
    }

    /** @test */
    public function revenue_is_zero_when_no_payments_exist(): void
    {
        $stats = $this->service->computeSummary();
        $this->assertSame(0, $stats['total_revenue']);
    }

    /** @test */
    public function revenue_handles_large_amounts_correctly(): void
    {
        $this->firebase->seed('System/Payments/BIG1', ['school_name'=>'S','amount'=>999999,'status'=>'paid']);
        $this->firebase->seed('System/Payments/BIG2', ['school_name'=>'S','amount'=>1000001,'status'=>'paid']);

        $stats = $this->service->computeSummary();
        $this->assertSame(2000000, $stats['total_revenue']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. New schools in 30 days
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function recent_registrations_counts_schools_created_in_last_30_days(): void
    {
        // School created today
        $this->firebase->seed('Users/Schools/New School', [
            'name' => 'New School', 'school_id' => 'N1',
            'status' => 'active', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        // School created 60 days ago (outside window)
        $this->firebase->seed('Users/Schools/Old School', [
            'name' => 'Old School', 'school_id' => 'O1',
            'status' => 'active', 'created_at' => date('Y-m-d H:i:s', strtotime('-60 days')),
        ]);

        $stats = $this->service->computeSummary();
        $this->assertSame(1, $stats['recent_regs'], 'Only the school created today should be in recent_regs.');
    }

    /** @test */
    public function recent_regs_is_zero_when_all_schools_are_older_than_30_days(): void
    {
        $this->firebase->seed('Users/Schools/Old', [
            'name' => 'Old', 'school_id' => 'O', 'status' => 'active',
            'created_at' => date('Y-m-d H:i:s', strtotime('-90 days')),
        ]);

        $stats = $this->service->computeSummary();
        $this->assertSame(0, $stats['recent_regs']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Summary cache write
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function refresh_stats_writes_summary_cache_to_firebase(): void
    {
        $this->seedSchool('Cache School', 'active', null, 'C1');

        $this->service->refreshStats();

        $summary = $this->firebase->get('System/Stats/Summary');
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_schools',  $summary);
        $this->assertArrayHasKey('active_schools', $summary);
        $this->assertArrayHasKey('total_revenue',  $summary);
        $this->assertArrayHasKey('updated_at',     $summary);
        $this->assertSame(1, $summary['total_schools']);
    }

    /** @test */
    public function refresh_stats_updated_at_is_recent_timestamp(): void
    {
        $this->service->refreshStats();

        $summary    = $this->firebase->get('System/Stats/Summary');
        $updated_at = $summary['updated_at'] ?? '';
        $diff       = abs(time() - strtotime($updated_at));
        $this->assertLessThan(5, $diff, 'updated_at should be within 5 seconds of now.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Chart data
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function status_distribution_chart_data_counts_each_status(): void
    {
        $this->seedSchool('SA1', 'active',    null, 'X1');
        $this->seedSchool('SA2', 'active',    null, 'X2');
        $this->seedSchool('SI1', 'inactive',  null, 'X3');
        $this->seedSchool('SS1', 'suspended', null, 'X4');

        $chart = $this->service->statusDistribution();

        $this->assertSame(2, $chart['active']);
        $this->assertSame(1, $chart['inactive']);
        $this->assertSame(1, $chart['suspended']);
    }

    /** @test */
    public function plan_distribution_aggregates_by_plan_name(): void
    {
        $this->seedSchool('PB1', 'active', $this->makeSubscription('plan_basic',    'Basic'),    'PB1');
        $this->seedSchool('PB2', 'active', $this->makeSubscription('plan_basic',    'Basic'),    'PB2');
        $this->seedSchool('PS1', 'active', $this->makeSubscription('plan_standard', 'Standard'), 'PS1');

        $dist = $this->service->planDistribution();

        $this->assertSame(2, $dist['Basic'],    'Two schools on Basic.');
        $this->assertSame(1, $dist['Standard'], 'One school on Standard.');
    }

    /** @test */
    public function plan_distribution_handles_school_with_no_subscription(): void
    {
        // School without subscription node
        $this->firebase->seed('Users/Schools/NoSub School', [
            'name' => 'NoSub School', 'school_id' => 'NS1', 'status' => 'active',
        ]);

        $dist = $this->service->planDistribution();

        // Should not throw; 'Unknown' bucket should exist
        $this->assertIsArray($dist);
        $this->assertArrayHasKey('Unknown', $dist);
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// DashboardStatsService — extracted from Superadmin.php dashboard_charts()
// ─────────────────────────────────────────────────────────────────────────────
class DashboardStatsService
{
    public function __construct(private readonly \Tests\Support\FirebaseMock $fb) {}

    public function computeSummary(): array
    {
        $schools  = $this->fb->get('Users/Schools') ?? [];
        $payments = $this->fb->get('System/Payments') ?? [];

        $total    = 0;
        $active   = 0;
        $students = 0;
        $staff    = 0;
        $recent   = 0;
        $cutoff   = strtotime('-30 days');

        foreach ($schools as $name => $data) {
            if (!is_array($data)) continue;
            $total++;
            if (($data['status'] ?? '') === 'active') $active++;
            $students += (int)($data['stats']['students'] ?? 0);
            $staff    += (int)($data['stats']['staff']    ?? 0);
            $created   = strtotime($data['created_at'] ?? '');
            if ($created && $created >= $cutoff) $recent++;
        }

        $revenue = 0;
        foreach ($payments as $pid => $p) {
            if (!is_array($p)) continue;
            if (($p['status'] ?? '') === 'paid') $revenue += (int)($p['amount'] ?? 0);
        }

        return compact('total', 'active', 'students', 'staff', 'recent', 'revenue') + [
            'total_schools'  => $total,
            'active_schools' => $active,
            'total_students' => $students,
            'total_staff'    => $staff,
            'total_revenue'  => $revenue,
            'recent_regs'    => $recent,
        ];
    }

    public function refreshStats(): void
    {
        $s = $this->computeSummary();
        $this->fb->set('System/Stats/Summary', [
            'total_schools'  => $s['total_schools'],
            'active_schools' => $s['active_schools'],
            'total_students' => $s['total_students'],
            'total_staff'    => $s['total_staff'],
            'total_revenue'  => $s['total_revenue'],
            'recent_regs'    => $s['recent_regs'],
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    public function statusDistribution(): array
    {
        $dist    = ['active' => 0, 'inactive' => 0, 'suspended' => 0];
        $schools = $this->fb->get('Users/Schools') ?? [];
        foreach ($schools as $data) {
            if (!is_array($data)) continue;
            $s = $data['status'] ?? 'active';
            $dist[$s] = ($dist[$s] ?? 0) + 1;
        }
        return $dist;
    }

    public function planDistribution(): array
    {
        $dist    = [];
        $schools = $this->fb->get('Users/Schools') ?? [];
        foreach ($schools as $data) {
            if (!is_array($data)) continue;
            $plan = $data['subscription']['plan_name'] ?? 'Unknown';
            $dist[$plan] = ($dist[$plan] ?? 0) + 1;
        }
        if (empty($dist)) $dist['Unknown'] = 0;
        return $dist;
    }
}
