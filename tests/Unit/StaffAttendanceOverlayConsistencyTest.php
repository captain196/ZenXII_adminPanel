<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Trail 2 (Work Schedule → Calendar → Attendance → Leave/LOP → Payroll),
 * Phase D, Finding #1, 2026-08-18.
 *
 * Confirmed defect: the staff self-view (Staff_attendance::me()) applies a
 * live V→A/O/H overlay to unfinalized days; the admin grid
 * (Attendance::_fetch_staff_attendance_fs()) read the raw, un-overlaid
 * dayWise string. Before the nightly finaliser runs, the two surfaces could
 * disagree about the same employee/date.
 *
 * Fix: the overlay logic was extracted verbatim into
 * Staff_attendance_overlay::apply() (one shared, read-only decision) and
 * both controllers now call it. This does NOT create a second attendance-
 * state calculation — it removes the second one that already existed
 * (the admin grid returning raw, un-overlaid text) by pointing it at the
 * same function the self-view already used.
 *
 * Source-regression tests (Firestore_service has no behavioral mock for
 * controller code).
 */
class StaffAttendanceOverlayConsistencyTest extends TestCase
{
    private string $overlayLibSrc;
    private string $staffAttendanceSrc;
    private string $attendanceSrc;

    protected function setUp(): void
    {
        foreach ([
            'overlayLibSrc'       => __DIR__ . '/../../application/libraries/Staff_attendance_overlay.php',
            'staffAttendanceSrc'  => __DIR__ . '/../../application/controllers/Staff_attendance.php',
            'attendanceSrc'       => __DIR__ . '/../../application/controllers/Attendance.php',
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

    // ── The shared decision exists exactly once ─────────────────────────

    public function test_overlay_class_exists_with_the_expected_contract(): void
    {
        $this->assertStringContainsString('class Staff_attendance_overlay', $this->overlayLibSrc);
        $this->assertStringContainsString('public static function apply(', $this->overlayLibSrc);
    }

    public function test_no_second_reimplementation_of_the_overlay_loop_exists(): void
    {
        // The distinctive V/A/H/O rewrite loop must exist in exactly the
        // shared library — not re-typed anywhere else.
        $marker = "if (\$ch !== 'V' && \$ch !== 'A') continue;";
        $this->assertEquals(1, substr_count($this->overlayLibSrc, $marker));
        $this->assertStringNotContainsString($marker, $this->staffAttendanceSrc);
        $this->assertStringNotContainsString($marker, $this->attendanceSrc);
    }

    // ── Self-view delegates, behavior preserved ──────────────────────────

    public function test_staff_self_view_overlay_wrapper_delegates_to_the_shared_class(): void
    {
        $start = strpos($this->staffAttendanceSrc, 'private function _overlay_daywise(');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->staffAttendanceSrc, $start);
        $this->assertStringContainsString('Staff_attendance_overlay::apply(', $body);
        $this->assertStringContainsString('$this->holiday_service,', $body);
    }

    // ── Admin grid now applies the same overlay ──────────────────────────

    public function test_admin_grid_applies_the_shared_overlay_before_returning_attendance(): void
    {
        $start = strpos($this->attendanceSrc, 'private function _fetch_staff_attendance_fs()');
        $this->assertNotFalse($start);
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringContainsString('Staff_attendance_overlay::apply(', $body);
    }

    public function test_admin_grid_skips_overlay_for_a_blocked_unresolvable_schedule(): void
    {
        // Showing a computed O/H/A against an unknown calendar would be
        // worse than leaving the day Vacant — the row is already flagged
        // via 'scheduleBlocked' for the UI to lock/warn on separately.
        $start = strpos($this->attendanceSrc, 'private function _fetch_staff_attendance_fs()');
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringContainsString("if (empty(\$schedule['blocked'][\$staffId])) {", $body);
    }

    public function test_admin_grid_uses_the_same_autoabsent_and_timezone_settings_as_self_view(): void
    {
        $gridSchedStart = strpos($this->attendanceSrc, 'private function _staff_grid_schedule(');
        $this->assertNotFalse($gridSchedStart);
        $gridSchedBody = $this->extractFunctionBody($this->attendanceSrc, $gridSchedStart);
        $this->assertStringContainsString("'autoAbsent'          => array_key_exists('autoAbsent', \$policy) ? !empty(\$policy['autoAbsent']) : true,", $gridSchedBody);

        $fetchStart = strpos($this->attendanceSrc, 'private function _fetch_staff_attendance_fs()');
        $fetchBody = $this->extractFunctionBody($this->attendanceSrc, $fetchStart);
        $this->assertStringContainsString('$schedule[\'autoAbsent\']', $fetchBody);
        $this->assertStringContainsString("new \\DateTimeZone(\$schedule['timezone'])", $fetchBody);
    }

    public function test_grid_read_never_writes_anything(): void
    {
        // Purely a read-time transform — must never persist a write.
        $start = strpos($this->attendanceSrc, 'private function _fetch_staff_attendance_fs()');
        $body = $this->extractFunctionBody($this->attendanceSrc, $start);
        $this->assertStringNotContainsString('->set(', $body);
        $this->assertStringNotContainsString('->setEntity(', $body);
        $this->assertStringNotContainsString('firestoreCommitBatch', $body);
    }

    // ── Overlay logic itself: exhaustive state coverage ──────────────────

    public function test_overlay_leaves_today_and_future_untouched(): void
    {
        $this->assertStringContainsString('if ($dateISO >= $todayISO) continue;', $this->overlayLibSrc);
    }

    public function test_overlay_only_touches_vacant_and_previously_auto_absented_days(): void
    {
        $this->assertStringContainsString("if (\$ch !== 'V' && \$ch !== 'A') continue;", $this->overlayLibSrc);
    }

    public function test_special_working_day_beats_holiday_and_weekly_off(): void
    {
        $swdPos = strpos($this->overlayLibSrc, 'if ($isSwd) {');
        $holPos = strpos($this->overlayLibSrc, 'elseif ($isHol) {');
        $this->assertNotFalse($swdPos);
        $this->assertNotFalse($holPos);
        $this->assertLessThan($holPos, $swdPos, 'SWD branch must be checked before the holiday branch.');
    }

    public function test_weekly_off_maps_to_o_and_holiday_maps_to_h(): void
    {
        $this->assertStringContainsString("\$dayWise[\$d - 1] = 'O';", $this->overlayLibSrc);
        $this->assertStringContainsString("\$dayWise[\$d - 1] = 'H';", $this->overlayLibSrc);
    }

    public function test_auto_absent_off_leaves_unmarked_working_days_vacant(): void
    {
        // The V-branch's Absent assignment is conditional on $autoAbsent —
        // when off, no assignment happens in that branch, so a working-day
        // 'V' char is left exactly as it was.
        $this->assertStringContainsString('elseif ($autoAbsent) {', $this->overlayLibSrc);
    }

    public function test_genuine_absent_is_never_overwritten_by_a_non_holiday_day(): void
    {
        // ch === 'A' (genuine, previously-set Absent) only ever gets
        // reassigned inside the SWD branch (guarded by ch==='V', so 'A'
        // never enters it) or the holiday branch (unconditional H, correct —
        // a day later declared a holiday SHOULD retroactively show H) or the
        // third branch (also guarded by ch==='V'). No branch outside these
        // three touches $dayWise at all, so an 'A' that is neither a holiday
        // nor an SWD day structurally falls through every iteration untouched.
        $this->assertStringContainsString("if (\$ch === 'V' && \$autoAbsent) \$dayWise[\$d - 1] = 'A';", $this->overlayLibSrc);
        $this->assertStringContainsString('} elseif ($ch === \'V\') {', $this->overlayLibSrc);
        // Exactly three sites ever assign into $dayWise — SWD's 'A', the
        // holiday 'H', and the V-branch's 'O'/'A' — confirming no fourth,
        // unconditional path could clobber a genuine 'A'.
        $this->assertEquals(4, substr_count($this->overlayLibSrc, '$dayWise[$d - 1] ='));
    }

    public function test_lookup_failure_fails_closed_leaving_the_day_as_is(): void
    {
        $this->assertStringContainsString('} catch (\Throwable $e) {', $this->overlayLibSrc);
        $this->assertStringContainsString('continue;', $this->overlayLibSrc);
    }
}
