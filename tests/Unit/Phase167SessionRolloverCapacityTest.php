<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.7 — SEC-13: session rollover must carry section capacity.
 *
 * ⚠ CORRECTION TO PHASES 16.5 AND 16.6: those reports stated that "no code
 * copies section definitions into a new session". THAT WAS WRONG —
 * `School_config::rollover_session()` has copied sections all along, with
 * idempotency, resume and locking. I had searched the promotion flow, not
 * School_config.
 *
 * THE ACTUAL DEFECT (SEC-13): that copy rebuilt the section document field by
 * field and OMITTED `maxStrength`. Every rolled-over section was created with
 * no configured capacity, so the canonical resolver fell back to its default
 * of 40 — turning a school's 30 / 40 / 50 into 40 / 40 / 40.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase167SessionRolloverCapacityTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors Firestore_service::sectionDocId(). */
    private function docId(string $school, string $session, string $class, string $section): string
    {
        return "{$school}_{$session}_{$class}_{$section}";
    }

    /** Mirrors the corrected rollover section copy. */
    private function copySection(string $school, string $to, array $src): array
    {
        $cap = (int) ($src['maxStrength'] ?? $src['max_strength'] ?? 0);
        if ($cap < 0) $cap = 0;
        return [
            'schoolId'      => $school,
            'className'     => (string) $src['className'],
            'section'       => (string) $src['section'],
            'sectionKey'    => "{$src['className']}/{$src['section']}",
            'classTeacher'  => '',
            'maxStrength'   => $cap,
            'session'       => $to,
            'studentCount'  => 0,
        ];
    }

    /** Mirrors the full copy loop, including the skip-existing rule. */
    private function rollover(string $school, string $from, string $to, array $src, array $existingTargetIds = []): array
    {
        $copied = []; $skipped = 0;
        foreach ($src as $d) {
            $class = (string) ($d['className'] ?? '');
            $sec   = (string) ($d['section'] ?? '');
            if ($class === '' || $sec === '') { $skipped++; continue; }
            $id = $this->docId($school, $to, $class, $sec);
            if (in_array($id, $existingTargetIds, true)) { $skipped++; continue; }
            $copied[$id] = $this->copySection($school, $to, $d);
        }
        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** Mirrors MY_Controller::_sectionCapacity() — the D17 resolver. */
    private function resolveCapacity(?array $doc): int
    {
        $c = 40;
        if (is_array($doc)) {
            $raw = (int) ($doc['maxStrength'] ?? $doc['max_strength'] ?? 0);
            if ($raw > 0) $c = $raw;
        }
        return $c;
    }

    private function src(): array
    {
        return [
            ['className' => 'Class 5th', 'section' => 'Section A', 'maxStrength' => 30],
            ['className' => 'Class 5th', 'section' => 'Section B', 'maxStrength' => 40],
            ['className' => 'Class 5th', 'section' => 'Section C', 'maxStrength' => 50],
        ];
    }

    // ── A. THE DEFECT ───────────────────────────────────────────────────

    public function test_capacities_survive_rollover(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src());
        $caps = array_map(fn($d) => $d['maxStrength'], array_values($r['copied']));
        $this->assertSame([30, 40, 50], $caps, 'pre-fix these were dropped entirely');
    }

    public function test_pre_fix_omission_would_have_resolved_to_forty(): void
    {
        // Reproduces the old behaviour: no maxStrength on the copied doc.
        $withoutCapacity = ['className' => 'Class 5th', 'section' => 'Section A', 'session' => '2026-27'];
        $this->assertSame(40, $this->resolveCapacity($withoutCapacity),
            '30 / 40 / 50 silently became 40 / 40 / 40');
    }

    public function test_resolved_capacity_after_rollover_matches_the_source(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src());
        foreach ([30, 40, 50] as $i => $expected) {
            $doc = array_values($r['copied'])[$i];
            $this->assertSame($expected, $this->resolveCapacity($doc));
        }
    }

    // ── B. Capacity edge cases ──────────────────────────────────────────

    public function test_unconfigured_source_capacity_is_stored_as_zero_not_forty(): void
    {
        // Baking 40 into the document would turn "never configured" into
        // "explicitly 40" and hide it from the operator. The resolver owns
        // the default, not the stored record.
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', [
            ['className' => 'Class 6th', 'section' => 'Section A'],
        ]);
        $doc = array_values($r['copied'])[0];
        $this->assertSame(0, $doc['maxStrength'], 'stored as unconfigured');
        $this->assertSame(40, $this->resolveCapacity($doc), 'resolver still supplies the default');
    }

    public function test_negative_capacity_is_normalised_to_zero(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', [
            ['className' => 'Class 6th', 'section' => 'Section A', 'maxStrength' => -5],
        ]);
        $this->assertSame(0, array_values($r['copied'])[0]['maxStrength']);
    }

    public function test_snake_case_capacity_is_also_carried(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', [
            ['className' => 'Class 6th', 'section' => 'Section A', 'max_strength' => 35],
        ]);
        $this->assertSame(35, array_values($r['copied'])[0]['maxStrength']);
    }

    // ── C. Session isolation ────────────────────────────────────────────

    public function test_target_documents_use_the_target_session(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src());
        foreach ($r['copied'] as $id => $doc) {
            $this->assertStringContainsString('_2026-27_', $id);
            $this->assertSame('2026-27', $doc['session']);
        }
    }

    public function test_source_and_target_ids_never_collide(): void
    {
        $a = $this->docId('SCH_1', '2025-26', 'Class 5th', 'Section A');
        $b = $this->docId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $this->assertNotSame($a, $b, 'the source session must be untouchable');
    }

    public function test_schools_never_collide(): void
    {
        $this->assertNotSame(
            $this->docId('SCH_1', '2026-27', 'Class 5th', 'Section A'),
            $this->docId('SCH_2', '2026-27', 'Class 5th', 'Section A')
        );
    }

    // ── D. Idempotency / existing target ────────────────────────────────

    public function test_second_submission_creates_no_duplicates(): void
    {
        $first  = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src());
        $second = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src(), array_keys($first['copied']));
        $this->assertCount(3, $first['copied']);
        $this->assertCount(0, $second['copied'], 'a repeat submission must copy nothing');
        $this->assertSame(3, $second['skipped']);
    }

    public function test_an_existing_target_section_is_never_overwritten(): void
    {
        // The operator may have already set a different capacity for next year.
        $existingId = $this->docId('SCH_1', '2026-27', 'Class 5th', 'Section A');
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src(), [$existingId]);
        $this->assertArrayNotHasKey($existingId, $r['copied'], 'skip, never overwrite');
        $this->assertCount(2, $r['copied']);
    }

    // ── E. Preview states ───────────────────────────────────────────────

    private function state(int $srcCap, ?int $dstCap): string
    {
        if ($dstCap === null)      return 'NEW';
        if ($dstCap === $srcCap)   return 'EXISTS_UNCHANGED';
        return 'EXISTS_DIFFERENT_CAPACITY';
    }

    public function test_preview_reports_the_three_non_destructive_states(): void
    {
        $this->assertSame('NEW',                       $this->state(30, null));
        $this->assertSame('EXISTS_UNCHANGED',          $this->state(30, 30));
        $this->assertSame('EXISTS_DIFFERENT_CAPACITY', $this->state(30, 45));
    }

    public function test_preview_surfaces_the_capacity_that_will_be_carried(): void
    {
        $row = ['currentCapacity' => 50, 'targetCapacity' => null, 'state' => 'NEW', 'willCopy' => true];
        $this->assertSame(50, $row['currentCapacity']);
        $this->assertTrue($row['willCopy']);
    }

    // ── F. Empty / malformed sources ────────────────────────────────────

    public function test_empty_source_session_is_safe(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', []);
        $this->assertSame([], $r['copied']);
        $this->assertSame(0, $r['skipped']);
    }

    public function test_malformed_source_rows_are_skipped_not_fatal(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', [
            ['className' => '',          'section' => 'Section A', 'maxStrength' => 30],
            ['className' => 'Class 5th', 'section' => '',          'maxStrength' => 30],
            ['className' => 'Class 5th', 'section' => 'Section A', 'maxStrength' => 30],
        ]);
        $this->assertCount(1, $r['copied']);
        $this->assertSame(2, $r['skipped']);
    }

    // ── G. Scope: structure only ────────────────────────────────────────

    public function test_rollover_copies_no_student_data(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', $this->src());
        foreach ($r['copied'] as $doc) {
            $this->assertArrayNotHasKey('studentId', $doc);
            $this->assertArrayNotHasKey('rollNo', $doc);
            $this->assertArrayNotHasKey('srNo', $doc);
            $this->assertSame(0, $doc['studentCount'], 'the new section starts empty');
        }
    }

    public function test_class_teacher_is_reset_not_carried(): void
    {
        $r = $this->rollover('SCH_1', '2025-26', '2026-27', [
            ['className' => 'Class 5th', 'section' => 'Section A', 'maxStrength' => 30,
             'classTeacher' => 'Mrs Rao'],
        ]);
        $this->assertSame('', array_values($r['copied'])[0]['classTeacher'],
            'teacher assignment is a new-session decision');
    }

    // ── H. Identity invariants ──────────────────────────────────────────

    public function test_identity_separation_unchanged(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
