<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Phase 20.1 — P20-F1: section-delete safety.
 *
 * THE DEFECT: all three section/class delete guards in School_config decided
 * "is this section empty?" with
 *
 *     schoolWhere('students', [['sectionKey','==',$k], ['status','==','Active']])
 *
 * A Firestore equality predicate binds to ONE exact field name. The `students`
 * collection carries BOTH legacy PascalCase (`Status`) and canonical camelCase
 * shapes, and legacy documents may carry no `sectionKey` composite at all.
 * Such a student is invisible to that predicate — so the guard saw zero
 * students, called the section empty, and DELETED A SECTION HOLDING CHILDREN.
 *
 * Sites fixed:
 *   1. School_config::delete_section()      — single section delete
 *   2. School_config::bulk_save_sections()  — matrix toggle remove
 *   3. School_config::soft_delete_class()   — found in the Part 8 sweep;
 *                                             removes the whole class
 *
 * SCOPE NOTE — this file tests the DELETE DECISION. The roster rule itself
 * (session scoping, blank-session tolerance, withdrawn exclusion, dual field
 * shapes) is already covered by Phase166SectionRosterAndDeadFallbackTest;
 * those cases are not duplicated here. What is new is the two-signal,
 * fail-closed decision built on top of it.
 *
 * IDENTITY DISCIPLINE (SRNO sweep, Part 6): SRNO and Roll are deliberately
 * crossed so any substitution shows up as a wrong value, not a coincidence.
 *   STU0012 → SRNO 47 → Roll 12   ·   STU0047 → SRNO 12 → Roll 47
 */
class Phase201SectionDeleteSafetyTest extends TestCase
{
    private const CLASS_N = 'Class 5th';
    private const SECTION = 'Section A';
    private const SESSION = '2026-27';

    // ── canonical rule under test ────────────────────────────────────────

    /** Mirrors MY_Controller::_filterSectionRoster() (:1258-1293). */
    private function filter(array $docs, string $class, string $section, string $session): array
    {
        if ($class === '' || $section === '') return [];
        $c  = mb_strtolower(trim($class));
        $s  = mb_strtolower(trim($section));
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

    /** The OLD guard, kept so the regression is demonstrated, not asserted. */
    private function vulnerableGuard(array $docs, string $composite): int
    {
        $n = 0;
        foreach ($docs as $row) {
            $d = is_array($row) ? ($row['data'] ?? $row) : [];
            if (($d['sectionKey'] ?? null) === $composite && ($d['status'] ?? null) === 'Active') $n++;
        }
        return $n;
    }

    /**
     * Mirrors School_config::_section_active_students().
     * $snapshotThrows / $indexedThrows simulate a source failing.
     */
    private function decide(
        array $snapshot,
        array $indexedRows,
        bool $snapshotThrows = false,
        bool $indexedThrows = false
    ): array {
        $canonical = null;
        $indexed   = null;

        if (!$snapshotThrows) {
            $canonical = count($this->filter($snapshot, self::CLASS_N, self::SECTION, self::SESSION));
        }
        if (!$indexedThrows) {
            $indexed = count($this->filter($indexedRows, self::CLASS_N, self::SECTION, self::SESSION));
        }

        return [
            'count'      => max((int) $canonical, (int) $indexed),
            'determined' => ($canonical !== null && $indexed !== null),
        ];
    }

    /** A section is deletable ONLY when determined AND empty. */
    private function isDeletable(array $d): bool
    {
        return $d['determined'] === true && $d['count'] === 0;
    }

    private function stu(array $o = []): array
    {
        return ['data' => array_merge([
            'studentId'  => 'STU0012',
            'className'  => self::CLASS_N,
            'section'    => self::SECTION,
            'session'    => self::SESSION,
            'status'     => 'Active',
            'sectionKey' => self::CLASS_N . '/' . self::SECTION,
            'srNo'       => '47',
            'rollNo'     => '12',
        ], $o)];
    }

    private function composite(): string { return self::CLASS_N . '/' . self::SECTION; }

    // ── T-1 ──────────────────────────────────────────────────────────────

    public function test_T1_empty_section_is_deletable(): void
    {
        $d = $this->decide([], []);
        $this->assertTrue($this->isDeletable($d), 'A genuinely empty section must remain deletable.');
        $this->assertSame(0, $d['count']);
    }

    // ── T-2 ──────────────────────────────────────────────────────────────

    public function test_T2_camelcase_active_student_blocks_delete(): void
    {
        $rows = [$this->stu()];
        $d = $this->decide($rows, $rows);
        $this->assertFalse($this->isDeletable($d));
        $this->assertSame(1, $d['count']);
    }

    // ── T-3 — the core regression ────────────────────────────────────────

    public function test_T3_pascalcase_status_student_blocks_delete(): void
    {
        // Legacy shape only: `Status`, no `status`.
        $legacy = $this->stu(['Status' => 'Active']);
        unset($legacy['data']['status']);
        $rows = [$legacy];

        // The OLD guard could not see this student…
        $this->assertSame(0, $this->vulnerableGuard($rows, $this->composite()),
            'Precondition: the old predicate is blind to the PascalCase shape.');

        // …the fixed decision does.
        $d = $this->decide($rows, $rows);
        $this->assertFalse($this->isDeletable($d),
            'A PascalCase-Status student must block deletion.');
        $this->assertSame(1, $d['count']);
    }

    // ── T-4 ──────────────────────────────────────────────────────────────

    public function test_T4_mixed_shape_students_all_block_delete(): void
    {
        $legacy = $this->stu(['studentId' => 'STU0047', 'srNo' => '12', 'rollNo' => '47', 'Status' => 'Active']);
        unset($legacy['data']['status']);
        $rows = [$this->stu(), $legacy];

        $this->assertSame(1, $this->vulnerableGuard($rows, $this->composite()),
            'Precondition: the old predicate saw only the camelCase student.');

        $d = $this->decide($rows, $rows);
        $this->assertFalse($this->isDeletable($d));
        $this->assertSame(2, $d['count'], 'Both shapes must be counted.');
    }

    // ── T-5 — missing sectionKey ─────────────────────────────────────────

    public function test_T5_student_without_sectionKey_still_blocks_delete(): void
    {
        $noKey = $this->stu();
        unset($noKey['data']['sectionKey']);

        // Indexed query cannot return it — it has no sectionKey to match.
        $this->assertSame(0, $this->vulnerableGuard([$noKey], $this->composite()),
            'Precondition: a student with no sectionKey is invisible to the indexed predicate.');

        // Snapshot signal (matches on className/section) catches it.
        $d = $this->decide([$noKey], []);
        $this->assertFalse($this->isDeletable($d),
            'A student linked by className/section but lacking sectionKey must block deletion.');
        $this->assertSame(1, $d['count']);
    }

    // ── T-6 ──────────────────────────────────────────────────────────────

    public function test_T6_only_withdrawn_students_leaves_section_deletable(): void
    {
        $rows = [
            $this->stu(['status' => 'Withdrawn']),
            $this->stu(['studentId' => 'STU0047', 'status' => 'Inactive']),
        ];
        $d = $this->decide($rows, $rows);
        $this->assertTrue($this->isDeletable($d),
            'Withdrawn/Inactive students do not occupy a seat, so they do not block deletion.');
    }

    // ── T-7 ──────────────────────────────────────────────────────────────

    public function test_T7_student_in_another_session_does_not_block(): void
    {
        $rows = [$this->stu(['session' => '2025-26'])];
        $d = $this->decide($rows, $rows);
        $this->assertTrue($this->isDeletable($d),
            'A 2025-26 student must not block a 2026-27 section (Phase 20.1 Part 4).');
    }

    public function test_T7b_blank_session_legacy_student_still_blocks(): void
    {
        $rows = [$this->stu(['session' => ''])];
        $d = $this->decide($rows, $rows);
        $this->assertFalse($this->isDeletable($d),
            'A blank session is a legacy document and must be treated as belonging here.');
    }

    // ── T-8 — fail closed ────────────────────────────────────────────────

    public function test_T8_snapshot_failure_fails_closed(): void
    {
        $d = $this->decide([], [], true, false);
        $this->assertFalse($d['determined']);
        $this->assertFalse($this->isDeletable($d),
            'An unverifiable roster must never be treated as an empty section.');
    }

    public function test_T8b_indexed_failure_fails_closed(): void
    {
        $d = $this->decide([], [], false, true);
        $this->assertFalse($this->isDeletable($d));
    }

    public function test_T8c_both_sources_failing_fails_closed(): void
    {
        $d = $this->decide([], [], true, true);
        $this->assertFalse($this->isDeletable($d));
    }

    public function test_T8d_empty_snapshot_is_rescued_by_the_indexed_signal(): void
    {
        // _cachedStudentDocsForRollCheck() returns [] on error rather than
        // throwing (MY_Controller:1404), so an empty snapshot is
        // indistinguishable from "no students". The independent indexed
        // signal is what stops that from reading as an empty section.
        $rows = [$this->stu()];
        $d = $this->decide([], $rows);
        $this->assertFalse($this->isDeletable($d),
            'A silently-empty snapshot must not by itself authorise deletion.');
        $this->assertSame(1, $d['count']);
    }

    public function test_T8e_malformed_rows_do_not_fatal_and_do_not_authorise_delete(): void
    {
        $rows = [null, 'garbage', ['data' => null], ['data' => ['studentId' => '']], $this->stu()];
        $d = $this->decide($rows, $rows);
        $this->assertSame(1, $d['count'], 'Malformed rows are skipped; the real student still counts.');
        $this->assertFalse($this->isDeletable($d));
    }

    // ── T-9 — all three sites share the one definition ───────────────────

    public function test_T9_all_delete_paths_use_the_canonical_helper(): void
    {
        $src = $this->strippedSource(
            __DIR__ . '/../../application/controllers/School_config.php');

        // Call sites only — `$this->` excludes the declaration itself.
        $this->assertSame(3, substr_count($src, '$this->_section_active_students('),
            'All three delete guards (delete_section, bulk_save_sections, '
            . 'soft_delete_class) must call the one canonical helper.');
        $this->assertSame(1, substr_count($src, 'private function _section_active_students('),
            'There must be exactly ONE definition of section emptiness.');

        // The helper itself must delegate, not re-derive.
        $this->assertStringContainsString('_filterSectionRoster(', $src,
            'The helper must reuse the canonical roster rule.');
        $this->assertStringContainsString('_cachedStudentDocsForRollCheck(', $src,
            'The helper must reuse the shared snapshot, not issue a per-student query.');
    }

    public function test_T9b_no_vulnerable_students_predicate_survives_outside_the_helper(): void
    {
        $src = $this->strippedSource(
            __DIR__ . '/../../application/controllers/School_config.php');

        // Scoped to the SECTION shape: a `sectionKey ==` predicate paired with
        // a bare `status ==` on students. Exactly one may survive — signal B
        // inside _section_active_students(), whose rows are then passed
        // through _filterSectionRoster() before being trusted.
        //
        // (A `streamKey`-based variant also exists in delete_stream(); it is
        // registered as P20-F8 and deliberately NOT in scope here, because the
        // canonical roster rule matches className/section and cannot express a
        // stream membership test. Fixing it needs new logic this phase forbids.)
        $this->assertSame(
            1,
            preg_match_all(
                "/\\['sectionKey',\\s*'==',[^\\]]*\\],\\s*\\['status',\\s*'==',\\s*'Active'\\]/",
                $src
            ),
            'Exactly one sectionKey+status predicate may remain — signal B inside '
            . '_section_active_students(), which is re-filtered canonically.'
        );
    }

    // ── T-10 — SRNO integrity ────────────────────────────────────────────

    public function test_T10_delete_decision_never_touches_srno_or_roll(): void
    {
        $rows = [$this->stu(), $this->stu(['studentId' => 'STU0047', 'srNo' => '12', 'rollNo' => '47'])];
        $before = json_encode($rows);

        $this->decide($rows, $rows);

        $this->assertSame($before, json_encode($rows),
            'The delete decision is read-only over student documents.');
        $this->assertSame('47', $rows[0]['data']['srNo'], 'STU0012 keeps SRNO 47.');
        $this->assertSame('12', $rows[0]['data']['rollNo'], 'STU0012 keeps Roll 12.');
        $this->assertSame('12', $rows[1]['data']['srNo'], 'STU0047 keeps SRNO 12.');
        $this->assertSame('47', $rows[1]['data']['rollNo'], 'STU0047 keeps Roll 47.');
    }

    public function test_T10b_no_delete_guard_reads_srno_as_a_section_key(): void
    {
        $src = $this->strippedSource(
            __DIR__ . '/../../application/controllers/School_config.php');

        // Scoped to the helper body — School_config legitimately references
        // rollNo elsewhere (the rollover path carries roll numbers forward),
        // so a whole-file assertion would be meaningless. What must hold is
        // that the emptiness decision itself is identity-blind.
        $start = strpos($src, 'private function _section_active_students(');
        $this->assertNotFalse($start, 'Canonical helper not found.');
        $body = substr($src, $start, 2600);

        $this->assertSame(0, preg_match_all('/srNo|sr_no|admissionNo|rollNo/i', $body),
            'The section-emptiness decision must not reference student identity fields.');
        $this->assertStringNotContainsString('->update(', $body, 'The helper must not write.');
        $this->assertStringNotContainsString('->remove(', $body, 'The helper must not delete.');
    }

    /**
     * Source with comments stripped. Without this, the assertions above match
     * the explanatory comments that describe the very pattern being banned.
     */
    private function strippedSource(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }
        return $out;
    }
}
