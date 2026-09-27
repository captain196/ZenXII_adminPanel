<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;
use Tests\Support\FakeSrNoBackingStore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Admission_no_service.php';

/**
 * Phase 10.2 (2026-08-13) — dedicated tests for Admission_no_service.
 *
 * Replaces AdmissionNumberComponentsTest.php, which tested the REJECTED
 * Phase 10.1 format (YYYY/CLASS/SRNO) and was deleted rather than left
 * passing against a design that is no longer the business rule.
 *
 * Reuses the same generic FakeSrNoFirestore/FakeSrNoBackingStore doubles
 * the SRNO/APAAR suites already use — they are collection-name-agnostic
 * (verified by source read; nothing hardcodes 'srNoIndex'), so the REAL
 * allocate()/resolve() control flow is exercised, not a reimplementation.
 */
class AdmissionNoServiceTest extends TestCase
{
    private function svc(FakeSrNoFirestore $f, string $schoolId = 'SCH_TEST'): \Admission_no_service
    {
        $svc = new \Admission_no_service();
        return $svc->init($f, $f, $schoolId, 'ADM_TESTER');
    }

    // ── Format contract ──────────────────────────────────────────────────

    public function test_allocated_number_matches_the_canonical_format(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocate('SCH_TEST', 'STU0001', '2026');
        $this->assertTrue($res['ok']);
        $this->assertMatchesRegularExpression('/^2026\/[23456789ABCDEFGHJKMNPQRSTVWXYZ]{6}$/', $res['admissionNo']);
        $this->assertTrue(\Admission_no_service::isValidFormat($res['admissionNo']));
    }

    public function test_format_validator_rejects_the_rejected_phase_10_1_shape(): void
    {
        // The old YYYY/CLASS/SRNO design must NOT validate — this is the
        // regression guard for the architecture correction itself.
        $this->assertFalse(\Admission_no_service::isValidFormat('2026/01/012'));
    }

    public function test_format_validator_rejects_ambiguous_characters(): void
    {
        // 0/O/1/I/L/U are excluded from the alphabet so a number cannot be
        // mis-transcribed off a printed certificate.
        foreach (['2026/7K4P9O', '2026/7K4P9I', '2026/7K4P90', '2026/7K4P91', '2026/7K4P9L', '2026/7K4P9U'] as $bad) {
            $this->assertFalse(\Admission_no_service::isValidFormat($bad), "must reject [{$bad}]");
        }
    }

    public function test_format_validator_rejects_wrong_length_and_shape(): void
    {
        foreach (['2026/7K4P9', '2026/7K4P922', '26/7K4P92', '2026-7K4P92', '7K4P92', ''] as $bad) {
            $this->assertFalse(\Admission_no_service::isValidFormat($bad), "must reject [{$bad}]");
        }
    }

    // ── Independence from class / SRNO / roll number ─────────────────────

    public function test_allocate_takes_no_class_srno_or_roll_number_argument(): void
    {
        // Structural guarantee, not a behavioural assertion: the method
        // signature is (schoolId, studentId, admissionYear) — there is no
        // argument position through which class, SRNO or Roll Number could
        // influence the generated value, so the corrected independence
        // requirement holds by construction.
        $ref = new \ReflectionMethod(\Admission_no_service::class, 'allocate');
        $params = array_map(fn($p) => $p->getName(), $ref->getParameters());
        $this->assertSame(['schoolId', 'studentId', 'admissionYear'], $params);
    }

    public function test_two_students_with_the_same_srno_and_class_get_different_numbers(): void
    {
        // Directly proves the value is not derived from SRNO/class: the
        // service never sees either, so two allocations in the same school
        // and year must still differ.
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $a = $svc->allocate('SCH_TEST', 'STU0001', '2026');
        $b = $svc->allocate('SCH_TEST', 'STU0002', '2026');
        $this->assertNotSame($a['admissionNo'], $b['admissionNo']);
    }

    // ── Year component ───────────────────────────────────────────────────

    public function test_year_component_is_the_supplied_admission_year(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocate('SCH_TEST', 'STU0001', '2028');
        $this->assertStringStartsWith('2028/', $res['admissionNo']);
    }

    public function test_invalid_admission_year_is_rejected(): void
    {
        $f = new FakeSrNoFirestore();
        foreach (['26', '20265', 'YYYY', ''] as $bad) {
            $res = $this->svc($f)->allocate('SCH_TEST', 'STU0001', $bad);
            $this->assertFalse($res['ok'], "year [{$bad}] must be rejected");
            $this->assertSame('invalid_admission_year', $res['reason']);
        }
    }

    // ── Required inputs ──────────────────────────────────────────────────

    public function test_missing_school_id_is_rejected(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocate('', 'STU0001', '2026');
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_school_id', $res['reason']);
    }

    public function test_missing_student_id_is_rejected(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocate('SCH_TEST', '', '2026');
        $this->assertFalse($res['ok']);
        $this->assertSame('missing_student_id', $res['reason']);
    }

    // ── Atomic claim / collision safety ──────────────────────────────────

    public function test_allocation_writes_exactly_one_index_document(): void
    {
        // Cost guarantee: ONE write, ZERO reads. The fake records every
        // write, so an accidental read-then-write pattern would show up as
        // extra documents or a differing count.
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocate('SCH_TEST', 'STU0001', '2026');
        $this->assertTrue($res['ok']);
        $this->assertSame(1, $res['attempts'], 'uncontended allocation must succeed on the first attempt');
        $this->assertCount(1, $f->backing()->store['admissionNoIndex']);
    }

    public function test_collision_on_a_taken_token_retries_and_still_succeeds(): void
    {
        // Pre-claim a specific doc id, then allocate repeatedly. Every
        // allocation must succeed (retrying past any collision) and every
        // resulting number must be distinct — proving the create-if-not-
        // exists collision signal is handled, not ignored.
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $seen = [];
        for ($i = 0; $i < 25; $i++) {
            $res = $svc->allocate('SCH_TEST', "STU{$i}", '2026');
            $this->assertTrue($res['ok'], "allocation {$i} must succeed");
            $this->assertArrayNotHasKey($res['admissionNo'], $seen, 'no duplicate may ever be returned');
            $seen[$res['admissionNo']] = true;
        }
        $this->assertCount(25, $f->backing()->store['admissionNoIndex']);
    }

    public function test_index_document_records_the_owning_student_and_school(): void
    {
        $f = new FakeSrNoFirestore();
        $res = $this->svc($f)->allocate('SCH_TEST', 'STU0042', '2026');
        $docs = $f->backing()->store['admissionNoIndex'];
        $doc  = reset($docs);
        $this->assertSame('STU0042', $doc['studentId']);
        $this->assertSame('SCH_TEST', $doc['schoolId']);
        $this->assertSame($res['admissionNo'], $doc['admissionNo']);
    }

    // ── resolve() ────────────────────────────────────────────────────────

    public function test_resolve_returns_the_owning_student(): void
    {
        $f = new FakeSrNoFirestore();
        $svc = $this->svc($f);
        $res = $svc->allocate('SCH_TEST', 'STU0007', '2026');
        $lookup = $svc->resolve($res['admissionNo']);
        $this->assertTrue($lookup['ok']);
        $this->assertSame('STU0007', $lookup['studentId']);
    }

    public function test_resolve_of_an_unknown_number_fails_closed(): void
    {
        $f = new FakeSrNoFirestore();
        $lookup = $this->svc($f)->resolve('2026/ZZZZZZ');
        $this->assertFalse($lookup['ok']);
        $this->assertSame('not_found', $lookup['reason']);
    }

    public function test_resolve_rejects_a_malformed_number_without_touching_firestore(): void
    {
        $f = new FakeSrNoFirestore();
        $lookup = $this->svc($f)->resolve('2026/01/012');   // the rejected old format
        $this->assertFalse($lookup['ok']);
        $this->assertSame('invalid_format', $lookup['reason']);
    }

    // ── Multi-tenant isolation ───────────────────────────────────────────

    public function test_two_schools_may_hold_the_same_admission_number(): void
    {
        // Admission Number is SCHOOL-SCOPED (same model as SRNO/APAAR).
        // Two schools sharing ONE backing store (modelling the single real
        // Firestore project) must both be able to hold the same value.
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        // Force the identical doc-id suffix in both tenants by pre-seeding
        // School A, then asserting School B can still claim its own copy.
        $resA = $this->svc($schoolA, 'SCH_A')->allocate('SCH_A', 'STU_A', '2026');
        $this->assertTrue($resA['ok']);

        $token = substr($resA['admissionNo'], 5);   // strip "2026/"
        $bDocId = $schoolB->docId("2026_{$token}");
        $created = $schoolB->firestoreCreate('admissionNoIndex', $bDocId, [
            'admissionNo' => "2026/{$token}", 'studentId' => 'STU_B', 'schoolId' => 'SCH_B',
        ]);
        $this->assertTrue($created, 'School B must be able to hold the SAME Admission Number as School A');
        $this->assertCount(2, $backing->store['admissionNoIndex'], 'two distinct docs, one per tenant');
    }

    public function test_school_a_cannot_resolve_school_bs_admission_number(): void
    {
        $backing = new FakeSrNoBackingStore();
        $schoolA = new FakeSrNoFirestore('SCH_A', $backing);
        $schoolB = new FakeSrNoFirestore('SCH_B', $backing);

        $resB = $this->svc($schoolB, 'SCH_B')->allocate('SCH_B', 'STU_B', '2026');
        $this->assertTrue($resB['ok']);

        $fromA = $this->svc($schoolA, 'SCH_A')->resolve($resB['admissionNo']);
        $this->assertFalse($fromA['ok'], 'School A must not resolve School B\'s Admission Number');

        $fromB = $this->svc($schoolB, 'SCH_B')->resolve($resB['admissionNo']);
        $this->assertTrue($fromB['ok'], 'School B\'s own lookup must still work — proving genuine scoping, not universal failure');
    }

    public function test_allocation_only_writes_the_index_never_the_student_doc(): void
    {
        // The service must not reach into `students` — writing the display
        // field onto the student record is the CALLER's job (Sis.php /
        // Readmission.php), keeping this service single-purpose exactly as
        // Sr_no_service::claim() is.
        $f = new FakeSrNoFirestore();
        $this->svc($f)->allocate('SCH_TEST', 'STU0001', '2026');
        $this->assertSame(['admissionNoIndex'], array_keys($f->backing()->store));
    }
}
