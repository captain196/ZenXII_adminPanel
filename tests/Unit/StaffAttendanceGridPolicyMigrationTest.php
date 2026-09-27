<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * N-35 (Phase 2) — the grid consumer. The last of the three attendance
 * consumers migrated from a flat, tenant-wide `sundays` array onto the
 * canonical `Policy_assignment_service` resolver (finaliser and punch path
 * were already migrated). Two staff in the same grid can legitimately have
 * different weekly-offs, so a single per-column Sunday answer for every row
 * was exactly the competing-authority defect the rest of N-35 already fixed.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code — see StaffCreationAuditMetadataTest's docblock; the view
 * is HTML+JS, so its guards read comment-stripped source the same way
 * WorkSchedulesSurfaceTest does for Work_schedules.php's view).
 */
class StaffAttendanceGridPolicyMigrationTest extends TestCase
{
    private string $attSrc;
    private string $viewSrc;

    protected function setUp(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
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
        $this->attSrc = $out;

        $this->viewSrc = file_get_contents(__DIR__ . '/../../application/views/attendance/staff.php');
        $this->assertNotFalse($this->viewSrc);
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

    private function fetchStaffBody(): string
    {
        $start = strpos($this->attSrc, 'private function _fetch_staff_attendance_fs(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->attSrc, $start);
    }

    private function gridScheduleBody(): string
    {
        $start = strpos($this->attSrc, 'private function _staff_grid_schedule(');
        $this->assertNotFalse($start, '_staff_grid_schedule() must exist.');
        return $this->extractFunctionBody($this->attSrc, $start);
    }

    // ── Backend: response shape ───────────────────────────────────────────

    /**
     * Originally also asserted the literal 'scheduleBlocked'=> spacing —
     * superseded by Phase D (2026-08-18), which reformatted this array when
     * adding 'holidayDays'/'specialWorkingDays' (replacing the flat, school-
     * wide 'holidays' field every row used to shade identically with
     * per-staff, department/exception-filtered calendar facts).
     */
    public function test_staff_grid_response_no_longer_sends_a_flat_sundays_array(): void
    {
        $body = $this->fetchStaffBody();
        $this->assertStringNotContainsString("'sundays'     => \$this->_get_sundays", $body,
            'The staff grid must not reuse the tenant-wide/hardcoded-Sunday helper — that is the competing-authority defect N-35 exists to close.');
        $this->assertStringContainsString("'nonWorkingDays'     => \$schedule['nonWorkingDays'],", $body);
        $this->assertStringContainsString("'scheduleBlocked'    => \$schedule['blocked'],", $body);
        $this->assertStringContainsString("'holidayDays'        => \$schedule['holidayDays'],", $body);
        $this->assertStringContainsString("'specialWorkingDays' => \$schedule['specialWorkingDays'],", $body);
    }

    public function test_student_grid_still_uses_get_sundays_unchanged(): void
    {
        // ORIGINAL INTENT, PRESERVED: this test guards that the STAFF work-schedule
        // migration did not reach into Student Attendance. Both student-facing
        // endpoints must resolve through the STUDENT calendar, never through
        // Policy_assignment_service.
        //
        // UPDATED BY THE CALENDAR PROGRAMME (Phase 5C, authorised). The symbol it
        // pinned — `_get_sundays()` — was one of TWO independent hardcoded
        // `date('w') === 0` implementations on the student side, so the register
        // grid and the marking engine could disagree about which days the school
        // is open. Both now resolve through Student_calendar_reader, and an
        // unconfigured tenant still gets exactly the Sundays it got before
        // (StudentCalendarLegacyEquivalenceTest proves it day-for-day over 36
        // months). The assertion is re-pointed at the new canonical symbol; what
        // it protects — student endpoints never using the staff authority — is
        // unchanged and is asserted explicitly below.
        $this->assertSame(2, substr_count($this->attSrc, "'sundays'     => \$this->_weekly_closure_days(\$year, \$monthNum),"),
            'Both student-facing endpoints (fetch_student_attendance, and the empty-roster early return) must resolve the school week through the canonical student calendar.');

        $this->assertStringNotContainsString('_get_sundays', $this->attSrc,
            'the duplicate student-side Sunday implementation must be gone, not merely bypassed');
    }

    public function test_grid_schedule_resolved_via_policy_assignment_service(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString('\Policy_assignment_service::resolve(', $body);
        $this->assertStringContainsString("'organisationPolicyId' => null,", $body);
    }

    public function test_grid_schedule_is_school_scoped(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString("\$this->fs->schoolWhere('staffScheduleAssignments', []);", $body);
        $this->assertStringContainsString("\$this->fs->get('schools', \$this->school_id);", $body);
    }

    public function test_grid_schedule_fails_closed_never_guesses(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString("if (!empty(\$r['block'])) {", $body);
        $this->assertStringContainsString("\$blocked[\$staffId] = \Policy_assignment_service::explain(\$r);", $body);
        $this->assertStringContainsString('$days_ = null;', $body);
        // A blocked employee must never contribute a partial/guessed day set.
        $this->assertStringContainsString('if ($days_ !== null) $nonWorkingDays[$staffId] = $days_;', $body);
    }

    public function test_no_new_calendar_maths_only_delegation(): void
    {
        // This is a third I/O-shape call site, not a third calendar
        // implementation — same guard style as EmployeeWorkScheduleTest's
        // "the schedule service computes no calendar itself".
        $body = $this->gridScheduleBody();
        foreach (['satCount', 'nthWeekdayOffs['] as $calendarLogic) {
            $this->assertStringNotContainsString($calendarLogic, $body,
                "grid schedule resolution must delegate calendar maths, found '{$calendarLogic}'");
        }
    }

    // ── Frontend: per-employee lock resolution ────────────────────────────

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    public function test_view_reads_per_staff_fields_not_flat_sundays(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('state.nonWorkingDays = res.nonWorkingDays', $code);
        $this->assertStringContainsString('state.scheduleBlocked = res.scheduleBlocked', $code);
        $this->assertStringNotContainsString('res.sundays', $code);
        $this->assertStringNotContainsString('state.sundays', $code);
    }

    public function test_date_lock_reason_takes_a_staff_id(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('function dateLockReason(staffId, day)', $code);
        // Every call site must pass a staff id — a bare dateLockReason(day)
        // would silently resolve against nothing (undefined staffId key).
        $this->assertStringNotContainsString('dateLockReason(day)', $code);
    }

    public function test_toggle_and_bulk_handlers_pass_staff_id_into_lock_check(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('dateLockReason(sid, day)', $code);
        $this->assertStringContainsString('dateLockReason(s.id, day)', $code);
    }

    public function test_bulk_action_skips_locked_staff_rather_than_refusing_the_whole_action(): void
    {
        $code = $this->viewCode();
        // The old behavior refused the entire bulk action if ANY single day
        // was locked tenant-wide; the new behavior must skip per-employee.
        $this->assertStringContainsString('skipped++', $code);
        $this->assertStringContainsString('applied++', $code);
    }

    public function test_header_row_no_longer_shades_a_shared_weekly_off_column(): void
    {
        $code = $this->viewCode();
        // Header must keep holiday/future (school-wide facts) but drop the
        // per-column Sunday shade, since two rows can now disagree on it.
        $headStart = strpos($code, 'function renderGrid()');
        $this->assertNotFalse($headStart);
        $headerSection = substr($code, $headStart, strpos($code, '/* Body */', $headStart) - $headStart);
        $this->assertStringNotContainsString('isSun', $headerSection);
    }

    public function test_body_shading_is_resolved_per_staff_per_day(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('nwd[day]', $code,
            'the per-row weekly-off shade must be looked up per staff member, not from a shared set');
    }

    public function test_blocked_row_is_visually_distinguished(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('att-row-blocked', $code);
        $this->assertStringContainsString('scheduleBlocked', $code);
    }
}
