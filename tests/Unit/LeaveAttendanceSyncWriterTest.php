<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase D §5 follow-up, 2026-08-18.
 *
 * Confirmed, compounding defects in Leave's attendance-sync path, found
 * while tracing "approved Leave" interaction during the Staff Attendance
 * hardening phase:
 *
 *   Hr::_apply_leave_to_attendance() and Hr::_revert_leave_from_attendance()
 *   wrote directly via $this->firebase->firestoreSet()/firestoreDelete(),
 *   bypassing Staff_attendance_writer entirely (the same defect class as
 *   Finding #2, but worse):
 *     - no lock check at all (could write into a payroll-closed month)
 *     - no CAS on the summary rebuild (race with punch/grid-save/finaliser)
 *     - drifted field name ('vacation' instead of the canonical 'void',
 *       halfDay/extraWorked/weeklyOff never written)
 *     - unbounded query (scans ALL of a staff member's staffAttendance
 *       history, filters client-side)
 *     - _revert_leave_from_attendance() computed the corrected dayWise
 *       string and NEVER WROTE IT BACK — a cancelled leave left
 *       staffAttendanceSummary.dayWise permanently stale, still 'L'
 *
 * Fix: both now route through Staff_attendance_writer::markSingleDay() —
 * the SAME canonical writer every other attendance write path uses. No
 * second writer created.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeaveAttendanceSyncWriterTest extends TestCase
{
    private string $controllerSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertNotFalse($raw);
        $out = '';
        foreach (token_get_all($raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        $this->controllerSrc = $out;
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

    private function applyFnBody(): string
    {
        $start = strpos($this->controllerSrc, 'private function _apply_leave_to_attendance(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    private function revertFnBody(): string
    {
        $start = strpos($this->controllerSrc, 'private function _revert_leave_from_attendance(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->controllerSrc, $start);
    }

    // ── No second writer, canonical writer used ──────────────────────────

    public function test_apply_routes_through_the_canonical_writer(): void
    {
        $body = $this->applyFnBody();
        $this->assertStringContainsString("\$this->load->library('staff_attendance_writer');", $body);
        $this->assertStringContainsString('$this->staff_attendance_writer->markSingleDay($staffId, $dateISO, \'L\', $ctx);', $body);
    }

    public function test_revert_routes_through_the_canonical_writer(): void
    {
        $body = $this->revertFnBody();
        $this->assertStringContainsString("\$this->load->library('staff_attendance_writer');", $body);
        $this->assertStringContainsString('$this->staff_attendance_writer->markSingleDay($staffId, $dateISO, $restoreTo, [', $body);
    }

    public function test_neither_function_calls_firestore_directly_for_attendance_state(): void
    {
        $applyBody = $this->applyFnBody();
        $revertBody = $this->revertFnBody();
        foreach ([$applyBody, $revertBody] as $body) {
            $this->assertStringNotContainsString('$this->firebase->firestoreSet(\'staffAttendance\'', $body);
            $this->assertStringNotContainsString('$this->firebase->firestoreSet(\'staffAttendanceSummary\'', $body);
            $this->assertStringNotContainsString('$this->firebase->firestoreDelete(\'staffAttendance\'', $body);
        }
    }

    // ── Lock check is now respected ──────────────────────────────────────

    public function test_apply_catches_month_locked_as_a_warning_not_a_silent_bypass(): void
    {
        $body = $this->applyFnBody();
        $this->assertStringContainsString('catch (MonthLockedException $e) {', $body);
        $this->assertStringContainsString('is locked.', $body);
    }

    public function test_revert_catches_month_locked_as_a_warning_not_a_silent_bypass(): void
    {
        $body = $this->revertFnBody();
        $this->assertStringContainsString('catch (MonthLockedException $e) {', $body);
        $this->assertStringContainsString('is locked.', $body);
    }

    public function test_a_locked_month_does_not_block_the_whole_leave_decision(): void
    {
        // Confirms the design choice: a locked date is skipped with a
        // warning, not a thrown/fatal error that would abort the loop or
        // the caller's leave-decision flow.
        $body = $this->applyFnBody();
        $this->assertStringContainsString('$warnings[] = "Attendance for {$dateISO} was not updated', $body);
        $this->assertStringContainsString('$cursor->modify(\'+1 day\');', $body);
    }

    // ── Revert now actually persists the correction ──────────────────────

    public function test_revert_no_longer_silently_drops_the_computed_correction(): void
    {
        // The old bug: computed $attStr[$idx] = 'V' and never wrote it back.
        // markSingleDay() now performs and PERSISTS the write atomically.
        $body = $this->revertFnBody();
        $this->assertStringContainsString('markSingleDay($staffId, $dateISO, $restoreTo,', $body);
    }

    public function test_revert_restores_the_recorded_previous_status_not_a_blind_vacant_reset(): void
    {
        $body = $this->revertFnBody();
        $this->assertStringContainsString("\$restoreTo = (is_array(\$existing) && !empty(\$existing['previousStatus']))", $body);
        $this->assertStringContainsString("? strtoupper((string) \$existing['previousStatus']) : 'V';", $body);
    }

    public function test_revert_validates_the_restored_status_against_the_allowed_set(): void
    {
        $body = $this->revertFnBody();
        $this->assertStringContainsString('if (!in_array($restoreTo, \Staff_attendance_writer::ALLOWED_STATUS, true)) $restoreTo = \'V\';', $body);
    }

    public function test_revert_never_touches_a_day_that_is_no_longer_marked_leave(): void
    {
        // A day already corrected/re-punched since the leave was approved
        // must never be clobbered by a stale cancellation.
        $body = $this->revertFnBody();
        $this->assertStringContainsString("if (\$currentStatus === 'L') {", $body);
    }

    // ── Call sites updated, warnings surfaced, nothing silently swallowed ─

    public function test_decide_leave_passes_the_leave_request_id_for_audit_cross_reference(): void
    {
        $start = strpos($this->controllerSrc, '$attendanceWarnings = $this->_apply_leave_to_attendance(');
        $this->assertNotFalse($start);
        $chunk = substr($this->controllerSrc, $start, 320);
        $this->assertStringContainsString('(string) $id', $chunk);
    }

    public function test_decide_leave_response_surfaces_attendance_warnings(): void
    {
        $this->assertStringContainsString("'attendance_warnings' => \$attendanceWarnings", $this->controllerSrc);
    }

    public function test_cancel_leave_response_surfaces_revert_warnings(): void
    {
        $this->assertStringContainsString("'attendance_warnings' => \$revertWarnings", $this->controllerSrc);
    }

    public function test_no_other_call_sites_were_missed(): void
    {
        // Exactly two callers (decide_leave, cancel_leave) — a third call
        // site added later must be reviewed for the same warning-surfacing
        // contract, not silently ignore the return value.
        $this->assertEquals(2, substr_count($this->controllerSrc, '_apply_leave_to_attendance('));
        $this->assertEquals(2, substr_count($this->controllerSrc, '_revert_leave_from_attendance('));
    }
}
