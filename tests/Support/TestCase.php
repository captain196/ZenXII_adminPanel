<?php
namespace Tests\Support;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case for GraderIQ SaaS ERP tests.
 *
 * Provides:
 *  - $firebase     : FirebaseMock instance reset before every test
 *  - $logCapture   : array of log_message() calls during the test
 *  - assertRedirectedTo()      : check redirect() stub target
 *  - assertFirebasePath()      : assert a value was written to a Firebase path
 *  - assertFirebasePathExists(): check a path is set in the mock store
 *  - seedSchool()              : quickly seed a test school into FirebaseMock
 *  - seedPlan()                : seed a subscription plan
 *  - makeSubscription()        : build a subscription array with configurable expiry
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected FirebaseMock $firebase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firebase = new FirebaseMock();
        $GLOBALS['__test_log_capture']    = [];
        $GLOBALS['__test_last_redirect']  = null;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->firebase->reset();
        unset($GLOBALS['__test_log_capture'], $GLOBALS['__test_last_redirect']);
    }

    // ── Assertion helpers ──────────────────────────────────────────────────────

    protected function assertRedirectedTo(string $expected): void
    {
        $this->assertSame($expected, $GLOBALS['__test_last_redirect'] ?? null,
            "Expected redirect to '{$expected}' but got '" . ($GLOBALS['__test_last_redirect'] ?? 'none') . "'");
    }

    protected function assertFirebasePath(string $path, mixed $expected): void
    {
        $actual = $this->firebase->get($path);
        $this->assertEquals($expected, $actual, "Firebase path '{$path}' has unexpected value.");
    }

    protected function assertFirebasePathExists(string $path): void
    {
        $val = $this->firebase->get($path);
        $this->assertNotNull($val, "Expected Firebase path '{$path}' to exist but it is null.");
    }

    protected function assertFirebasePathNotExists(string $path): void
    {
        $val = $this->firebase->get($path);
        $this->assertNull($val, "Expected Firebase path '{$path}' to not exist but found: " . json_encode($val));
    }

    protected function assertJsonSuccess(string $json): array
    {
        $data = json_decode($json, true);
        $this->assertIsArray($data, "Response is not valid JSON.");
        $this->assertSame('success', $data['status'] ?? null, "Expected status=success but got: " . json_encode($data));
        return $data;
    }

    protected function assertJsonError(string $json, ?string $messageContains = null): array
    {
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertSame('error', $data['status'] ?? null, "Expected status=error.");
        if ($messageContains !== null) {
            $this->assertStringContainsString($messageContains, $data['message'] ?? '',
                "Error message should contain '{$messageContains}'.");
        }
        return $data;
    }

    // ── Fixture helpers ────────────────────────────────────────────────────────

    protected function seedSchool(
        string $name,
        string $status = 'active',
        ?array $subscription = null,
        string $school_id = 'TST001'
    ): void {
        $sub = $subscription ?? $this->makeSubscription('plan_basic', 'Basic', 'active', '+1 year');
        $this->firebase->seed("Users/Schools/{$name}", [
            'name'         => $name,
            'school_id'    => $school_id,
            'status'       => $status,
            'subscription' => $sub,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
        $this->firebase->seed("System/Schools/{$name}", [
            'name'       => $name,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->firebase->seed("School_ids/{$school_id}", $name);
    }

    protected function seedPlan(
        string $plan_id = 'plan_basic',
        string $name    = 'Basic',
        int    $price   = 999,
        int    $max_students = 200
    ): void {
        $this->firebase->seed("System/Plans/{$plan_id}", [
            'name'          => $name,
            'price'         => $price,
            'billing_cycle' => 'monthly',
            'max_students'  => $max_students,
            'max_staff'     => 20,
            'grace_days'    => 15,
            'sort_order'    => 1,
            'modules'       => $this->_allModules(true),
        ]);
    }

    protected function makeSubscription(
        string $plan_id   = 'plan_basic',
        string $plan_name = 'Basic',
        string $status    = 'active',
        string $endOffset = '+1 year'
    ): array {
        return [
            'plan_id'    => $plan_id,
            'plan_name'  => $plan_name,
            'status'     => $status,
            'end_date'   => date('Y-m-d', strtotime($endOffset)),
            'started_at' => date('Y-m-d'),
        ];
    }

    private function _allModules(bool $enabled = true): array
    {
        $modules = ['fees','attendance','exam','result','timetable',
                    'library','transport','hostel','hr','payroll',
                    'inventory','messaging','reports','api','custom'];
        return array_fill_keys($modules, $enabled);
    }
}
