<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_generation_ab_verify — READ ONLY. The Phase-1 byte-identical gate.
 *
 * For each Active student in the school: builds the legacy specs via
 * Fee_lifecycle::buildAdmissionDemandSpecs (the extracted helper) AND the
 * unified specs via Fee_generation_service::generateDemandsForStudent (the
 * new code path that P2 will route through). Deep-compares the two and
 * reports any divergence.
 *
 * **No writes.** No demand emission. No flag flips. Pure dry-run.
 *
 * P2 cutover is GATED on this probe returning **9/9 zero-diff** for all
 * students with no active studentConcessions/studentServiceEnrollments
 * (which is every student today — Phase 0 left no captured data).
 *
 * Usage:
 *   SCHOOL_ID=SCH_D94FE8F7AD [SESSION_YEAR=2026-27] \
 *     php index.php fee_generation_ab_verify check
 */
class Fee_generation_ab_verify extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) show_error('CLI-only.', 403);
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->load->library('Fee_lifecycle',      null, 'feeLifecycle');
        $this->load->library('Fee_concession_reader',         null, 'concessionReader');
        $this->load->library('Fee_service_enrollment_reader', null, 'enrollmentReader');
        $this->load->library('Fee_generation_service',        null, 'genSvc');
    }

    public function check(): void
    {
        $schoolFs    = (string)(getenv('SCHOOL_ID')    ?: '');
        $sessionYear = (string)(getenv('SESSION_YEAR') ?: '');
        $adminId     = 'ab_verify';

        if ($schoolFs === '') { echo "ERROR: SCHOOL_ID required.\n"; exit(1); }

        // Resolve session if not explicit (use schools.currentSession; fallback to latest in sessions[]).
        if ($sessionYear === '') {
            $schoolDoc = $this->firebase->firestoreGet('schools', $schoolFs);
            $sessionYear = (string)($schoolDoc['currentSession'] ?? '');
            if ($sessionYear === '' && isset($schoolDoc['sessions']) && is_array($schoolDoc['sessions'])) {
                $list = $schoolDoc['sessions']; rsort($list);
                $sessionYear = (string)($list[0] ?? '');
            }
            if ($sessionYear === '') { echo "ERROR: could not resolve session.\n"; exit(1); }
        }

        echo "═══════════════════════════════════════════════════════════════\n";
        echo " Phase-1 A/B verification — legacy vs unified demand specs (READ ONLY)\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        echo "  school:  {$schoolFs}\n";
        echo "  session: {$sessionYear}\n\n";

        // Init the shared infra.
        $this->fsTxn->init($this->firebase, $this->fs, $schoolFs, $sessionYear);
        $this->feeLifecycle->init($this->firebase, $schoolFs, $sessionYear, $adminId);
        $this->concessionReader->init($this->firebase);
        $this->enrollmentReader->init($this->firebase);
        $this->genSvc->init(
            $this->feeLifecycle,
            $this->concessionReader,
            $this->enrollmentReader,
            $schoolFs,
            $sessionYear
        );

        // Enumerate Active students.
        $studs = $this->firebase->firestoreQuery('students', [
            ['schoolId', '==', $schoolFs],
        ], null, 'ASC', 1000);
        $pool = [];
        foreach ($studs as $r) {
            $d   = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sid = (string)($d['studentId'] ?? $d['userId'] ?? '');
            $sta = (string)($d['status'] ?? $d['Status'] ?? '');
            $cls = (string)($d['className'] ?? '');
            $sec = (string)($d['section']   ?? '');
            if ($sid === '' || $cls === '' || $sec === '') continue;
            if ($sta !== '' && $sta !== 'Active') continue;
            $pool[$sid] = ['class' => $cls, 'section' => $sec, 'name' => (string)($d['name'] ?? '')];
        }
        ksort($pool);

        $total = count($pool);
        $pass = 0; $fail = 0; $skipped = 0;
        echo "── per-student A/B ──  ({$total} active students)\n";
        foreach ($pool as $sid => $info) {
            $legacy = $this->feeLifecycle->buildAdmissionDemandSpecs($sid, $info['class'], $info['section']);
            $unified = $this->genSvc->generateDemandsForStudent($sid, $info['class'], $info['section']);

            if (!($legacy['chartFound'] ?? false) && !($unified['chartFound'] ?? false)) {
                echo sprintf("  %-10s %-12s/%-12s  no chart — both empty (skipped)\n", $sid, $info['class'], $info['section']);
                $skipped++;
                continue;
            }

            $diff = $this->_specDiff($legacy['specs'] ?? [], $unified['specs'] ?? []);
            if (empty($diff)) {
                echo sprintf("  %-10s %-12s/%-12s  legacy_specs=%-3d unified_specs=%-3d  ✓ identical\n",
                    $sid, $info['class'], $info['section'], count($legacy['specs']), count($unified['specs']));
                $pass++;
            } else {
                echo sprintf("  %-10s %-12s/%-12s  legacy_specs=%-3d unified_specs=%-3d  ✗ DIFF (%d issues)\n",
                    $sid, $info['class'], $info['section'], count($legacy['specs']), count($unified['specs']), count($diff));
                foreach (array_slice($diff, 0, 6) as $line) echo "      - {$line}\n";
                if (count($diff) > 6) echo "      ... (" . (count($diff) - 6) . " more)\n";
                $fail++;
            }
        }

        echo "\n── Result ──\n";
        echo "  pass:    {$pass} / {$total}\n";
        echo "  fail:    {$fail}\n";
        echo "  skipped: {$skipped}\n";
        $gate = ($fail === 0 && ($pass + $skipped) === $total);
        echo "  GATE:    " . ($gate ? "PASS — safe to authorize P2 flag flip" : "FAIL — investigate before any P2 cutover") . "\n";
        echo "═══════════════════════════════════════════════════════════════\n";
        exit($gate ? 0 : 1);
    }

    /**
     * Deep-diff two spec arrays. Both are unordered (built from chart-month
     * iteration which is map-key order — same in both calls). Compare by
     * demandId; for matched specs, deep-compare `data`.
     */
    private function _specDiff(array $a, array $b): array
    {
        $diff = [];
        $byIdA = [];
        foreach ($a as $e) $byIdA[(string)($e['demandId'] ?? '')] = $e;
        $byIdB = [];
        foreach ($b as $e) $byIdB[(string)($e['demandId'] ?? '')] = $e;

        // missing in unified
        foreach ($byIdA as $id => $ea) {
            if (!isset($byIdB[$id])) $diff[] = "missing in unified: {$id}";
        }
        // extra in unified
        foreach ($byIdB as $id => $eb) {
            if (!isset($byIdA[$id])) $diff[] = "extra in unified:   {$id}";
        }
        // field-level diff on matched
        foreach ($byIdA as $id => $ea) {
            if (!isset($byIdB[$id])) continue;
            $eb = $byIdB[$id];
            if (($ea['op'] ?? '') !== ($eb['op'] ?? '')) $diff[] = "{$id}: op differs ({$ea['op']} vs {$eb['op']})";
            $da = is_array($ea['data'] ?? null) ? $ea['data'] : [];
            $db = is_array($eb['data'] ?? null) ? $eb['data'] : [];
            // Skip call-time metadata: createdAt/updatedAt are stamped via
            // date('c') inside each invocation, so calling legacy + unified
            // back-to-back inevitably differs by 0-N seconds depending on
            // when each call straddles a wall-clock boundary. In production
            // each path is called exactly ONCE per admission — same timestamp
            // semantics — so the diff would never appear.
            $skipFields = ['createdAt', 'updatedAt'];
            $allKeys = array_unique(array_merge(array_keys($da), array_keys($db)));
            foreach ($allKeys as $k) {
                if (in_array($k, $skipFields, true)) continue;
                $va = $da[$k] ?? null;
                $vb = $db[$k] ?? null;
                // Tolerate tiny float drift on the rounded amount fields only.
                if (is_numeric($va) && is_numeric($vb) && in_array($k, ['grossAmount','netAmount','balance','paidAmount','concessionApplied'], true)) {
                    if (abs(((float)$va) - ((float)$vb)) > 0.0001) $diff[] = "{$id}.{$k}: {$va} != {$vb}";
                } else {
                    if (json_encode($va) !== json_encode($vb)) $diff[] = "{$id}.{$k}: " . json_encode($va) . " != " . json_encode($vb);
                }
            }
        }
        return $diff;
    }
}
