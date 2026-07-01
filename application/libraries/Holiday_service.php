<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Holiday_service — the single, reusable Firestore abstraction over the
 * canonical academic calendar (`calendarEvents`, `type == 'holiday'`).
 *
 * Purpose
 * -------
 * One place — and only one — answers "is date X a holiday for this school
 * in this session?". Attendance, HR, Leave, Examination, Timetable, Payroll,
 * and future modules should depend on THIS service instead of each writing
 * their own calendarEvents query. It also normalises the known field drift
 * (`type` vs `category`, `startDate` vs `start_date`) in a single location.
 *
 * Architecture
 * ------------
 *   - FIRESTORE ONLY. Reads `calendarEvents` via the school-scoped
 *     Firestore_service ($fs). ZERO RTDB. ZERO writes.
 *   - Canonical writer of `calendarEvents` remains Calendar_service /
 *     Academic.php — this service is read-only.
 *   - Two-tier caching keeps per-call cost near zero:
 *       1. per-request memo (instance array)
 *       2. optional short-TTL file cache (survives across HTTP requests),
 *          mirroring the Firebase token-cache pattern.
 *     A holiday set rarely changes intra-day, so a small TTL is safe; a
 *     missed just-added holiday self-heals within one TTL window and only
 *     ever fails OPEN to "not a holiday" (never blocks a legitimate punch).
 *
 * NOTE on the legacy RTDB holiday store
 * -------------------------------------
 *   Three legacy readers still read holidays from RTDB
 *   (`Schools/{name}/Config/Attendance/holidays`):
 *     - Attendance::_is_holiday() / _get_holidays_for_month()
 *     - attendance_helper::get_non_working_days()
 *   This service is their Firestore-native replacement. Migrating those
 *   shared readers is a separate, approval-gated phase (see the Phase 3
 *   RTDB audit) — this service does NOT modify them.
 */
class Holiday_service
{
    /** @var object Firestore_service (school-scoped) — injected, never created here. */
    private $fs;
    private string $schoolId = '';
    private string $session  = '';
    private int    $ttl      = 300;       // file-cache TTL seconds; <=0 disables file cache
    private bool   $ready    = false;

    /** Per-request memo: [dateISO => holidayName]. null until first load. */
    private $memo = null;

    /** Max calendarEvents updatedAt across holiday docs (ISO-8601). null until load. */
    private $_lastUpdated = null;

    /**
     * @param object $fs       a Firestore_service already init()'d to the school
     * @param string $schoolId SCH_XXXXXX (used for the cache key)
     * @param string $session  active session (used for the cache key)
     * @param int    $ttl      file-cache TTL in seconds (default 300; 0 = memo only)
     */
    public function init($fs, string $schoolId, string $session, int $ttl = 300): self
    {
        if (!is_object($fs) || $schoolId === '') {
            throw new \InvalidArgumentException('Holiday_service: $fs and schoolId are required');
        }
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->ttl      = $ttl;
        $this->ready    = true;
        $this->memo     = null;
        $this->_lastUpdated = null;
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Is the given ISO date (YYYY-MM-DD) a holiday for this school?
     *
     * @return bool false for malformed dates or when the service is uninitialised
     *              (fails OPEN — never fabricates a holiday).
     */
    public function is_holiday(string $dateISO): bool
    {
        if (!$this->ready) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateISO)) return false;
        $map = $this->_load();
        return isset($map[$dateISO]);
    }

    /**
     * All holidays between two ISO dates, inclusive.
     * @return array [dateISO => name]
     */
    public function holidays_in_range(string $fromISO, string $toISO): array
    {
        if (!$this->ready) return [];
        $map = $this->_load();
        if (empty($map)) return [];
        $out = [];
        foreach ($map as $d => $name) {
            if ($d >= $fromISO && $d <= $toISO) $out[$d] = $name;
        }
        ksort($out);
        return $out;
    }

    /**
     * Holidays in a given month, keyed by day-of-month (int) — the shape the
     * attendance register grid consumes (replacement for the RTDB-backed
     * attendance_helper::get_non_working_days()).
     *
     * @return array [int dayOfMonth => string name]
     */
    public function holidays_in_month(int $year, int $month): array
    {
        if (!$this->ready || $month < 1 || $month > 12) return [];
        $prefix = sprintf('%04d-%02d-', $year, $month);
        $out = [];
        foreach ($this->_load() as $d => $name) {
            if (strpos($d, $prefix) === 0) {
                $out[(int) substr($d, 8, 2)] = $name;
            }
        }
        return $out;
    }

    /**
     * The full cached holiday map for this school/session. [dateISO => name]
     */
    public function all_holiday_dates(): array
    {
        return $this->ready ? $this->_load() : [];
    }

    /**
     * Invalidate caches (call after an admin adds/removes a holiday so the
     * next read is fresh rather than waiting out the TTL).
     */
    public function flush(): void
    {
        $this->memo = null;
        $file = $this->_cache_file();
        if ($file !== null && file_exists($file)) {
            @unlink($file);
        }
    }

    /* ─── internal: load + normalise + cache ─────────────────────────── */

    /**
     * Most-recent calendarEvents `updatedAt` across holiday docs (ISO-8601),
     * or null if none/unknown. Read THROUGH this canonical reader only — used
     * by the read-only Holiday Status page. Loads (and caches) on demand.
     */
    public function last_updated_at(): ?string
    {
        if (!$this->ready) return null;
        $this->_load();
        return $this->_lastUpdated;
    }

    /**
     * Resolve the holiday map: memo → file cache → Firestore.
     * @return array [dateISO => name]
     */
    private function _load(): array
    {
        if (is_array($this->memo)) {
            return $this->memo;
        }

        // Tier 2: file cache (cross-request), only when TTL > 0.
        $file = ($this->ttl > 0) ? $this->_cache_file() : null;
        if ($file !== null && file_exists($file)) {
            $cached = @json_decode(@file_get_contents($file), true);
            if (is_array($cached) && isset($cached['exp'], $cached['holidays'])
                && $cached['exp'] > time() && is_array($cached['holidays'])) {
                $this->memo = $cached['holidays'];
                $this->_lastUpdated = $cached['lastUpdated'] ?? null;
                return $this->memo;
            }
            @unlink($file); // expired/corrupt
        }

        // Tier 3: Firestore — the ONE place that queries calendarEvents.
        $map = $this->_query_firestore();
        $this->memo = $map;

        if ($file !== null) {
            $dir = dirname($file);
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            @file_put_contents($file, json_encode([
                'exp'         => time() + $this->ttl,
                'holidays'    => $map,
                'lastUpdated' => $this->_lastUpdated,
            ]), LOCK_EX);
        }
        return $map;
    }

    /**
     * Read holiday events from Firestore and expand each into per-day entries,
     * normalising the type/category + startDate/start_date drift here so no
     * consumer ever has to. Best-effort: any failure yields an empty map
     * (fails open to "no holidays"), never throws into the caller's flow.
     *
     * @return array [dateISO => name]
     */
    private function _query_firestore(): array
    {
        $out = [];
        $maxUpd = '';
        try {
            // schoolWhere auto-injects schoolId == this school. We pull all
            // calendar events and filter holiday in PHP so that docs carrying
            // only the legacy `category` field (no `type`) are not missed.
            $rows = $this->fs->schoolWhere('calendarEvents', []);
            foreach ((array) $rows as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                if (empty($d)) continue;

                $kind = strtolower((string) ($d['type'] ?? $d['category'] ?? ''));
                if ($kind !== 'holiday') continue;
                if (strtolower((string) ($d['status'] ?? '')) === 'cancelled') continue;

                $start = (string) ($d['startDate'] ?? $d['start_date'] ?? '');
                if ($start === '') continue;
                $end   = (string) ($d['endDate'] ?? $d['end_date'] ?? $start);
                $name  = (string) ($d['title'] ?? $d['name'] ?? 'Holiday');

                $upd = (string) ($d['updatedAt'] ?? $d['updated_at'] ?? '');
                if ($upd !== '' && $upd > $maxUpd) $maxUpd = $upd;

                $this->_expand_range($start, $end, $name, $out);
            }
        } catch (\Throwable $e) {
            // Fail open — a holiday-read failure must never break a punch.
            // Guard log_message so the fail-open path can't itself throw.
            if (function_exists('log_message')) {
                log_message('error', 'Holiday_service::_query_firestore failed: ' . $e->getMessage());
            }
            $this->_lastUpdated = null;
            return [];
        }
        $this->_lastUpdated = ($maxUpd !== '') ? $maxUpd : null;
        return $out;
    }

    /** Expand a [start..end] inclusive date range into $out[dateISO] = name. */
    private function _expand_range(string $start, string $end, string $name, array &$out): void
    {
        try {
            $cur = new DateTime($start);
            $lim = new DateTime($end >= $start ? $end : $start);
            $guard = 0;
            while ($cur <= $lim && $guard++ < 370) {
                $out[$cur->format('Y-m-d')] = $name;
                $cur->modify('+1 day');
            }
        } catch (\Throwable $e) {
            // Unparseable date — record the raw start only, defensively.
            $out[$start] = $name;
        }
    }

    /** Absolute path of the file-cache entry, or null when no cache dir is resolvable. */
    private function _cache_file(): ?string
    {
        $dir = defined('APPPATH') ? (APPPATH . 'cache/holidays') : (sys_get_temp_dir() . '/zenx_holidays');
        $key = sha1($this->schoolId . '|' . $this->session);
        return $dir . '/hol_' . $key . '.json';
    }
}
