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

    /** Cache format version — bump whenever the cached shape changes, so an
     *  old-format file on disk is treated as a miss (safe, self-healing)
     *  rather than misread. Phase D (2026-08-18) moved from a flat
     *  [date=>name] map to per-day ENTRY LISTS carrying applicability, so
     *  audience/department/exceptStaff filtering can happen per caller
     *  instead of being baked into one shared map. */
    private const CACHE_VERSION = 2;

    /**
     * Per-request memo, one array per calendarEvents type this service
     * serves: [dateISO => [ {name, appliesTo, departments, exceptStaff}, ... ]].
     * Multiple entries per date are possible (e.g. two department-scoped
     * holidays landing on the same date) — filtering picks whichever
     * entries match the caller's audience/department/staffId. null until
     * first load.
     */
    private $memo = null;         // holiday entries
    private $memoSwd = null;      // special_working_day entries
    private $memoHalfDay = null;  // half_day entries

    /** Max calendarEvents updatedAt across holiday docs (ISO-8601). null until load. */
    private $_lastUpdated = null;

    /** Whether the most recent holiday-map resolve SUCCEEDED (false = Firestore
     *  read failed, as opposed to a genuinely empty calendar). null until first
     *  load. Lets fail-closed callers (the payroll finaliser) avoid baking a real
     *  holiday into payroll as Absent on a transient read failure. */
    private ?bool $_loadedOk = null;

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
        $this->memo         = null;
        $this->memoSwd      = null;
        $this->memoHalfDay  = null;
        $this->_lastUpdated = null;
        $this->_loadedOk    = null;
        return $this;
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Is the given ISO date (YYYY-MM-DD) a holiday for this school, for the
     * given audience?
     *
     * @param string $audience   'staff' (default, preserves every pre-Phase-D
     *                           call site's behavior unchanged) or 'students'.
     * @param string $department Staff-only. '' = ignore department scoping.
     * @param string $staffId    Staff-only. '' = ignore individual exceptions.
     * @return bool false for malformed dates or when the service is uninitialised
     *              (fails OPEN — never fabricates a holiday).
     */
    public function is_holiday(string $dateISO, string $audience = 'staff', string $department = '', string $staffId = ''): bool
    {
        if (!$this->ready) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateISO)) return false;
        return $this->_matches_on($this->_load(), $dateISO, $audience, $department, $staffId) !== null;
    }

    /** Is the given ISO date a declared Special Working Day for this audience? Same filtering rules as is_holiday(). */
    public function is_special_working_day(string $dateISO, string $audience = 'staff', string $department = '', string $staffId = ''): bool
    {
        if (!$this->ready) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateISO)) return false;
        return $this->_matches_on($this->_load_swd(), $dateISO, $audience, $department, $staffId) !== null;
    }

    /** Is the given ISO date a declared Half Day for this audience? Same filtering rules as is_holiday(). */
    public function is_half_day(string $dateISO, string $audience = 'staff', string $department = '', string $staffId = ''): bool
    {
        if (!$this->ready) return false;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateISO)) return false;
        return $this->_matches_on($this->_load_half_day(), $dateISO, $audience, $department, $staffId) !== null;
    }

    /**
     * Did the most recent holiday-map resolve SUCCEED? Returns false only when the
     * Firestore read actually FAILED — a genuinely empty calendar returns true.
     * Fail-closed callers (the payroll finaliser) use this to avoid persisting a
     * real holiday as Absent on a transient read failure. Triggers a load if needed.
     */
    public function load_ok(): bool
    {
        if (!$this->ready) return false;
        $this->_load();
        return $this->_loadedOk === true;
    }

    /**
     * All holidays between two ISO dates, inclusive, filtered for one audience.
     * @return array [dateISO => name]
     */
    public function holidays_in_range(string $fromISO, string $toISO, string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        return $this->_in_range($this->_load(), $fromISO, $toISO, $audience, $department, $staffId);
    }

    /** Special Working Days between two ISO dates, inclusive, filtered for one audience. @return array [dateISO => name] */
    public function special_working_days_in_range(string $fromISO, string $toISO, string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        return $this->_in_range($this->_load_swd(), $fromISO, $toISO, $audience, $department, $staffId);
    }

    /** Half Days between two ISO dates, inclusive, filtered for one audience. @return array [dateISO => name] */
    public function half_days_in_range(string $fromISO, string $toISO, string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        return $this->_in_range($this->_load_half_day(), $fromISO, $toISO, $audience, $department, $staffId);
    }

    /**
     * Holidays in a given month, keyed by day-of-month (int), filtered for one
     * audience — the shape the attendance register grid consumes (replacement
     * for the RTDB-backed attendance_helper::get_non_working_days()).
     *
     * @return array [int dayOfMonth => string name]
     */
    public function holidays_in_month(int $year, int $month, string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        return $this->_in_month($this->_load(), $year, $month, $audience, $department, $staffId);
    }

    /** Special Working Days in a given month, keyed by day-of-month (int), filtered for one audience. */
    public function special_working_days_in_month(int $year, int $month, string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        return $this->_in_month($this->_load_swd(), $year, $month, $audience, $department, $staffId);
    }

    /** Half Days in a given month, keyed by day-of-month (int), filtered for one audience. */
    public function half_days_in_month(int $year, int $month, string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        return $this->_in_month($this->_load_half_day(), $year, $month, $audience, $department, $staffId);
    }

    /**
     * The full cached holiday map for this school/session, filtered for one
     * audience. [dateISO => name]
     */
    public function all_holiday_dates(string $audience = 'staff', string $department = '', string $staffId = ''): array
    {
        if (!$this->ready) return [];
        return $this->_filter_map($this->_load(), $audience, $department, $staffId);
    }

    /**
     * Invalidate caches (call after an admin adds/removes a calendar event so
     * the next read is fresh rather than waiting out the TTL).
     */
    public function flush(): void
    {
        $this->memo        = null;
        $this->memoSwd     = null;
        $this->memoHalfDay = null;
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
     * Does this date's entry list contain one matching the given audience/
     * department/staffId? Returns the matching entry's name, or null.
     *
     * Filtering rules (Phase D):
     *   - appliesTo empty/absent on the event → applies to every audience.
     *   - departments/exceptStaff are STAFF-ONLY concepts — never consulted
     *     for audience='students', so a students-scoped event is never
     *     accidentally narrowed by a department filter that was never meant
     *     to apply to it.
     *   - departments empty/absent → applies to every department.
     *   - exceptStaff containing this staffId → this event does NOT apply,
     *     regardless of how well audience/department otherwise match. Same
     *     field, same meaning, on every event type: "who this event does
     *     NOT apply to" — an essential-staff opt-in on a Holiday, or a
     *     stay-off exception on a Special Working Day, use the identical
     *     mechanism.
     */
    private function _matches_on(array $map, string $dateISO, string $audience, string $department, string $staffId): ?string
    {
        $entries = $map[$dateISO] ?? [];
        foreach ($entries as $e) {
            $appliesTo = (array) ($e['appliesTo'] ?? []);
            if (!empty($appliesTo) && !in_array($audience, $appliesTo, true)) continue;

            if ($audience === 'staff') {
                $departments = (array) ($e['departments'] ?? []);
                if (!empty($departments) && ($department === '' || !in_array($department, $departments, true))) continue;

                $exceptStaff = (array) ($e['exceptStaff'] ?? []);
                if ($staffId !== '' && in_array($staffId, $exceptStaff, true)) continue;
            }

            return (string) ($e['name'] ?? '');
        }
        return null;
    }

    /** Filter a whole [dateISO => entries] map down to [dateISO => name] for one audience. */
    private function _filter_map(array $map, string $audience, string $department, string $staffId): array
    {
        $out = [];
        foreach (array_keys($map) as $d) {
            $name = $this->_matches_on($map, $d, $audience, $department, $staffId);
            if ($name !== null) $out[$d] = $name;
        }
        ksort($out);
        return $out;
    }

    private function _in_range(array $map, string $fromISO, string $toISO, string $audience, string $department, string $staffId): array
    {
        if (!$this->ready) return [];
        $out = [];
        foreach ($map as $d => $entries) {
            if ($d < $fromISO || $d > $toISO) continue;
            $name = $this->_matches_on($map, $d, $audience, $department, $staffId);
            if ($name !== null) $out[$d] = $name;
        }
        ksort($out);
        return $out;
    }

    private function _in_month(array $map, int $year, int $month, string $audience, string $department, string $staffId): array
    {
        if (!$this->ready || $month < 1 || $month > 12) return [];
        $prefix = sprintf('%04d-%02d-', $year, $month);
        $out = [];
        foreach ($map as $d => $entries) {
            if (strpos($d, $prefix) !== 0) continue;
            $name = $this->_matches_on($map, $d, $audience, $department, $staffId);
            if ($name !== null) $out[(int) substr($d, 8, 2)] = $name;
        }
        return $out;
    }

    /** Resolve the holiday entry map: memo → file cache → Firestore. */
    private function _load(): array
    {
        if (is_array($this->memo)) return $this->memo;
        $this->_load_all_types();
        return $this->memo ?? [];
    }

    /** Resolve the special_working_day entry map: memo → file cache → Firestore. */
    private function _load_swd(): array
    {
        if (is_array($this->memoSwd)) return $this->memoSwd;
        $this->_load_all_types();
        return $this->memoSwd ?? [];
    }

    /** Resolve the half_day entry map: memo → file cache → Firestore. */
    private function _load_half_day(): array
    {
        if (is_array($this->memoHalfDay)) return $this->memoHalfDay;
        $this->_load_all_types();
        return $this->memoHalfDay ?? [];
    }

    /**
     * Load all three calendar-fact types in ONE Firestore pass (they all
     * live in the same `calendarEvents` collection) and populate all three
     * memos together, so calling is_holiday() then is_special_working_day()
     * for the same request never triggers a second query.
     */
    private function _load_all_types(): void
    {
        if (is_array($this->memo) && is_array($this->memoSwd) && is_array($this->memoHalfDay)) return;

        // Tier 2: file cache (cross-request), only when TTL > 0.
        $file = ($this->ttl > 0) ? $this->_cache_file() : null;
        if ($file !== null && file_exists($file)) {
            $cached = @json_decode(@file_get_contents($file), true);
            if (is_array($cached) && ($cached['v'] ?? 0) === self::CACHE_VERSION
                && isset($cached['exp'], $cached['holidays'], $cached['swd'], $cached['halfDay'])
                && $cached['exp'] > time()
                && is_array($cached['holidays']) && is_array($cached['swd']) && is_array($cached['halfDay'])) {
                $this->memo        = $cached['holidays'];
                $this->memoSwd     = $cached['swd'];
                $this->memoHalfDay = $cached['halfDay'];
                $this->_lastUpdated = $cached['lastUpdated'] ?? null;
                $this->_loadedOk = true;
                return;
            }
            @unlink($file); // expired/corrupt/stale-version
        }

        // Tier 3: Firestore — the ONE place that queries calendarEvents.
        [$holidays, $swd, $halfDay] = $this->_query_firestore();
        $this->memo        = $holidays;
        $this->memoSwd      = $swd;
        $this->memoHalfDay  = $halfDay;

        if ($file !== null && $this->_loadedOk === true) {
            $dir = dirname($file);
            if (!is_dir($dir)) @mkdir($dir, 0700, true);
            @file_put_contents($file, json_encode([
                'v'           => self::CACHE_VERSION,
                'exp'         => time() + $this->ttl,
                'holidays'    => $holidays,
                'swd'         => $swd,
                'halfDay'     => $halfDay,
                'lastUpdated' => $this->_lastUpdated,
            ]), LOCK_EX);
        }
    }

    /**
     * Read every relevant calendarEvents doc from Firestore ONCE and expand
     * each into per-day entries carrying its applicability metadata,
     * normalising the type/category + startDate/start_date drift here so no
     * consumer ever has to. Best-effort: any failure yields empty maps
     * (fails open to "nothing declared"), never throws into the caller's flow.
     *
     * @return array{0: array, 1: array, 2: array} [holidayMap, swdMap, halfDayMap],
     *         each [dateISO => [ {name, appliesTo, departments, exceptStaff}, ... ]]
     */
    private function _query_firestore(): array
    {
        $holidays = []; $swd = []; $halfDay = [];
        $maxUpd = '';
        try {
            // schoolWhere auto-injects schoolId == this school. We pull all
            // calendar events and filter kind in PHP so that docs carrying
            // only the legacy `category` field (no `type`) are not missed.
            // ACAD-D17: this read decides whether a day is a HOLIDAY or a WORKING
            // day, and it is read negatively — a date absent from the map is a
            // working day. schoolWhere() returns [] for a missing index, a network
            // failure, a 403 and a 429 alike, so every holiday silently vanished
            // and the catch below never fired.
            //
            // That defeated $_loadedOk, and with it load_ok(), whose entire reason
            // for existing is to let Staff_attendance_finaliser fail closed:
            //
            //     if (!$this->ci->holiday_service->load_ok()) { ...skip run... }
            //
            // load_ok() returned TRUE on a failed read, the finaliser proceeded
            // against an empty calendar, and a real holiday was auto-marked Absent
            // and — in that guard's own words — "baked into payroll as Absent and
            // never self-correct".
            //
            // Reading fail-loud makes the catch reachable, which is all that is
            // needed: the guard was already correct, it was simply never told.
            $rows = $this->fs->schoolWhereOrFail('calendarEvents', []);
            foreach ((array) $rows as $row) {
                $d = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                if (empty($d)) continue;

                $kind = strtolower((string) ($d['type'] ?? $d['category'] ?? ''));
                if (!in_array($kind, ['holiday', 'special_working_day', 'half_day'], true)) continue;
                if (strtolower((string) ($d['status'] ?? '')) === 'cancelled') continue;

                $start = (string) ($d['startDate'] ?? $d['start_date'] ?? '');
                if ($start === '') continue;
                $end   = (string) ($d['endDate'] ?? $d['end_date'] ?? $start);
                $name  = (string) ($d['title'] ?? $d['name'] ?? ucfirst(str_replace('_', ' ', $kind)));

                $upd = (string) ($d['updatedAt'] ?? $d['updated_at'] ?? '');
                if ($upd !== '' && $upd > $maxUpd) $maxUpd = $upd;

                $entry = [
                    'name'        => $name,
                    'appliesTo'   => is_array($d['appliesTo'] ?? null)   ? array_values($d['appliesTo'])   : [],
                    'departments' => is_array($d['departments'] ?? null) ? array_values($d['departments']) : [],
                    'exceptStaff' => is_array($d['exceptStaff'] ?? null) ? array_values($d['exceptStaff']) : [],
                ];

                if ($kind === 'holiday') {
                    $this->_expand_range($start, $end, $entry, $holidays);
                } elseif ($kind === 'special_working_day') {
                    $this->_expand_range($start, $end, $entry, $swd);
                } else {
                    $this->_expand_range($start, $end, $entry, $halfDay);
                }
            }
        } catch (\Throwable $e) {
            // Fail open for punch flows — a calendar-read failure must never break a
            // punch. But record that the load FAILED so fail-closed callers (the
            // payroll finaliser) can tell this apart from a genuinely empty calendar.
            // Guard log_message so the fail-open path can't itself throw.
            if (function_exists('log_message')) {
                log_message('error', 'Holiday_service::_query_firestore failed: ' . $e->getMessage());
            }
            $this->_lastUpdated = null;
            $this->_loadedOk    = false;
            return [[], [], []];
        }
        $this->_lastUpdated = ($maxUpd !== '') ? $maxUpd : null;
        $this->_loadedOk    = true;
        return [$holidays, $swd, $halfDay];
    }

    /** Expand a [start..end] inclusive date range, appending $entry to $out[dateISO][]. */
    private function _expand_range(string $start, string $end, array $entry, array &$out): void
    {
        try {
            $cur = new DateTime($start);
            $lim = new DateTime($end >= $start ? $end : $start);
            $guard = 0;
            while ($cur <= $lim && $guard++ < 370) {
                $out[$cur->format('Y-m-d')][] = $entry;
                $cur->modify('+1 day');
            }
        } catch (\Throwable $e) {
            // Unparseable date — record the raw start only, defensively.
            $out[$start][] = $entry;
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
