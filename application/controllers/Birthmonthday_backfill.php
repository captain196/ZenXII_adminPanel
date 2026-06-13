<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Birthmonthday_backfill — one-time migration for the dashboard "Birthdays
 * Today" optimization.
 *
 * Populates a precomputed `birthMonthDay` ("MM-DD") field on every student
 * doc, derived from their DOB. The dashboard then matches birthdays with a
 * single equality query instead of scanning + DOB-parsing every student.
 *
 * New / edited students get `birthMonthDay` automatically via
 * Firestore_service::saveStudent(); this script covers the EXISTING ones.
 *
 * Subcommands:
 *   dry_run   List what would change (no writes)
 *   apply     Patch students whose birthMonthDay is missing/stale
 *   verify    Report how many students still lack a correct birthMonthDay
 *
 * INVOCATION:
 *   SCHOOL_ID=<schoolFs> php index.php birthmonthday_backfill dry_run
 *   SCHOOL_ID=<schoolFs> php index.php birthmonthday_backfill apply
 *   SCHOOL_ID=<schoolFs> php index.php birthmonthday_backfill verify
 *
 * Safety:
 *   - CLI-only (no HTTP entry point)
 *   - Idempotent — only patches when the stored value differs
 *   - Non-destructive — patches a single field, touches nothing else
 *   - Per-doc try/catch; failures logged, not aborted
 */
class Birthmonthday_backfill extends CI_Controller
{
    private const FETCH_LIMIT = 20000; // warn if hit (pagination needed)

    private string $schoolFs = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Birthmonthday_backfill is CLI-only.', 403);
        }
        $this->load->library('firebase');
        $this->load->library('firestore_service');

        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') {
            echo "ERROR: Set SCHOOL_ID environment variable.\n";
            exit(1);
        }
    }

    /** CLI: php index.php birthmonthday_backfill dry_run */
    public function dry_run(): void { $this->_run(false); }

    /** CLI: php index.php birthmonthday_backfill apply */
    public function apply(): void { $this->_run(true); }

    /** CLI: php index.php birthmonthday_backfill verify */
    public function verify(): void
    {
        echo "=== birthmonthday_backfill verify ===\n";
        echo "schoolFs={$this->schoolFs}\n\n";

        $students = $this->_fetch_students();
        echo "students fetched: " . count($students) . "\n";

        $ok = 0; $missing = 0; $unparseable = 0;
        foreach ($students as $s) {
            $d   = is_array($s['data'] ?? null) ? $s['data'] : [];
            $dob = (string) ($d['DOB'] ?? $d['dob'] ?? '');
            $want = Firestore_service::birthMonthDay($dob);
            $have = (string) ($d['birthMonthDay'] ?? '');
            if ($want === '') { $unparseable++; continue; }
            if ($have === $want) $ok++; else $missing++;
        }
        echo "correct birthMonthDay:        {$ok}\n";
        echo "missing/stale:                {$missing}\n";
        echo "unparseable DOB (skipped):    {$unparseable}\n";
        echo ($missing === 0 ? "\n✅ All parseable DOBs have a correct birthMonthDay.\n"
                             : "\n⚠ {$missing} student(s) still need apply.\n");
        echo "=== End verify ===\n";
    }

    // ── Internals ────────────────────────────────────────────────────

    private function _fetch_students(): array
    {
        try {
            $rows = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', self::FETCH_LIMIT);
            if (count($rows) >= self::FETCH_LIMIT) {
                echo "⚠ WARNING: fetched " . self::FETCH_LIMIT . " students (limit hit) — "
                   . "some students may be unprocessed; pagination needed.\n";
            }
            return $rows;
        } catch (\Throwable $e) {
            echo "ERROR fetching students: " . $e->getMessage() . "\n";
            return [];
        }
    }

    private function _run(bool $apply): void
    {
        $mode = $apply ? 'APPLY' : 'DRY-RUN';
        echo "=== birthmonthday_backfill {$mode} ===\n";
        echo "schoolFs={$this->schoolFs}\n\n";

        $students = $this->_fetch_students();
        echo "students fetched: " . count($students) . "\n";

        $stats = ['scanned' => 0, 'updated' => 0, 'already_ok' => 0, 'unparseable' => 0, 'failed' => 0];

        foreach ($students as $s) {
            $stats['scanned']++;
            $d     = is_array($s['data'] ?? null) ? $s['data'] : [];
            $docId = (string) ($s['id'] ?? '');
            if ($docId === '') continue;

            $dob  = (string) ($d['DOB'] ?? $d['dob'] ?? '');
            $want = Firestore_service::birthMonthDay($dob);
            $have = (string) ($d['birthMonthDay'] ?? '');

            if ($want === '') { $stats['unparseable']++; continue; }
            if ($have === $want) { $stats['already_ok']++; continue; }

            if (!$apply) {
                echo "  [DRY-RUN] {$docId}: '{$have}' → '{$want}'\n";
                $stats['updated']++;
                continue;
            }
            try {
                $ok = $this->firebase->firestoreUpdate('students', $docId, ['birthMonthDay' => $want]);
                if ($ok) { echo "  [UPDATED] {$docId}: birthMonthDay={$want}\n"; $stats['updated']++; }
                else     { echo "  [FAIL] {$docId} (update returned false)\n"; $stats['failed']++; }
            } catch (\Throwable $e) {
                echo "  [FAIL] {$docId} — " . $e->getMessage() . "\n";
                log_message('error', "birthmonthday_backfill {$mode} failed for {$docId}: " . $e->getMessage());
                $stats['failed']++;
            }
        }

        echo "\n── Summary ──\n";
        foreach ($stats as $k => $v) echo "  {$k}: {$v}\n";
        echo "=== End {$mode} ===\n";
    }
}
