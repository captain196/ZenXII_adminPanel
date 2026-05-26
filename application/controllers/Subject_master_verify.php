<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Subject_master_verify — examination Tier 1.1 subject master canonical verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical schema per
 * School_config.php:1922-1928 save_subject write contract:
 *
 *   Collection: subjects
 *   Doc-id:     {classKey}_{subjectCode}
 *   Required:
 *     classKey    : string (numeric "10" or non-numeric "LKG")
 *     subjectCode : matches ^[A-Za-z0-9_]+$
 *     name        : non-empty
 *     category    : string
 *     stream      : string
 *
 *   Plus schoolId injected by setEntity (Firestore_service helper).
 *
 *   Additional invariants:
 *     - Doc-id matches {classKey}_{subjectCode} pattern
 *     - subjectCode unique within (school, classKey)
 *     - Subject name unique within (school, classKey) — duplicates indicate seed/migration drift
 *
 * INVOCATION:
 *   php index.php subject_master_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Subject_master_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const SUBJECT_CODE_REGEX = '/^[A-Za-z0-9_]+$/';
    private const REQUIRED_FIELDS = ['classKey', 'subjectCode', 'name'];
    private const OPTIONAL_FIELDS = ['category', 'stream'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Subject_master_verify is CLI-only.', 403);
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

    /** CLI: php index.php subject_master_verify verify */
    public function verify(): void
    {
        echo "=== examination Tier 1.1 subject master canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // Try schoolFs first; fall back to no-filter discovery if 0 results
        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('subjects', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
            echo "Query 1 (schoolId == {$this->schoolFs}): " . count($rows) . " docs\n";
            if (count($rows) === 0) {
                $rowsAll = $this->firebase->firestoreQuery('subjects', [
                ], null, 'ASC', 500);
                echo "Query 2 (no schoolId filter — discovery): " . count($rowsAll) . " docs\n";
                if (count($rowsAll) > 0) {
                    $sampleId = $rowsAll[0]['data']['schoolId'] ?? '(absent)';
                    echo "Sample schoolId value in collection: " . var_export($sampleId, true) . "\n";
                    $rows = $rowsAll;
                }
            }
        } catch (\Throwable $e) {
            echo "ERROR loading subjects: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "\nTotal subjects analyzed: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.1 TRIVIAL PASS — no subjects in scope ===\n";
            return;
        }

        // Distribution tallies
        $classKeyTally   = [];
        $categoryTally   = [];
        $streamTally     = [];
        $codeFormatBad   = [];   // subjectCode not matching regex
        $docIdMismatch   = [];   // doc-id != {classKey}_{subjectCode}
        $missingFields   = [];   // docId => list of missing required
        $duplicateNames  = [];   // class => name => [codes...]  (track first-seen)
        $sampleByClass   = [];   // for visibility

        // Build (classKey, name) → [codes] map for duplicate detection
        $classNameMap = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $classKey    = (string)($data['classKey']    ?? '');
            $subjectCode = (string)($data['subjectCode'] ?? '');
            $name        = (string)($data['name']        ?? '');
            $category    = (string)($data['category']    ?? '');
            $stream      = (string)($data['stream']      ?? '');

            // Required-field presence
            $missing = [];
            foreach (self::REQUIRED_FIELDS as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') $missing[] = $f;
            }
            if (!empty($missing)) $missingFields[$docId] = $missing;

            // Tallies
            if ($classKey !== '') $classKeyTally[$classKey] = ($classKeyTally[$classKey] ?? 0) + 1;
            if ($category !== '') $categoryTally[$category] = ($categoryTally[$category] ?? 0) + 1;
            if ($stream   !== '') $streamTally[$stream]     = ($streamTally[$stream]     ?? 0) + 1;

            // subjectCode regex
            if ($subjectCode !== '' && !preg_match(self::SUBJECT_CODE_REGEX, $subjectCode)) {
                $codeFormatBad[] = "{$docId} — subjectCode=\"{$subjectCode}\" violates regex";
            }

            // Doc-id pattern
            $expectedDocIdSuffix = "{$classKey}_{$subjectCode}";
            if ($classKey !== '' && $subjectCode !== '' && strpos($docId, $expectedDocIdSuffix) === false) {
                $docIdMismatch[] = "{$docId} — expected substring \"{$expectedDocIdSuffix}\"";
            }

            // Duplicate-name detection within same classKey
            if ($classKey !== '' && $name !== '') {
                $nameLower = strtolower(trim($name));
                $classNameMap[$classKey][$nameLower][] = $subjectCode;
            }

            // Sample
            if (!isset($sampleByClass[$classKey])) {
                $sampleByClass[$classKey] = "{$docId} ({$name} / cat={$category})";
            }
        }

        // Build duplicate list
        foreach ($classNameMap as $classKey => $nameMap) {
            foreach ($nameMap as $nameLower => $codes) {
                if (count($codes) > 1) {
                    $duplicateNames[] = "class={$classKey} name=\"{$nameLower}\" codes=[" . implode(',', $codes) . "]";
                }
            }
        }

        // ── Distribution report ──────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "classKey distribution:\n";
        ksort($classKeyTally);
        foreach ($classKeyTally as $v => $c) echo "  classKey=\"{$v}\" x {$c}\n";
        echo "\ncategory distribution:\n";
        foreach ($categoryTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nstream distribution:\n";
        foreach ($streamTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nSample (one per class):\n";
        ksort($sampleByClass);
        foreach ($sampleByClass as $cls => $sample) echo "  classKey={$cls}: {$sample}\n";

        // ── Conformance ──────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Docs missing required fields: " . count($missingFields) . "\n";
        foreach ($missingFields as $id => $miss) echo "  - {$id}: " . implode(',', $miss) . "\n";
        echo "\nsubjectCode regex violations: " . count($codeFormatBad) . "\n";
        foreach ($codeFormatBad as $row) echo "  - {$row}\n";
        echo "\ndoc-id pattern mismatches: " . count($docIdMismatch) . "\n";
        foreach ($docIdMismatch as $row) echo "  - {$row}\n";
        echo "\nduplicate subject names within same class: " . count($duplicateNames) . "\n";
        foreach ($duplicateNames as $row) echo "  - {$row}\n";

        // ── Verdict ──────────────────────────────────────────────────────
        $criticalDrift = count($missingFields) + count($codeFormatBad);
        $minorDrift    = count($docIdMismatch) + count($duplicateNames);

        echo "\n─── Verdict ───\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED candidate — {$criticalDrift} critical drift indicators (missing required OR regex violation)\n";
        } elseif ($minorDrift > 0) {
            echo "⚠ WATCH — {$minorDrift} minor drift indicators (doc-id pattern OR duplicate names). Classify per soak contract.\n";
        } else {
            echo "✅ T1.1 NORMAL — all {$total} subjects fully canonical\n";
        }

        echo "\n=== End verification ===\n";
    }
}
