<?php

use PHPUnit\Framework\TestCase;

/**
 * The period caps must be enforced by the SERVER on BOTH writers of
 * `subjectAssignments`, not by the browser on one of them.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 *
 * `save_subject_assignments()` has always refused a curriculum the week cannot
 * hold: no subject may ask for more periods than there are working days, and
 * the total may not exceed periods/day × working days.
 *
 * `save_class_matrix()` writes the SAME collection and enforced neither. The
 * only guard was in the Subject Assignment screen's JavaScript, so any request
 * that did not come from that screen could store an impossible curriculum.
 *
 * Observed, not hypothetical: Class 9th in the live tenant asks for 45 periods
 * against a 36-period week. The generator then silently under-delivers
 * (English 10 short, Hindi 5 short) and it surfaces later in a timetable
 * report, looking like a generator failure rather than data that never fit.
 * ACAD-D14 and ACAD-D18 were the same shape — two writers, one guard.
 *
 * Through the UI nothing changes: the screen already refuses both conditions
 * before posting. This closes the bypass around it.
 */
final class SubjectMatrixPeriodCapTest extends TestCase
{
    private static string $ctrl = '';

    public static function setUpBeforeClass(): void
    {
        self::$ctrl = (string) file_get_contents(
            __DIR__ . '/../../application/controllers/Academic.php');
    }

    /** save_class_matrix's own body, bounded by the next method. */
    private function matrixBody(): string
    {
        $i = strpos(self::$ctrl, 'public function save_class_matrix');
        $this->assertNotFalse($i, 'save_class_matrix is gone');
        $next = strpos(self::$ctrl, "\n    public function ", $i + 10);
        return substr(self::$ctrl, $i, ($next !== false ? $next - $i : 20000));
    }

    private function siblingBody(): string
    {
        $i = strpos(self::$ctrl, 'public function save_subject_assignments');
        $this->assertNotFalse($i, 'save_subject_assignments is gone');
        $next = strpos(self::$ctrl, "\n    public function ", $i + 10);
        return substr(self::$ctrl, $i, ($next !== false ? $next - $i : 20000));
    }

    // ── the premise ─────────────────────────────────────────────────────

    /**
     * This test exists because the two writers disagreed. If the sibling ever
     * stops enforcing, the rule itself should be re-derived rather than this
     * test quietly enforcing a standard nothing else holds.
     */
    public function test_the_sibling_writer_still_enforces_both_caps(): void
    {
        $s = $this->siblingBody();
        $this->assertStringContainsString('$maxPeriodsPerWeek', $s,
            'the sibling no longer computes a weekly cap');
        $this->assertStringContainsString('exceeds the maximum', $s,
            'the sibling no longer refuses an over-subscribed total');
    }

    // ── the fix ─────────────────────────────────────────────────────────

    public function test_the_matrix_writer_refuses_a_subject_that_exceeds_the_working_days(): void
    {
        $m = $this->matrixBody();
        $this->assertStringContainsString('$pw > $numWorkingDays', $m,
            'a subject may still ask for more periods than there are working days, '
            . 'which is the same subject twice in a day for at least one day');
        $this->assertStringContainsString('but there are only', $m,
            'the refusal does not say what the limit is');
    }

    public function test_the_matrix_writer_refuses_an_over_subscribed_total(): void
    {
        $m = $this->matrixBody();
        $this->assertStringContainsString('$totalAssigned > $maxPeriodsPerWeek', $m,
            'a curriculum larger than the week can hold can still be stored');
        $this->assertStringContainsString('exceeds the', $m,
            'the refusal does not state the maximum');
    }

    /**
     * The refusal must land in $errors, which aborts BEFORE any write. A check
     * that returned after the delete phase would leave the class half-emptied.
     */
    public function test_the_refusal_aborts_before_any_data_is_touched(): void
    {
        $m = $this->matrixBody();

        $capAt = strpos($m, '$totalAssigned > $maxPeriodsPerWeek');
        $this->assertNotFalse($capAt);

        $abortAt = strpos($m, 'if (!empty($errors))');
        $this->assertNotFalse($abortAt, 'the pre-write abort is gone');
        $this->assertLessThan($abortAt, $capAt,
            'the cap is computed after the abort, so it could not prevent a write');

        foreach (['commitBatchStatus(', "remove('subjectAssignments'"] as $write) {
            $w = strpos($m, $write);
            if ($w === false) { continue; }
            $this->assertLessThan($w, $abortAt,
                "the abort must precede {$write}, or a refused save still writes");
        }
    }

    // ── counter-tests: it must not over-reach ───────────────────────────

    /**
     * COUNTER-TEST. A school that has not configured period scheduling has no
     * cap to apply. Inventing one would refuse every subject assignment for a
     * new school — and would look identical in a green suite.
     */
    public function test_an_unconfigured_week_is_not_given_an_invented_cap(): void
    {
        $m = $this->matrixBody();

        $this->assertStringContainsString('if ($maxPeriodsPerWeek > 0)', $m,
            'the caps are applied even when period scheduling is unconfigured, '
            . 'which would refuse every save for a school still being set up');

        $this->assertMatchesRegularExpression(
            '~periodsPerDay\'\]\s*\?\?\s*\$ttSettings\[\x27No_of_periods\'\]\s*\?\?\s*0~', $m,
            'an absent period count must fall back to 0 (no cap), not to a number '
            . 'that fabricates one');
    }

    /**
     * COUNTER-TEST. saveSettings() dual-writes both key shapes; reading only
     * one would silently disable the cap if that ever stopped.
     */
    public function test_both_settings_key_shapes_are_read(): void
    {
        $m = $this->matrixBody();

        foreach (['periodsPerDay', 'No_of_periods', 'workingDays', 'Working_days'] as $key) {
            $this->assertStringContainsString($key, $m,
                "the '{$key}' settings shape is not read, so the cap would vanish "
                . 'for a tenant stored in that shape alone');
        }
    }

    /**
     * COUNTER-TEST. An unreadable settings document must not become a refusal:
     * the caps are a limit on what may be stored, not a reason to block work
     * when the limit itself cannot be read. It is logged instead.
     */
    public function test_an_unreadable_settings_document_does_not_block_the_save(): void
    {
        $m = $this->matrixBody();

        $i = strpos($m, "timetableSettings read failed");
        $this->assertNotFalse($i, 'a failed settings read is not logged');

        $window = substr($m, max(0, $i - 300), 600);
        $this->assertStringContainsString('log_message', $window,
            'the failed read is not recorded');
        $this->assertStringNotContainsString('json_error', $window,
            'an unreadable settings document refuses the save outright');
    }

    /**
     * The tenant and session come from the authenticated context, never the
     * request — otherwise the cap could be read from another school's week.
     */
    public function test_the_settings_document_is_tenant_bound_from_the_server(): void
    {
        $m = $this->matrixBody();
        $this->assertStringContainsString(
            '"{$this->school_id}_{$this->session_year}"', $m,
            'the settings document id is not built from the server-side tenant and session');
    }
}
