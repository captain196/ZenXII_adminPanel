<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ptm_schoolid_probe — PTM Tier 1.0 schoolId field-value convention discovery.
 *
 * READ-ONLY CLI tool. Per Ptm.php:48-49 code review:
 *   $this->school_name = $claims['school_id'];
 *   $this->school_id   = $claims['school_id'];
 *
 * Both bound to the same JWT-claim value (likely friendly school_name).
 * This probe verifies which value the PTM collections actually store.
 *
 * Probe sequence per collection:
 *   1. Query with schoolFs filter — note count
 *   2. Query without filter — sample schoolId values
 *
 * INVOCATION:
 *   php index.php ptm_schoolid_probe verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Ptm_schoolid_probe extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const COLLECTIONS = ['ptmEvents', 'ptmRsvps', 'pushRequests'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Ptm_schoolid_probe is CLI-only.', 403);
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

    /** CLI: php index.php ptm_schoolid_probe verify */
    public function verify(): void
    {
        echo "=== PTM Tier 1.0 schoolId field-value convention discovery ===\n";
        echo "Scope: env SCHOOL_ID={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        foreach (self::COLLECTIONS as $col) {
            echo "─── {$col} ───\n";

            // Probe 1: with schoolFs filter
            try {
                $rows = $this->firebase->firestoreQuery($col, [
                    ['schoolId', '==', $this->schoolFs],
                ], null, 'ASC', 100);
                echo "  Probe 1 (schoolId == {$this->schoolFs}): " . count($rows) . " docs\n";
            } catch (\Throwable $e) {
                echo "  Probe 1 ERROR: " . $e->getMessage() . "\n";
            }

            // Probe 2: without filter — sample schoolId values
            try {
                $rowsAll = $this->firebase->firestoreQuery($col, [], null, 'ASC', 100);
                $allHits = count($rowsAll);
                echo "  Probe 2 (no filter): {$allHits} docs total\n";
                if ($allHits > 0) {
                    $distinct = [];
                    foreach ($rowsAll as $r) {
                        $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                        $sid = (string)($d['schoolId'] ?? '(missing)');
                        if (!isset($distinct[$sid])) $distinct[$sid] = 0;
                        $distinct[$sid]++;
                    }
                    echo "  distinct schoolId field values found:\n";
                    foreach ($distinct as $v => $c) {
                        $marker = $v === $this->schoolFs ? '(matches schoolFs)' : ($v === '(missing)' ? '(absent)' : '(NON-canonical / friendly?)');
                        echo "    \"{$v}\" x {$c} {$marker}\n";
                    }
                    // Sample first doc field set
                    $first = is_array($rowsAll[0]['data'] ?? null) ? $rowsAll[0]['data'] : [];
                    echo "  first-doc top-level fields: " . implode(', ', array_slice(array_keys($first), 0, 15)) . "\n";
                }
            } catch (\Throwable $e) {
                echo "  Probe 2 ERROR: " . $e->getMessage() . "\n";
            }
            echo "\n";
        }

        echo "─── Convention summary ───\n";
        echo "Reference values:\n";
        echo "  schoolFs    = {$this->schoolFs}\n";
        echo "  sessionYear = {$this->sessionYear}\n";
        echo "\nInterpretation:\n";
        echo "  - If schoolFs matches → PTM uses canonical FS-style schoolId\n";
        echo "  - If different value → PTM uses friendly school_name (matching Library T1.0 deferred question)\n";
        echo "  - If empty across all 3 → PTM not yet operationally used\n";
        echo "\n=== End discovery ===\n";
    }
}
