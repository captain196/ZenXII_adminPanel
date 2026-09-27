<?php

use PHPUnit\Framework\TestCase;

if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/../../application/');
if (!defined('BASEPATH')) define('BASEPATH', 'x');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  AN ORPHAN MARKER THAT READS THE WRONG FIELD MARKS NOTHING
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── LC-8 · THE DEFECT (found 2026-08-27, on live data) ───────────────────
 * `Sis::delete_student()` hard-deletes a student but deliberately does NOT
 * release their Admission Number, SRNO or APAAR ID — P11-G2 records that
 * reusability is an open business decision. What it promises instead is an
 * integrity MARKER (`currentOwnerDeleted`) so an index entry owned by a
 * deleted student is distinguishable from a healthy one.
 *
 * P11-G2 extended the working srNoIndex guard to the other two indexes by
 * copying it verbatim — owner field included. The three indexes do not share
 * a schema:
 *
 *     Sr_no_service        writes `currentStudentId`   -> guard correct
 *     Apaar_service        writes `currentStudentId`   -> guard correct
 *     Admission_no_service writes `studentId`          -> guard NEVER true
 *
 * So for admissionNoIndex the comparison was against a field that does not
 * exist in the collection. It was always null, the branch never ran, and the
 * marker was never written once.
 *
 * MEASURED, NOT INFERRED — `scripts/admission_index_marker_readonly.js`
 * against the live tenant: 16 orphaned entries, owner field `studentId` on
 * 16/16, `currentStudentId` absent from every document, 0 marked.
 *
 * ── WHY IT SURVIVED REVIEW ───────────────────────────────────────────────
 * It failed silently and in the reassuring direction. No error, no exception,
 * no log line — just a conditional that was never true. Reading the code, the
 * guard looks careful; only the DATA shows it never fires. That is why this
 * test pins the field names of the three writers, not merely the shape of the
 * guard: the defect lives in the relationship between them.
 */
final class IdentityIndexOrphanMarkerTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents(APPPATH . 'controllers/Sis.php')) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        // Collapse whitespace runs. Stripping a comment leaves its newlines
        // behind, so a fix with a long explanatory block pushes the code it
        // explains far outside any byte window measured from an anchor — the
        // assertions below would then fail on the fix being well-documented
        // rather than on anything being wrong.
        self::$src = (string) preg_replace('/\s+/', ' ', $out);
    }

    private function strip(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
                $out .= $t[1];
            } else { $out .= $t; }
        }
        return $out;
    }

    public function test_the_comment_stripper_actually_strips(): void
    {
        // Control: the fix's own comment names both field spellings.
        $this->assertStringNotContainsString('THIS GUARD READ A FIELD THAT DOES NOT EXIST', self::$src);
        $this->assertStringNotContainsString('LC-8', self::$src);
    }

    // ── THE RELATIONSHIP THAT MAKES THE DEFECT ───────────────────────────

    public function test_the_admission_number_index_is_keyed_on_studentId(): void
    {
        // If this ever changes, the guard below must change with it. Pinning
        // the writer is what stops the two drifting apart again.
        $svc = $this->strip(APPPATH . 'libraries/Admission_no_service.php');
        $this->assertStringContainsString("'studentId'", $svc,
            'Admission_no_service no longer writes studentId — the delete-time '
            . 'orphan marker reads that field and would stop matching');
    }

    public function test_the_srno_and_apaar_indexes_are_keyed_on_currentStudentId(): void
    {
        // The positive control for the whole finding: these two really do use
        // the other spelling, which is why copying their guard was wrong only
        // for admissionNoIndex.
        foreach (['Sr_no_service.php', 'Apaar_service.php'] as $f) {
            $this->assertStringContainsString("'currentStudentId'", $this->strip(APPPATH . 'libraries/' . $f),
                "{$f} no longer writes currentStudentId — its orphan marker guard "
                . 'now reads a field it does not write');
        }
    }

    // ── THE GUARD ────────────────────────────────────────────────────────

    public function test_the_admission_marker_reads_the_field_the_collection_uses(): void
    {
        $at = strpos(self::$src, "get('admissionNoIndex'");
        $this->assertNotFalse($at, 'the admissionNoIndex orphan-marker block moved');
        $window = substr(self::$src, $at, 700);

        $this->assertStringContainsString("\$admDoc['studentId']", $window,
            'the admissionNoIndex orphan marker does not read studentId — the field '
            . 'Admission_no_service actually writes. The marker can never fire.');
    }

    public function test_the_admission_marker_still_writes_the_marker(): void
    {
        $at = strpos(self::$src, "get('admissionNoIndex'");
        $window = substr(self::$src, $at, 900);
        $this->assertStringContainsString("'currentOwnerDeleted'", $window,
            'the marker P11-G2 promises is no longer written');
    }

    public function test_a_blank_owner_can_never_match(): void
    {
        // Without this, an index entry carrying NEITHER field would compare
        // '' === $id. That is false for a real id, but the guard would be one
        // typo away from marking entries that are not ours — and this fix
        // widened the read, so the emptiness check is what keeps it narrow.
        $at = strpos(self::$src, "get('admissionNoIndex'");
        $window = substr(self::$src, $at, 700);
        $this->assertMatchesRegularExpression(
            '/\$admOwner\s*!==\s*\'\'\s*&&\s*\$admOwner\s*===\s*\$id/', $window,
            'the owner comparison does not require a non-empty owner'
        );
    }

    public function test_the_marking_never_blocks_the_deletion(): void
    {
        // Integrity marking is observability. It must not be able to fail the
        // destructive operation it observes.
        $at = strpos(self::$src, "get('admissionNoIndex'");
        $window = substr(self::$src, max(0, $at - 300), 1100);
        $this->assertStringContainsString('try', $window);
        $this->assertStringContainsString('Throwable', $window,
            'catch \Exception misses an Error from a missing collaborator (FEE-W1)');
    }

    public function test_the_sibling_guards_are_untouched(): void
    {
        // The fix is scoped to the one index with the differing schema.
        // Rewriting the other two would break the guards that DO work.
        foreach (['srNoIndex', 'apaarIndex'] as $col) {
            $at = strpos(self::$src, "get('{$col}'");
            $this->assertNotFalse($at, "the {$col} orphan-marker block moved");
            $window = substr(self::$src, $at, 400);
            $this->assertStringContainsString('currentStudentId', $window,
                "{$col}'s guard no longer reads currentStudentId, which is what its "
                . 'service writes — a correct guard was broken');
        }
    }

    public function test_the_detector_that_found_this_exists(): void
    {
        // The defect was invisible in source and obvious in data. The detector
        // is part of the fix.
        $script = __DIR__ . '/../../scripts/admission_index_marker_readonly.js';
        $this->assertFileExists($script);
        $src = (string) file_get_contents($script);
        foreach (['currentOwnerDeleted', 'admissionNoIndex', 'currentStudentId'] as $needed) {
            $this->assertStringContainsString($needed, $src);
        }
    }
}
