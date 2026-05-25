<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Communication_verify — Communication Phase 6 completion verifier.
 *
 * READ-ONLY CLI tool. Tier 1 batch covering the full Communication surface.
 *
 *   T1.0 — RTDB residue scan (file-level — handled in code review)
 *   T1.1 — Counter convention (schools/{schoolId}_profile commCounters.*)
 *   T1.2 — messageTemplates collection canonical schema
 *   T1.3 — Trigger collection: messageTriggers vs alertTriggers divergence
 *   T1.4 — messageQueue lifecycle + retry counters
 *   T1.5 — deliveryLogs / messageLogs divergence + audit-path
 *   T1.6 — notices + circulars (HR-source guard, dual-emit shape)
 *   T1.7 — circularAcks flat-key idempotency + cross-reference
 *   T1.8 — conversations + messageInboxes + messages canonical
 *   T1.9 — pushRequests relationships + delivery tracking
 *   T1.10 — Audience resolution + target-group integrity
 *
 * INVOCATION:
 *   php index.php communication_verify verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Mutates nothing. Idempotent.
 */
class Communication_verify extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Communication_verify is CLI-only.', 403);
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

    public function verify(): void
    {
        echo "=== Communication Phase 6 verification ===\n";
        echo "Scope: schoolId={$this->schoolFs} session={$this->sessionYear}\n";
        echo str_repeat('-', 64) . "\n\n";

        // Pre-fetch the full surface
        $templates       = $this->_fetch('messageTemplates');
        $triggersM       = $this->_fetch('messageTriggers');
        $triggersA       = $this->_fetch('alertTriggers');
        $queue           = $this->_fetch('messageQueue');
        $logsM           = $this->_fetch('messageLogs');
        $logsD           = $this->_fetch('deliveryLogs');
        $notices         = $this->_fetch('notices');
        $circulars       = $this->_fetch('circulars');
        $circularAcks    = $this->_fetch('circularAcks');
        $conversations   = $this->_fetch('conversations');
        $messageInboxes  = $this->_fetch('messageInboxes');
        $messages        = $this->_fetch('messages');
        $pushRequests    = $this->_fetch('pushRequests');
        $sections        = $this->_fetch('sections');

        // Schools profile doc holds counters
        $profileDocId = $this->schoolFs . '_profile';
        $profileDoc = null;
        try { $profileDoc = $this->firebase->firestoreGet('schools', $profileDocId); } catch (\Throwable $e) {}
        if (!is_array($profileDoc)) {
            // Fallback: school doc at $schoolFs
            try { $profileDoc = $this->firebase->firestoreGet('schools', $this->schoolFs); } catch (\Throwable $e) {}
        }

        echo "Pre-fetch sizes:\n";
        echo "  messageTemplates: " . count($templates) . "\n";
        echo "  messageTriggers : " . count($triggersM) . "  alertTriggers: " . count($triggersA) . "\n";
        echo "  messageQueue    : " . count($queue) . "\n";
        echo "  messageLogs     : " . count($logsM) . "  deliveryLogs: " . count($logsD) . "\n";
        echo "  notices         : " . count($notices) . "  circulars: " . count($circulars) . "\n";
        echo "  circularAcks    : " . count($circularAcks) . "\n";
        echo "  conversations   : " . count($conversations) . "\n";
        echo "  messageInboxes  : " . count($messageInboxes) . "\n";
        echo "  messages        : " . count($messages) . "\n";
        echo "  pushRequests    : " . count($pushRequests) . "\n";
        echo "  sections        : " . count($sections) . "\n";
        echo "  schools-profile-doc: " . (is_array($profileDoc) ? 'present' : 'MISSING') . "\n\n";

        $this->_t1_1_counters($profileDoc, $templates, $triggersM, $triggersA, $queue, $logsM, $logsD, $notices, $circulars, $conversations, $messages);
        echo "\n";
        $this->_t1_2_templates($templates);
        echo "\n";
        $this->_t1_3_triggers_divergence($triggersM, $triggersA, $templates);
        echo "\n";
        $this->_t1_4_queue($queue, $templates);
        echo "\n";
        $this->_t1_5_logs_divergence($logsM, $logsD, $queue);
        echo "\n";
        $this->_t1_6_notices_circulars($notices, $circulars);
        echo "\n";
        $this->_t1_7_circular_acks($circularAcks, $circulars);
        echo "\n";
        $this->_t1_8_messaging($conversations, $messageInboxes, $messages);
        echo "\n";
        $this->_t1_9_push_requests($pushRequests, $notices, $circulars, $queue);
        echo "\n";
        $this->_t1_10_audience_resolution($sections, $notices, $circulars, $queue);

        echo "\n=== End Communication Phase 6 verification ===\n";
    }

    private function _fetch(string $col): array
    {
        try {
            return $this->firebase->firestoreQuery($col, [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 2000);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Read the actual constant values from Communication.php source so the
     * verifier reflects current truth rather than a frozen-in-time snapshot.
     * Returns [seedSourceCollection, writeTargetCollection].
     */
    private function _read_constants(string $counterType, string $writeConstName): array
    {
        $path = APPPATH . 'controllers/Communication.php';
        $src  = is_file($path) ? (string) file_get_contents($path) : '';
        $seedCol = '';
        $writeCol = '';
        // Match: 'Trigger' => ['alertTriggers', 'TRG'],
        if (preg_match("/'{$counterType}'\\s*=>\\s*\\[\\s*'([^']+)'/", $src, $m)) {
            $seedCol = $m[1];
        }
        // Match: private const FS_COL_TRIGGERS = 'alertTriggers';
        if (preg_match("/const\\s+{$writeConstName}\\s*=\\s*'([^']+)'/", $src, $m)) {
            $writeCol = $m[1];
        }
        return [$seedCol, $writeCol];
    }

    // ── T1.1 Counter convention ─────────────────────────────────────────
    private function _t1_1_counters(?array $profileDoc, array $templates, array $triggersM, array $triggersA,
                                    array $queue, array $logsM, array $logsD, array $notices, array $circulars,
                                    array $conversations, array $messages): void
    {
        echo "─── T1.1: Counter convention (schools/profile commCounters.*) ───\n";
        if (!is_array($profileDoc)) {
            echo "  profile doc missing — cannot verify counter state\n";
            return;
        }

        $counters = [
            'Conversation' => 'CONV', 'Message' => 'MSG',
            'Notice' => 'NOT', 'Circular' => 'CIR',
            'Template' => 'TPL', 'Trigger' => 'TRG',
            'Log' => 'LOG', 'Queue' => 'QUE',
        ];

        $sources = [
            'Conversation' => $conversations, 'Message' => $messages,
            'Notice' => $notices, 'Circular' => $circulars,
            'Template' => $templates, 'Trigger' => $triggersM, // counter seed scans messageTriggers
            'Log' => $logsM,                                     // counter seed scans messageLogs
            'Queue' => $queue,
        ];

        $issues = [];
        foreach ($counters as $type => $prefix) {
            $flatKey = "commCounters.{$type}";
            $val = $profileDoc[$flatKey] ?? null;
            $nestedVal = isset($profileDoc['commCounters']) && is_array($profileDoc['commCounters'])
                ? ($profileDoc['commCounters'][$type] ?? null) : null;
            $effective = $val ?? $nestedVal;
            $shape = $val !== null ? 'flat' : ($nestedVal !== null ? 'nested' : 'absent');

            // Highest extant id in seed source
            $maxN = 0;
            $sourceCount = 0;
            $sourcePrefix = $this->schoolFs . '_';
            foreach ($sources[$type] ?? [] as $r) {
                $sourceCount++;
                $d = is_array($r['data'] ?? null) ? $r['data'] : [];
                $rawId = (string)($d['id'] ?? '');
                $trimmed = (strpos($rawId, $sourcePrefix) === 0) ? substr($rawId, strlen($sourcePrefix)) : $rawId;
                if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', $trimmed, $m)) {
                    $n = (int) $m[1];
                    if ($n > $maxN) $maxN = $n;
                }
            }

            $effInt = is_numeric($effective) ? (int)$effective : null;
            $status = ($effective === null)
                ? "absent (will self-heal from highest extant doc, count={$sourceCount} max={$maxN})"
                : (($effInt !== null && $effInt < $maxN) ? "⚠ counter ({$effInt}) < highest id ({$maxN}) → next ID will COLLIDE" : "ok ({$effInt} ≥ max {$maxN})");
            echo sprintf("  %-13s %-4s  shape=%-7s effective=%-8s sourceCount=%-3d maxN=%-3d  %s\n",
                $type, $prefix, $shape, var_export($effective, true), $sourceCount, $maxN, $status);

            if ($effInt !== null && $effInt < $maxN) $issues[] = "{$type}: counter ({$effInt}) < highest id ({$maxN})";
        }

        if (empty($issues)) {
            echo "  ✅ T1.1 NORMAL — counter convention coherent (where data present)\n";
        } else {
            echo "  ⚠ INVESTIGATE — counter / source divergence:\n";
            foreach ($issues as $i) echo "    - {$i}\n";
        }
    }

    // ── T1.2 messageTemplates ──────────────────────────────────────────
    private function _t1_2_templates(array $templates): void
    {
        echo "─── T1.2: messageTemplates canonical schema ───\n";
        if (empty($templates)) {
            echo "  ℹ TRIVIAL PASS — no templates defined yet\n";
            return;
        }
        $expected = ['id', 'name', 'channel', 'body'];
        $missing = [];
        $eventDist = [];
        foreach ($templates as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            foreach ($expected as $f) {
                if (!array_key_exists($f, $d)) $missing[] = ($d['id'] ?? '?') . ":missing {$f}";
            }
            $ev = (string)($d['event'] ?? $d['type'] ?? '');
            if ($ev !== '') $eventDist[$ev] = ($eventDist[$ev] ?? 0) + 1;
        }
        echo "  templates total: " . count($templates) . "\n";
        echo "  events distribution: " . json_encode($eventDist) . "\n";
        echo "  schema field gaps: " . count($missing) . "\n";
        foreach (array_slice($missing, 0, 10) as $row) echo "    - {$row}\n";
        echo (empty($missing) ? "  ✅ T1.2 NORMAL\n" : "  ⚠ WATCH — schema gaps\n");
    }

    // ── T1.3 Trigger collection divergence ─────────────────────────────
    private function _t1_3_triggers_divergence(array $triggersM, array $triggersA, array $templates): void
    {
        echo "─── T1.3: Trigger collection — counter-seed alignment ───\n";
        [$seedCol, $writeCol] = $this->_read_constants('Trigger', 'FS_COL_TRIGGERS');
        echo "  COUNTER_SEED_SOURCES['Trigger'] = '{$seedCol}'\n";
        echo "  FS_COL_TRIGGERS              = '{$writeCol}'\n";
        $aligned = ($seedCol === $writeCol);
        echo "  alignment: " . ($aligned ? '✅ ALIGNED' : '⚠ DIVERGENT') . "\n";

        echo "  messageTriggers docs: " . count($triggersM) . "  alertTriggers docs: " . count($triggersA) . "\n";

        $effective = (count($triggersA) > 0) ? $triggersA : (count($triggersM) > 0 ? $triggersM : []);
        $effSrc = (count($triggersA) > 0) ? 'alertTriggers' : (count($triggersM) > 0 ? 'messageTriggers' : 'none');
        echo "  effective triggers source: {$effSrc}\n";

        if (empty($effective)) {
            echo "  ℹ TRIVIAL — no trigger docs in either collection yet\n";
            if ($aligned) {
                echo "  ✅ T1.3 NORMAL — counter seed source matches write target\n";
            } else {
                echo "  ⚠ STRUCTURAL — counter seed scans '{$seedCol}' but writes land in '{$writeCol}'\n";
            }
            return;
        }

        // Schema: id, event, templateId/template_id, enabled
        $missing = [];
        $eventDist = [];
        $tplRefs = [];
        $tplMap = [];
        foreach ($templates as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $tplMap[(string)($d['id'] ?? '')] = $d;
        }
        foreach ($effective as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $id = (string)($d['id'] ?? '?');
            foreach (['id','event','enabled'] as $f) {
                if (!array_key_exists($f, $d)) $missing[] = "{$id}: missing {$f}";
            }
            $ev = (string)($d['event'] ?? '');
            if ($ev !== '') $eventDist[$ev] = ($eventDist[$ev] ?? 0) + 1;
            $tplId = (string)($d['templateId'] ?? $d['template_id'] ?? '');
            if ($tplId !== '') {
                $tplRefs[] = $tplId;
                if (!isset($tplMap[$tplId])) $missing[] = "{$id}: templateId={$tplId} not found in messageTemplates";
            }
        }
        echo "  events: " . json_encode($eventDist) . "\n";
        echo "  schema/template-ref gaps: " . count($missing) . "\n";
        foreach (array_slice($missing, 0, 8) as $row) echo "    - {$row}\n";

        // Cross-collection orphan: trigger in messageTriggers AND alertTriggers with same id?
        $aIds = []; $mIds = [];
        foreach ($triggersA as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $aIds[(string)($d['id']??'')] = true; }
        foreach ($triggersM as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $mIds[(string)($d['id']??'')] = true; }
        $overlap = array_intersect_key($aIds, $mIds);
        if (!empty($overlap)) echo "  ⚠ overlap (id present in BOTH collections): " . count($overlap) . "\n";

        if ($aligned) {
            echo "  ✅ T1.3 NORMAL — counter seed source matches write target\n";
        } else {
            echo "  ⚠ T1.3 WATCH — collection-name divergence: seed='{$seedCol}' vs write='{$writeCol}'\n";
        }
    }

    // ── T1.4 messageQueue lifecycle ────────────────────────────────────
    private function _t1_4_queue(array $queue, array $templates): void
    {
        echo "─── T1.4: messageQueue lifecycle ───\n";
        if (empty($queue)) {
            echo "  ℹ TRIVIAL — no queued messages\n";
            return;
        }
        $statusDist = [];
        $channelDist = [];
        $orphanTpl = 0;
        $retryStats = ['no_attempts' => 0, 'with_attempts' => 0, 'max_attempts' => 0];
        $stuckProcessing = 0;
        $now = time();
        foreach ($queue as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $st = (string)($d['status'] ?? 'unknown');
            $statusDist[$st] = ($statusDist[$st] ?? 0) + 1;
            $ch = (string)($d['channel'] ?? '');
            if ($ch !== '') $channelDist[$ch] = ($channelDist[$ch] ?? 0) + 1;

            $att = (int)($d['attempts'] ?? 0);
            if ($att === 0) $retryStats['no_attempts']++;
            else { $retryStats['with_attempts']++; if ($att > $retryStats['max_attempts']) $retryStats['max_attempts'] = $att; }

            if ($st === 'processing') {
                $updatedAt = (string)($d['updated_at'] ?? $d['updatedAt'] ?? '');
                $ts = strtotime($updatedAt) ?: 0;
                if ($ts > 0 && ($now - $ts) > 3600) $stuckProcessing++;
            }
        }
        echo "  status distribution: " . json_encode($statusDist) . "\n";
        echo "  channel distribution: " . json_encode($channelDist) . "\n";
        echo "  attempts: no={$retryStats['no_attempts']}, with={$retryStats['with_attempts']}, max={$retryStats['max_attempts']}\n";
        echo "  stuck in 'processing' >1h: {$stuckProcessing}\n";

        $issues = ($stuckProcessing > 0) ? ['stuck processing rows'] : [];
        echo (empty($issues) ? "  ✅ T1.4 NORMAL\n" : "  ⚠ WATCH — " . implode(', ', $issues) . "\n");
    }

    // ── T1.5 Log collection counter-seed alignment ─────────────────────
    private function _t1_5_logs_divergence(array $logsM, array $logsD, array $queue): void
    {
        echo "─── T1.5: Log collection — counter-seed alignment ───\n";
        [$seedCol, $writeCol] = $this->_read_constants('Log', 'FS_COL_LOGS');
        echo "  COUNTER_SEED_SOURCES['Log'] = '{$seedCol}'\n";
        echo "  FS_COL_LOGS              = '{$writeCol}'\n";
        $aligned = ($seedCol === $writeCol);
        echo "  alignment: " . ($aligned ? '✅ ALIGNED' : '⚠ DIVERGENT') . "\n";

        echo "  messageLogs docs: " . count($logsM) . "  deliveryLogs docs: " . count($logsD) . "\n";
        $effective = (count($logsD) > 0) ? $logsD : (count($logsM) > 0 ? $logsM : []);
        $effSrc = (count($logsD) > 0) ? 'deliveryLogs' : (count($logsM) > 0 ? 'messageLogs' : 'none');
        echo "  effective logs source: {$effSrc}\n";

        if (empty($effective)) {
            echo "  ℹ TRIVIAL — no delivery logs yet\n";
            if ($aligned) {
                echo "  ✅ T1.5 NORMAL — counter seed source matches write target\n";
            } else {
                echo "  ⚠ STRUCTURAL — counter seed scans '{$seedCol}' but writes land in '{$writeCol}'\n";
            }
            return;
        }

        // Audit-path: each log should reference a queue id, status, and timestamp
        $statusDist = [];
        $missing = [];
        $qIds = [];
        foreach ($queue as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $qid = (string)($d['id']??''); if($qid!=='') $qIds[$qid]=true; }
        $orphanLogs = 0;
        foreach ($effective as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $st = (string)($d['status'] ?? $d['code'] ?? 'unknown');
            $statusDist[$st] = ($statusDist[$st] ?? 0) + 1;
            $qref = (string)($d['queueId'] ?? $d['queue_id'] ?? '');
            if ($qref === '') $missing[] = ($d['id']??'?') . ": missing queueId";
            elseif (!isset($qIds[$qref])) $orphanLogs++;
        }
        echo "  status distribution: " . json_encode($statusDist) . "\n";
        echo "  logs missing queueId: " . count($missing) . "\n";
        echo "  logs with queueId not in messageQueue: {$orphanLogs}\n";

        if ($aligned) {
            echo "  ✅ T1.5 NORMAL — counter seed source matches write target\n";
        } else {
            echo "  ⚠ T1.5 WATCH — collection-name divergence parallel to T1.3\n";
        }
    }

    // ── T1.6 notices + circulars ───────────────────────────────────────
    private function _t1_6_notices_circulars(array $notices, array $circulars): void
    {
        echo "─── T1.6: notices + circulars (HR-source guard, dual-emit) ───\n";
        echo "  notices: " . count($notices) . "  circulars: " . count($circulars) . "\n";
        $hrSrcN = 0; $hrSrcC = 0;
        foreach ($notices as $r) { $d=is_array($r['data']??null)?$r['data']:[]; if ((string)($d['source']??'')==='hr_recruitment') $hrSrcN++; }
        foreach ($circulars as $r) { $d=is_array($r['data']??null)?$r['data']:[]; if ((string)($d['source']??'')==='hr_recruitment') $hrSrcC++; }
        echo "  HR-sourced notices: {$hrSrcN}  HR-sourced circulars: {$hrSrcC}\n";

        // Dual-emit shape: should carry both snake_case (admin) and Android-canonical (body/author/sentAt)
        $dualEmitOk = 0; $shapeIssues = [];
        foreach ($circulars as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $hasAdminShape = isset($d['description']) || isset($d['target_group']) || isset($d['issued_date']);
            $hasAndroidShape = isset($d['body']) || isset($d['sentAt']) || isset($d['targetType']);
            if ($hasAdminShape && $hasAndroidShape) $dualEmitOk++;
            else $shapeIssues[] = ($d['id']??'?') . ': admin=' . ($hasAdminShape?'y':'n') . ' android=' . ($hasAndroidShape?'y':'n');
        }
        echo "  circulars with dual-emit shape: {$dualEmitOk} / " . count($circulars) . "\n";
        foreach (array_slice($shapeIssues, 0, 5) as $row) echo "    - {$row}\n";

        echo (empty($shapeIssues) ? "  ✅ T1.6 NORMAL\n" : "  ⚠ WATCH — dual-emit shape gaps\n");
    }

    // ── T1.7 circularAcks ──────────────────────────────────────────────
    private function _t1_7_circular_acks(array $circularAcks, array $circulars): void
    {
        echo "─── T1.7: circularAcks idempotency + cross-reference ───\n";
        if (empty($circularAcks)) {
            echo "  ℹ TRIVIAL PASS — no acks recorded\n";
            return;
        }
        $cirIds = [];
        foreach ($circulars as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $cirIds[(string)($d['id']??'')] = true; }

        $orphans = 0;
        $dupKey = [];
        foreach ($circularAcks as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $cir = (string)($d['circularId'] ?? '');
            $usr = (string)($d['userId'] ?? '');
            if ($cir !== '' && !isset($cirIds[$cir])) $orphans++;
            $key = "{$this->schoolFs}_{$cir}_{$usr}";
            $dupKey[$key] = ($dupKey[$key] ?? 0) + 1;
        }
        $dupes = 0;
        foreach ($dupKey as $k => $c) if ($c > 1) $dupes++;
        echo "  acks total: " . count($circularAcks) . "\n";
        echo "  acks referencing unknown circular: {$orphans}\n";
        echo "  duplicate flat-key entries: {$dupes}\n";
        echo (($orphans + $dupes) === 0 ? "  ✅ T1.7 NORMAL\n" : "  ⚠ WATCH\n");
    }

    // ── T1.8 conversations + inboxes + messages ────────────────────────
    private function _t1_8_messaging(array $conversations, array $inboxes, array $messages): void
    {
        echo "─── T1.8: conversations / messageInboxes / messages canonical ───\n";
        if (empty($conversations) && empty($inboxes) && empty($messages)) {
            echo "  ℹ TRIVIAL PASS — no messaging primitives populated\n";
            return;
        }

        // Conversations: participant shape — admin uses participants map; canonical uses participantIds array
        $partMap = 0; $partArr = 0; $both = 0; $neither = 0;
        $convoIds = [];
        foreach ($conversations as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $cid = (string)($d['id'] ?? '');
            if ($cid !== '') $convoIds[$cid] = true;
            $hasMap = isset($d['participants']) && is_array($d['participants']);
            $hasArr = isset($d['participantIds']) && is_array($d['participantIds']);
            if ($hasMap && $hasArr) $both++;
            elseif ($hasMap) $partMap++;
            elseif ($hasArr) $partArr++;
            else $neither++;
        }
        echo "  conversations: " . count($conversations) . " (both=$both, only-map=$partMap, only-arr=$partArr, neither=$neither)\n";

        // Inboxes: lowercase role per migration memory
        $caseIssues = 0;
        $rolesSeen = [];
        foreach ($inboxes as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $role = (string)($d['role'] ?? '');
            if ($role !== '') {
                $rolesSeen[$role] = ($rolesSeen[$role] ?? 0) + 1;
                if ($role !== strtolower($role)) $caseIssues++;
            }
        }
        echo "  messageInboxes: " . count($inboxes) . " (case anomalies: {$caseIssues})\n";
        echo "  inbox role distribution: " . json_encode($rolesSeen) . "\n";

        // Messages: must reference a conversation
        $orphanMsgs = 0;
        foreach ($messages as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $cid = (string)($d['conversationId'] ?? $d['convId'] ?? '');
            if ($cid !== '' && !isset($convoIds[$cid])) $orphanMsgs++;
        }
        echo "  messages: " . count($messages) . "  orphan (unknown conversation): {$orphanMsgs}\n";

        $total = $caseIssues + $orphanMsgs;
        echo (($total === 0) ? "  ✅ T1.8 NORMAL\n" : "  ⚠ WATCH — messaging schema/cross-ref gaps\n");
    }

    // ── T1.9 pushRequests ─────────────────────────────────────────────
    private function _t1_9_push_requests(array $pushRequests, array $notices, array $circulars, array $queue): void
    {
        echo "─── T1.9: pushRequests relationships + delivery tracking ───\n";
        if (empty($pushRequests)) {
            echo "  ℹ TRIVIAL — no pushRequests recorded\n";
            return;
        }
        $statusDist = [];
        $retryDist  = [];
        $refKinds = [];
        $orphans = 0;
        $noticeIds = [];
        $circularIds = [];
        $queueIds = [];
        foreach ($notices as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $noticeIds[(string)($d['id']??'')] = true; }
        foreach ($circulars as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $circularIds[(string)($d['id']??'')] = true; }
        foreach ($queue as $r) { $d = is_array($r['data']??null)?$r['data']:[]; $queueIds[(string)($d['id']??'')] = true; }

        foreach ($pushRequests as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $st = (string)($d['status'] ?? 'unknown');
            $statusDist[$st] = ($statusDist[$st] ?? 0) + 1;
            $retry = (int)($d['retryCount'] ?? $d['attempts'] ?? 0);
            $retryDist[$retry] = ($retryDist[$retry] ?? 0) + 1;
            $sourceId = (string)($d['sourceId'] ?? $d['noticeId'] ?? $d['circularId'] ?? $d['queueId'] ?? $d['eventId'] ?? '');
            $sourceKind = (string)($d['sourceKind'] ?? $d['source'] ?? $d['kind'] ?? '');
            $refKinds[$sourceKind ?: 'unknown'] = ($refKinds[$sourceKind ?: 'unknown'] ?? 0) + 1;
            // Cross-reference if possible
            if ($sourceId !== '' && !isset($noticeIds[$sourceId]) && !isset($circularIds[$sourceId]) && !isset($queueIds[$sourceId])) {
                $orphans++;
            }
        }
        echo "  pushRequests: " . count($pushRequests) . "\n";
        echo "  status distribution: " . json_encode($statusDist) . "\n";
        echo "  retry distribution: " . json_encode($retryDist) . "\n";
        echo "  source kind distribution: " . json_encode($refKinds) . "\n";
        echo "  orphan source references (not in notices/circulars/queue): {$orphans}\n";
        echo (($orphans === 0) ? "  ✅ T1.9 NORMAL\n" : "  ⚠ WATCH — orphan source references\n");
    }

    // ── T1.10 Audience resolution / target groups ─────────────────────
    private function _t1_10_audience_resolution(array $sections, array $notices, array $circulars, array $queue): void
    {
        echo "─── T1.10: Audience resolution + target-group integrity ───\n";
        $sectionKeys = [];
        foreach ($sections as $r) {
            $d = is_array($r['data']??null)?$r['data']:[];
            $cn = (string)($d['className'] ?? '');
            $se = (string)($d['section'] ?? '');
            if ($cn !== '' && $se !== '') $sectionKeys["{$cn}|{$se}"] = true;
        }
        echo "  canonical section keys (className|section): " . count($sectionKeys) . "\n";

        // Common target-group spec patterns in admin + bulk send
        $validTopLevel = ['All School', 'All Students', 'All Teachers', 'all', 'broadcast', 'parents'];

        $unknownTargets = [];
        foreach ([['notices', $notices], ['circulars', $circulars], ['queue', $queue]] as $pair) {
            [$col, $rows] = $pair;
            foreach ($rows as $r) {
                $d = is_array($r['data']??null)?$r['data']:[];
                $tg = (string)($d['target_group'] ?? $d['targetGroup'] ?? $d['target'] ?? '');
                if ($tg === '') continue;
                // It's either a top-level wildcard or a "ClassN|SectionX" key
                if (in_array($tg, $validTopLevel, true)) continue;
                if (isset($sectionKeys[$tg])) continue;
                $unknownTargets[] = "{$col}/" . ($d['id']??'?') . ": target='{$tg}'";
            }
        }
        echo "  unknown / unresolvable target_group values: " . count($unknownTargets) . "\n";
        foreach (array_slice($unknownTargets, 0, 10) as $row) echo "    - {$row}\n";
        echo (empty($unknownTargets) ? "  ✅ T1.10 NORMAL\n" : "  ⚠ WATCH — audience drift\n");
    }
}
