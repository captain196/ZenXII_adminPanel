<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Academic Assignment Policy — Option B. Source-regression tests following
 * this repo's established convention (Firestore_service has no behavioral
 * mock for controller code — see StaffCreationAuditMetadataTest's docblock)
 * for Staff::_cascade_subject_assignments()'s two changes:
 *
 *   1. The automatic un-archive on Reactivation/Rehire is REMOVED (not
 *      merely gated) — Active transitions now do nothing to subjectAssignments.
 *   2. Deactivation now also clears a stale sections.classTeacherId pointer,
 *      compare-and-clear so a reassigned class teacher's pointer is never
 *      overwritten by a departed teacher's own separation cascade.
 */
class StaffAcademicAssignmentPolicyOptionBTest extends TestCase
{
    private string $staffSrc;

    protected function setUp(): void
    {
        $this->staffSrc = $this->readCodeOnly(__DIR__ . '/../../application/controllers/Staff.php');
    }

    private function readCodeOnly(string $path): string
    {
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "Could not read {$path}");
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }

    private function extractFunctionBody(string $src, int $functionKeywordOffset): string
    {
        $openBrace = strpos($src, '{', $functionKeywordOffset);
        $this->assertNotFalse($openBrace);
        $depth = 0;
        $len = strlen($src);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) return substr($src, $openBrace, $i - $openBrace + 1);
            }
        }
        $this->fail('Unbalanced braces while extracting function body.');
    }

    private function cascadeBody(): string
    {
        $start = strpos($this->staffSrc, 'function _cascade_subject_assignments(');
        $this->assertNotFalse($start, '_cascade_subject_assignments() must exist.');
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    private function clearPointerBody(): string
    {
        $start = strpos($this->staffSrc, 'function _clear_stale_class_teacher_pointer(');
        $this->assertNotFalse($start, '_clear_stale_class_teacher_pointer() must exist.');
        return $this->extractFunctionBody($this->staffSrc, $start);
    }

    // ── 1. Deactivation still archives subject assignments (unchanged) ──

    public function test_deactivation_still_archives_subject_assignments(): void
    {
        $body = $this->cascadeBody();
        $this->assertStringContainsString("'archived'                       => true,", $body);
        $this->assertStringContainsString("'archivedBecauseOfDeactivation'  => true,", $body);
        $this->assertStringContainsString("\$this->fs->update('subjectAssignments', \$docId, \$patch);", $body);
    }

    // ── 2. Deactivation remains school-scoped ─────────────────────────

    public function test_deactivation_query_remains_school_scoped(): void
    {
        $body = $this->cascadeBody();
        $this->assertStringContainsString("\$this->fs->schoolWhere('subjectAssignments', [", $body,
            'The cascade must keep using the tenant-scoped schoolWhere(), never a bare/unscoped query.');
    }

    // ── 3/6/7/8. classTeacherId clear on a class-teacher row ──────────

    public function test_class_teacher_pointer_cleared_only_for_is_class_teacher_rows(): void
    {
        $body = $this->cascadeBody();
        $this->assertStringContainsString("if (!empty(\$d['isClassTeacher'])) {", $body);
        $this->assertStringContainsString('_clear_stale_class_teacher_pointer($userId, $d, $stats);', $body);
    }

    public function test_pointer_clear_is_compare_and_clear_never_blind_overwrite(): void
    {
        $body = $this->clearPointerBody();
        $this->assertStringContainsString("\$currentClassTeacherId = trim((string) (\$sectionDoc['classTeacherId'] ?? ''));", $body);
        $ifPos = strpos($body, 'if ($currentClassTeacherId !== $userId) {');
        $this->assertNotFalse($ifPos,
            'A section already reassigned to a different (e.g. new) class teacher must never be stomped by a departing teacher\'s own separation cascade.');
        $returnPos = strpos($body, 'return;', $ifPos);
        $writePos = strpos($body, "\$this->fs->update('sections',", $ifPos);
        $this->assertNotFalse($returnPos);
        $this->assertLessThan($writePos, $returnPos,
            'The mismatch branch must return before reaching the write — never fall through to clearing someone else\'s pointer.');
    }

    public function test_pointer_clear_sets_empty_string_not_null_or_teacher_name(): void
    {
        $body = $this->clearPointerBody();
        $this->assertStringContainsString("\$this->fs->update('sections', \$sectionDocId, ['classTeacherId' => '']);", $body);
    }

    public function test_pointer_clear_reads_section_doc_before_writing(): void
    {
        $body = $this->clearPointerBody();
        $readPos = strpos($body, "\$this->fs->get('sections', \$sectionDocId);");
        $writePos = strpos($body, "\$this->fs->update('sections', \$sectionDocId,");
        $this->assertNotFalse($readPos);
        $this->assertNotFalse($writePos);
        $this->assertLessThan($writePos, $readPos, 'Must read the current pointer before deciding whether to clear it.');
    }

    // ── 9. Cross-school isolation — pointer clear derives its ids from the
    //      already school-scoped assignment row, never from raw request input ──

    public function test_pointer_clear_uses_school_scoped_assignment_row_fields_not_request_input(): void
    {
        $body = $this->clearPointerBody();
        $this->assertStringContainsString("(string) (\$assignmentRow['schoolId'] ?? \$this->school_id)", $body);
        $this->assertStringNotContainsString('$this->input->post(', $body,
            'Section doc resolution must come from the assignment row (already filtered to this school by schoolWhere()), never from client-submitted POST data.');
    }

    // ── 4/5. Reactivation/Rehire no longer restores anything ──────────

    public function test_active_transition_returns_early_with_no_restoration(): void
    {
        $body = $this->cascadeBody();
        $this->assertStringContainsString("if (\$newStatus !== 'Inactive') {", $body);
        $ifPos = strpos($body, "if (\$newStatus !== 'Inactive') {");
        $returnPos = strpos($body, 'return $stats;', $ifPos);
        $queryPos = strpos($body, "schoolWhere('subjectAssignments'");
        $this->assertNotFalse($returnPos);
        $this->assertLessThan($queryPos, $returnPos,
            'The early return for non-Inactive transitions must happen BEFORE the subjectAssignments query — Active must not even read assignment rows.');
    }

    public function test_no_unarchive_logic_remains_anywhere_in_the_cascade(): void
    {
        $body = $this->cascadeBody();
        $this->assertStringNotContainsString("'archived'                       => false,", $body,
            'Un-archiving must not happen on any transition anymore.');
        $this->assertStringNotContainsString('unarchivedAt', $body);
        $this->assertStringNotContainsString('unarchivedBy', $body);
    }

    public function test_rehire_and_reactivate_share_the_same_no_op_path(): void
    {
        // Both Reactivate (bare Active) and Rehire (Active + attribute changes)
        // call set_status() with newStatus === 'Active' — the cascade takes
        // $newStatus, not any rehire-specific flag, so there is no special
        // "restore on Rehire" carve-out to accidentally reintroduce.
        $body = $this->cascadeBody();
        $this->assertStringNotContainsString('rehireOtherChanges', $body);
        $this->assertStringNotContainsString('rehireEffectiveDate', $body);
    }

    // ── 10/11. No behavior asserted here for Teacher/Parent apps — verified
    //      by source inspection during design (both already treat a blank
    //      classTeacherId as an existing, handled state); nothing in this
    //      repo's PHP changes their contract, so no test surface exists here.

    // ── 12/13. No RBAC / no new capability introduced by this phase ───

    public function test_no_new_rbac_capability_introduced_by_this_phase(): void
    {
        $body = $this->cascadeBody() . $this->clearPointerBody();
        $this->assertStringNotContainsString('token.role', $body);
        $this->assertStringNotContainsString("'staff.academicAssignment'", $body);
    }

    public function test_set_status_rbac_gate_still_governs_the_trigger_path(): void
    {
        $start = strpos($this->staffSrc, 'function set_status(');
        $body = $this->extractFunctionBody($this->staffSrc, $start);
        $this->assertStringContainsString('$this->_require_role(self::MANAGE_ROLES, "", "SIS", "manage");', $body);
    }

    // ── 14/15/16/17. No Attendance/Work Schedule/Examination/Result/Student
    //      Attendance/Payroll code referenced or touched by this phase ──

    public function test_no_unrelated_subsystems_referenced_by_the_cascade(): void
    {
        $body = $this->cascadeBody() . $this->clearPointerBody();
        foreach (['work_schedule', 'workSchedule', 'staffAttendance', 'studentAttendance', 'salaryStructure', 'examination', 'Examination', 'Result'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_no_new_collection_introduced(): void
    {
        foreach (['staffAssignmentHistory', 'staffAssignmentEpisodes', 'assignmentRestoreEvents', 'teacherRehireAssignments'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $this->staffSrc);
        }
    }

    // ── Manually-archived rows (not this teacher's own deactivation marker)
    //    are structurally unreachable now — Active does nothing at all, so
    //    the old archivedBecauseOfDeactivation read-gate that used to protect
    //    them is gone because there is no un-archive branch left to guard.

    public function test_archived_because_of_deactivation_marker_still_written_for_provenance(): void
    {
        // Still written on archive (needed for future manual/Academic Planner
        // reasoning about why a row was archived), even though nothing reads
        // it to auto-restore anymore.
        $body = $this->cascadeBody();
        $this->assertStringContainsString("'archivedBecauseOfDeactivation'  => true,", $body);
    }
}
