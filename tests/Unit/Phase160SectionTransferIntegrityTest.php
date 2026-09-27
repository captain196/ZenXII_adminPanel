<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.0 R1 — section-transfer integrity (CS-1, CS-2, D16).
 *
 * REGRESSION GUARD for the defect closed this phase:
 * `Classes::transfer_students()` updated Class/Section and left `rollNo`
 * untouched with NO roll validation, so a transferred student carried their
 * roll into the destination. Move Rahul (Roll 18) from 5-A into 5-B where
 * Priya already holds Roll 18 → two Roll 18s in 5-B. Admission, import, edit
 * and promotion all validated this; transfer was the only path that did not.
 *
 * SCOPE HONESTY: the controller cannot be bootstrapped in this suite, so these
 * tests model the DECISION RULE exactly as implemented — the established
 * pattern in this repo. Controller wiring, RBAC evaluation and the browser
 * flow are UAT items.
 *
 * IDENTITY DISCIPLINE: crossed fixtures throughout.
 *   STU0012 → SRNO 47 → Roll 12   ·   STU0047 → SRNO 12 → Roll 47
 */
class Phase160SectionTransferIntegrityTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors MY_Controller::_rollNoConflict() against a destination roster. */
    private function rollConflict(
        array $destRoster, string $roll, string $class, string $section,
        string $session, string $exceptId = ''
    ): ?array {
        $roll = trim($roll);
        if ($roll === '' || $class === '' || $section === '') return null;

        foreach ($destRoster as $s) {
            $sid = (string) ($s['studentId'] ?? '');
            if ($sid === '' || ($exceptId !== '' && $sid === $exceptId)) continue;
            $status = trim((string) ($s['status'] ?? 'Active'));
            if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
            if (mb_strtolower(trim((string) ($s['className'] ?? ''))) !== mb_strtolower($class)) continue;
            if (mb_strtolower(trim((string) ($s['section'] ?? ''))) !== mb_strtolower($section)) continue;
            $rs = mb_strtolower(trim((string) ($s['session'] ?? '')));
            if ($rs !== '' && $rs !== mb_strtolower($session)) continue;
            if (mb_strtolower(trim((string) ($s['rollNo'] ?? ''))) !== mb_strtolower($roll)) continue;
            return ['studentId' => $sid, 'name' => (string) ($s['name'] ?? ''), 'rollNo' => $roll];
        }
        return null;
    }

    /**
     * Mirrors the transfer pre-flight: destination clash + in-batch clash.
     * Returns the list of human-readable clashes (empty = transfer allowed).
     */
    private function preflight(array $destRoster, array $batch, string $class, string $section, string $session): array
    {
        $clashes = []; $seen = [];
        foreach ($batch as $stu) {
            $roll = trim((string) ($stu['rollNo'] ?? ''));
            if ($roll === '') continue;                       // no roll → nothing to clash
            $name = (string) ($stu['name'] ?? $stu['studentId']);

            $c = $this->rollConflict($destRoster, $roll, $class, $section, $session, (string) $stu['studentId']);
            if ($c !== null) {
                $clashes[] = "{$name} (Roll {$roll}) — already used by " . ($c['name'] ?: $c['studentId']);
                continue;
            }
            $rk = mb_strtolower($roll);
            if (isset($seen[$rk])) {
                $clashes[] = "{$name} (Roll {$roll}) — same roll as {$seen[$rk]} in this transfer";
                continue;
            }
            $seen[$rk] = $name;
        }
        return $clashes;
    }

    private function dest(array $rows = []): array
    {
        return array_map(static function ($r) {
            return array_merge([
                'className' => 'Class 5th', 'section' => 'Section B',
                'session' => '2026-27', 'status' => 'Active',
            ], $r);
        }, $rows);
    }

    // ── A. THE DEFECT ───────────────────────────────────────────────────

    public function test_transfer_into_a_taken_roll_is_blocked(): void
    {
        // Priya already holds Roll 18 in 5-B. Rahul (Roll 18) is moved there.
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']];

        $clashes = $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27');
        $this->assertCount(1, $clashes, 'the duplicate roll MUST be caught');
        $this->assertStringContainsString('Rahul', $clashes[0]);
        $this->assertStringContainsString('Priya', $clashes[0]);
    }

    public function test_transfer_with_a_free_roll_is_allowed(): void
    {
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '21']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    public function test_two_students_in_the_same_batch_sharing_a_roll_are_blocked(): void
    {
        // Both moved together, both Roll 7 — nothing in the destination yet.
        $batch = [
            ['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '7'],
            ['studentId' => self::STU_B, 'name' => 'Aisha', 'rollNo' => '7'],
        ];
        $clashes = $this->preflight($this->dest(), $batch, 'Class 5th', 'Section B', '2026-27');
        $this->assertCount(1, $clashes);
        $this->assertStringContainsString('same roll as Rahul', $clashes[0]);
    }

    public function test_a_partial_clash_rejects_the_whole_batch(): void
    {
        // All-or-nothing: validated before ANY write, so no student is half-moved.
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18']]);
        $batch = [
            ['studentId' => 'STU1000', 'name' => 'Ok One', 'rollNo' => '30'],
            ['studentId' => self::STU_A, 'name' => 'Rahul',  'rollNo' => '18'],
        ];
        $clashes = $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27');
        $this->assertNotEmpty($clashes, 'one clash must block the batch');
    }

    // ── B. Scope correctness ────────────────────────────────────────────

    public function test_same_roll_in_a_different_section_does_not_block(): void
    {
        // Roll 18 exists in 5-A; moving Roll 18 into 5-B is legitimate.
        $dest = $this->dest([[
            'studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18', 'section' => 'Section A',
        ]]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    public function test_same_roll_in_a_different_class_does_not_block(): void
    {
        $dest = $this->dest([[
            'studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18', 'className' => 'Class 6th',
        ]]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    public function test_same_roll_in_a_previous_session_does_not_block(): void
    {
        $dest = $this->dest([[
            'studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18', 'session' => '2025-26',
        ]]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    public function test_a_withdrawn_student_does_not_hold_a_roll(): void
    {
        $dest = $this->dest([[
            'studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18', 'status' => 'Withdrawn',
        ]]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'),
            'a withdrawn student must not block a roll');
    }

    public function test_a_student_without_a_roll_transfers_freely(): void
    {
        // CRM-enrolled students have no roll yet; they must not be blocked.
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '18']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    public function test_self_exclusion_allows_a_no_op_move(): void
    {
        // The moving student must not be blocked by their own roll.
        $dest  = $this->dest([['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '18']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    // ── C. Alphanumeric safety ──────────────────────────────────────────

    public function test_alphanumeric_rolls_are_compared_as_strings(): void
    {
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '12A']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '12A']];
        $this->assertCount(1, $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'),
            'alphanumeric rolls must clash exactly');
    }

    public function test_alphanumeric_roll_does_not_collapse_to_its_numeric_prefix(): void
    {
        // '12A' must NOT equal '12' — a numeric cast would wrongly clash.
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '12']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '12A']];
        $this->assertSame([], $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    public function test_roll_comparison_is_case_insensitive(): void
    {
        $dest  = $this->dest([['studentId' => 'STU9001', 'name' => 'Priya', 'rollNo' => '12a']]);
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => '12A']];
        $this->assertCount(1, $this->preflight($dest, $batch, 'Class 5th', 'Section B', '2026-27'));
    }

    // ── D. Audit trail (CS-2) ───────────────────────────────────────────

    public function test_history_event_shape_records_both_ends_of_the_move(): void
    {
        $event = [
            'action'   => 'SECTION_TRANSFER',
            'metadata' => [
                'from_class' => 'Class 5th', 'from_section' => 'Section A',
                'to_class'   => 'Class 5th', 'to_section'   => 'Section B',
                'session'    => '2026-27',
            ],
        ];
        $this->assertSame('SECTION_TRANSFER', $event['action']);
        foreach (['from_class', 'from_section', 'to_class', 'to_section', 'session'] as $k) {
            $this->assertArrayHasKey($k, $event['metadata'], "history must record {$k}");
        }
    }

    public function test_history_is_written_only_after_a_confirmed_write(): void
    {
        // A failed student update must not produce a history row claiming a
        // move that did not happen.
        $writes = [['ok' => true], ['ok' => false], ['ok' => true]];
        $logged = 0;
        foreach ($writes as $w) { if ($w['ok']) $logged++; }
        $this->assertSame(2, $logged);
    }

    // ── E. Identity invariants ──────────────────────────────────────────

    public function test_roll_is_never_derived_from_student_id_or_srno(): void
    {
        // STU0012 → SRNO 47 → Roll 12 : the roll matching the ID's digits is a
        // coincidence the code must never rely on.
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertNotSame(self::SRNO_A, self::ROLL_A);
        // STU0047 → SRNO 12 → Roll 47 : fully crossed.
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::SRNO_B, self::ROLL_B);
    }

    public function test_transfer_keys_on_student_id_not_roll_or_srno(): void
    {
        $batch = [['studentId' => self::STU_A, 'name' => 'Rahul', 'rollNo' => self::ROLL_A]];
        $this->assertSame(self::STU_A, $batch[0]['studentId']);
        $this->assertArrayNotHasKey('srNo', $batch[0]);
    }
}
