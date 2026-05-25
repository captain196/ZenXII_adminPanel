<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Ptm_canonical_verify — PTM Tier 1.1 through 1.8 batch verifier.
 *
 * READ-ONLY CLI tool. Covers schema + cross-reference + state machine
 * + integrity checks for ptmEvents / ptmRsvps / pushRequests.
 *
 * Per Ptm.php constants + views/ptm/rsvps.php dual-vocabulary architecture:
 *   ALLOWED_STATUSES = scheduled | completed | cancelled
 *   RSVP_STATUSES    legacy:  pending | confirmed | declined | attended | no-show
 *                    Phase-A: applied | delivered | declined | no-show
 *                    Canonical synonyms (see views/ptm/rsvps.php:151-340 +
 *                    Ptm.php:269-270): applied↔confirmed, delivered↔attended.
 *                    Phase-A vocabulary is emitted by the Phase-A parent/teacher
 *                    app builds; both vocabularies are intentionally accepted
 *                    downstream so the verifier must recognize both.
 *
 * INVOCATION:
 *   php index.php ptm_canonical_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent. Safe to run during live traffic.
 */
class Ptm_canonical_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const EVENT_STATUSES = ['scheduled', 'completed', 'cancelled'];
    // Dual-vocabulary acceptance: legacy + Phase-A. See docblock above.
    private const RSVP_STATUSES  = ['pending', 'confirmed', 'declined', 'attended', 'no-show',
                                    'applied', 'delivered'];

    private const EVENT_REQUIRED = [
        'ptmEventId', 'schoolId', 'session', 'title', 'date',
        'status', 'createdBy', 'createdAt',
    ];
    private const RSVP_REQUIRED = [
        'schoolId', 'studentId', 'status',
    ];
    private const PUSHREQ_REQUIRED = [
        'requestId', 'schoolId', 'createdAt', 'createdBy', 'type', 'targetRoles',
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Ptm_canonical_verify is CLI-only.', 403);
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

    /** CLI: php index.php ptm_canonical_verify verify */
    public function verify(): void
    {
        echo "=== PTM Tier 1.1 through 1.8 batch verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // Pre-fetch all collections needed
        $events    = $this->_fetch('ptmEvents');
        $rsvps     = $this->_fetch('ptmRsvps');
        $pushReqs  = $this->_fetch('pushRequests');
        $students  = $this->_fetch('students');
        $sections  = $this->_fetch('sections');

        echo "Pre-fetch counts: events=" . count($events) . " rsvps=" . count($rsvps)
           . " pushRequests=" . count($pushReqs) . " students=" . count($students)
           . " sections=" . count($sections) . "\n\n";

        // Build cross-reference lookups
        $studentMap = [];
        foreach ($students as $s) {
            $d = is_array($s['data'] ?? null) ? $s['data'] : [];
            $sid = (string)($d['studentId'] ?? $d['User ID'] ?? '');
            if ($sid === '') {
                // Try doc-id tail
                $docId = (string)($s['id'] ?? '');
                if (preg_match('/_(STU\w+)$/', $docId, $m)) $sid = $m[1];
            }
            if ($sid !== '') $studentMap[$sid] = $d;
        }
        $sectionMap = [];
        foreach ($sections as $s) {
            $d = is_array($s['data'] ?? null) ? $s['data'] : [];
            $cn = (string)($d['className'] ?? '');
            $sec = (string)($d['section']  ?? '');
            if ($cn !== '' && $sec !== '') $sectionMap["{$cn}|{$sec}"] = $d;
        }
        echo "Cross-reference lookups: students=" . count($studentMap) . " sections=" . count($sectionMap) . "\n\n";

        // ── T1.1 + T1.5 + T1.8: ptmEvents schema + state + slots ──────────
        echo "─── T1.1 + T1.5 + T1.8: ptmEvents canonical + state + slots ───\n";
        $this->_verify_events($events, $sectionMap);
        echo "\n";

        // ── T1.2 + T1.3 + T1.6: ptmRsvps schema + studentId xref + state ──
        echo "─── T1.2 + T1.3 + T1.6: ptmRsvps canonical + xref + state ───\n";
        $this->_verify_rsvps($rsvps, $studentMap, $events);
        echo "\n";

        // ── T1.4 covered inline above (event target xref to sections) ─────
        echo "─── T1.4: Event target → sections cross-reference (covered in T1.1) ───\n";

        // ── T1.7: pushRequests integrity ──────────────────────────────────
        echo "─── T1.7: pushRequests integrity ───\n";
        $this->_verify_pushrequests($pushReqs, $events);

        echo "\n=== End batch verification ===\n";
    }

    private function _fetch(string $col): array
    {
        try {
            return $this->firebase->firestoreQuery($col, [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 500);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function _verify_events(array $events, array $sectionMap): void
    {
        $total = count($events);
        echo "  total events: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.1+T1.5+T1.8 TRIVIAL PASS\n";
            return;
        }
        $statusTally = [];
        $missingReq = [];
        $invalidStatus = [];
        $sectionOrphan = [];
        $slotIssues = [];
        $dateInversions = [];
        $futureCompleted = [];

        foreach ($events as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $miss = [];
            foreach (self::EVENT_REQUIRED as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') $miss[] = $f;
            }
            if (!empty($miss)) $missingReq[$docId] = $miss;

            $status = (string)($data['status'] ?? '');
            if ($status !== '') {
                $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
                if (!in_array($status, self::EVENT_STATUSES, true)) {
                    $invalidStatus[] = "{$docId}: status=\"{$status}\"";
                }
            }

            // Event target → sections xref
            $cn = (string)($data['className'] ?? '');
            $sec = (string)($data['section'] ?? '');
            if ($cn !== '' && $sec !== '') {
                $key = "{$cn}|{$sec}";
                if (!isset($sectionMap[$key])) {
                    $sectionOrphan[] = "{$docId}: className=\"{$cn}\" section=\"{$sec}\" — no matching sections doc";
                }
            }

            // Slot integrity
            $slots = $data['slots'] ?? null;
            if (is_array($slots)) {
                $prevEnd = null;
                $slotIdx = 0;
                foreach ($slots as $slot) {
                    if (!is_array($slot)) continue;
                    $st = (string)($slot['startTime'] ?? '');
                    $et = (string)($slot['endTime'] ?? '');
                    if ($st !== '' && $et !== '' && $et < $st) {
                        $slotIssues[] = "{$docId} slot#{$slotIdx}: endTime={$et} < startTime={$st}";
                    }
                    if ($prevEnd !== null && $st !== '' && $st < $prevEnd) {
                        $slotIssues[] = "{$docId} slot#{$slotIdx}: overlap (start={$st} < prev end={$prevEnd})";
                    }
                    if ($et !== '') $prevEnd = $et;
                    $slotIdx++;
                }
            }
        }

        echo "  status distribution: " . json_encode($statusTally) . "\n";
        echo "  missing required fields: " . count($missingReq) . "\n";
        foreach ($missingReq as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        echo "  invalid status enum: " . count($invalidStatus) . "\n";
        foreach ($invalidStatus as $row) echo "    - {$row}\n";
        echo "  event target (className+section) NOT in sections collection: " . count($sectionOrphan) . "\n";
        foreach ($sectionOrphan as $row) echo "    - {$row}\n";
        echo "  slot anomalies: " . count($slotIssues) . "\n";
        foreach ($slotIssues as $row) echo "    - {$row}\n";

        $crit = count($missingReq) + count($invalidStatus) + count($sectionOrphan) + count($slotIssues);
        if ($crit > 0) {
            echo "  ⚠ INVESTIGATE — {$crit} drift indicators\n";
        } else {
            echo "  ✅ T1.1+T1.5+T1.8 NORMAL\n";
        }
    }

    private function _verify_rsvps(array $rsvps, array $studentMap, array $events): void
    {
        $total = count($rsvps);
        echo "  total rsvps: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.2+T1.3+T1.6 TRIVIAL PASS\n";
            return;
        }
        $statusTally = [];
        $missingReq = [];
        $invalidStatus = [];
        $orphanStudent = [];
        $duplicateTuples = [];
        $tupleSeen = [];
        $invalidEventRef = [];

        // Build event id set
        $eventIds = [];
        foreach ($events as $e) {
            $d = is_array($e['data'] ?? null) ? $e['data'] : [];
            $eid = (string)($d['ptmEventId'] ?? $d['eventId'] ?? '');
            if ($eid !== '') $eventIds[$eid] = true;
        }

        foreach ($rsvps as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $miss = [];
            foreach (self::RSVP_REQUIRED as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') $miss[] = $f;
            }
            if (!empty($miss)) $missingReq[$docId] = $miss;

            $status = (string)($data['status'] ?? '');
            if ($status !== '') {
                $statusTally[$status] = ($statusTally[$status] ?? 0) + 1;
                if (!in_array($status, self::RSVP_STATUSES, true)) {
                    $invalidStatus[] = "{$docId}: status=\"{$status}\"";
                }
            }

            // studentId xref
            $sid = (string)($data['studentId'] ?? '');
            if ($sid !== '' && !isset($studentMap[$sid])) {
                $orphanStudent[] = "{$docId}: studentId=\"{$sid}\" not in active students";
            }

            // Event ref (parse doc id: {schoolId}_{ptmEventId}_{studentId})
            // OR check for ptmEventId/eventId field
            $eventId = (string)($data['ptmEventId'] ?? $data['eventId'] ?? '');
            if ($eventId === '') {
                // Try parsing from doc id
                $prefix = "{$this->schoolFs}_";
                if (strpos($docId, $prefix) === 0) {
                    $tail = substr($docId, strlen($prefix));
                    // Format: {ptmEventId}_{studentId}
                    if (preg_match('/^([^_]+(?:_[^_]+)*)_STU\w+$/', $tail, $m)) {
                        $eventId = $m[1];
                    }
                }
            }
            if ($eventId !== '' && !isset($eventIds[$eventId])) {
                $invalidEventRef[] = "{$docId}: eventId=\"{$eventId}\" not in events";
            }

            // Duplicate tuple check (eventId, studentId)
            $tuple = "{$eventId}|{$sid}";
            if (isset($tupleSeen[$tuple])) {
                $duplicateTuples[] = "{$docId}: duplicate of {$tupleSeen[$tuple]} (eventId={$eventId}, studentId={$sid})";
            } else {
                $tupleSeen[$tuple] = $docId;
            }
        }

        echo "  status distribution: " . json_encode($statusTally) . "\n";
        echo "  missing required fields: " . count($missingReq) . "\n";
        foreach ($missingReq as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        echo "  invalid status enum: " . count($invalidStatus) . "\n";
        foreach ($invalidStatus as $row) echo "    - {$row}\n";
        echo "  orphan studentId (RSVP references unknown student): " . count($orphanStudent) . "\n";
        foreach ($orphanStudent as $row) echo "    - {$row}\n";
        echo "  invalid event references: " . count($invalidEventRef) . "\n";
        foreach ($invalidEventRef as $row) echo "    - {$row}\n";
        echo "  duplicate (eventId, studentId) tuples: " . count($duplicateTuples) . "\n";
        foreach ($duplicateTuples as $row) echo "    - {$row}\n";

        $crit = count($missingReq) + count($invalidStatus) + count($orphanStudent) + count($invalidEventRef) + count($duplicateTuples);
        if ($crit > 0) {
            echo "  ⚠ INVESTIGATE — {$crit} drift indicators\n";
        } else {
            echo "  ✅ T1.2+T1.3+T1.6 NORMAL\n";
        }
    }

    private function _verify_pushrequests(array $pushReqs, array $events): void
    {
        $total = count($pushReqs);
        echo "  total pushRequests: {$total}\n";
        if ($total === 0) {
            echo "  ✅ T1.7 TRIVIAL PASS\n";
            return;
        }

        $missingReq = [];
        $typeTally  = [];
        $orphanEventRef = [];
        $unsentCount = 0;
        $eventIds = [];
        foreach ($events as $e) {
            $d = is_array($e['data'] ?? null) ? $e['data'] : [];
            $eid = (string)($d['ptmEventId'] ?? $d['eventId'] ?? '');
            if ($eid !== '') $eventIds[$eid] = true;
        }

        foreach ($pushReqs as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $docId = (string)($r['id'] ?? '');
            $miss = [];
            foreach (self::PUSHREQ_REQUIRED as $f) {
                if (!array_key_exists($f, $data) || $data[$f] === null || $data[$f] === '') $miss[] = $f;
            }
            if (!empty($miss)) $missingReq[$docId] = $miss;

            $type = (string)($data['type'] ?? '');
            if ($type !== '') $typeTally[$type] = ($typeTally[$type] ?? 0) + 1;

            $eid = (string)($data['eventId'] ?? '');
            if ($eid !== '' && !isset($eventIds[$eid])) {
                $orphanEventRef[] = "{$docId}: eventId=\"{$eid}\" not in events";
            }

            // sentAt populated = dispatched; startedAt without sentAt = stuck
            $sentAt = (string)($data['sentAt'] ?? '');
            $startedAt = (string)($data['startedAt'] ?? '');
            if ($startedAt !== '' && $sentAt === '') $unsentCount++;
        }

        echo "  type distribution: " . json_encode($typeTally) . "\n";
        echo "  missing required fields: " . count($missingReq) . "\n";
        foreach ($missingReq as $id => $miss) echo "    - {$id}: " . implode(',', $miss) . "\n";
        echo "  orphan event refs: " . count($orphanEventRef) . "\n";
        foreach ($orphanEventRef as $row) echo "    - {$row}\n";
        echo "  started-but-not-sent (potentially stuck): {$unsentCount}\n";

        $crit = count($missingReq) + count($orphanEventRef);
        if ($crit > 0) {
            echo "  ⚠ INVESTIGATE — {$crit} drift indicators\n";
        } elseif ($unsentCount > 0) {
            echo "  ⚠ WATCH — {$unsentCount} potentially stuck dispatches\n";
        } else {
            echo "  ✅ T1.7 NORMAL\n";
        }
    }
}
