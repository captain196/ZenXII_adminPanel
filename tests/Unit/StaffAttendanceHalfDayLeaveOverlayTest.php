<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Workforce Policy Programme Phase F — Attendance/LOP reconciliation
 * sub-phase (2026-08-18). Research finding: approved Half-Day Leave never
 * touches `staffAttendance`/`staffAttendanceSummary` (by design — the staff
 * worked the other half), so the staff attendance grid showed nothing for
 * it, leaving admins to guess why a day looked incomplete. Payroll's LOP
 * formula was NOT changed to fix this — this is a READ-ONLY display overlay
 * sourced directly from already-approved `leaveApplications`, using the
 * existing (applicantType, schoolId, status, appliedAt) composite index (no
 * new index/deploy required), bucketed client-side to avoid an N+1 query
 * per staff member (same one-query-then-bucket shape already used by
 * `fetch_student_attendance()`'s attendanceSummary read).
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code; the view is HTML+JS — comment-stripping convention
 * matches StaffAttendanceGridPhaseDTest).
 */
class StaffAttendanceHalfDayLeaveOverlayTest extends TestCase
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

    private function gridScheduleBody(): string
    {
        $start = strpos($this->attSrc, 'private function _staff_grid_schedule(');
        $this->assertNotFalse($start);
        return $this->extractFunctionBody($this->attSrc, $start);
    }

    // ── Backend ──────────────────────────────────────────────────────────

    public function test_overlay_query_uses_the_existing_indexed_shape(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString("['applicantType', '==', 'staff'],", $body);
        $this->assertStringContainsString("['status', '==', 'approved'],", $body);
        $this->assertStringContainsString("'appliedAt', 'DESC', 500", $body,
            'must reuse the (applicantType, schoolId, status, appliedAt) composite index — no new index required.');
    }

    public function test_overlay_filters_to_half_day_only(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString(
            "\$isHalf = filter_var(\$d['halfDay'] ?? \$d['half_day'] ?? false, FILTER_VALIDATE_BOOLEAN);",
            $body
        );
        $this->assertStringContainsString('if (!$isHalf) continue;', $body);
    }

    public function test_overlay_scoped_to_requested_staff_ids_and_month(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString('if ($sid === \'\' || !isset($staffIdSet[$sid])) continue;', $body);
        $this->assertStringContainsString("if (strpos(\$date, \$monthPrefix . '-') !== 0) continue;", $body);
    }

    public function test_overlay_fails_safe_never_blocks_the_grid(): void
    {
        $body = $this->gridScheduleBody();
        $tryPos = strpos($body, '$halfDayLeaveDays = [];');
        $catchPos = strpos($body, 'catch (\Exception $e) {');
        $this->assertNotFalse($tryPos);
        $this->assertNotFalse($catchPos);
        // Reset to empty on failure (once in the catch, once at declaration) —
        // a lookup error must degrade to "no overlay", never propagate.
        $this->assertSame(2, substr_count($body, '$halfDayLeaveDays = [];'));
    }

    public function test_overlay_returned_alongside_existing_calendar_fields(): void
    {
        $body = $this->gridScheduleBody();
        $this->assertStringContainsString("'halfDayLeaveDays'    => \$halfDayLeaveDays,", $body);
    }

    public function test_overlay_does_not_touch_non_working_days_or_blocked_computation(): void
    {
        // The overlay block must sit BEFORE the existing per-staff
        // nonWorkingDays/blocked loop, and must not itself write into
        // $nonWorkingDays or $blocked — display-only, zero coupling to
        // what's editable/lockable.
        $body = $this->gridScheduleBody();
        $overlayEnd = strpos($body, '$nonWorkingDays     = [];');
        $overlayBlock = substr($body, 0, $overlayEnd);
        $this->assertStringContainsString('$halfDayLeaveDays', $overlayBlock);
        $this->assertStringNotContainsString('$nonWorkingDays[', $overlayBlock);
        $this->assertStringNotContainsString('$blocked[', $overlayBlock);
    }

    public function test_fetch_response_exposes_half_day_leave_days(): void
    {
        $this->assertStringContainsString(
            "'halfDayLeaveDays'   => \$schedule['halfDayLeaveDays'],",
            $this->attSrc
        );
    }

    // ── Frontend ─────────────────────────────────────────────────────────

    private function viewCode(): string
    {
        $v = $this->viewSrc;
        $v = preg_replace('/<!--.*?-->/s', '', $v);
        $v = preg_replace('~/\*.*?\*/~s', '', $v);
        $v = preg_replace('~^\s*//.*$~m', '', $v);
        return (string) $v;
    }

    public function test_state_and_load_wire_half_day_leave_days(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString('halfDayLeaveDays: {}', $code);
        $this->assertStringContainsString('state.halfDayLeaveDays = res.halfDayLeaveDays', $code);
    }

    public function test_overlay_never_changes_cell_editability(): void
    {
        // dateLockReason(s.id, day) is the single source of truth for
        // editability — the render loop must keep computing `editable` from
        // it alone. hasHdLeave must not appear inside that expression.
        $code = $this->viewCode();

        // The permission half of this expression became canEditNow() on
        // 2026-08-23, when a closed month was made to disable the register the
        // same way view-only access does (GAP-1). That is orthogonal to Phase F:
        // what this test guards is that the OVERLAY does not reach into
        // editability, so assert that intent directly rather than pinning the
        // whole line and failing on an unrelated change.
        $editablePos = strpos($code, 'var editable = canEditNow() && !dateLockReason(s.id, day);');
        $this->assertNotFalse($editablePos,
            'editability must remain the permission/lock gate AND dateLockReason, nothing else.');

        $expr = substr($code, (int) $editablePos, 80);
        $this->assertStringNotContainsString('hasHdLeave', $expr,
            'Phase F does not touch locking — a half-day leave must not make a cell uneditable.');
    }

    public function test_overlay_renders_as_an_additive_stripe_not_a_competing_background(): void
    {
        $code = $this->viewCode();
        $this->assertStringContainsString("if (hasHdLeave) tdCls += ' att-col-hdleave';", $code);
        $this->assertStringContainsString('att-hdleave-dot', $code);
    }
}
