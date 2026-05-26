<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Comm_counter_probe — P2.0.1 pre-APPLY forensic on parallel counter values.
 *
 * Reads current values of:
 *   schools/{schoolFs}_profile.commCounters.Notice  (Writer 1's canonical counter)
 *   communicationCounters/{school_name}.noticeCounter (Writer 2's parallel counter)
 *
 * Goal: assess whether post-P2.0.1 (Writer 2 starts producing same {schoolFs}_NOTxxxx
 * format as Writer 1) the two counters would produce colliding doc IDs.
 *
 * INVOCATION: php index.php comm_counter_probe probe
 * Env: SCHOOL_ID=<schoolFs>
 */
class Comm_counter_probe extends CI_Controller
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

    public function probe(): void
    {
        echo "=== Communication counter topology probe (post-P2.0.1' canonical) ===\n";
        echo "schoolFs={$this->schoolFs}\n\n";

        // Canonical counter: schools/{schoolFs}_profile.commCounters.Notice
        // Both Writer 1 (Communication.save_notice) and Writer 2 (Communication_helper.
        // write_event_notice) read/write THIS counter post-P2.0.1' bundled APPLY.
        $profile = $this->firebase->firestoreGet('schools', $this->schoolFs . '_profile');
        if (!is_array($profile)) {
            $profile = $this->firebase->firestoreGet('schools', $this->schoolFs);
        }
        $canonical = null;
        if (is_array($profile)) {
            $canonical = $profile['commCounters.Notice'] ?? null;
            if ($canonical === null && isset($profile['commCounters']) && is_array($profile['commCounters'])) {
                $canonical = $profile['commCounters']['Notice'] ?? null;
            }
        }
        echo "Canonical Notice counter (Writer 1 + Writer 2 unified):\n";
        echo "  source: schools/{$this->schoolFs}_profile.commCounters.Notice\n";
        echo "  current value: " . var_export($canonical, true) . "\n\n";

        // LEGACY (deprecated post-P2.0.1'): communicationCounters/*.noticeCounter
        // No longer maintained by any writer. Informational only — should be
        // garbage-collected in a future data-hygiene pass.
        echo "Legacy counter (deprecated post-P2.0.1' — no active writer):\n";
        echo "  source: communicationCounters/*.noticeCounter\n";
        try {
            $rows = $this->firebase->firestoreQuery('communicationCounters', [], null, 'ASC', 100);
            if (empty($rows)) {
                echo "  collection empty (already cleaned)\n";
            } else {
                echo "  collection inventory (frozen at last pre-P2.0.1' value):\n";
                foreach ($rows as $r) {
                    $d = is_array($r['data']??null)?$r['data']:[];
                    $docId = (string)($r['id'] ?? '');
                    $nc = $d['noticeCounter'] ?? '(unset)';
                    $sid = $d['schoolId'] ?? '(unset)';
                    $upd = $d['updatedAt'] ?? '(unset)';
                    echo "    docId={$docId}  schoolId={$sid}  noticeCounter={$nc}  lastUpdate={$upd}\n";
                }
            }
        } catch (\Throwable $e) {
            echo "  listing error: " . $e->getMessage() . "\n";
        }

        echo "\n--- Notice doc-ID inventory ---\n";
        $notices = $this->firebase->firestoreQuery('notices', [
            ['schoolId', '==', $this->schoolFs],
        ], null, 'ASC', 200);
        $canonical4Pad = "/^" . preg_quote($this->schoolFs, '/') . "_NOT(\d{4})$/";
        $legacyRaw     = "/^NOT(\d{4,5})$/";
        $canonIds = [];
        $rawIds = [];
        foreach ($notices as $n) {
            $docId = (string)($n['id'] ?? '');
            if (preg_match($canonical4Pad, $docId, $m)) $canonIds[] = (int)$m[1];
            if (preg_match($legacyRaw, $docId, $m)) $rawIds[] = (int)$m[1];
        }
        sort($canonIds); sort($rawIds);
        echo "  Canonical school-scoped (post-P2.0.1' format): " . json_encode($canonIds) . "\n";
        echo "  Legacy raw format (pre-P2.0.1' Writer 2 docs awaiting P2.0.4 migration): " . json_encode($rawIds) . "\n";

        // Convergence verdict
        if (is_numeric($canonical)) {
            echo "\n--- Convergence verdict ---\n";
            $maxCanonical = empty($canonIds) ? 0 : max($canonIds);
            echo "  Canonical counter: {$canonical}\n";
            echo "  Highest extant canonical doc-id numeric: {$maxCanonical}\n";
            if ((int)$canonical >= $maxCanonical) {
                echo "  ✅ Counter ≥ highest extant doc-id — next write produces unique doc-id\n";
            } else {
                echo "  ⚠ Counter < highest extant doc-id — next write would COLLIDE\n";
            }
            echo "  Both writers (Writer 1 save_notice + Writer 2 write_event_notice) share this counter post-P2.0.1'.\n";
            echo "  Concurrent races on the unified counter remain a P2.0.3 transactionalization concern.\n";
        }
        echo "\n=== End probe ===\n";
    }
}
