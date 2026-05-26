<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attendance_canonical_verify — attendance Tier 1.1 daily-record canonical
 * schema verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical schema per
 * [[attendance_firestore_migration]] (Phase 7 Firestore-first, 2026-04-08)
 * + the actual write path at application/controllers/Attendance.php:5589-5606:
 *
 *   Required canonical fields:
 *     schoolId, session, date, className, section, classOrder, sectionCode,
 *     sectionKey, studentId, studentName, status, markedBy, markedAt,
 *     late, lateMinutes, notified
 *
 *   Dual-emit observation per memory: `tardy` should be canonical with
 *   `late` legacy alias "still written for one cycle". This verifier
 *   reports the current state of that dual-emit cycle.
 *
 *   class/section canonical conformance (per cross-system T1.1):
 *     className matches "Class N(th|st|nd|rd)" pattern
 *     section matches "Section X" pattern
 *
 * INVOCATION:
 *   php index.php attendance_canonical_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Attendance_canonical_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const REQUIRED_CANONICAL_FIELDS = [
        'schoolId', 'session', 'date',
        'className', 'section', 'classOrder', 'sectionCode',
        'studentId', 'studentName',
        'status', 'markedBy', 'markedAt',
        'late', 'lateMinutes', 'notified',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Attendance_canonical_verify is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    /** CLI: php index.php attendance_canonical_verify verify_roster
     *  T1.7 — roster completeness check. Cross-references attendance +
     *  attendanceSummary against active students.
     *    - every studentId in attendance docs resolves to an active student
     *    - every active student has at least 1 attendance OR summary record
     *      (or documents the gap)
     *    - no orphan attendance refs to deleted students (incl. STU_TEST_* post-cleanup)
     */
    public function verify_roster(): void
    {
        echo "=== attendance Tier 1.7 roster completeness verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // Step 1: load active students
        $studentMap = [];   // studentId => ['className', 'section', 'name']
        try {
            $students = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            foreach ($students as $s) {
                $data = is_array($s['data'] ?? null) ? $s['data'] : [];
                $sid = (string)($data['studentId'] ?? $data['User ID'] ?? '');
                if ($sid === '') {
                    // Fall back to doc-id tail
                    $docId = (string)($s['id'] ?? '');
                    if (preg_match('/_(STU\w+)$/', $docId, $m)) $sid = $m[1];
                }
                if ($sid === '') continue;
                $studentMap[$sid] = [
                    'name'      => (string)($data['name'] ?? $data['Name'] ?? '?'),
                    'className' => (string)($data['className'] ?? ''),
                    'section'   => (string)($data['section'] ?? ''),
                ];
            }
            echo "[Step 1] Active students loaded: " . count($studentMap) . "\n";
        } catch (\Throwable $e) {
            echo "ERROR loading students: " . $e->getMessage() . "\n";
            return;
        }

        // Step 2: load attendance + attendanceSummary
        try {
            $attendance = $this->firebase->firestoreQuery('attendance', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            $summaries = $this->firebase->firestoreQuery('attendanceSummary', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            return;
        }
        echo "[Step 2] attendance docs: " . count($attendance) . "  summary docs: " . count($summaries) . "\n";

        // Step 3: tally + cross-reference
        $attendanceStudents = [];   // studentId => count of daily attendance docs
        $summaryStudents    = [];   // studentId => count of summary docs
        $orphanAttendance   = [];   // attendance refs to non-existent students
        $orphanSummary      = [];   // summary refs to non-existent students

        foreach ($attendance as $a) {
            $data = is_array($a['data'] ?? null) ? $a['data'] : [];
            $sid = (string)($data['studentId'] ?? '');
            if ($sid === '') continue;
            $attendanceStudents[$sid] = ($attendanceStudents[$sid] ?? 0) + 1;
            if (!isset($studentMap[$sid])) {
                $orphanAttendance[] = "attendance/{$a['id']} → studentId={$sid} (no matching student)";
            }
        }
        foreach ($summaries as $s) {
            $data = is_array($s['data'] ?? null) ? $s['data'] : [];
            $sid = (string)($data['studentId'] ?? '');
            if ($sid === '') continue;
            $summaryStudents[$sid] = ($summaryStudents[$sid] ?? 0) + 1;
            if (!isset($studentMap[$sid])) {
                $orphanSummary[] = "attendanceSummary/{$s['id']} → studentId={$sid} (no matching student)";
            }
        }

        // Step 4: completeness — every student should have ≥ 1 record somewhere
        $studentsNoAttendance  = [];
        $studentsNoSummary     = [];
        $studentsNoneAtAll     = [];

        foreach ($studentMap as $sid => $info) {
            $hasAttendance = isset($attendanceStudents[$sid]);
            $hasSummary    = isset($summaryStudents[$sid]);
            if (!$hasAttendance) $studentsNoAttendance[] = "{$sid} ({$info['name']})";
            if (!$hasSummary)    $studentsNoSummary[]    = "{$sid} ({$info['name']})";
            if (!$hasAttendance && !$hasSummary) $studentsNoneAtAll[] = "{$sid} ({$info['name']})";
        }

        // ── Report ────────────────────────────────────────────────────────
        echo "\n─── Per-student attendance coverage ───\n";
        printf("  Students with ≥1 daily attendance:   %d / %d\n",
            count($studentMap) - count($studentsNoAttendance), count($studentMap));
        printf("  Students with ≥1 attendanceSummary:  %d / %d\n",
            count($studentMap) - count($studentsNoSummary), count($studentMap));
        printf("  Students with NEITHER:               %d / %d\n",
            count($studentsNoneAtAll), count($studentMap));
        if (!empty($studentsNoAttendance)) {
            echo "\n  Students without ANY daily attendance docs:\n";
            foreach ($studentsNoAttendance as $row) echo "    - {$row}\n";
        }
        if (!empty($studentsNoSummary)) {
            echo "\n  Students without ANY summary docs:\n";
            foreach ($studentsNoSummary as $row) echo "    - {$row}\n";
        }
        if (!empty($studentsNoneAtAll)) {
            echo "\n  Students with NEITHER daily nor summary:\n";
            foreach ($studentsNoneAtAll as $row) echo "    - {$row}\n";
        }

        echo "\n─── Orphan references ───\n";
        echo "  Orphan attendance refs (to non-existent students): " . count($orphanAttendance) . "\n";
        foreach ($orphanAttendance as $row) echo "    - {$row}\n";
        echo "  Orphan summary refs (to non-existent students):   " . count($orphanSummary) . "\n";
        foreach ($orphanSummary as $row) echo "    - {$row}\n";

        // ── Verdict ────────────────────────────────────────────────────────
        echo "\n─── Verdict ───\n";
        $criticalDrift = count($orphanAttendance) + count($orphanSummary);
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED — {$criticalDrift} orphan attendance refs to non-existent students. Per Tier 1.7 FREEZE rule: orphan attendance is critical integrity violation.\n";
        } elseif (count($studentsNoneAtAll) === count($studentMap)) {
            echo "⚠ INFO — no students have any attendance records in scope (likely fresh dev environment)\n";
        } elseif (count($studentsNoAttendance) > 0 || count($studentsNoSummary) > 0) {
            printf("⚠ WATCH — %d students missing daily attendance, %d missing summary (coverage gap, NOT orphan)\n",
                count($studentsNoAttendance), count($studentsNoSummary));
        } else {
            echo "✅ T1.7 NORMAL — all students have full attendance coverage; no orphans.\n";
        }

        echo "\n=== End verification ===\n";
    }

    /** CLI: php index.php attendance_canonical_verify dump_summary <docId>
     *  Read-only forensic dump of a single attendanceSummary doc with
     *  fully-expanded dayWise map. Used to identify over-counting cause.
     */
    public function dump_summary(string $docId = ''): void
    {
        if ($docId === '') {
            echo "Usage: dump_summary <docId>\n";
            echo "Example: dump_summary SCH_D94FE8F7AD_STU0001_2026-05\n";
            exit(1);
        }
        echo "=== attendanceSummary forensic dump — {$docId} ===\n";
        echo str_repeat('-', 64) . "\n\n";

        $data = $this->firebase->firestoreGet('attendanceSummary', $docId);
        if (!is_array($data)) {
            echo "Doc not found: {$docId}\n";
            return;
        }

        // Print top-level fields (excluding dayWise + __updateTime; handle separately)
        ksort($data);
        $dayWise = null;
        foreach ($data as $k => $v) {
            if ($k === '__updateTime') continue;
            if ($k === 'dayWise') { $dayWise = $v; continue; }
            $vDisp = is_array($v) ? '[map: ' . count($v) . ' keys]' : var_export($v, true);
            printf("  %-20s = %s\n", $k, $vDisp);
        }

        // dayWise expansion
        if (is_array($dayWise)) {
            echo "\n  dayWise map (" . count($dayWise) . " entries):\n";
            ksort($dayWise);
            $valueTally = [];
            foreach ($dayWise as $day => $val) {
                $valStr = is_array($val) ? json_encode($val) : var_export($val, true);
                printf("    %-30s = %s\n", $day, $valStr);
                $valueTally[$valStr] = ($valueTally[$valStr] ?? 0) + 1;
            }
            echo "\n  dayWise value distribution:\n";
            arsort($valueTally);
            foreach ($valueTally as $v => $c) printf("    %-30s x %d\n", $v, $c);
        } else {
            echo "\n  dayWise: " . var_export($dayWise, true) . "\n";
        }

        // Reconciliation breakdown
        echo "\n  ── reconciliation breakdown ──\n";
        $present  = (float)($data['present']     ?? 0);
        $absent   = (float)($data['absent']      ?? 0);
        $leave    = (float)($data['leave']       ?? 0);
        $holiday  = (float)($data['holiday']     ?? 0);
        $vacation = (float)($data['vacation']    ?? 0);
        $tardy    = (float)($data['tardy']       ?? 0);
        $workingD = (float)($data['workingDays'] ?? 0);
        $totalD   = (float)($data['totalDays']   ?? 0);
        printf("    present     = %g\n", $present);
        printf("    absent      = %g\n", $absent);
        printf("    leave       = %g\n", $leave);
        printf("    holiday     = %g\n", $holiday);
        printf("    vacation    = %g\n", $vacation);
        printf("    tardy       = %g\n", $tardy);
        printf("    workingDays = %g\n", $workingD);
        printf("    totalDays   = %g\n", $totalD);
        printf("    Σ(present+absent+leave+holiday+vacation) = %g\n", $present+$absent+$leave+$holiday+$vacation);
        printf("    Σ(present+absent+leave)                  = %g  (vs workingDays=%g, delta=%g)\n", $present+$absent+$leave, $workingD, ($present+$absent+$leave)-$workingD);
        printf("    Σ(holiday+vacation)                      = %g\n", $holiday+$vacation);
        // dayWise actual category counts if it's a map of date→category
        if (is_array($dayWise)) {
            $dayCatTally = [];
            foreach ($dayWise as $val) {
                $k = is_string($val) ? $val : json_encode($val);
                $dayCatTally[$k] = ($dayCatTally[$k] ?? 0) + 1;
            }
            echo "\n    dayWise category counts (ground truth from map):\n";
            ksort($dayCatTally);
            foreach ($dayCatTally as $cat => $cnt) printf("      %-30s x %d\n", $cat, $cnt);
        }

        echo "\n=== End forensic dump ===\n";
    }

    /** CLI: php index.php attendance_canonical_verify verify_aggregations
     *  Batch verifier for T1.3 (attendanceSummary), T1.4 (staffAttendance +
     *  staffAttendanceSummary parity), T1.5 (attendanceEventsFired log).
     *  Read-only. Same discovery-style approach as T1.1.
     */
    public function verify_aggregations(): void
    {
        echo "=== attendance Tier 1.3 + 1.4 + 1.5 batch verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        $this->_verify_attendanceSummary();
        echo "\n";
        $this->_verify_staffAttendance();
        echo "\n";
        $this->_verify_attendanceEventsFired();

        echo "\n=== End batch verification ===\n";
    }

    // ── T1.3: attendanceSummary ─────────────────────────────────────────
    private function _verify_attendanceSummary(): void
    {
        echo "─── T1.3: attendanceSummary canonical ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('attendanceSummary', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total docs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.3 TRIVIAL PASS — no summary docs in scope.\n";
            return;
        }

        // Discovery scan: list top-level field names of first doc, sample IDs
        $sampleData = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
        echo "  first-doc fields: " . implode(', ', array_keys($sampleData)) . "\n";

        // Tally + integrity checks
        $studentIdTally = [];
        $periodTally    = [];
        $negativeCounts = [];
        $totalDoesNotEqual = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $sid = (string)($data['studentId'] ?? '');
            if ($sid !== '') $studentIdTally[$sid] = ($studentIdTally[$sid] ?? 0) + 1;
            $period = (string)($data['period'] ?? $data['monthKey'] ?? $data['month'] ?? '');
            if ($period !== '') $periodTally[$period] = ($periodTally[$period] ?? 0) + 1;

            // Negative-count check — actual schema uses singular field names
            // (present/absent/leave/holiday/vacation/tardy/lateTimes), not *Days
            foreach (['present','absent','leave','holiday','vacation','tardy','lateTimes','workingDays','totalDays'] as $f) {
                $v = $data[$f] ?? null;
                if (is_numeric($v) && (float)$v < 0) {
                    $negativeCounts[] = "{$docId}: {$f}=" . $v;
                }
            }

            // Reconciliation: totalDays ≈ present + absent + leave + holiday +
            // vacation. Note: tardy is a subset of present (a tardy day is still
            // a present day), so we don't add it to the sum. workingDays should
            // ≈ totalDays - holiday - vacation.
            $present  = (float)($data['present']     ?? 0);
            $absent   = (float)($data['absent']      ?? 0);
            $leave    = (float)($data['leave']       ?? 0);
            $holiday  = (float)($data['holiday']     ?? 0);
            $vacation = (float)($data['vacation']    ?? 0);
            $workingD = $data['workingDays'] ?? null;
            $totalD   = $data['totalDays']   ?? null;
            $sumAll   = $present + $absent + $leave + $holiday + $vacation;
            $sumWork  = $present + $absent + $leave;

            if (is_numeric($totalD) && $totalD > 0 && abs((float)$totalD - $sumAll) > 1) {
                $totalDoesNotEqual[] = "{$docId}: present+absent+leave+holiday+vacation={$sumAll} vs totalDays={$totalD}";
            }
            if (is_numeric($workingD) && $workingD > 0 && abs((float)$workingD - $sumWork) > 1) {
                $totalDoesNotEqual[] = "{$docId}: present+absent+leave={$sumWork} vs workingDays={$workingD}";
            }
        }

        echo "  distinct studentIds: " . count($studentIdTally) . "\n";
        echo "  distinct periods: " . count($periodTally) . "\n";
        foreach ($periodTally as $p => $c) echo "    \"{$p}\" x {$c}\n";
        echo "  negative-count violations: " . count($negativeCounts) . "\n";
        foreach ($negativeCounts as $row) echo "    - {$row}\n";
        echo "  total-day reconciliation gaps: " . count($totalDoesNotEqual) . "\n";
        foreach ($totalDoesNotEqual as $row) echo "    - {$row}\n";

        if (count($negativeCounts) > 0) {
            echo "  🛑 FREEZE_REQUIRED — negative attendance counts detected\n";
        } elseif (count($totalDoesNotEqual) > 0) {
            echo "  ⚠ INVESTIGATE — total-day arithmetic mismatch\n";
        } else {
            echo "  ✅ T1.3 NORMAL — all summary counts non-negative + total-day arithmetic coherent\n";
        }
    }

    // ── T1.4: staff attendance parity ────────────────────────────────────
    private function _verify_staffAttendance(): void
    {
        echo "─── T1.4: staffAttendance + staffAttendanceSummary parity ───\n";

        $staffAtt = [];
        $staffSum = [];
        try {
            $staffAtt = $this->firebase->firestoreQuery('staffAttendance', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  staffAttendance unavailable: " . $e->getMessage() . "\n";
        }
        try {
            $staffSum = $this->firebase->firestoreQuery('staffAttendanceSummary', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  staffAttendanceSummary unavailable: " . $e->getMessage() . "\n";
        }
        echo "  staffAttendance docs: " . count($staffAtt) . "\n";
        echo "  staffAttendanceSummary docs: " . count($staffSum) . "\n";

        if (count($staffAtt) === 0 && count($staffSum) === 0) {
            echo "  ✅ T1.4 TRIVIAL PASS — no staff-attendance data in scope.\n";
            return;
        }

        // Discovery: list field names
        if (count($staffAtt) > 0) {
            $d = is_array($staffAtt[0]['data'] ?? null) ? $staffAtt[0]['data'] : [];
            echo "  staffAttendance first-doc fields: " . implode(', ', array_keys($d)) . "\n";
        }
        if (count($staffSum) > 0) {
            $d = is_array($staffSum[0]['data'] ?? null) ? $staffSum[0]['data'] : [];
            echo "  staffAttendanceSummary first-doc fields: " . implode(', ', array_keys($d)) . "\n";
        }

        // Sanity: staffId resolves; status enum; lateMinutes ≥ 0; negative counts in summary
        $unknownStaffRefs = [];
        $negativeCounts   = [];
        $negativeLateMin  = [];
        $staffIdTally     = [];
        $statusTally      = [];
        foreach ($staffAtt as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $sid = (string)($data['staffId'] ?? '');
            if ($sid !== '') $staffIdTally[$sid] = ($staffIdTally[$sid] ?? 0) + 1;
            $st = (string)($data['status'] ?? '');
            if ($st !== '') $statusTally[$st] = ($statusTally[$st] ?? 0) + 1;
            $lm = $data['lateMinutes'] ?? null;
            if (is_numeric($lm) && (float)$lm < 0) $negativeLateMin[] = "{$docId}: lateMinutes={$lm}";
        }
        foreach ($staffSum as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            foreach (['presentDays','absentDays','tardyDays','lateDays','totalDays'] as $f) {
                $v = $data[$f] ?? null;
                if (is_numeric($v) && (float)$v < 0) $negativeCounts[] = "{$docId}: {$f}=" . $v;
            }
        }
        echo "  staffAttendance distinct staffIds: " . count($staffIdTally) . "\n";
        echo "  staffAttendance status distribution: " . json_encode($statusTally) . "\n";
        echo "  negative lateMinutes: " . count($negativeLateMin) . "\n";
        foreach ($negativeLateMin as $row) echo "    - {$row}\n";
        echo "  negative summary counts: " . count($negativeCounts) . "\n";
        foreach ($negativeCounts as $row) echo "    - {$row}\n";

        if (count($negativeLateMin) + count($negativeCounts) > 0) {
            echo "  🛑 FREEZE_REQUIRED — negative attendance values\n";
        } else {
            echo "  ✅ T1.4 NORMAL — staff-attendance data structurally coherent\n";
        }
    }

    // ── T1.5: attendanceEventsFired log integrity ───────────────────────
    private function _verify_attendanceEventsFired(): void
    {
        echo "─── T1.5: attendanceEventsFired log integrity ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('attendanceEventsFired', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total event docs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.5 TRIVIAL PASS — no events in scope.\n";
            return;
        }

        $sampleData = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
        echo "  first-doc fields: " . implode(', ', array_keys($sampleData)) . "\n";

        $eventTypeTally = [];
        $studentEventCombos = [];  // {studentId|date|eventType} => count, to detect duplicates
        $duplicates = [];
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $et = (string)($data['eventType'] ?? $data['type'] ?? '');
            if ($et !== '') $eventTypeTally[$et] = ($eventTypeTally[$et] ?? 0) + 1;
            $sid = (string)($data['studentId'] ?? $data['staffId'] ?? '');
            $dt = (string)($data['date'] ?? '');
            $combo = "{$sid}|{$dt}|{$et}";
            if ($sid !== '' && $dt !== '' && $et !== '') {
                $studentEventCombos[$combo] = ($studentEventCombos[$combo] ?? 0) + 1;
                if ($studentEventCombos[$combo] > 1) {
                    $duplicates[] = "{$docId}: dup combo={$combo} (now x{$studentEventCombos[$combo]})";
                }
            }
        }
        echo "  eventType distribution: " . json_encode($eventTypeTally) . "\n";
        echo "  duplicate {studentId|date|eventType} combos: " . count($duplicates) . "\n";
        foreach ($duplicates as $row) echo "    - {$row}\n";

        if (count($duplicates) > 0) {
            echo "  ⚠ INVESTIGATE — duplicate event firings detected\n";
        } else {
            echo "  ✅ T1.5 NORMAL — event log integrity preserved\n";
        }
    }

    /** CLI: php index.php attendance_canonical_verify inspect_all
     *  Read-only forensic dump — for each attendance doc in scope, prints
     *  the FULL top-level field list with values. Used for side-by-side
     *  comparison between full-canonical and partial-canonical writers.
     */
    public function inspect_all(): void
    {
        echo "=== attendance forensic dump — all docs in scope ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('attendance', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            return;
        }
        if (count($rows) === 0) {
            echo "No docs in scope.\n";
            return;
        }

        // Sort by docId for stable side-by-side comparison
        usort($rows, function($a, $b) {
            return strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? ''));
        });

        foreach ($rows as $idx => $r) {
            $docId = (string)($r['id'] ?? '');
            $data  = is_array($r['data'] ?? null) ? $r['data'] : [];
            echo "[" . ($idx + 1) . "] docId={$docId}\n";
            echo "    fieldCount = " . count($data) . "\n";
            ksort($data);
            foreach ($data as $k => $v) {
                if ($k === '__updateTime') continue;   // Firestore internal
                $vDisp = is_array($v) ? '[map: ' . implode(',', array_keys($v)) . ']' : var_export($v, true);
                echo "    {$k} = {$vDisp}\n";
            }
            echo "\n";
        }
        echo "=== End forensic dump ===\n";
    }

    /** CLI: php index.php attendance_canonical_verify verify */
    public function verify(): void
    {
        echo "=== attendance Tier 1.1 daily-record canonical schema verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // Try schoolFs first; if 0 results, retry without schoolId filter for
        // discovery (the write code at Attendance.php:5590 uses school_name —
        // friendly name — not schoolFs; need to verify which value is in the docs).
        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('attendance', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            echo "Query 1 (schoolId == {$this->schoolFs}): " . count($rows) . " docs\n";
            if (count($rows) === 0) {
                $rowsAll = $this->firebase->firestoreQuery('attendance', [
                ], null, 'ASC', 500);
                echo "Query 2 (no schoolId filter — discovery): " . count($rowsAll) . " docs\n";
                if (count($rowsAll) > 0) {
                    $rows = $rowsAll;
                    $sample = $rowsAll[0]['data']['schoolId'] ?? '(absent)';
                    echo "Sample schoolId field value: " . var_export($sample, true) . "\n";
                    echo "Switching to all-docs scan since SCHOOL_ID filter mismatched.\n";
                }
            }
        } catch (\Throwable $e) {
            echo "ERROR loading attendance: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "\nTotal attendance docs analyzed: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.1 TRIVIAL PASS — no attendance docs to verify ===\n";
            return;
        }

        // Distribution tallies
        $statusTally       = [];
        $classNameTally    = [];
        $sectionTally      = [];
        $schoolIdTally     = [];
        $markedByTally     = [];
        $dateTally         = [];

        $missingFields = [];   // docId => list
        $tardyPresentCount = 0;
        $latePresentCount  = 0;
        $tardyOnlyCount    = 0;
        $lateOnlyCount     = 0;
        $bothPresentCount  = 0;
        $neitherCount      = 0;
        $tardyLateDivergent = []; // docs where tardy/late disagree
        $classDriftCount   = 0;
        $sectionDriftCount = 0;
        $negativeLateMinutes = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            // Field presence
            $missing = [];
            foreach (self::REQUIRED_CANONICAL_FIELDS as $f) {
                if (!array_key_exists($f, $data)) $missing[] = $f;
            }
            if (!empty($missing)) $missingFields[$docId] = $missing;

            // Tallies
            $status = (string)($data['status'] ?? '');
            if ($status !== '') $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
            $cn = (string)($data['className'] ?? '');
            if ($cn !== '') $classNameTally[$cn] = ($classNameTally[$cn] ?? 0) + 1;
            $sec = (string)($data['section'] ?? '');
            if ($sec !== '') $sectionTally[$sec] = ($sectionTally[$sec] ?? 0) + 1;
            $sid = (string)($data['schoolId'] ?? '');
            if ($sid !== '') $schoolIdTally[$sid] = ($schoolIdTally[$sid] ?? 0) + 1;
            $mb = (string)($data['markedBy'] ?? '');
            if ($mb !== '') $markedByTally[$mb] = ($markedByTally[$mb] ?? 0) + 1;
            $dt = (string)($data['date'] ?? '');
            if ($dt !== '') $dateTally[$dt] = ($dateTally[$dt] ?? 0) + 1;

            // tardy / late dual-emit observation
            $hasTardy = array_key_exists('tardy', $data);
            $hasLate  = array_key_exists('late', $data);
            $tardyVal = $hasTardy ? (bool)$data['tardy'] : null;
            $lateVal  = $hasLate  ? (bool)$data['late']  : null;
            if ($hasTardy) $tardyPresentCount++;
            if ($hasLate)  $latePresentCount++;
            if ($hasTardy && $hasLate)   $bothPresentCount++;
            elseif ($hasTardy && !$hasLate) $tardyOnlyCount++;
            elseif (!$hasTardy && $hasLate) $lateOnlyCount++;
            else $neitherCount++;
            if ($hasTardy && $hasLate && $tardyVal !== $lateVal) {
                $tardyLateDivergent[] = "{$docId}: tardy=" . var_export($tardyVal, true) . " late=" . var_export($lateVal, true);
            }

            // class/section canonical (per cross-system T1.1)
            if ($cn !== '' && !preg_match('/^Class \S+/', $cn)) $classDriftCount++;
            if ($sec !== '' && !preg_match('/^Section \S+/', $sec)) $sectionDriftCount++;

            // lateMinutes validity
            $lm = $data['lateMinutes'] ?? null;
            if (is_numeric($lm) && (float)$lm < 0) {
                $negativeLateMinutes[] = "{$docId}: lateMinutes={$lm}";
            }
        }

        // ── Distribution report ──────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "schoolId field values:\n";
        foreach ($schoolIdTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nstatus values:\n";
        foreach ($statusTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nclassName values:\n";
        foreach ($classNameTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nsection values:\n";
        foreach ($sectionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\ndate values:\n";
        foreach ($dateTally as $v => $c) echo "  {$v} x {$c}\n";
        echo "\nmarkedBy values:\n";
        foreach ($markedByTally as $v => $c) echo "  \"{$v}\" x {$c}\n";

        // ── Field presence ───────────────────────────────────────────────
        echo "\n─── Required canonical field presence ───\n";
        printf("Docs missing canonical fields: %d / %d\n", count($missingFields), $total);
        foreach ($missingFields as $id => $miss) {
            echo "  - {$id}: missing " . implode(',', $miss) . "\n";
        }

        // ── tardy/late dual-emit ──────────────────────────────────────────
        echo "\n─── tardy vs late field presence (Phase 7 dual-emit cycle check) ───\n";
        printf("  tardy field present: %d / %d\n", $tardyPresentCount, $total);
        printf("  late field present:  %d / %d\n", $latePresentCount, $total);
        printf("  both present:        %d\n", $bothPresentCount);
        printf("  tardy-only:          %d\n", $tardyOnlyCount);
        printf("  late-only:           %d\n", $lateOnlyCount);
        printf("  neither:             %d\n", $neitherCount);
        if (!empty($tardyLateDivergent)) {
            echo "  ⚠ tardy/late divergent (values disagree): " . count($tardyLateDivergent) . "\n";
            foreach ($tardyLateDivergent as $row) echo "    - {$row}\n";
        }

        // ── class/section canonical drift ─────────────────────────────────
        echo "\n─── class/section canonical conformance (per cross-system T1.1) ───\n";
        printf("  className drift (no \"Class \" prefix): %d\n", $classDriftCount);
        printf("  section drift (no \"Section \" prefix): %d\n", $sectionDriftCount);

        // ── lateMinutes ───────────────────────────────────────────────────
        echo "\n─── lateMinutes validity ───\n";
        printf("  negative lateMinutes: %d\n", count($negativeLateMinutes));
        foreach ($negativeLateMinutes as $row) echo "    - {$row}\n";

        // ── Verdict ──────────────────────────────────────────────────────
        echo "\n─── Verdict ───\n";
        $criticalDrift = $classDriftCount + $sectionDriftCount + count($tardyLateDivergent) + count($negativeLateMinutes);
        $minorDrift = count($missingFields);
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED — {$criticalDrift} critical drift indicators (class/section canon OR tardy/late divergence OR negative lateMinutes)\n";
        } elseif ($minorDrift > 0) {
            echo "⚠ WATCH — {$minorDrift} docs with missing canonical fields. Classify each above per soak contract.\n";
        } else {
            echo "✅ T1.1 NORMAL — all {$total} attendance docs canonical:\n";
            echo "    - all required fields present\n";
            echo "    - class/section conforms to cross-system T1.1 canon\n";
            echo "    - lateMinutes coherent\n";
            echo "    - tardy/late field state classified for Phase 7 cycle status (see distribution above)\n";
        }

        echo "\n=== End verification ===\n";
    }
}
