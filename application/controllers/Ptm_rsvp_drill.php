<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ptm_rsvp_drill — P1.3 forensic drill on ptmRsvps out-of-enum status.
 *
 * Tier 1.6 (PTM closure) reported 1 ptmRsvp with status="delivered" not in
 * declared enum (pending/confirmed/declined/attended/no-show). This drill
 * dumps the full content of every non-canonical RSVP for semantic-intent
 * analysis.
 *
 * INVOCATION: php index.php ptm_rsvp_drill dump
 * Env: SCHOOL_ID=<schoolFs>
 *
 * Read-only. CLI-only.
 */
class Ptm_rsvp_drill extends CI_Controller
{
    private const CANONICAL_STATUSES = ['pending', 'confirmed', 'declined', 'attended', 'no-show'];
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
        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('ptmRsvps', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 1000);
        } catch (\Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n"; exit(1);
        }

        echo "Total ptmRsvps: " . count($rows) . "\n";
        echo "Canonical statuses: " . implode(',', self::CANONICAL_STATUSES) . "\n\n";

        $statusDist = [];
        $offenders = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $s = (string)($d['status'] ?? 'MISSING');
            $statusDist[$s] = ($statusDist[$s] ?? 0) + 1;
            if (!in_array(strtolower($s), self::CANONICAL_STATUSES, true)) {
                $offenders[] = ['docId' => (string)($r['id'] ?? ''), 'data' => $d];
            }
        }
        echo "Status distribution: " . json_encode($statusDist) . "\n";
        echo "Out-of-enum count: " . count($offenders) . "\n\n";

        foreach ($offenders as $i => $row) {
            echo "═════ Offender [{$i}] ═════\n";
            echo "  docId: {$row['docId']}\n";
            echo "  fields: " . implode(', ', array_keys($row['data'])) . "\n";
            foreach ($row['data'] as $k => $v) {
                $vs = is_scalar($v) ? (string)$v : json_encode($v);
                if (strlen($vs) > 120) $vs = substr($vs, 0, 120) . '…';
                echo "    {$k} = {$vs}\n";
            }
            echo "\n";
        }
    }
}
