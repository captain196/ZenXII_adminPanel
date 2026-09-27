<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;
use Tests\Support\FakeSrNoBackingStore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Apaar_service.php';

/**
 * Multi-tenant isolation for Apaar_service — mirrors
 * SrNoServiceMultiTenantTest.php's structure and coverage, per the Phase 9
 * directive not to assume SRNO's multi-tenant tests prove APAAR correctness.
 * Every test shares ONE FakeSrNoBackingStore across two "school" fakes
 * (SCH_A, SCH_B), modeling one shared Firestore project with isolation via
 * doc-id prefixing only.
 */
class ApaarServiceMultiTenantTest extends TestCase
{
    private function svcFor(FakeSrNoFirestore $f, string $schoolId): \Apaar_service
    {
        $svc = new \Apaar_service();
        return $svc->init($f, $f, $schoolId, 'ADM_TESTER');
    }

    // ── Part 16 TEST 9 — same APAAR digits, two schools, both succeed ────

    public function test_same_apaar_in_two_different_schools_does_not_collide(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $resA = $this->svcFor($schoolA, 'SCH_A')->claim('123456789012', 'STU0011');
        $resB = $this->svcFor($schoolB, 'SCH_B')->claim('123456789012', 'STU0021');

        $this->assertTrue($resA['ok'], 'School A must be able to claim this APAAR');
        $this->assertTrue($resB['ok'], 'School B must independently be able to claim the SAME digits — different tenant, different namespace, matching the LOCKED school-scoped (not global) uniqueness design');

        $this->assertSame('STU0011', $backing->store['apaarIndex']['SCH_A_123456789012']['currentStudentId']);
        $this->assertSame('STU0021', $backing->store['apaarIndex']['SCH_B_123456789012']['currentStudentId']);
        $this->assertCount(2, $backing->store['apaarIndex']);
    }

    // ── Part 16 TEST 11 — duplicate APAAR WITHIN one school still rejected ──

    public function test_duplicate_apaar_within_the_same_school_is_rejected_atomically(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($schoolA, 'SCH_A');

        $svc->claim('123456789012', 'STU0011');
        $res = $svc->claim('123456789012', 'STU0012');

        $this->assertFalse($res['ok']);
        $this->assertSame('duplicate', $res['reason']);
        $this->assertSame('STU0011', $backing->store['apaarIndex']['SCH_A_123456789012']['currentStudentId']);
    }

    // ── Part 16 TEST 10 — cross-school Student ID lookup cannot claim another school's APAAR ──

    public function test_school_a_cannot_resolve_school_bs_apaar_claim(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $this->svcFor($schoolB, 'SCH_B')->claim('123456789012', 'STU0021');

        $lookupFromA = $this->svcFor($schoolA, 'SCH_A')->resolve('123456789012');
        $this->assertFalse($lookupFromA['ok'], 'School A must not resolve School B\'s APAAR claim');

        $lookupFromB = $this->svcFor($schoolB, 'SCH_B')->resolve('123456789012');
        $this->assertTrue($lookupFromB['ok']);
        $this->assertSame('STU0021', $lookupFromB['currentStudentId']);
    }

    // ── Cross-school relink attempt — structurally impossible, same pattern as SRNO ──

    public function test_relink_cannot_use_a_previous_student_id_that_only_exists_in_another_school(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $this->svcFor($schoolA, 'SCH_A')->claim('123456789012', 'STU0011');
        $this->svcFor($schoolB, 'SCH_B')->claim('999999999999', 'STU0099');

        $res = $this->svcFor($schoolA, 'SCH_A')->relink('123456789012', 'STU0099', 'STU0047');

        $this->assertFalse($res['ok']);
        $this->assertSame('expected_owner_mismatch', $res['reason']);
        $this->assertSame('STU0011', $backing->store['apaarIndex']['SCH_A_123456789012']['currentStudentId']);
    }

    // ── Part 16 TEST 12 — two simultaneous claims for the same school+APAAR cannot both win ──

    public function test_simultaneous_claims_same_school_same_apaar_only_one_wins(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);

        $first  = $this->svcFor($schoolA, 'SCH_A')->claim('123456789012', 'STU0011');
        $second = $this->svcFor($schoolA, 'SCH_A')->claim('123456789012', 'STU0012');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('duplicate', $second['reason']);
        $this->assertCount(1, $backing->store['apaarIndex']);
    }

    // ── Part 8 — APAAR follows the person, never a double-active-ownership state ──

    public function test_apaar_double_spend_of_a_historical_predecessor_is_rejected(): void
    {
        $backing = new FakeSrNoBackingStore();
        $school = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($school, 'SCH_A');

        $svc->claim('123456789012', 'STU0001');
        $first = $svc->relink('123456789012', 'STU0001', 'STU0047');
        $this->assertTrue($first['ok']);

        // A second, DIFFERENT new admission also asserting STU0001 as predecessor.
        $second = $svc->relink('123456789012', 'STU0001', 'STU0099');
        $this->assertFalse($second['ok'], 'STU0001 must not be usable as an APAAR predecessor a second time');
        $this->assertSame('expected_owner_mismatch', $second['reason']);
        $this->assertSame('STU0047', $backing->store['apaarIndex']['SCH_A_123456789012']['currentStudentId']);
    }
}
