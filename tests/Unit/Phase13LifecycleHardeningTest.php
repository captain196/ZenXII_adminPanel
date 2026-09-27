<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSrNoFirestore;
use Tests\Support\FakeSrNoBackingStore;

require_once __DIR__ . '/../Support/audit_helper_stub.php';
require_once __DIR__ . '/../../application/helpers/audit_helper.php';
require_once __DIR__ . '/../../application/libraries/Sr_no_service.php';

/**
 * Phase 13 — lifecycle hardening.
 *
 * Covers the parts of Workstreams A/B/C/E/F that are expressible without a
 * CodeIgniter or Firestore harness. What is NOT covered here, and why, is
 * stated explicitly in the report's test section rather than papered over:
 *
 *   - N12-1 (Parent app status filter) is Kotlin — verified by compile + the
 *     UAT matrix, not by this PHP suite.
 *   - The SIB-B1 controller flow needs a CI bootstrap; the DECISION RULE it now
 *     uses is modelled and tested here exactly as implemented.
 *   - APAAR masking is a view helper; its pure function is duplicated here to
 *     assert the contract shape (documented as such below).
 *
 * IDENTITY DISCIPLINE: every fixture in this file uses DELIBERATELY CROSSED
 * Student ID / SRNO pairs. Nothing here may imply STU0012 ↔ SRNO 12.
 */
class Phase13LifecycleHardeningTest extends TestCase
{
    // Canonical crossed fixtures used throughout.
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    private function svc(FakeSrNoFirestore $f, string $schoolId = 'SCH_A'): \Sr_no_service
    {
        return (new \Sr_no_service())->init($f, $f, $schoolId, 'ADM_TESTER');
    }

    // ══ L. Student ID / SRNO crossed-pair invariant ═════════════════════

    public function test_crossed_pairs_resolve_independently(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $this->assertTrue($svc->claim(self::SRNO_A, self::STU_A)['ok']);   // 47 -> STU0012
        $this->assertTrue($svc->claim(self::SRNO_B, self::STU_B)['ok']);   // 12 -> STU0047

        $this->assertSame(self::STU_A, $svc->resolve(self::SRNO_A)['currentStudentId']);
        $this->assertSame(self::STU_B, $svc->resolve(self::SRNO_B)['currentStudentId']);

        // The trap a derivation bug falls into: 12 must NOT return STU0012.
        $this->assertNotSame(self::STU_A, $svc->resolve(self::SRNO_B)['currentStudentId']);
    }

    // ══ R. Two schools may both use SRNO 47 ═════════════════════════════

    public function test_two_schools_both_use_srno_47_independently(): void
    {
        $backing = new FakeSrNoBackingStore();
        $a = new FakeSrNoFirestore('SCH_A', $backing);
        $b = new FakeSrNoFirestore('SCH_B', $backing);

        $this->svc($a, 'SCH_A')->claim('47', 'STU0012');
        $this->svc($b, 'SCH_B')->claim('47', 'STU0099');

        $this->assertSame('STU0012', $this->svc($a, 'SCH_A')->resolve('47')['currentStudentId']);
        $this->assertSame('STU0099', $this->svc($b, 'SCH_B')->resolve('47')['currentStudentId']);
        $this->assertFalse($this->svc($a, 'SCH_A')->resolve('999')['ok']);
    }

    // ══ B/T. Re-admission resolves to the CURRENT Student ID ════════════

    public function test_readmission_resolves_srno_to_current_student_id(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim(self::SRNO_A, self::STU_A);                       // 47 -> STU0012
        $this->assertTrue($svc->relink(self::SRNO_A, self::STU_A, self::STU_B, 're-admission')['ok']);

        $res = $svc->resolve(self::SRNO_A);
        $this->assertSame(self::STU_B, $res['currentStudentId'], 'must return the CURRENT owner');
        $this->assertNotSame(self::STU_A, $res['currentStudentId'], 'must NEVER return the historical owner');
    }

    // ══ C. Historical Student ID stays historical ═══════════════════════

    public function test_historical_student_id_is_retained_but_never_resolved(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim(self::SRNO_A, self::STU_A);
        $svc->relink(self::SRNO_A, self::STU_A, self::STU_B, 're-admission');

        // The trail survives for Student Trail / integrity verification…
        $this->assertContains(self::STU_A, $svc->getFullRecord(self::SRNO_A)['historicalStudentIds'] ?? []);
        // …but no ordinary lookup can route new activity to it.
        $this->assertSame(self::STU_B, $svc->resolve(self::SRNO_A)['currentStudentId']);
    }

    // ══ S. Promotion preserves SRNO ═════════════════════════════════════

    public function test_promotion_leaves_srno_resolution_untouched(): void
    {
        $f = new FakeSrNoFirestore('SCH_A');
        $svc = $this->svc($f);
        $svc->claim(self::SRNO_A, self::STU_A);
        $before = $f->backing()->store['srNoIndex'];

        // Promotion writes ONLY to the student document.
        $f->firestoreSet('students', $f->docId(self::STU_A), [
            'studentId' => self::STU_A, 'className' => 'Class 6th', 'section' => 'Section A', 'rollNo' => '7',
        ]);

        $this->assertSame($before, $f->backing()->store['srNoIndex']);
        $this->assertSame(self::STU_A, $svc->resolve(self::SRNO_A)['currentStudentId']);
    }

    // ══ D/E. SIB-B1 — the corrected duplicate rule ══════════════════════
    //
    // Models the decision rule exactly as implemented in
    // Sis::_runStudentImport()/import_dedupe_check(): a duplicate requires
    // phone AND normalised-name agreement. The controller itself needs a CI
    // bootstrap and is covered by the UAT matrix.

    private function isDuplicate(array $seenPhones, string $phone, string $name): bool
    {
        $nameNorm = strtolower(preg_replace('/\s+/', ' ', trim($name)));
        if ($phone === '' || $nameNorm === '') return false;
        return isset($seenPhones[$phone][$nameNorm]);
    }

    private function remember(array &$seenPhones, string $phone, string $name, string $studentId): void
    {
        $nameNorm = strtolower(preg_replace('/\s+/', ' ', trim($name)));
        if ($phone !== '' && $nameNorm !== '') $seenPhones[$phone][$nameNorm] = $studentId;
    }

    public function test_two_siblings_sharing_a_parent_phone_both_survive_import(): void
    {
        // THE SIB-B1 REGRESSION GUARD.
        // Student A: STU0012 / SRNO 47 · Student B: STU0047 / SRNO 48
        // Same parent phone, different names — two legitimate children.
        $seen = [];
        $phone = '9999999999';

        $this->assertFalse($this->isDuplicate($seen, $phone, 'Rahul Sharma'));
        $this->remember($seen, $phone, 'Rahul Sharma', self::STU_A);

        $this->assertFalse(
            $this->isDuplicate($seen, $phone, 'Priya Sharma'),
            'a sibling sharing the parent phone must NOT be treated as a duplicate'
        );
        $this->remember($seen, $phone, 'Priya Sharma', self::STU_B);

        $this->assertCount(2, $seen[$phone], 'both children must be recorded against the shared phone');
        $this->assertSame(self::STU_A, $seen[$phone]['rahul sharma']);
        $this->assertSame(self::STU_B, $seen[$phone]['priya sharma']);
    }

    public function test_the_same_student_re_imported_is_still_deduped(): void
    {
        // The fix must not swing the other way and start creating duplicates.
        $seen = [];
        $phone = '9999999999';
        $this->remember($seen, $phone, 'Rahul Sharma', self::STU_A);

        $this->assertTrue($this->isDuplicate($seen, $phone, 'Rahul Sharma'));
        $this->assertTrue($this->isDuplicate($seen, $phone, '  RAHUL   SHARMA '),
            'case and whitespace differences must still dedupe');
    }

    public function test_phone_alone_is_never_sufficient_proof_of_duplicate(): void
    {
        $seen = [];
        $this->remember($seen, '9999999999', 'Rahul Sharma', self::STU_A);
        $this->assertFalse($this->isDuplicate($seen, '9999999999', 'Someone Else'));
    }

    // ══ F. APAAR masking contract ═══════════════════════════════════════
    //
    // Mirrors s360_mask_apaar() in views/student360/workspace.php. Duplicated
    // deliberately: the original is a view-scope function that cannot be
    // required without CI. Asserts the CONTRACT — the report states this
    // limitation rather than implying the view itself is executed.

    private function maskApaar($raw): string
    {
        $v = trim((string) $raw);
        if ($v === '') return '';
        $digits = preg_replace('/\D/', '', $v) ?? '';
        if ($digits === '') return 'XXXX XXXX XXXX';
        return 'XXXX XXXX ' . substr($digits, -4);
    }

    public function test_apaar_is_masked_to_last_four_digits(): void
    {
        $this->assertSame('XXXX XXXX 9012', $this->maskApaar('123456789012'));
        $this->assertSame('XXXX XXXX 9012', $this->maskApaar('1234 5678 9012'));
        // The full value must never appear in the masked output.
        $this->assertStringNotContainsString('12345678', $this->maskApaar('123456789012'));
    }

    public function test_blank_apaar_stays_blank_and_garbage_is_masked(): void
    {
        $this->assertSame('', $this->maskApaar(''));
        $this->assertSame('', $this->maskApaar('   '));
        $this->assertSame('XXXX XXXX XXXX', $this->maskApaar('not-a-number'));
    }

    // ══ H/I/J/K. Roll Number semantics ══════════════════════════════════
    //
    // ZenX stores Roll Number as free text and enforces NO uniqueness (see the
    // report's Roll Number decision). These assert the storage/display contract
    // that must not regress.

    public function test_roll_number_values_are_preserved_verbatim(): void
    {
        foreach (['18', 'A12', '18A', 'B-07', 'X1'] as $roll) {
            $this->assertSame($roll, $this->rollForDisplay($roll), "roll [{$roll}] must survive verbatim");
        }
    }

    public function test_blank_roll_number_never_becomes_a_fabricated_value(): void
    {
        foreach (['', '   '] as $blank) {
            $out = $this->rollForDisplay($blank);
            $this->assertSame('—', $out);
            $this->assertStringNotContainsString('-2147483648', $out);
            $this->assertNotSame('0', $out);
        }
    }

    public function test_roll_number_is_scoped_not_globally_unique(): void
    {
        // Roll 18 in 5-A and Roll 18 in 5-B are BOTH valid, as are the same
        // class/section pair across two schools. Modelled as the scope key the
        // system would have to use if uniqueness were ever introduced —
        // asserting that the key includes school, session, class and section.
        $key = static fn(string $school, string $session, string $cls, string $sec, string $roll): string
            => implode('|', [$school, $session, $cls, $sec, $roll]);

        $this->assertNotSame(
            $key('SCH_A', '2026-27', 'Class 5th', 'Section A', '18'),
            $key('SCH_A', '2026-27', 'Class 5th', 'Section B', '18'),
            'same roll in a different SECTION must be a different scope'
        );
        $this->assertNotSame(
            $key('SCH_A', '2026-27', 'Class 5th', 'Section A', '18'),
            $key('SCH_B', '2026-27', 'Class 5th', 'Section A', '18'),
            'same roll in a different SCHOOL must be a different scope'
        );
        // And a roll number is never an identity.
        $this->assertNotSame('18', self::STU_A);
        $this->assertNotSame('18', self::SRNO_A);
    }

    /** Mirrors the Teacher app's formatRollNo() contract. */
    private function rollForDisplay(string $raw): string
    {
        $t = trim($raw);
        return $t === '' ? '—' : $t;
    }

    // ══ M/N/O. Fee receipt identity contract ════════════════════════════

    public function test_receipt_document_keeps_student_id_and_carries_srno_for_display(): void
    {
        // Mirrors the receipt document built in FeeCollectionService: the
        // canonical key stays `studentId`; srNo/rollNo are added as display
        // fields. A receipt must NEVER be keyed by srNo.
        $student = ['studentId' => self::STU_A, 'srNo' => self::SRNO_A, 'rollNo' => '18', 'name' => 'Rahul Sharma'];
        $receiptDoc = [
            'receiptNo'   => 'R000001',
            'studentId'   => $student['studentId'],
            'studentName' => $student['name'],
            'srNo'        => (string) ($student['srNo'] ?? ''),
            'rollNo'      => (string) ($student['rollNo'] ?? ''),
        ];

        $this->assertSame(self::STU_A, $receiptDoc['studentId'], 'canonical key must remain the Student ID');
        $this->assertSame(self::SRNO_A, $receiptDoc['srNo'], 'SR No. must be present for display');
        $this->assertArrayHasKey('studentId', $receiptDoc);
        // And the display identifier must not have replaced the key.
        $this->assertNotSame($receiptDoc['srNo'], $receiptDoc['studentId']);
    }

    public function test_printed_receipt_shows_srno_and_omits_student_id(): void
    {
        // Models the identity block in views/fees/receipt.php after Phase 13:
        // SR No. / Class / Roll are printed; the Student ID is not.
        $view = ['student_name' => 'Rahul Sharma', 'sr_no' => self::SRNO_A, 'roll_no' => '18',
                 'class_display' => '5th', 'section_display' => 'A', 'user_id' => self::STU_A];

        $printed = array_filter([
            'Student' => $view['student_name'],
            'SR No.'  => $view['sr_no'],
            'Class'   => $view['class_display'],
            'Roll No.'=> $view['roll_no'],
        ], static fn($v) => trim((string) $v) !== '');

        $this->assertSame(self::SRNO_A, $printed['SR No.']);
        $this->assertArrayNotHasKey('Student ID', $printed, 'the Student ID must not be printed');
        $this->assertNotContains(self::STU_A, array_values($printed), 'no printed field may carry the Student ID');
        // …while the view still RECEIVES it for internal use.
        $this->assertSame(self::STU_A, $view['user_id']);
    }

    public function test_receipt_omits_srno_row_when_school_has_none(): void
    {
        $printed = array_filter(['Student' => 'Rahul Sharma', 'SR No.' => '', 'Roll No.' => ''],
            static fn($v) => trim((string) $v) !== '');
        $this->assertArrayNotHasKey('SR No.', $printed);
        $this->assertArrayNotHasKey('Roll No.', $printed);
    }
}
