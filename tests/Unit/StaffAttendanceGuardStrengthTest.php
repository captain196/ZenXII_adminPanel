<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Which staff-attendance guards are HARD, and which are guard rails.
 *
 * The DEV-1 sweep asked of every control: does it actually stop the thing it
 * appears to stop? For the staff register the answer splits cleanly, and the
 * split is worth pinning, because from the operator's side both look the same —
 * a cell you cannot click.
 *
 *   HARD (server-enforced, cannot be bypassed by a crafted POST)
 *     • the month lock — every write path goes through
 *       Staff_attendance_writer, which throws MonthLockedException
 *
 *   GUARD RAIL (client-side only, by design)
 *     • weekly-off / holiday / future / unresolvable-schedule cell locks
 *
 * THE GUARD RAILS ARE NOT A DEFECT, and were deliberately left alone.
 * `allowWorkOnOff` exists precisely so a school CAN record work on a rest day,
 * and 'W' (work-on-off) is a first-class mark in the vocabulary. Hard-refusing
 * marks on non-working days server-side would contradict a shipped feature, and
 * deciding which overrides are legitimate is a policy question, not an
 * engineering one. What matters is that nobody later mistakes the UI lock for
 * enforcement — which is exactly the assumption that made DEV-1 and DEV-2
 * survive as long as they did.
 *
 * GUARD-1 was raised against the 'blocked' cell lock and RESOLVED: its wording
 * is factual and it names its own remedy (assign a work schedule), so it is a
 * guard rail with a way out rather than a dead end. The test below keeps it
 * that way.
 */
final class StaffAttendanceGuardStrengthTest extends TestCase
{
    private static string $att    = '';
    private static string $view   = '';
    private static string $writer = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$att    = (string) file_get_contents($root . 'application/controllers/Attendance.php');
        self::$view   = (string) file_get_contents($root . 'application/views/attendance/staff.php');
        self::$writer = (string) file_get_contents($root . 'application/libraries/Staff_attendance_writer.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = self::$view = self::$writer = '';
    }

    private function methodOf(string $name): string
    {
        $i = strpos(self::$att, 'function ' . $name);
        $this->assertNotFalse($i, "{$name} must exist");

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos(self::$att, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        $this->assertNotEmpty($next);
        return substr(self::$att, (int) $i, min($next) - (int) $i);
    }

    // ── The hard guard ───────────────────────────────────────────────────

    /**
     * The month lock is enforced on BOTH staff write paths, so it cannot be
     * bypassed by posting directly. This is the guard that protects payroll,
     * and it is the one that had to be hard.
     */
    public function test_the_month_lock_is_enforced_on_every_write_path(): void
    {
        foreach (['_save_staff_attendance_fs', '_mark_staff_day_fs'] as $path) {
            $this->assertStringContainsString('MonthLocked', $this->methodOf($path),
                "{$path} must refuse a closed month server-side");
        }

        // Because the writer itself refuses, not merely the controller.
        $this->assertStringContainsString('throw new MonthLockedException', self::$writer);
        $this->assertStringContainsString('$lock = $this->lock_cache->is_locked(', self::$writer);
    }

    /** And the register cannot be edited client-side either (GAP-1). */
    public function test_a_closed_month_is_also_refused_in_the_browser(): void
    {
        $this->assertStringContainsString('function canEditNow() { return CAN_EDIT && !MONTH_LOCKED; }', self::$view);
        $this->assertSame(0, substr_count(self::$view, '!CAN_EDIT'),
            'every gate must consult the lock, not the bare permission');
    }

    // ── The guard rails, stated honestly ─────────────────────────────────

    /**
     * The cell locks are client-side. Pinned so that a future reader does not
     * assume the server refuses these — the assumption that let DEV-1 and DEV-2
     * survive.
     */
    public function test_cell_locks_are_client_side_by_design(): void
    {
        $this->assertStringContainsString('function dateLockReason', self::$view);
        foreach (['weeklyoff', 'holiday', 'future', 'blocked'] as $reason) {
            $this->assertStringContainsString("'{$reason}'", self::$view);
        }

        // The server does NOT re-check them on the bulk save.
        $save = $this->methodOf('_save_staff_attendance_fs');
        foreach (['weeklyOff', 'nonWorking', 'dateLockReason'] as $absent) {
            $this->assertStringNotContainsString($absent, $save,
                'if this becomes server-enforced, revisit allowWorkOnOff — a school '
                . 'is entitled to record work on a rest day');
        }
    }

    /**
     * Working on a rest day is a SUPPORTED outcome, which is why the rest-day
     * cell lock must not become a server refusal.
     */
    public function test_working_on_a_rest_day_is_a_supported_outcome(): void
    {
        $this->assertStringContainsString("'allowWorkOnOff'", self::$att,
            'the policy that permits it');
        $this->assertStringContainsString("'W'", self::$att,
            'work-on-off is a first-class mark, not an anomaly');

        // And payroll has a disposition for it, so recording it is not a leak.
        $hr = (string) file_get_contents(__DIR__ . '/../../application/controllers/Hr.php');
        $this->assertStringContainsString("'W'", $hr);
    }

    /**
     * The 'blocked' cell lock must always name its remedy.
     *
     * It is the only guard rail that states a condition rather than a rule, so
     * it is the one that could read as a dead end. It does not, because the
     * sentence tells the operator how to clear it — and that is the property
     * worth pinning.
     */
    public function test_the_blocked_lock_always_names_its_remedy(): void
    {
        $this->assertStringContainsString(
            'This employee has no resolvable work schedule for this period', self::$view);

        // Server-side, nothing refuses such a mark.
        $save = $this->methodOf('_save_staff_attendance_fs');
        $this->assertStringNotContainsString('blocked', $save);

        // RESOLVED, not left open. The wording is FACTUAL — the employee really
        // has no resolvable schedule for the period, and the sentence names the
        // remedy (assign one under Work Schedules). It is a guard rail with a
        // documented way out, not a claim the server does not back:
        //
        //   • server enforcement was considered and REJECTED — `allowWorkOnOff`
        //     exists so a school CAN record work on a rest day, and hard-refusing
        //     marks server-side would contradict a shipped feature;
        //   • the operator is never stuck: assigning a work schedule clears it.
        //
        // What must not drift is the remedy. If the message ever stops naming a
        // way out, this becomes the dead end it was suspected of being.
        $this->assertStringContainsString('Work Schedules', self::$view,
            'the lock must always tell the operator how to clear it');
    }

    // ── The mark vocabulary is closed on every path ─────────────────────

    /** No write path can introduce a mark payroll has no disposition for. */
    public function test_every_path_constrains_the_mark_vocabulary(): void
    {
        $this->assertStringContainsString('_validate_inputs($staffId, $dateISO, $status)', self::$writer,
            'the writer validates centrally, so no caller can widen the vocabulary');

        $this->assertStringContainsString(
            "\$staffStatus = ['P', 'A', 'L', 'H', 'T', 'V', 'M', 'W', 'O'];", self::$att);
    }
}
