<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Library_schoolid_probe — Library Tier 1.0 schoolId field-value convention
 * discovery.
 *
 * READ-ONLY CLI tool. Per code review at Library.php:86:
 *   firestoreQuery(self::COL_CATEGORIES, [['schoolId', '==', $this->school_name]], ...)
 *
 * The Library module appears to query with `school_name` (friendly) rather
 * than `schoolFs` (FS-style canonical). This probe verifies which value
 * the schoolId field actually contains in the 4 Library collections.
 *
 * Probe sequence per collection:
 *   1. Query with schoolFs filter — note count
 *   2. If 0, query without filter — sample schoolId value
 *   3. Report what convention is actually stored
 *
 * INVOCATION:
 *   php index.php library_schoolid_probe verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Library_schoolid_probe extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const COLLECTIONS = ['libraryBooks', 'bookCategories', 'libraryIssues', 'libraryFines'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Library_schoolid_probe is CLI-only.', 403);
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

    /** CLI: php index.php library_schoolid_probe verify */
    public function verify(): void
    {
        echo "=== Library Tier 1.0 schoolId field-value convention discovery ===\n";
        echo "Scope: env SCHOOL_ID={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        foreach (self::COLLECTIONS as $col) {
            echo "─── {$col} ───\n";
            $fsHits = 0;
            $allHits = 0;
            $distinctSchoolIdValues = [];

            // Probe 1: with schoolFs filter
            try {
                $rows = $this->firebase->firestoreQuery($col, [
                    ['schoolId', '==', $this->schoolFs],
                ], null, 'ASC', 100);
                $fsHits = count($rows);
                echo "  Probe 1 (schoolId == {$this->schoolFs}): {$fsHits} docs\n";
            } catch (\Throwable $e) {
                echo "  Probe 1 ERROR: " . $e->getMessage() . "\n";
            }

            // Probe 2: without filter — sample schoolId values
            try {
                $rowsAll = $this->firebase->firestoreQuery($col, [], null, 'ASC', 100);
                $allHits = count($rowsAll);
                foreach ($rowsAll as $r) {
                    $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                    $sid = (string)($d['schoolId'] ?? '(missing)');
                    if (!isset($distinctSchoolIdValues[$sid])) $distinctSchoolIdValues[$sid] = 0;
                    $distinctSchoolIdValues[$sid]++;
                }
                echo "  Probe 2 (no filter): {$allHits} docs total\n";
                if (!empty($distinctSchoolIdValues)) {
                    echo "  distinct schoolId field values found:\n";
                    foreach ($distinctSchoolIdValues as $v => $c) {
                        $marker = $v === $this->schoolFs ? '(matches schoolFs)' : ($v === '(missing)' ? '(absent)' : '(NON-canonical)');
                        echo "    \"{$v}\" x {$c} {$marker}\n";
                    }
                }
            } catch (\Throwable $e) {
                echo "  Probe 2 ERROR: " . $e->getMessage() . "\n";
            }

            // Sample first doc field set if any data
            if ($allHits > 0) {
                $first = is_array($rowsAll[0]['data'] ?? null) ? $rowsAll[0]['data'] : [];
                echo "  first-doc field-set: " . implode(', ', array_slice(array_keys($first), 0, 15)) . "\n";
            }
            echo "\n";
        }

        echo "─── Convention summary ───\n";
        echo "Reference values:\n";
        echo "  schoolFs    = {$this->schoolFs}\n";
        echo "  sessionYear = {$this->sessionYear}\n";
        echo "\nInterpretation guide:\n";
        echo "  - If all probes return schoolFs match → Library uses canonical FS-style schoolId\n";
        echo "  - If probes return school_name (friendly) → Library uses legacy school_name convention\n";
        echo "  - If 0 docs in any collection → no data to verify; T1.0 trivially passes pending T1.1+\n";
        echo "\n=== End discovery ===\n";
    }
}
