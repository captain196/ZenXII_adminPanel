<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sections_verify — Academic Planner Tier 1.8 sections canonical verification.
 *
 * READ-ONLY CLI tool. Discovery-style: surveys actual stored field schemas
 * because code review revealed multiple writer paths with DIFFERENT conventions:
 *
 *   Writer A (Classes.php:195/414):
 *     doc-id: {schoolId}_{classKey}_{section}     (e.g. SCH_D94FE8F7AD_8_A)
 *     fields: schoolId, className (=classKey "8"), section (="A"),
 *             maxStrength, session, updatedAt
 *
 *   Writer B (Subject_assignment_service.php:296):
 *     doc-id: {schoolId}_{session}_{canonicalClass}_{canonicalSection}
 *             (e.g. SCH_D94FE8F7AD_2026-27_Class_8th_Section_A)
 *     fields: classTeacherId, className (canonical "Class 8th"),
 *             section (canonical "Section A"), classOrder, sectionCode,
 *             sectionKey, updatedAt
 *
 *   (Plus writers at Sis.php:2344 and School_config.php:3232 — TBD)
 *
 * This verifier surfaces actual stored state to identify which writers
 * are dominant + whether canonical alignment exists in any form.
 *
 * INVOCATION:
 *   php index.php sections_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Sections_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Sections_verify is CLI-only.', 403);
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

    /** CLI: php index.php sections_verify verify */
    public function verify(): void
    {
        echo "=== Academic Planner Tier 1.8 sections canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('sections', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading sections: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total sections docs: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.8 TRIVIAL PASS — no sections in scope ===\n";
            return;
        }

        // Discovery: classify each doc by writer signature
        $writerASig = ['schoolId', 'className', 'section', 'maxStrength', 'session', 'updatedAt'];
        $writerBSig = ['schoolId', 'className', 'section', 'classOrder', 'sectionCode', 'sectionKey', 'classTeacherId', 'updatedAt'];

        $classNameValues  = [];
        $sectionValues    = [];
        $docIdPatterns    = [];
        $fieldPresence    = [];   // field => count of docs containing it
        $perDocFields     = [];   // docId => sorted field list
        $missingClassOrder = [];  // docs claiming canonical but missing classOrder
        $writerAishCount  = 0;
        $writerBishCount  = 0;
        $unknownCount     = 0;

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            // Strip Firestore internal fields
            unset($data['__updateTime']);
            $fields = array_keys($data);
            sort($fields);
            $perDocFields[$docId] = $fields;

            // Field presence tally
            foreach ($fields as $f) $fieldPresence[$f] = ($fieldPresence[$f] ?? 0) + 1;

            // Value tallies
            $cn = (string)($data['className'] ?? '');
            if ($cn !== '') $classNameValues[$cn] = ($classNameValues[$cn] ?? 0) + 1;
            $sec = (string)($data['section'] ?? '');
            if ($sec !== '') $sectionValues[$sec] = ($sectionValues[$sec] ?? 0) + 1;

            // Doc-id pattern classification
            $prefix = "{$this->schoolFs}_";
            $afterPrefix = substr($docId, strlen($prefix));
            if (preg_match('/^\w+_\w+$/', $afterPrefix) && !str_contains($afterPrefix, '_Class_')) {
                $docIdPatterns['short_classKey_section'] = ($docIdPatterns['short_classKey_section'] ?? 0) + 1;
            } elseif (str_contains($afterPrefix, '_Class_') && str_contains($afterPrefix, '_Section_')) {
                $docIdPatterns['long_canonical'] = ($docIdPatterns['long_canonical'] ?? 0) + 1;
            } else {
                $docIdPatterns['other'] = ($docIdPatterns['other'] ?? 0) + 1;
            }

            // Writer signature classification
            $hasClassOrder = array_key_exists('classOrder', $data);
            $hasSectionCode = array_key_exists('sectionCode', $data);
            $hasMaxStrength = array_key_exists('maxStrength', $data);

            if ($hasClassOrder && $hasSectionCode) {
                $writerBishCount++;
            } elseif ($hasMaxStrength && !$hasClassOrder) {
                $writerAishCount++;
            } else {
                $unknownCount++;
            }

            // Drift detection: claims canonical className "Class N" but missing classOrder/sectionCode
            if (preg_match('/^Class\s+\d/', $cn) && (!$hasClassOrder || !$hasSectionCode)) {
                $missingClassOrder[] = "{$docId}: className=\"{$cn}\" but missing classOrder/sectionCode";
            }
        }

        // ── Report ────────────────────────────────────────────────────────
        echo "─── Doc-id pattern distribution ───\n";
        foreach ($docIdPatterns as $p => $c) printf("  %-30s : %d\n", $p, $c);

        echo "\n─── Writer-signature classification ───\n";
        printf("  Writer A-ish (classKey + maxStrength, no canonical breakdown):  %d\n", $writerAishCount);
        printf("  Writer B-ish (canonical className/section + classOrder + sectionCode): %d\n", $writerBishCount);
        printf("  Unknown / mixed signature: %d\n", $unknownCount);

        echo "\n─── className value distribution ───\n";
        ksort($classNameValues);
        foreach ($classNameValues as $v => $c) echo "  \"{$v}\" x {$c}\n";

        echo "\n─── section value distribution ───\n";
        ksort($sectionValues);
        foreach ($sectionValues as $v => $c) echo "  \"{$v}\" x {$c}\n";

        echo "\n─── Field-presence frequency ───\n";
        arsort($fieldPresence);
        foreach ($fieldPresence as $f => $c) printf("  %-20s : %d / %d\n", $f, $c, $total);

        echo "\n─── Per-doc field summary (first 10) ───\n";
        $shown = 0;
        foreach ($perDocFields as $docId => $fields) {
            if ($shown++ >= 10) break;
            echo "  {$docId}\n    fields: " . implode(', ', $fields) . "\n";
        }
        if (count($perDocFields) > 10) echo "  ... (" . (count($perDocFields) - 10) . " more)\n";

        echo "\n─── Canonical-claim drift (className looks canonical but missing classOrder/sectionCode) ───\n";
        echo "  count: " . count($missingClassOrder) . "\n";
        foreach ($missingClassOrder as $row) echo "  - {$row}\n";

        // ── Verdict ──────────────────────────────────────────────────────
        echo "\n─── Verdict ───\n";
        if ($total > 0 && $writerBishCount === $total) {
            echo "✅ T1.8 NORMAL — all {$total} sections written via canonical Writer B (full canonical breakdown).\n";
        } elseif ($writerAishCount > 0 && $writerBishCount > 0) {
            echo "⚠ WATCH — multi-writer asymmetry: " . $writerAishCount . " legacy + " . $writerBishCount . " canonical docs. Mixed canonical state.\n";
        } elseif ($writerAishCount === $total) {
            echo "⚠ WATCH — all {$total} docs use legacy Writer A signature (classKey + minimal fields, no canonical breakdown).\n";
        } else {
            echo "⚠ INVESTIGATE — {$unknownCount} unknown-signature docs need classification.\n";
        }

        echo "\n=== End verification ===\n";
    }
}
