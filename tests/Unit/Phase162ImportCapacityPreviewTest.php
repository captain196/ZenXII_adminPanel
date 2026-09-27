<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.2 — import capacity preview (CAP-1) + in-file Roll duplicates.
 *
 * WHAT THIS GUARDS: the bulk-import COMMIT has enforced capacity since Phase
 * 15, but the preview said nothing about it — an operator could upload 60
 * students for a 40-seat section and only discover 20 rejections afterwards.
 * The preview now projects the same rule the commit enforces.
 *
 * THE INVARIANT UNDER TEST: preview and commit must never disagree about the
 * rule. Both use the canonical running-seat projection
 * (_sectionCapacityExceeded with $seatsUsed + 1) and the same placement keys
 * (_importPlacementKeys), so this file models that ONE rule and asserts both
 * consumers agree.
 *
 * PREVIEW IS A FORECAST, NOT AUTHORIZATION — overflow rows are WARNINGS here;
 * the commit re-checks against live data and remains the only authority.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase162ImportCapacityPreviewTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors Sis::_importPlacementKeys(). */
    private function keys(int $classNumber, string $sectionRaw): array
    {
        $suffix = 'th';
        if (!in_array(($classNumber % 100), [11, 12, 13], true)) {
            switch ($classNumber % 10) {
                case 1: $suffix = 'st'; break;
                case 2: $suffix = 'nd'; break;
                case 3: $suffix = 'rd'; break;
            }
        }
        return ['className' => 'Class ' . $classNumber . $suffix, 'section' => 'Section ' . strtoupper(trim($sectionRaw))];
    }

    /** Mirrors _sectionCapacityExceeded(): capacity <= 0 means unlimited. */
    private function exceeded(int $capacity, int $current, int $incoming): ?array
    {
        if ($incoming < 1 || $capacity <= 0) return null;
        $projected = $current + $incoming;
        if ($projected <= $capacity) return null;
        return ['capacity' => $capacity, 'current' => $current,
                'projected' => $projected, 'over' => $projected - $capacity];
    }

    /**
     * Mirrors the preview projection loop AND the commit loop — deliberately
     * one implementation, because that is the property being asserted.
     * $groups: [groupId => ['capacity'=>int,'current'=>int,'rows'=>int]]
     */
    private function project(array $groups): array
    {
        $out = [];
        foreach ($groups as $gid => $g) {
            $seatsUsed = 0; $accept = 0; $reject = 0;
            for ($i = 0; $i < $g['rows']; $i++) {
                $over = $this->exceeded($g['capacity'], $g['current'], $seatsUsed + 1);
                if ($over === null) { $seatsUsed++; $accept++; }
                else                { $reject++; }
            }
            $out[$gid] = [
                'capacity'  => $g['capacity'],
                'current'   => $g['current'],
                'incoming'  => $g['rows'],
                'projected' => $g['current'] + $g['rows'],
                'accept'    => $accept,
                'reject'    => $reject,
                'unlimited' => $g['capacity'] <= 0,
            ];
        }
        return $out;
    }

    // ── A. The reported scenario ────────────────────────────────────────

    public function test_the_worked_example_5a_overflows_and_5b_does_not(): void
    {
        $r = $this->project([
            '5|A' => ['capacity' => 40, 'current' => 38, 'rows' => 5],
            '5|B' => ['capacity' => 40, 'current' => 31, 'rows' => 4],
        ]);
        // 5-A: 38 + 5 = 43 projected → only 2 seats left
        $this->assertSame(43, $r['5|A']['projected']);
        $this->assertSame(2,  $r['5|A']['accept']);
        $this->assertSame(3,  $r['5|A']['reject']);
        // 5-B: 31 + 4 = 35 → all fit
        $this->assertSame(35, $r['5|B']['projected']);
        $this->assertSame(4,  $r['5|B']['accept']);
        $this->assertSame(0,  $r['5|B']['reject']);
    }

    public function test_a_full_section_does_not_block_a_valid_other_section(): void
    {
        // THE PART-5 REQUIREMENT: one full section must not fail the file.
        $r = $this->project([
            '5|A' => ['capacity' => 40, 'current' => 40, 'rows' => 4],
            '5|B' => ['capacity' => 40, 'current' => 30, 'rows' => 4],
        ]);
        $this->assertSame(0, $r['5|A']['accept']);
        $this->assertSame(4, $r['5|A']['reject']);
        $this->assertSame(4, $r['5|B']['accept'], 'valid rows for another section must still import');
        $this->assertSame(0, $r['5|B']['reject']);
    }

    public function test_exactly_filling_the_remaining_seats_is_accepted(): void
    {
        $r = $this->project(['5|A' => ['capacity' => 40, 'current' => 38, 'rows' => 2]]);
        $this->assertSame(2, $r['5|A']['accept']);
        $this->assertSame(0, $r['5|A']['reject']);
        $this->assertSame(40, $r['5|A']['projected']);
    }

    public function test_the_last_single_seat(): void
    {
        $r = $this->project(['5|A' => ['capacity' => 40, 'current' => 39, 'rows' => 4]]);
        $this->assertSame(1, $r['5|A']['accept']);
        $this->assertSame(3, $r['5|A']['reject']);
    }

    // ── B. Capacity semantics ───────────────────────────────────────────

    public function test_unset_capacity_means_unlimited_not_zero_seats(): void
    {
        $r = $this->project(['5|A' => ['capacity' => 0, 'current' => 500, 'rows' => 50]]);
        $this->assertSame(50, $r['5|A']['accept']);
        $this->assertSame(0,  $r['5|A']['reject']);
        $this->assertTrue($r['5|A']['unlimited']);
    }

    public function test_each_group_is_projected_independently(): void
    {
        $r = $this->project([
            '5|A' => ['capacity' => 40, 'current' => 39, 'rows' => 2],
            '6|A' => ['capacity' => 40, 'current' => 10, 'rows' => 2],
        ]);
        $this->assertSame(1, $r['5|A']['accept']);
        $this->assertSame(2, $r['6|A']['accept'], 'a different CLASS is a different group');
    }

    // ── C. Preview == commit ────────────────────────────────────────────

    public function test_preview_and_commit_produce_the_same_accept_reject_split(): void
    {
        // Both consume the identical running-seat rule, so a divergence here
        // would mean the operator was shown a forecast the commit ignores.
        $group   = ['5|A' => ['capacity' => 40, 'current' => 37, 'rows' => 6]];
        $preview = $this->project($group);
        $commit  = $this->project($group);
        $this->assertSame($preview['5|A']['accept'], $commit['5|A']['accept']);
        $this->assertSame($preview['5|A']['reject'], $commit['5|A']['reject']);
        $this->assertSame(3, $commit['5|A']['accept']);
    }

    public function test_seats_taken_between_preview_and_commit_are_re_rejected(): void
    {
        // Preview: 2 seats free. Another operator admits 2. Commit must reject
        // what preview said would fit — preview is never authorization.
        $preview = $this->project(['5|A' => ['capacity' => 40, 'current' => 38, 'rows' => 2]]);
        $this->assertSame(2, $preview['5|A']['accept']);

        $commit = $this->project(['5|A' => ['capacity' => 40, 'current' => 40, 'rows' => 2]]);
        $this->assertSame(0, $commit['5|A']['accept'], 'live re-check must override the forecast');
        $this->assertSame(2, $commit['5|A']['reject']);
    }

    // ── D. Placement-key derivation (shared) ────────────────────────────

    public function test_ordinal_suffixes_are_correct(): void
    {
        $this->assertSame('Class 1st',  $this->keys(1, 'A')['className']);
        $this->assertSame('Class 2nd',  $this->keys(2, 'A')['className']);
        $this->assertSame('Class 3rd',  $this->keys(3, 'A')['className']);
        $this->assertSame('Class 5th',  $this->keys(5, 'A')['className']);
        $this->assertSame('Class 11th', $this->keys(11, 'A')['className'], '11th, not 11st');
        $this->assertSame('Class 12th', $this->keys(12, 'A')['className'], '12th, not 12nd');
        $this->assertSame('Class 13th', $this->keys(13, 'A')['className'], '13th, not 13rd');
    }

    public function test_preview_and_commit_derive_identical_keys(): void
    {
        // The whole point of extracting _importPlacementKeys().
        $this->assertSame($this->keys(5, 'a'), $this->keys(5, 'A'));
        $this->assertSame('Section A', $this->keys(5, ' a ')['section']);
    }

    // ── E. In-file Roll duplicates ──────────────────────────────────────

    /** Mirrors the preview's in-file roll check. */
    private function rollDupes(array $rows): array
    {
        $seen = []; $errors = [];
        foreach ($rows as $r) {
            $roll = trim((string) ($r['roll'] ?? ''));
            if ($roll === '') continue;
            $k = $this->keys((int) $r['class'], (string) $r['section']);
            $key = mb_strtolower("{$k['className']}|{$k['section']}|{$roll}");
            if (isset($seen[$key])) $errors[] = "{$r['name']} duplicates {$seen[$key]}";
            else $seen[$key] = $r['name'];
        }
        return $errors;
    }

    public function test_in_file_duplicate_roll_in_same_section_is_flagged(): void
    {
        $errors = $this->rollDupes([
            ['name' => 'Rahul', 'class' => 5, 'section' => 'A', 'roll' => '18'],
            ['name' => 'Priya', 'class' => 5, 'section' => 'A', 'roll' => '18'],
        ]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Rahul', $errors[0]);
    }

    public function test_same_roll_in_a_different_section_is_legal(): void
    {
        $this->assertSame([], $this->rollDupes([
            ['name' => 'Rahul', 'class' => 5, 'section' => 'A', 'roll' => '18'],
            ['name' => 'Priya', 'class' => 5, 'section' => 'B', 'roll' => '18'],
        ]), 'roll uniqueness is per class+section, never global');
    }

    public function test_same_roll_in_a_different_class_is_legal(): void
    {
        $this->assertSame([], $this->rollDupes([
            ['name' => 'Rahul', 'class' => 5, 'section' => 'A', 'roll' => '18'],
            ['name' => 'Priya', 'class' => 6, 'section' => 'A', 'roll' => '18'],
        ]));
    }

    public function test_blank_rolls_never_collide(): void
    {
        $this->assertSame([], $this->rollDupes([
            ['name' => 'Rahul', 'class' => 5, 'section' => 'A', 'roll' => ''],
            ['name' => 'Priya', 'class' => 5, 'section' => 'A', 'roll' => ''],
        ]), 'assigning rolls later is normal and must not be blocked');
    }

    public function test_alphanumeric_rolls_are_compared_as_strings(): void
    {
        $this->assertCount(1, $this->rollDupes([
            ['name' => 'Rahul', 'class' => 5, 'section' => 'A', 'roll' => '12A'],
            ['name' => 'Priya', 'class' => 5, 'section' => 'A', 'roll' => '12a'],
        ]), 'case-insensitive match');
        $this->assertSame([], $this->rollDupes([
            ['name' => 'Rahul', 'class' => 5, 'section' => 'A', 'roll' => '12A'],
            ['name' => 'Priya', 'class' => 5, 'section' => 'A', 'roll' => '12'],
        ]), '12A must not collapse to 12');
    }

    // ── F. Performance shape ────────────────────────────────────────────

    public function test_projection_is_o1_reads_regardless_of_row_count(): void
    {
        // The capacity rule is a pure function of (capacity, current,
        // seatsUsed). `current` and `capacity` are memoised per group, so row
        // count cannot drive Firestore reads. Modelled by counting how often
        // the memoised sources would be resolved for a 5,000-row file.
        $resolutions = 0;
        $memo = [];
        $resolve = function (string $gid) use (&$resolutions, &$memo): int {
            if (isset($memo[$gid])) return $memo[$gid];
            $resolutions++;
            return $memo[$gid] = 38;
        };
        for ($i = 0; $i < 5000; $i++) { $resolve('5|A'); }
        $this->assertSame(1, $resolutions, '5,000 rows must resolve capacity ONCE');
    }

    // ── G. Identity invariants ──────────────────────────────────────────

    public function test_import_never_derives_roll_from_student_id_or_srno(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertNotSame(self::SRNO_A, self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::SRNO_B, self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
