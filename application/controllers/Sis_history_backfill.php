<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sis_history_backfill — P2.1.6c bounded data-migration controller.
 *
 * Copies each entry in `students.History` map field into the new
 * `studentHistory/{schoolFs}_{studentId}_{histKey}` flat collection
 * (Phase 2.1.6 Option A architecture per [[sis_tier2_closed]] T2.7
 * + BUG_LEDGER CARRY-009).
 *
 * Subcommands:
 *   dry-run   List what would be migrated (no Firestore writes)
 *   apply     Execute the backfill (idempotent — createDocument fails-if-exists)
 *   verify    Cross-reference map entries vs studentHistory docs
 *
 * INVOCATION:
 *   php index.php sis_history_backfill dry_run
 *   php index.php sis_history_backfill apply
 *   php index.php sis_history_backfill verify
 *   Env: SCHOOL_ID=<schoolFs>
 *
 * Safety:
 *   - CLI-only (no HTTP entry point)
 *   - createDocument fails-if-exists — re-runs are idempotent
 *   - No destructive operation (existing History map preserved)
 *   - Per-doc try/catch; failures logged, not aborted
 */
class Sis_history_backfill extends CI_Controller
{
    private string $schoolFs = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Sis_history_backfill is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') {
            echo "ERROR: Set SCHOOL_ID environment variable.\n";
            exit(1);
        }
    }

    /** CLI: php index.php sis_history_backfill dry_run */
    public function dry_run(): void
    {
        $this->_run(/* apply */ false);
    }

    /** CLI: php index.php sis_history_backfill apply */
    public function apply(): void
    {
        $this->_run(/* apply */ true);
    }

    /** CLI: php index.php sis_history_backfill verify */
    public function verify(): void
    {
        echo "=== sis_history_backfill verify (post-APPLY cross-reference) ===\n";
        echo "schoolFs={$this->schoolFs}\n\n";

        $students = $this->_fetch_students();
        echo "students fetched: " . count($students) . "\n";

        $mapCount = 0;
        $foundCount = 0;
        $missing = [];

        foreach ($students as $s) {
            $d = is_array($s['data'] ?? null) ? $s['data'] : [];
            $studentId = (string)($d['studentId'] ?? $d['User ID'] ?? $d['userId'] ?? '');
            if ($studentId === '') continue;
            $history = $d['History'] ?? [];
            if (!is_array($history) || empty($history)) continue;

            foreach ($history as $histKey => $entry) {
                if (!is_array($entry) || $histKey === '') continue;
                $mapCount++;
                $docId = $this->schoolFs . '_' . $studentId . '_' . $histKey;
                $existing = null;
                try {
                    $existing = $this->firebase->firestoreGet('studentHistory', $docId);
                } catch (\Throwable $e) {}
                if (is_array($existing) && !empty($existing)) {
                    $foundCount++;
                } else {
                    $missing[] = $docId;
                }
            }
        }

        echo "map entries:            {$mapCount}\n";
        echo "studentHistory matches: {$foundCount}\n";
        echo "missing (not backfilled): " . count($missing) . "\n";
        foreach ($missing as $m) echo "    - {$m}\n";

        if ($mapCount > 0 && $foundCount === $mapCount && empty($missing)) {
            echo "\n✅ Backfill verified — all map entries have corresponding studentHistory docs.\n";
        } elseif ($mapCount === 0) {
            echo "\nℹ No map entries to verify.\n";
        } else {
            echo "\n⚠ Backfill incomplete — " . count($missing) . " missing.\n";
        }
        echo "=== End verify ===\n";
    }

    // ── Internals ────────────────────────────────────────────────────

    private function _fetch_students(): array
    {
        try {
            return $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "ERROR fetching students: " . $e->getMessage() . "\n";
            return [];
        }
    }

    private function _run(bool $apply): void
    {
        $mode = $apply ? 'APPLY' : 'DRY-RUN';
        echo "=== sis_history_backfill {$mode} ===\n";
        echo "schoolFs={$this->schoolFs}\n";
        echo "target collection: studentHistory\n";
        echo "doc-id format: {schoolFs}_{studentId}_{histKey}\n\n";

        $students = $this->_fetch_students();
        echo "students fetched: " . count($students) . "\n";

        $stats = [
            'students_with_history' => 0,
            'map_entries_total'     => 0,
            'would_create'          => 0,
            'created'               => 0,
            'skipped_existing'      => 0,
            'failed'                => 0,
        ];

        foreach ($students as $s) {
            $d = is_array($s['data'] ?? null) ? $s['data'] : [];
            $studentId = (string)($d['studentId'] ?? $d['User ID'] ?? $d['userId'] ?? '');
            if ($studentId === '') continue;
            $history = $d['History'] ?? [];
            if (!is_array($history) || empty($history)) continue;

            $stats['students_with_history']++;
            echo "── {$studentId}: " . count($history) . " history entries ──\n";

            foreach ($history as $histKey => $entry) {
                if (!is_array($entry) || $histKey === '') continue;
                $stats['map_entries_total']++;

                $docId = $this->schoolFs . '_' . $studentId . '_' . $histKey;
                $data = [
                    'schoolId'    => $this->schoolFs,
                    'studentId'   => $studentId,
                    'histKey'     => $histKey,
                    'action'      => (string)($entry['action']      ?? ''),
                    'description' => (string)($entry['description'] ?? ''),
                    'changed_by'  => (string)($entry['changed_by']  ?? ''),
                    'changed_at'  => (string)($entry['changed_at']  ?? ''),
                    'metadata'    => is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [],
                    'backfilledAt' => date('c'),
                    'backfillSource' => 'sis_history_backfill_p2_1_6c',
                ];

                if (!$apply) {
                    // Dry-run: just report
                    echo "  [DRY-RUN] would create studentHistory/{$docId}  (action={$data['action']})\n";
                    $stats['would_create']++;
                    continue;
                }

                // APPLY mode — idempotency via createDocument fails-if-exists
                try {
                    // Check if already backfilled
                    $existing = $this->firebase->firestoreGet('studentHistory', $docId);
                    if (is_array($existing) && !empty($existing)) {
                        echo "  [SKIP] {$docId} already exists\n";
                        $stats['skipped_existing']++;
                        continue;
                    }
                    $ok = $this->firebase->firestoreCreate('studentHistory', $docId, $data);
                    if ($ok) {
                        echo "  [CREATED] studentHistory/{$docId}\n";
                        $stats['created']++;
                    } else {
                        // createDocument returned false — likely 409 (race condition)
                        echo "  [SKIP] {$docId} (createDocument returned false — may have been created concurrently)\n";
                        $stats['skipped_existing']++;
                    }
                } catch (\Throwable $e) {
                    echo "  [FAIL] {$docId} — " . $e->getMessage() . "\n";
                    log_message('error', "sis_history_backfill {$mode} failed for {$docId}: " . $e->getMessage());
                    $stats['failed']++;
                }
            }
        }

        echo "\n── Summary ──\n";
        foreach ($stats as $k => $v) echo "  {$k}: {$v}\n";
        echo "=== End {$mode} ===\n";
    }
}
