<?php
namespace Tests\Unit;

use Tests\Support\TestCase;

/**
 * SubscriptionTest
 *
 * Covers:
 *  - Plan assignment to a school
 *  - Expiry classification (active / expiring_soon / grace / expired / suspended)
 *  - Auto-suspend enforcement via expire_check logic
 *  - Payment recording and subscription sync
 */
class SubscriptionTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // 1. Plan assignment
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function assign_plan_writes_subscription_node_to_firebase(): void
    {
        $this->seedSchool('Assign School', 'active', null, 'ASN001');
        $this->seedPlan('plan_standard', 'Standard', 1499);

        $service = new SubscriptionService($this->firebase);
        $service->assignPlan('Assign School', 'plan_standard', date('Y-m-d', strtotime('+1 year')));

        $sub = $this->firebase->get('Users/Schools/Assign School/subscription');
        $this->assertSame('plan_standard', $sub['plan_id']);
        $this->assertSame('Standard',      $sub['plan_name']);
        $this->assertSame('active',        $sub['status']);
        $this->assertSame(date('Y-m-d', strtotime('+1 year')), $sub['end_date']);
    }

    /** @test */
    public function assign_plan_also_updates_System_Schools_node(): void
    {
        $this->seedSchool('SysSync School', 'active', null, 'SYS001');

        $service = new SubscriptionService($this->firebase);
        $service->assignPlan('SysSync School', 'plan_premium', date('Y-m-d', strtotime('+2 years')));

        $sysSub = $this->firebase->get('System/Schools/SysSync School/subscription');
        $this->assertNotNull($sysSub, 'System/Schools subscription must be synced.');
        $this->assertSame('plan_premium', $sysSub['plan_id']);
    }

    /** @test */
    public function assign_plan_rejects_empty_plan_id(): void
    {
        $this->seedSchool('Reject School');
        $service = new SubscriptionService($this->firebase);

        $this->expectException(\InvalidArgumentException::class);
        $service->assignPlan('Reject School', '', date('Y-m-d', strtotime('+1 year')));
    }

    /** @test */
    public function assign_plan_rejects_past_end_date(): void
    {
        $this->seedSchool('Past School');
        $service = new SubscriptionService($this->firebase);

        $this->expectException(\InvalidArgumentException::class);
        $service->assignPlan('Past School', 'plan_basic', '2020-01-01');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Expiry classification
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function active_school_classified_as_active(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '+6 months');
        $this->assertSame('active', SubscriptionService::classify($sub));
    }

    /** @test */
    public function school_expiring_in_15_days_classified_as_expiring_soon(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '+15 days');
        $this->assertSame('expiring_soon', SubscriptionService::classify($sub));
    }

    /** @test */
    public function school_expiring_today_classified_as_expiring_soon(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', 'today');
        // today == end_date, diff = 0 days → expiring_soon
        $classified = SubscriptionService::classify($sub);
        $this->assertContains($classified, ['expiring_soon', 'grace'],
            "Today's expiry should be expiring_soon or grace.");
    }

    /** @test */
    public function school_expired_7_days_ago_classified_as_grace(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '-7 days');
        $this->assertSame('grace', SubscriptionService::classify($sub));
    }

    /** @test */
    public function school_expired_20_days_ago_classified_as_expired(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '-20 days');
        $this->assertSame('expired', SubscriptionService::classify($sub));
    }

    /** @test */
    public function school_with_suspended_status_always_classified_as_suspended(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'suspended', '+1 year');
        $this->assertSame('suspended', SubscriptionService::classify($sub));
    }

    /** @test */
    public function school_with_inactive_status_classified_as_inactive(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'inactive', '+1 year');
        $this->assertSame('inactive', SubscriptionService::classify($sub));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Expire check enforcement
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function expire_check_suspends_school_past_grace_period(): void
    {
        // end_date = 20 days ago → past 15-day grace → should be suspended
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '-20 days');
        $this->seedSchool('Expire School', 'active', $sub, 'EXP001');

        $service = new SubscriptionService($this->firebase);
        $count   = $service->runExpireCheck();

        $this->assertSame(1, $count, 'One school should have been suspended.');
        $status = $this->firebase->get('Users/Schools/Expire School/subscription/status');
        $this->assertSame('suspended', $status);
    }

    /** @test */
    public function expire_check_does_not_suspend_school_in_grace_period(): void
    {
        // end_date = 7 days ago → still within 15-day grace → must NOT be suspended
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '-7 days');
        $this->seedSchool('Grace School', 'active', $sub, 'GRC001');

        $service = new SubscriptionService($this->firebase);
        $count   = $service->runExpireCheck();

        $this->assertSame(0, $count, 'Grace period school must not be suspended.');
        $status = $this->firebase->get('Users/Schools/Grace School/subscription/status');
        $this->assertNotSame('suspended', $status);
    }

    /** @test */
    public function expire_check_does_not_touch_active_schools(): void
    {
        $sub = $this->makeSubscription('plan_basic', 'Basic', 'active', '+1 year');
        $this->seedSchool('Safe School', 'active', $sub, 'SAF001');

        $service = new SubscriptionService($this->firebase);
        $count   = $service->runExpireCheck();

        $this->assertSame(0, $count);
        $status = $this->firebase->get('Users/Schools/Safe School/status');
        $this->assertSame('active', $status);
    }

    /** @test */
    public function expire_check_processes_multiple_schools_correctly(): void
    {
        // School A: active → safe
        $this->seedSchool('School A', 'active', $this->makeSubscription('p','P','active','+1 year'), 'SA1');
        // School B: grace → safe
        $this->seedSchool('School B', 'active', $this->makeSubscription('p','P','active','-7 days'), 'SB1');
        // School C: past grace → should be suspended
        $this->seedSchool('School C', 'active', $this->makeSubscription('p','P','active','-20 days'), 'SC1');
        // School D: already suspended → skip
        $this->seedSchool('School D', 'suspended', $this->makeSubscription('p','P','suspended','-30 days'), 'SD1');

        $service = new SubscriptionService($this->firebase);
        $count   = $service->runExpireCheck();

        $this->assertSame(1, $count, 'Only School C should be newly suspended.');
        $this->assertSame('suspended', $this->firebase->get('Users/Schools/School C/subscription/status'));
        $this->assertNotSame('suspended', $this->firebase->get('Users/Schools/School A/status'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Payment recording
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function add_payment_stores_record_in_System_Payments(): void
    {
        $this->seedSchool('Pay School', 'active', null, 'PAY001');
        $service = new SubscriptionService($this->firebase);

        $payment_id = $service->addPayment([
            'school_name'  => 'Pay School',
            'amount'       => 5000,
            'plan_name'    => 'Basic',
            'mode'         => 'cash',
            'invoice_date' => date('Y-m-d'),
            'status'       => 'paid',
        ]);

        $this->assertNotNull($payment_id);
        $record = $this->firebase->get("System/Payments/{$payment_id}");
        $this->assertSame(5000,        $record['amount']);
        $this->assertSame('Pay School', $record['school_name']);
        $this->assertSame('paid',       $record['status']);
    }

    /** @test */
    public function add_payment_with_zero_amount_is_rejected(): void
    {
        $service = new SubscriptionService($this->firebase);
        $this->expectException(\InvalidArgumentException::class);
        $service->addPayment(['school_name'=>'S','amount'=>0,'plan_name'=>'B','mode'=>'cash','invoice_date'=>date('Y-m-d'),'status'=>'paid']);
    }

    /** @test */
    public function delete_payment_removes_record_from_firebase(): void
    {
        $this->firebase->seed('System/Payments/PAY_001', ['school_name'=>'Test','amount'=>1000,'status'=>'paid']);
        $service = new SubscriptionService($this->firebase);

        $service->deletePayment('PAY_001');

        $this->assertNull($this->firebase->get('System/Payments/PAY_001'));
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// SubscriptionService — extracted logic (mirrors Superadmin_plans controller)
// ─────────────────────────────────────────────────────────────────────────────
class SubscriptionService
{
    /** Grace period in days before suspension */
    const GRACE_DAYS   = 15;
    /** Schools expiring within this many days are "expiring_soon" */
    const EXPIRY_WARN  = 30;

    public function __construct(private readonly \Tests\Support\FirebaseMock $fb) {}

    public function assignPlan(string $school, string $plan_id, string $end_date): void
    {
        if (empty($plan_id)) throw new \InvalidArgumentException('plan_id required.');
        if (strtotime($end_date) < strtotime('today')) {
            throw new \InvalidArgumentException('End date must be in the future.');
        }
        $sub = [
            'plan_id'    => $plan_id,
            'plan_name'  => ucfirst(str_replace('plan_', '', $plan_id)),
            'status'     => 'active',
            'end_date'   => $end_date,
            'started_at' => date('Y-m-d'),
        ];
        $this->fb->update("Users/Schools/{$school}", ['subscription' => $sub]);
        $this->fb->update("System/Schools/{$school}", ['subscription' => $sub]);
    }

    public static function classify(array $sub): string
    {
        $status   = $sub['status'] ?? 'active';
        $end_date = $sub['end_date'] ?? '';

        if (in_array($status, ['suspended', 'inactive'], true)) return $status;

        if ($end_date === '') return 'active';

        $diff = (int) ceil((strtotime($end_date) - time()) / 86400);

        if ($diff >= 0 && $diff <= self::EXPIRY_WARN) return 'expiring_soon';
        if ($diff >= 0) return 'active';
        if (abs($diff) <= self::GRACE_DAYS) return 'grace';
        return 'expired';
    }

    public function runExpireCheck(): int
    {
        $schools = $this->fb->get('Users/Schools') ?? [];
        $count   = 0;

        foreach ($schools as $name => $data) {
            if (!is_array($data)) continue;
            $sub    = $data['subscription'] ?? [];
            $status = $data['status'] ?? 'active';
            if ($status === 'suspended') continue;

            if (self::classify($sub) === 'expired') {
                $this->fb->update("Users/Schools/{$name}", ['status' => 'suspended']);
                $this->fb->update("Users/Schools/{$name}/subscription", ['status' => 'suspended']);
                $count++;
            }
        }
        return $count;
    }

    public function addPayment(array $data): string
    {
        if (empty($data['amount']) || $data['amount'] <= 0) {
            throw new \InvalidArgumentException('Payment amount must be > 0.');
        }
        $id = 'PAY_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        $this->fb->set("System/Payments/{$id}", array_merge($data, ['created_at' => date('Y-m-d H:i:s')]));
        return $id;
    }

    public function deletePayment(string $payment_id): void
    {
        $this->fb->delete("System/Payments/{$payment_id}");
    }
}
