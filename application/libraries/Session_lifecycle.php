<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Session_lifecycle — canonical session-list mutation library.
 *
 * SC-Step9 (Session Convergence — 2026-06-02): extracted per operator-locked
 * EX-C(b) decision so that callers OUTSIDE School_config (currently:
 * Sis::execute_promotion) can route session-list additions through the
 * SAME lock + Firestore __updateTime CAS contract that School_config's
 * canonical writers use, without inter-controller coupling.
 *
 * Scope (minimal per D2=A):
 *   - add_session(): idempotently append a YYYY-YY session to
 *                    schools/{id}.sessions[] with lock + CAS
 *
 * NOT in scope (intentionally — YAGNI):
 *   - set_active_session  (sole caller is School_config controller)
 *   - delete_session / archive_session / rollover_session (same)
 *
 * Cross-controller serialization is preserved via the SHARED filesystem
 * lock path application/cache/school_config_locks/{schoolId}__sessions.lock
 * — OS flock contention against School_config::_config_lock_acquire
 * happens automatically because both code paths point at the same .lock
 * file on disk.
 *
 * Caller usage example (Sis::execute_promotion):
 *   $this->load->library('Session_lifecycle', null, 'session_lifecycle');
 *   $r = $this->session_lifecycle->add_session(
 *       $this->school_id, $toSession, $this->admin_id, 'promotion'
 *   );
 *   if (!$r['success']) {
 *       return $this->json_error("could not add session: " . $r['error']);
 *   }
 *   $this->session->set_userdata('available_sessions', $r['sessions']);
 *
 * NOT a general-purpose cache — intentionally tiny surface area, matching
 * the convergence-final architectural goal of one canonical writer per field.
 */
class Session_lifecycle
{
    /** @var object Firebase library (REST client wrapper) */
    private $firebase;

    public function __construct()
    {
        $CI = &get_instance();
        // Library is loaded by callers that already have firebase loaded;
        // pull from CI superobject. Don't require init() — caller passes
        // schoolId per-call so this library is stateless across requests.
        $this->firebase = $CI->firebase ?? null;
    }

    /**
     * Add a session to schools/{schoolId}.sessions[] with lock + CAS.
     * Idempotent — returns success if already present (no double-write).
     *
     * Mirrors School_config::add_session lock+CAS pattern exactly. The
     * shared lock path ensures cross-controller serialization with
     * School_config's other lifecycle writers (delete/archive/rollover/
     * sync/set_active) and with itself (concurrent promotion + add).
     *
     * @param string $schoolId Firestore schools doc id (SCH_<10HEX>)
     * @param string $session  YYYY-YY format. Validated; format errors
     *                          return success=false / error=format_invalid.
     * @param string $actor    Audit trail actor (e.g., admin_id 'SSA0001')
     * @param string $source   Audit trail source — short identifier of the
     *                          caller (e.g., 'promotion', 'sync_sessions')
     * @return array {
     *   'success'    => bool,
     *   'sessions'   => array (full post-write sessions[] list on success),
     *   'idempotent' => bool (true if session was already present),
     *   'error'      => string ('format_invalid' | 'lock_timeout' |
     *                            'cas_failed' | 'fs_error' | 'not_ready')
     * }
     */
    public function add_session(string $schoolId, string $session, string $actor, string $source): array
    {
        if ($this->firebase === null) {
            log_message('error',
                "SC-Step9: Session_lifecycle::add_session called before firebase library available");
            return ['success' => false, 'sessions' => [], 'idempotent' => false, 'error' => 'not_ready'];
        }
        if ($schoolId === '' || !preg_match('/^\d{4}-\d{2}$/', $session)) {
            return ['success' => false, 'sessions' => [], 'idempotent' => false, 'error' => 'format_invalid'];
        }

        $lock = $this->_config_lock_acquire($schoolId, 'sessions');
        if ($lock === null) {
            log_message('error',
                "ACC_CONCURRENT_REJECTED endpoint=Session_lifecycle/add_session "
                . "schoolId={$schoolId} actor={$actor} source={$source} "
                . "reason=lock_timeout session={$session}");
            return ['success' => false, 'sessions' => [], 'idempotent' => false, 'error' => 'lock_timeout'];
        }
        try {
            // Re-read INSIDE the lock for freshest sessions[] + __updateTime
            $fsSchool = $this->firebase->firestoreGet('schools', $schoolId);
            if (!is_array($fsSchool)) $fsSchool = [];
            $sessions = (is_array($fsSchool['sessions'] ?? null))
                ? array_values(array_filter($fsSchool['sessions'], 'is_string'))
                : [];
            $updateTime = (string) ($fsSchool['__updateTime'] ?? '');

            // Idempotent re-add — already present
            if (in_array($session, $sessions, true)) {
                log_message('error',
                    "ACC_SESSION_ADD_IDEMPOTENT_LIB schoolId={$schoolId} session={$session} "
                    . "actor={$actor} source={$source}");
                return [
                    'success'    => true,
                    'sessions'   => $sessions,
                    'idempotent' => true,
                    'error'      => '',
                ];
            }

            $sessions[] = $session;
            sort($sessions);

            $ops = [[
                'op'         => 'update',
                'collection' => 'schools',
                'docId'      => $schoolId,
                // Mirror School_config::add_session DOC-MERGE-FIX semantics:
                // emit updateMask so only `sessions` + `updatedAt` are touched.
                'merge'      => true,
                'data'       => [
                    'sessions'  => $sessions,
                    'updatedAt' => date('c'),
                ],
            ]];
            if ($updateTime !== '') {
                $ops[0]['precondition'] = ['updateTime' => $updateTime];
            }
            $committed = false;
            try {
                $committed = (bool) $this->firebase->firestoreCommitBatch($ops);
            } catch (\Throwable $e) {
                log_message('error',
                    "ACC_SESSION_ADD_LIB_COMMIT_FAILED schoolId={$schoolId} "
                    . "session={$session} actor={$actor} source={$source} "
                    . "err=" . $e->getMessage());
                return ['success' => false, 'sessions' => [], 'idempotent' => false, 'error' => 'fs_error'];
            }
            if (!$committed) {
                log_message('error',
                    "ACC_CONCURRENT_REJECTED endpoint=Session_lifecycle/add_session "
                    . "schoolId={$schoolId} actor={$actor} source={$source} "
                    . "reason=cas_failed session={$session}");
                return ['success' => false, 'sessions' => [], 'idempotent' => false, 'error' => 'cas_failed'];
            }

            log_message('error',
                "ACC_SESSION_ADDED_LIB schoolId={$schoolId} session={$session} "
                . "actor={$actor} source={$source} total=" . count($sessions));

            return [
                'success'    => true,
                'sessions'   => $sessions,
                'idempotent' => false,
                'error'      => '',
            ];
        } finally {
            $this->_config_lock_release($lock);
        }
    }

    /**
     * Filesystem-lock helper. PATH-IDENTICAL to School_config::
     * _config_lock_acquire (School_config.php L320-343) so cross-controller
     * calls serialize at the OS flock level. When Step 9 ships, the
     * library + School_config share the same .lock file path.
     */
    private function _config_lock_acquire(string $schoolId, string $lockName, int $timeoutMs = 2000)
    {
        $dir = APPPATH . 'cache/school_config_locks/';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $schoolId . '__' . $lockName);
        $path = $dir . $safe . '.lock';
        $fh = @fopen($path, 'c+');
        if (!$fh) return null;
        $deadline = microtime(true) + ($timeoutMs / 1000.0);
        do {
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                @ftruncate($fh, 0);
                @fwrite($fh, json_encode([
                    'acquired_at' => date('c'),
                    'library'     => 'Session_lifecycle',
                    'pid'         => function_exists('getmypid') ? getmypid() : 0,
                ]));
                return $fh;
            }
            usleep(50000); // 50 ms
        } while (microtime(true) < $deadline);
        @fclose($fh);
        return null;
    }

    private function _config_lock_release($fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }
}
