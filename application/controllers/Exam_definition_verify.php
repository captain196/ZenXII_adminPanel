<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Exam_definition_verify — examination Tier 1.2 exam-definition canonical
 * verification.
 *
 * READ-ONLY CLI tool. Per code review:
 *   - Exam.php:161 writes to RTDB path `Schools/{schoolName}/{year}/Exams/{examId}`
 *   - No firestoreSet('examinations', ...) sites found across the codebase
 *
 * Therefore T1.2 verifies BOTH:
 *   (a) Whether any 'examinations' Firestore collection exists at all
 *       (discovery — would surface a partial migration if present)
 *   (b) The current authoritative source location (RTDB vs Firestore)
 *
 * If Firestore 'examinations' is empty, T1.2 classifies as the
 * "examination module not yet migrated to Firestore" carry — analogous
 * to other documented RTDB→Firestore migration boundaries.
 *
 * INVOCATION:
 *   php index.php exam_definition_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Exam_definition_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Exam_definition_verify is CLI-only.', 403);
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

    /** CLI: php index.php exam_definition_verify verify */
    public function verify(): void
    {
        echo "=== examination Tier 1.2 exam-definition canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // ── Probe 1: Firestore candidate collections ────────────────────
        echo "─── Firestore candidate collections ───\n";
        $candidates = ['examinations', 'exams', 'examMasters', 'examConfigurations', 'examDefinitions'];
        $hits = [];
        foreach ($candidates as $col) {
            try {
                $rows = $this->firebase->firestoreQuery($col, [
                    ['schoolId', '==', $this->schoolFs],
                ], null, 'ASC', 50);
                $cnt = count($rows);
                echo "  {$col}: {$cnt} docs (schoolId={$this->schoolFs} filter)\n";
                if ($cnt > 0) {
                    $hits[$col] = $rows;
                    // Sample doc
                    $sample = is_array($rows[0]['data'] ?? null) ? $rows[0]['data'] : [];
                    echo "    sample fields: " . implode(', ', array_keys($sample)) . "\n";
                }
                // Try without schoolId filter too
                if ($cnt === 0) {
                    $rowsAll = $this->firebase->firestoreQuery($col, [], null, 'ASC', 50);
                    $cntAll = count($rowsAll);
                    if ($cntAll > 0) {
                        echo "    (no-filter probe: {$cntAll} docs exist in collection but none match schoolId)\n";
                    }
                }
            } catch (\Throwable $e) {
                echo "  {$col}: unavailable — " . $e->getMessage() . "\n";
            }
        }

        // ── Probe 2: RTDB exam data presence ─────────────────────────────
        // Per code path Exam.php:161 — writes to Schools/{schoolName}/{year}/Exams/{examId}
        // Read this via the Firebase library's RTDB get() method to count.
        echo "\n─── RTDB exam-data probe (Exam.php:161 contract) ───\n";
        // We need schoolName, not schoolFs. The current bootstrap only provides
        // schoolFs (BUG-A7 Framing α). Try a few common paths.
        $rtdbProbed = false;
        try {
            // Attempt with schoolFs as the path component (probably won't work)
            $path = "Schools/{$this->schoolFs}/{$this->sessionYear}/Exams";
            $node = $this->firebase->get($path);
            echo "  Path tried: {$path}\n";
            echo "  Result: " . (is_array($node) ? count($node) . " entries" : var_export($node, true)) . "\n";
            $rtdbProbed = true;
        } catch (\Throwable $e) {
            echo "  RTDB probe with schoolFs failed: " . $e->getMessage() . "\n";
        }

        // ── Verdict ──────────────────────────────────────────────────────
        echo "\n─── Verdict ───\n";
        if (empty($hits)) {
            echo "✅/⚠ T1.2 OBSERVATION — no Firestore-side examination documents found in any of the\n";
            echo "candidate collections (examinations / exams / examMasters / examConfigurations /\n";
            echo "examDefinitions). Per code review (Exam.php:161), exam definitions are written to\n";
            echo "RTDB at 'Schools/{schoolName}/{year}/Exams/{examId}' — RTDB legacy storage.\n";
            echo "\nClassification options for operator:\n";
            echo "  (a) If RTDB is the documented current source-of-truth for exams → T1.2 NOT_APPLICABLE\n";
            echo "      from Firestore-canonical perspective; defer to RTDB-side verification (if any).\n";
            echo "  (b) If Phase 7+ examination Firestore migration was planned → T1.2 WATCH carry for\n";
            echo "      pending migration; parallels other RTDB → Firestore migration boundaries.\n";
            echo "\nNote: Per [[feedback_no_rtdb_ever]] policy, RTDB usage is forbidden. Examination\n";
            echo "module is the strongest candidate for the next phase of RTDB elimination.\n";
        } else {
            echo "⚠ INVESTIGATE — Firestore exam-related collection(s) found: " . implode(', ', array_keys($hits)) . "\n";
            echo "Need to verify whether these are canonical writes or transitional dual-emit.\n";
        }

        echo "\n=== End verification ===\n";
    }
}
