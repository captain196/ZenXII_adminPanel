<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Firestore_retry_queue — RTDB-based persistent queue for failed Firestore writes.
 *
 * PERSISTENCE: Stored in RTDB at System/RetryQueue/{schoolId}/{entryId}
 *   - Survives server restarts (unlike file-based queue)
 *   - Visible across all app instances
 *   - Queryable for admin monitoring
 *
 * DEAD LETTER: System/RetryQueue/DeadLetter/{entryId}
 *   - Failed after MAX_RETRIES attempts
 *   - Admin can replay or discard
 *
 * IDEMPOTENCY:
 *   - Each entry has an idempotencyKey
 *   - Duplicates are rejected on push()
 *   - processQueue() checks before executing
 *
 * BACKOFF: Exponential with jitter
 *   Attempt 1: immediate (inline)
 *   Attempt 2: 200ms (inline)
 *   Retry 1:   2s
 *   Retry 2:   8s
 *   Retry 3:   32s
 *   Retry 4:   60s (capped)
 *
 * ENTRY SCHEMA:
 *   {
 *     id:              "fq_abc123_1711900000",
 *     idempotencyKey:  "move_STU0001_42",
 *     syncMethod:      "syncStudent",
 *     args:            ["STU0001", {...}],
 *     schoolId:        "SCH_A98BD946F3",
 *     schoolCode:      "10001",
 *     session:         "2026-27",
 *     retryCount:      0,
 *     maxRetries:      5,
 *     createdAt:       "2026-04-01T10:30:00+05:30",
 *     lastAttempt:     null,
 *     nextRetryAfter:  "2026-04-01T10:30:02+05:30",
 *     status:          "pending",   // pending | completed | dead
 *     lastError:       "Connection timeout",
 *     syncVersion:     42
 *   }
 */
class Firestore_retry_queue
{
    const MAX_RETRIES       = 5;
    const BACKOFF_BASE_SEC  = 2;
    const BACKOFF_CAP_SEC   = 60;
    const QUEUE_PATH        = 'System/RetryQueue';
    const DEAD_PATH         = 'System/RetryQueue/DeadLetter';

    /** @var object Firebase RTDB library (set on first use) */
    private $firebase;

    /** File-based fallback if RTDB is unavailable */
    private $fallbackFile;
    private $deadFile;

    public function __construct()
    {
        $logPath = APPPATH . 'logs/';
        if (!is_dir($logPath)) @mkdir($logPath, 0755, true);
        $this->fallbackFile = $logPath . 'firestore_retry_queue.jsonl';
        $this->deadFile     = $logPath . 'firestore_dead_letter.jsonl';
    }

    /**
     * Lazy-load Firebase from CI instance.
     */
    private function _fb()
    {
        if ($this->firebase) return $this->firebase;
        try {
            $CI =& get_instance();
            if (isset($CI->firebase)) {
                $this->firebase = $CI->firebase;
                return $this->firebase;
            }
        } catch (\Exception $e) { /* fallback to file */ }
        return null;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PUSH — Called by Dual_write on failure
    // ══════════════════════════════════════════════════════════════════

    public function push(
        string $syncMethod,
        array  $args,
        string $schoolId,
        string $schoolCode,
        string $session,
        string $error = '',
        ?string $idempotencyKey = null
    ): bool {
        $entryId = $this->_generateId();

        $entry = [
            'id'              => $entryId,
            'idempotencyKey'  => $idempotencyKey ?? '',
            'syncMethod'      => $syncMethod,
            'args'            => $args,
            'schoolId'        => $schoolId,
            'schoolCode'      => $schoolCode,
            'session'         => $session,
            'retryCount'      => 0,
            'maxRetries'      => self::MAX_RETRIES,
            'createdAt'       => date('c'),
            'lastAttempt'     => null,
            'nextRetryAfter'  => date('c', time() + self::BACKOFF_BASE_SEC),
            'status'          => 'pending',
            'lastError'       => $error,
        ];

        $fb = $this->_fb();
        if ($fb) {
            // Idempotency check: scan existing pending entries
            if ($idempotencyKey) {
                try {
                    $existing = $fb->get(self::QUEUE_PATH . "/{$schoolId}");
                    if (is_array($existing)) {
                        foreach ($existing as $ex) {
                            if (is_array($ex)
                                && ($ex['status'] ?? '') === 'pending'
                                && ($ex['idempotencyKey'] ?? '') === $idempotencyKey) {
                                return true; // Already queued
                            }
                        }
                    }
                } catch (\Exception $e) { /* proceed — better to have a dupe than lose the entry */ }
            }

            // Write to RTDB
            try {
                $fb->set(self::QUEUE_PATH . "/{$schoolId}/{$entryId}", $entry);
                return true;
            } catch (\Exception $e) {
                log_message('error', "RetryQueue RTDB push failed: " . $e->getMessage());
                // Fall through to file-based fallback
            }
        }

        // Fallback: file-based queue
        return $this->_appendFile($this->fallbackFile, $entry);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PROCESS — Called by cron worker
    // ══════════════════════════════════════════════════════════════════

    public function processQueue($firebase): array
    {
        $this->firebase = $firebase;
        $result = ['processed' => 0, 'succeeded' => 0, 'retried' => 0, 'dead' => 0, 'errors' => []];

        // Process RTDB queue
        try {
            $allSchools = $firebase->get(self::QUEUE_PATH);
            if (!is_array($allSchools)) return $result;

            foreach ($allSchools as $schoolId => $entries) {
                if ($schoolId === 'DeadLetter' || !is_array($entries)) continue;

                foreach ($entries as $entryId => $entry) {
                    if (!is_array($entry) || ($entry['status'] ?? '') !== 'pending') continue;

                    // Backoff check: skip if not ready for retry yet
                    $nextRetry = $entry['nextRetryAfter'] ?? null;
                    if ($nextRetry && strtotime($nextRetry) > time()) continue;

                    $result['processed']++;
                    $this->_processEntry($firebase, $schoolId, $entryId, $entry, $result);
                }
            }
        } catch (\Exception $e) {
            $result['errors'][] = 'RTDB queue read failed: ' . $e->getMessage();
        }

        // Also process file-based fallback entries
        $this->_processFileFallback($firebase, $result);

        return $result;
    }

    private function _processEntry($firebase, string $schoolId, string $entryId, array $entry, array &$result): void
    {
        $sync = $this->_buildSync($firebase, $entry);
        if (!$sync) {
            $this->_failEntry($firebase, $schoolId, $entryId, $entry, 'Sync init failed', $result);
            return;
        }

        // Check idempotency: if a later version exists for same key, skip this one
        if (!empty($entry['idempotencyKey'])) {
            // Stale check: if syncVersion in entry is lower than current, skip
            try {
                $curVer = $firebase->get("System/SyncVersion/{$schoolId}");
                $entryVer = $entry['syncVersion'] ?? 0;
                if (is_numeric($curVer) && $entryVer > 0 && $entryVer < (int)$curVer - 5) {
                    // This entry is significantly stale — skip and mark completed
                    $firebase->set(self::QUEUE_PATH . "/{$schoolId}/{$entryId}/status", 'completed');
                    $result['succeeded']++;
                    return;
                }
            } catch (\Exception $e) { /* proceed */ }
        }

        $success = $this->_attemptWrite($sync, $entry['syncMethod'], $entry['args']);

        if ($success) {
            // Mark completed and remove from queue
            try {
                $firebase->delete(self::QUEUE_PATH . "/{$schoolId}", $entryId);
            } catch (\Exception $e) {
                // Fallback: mark as completed in place
                $firebase->set(self::QUEUE_PATH . "/{$schoolId}/{$entryId}/status", 'completed');
            }
            $result['succeeded']++;
        } else {
            $this->_failEntry($firebase, $schoolId, $entryId, $entry, 'Write returned false', $result);
        }
    }

    private function _failEntry($firebase, string $schoolId, string $entryId, array $entry, string $error, array &$result): void
    {
        $entry['retryCount']  = ($entry['retryCount'] ?? 0) + 1;
        $entry['lastAttempt'] = date('c');
        $entry['lastError']   = $error;

        if ($entry['retryCount'] >= ($entry['maxRetries'] ?? self::MAX_RETRIES)) {
            // Move to dead letter
            $entry['status'] = 'dead';
            try {
                $firebase->set(self::DEAD_PATH . "/{$entryId}", $entry);
                $firebase->delete(self::QUEUE_PATH . "/{$schoolId}", $entryId);
            } catch (\Exception $e) {
                $this->_appendFile($this->deadFile, $entry);
            }
            $result['dead']++;
            log_message('error', "RetryQueue DEAD: {$entry['syncMethod']} id={$entryId} after {$entry['retryCount']} retries");
        } else {
            // Schedule next retry with exponential backoff + jitter
            $delaySec = min(
                pow(self::BACKOFF_BASE_SEC, $entry['retryCount'] + 1),
                self::BACKOFF_CAP_SEC
            );
            $jitter = random_int(0, (int)($delaySec * 0.3)); // 0-30% jitter
            $entry['nextRetryAfter'] = date('c', time() + $delaySec + $jitter);
            $entry['status'] = 'pending';

            try {
                $firebase->set(self::QUEUE_PATH . "/{$schoolId}/{$entryId}", $entry);
            } catch (\Exception $e) {
                $this->_appendFile($this->fallbackFile, $entry);
            }
            $result['retried']++;
        }
    }

    private function _processFileFallback($firebase, array &$result): void
    {
        if (!file_exists($this->fallbackFile)) return;
        $lines = @file($this->fallbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return;

        $remaining = [];
        foreach ($lines as $line) {
            $entry = json_decode(trim($line), true);
            if (!is_array($entry) || ($entry['status'] ?? '') !== 'pending') continue;

            // Migrate to RTDB queue
            $schoolId = $entry['schoolId'] ?? 'unknown';
            $entryId  = $entry['id'] ?? $this->_generateId();
            try {
                $firebase->set(self::QUEUE_PATH . "/{$schoolId}/{$entryId}", $entry);
            } catch (\Exception $e) {
                $remaining[] = $line; // Keep in file if RTDB push fails
            }
        }

        // Rewrite file with only un-migrated entries
        @file_put_contents($this->fallbackFile, implode("\n", $remaining) . (empty($remaining) ? '' : "\n"), LOCK_EX);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ADMIN MONITORING
    // ══════════════════════════════════════════════════════════════════

    /**
     * Get queue health stats (for admin panel / debug endpoint).
     */
    public function getStats(): array
    {
        $fb = $this->_fb();
        $stats = [
            'pending_count'     => 0,
            'dead_count'        => 0,
            'pending_by_school' => [],
            'pending_by_method' => [],
            'oldest_pending'    => null,
            'storage'           => 'unknown',
        ];

        if ($fb) {
            $stats['storage'] = 'rtdb';
            try {
                $all = $fb->get(self::QUEUE_PATH);
                if (is_array($all)) {
                    foreach ($all as $schoolId => $entries) {
                        if ($schoolId === 'DeadLetter') {
                            $stats['dead_count'] = is_array($entries) ? count($entries) : 0;
                            continue;
                        }
                        if (!is_array($entries)) continue;

                        $schoolPending = 0;
                        foreach ($entries as $entry) {
                            if (!is_array($entry) || ($entry['status'] ?? '') !== 'pending') continue;
                            $schoolPending++;
                            $method = $entry['syncMethod'] ?? 'unknown';
                            $stats['pending_by_method'][$method] = ($stats['pending_by_method'][$method] ?? 0) + 1;

                            $created = $entry['createdAt'] ?? null;
                            if ($created && ($stats['oldest_pending'] === null || $created < $stats['oldest_pending'])) {
                                $stats['oldest_pending'] = $created;
                            }
                        }
                        if ($schoolPending > 0) {
                            $stats['pending_by_school'][$schoolId] = $schoolPending;
                        }
                        $stats['pending_count'] += $schoolPending;
                    }
                }
            } catch (\Exception $e) {
                $stats['error'] = $e->getMessage();
            }
        } else {
            $stats['storage'] = 'file_fallback';
            if (file_exists($this->fallbackFile)) {
                $lines = @file($this->fallbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $stats['pending_count'] = $lines ? count($lines) : 0;
            }
        }

        return $stats;
    }

    /**
     * Get all pending entries for a school (admin UI).
     */
    public function getPending(?string $schoolId = null): array
    {
        $fb = $this->_fb();
        if (!$fb) return [];

        try {
            if ($schoolId) {
                $entries = $fb->get(self::QUEUE_PATH . "/{$schoolId}");
            } else {
                $entries = $fb->get(self::QUEUE_PATH);
            }
            if (!is_array($entries)) return [];

            $pending = [];
            foreach ($entries as $k => $v) {
                if ($k === 'DeadLetter') continue;
                if ($schoolId) {
                    // Direct entries
                    if (is_array($v) && ($v['status'] ?? '') === 'pending') $pending[] = $v;
                } else {
                    // Nested by school
                    if (is_array($v)) {
                        foreach ($v as $entry) {
                            if (is_array($entry) && ($entry['status'] ?? '') === 'pending') $pending[] = $entry;
                        }
                    }
                }
            }
            return $pending;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get dead-letter entries.
     */
    public function getDead(): array
    {
        $fb = $this->_fb();
        if (!$fb) {
            if (!file_exists($this->deadFile)) return [];
            $lines = @file($this->deadFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            return array_filter(array_map(fn($l) => json_decode(trim($l), true), $lines ?: []), 'is_array');
        }

        try {
            $dead = $fb->get(self::DEAD_PATH);
            return is_array($dead) ? array_values($dead) : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Replay a dead-letter entry (move back to pending).
     */
    public function replayDead(string $entryId): bool
    {
        $fb = $this->_fb();
        if (!$fb) return false;

        try {
            $entry = $fb->get(self::DEAD_PATH . "/{$entryId}");
            if (!is_array($entry)) return false;

            $entry['status']         = 'pending';
            $entry['retryCount']     = 0;
            $entry['lastAttempt']    = null;
            $entry['lastError']      = null;
            $entry['nextRetryAfter'] = date('c');

            $schoolId = $entry['schoolId'] ?? 'unknown';
            $fb->set(self::QUEUE_PATH . "/{$schoolId}/{$entryId}", $entry);
            $fb->delete(self::DEAD_PATH, $entryId);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Replay ALL dead letters.
     */
    public function replayAllDead(): int
    {
        $dead = $this->getDead();
        $replayed = 0;
        foreach ($dead as $entry) {
            if ($this->replayDead($entry['id'] ?? '')) $replayed++;
        }
        return $replayed;
    }

    /**
     * Purge dead letters older than $days.
     */
    public function purgeDead(int $days = 30): int
    {
        $fb = $this->_fb();
        if (!$fb) return 0;

        $cutoff = date('c', strtotime("-{$days} days"));
        $dead = $this->getDead();
        $purged = 0;

        foreach ($dead as $entry) {
            if (($entry['createdAt'] ?? '') < $cutoff) {
                try {
                    $fb->delete(self::DEAD_PATH, $entry['id'] ?? '');
                    $purged++;
                } catch (\Exception $e) { /* skip */ }
            }
        }
        return $purged;
    }

    // ══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ══════════════════════════════════════════════════════════════════

    private function _generateId(): string
    {
        return 'fq_' . bin2hex(random_bytes(8)) . '_' . time();
    }

    private function _appendFile(string $file, array $entry): bool
    {
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    private function _buildSync($firebase, array $entry)
    {
        try {
            require_once APPPATH . 'libraries/Entity_firestore_sync.php';
            $sync = new Entity_firestore_sync();
            $sync->init($firebase, $entry['schoolId'] ?? '', $entry['session'] ?? '', $entry['schoolCode'] ?? '');
            return $sync;
        } catch (\Exception $e) {
            log_message('error', "RetryQueue _buildSync failed: " . $e->getMessage());
            return null;
        }
    }

    private function _attemptWrite($sync, string $method, array $args): bool
    {
        if (!method_exists($sync, $method)) return false;
        try {
            return (bool) call_user_func_array([$sync, $method], $args);
        } catch (\Exception $e) {
            log_message('error', "RetryQueue {$method} threw: " . $e->getMessage());
            return false;
        }
    }
}
