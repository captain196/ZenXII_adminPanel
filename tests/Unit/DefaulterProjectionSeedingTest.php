<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  CONS-2 — A STUDENT WITH DEMANDS BUT NO DEFAULTER PROJECTION
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `Fee_lifecycle::assignInitialFees()` writes `feeDemands` but emits NO
 * defaulter event. Every caller therefore has to seed the projection itself,
 * and this has been fixed once per path as each was discovered:
 *
 *   Phase 3D  direct admission          — "verified leak: STU0004, STU0005"
 *   CONS-1    single-student generation — "STU0184 … defaulter record still
 *                                          read Rs.11,200 instead of Rs.10,480"
 *   CONS-2    bulk import + enrolment   — this file
 *
 * Four production callers exist. Two had the fix; two did not.
 *
 * ── WHY IT IS EASY TO MISS ────────────────────────────────────────────────
 * Nothing looks wrong. The family's dues are correct everywhere that reads
 * `feeDemands`, which includes the Parent app. What breaks is the school's own
 * DEFAULTER REPORT, which reads the projection — the student simply is not
 * listed. A school cannot chase a student it cannot see, and no error is raised.
 *
 * On a bulk import that is the worst version: an entire intake is invisible at
 * once.
 */
class DefaulterProjectionSeedingTest extends TestCase
{
    private static string $sis = '';

    public static function setUpBeforeClass(): void
    {
        self::$sis = (string) file_get_contents(APPPATH . 'controllers/Sis.php');
    }

    /** Executable code only — the prose below explains the defect by name. */
    private function executable(): string
    {
        $src = preg_replace('!/\*.*?\*/!s', '', self::$sis);
        $src = preg_replace('!^\s*//.*$!m', '', (string) $src);
        return (string) preg_replace('!^\s*\*.*$!m', '', (string) $src);
    }

    // ══ CONTROL ═════════════════════════════════════════════════════════════

    public function test_control_the_stripper_keeps_code_and_drops_prose(): void
    {
        $src = $this->executable();

        $this->assertStringContainsString('assignInitialFees(', $src);
        $this->assertStringNotContainsString('verified leak', $src,
            'comments are not being stripped, so the assertions below would match the notes '
            . 'that DESCRIBE the fix rather than the fix itself');
    }

    // ══ THE INVARIANT ═══════════════════════════════════════════════════════

    /**
     * Every call site that creates demands must seed the projection.
     *
     * Counted rather than spot-checked: this defect was introduced three times
     * by adding a new caller and not the accompanying sync, so the guard has to
     * be about the RATIO, not about any one path.
     */
    public function test_every_demand_creating_path_seeds_the_defaulter_projection(): void
    {
        $src = $this->executable();

        $assigns = substr_count($src, 'assignInitialFees(');
        $syncs   = substr_count($src, 'updateDefaulterStatus(');

        $this->assertGreaterThanOrEqual($assigns, $syncs,
            "Sis.php calls assignInitialFees() {$assigns} times but updateDefaulterStatus() "
            . "only {$syncs} times. A path that creates demands without seeding the "
            . 'projection leaves those students missing from the defaulter report — their '
            . 'dues are right everywhere else, so nothing looks wrong.');
    }

    /**
     * The bulk import specifically. This is the worst case: a whole intake
     * missing from the report in one operation.
     */
    public function test_the_bulk_import_seeds_the_projection(): void
    {
        $at = strpos(self::$sis, 'private function _runStudentImport');
        $this->assertNotFalse($at, '_runStudentImport was renamed');

        $end  = strpos(self::$sis, "\n    public function ", $at);
        $body = substr(self::$sis, $at, ($end !== false ? $end - $at : 40000));

        $this->assertStringContainsString('assignInitialFees(', $body,
            'the import no longer assigns fees — re-derive this test');
        $this->assertStringContainsString('updateDefaulterStatus(', $body,
            'the bulk import creates demands but never seeds the defaulter projection. An '
            . 'ENTIRE IMPORTED INTAKE would be absent from the school\'s defaulter report.');
    }

    public function test_the_enrolment_path_seeds_the_projection(): void
    {
        $at = strpos(self::$sis, 'public function enroll_student');
        $this->assertNotFalse($at, 'enroll_student was renamed');

        $end  = strpos(self::$sis, "\n    public function ", $at + 20);
        $body = substr(self::$sis, $at, ($end !== false ? $end - $at : 20000));

        $this->assertStringContainsString('updateDefaulterStatus(', $body,
            'enroll_student creates demands but never seeds the defaulter projection');
    }

    // ══ IT MUST NOT BREAK THE OPERATION IT FOLLOWS ══════════════════════════

    /**
     * Fail-soft, exactly as Phase 3D is. A projection is a derived convenience;
     * losing it must never fail an admission, an enrolment, or an import of
     * several hundred students. The doc is recreated by any later payment event
     * or by backfill_defaulters.js.
     *
     * \Throwable rather than \Exception (FEE-W1): a missing collaborator raises
     * an Error, which \Exception does not catch — and by this point the student
     * exists and the demands are written.
     */
    /** Index just past the brace matching the one at $open. */
    private function matchBrace(string $s, int $open): int
    {
        $depth = 0; $i = $open; $len = strlen($s);
        while ($i < $len) {
            if ($s[$i] === '{') { $depth++; }
            elseif ($s[$i] === '}') { $depth--; if ($depth === 0) return $i + 1; }
            $i++;
        }
        return $len;
    }

    /**
     * The try{…}catch(…){…} that ENCLOSES $pos, or null when $pos is not inside
     * one. Replaces a look-back/look-forward character window: the guard is found
     * by structure, so inserting code between the call and its catch cannot hide
     * either from the assertion.
     */
    private function enclosingTryCatch(string $s, int $pos): ?string
    {
        $search = $pos;
        while (($try = strrpos(substr($s, 0, $search), 'try {')) !== false) {
            $open = strpos($s, '{', $try);
            $end  = $this->matchBrace($s, $open);
            if ($end > $pos) {
                $catchAt = strpos($s, 'catch', $end);
                if ($catchAt !== false && $catchAt - $end < 40) {
                    $cOpen = strpos($s, '{', $catchAt);
                    if ($cOpen !== false) $end = $this->matchBrace($s, $cOpen);
                }
                return substr($s, $try, $end - $try);
            }
            $search = $try;   // that try closed before $pos — look further back
        }
        return null;
    }

    public function test_a_projection_failure_never_aborts_the_operation(): void
    {
        $src = self::$sis;
        $offset = 0;
        $checked = 0;

        while (($at = strpos($src, 'updateDefaulterStatus(', $offset)) !== false) {
            $offset = $at + 1;

            // 2026-09-14 — was a 300-char look-back for "try {" plus a 400-char
            // look-forward for the catch. The nearest catch sat 325 chars ahead,
            // i.e. 75 characters from falling out of its own window: two or three
            // added lines and this test would have gone red while the guard was
            // still in place, exactly as the Phase 20.3h seat-guard assertion did.
            // The guard is now located by structure, not by distance.
            $block = $this->enclosingTryCatch($src, $at);
            if ($block === null) continue;   // not a guarded seed site

            $this->assertMatchesRegularExpression('/catch \(\\\\Throwable/', $block,
                'a defaulter-projection seed is guarded by catch(\\Exception) or not at all. '
                . 'A missing collaborator raises an Error, and the student and demands already '
                . 'exist by this point — the caller would see a failure for work that '
                . 'succeeded, and repeat it.');
            $checked++;
        }

        $this->assertGreaterThanOrEqual(2, $checked,
            'fewer guarded projection seeds than expected — the extraction found only '
            . "{$checked}, so this test is not exercising the sites it claims to");
    }

    /**
     * The import seeds INSIDE the fee-assignment success branch. If assignment
     * threw there are no demands, and writing a "not a defaulter" record would
     * assert something untrue about a student whose billing is broken.
     */
    public function test_the_import_only_seeds_when_fees_were_actually_assigned(): void
    {
        $at = strpos(self::$sis, 'CONS-2 · SEED THE DEFAULTER PROJECTION');
        $this->assertNotFalse($at, 'the import seed site was renamed');

        // Walk back to the nearest assignInitialFees — it must be in the same
        // try block, i.e. no catch between them.
        //
        // 2026-09-14 — the look-back was a flat 1200 characters and the call sat
        // 1102 back, leaving 98. Scoped to the enclosing method instead, so the
        // search covers exactly the code this claim is about however it grows.
        $mStart = strrpos(substr(self::$sis, 0, $at), 'private function _runStudentImport');
        $this->assertNotFalse($mStart,
            'the import seed is no longer inside _runStudentImport()');
        $before   = substr(self::$sis, $mStart, $at - $mStart);
        $assignAt = strrpos($before, 'assignInitialFees(');
        $this->assertNotFalse($assignAt,
            'the import seed is no longer preceded by the fee assignment it depends on');

        $between = substr($before, $assignAt);
        $this->assertStringNotContainsString('} catch', $between,
            'the import seeds the projection OUTSIDE the fee-assignment success branch, so a '
            . 'student whose fee assignment failed would still be recorded as "not a '
            . 'defaulter" — asserting something untrue about broken billing.');
    }
}
