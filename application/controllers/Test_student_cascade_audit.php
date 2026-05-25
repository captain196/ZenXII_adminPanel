<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Test_student_cascade_audit — Tier 1.1 follow-up cascade-impact analysis
 * for the 9 STU_TEST_* test-fixture students discovered to have derived-field
 * drift during T1.1.
 *
 * READ-ONLY CLI tool. For each candidate downstream collection, queries
 * the entire collection scoped by schoolId, then tallies any docs whose
 * studentId field matches one of the 9 STU_TEST_* IDs. The output is a
 * per-student per-collection reference count that determines whether the
 * cleanup blast radius is bounded.
 *
 * INVOCATION:
 *   php index.php test_student_cascade_audit audit
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Test_student_cascade_audit extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    /** The 9 test-fixture student IDs flagged in T1.1. */
    private const TEST_STUDENT_IDS = [
        'STU_TEST_09A_01', 'STU_TEST_09A_02', 'STU_TEST_09A_03',
        'STU_TEST_09B_01', 'STU_TEST_09B_02', 'STU_TEST_09B_03',
        'STU_TEST_10A_01', 'STU_TEST_10A_02', 'STU_TEST_10A_03',
    ];

    /**
     * Collections where studentId may appear as a field.
     * For each, we fetch by schoolId scope and post-filter on studentId.
     * Collections that don't exist yet get a graceful empty result.
     */
    private const STUDENT_REFERENCED_COLLECTIONS = [
        // Fee subsystem
        'feeReceipts', 'feeDemands', 'feeDefaulters', 'studentFeeSummary',
        'feeReceiptAllocations', 'studentDiscounts', 'scholarshipAwards',
        'feeReminderLog', 'feeOnlineOrders', 'feeOnlinePayments',
        'feeReceiptIndex', 'feeIdempotency', 'feePendingWrites', 'feeLocks',
        // Attendance subsystem
        'attendance', 'attendanceDaily', 'attendanceSummary', 'attendanceEventsFired',
        // Academic subsystem
        'homeworks', 'homeworkSubmissions', 'redFlags',
        // Communication subsystem
        'notifications', 'messages', 'conversations',
        // Other subsystems
        'admissionPayments', 'parents', 'parentStudents',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Test_student_cascade_audit is CLI-only.', 403);
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

    /** CLI: php index.php test_student_cascade_audit audit */
    public function audit(): void
    {
        echo "=== Tier 1.1 Cascade-Impact Audit — STU_TEST_* students ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo "Candidate IDs (" . count(self::TEST_STUDENT_IDS) . "): " . implode(', ', self::TEST_STUDENT_IDS) . "\n";
        echo str_repeat('-', 64) . "\n\n";

        $testIdSet = array_flip(self::TEST_STUDENT_IDS);
        $perStudentTotal = array_fill_keys(self::TEST_STUDENT_IDS, 0);
        $perCollectionTotal = [];
        $detailFindings = [];

        // ── students (baseline — expect 9 hits) ────────────────────────────
        echo "--- students (baseline) ---\n";
        try {
            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            $hits = $this->_count_matches($rows, $testIdSet, $perStudentTotal);
            $perCollectionTotal['students'] = $hits;
            echo "  total docs fetched: " . count($rows) . "\n";
            echo "  STU_TEST_* hits: {$hits}\n\n";
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n\n";
            $perCollectionTotal['students'] = -1;
        }

        // ── downstream collections ─────────────────────────────────────────
        foreach (self::STUDENT_REFERENCED_COLLECTIONS as $col) {
            echo "--- {$col} ---\n";
            try {
                $rows = $this->firebase->firestoreQuery($col, [
                    ['schoolId', '==', $this->schoolFs],
                ], null, 'ASC', 1000);
                $hits = $this->_count_matches($rows, $testIdSet, $perStudentTotal, $col, $detailFindings);
                $perCollectionTotal[$col] = $hits;
                echo "  total docs fetched: " . count($rows) . "\n";
                echo "  STU_TEST_* hits: {$hits}\n";
                if ($hits > 0) {
                    echo "  (details follow in final report)\n";
                }
            } catch (\Throwable $e) {
                echo "  collection unavailable or empty: " . $e->getMessage() . "\n";
                $perCollectionTotal[$col] = -1;
            }
            echo "\n";
        }

        // ── Report ────────────────────────────────────────────────────────
        echo str_repeat('=', 64) . "\n";
        echo "PER-COLLECTION SUMMARY (STU_TEST_* references)\n";
        echo str_repeat('=', 64) . "\n";
        ksort($perCollectionTotal);
        foreach ($perCollectionTotal as $col => $cnt) {
            $marker = $cnt === -1 ? '(unavailable)' : ($cnt === 0 ? '✓ clean' : "⚠ {$cnt} ref(s)");
            printf("  %-30s : %s\n", $col, $marker);
        }

        echo "\n";
        echo str_repeat('=', 64) . "\n";
        echo "PER-STUDENT TOTAL REFERENCES (excluding self in students)\n";
        echo str_repeat('=', 64) . "\n";
        $studentsHits = $perCollectionTotal['students'] ?? 0;
        foreach ($perStudentTotal as $sid => $total) {
            $exSelf = max(0, $total - 1); // subtract 1 for the self-ref in students
            $marker = $exSelf === 0 ? '✓ no downstream refs' : "⚠ {$exSelf} downstream ref(s)";
            printf("  %-22s : total=%d  downstream=%d  %s\n", $sid, $total, $exSelf, $marker);
        }

        echo "\n";
        echo str_repeat('=', 64) . "\n";
        echo "DETAIL FINDINGS\n";
        echo str_repeat('=', 64) . "\n";
        if (empty($detailFindings)) {
            echo "  (none — all STU_TEST_* students are isolated; safe-to-purge candidates)\n";
        } else {
            foreach ($detailFindings as $f) echo "  - {$f}\n";
        }

        echo "\n=== End cascade audit ===\n";
    }

    // ══════════════════════════════════════════════════════════════════
    //  CLEANUP CHOREOGRAPHY — BUG-CLEANUP-STU-TEST-PKG-001
    //  Three-pass cascade purge of the 9 STU_TEST_* test-fixture students.
    //  Each pass is defensive (namespace-prefix guard), idempotent
    //  (re-runnable on clean state as no-op), and fully logged.
    //
    //  Pass order chosen so projections (feeDefaulters) are removed AFTER
    //  their authoritative source (feeDemands), and the source-of-truth
    //  doc (students) is removed last. This ordering means a partial-run
    //  interruption leaves the system in a known intermediate state
    //  rather than orphaned projections.
    //
    //  ⚠ Hard safety cap (MAX_DELETES_PER_PASS) aborts if any pass
    //  identifies more candidates than the audit predicted. This guards
    //  against scope creep from a buggy guard.
    // ══════════════════════════════════════════════════════════════════

    /** Hard ceiling on per-pass deletions. Audit predicted: 333 / 9 / 9 — cap at 500. */
    private const MAX_DELETES_PER_PASS = 500;

    /** CLI: php index.php test_student_cascade_audit cleanup_dryrun
     *  Read-only preview — lists every doc that would be deleted without touching anything.
     */
    public function cleanup_dryrun(): void
    {
        $this->_cleanup_run(true);
    }

    /** CLI: php index.php test_student_cascade_audit cleanup_execute
     *  Live execution — requires explicit operator authorization per BUG-CLEANUP-STU-TEST-PKG-001.
     */
    public function cleanup_execute(): void
    {
        $this->_cleanup_run(false);
    }

    private function _cleanup_run(bool $dryRun): void
    {
        $modeLabel = $dryRun ? "DRY-RUN" : "EXECUTE";
        echo "=== STU_TEST_* Cleanup [{$modeLabel}] ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo "Target IDs (" . count(self::TEST_STUDENT_IDS) . "): " . implode(', ', self::TEST_STUDENT_IDS) . "\n";
        echo str_repeat('-', 64) . "\n\n";

        if (!$dryRun) {
            log_message('error', "STU_TEST_CLEANUP_BEGIN schoolId={$this->schoolFs} session={$this->sessionYear}");
        }

        $testIdSet = array_flip(self::TEST_STUDENT_IDS);

        // ── Pass 1: feeDemands (333 expected) ──────────────────────────────
        echo "[Pass 1] feeDemands\n";
        $p1 = $this->_cleanup_pass_by_studentId('feeDemands', $testIdSet, $dryRun);
        $this->_print_pass_summary('feeDemands', $p1);

        // ── Pass 2: feeDefaulters (9 expected) ─────────────────────────────
        echo "\n[Pass 2] feeDefaulters\n";
        $p2 = $this->_cleanup_pass_by_studentId('feeDefaulters', $testIdSet, $dryRun);
        $this->_print_pass_summary('feeDefaulters', $p2);

        // ── Pass 3: students (9 expected) ──────────────────────────────────
        echo "\n[Pass 3] students\n";
        $p3 = $this->_cleanup_pass_students($testIdSet, $dryRun);
        $this->_print_pass_summary('students', $p3);

        // ── Final report ───────────────────────────────────────────────────
        echo "\n" . str_repeat('=', 64) . "\n";
        echo "CLEANUP {$modeLabel} SUMMARY\n";
        echo str_repeat('=', 64) . "\n";
        $totalDeletes = ($p1['deleted'] ?? 0) + ($p2['deleted'] ?? 0) + ($p3['deleted'] ?? 0);
        $totalCandidates = ($p1['candidates'] ?? 0) + ($p2['candidates'] ?? 0) + ($p3['candidates'] ?? 0);
        $totalGuarded = ($p1['guarded_out'] ?? 0) + ($p2['guarded_out'] ?? 0) + ($p3['guarded_out'] ?? 0);
        $totalErrors = ($p1['errors'] ?? 0) + ($p2['errors'] ?? 0) + ($p3['errors'] ?? 0);
        printf("  Total candidates identified : %d  (audit predicted: 351)\n", $totalCandidates);
        printf("  Total %s : %d\n", $dryRun ? 'would-delete  ' : 'actually deleted', $totalDeletes);
        printf("  Total guarded-out (safety)  : %d  (must be 0)\n", $totalGuarded);
        printf("  Total errors                : %d\n", $totalErrors);
        if (!$dryRun) {
            log_message('error',
                "STU_TEST_CLEANUP_END schoolId={$this->schoolFs} session={$this->sessionYear} "
                . "deleted_total={$totalDeletes} guarded={$totalGuarded} errors={$totalErrors}");
        }
        echo "\n=== End cleanup {$modeLabel} ===\n";
    }

    /** Pass 1 / Pass 2 — by-studentId field match. */
    private function _cleanup_pass_by_studentId(string $col, array $testIdSet, bool $dryRun): array
    {
        $stats = ['candidates' => 0, 'deleted' => 0, 'guarded_out' => 0, 'errors' => 0];
        try {
            $rows = $this->firebase->firestoreQuery($col, [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "  collection unavailable: " . $e->getMessage() . "\n";
            $stats['errors']++;
            return $stats;
        }

        $toDelete = [];
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $id   = (string)($r['id'] ?? '');
            $sid  = (string)($data['studentId'] ?? '');
            if ($sid !== '' && isset($testIdSet[$sid])) {
                $toDelete[] = ['id' => $id, 'sid' => $sid];
            }
        }
        $stats['candidates'] = count($toDelete);
        echo "  candidates identified: " . count($toDelete) . "\n";

        if ($stats['candidates'] > self::MAX_DELETES_PER_PASS) {
            echo "  ⚠ HARD ABORT: candidate count exceeds MAX_DELETES_PER_PASS=" . self::MAX_DELETES_PER_PASS . "\n";
            log_message('error', "STU_TEST_CLEANUP_ABORT_{$col} candidates=" . count($toDelete)
                . " exceeds cap=" . self::MAX_DELETES_PER_PASS);
            $stats['errors']++;
            return $stats;
        }

        foreach ($toDelete as $entry) {
            $id  = $entry['id'];
            $sid = $entry['sid'];
            // Defensive guard: doc's studentId MUST start with STU_TEST_.
            if (strpos($sid, 'STU_TEST_') !== 0) {
                $stats['guarded_out']++;
                echo "  GUARD: skipping {$col}/{$id} — studentId={$sid} doesn't match STU_TEST_*\n";
                continue;
            }
            if ($dryRun) {
                echo "  would-delete: {$col}/{$id}  (studentId={$sid})\n";
                $stats['deleted']++;
            } else {
                try {
                    $ok = $this->firebase->firestoreDelete($col, $id);
                    if ($ok) {
                        echo "  deleted: {$col}/{$id}\n";
                        log_message('error', "STU_TEST_CLEANUP_DELETED col={$col} docId={$id} sid={$sid}");
                        $stats['deleted']++;
                    } else {
                        echo "  ERROR (firestoreDelete returned false): {$col}/{$id}\n";
                        $stats['errors']++;
                    }
                } catch (\Throwable $e) {
                    echo "  ERROR: {$col}/{$id} — " . $e->getMessage() . "\n";
                    log_message('error', "STU_TEST_CLEANUP_FAILED col={$col} docId={$id} sid={$sid} err=" . $e->getMessage());
                    $stats['errors']++;
                }
            }
        }
        return $stats;
    }

    /** Pass 3 — students by deterministic doc-id. */
    private function _cleanup_pass_students(array $testIdSet, bool $dryRun): array
    {
        $stats = ['candidates' => 0, 'deleted' => 0, 'guarded_out' => 0, 'errors' => 0];
        $col = 'students';

        foreach (array_keys($testIdSet) as $sid) {
            $docId = "{$this->schoolFs}_{$sid}";
            // Fetch first so we can verify the namespace guard
            try {
                $doc = $this->firebase->firestoreGet($col, $docId);
            } catch (\Throwable $e) {
                echo "  ERROR fetching {$col}/{$docId}: " . $e->getMessage() . "\n";
                $stats['errors']++;
                continue;
            }
            if (!is_array($doc)) {
                echo "  not found (already deleted?): {$col}/{$docId}\n";
                continue;
            }
            $stats['candidates']++;
            // Defensive guard: doc-id MUST contain STU_TEST_, AND data should
            // confirm via name or studentId field if available.
            if (strpos($docId, 'STU_TEST_') === false) {
                $stats['guarded_out']++;
                echo "  GUARD: skipping {$col}/{$docId} — doc-id doesn't contain STU_TEST_\n";
                continue;
            }
            $docSid = (string)($doc['studentId'] ?? '');
            if ($docSid !== '' && strpos($docSid, 'STU_TEST_') !== 0) {
                $stats['guarded_out']++;
                echo "  GUARD: skipping {$col}/{$docId} — data studentId={$docSid} doesn't match STU_TEST_*\n";
                continue;
            }
            if ($dryRun) {
                echo "  would-delete: {$col}/{$docId}  (name=" . ($doc['name'] ?? $doc['Name'] ?? '?') . ")\n";
                $stats['deleted']++;
            } else {
                try {
                    $ok = $this->firebase->firestoreDelete($col, $docId);
                    if ($ok) {
                        echo "  deleted: {$col}/{$docId}\n";
                        log_message('error', "STU_TEST_CLEANUP_DELETED col={$col} docId={$docId} sid={$sid}");
                        $stats['deleted']++;
                    } else {
                        echo "  ERROR (firestoreDelete returned false): {$col}/{$docId}\n";
                        $stats['errors']++;
                    }
                } catch (\Throwable $e) {
                    echo "  ERROR: {$col}/{$docId} — " . $e->getMessage() . "\n";
                    log_message('error', "STU_TEST_CLEANUP_FAILED col={$col} docId={$docId} sid={$sid} err=" . $e->getMessage());
                    $stats['errors']++;
                }
            }
        }
        return $stats;
    }

    private function _print_pass_summary(string $col, array $stats): void
    {
        printf("  → %s: candidates=%d deleted=%d guarded=%d errors=%d\n",
            $col, $stats['candidates'] ?? 0, $stats['deleted'] ?? 0,
            $stats['guarded_out'] ?? 0, $stats['errors'] ?? 0);
    }

    /**
     * Scan rows for studentId matches; tally per-student counts and
     * optionally collect detail findings for non-students collections.
     * @return int total matches in this scan
     */
    private function _count_matches(array $rows, array $testIdSet, array &$perStudentTotal,
                                    string $col = '', array &$detailFindings = null): int
    {
        $hits = 0;
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $id   = (string)($r['id'] ?? '');

            // Try multiple possible fields where studentId could live
            $candidates = [
                (string)($data['studentId'] ?? ''),
                (string)($data['student_id'] ?? ''),
                (string)($data['childId'] ?? ''),
                (string)($data['child_id'] ?? ''),
            ];
            // Also check if the doc-id encodes a test student (e.g., feeReceiptIndex uses compound IDs)
            foreach (array_keys($testIdSet) as $candidate_id) {
                if (strpos($id, $candidate_id) !== false) {
                    $candidates[] = $candidate_id;
                    break;
                }
            }

            foreach ($candidates as $sid) {
                if ($sid !== '' && isset($testIdSet[$sid])) {
                    $hits++;
                    $perStudentTotal[$sid]++;
                    if ($col !== '' && $detailFindings !== null) {
                        $detailFindings[] = "{$col}/{$id}  studentId={$sid}";
                    }
                    break; // count this row once even if multiple fields match
                }
            }
        }
        return $hits;
    }
}
