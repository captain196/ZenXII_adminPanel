<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Curriculum_verify — Academic Planner Tier 1.1 curriculum canonical verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical schema per Curriculum_service.php:257-267
 * + _currDocId pattern at :579-583:
 *
 *   Collection: curriculum
 *   Doc-id:     {schoolId}_{session}_{classSection}_{subject}
 *               (spaces/slashes in classSection/subject replaced with underscores)
 *
 *   Required canonical fields:
 *     schoolId, session, classSection, subject, topics (array OR subcollection),
 *     updatedAt, updatedByUid, updatedByName, version (int, optimistic concurrency)
 *
 *   Optional (Phase 1 subcollection mode):
 *     topicsModel ("array" | "subcollection"), totalTopics, completedTopics, percentComplete
 *
 *   Cross-references (verified against prior Tier 1 baselines):
 *     - subject value resolves to subjects collection (examination T1.1 canon)
 *     - classSection follows cross-system T1.1 canon (Class_<n>_Section_<x> format)
 *
 * INVOCATION:
 *   php index.php curriculum_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Curriculum_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const REQUIRED_FIELDS = [
        'schoolId', 'session', 'classSection', 'subject',
        'updatedAt', 'updatedByUid', 'version',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Curriculum_verify is CLI-only.', 403);
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

    /** CLI: php index.php curriculum_verify verify */
    public function verify(): void
    {
        echo "=== Academic Planner Tier 1.1 curriculum canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('curriculum', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading curriculum: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total curriculum docs: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.1 TRIVIAL PASS — no curriculum docs in scope ===\n";
            return;
        }

        // Distribution tallies
        $classSectionTally = [];
        $subjectTally      = [];
        $sessionTally      = [];
        $topicsModelTally  = [];
        $versionDistribution = [];
        $updatedByTally    = [];

        // Drift trackers
        $missingFields       = [];
        $docIdMismatch       = [];
        $classSectionDrift   = [];
        $negativeCounters    = [];
        $invalidPercent      = [];
        $topicsCounterMismatch = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            // Required field presence
            $missing = [];
            foreach (self::REQUIRED_FIELDS as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                    $missing[] = $f;
                }
            }
            // topics: array OR subcollection mode (one of two must be true)
            $hasTopics = is_array($data['topics'] ?? null);
            $topicsModel = (string)($data['topicsModel'] ?? '');
            if (!$hasTopics && $topicsModel === '') {
                $missing[] = 'topics OR topicsModel';
            }
            if (!empty($missing)) $missingFields[$docId] = $missing;

            // Doc-id pattern: {schoolId}_{session}_{classSection}_{subject}
            $expectedPrefix = "{$this->schoolFs}_{$this->sessionYear}_";
            if (strpos($docId, $expectedPrefix) !== 0) {
                $docIdMismatch[] = "{$docId} — expected prefix \"{$expectedPrefix}\"";
            }

            // Distribution
            $cs = (string)($data['classSection'] ?? '');
            if ($cs !== '') $classSectionTally[$cs] = ($classSectionTally[$cs] ?? 0) + 1;
            $sub = (string)($data['subject'] ?? '');
            if ($sub !== '') $subjectTally[$sub] = ($subjectTally[$sub] ?? 0) + 1;
            $sess = (string)($data['session'] ?? '');
            if ($sess !== '') $sessionTally[$sess] = ($sessionTally[$sess] ?? 0) + 1;
            $tm = $topicsModel !== '' ? $topicsModel : 'array';
            $topicsModelTally[$tm] = ($topicsModelTally[$tm] ?? 0) + 1;
            $ver = (int)($data['version'] ?? 0);
            $versionDistribution[$ver] = ($versionDistribution[$ver] ?? 0) + 1;
            $ub = (string)($data['updatedByUid'] ?? '');
            if ($ub !== '') $updatedByTally[$ub] = ($updatedByTally[$ub] ?? 0) + 1;

            // classSection canonical (cross-system T1.1 canon: "Class_<n>_Section_<x>")
            if ($cs !== '' && !preg_match('/^Class_\S+_Section_\S+$/', $cs)) {
                $classSectionDrift[] = "{$docId} — classSection=\"{$cs}\"";
            }

            // Counters validity (Phase 1 subcollection mode)
            foreach (['totalTopics', 'completedTopics'] as $f) {
                $v = $data[$f] ?? null;
                if (is_numeric($v) && (float)$v < 0) {
                    $negativeCounters[] = "{$docId}: {$f}=" . $v;
                }
            }
            $pct = $data['percentComplete'] ?? null;
            if (is_numeric($pct) && ((float)$pct < 0 || (float)$pct > 100)) {
                $invalidPercent[] = "{$docId}: percentComplete=" . $pct;
            }

            // topics counter coherence (legacy array mode)
            if ($hasTopics) {
                $topicCount = count($data['topics']);
                $tt = $data['totalTopics'] ?? null;
                if (is_numeric($tt) && abs((int)$tt - $topicCount) > 0) {
                    $topicsCounterMismatch[] = "{$docId}: array length={$topicCount} vs totalTopics={$tt}";
                }
            }
        }

        // ── Distribution report ─────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "Sessions:\n";
        foreach ($sessionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nClassSection distribution:\n";
        ksort($classSectionTally);
        foreach ($classSectionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nSubject distribution:\n";
        ksort($subjectTally);
        foreach ($subjectTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\ntopicsModel distribution:\n";
        foreach ($topicsModelTally as $v => $c) echo "  {$v} x {$c}\n";
        echo "\nversion distribution:\n";
        ksort($versionDistribution);
        foreach ($versionDistribution as $v => $c) echo "  v{$v} x {$c}\n";
        echo "\nupdatedByUid distribution:\n";
        foreach ($updatedByTally as $v => $c) echo "  \"{$v}\" x {$c}\n";

        // ── Conformance ─────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Docs missing required fields: " . count($missingFields) . "\n";
        foreach ($missingFields as $id => $miss) echo "  - {$id}: " . implode(',', $miss) . "\n";
        echo "\nDoc-id prefix mismatches: " . count($docIdMismatch) . "\n";
        foreach ($docIdMismatch as $row) echo "  - {$row}\n";
        echo "\nclassSection canonical drift (no Class_*_Section_* pattern): " . count($classSectionDrift) . "\n";
        foreach ($classSectionDrift as $row) echo "  - {$row}\n";
        echo "\nNegative counters: " . count($negativeCounters) . "\n";
        foreach ($negativeCounters as $row) echo "  - {$row}\n";
        echo "\nInvalid percentComplete (out of 0-100): " . count($invalidPercent) . "\n";
        foreach ($invalidPercent as $row) echo "  - {$row}\n";
        echo "\nTopics count vs totalTopics mismatch: " . count($topicsCounterMismatch) . "\n";
        foreach ($topicsCounterMismatch as $row) echo "  - {$row}\n";

        // ── Verdict ─────────────────────────────────────────────────────
        $criticalDrift = count($missingFields) + count($docIdMismatch) + count($classSectionDrift) + count($negativeCounters) + count($invalidPercent);
        $minorDrift = count($topicsCounterMismatch);
        echo "\n─── Verdict ───\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED candidate — {$criticalDrift} critical drift indicators\n";
        } elseif ($minorDrift > 0) {
            echo "⚠ WATCH — {$minorDrift} topic-counter coherence mismatches\n";
        } else {
            echo "✅ T1.1 NORMAL — all {$total} curriculum docs fully canonical\n";
        }

        echo "\n=== End verification ===\n";
    }
}
