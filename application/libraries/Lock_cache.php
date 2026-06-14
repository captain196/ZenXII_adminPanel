<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Lock_cache — PHP-session-backed read cache for staffAttendanceLocks docs.
 *
 * Phase II Step II.2 deliverable. Pure-PHP scaffolding; no caller yet.
 *
 * Purpose
 * -------
 * Stream B workflows (W5/W6/W2/W3/W4 in future steps) must verify a month
 * is not locked before writing. A live Firestore round-trip per call adds
 * ~200ms per W5/W6 latency. This cache eliminates that round-trip for
 * subsequent calls within an admin's session.
 *
 * Architectural lock
 * ------------------
 *   - Firestore = sole authority for lock state. The cache is a per-admin
 *     OPTIMISATION layer; it never disagrees with Firestore for long.
 *   - On cache backend failure, falls back to a live Firestore read —
 *     NOT to RTDB.
 *   - Two distinct entry points:
 *       is_locked()        — cache-first; OK for write-gate at writer entry
 *       is_locked_live()   — bypass cache; REQUIRED for payroll, month-close,
 *                            and any workflow that must see the most-recent
 *                            lock state.
 *
 * TTL
 * ---
 * 5 minutes. Tunable via TTL_SECONDS constant. Rationale: lock state changes
 * are rare (~1 / month / school), so a 5-min staleness window is acceptable
 * for the write-gate semantics (lock is forward-blocking; in-flight marks
 * during a lock event proceed).
 *
 * Per-session isolation
 * --------------------
 * Session storage is per-admin (CodeIgniter session = per-cookie). Two
 * admins working concurrently see independent cache states. Within one
 * admin's session, all W5/W6/W2/W3/W4 calls share the cache.
 *
 * Invalidation
 * ------------
 * Callers MUST call invalidate() whenever a lock-doc write happens. The
 * lock-management UI (Phase VI) will own this responsibility.
 */
class Lock_cache
{
    const TTL_SECONDS         = 300; // 5 minutes
    const SESSION_KEY_PREFIX  = 'stream_b_lock_cache_';

    /** @var \CI_Controller */
    private $ci;

    public function __construct()
    {
        $this->ci =& get_instance();
        $this->ci->load->library('session');
    }

    /**
     * Cache-first lookup. Returns:
     *   ['is_locked' => bool, 'source' => 'cache'|'live'|'fallback', 'age_ms' => int]
     *
     * Used by Stream B writers as the lock-gate check before commit.
     * NEVER used by payroll or month-close — see is_locked_live().
     */
    public function is_locked(string $schoolId, string $session, string $monthKey): array
    {
        $key = $this->_cache_key($schoolId, $session, $monthKey);
        $cached = $this->_cache_get($key);
        if ($cached !== null && isset($cached['cachedAt'], $cached['is_locked'])) {
            $ageMs = (int) ((microtime(true) - (float) $cached['cachedAt']) * 1000);
            if ($ageMs < self::TTL_SECONDS * 1000) {
                return [
                    'is_locked' => (bool) $cached['is_locked'],
                    'source'    => 'cache',
                    'age_ms'    => $ageMs,
                ];
            }
        }
        // Cache miss / expired / corrupted — live read
        return $this->_live_read_and_cache($schoolId, $session, $monthKey, $key);
    }

    /**
     * Live Firestore lookup. Bypasses cache entirely. REQUIRED for any
     * workflow whose correctness depends on the freshest lock state:
     *   - payroll run gate (Hr.php payroll workflows)
     *   - month-close transition
     *   - audit reporting
     *
     * Verifier A22 asserts callers of these workflows use this method,
     * not is_locked().
     */
    public function is_locked_live(string $schoolId, string $session, string $monthKey): bool
    {
        $r = $this->_live_read($schoolId, $session, $monthKey);
        return $r['is_locked'];
    }

    /**
     * Invalidate cached entry. Called by:
     *   - lock-set workflow (operator marks a month closed)
     *   - lock-unset workflow (operator opens a month)
     * Phase VI lock-management UI will own these invalidation calls.
     */
    public function invalidate(string $schoolId, string $session, string $monthKey): void
    {
        $key = $this->_cache_key($schoolId, $session, $monthKey);
        $this->_cache_clear($key);
    }

    /** Internal: deterministic per-tenant per-month cache key. */
    private function _cache_key(string $schoolId, string $session, string $monthKey): string
    {
        return self::SESSION_KEY_PREFIX . sha1("{$schoolId}|{$session}|{$monthKey}");
    }

    /** Internal: cache read with fail-safe (returns null on any error). */
    private function _cache_get(string $key)
    {
        try {
            $val = $this->ci->session->userdata($key);
            return is_array($val) ? $val : null;
        } catch (\Throwable $e) {
            log_message('warning', 'Lock_cache::_cache_get session read failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Internal: cache write with fail-safe (silent on error). */
    private function _cache_put(string $key, bool $isLocked): void
    {
        try {
            $this->ci->session->set_userdata($key, [
                'is_locked' => $isLocked,
                'cachedAt'  => microtime(true),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Lock_cache::_cache_put session write failed: ' . $e->getMessage());
        }
    }

    /** Internal: cache clear (silent on error). */
    private function _cache_clear(string $key): void
    {
        try {
            $this->ci->session->unset_userdata($key);
        } catch (\Throwable $e) {
            log_message('warning', 'Lock_cache::_cache_clear session unset failed: ' . $e->getMessage());
        }
    }

    /** Internal: live Firestore read, no cache touched. */
    private function _live_read(string $schoolId, string $session, string $monthKey): array
    {
        $docId = "{$schoolId}_{$session}_{$monthKey}";
        $this->ci->load->library('firebase');
        $doc = $this->ci->firebase->firestoreGet('staffAttendanceLocks', $docId);
        $isLocked = is_array($doc) && !empty($doc['isLocked']);
        return ['is_locked' => $isLocked, 'source' => 'live', 'age_ms' => 0];
    }

    /** Internal: live read + populate cache. Used by is_locked() on miss. */
    private function _live_read_and_cache(string $schoolId, string $session, string $monthKey, string $key): array
    {
        try {
            $r = $this->_live_read($schoolId, $session, $monthKey);
            $this->_cache_put($key, $r['is_locked']);
            return $r;
        } catch (\Throwable $e) {
            // Firestore error — fail loud; do NOT consult RTDB or any fallback.
            log_message('error', 'Lock_cache::_live_read_and_cache Firestore failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
