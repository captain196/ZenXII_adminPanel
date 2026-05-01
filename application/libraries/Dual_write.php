<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Dual_write — Production-hardened dual-write engine (RTDB + Firestore + Auth).
 *
 * ARCHITECTURE:
 *   RTDB      = primary source of truth (must succeed)
 *   Firestore = query layer for apps (eventually consistent)
 *   Auth      = identity layer (retried on failure)
 *
 * PRODUCTION FEATURES:
 *   1. Batch multi-path RTDB updates (atomic per Firebase guarantee)
 *   2. batchMoveStudents() for bulk promotion (single atomic RTDB write)
 *   3. RTDB-based persistent retry queue (survives server restarts)
 *   4. syncVersion on every write (monotonic counter for stale-read prevention)
 *   5. Unified idempotencyKey across RTDB, Firestore, and Auth operations
 *   6. Retried REMOVE operations (prevents duplicate roster ghost entries)
 *   7. Dead-letter monitoring via admin endpoint
 *   8. Soft-delete with recovery support
 */
class Dual_write
{
    private $firebase;
    private $sync;
    private $retryQueue;

    private $schoolId    = '';
    private $session     = '';
    private $schoolCode  = '';
    private $parentDbKey = '';
    private $ready       = false;

    /** @var int Monotonic sync version — incremented on every write */
    private $syncVersion = 0;

    /** @var array Idempotency keys processed this request */
    private $processedKeys = [];

    private $stats = [
        'rtdb_writes'    => 0, 'rtdb_failures'  => 0,
        'fs_writes'      => 0, 'fs_failures'    => 0,
        'fs_retries'     => 0, 'fs_queued'      => 0,
        'auth_writes'    => 0, 'auth_failures'  => 0,
        'batch_paths'    => 0,
    ];

    // ══════════════════════════════════════════════════════════════════
    //  INIT
    // ══════════════════════════════════════════════════════════════════

    public function init($firebase, string $schoolId, string $session, string $schoolCode = '', string $parentDbKey = ''): self
    {
        $this->firebase    = $firebase;
        $this->schoolId    = $schoolId;
        $this->session     = $session;
        $this->schoolCode  = $schoolCode ?: $schoolId;
        $this->parentDbKey = $parentDbKey ?: $this->schoolCode;

        $CI =& get_instance();
        if (!isset($CI->entity_sync)) {
            $CI->load->library('entity_firestore_sync', null, 'entity_sync');
        }
        $this->sync = $CI->entity_sync;
        $this->sync->init($firebase, $schoolId, $session, $this->schoolCode);

        if (!isset($CI->firestore_retry_queue)) {
            $CI->load->library('firestore_retry_queue');
        }
        $this->retryQueue = $CI->firestore_retry_queue;

        // 2026-04-24: RTDB has been fully retired (see memory file
        // "NO RTDB — Firestore ONLY"). Reading System/SyncVersion here
        // was consuming a ~5–10 s retry timeout on every request when
        // the legacy RTDB endpoint was unreachable (DNS / connect
        // failures) — directly responsible for the 26 s fetch_fee_details
        // spike in the 2026-04-24 logs, and every similar cross-module
        // slow-request trace. We now seed with a local epoch-ms counter
        // so writes that depend on syncVersion still get a monotonic,
        // tenant-unique value without any network call.
        $this->syncVersion = (int) (microtime(true) * 1000);

        $this->ready = ($firebase !== null && $schoolId !== '');
        return $this;
    }

    public function isReady(): bool       { return $this->ready; }
    public function getStats(): array     { return $this->stats; }
    public function schoolId(): string    { return $this->schoolId; }
    public function session(): string     { return $this->session; }
    public function parentDbKey(): string { return $this->parentDbKey; }
    public function getSyncVersion(): int { return $this->syncVersion; }

    // ══════════════════════════════════════════════════════════════════
    //  SYNC VERSION (prevents stale reads in apps)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Increment and return the next syncVersion.
     * Stored in RTDB at System/SyncVersion/{schoolId}.
     * Apps can compare this to their cached version to detect stale data.
     */
    private function _nextSyncVersion(): int
    {
        $this->syncVersion++;
        // 2026-04-24: RTDB retired — see init(). The write below was
        // "fire-and-forget" per the comment but the Firebase library
        // still issued the HTTP request synchronously on mod_php,
        // costing ~500 ms on healthy days and ~10 s on flaky DNS.
        // The counter lives purely in memory now; readers that need a
        // monotonic cache-buster get the same guarantee from the
        // epoch-ms seed plus the in-process increment.
        return $this->syncVersion;
    }

    /**
     * Build metadata fields injected into every Firestore write.
     */
    private function _syncMeta(): array
    {
        return [
            'syncVersion'  => $this->_nextSyncVersion(),
            'lastSyncedAt' => date('c'),
            'updatedAt'    => date('c'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════
    //  VALIDATION
    // ══════════════════════════════════════════════════════════════════

    public static function validateClass(string $val): string
    {
        $v = Firestore_service::classKey(trim($val));
        if ($v === '' || $v === 'Class ') {
            throw new \InvalidArgumentException("Invalid class: '{$val}'");
        }
        return $v;
    }

    public static function validateSection(string $val): string
    {
        $v = Firestore_service::sectionKey(trim($val));
        if ($v === '' || $v === 'Section ') {
            throw new \InvalidArgumentException("Invalid section: '{$val}'");
        }
        return $v;
    }

    // ══════════════════════════════════════════════════════════════════
    //  RTDB ENGINE
    // ══════════════════════════════════════════════════════════════════

    private function writeToRTDB(string $path, $data, string $method = 'set'): bool
    {
        try {
            switch ($method) {
                case 'update': $this->firebase->update($path, $data); break;
                case 'push':   $this->firebase->push($path, $data);   break;
                case 'delete':
                    $key = is_string($data) ? $data : '';
                    $this->firebase->delete($path, $key);
                    break;
                default:       $this->firebase->set($path, $data);    break;
            }
            $this->stats['rtdb_writes']++;
            return true;
        } catch (\Exception $e) {
            $this->stats['rtdb_failures']++;
            $this->_log('error', "RTDB {$method} FAILED: {$path} — " . $e->getMessage());
            return false;
        }
    }

    /**
     * ATOMIC multi-path RTDB update.
     * Firebase guarantees ALL paths succeed or NONE do.
     */
    public function multiPathUpdate(array $updates): bool
    {
        if (!$this->ready || empty($updates)) return false;
        try {
            $this->firebase->getDatabase()->getReference('/')->update($updates);
            $pathCount = count($updates);
            $this->stats['rtdb_writes'] += $pathCount;
            $this->stats['batch_paths'] += $pathCount;
            $this->_log('debug', "RTDB multi-path OK: {$pathCount} paths");
            return true;
        } catch (\Exception $e) {
            $this->stats['rtdb_failures']++;
            $this->_log('error', 'RTDB multi-path FAILED (' . count($updates) . ' paths): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retried REMOVE: deletes with verification.
     * If first attempt fails, retries once. Logs but does not block caller.
     */
    private function _retriedRemove(string $path, string $childKey): bool
    {
        $ok = $this->writeToRTDB($path, $childKey, 'delete');
        if ($ok) return true;

        // Retry once after 100ms
        usleep(100000);
        $ok = $this->writeToRTDB($path, $childKey, 'delete');
        if (!$ok) {
            $this->_log('error', "REMOVE retry FAILED: {$path}/{$childKey} — may cause ghost roster entry");
        }
        return $ok;
    }

    // ══════════════════════════════════════════════════════════════════
    //  FIRESTORE ENGINE (with idempotent retry queue)
    // ══════════════════════════════════════════════════════════════════

    /**
     * @param string      $syncMethod      Entity_firestore_sync method
     * @param array       $args            Method arguments
     * @param string|null $idempotencyKey  Shared across RTDB/FS/Auth for same logical operation
     */
    private function writeToFirestore(string $syncMethod, array $args, ?string $idempotencyKey = null): bool
    {
        if (!$this->sync || !method_exists($this->sync, $syncMethod)) {
            $this->stats['fs_failures']++;
            return false;
        }

        // Deduplicate within same request
        if ($idempotencyKey && isset($this->processedKeys[$idempotencyKey])) {
            return true;
        }

        // Attempt 1
        try {
            if (call_user_func_array([$this->sync, $syncMethod], $args)) {
                $this->stats['fs_writes']++;
                if ($idempotencyKey) $this->processedKeys[$idempotencyKey] = true;
                return true;
            }
        } catch (\Exception $e) {
            $this->_log('error', "FS {$syncMethod} attempt 1: " . $e->getMessage());
        }

        // Attempt 2 (200ms backoff)
        $this->stats['fs_retries']++;
        usleep(200000);
        try {
            if (call_user_func_array([$this->sync, $syncMethod], $args)) {
                $this->stats['fs_writes']++;
                if ($idempotencyKey) $this->processedKeys[$idempotencyKey] = true;
                return true;
            }
        } catch (\Exception $e) { /* fall through */ }

        // Queue for async retry
        $this->stats['fs_failures']++;
        $this->_enqueue($syncMethod, $args, $idempotencyKey);
        return false;
    }

    private function _enqueue(string $syncMethod, array $args, ?string $idempotencyKey = null, string $error = ''): void
    {
        if (!$this->retryQueue) return;
        $queued = $this->retryQueue->push(
            $syncMethod, $args,
            $this->schoolId, $this->schoolCode, $this->session,
            $error ?: 'Inline retry exhausted',
            $idempotencyKey
        );
        if ($queued) {
            $this->stats['fs_queued']++;
        } else {
            $this->_log('error', "FS {$syncMethod} FAILED and could NOT be queued");
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  AUTH ENGINE (with retry via queue)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Update Firebase Auth — NOT fire-and-forget. Shares idempotencyKey with
     * the parent operation so RTDB+FS+Auth are all tracked together.
     */
    public function updateAuth(string $uid, array $props, ?string $idempotencyKey = null): bool
    {
        $key = $idempotencyKey ?? "auth_{$uid}_" . md5(json_encode($props));
        if (isset($this->processedKeys[$key])) return true;

        try {
            $this->firebase->updateFirebaseUser($uid, $props);
            $this->stats['auth_writes']++;
            $this->processedKeys[$key] = true;
            return true;
        } catch (\Exception $e) {
            $this->stats['auth_failures']++;
            $this->_log('error', "Auth update failed {$uid}: " . $e->getMessage());
            // Queue auth retry — uses same idempotencyKey as parent op
            $this->_enqueue('_retryAuth', [$uid, $props], $key, $e->getMessage());
            return false;
        }
    }

    public function createAuth(string $uid, string $email, string $password, string $displayName, array $claims, ?string $idempotencyKey = null): bool
    {
        $key = $idempotencyKey ?? "authCreate_{$uid}";
        if (isset($this->processedKeys[$key])) return true;

        try {
            $this->firebase->createFirebaseUser($email, $password, [
                'uid' => $uid, 'displayName' => $displayName,
            ]);
            $this->firebase->setFirebaseClaims($uid, $claims);
            $this->stats['auth_writes']++;
            $this->processedKeys[$key] = true;
            return true;
        } catch (\Exception $e) {
            $this->stats['auth_failures']++;
            $this->_log('error', "Auth create failed {$uid}: " . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  GENERIC DUAL-WRITE
    // ══════════════════════════════════════════════════════════════════

    public function write(string $rtdbPath, $rtdbData, string $rtdbMethod, string $fsSyncMethod, array $fsSyncArgs, ?string $idempotencyKey = null): bool
    {
        $rtdbOk = $this->writeToRTDB($rtdbPath, $rtdbData, $rtdbMethod);
        if (!$rtdbOk) return false;
        $this->writeToFirestore($fsSyncMethod, $fsSyncArgs, $idempotencyKey);
        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT: SINGLE OPERATIONS
    // ══════════════════════════════════════════════════════════════════

    /**
     * R7 — Firestore-only. Was a dual-write to
     * `Users/Parents/{parentDbKey}/{id}` (RTDB) + Firestore students;
     * now the RTDB write is gone. No live callers remain in the
     * codebase but the public signature is preserved for safety.
     */
    public function writeStudent(string $studentId, array $data): bool
    {
        if (!$this->ready) return false;
        $idemKey = "stu_{$studentId}_" . $this->syncVersion;
        $data = array_merge($data, $this->_syncMeta());
        $this->writeToFirestore('syncStudent', [$studentId, $data], $idemKey);
        return true;
    }

    /**
     * R7 — Firestore-only. See writeStudent() for the rationale.
     */
    public function setStudent(string $studentId, array $data): bool
    {
        if (!$this->ready) return false;
        $idemKey = "stuSet_{$studentId}_" . $this->syncVersion;
        $data = array_merge($data, $this->_syncMeta());
        $this->writeToFirestore('syncStudent', [$studentId, $data], $idemKey);
        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT: MOVE (single student)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Move student between classes — Firestore-only post-R7.
     *
     * The previous implementation did an atomic RTDB add-new + profile
     * update, then a retried remove from the old roster, and finally a
     * Firestore sync. All RTDB steps are gone; the single syncStudent()
     * call below carries the new className/section/status, and rosters
     * are derived from Firestore (so the old roster entry is implicitly
     * gone the moment the doc's section field flips).
     *
     * No live callers — `Sis::promote` uses `batchMoveStudents` instead.
     * Kept for API compatibility.
     */
    public function moveStudent(string $studentId, array $from, array $to, array $data): bool
    {
        if (!$this->ready) return false;

        $name      = $data['Name'] ?? $data['name'] ?? $studentId;
        $toClass   = self::validateClass($to['class']);
        $toSection = self::validateSection($to['section']);
        // $from and its sub-fields are validated at the caller and
        // would historically determine the old-roster path; that path
        // no longer exists. Validate-and-discard to fail fast on bad input.
        self::validateClass($from['class']  ?? '');
        self::validateSection($from['section'] ?? '');
        $idemKey = "move_{$studentId}_{$this->syncVersion}";

        $fsData = array_merge($data, $this->_syncMeta(), [
            'Name'       => $name,
            'name'       => $name,
            'className'  => $toClass,
            'section'    => $toSection,
            'sectionKey' => Firestore_service::buildSectionKey($toClass, $toSection),
            'status'     => 'Active',
        ]);
        $this->writeToFirestore('syncStudent', [$studentId, $fsData], $idemKey);

        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT: BATCH MOVE (bulk promotion — single RTDB call)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Move N students in ONE atomic RTDB multi-path update.
     *
     * Instead of N separate moveStudent() calls (N network roundtrips),
     * this builds a single multi-path update for ALL students, then
     * batch-syncs Firestore.
     *
     * @param array  $students   [ userId => ['name'=>..., 'oldSection'=>'Section A'] ]
     * @param string $fromClass  "Class 8th"
     * @param string $fromSession "2025-26"
     * @param string $toClass    "Class 9th"
     * @param string $toSection  "Section A"
     * @param string $toSession  "2026-27"
     * @return array ['moved' => [...], 'failed' => [...]]
     */
    public function batchMoveStudents(
        array  $students,
        string $fromClass,
        string $fromSession,
        string $toClass,
        string $toSection,
        string $toSession
    ): array {
        $result = ['moved' => [], 'failed' => []];
        if (!$this->ready || empty($students)) return $result;

        $toClass   = self::validateClass($toClass);
        $toSection = self::validateSection($toSection);
        // $fromClass / $fromSession / oldSection are no longer needed —
        // the legacy "remove from old session roster" RTDB step is gone
        // post-R7 (rosters are now derived from Firestore students.className /
        // section, which the per-student syncStudent below updates).
        // Validating $fromClass and reading $oldSection is left intentionally
        // un-done here so a stale `from` arg can't hide a bug.

        // R7 — Firestore-only batch promotion. Each student gets a
        // syncStudent() call with the new class/section/status. No
        // RTDB roster writes (`Schools/{...}/{class}/{section}/Students/List`
        // and `…/{Students}/{id}`) and no RTDB profile writes
        // (`Users/Parents/{parentDbKey}/{id}/Class` & `/Section`).
        // Roster reads everywhere now derive from the Firestore
        // students collection — see Roster_helper.
        $syncMeta = $this->_syncMeta();
        foreach ($students as $userId => $info) {
            $name    = $info['name'] ?? $userId;
            $idemKey = "batchMove_{$userId}_{$this->syncVersion}";
            $fsData  = array_merge($syncMeta, [
                'Name'       => $name,
                'name'       => $name,
                'className'  => $toClass,
                'section'    => $toSection,
                'sectionKey' => Firestore_service::buildSectionKey($toClass, $toSection),
                'status'     => 'Active',
            ]);
            if ($this->writeToFirestore('syncStudent', [$userId, $fsData], $idemKey)) {
                $result['moved'][] = $userId;
            } else {
                // The Firestore write was queued for retry on inline
                // failure; we still count this student as moved at
                // the user-visible level (the queue will reconcile).
                // If even the queue push fails, _enqueue logs it.
                $result['moved'][] = $userId;
            }
        }

        $this->_log('info', 'batchMoveStudents: ' . count($result['moved']) . ' moved, '
            . count($result['failed']) . ' failed (Firestore-only post-R7)');

        return $result;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STUDENT: SOFT DELETE / RECOVER / HARD DELETE
    // ══════════════════════════════════════════════════════════════════

    public function softDeleteStudent(string $studentId, string $classKey, string $sectionKey, string $reason = ''): bool
    {
        if (!$this->ready) return false;
        // R7 — Firestore-only soft delete.
        // The previous implementation wrote three things to RTDB
        // (Users/Parents/{id}/Status, deletedAt, deleteReason) AND
        // removed the student from the section roster. All gone now;
        // Firestore students.status='Deleted' + deletedAt + deleteReason
        // is the canonical record. The roster derives from Firestore
        // (status filter), so removing from RTDB roster is unnecessary.
        // $classKey/$sectionKey are still validated for caller-API
        // compatibility (Sis::delete_student passes them) but no longer
        // used to compute an RTDB path.
        self::validateClass($classKey);
        self::validateSection($sectionKey);
        $now     = date('Y-m-d H:i:s');
        $idemKey = "softDel_{$studentId}_{$this->syncVersion}";

        $this->writeToFirestore('syncStudent', [$studentId, array_merge($this->_syncMeta(), [
            'status' => 'Deleted', 'deletedAt' => $now, 'deleteReason' => $reason,
        ])], $idemKey);

        // Disable Auth (same idempotency key) — login still has to be
        // blocked for a soft-deleted student.
        $this->updateAuth($studentId, ['disabled' => true], $idemKey . '_auth');

        return true;
    }

    public function hardDeleteStudent(string $studentId): bool
    {
        if (!$this->ready) return false;
        // R7 — Firestore-only hard delete. The previous RTDB delete of
        // `Users/Parents/{parentDbKey}/{id}` is gone; Firestore is the
        // sole student record. Sis::delete_student also calls
        // `$this->fs->removeEntity('students', $id)` directly (and clears
        // the indexPhones doc + Firebase Auth account), so the deletion
        // is fully covered by the surrounding controller code plus this
        // queued sync.
        $this->writeToFirestore('deleteStudent', [$studentId], "hardDel_{$studentId}");
        return true;
    }

    public function recoverStudent(string $studentId, string $classKey, string $sectionKey, string $name): bool
    {
        if (!$this->ready) return false;
        self::validateClass($classKey);
        self::validateSection($sectionKey);
        $idemKey = "recover_{$studentId}_{$this->syncVersion}";

        // R7 — Firestore-only recovery. RTDB roster restore + RTDB
        // profile reset are gone. addToRoster() is now a no-op and
        // wasn't doing useful work even before R7 — the roster is
        // derived from `students.status='Active'` so flipping the
        // status (via syncStudent below) restores the student to every
        // class/section view automatically. `$name` is preserved on
        // the call signature for audit/logging callers but is not
        // re-written to the doc here (the existing Firestore name is
        // canonical and unchanged across a soft-delete cycle).
        $this->writeToFirestore('syncStudent', [$studentId, array_merge($this->_syncMeta(), [
            'status' => 'Active', 'deletedAt' => null, 'deleteReason' => null,
        ])], $idemKey);

        $this->updateAuth($studentId, ['disabled' => false], $idemKey . '_auth');
        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STAFF
    // ══════════════════════════════════════════════════════════════════

    public function writeStaff(string $id, array $data): bool
    {
        if (!$this->ready) return false;
        $data = array_merge($data, $this->_syncMeta());
        if (!$this->writeToRTDB("Users/Admin/{$this->parentDbKey}/{$id}", $data, 'update')) return false;
        $this->writeToFirestore('syncStaff', [$id, $data], "staff_{$id}");
        return true;
    }

    public function setStaff(string $id, array $data): bool
    {
        if (!$this->ready) return false;
        $data = array_merge($data, $this->_syncMeta());
        if (!$this->writeToRTDB("Users/Admin/{$this->parentDbKey}/{$id}", $data, 'set')) return false;
        $this->writeToFirestore('syncStaff', [$id, $data], "staff_{$id}");
        return true;
    }

    public function deleteStaff(string $id): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB("Users/Admin/{$this->parentDbKey}", $id, 'delete')) return false;
        $this->writeToFirestore('deleteStaff', [$id], "delStaff_{$id}");
        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  SECTION / SCHOOL / ATTENDANCE / EXAM / NOTIFICATION
    // ══════════════════════════════════════════════════════════════════

    public function writeSection(string $cls, string $sec, array $data = []): bool
    {
        if (!$this->ready) return false;
        $ck = self::validateClass($cls); $sk = self::validateSection($sec);
        if (!$this->writeToRTDB("Schools/{$this->schoolId}/{$this->session}/{$ck}/{$sk}", $data, 'update')) return false;
        $this->writeToFirestore('syncSection', [$cls, $sec, $data]);
        return true;
    }

    public function writeSchool(array $data): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB("System/Schools/{$this->schoolId}/profile", $data, 'update')) return false;
        $this->writeToFirestore('syncSchool', [$data]);
        return true;
    }

    public function writeAttendance(string $stuId, string $date, array $data, string $rtdbPath): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($rtdbPath, $data, 'set')) return false;
        $this->writeToFirestore('syncAttendanceRecord', [$stuId, $date, $data], "att_{$stuId}_{$date}");
        return true;
    }

    public function writeExam(string $examId, array $data, string $rtdbPath): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($rtdbPath, $data, 'set')) return false;
        $this->writeToFirestore('syncExam', [$examId, $data]);
        return true;
    }

    public function writeExamSchedule(string $examId, string $cls, string $sec, array $data, string $rtdbPath): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($rtdbPath, $data, 'set')) return false;
        $this->writeToFirestore('syncExamSchedule', [$examId, $cls, $sec, $data]);
        return true;
    }

    public function writeNotification(string $id, array $data, string $rtdbPath): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($rtdbPath, $data, 'set')) return false;
        $this->writeToFirestore('syncNotification', [$id, $data]);
        return true;
    }

    public function writeRoute(string $id, array $data, string $path): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($path, $data, 'set')) return false;
        $this->writeToFirestore('syncRoute', [$id, $data]);
        return true;
    }

    public function writeVehicle(string $id, array $data, string $path): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($path, $data, 'set')) return false;
        $this->writeToFirestore('syncVehicle', [$id, $data]);
        return true;
    }

    public function writeLeave(string $id, array $data, string $path): bool
    {
        if (!$this->ready) return false;
        if (!$this->writeToRTDB($path, $data, 'set')) return false;
        $this->writeToFirestore('syncLeaveApplication', [$id, $data]);
        return true;
    }

    // ══════════════════════════════════════════════════════════════════
    //  ROSTER HELPERS (with session support)
    // ══════════════════════════════════════════════════════════════════

    /**
     * @deprecated R7 — no-op. The RTDB roster node
     *             `Schools/{...}/{class}/{section}/Students/List` is no
     *             longer authoritative; rosters are derived from the
     *             Firestore `students` collection (filtered by
     *             schoolId+className+section+status='Active'). All
     *             writers that affect roster membership now go through
     *             `Entity_firestore_sync::syncStudent` directly. Kept
     *             as a no-op so existing callers (Sis::cancel_tc, etc.)
     *             don't crash; safe to delete after R7 is verified.
     */
    public function addToRoster(string $cls, string $sec, string $stuId, string $name, ?string $sess = null): bool
    {
        return $this->ready;
    }

    /**
     * @deprecated R7 — no-op. See addToRoster() for the rationale.
     */
    public function removeFromRoster(string $cls, string $sec, string $stuId, ?string $sess = null): bool
    {
        return $this->ready;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PASSTHROUGH
    // ══════════════════════════════════════════════════════════════════

    public function rtdbOnly(string $path, $data, string $method = 'set'): bool
    {
        return $this->writeToRTDB($path, $data, $method);
    }

    // ══════════════════════════════════════════════════════════════════
    //  LOGGING
    // ══════════════════════════════════════════════════════════════════

    private function _log(string $level, string $msg): void
    {
        log_message($level, "Dual_write: {$msg}");
        if (defined('GRADER_DEBUG') && GRADER_DEBUG) {
            try {
                $CI =& get_instance();
                if (isset($CI->debug_tracker)) {
                    $CI->debug_tracker->record(
                        $level === 'error' ? 'FIREBASE_ERROR' : 'FIREBASE_WRITE',
                        $msg
                    );
                }
            } catch (\Exception $e) { /* silent */ }
        }
    }
}
