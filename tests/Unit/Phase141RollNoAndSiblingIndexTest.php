<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 14.1 — Roll Number roster uniqueness + multi-student phone index.
 *
 * SCOPE HONESTY: the production implementations live on a CodeIgniter
 * controller (`MY_Controller::_rollNoConflict`, `Sis::_registerPhoneIndex`,
 * `Sis::_deregisterPhoneIndex`) which cannot be bootstrapped in this suite.
 * These tests model the DECISION RULES exactly as implemented and guard the
 * properties that would silently regress. The controller wiring itself is
 * covered by the UAT matrix, and the report says so rather than implying
 * otherwise.
 *
 * IDENTITY DISCIPLINE: crossed fixtures only.
 *   Student A: STU0012 / SRNO 47 / Roll A12
 *   Student B: STU0047 / SRNO 12 / Roll 18
 * Nothing here may imply STU0012 ↔ SRNO 12.
 */
class Phase141RollNoAndSiblingIndexTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = 'A12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '18';

    // ── Roll uniqueness rule, mirroring MY_Controller::_rollNoConflict ──

    /** @param array<int,array<string,string>> $roster */
    private function rollConflict(
        array $roster, string $roll, string $class, string $section,
        string $session, string $exceptId = ''
    ): ?array {
        $roll = trim($roll);
        if ($roll === '' || $class === '' || $section === '') return null;
        $rc = mb_strtolower($roll);
        foreach ($roster as $s) {
            $sid = (string) ($s['studentId'] ?? '');
            if ($sid === '' || ($exceptId !== '' && $sid === $exceptId)) continue;
            $status = trim((string) ($s['status'] ?? 'Active'));
            if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
            $r = mb_strtolower(trim((string) ($s['rollNo'] ?? '')));
            if ($r === '' || $r !== $rc) continue;
            if (mb_strtolower(trim((string) ($s['className'] ?? ''))) !== mb_strtolower(trim($class))) continue;
            if (mb_strtolower(trim((string) ($s['section'] ?? ''))) !== mb_strtolower(trim($section))) continue;
            $rs = mb_strtolower(trim((string) ($s['session'] ?? '')));
            $cs = mb_strtolower(trim($session));
            if ($cs !== '' && $rs !== '' && $rs !== $cs) continue;
            return ['studentId' => $sid, 'name' => (string) ($s['name'] ?? ''), 'rollNo' => (string) ($s['rollNo'] ?? '')];
        }
        return null;
    }

    private function rosterWithRahul(): array
    {
        return [[
            'studentId' => self::STU_A, 'name' => 'Rahul Sharma', 'rollNo' => '18',
            'className' => 'Class 5th', 'section' => 'Section A',
            'session' => '2026-27', 'status' => 'Active',
        ]];
    }

    // 1. Same school/session/class/section + same roll → REJECT
    public function test_duplicate_roll_in_same_class_section_session_is_rejected(): void
    {
        $c = $this->rollConflict($this->rosterWithRahul(), '18', 'Class 5th', 'Section A', '2026-27');
        $this->assertNotNull($c, 'a second active student must not take Roll 18 in 5-A');
        $this->assertSame(self::STU_A, $c['studentId']);
    }

    // 2. Different SECTION → ALLOW
    public function test_same_roll_in_a_different_section_is_allowed(): void
    {
        $this->assertNull($this->rollConflict($this->rosterWithRahul(), '18', 'Class 5th', 'Section B', '2026-27'));
    }

    // 3. Different CLASS → ALLOW
    public function test_same_roll_in_a_different_class_is_allowed(): void
    {
        $this->assertNull($this->rollConflict($this->rosterWithRahul(), '18', 'Class 6th', 'Section A', '2026-27'));
    }

    // 4. Different SESSION → ALLOW
    public function test_same_roll_in_a_different_session_is_allowed(): void
    {
        $this->assertNull($this->rollConflict($this->rosterWithRahul(), '18', 'Class 5th', 'Section A', '2027-28'));
    }

    // 5. Different SCHOOL → ALLOW (tenant isolation is the snapshot's scope)
    public function test_same_roll_in_a_different_school_is_allowed(): void
    {
        // A different school's roster never reaches the validator — the snapshot
        // it reads is school-scoped (schoolWhere). Modelled as an empty roster.
        $this->assertNull($this->rollConflict([], '18', 'Class 5th', 'Section A', '2026-27'));
    }

    // 6. SELF-UPDATE → ALLOW
    public function test_a_student_re_saving_their_own_roll_is_allowed(): void
    {
        $c = $this->rollConflict($this->rosterWithRahul(), '18', 'Class 5th', 'Section A', '2026-27', self::STU_A);
        $this->assertNull($c, 'the self-update exception must exclude the student being edited');
    }

    // 7. Alphanumeric roll → ALLOW, and still uniquely enforced
    public function test_alphanumeric_roll_is_supported_and_enforced(): void
    {
        $roster = [[
            'studentId' => self::STU_A, 'name' => 'Rahul Sharma', 'rollNo' => self::ROLL_A,
            'className' => 'Class 5th', 'section' => 'Section A', 'session' => '2026-27', 'status' => 'Active',
        ]];
        $this->assertNull($this->rollConflict($roster, 'B-07', 'Class 5th', 'Section A', '2026-27'));
        $this->assertNotNull($this->rollConflict($roster, 'A12', 'Class 5th', 'Section A', '2026-27'));
        // Case-insensitive: "a12" is the same roster position as "A12".
        $this->assertNotNull($this->rollConflict($roster, 'a12', 'Class 5th', 'Section A', '2026-27'));
    }

    // 8. Blank roll → never a conflict, never fabricated
    public function test_blank_roll_never_conflicts(): void
    {
        $this->assertNull($this->rollConflict($this->rosterWithRahul(), '',    'Class 5th', 'Section A', '2026-27'));
        $this->assertNull($this->rollConflict($this->rosterWithRahul(), '   ', 'Class 5th', 'Section A', '2026-27'));
        // …and a roster row with a blank roll never blocks anyone.
        $roster = [['studentId' => self::STU_B, 'rollNo' => '', 'className' => 'Class 5th',
                    'section' => 'Section A', 'session' => '2026-27', 'status' => 'Active']];
        $this->assertNull($this->rollConflict($roster, '18', 'Class 5th', 'Section A', '2026-27'));
    }

    // Withdrawn students hold no roster position
    public function test_a_withdrawn_student_does_not_block_a_roll_number(): void
    {
        $roster = [[
            'studentId' => self::STU_A, 'name' => 'Rahul Sharma', 'rollNo' => '18',
            'className' => 'Class 5th', 'section' => 'Section A', 'session' => '2026-27',
            'status' => 'Withdrawn',
        ]];
        $this->assertNull($this->rollConflict($roster, '18', 'Class 5th', 'Section A', '2026-27'),
            'a withdrawn record must not reserve a live roll number');
    }

    // 9/10. Promotion + class transfer collision detection
    public function test_promotion_into_a_section_that_already_uses_the_roll_is_detected(): void
    {
        // Rahul (5-A, Roll 18) promoted into 6-A where Priya already holds 18.
        $destination = [[
            'studentId' => self::STU_B, 'name' => 'Priya Sharma', 'rollNo' => '18',
            'className' => 'Class 6th', 'section' => 'Section A', 'session' => '2026-27', 'status' => 'Active',
        ]];
        $this->assertNotNull($this->rollConflict($destination, '18', 'Class 6th', 'Section A', '2026-27', self::STU_A));
    }

    public function test_two_promoted_students_carrying_the_same_roll_collide_with_each_other(): void
    {
        // The merge-two-sections case: 5-A/18 and 5-B/18 both promoted into 6-A.
        // Neither collides with the destination, but they collide with EACH OTHER —
        // which is why execute_promotion() also tracks rolls claimed by the batch.
        $claimed = [];
        $batch = [
            ['studentId' => self::STU_A, 'name' => 'Rahul Sharma', 'rollNo' => '18'],
            ['studentId' => self::STU_B, 'name' => 'Priya Sharma', 'rollNo' => '18'],
        ];
        $clashes = [];
        foreach ($batch as $s) {
            $k = mb_strtolower(trim($s['rollNo']));
            if ($k === '') continue;
            if (isset($claimed[$k])) $clashes[] = "Roll {$s['rollNo']} ({$s['name']} vs {$claimed[$k]})";
            else $claimed[$k] = $s['name'];
        }
        $this->assertCount(1, $clashes, 'merging two sections with the same roll must be detected');
        $this->assertStringContainsString('Rahul Sharma', $clashes[0]);
    }

    // 11. Import: two rows in one file
    public function test_two_import_rows_with_the_same_roll_in_one_class_collide(): void
    {
        $seenRolls = [];
        $rows = [
            ['name' => 'Rahul Sharma', 'roll' => '18', 'class' => 'Class 5th', 'section' => 'Section A'],
            ['name' => 'Priya Sharma', 'roll' => '18', 'class' => 'Class 5th', 'section' => 'Section A'],
            ['name' => 'Aarti Verma',  'roll' => '18', 'class' => 'Class 5th', 'section' => 'Section B'],
        ];
        $rejected = [];
        foreach ($rows as $r) {
            $k = mb_strtolower("{$r['class']}|{$r['section']}|{$r['roll']}");
            if (isset($seenRolls[$k])) { $rejected[] = $r['name']; continue; }
            $seenRolls[$k] = $r['name'];
        }
        $this->assertSame(['Priya Sharma'], $rejected, 'only the same-section duplicate is rejected');
        $this->assertCount(2, $seenRolls, 'Section B keeps its own Roll 18');
    }

    // ── P14-1: multi-student phone index ────────────────────────────────

    /** Mirrors Sis::_registerPhoneIndex — append, never overwrite. */
    private function register(?array $doc, string $studentId): array
    {
        $ids = [];
        if (is_array($doc)) {
            if (is_array($doc['userIds'] ?? null)) $ids = array_values(array_map('strval', $doc['userIds']));
            $legacy = trim((string) ($doc['userId'] ?? ''));
            if ($legacy !== '' && !in_array($legacy, $ids, true)) $ids[] = $legacy;
        }
        if (!in_array($studentId, $ids, true)) $ids[] = $studentId;
        return ['userId' => $ids[0], 'userIds' => array_values($ids), 'type' => 'student'];
    }

    /** Mirrors Sis::_deregisterPhoneIndex. */
    private function deregister(array $doc, string $studentId): ?array
    {
        $ids = array_values(array_filter($doc['userIds'] ?? [], static fn($v) => (string) $v !== $studentId));
        if (empty($ids)) return null;                       // document deleted
        return ['userId' => $ids[0], 'userIds' => $ids, 'type' => 'student'];
    }

    public function test_two_siblings_both_stay_registered_against_the_shared_phone(): void
    {
        $doc = $this->register(null, self::STU_A);
        $doc = $this->register($doc, self::STU_B);
        $this->assertSame([self::STU_A, self::STU_B], $doc['userIds']);
        $this->assertSame(self::STU_A, $doc['userId'], 'the primary stays the FIRST registrant');
    }

    public function test_a_third_sibling_joins_without_evicting_anyone(): void
    {
        $doc = $this->register($this->register($this->register(null, self::STU_A), self::STU_B), 'STU0055');
        $this->assertSame([self::STU_A, self::STU_B, 'STU0055'], $doc['userIds']);
    }

    public function test_registration_is_idempotent(): void
    {
        $doc = $this->register($this->register(null, self::STU_A), self::STU_A);
        $this->assertSame([self::STU_A], $doc['userIds'], 're-registering the same student must not duplicate it');
    }

    public function test_a_legacy_single_value_document_is_upgraded_without_data_loss(): void
    {
        // Pre-Phase-14.1 shape — no userIds[] at all.
        $legacy = ['userId' => self::STU_A, 'type' => 'student'];
        $doc = $this->register($legacy, self::STU_B);
        $this->assertContains(self::STU_A, $doc['userIds'], 'the legacy occupant must be preserved');
        $this->assertContains(self::STU_B, $doc['userIds']);
        $this->assertSame(self::STU_A, $doc['userId'], 'legacy readers keep seeing the original primary');
    }

    public function test_deleting_one_sibling_leaves_the_others_registered(): void
    {
        $doc = $this->register($this->register(null, self::STU_A), self::STU_B);
        $after = $this->deregister($doc, self::STU_A);
        $this->assertNotNull($after, 'the document must survive while a sibling remains');
        $this->assertSame([self::STU_B], $after['userIds']);
        $this->assertSame(self::STU_B, $after['userId'], 'the primary is re-pointed to a surviving student');
    }

    public function test_deleting_the_last_student_removes_the_document(): void
    {
        $doc = $this->register(null, self::STU_A);
        $this->assertNull($this->deregister($doc, self::STU_A));
    }

    public function test_reimport_of_a_sibling_file_creates_no_duplicates(): void
    {
        // THE P14-1 REGRESSION GUARD. Import #1 registers both siblings; on
        // re-import each row must find ITS OWN student among the candidates.
        $doc   = $this->register($this->register(null, self::STU_A), self::STU_B);
        $names = [self::STU_A => 'rahul sharma', self::STU_B => 'priya sharma'];

        foreach ([['rahul sharma', self::STU_A], ['priya sharma', self::STU_B]] as [$rowName, $expected]) {
            $matched = null;
            foreach ($doc['userIds'] as $cand) {              // name-corroborate EVERY candidate
                if (($names[$cand] ?? '') === $rowName) { $matched = $cand; break; }
            }
            $this->assertSame($expected, $matched,
                "re-importing {$rowName} must match the existing student, not create a duplicate");
        }
    }

    // ── Identity invariant ──────────────────────────────────────────────

    public function test_roll_srno_and_student_id_remain_independent(): void
    {
        $this->assertNotSame(self::ROLL_A, self::SRNO_A);
        $this->assertNotSame(self::ROLL_A, self::STU_A);
        $this->assertNotSame(self::SRNO_A, self::STU_A);
        // The crossed pairs: neither student's SRNO matches their ID's digits.
        $this->assertSame('47', self::SRNO_A);  $this->assertSame('STU0012', self::STU_A);
        $this->assertSame('12', self::SRNO_B);  $this->assertSame('STU0047', self::STU_B);
    }
}
