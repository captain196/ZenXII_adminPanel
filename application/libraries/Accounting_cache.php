<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_cache — Phase 8A two-tier cache for accounting-static data.
 *
 * Mirrors Fee_cache's contract:
 *   • Tier 1 — request-local static array (always on, zero config).
 *   • Tier 2 — APCU (opt-in; no-op if the extension is not loaded).
 *
 * Cached reads:
 *   - Chart of Accounts      — changes only when an admin edits CoA (rare).
 *   - Period lock doc        — changes only at period-close / reopen (rare).
 *   - Balance-shard config   — static unless an operator reshards (very rare).
 *
 * Never cached (authoritative every time):
 *   - Closing balances (they change on every journal post).
 *   - Ledger entries.
 *   - Idempotency slots.
 *
 * Usage:
 *     $cache = $this->load->library('Accounting_cache', null, 'acctCache');
 *     $this->acctCache->init($schoolId, $session);
 *
 *     $coa = $this->acctCache->remember('coa', 300, function () use ($firebase) {
 *         return $firebase->firestoreQuery('chartOfAccounts', [
 *             ['schoolId', '==', $schoolId]
 *         ]);
 *     });
 *
 *     // After admin edits CoA:
 *     $this->acctCache->forget('coa');
 *
 *  Correctness:
 *   - Cache is scoped by schoolId+session — no cross-tenant leak.
 *   - APCU TTL is clamped to MAX_TTL_SEC to bound stale-read window.
 *   - `forget()` is called immediately on any write that invalidates
 *     the cached data (CoA edit, period lock change, shard config change).
 */
final class Accounting_cache
{
    /** Request-local tier. Cleared when PHP process ends. */
    private static array $local = [];

    private const DEFAULT_TTL_SEC = 300;  // 5 min
    private const MAX_TTL_SEC     = 900;  // 15 min hard ceiling

    private string $prefix = '';
    private bool   $apcu   = false;

    public function __construct() {}

    public function init(string $schoolId, string $session): void
    {
        $this->prefix = "acct:{$schoolId}:{$session}:";
        $this->apcu   = function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Cache-aside fetch. If cached, returns the cached value; otherwise
     * calls $producer, stores the result, and returns it. The producer
     * is never called on a hit.
     */
    public function remember(string $key, int $ttlSec, callable $producer)
    {
        $full = $this->prefix . $key;
        $ttl  = max(1, min(self::MAX_TTL_SEC, $ttlSec));

        if (array_key_exists($full, self::$local)) {
            return self::$local[$full];
        }
        if ($this->apcu) {
            $hit = apcu_fetch($full, $ok);
            if ($ok) {
                self::$local[$full] = $hit;
                return $hit;
            }
        }
        $value = $producer();
        self::$local[$full] = $value;
        if ($this->apcu) {
            @apcu_store($full, $value, $ttl);
        }
        return $value;
    }

    /** Direct read. Returns null if absent from BOTH tiers. */
    public function get(string $key)
    {
        $full = $this->prefix . $key;
        if (array_key_exists($full, self::$local)) return self::$local[$full];
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

    /** Invalidate ONE key. Call from every write-path that mutates the
     *  cached data (CoA edit, period lock, shard config). */
    public function forget(string $key): void
    {
        $full = $this->prefix . $key;
        unset(self::$local[$full]);
        if ($this->apcu) @apcu_delete($full);
    }

    /** Invalidate every accounting-cache key for this tenant+session.
     *  Used by catastrophic-change endpoints (year rollover, etc). */
    public function forgetAll(): void
    {
        foreach (array_keys(self::$local) as $k) {
            if (strpos($k, $this->prefix) === 0) unset(self::$local[$k]);
        }
        // APCU entries age out naturally on their TTL. A caller that
        // truly needs a synchronous cross-request flush must call
        // apcu_delete() per key explicitly (rare — and covered by
        // targeted forget() calls at the write site).
    }
}
