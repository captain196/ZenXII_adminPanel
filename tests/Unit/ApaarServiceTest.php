<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Apaar_service.php';

/**
 * Dedicated Apaar_service tests — per the Phase 9 directive, does NOT assume
 * SrNoServiceTest.php automatically proves APAAR correctness. Reuses the
 * same generic FakeSrNoFirestore/FakeSrNoBackingStore doubles (they are
 * collection-name-agnostic — verified by reading them; nothing hardcodes
 * 'srNoIndex'), exercising the REAL claim/relink logic in Apaar_service.php
 * against real control flow.
 *
 * No live Firestore/browser is available in this unit-test environment
 * (same documented constraint as SrNoServiceTest.php) — see the Phase 9
 * implementation report's "Tests executed" section for what remains
 * staging/browser-only.
 */
class ApaarServiceTest extends TestCase
{
    private function svc(FakeSrNoFirestore $f): \Apaar_service
    {
        $svc = new \Apaar_service();
        return $svc->init($f, $f, 'SCH_TEST', 'ADM_TESTER');
    }

    // ── normalize() — pure function, no Firestore needed ────────────────

    public function test_normalize_accepts_exactly_12_digits(): void
    {
        $this->assertSame('123456789012', \Apaar_service::normalize('123456789012'));
    }

    public function test_normalize_strips_spaces_and_hyphens(): void
    {
        $this->assertSame('123456789012', \Apaar_service::normalize('1234 5678 9012'));
        $this->assertSame('123456789012', \Apaar_service::normalize('1234-5678-9012'));
        $this->assertSame('123456789012', \Apaar_service::normalize(' 1234 5678 9012 '));
    }

    public function test_normalize_rejects_wrong_length_or_non_digit_input(): void
    {
        foreach (['12345678901', '1234567890123', 'ABCD5678EFGH', '', ' ', '12345678901२'] as $bad) {
            $this->assertNull(\Apaar_service::normalize($bad), "expected null for input [{$bad}]");
        }
    }

    public function test_normalize_never_invents_a_checksum_it_only_checks_length_and_digits(): void
    {
        // Two 12-digit values that would fail any plausible check-digit scheme
        // must still both normalize successfully — SR_NUMBER_AND_APAAR_ID_DESIGN.md
        // §4 explicitly forbids inventing a checksum rule.
        $this->assertSame('000000000000', \Apaar_service::normalize('000000000000'));
        $this->assertSame('999999999999', \Apaar_service::normalize('999999999999'));
    }

    // ── claim() — first-time allocation ──────────────────────────────────

    public function test_first_claim_succeeds(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->claim('1234 5678 9012', 'STU0001');
        $this->assertTrue($res['ok']);
        $this->assertSame('STU0001', $f->backing()->store['apaarIndex']['SCH_TEST_123456789012']['currentStudentId']);
        $this->assertSame('123456789012', $f->backing()->store['apaarIndex']['SCH_TEST_123456789012']['apaarId']);
    }

    public function test_claim_rejects_invalid_format_without_writing_anything(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->claim('12345', 'STU0001');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_format', $res['reason']);
        $this->assertArrayNotHasKey('apaarIndex', $f->backing()->store, 'an invalid-format claim must write nothing');
    }

    // ── Duplicate protection — atomic, school-scoped ─────────────────────

    public function test_duplicate_apaar_within_the_same_school_is_rejected(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');

        $res = $svc->claim('123456789012', 'STU0099');
        $this->assertFalse($res['ok']);
        $this->assertSame('duplicate', $res['reason']);
        $this->assertSame('STU0001', $res['currentStudentId']);
        // Ownership must be completely unchanged by the rejected attempt.
        $this->assertSame('STU0001', $f->backing()->store['apaarIndex']['SCH_TEST_123456789012']['currentStudentId']);
    }

    public function test_simultaneous_claims_for_the_same_apaar_cannot_both_win(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);

        // firestoreCreate's atomic create-if-not-exists is the collision
        // primitive — the second "simultaneous" call in a single-threaded
        // test deterministically observes the first one already committed,
        // exactly as a real concurrent request would on a real 409.
        $first  = $svc->claim('123456789012', 'STU0001');
        $second = $svc->claim('123456789012', 'STU0099');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('duplicate', $second['reason']);
    }

    // ── resolve() ─────────────────────────────────────────────────────────

    public function test_resolve_returns_current_owner(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');

        $res = $svc->resolve('123456789012');
        $this->assertTrue($res['ok']);
        $this->assertSame('STU0001', $res['currentStudentId']);
    }

    public function test_resolve_of_unclaimed_apaar_fails_closed(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->resolve('999999999999');
        $this->assertFalse($res['ok']);
    }

    // ── relink() — verified re-admission, APAAR follows the PERSON ──────

    public function test_verified_readmission_relinks_apaar_to_the_new_student(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');

        $res = $svc->relink('123456789012', 'STU0001', 'STU0047');
        $this->assertTrue($res['ok']);
        $this->assertSame('relinked', $res['reason']);

        $idx = $f->backing()->store['apaarIndex']['SCH_TEST_123456789012'];
        $this->assertSame('STU0047', $idx['currentStudentId']);
        $this->assertContains('STU0001', $idx['historicalStudentIds']);

        // The new student's own record gets apaarId, in the SAME batch —
        // never a second, independent APAAR ownership record (Part 8's
        // explicit "do not allow two independent active ownership records").
        $newStuDoc = $f->backing()->store['students']['SCH_TEST_STU0047'];
        $this->assertSame('123456789012', $newStuDoc['apaarId']);

        // The OLD student's own record is untouched — relink() never writes to it.
        $this->assertArrayNotHasKey('SCH_TEST_STU0001', $f->backing()->store['students'] ?? []);

        $lookup = $svc->resolve('123456789012');
        $this->assertSame('STU0047', $lookup['currentStudentId']);
    }

    public function test_relink_rejects_a_wrong_expected_previous_student_id(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');

        $res = $svc->relink('123456789012', 'STU0099', 'STU0047');
        $this->assertFalse($res['ok']);
        $this->assertSame('expected_owner_mismatch', $res['reason']);
        $this->assertSame('STU0001', $f->backing()->store['apaarIndex']['SCH_TEST_123456789012']['currentStudentId']);
    }

    public function test_double_submitted_relink_is_idempotent_no_duplicate_ownership(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');

        $first  = $svc->relink('123456789012', 'STU0001', 'STU0047');
        $second = $svc->relink('123456789012', 'STU0001', 'STU0047');

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame('noop', $second['reason']);

        $idx = $f->backing()->store['apaarIndex']['SCH_TEST_123456789012'];
        $this->assertSame(['STU0001'], array_values($idx['historicalStudentIds']));
        $this->assertCount(2, $idx['history']);
    }

    public function test_concurrent_readmission_only_one_relink_succeeds(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');

        $adminA = $svc->relink('123456789012', 'STU0001', 'STU0047');
        $adminB = $svc->relink('123456789012', 'STU0001', 'STU0048');

        $this->assertTrue($adminA['ok']);
        $this->assertFalse($adminB['ok']);
        $this->assertSame('expected_owner_mismatch', $adminB['reason']);
        $this->assertSame('STU0047', $f->backing()->store['apaarIndex']['SCH_TEST_123456789012']['currentStudentId'], 'no split ownership — exactly one current holder');
    }

    public function test_relink_rejects_self_reference(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0099');

        $res = $svc->relink('123456789012', 'STU0099', 'STU0099');
        $this->assertFalse($res['ok']);
        $this->assertSame('same_student_id', $res['reason']);
    }

    public function test_relink_against_a_never_claimed_apaar_fails_closed(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->relink('999999999999', 'STU0001', 'STU0047');
        $this->assertFalse($res['ok']);
        $this->assertSame('apaar_not_found', $res['reason']);
    }

    public function test_repeated_readmission_chain_preserves_full_history(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');
        $svc->relink('123456789012', 'STU0001', 'STU0047');
        $svc->relink('123456789012', 'STU0047', 'STU0098');

        $idx = $f->backing()->store['apaarIndex']['SCH_TEST_123456789012'];
        $this->assertSame('STU0098', $idx['currentStudentId']);
        $this->assertSame(['STU0001', 'STU0047'], array_values($idx['historicalStudentIds']));
    }

    public function test_relink_rejects_circular_chain(): void
    {
        // IMPORTANT: unlike SRNO's own relink(), Apaar_service::relink()
        // deliberately does NOT write `previousStudentId` — that field is
        // Sr_no_service::relink()'s sole responsibility (Part 8: "do not
        // modify Sr_no_service.php... APAAR must have its own... mechanism").
        // In production, Readmission::confirm() always calls the SRNO relink
        // FIRST, so previousStudentId already exists by the time Apaar's own
        // relink runs. This test reproduces that ordering explicitly by
        // seeding the fake student doc directly, rather than relying on
        // Apaar_service's own (nonexistent) previousStudentId write — Apaar's
        // cycle guard must still correctly READ a chain established by SRNO's
        // relink, even though it never WRITES that chain itself.
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');
        $f->commitBatch([[
            'op' => 'update', 'collection' => 'students', 'docId' => 'SCH_TEST_STU0047',
            'data' => ['previousStudentId' => 'STU0001'], 'merge' => true, 'precondition' => null,
        ]]);

        // A later attempt treating STU0001 as descending from STU0047 would
        // close a loop — must be rejected, mirroring Sr_no_service's own guard.
        $res = $svc->relink('123456789012', 'STU0047', 'STU0001');
        $this->assertFalse($res['ok']);
        $this->assertSame('would_create_cycle', $res['reason']);
    }

    // ── Identity-domain isolation — the whole point of a SEPARATE service ──

    public function test_apaar_relink_never_touches_srNoIndex(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');
        $svc->relink('123456789012', 'STU0001', 'STU0047');

        $this->assertArrayNotHasKey('srNoIndex', $f->backing()->store,
            'Apaar_service must never write to srNoIndex — SRNO and APAAR are separate identity domains');
    }

    public function test_relink_only_writes_apaarIndex_and_the_new_student_doc(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('123456789012', 'STU0001');
        $svc->relink('123456789012', 'STU0001', 'STU0047');

        $this->assertEqualsCanonicalizing(['apaarIndex', 'students'], array_keys($f->backing()->store),
            'relink() must never touch any collection other than apaarIndex and the new student\'s own record');
    }

    // ── Optionality — blank/absent APAAR is always a legitimate answer ──

    public function test_blank_apaar_is_never_claimed_by_the_caller_contract(): void
    {
        // Apaar_service itself has no "optional" branch — callers (Sis::enroll_student,
        // Readmission::confirm) are responsible for never calling claim()/relink() with
        // an empty string. This test documents that contract: an empty string is simply
        // an invalid-format rejection, not a silently-accepted "no APAAR" no-op, so a
        // caller MUST guard on emptiness before calling in (which both callers do).
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->claim('', 'STU0001');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_format', $res['reason']);
    }
}
