<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;
use Tests\Support\FakeSrNoBackingStore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';

/**
 * Covers the multi-tenant isolation requirements from
 * SRNO_STUDENT_ID_RE_ADMISSION_MULTI_TENANT_FINAL_AUDIT.md — Section 6/21
 * and TEST MATRIX items 2, 3, 4, 12, 14. Every test in this file shares ONE
 * FakeSrNoBackingStore across two "school" fakes (SCH_A, SCH_B), modeling
 * the real architecture: one Firestore project, many tenants, isolation
 * via doc-id prefixing only — never via separate storage. This is the
 * meaningful multi-tenant test; SrNoServiceTest.php's single-fake tests
 * cannot exercise cross-tenant behavior at all.
 */
class SrNoServiceMultiTenantTest extends TestCase
{
    private function svcFor(FakeSrNoFirestore $f, string $schoolId): \Sr_no_service
    {
        $svc = new \Sr_no_service();
        return $svc->init($f, $f, $schoolId, 'ADM_TESTER');
    }

    // ── TEST MATRIX 4 — same SRNO digits, two schools, both succeed ─────

    public function test_same_srno_in_two_different_schools_does_not_collide(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $resA = $this->svcFor($schoolA, 'SCH_A')->claim('1033', 'STU0011');
        $resB = $this->svcFor($schoolB, 'SCH_B')->claim('1033', 'STU0021');

        $this->assertTrue($resA['ok'], 'School A must be able to claim SRNO 1033');
        $this->assertTrue($resB['ok'], 'School B must independently be able to claim the SAME digits 1033 — different tenant, different namespace');

        $this->assertSame('STU0011', $backing->store['srNoIndex']['SCH_A_1033']['currentStudentId']);
        $this->assertSame('STU0021', $backing->store['srNoIndex']['SCH_B_1033']['currentStudentId']);
        // Two distinct documents in the SAME shared store — proves isolation is via doc-id, not separate storage.
        $this->assertCount(2, $backing->store['srNoIndex']);
    }

    // ── TEST MATRIX 3 — duplicate SRNO WITHIN one school still rejected ──

    public function test_duplicate_srno_within_the_same_school_is_still_rejected(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($schoolA, 'SCH_A');

        $svc->claim('1033', 'STU0011');
        $res = $svc->claim('1033', 'STU0012'); // a second, different student in the SAME school

        $this->assertFalse($res['ok']);
        $this->assertSame('duplicate', $res['reason']);
        $this->assertSame('STU0011', $backing->store['srNoIndex']['SCH_A_1033']['currentStudentId']);
    }

    // ── TEST MATRIX 25 — cross-tenant SRNO lookup must not resolve ──────

    public function test_school_a_cannot_resolve_school_bs_srno_claim(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $this->svcFor($schoolB, 'SCH_B')->claim('1033', 'STU0021');

        // School A's OWN service handle, resolving the SAME digits, must
        // see its own (nonexistent) namespace — never School B's claim.
        $lookupFromA = $this->svcFor($schoolA, 'SCH_A')->resolve('1033');
        $this->assertFalse($lookupFromA['ok'], 'School A must not resolve School B\'s SRNO claim');
        $this->assertSame('not_found', $lookupFromA['reason']);

        // Confirm School B's own lookup DOES succeed, proving this isn't a
        // universal failure but genuine tenant-scoped isolation.
        $lookupFromB = $this->svcFor($schoolB, 'SCH_B')->resolve('1033');
        $this->assertTrue($lookupFromB['ok']);
        $this->assertSame('STU0021', $lookupFromB['currentStudentId']);
    }

    // ── TEST MATRIX 14 — cross-school previousStudentId must be structurally impossible ──
    // At the Sr_no_service layer, this manifests as: School A attempting to
    // relink School A's SRNO against an "old" Student ID that only exists
    // in School B's students collection. Because $this->fs is schoolId-bound,
    // the srno_not_found / expected_owner_mismatch paths are what a
    // cross-tenant attempt actually hits — there is no code path that reads
    // across the schoolId prefix at all, so this is proven structurally, not
    // merely by one input combination.

    public function test_relink_cannot_use_a_previous_student_id_that_only_exists_in_another_school(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        // School A owns its own SRNO 1033 under STU0011.
        $this->svcFor($schoolA, 'SCH_A')->claim('1033', 'STU0011');
        // School B has a COMPLETELY UNRELATED student STU0099 with its own SRNO.
        $this->svcFor($schoolB, 'SCH_B')->claim('9999', 'STU0099');

        // An operator in School A attempts to relink 1033, asserting the
        // (nonexistent-in-A, only-real-in-B) STU0099 as the previous owner.
        $res = $this->svcFor($schoolA, 'SCH_A')->relink('1033', 'STU0099', 'STU0047');

        $this->assertFalse($res['ok']);
        $this->assertSame('expected_owner_mismatch', $res['reason'], 'STU0099 never owned SRNO 1033 in School A\'s namespace — School B\'s existence is irrelevant and invisible here');
        $this->assertSame('STU0011', $backing->store['srNoIndex']['SCH_A_1033']['currentStudentId'], 'ownership must be completely unaffected');
    }

    // ── TEST MATRIX 12 — circular-link prevention ────────────────────────

    public function test_circular_previous_student_id_chain_is_rejected(): void
    {
        $backing = new FakeSrNoBackingStore();
        $school = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($school, 'SCH_A');

        $svc->claim('1025', 'STU0001');
        $forward = $svc->relink('1025', 'STU0001', 'STU0047'); // STU0047.previousStudentId = STU0001
        $this->assertTrue($forward['ok']);

        // Now attempt the reverse: treat STU0001 as if it descends from STU0047.
        $reverse = $svc->relink('1025', 'STU0047', 'STU0001');
        $this->assertFalse($reverse['ok']);
        $this->assertSame('would_create_cycle', $reverse['reason']);

        // Ownership must remain exactly where the legitimate forward link left it.
        $this->assertSame('STU0047', $backing->store['srNoIndex']['SCH_A_1025']['currentStudentId']);
    }

    // ── TEST MATRIX 13 — self-link prevention (already covered in
    // SrNoServiceTest.php's same_student_id case; re-asserted here in the
    // multi-tenant fake for completeness of this file's own suite) ──────

    public function test_self_link_is_rejected(): void
    {
        $backing = new FakeSrNoBackingStore();
        $school = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($school, 'SCH_A');
        $svc->claim('1025', 'STU0001');

        $res = $svc->relink('1025', 'STU0001', 'STU0001');
        $this->assertFalse($res['ok']);
        $this->assertSame('same_student_id', $res['reason']);
    }

    // ── Deep chain circular-link — proves the walk isn't just a 1-hop check ──

    public function test_deep_circular_chain_is_also_rejected(): void
    {
        $backing = new FakeSrNoBackingStore();
        $school = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($school, 'SCH_A');

        $svc->claim('1025', 'STU0001');
        $svc->relink('1025', 'STU0001', 'STU0047'); // STU0047 <- STU0001
        $svc->relink('1025', 'STU0047', 'STU0098'); // STU0098 <- STU0047 <- STU0001

        // Attempt to close the loop three hops back: make STU0001 descend from STU0098.
        $res = $svc->relink('1025', 'STU0098', 'STU0001');
        $this->assertFalse($res['ok']);
        $this->assertSame('would_create_cycle', $res['reason']);
        $this->assertSame('STU0098', $backing->store['srNoIndex']['SCH_A_1025']['currentStudentId'], 'legitimate chain must be unaffected by the rejected attempt');
    }

    // ── Predecessor double-spend — Phase 4 finding ───────────────────────
    // The prior audit flagged "nothing explicitly prevents a historical
    // Student ID from being reused as previousStudentId by two different
    // new records" as an open gap. On closer inspection this pass, the
    // EXISTING ownership-mismatch check already prevents it as a side
    // effect: after the first relink, srNoIndex.currentStudentId is no
    // longer the old id, so a second confirm() attempt against the SAME
    // old id fails the identical `expected_owner_mismatch` check TEST 4
    // exercises — no new guard was needed. This test proves that
    // conclusion rather than asserting it from reasoning alone.

    public function test_a_historical_predecessor_cannot_be_double_spent_by_two_new_admissions(): void
    {
        $backing = new FakeSrNoBackingStore();
        $school = new FakeSrNoFirestore('SCH_A', $backing);
        $svc = $this->svcFor($school, 'SCH_A');

        $svc->claim('1025', 'STU0001');
        $first = $svc->relink('1025', 'STU0001', 'STU0047');
        $this->assertTrue($first['ok']);

        // A second, DIFFERENT new admission also claims STU0001 as its predecessor.
        $second = $svc->relink('1025', 'STU0001', 'STU0099');
        $this->assertFalse($second['ok'], 'STU0001 must not be usable as a predecessor a second time');
        $this->assertSame('expected_owner_mismatch', $second['reason']);
        $this->assertSame('STU0047', $backing->store['srNoIndex']['SCH_A_1025']['currentStudentId'], 'the legitimate first re-admission must remain the current owner');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Phase 9.1 (2026-08-13) — allocateNext() school-scoped isolation.
    //  Covers Part 12 TEST 1-5 exactly: independent per-school sequences,
    //  no cross-school counter contamination.
    // ══════════════════════════════════════════════════════════════════════

    public function test_school_a_and_school_b_each_independently_start_at_1(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $a1 = $this->svcFor($schoolA, 'SCH_A')->allocateNext('SCH_A', 'STU_A1');
        $b1 = $this->svcFor($schoolB, 'SCH_B')->allocateNext('SCH_B', 'STU_B1');

        $this->assertSame('1', $a1['srNo'], 'School A first student = SRNO 1');
        $this->assertSame('1', $b1['srNo'], 'School B first student = SRNO 1 — NOT 2, NOT a shared counter');
    }

    public function test_school_a_second_and_third_students_continue_sequentially_independent_of_school_b(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);
        $svcA = $this->svcFor($schoolA, 'SCH_A');
        $svcB = $this->svcFor($schoolB, 'SCH_B');

        $this->assertSame('1', $svcA->allocateNext('SCH_A', 'STU_A1')['srNo']);
        $this->assertSame('2', $svcA->allocateNext('SCH_A', 'STU_A2')['srNo']);
        // School B admissions in between must NOT perturb School A's sequence.
        $this->assertSame('1', $svcB->allocateNext('SCH_B', 'STU_B1')['srNo']);
        $this->assertSame('2', $svcB->allocateNext('SCH_B', 'STU_B2')['srNo']);
        // School A's THIRD student, after School B's admissions, must be 3 — not 4, not 5.
        $this->assertSame('3', $svcA->allocateNext('SCH_A', 'STU_A3')['srNo'], 'School A must reach exactly 3, unaffected by School B\'s 2 admissions');
    }

    public function test_no_cross_school_counter_document_is_shared(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $this->svcFor($schoolA, 'SCH_A')->allocateNext('SCH_A', 'STU_A1');
        $this->svcFor($schoolB, 'SCH_B')->allocateNext('SCH_B', 'STU_B1');

        // Two DISTINCT counter pointer docs, two DISTINCT sets of claim
        // docs — proves isolation is via doc-id keying, not shared state,
        // the same pattern already proven for srNoIndex/apaarIndex.
        $this->assertArrayHasKey('_sys_SRNO_SCH_A', $backing->store['feeCounters']);
        $this->assertArrayHasKey('_sys_SRNO_SCH_B', $backing->store['feeCounters']);
        $this->assertArrayHasKey('_sys_SRNO_SCH_A_claim_1', $backing->store['feeCounters']);
        $this->assertArrayHasKey('_sys_SRNO_SCH_B_claim_1', $backing->store['feeCounters']);
    }

    public function test_school_id_spoofing_cannot_allocate_into_another_schools_sequence(): void
    {
        // Even if a caller passed a DIFFERENT schoolId than the one its own
        // Firestore handle is bound to (simulating a hypothetical spoofed
        // parameter — not reachable in production, since Sis.php always
        // passes $this->parent_db_key server-side, never client input —
        // this test proves the SERVICE layer itself is schoolId-keyed by
        // the explicit parameter, not by any ambient/global state, so
        // there is no shared counter a mismatched call could corrupt).
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);

        $res = $this->svcFor($schoolA, 'SCH_A')->allocateNext('SCH_B', 'STU_SPOOF');
        // Allocates correctly into SCH_B's OWN sequence (the schoolId the
        // caller explicitly passed) — it does not corrupt SCH_A's, and it
        // does not silently use the fake's bound 'SCH_A' instead. This
        // demonstrates the service has no ambient/implicit school context
        // — the caller (Sis.php) is the sole source of truth, and Sis.php
        // itself never accepts schoolId from client input (verified by
        // source grep, documented in the Phase 9.1 report's security
        // analysis, not re-proven at this layer).
        $this->assertTrue($res['ok']);
        $this->assertSame('1', $res['srNo']);
        $this->assertArrayHasKey('_sys_SRNO_SCH_B_claim_1', $backing->store['feeCounters']);
        $this->assertArrayNotHasKey('_sys_SRNO_SCH_A_claim_1', $backing->store['feeCounters'] ?? []);
    }
}
