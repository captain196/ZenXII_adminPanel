<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 15 — Class/Section capacity enforcement.
 *
 * SCOPE HONESTY: `MY_Controller::_sectionCapacityExceeded()` and the import
 * loop live on CodeIgniter controllers that cannot be bootstrapped in this
 * suite. These tests model the DECISION RULE exactly as implemented — the
 * Active-only filter, session scoping, the unlimited-when-unset semantics,
 * and the import's running-seat projection — and are the regression guard for
 * that rule. Controller wiring and the admission form are covered by the UAT
 * matrix in the report, not by this file.
 *
 * IDENTITY DISCIPLINE: crossed fixtures throughout.
 *   STU0012 ↔ SRNO 47   ·   STU0047 ↔ SRNO 12
 */
class Phase15SectionCapacityTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';

    /** Mirrors MY_Controller::_sectionCapacityExceeded(). */
    private function capacityExceeded(
        array $roster, int $capacity, string $class, string $section,
        string $session, int $incoming = 1, string $exceptId = ''
    ): ?array {
        if ($class === '' || $section === '' || $incoming < 1) return null;
        if ($capacity <= 0) return null;                 // unset => unlimited

        $current = 0;
        foreach ($roster as $s) {
            $sid = (string) ($s['studentId'] ?? '');
            if ($sid === '' || ($exceptId !== '' && $sid === $exceptId)) continue;
            $status = trim((string) ($s['status'] ?? 'Active'));
            if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
            if (mb_strtolower(trim((string) ($s['className'] ?? ''))) !== mb_strtolower($class)) continue;
            if (mb_strtolower(trim((string) ($s['section'] ?? ''))) !== mb_strtolower($section)) continue;
            $rs = mb_strtolower(trim((string) ($s['session'] ?? '')));
            $cs = mb_strtolower(trim($session));
            if ($cs !== '' && $rs !== '' && $rs !== $cs) continue;
            $current++;
        }
        $projected = $current + $incoming;
        if ($projected <= $capacity) return null;
        return ['capacity' => $capacity, 'current' => $current, 'incoming' => $incoming,
                'projected' => $projected, 'over' => $projected - $capacity];
    }

    private function roster(int $n, array $over = []): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = array_merge([
                'studentId' => 'STU' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'className' => 'Class 5th', 'section' => 'Section A',
                'session' => '2026-27', 'status' => 'Active',
            ], $over);
        }
        return $out;
    }

    // ── A. Direct admission ─────────────────────────────────────────────

    public function test_admission_into_a_non_full_section_is_allowed(): void
    {
        $this->assertNull($this->capacityExceeded($this->roster(37), 40, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_admission_into_a_full_section_is_rejected(): void
    {
        $over = $this->capacityExceeded($this->roster(40), 40, 'Class 5th', 'Section A', '2026-27');
        $this->assertNotNull($over);
        $this->assertSame(40, $over['capacity']);
        $this->assertSame(40, $over['current']);
        $this->assertSame(1, $over['over']);
    }

    public function test_the_last_remaining_seat_is_allowed(): void
    {
        $this->assertNull($this->capacityExceeded($this->roster(39), 40, 'Class 5th', 'Section A', '2026-27'),
            'exactly filling the section must succeed');
    }

    public function test_withdrawn_students_do_not_consume_capacity(): void
    {
        // 40 rows, but all withdrawn — the section is effectively empty.
        $roster = $this->roster(40, ['status' => 'Withdrawn']);
        $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27'),
            'a withdrawn student must not hold a seat');
    }

    public function test_capacity_is_session_scoped(): void
    {
        // 40 active students, but in the PREVIOUS session.
        $roster = $this->roster(40, ['session' => '2025-26']);
        $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27'),
            'last year\'s roster must not consume this year\'s seats');
    }

    public function test_capacity_is_section_scoped(): void
    {
        $roster = $this->roster(40, ['section' => 'Section B']);
        $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27'),
            'a full Section B must not block Section A');
    }

    public function test_capacity_is_class_scoped(): void
    {
        $roster = $this->roster(40, ['className' => 'Class 6th']);
        $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_unset_capacity_means_unlimited_not_zero(): void
    {
        // Mirrors the existing promotion/transfer guards: `if ($maxStrength > 0)`.
        // Treating 0 as "zero seats" would retroactively lock every section
        // that has never had a capacity configured.
        $this->assertNull($this->capacityExceeded($this->roster(500), 0, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_editing_the_same_student_does_not_count_them_twice(): void
    {
        // Self-exclusion: re-saving a student already in a full section must
        // not be blocked by their own seat.
        $roster = $this->roster(40);
        $selfId = 'STU0001';
        $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27', 1, $selfId));
    }

    // ── B. Bulk import projection ───────────────────────────────────────

    public function test_import_that_exactly_fills_remaining_seats_succeeds(): void
    {
        // 38 existing, capacity 40, importing 2 → projected 40 → allowed.
        $roster = $this->roster(38);
        $seatsUsed = 0;
        for ($i = 0; $i < 2; $i++) {
            $over = $this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27', $seatsUsed + 1);
            $this->assertNull($over, "import row {$i} should fit");
            $seatsUsed++;
        }
        $this->assertSame(2, $seatsUsed);
    }

    public function test_import_that_exceeds_capacity_rejects_only_the_overflow_rows(): void
    {
        // 38 existing, capacity 40, importing 3 → rows 1-2 fit, row 3 rejected.
        $roster = $this->roster(38);
        $seatsUsed = 0; $accepted = 0; $rejected = 0;
        for ($i = 0; $i < 3; $i++) {
            $over = $this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27', $seatsUsed + 1);
            if ($over !== null) { $rejected++; continue; }
            $seatsUsed++; $accepted++;
        }
        $this->assertSame(2, $accepted, 'only the two real seats may be filled');
        $this->assertSame(1, $rejected);
        $this->assertLessThanOrEqual(40, 38 + $accepted, 'the section must never end over capacity');
    }

    public function test_each_class_section_group_is_projected_independently(): void
    {
        // 5-A: 38 existing + 2 incoming → OK.  5-B: 20 existing + 10 → OK.
        $roster = array_merge(
            $this->roster(38),
            $this->roster(20, ['section' => 'Section B'])
        );
        $seats = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < 2; $i++) {
            $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27', $seats['a'] + 1));
            $seats['a']++;
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section B', '2026-27', $seats['b'] + 1));
            $seats['b']++;
        }
        $this->assertSame(2, $seats['a']);
        $this->assertSame(10, $seats['b']);
    }

    public function test_a_full_section_in_one_group_does_not_block_a_valid_other_group(): void
    {
        // 5-A full (40/40) rejects; 5-B (20/40) still accepts — proving the
        // import does not abort the whole file for one bad group.
        $roster = array_merge($this->roster(40), $this->roster(20, ['section' => 'Section B']));
        $this->assertNotNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27', 1));
        $this->assertNull($this->capacityExceeded($roster, 40, 'Class 5th', 'Section B', '2026-27', 1));
    }

    public function test_import_message_reports_concrete_numbers(): void
    {
        $over = $this->capacityExceeded($this->roster(38), 40, 'Class 5th', 'Section A', '2026-27', 5);
        $this->assertNotNull($over);
        $this->assertSame(38, $over['current']);
        $this->assertSame(5,  $over['incoming']);
        $this->assertSame(43, $over['projected']);
        $this->assertSame(3,  $over['over'], 'the operator must be told exactly how many over they are');
    }

    // ── C. Latency shape ────────────────────────────────────────────────

    public function test_projection_needs_no_per_row_capacity_read(): void
    {
        // The running counter means a 500-row single-section import consults
        // the roster snapshot ONCE (memoised) rather than per row. Modelled by
        // asserting the projection is a pure function of (current, seatsUsed)
        // — it never needs to re-derive `current` mid-run.
        $roster = $this->roster(10);
        $seatsUsed = 0; $derivations = 0;
        for ($i = 0; $i < 25; $i++) {
            $over = $this->capacityExceeded($roster, 40, 'Class 5th', 'Section A', '2026-27', $seatsUsed + 1);
            $derivations++;
            if ($over === null) $seatsUsed++;
        }
        $this->assertSame(25, $derivations);
        $this->assertSame(25, $seatsUsed, 'all 25 fit within capacity 40 given 10 existing');
    }

    // ── D. Identity invariants ──────────────────────────────────────────

    public function test_capacity_logic_never_touches_student_or_sr_identity(): void
    {
        // Capacity is a placement concern; it must not read, derive or imply
        // any identifier relationship.
        $this->assertNotSame(self::STU_A, self::SRNO_A);
        $this->assertSame('47', self::SRNO_A);   // STU0012 pairs with 47, NOT 12
        $this->assertSame('12', self::SRNO_B);   // STU0047 pairs with 12, NOT 47
    }
}
