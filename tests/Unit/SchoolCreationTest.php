<?php
namespace Tests\Unit;

use Tests\Support\TestCase;

/**
 * SchoolCreationTest
 *
 * Tests the business logic for school onboarding:
 *  - FirebaseMock is used instead of the real database.
 *  - The service-layer functions are extracted into SchoolService (see below).
 *  - Where a full CI controller is needed, we stub only the parts required.
 */
class SchoolCreationTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // 1. Firebase data structure written during onboarding
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function onboarding_writes_school_node_to_Users_Schools(): void
    {
        $service = new SchoolService($this->firebase);
        $service->onboard([
            'name'        => 'Alpha Academy',
            'school_id'   => 'ALP001',
            'admin_email' => 'admin@alpha.edu',
            'plan_id'     => 'plan_basic',
            'session'     => '2025-26',
        ]);

        $node = $this->firebase->get('Users/Schools/Alpha Academy');
        $this->assertIsArray($node, 'Users/Schools node should be an array.');
        $this->assertSame('Alpha Academy', $node['name']);
        $this->assertSame('ALP001',        $node['school_id']);
        $this->assertSame('active',        $node['status']);
        $this->assertArrayHasKey('subscription', $node);
        $this->assertArrayHasKey('created_at',   $node);
    }

    /** @test */
    public function onboarding_registers_school_code_in_School_ids(): void
    {
        $service = new SchoolService($this->firebase);
        $service->onboard([
            'name'        => 'Beta School',
            'school_id'   => 'BET002',
            'admin_email' => 'a@beta.edu',
            'plan_id'     => 'plan_basic',
            'session'     => '2025-26',
        ]);

        $this->assertFirebasePath('School_ids/BET002', 'Beta School');
    }

    /** @test */
    public function onboarding_creates_admin_node_with_bcrypt_password(): void
    {
        $service = new SchoolService($this->firebase);
        $service->onboard([
            'name'        => 'Gamma Institute',
            'school_id'   => 'GAM003',
            'admin_email' => 'admin@gamma.edu',
            'plan_id'     => 'plan_standard',
            'session'     => '2025-26',
        ]);

        $admins = $this->firebase->get('Users/Admin/GAM003');
        $this->assertIsArray($admins, 'Users/Admin/{code} should be set.');
        $adminNode = reset($admins);
        $this->assertSame('Super Admin', $adminNode['Role']);
        $this->assertSame('admin@gamma.edu', $adminNode['email']);
        // Password must be bcrypt
        $this->assertStringStartsWith('$2y$', $adminNode['password'],
            'Admin password must be stored as bcrypt hash.');
    }

    /** @test */
    public function onboarding_writes_SA_metadata_to_System_Schools(): void
    {
        $service = new SchoolService($this->firebase);
        $service->onboard([
            'name'      => 'Delta College',
            'school_id' => 'DEL004',
            'admin_email' => 'a@delta.edu',
            'plan_id'   => 'plan_premium',
            'session'   => '2025-26',
        ]);

        $meta = $this->firebase->get('System/Schools/Delta College');
        $this->assertIsArray($meta);
        $this->assertArrayHasKey('name',       $meta);
        $this->assertArrayHasKey('created_at', $meta);
    }

    /** @test */
    public function onboarding_creates_default_account_heads(): void
    {
        $service = new SchoolService($this->firebase);
        $service->onboard([
            'name'      => 'Epsilon High',
            'school_id' => 'EPS005',
            'admin_email' => 'a@eps.edu',
            'plan_id'   => 'plan_basic',
            'session'   => '2025-26',
        ]);

        $accounts = $this->firebase->get('Schools/Epsilon High/2025-26/Accounts');
        $this->assertIsArray($accounts, 'Default account heads should be created.');
        $this->assertArrayHasKey('Fees', $accounts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Duplicate detection
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function availability_check_returns_name_taken_when_school_exists(): void
    {
        $this->firebase->seed('Users/Schools/Alpha Academy', ['name' => 'Alpha Academy']);
        $service = new SchoolService($this->firebase);

        $result = $service->checkAvailability('Alpha Academy', 'NEW001');

        $this->assertTrue($result['name_taken'],  'name_taken should be true.');
        $this->assertFalse($result['code_taken'], 'code_taken should be false for new code.');
    }

    /** @test */
    public function availability_check_returns_code_taken_when_code_exists(): void
    {
        $this->firebase->seed('School_ids/ALP001', 'Alpha Academy');
        $service = new SchoolService($this->firebase);

        $result = $service->checkAvailability('New School', 'ALP001');

        $this->assertFalse($result['name_taken'], 'name_taken should be false for new name.');
        $this->assertTrue($result['code_taken'],  'code_taken should be true.');
    }

    /** @test */
    public function availability_check_allows_completely_new_school(): void
    {
        $service = new SchoolService($this->firebase);

        $result = $service->checkAvailability('Brand New School', 'BNS999');

        $this->assertFalse($result['name_taken']);
        $this->assertFalse($result['code_taken']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. School name validation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function school_name_with_invalid_characters_is_rejected(): void
    {
        $service = new SchoolService($this->firebase);

        $this->expectException(\InvalidArgumentException::class);
        $service->onboard([
            'name'        => '<script>alert(1)</script>',
            'school_id'   => 'XSS001',
            'admin_email' => 'x@x.com',
            'plan_id'     => 'plan_basic',
            'session'     => '2025-26',
        ]);
    }

    /** @test */
    public function empty_school_name_is_rejected(): void
    {
        $service = new SchoolService($this->firebase);

        $this->expectException(\InvalidArgumentException::class);
        $service->onboard([
            'name'        => '',
            'school_id'   => 'EMP001',
            'admin_email' => 'e@e.com',
            'plan_id'     => 'plan_basic',
            'session'     => '2025-26',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Firebase failure handling
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function onboarding_returns_false_when_firebase_set_fails(): void
    {
        $this->firebase->failNext('set');
        $service = new SchoolService($this->firebase);

        $result = $service->onboard([
            'name'        => 'Fail School',
            'school_id'   => 'FAI001',
            'admin_email' => 'f@f.com',
            'plan_id'     => 'plan_basic',
            'session'     => '2025-26',
        ]);

        $this->assertFalse($result, 'Onboarding should return false when Firebase write fails.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Status toggle
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function toggle_status_updates_both_firebase_paths(): void
    {
        $this->seedSchool('Toggle School', 'active', null, 'TOG001');
        $service = new SchoolService($this->firebase);

        $service->toggleStatus('Toggle School', 'suspended');

        $userNode   = $this->firebase->get('Users/Schools/Toggle School/status');
        $systemNode = $this->firebase->get('System/Schools/Toggle School/status');
        $this->assertSame('suspended', $userNode,   'Users/Schools status must update.');
        $this->assertSame('suspended', $systemNode, 'System/Schools status must update.');
    }

    /** @test */
    public function toggle_status_rejects_invalid_status_value(): void
    {
        $this->seedSchool('Status School');
        $service = new SchoolService($this->firebase);

        $this->expectException(\InvalidArgumentException::class);
        $service->toggleStatus('Status School', 'hacked');
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// Inline Service class (extracts logic from Superadmin_schools controller)
// In a full refactor these would live in application/services/SchoolService.php
// ─────────────────────────────────────────────────────────────────────────────
class SchoolService
{
    private const VALID_STATUSES = ['active', 'inactive', 'suspended'];
    private const NAME_REGEX     = "/^[A-Za-z0-9 ',_\-]+$/u";

    public function __construct(private readonly \Tests\Support\FirebaseMock $fb) {}

    public function onboard(array $data): bool
    {
        $name      = trim($data['name']       ?? '');
        $school_id = trim($data['school_id']  ?? '');
        $email     = trim($data['admin_email'] ?? '');
        $plan_id   = trim($data['plan_id']     ?? 'plan_basic');
        $session   = trim($data['session']     ?? date('Y') . '-' . (date('y') + 1));

        if ($name === '') throw new \InvalidArgumentException('School name is required.');
        if (!preg_match(self::NAME_REGEX, $name)) throw new \InvalidArgumentException('School name contains invalid characters.');

        $now = date('Y-m-d H:i:s');
        $sub = [
            'plan_id'    => $plan_id,
            'plan_name'  => ucfirst(str_replace('plan_', '', $plan_id)),
            'status'     => 'active',
            'end_date'   => date('Y-m-d', strtotime('+1 year')),
            'started_at' => date('Y-m-d'),
        ];

        // 1. Users/Schools
        $ok = $this->fb->set("Users/Schools/{$name}", [
            'name'         => $name,
            'school_id'    => $school_id,
            'status'       => 'active',
            'subscription' => $sub,
            'created_at'   => $now,
        ]);
        if (!$ok) return false;

        // 2. School_ids
        $this->fb->set("School_ids/{$school_id}", $name);

        // 3. Admin node
        $admin_id = 'adm_' . substr(md5(uniqid('', true)), 0, 8);
        $this->fb->set("Users/Admin/{$school_id}/{$admin_id}", [
            'name'       => 'School Admin',
            'email'      => $email,
            'Role'       => 'Super Admin',
            'password'   => password_hash('Admin@123', PASSWORD_BCRYPT),
            'school_name'=> $name,
            'created_at' => $now,
        ]);

        // 4. System/Schools metadata
        $this->fb->set("System/Schools/{$name}", [
            'name'         => $name,
            'school_id'    => $school_id,
            'subscription' => $sub,
            'created_at'   => $now,
        ]);

        // 5. Default accounts
        $this->fb->set("Schools/{$name}/{$session}/Accounts", [
            'Fees'     => ['Tuition Fee' => true, 'Admission Fee' => true],
            'Expenses' => ['Utilities' => true, 'Salaries' => true],
        ]);

        return true;
    }

    public function checkAvailability(string $name, string $code): array
    {
        return [
            'name_taken' => $this->fb->get("Users/Schools/{$name}") !== null,
            'code_taken' => $this->fb->get("School_ids/{$code}")    !== null,
        ];
    }

    public function toggleStatus(string $name, string $status): void
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }
        $this->fb->update("Users/Schools/{$name}", ['status' => $status]);
        $this->fb->update("System/Schools/{$name}", ['status' => $status]);
    }
}
