<?php
namespace Tests\Unit;

use Tests\Support\TestCase;

/**
 * AdmissionCrmTest
 *
 * Tests the Admission CRM business logic using FirebaseMock.
 * Covers: inquiry/application CRUD, stage validation, status transitions,
 *         student ID format, duplicate detection, phone/email validation,
 *         enrollment schema, and waitlist management.
 */
class AdmissionCrmTest extends TestCase
{
    private AdmissionCrmService $svc;
    private string $school   = 'Test School';
    private string $schoolId = 'TST001';
    private string $session  = '2025-26';

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AdmissionCrmService($this->firebase, $this->school, $this->schoolId, $this->session);

        // Seed classes so _get_classes works
        $this->firebase->seed("Schools/{$this->school}/{$this->session}/Class 9th", [
            'Section A' => ['Students' => ['List' => []]],
        ]);
        $this->firebase->seed("Schools/{$this->school}/{$this->session}/Class 10th", [
            'Section A' => ['Students' => ['List' => []]],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. INQUIRY CRUD
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function save_inquiry_creates_new_inquiry(): void
    {
        $result = $this->svc->saveInquiry([
            'student_name'   => 'John Doe',
            'parent_name'    => 'Jane Doe',
            'phone'          => '9876543210',
            'email'          => 'jane@example.com',
            'class'          => '9th',
            'source'         => 'Walk-in',
        ]);

        $this->assertSame('success', $result['status']);
        $id = $result['data']['id'];
        $this->assertStringStartsWith('INQ', $id);

        $stored = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Inquiries/{$id}");
        $this->assertSame('John Doe', $stored['student_name']);
        $this->assertSame($this->session, $stored['session']);
    }

    /** @test */
    public function save_inquiry_rejects_missing_fields(): void
    {
        $result = $this->svc->saveInquiry([
            'student_name' => '',
            'parent_name'  => 'Jane',
            'phone'        => '9876543210',
        ]);
        $this->assertSame('error', $result['status']);
    }

    /** @test */
    public function save_inquiry_rejects_invalid_phone(): void
    {
        $result = $this->svc->saveInquiry([
            'student_name' => 'John',
            'parent_name'  => 'Jane',
            'phone'        => 'abc123',
            'email'        => '',
        ]);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('phone', strtolower($result['message']));
    }

    /** @test */
    public function save_inquiry_rejects_invalid_email(): void
    {
        $result = $this->svc->saveInquiry([
            'student_name' => 'John',
            'parent_name'  => 'Jane',
            'phone'        => '9876543210',
            'email'        => 'not-an-email',
        ]);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('email', strtolower($result['message']));
    }

    /** @test */
    public function delete_inquiry_removes_from_firebase(): void
    {
        $result = $this->svc->saveInquiry([
            'student_name' => 'A', 'parent_name' => 'B', 'phone' => '1234567890',
        ]);
        $id = $result['data']['id'];

        $this->svc->deleteInquiry($id);
        $this->assertNull(
            $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Inquiries/{$id}")
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2. APPLICATION CRUD
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function save_application_creates_new_application(): void
    {
        $result = $this->svc->saveApplication([
            'student_name' => 'Alice', 'class' => '10th', 'phone' => '9876543210',
        ]);
        $this->assertSame('success', $result['status']);
        $id = $result['data']['id'];
        $this->assertStringStartsWith('APP', $id);

        $stored = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$id}");
        $this->assertSame('pending', $stored['status']);
        $this->assertSame('document_collection', $stored['stage']);
    }

    /** @test */
    public function save_application_rejects_invalid_phone(): void
    {
        $result = $this->svc->saveApplication([
            'student_name' => 'Alice', 'class' => '10th', 'phone' => 'badphone',
        ]);
        $this->assertSame('error', $result['status']);
    }

    /** @test */
    public function save_application_rejects_invalid_email(): void
    {
        $result = $this->svc->saveApplication([
            'student_name' => 'Alice', 'class' => '10th', 'phone' => '9876543210',
            'email' => 'nope',
        ]);
        $this->assertSame('error', $result['status']);
    }

    /** @test */
    public function convert_inquiry_to_application(): void
    {
        $inq = $this->svc->saveInquiry([
            'student_name' => 'Bob', 'parent_name' => 'Dad', 'phone' => '9876543210',
            'class' => '9th',
        ]);
        $inqId = $inq['data']['id'];

        $result = $this->svc->convertToApplication($inqId);
        $this->assertSame('success', $result['status']);
        $appId = $result['data']['application_id'];

        // Inquiry should be marked as converted
        $inquiry = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Inquiries/{$inqId}");
        $this->assertSame('converted', $inquiry['status']);
        $this->assertSame($appId, $inquiry['application_id']);

        // Application should exist
        $app = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('Bob', $app['student_name']);
        $this->assertSame('pending', $app['status']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. STAGE VALIDATION
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function update_stage_rejects_invalid_stage(): void
    {
        $app = $this->svc->saveApplication([
            'student_name' => 'Carol', 'class' => '9th',
        ]);
        $appId = $app['data']['id'];

        $result = $this->svc->updateStage($appId, 'completely_made_up_stage');
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid stage', $result['message']);
    }

    /** @test */
    public function update_stage_accepts_valid_stage(): void
    {
        $app = $this->svc->saveApplication([
            'student_name' => 'Carol', 'class' => '9th',
        ]);
        $appId = $app['data']['id'];

        $result = $this->svc->updateStage($appId, 'under_review');
        $this->assertSame('success', $result['status']);

        $stored = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('under_review', $stored['stage']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. STATUS TRANSITION VALIDATION
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function approve_rejects_already_enrolled(): void
    {
        $appId = $this->_createAndApproveApp('Dave', '9th');

        // Enroll first
        $this->svc->enrollStudent($appId);

        // Now try to approve again
        $result = $this->svc->approveApplication($appId);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('enrolled', strtolower($result['message']));
    }

    /** @test */
    public function approve_rejects_already_approved(): void
    {
        $appId = $this->_createAndApproveApp('Eve', '10th');

        $result = $this->svc->approveApplication($appId);
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('already approved', strtolower($result['message']));
    }

    /** @test */
    public function reject_rejects_already_enrolled(): void
    {
        $appId = $this->_createAndApproveApp('Frank', '9th');
        $this->svc->enrollStudent($appId);

        $result = $this->svc->rejectApplication($appId);
        $this->assertSame('error', $result['status']);
    }

    /** @test */
    public function reject_rejects_already_rejected(): void
    {
        $app = $this->svc->saveApplication([
            'student_name' => 'Grace', 'class' => '9th',
        ]);
        $appId = $app['data']['id'];
        $this->svc->rejectApplication($appId);

        $result = $this->svc->rejectApplication($appId);
        $this->assertSame('error', $result['status']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5. STUDENT ID FORMAT — must match Student::studentAdmission()
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function enroll_generates_correct_student_id_format(): void
    {
        // Set Count to simulate existing students
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 5);

        $appId = $this->_createAndApproveApp('Hannah', '9th');
        $result = $this->svc->enrollStudent($appId);

        $this->assertSame('success', $result['status']);
        // STU000 + count (5) → STU0005 — matches Student.php: 'STU000' . $studentIdCount
        $this->assertSame('STU0005', $result['data']['student_id']);
    }

    /** @test */
    public function enroll_student_id_format_at_count_10(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 10);

        $appId = $this->_createAndApproveApp('Ivan', '10th');
        $result = $this->svc->enrollStudent($appId);

        // STU000 + 10 = STU00010 (NOT STU0010)
        $this->assertSame('STU00010', $result['data']['student_id']);
    }

    /** @test */
    public function enroll_student_id_format_at_count_100(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 100);

        $appId = $this->_createAndApproveApp('Julia', '9th');
        $result = $this->svc->enrollStudent($appId);

        // STU000 + 100 = STU000100
        $this->assertSame('STU000100', $result['data']['student_id']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6. ENROLLMENT — writes correct Firebase nodes
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function enroll_writes_student_profile(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 1);

        $appId = $this->_createAndApproveApp('Karen', '9th', [
            'gender' => 'Female', 'dob' => '2010-05-15',
            'father_name' => 'Karl', 'phone' => '9999999999',
        ]);

        $result = $this->svc->enrollStudent($appId);
        $stuId = $result['data']['student_id'];

        $profile = $this->firebase->get("Users/Parents/{$this->schoolId}/{$stuId}");
        $this->assertSame('Karen', $profile['Name']);
        $this->assertSame($stuId, $profile['User Id']);
        $this->assertSame('9th', $profile['Class']);
        $this->assertSame('A', $profile['Section']);
        $this->assertSame('Female', $profile['Gender']);
        $this->assertSame('Karl', $profile['Father Name']);
        $this->assertSame('15-05-2010', $profile['DOB']);
        $this->assertArrayHasKey('Address', $profile);
        $this->assertArrayHasKey('Doc', $profile);
    }

    /** @test */
    public function enroll_writes_class_roster(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 1);

        $appId = $this->_createAndApproveApp('Leo', '9th');
        $result = $this->svc->enrollStudent($appId);
        $stuId = $result['data']['student_id'];

        $roster = $this->firebase->get(
            "Schools/{$this->school}/{$this->session}/Class 9th/Section A/Students/List/{$stuId}"
        );
        $this->assertSame('Leo', $roster);
    }

    /** @test */
    public function enroll_updates_count(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 3);

        $appId = $this->_createAndApproveApp('Max', '10th');
        $this->svc->enrollStudent($appId);

        $count = $this->firebase->get("Users/Parents/{$this->schoolId}/Count");
        $this->assertSame(4, $count);
    }

    /** @test */
    public function enroll_updates_phone_mappings(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 1);

        $appId = $this->_createAndApproveApp('Nora', '9th', ['phone' => '8888888888']);
        $result = $this->svc->enrollStudent($appId);
        $stuId = $result['data']['student_id'];

        $this->assertSame($this->schoolId, $this->firebase->get('Exits/8888888888'));
        $this->assertSame($stuId, $this->firebase->get('User_ids_pno/8888888888'));
    }

    /** @test */
    public function enroll_rejects_non_approved_application(): void
    {
        $app = $this->svc->saveApplication([
            'student_name' => 'Oscar', 'class' => '9th',
        ]);
        $result = $this->svc->enrollStudent($app['data']['id']);
        $this->assertSame('error', $result['status']);
    }

    /** @test */
    public function enroll_marks_application_as_enrolled(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 1);
        $appId = $this->_createAndApproveApp('Pat', '9th');
        $this->svc->enrollStudent($appId);

        $app = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('enrolled', $app['status']);
        $this->assertSame('enrolled', $app['stage']);
        $this->assertArrayHasKey('student_id', $app);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7. DUPLICATE DETECTION (online form)
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function submit_online_form_detects_duplicate_phone(): void
    {
        $first = $this->svc->submitOnlineForm([
            'student_name' => 'Quinn', 'parent_name' => 'QP', 'phone' => '7777777777', 'class' => '9th',
        ]);
        $this->assertSame('success', $first['status']);

        // Second submission with same phone
        $second = $this->svc->submitOnlineForm([
            'student_name' => 'Quinn2', 'parent_name' => 'QP2', 'phone' => '7777777777', 'class' => '10th',
        ]);
        $this->assertSame('error', $second['status']);
        $this->assertStringContainsString('already exists', strtolower($second['message']));
    }

    /** @test */
    public function submit_online_form_validates_phone(): void
    {
        $result = $this->svc->submitOnlineForm([
            'student_name' => 'Rita', 'parent_name' => 'RP', 'phone' => 'notaphone', 'class' => '9th',
        ]);
        $this->assertSame('error', $result['status']);
    }

    /** @test */
    public function submit_online_form_creates_inquiry_and_application(): void
    {
        $result = $this->svc->submitOnlineForm([
            'student_name' => 'Sam', 'parent_name' => 'SP', 'phone' => '6666666666', 'class' => '9th',
        ]);
        $this->assertSame('success', $result['status']);
        $appId = $result['data']['application_id'];

        // Both inquiry and application should exist
        $this->firebase->assertCalled('set', 'Inquiries/', $this);
        $this->firebase->assertCalled('set', 'Applications/', $this);

        $app = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('pending', $app['status']);
        $this->assertSame('Online', $app['created_by']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8. WAITLIST
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function add_to_waitlist_and_promote(): void
    {
        $this->firebase->seed("Users/Parents/{$this->schoolId}/Count", 1);

        $app = $this->svc->saveApplication([
            'student_name' => 'Tim', 'class' => '9th', 'phone' => '5555555555',
        ]);
        $appId = $app['data']['id'];

        // Add to waitlist
        $wl = $this->svc->addToWaitlist($appId, 'Class full', 5);
        $this->assertSame('success', $wl['status']);
        $wlId = $wl['data']['id'];

        // Application should be waitlisted
        $stored = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('waitlisted', $stored['status']);

        // Promote
        $promote = $this->svc->promoteFromWaitlist($wlId);
        $this->assertSame('success', $promote['status']);

        // Application should be approved, waitlist entry deleted
        $stored = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('approved', $stored['status']);
        $this->assertNull(
            $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Waitlist/{$wlId}")
        );
    }

    /** @test */
    public function remove_from_waitlist_restores_pending(): void
    {
        $app = $this->svc->saveApplication([
            'student_name' => 'Uma', 'class' => '10th',
        ]);
        $appId = $app['data']['id'];
        $wl = $this->svc->addToWaitlist($appId, 'No seats', 10);
        $wlId = $wl['data']['id'];

        $this->svc->removeFromWaitlist($wlId);

        $stored = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Applications/{$appId}");
        $this->assertSame('pending', $stored['status']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 9. COUNTER INCREMENT
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function counter_increments_correctly_across_operations(): void
    {
        // Create inquiry (counter 0→1), then application (counter 1→2)
        $this->svc->saveInquiry([
            'student_name' => 'V', 'parent_name' => 'VP', 'phone' => '1111111111',
        ]);
        $this->svc->saveApplication([
            'student_name' => 'W', 'class' => '9th',
        ]);

        $counter = $this->firebase->get("Schools/{$this->school}/CRM/Admissions/Counter");
        $this->assertSame(2, $counter);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function _createAndApproveApp(string $name, string $class, array $extra = []): string
    {
        $data = array_merge(['student_name' => $name, 'class' => $class], $extra);
        $app = $this->svc->saveApplication($data);
        $appId = $app['data']['id'];
        $this->svc->approveApplication($appId);
        return $appId;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Service layer — extracts controller business logic for testability
// ═══════════════════════════════════════════════════════════════════════════

class AdmissionCrmService
{
    private $firebase;
    private string $school;
    private string $schoolId;
    private string $session;
    private string $base;

    public function __construct($firebase, string $school, string $schoolId, string $session)
    {
        $this->firebase = $firebase;
        $this->school   = $school;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->base     = "Schools/{$school}/CRM/Admissions";
    }

    // ── Inquiry ──

    public function saveInquiry(array $input, ?string $id = null): array
    {
        $student_name = trim($input['student_name'] ?? '');
        $parent_name  = trim($input['parent_name'] ?? '');
        $phone        = trim($input['phone'] ?? '');
        $email        = trim($input['email'] ?? '');

        if ($student_name === '' || $parent_name === '' || $phone === '') {
            return $this->error('Student name, parent name, and phone are required');
        }
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $phone))) {
            return $this->error('Invalid phone number format');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email address');
        }

        $now = date('Y-m-d H:i:s');

        if ($id) {
            $existing = $this->firebase->get("{$this->base}/Inquiries/{$id}");
            if (!is_array($existing)) return $this->error('Inquiry not found');
            $data = array_merge($existing, array_intersect_key($input, array_flip([
                'student_name','parent_name','phone','email','class','source','notes','status','follow_up_date',
            ])));
            $data['updated_at'] = $now;
            $this->firebase->set("{$this->base}/Inquiries/{$id}", $data);
        } else {
            $counter = (int)($this->firebase->get("{$this->base}/Counter") ?? 0);
            $counter++;
            $id = 'INQ' . str_pad($counter, 5, '0', STR_PAD_LEFT);

            $data = [
                'inquiry_id'   => $id,
                'student_name' => $student_name,
                'parent_name'  => $parent_name,
                'phone'        => $phone,
                'email'        => $email,
                'class'        => $input['class'] ?? '',
                'source'       => $input['source'] ?? 'Walk-in',
                'notes'        => $input['notes'] ?? '',
                'status'       => $input['status'] ?? 'new',
                'session'      => $this->session,
                'created_at'   => $now,
                'updated_at'   => $now,
                'created_by'   => 'Test',
            ];
            $this->firebase->set("{$this->base}/Inquiries/{$id}", $data);
            $this->firebase->set("{$this->base}/Counter", $counter);
        }

        return $this->success('Inquiry saved', ['id' => $id]);
    }

    public function deleteInquiry(string $id): array
    {
        $this->firebase->delete("{$this->base}/Inquiries", $id);
        return $this->success('Deleted');
    }

    public function convertToApplication(string $inquiryId): array
    {
        $inquiry = $this->firebase->get("{$this->base}/Inquiries/{$inquiryId}");
        if (!is_array($inquiry)) return $this->error('Inquiry not found');

        $counter = (int)($this->firebase->get("{$this->base}/Counter") ?? 0);
        $counter++;
        $appId = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $now = date('Y-m-d H:i:s');
        $application = [
            'application_id' => $appId,
            'inquiry_id'     => $inquiryId,
            'student_name'   => $inquiry['student_name'] ?? '',
            'parent_name'    => $inquiry['parent_name'] ?? '',
            'phone'          => $inquiry['phone'] ?? '',
            'email'          => $inquiry['email'] ?? '',
            'class'          => $inquiry['class'] ?? '',
            'session'        => $inquiry['session'] ?? $this->session,
            'status'         => 'pending',
            'stage'          => 'document_collection',
            'created_at'     => $now,
            'updated_at'     => $now,
            'created_by'     => 'Test',
            'history'        => [['action' => "Created from {$inquiryId}", 'by' => 'Test', 'timestamp' => $now]],
        ];

        $this->firebase->set("{$this->base}/Applications/{$appId}", $application);
        $this->firebase->set("{$this->base}/Counter", $counter);
        $this->firebase->update("{$this->base}/Inquiries/{$inquiryId}", [
            'status' => 'converted', 'application_id' => $appId, 'updated_at' => $now,
        ]);

        return $this->success('Converted', ['application_id' => $appId]);
    }

    // ── Application ──

    public function saveApplication(array $input, ?string $id = null): array
    {
        $studentName = trim($input['student_name'] ?? '');
        $class       = trim($input['class'] ?? '');
        $phone       = trim($input['phone'] ?? '');
        $email       = trim($input['email'] ?? '');

        if ($studentName === '' || $class === '') {
            return $this->error('Student name and class are required');
        }
        if ($phone !== '' && !preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $phone))) {
            return $this->error('Invalid phone number format');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email address');
        }

        $now = date('Y-m-d H:i:s');

        if ($id) {
            $existing = $this->firebase->get("{$this->base}/Applications/{$id}");
            if (!is_array($existing)) return $this->error('Application not found');
            $input['updated_at'] = $now;
            $this->firebase->update("{$this->base}/Applications/{$id}", $input);
            return $this->success('Updated', ['id' => $id]);
        }

        $counter = (int)($this->firebase->get("{$this->base}/Counter") ?? 0);
        $counter++;
        $appId = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $data = array_merge($input, [
            'application_id' => $appId,
            'session'        => $this->session,
            'status'         => 'pending',
            'stage'          => 'document_collection',
            'created_at'     => $now,
            'updated_at'     => $now,
            'created_by'     => 'Test',
            'history'        => [['action' => 'Created', 'by' => 'Test', 'timestamp' => $now]],
        ]);

        $this->firebase->set("{$this->base}/Applications/{$appId}", $data);
        $this->firebase->set("{$this->base}/Counter", $counter);

        return $this->success('Created', ['id' => $appId]);
    }

    // ── Stage ──

    public function updateStage(string $id, string $stage): array
    {
        if (!$id || !$stage) return $this->error('ID and stage required');

        $settings = $this->firebase->get("{$this->base}/Settings") ?? [];
        $allowed = (is_array($settings) && !empty($settings['stages']))
            ? array_keys($settings['stages'])
            : array_keys($this->defaultStages());
        if (!in_array($stage, $allowed, true)) {
            return $this->error('Invalid stage: ' . $stage);
        }

        $app = $this->firebase->get("{$this->base}/Applications/{$id}");
        if (!is_array($app)) return $this->error('Application not found');

        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = ['action' => "Stage: {$app['stage']} → {$stage}", 'by' => 'Test', 'timestamp' => $now];

        $this->firebase->update("{$this->base}/Applications/{$id}", [
            'stage' => $stage, 'updated_at' => $now, 'history' => $history,
        ]);

        return $this->success('Stage updated');
    }

    // ── Approve / Reject ──

    public function approveApplication(string $id, string $remarks = ''): array
    {
        $app = $this->firebase->get("{$this->base}/Applications/{$id}");
        if (!is_array($app)) return $this->error('Application not found');

        $status = $app['status'] ?? 'pending';
        if ($status === 'enrolled') return $this->error('Cannot approve an already enrolled application');
        if ($status === 'approved') return $this->error('Application is already approved');

        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = ['action' => 'Approved', 'by' => 'Test', 'timestamp' => $now];

        $this->firebase->update("{$this->base}/Applications/{$id}", [
            'status' => 'approved', 'stage' => 'approved', 'approved_at' => $now,
            'updated_at' => $now, 'history' => $history,
        ]);

        return $this->success('Approved');
    }

    public function rejectApplication(string $id, string $reason = ''): array
    {
        $app = $this->firebase->get("{$this->base}/Applications/{$id}");
        if (!is_array($app)) return $this->error('Application not found');

        $status = $app['status'] ?? 'pending';
        if ($status === 'enrolled') return $this->error('Cannot reject an already enrolled application');
        if ($status === 'rejected') return $this->error('Application is already rejected');

        $now = date('Y-m-d H:i:s');
        $history = $app['history'] ?? [];
        $history[] = ['action' => 'Rejected', 'by' => 'Test', 'timestamp' => $now];

        $this->firebase->update("{$this->base}/Applications/{$id}", [
            'status' => 'rejected', 'stage' => 'rejected', 'rejected_at' => $now,
            'reject_reason' => $reason, 'updated_at' => $now, 'history' => $history,
        ]);

        return $this->success('Rejected');
    }

    // ── Enroll ──

    public function enrollStudent(string $id): array
    {
        $app = $this->firebase->get("{$this->base}/Applications/{$id}");
        if (!is_array($app)) return $this->error('Application not found');
        if (($app['status'] ?? '') !== 'approved') return $this->error('Only approved applications can be enrolled');

        $studentIdCount = (int)($this->firebase->get("Users/Parents/{$this->schoolId}/Count") ?? 0);
        if ($studentIdCount === 0) $studentIdCount = 1;

        // Match Student.php format: 'STU000' . $studentIdCount
        $studentId = 'STU000' . $studentIdCount;

        $className = trim($app['class'] ?? '');
        $section   = trim($app['section'] ?? 'A');
        if ($className === '') return $this->error('Class not specified');

        $classNode    = "Class {$className}";
        $combinedPath = "{$classNode}/Section {$section}";

        $formattedDOB = '';
        if (!empty($app['dob'])) $formattedDOB = date('d-m-Y', strtotime($app['dob']));

        $now = date('Y-m-d H:i:s');

        $studentData = [
            "Name"              => $app['student_name'] ?? '',
            "User Id"           => $studentId,
            "DOB"               => $formattedDOB,
            "Admission Date"    => date('d-m-Y'),
            "Class"             => $className,
            "Section"           => $section,
            "Gender"            => $app['gender'] ?? '',
            "Blood Group"       => $app['blood_group'] ?? '',
            "Category"          => $app['category'] ?? '',
            "Religion"          => $app['religion'] ?? '',
            "Nationality"       => $app['nationality'] ?? '',
            "Father Name"       => $app['father_name'] ?? '',
            "Father Occupation" => $app['father_occupation'] ?? '',
            "Mother Name"       => $app['mother_name'] ?? '',
            "Mother Occupation" => $app['mother_occupation'] ?? '',
            "Guard Contact"     => $app['guardian_phone'] ?? '',
            "Guard Relation"    => $app['guardian_relation'] ?? '',
            "Phone Number"      => $app['phone'] ?? '',
            "Email"             => $app['email'] ?? '',
            "Password"          => 'test_password',
            "Address"           => [
                "Street"     => $app['address'] ?? '',
                "City"       => $app['city'] ?? '',
                "State"      => $app['state'] ?? '',
                "PostalCode" => $app['pincode'] ?? '',
            ],
            "Pre School"  => $app['previous_school'] ?? '',
            "Pre Class"   => $app['previous_class'] ?? '',
            "Pre Marks"   => $app['previous_marks'] ?? '',
            "Profile Pic" => "",
            "Doc"         => [
                "Aadhar Card"          => ["thumbnail" => "", "url" => ""],
                "Birth Certificate"    => ["thumbnail" => "", "url" => ""],
                "Photo"                => ["thumbnail" => "", "url" => ""],
                "Transfer Certificate" => ["thumbnail" => "", "url" => ""],
            ],
        ];

        $this->firebase->set("Users/Parents/{$this->schoolId}/{$studentId}", $studentData);
        $this->firebase->update(
            "Schools/{$this->school}/{$this->session}/{$combinedPath}/Students",
            [$studentId => ['Name' => $app['student_name'] ?? '']]
        );
        $this->firebase->update(
            "Schools/{$this->school}/{$this->session}/{$combinedPath}/Students/List",
            [$studentId => $app['student_name'] ?? '']
        );
        $this->firebase->set("Users/Parents/{$this->schoolId}/Count", $studentIdCount + 1);

        $phone = trim($app['phone'] ?? '');
        if ($phone !== '') {
            $this->firebase->update('Exits', [$phone => $this->schoolId]);
            $this->firebase->update('User_ids_pno', [$phone => $studentId]);
        }

        $history = $app['history'] ?? [];
        $history[] = ['action' => "Enrolled as {$studentId}", 'by' => 'Test', 'timestamp' => $now];

        $this->firebase->update("{$this->base}/Applications/{$id}", [
            'status' => 'enrolled', 'stage' => 'enrolled', 'student_id' => $studentId,
            'enrolled_at' => $now, 'updated_at' => $now, 'history' => $history,
        ]);

        return $this->success('Enrolled', ['student_id' => $studentId, 'class' => $className, 'section' => $section]);
    }

    // ── Waitlist ──

    public function addToWaitlist(string $appId, string $reason = '', int $priority = 99): array
    {
        $app = $this->firebase->get("{$this->base}/Applications/{$appId}");
        if (!is_array($app)) return $this->error('Application not found');

        $now = date('Y-m-d H:i:s');
        $counter = (int)($this->firebase->get("{$this->base}/Counter") ?? 0);
        $counter++;
        $wlId = 'WL' . str_pad($counter, 5, '0', STR_PAD_LEFT);

        $this->firebase->set("{$this->base}/Waitlist/{$wlId}", [
            'waitlist_id'    => $wlId,
            'application_id' => $appId,
            'student_name'   => $app['student_name'] ?? '',
            'parent_name'    => $app['parent_name'] ?? '',
            'phone'          => $app['phone'] ?? '',
            'class'          => $app['class'] ?? '',
            'session'        => $app['session'] ?? $this->session,
            'priority'       => $priority,
            'reason'         => $reason,
            'status'         => 'waiting',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $this->firebase->set("{$this->base}/Counter", $counter);

        $history = $app['history'] ?? [];
        $history[] = ['action' => 'Added to waitlist', 'by' => 'Test', 'timestamp' => $now];
        $this->firebase->update("{$this->base}/Applications/{$appId}", [
            'status' => 'waitlisted', 'stage' => 'waitlisted', 'updated_at' => $now, 'history' => $history,
        ]);

        return $this->success('Added to waitlist', ['id' => $wlId]);
    }

    public function removeFromWaitlist(string $id): array
    {
        $entry = $this->firebase->get("{$this->base}/Waitlist/{$id}");
        if (is_array($entry) && !empty($entry['application_id'])) {
            $this->firebase->update("{$this->base}/Applications/{$entry['application_id']}", [
                'status' => 'pending', 'stage' => 'document_collection', 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $this->firebase->delete("{$this->base}/Waitlist", $id);
        return $this->success('Removed');
    }

    public function promoteFromWaitlist(string $id): array
    {
        $entry = $this->firebase->get("{$this->base}/Waitlist/{$id}");
        if (!is_array($entry)) return $this->error('Entry not found');

        $appId = $entry['application_id'] ?? '';
        if (!$appId) return $this->error('No linked application');

        $now = date('Y-m-d H:i:s');
        $app = $this->firebase->get("{$this->base}/Applications/{$appId}");
        if (is_array($app)) {
            $history = $app['history'] ?? [];
            $history[] = ['action' => 'Promoted from waitlist', 'by' => 'Test', 'timestamp' => $now];
            $this->firebase->update("{$this->base}/Applications/{$appId}", [
                'status' => 'approved', 'stage' => 'approved', 'approved_at' => $now,
                'updated_at' => $now, 'history' => $history,
            ]);
        }

        $this->firebase->delete("{$this->base}/Waitlist", $id);
        return $this->success('Promoted');
    }

    // ── Online form ──

    public function submitOnlineForm(array $input): array
    {
        $studentName = trim($input['student_name'] ?? '');
        $phone       = trim($input['phone'] ?? '');
        $class       = trim($input['class'] ?? '');
        $email       = trim($input['email'] ?? '');

        if ($studentName === '' || $phone === '' || $class === '') {
            return $this->error('Student name, phone, and class are required');
        }
        if (!preg_match('/^\+?\d{10,15}$/', preg_replace('/[\s\-]/', '', $phone))) {
            return $this->error('Invalid phone number format');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Invalid email address');
        }

        // Duplicate detection
        $existingApps = $this->firebase->get("{$this->base}/Applications") ?? [];
        if (is_array($existingApps)) {
            foreach ($existingApps as $ea) {
                if (!is_array($ea)) continue;
                if (($ea['session'] ?? '') !== $this->session) continue;
                if (($ea['phone'] ?? '') === $phone && in_array($ea['status'] ?? '', ['pending','approved','waitlisted','enrolled'])) {
                    return $this->error('An application with this phone number already exists for this session (ID: ' . ($ea['application_id'] ?? 'N/A') . ')');
                }
            }
        }

        $now = date('Y-m-d H:i:s');
        $counter = (int)($this->firebase->get("{$this->base}/Counter") ?? 0);

        $counter++;
        $inqId = 'INQ' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $this->firebase->set("{$this->base}/Inquiries/{$inqId}", [
            'inquiry_id' => $inqId, 'student_name' => $studentName,
            'parent_name' => $input['parent_name'] ?? '', 'phone' => $phone,
            'email' => $email, 'class' => $class, 'source' => 'Online Form',
            'status' => 'converted', 'session' => $this->session,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => 'Online',
        ]);

        $counter++;
        $appId = 'APP' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $this->firebase->set("{$this->base}/Applications/{$appId}", array_merge($input, [
            'application_id' => $appId, 'inquiry_id' => $inqId,
            'session' => $this->session, 'status' => 'pending',
            'stage' => 'document_collection', 'created_at' => $now,
            'updated_at' => $now, 'created_by' => 'Online', 'documents' => [],
            'history' => [['action' => 'Submitted via online form', 'by' => 'Online', 'timestamp' => $now]],
        ]));

        $this->firebase->update("{$this->base}/Inquiries/{$inqId}", ['application_id' => $appId]);
        $this->firebase->set("{$this->base}/Counter", $counter);

        return $this->success('Submitted', ['application_id' => $appId]);
    }

    // ── Helpers ──

    private function defaultStages(): array
    {
        return [
            'document_collection' => 'Document Collection',
            'under_review'        => 'Under Review',
            'interview'           => 'Interview / Test',
            'approved'            => 'Approved',
            'rejected'            => 'Rejected',
            'waitlisted'          => 'Waitlisted',
        ];
    }

    private function success(string $msg, array $data = []): array
    {
        return ['status' => 'success', 'message' => $msg, 'data' => $data];
    }

    private function error(string $msg): array
    {
        return ['status' => 'error', 'message' => $msg];
    }
}
