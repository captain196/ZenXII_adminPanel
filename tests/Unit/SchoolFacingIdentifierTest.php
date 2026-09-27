<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  A SCHOOL MUST SEE ITS OWN REGISTER NUMBER, NOT ZENX'S INTERNAL KEY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── THE IDENTITY MODEL (traced, not assumed) ─────────────────────────────
 *   studentId        internal canonical key ("STU0183"). ZenX joins on it.
 *                    Global; never reused; not a school-facing number.
 *   srNo             the school's own register number. SCHOOL-scoped (never
 *                    session-scoped), claimed in `srNoIndex/{schoolId}_{srNo}`
 *                    under `currentStudentId`, and carried across
 *                    re-admission by `historicalStudentIds[]`.
 *   admissionNumber  the admission EVENT id ("2026/F2HH9Y"), claimed in
 *                    `admissionNoIndex` under `studentId` — a DIFFERENT owner
 *                    field from srNoIndex, which is what LC-8 was about.
 *   rollNo           class+section scoped, repeats across the school, and is
 *                    deliberately excluded from search for exactly that reason.
 *
 * ── ID-1 · THE DEFECT ────────────────────────────────────────────────────
 * `sis/students.php` — the main student roster, the screen a school lives in —
 * had ONE identifier column, labelled "ID", showing `user_id`. The internal
 * key was the only student number an administrator could see or quote.
 *
 * The backend was never the problem: `Sis::search_student()` has always
 * returned `sr_no` on every row AND has always included `srNo` in its search
 * haystack. The data arrived and the view discarded it, and the search
 * placeholder never mentioned SR No., so the capability was undiscoverable.
 *
 * ── WHY BOTH ARE SHOWN, NOT A SUBSTITUTION ───────────────────────────────
 * Most students predate SRNO enforcement and legitimately have none — 150 of
 * 153 at the time of writing, a legacy population the operator has ruled must
 * NOT be auto-numbered. Replacing the id with the SRNO would have blanked the
 * column for almost every row. Both are rendered, each labelled, so the
 * question "which identifier am I looking at?" never arises.
 */
final class SchoolFacingIdentifierTest extends TestCase
{
    private static string $view = '';
    private static string $ctrl = '';

    public static function setUpBeforeClass(): void
    {
        self::$view = (string) file_get_contents(APPPATH . 'views/sis/students.php');
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/Sis.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        self::$ctrl = (string) preg_replace('/\s+/', ' ', $out);
    }

    // ── THE DATA WAS ALWAYS THERE ────────────────────────────────────────

    public function test_the_roster_endpoint_returns_the_register_number(): void
    {
        // If this ever stops, the view below silently renders nothing and the
        // regression looks like a CSS problem.
        $this->assertStringContainsString("'sr_no' => ", self::$ctrl,
            'search_student no longer returns sr_no — the roster cannot show it');
    }

    public function test_the_roster_endpoint_searches_by_register_number(): void
    {
        $at = strpos(self::$ctrl, '$haystack = strtolower(');
        $this->assertNotFalse($at, 'the search haystack moved');
        $window = substr(self::$ctrl, $at, 260);
        $this->assertStringContainsString("srNo", $window,
            'SRNO is no longer searchable — a school that works by register '
            . 'number cannot find its own students');
    }

    public function test_roll_number_is_still_excluded_from_search(): void
    {
        // Deliberate: roll numbers repeat across the school, so matching them
        // would imply a global uniqueness that does not exist.
        $at = strpos(self::$ctrl, '$haystack = strtolower(');
        $window = substr(self::$ctrl, $at, 260);
        $this->assertStringNotContainsString("rollNo", $window,
            'roll number was added to search — it is class+section scoped and '
            . 'repeats, so it cannot identify a student');
    }

    // ── THE VIEW MUST RENDER IT ──────────────────────────────────────────

    public function test_the_roster_renders_the_register_number(): void
    {
        $this->assertStringContainsString('s.sr_no', self::$view,
            'the roster still discards sr_no and shows only the internal key');
    }

    public function test_both_identifiers_are_labelled(): void
    {
        // The whole point: never leave an administrator guessing which number
        // is on screen.
        $this->assertStringContainsString('School register number', self::$view,
            'the register number is rendered without a label');
        $this->assertStringContainsString('Internal Student ID', self::$view,
            'the internal key is rendered without a label');
        // 2026-09-13 — EXPECTED CHANGE. The two identifiers were split into two
        // columns at the operator's request, so a single combined header no longer
        // exists. The requirement is unchanged and is asserted more strictly than
        // before: BOTH identifiers must be named in the header row.
        //
        // This assertion previously passed against the new view purely because a
        // source comment still contained the old header text — a false green of
        // exactly the kind this suite exists to prevent. Matching on the <th>
        // markup, not a bare string, closes that hole.
        $this->assertMatchesRegularExpression('/<th[^>]*>SR No\.<\/th>/', self::$view,
            'the roster has no column headed "SR No."');
        $this->assertMatchesRegularExpression('/<th[^>]*>Student ID<\/th>/', self::$view,
            'the roster has no column headed "Student ID"');
    }

    public function test_a_student_without_a_register_number_still_shows_an_identifier(): void
    {
        // 150 of 153 students have no SRNO and must not be auto-numbered, so the
        // internal id is rendered unconditionally and the SR badge only when present.
        //
        // 2026-09-13 — EXPECTED CHANGE. With the identifiers now in SEPARATE columns,
        // the old empty-string fallback would leave a blank cell in a column of its
        // own, which reads as a rendering fault rather than an unassigned value. The
        // fallback is now an explicit em-dash. The REQUIREMENT is unchanged — a
        // student without a register number must still show an identifier, and must
        // never show an empty SR badge — only the spelling of the fallback moved.
        $this->assertMatchesRegularExpression('/\$\{s\.sr_no\s/', self::$view,
            'the SR badge is not rendered conditionally');
        $this->assertStringContainsString('nsa-id-none', self::$view,
            'a student with no register number gets no explicit "not set" mark, so the '
            . 'SR No. column renders blank and reads as broken');

        // The internal id is rendered UNCONDITIONALLY, on its own line, so
        // the cell is never blank.
        $line = '';
        foreach (explode("\n", self::$view) as $l) {
            if (strpos($l, 'Internal Student ID') !== false) { $line = $l; break; }
        }
        $this->assertNotSame('', $line, 'the internal-id span is gone');
        $this->assertStringContainsString('esc(s.user_id)', $line);
        $this->assertStringNotContainsString('s.sr_no ? `<span', $line,
            'the internal id is rendered only when an SR number exists — a student '
            . 'without one would have a blank identifier cell');
    }

    public function test_the_search_affordance_mentions_the_register_number(): void
    {
        $this->assertMatchesRegularExpression('/placeholder="Search by [^"]*SR No\./', self::$view,
            'the search box does not tell the school it can search by SR No., so a '
            . 'capability the backend already has stays invisible');
    }

    // ── THE INTERNAL KEY REMAINS THE JOIN KEY ────────────────────────────

    public function test_actions_still_operate_on_the_internal_id(): void
    {
        // Presentation changed; identity did not. Every action must still post
        // the studentId — routing a lifecycle transition by a school-facing
        // number would be a genuine correctness regression, not a cosmetic one.
        foreach (['changeStatus(event', 'withdrawStudent(event', 'deleteStudent(event'] as $action) {
            $at = strpos(self::$view, $action);
            $this->assertNotFalse($at, "{$action} moved");
            $window = substr(self::$view, $at, 120);
            $this->assertStringContainsString('s.user_id', $window,
                "{$action} no longer passes the internal student id — a lifecycle "
                . 'transition must never be routed by a display identifier');
        }
    }
}
