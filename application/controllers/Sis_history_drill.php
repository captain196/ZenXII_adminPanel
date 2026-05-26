<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sis_history_drill — Tier 2 T2.2 drill-down.
 *
 * Reads STU0001 (and other selected students) History field and dumps the
 * key-encoded timestamp vs changed_at timestamp side-by-side, plus the action
 * and metadata. Read-only. CLI only.
 *
 * INVOCATION:
 *   php index.php sis_history_drill dump
 *   Env: SCHOOL_ID=<schoolFs>
 */
class Sis_history_drill extends CI_Controller
{
    private string $schoolFs = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->load->library('firestore_service');
        $this->schoolFs = (string) (getenv('SCHOOL_ID') ?: '');
        if ($this->schoolFs === '') { echo "ERROR: Set SCHOOL_ID\n"; exit(1); }
    }

    public function dump(): void
    {
        // Phase 2.1.6e (2026-05-24) — reader cutover: source from canonical
        // studentHistory collection (was legacy students.History map).
        $studentRows = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $this->schoolFs],
        ], null, 'ASC', 100);

        $historyRows = $this->firebase->firestoreQuery('studentHistory', [
            ['schoolId', '==', $this->schoolFs],
        ], null, 'ASC', 5000);

        // Group history by studentId
        $byStudent = [];
        foreach ($historyRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sid = (string)($d['studentId'] ?? '');
            $hk  = (string)($d['histKey']   ?? '');
            if ($sid === '' || $hk === '') continue;
            if (!isset($byStudent[$sid])) $byStudent[$sid] = [];
            $byStudent[$sid][$hk] = $d;
        }
        foreach ($byStudent as &$h) ksort($h);
        unset($h);

        foreach ($studentRows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sid = (string)($d['studentId'] ?? $d['User ID'] ?? $d['userId'] ?? $r['id'] ?? '');
            $h = $byStudent[$sid] ?? [];
            if (empty($h)) continue;

            echo "═════ {$sid} (status=" . ($d['status'] ?? $d['Status'] ?? '?')
                . ", admissionDate=" . ($d['Admission Date'] ?? $d['admissionDate'] ?? '?')
                . ", createdAt=" . ($d['createdAt'] ?? '?') . ") ═════\n";
            foreach ($h as $key => $entry) {
                $act = $entry['action'] ?? '?';
                $ca  = $entry['changed_at'] ?? '?';
                $by  = $entry['changed_by'] ?? '?';
                $desc = $entry['description'] ?? '';
                $meta = $entry['metadata'] ?? [];
                $metaStr = is_array($meta) ? json_encode($meta) : (string)$meta;
                echo "  key={$key}\n";
                echo "    action={$act}  changed_at={$ca}  by={$by}\n";
                echo "    desc: {$desc}\n";
                echo "    meta: {$metaStr}\n";
            }
            echo "\n";
        }
    }
}
