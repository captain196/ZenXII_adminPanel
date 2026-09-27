<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;
use Tests\Support\FakeSrNoBackingStore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';

/**
 * Phase 11.3 (2026-08-14) — SRNO as a second OPERATIONAL student identifier.
 *
 * WHAT THIS SUITE DOES AND DOES NOT COVER — read before trusting it.
 *
 * The dual-lookup feature has two halves:
 *
 *   1. The RESOLUTION half — SRNO -> current Student ID, school-scoped, and
 *      re-admission-correct. That is `Sr_no_service::resolve()`, exercised here
 *      through the REAL class against the same in-memory Firestore doubles the
 *      SRNO/APAAR/Admission-Number suites already use. These tests run real
 *      control flow, not a source-text grep.
 *
 *   2. The BRANCHING half — MY_Controller::_resolveStudentRef() choosing the
 *      SRNO path vs the Student ID path. That lives on a CodeIgniter controller
 *      which cannot be bootstrapped in this suite, so it is NOT directly
 *      executed here and is marked NOT UNIT TESTED in the report.
 *
 *      What IS tested here is the *invariant the branch depends on*: that the
 *      Student ID and SRNO token shapes are disjoint, so the branch cannot
 *      mis-route an input. If that invariant ever breaks — e.g. someone drops
 *      the STU prefix or widens the SRNO format — these tests fail loudly, which
 *      is the failure that would actually matter.
 */
class SrNoDualLookupTest extends TestCase
{
    /** The exact production format, Sr_no_service::FORMAT_RE. */
    private const SRNO_RE = '/^[0-9]{1,9}$/';

    private function svc(FakeSrNoFirestore $f, string $schoolId = 'SCH_A'): \Sr_no_service
    {
        $svc = new \Sr_no_service();
        return $svc->init($f, $f, $schoolId, 'ADM_TESTER');
    }

    // ── The invariant the dual-lookup branch rests on ────────────────────

    public function test_student_id_and_srno_token_shapes_are_disjoint(): void
    {
        // Id_generator formats Student IDs as 'STU' + zero-padded digits
        // (PAD['STU'] => 4). No such value can satisfy the SRNO format, so
        // MY_Controller::_resolveStudentRef() can never mistake one for the
        // other. This is the property that makes a single input box safe and
        // removes any need for an operator-facing mode selector.
        foreach (['STU0001', 'STU0012', 'STU9999', 'STU12345'] as $studentId) {
            $this->assertDoesNotMatchRegularExpression(
                self::SRNO_RE, $studentId,
                "Student ID [{$studentId}] must NOT parse as an SRNO"
            );
        }
        foreach (['1', '12', '0012', '999999999'] as $srNo) {
            $this->assertMatchesRegularExpression(self::SRNO_RE, $srNo);
            $this->assertStringStartsNotWith('STU', $srNo);
        }
    }

    public function test_srno_format_rejects_anything_a_student_id_could_be(): void
    {
        foreach (['STU0012', 'stu0012', '12A', 'A12', '12-3', '1234567890', ''] as $bad) {
            $this->assertDoesNotMatchRegularExpression(self::SRNO_RE, $bad, "must reject [{$bad}]");
        }
    }

    // ── THE NON-NEGOTIABLE RULE: SRNO IS NOT DERIVED FROM STUDENT ID ────

    public function test_student_id_digits_do_not_imply_the_srno(): void
    {
        // Phase 11.5 §12. `STU0012` does NOT mean SRNO 12. The two values are
        // independent, and SRNO ownership is resolved ONLY from the school's
        // index — never computed from the Student ID's digits.
        //
        // Modelled with a deliberately CROSSED pairing, which is exactly the
        // case a numeric-derivation bug would get wrong:
        //     SRNO 47 -> STU0012
        //     SRNO 12 -> STU0047
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $this->assertTrue($svc->claim('47', 'STU0012')['ok']);
        $this->assertTrue($svc->claim('12', 'STU0047')['ok']);

        // If anything derived SRNO from the Student ID's digits, these two
        // assertions would come out swapped.
        $this->assertSame('STU0012', $svc->resolve('47')['currentStudentId'],
            'SRNO 47 must resolve to STU0012 — SRNO is NOT derived from the Student ID');
        $this->assertSame('STU0047', $svc->resolve('12')['currentStudentId'],
            'SRNO 12 must resolve to STU0047, NOT to STU0012');
    }

    public function test_a_student_id_with_no_srno_at_all_does_not_resolve(): void
    {
        // The converse guard: a student can exist with NO SRNO. Nothing may
        // invent one from the numeric part of the Student ID.
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('47', 'STU0012');           // only SRNO 47 exists in this school

        // "12" is the numeric tail of STU0012 — it must NOT resolve to it.
        $res = $svc->resolve('12');
        $this->assertFalse($res['ok'],
            'the digits inside a Student ID must never be treated as its SRNO');
    }

    // ── Requirement 2/3: SRNO resolves to the canonical Student ID ───────

    public function test_srno_resolves_to_the_canonical_student_id(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $this->assertTrue($svc->claim('12', 'STU0012')['ok']);

        $res = $svc->resolve('12');
        $this->assertTrue($res['ok']);
        $this->assertSame('STU0012', $res['currentStudentId']);
    }

    public function test_resolution_costs_exactly_one_document_read(): void
    {
        // Latency contract (§21/§32): the SRNO path is ONE index read, and the
        // caller then loads the student ONCE. resolve() must never touch the
        // students collection itself — that would be the forbidden
        // resolve -> load -> load-again pattern.
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('12', 'STU0012');

        $before = array_keys($f->backing()->store);
        $svc->resolve('12');
        $this->assertSame($before, array_keys($f->backing()->store),
            'resolve() must not read or create any collection beyond srNoIndex');
        $this->assertSame(['srNoIndex'], array_keys($f->backing()->store));
    }

    public function test_leading_zeros_resolve_to_the_same_student(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('12', 'STU0012');

        foreach (['12', '012', '0012'] as $variant) {
            $res = $svc->resolve($variant);
            $this->assertTrue($res['ok'], "[{$variant}] must resolve");
            $this->assertSame('STU0012', $res['currentStudentId']);
        }
    }

    public function test_unknown_srno_fails_closed_without_falling_back(): void
    {
        // A miss must be a clean miss — never a guess, never a nearest match.
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('12', 'STU0012');

        $res = $svc->resolve('99999');
        $this->assertFalse($res['ok']);
        $this->assertSame('not_found', $res['reason']);
        $this->assertArrayNotHasKey('currentStudentId', $res);
    }

    public function test_malformed_srno_is_rejected_without_touching_firestore(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $res = $this->svc($f)->resolve('A12');
        $this->assertFalse($res['ok']);
        $this->assertSame('invalid_format', $res['reason']);
        $this->assertSame([], $f->backing()->store, 'no read should have occurred');
    }

    // ── Requirement 4/5/6: school scoping and cross-tenant rejection ─────

    public function test_same_srno_in_two_schools_resolves_to_different_students(): void
    {
        // ONE shared backing store models the single real Firestore project;
        // isolation comes only from the schoolId-prefixed doc id.
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $this->assertTrue($this->svc($schoolA, 'SCH_A')->claim('12', 'STU0012')['ok']);
        $this->assertTrue($this->svc($schoolB, 'SCH_B')->claim('12', 'STU0456')['ok']);

        $this->assertSame('STU0012', $this->svc($schoolA, 'SCH_A')->resolve('12')['currentStudentId']);
        $this->assertSame('STU0456', $this->svc($schoolB, 'SCH_B')->resolve('12')['currentStudentId']);
        $this->assertCount(2, $backing->store['srNoIndex'], 'two distinct docs, one per tenant');
    }

    public function test_school_a_can_never_resolve_school_bs_srno(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        // Only School B has SRNO 77.
        $this->assertTrue($this->svc($schoolB, 'SCH_B')->claim('77', 'STU0456')['ok']);

        $fromA = $this->svc($schoolA, 'SCH_A')->resolve('77');
        $this->assertFalse($fromA['ok'], "School A must not resolve School B's SRNO");
        $this->assertSame('not_found', $fromA['reason']);

        // Proves genuine scoping rather than universal failure.
        $this->assertSame('STU0456', $this->svc($schoolB, 'SCH_B')->resolve('77')['currentStudentId']);
    }

    public function test_tenant_scope_comes_from_the_firestore_context_not_the_caller(): void
    {
        // The schoolId passed to init() is NOT what scopes resolution — the
        // doc id is built from the Firestore context, which in production is
        // set once from the authenticated session/claims and is never
        // client-supplied. Here School A's context is used while init() is
        // handed a DIFFERENT school string: resolution must still be School A's.
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $this->svc($schoolA, 'SCH_A')->claim('55', 'STU_A');
        $this->svc($schoolB, 'SCH_B')->claim('55', 'STU_B');

        $svcLying = (new \Sr_no_service())->init($schoolA, $schoolA, 'SCH_B', 'ADM');
        $this->assertSame('STU_A', $svcLying->resolve('55')['currentStudentId'],
            'a caller-supplied schoolId must NOT be able to redirect resolution to another tenant');
    }

    // ── Requirement 8/9: re-admission returns the CURRENT owner ──────────

    public function test_after_readmission_srno_resolves_to_the_new_student_id(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $this->assertTrue($svc->claim('12', 'STU0001')['ok']);
        $this->assertSame('STU0001', $svc->resolve('12')['currentStudentId']);

        // Verified re-admission relink — the ONLY path that moves ownership.
        $relink = $svc->relink('12', 'STU0001', 'STU0047', 're-admission');
        $this->assertTrue($relink['ok'], 'relink should succeed: ' . json_encode($relink));

        $res = $svc->resolve('12');
        $this->assertTrue($res['ok']);
        $this->assertSame('STU0047', $res['currentStudentId'], 'must return the CURRENT owner');
        $this->assertNotSame('STU0001', $res['currentStudentId'], 'must NEVER return the historical owner');
    }

    public function test_historical_owner_is_retained_but_never_returned_by_resolve(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('12', 'STU0001');
        $svc->relink('12', 'STU0001', 'STU0047', 're-admission');

        // The trail is preserved for Student Trail / integrity verification…
        $full = $svc->getFullRecord('12');
        $this->assertContains('STU0001', $full['historicalStudentIds'] ?? []);

        // …but ordinary operational lookup never surfaces it.
        $this->assertSame('STU0047', $svc->resolve('12')['currentStudentId']);
    }

    // ── Requirement 10/11: promotion preserves the mapping ───────────────

    public function test_promotion_does_not_change_srno_resolution(): void
    {
        // Promotion mutates class/section/roll on the STUDENT document and does
        // not touch srNoIndex at all, so the mapping is invariant by
        // construction. Modelled here by asserting the index is untouched
        // across an unrelated student-document change.
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('12', 'STU0012');
        $before = $f->backing()->store['srNoIndex'];

        // Simulate promotion writing ONLY to the students collection.
        $f->firestoreSet('students', $f->docId('STU0012'), [
            'studentId' => 'STU0012', 'className' => 'Class 6th', 'section' => 'Section A', 'rollNo' => '7',
        ]);

        $this->assertSame($before, $f->backing()->store['srNoIndex'], 'promotion must not touch srNoIndex');
        $this->assertSame('STU0012', $svc->resolve('12')['currentStudentId']);
    }

    // ── Requirement 24/35: SRNO never becomes a storage key ──────────────

    public function test_resolve_never_writes_anything(): void
    {
        // SRNO is a lookup mechanism, not a record. A resolution must leave no
        // trace: no new document, no mutation, no counter movement.
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim('12', 'STU0012');
        $snapshot = json_encode($f->backing()->store);

        $svc->resolve('12');
        $svc->resolve('99999');
        $svc->resolve('nonsense');

        $this->assertSame($snapshot, json_encode($f->backing()->store),
            'resolve() must be side-effect free');
    }

    public function test_index_document_stores_the_student_id_not_the_reverse(): void
    {
        // Guards the §35 rule from the other direction: the index maps
        // SRNO -> Student ID. Nothing anywhere should store srNo AS the
        // student key.
        $f = new FakeSrNoFirestore('SCH_A');
        $this->svc($f)->claim('12', 'STU0012');
        $doc = $f->backing()->store['srNoIndex']['SCH_A_12'];

        $this->assertSame('STU0012', $doc['currentStudentId']);
        $this->assertSame('12', $doc['srNo']);
        $this->assertArrayNotHasKey('studentId', $doc,
            'the index must expose currentStudentId, never a bare studentId alias');
    }
}
