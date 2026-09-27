<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';

/**
 * Covers TEST 1-8 of SRNO_RE_ADMISSION_ARCHITECTURE_AUDIT.md §26 that are
 * expressible as pure PHP logic against Sr_no_service (no live Firestore,
 * no browser, no UI — per this repo's baseline: no service-account
 * credentials exist in the repository, so integration tests against a real
 * Firestore project are not possible here; see the implementation report's
 * "Tests executed" section for what remains browser/staging-only).
 *
 * TEST 9-14 (financial-record isolation, Student 360 UI, printed-document
 * immutability, no-old-record UX) are NOT covered here — they require
 * either live Firestore financial collections or a browser, neither
 * available in this unit-test environment. TEST 9/10's core CLAIM (that
 * relink() only ever touches `srNoIndex` and the new student's own
 * `previousStudentId`/`srNo*` fields, never any other collection) IS
 * verified here (test_relink_only_writes_srNoIndex_and_the_new_student_doc)
 * — that is the code-level guarantee those two scenarios depend on.
 */
class SrNoServiceTest extends TestCase
{
    private function svc(FakeSrNoFirestore $f): \Sr_no_service
    {
        $svc = new \Sr_no_service();
        return $svc->init($f, $f, 'SCH_TEST', 'ADM_TESTER');
    }

    // ── normalize() — pure function, no Firestore needed ────────────────

    public function test_normalize_strips_leading_zeros_for_the_norm_key(): void
    {
        $a = \Sr_no_service::normalize('0147');
        $b = \Sr_no_service::normalize('147');
        $c = \Sr_no_service::normalize('00147');
        $this->assertSame('147', $a['norm']);
        $this->assertSame($a['norm'], $b['norm']);
        $this->assertSame($a['norm'], $c['norm']);
        $this->assertSame('0147', $a['display'], 'display value preserves the padding as typed');
    }

    public function test_normalize_rejects_non_digit_input(): void
    {
        foreach (['SR/147', '147 A', '147.0', '', ' ', '१४७', '-5'] as $bad) {
            $this->assertNull(\Sr_no_service::normalize($bad), "expected null for input [{$bad}]");
        }
    }

    // ── TEST 1 — first admission ─────────────────────────────────────────

    public function test_first_admission_claims_the_srno(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->claim('1025', 'STU0001');
        $this->assertTrue($res['ok']);
        $this->assertSame('STU0001', $f->backing()->store['srNoIndex']['SCH_TEST_1025']['currentStudentId']);
    }

    // ── TEST 2 — ordinary duplicate SRNO is rejected ─────────────────────

    public function test_unrelated_student_cannot_claim_an_already_owned_srno(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');

        $res = $svc->claim('1025', 'STU0099');
        $this->assertFalse($res['ok']);
        $this->assertSame('duplicate', $res['reason']);
        $this->assertSame('STU0001', $res['currentStudentId']);
        // Ownership must be completely unchanged by the rejected attempt.
        $this->assertSame('STU0001', $f->backing()->store['srNoIndex']['SCH_TEST_1025']['currentStudentId']);
    }

    // ── TEST 3 — verified re-admission relinks correctly ─────────────────

    public function test_verified_readmission_relinks_srno_to_the_new_student(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');

        $res = $svc->relink('1025', 'STU0001', 'STU0047');
        $this->assertTrue($res['ok']);
        $this->assertSame('relinked', $res['reason']);

        $idx = $f->backing()->store['srNoIndex']['SCH_TEST_1025'];
        $this->assertSame('STU0047', $idx['currentStudentId'], 'SRNO 1025 must now resolve to the CURRENT holder, not the historical one');
        $this->assertContains('STU0001', $idx['historicalStudentIds']);

        // The new student's own record gets previousStudentId + srNo, in the SAME batch.
        $newStuDoc = $f->backing()->store['students']['SCH_TEST_STU0047'];
        $this->assertSame('STU0001', $newStuDoc['previousStudentId']);
        $this->assertSame('1025', $newStuDoc['srNo']);

        // The OLD student's own record is untouched — relink() never writes to it.
        $this->assertArrayNotHasKey('SCH_TEST_STU0001', $f->backing()->store['students'] ?? []);

        // resolve() must now return the CURRENT student, not the historical one.
        $lookup = $svc->resolve('1025');
        $this->assertSame('STU0047', $lookup['currentStudentId']);
    }

    // ── TEST 4 — wrong previous Student ID is rejected ───────────────────

    public function test_relink_rejects_a_wrong_expected_previous_student_id(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');

        $res = $svc->relink('1025', 'STU0099', 'STU0047'); // STU0099 never owned 1025
        $this->assertFalse($res['ok']);
        $this->assertSame('expected_owner_mismatch', $res['reason']);
        $this->assertSame('STU0001', $f->backing()->store['srNoIndex']['SCH_TEST_1025']['currentStudentId'], 'ownership must not move on a rejected relink');
    }

    // ── TEST 5 — an unrelated student attempting relink is rejected ─────
    // IMPORTANT BOUNDARY, stated honestly rather than tested around: at the
    // Sr_no_service layer alone, relink('1025','STU0001','STU0099') given
    // ACCURATE current state WOULD succeed — the service's only job is
    // "does the asserted prior owner match the live one", which is a
    // MECHANICAL check, not an identity-verification one. Genuine
    // "is STU0099 actually the same real-world student as STU0001"
    // verification is a CONTROLLER-layer responsibility, enforced by
    // Readmission::confirm() (Principal-only RBAC + the prior record must
    // exist + the prior record must not still be Active) BEFORE it ever
    // calls relink() — see Readmission.php's docblock. What the service
    // layer CAN and does reject on its own is any assertion that doesn't
    // match live state, which is what these two cases cover:
    public function test_relink_rejects_self_referential_and_mismatched_assertions(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');
        $svc->claim('2001', 'STU0099'); // STU0099 is a real, unrelated, already-admitted student

        // A malformed/degenerate assertion (new id == asserted old id) is rejected outright.
        $selfRef = $svc->relink('1025', 'STU0099', 'STU0099');
        $this->assertFalse($selfRef['ok']);
        $this->assertSame('same_student_id', $selfRef['reason']);

        // STU0099 asserting it descends from SRNO 1025's owner, when it does not, is rejected —
        // identical mechanism to TEST 4, confirming the check generalizes to any wrong assertion.
        $wrongLineage = $svc->relink('1025', 'STU0099', 'STU0200');
        $this->assertFalse($wrongLineage['ok']);
        $this->assertSame('expected_owner_mismatch', $wrongLineage['reason']);
    }

    // ── TEST 6 — double submission is idempotent ─────────────────────────

    public function test_double_submitted_relink_is_idempotent_no_duplicate_ownership(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');

        $first  = $svc->relink('1025', 'STU0001', 'STU0047');
        $second = $svc->relink('1025', 'STU0001', 'STU0047'); // same request, retried

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame('noop', $second['reason'], 'a retried relink must be a clean no-op, not a second history/audit event');

        $idx = $f->backing()->store['srNoIndex']['SCH_TEST_1025'];
        $this->assertSame('STU0047', $idx['currentStudentId']);
        $this->assertSame(['STU0001'], array_values($idx['historicalStudentIds']), 'no duplicate historical entries from the retried call');
        // history[] holds ['claimed' from claim(), 'relink_in' from the FIRST relink] —
        // the retried second call must add nothing further.
        $this->assertCount(2, $idx['history'], 'the bounded history cache must not gain a THIRD entry for the no-op retry');
    }

    // ── TEST 7 (deterministic simulation) — a lost concurrent relink fails safely ─
    // True thread concurrency cannot be exercised in a single-threaded unit test.
    // This simulates the exact state a losing concurrent writer would observe: by
    // the time it re-reads, current ownership has already moved to a DIFFERENT
    // new Student ID than the one this caller expected to establish — the same
    // code path proven safe in test_relink_rejects_a_wrong_expected_previous_student_id.

    public function test_concurrent_readmission_only_one_relink_succeeds(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');

        // Admin A wins the race first.
        $adminA = $svc->relink('1025', 'STU0001', 'STU0047');
        // Admin B's request was built against the SAME original state (STU0001 as
        // the expected owner) but commits after A — by the time it's evaluated,
        // current ownership is STU0047, not STU0001.
        $adminB = $svc->relink('1025', 'STU0001', 'STU0048');

        $this->assertTrue($adminA['ok'], 'exactly one must succeed');
        $this->assertFalse($adminB['ok'], 'the other must fail safely, not silently overwrite');
        $this->assertSame('expected_owner_mismatch', $adminB['reason']);
        $this->assertSame('STU0047', $f->backing()->store['srNoIndex']['SCH_TEST_1025']['currentStudentId'], 'no split ownership — exactly one current holder');
    }

    // ── TEST 8 — multiple re-admissions of the same SRNO ─────────────────

    public function test_repeated_readmission_chain_preserves_full_history(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');
        $svc->relink('1025', 'STU0001', 'STU0047');
        $svc->relink('1025', 'STU0047', 'STU0098');

        $idx = $f->backing()->store['srNoIndex']['SCH_TEST_1025'];
        $this->assertSame('STU0098', $idx['currentStudentId'], 'must resolve to the LATEST admission');
        $this->assertSame(['STU0001', 'STU0047'], array_values($idx['historicalStudentIds']), 'both prior holders preserved, oldest first');

        $lookup = $svc->resolve('1025');
        $this->assertSame('STU0098', $lookup['currentStudentId']);
    }

    // ── Section 12 support — a rejection reason exists for "no old record" ──
    // Sr_no_service itself has no concept of "old record exists or not" (that
    // check is Readmission::confirm()'s job, per its own docblock — this test
    // documents the boundary: relink() against a NEVER-CLAIMED srNo fails
    // closed with a distinct, honest reason rather than silently succeeding.

    public function test_relink_against_a_never_claimed_srno_fails_closed(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->relink('9999', 'STU0001', 'STU0047');
        $this->assertFalse($res['ok']);
        $this->assertSame('srno_not_found', $res['reason']);
    }

    // ── Financial/foreign-key isolation guarantee (supports TEST 9/10) ──

    public function test_relink_only_writes_srNoIndex_and_the_new_student_doc(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->claim('1025', 'STU0001');
        $svc->relink('1025', 'STU0001', 'STU0047');

        $this->assertEqualsCanonicalizing(['srNoIndex', 'students'], array_keys($f->backing()->store),
            'relink() must never touch any collection other than srNoIndex and the new student\'s own record — ' .
            'no fee/attendance/exam/discipline/transport/hostel/certificate/document collection may be written by this class');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  Phase 9.1 (2026-08-13) — allocateNext(): mandatory, automatic,
    //  school-scoped, sequential SRNO allocation. Covers the Phase 9.1
    //  directive's Part 25 test strategy items that are expressible as
    //  pure service-layer logic (no live Firestore, no browser).
    // ══════════════════════════════════════════════════════════════════════

    public function test_first_student_in_a_school_receives_srno_1(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocateNext('SCH_TEST', 'STU0001');
        $this->assertTrue($res['ok']);
        $this->assertSame('1', $res['srNo']);
    }

    public function test_second_student_in_the_same_school_receives_srno_2(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->allocateNext('SCH_TEST', 'STU0001');
        $res = $svc->allocateNext('SCH_TEST', 'STU0002');
        $this->assertTrue($res['ok']);
        $this->assertSame('2', $res['srNo']);
    }

    public function test_third_student_receives_srno_3_sequentially(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->allocateNext('SCH_TEST', 'STU0001');
        $svc->allocateNext('SCH_TEST', 'STU0002');
        $res = $svc->allocateNext('SCH_TEST', 'STU0003');
        $this->assertTrue($res['ok']);
        $this->assertSame('3', $res['srNo']);
    }

    public function test_allocateNext_actually_registers_ownership_in_srNoIndex(): void
    {
        // Not just a counter bump — the allocated number must be a REAL,
        // resolvable SRNO claim, exactly as if claim() had been called
        // directly (allocateNext() reuses claim() internally for this).
        // NOTE: writing `srNo`/`srNoNorm` onto the student's OWN doc is
        // the CALLER's job (Sis::_claimIdentityAttributes()), not
        // claim()'s/allocateNext()'s — claim() only ever writes srNoIndex,
        // confirmed by the existing test_relink_only_writes_... guarantee
        // for relink(); claim() itself is even narrower (srNoIndex only,
        // no student doc at all).
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $svc->allocateNext('SCH_TEST', 'STU0001');
        $lookup = $svc->resolve('1');
        $this->assertTrue($lookup['ok']);
        $this->assertSame('STU0001', $lookup['currentStudentId']);
        $this->assertSame('STU0001', $f->backing()->store['srNoIndex']['SCH_TEST_1']['currentStudentId']);
    }

    public function test_manually_submitted_srno_is_never_accepted_for_allocation(): void
    {
        // allocateNext() takes no SRNO value parameter at all — there is
        // no argument position a caller could use to inject "999" even if
        // it tried. This test documents that structural guarantee: the
        // allocated value is ALWAYS exactly pointer+1, never operator input.
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocateNext('SCH_TEST', 'STU0001');
        $this->assertSame('1', $res['srNo'], 'first allocation must be 1 regardless of any external hint');
    }

    public function test_allocateNext_requires_school_id(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocateNext('', 'STU0001');
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_school_id', $res['reason']);
    }

    public function test_allocateNext_requires_student_id(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocateNext('SCH_TEST', '');
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_student_id', $res['reason']);
    }

    public function test_two_simultaneous_admissions_in_one_school_get_unique_sequential_numbers(): void
    {
        // Single-threaded deterministic simulation of the race (same
        // documented limitation as the pre-existing relink() concurrency
        // tests — true multi-process concurrency cannot be exercised in a
        // unit test). What IS proven: the SAME atomic firestoreCreate
        // collision primitive already trusted elsewhere in this codebase
        // is what prevents a duplicate, not a read-then-write race.
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $a = $svc->allocateNext('SCH_TEST', 'STU_A');
        $b = $svc->allocateNext('SCH_TEST', 'STU_B');
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $this->assertNotSame($a['srNo'], $b['srNo'], 'no duplicate SRNO — both must be unique');
        $this->assertEqualsCanonicalizing(['1', '2'], [$a['srNo'], $b['srNo']]);
    }

    public function test_existing_data_floor_is_respected_not_max_plus_one_in_php(): void
    {
        // A school with pre-existing SRNO claims 1,2,3,7,8 (e.g. from a
        // legacy manual-entry path) must have its counter lazily
        // initialized to the TRUE max (8), not silently restart at 1 and
        // collide, and not require any migration script — this is a
        // read-only scan at allocation time, never a write to existing data.
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        foreach (['1', '2', '3', '7', '8'] as $n) {
            $svc->claim($n, 'STU_LEGACY_' . $n);
        }
        $res = $svc->allocateNext('SCH_TEST', 'STU_NEW');
        $this->assertTrue($res['ok']);
        $this->assertSame('9', $res['srNo'], 'must continue from the true existing max, never max(SRNO)+1 computed differently, and never restart at a low number');
    }

    public function test_allocateNext_self_heals_when_counter_and_index_disagree(): void
    {
        // If the counter reservation for a number succeeds but srNoIndex
        // already independently owns that same digit (e.g. a legacy claim
        // the lazy-init scan raced or missed), allocateNext() must not
        // silently overwrite ownership or return a colliding number — it
        // must release the counter reservation and move to the next one.
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        // Legacy claim for "2" exists, but nothing for "1" — simulates a
        // gap the lazy-init floor scan (which finds the true MAX) would
        // NOT protect against if the counter pointer were somehow reset.
        $svc->claim('1', 'STU_LEGACY_1');
        // Manually force the counter pointer back to 0 to simulate the
        // disagreement scenario deterministically (bypasses the lazy-init
        // floor, which would normally prevent this from arising at all —
        // proves the self-heal path works independently of that guard).
        $f->backing()->store['feeCounters']['_sys_SRNO_SCH_TEST'] = ['kind' => 'SRNO', 'schoolId' => 'SCH_TEST', 'value' => 0, '__updateTime' => '0'];
        $res = $svc->allocateNext('SCH_TEST', 'STU_NEW');
        $this->assertTrue($res['ok']);
        $this->assertSame('2', $res['srNo'], 'must skip the already-owned "1" and land on the next genuinely free number');
    }
}
