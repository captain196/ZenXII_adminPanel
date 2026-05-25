<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Substitutes_verify — Academic Planner Tier 1.3 substitutes canonical verification.
 *
 * READ-ONLY CLI tool. Verifies canonical schema per Substitute_service.php:265-282:
 *
 *   Collection: substitutes
 *   Doc-id:     {schoolId}_{id}
 *
 *   Required canonical fields (modern writes):
 *     id, schoolId, session, status (assigned|completed|cancelled), date (YYYY-MM-DD),
 *     absentTeacherId, absentTeacherName, assignments (array),
 *     reason, createdAt, createdByUid, createdByName, version
 *
 *   Status enum: assigned | completed | cancelled
 *
 *   Phase-0 F-5 legacy compensation: read-side derives `id` from docKey when
 *   absent — Substitute_service.php:65-69. The verifier treats missing `id`
 *   on otherwise-canonical docs as LEGACY RESIDUE (not FREEZE).
 *
 * INVOCATION:
 *   php index.php substitutes_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Substitutes_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const VALID_STATUS = ['assigned', 'completed', 'cancelled'];

    // Modern writes contain all of these
    private const MODERN_REQUIRED = [
        'id', 'schoolId', 'session', 'status', 'date',
        'absentTeacherId', 'createdAt', 'createdByUid', 'version',
    ];

    // Phase-0 F-5 known legacy gaps that read-side compensates for
    private const LEGACY_F5_OPTIONAL = ['id'];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Substitutes_verify is CLI-only.', 403);
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

    /** CLI: php index.php substitutes_verify verify */
    public function verify(): void
    {
        echo "=== Academic Planner Tier 1.3 substitutes canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('substitutes', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading substitutes: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total substitutes docs: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.3 TRIVIAL PASS — no substitutes in scope ===\n";
            return;
        }

        $statusTally       = [];
        $monthTally        = [];
        $absentTeacherTally = [];
        $assignmentCountDist = [];
        $versionDist       = [];

        $missingCritical   = [];   // missing required fields OTHER than known-legacy-F5 fields
        $legacyF5Residue   = [];   // missing only `id` (Phase-0 F-5 pattern)
        $badStatus         = [];
        $badDate           = [];
        $emptyAssignments  = [];

        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');

            // Distinguish modern-required gaps from Phase-0 F-5 legacy gaps
            $missing = [];
            foreach (self::MODERN_REQUIRED as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') {
                    $missing[] = $f;
                }
            }
            $onlyLegacyF5 = !empty($missing) && empty(array_diff($missing, self::LEGACY_F5_OPTIONAL));
            if ($onlyLegacyF5) {
                $legacyF5Residue[] = "{$docId}: missing " . implode(',', $missing) . " (Phase-0 F-5 read-side compensated)";
            } elseif (!empty($missing)) {
                $missingCritical[$docId] = $missing;
            }

            // Status enum
            $status = (string)($data['status'] ?? '');
            if ($status !== '') {
                $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
                if (!in_array($status, self::VALID_STATUS, true)) {
                    $badStatus[] = "{$docId}: status=\"{$status}\"";
                }
            }

            // Date format
            $date = (string)($data['date'] ?? '');
            if ($date !== '') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $badDate[] = "{$docId}: date=\"{$date}\"";
                } else {
                    $month = substr($date, 0, 7);
                    $monthTally[$month] = ($monthTally[$month] ?? 0) + 1;
                }
            }

            // Assignment array
            $assn = $data['assignments'] ?? null;
            if (is_array($assn)) {
                $cnt = count($assn);
                $assignmentCountDist[$cnt] = ($assignmentCountDist[$cnt] ?? 0) + 1;
                if ($cnt === 0) {
                    $emptyAssignments[] = "{$docId}: empty assignments array";
                }
            }

            // Tallies
            $atid = (string)($data['absentTeacherId'] ?? '');
            if ($atid !== '') $absentTeacherTally[$atid] = ($absentTeacherTally[$atid] ?? 0) + 1;
            $ver = (int)($data['version'] ?? 0);
            $versionDist[$ver] = ($versionDist[$ver] ?? 0) + 1;
        }

        // ── Distribution report ─────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "Status:\n";
        ksort($statusTally);
        foreach ($statusTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nMonth (substitute date):\n";
        ksort($monthTally);
        foreach ($monthTally as $v => $c) echo "  {$v} x {$c}\n";
        echo "\nAbsentTeacherId distribution:\n";
        foreach ($absentTeacherTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nAssignments per substitute (array length):\n";
        ksort($assignmentCountDist);
        foreach ($assignmentCountDist as $v => $c) echo "  {$v} assignments x {$c}\n";
        echo "\nVersion:\n";
        ksort($versionDist);
        foreach ($versionDist as $v => $c) echo "  v{$v} x {$c}\n";

        // ── Conformance ─────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Critical missing required (NOT legacy F-5): " . count($missingCritical) . "\n";
        foreach ($missingCritical as $id => $miss) echo "  - {$id}: " . implode(',', $miss) . "\n";
        echo "\nLegacy Phase-0 F-5 residue (missing `id` only — read-side compensates): " . count($legacyF5Residue) . "\n";
        foreach ($legacyF5Residue as $row) echo "  - {$row}\n";
        echo "\nInvalid status values: " . count($badStatus) . "\n";
        foreach ($badStatus as $row) echo "  - {$row}\n";
        echo "\nMalformed dates: " . count($badDate) . "\n";
        foreach ($badDate as $row) echo "  - {$row}\n";
        echo "\nEmpty assignment arrays: " . count($emptyAssignments) . "\n";
        foreach ($emptyAssignments as $row) echo "  - {$row}\n";

        // ── Verdict ─────────────────────────────────────────────────────
        $criticalDrift = count($missingCritical) + count($badStatus) + count($badDate) + count($emptyAssignments);
        echo "\n─── Verdict ───\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED candidate — {$criticalDrift} non-legacy critical drift indicators\n";
        } elseif (count($legacyF5Residue) > 0) {
            echo "⚠ WATCH — " . count($legacyF5Residue) . " Phase-0 F-5 legacy residue docs (read-side compensates; modern writes canonical)\n";
        } else {
            echo "✅ T1.3 NORMAL — all {$total} substitutes fully canonical\n";
        }

        echo "\n=== End verification ===\n";
    }
}
