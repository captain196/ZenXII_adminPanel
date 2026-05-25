<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Calendar_events_verify — Academic Planner Tier 1.2 calendarEvents canonical
 * verification.
 *
 * READ-ONLY CLI tool. Verifies the canonical schema per Calendar_service.php:127-148:
 *
 *   Collection: calendarEvents
 *   Doc-id:     {schoolId}_{eventId}  (eventId format: "EVT_<uniqid>")
 *
 *   Required canonical fields:
 *     id, schoolId, session, title, type, startDate, endDate, description,
 *     visibleTo (array), updatedAt, updatedByUid, version
 *
 *   Type enum (Calendar_service::VALID_TYPES):
 *     holiday | exam | meeting | event | activity
 *
 *   visibleTo allowed audiences (Calendar_service::ALLOWED_AUDIENCES):
 *     admin | teacher | parent
 *
 *   Date constraints:
 *     startDate / endDate match YYYY-MM-DD
 *     endDate >= startDate
 *
 *   Dual-shape compat (Phase 0): start_date / end_date snake_case fallback may exist
 *
 * INVOCATION:
 *   php index.php calendar_events_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Calendar_events_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const VALID_TYPES = ['holiday', 'exam', 'meeting', 'event', 'activity'];
    private const ALLOWED_AUDIENCES = ['admin', 'teacher', 'parent'];
    private const REQUIRED_FIELDS = [
        'id', 'schoolId', 'session', 'title', 'type',
        'startDate', 'endDate', 'updatedAt', 'updatedByUid', 'version',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Calendar_events_verify is CLI-only.', 403);
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

    /** CLI: php index.php calendar_events_verify verify */
    public function verify(): void
    {
        echo "=== Academic Planner Tier 1.2 calendarEvents canonical verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        try {
            $rows = $this->firebase->firestoreQuery('calendarEvents', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            echo "ERROR loading calendarEvents: " . $e->getMessage() . "\n";
            return;
        }

        $total = count($rows);
        echo "Total calendarEvents docs: {$total}\n\n";
        if ($total === 0) {
            echo "=== T1.2 TRIVIAL PASS — no calendar events in scope ===\n";
            return;
        }

        // Distribution + drift tracking
        $typeTally       = [];
        $audienceTally   = [];   // individual audience tokens
        $sessionTally    = [];
        $versionDist     = [];
        $startMonthTally = [];

        $missingFields    = [];
        $docIdMismatch    = [];
        $badType          = [];
        $badStartDate     = [];
        $badEndDate       = [];
        $endBeforeStart   = [];
        $badVisibleTo     = [];   // visibleTo not array OR contains invalid token
        $snakeCaseDrift   = [];   // doc has snake_case start_date/end_date without camelCase counterpart

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
            if (!empty($missing)) $missingFields[$docId] = $missing;

            // Doc-id pattern: {schoolId}_{eventId}
            $expectedPrefix = "{$this->schoolFs}_";
            if (strpos($docId, $expectedPrefix) !== 0) {
                $docIdMismatch[] = "{$docId} — expected prefix \"{$expectedPrefix}\"";
            }

            // Type enum check
            $type = (string)($data['type'] ?? '');
            if ($type !== '') {
                $typeTally[$type] = ($typeTally[$type] ?? 0) + 1;
                if (!in_array($type, self::VALID_TYPES, true)) {
                    $badType[] = "{$docId} — type=\"{$type}\" (valid: " . implode('|', self::VALID_TYPES) . ")";
                }
            }

            // Date format + ordering
            $sd = (string)($data['startDate'] ?? $data['start_date'] ?? '');
            $ed = (string)($data['endDate']   ?? $data['end_date']   ?? '');
            if ($sd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) {
                $badStartDate[] = "{$docId}: startDate=\"{$sd}\"";
            }
            if ($ed !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
                $badEndDate[] = "{$docId}: endDate=\"{$ed}\"";
            }
            if ($sd !== '' && $ed !== '' && $ed < $sd) {
                $endBeforeStart[] = "{$docId}: startDate={$sd} > endDate={$ed}";
            }
            if ($sd !== '') {
                $month = substr($sd, 0, 7);
                $startMonthTally[$month] = ($startMonthTally[$month] ?? 0) + 1;
            }

            // Snake_case-only dual-shape drift
            $hasCamel = array_key_exists('startDate', $data);
            $hasSnake = array_key_exists('start_date', $data);
            if (!$hasCamel && $hasSnake) {
                $snakeCaseDrift[] = "{$docId} — snake_case-only (no startDate camelCase)";
            }

            // visibleTo validation
            $vto = $data['visibleTo'] ?? null;
            if ($vto !== null) {
                if (!is_array($vto)) {
                    $badVisibleTo[] = "{$docId}: visibleTo not array — type=" . gettype($vto);
                } else {
                    foreach ($vto as $token) {
                        $tokenStr = (string)$token;
                        if ($tokenStr !== '' && !in_array(strtolower($tokenStr), self::ALLOWED_AUDIENCES, true)) {
                            $badVisibleTo[] = "{$docId}: visibleTo contains invalid token \"{$tokenStr}\"";
                        }
                        $audienceTally[$tokenStr] = ($audienceTally[$tokenStr] ?? 0) + 1;
                    }
                }
            }

            // Tallies
            $sess = (string)($data['session'] ?? '');
            if ($sess !== '') $sessionTally[$sess] = ($sessionTally[$sess] ?? 0) + 1;
            $ver = (int)($data['version'] ?? 0);
            $versionDist[$ver] = ($versionDist[$ver] ?? 0) + 1;
        }

        // ── Distribution report ─────────────────────────────────────────
        echo "─── Distribution ───\n";
        echo "Sessions:\n";
        foreach ($sessionTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nEvent type:\n";
        ksort($typeTally);
        foreach ($typeTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nVisibility audience (tokens):\n";
        ksort($audienceTally);
        foreach ($audienceTally as $v => $c) echo "  \"{$v}\" x {$c}\n";
        echo "\nStart month distribution:\n";
        ksort($startMonthTally);
        foreach ($startMonthTally as $v => $c) echo "  {$v} x {$c}\n";
        echo "\nVersion distribution:\n";
        ksort($versionDist);
        foreach ($versionDist as $v => $c) echo "  v{$v} x {$c}\n";

        // ── Conformance ─────────────────────────────────────────────────
        echo "\n─── Conformance ───\n";
        echo "Docs missing required fields: " . count($missingFields) . "\n";
        foreach ($missingFields as $id => $miss) echo "  - {$id}: " . implode(',', $miss) . "\n";
        echo "\nDoc-id prefix mismatches: " . count($docIdMismatch) . "\n";
        foreach ($docIdMismatch as $row) echo "  - {$row}\n";
        echo "\nInvalid type values: " . count($badType) . "\n";
        foreach ($badType as $row) echo "  - {$row}\n";
        echo "\nMalformed startDate: " . count($badStartDate) . "\n";
        foreach ($badStartDate as $row) echo "  - {$row}\n";
        echo "\nMalformed endDate: " . count($badEndDate) . "\n";
        foreach ($badEndDate as $row) echo "  - {$row}\n";
        echo "\nendDate < startDate: " . count($endBeforeStart) . "\n";
        foreach ($endBeforeStart as $row) echo "  - {$row}\n";
        echo "\nvisibleTo violations: " . count($badVisibleTo) . "\n";
        foreach ($badVisibleTo as $row) echo "  - {$row}\n";
        echo "\nSnake_case-only dual-shape drift: " . count($snakeCaseDrift) . "\n";
        foreach ($snakeCaseDrift as $row) echo "  - {$row}\n";

        // ── Verdict ─────────────────────────────────────────────────────
        $criticalDrift = count($missingFields) + count($badType) + count($badStartDate)
                       + count($badEndDate) + count($endBeforeStart) + count($badVisibleTo);
        $minorDrift = count($docIdMismatch) + count($snakeCaseDrift);

        echo "\n─── Verdict ───\n";
        if ($criticalDrift > 0) {
            echo "🛑 FREEZE_REQUIRED candidate — {$criticalDrift} critical drift indicators\n";
        } elseif ($minorDrift > 0) {
            echo "⚠ WATCH — {$minorDrift} minor drift indicators (doc-id prefix OR snake_case-only)\n";
        } else {
            echo "✅ T1.2 NORMAL — all {$total} calendarEvents fully canonical\n";
        }

        echo "\n=== End verification ===\n";
    }
}
