<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_cache — Phase 6 lightweight caching helper.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  DESIGN
 * ──────────────────────────────────────────────────────────────────────
 *  Two tiers, transparent to the caller:
 *
 *   Tier 1 — REQUEST-LOCAL
 *     Static-array cache scoped to the current PHP request. Hits return
 *     the value with zero I/O. Lives until the request ends.
 *
 *   Tier 2 — APCU (if available)
 *     Shared across PHP requests on the same server. Default TTL 60s.
 *     No-op when apcu is not loaded (XAMPP default does not include it);
 *     caller gets a pure request-local cache without knowing or caring.
 *
 *  All keys are namespaced by `{schoolId}:{session}:{kind}:{subkey}`
 *  so tenants cannot leak into each other even if they share an APCU
 *  instance.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USAGE
 * ──────────────────────────────────────────────────────────────────────
 *     $cache = $this->load->library('Fee_cache', null, 'feeCache');
 *     $this->feeCache->init($schoolId, $session);
 *
 *     // Cache-aside read
 *     $summary = $this->feeCache->remember(
 *         'classSummary:Class_8th:Section_A:April',
 *         function () {
 *             return $this->feeSummaryReader->getClassSummary('Class 8th', 'Section A', 'April');
 *         },
 *         120   // ttlSec
 *     );
 *
 *     // Explicit write / invalidate on receipt submit
 *     $this->feeCache->forget('classSummary:Class_8th:Section_A:April');
 *
 *  Correctness: every summary write path already invalidates nothing
 *  because the cache's short TTL (≤300s) absorbs stale-read risk.
 *  For strictly-consistent reads, caller can bypass by calling the
 *  underlying reader directly.
 */
final class Fee_cache
{
    /** Request-local tier. Cleared at end of request. */
    private static array $local = [];

    /** Default TTL if caller doesn't specify. 60s balances freshness
     *  with hit rate on typical admin browsing patterns. */
    private const DEFAULT_TTL_SEC = 60;

    /** Max allowed TTL. Exceeded values are clamped — prevents a
     *  mistaken ttlSec=86400 from leaving stale data for a day. */
    private const MAX_TTL_SEC = 300;

    private string $prefix = '';
    private bool   $apcu   = false;

    public function __construct() {}

    public function init(string $schoolId, string $session): void
    {
        $this->prefix = "{$schoolId}:{$session}:";
        $this->apcu   = function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Cache-aside fetch. If the key is already cached, returns the
     * cached value. Otherwise calls $producer, stores the result, and
     * returns it. $producer is never called if the cache hits.
     *
     * @param string   $key
     * @param callable $producer  () => mixed   invoked on miss
     * @param int      $ttlSec    clamped to MAX_TTL_SEC
     * @return mixed
     */
    public function remember(string $key, callable $producer, int $ttlSec = self::DEFAULT_TTL_SEC)
    {
        $full = $this->prefix . $key;
        $ttl  = max(1, min(self::MAX_TTL_SEC, $ttlSec));

        // Tier 1 — request-local
        if (array_key_exists($full, self::$local)) {
            return self::$local[$full];
        }

        // Tier 2 — APCU
        if ($this->apcu) {
            $hit = apcu_fetch($full, $ok);
            if ($ok) {
                self::$local[$full] = $hit;
                return $hit;
            }
        }

        // Miss — produce + store
        $value = $producer();
        self::$local[$full] = $value;
        if ($this->apcu) {
            @apcu_store($full, $value, $ttl);
        }
        return $value;
    }

    /**
     * Direct read. Returns null if absent from both tiers. Use this
     * when the caller wants to decide the action on a miss (e.g., log
     * the miss, or return a domain-specific default).
     */
    public function get(string $key)
    {
        $full = $this->prefix . $key;
        if (array_key_exists($full, self::$local)) {
            return self::$local[$full];
        }
        if ($this->apcu) {
            $hit = apcu_fetch($full, $ok);
            if ($ok) { self::$local[$full] = $hit; return $hit; }
        }
        return null;
    }

    public function set(string $key, $value, int $ttlSec = self::DEFAULT_TTL_SEC): void
    {
        $full = $this->prefix . $key;
        $ttl  = max(1, min(self::MAX_TTL_SEC, $ttlSec));
        self::$local[$full] = $value;
        if ($this->apcu) @apcu_store($full, $value, $ttl);
    }

    /** Remove ONE key from both tiers. */
    public function forget(string $key): void
    {
        $full = $this->prefix . $key;
        unset(self::$local[$full]);
        if ($this->apcu) @apcu_delete($full);
    }

    /** Remove every key belonging to this tenant+session. Useful after
     *  a bulk write (e.g., demand generation) to force next reads to
     *  re-pull from source. Request-local only — APCU entries will
     *  age out naturally on their TTL. */
    public function forgetAll(): void
    {
        foreach (array_keys(self::$local) as $k) {
            if (strpos($k, $this->prefix) === 0) unset(self::$local[$k]);
        }
    }
}
