<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 — Transfer × Work Schedule architecture decision, Priority 1
 * (2026-08-18).
 *
 * Confirmed by the dedicated architecture audit (Transfer × Work Schedule
 * Decision report): Staff::transfer_staff()/promote_staff() write
 * staff.Department IMMEDIATELY regardless of the chosen effective date —
 * established, deliberate precedent. Phase J taught Payroll to resolve the
 * department that was ACTUALLY effective for a period from the employment-
 * history timeline instead of reading the live field. Attendance never
 * got the same fix — confirmed by direct code read across three surfaces:
 * the punch path (Staff_attendance::_resolve_employee_calendar()), the
 * finaliser (Staff_attendance_finaliser::_nonWorkingDays()/_overlay()),
 * and the admin grid (Attendance::_staff_grid_schedule()). A future-dated
 * transfer therefore silently changed what TODAY's holiday/SWD/weekly-off
 * resolution saw for that employee, starting the moment HR clicked
 * Transfer, not on the effective date — most consequential in the
 * finaliser, since it PERSISTS its overlay into staffAttendanceSummary.
 *
 * Fix: all three surfaces now resolve department via the SAME read-only
 * Staff_employment_history_service::resolveAttributeAsOf() Payroll already
 * uses — no second history system, no new writer. Each fails open to the
 * live value on any error or absent history (the common case for staff
 * whose department has never changed) — identical to pre-fix behavior in
 * that fallback case, so nothing regresses for the overwhelming majority
 * of staff.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/library code).
 */
class AttendanceDepartmentEffectiveDatingTest extends TestCase
{
    private string $staffAttendanceSrc;
    private string $finaliserSrc;
    private string $attendanceSrc;

    protected function setUp(): void
    {
        foreach ([
            'staffAttendanceSrc' => __DIR__ . '/../../application/controllers/Staff_attendance.php',
            'finaliserSrc'       => __DIR__ . '/../../application/libraries/Staff_attendance_finaliser.php',
            'attendanceSrc'      => __DIR__ . '/../../application/controllers/Attendance.php',
        ] as $prop => $path) {
            $raw = file_get_contents($path);
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
            $this->$prop = $out;
        }
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

    // ── Staff_attendance.php (punch path) ────────────────────────────────

    public function test_punch_path_resolver_exists_and_is_read_only(): void
    {
        $start = strpos($this->staffAttendanceSrc, 'function _department_as_of(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffAttendanceSrc, $start);
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringNotContainsString('commitBatch', $body);
        $this->assertStringContainsString('resolveAttributeAsOf($staffId, \'department\', $dateISO)', $body);
    }

    public function test_punch_path_resolver_fails_open_to_the_live_value(): void
    {
        $start = strpos($this->staffAttendanceSrc, 'function _department_as_of(');
        $body = $this->extractFunctionBody($this->staffAttendanceSrc, $start);
        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
        $this->assertStringContainsString('return $deptMemo[$k] = $liveFallback;', $body);
    }

    public function test_resolve_employee_calendar_uses_the_as_of_resolver_on_both_cache_paths(): void
    {
        $start = strpos($this->staffAttendanceSrc, 'function _resolve_employee_calendar(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffAttendanceSrc, $start);
        $this->assertEquals(2, substr_count($body, '$this->_department_as_of($staffId, $dateISO'),
            'both the warm-cache branch and the cold-load branch must resolve department as-of the date');
    }

    public function test_resolve_employee_calendar_no_longer_bakes_a_fixed_department_into_the_cached_context(): void
    {
        $start = strpos($this->staffAttendanceSrc, 'function _resolve_employee_calendar(');
        $body = $this->extractFunctionBody($this->staffAttendanceSrc, $start);
        // The per-date override must happen on a per-call copy of $ctx, not
        // on $ctxMemo[$staffId] itself (which would corrupt the cache for
        // every other date resolved against the same staff this request).
        $this->assertStringContainsString('$ctx[\'department\'] = $deptAsOf;', $body);
        $this->assertStringNotContainsString('$ctxMemo[$staffId][\'department\'] = $deptAsOf;', $body);
    }

    // ── Staff_attendance_finaliser.php (persists its result) ─────────────

    public function test_finaliser_resolver_exists_and_is_read_only(): void
    {
        $start = strpos($this->finaliserSrc, 'function _departmentAsOf(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->finaliserSrc, $start);
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringNotContainsString('commitBatch', $body);
        $this->assertStringContainsString('resolveAttributeAsOf($staffId, \'department\', $date)', $body);
    }

    public function test_finaliser_non_working_days_resolves_department_per_day(): void
    {
        $start = strpos($this->finaliserSrc, 'function _nonWorkingDays(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->finaliserSrc, $start);
        $this->assertStringContainsString('$ctx[\'department\'] = $this->_departmentAsOf($staffId, $date);', $body);
    }

    public function test_finaliser_overlay_resolves_department_per_day_not_once_before_the_loop(): void
    {
        $start = strpos($this->finaliserSrc, 'function _overlay(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->finaliserSrc, $start);
        // The old bug: $department read ONCE from staffDept[] before the
        // loop. Must now be resolved INSIDE the loop, per date.
        $this->assertStringContainsString('$department = $this->_departmentAsOf($staffId, $dateISO);', $body);
        $loopPos = strpos($body, 'for ($d = 1;');
        $deptPos = strpos($body, '$department = $this->_departmentAsOf');
        $this->assertGreaterThan($loopPos, $deptPos, 'department must be resolved INSIDE the per-day loop');
    }

    // ── Attendance.php (admin grid) ──────────────────────────────────────

    public function test_grid_resolver_exists_and_is_read_only(): void
    {
        $start = strpos($this->attendanceSrc, 'function _department_as_of(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringContainsString('resolveAttributeAsOf($staffId, \'department\', $date)', $body);
    }

    public function test_grid_schedule_resolves_department_per_day_inside_the_nonworkingdays_loop(): void
    {
        $start = strpos($this->attendanceSrc, 'function _staff_grid_schedule(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringContainsString('$ctx[\'department\'] = $this->_department_as_of($staffId, $date, $liveDept);', $body);
    }

    public function test_grid_schedule_holiday_and_swd_batch_calls_use_period_start_resolution(): void
    {
        // Consistent with Payroll's own Phase J granularity (period-start,
        // not full per-day) for the month-batch holiday/SWD calls — matches
        // rather than exceeds Payroll's precision for these two calls.
        $start = strpos($this->attendanceSrc, 'function _staff_grid_schedule(');
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringContainsString('$periodStartDept = $this->_department_as_of($staffId, $monthKey . \'-01\', $liveDept);', $body);
        $this->assertStringContainsString('special_working_days_in_month(', $body);
        $this->assertStringContainsString('(int) $y, (int) $m, \'staff\', $periodStartDept, $staffId);', $body);
    }

    // ── All three call the SAME resolver, no second history system ──────

    public function test_all_three_surfaces_call_the_same_shared_history_service(): void
    {
        $this->assertStringContainsString("\$this->load->library('staff_employment_history_service');", $this->staffAttendanceSrc);
        $this->assertStringContainsString("\$this->ci->load->library('staff_employment_history_service');", $this->finaliserSrc);
        $this->assertStringContainsString("\$this->load->library('staff_employment_history_service');", $this->attendanceSrc);
    }

    public function test_no_second_resolveattributeasof_implementation_exists(): void
    {
        // The actual resolution logic (querying staffEmploymentTimeline)
        // lives in exactly one place — the service itself. All three
        // attendance-side call sites must be thin wrappers, not
        // reimplementations.
        $serviceSrc = file_get_contents(__DIR__ . '/../../application/libraries/Staff_employment_history_service.php');
        $this->assertEquals(1, substr_count($serviceSrc, 'function resolveAttributeAsOf('));
    }
}
