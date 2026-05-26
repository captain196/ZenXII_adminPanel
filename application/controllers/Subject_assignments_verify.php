<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Subject_assignments_verify — Tier 1.2 cross-system subjectAssignments
 * authoritative + completeness verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical subjectAssignments schema
 * + cross-reference invariants per [[subject_assignments_architecture]]:
 *
 *   1. teacherId field present + non-empty + resolvable to staff doc
 *   2. Active assignment → teacher MUST be active
 *   3. Inactive teacher → assignment MUST be archived (status="archived"
 *      OR archivedBecauseOfDeactivation=true)
 *   4. Doc-id format: {schoolId}_{session}_Class_<n>_Section_<x>_<subjectCode>
 *   5. Class/section in doc-id parses to canonical schema (matches T1.1 canon)
 *
 * Cross-references against staff collection's `userId` + `status` fields.
 *
 * INVOCATION:
 *   php index.php subject_assignments_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Subject_assignments_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Subject_assignments_verify is CLI-only.', 403);
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

    /** CLI: php index.php subject_assignments_verify verify */
    public function verify(): void
    {
        echo "=== Tier 1.2 subjectAssignments Authoritative + Completeness Verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // ── Step 1: build staff lookup ────────────────────────────────────
        echo "[Step 1] Loading staff lookup table\n";
        $staffMap = [];           // userId => ['status' => 'Active'|'Inactive', 'role' => '...']
        try {
            $staffRows = $this->firebase->firestoreQuery('staff', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            foreach ($staffRows as $s) {
                $data = is_array($s['data'] ?? null) ? $s['data'] : [];
                $uid  = (string)($data['userId'] ?? $data['User ID'] ?? '');
                if ($uid === '') continue;
                $staffMap[$uid] = [
                    'status' => (string)($data['status'] ?? $data['Status'] ?? 'Active'),
                    'role'   => (string)($data['role'] ?? $data['Position'] ?? ''),
                    'name'   => (string)($data['name'] ?? $data['Name'] ?? ''),
                ];
            }
            echo "  total staff: " . count($staffMap) . "\n";
            $activeCount = 0; $inactiveCount = 0;
            foreach ($staffMap as $info) {
                if (strcasecmp($info['status'], 'Inactive') === 0) $inactiveCount++;
                else $activeCount++;
            }
            echo "  active: {$activeCount}, inactive: {$inactiveCount}\n\n";
        } catch (\Throwable $e) {
            echo "  ERROR loading staff: " . $e->getMessage() . "\n";
            return;
        }

        // ── Step 2: fetch subjectAssignments ──────────────────────────────
        echo "[Step 2] Loading subjectAssignments\n";
        try {
            $assignments = $this->firebase->firestoreQuery('subjectAssignments', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
            return;
        }
        $total = count($assignments);
        echo "  total assignments: {$total}\n\n";

        if ($total === 0) {
            echo "No subjectAssignments in scope.\n";
            echo "=== T1.2 TRIVIAL PASS — no data to verify ===\n";
            return;
        }

        // ── Step 3: per-assignment cross-reference ────────────────────────
        echo "[Step 3] Cross-reference scan\n";

        $stats = [
            'active_clean'        => 0,  // active assignment + active teacher
            'archived_clean'      => 0,  // archived assignment (regardless of teacher status)
            'orphan_no_teacherId' => 0,  // missing/empty teacherId field
            'orphan_unknown_teacher' => 0,  // teacherId doesn't resolve to staff
            'phantom_active'      => 0,  // active assignment but teacher is Inactive — FREEZE
            'phantom_archived'    => 0,  // archived but teacher is Active (reactivation gap)
            'docid_drift'         => 0,  // doc-id doesn't follow canonical pattern
            'classsection_drift'  => 0,  // class/section in doc-id non-canonical
        ];
        $issues = [];

        foreach ($assignments as $a) {
            $docId = (string)($a['id'] ?? '');
            $data  = is_array($a['data'] ?? null) ? $a['data'] : [];

            $teacherId = (string)($data['teacherId'] ?? $data['staffId'] ?? '');
            $aStatus   = (string)($data['status'] ?? '');
            $autoArchived = !empty($data['archivedBecauseOfDeactivation']);
            $isArchived = ($aStatus === 'archived' || $autoArchived);

            // Doc-id canonical: {schoolId}_{session}_Class_<n>_Section_<x>_<subjectCode>
            $expectedPrefix = "{$this->schoolFs}_{$this->sessionYear}_";
            $docidOk = (strpos($docId, $expectedPrefix) === 0);
            if (!$docidOk) {
                $stats['docid_drift']++;
                $issues[] = "DOCID-DRIFT: {$docId}";
            }
            // Class/section drift detection from doc-id segments
            // Expected pattern after prefix: Class_<n_token>_Section_<x_token>_<subjectCode>
            $afterPrefix = substr($docId, strlen($expectedPrefix));
            $classSectionOk = (bool) preg_match('/^Class_\S+_Section_\S+(_\S+)?$/', $afterPrefix);
            if ($docidOk && !$classSectionOk) {
                $stats['classsection_drift']++;
                $issues[] = "CLASSSECTION-DRIFT: {$docId} — afterPrefix=\"{$afterPrefix}\"";
            }

            // teacherId presence
            if ($teacherId === '') {
                $stats['orphan_no_teacherId']++;
                $issues[] = "ORPHAN-NO-TEACHERID: {$docId} (status={$aStatus} autoArchived=" . ($autoArchived ? 'true' : 'false') . ")";
                continue;
            }

            // teacherId resolves to staff?
            if (!isset($staffMap[$teacherId])) {
                $stats['orphan_unknown_teacher']++;
                $issues[] = "ORPHAN-UNKNOWN-TEACHER: {$docId} teacherId={$teacherId}";
                continue;
            }

            $teacherStatus = $staffMap[$teacherId]['status'];
            $teacherActive = (strcasecmp($teacherStatus, 'Active') === 0);

            if (!$isArchived && $teacherActive) {
                $stats['active_clean']++;
            } elseif ($isArchived) {
                $stats['archived_clean']++;
                if ($teacherActive) {
                    // Archived but teacher is now Active — could be intentional manual archive
                    // OR a reactivation gap. Flag the auto-archived variant specifically.
                    if ($autoArchived) {
                        $stats['phantom_archived']++;
                        $issues[] = "PHANTOM-ARCHIVED-AUTO: {$docId} teacherId={$teacherId} (teacher now Active but assignment still archivedBecauseOfDeactivation=true — possible reactivation gap)";
                    }
                }
            } elseif (!$isArchived && !$teacherActive) {
                // ACTIVE assignment but INACTIVE teacher — FREEZE
                $stats['phantom_active']++;
                $issues[] = "PHANTOM-ACTIVE: {$docId} teacherId={$teacherId} teacherStatus={$teacherStatus} — active assignment owned by INACTIVE teacher (cascade gap)";
            }
        }

        // ── Report ────────────────────────────────────────────────────────
        echo "\n─── Per-assignment classification ───\n";
        printf("  active_clean             : %d  (active assignment + active teacher)\n", $stats['active_clean']);
        printf("  archived_clean           : %d  (archived assignment regardless of teacher status)\n", $stats['archived_clean']);
        printf("  orphan_no_teacherId      : %d  ⚠ INVESTIGATE\n", $stats['orphan_no_teacherId']);
        printf("  orphan_unknown_teacher   : %d  ⚠ INVESTIGATE\n", $stats['orphan_unknown_teacher']);
        printf("  phantom_active           : %d  🛑 FREEZE candidate (active assn + inactive teacher)\n", $stats['phantom_active']);
        printf("  phantom_archived         : %d  ⚠ WATCH (reactivation gap candidate)\n", $stats['phantom_archived']);
        printf("  docid_drift              : %d  ⚠ doc-id not in canonical prefix shape\n", $stats['docid_drift']);
        printf("  classsection_drift       : %d  ⚠ class/section segment doesn't match canonical pattern\n", $stats['classsection_drift']);

        $totalDrift = $stats['orphan_no_teacherId']
                    + $stats['orphan_unknown_teacher']
                    + $stats['phantom_active']
                    + $stats['phantom_archived']
                    + $stats['docid_drift']
                    + $stats['classsection_drift'];

        if (!empty($issues)) {
            echo "\n─── Issue details ───\n";
            foreach ($issues as $i) echo "  - {$i}\n";
        }

        echo "\n─── Verdict ───\n";
        if ($stats['phantom_active'] > 0) {
            echo "🛑 FREEZE_REQUIRED — {$stats['phantom_active']} active assignment(s) owned by inactive teacher. Phase 3 cascade integrity boundary violation.\n";
        } elseif ($totalDrift === 0) {
            echo "✅ T1.2 NORMAL — full cross-reference conformance:\n";
            printf("    %d active assignments correctly map to active teachers\n", $stats['active_clean']);
            printf("    %d archived assignments preserved per Phase 3 cascade\n", $stats['archived_clean']);
            echo "    0 orphans / phantoms / drift indicators\n";
        } else {
            echo "⚠ WATCH — {$totalDrift} drift indicators across non-critical categories. Classify each above per soak contract.\n";
        }

        echo "\n=== End verification ===\n";
    }
}
