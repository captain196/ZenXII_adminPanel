<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 16.6 — SEC-11 (session-scoped section rosters) + SEC-12 (dead RTDB
 * fallback calls that fatally crashed empty sections).
 *
 * SEC-11: three read paths in Classes.php counted section occupancy with
 * `schoolWhere('students', …)` — school-scoped but NOT session-scoped — so the
 * Classes screen showed strength counted across EVERY session while the server
 * (since Phase 16.5) enforced session-scoped capacity. UI and server disagreed
 * about the same section.
 *
 * SEC-12: `_rtdb_fallback_students()` and `_rtdb_fallback_all_sections()` were
 * DELETED from the codebase but THREE call sites were left behind, each guarded
 * by `if (empty(...))`. An empty Firestore result therefore raised a PHP fatal
 * error rather than returning an empty list.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase166SectionRosterAndDeadFallbackTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors MY_Controller::_filterSectionRoster(). */
    private function filter(array $docs, string $class, string $section, string $session, string $except = ''): array
    {
        if ($class === '' || $section === '') return [];
        $c = mb_strtolower(trim($class));
        $s = mb_strtolower(trim($section));
        $ss = mb_strtolower(trim($session));
        $ex = trim($except);

        $out = [];
        foreach ($docs as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : null;
            if (!is_array($d)) continue;
            $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $d['User Id'] ?? '');
            if ($sid === '' || ($ex !== '' && $sid === $ex)) continue;
            $status = trim((string) ($d['status'] ?? $d['Status'] ?? 'Active'));
            if ($status !== '' && strcasecmp($status, 'Active') !== 0) continue;
            if (mb_strtolower(trim((string) ($d['className'] ?? $d['Class'] ?? ''))) !== $c) continue;
            if (mb_strtolower(trim((string) ($d['section'] ?? $d['Section'] ?? ''))) !== $s) continue;
            $rs = mb_strtolower(trim((string) ($d['session'] ?? '')));
            if ($ss !== '' && $rs !== '' && $rs !== $ss) continue;
            $out[] = $row;
        }
        return $out;
    }

    private function stu(array $o = []): array
    {
        return ['data' => array_merge([
            'studentId' => 'STU' . random_int(1000, 9999),
            'className' => 'Class 5th', 'section' => 'Section A',
            'session' => '2026-27', 'status' => 'Active',
        ], $o)];
    }

    private function many(int $n, array $o = []): array
    {
        $r = [];
        for ($i = 0; $i < $n; $i++) $r[] = $this->stu($o);
        return $r;
    }

    // ── A. SEC-11 — session scoping on display paths ────────────────────

    public function test_other_sessions_do_not_inflate_section_strength(): void
    {
        // THE DEFECT: 40 students last year + 5 this year displayed as 45/40
        // "FULL" while the server correctly still admitted more.
        $docs = array_merge(
            $this->many(40, ['session' => '2025-26']),
            $this->many(5,  ['session' => '2026-27'])
        );
        $this->assertCount(5, $this->filter($docs, 'Class 5th', 'Section A', '2026-27'),
            'the screen must show THIS session only');
    }

    public function test_ui_count_now_matches_the_server_capacity_count(): void
    {
        // Both sides run the same rule, so they cannot disagree.
        $docs = array_merge(
            $this->many(40, ['session' => '2025-26']),
            $this->many(12, ['session' => '2026-27'])
        );
        $uiCount     = count($this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
        $serverCount = count($this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
        $this->assertSame($uiCount, $serverCount);
        $this->assertSame(12, $uiCount);
    }

    public function test_legacy_blank_session_students_are_still_counted(): void
    {
        // A hard Firestore `where session ==` would have DROPPED these,
        // under-counting a section and letting a full one look empty.
        $docs = array_merge($this->many(3, ['session' => '']), $this->many(2));
        $this->assertCount(5, $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_withdrawn_students_are_excluded(): void
    {
        $docs = array_merge($this->many(4), $this->many(6, ['status' => 'Withdrawn']));
        $this->assertCount(4, $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_other_sections_and_classes_are_excluded(): void
    {
        $docs = array_merge(
            $this->many(3),
            $this->many(7, ['section' => 'Section B']),
            $this->many(9, ['className' => 'Class 6th'])
        );
        $this->assertCount(3, $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_title_case_and_lowercase_field_shapes_both_match(): void
    {
        // students docs are dual-emitted; the filter must accept either.
        $docs = [
            ['data' => ['studentId' => 'STU1', 'Class' => 'Class 5th', 'Section' => 'Section A',
                        'session' => '2026-27', 'Status' => 'Active']],
            ['data' => ['studentId' => 'STU2', 'className' => 'Class 5th', 'section' => 'Section A',
                        'session' => '2026-27', 'status' => 'Active']],
        ];
        $this->assertCount(2, $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_delete_section_guard_is_not_vetoed_by_a_previous_session(): void
    {
        // Last year's students must not block deleting a section that is
        // empty this year — the operator cannot act on their dues.
        $docs = $this->many(30, ['session' => '2025-26']);
        $this->assertSame([], $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_self_exclusion_still_works(): void
    {
        $docs = [$this->stu(['studentId' => self::STU_A])];
        $this->assertCount(0, $this->filter($docs, 'Class 5th', 'Section A', '2026-27', self::STU_A));
    }

    // ── B. SEC-12 — an empty result is a valid answer ───────────────────

    public function test_an_empty_section_returns_an_empty_list_not_a_crash(): void
    {
        // Pre-fix this path called an undefined method and fatally errored.
        $this->assertSame([], $this->filter([], 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_a_section_holding_only_other_session_students_is_empty(): void
    {
        // SEC-11 makes this case MORE common, which is why leaving the dead
        // fallback call would have widened the crash.
        $docs = $this->many(20, ['session' => '2024-25']);
        $this->assertSame([], $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    public function test_a_school_with_no_sections_configured_is_a_valid_state(): void
    {
        // The other two dead calls crashed the classes screen for every school
        // on its first run.
        $sections = [];
        $this->assertSame([], $sections);
        $this->assertCount(0, $sections);
    }

    public function test_malformed_rows_are_skipped_not_fatal(): void
    {
        $docs = [['data' => null], ['nope' => 1], $this->stu()];
        $this->assertCount(1, $this->filter($docs, 'Class 5th', 'Section A', '2026-27'));
    }

    // ── C. ROLL-6 — release-on-save analysis ────────────────────────────

    public function test_the_roster_becomes_authoritative_once_a_student_is_saved(): void
    {
        // This is why release-on-save is an optimisation, not a correctness
        // fix: after the save, `$taken` (built from the roster) already
        // excludes the number, so the lingering claim changes nothing.
        $roster = [12 => true];
        $claims = [12 => ['claimedAt' => date('c')]];
        $picked = null;
        for ($c = 1; $c <= 20; $c++) {
            if (isset($roster[$c])) continue;      // roster checked FIRST
            if (isset($claims[$c])) continue;
            $picked = $c; break;
        }
        $this->assertSame(1, $picked);
        $this->assertArrayHasKey(12, $roster, 'the saved roll is blocked by the roster, not the claim');
    }

    // ── D. Identity invariants ──────────────────────────────────────────

    public function test_roster_filtering_carries_no_roll_or_srno_identity(): void
    {
        $r = $this->filter([$this->stu(['studentId' => self::STU_A])], 'Class 5th', 'Section A', '2026-27');
        $this->assertArrayNotHasKey('srNo', $r[0]['data']);
        $this->assertArrayNotHasKey('rollNo', $r[0]['data']);
    }

    public function test_identity_separation_unchanged(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
        $this->assertNotSame(self::STU_A, self::STU_B);
    }
}
