<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Minimal per-school dashboard cache.
 *
 * Uses APCu when available (in-memory, microsecond reads). Falls back to
 * file cache under application/cache/dashboard/ when APCu isn't installed.
 *
 * Safe defaults:
 *   - TTL is always bounded (no "infinite" cache).
 *   - get() returns null on cache miss; callers ALWAYS regenerate on null.
 *   - invalidate() nukes a single key; no bulk helpers (keep blast radius tight).
 *
 * Keys are namespaced per schoolId so multi-tenant writes never leak across
 * schools.
 *
 * NOT a general-purpose cache — intentionally tiny surface area.
 */
class Dashboard_cache
{
    private string $prefix = 'grader_dash_v1_';
    private bool   $apcuOk;
    private string $fileDir;

    public function __construct()
    {
        $this->apcuOk  = function_exists('apcu_fetch') && ini_get('apc.enabled');
        $this->fileDir = APPPATH . 'cache/dashboard';
        if (!$this->apcuOk && !is_dir($this->fileDir)) {
            @mkdir($this->fileDir, 0755, true);
        }
    }

    private function k(string $schoolId, string $name): string
    {
        return $this->prefix . preg_replace('/[^A-Za-z0-9_]/', '_', $schoolId . '_' . $name);
    }

    /**
     * Return cached value or null on miss / expiry.
     * Optional $ageOut receives the cache entry age in seconds (or null on
     * miss / when unavailable — e.g., legacy files written before writtenAt
     * was stored).
     */
    public function get(string $schoolId, string $name, ?int &$ageOut = null)
    {
        $ageOut = null;
        $key = $this->k($schoolId, $name);
        if ($this->apcuOk) {
            $ok = false;
            $env = apcu_fetch($key, $ok);
            if (!$ok) return null;
            if (is_array($env) && array_key_exists('val', $env)) {
                if (isset($env['writtenAt'])) $ageOut = time() - (int) $env['writtenAt'];
                return $env['val'];
            }
            return $env;
        }
        $f = $this->fileDir . '/' . $key . '.json';
        if (!is_file($f)) return null;
        $raw = @file_get_contents($f);
        if ($raw === false) return null;
        $env = json_decode($raw, true);
        if (!is_array($env) || !isset($env['exp'], $env['val'])) return null;
        if ($env['exp'] < time()) { @unlink($f); return null; }
        if (isset($env['writtenAt'])) $ageOut = time() - (int) $env['writtenAt'];
        return $env['val'];
    }

    /**
     * Store a value for $ttl seconds. Silently drops if serialisation fails.
     */
    public function set(string $schoolId, string $name, $value, int $ttl = 30): void
    {
        $key = $this->k($schoolId, $name);
        $now = time();
        $env = ['exp' => $now + max(1, $ttl), 'writtenAt' => $now, 'val' => $value];
        if ($this->apcuOk) {
            @apcu_store($key, $env, max(1, $ttl));
            return;
        }
        $json = json_encode($env);
        if ($json === false) return;
        @file_put_contents($this->fileDir . '/' . $key . '.json', $json, LOCK_EX);
    }

    public function invalidate(string $schoolId, string $name): void
    {
        $key = $this->k($schoolId, $name);
        if ($this->apcuOk) { @apcu_delete($key); return; }
        @unlink($this->fileDir . '/' . $key . '.json');
    }
}
