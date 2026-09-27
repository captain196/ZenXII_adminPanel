<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The complete staff attendance flow, seam by seam.
 *
 *   Teacher app  → attendanceRegularizations   (deterministic id, pending, P|M)
 *   admin queue  → staff_regularization_list   (tenant-scoped, grouped by batch)
 *   decision     → staff_regularization_decide (payroll guard → CAS claim → writer)
 *   ledger       → Staff_attendance_writer     (staffAttendance + staffAttendanceSummary)
 *   payroll      → staffAttendanceSummary.dayWise
 *   month close  → staffAttendanceLocks        (writer refuses; UI can set it)
 *
 * Every one of those arrows is a HANDOFF between components that are edited
 * separately — two repositories, in two languages, plus a rules file a third
 * party owns. Each individual piece can be correct while a handoff is broken:
 * a renamed field, a doc id composed differently on the two sides, a mark the
 * next stage has no disposition for. Nothing else in the suite watches the
 * joins, which is exactly where this kind of system fails.
 *
 * These tests assert the joins, not the pieces.
 */
final class StaffAttendanceFlowSeamTest extends TestCase
{
    private const APP_REPO = 'D:/Projects/SchoolSyncTeacher';

    private static string $att    = '';
    private static string $writer = '';
    private static string $hr     = '';
    private static string $rules  = '';
    private static string $lock   = '';

    public static function setUpBeforeClass(): void
    {
        $root = __DIR__ . '/../../';
        self::$att    = (string) file_get_contents($root . 'application/controllers/Attendance.php');
        self::$writer = (string) file_get_contents($root . 'application/libraries/Staff_attendance_writer.php');
        self::$hr     = (string) file_get_contents($root . 'application/controllers/Hr.php');
        self::$rules  = (string) file_get_contents($root . 'firebase-rules/firestore.rules');
        self::$lock   = (string) file_get_contents($root . 'application/libraries/Lock_cache.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$att = self::$writer = self::$hr = self::$rules = self::$lock = '';
    }

    private function appFile(string $rel): ?string
    {
        $p = self::APP_REPO . $rel;
        return is_file($p) ? (string) file_get_contents($p) : null;
    }

    // ── SEAM 1 — Teacher app → Firestore ─────────────────────────────────

    /**
     * The app and the admin panel must name the same collection. This is a
     * cross-repository, cross-language constant: nothing but a test connects
     * a Kotlin `const val` to a PHP string literal.
     */
    public function test_both_repos_name_the_same_collection(): void
    {
        $consts = $this->appFile('/app/src/main/java/com/schoolsync/teacher/util/Constants.kt');
        if ($consts === null) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString(
            'const val ATTENDANCE_REGULARIZATIONS = "attendanceRegularizations"', $consts);
        $this->assertStringContainsString("'attendanceRegularizations'", self::$att);
        $this->assertStringContainsString('match /attendanceRegularizations/{docId}', self::$rules);
    }

    /**
     * Identity is the load-bearing seam of this entire flow.
     *
     * The rule pins staffId == auth.uid on create; the GPS punch path derives
     * the same uid from the token and addresses `staff/{schoolId}_{uid}`; the
     * decision hands that same staffId to the writer, which composes
     * `staffAttendanceSummary/{schoolId}_{staffId}_{month}`. If any one of
     * those moved to a differently-shaped id, approvals would stamp a row the
     * register never shows, and the self-approval guard would compare two
     * namespaces and never match.
     */
    public function test_one_identity_runs_through_the_whole_chain(): void
    {
        // Rule: the client may only claim its own uid.
        $this->assertStringContainsString('request.resource.data.staffId == request.auth.uid', self::$rules);

        // Punch: uid from the token, staff doc addressed by it.
        $punch = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff_attendance.php');
        $this->assertStringContainsString("\$staffId = (string) (\$this->_claims['uid'] ?? '');", $punch);

        // Writer: the summary doc is composed from that same staffId.
        $this->assertStringContainsString('_summary_doc_id($staffId, $monthKey)', self::$writer);

        // Decision: hands the request's staffId straight to the writer.
        $this->assertStringContainsString(
            '$this->staff_attendance_writer->markSingleDay($staffId, $date, $finalMark', self::$att);
    }

    // ── SEAM 2 — the queue ───────────────────────────────────────────────

    /** The queue reads only staff requests of this tenant. */
    public function test_the_queue_is_scoped_to_staff_and_tenant(): void
    {
        $i = strpos(self::$att, 'function staff_regularization_list');
        $this->assertNotFalse($i);
        $body = substr(self::$att, (int) $i, 2200);

        $this->assertStringContainsString("schoolWhere('attendanceRegularizations'", $body);
        $this->assertStringContainsString("['personType', '==', 'staff']", $body);
    }

    /**
     * The app files one document PER DATE sharing a batchId, and the panel
     * decides per batch. If the app stopped writing batchId the queue would
     * still render — as one card per day, silently fragmenting a single
     * request into several.
     */
    public function test_the_batch_grouping_matches_what_the_app_writes(): void
    {
        $repo = $this->appFile(
            '/app/src/main/java/com/schoolsync/teacher/data/repository/firestore/AttendanceRegularizationRepository.kt');
        if ($repo === null) { $this->markTestSkipped('Teacher app repo not present'); }

        $this->assertStringContainsString('"batchId" to batchId', $repo);
        $this->assertStringContainsString("\$bid = (string) (\$d['batchId']", self::$att);
        $this->assertStringContainsString("['batchId',    '==', \$batchId],", self::$att,
            'deciding a whole request must select by the same key the app groups by');
    }

    // ── SEAM 3 — decision → ledger ───────────────────────────────────────

    /**
     * The approval writes through the SAME canonical writer the GPS punch
     * uses. A second writer would have to re-implement the summary maths,
     * the counters and the lock check — and would drift.
     */
    public function test_the_approval_writes_through_the_canonical_writer(): void
    {
        $punch = (string) file_get_contents(__DIR__ . '/../../application/controllers/Staff_attendance.php');

        $this->assertStringContainsString('staff_attendance_writer->markSingleDay(', $punch);
        $this->assertStringContainsString('staff_attendance_writer->markSingleDay(', self::$att);
        $this->assertStringContainsString("'source'       => 'correction',", self::$att,
            'the corrected day must be distinguishable from a live punch in the ledger');
    }

    /** The writer updates both the per-day document and the month summary. */
    public function test_the_writer_updates_the_day_and_the_month(): void
    {
        $this->assertStringContainsString("'staffAttendance'", self::$writer);
        $this->assertStringContainsString("firestoreGet('staffAttendanceSummary'", self::$writer);
        $this->assertStringContainsString('$newDayWise', self::$writer,
            'the month summary string is what payroll later reads');
    }

    // ── SEAM 4 — ledger → payroll ────────────────────────────────────────

    /**
     * Payroll reads the very field the writer maintains. This is the seam that
     * turns an attendance correction into money.
     */
    public function test_payroll_reads_the_summary_the_writer_maintains(): void
    {
        $this->assertStringContainsString("schoolWhere('staffAttendanceSummary'", self::$hr);
        $this->assertStringContainsString("\$d['dayWise']", self::$hr);
        $this->assertStringContainsString("'dayWise'", self::$writer);
    }

    /**
     * A regularization can only apply a mark from the staff vocabulary, and
     * that vocabulary is what payroll has dispositions for. An invented mark
     * would reach a payslip as an unhandled character.
     *
     * @see AttendanceMarkPayrollCoverageTest for the disposition map itself
     */
    public function test_no_correction_can_introduce_a_mark_payroll_cannot_read(): void
    {
        $this->assertStringContainsString(
            "\$staffStatus = ['P', 'A', 'L', 'H', 'T', 'V', 'M', 'W', 'O'];", self::$att);
        $this->assertStringContainsString('in_array($reqMark, $staffStatus, true)', self::$att);

        // The rule narrows the CLIENT further still.
        $this->assertStringContainsString("request.resource.data.requestedStatus in ['P', 'M']", self::$rules);
    }

    // ── SEAM 5 — the month close ─────────────────────────────────────────

    /**
     * The lock is written and read by the same doc id. These live in different
     * files with independently written string composition, so a change to
     * either side alone silently produces a lock that is set but never seen —
     * the worst possible failure for this control, because it reports success.
     */
    public function test_the_lock_is_written_and_read_by_the_same_key(): void
    {
        $this->assertStringContainsString(
            '$docId = "{$this->school_id}_{$this->session_year}_{$monthKey}";', self::$att);
        $this->assertStringContainsString(
            '$docId = "{$schoolId}_{$session}_{$monthKey}";', self::$lock);

        $this->assertStringContainsString("firestoreSet('staffAttendanceLocks'", self::$att);
        $this->assertStringContainsString("firestoreGet('staffAttendanceLocks'", self::$lock);

        // Same flag on both sides.
        $this->assertStringContainsString("'isLocked'   => true,", self::$att);
        $this->assertStringContainsString("!empty(\$doc['isLocked'])", self::$lock);
    }

    /** A locked month stops the writer, whichever path called it. */
    public function test_a_locked_month_stops_every_write_path(): void
    {
        $this->assertStringContainsString('$lock = $this->lock_cache->is_locked(', self::$writer);
        $this->assertStringContainsString('throw new MonthLockedException', self::$writer);

        // And the decision handles that exception distinctly, releasing its claim.
        $this->assertStringContainsString('} catch (MonthLockedException $e) {', self::$att);
        $this->assertStringContainsString("\$this->_reg_release(\$rid, 'month_locked');", self::$att);
    }

    /**
     * Setting or clearing the lock invalidates the cache the writer consults.
     * Without this a just-closed month stays writable for the cache TTL.
     */
    public function test_closing_a_month_takes_effect_immediately(): void
    {
        $this->assertSame(2, substr_count(self::$att, '$this->lock_cache->invalidate('),
            'both lock and unlock must invalidate, or the writer keeps the old verdict');
    }

    // ── SEAM 6 — the two locks ───────────────────────────────────────────

    /**
     * The payroll period lock and the attendance month lock are SEPARATE
     * systems, and nothing couples them. That is why the regularization path
     * checks the payroll lock explicitly: locking August's payroll does not
     * lock August's register, so without this guard an approved correction
     * could rewrite the attendance a finalised payslip was computed from.
     *
     * If the two are ever coupled properly, this test should fail and be
     * replaced by one asserting the coupling.
     */
    public function test_the_payroll_lock_is_checked_because_it_is_not_coupled(): void
    {
        // Payroll writes its lock somewhere else entirely.
        $this->assertStringContainsString('"payrollLocks.{$lockKey}" => $lockData,', self::$hr);
        $this->assertStringNotContainsString('staffAttendanceLocks', self::$hr,
            'if payroll ever starts writing the attendance lock, the explicit guard '
            . 'in the regularization path should be revisited');

        // So the regularization path reads it directly.
        $this->assertStringContainsString('private function _payroll_month_locked', self::$att);
        $this->assertStringContainsString("\$doc['payrollLocks']", self::$att);
    }

    /** Both locks are keyed by the month they protect, in their own formats. */
    public function test_each_lock_uses_its_own_key_format_consistently(): void
    {
        // Payroll: {YYYY}_{MonthName}
        $this->assertStringContainsString('$lockKey = "{$year}_{$month}";', self::$hr);
        $this->assertStringContainsString("date('Y', \$ts) . '_' . date('F', \$ts)", self::$att,
            'the guard must compose the payroll key exactly as Hr does, or it reads nothing');

        // Attendance: YYYY-MM
        $this->assertStringContainsString("\$monthKey = sprintf('%04d-%02d', \$year, \$monthNum);", self::$att);
    }

    // ── The flow end to end ──────────────────────────────────────────────

    /**
     * The order of operations in a decision, stated once.
     *
     * Each step exists because skipping it caused a real defect:
     *   payroll guard  — refuse before claiming, so a refusal costs no churn
     *   fresh re-read  — judge "pending" against the version we will write
     *   self-approval  — audited refusal, before any write
     *   claim (CAS)    — exactly one reviewer proceeds
     *   writer         — the register changes only after the claim is held
     *   release        — a failed write puts the request back in the queue
     *   audit          — what was applied, to whom, on which day
     */
    public function test_a_decision_runs_its_steps_in_the_only_safe_order(): void
    {
        $i = strpos(self::$att, 'function staff_regularization_decide');
        $this->assertNotFalse($i);
        $next = strpos(self::$att, '    public function ', (int) $i + 40);
        $body = substr(self::$att, (int) $i, (int) $next - (int) $i);

        $steps = [
            'fresh re-read'   => '$fresh = $this->firebase->firestoreGet(',
            'self-approval'   => 'STAFF_REG_SELF_APPROVAL_BLOCKED',
            'payroll guard'   => '$payLock = $this->_payroll_month_locked($date);',
            'claim'           => "'status'      => 'approved',",
            'writer'          => 'markSingleDay(',
            'audit'           => 'STAFF_REG_APPROVE',
        ];

        $at = [];
        foreach ($steps as $name => $needle) {
            $pos = strpos($body, $needle);
            $this->assertNotFalse($pos, "step missing from the decision: {$name}");
            $at[$name] = $pos;
        }

        $this->assertLessThan($at['self-approval'], $at['fresh re-read'],
            'judge the current state before anything else');
        $this->assertLessThan($at['payroll guard'], $at['self-approval'],
            'a self-approval attempt is refused and audited before any lock work');
        $this->assertLessThan($at['claim'], $at['payroll guard'],
            'refuse a closed payroll month before taking a claim');
        $this->assertLessThan($at['writer'], $at['claim'],
            'hold the claim before touching the register');
        $this->assertLessThan($at['audit'], $at['writer'],
            'the audit records what actually landed');
    }
}
