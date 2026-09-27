<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase D, Finding #3, 2026-08-18.
 *
 * Four functions independently answer "is this staff member expected to
 * work on this date": Staff_attendance::_resolve_employee_calendar(),
 * Staff_attendance::_non_working_days(), Staff_attendance_finaliser::
 * _nonWorkingDays(), and Attendance::_staff_grid_schedule(). A full
 * 15-dimension trace (inputs, outputs, cache lifetime, fail-closed shape,
 * staff/department/org resolution, holidays/SWD/half-day, future/historical
 * dates, effective-dating, tenant isolation) found they are NOT genuinely
 * equivalent — each has a distinct I/O lifecycle load-bearing for its
 * caller (punch-time single-date memo vs. finaliser's once-per-run bulk
 * index vs. grid's once-per-request bulk index). Forcing them into one
 * shared function would regress at least one caller's efficiency, so per
 * the standing instruction (Case B) they are deliberately NOT consolidated.
 *
 * What IS shared, correctly, is the decision core all four call into:
 * Policy_assignment_service::resolve() + Holiday_service. This test suite
 * protects that shared core AND the four functions' legitimately distinct
 * shapes — so a future change cannot silently merge them, and cannot
 * silently make one of them behave like the others by accident.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller/library code).
 */
class ExpectedDayResolutionDivergenceProtectionTest extends TestCase
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

    // ── All four still exist, independently — never silently merged ─────

    public function test_all_four_resolvers_still_exist_as_separate_functions(): void
    {
        $this->assertStringContainsString('function _resolve_employee_calendar(', $this->staffAttendanceSrc);
        $this->assertStringContainsString('function _non_working_days(',         $this->staffAttendanceSrc);
        $this->assertStringContainsString('function _nonWorkingDays(',           $this->finaliserSrc);
        $this->assertStringContainsString('function _staff_grid_schedule(',      $this->attendanceSrc);
    }

    // ── Org-level fallback is refused consistently across all four ──────
    // (This IS the shared invariant — not something Case B exempts.)

    public function test_all_four_refuse_organisation_level_fallback(): void
    {
        $resolveStart = strpos($this->staffAttendanceSrc, 'function _resolve_employee_calendar(');
        $resolveBody  = $this->extractFunctionBody($this->staffAttendanceSrc, $resolveStart);
        $this->assertStringContainsString("'organisationPolicyId' => null,", $resolveBody);

        $finaliserStart = strpos($this->finaliserSrc, 'function _nonWorkingDays(');
        $finaliserBody  = $this->extractFunctionBody($this->finaliserSrc, $finaliserStart);
        $this->assertStringContainsString("'organisationPolicyId' => null,", $finaliserBody);

        $gridStart = strpos($this->attendanceSrc, 'function _staff_grid_schedule(');
        $gridBody  = $this->extractFunctionBody($this->attendanceSrc, $gridStart);
        $this->assertStringContainsString("'organisationPolicyId' => null,", $gridBody);
    }

    // ── Each resolver's distinct I/O lifecycle shape is preserved ───────

    public function test_punch_path_resolver_uses_a_per_request_memo_not_a_bulk_index(): void
    {
        // Function 1 is deliberately single-date, request-scoped — a person
        // is waiting at the punch endpoint. Must NOT be rewritten to require
        // a bulk index load (that would regress the punch path's latency).
        $start = strpos($this->staffAttendanceSrc, 'function _resolve_employee_calendar(');
        $body = $this->extractFunctionBody($this->staffAttendanceSrc, $start);
        $this->assertStringContainsString('static $memo = [], $ctxMemo = [];', $body);
    }

    public function test_finaliser_resolver_loads_its_schedule_index_once_per_run(): void
    {
        // Function 3 batches its schedule/assignment read ONCE for the whole
        // run (all staff, all months) — must not regress to a per-staff read.
        $this->assertStringContainsString('indexLoaded', $this->finaliserSrc);
        $this->assertStringContainsString('function _loadScheduleIndex(', $this->finaliserSrc);
    }

    public function test_finaliser_resolver_distinguishes_unresolvable_from_genuinely_no_days_off(): void
    {
        // Function 3's fail-closed shape is the strictest of the four: null
        // (unresolvable) is never confusable with [] (resolvable, no offs).
        // This is the exact distinction Finding #3's trace found function 2
        // (_non_working_days) does NOT make — protecting function 3's
        // stricter contract here, not "fixing" function 2 to match it
        // (function 2's only consumer, me()'s live-view overlay, treats
        // both cases identically today — changing its shape without a
        // caller that needs the distinction would be scope creep).
        $start = strpos($this->finaliserSrc, 'function _nonWorkingDays(');
        $body = $this->extractFunctionBody($this->finaliserSrc, $start);
        $this->assertStringContainsString('return $this->calendarCache[$key] = null;', $body);
    }

    public function test_grid_resolver_reports_a_named_block_reason_per_staff_not_a_bare_bool(): void
    {
        // Function 4's fail-closed shape surfaces a human-readable chain
        // (Policy_assignment_service::explain()) per staffId — the richest
        // of the four, matching its caller's need to show admins WHY a row
        // is locked, not just THAT it is.
        $start = strpos($this->attendanceSrc, 'function _staff_grid_schedule(');
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringContainsString('\Policy_assignment_service::explain($r)', $body);
        $this->assertStringContainsString("\$blocked[\$staffId] =", $body);
    }

    // ── The shared decision core all four call into ──────────────────────

    public function test_all_four_call_the_same_policy_assignment_resolver(): void
    {
        $this->assertStringContainsString('Policy_assignment_service::resolve(', $this->staffAttendanceSrc);
        $this->assertStringContainsString('Policy_assignment_service::resolve(', $this->finaliserSrc);
        $this->assertStringContainsString('Policy_assignment_service::resolve(', $this->attendanceSrc);
    }

    public function test_weekly_off_matching_uses_the_same_day_name_convention_everywhere(): void
    {
        // All four (well, the three that touch weekly-offs directly) key off
        // date('D', ...) against policy.weeklyOffs — divergent day-name
        // formats between them would be a real, silent scheduling bug.
        $this->assertStringContainsString("date('D',", $this->staffAttendanceSrc);
        $this->assertStringContainsString("date('D',", $this->finaliserSrc);
        $this->assertStringContainsString("date('D',", $this->attendanceSrc);
    }
}
