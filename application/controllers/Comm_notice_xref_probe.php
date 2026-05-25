<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Comm_notice_xref_probe — P2.0.4 cross-reference forensic.
 *
 * Scans for any docs in cross-collections (circularReads, pushRequests,
 * messageInboxes, notifications) that reference the 4 raw-format
 * notice IDs (NOT00001-NOT00004) — these would orphan if we rename
 * to canonical {schoolFs}_NOTxxxx.
 *
 * Read-only. CLI-only.
 *
 * INVOCATION: php index.php comm_notice_xref_probe probe
 * Env: SCHOOL_ID=<schoolFs>
 */
class Comm_notice_xref_probe extends CI_Controller
{
    private string $schoolFs = '';
    private const TARGET_NOTICE_IDS = ['NOT00001', 'NOT00002', 'NOT00003', 'NOT00004'];

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
        echo "=== P2.0.4 cross-reference forensic on raw-format notice IDs ===\n";
        echo "schoolFs={$this->schoolFs}\n";
        echo "target notice IDs: " . json_encode(self::TARGET_NOTICE_IDS) . "\n\n";

        // ── 1. circularReads — docId is {circularId}_{userId}; circularId field also stored
        echo "── circularReads ──\n";
        try {
            $rows = $this->firebase->firestoreQuery('circularReads', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            $matches = 0;
            foreach ($rows as $r) {
                $d = is_array($r['data']??null)?$r['data']:[];
                $docId = (string)($r['id'] ?? '');
                $cid = (string)($d['circularId'] ?? '');
                if (in_array($cid, self::TARGET_NOTICE_IDS, true)) {
                    echo "  MATCH docId={$docId}  circularId={$cid}  userId=" . ($d['userId'] ?? '?') . "\n";
                    $matches++;
                }
                // Also check docId prefix
                foreach (self::TARGET_NOTICE_IDS as $tid) {
                    if (strpos($docId, $tid . '_') === 0) {
                        echo "  MATCH-by-docId-prefix docId={$docId}\n";
                        $matches++;
                    }
                }
            }
            echo "  total: " . count($rows) . " docs scanned; {$matches} matches.\n";
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        // ── 2. pushRequests — payloadData.noticeId or similar
        echo "\n── pushRequests ──\n";
        try {
            $rows = $this->firebase->firestoreQuery('pushRequests', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            $matches = 0;
            foreach ($rows as $r) {
                $d = is_array($r['data']??null)?$r['data']:[];
                $docId = (string)($r['id'] ?? '');
                $payload = is_array($d['payloadData'] ?? null) ? $d['payloadData'] : [];
                $nid1 = (string)($d['noticeId'] ?? $payload['noticeId'] ?? '');
                $sourceId = (string)($d['sourceId'] ?? $payload['sourceId'] ?? '');
                if (in_array($nid1, self::TARGET_NOTICE_IDS, true) || in_array($sourceId, self::TARGET_NOTICE_IDS, true)) {
                    echo "  MATCH docId={$docId}  noticeId={$nid1}  sourceId={$sourceId}\n";
                    $matches++;
                }
            }
            echo "  total: " . count($rows) . " docs scanned; {$matches} matches.\n";
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        // ── 3. messageInboxes — possible noticeId field
        echo "\n── messageInboxes ──\n";
        try {
            $rows = $this->firebase->firestoreQuery('messageInboxes', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            $matches = 0;
            foreach ($rows as $r) {
                $d = is_array($r['data']??null)?$r['data']:[];
                $nid = (string)($d['noticeId'] ?? $d['sourceId'] ?? '');
                if (in_array($nid, self::TARGET_NOTICE_IDS, true)) {
                    echo "  MATCH docId=" . ($r['id']??'?') . "  noticeId={$nid}\n";
                    $matches++;
                }
            }
            echo "  total: " . count($rows) . " docs scanned; {$matches} matches.\n";
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        // ── 4. notifications — possible noticeId field
        echo "\n── notifications ──\n";
        try {
            $rows = $this->firebase->firestoreQuery('notifications', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
            $matches = 0;
            foreach ($rows as $r) {
                $d = is_array($r['data']??null)?$r['data']:[];
                $nid = (string)($d['noticeId'] ?? $d['sourceId'] ?? '');
                if (in_array($nid, self::TARGET_NOTICE_IDS, true)) {
                    echo "  MATCH docId=" . ($r['id']??'?') . "  noticeId={$nid}\n";
                    $matches++;
                }
            }
            echo "  total: " . count($rows) . " docs scanned; {$matches} matches.\n";
        } catch (\Throwable $e) {
            echo "  ERROR: " . $e->getMessage() . "\n";
        }

        // ── 5. Show full doc content of the 4 raw notices
        echo "\n── Full content of 4 raw-format notice docs ──\n";
        foreach (self::TARGET_NOTICE_IDS as $nid) {
            $doc = null;
            try { $doc = $this->firebase->firestoreGet('notices', $nid); } catch (\Throwable $e) {}
            if (is_array($doc)) {
                echo "──── notices/{$nid} ────\n";
                foreach ($doc as $k => $v) {
                    $vs = is_scalar($v) ? (string)$v : json_encode($v);
                    if (strlen($vs) > 120) $vs = substr($vs, 0, 120) . '…';
                    echo "  {$k} = {$vs}\n";
                }
            } else {
                echo "──── notices/{$nid} — NOT FOUND\n";
            }
        }

        echo "\n=== End P2.0.4 cross-reference probe ===\n";
    }
}
