<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ACAD-D5 — the teacher double-booking guard fails OPEN.
 *
 * Timetable_service::savePeriod() blocks a double-booking properly:
 *
 *     throw new Service_exception("Teacher conflict: {$teacherName} is already
 *         assigned to {$label} on {$day} period N. Remove that assignment
 *         first.", 'conflict');
 *
 * but the evidence it reasons over is fetched like this:
 *
 *     $allDayDocs = [];
 *     try   { $allDayDocs = firestoreQuery(TT, [schoolId, session, day], …, 200); }
 *     catch (\Throwable $e) {}          // silent
 *     foreach ($allDayDocs as $doc) { … build $dayTimetables … }
 *
 * If that query throws, $dayTimetables is empty, the conflict loop finds
 * nothing, and the save proceeds — silently scheduling one teacher into two
 * class-sections in the same period. The guard does not report that it could
 * not evaluate; it reports that there is no conflict.
 *
 * CORRECTION (2026-08-28). An earlier revision of this comment listed the
 * missing `day` composite index as an aggravator: no deployed timetables index
 * names `day` (deployed: schoolId+session, schoolId+sectionKey,
 * schoolId+sectionKey+session, schoolId+className+session,
 * schoolCode+classKey+sectionKey). The premise is true; the inference was not.
 * This query carries THREE equality filters and no orderBy, and Firestore
 * serves equality-only queries from its automatic single-field indexes, so no
 * composite index is required. The logs bear this out: across every retained
 * log file, FAILED_PRECONDITION appears only for `tripLogs` and
 * `campusAccessRequests`, never for `timetables`, and there is not one non-200
 * on this collection. The index is not the trigger.
 *
 * THE REAL TRIGGER, found later and recorded as ACAD-D12: query() does not
 * throw at all. It turns every non-200 — and a curl failure, and an
 * uninitialised client — into `return []`, which this guard cannot tell apart
 * from "no conflict". The catch below was therefore unreachable for every
 * realistic failure mode, and the fix was a fail-loud read
 * (firestoreQueryOrFail), not an index. See NegativeGuardFailLoudTest.
 *
 * The remaining aggravator stands: a silent limit of 200 day-docs truncates
 * the search with no signal.
 *
 * The same codebase already does this correctly elsewhere:
 * Curriculum_service::_canEdit() catches Throwable and returns FALSE. A guard
 * that cannot evaluate must deny, not allow.
 */
final class TimetableConflictGuardFailClosedTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $p = __DIR__ . '/../../application/libraries/Timetable_service.php';
        $this->assertFileExists($p);
        $this->src = (string) file_get_contents($p);
    }

    private function method(string $name): string
    {
        $i = strpos($this->src, "function {$name}(");
        $this->assertNotFalse($i, "{$name}() is gone");
        $end = strpos($this->src, "\n    public function ", $i + 10);
        if ($end === false) $end = strpos($this->src, "\n    private function ", $i + 10);
        $this->assertNotFalse($end, "could not bound {$name}()");
        return substr($this->src, $i, $end - $i);
    }

    /**
     * Method source with comments stripped. Prose that merely NAMES a call
     * (log_message, throw…) otherwise satisfies assertions about it — a trap
     * that has already let mutants survive in this suite.
     */
    private function codeOnly(string $name): string
    {
        $s = preg_replace('~/\*.*?\*/~s', '', $this->method($name));
        return (string) preg_replace('~^\s*//.*$~m', '', (string) $s);
    }

    /** Premise: the guard exists and blocks. */
    public function test_the_double_booking_guard_still_throws(): void
    {
        $fn = $this->method('savePeriod');

        $this->assertStringContainsString('Teacher conflict:', $fn,
            'savePeriod no longer blocks a teacher double-booking');
        $this->assertMatchesRegularExpression("~throw new Service_exception\([^;]*'conflict'~s", $fn,
            'the conflict must be raised as a conflict-typed exception');
    }

    /**
     * DEFECT ACAD-D5 — the evidence fetch must not swallow its failure.
     * A silent empty result is indistinguishable from "no conflict".
     */
    public function test_the_conflict_evidence_fetch_does_not_fail_open(): void
    {
        $fn = $this->codeOnly('savePeriod');

        // Target the DAY query specifically — the one the conflict loop reasons
        // over. Other catches in this method feed warnings, not the hard guard,
        // and lumping them in would overstate the defect.
        $this->assertMatchesRegularExpression(
            '~day.{0,30}\$day.{0,300}?catch\s*\([^)]*\)\s*\{(?!\s*\}).{0,900}?throw new Service_exception~s',
            $fn,
            "savePeriod swallows the failure of the very query its conflict guard\n"
            . "reasons over. An empty day map reads as 'no conflict', so the save\n"
            . 'proceeds and double-books the teacher.');
    }

    /** Same defect in the standalone pre-save checker. */
    public function test_the_standalone_checker_does_not_fail_open(): void
    {
        $fn = $this->codeOnly('detectConflicts');

        $this->assertMatchesRegularExpression(
            '~day.{0,30}\$day.{0,300}?catch\s*\([^)]*\)\s*\{(?!\s*\}).{0,900}?throw new Service_exception~s',
            $fn,
            'detectConflicts() returns conflict=false when its query fails');
    }

    /**
     * Failing closed is only meaningful if the failure is visible. Whatever
     * replaces the empty catch must log — a silent deny is its own trap.
     */
    public function test_the_failure_is_logged(): void
    {
        $fn = $this->codeOnly('savePeriod');

        // One regex over comment-stripped code: the day query's catch must
        // BOTH log and throw. Offsets from the raw source cannot be used here —
        // stripping comments shifts them.
        $this->assertMatchesRegularExpression(
            '~day.{0,30}\$day.{0,300}?catch\s*\([^)]*\)\s*\{.{0,600}?log_message.{0,600}?throw new Service_exception~s',
            $fn,
            'a guard that denies on error must say why, or it is undiagnosable');
    }
}
