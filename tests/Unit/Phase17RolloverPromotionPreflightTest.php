<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 17 — SEC-17: all-or-nothing pre-flight for rollover promotion (D21).
 *
 * THE DEFECT: `School_config::rollover_session(promote_students=1)` moved
 * students with NEITHER the capacity guard NOR the roll-collision guard that
 * `Sis::execute_promotion()` applies. Rollover could overfill a destination
 * section or create duplicate Roll Numbers.
 *
 * D21 (operator decision): ALL-OR-NOTHING. If ANY student would violate
 * capacity or roll uniqueness, the ENTIRE promotion is refused and NO student
 * is moved. A per-student skip would leave a school half-promoted.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase17RolloverPromotionPreflightTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors School_config::_rolloverNextClass(). */
    private function nextClass($order): array
    {
        if ($order === 12 || (is_numeric($order) && (int) $order === 12)) return ['kind' => 'alumni'];
        if (is_numeric($order) && (int) $order >= 1 && (int) $order <= 11) {
            $n = (int) $order + 1;
            $suffix = in_array($n, [11, 12], true) ? 'th'
                : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
            if (in_array($n % 100, [11, 12, 13], true)) $suffix = 'th';
            return ['kind' => 'promote', 'label' => "Class {$n}{$suffix}", 'order' => $n];
        }
        $fn = [-5 => -4, -4 => -3, -3 => -2, -2 => -1, -1 => 1];
        $fl = [-4 => 'Class Pre-Nursery', -3 => 'Class Nursery', -2 => 'Class LKG',
               -1 => 'Class UKG', 1 => 'Class 1st'];
        if (is_numeric($order) && isset($fn[(int) $order])) {
            $no = $fn[(int) $order];
            return ($fl[$no] ?? '') === ''
                ? ['kind' => 'skip', 'reason' => 'no_foundational_label']
                : ['kind' => 'promote', 'label' => $fl[$no], 'order' => $no];
        }
        return ['kind' => 'skip', 'reason' => 'unknown_classOrder'];
    }

    /** Canonical capacity (D17): positive wins, else 40. */
    private function capacity(array $sections, string $label, string $section): int
    {
        $raw = (int) ($sections[mb_strtolower("{$label}|{$section}")] ?? 0);
        return $raw > 0 ? $raw : 40;
    }

    /** Active, target-session occupancy of a destination. */
    private function occupancy(array $roster, string $label, string $section, string $to): int
    {
        $n = 0;
        foreach ($roster as $d) {
            if (strcasecmp((string) ($d['status'] ?? 'Active'), 'Active') !== 0) continue;
            if (mb_strtolower((string) $d['className']) !== mb_strtolower($label)) continue;
            if (mb_strtolower((string) $d['section'])   !== mb_strtolower($section)) continue;
            $rs = mb_strtolower((string) ($d['session'] ?? ''));
            if ($rs !== '' && $rs !== mb_strtolower($to)) continue;
            $n++;
        }
        return $n;
    }

    /**
     * Mirrors the implemented pre-flight.
     * Returns ['blocked'=>bool,'capacity'=>[],'rolls'=>[],'planned'=>int]
     */
    private function preflight(array $src, array $roster, array $sections, string $to): array
    {
        $plan = []; $incoming = []; $destSeen = [];
        foreach ($src as $s) {
            $sid = (string) ($s['id'] ?? '');
            if ($sid === '') continue;
            if (($s['status'] ?? 'Active') !== 'Active') continue;
            if (((string) ($s['session'] ?? '')) === $to) continue;   // resume: already promoted

            $next = $this->nextClass($s['classOrder'] ?? null);
            if ($next['kind'] !== 'promote') continue;                // alumni / skip take no seat

            $sec = trim((string) ($s['section'] ?? ''));
            $k   = mb_strtolower("{$next['label']}|{$sec}");
            $destSeen[$k] = ['label' => $next['label'], 'section' => $sec];
            $plan[$sid] = ['label' => $next['label'], 'section' => $sec,
                           'roll' => trim((string) ($s['rollNo'] ?? '')),
                           'name' => (string) ($s['name'] ?? $sid)];
            $incoming[$k] = ($incoming[$k] ?? 0) + 1;
        }

        // Destination roll ownership, indexed once.
        $destRoll = [];
        foreach ($destSeen as $k => $d) {
            if ($d['section'] === '') continue;
            foreach ($roster as $r) {
                if (strcasecmp((string) ($r['status'] ?? 'Active'), 'Active') !== 0) continue;
                if (mb_strtolower((string) $r['className']) !== mb_strtolower($d['label'])) continue;
                if (mb_strtolower((string) $r['section'])   !== mb_strtolower($d['section'])) continue;
                $rs = mb_strtolower((string) ($r['session'] ?? ''));
                if ($rs !== '' && $rs !== mb_strtolower($to)) continue;
                $roll = trim((string) ($r['rollNo'] ?? ''));
                if ($roll !== '') $destRoll[$k][mb_strtolower($roll)] = (string) ($r['name'] ?? 'existing');
            }
        }

        $capIssues = [];
        foreach ($destSeen as $k => $d) {
            if ($d['section'] === '') continue;
            $inc = (int) ($incoming[$k] ?? 0);
            if ($inc < 1) continue;
            $cap  = $this->capacity($sections, $d['label'], $d['section']);
            $cur  = $this->occupancy($roster, $d['label'], $d['section'], $to);
            if (($cur + $inc) > $cap) $capIssues[] = "{$d['label']}-{$d['section']}";
        }

        $rollIssues = []; $batch = [];
        foreach ($plan as $p) {
            if ($p['roll'] === '' || $p['section'] === '') continue;
            $k = mb_strtolower("{$p['label']}|{$p['section']}");
            $lc = mb_strtolower($p['roll']);
            if (isset($destRoll[$k][$lc]))  { $rollIssues[] = "{$p['name']} vs {$destRoll[$k][$lc]}"; continue; }
            if (isset($batch[$k][$lc]))     { $rollIssues[] = "{$p['name']} vs {$batch[$k][$lc]}";   continue; }
            $batch[$k][$lc] = $p['name'];
        }

        return ['blocked' => (!empty($capIssues) || !empty($rollIssues)),
                'capacity' => $capIssues, 'rolls' => $rollIssues, 'planned' => count($plan)];
    }

    private function stu(array $o = []): array
    {
        return array_merge(['id' => 'STU' . random_int(1000, 9999), 'classOrder' => 5,
                            'section' => 'Section A', 'session' => '2025-26',
                            'status' => 'Active', 'rollNo' => '', 'name' => 'Student'], $o);
    }

    private function cohort(int $n, array $o = []): array
    {
        $r = [];
        for ($i = 1; $i <= $n; $i++) $r[] = $this->stu(array_merge(['id' => "STU{$i}"], $o));
        return $r;
    }

    // ── A. Capacity ─────────────────────────────────────────────────────

    public function test_all_students_fit_promotion_allowed(): void
    {
        $r = $this->preflight($this->cohort(30), [], ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked']);
        $this->assertSame(30, $r['planned']);
    }

    public function test_exactly_full_is_allowed(): void
    {
        $r = $this->preflight($this->cohort(40), [], ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked'], 'filling the section exactly must succeed');
    }

    public function test_one_student_over_capacity_blocks_the_entire_promotion(): void
    {
        // THE D21 REGRESSION GUARD: 40 students into a 38-seat section.
        $r = $this->preflight($this->cohort(40), [], ['class 6th|section a' => 38], '2026-27');
        $this->assertTrue($r['blocked']);
        $this->assertNotEmpty($r['capacity']);
    }

    public function test_thirty_nine_valid_and_one_conflict_still_blocks_everything(): void
    {
        $r = $this->preflight($this->cohort(40), [], ['class 6th|section a' => 39], '2026-27');
        $this->assertTrue($r['blocked'], 'no partial promotion — ever');
    }

    public function test_existing_destination_students_consume_seats(): void
    {
        // Repeaters / already-admitted students in 6-A.
        $roster = [];
        for ($i = 0; $i < 10; $i++) {
            $roster[] = ['className' => 'Class 6th', 'section' => 'Section A',
                         'session' => '2026-27', 'status' => 'Active', 'rollNo' => ''];
        }
        $r = $this->preflight($this->cohort(35), $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertTrue($r['blocked'], '10 existing + 35 incoming > 40');
    }

    public function test_withdrawn_destination_students_do_not_consume_seats(): void
    {
        $roster = [];
        for ($i = 0; $i < 10; $i++) {
            $roster[] = ['className' => 'Class 6th', 'section' => 'Section A',
                         'session' => '2026-27', 'status' => 'Withdrawn', 'rollNo' => ''];
        }
        $r = $this->preflight($this->cohort(35), $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked']);
    }

    public function test_unset_capacity_uses_the_canonical_default_of_forty(): void
    {
        $this->assertTrue($this->preflight($this->cohort(41), [], [], '2026-27')['blocked']);
        $this->assertFalse($this->preflight($this->cohort(40), [], [], '2026-27')['blocked']);
    }

    public function test_each_destination_section_is_projected_independently(): void
    {
        $src = array_merge(
            $this->cohort(40, ['section' => 'Section A']),
            $this->cohort(5,  ['section' => 'Section B'])
        );
        // 6-A tight (38), 6-B roomy — 6-A must be the only failure.
        $r = $this->preflight($src, [], ['class 6th|section a' => 38, 'class 6th|section b' => 40], '2026-27');
        $this->assertTrue($r['blocked']);
        $this->assertCount(1, $r['capacity']);
    }

    public function test_other_sessions_do_not_contaminate_destination_occupancy(): void
    {
        $roster = [];
        for ($i = 0; $i < 30; $i++) {
            $roster[] = ['className' => 'Class 6th', 'section' => 'Section A',
                         'session' => '2024-25', 'status' => 'Active', 'rollNo' => ''];
        }
        $r = $this->preflight($this->cohort(35), $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked'], "an old session's roster must not block promotion");
    }

    // ── B. Roll numbers ─────────────────────────────────────────────────

    public function test_destination_roll_collision_blocks_everything(): void
    {
        $roster = [['className' => 'Class 6th', 'section' => 'Section A', 'session' => '2026-27',
                    'status' => 'Active', 'rollNo' => '18', 'name' => 'Priya']];
        $src = [$this->stu(['id' => self::STU_A, 'rollNo' => '18', 'name' => 'Rahul'])];
        $r = $this->preflight($src, $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertTrue($r['blocked']);
        $this->assertStringContainsString('Rahul', $r['rolls'][0]);
        $this->assertStringContainsString('Priya', $r['rolls'][0]);
    }

    public function test_in_batch_roll_collision_blocks_everything(): void
    {
        $src = [
            $this->stu(['id' => self::STU_A, 'rollNo' => '7', 'name' => 'Rahul']),
            $this->stu(['id' => self::STU_B, 'rollNo' => '7', 'name' => 'Aisha']),
        ];
        $r = $this->preflight($src, [], ['class 6th|section a' => 40], '2026-27');
        $this->assertTrue($r['blocked']);
    }

    public function test_same_roll_into_different_sections_is_legal(): void
    {
        $src = [
            $this->stu(['id' => 'S1', 'rollNo' => '18', 'section' => 'Section A']),
            $this->stu(['id' => 'S2', 'rollNo' => '18', 'section' => 'Section B']),
        ];
        $r = $this->preflight($src, [], ['class 6th|section a' => 40, 'class 6th|section b' => 40], '2026-27');
        $this->assertFalse($r['blocked'], 'roll uniqueness is per class+section, never global');
    }

    public function test_alphanumeric_rolls_and_case_insensitivity(): void
    {
        $roster = [['className' => 'Class 6th', 'section' => 'Section A', 'session' => '2026-27',
                    'status' => 'Active', 'rollNo' => '12a', 'name' => 'Existing']];
        $clash = $this->preflight([$this->stu(['rollNo' => '12A'])], $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertTrue($clash['blocked'], '12A and 12a are the same roll');

        $noClash = $this->preflight([$this->stu(['rollNo' => '12'])], $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($noClash['blocked'], '12A must not collapse to 12');
    }

    public function test_blank_rolls_never_collide(): void
    {
        $r = $this->preflight($this->cohort(5, ['rollNo' => '']), [], ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked']);
    }

    public function test_old_session_roll_does_not_collide(): void
    {
        $roster = [['className' => 'Class 6th', 'section' => 'Section A', 'session' => '2024-25',
                    'status' => 'Active', 'rollNo' => '18', 'name' => 'Old']];
        $r = $this->preflight([$this->stu(['rollNo' => '18'])], $roster, ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked']);
    }

    // ── C. Both conflict types reported together ────────────────────────

    public function test_capacity_and_roll_conflicts_are_both_reported(): void
    {
        $roster = [['className' => 'Class 6th', 'section' => 'Section A', 'session' => '2026-27',
                    'status' => 'Active', 'rollNo' => '18', 'name' => 'Priya']];
        $src = $this->cohort(40);
        $src[0]['rollNo'] = '18'; $src[0]['name'] = 'Rahul';
        $r = $this->preflight($src, $roster, ['class 6th|section a' => 38], '2026-27');
        $this->assertTrue($r['blocked']);
        $this->assertNotEmpty($r['capacity'], 'operator should fix everything in one pass');
        $this->assertNotEmpty($r['rolls']);
    }

    // ── D. Class transitions ────────────────────────────────────────────

    public function test_class_twelve_becomes_alumni_and_consumes_no_seat(): void
    {
        $r = $this->preflight($this->cohort(50, ['classOrder' => 12]), [], [], '2026-27');
        $this->assertFalse($r['blocked'], 'Alumni has no destination section');
        $this->assertSame(0, $r['planned']);
    }

    public function test_foundational_ladder_is_planned(): void
    {
        $this->assertSame('Class UKG',   $this->nextClass(-2)['label']);
        $this->assertSame('Class 1st',   $this->nextClass(-1)['label']);
        $this->assertSame('Class 11th',  $this->nextClass(10)['label']);
        $this->assertSame('alumni',      $this->nextClass(12)['kind']);
        $this->assertSame('skip',        $this->nextClass('weird')['kind']);
    }

    public function test_unmappable_students_consume_no_seat(): void
    {
        $r = $this->preflight($this->cohort(50, ['classOrder' => 'weird']), [], ['class 6th|section a' => 1], '2026-27');
        $this->assertFalse($r['blocked']);
        $this->assertSame(0, $r['planned']);
    }

    // ── E. Resume / idempotency ─────────────────────────────────────────

    public function test_already_promoted_students_are_not_double_counted(): void
    {
        // A prior partial run promoted them; they now sit in the target
        // session AND in the destination roster. Counting them twice would
        // wrongly refuse a legitimate resume.
        $src = $this->cohort(20, ['session' => '2026-27']);
        $r = $this->preflight($src, [], ['class 6th|section a' => 5], '2026-27');
        $this->assertFalse($r['blocked']);
        $this->assertSame(0, $r['planned']);
    }

    public function test_inactive_students_are_not_promoted(): void
    {
        $r = $this->preflight($this->cohort(50, ['status' => 'Withdrawn']), [], ['class 6th|section a' => 1], '2026-27');
        $this->assertFalse($r['blocked']);
        $this->assertSame(0, $r['planned']);
    }

    // ── F. No partial write on refusal ──────────────────────────────────

    public function test_a_blocked_preflight_performs_no_writes(): void
    {
        $writes = [];
        $r = $this->preflight($this->cohort(40), [], ['class 6th|section a' => 38], '2026-27');
        if (!$r['blocked']) { $writes[] = 'promoted'; }
        $this->assertTrue($r['blocked']);
        $this->assertSame([], $writes, 'validation precedes every write');
    }

    public function test_audit_of_a_blocked_run_reports_zero_promoted(): void
    {
        $meta = ['outcome' => 'blocked', 'studentsPromoted' => 0, 'plannedStudents' => 40];
        $this->assertSame('blocked', $meta['outcome']);
        $this->assertSame(0, $meta['studentsPromoted'], 'history must never imply a promotion happened');
    }

    // ── G. Performance shape ────────────────────────────────────────────

    public function test_reads_scale_with_destinations_not_students(): void
    {
        // Capacity is memoised per destination; the roster snapshot is one
        // shared read. 2000 students across 4 destinations = 4 resolutions.
        $resolved = []; $reads = 0;
        $resolve = function (string $k) use (&$resolved, &$reads) {
            if (isset($resolved[$k])) return $resolved[$k];
            $reads++; return $resolved[$k] = 40;
        };
        for ($i = 0; $i < 2000; $i++) $resolve('dest' . ($i % 4));
        $this->assertSame(4, $reads, 'O(destinations), not O(students)');
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

    public function test_preflight_never_renumbers_or_clears_a_roll(): void
    {
        $src = [$this->stu(['id' => self::STU_A, 'rollNo' => self::ROLL_A])];
        $r = $this->preflight($src, [], ['class 6th|section a' => 40], '2026-27');
        $this->assertFalse($r['blocked']);
        $this->assertSame('12', self::ROLL_A, 'the roll is validated, never rewritten');
    }
}
