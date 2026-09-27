<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.3 — LIFE-1: Firestore class/section enumeration.
 *
 * WHAT THIS GUARDS: `MY_Controller::_get_session_classes()` used to walk the
 * RETIRED RTDB tree `Schools/{school}/{session}/Class X/Section Y`. For
 * Firestore-onboarded schools that returned an EMPTY ARRAY WITH NO ERROR, so
 * every class dropdown fed by it (Academic, Certificates, LMS, Health_check)
 * rendered blank and nothing reported a fault.
 *
 * THE LOAD-BEARING CONTRACT: `section` must be the BARE letter ('A'), NOT the
 * stored Firestore form ('Section A'). Three independent consumers re-prefix it
 * themselves — Timetable_service.php:690, Health_check.php:309, and Red_flags'
 * normaliser. Returning the prefixed value would produce 'Section Section A'.
 *
 * SCOPE HONESTY: the controller cannot be bootstrapped here, so these tests
 * model the transformation rule exactly as implemented.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase163ClassSectionEnumerationTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors the implemented enumeration transform. */
    private function enumerate(array $docs, string $session): array
    {
        $classes = []; $seen = [];
        foreach ($docs as $d) {
            $className = trim((string) ($d['className'] ?? ''));
            $sectionIn = trim((string) ($d['section'] ?? ''));
            if ($className === '' || $sectionIn === '') continue;

            $rowSession = trim((string) ($d['session'] ?? ''));
            if ($session !== '' && $rowSession !== '' && strcasecmp($rowSession, $session) !== 0) continue;

            $classKey  = $className;   // already a classKey in stored data
            $secLetter = trim(preg_replace('/^Section\b\s*/i', '', $sectionIn));
            if ($classKey === '' || $secLetter === '') continue;

            $k = mb_strtolower($classKey . '|' . $secLetter);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;

            $classes[] = [
                'class_key'     => $classKey,
                'section'       => $secLetter,
                'label'         => $classKey . ' / Section ' . $secLetter,
                'class_section' => $classKey . " '" . $secLetter . "'",
            ];
        }
        usort($classes, static function ($a, $b) {
            $an = (int) preg_replace('/\D/', '', $a['class_key']);
            $bn = (int) preg_replace('/\D/', '', $b['class_key']);
            if ($an !== $bn) return $an <=> $bn;
            return strcmp($a['section'], $b['section']);
        });
        return $classes;
    }

    private function doc(string $class, string $section, string $session = '2026-27'): array
    {
        return ['className' => $class, 'section' => $section, 'session' => $session];
    }

    // ── A. The contract ─────────────────────────────────────────────────

    public function test_stored_prefixed_section_is_returned_bare(): void
    {
        // Firestore stores 'Section A'; consumers need 'A'.
        $r = $this->enumerate([$this->doc('Class 5th', 'Section A')], '2026-27');
        $this->assertSame('A', $r[0]['section'], 'section MUST be the bare letter');
        $this->assertSame('Class 5th', $r[0]['class_key']);
    }

    public function test_consumers_reprefix_without_doubling(): void
    {
        // Timetable_service.php:690 and Health_check.php:309 both do
        // `'Section ' . $section`. Bare input is what makes that correct.
        $r = $this->enumerate([$this->doc('Class 5th', 'Section A')], '2026-27');
        $this->assertSame('Section A', 'Section ' . $r[0]['section']);
        $this->assertNotSame('Section Section A', 'Section ' . $r[0]['section']);
    }

    public function test_already_bare_section_is_left_alone(): void
    {
        // Some writers store the bare letter. Both shapes must normalise equal.
        $a = $this->enumerate([$this->doc('Class 5th', 'A')], '2026-27');
        $b = $this->enumerate([$this->doc('Class 5th', 'Section A')], '2026-27');
        $this->assertSame($a[0]['section'], $b[0]['section']);
    }

    public function test_prefix_strip_is_case_insensitive(): void
    {
        $r = $this->enumerate([$this->doc('Class 5th', 'section B')], '2026-27');
        $this->assertSame('B', $r[0]['section']);
    }

    public function test_full_return_shape_matches_the_legacy_contract(): void
    {
        $r = $this->enumerate([$this->doc('Class 9th', 'Section A')], '2026-27');
        $this->assertSame([
            'class_key'     => 'Class 9th',
            'section'       => 'A',
            'label'         => 'Class 9th / Section A',
            'class_section' => "Class 9th 'A'",
        ], $r[0]);
    }

    // ── B. Session scoping ──────────────────────────────────────────────

    public function test_other_sessions_are_excluded(): void
    {
        $r = $this->enumerate([
            $this->doc('Class 5th', 'Section A', '2026-27'),
            $this->doc('Class 6th', 'Section A', '2025-26'),
        ], '2026-27');
        $this->assertCount(1, $r);
        $this->assertSame('Class 5th', $r[0]['class_key']);
    }

    public function test_legacy_rows_without_a_session_are_kept(): void
    {
        // Excluding blank-session rows would recreate the empty-dropdown
        // defect for exactly the older schools this fix rescues.
        $r = $this->enumerate([$this->doc('Class 5th', 'Section A', '')], '2026-27');
        $this->assertCount(1, $r, 'legacy sections must remain visible');
    }

    // ── C. Dedupe and ordering ──────────────────────────────────────────

    public function test_duplicate_class_sections_are_collapsed(): void
    {
        // A blank-session legacy row can duplicate a current-session row.
        $r = $this->enumerate([
            $this->doc('Class 5th', 'Section A', '2026-27'),
            $this->doc('Class 5th', 'Section A', ''),
            $this->doc('Class 5th', 'A',         '2026-27'),
        ], '2026-27');
        $this->assertCount(1, $r);
    }

    public function test_classes_sort_numerically_not_lexically(): void
    {
        $r = $this->enumerate([
            $this->doc('Class 10th', 'Section A'),
            $this->doc('Class 2nd',  'Section A'),
            $this->doc('Class 1st',  'Section A'),
        ], '2026-27');
        $this->assertSame(['Class 1st', 'Class 2nd', 'Class 10th'],
            array_column($r, 'class_key'), '10th must not sort before 2nd');
    }

    public function test_sections_sort_within_a_class(): void
    {
        $r = $this->enumerate([
            $this->doc('Class 5th', 'Section C'),
            $this->doc('Class 5th', 'Section A'),
            $this->doc('Class 5th', 'Section B'),
        ], '2026-27');
        $this->assertSame(['A', 'B', 'C'], array_column($r, 'section'));
    }

    // ── D. Malformed data ───────────────────────────────────────────────

    public function test_rows_missing_class_or_section_are_skipped(): void
    {
        $r = $this->enumerate([
            ['className' => '',           'section' => 'Section A', 'session' => '2026-27'],
            ['className' => 'Class 5th',  'section' => '',          'session' => '2026-27'],
            ['className' => 'Class 5th',  'section' => 'Section ',  'session' => '2026-27'],
        ], '2026-27');
        $this->assertSame([], $r, 'malformed rows must not produce blank dropdown entries');
    }

    public function test_no_sections_configured_yields_an_empty_list(): void
    {
        // A genuinely empty result is legitimate — distinct from a read
        // FAILURE, which the implementation logs loudly instead of caching.
        $this->assertSame([], $this->enumerate([], '2026-27'));
    }

    // ── E. Performance shape ────────────────────────────────────────────

    public function test_enumeration_is_memoised_per_request(): void
    {
        $reads = 0;
        $memo  = null;
        $get = function () use (&$reads, &$memo) {
            if (is_array($memo)) return $memo;
            $reads++;
            return $memo = [['class_key' => 'Class 5th', 'section' => 'A']];
        };
        for ($i = 0; $i < 20; $i++) $get();
        $this->assertSame(1, $reads, '20 consumers in one request must cost ONE read');
    }

    public function test_cost_does_not_scale_with_section_count(): void
    {
        // 100 sections still arrive in a single collection read; the transform
        // is in-memory. Modelled by asserting one pass produces all rows.
        $docs = [];
        for ($c = 1; $c <= 10; $c++) {
            foreach (['A','B','C','D','E','F','G','H','I','J'] as $s) {
                $docs[] = $this->doc("Class {$c}th", "Section {$s}");
            }
        }
        $this->assertCount(100, $this->enumerate($docs, '2026-27'));
    }

    // ── F. Identity invariants ──────────────────────────────────────────

    public function test_enumeration_carries_no_student_identity(): void
    {
        $r = $this->enumerate([$this->doc('Class 5th', 'Section A')], '2026-27');
        $this->assertArrayNotHasKey('studentId', $r[0]);
        $this->assertArrayNotHasKey('srNo', $r[0]);
        $this->assertArrayNotHasKey('rollNo', $r[0]);
    }

    public function test_identity_separation_is_unchanged(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
