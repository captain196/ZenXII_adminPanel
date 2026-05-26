<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Academic_planner_aggregations_verify — Tier 1.5 + 1.6 + 1.7 batch verifier.
 *
 * READ-ONLY CLI tool. Discovery-style verification of:
 *
 *   T1.5: timetableSettings (Timetable_service.php COLLECTION_SET)
 *         Doc-id: {schoolId}_{session}; period config
 *
 *   T1.6: lessonPlans (Lesson_plan_service.php COLLECTION)
 *         VALID_STATUSES = planned | completed | skipped | rescheduled
 *
 *   T1.7: leaveApplications (multiple writer paths: Attendance.php + Hr.php + Staff.php)
 *         Discovery-style — multiple shapes likely present
 *
 * INVOCATION:
 *   php index.php academic_planner_aggregations_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Academic_planner_aggregations_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const LESSON_VALID_STATUSES = ['planned', 'completed', 'skipped', 'rescheduled'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Academic_planner_aggregations_verify is CLI-only.', 403);
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

    /** CLI: php index.php academic_planner_aggregations_verify verify */
    public function verify(): void
    {
        echo "=== Academic Planner Tier 1.5 + 1.6 + 1.7 batch verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        $this->_verify_timetable_settings();
        echo "\n";
        $this->_verify_lesson_plans();
        echo "\n";
        $this->_verify_leave_applications();

        echo "\n=== End batch verification ===\n";
    }

    // ── T1.5: timetableSettings ─────────────────────────────────────────
    private function _verify_timetable_settings(): void
    {
        echo "─── T1.5: timetableSettings canonical ───\n";
        $docId = "{$this->schoolFs}_{$this->sessionYear}";
        try {
            $data = $this->firebase->firestoreGet('timetableSettings', $docId);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        if (!is_array($data)) {
            echo "  doc {$docId}: not found\n";
            echo "  ✅ T1.5 TRIVIAL PASS — no settings doc in scope (school may not have configured timetable yet).\n";
            return;
        }
        echo "  doc {$docId}: present\n";
        echo "  top-level fields: " . implode(', ', array_keys($data)) . "\n";

        // Inspect period structure if present
        $periods = $data['periods'] ?? null;
        if (is_array($periods)) {
            printf("  periods array: %d entries\n", count($periods));
            $sampleFields = [];
            if (count($periods) > 0 && is_array($periods[0])) {
                $sampleFields = array_keys($periods[0]);
            }
            echo "  period sub-doc fields: " . implode(', ', $sampleFields) . "\n";
            $negDuration = 0;
            foreach ($periods as $p) {
                if (!is_array($p)) continue;
                $st = (string)($p['startTime'] ?? '');
                $et = (string)($p['endTime']   ?? '');
                if ($st !== '' && $et !== '' && $et < $st) $negDuration++;
            }
            if ($negDuration > 0) {
                echo "  ⚠ {$negDuration} periods with endTime < startTime\n";
            } else {
                echo "  ✓ all periods have endTime >= startTime\n";
            }
        }
        echo "  ✅ T1.5 NORMAL\n";
    }

    // ── T1.6: lessonPlans ─────────────────────────────────────────────────
    private function _verify_lesson_plans(): void
    {
        echo "─── T1.6: lessonPlans canonical ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('lessonPlans', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total docs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.6 TRIVIAL PASS — no lesson plans in scope.\n";
            return;
        }

        $statusTally   = [];
        $teacherTally  = [];
        $monthTally    = [];
        $badStatus     = [];
        $badDate       = [];
        $missingTeacherId = [];

        $sampleData = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
        echo "  first-doc fields: " . implode(', ', array_keys($sampleData)) . "\n";

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            $status = (string)($data['status'] ?? '');
            if ($status !== '') {
                $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
                if (!in_array($status, self::LESSON_VALID_STATUSES, true)) {
                    $badStatus[] = "{$docId}: status=\"{$status}\"";
                }
            }
            $tid = (string)($data['teacherId'] ?? '');
            if ($tid !== '') $teacherTally[$tid] = ($teacherTally[$tid] ?? 0) + 1;
            else $missingTeacherId[] = $docId;

            $date = (string)($data['date'] ?? '');
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $badDate[] = "{$docId}: date=\"{$date}\"";
            } elseif ($date !== '') {
                $monthTally[substr($date, 0, 7)] = ($monthTally[substr($date, 0, 7)] ?? 0) + 1;
            }
        }

        echo "  status distribution: " . json_encode($statusTally) . "\n";
        echo "  teacherId distribution: " . count($teacherTally) . " distinct\n";
        echo "  month distribution: " . json_encode($monthTally) . "\n";
        echo "  bad status: " . count($badStatus) . "\n";
        foreach ($badStatus as $row) echo "    - {$row}\n";
        echo "  bad dates: " . count($badDate) . "\n";
        foreach ($badDate as $row) echo "    - {$row}\n";
        echo "  missing teacherId: " . count($missingTeacherId) . "\n";
        foreach ($missingTeacherId as $row) echo "    - {$row}\n";

        $crit = count($badStatus) + count($badDate) + count($missingTeacherId);
        if ($crit > 0) {
            echo "  ⚠ INVESTIGATE — {$crit} drift indicators\n";
        } else {
            echo "  ✅ T1.6 NORMAL\n";
        }
    }

    // ── T1.7: leaveApplications ──────────────────────────────────────────
    private function _verify_leave_applications(): void
    {
        echo "─── T1.7: leaveApplications canonical ───\n";
        try {
            $rows = $this->firebase->firestoreQuery('leaveApplications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($rows);
        echo "  total docs: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.7 TRIVIAL PASS — no leave applications in scope.\n";
            return;
        }

        $statusTally  = [];
        $typeTally    = [];
        $applicantTally = [];
        $monthTally   = [];
        $writerSigTally = [];   // distinct field-set signatures (writer identification)

        $sampleData = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
        echo "  first-doc fields: " . implode(', ', array_keys($sampleData)) . "\n";

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            $status = (string)($data['status'] ?? '');
            if ($status !== '') $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;

            $type = (string)($data['leaveType'] ?? $data['type'] ?? '');
            if ($type !== '') $typeTally[$type] = ($typeTally[$type] ?? 0) + 1;

            $applicant = (string)($data['applicantId'] ?? $data['staffId'] ?? $data['userId'] ?? '');
            if ($applicant !== '') $applicantTally[$applicant] = ($applicantTally[$applicant] ?? 0) + 1;

            $startDate = (string)($data['startDate'] ?? $data['fromDate'] ?? $data['from'] ?? '');
            if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $monthTally[substr($startDate, 0, 7)] = ($monthTally[substr($startDate, 0, 7)] ?? 0) + 1;
            }

            // Writer-signature classification by field set
            $fields = array_keys($data);
            sort($fields);
            $sig = implode(',', array_slice($fields, 0, 8));   // first 8 chars-sorted for grouping
            $writerSigTally[$sig] = ($writerSigTally[$sig] ?? 0) + 1;
        }

        echo "  status distribution: " . json_encode($statusTally) . "\n";
        echo "  leaveType distribution: " . json_encode($typeTally) . "\n";
        echo "  applicant distribution: " . count($applicantTally) . " distinct\n";
        echo "  month distribution: " . json_encode($monthTally) . "\n";
        echo "  distinct field-signatures (writer identification): " . count($writerSigTally) . "\n";
        $sigIdx = 0;
        foreach ($writerSigTally as $sig => $cnt) {
            echo "    Sig#" . (++$sigIdx) . " (n={$cnt}): " . substr($sig, 0, 100) . (strlen($sig) > 100 ? '...' : '') . "\n";
        }

        if (count($writerSigTally) > 1) {
            echo "  ⚠ WATCH — multi-writer asymmetry detected (multiple field-signatures present)\n";
        } else {
            echo "  ✅ T1.7 NORMAL (single writer signature)\n";
        }
    }
}
