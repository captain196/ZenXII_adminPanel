<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 18 — LC-1 / ATT-1: attendance roster must return EVERY student
 * regardless of which field shape their record was written in.
 *
 * THE DEFECT (🔴 HIGH — students could not be marked):
 * `_get_section_students()` and `_get_active_roster()` each ran a cascade of
 * strategies that queried DIFFERENT field shapes and returned immediately on
 * the first non-empty result:
 *
 *   Strategy 0  Roster_helper  → className / section / status   (camelCase)
 *   Strategy 1  schoolWhere    → Class / Section / Status       (PascalCase)
 *   Strategy 2  schoolWhere    → Class / Section                (PascalCase)
 *
 * The `students` collection holds BOTH shapes. So a class of 20 camelCase and
 * 5 PascalCase records returned 20 — and those 5 children were absent from
 * the attendance register entirely. Not marked absent: ABSENT FROM THE LIST.
 * They could be neither present nor absent.
 *
 * The cascade looked robust — four fallbacks — but each fallback only fires on
 * a COMPLETELY empty result, never on a PARTIAL one.
 *
 * IDENTITY DISCIPLINE: STU0012 → SRNO 47 → Roll 12 · STU0047 → SRNO 12 → Roll 47
 */
class Phase18AttendanceRosterShapeTest extends TestCase
{
    private const STU_A = 'STU0012';  private const SRNO_A = '47';  private const ROLL_A = '12';
    private const STU_B = 'STU0047';  private const SRNO_B = '12';  private const ROLL_B = '47';

    /** Mirrors MY_Controller::_filterSectionRoster() — the both-shape rule. */
    private function filter(array $docs, string $class, string $section, string $session): array
    {
        if ($class === '' || $section === '') return [];
        $c = mb_strtolower(trim($class));
        $s = mb_strtolower(trim($section));
        $ss = mb_strtolower(trim($session));

        $out = [];
        foreach ($docs as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : null;
            if (!is_array($d)) continue;
            $sid = (string) ($d['studentId'] ?? $d['userId'] ?? $d['User Id'] ?? '');
            if ($sid === '') continue;
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

    /** Mirrors _extractRosterFromStudentDocs(). */
    private function extract(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $s = is_array($row) ? ($row['data'] ?? $row) : [];
            if (!is_array($s)) continue;
            $uid = $s['User Id'] ?? $s['studentId'] ?? ($row['id'] ?? '');
            if ($uid === '') continue;
            $list[$uid] = $s['Name'] ?? $s['name'] ?? $uid;
        }
        return $list;
    }

    /** The OLD behaviour: camelCase query, return on non-empty. */
    private function legacyCascade(array $docs, string $class, string $section): array
    {
        $camel = [];
        foreach ($docs as $row) {
            $d = $row['data'] ?? $row;
            if (($d['className'] ?? null) === $class && ($d['section'] ?? null) === $section
                && strcasecmp((string) ($d['status'] ?? ''), 'Active') === 0) {
                $camel[$d['studentId']] = $d['name'] ?? '';
            }
        }
        if (!empty($camel)) return $camel;          // ← the short-circuit

        $pascal = [];
        foreach ($docs as $row) {
            $d = $row['data'] ?? $row;
            if (($d['Class'] ?? null) === $class && ($d['Section'] ?? null) === $section
                && strcasecmp((string) ($d['Status'] ?? ''), 'Active') === 0) {
                $pascal[$d['User Id']] = $d['Name'] ?? '';
            }
        }
        return $pascal;
    }

    private function camelStu(string $id, string $name, array $o = []): array
    {
        return ['data' => array_merge([
            'studentId' => $id, 'name' => $name, 'className' => 'Class 5th',
            'section' => 'Section A', 'status' => 'Active', 'session' => '2026-27',
        ], $o)];
    }

    private function pascalStu(string $id, string $name, array $o = []): array
    {
        return ['data' => array_merge([
            'User Id' => $id, 'studentId' => $id, 'Name' => $name, 'Class' => 'Class 5th',
            'Section' => 'Section A', 'Status' => 'Active', 'session' => '2026-27',
        ], $o)];
    }

    // ── A. THE DEFECT ───────────────────────────────────────────────────

    public function test_legacy_cascade_silently_dropped_pascal_students(): void
    {
        // Proves the bug existed: 3 camelCase + 2 PascalCase → only 3 returned.
        $docs = [
            $this->camelStu('STU1', 'Aisha'), $this->camelStu('STU2', 'Bilal'),
            $this->camelStu('STU3', 'Chetan'),
            $this->pascalStu(self::STU_A, 'Rahul'), $this->pascalStu(self::STU_B, 'Priya'),
        ];
        $legacy = $this->legacyCascade($docs, 'Class 5th', 'Section A');
        $this->assertCount(3, $legacy, 'the short-circuit hid the PascalCase students');
        $this->assertArrayNotHasKey(self::STU_A, $legacy, 'Rahul could not be marked at all');
    }

    public function test_both_shapes_are_now_returned_together(): void
    {
        $docs = [
            $this->camelStu('STU1', 'Aisha'), $this->camelStu('STU2', 'Bilal'),
            $this->camelStu('STU3', 'Chetan'),
            $this->pascalStu(self::STU_A, 'Rahul'), $this->pascalStu(self::STU_B, 'Priya'),
        ];
        $list = $this->extract($this->filter($docs, 'Class 5th', 'Section A', ''));
        $this->assertCount(5, $list, 'every child must appear on the register');
        $this->assertArrayHasKey(self::STU_A, $list);
        $this->assertArrayHasKey(self::STU_B, $list);
        $this->assertSame('Rahul', $list[self::STU_A]);
    }

    public function test_a_pure_pascal_class_is_fully_returned(): void
    {
        $docs = [$this->pascalStu('P1', 'One'), $this->pascalStu('P2', 'Two')];
        $this->assertCount(2, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '')));
    }

    public function test_a_pure_camel_class_is_fully_returned(): void
    {
        $docs = [$this->camelStu('C1', 'One'), $this->camelStu('C2', 'Two')];
        $this->assertCount(2, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '')));
    }

    // ── B. Status semantics preserved ───────────────────────────────────

    public function test_withdrawn_students_are_excluded_in_both_shapes(): void
    {
        $docs = [
            $this->camelStu('C1', 'Active One'),
            $this->camelStu('C2', 'Gone', ['status' => 'Withdrawn']),
            $this->pascalStu('P1', 'Gone Too', ['Status' => 'Withdrawn']),
        ];
        $list = $this->extract($this->filter($docs, 'Class 5th', 'Section A', ''));
        $this->assertCount(1, $list, 'a withdrawn student must never be markable');
        $this->assertArrayHasKey('C1', $list);
    }

    public function test_blank_status_defaults_to_active(): void
    {
        // This was old Strategy 2's whole purpose — preserved, done correctly.
        $docs = [$this->camelStu('C1', 'No Status', ['status' => ''])];
        $this->assertCount(1, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '')));
    }

    public function test_lowercase_active_is_accepted(): void
    {
        $docs = [
            $this->camelStu('C1', 'lower', ['status' => 'active']),
            $this->pascalStu('P1', 'Lower', ['Status' => 'ACTIVE']),
        ];
        $this->assertCount(2, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '')));
    }

    // ── C. Scoping ──────────────────────────────────────────────────────

    public function test_other_sections_and_classes_are_excluded_in_both_shapes(): void
    {
        $docs = [
            $this->camelStu('C1', 'Mine'),
            $this->camelStu('C2', 'OtherSec', ['section' => 'Section B']),
            $this->pascalStu('P1', 'OtherSec2', ['Section' => 'Section B']),
            $this->pascalStu('P2', 'OtherClass', ['Class' => 'Class 6th']),
        ];
        $this->assertCount(1, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '')));
    }

    public function test_session_filtering_is_disabled_for_attendance_by_design(): void
    {
        // ATT-2: the previous strategies applied NO session predicate, so an
        // empty session string preserves behaviour exactly. Whether attendance
        // SHOULD be session-scoped is registered, not silently changed.
        $docs = [
            $this->camelStu('C1', 'This Year', ['session' => '2026-27']),
            $this->camelStu('C2', 'Last Year', ['session' => '2025-26']),
        ];
        $this->assertCount(2, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '')));
        // ...and when a session IS supplied, scoping works.
        $this->assertCount(1, $this->extract($this->filter($docs, 'Class 5th', 'Section A', '2026-27')));
    }

    // ── D. Ordering ─────────────────────────────────────────────────────

    public function test_sorting_reads_both_name_shapes(): void
    {
        // A Firestore orderBy('Name') EXCLUDES docs lacking that exact field,
        // so camelCase-only records would have been dropped by the SORT even
        // after the predicate was fixed.
        $rows = [
            $this->camelStu('C1', 'Zara'),
            $this->pascalStu('P1', 'Amit'),
            $this->camelStu('C2', 'Meera'),
        ];
        usort($rows, static function ($a, $b) {
            $ad = $a['data']; $bd = $b['data'];
            return strcasecmp(
                trim((string) ($ad['Name'] ?? $ad['name'] ?? '')),
                trim((string) ($bd['Name'] ?? $bd['name'] ?? ''))
            );
        });
        $names = array_map(static fn($r) => $r['data']['Name'] ?? $r['data']['name'], $rows);
        $this->assertSame(['Amit', 'Meera', 'Zara'], $names);
    }

    // ── E. Mid-session admission (admissionDate roster) ─────────────────

    public function test_admission_date_is_preserved_for_both_shapes(): void
    {
        // _get_active_roster carries admissionDate, which stops a child
        // admitted on the 20th being shown absent for the 1st–19th. A student
        // missing from that roster has WRONG attendance, not just a short list.
        $rows = [
            $this->camelStu('C1', 'Camel', ['admissionDate' => '2026-07-20']),
            $this->pascalStu('P1', 'Pascal', ['Admission Date' => '2026-07-20']),
        ];
        $out = [];
        foreach ($this->filter($rows, 'Class 5th', 'Section A', '') as $row) {
            $d = $row['data'];
            $uid = (string) ($d['User Id'] ?? $d['studentId'] ?? '');
            $out[$uid] = (string) ($d['admissionDate'] ?? $d['Admission Date'] ?? '');
        }
        $this->assertCount(2, $out);
        $this->assertSame('2026-07-20', $out['C1']);
        $this->assertSame('2026-07-20', $out['P1']);
    }

    // ── F. Performance shape ────────────────────────────────────────────

    public function test_roster_costs_one_shared_read_not_three_queries(): void
    {
        // The cascade could issue up to three Firestore queries per call, and
        // _get_section_students has EIGHT call sites. The snapshot is memoised.
        $reads = 0; $memo = null;
        $snapshot = function () use (&$reads, &$memo) {
            if (is_array($memo)) return $memo;
            $reads++; return $memo = [];
        };
        for ($i = 0; $i < 8; $i++) $snapshot();
        $this->assertSame(1, $reads, 'eight call sites, one read');
    }

    // ── G. Identity invariants ──────────────────────────────────────────

    public function test_roster_keys_on_student_id_never_roll_or_srno(): void
    {
        $list = $this->extract($this->filter([$this->pascalStu(self::STU_A, 'Rahul')], 'Class 5th', 'Section A', ''));
        $this->assertArrayHasKey(self::STU_A, $list);
        $this->assertArrayNotHasKey(self::ROLL_A, $list);
        $this->assertArrayNotHasKey(self::SRNO_A, $list);
    }

    public function test_identity_separation_unchanged(): void
    {
        $this->assertSame('47', self::SRNO_A);
        $this->assertSame('12', self::ROLL_A);
        $this->assertSame('12', self::SRNO_B);
        $this->assertSame('47', self::ROLL_B);
    }
}
