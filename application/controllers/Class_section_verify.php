<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Class_section_verify — Tier 1.1 cross-system canonical-schema verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical class/section schema per
 * [[student_class_section_canonical]] (Phases 1-6 closure) + the Entity_firestore_sync
 * normalizer contract (application/libraries/Entity_firestore_sync.php:145):
 *
 *   className:   "Class 10th"  (with "Class " prefix + ordinal suffix; or "Class LKG" etc.)
 *   section:     "Section A"   (with "Section " prefix)
 *   classOrder:  10            (integer for sort; null for non-numeric)
 *   sectionCode: "A"           (raw token without "Section " prefix)
 *
 * Scope: students collection (primary source of truth). Cross-collection
 * sampling deferred to Tier 1.2.
 *
 * INVOCATION:
 *   php index.php class_section_verify verify_students
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Class_section_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Class_section_verify is CLI-only.', 403);
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

    /** CLI: php index.php class_section_verify verify_students */
    public function verify_students(): void
    {
        echo "=== Tier 1.1 Class/Section Canonical Schema Verification — students ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $students = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            echo "Total students fetched: " . count($students) . "\n\n";

            // Buckets for classification
            $conformant       = [];   // all 4 canonical fields present + valid
            $missing_className = [];  // className absent or empty
            $missing_section   = [];  // section absent or empty
            $bad_className     = [];  // className present but malformed
            $bad_section       = [];  // section present but malformed
            $missing_order     = [];  // className present (numeric) but classOrder null/missing
            $missing_sectionCode = []; // section present but sectionCode absent
            $legacy_field      = [];  // legacy `class` field present (drift candidate)

            // Distinct value tallies (to surface drift visually)
            $classNameTally   = [];
            $sectionTally     = [];

            foreach ($students as $s) {
                $data = is_array($s['data'] ?? null) ? $s['data'] : [];
                $id   = (string)($s['id'] ?? '');

                $className   = (string)($data['className']   ?? '');
                $section     = (string)($data['section']     ?? '');
                $classOrder  = $data['classOrder'] ?? null;
                $sectionCode = (string)($data['sectionCode'] ?? '');
                $legacyClass = $data['class'] ?? null;   // drift candidate
                $studentName = (string)($data['name'] ?? $data['Name'] ?? '?');

                // Tally distinct values
                if ($className !== '') $classNameTally[$className] = ($classNameTally[$className] ?? 0) + 1;
                if ($section !== '')   $sectionTally[$section]     = ($sectionTally[$section] ?? 0) + 1;

                // Legacy `class` field detection
                if ($legacyClass !== null && $legacyClass !== '') {
                    $legacy_field[] = "{$id} ({$studentName}) — legacy field 'class'=" . var_export($legacyClass, true);
                }

                // Field presence checks
                if ($className === '') { $missing_className[] = "{$id} ({$studentName})"; continue; }
                if ($section === '')   { $missing_section[]   = "{$id} ({$studentName})"; }

                // Format checks
                $classOK = (bool) preg_match('/^Class \S.*$/', $className);
                if (!$classOK) $bad_className[] = "{$id} ({$studentName}) — className=\"{$className}\"";

                $sectOK = ($section === '') ? true : (bool) preg_match('/^Section .+$/', $section);
                if (!$sectOK) $bad_section[] = "{$id} ({$studentName}) — section=\"{$section}\"";

                // classOrder presence (for numeric classes)
                if ($classOK) {
                    if (preg_match('/^Class (\d+)/', $className, $m)) {
                        if ($classOrder === null || $classOrder === '') {
                            $missing_order[] = "{$id} ({$studentName}) — className=\"{$className}\" but classOrder=null";
                        }
                    }
                }

                // sectionCode presence (if section present)
                if ($section !== '' && $sectionCode === '') {
                    $missing_sectionCode[] = "{$id} ({$studentName}) — section=\"{$section}\" but sectionCode missing";
                }

                if ($classOK && $sectOK && ($className === '' || empty($missing_order)) && ($section === '' || empty($missing_sectionCode))) {
                    $conformant[] = "{$id} ({$studentName}) — \"{$className}\" \"{$section}\" (order={$classOrder}, code={$sectionCode})";
                }
            }

            // ── Report ────────────────────────────────────────────────────
            echo "─── Distribution ───\n";
            echo "Distinct className values:\n";
            ksort($classNameTally);
            foreach ($classNameTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
            echo "\nDistinct section values:\n";
            ksort($sectionTally);
            foreach ($sectionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";

            echo "\n─── Conformance ───\n";
            echo "Conformant (all 4 canonical fields valid): " . count($conformant) . "\n";

            echo "\nMissing className: " . count($missing_className) . "\n";
            foreach ($missing_className as $row) echo "  - {$row}\n";

            echo "\nMissing section: " . count($missing_section) . "\n";
            foreach ($missing_section as $row) echo "  - {$row}\n";

            echo "\nMalformed className (doesn't start with \"Class \"): " . count($bad_className) . "\n";
            foreach ($bad_className as $row) echo "  - {$row}\n";

            echo "\nMalformed section (doesn't start with \"Section \"): " . count($bad_section) . "\n";
            foreach ($bad_section as $row) echo "  - {$row}\n";

            echo "\nMissing classOrder for numeric class: " . count($missing_order) . "\n";
            foreach ($missing_order as $row) echo "  - {$row}\n";

            echo "\nMissing sectionCode despite section present: " . count($missing_sectionCode) . "\n";
            foreach ($missing_sectionCode as $row) echo "  - {$row}\n";

            echo "\nLegacy 'class' field present (drift candidate): " . count($legacy_field) . "\n";
            foreach ($legacy_field as $row) echo "  - {$row}\n";

            // ── Verdict ────────────────────────────────────────────────────
            $anyDrift = count($missing_className) + count($missing_section)
                      + count($bad_className) + count($bad_section)
                      + count($missing_order) + count($missing_sectionCode)
                      + count($legacy_field);

            echo "\n─── Summary ───\n";
            echo "Total drift indicators: {$anyDrift}\n";
            if ($anyDrift === 0) {
                echo "VERDICT: ✅ T1.1 NORMAL — full canonical conformance across students collection.\n";
            } else {
                echo "VERDICT: ⚠ Drift detected. Classify above per soak contract.\n";
            }
        } catch (\Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }

        echo "\n=== End verification ===\n";
    }
}
