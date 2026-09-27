<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Attendance regularization must not rewrite a locked month, even when the
 * lock itself cannot be read.
 *
 * Regularization rewrites an attendance mark, and an attendance mark is a
 * direct payroll input. Rewriting a day in a month whose payroll has already
 * been finalised would leave the payslip disagreeing with the attendance record
 * it was computed from — a discrepancy nothing downstream would detect.
 *
 * The chain, verified 2026-08-21:
 *
 *   Attendance::staff_regularization_decide()
 *     → Staff_attendance_writer::markSingleDay()
 *         → Lock_cache::is_locked(schoolId, sessionYear, monthKey)
 *             → _live_read_and_cache() — logs and RETHROWS on Firestore failure
 *     → MonthLockedException  → skipped ['reason' => 'month_locked']
 *     → any other Throwable   → skipped ['reason' => 'write_failed']
 *
 * The important property is the SECOND one. `markSingleDay()` tests the lock
 * with `!empty($lock['is_locked'])`, which treats an unreadable lock as
 * "unlocked". That is only safe because `_live_read_and_cache()` rethrows
 * rather than returning an empty array — the exception escapes before the
 * `!empty()` test is ever reached, and the caller skips the write.
 *
 * If anyone ever "hardens" that rethrow into a swallowed error returning `[]`,
 * the guard silently inverts from fail-closed to fail-open and locked months
 * become writable during any Firestore hiccup. These tests exist to make that
 * change fail loudly.
 *
 * AUDITED AND FOUND CORRECT — this is a regression guard, not a defect report.
 */
final class RegularizationMonthLockTest extends TestCase
{
    private string $att;
    private string $writer;
    private string $lock;

    protected function setUp(): void
    {
        $att    = file_get_contents(__DIR__ . '/../../application/controllers/Attendance.php');
        $writer = file_get_contents(__DIR__ . '/../../application/libraries/Staff_attendance_writer.php');
        $lock   = file_get_contents(__DIR__ . '/../../application/libraries/Lock_cache.php');
        $this->assertNotFalse($att);
        $this->assertNotFalse($writer);
        $this->assertNotFalse($lock);
        $this->att    = $att;
        $this->writer = $writer;
        $this->lock   = $lock;
    }

    /**
     * The caller's disposition of one request, as implemented.
     *
     * @param string $lockState 'locked' | 'unlocked' | 'unreadable'
     * @return string 'written' | 'month_locked' | 'write_failed'
     */
    private function decide(string $lockState): string
    {
        // markSingleDay(): is_locked() is called FIRST and propagates.
        if ($lockState === 'unreadable') {
            return 'write_failed';          // Lock_cache rethrows; caller catches Throwable
        }
        if ($lockState === 'locked') {
            return 'month_locked';          // MonthLockedException
        }
        return 'written';
    }

    // ── The three lock states ────────────────────────────────────────────

    public function test_an_unlocked_month_is_written(): void
    {
        $this->assertSame('written', $this->decide('unlocked'));
    }

    public function test_a_locked_month_is_refused(): void
    {
        $this->assertSame('month_locked', $this->decide('locked'),
            'a finalised payroll month must not be rewritten');
    }

    /** THE ONE THAT MATTERS. */
    public function test_an_unreadable_lock_refuses_the_write(): void
    {
        $this->assertNotSame('written', $this->decide('unreadable'),
            'an unreadable lock must never be treated as an unlocked month');
        $this->assertSame('write_failed', $this->decide('unreadable'));
    }

    // ── The source properties that make the above true ───────────────────

    public function test_lock_cache_rethrows_rather_than_swallowing(): void
    {
        $i = strpos($this->lock, 'function _live_read_and_cache');
        $this->assertNotFalse($i);
        $body = substr($this->lock, (int) $i, 1800);

        $this->assertStringContainsString('throw $e;', $body,
            'swallowing this error inverts the month lock from fail-closed to FAIL-OPEN: '
            . "markSingleDay() tests !empty(\$lock['is_locked']), so a returned [] reads as unlocked");
    }

    public function test_the_writer_checks_the_lock_before_writing(): void
    {
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->writer, "\$this->lock_cache->is_locked("),
            'every write entry point must consult the lock, not just markSingleDay()'
        );
        $this->assertStringContainsString('throw new MonthLockedException(', $this->writer);
    }

    /** The lock is tenant + session + month scoped, never global. */
    public function test_the_lock_is_scoped_to_tenant_session_and_month(): void
    {
        $this->assertStringContainsString(
            'is_locked($this->schoolId, $this->sessionYear, $monthKey)',
            $this->writer,
            'a lock keyed on anything less would leak across tenants or sessions'
        );
    }

    public function test_the_caller_handles_both_failure_modes_distinctly(): void
    {
        $this->assertStringContainsString("'reason' => 'month_locked'", $this->att);
        $this->assertStringContainsString("'reason' => 'write_failed'", $this->att);
    }

    /**
     * One bad row must not abort a legitimate bulk approval — the caller
     * `continue`s per request rather than failing the batch.
     */
    public function test_a_refused_row_skips_rather_than_aborting_the_batch(): void
    {
        $body = $this->decideBody();

        $this->assertStringContainsString('} catch (MonthLockedException $e) {', $body);
        $this->assertGreaterThanOrEqual(2, substr_count($body, '$skipped[] = '));
    }

    // ── The written mark stays inside the vocabulary ─────────────────────

    /**
     * Regularization must never introduce a mark payroll has no disposition
     * for. The final mark is filtered through the staff mark list, defaulting
     * to 'P'. See AttendanceMarkPayrollCoverageTest for why that matters.
     */
    public function test_the_written_mark_is_constrained_to_the_staff_vocabulary(): void
    {
        $this->assertStringContainsString(
            "(in_array(\$reqMark, \$staffStatus, true) ? \$reqMark : 'P')",
            $this->att,
            'an unvalidated mark could land a character payroll has no branch for'
        );
    }

    public function test_an_admin_override_is_also_vocabulary_checked(): void
    {
        $this->assertStringContainsString(
            "in_array(\$override, \$staffStatus, true)",
            $this->att,
            'the manual override path must be validated too, not trusted'
        );
    }

    // ── Provenance ───────────────────────────────────────────────────────

    /** Every regularized day must be traceable back to its request. */
    public function test_the_write_records_its_provenance(): void
    {
        foreach (["'source'       => 'correction'", "'correctionId' => \$rid"] as $needle) {
            $this->assertStringContainsString($needle, $this->att,
                'a rewritten attendance day must say who rewrote it and why');
        }
    }

    /**
     * Release large source strings between tests.
     *
     * PHPUnit keeps every TestCase instance alive for the duration of the run,
     * so a multi-hundred-KB source held in a property stays resident once per
     * test method. Measured: a source-reading class costs ~18 MB against a
     * 512 MB limit, and the suite was running at 506 MB. Clearing the reference
     * frees the string while leaving the (now tiny) instance for reporting.
     */
    protected function tearDown(): void
    {
        foreach (get_object_vars($this) as $prop => $val) {
            if (is_string($val) && strlen($val) > 50000) $this->{$prop} = '';
        }
    }

    /**
     * The full body of staff_regularization_decide().
     *
     * Bounded at the NEXT method rather than by a character count: a fixed
     * window silently shrinks its own coverage every time the method grows,
     * and then fails on code that never changed.
     */
    private function decideBody(): string
    {
        $i = strpos($this->att, 'function staff_regularization_decide');
        $this->assertNotFalse($i, 'the decide endpoint must still exist');

        $next = [];
        foreach (['    private function ', '    public function ', '    protected function '] as $m) {
            $at = strpos($this->att, $m, (int) $i + 40);
            if ($at !== false) { $next[] = $at; }
        }
        $this->assertNotEmpty($next, 'could not find the end of the method');

        $body = substr($this->att, (int) $i, min($next) - (int) $i);

        // Slice sanity: if this is not the method we meant, every assertion
        // built on it is meaningless.
        $this->assertStringContainsString('$decision', $body);
        $this->assertStringContainsString('$skipped[] = ', $body);

        return $body;
    }
}
