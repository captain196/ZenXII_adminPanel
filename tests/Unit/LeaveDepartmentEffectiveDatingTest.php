<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 — Transfer × Work Schedule architecture decision, Priority 2
 * (2026-08-19).
 *
 * Same bug class as AttendanceDepartmentEffectiveDatingTest, one surface
 * over: Hr::_count_chargeable_leave_days() (called from apply_leave()) used
 * to resolve department ONCE, from the live staff profile, for the entire
 * requested leave span. Staff::transfer_staff() writes staff.Department
 * immediately regardless of the chosen effective date (Trail 2 Phase I/J
 * precedent) — so a leave request filed to span a future-dated transfer's
 * effective date silently used the NEW department's calendar (holidays/
 * weekly-offs/SWDs) for days that were still governed by the OLD
 * department, and vice-versa for days after the effective date once the
 * live value flips back conceptually. Fix: resolve department per the
 * relevant date(s) via the same read-only
 * Staff_employment_history_service::resolveAttributeAsOf() Payroll and
 * Attendance already use.
 *
 * Deliberately NOT a naive per-day resolver call for every day in the span
 * (up to LEAVE_MAX_SPAN_DAYS = 366) — that would be a genuine N+1 (the
 * service has no internal caching; every call is a fresh Firestore query).
 * Instead: probe the two span endpoints (2 queries, memoized) and only fall
 * through to the more expensive per-day resolution path when they actually
 * disagree — i.e. a transfer genuinely occurred inside the span. This is
 * the specific thing under test here beyond the department-resolution
 * change itself.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class LeaveDepartmentEffectiveDatingTest extends TestCase
{
    private string $src;

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
        $this->src = $out;
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

    private function fn(string $name): string
    {
        $start = strpos($this->src, 'function ' . $name . '(');
        $this->assertNotFalse($start, "{$name}() must exist.");
        return $this->extractFunctionBody($this->src, $start);
    }

    // ── The resolver helper itself ───────────────────────────────────────

    public function test_department_as_of_resolver_exists_and_is_read_only(): void
    {
        $body = $this->fn('_department_as_of');
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringNotContainsString('commitBatch', $body);
        $this->assertStringContainsString('resolveAttributeAsOf($staffId, \'department\', $dateISO)', $body);
    }

    public function test_department_as_of_fails_open_to_the_live_fallback(): void
    {
        $body = $this->fn('_department_as_of');
        $this->assertStringContainsString('} catch (\Throwable $e) {', $body);
        $this->assertStringContainsString('return $deptMemo[$k] = $liveFallback;', $body);
    }

    public function test_department_as_of_is_memoized_per_staff_and_date(): void
    {
        $body = $this->fn('_department_as_of');
        $this->assertStringContainsString('static $deptMemo = [];', $body);
        $this->assertStringContainsString('array_key_exists($k, $deptMemo)', $body);
    }

    // ── The N+1 guard: two-endpoint probe before any per-day loop ────────

    public function test_chargeable_days_probes_only_the_two_span_endpoints_before_looping(): void
    {
        $body = $this->fn('_count_chargeable_leave_days');
        $this->assertStringContainsString(
            '$deptAtStart = $this->_department_as_of($staffId, $fromDate, $liveFallbackDepartment);',
            $body
        );
        $this->assertStringContainsString(
            '$deptAtEnd   = $this->_department_as_of($staffId, $toDate, $liveFallbackDepartment);',
            $body
        );
        // The probe must happen BEFORE any per-day while loop — i.e. before
        // the per-day resolver call further down.
        $probePos = strpos($body, '$deptAtEnd   = $this->_department_as_of');
        $perDayLoopPos = strpos($body, '$department = $this->_department_as_of($staffId, $dateISO, $liveFallbackDepartment);');
        $this->assertNotFalse($perDayLoopPos);
        $this->assertLessThan($perDayLoopPos, $probePos);
    }

    public function test_matching_endpoints_take_the_single_department_fast_path(): void
    {
        $body = $this->fn('_count_chargeable_leave_days');
        $this->assertStringContainsString('if ($deptAtStart === $deptAtEnd) {', $body);
        $this->assertStringContainsString('return $this->_chargeable_days_single_department(', $body);
    }

    public function test_single_department_fast_path_makes_no_additional_history_service_calls(): void
    {
        // The fast path (the common case — no transfer inside the span)
        // must not call the resolver again; its only department-history
        // cost is the 2 probe queries already paid before dispatch.
        $body = $this->fn('_chargeable_days_single_department');
        $this->assertStringNotContainsString('_department_as_of', $body);
        $this->assertStringNotContainsString('staff_employment_history_service', $body);
    }

    public function test_single_department_fast_path_preserves_the_original_month_slice_algorithm(): void
    {
        // Byte-for-byte the pre-fix loop shape, just extracted into its own
        // method — a genuine no-behavior-change refactor for the common case.
        $body = $this->fn('_chargeable_days_single_department');
        $this->assertStringContainsString('$monthEnd    = new DateTime(sprintf(\'%04d-%02d-%02d\', $year, $monthNum, $monthEndDay));', $body);
        $this->assertStringContainsString('$sliceEnd    = ($monthEnd < $to) ? $monthEnd : $to;', $body);
        $this->assertStringContainsString('holidays_in_month(', $body);
        $this->assertStringContainsString('special_working_days_in_month(', $body);
        $this->assertStringContainsString('half_days_in_month(', $body);
        $this->assertStringContainsString('Work_schedule_service::resolveEmployeeCalendar(', $body);
    }

    // ── The per-day fallback (only reached on a genuine mismatch) ────────

    public function test_per_day_fallback_resolves_department_inside_its_loop(): void
    {
        $body = $this->fn('_count_chargeable_leave_days');
        $whileLoopPos = strpos($body, 'while ($cursor <= $to) {');
        $this->assertNotFalse($whileLoopPos);
        $perDayResolvePos = strpos($body, '$department = $this->_department_as_of($staffId, $dateISO, $liveFallbackDepartment);');
        $this->assertNotFalse($perDayResolvePos);
        $this->assertGreaterThan($whileLoopPos, $perDayResolvePos,
            'department must be resolved INSIDE the per-day fallback loop, not once before it');
    }

    public function test_per_day_fallback_caches_the_resolved_calendar_per_year_month_department(): void
    {
        $body = $this->fn('_count_chargeable_leave_days');
        $this->assertStringContainsString('$monthCalCache = [];', $body);
        $this->assertStringContainsString("\$cacheKey = \$year . '-' . \$monthNum . '|' . \$department;", $body);
        $this->assertStringContainsString('if (!isset($monthCalCache[$cacheKey])) {', $body);
    }

    public function test_per_day_fallback_still_honors_a_blocked_calendar(): void
    {
        $body = $this->fn('_count_chargeable_leave_days');
        $this->assertStringContainsString("'calendarAware' => false, 'blockReason' => (string) (\$cal['blockReason'] ?? '')", $body);
    }

    // ── Call site wiring — apply_leave() must pass its live department as the fallback ──

    public function test_apply_leave_still_calls_count_chargeable_leave_days_with_the_live_department(): void
    {
        $body = $this->fn('apply_leave');
        $this->assertStringContainsString(
            '$chargeable = $this->_count_chargeable_leave_days($staffId, $department, $fromDate, $toDate);',
            $body
        );
    }

    // ── No second history system, no new writer ──────────────────────────

    public function test_no_second_resolveattributeasof_implementation_exists(): void
    {
        $serviceSrc = file_get_contents(__DIR__ . '/../../application/libraries/Staff_employment_history_service.php');
        $this->assertEquals(1, substr_count($serviceSrc, 'function resolveAttributeAsOf('));
    }

    public function test_department_as_of_and_chargeable_helpers_never_write_to_staffemploymenttimeline(): void
    {
        foreach (['_department_as_of', '_count_chargeable_leave_days', '_chargeable_days_single_department'] as $fn) {
            $body = $this->fn($fn);
            $this->assertStringNotContainsString('TIMELINE_COL', $body);
            $this->assertStringNotContainsString('recordChanges', $body);
            $this->assertStringNotContainsString('recordJoined', $body);
        }
    }
}
