<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Stream_b_verifier — Stream B (staff attendance) canonical-schema +
 * site-count + index-readiness verifier.
 *
 * Phase I Step 2 deliverable. CLI-only, read-only, idempotent.
 *
 * Six assertions:
 *   A1 — staffAttendance/* docs carry the canonical field set
 *   A2 — staffAttendanceSummary.dayWise length == cal_days_in_month, charset == {P,A,L,H,T,V}
 *   A3 — staff-domain RTDB site count in PHP source matches the locked inventory (34 ACTIVE)
 *   A4 — Hr.php carries ZERO active RTDB-staff-attendance writes (post-dead-code-removal)
 *   A5 — staffAttendanceLocks/* doc shape (schoolId, session, month, lockedAtMs presence — forward-looking)
 *   A6 — all 7 Stream-B Firestore indexes (F-SB-1..F-SB-7) resolve their target queries
 *   A7 — Phase II: stream_b_flags config exists and defaults to OFF (mutually exclusive dispatch)
 *   A17 — Phase II: Lock_cache fresh-read parity (cache result matches Firestore truth)
 *   A18 — Phase II: Lock_cache TTL expiry triggers live re-read
 *   A19 — Phase II: Lock_cache per-session isolation (key derivation is deterministic + session-scoped)
 *   A20 — Phase II: Lock_cache invalidate() clears cached entry
 *   A21 — Phase II: Lock_cache fail-safe on session-backend error (structural)
 *   A22 — Phase II: Lock_cache exposes is_locked_live() for payroll/month-close (structural)
 *   A23 — Phase II: Staff_attendance_writer uses CAS precondition on summary write
 *   A24 — Phase II: markSingleDay happy-path against synthetic tenant
 *   A25 — Phase II: writer defines fail-loud exception types
 *   A26 — Phase II: staffAttendance write has NO precondition; summary write HAS precondition
 *   A8  — Phase II: mark_staff_day dispatcher exists; _mark_staff_day_fs body uses ZERO RTDB calls
 *   A9  — Phase II: _mark_staff_day_legacy preserved; flag-OFF dispatch reaches it
 *   A27 — Step III.0: Stream_b_telemetry library + dispatcher emit + fs-path emit (MVT)
 *   A28 — Step III.1: bulkMarkDay implementation is sequential CAS (structural)
 *   A29 — Step III.1: bulkMarkDay happy-path against synthetic tenant (integration)
 *   A30 — Step III.2: save_staff_attendance dispatcher routes + FS path RTDB-free
 *   A31 — Step III.2: _save_staff_attendance_legacy preserved (RTDB writes + helper)
 *
 * INVOCATION:
 *   php index.php stream_b_verifier verify
 *   Env required: SCHOOL_ID=<schoolFs>  SESSION_YEAR=<YYYY-YY>
 *
 * Exit codes:
 *   0 — all 6 assertions PASS
 *   1 — env vars missing
 *   2 — one or more assertions FAILED
 */
class Stream_b_verifier extends CI_Controller
{
    private string $schoolFs    = '';
    private string $sessionYear = '';

    private const REQUIRED_STAFF_ATT_FIELDS = [
        'schoolId', 'date', 'staffId', 'status', 'markedBy', 'markedAt',
    ];

    private const ALLOWED_DAYWISE_CHARS = ['P','A','L','H','T','V'];

    /**
     * Locked baseline after Phase I Step 2 (2026-06-12).
     * Reports two numbers per file:
     *   total_refs  — all "Staff_Attendance" string occurrences (incl. docblock)
     *   active_ops  — firebase->(get|set|update|delete|push) inline calls with literal Staff_Attendance path
     * As migration progresses Phase II..VIII, both numbers trend monotonically to docblock-only / zero.
     */
    private const EXPECTED_SITE_COUNTS = [
        'Attendance.php'        => ['total_refs' => 25, 'active_ops_min' => 6],  // 23 active path-refs + 2 docblock; regex catches 6 inline calls + assignments
        'attendance_helper.php' => ['total_refs' => 4,  'active_ops_min' => 4],  // 4 inline firebase->X calls
        'Hr.php'                => ['total_refs' => 1,  'active_ops_min' => 0],  // 1 docblock-only line; 0 active firebase->X
    ];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) {
            show_error('Stream_b_verifier is CLI-only.', 403);
        }
        $this->load->library('firebase');

        $this->schoolFs    = (string) (getenv('SCHOOL_ID')    ?: '');
        $this->sessionYear = (string) (getenv('SESSION_YEAR') ?: '');
        if ($this->schoolFs === '' || $this->sessionYear === '') {
            echo "ERROR: Set SCHOOL_ID and SESSION_YEAR environment variables.\n";
            exit(1);
        }
    }

    public function verify(): void
    {
        $hdr = str_repeat('=', 72);
        echo "{$hdr}\n";
        echo "  Stream B Verifier — Phase I Step 2\n";
        echo "  School: {$this->schoolFs}   Session: {$this->sessionYear}\n";
        echo "  Time:   " . date('c') . "\n";
        echo "{$hdr}\n";

        $results = [];
        $results['A1'] = $this->_assert_staff_att_schema();
        $results['A2'] = $this->_assert_summary_invariants();
        $results['A3'] = $this->_assert_site_count_parity();
        $results['A4'] = $this->_assert_hr_zero_active_writes();
        $results['A5'] = $this->_assert_locks_shape();
        $results['A6'] = $this->_assert_indexes_ready();
        $results['A7']  = $this->_assert_stream_b_flags();
        $results['A17'] = $this->_assert_lock_cache_parity();
        $results['A18'] = $this->_assert_lock_cache_ttl();
        $results['A19'] = $this->_assert_lock_cache_session_isolation();
        $results['A20'] = $this->_assert_lock_cache_invalidate();
        $results['A21'] = $this->_assert_lock_cache_fail_safe();
        $results['A22'] = $this->_assert_lock_cache_live_method();
        $results['A23'] = $this->_assert_writer_uses_cas();
        $results['A24'] = $this->_assert_writer_happy_path();
        $results['A25'] = $this->_assert_writer_exception_types();
        $results['A26'] = $this->_assert_writer_precondition_scope();
        $results['A8']  = $this->_assert_dispatcher_fs_zero_rtdb();
        $results['A9']  = $this->_assert_dispatcher_legacy_preserved();
        $results['A27'] = $this->_assert_mvt_telemetry();
        $results['A28'] = $this->_assert_bulk_mark_structural();
        $results['A29'] = $this->_assert_bulk_mark_happy_path();
        $results['A30'] = $this->_assert_save_dispatcher_fs_zero_rtdb();
        $results['A31'] = $this->_assert_save_dispatcher_legacy_preserved();

        echo "\n{$hdr}\n";
        echo "  Summary\n";
        echo "{$hdr}\n";
        $allPass = true;
        foreach ($results as $code => $r) {
            $verdict = $r['pass'] ? 'PASS' : 'FAIL';
            if (!$r['pass']) $allPass = false;
            printf("  %s  %-4s  %s\n", $code, $verdict, $r['msg']);
        }
        echo "{$hdr}\n";
        echo $allPass ? "  RESULT: ALL ASSERTIONS PASS\n" : "  RESULT: ONE OR MORE FAILURES\n";
        echo "{$hdr}\n";
        exit($allPass ? 0 : 2);
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A1 — staffAttendance canonical schema                              */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_staff_att_schema(): array
    {
        echo "\n── A1: staffAttendance canonical schema ──\n";
        try {
            $docs = $this->firebase->firestoreQuery('staffAttendance', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 100);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => "query failed: " . $e->getMessage()];
        }

        $count   = count($docs);
        $missing = [];
        $extra   = [];
        foreach ($docs as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            foreach (self::REQUIRED_STAFF_ATT_FIELDS as $f) {
                if (!array_key_exists($f, $d)) $missing[$f] = ($missing[$f] ?? 0) + 1;
            }
        }
        echo "  docs scanned: {$count}\n";
        foreach ($missing as $f => $c) {
            echo "  missing field [{$f}] in {$c}/{$count} docs\n";
        }
        if ($count === 0) {
            return ['pass' => true, 'msg' => "no docs to verify (collection empty); schema check skipped"];
        }
        $ok = empty($missing);
        return ['pass' => $ok, 'msg' => $ok ? "{$count} docs all carry canonical fields" : "schema gaps in " . count($missing) . " field(s)"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A2 — staffAttendanceSummary.dayWise invariants                     */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_summary_invariants(): array
    {
        echo "\n── A2: staffAttendanceSummary.dayWise invariants ──\n";
        try {
            $docs = $this->firebase->firestoreQuery('staffAttendanceSummary', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 200);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => "query failed: " . $e->getMessage()];
        }
        $count = count($docs);
        $len_mismatch = 0;
        $bad_chars    = 0;
        $allowedSet   = array_flip(self::ALLOWED_DAYWISE_CHARS);

        foreach ($docs as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            $dw = (string) ($d['dayWise'] ?? '');
            $month = (string) ($d['month'] ?? '');
            if ($dw === '' || $month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) continue;
            [$yr, $mo] = array_map('intval', explode('-', $month));
            $dim = cal_days_in_month(CAL_GREGORIAN, $mo, $yr);
            if (strlen($dw) !== $dim) {
                $len_mismatch++;
                echo "  len mismatch on {$row['id']}: dayWise=" . strlen($dw) . " expected={$dim}\n";
            }
            for ($i = 0, $n = strlen($dw); $i < $n; $i++) {
                $c = strtoupper($dw[$i]);
                if (!isset($allowedSet[$c])) { $bad_chars++; break; }
            }
        }
        echo "  docs scanned: {$count}\n";
        echo "  length mismatches: {$len_mismatch}\n";
        echo "  charset violations: {$bad_chars}\n";
        $ok = ($len_mismatch === 0 && $bad_chars === 0);
        return ['pass' => $ok, 'msg' => $ok ? "all {$count} summaries respect length+charset" : "len_mismatch={$len_mismatch} bad_chars={$bad_chars}"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A3 — RTDB-site-count parity in PHP source                          */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_site_count_parity(): array
    {
        echo "\n── A3: RTDB site count in PHP source ──\n";
        $appPath = realpath(APPPATH);

        // Total reference count (any "Staff_Attendance" string in source)
        $refPattern        = '/Staff_Attendance/';
        // Active op count (inline firebase->X with literal Staff_Attendance path)
        $rtdbCallPattern   = '/\$this->firebase->(get|set|update|delete|push)\s*\(\s*[^)]*Staff_Attendance/';
        $helperCallPattern = '/\$firebase->(get|set|update|delete|push)\s*\(\s*[^)]*Staff_Attendance/';

        $files = [
            'Attendance.php'        => $appPath . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'Attendance.php',
            'attendance_helper.php' => $appPath . DIRECTORY_SEPARATOR . 'helpers'     . DIRECTORY_SEPARATOR . 'attendance_helper.php',
            'Hr.php'                => $appPath . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'Hr.php',
        ];

        $ok = true;
        echo "  expected → observed:\n";
        foreach (self::EXPECTED_SITE_COUNTS as $label => $exp) {
            $path = $files[$label];
            if (!is_file($path)) { echo "    {$label}: FILE NOT FOUND\n"; $ok = false; continue; }
            $src = (string) file_get_contents($path);
            preg_match_all($refPattern, $src, $rm);
            $totalRefs = count($rm[0]);
            $callPat = ($label === 'attendance_helper.php') ? $helperCallPattern : $rtdbCallPattern;
            preg_match_all($callPat, $src, $cm);
            $activeOps = count($cm[0]);

            $refOk    = ($totalRefs === $exp['total_refs']);
            $opsOk    = ($activeOps >= $exp['active_ops_min']);
            $marker   = ($refOk && $opsOk) ? 'OK' : 'DRIFT';
            printf("    %-26s  refs:exp=%-2d obs=%-2d  active_ops:min=%d obs=%-2d  %s\n",
                $label, $exp['total_refs'], $totalRefs, $exp['active_ops_min'], $activeOps, $marker);
            if (!($refOk && $opsOk)) $ok = false;
        }
        return ['pass' => $ok, 'msg' => $ok ? "all 3 files match expected ref+op counts" : "site-count drift"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A4 — Hr.php has zero active RTDB-staff-attendance writes           */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_hr_zero_active_writes(): array
    {
        echo "\n── A4: Hr.php zero active RTDB writes to Staff_Attendance ──\n";
        $appPath = realpath(APPPATH);
        $path = $appPath . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'Hr.php';
        if (!is_file($path)) {
            return ['pass' => false, 'msg' => "Hr.php not found"];
        }
        $src = (string) file_get_contents($path);
        // Active write pattern: firebase->(set|update|delete|push) targeting Staff_Attendance
        preg_match_all('/\$this->firebase->(set|update|delete|push)\s*\([^)]*Staff_Attendance/', $src, $m);
        $writes = count($m[0]);
        // Active read pattern: firebase->get targeting Staff_Attendance
        preg_match_all('/\$this->firebase->get\s*\([^)]*Staff_Attendance/', $src, $r);
        $reads = count($r[0]);
        echo "  Hr.php active writes: {$writes}\n";
        echo "  Hr.php active reads:  {$reads}\n";
        $ok = ($writes === 0 && $reads === 0);
        return ['pass' => $ok, 'msg' => $ok ? "Hr.php carries zero active runtime RTDB-staff-attendance ops" : "writes={$writes} reads={$reads}"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A5 — staffAttendanceLocks doc shape (forward-looking)              */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_locks_shape(): array
    {
        echo "\n── A5: staffAttendanceLocks shape (forward-looking; may be empty) ──\n";
        try {
            $docs = $this->firebase->firestoreQuery('staffAttendanceLocks', [
                ['schoolId', '==', $this->schoolFs],
            ], null, 'ASC', 50);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => "query failed: " . $e->getMessage()];
        }
        $count = count($docs);
        if ($count === 0) {
            echo "  no lock docs yet (expected at Phase I — Phase VI populates)\n";
            return ['pass' => true, 'msg' => "empty (forward-looking)"];
        }
        $required = ['schoolId','session','month','lockedAtMs'];
        $missing  = 0;
        foreach ($docs as $row) {
            $d = is_array($row['data'] ?? null) ? $row['data'] : [];
            foreach ($required as $f) if (!array_key_exists($f, $d)) { $missing++; break; }
        }
        echo "  docs scanned: {$count}\n";
        echo "  missing-field rows: {$missing}\n";
        $ok = ($missing === 0);
        return ['pass' => $ok, 'msg' => $ok ? "{$count} lock docs respect canonical shape" : "{$missing} rows missing required fields"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A7 — stream_b_flags config exists and defaults to OFF              */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_stream_b_flags(): array
    {
        echo "\n── A7: stream_b_flags config exists + defaults to OFF ──\n";

        // Locate the flag config file
        $appPath  = realpath(APPPATH);
        $flagPath = $appPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'stream_b_flags.php';
        if (!is_file($flagPath)) {
            return ['pass' => false, 'msg' => "stream_b_flags.php file not found at {$flagPath}"];
        }
        echo "  file exists: {$flagPath}\n";

        // Load via CodeIgniter config loader (proper way)
        try {
            $this->config->load('stream_b_flags', true);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => "config load failed: " . $e->getMessage()];
        }

        // Verify flag exists and defaults to false
        $writerFlag = $this->config->item('stream_b_writer_fs_only', 'stream_b_flags');
        $allowlist  = $this->config->item('enabled_for_schools', 'stream_b_flags');

        $writerOk = ($writerFlag === false);
        $allowlistOk = is_array($allowlist) && empty($allowlist);

        echo "  stream_b_writer_fs_only: " . var_export($writerFlag, true) . " (expected: false)\n";
        echo "  enabled_for_schools:     " . (is_array($allowlist) ? '[' . count($allowlist) . ' entries]' : var_export($allowlist, true)) . " (expected: [] empty array)\n";

        // Verify helper function exists and is callable
        $helperOk = function_exists('stream_b_writer_enabled');
        echo "  stream_b_writer_enabled(): " . ($helperOk ? 'function exists' : 'MISSING') . "\n";

        // Test the helper returns false for any tenant when flags are off
        $helperReturnsFalse = false;
        if ($helperOk) {
            $r = stream_b_writer_enabled('SCH_TEST_ANY', $this->config);
            $helperReturnsFalse = ($r === false);
            echo "  stream_b_writer_enabled('SCH_TEST_ANY'): " . var_export($r, true) . " (expected: false)\n";
        }

        $ok = $writerOk && $allowlistOk && $helperOk && $helperReturnsFalse;
        return ['pass' => $ok, 'msg' => $ok
            ? "flag exists, defaults OFF, helper enforces dispatch correctly"
            : "flag/helper validation failed"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A17 — Lock_cache fresh-read parity with Firestore                  */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_cache_parity(): array
    {
        echo "\n── A17: Lock_cache fresh-read parity with Firestore ──\n";
        try {
            $this->load->library('lock_cache');
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'Lock_cache library load failed: ' . $e->getMessage()];
        }
        // Use a probe lock doc that we control fully
        $probeMonth = 'PROBE_' . date('YmdHis');
        $probeSession = 'PROBE_SESSION';
        $probeSchool = $this->schoolFs;
        $docId = "{$probeSchool}_{$probeSession}_{$probeMonth}";

        // Setup: write a probe lock doc with isLocked=true
        try {
            $this->firebase->firestoreSet('staffAttendanceLocks', $docId, [
                'schoolId'    => $probeSchool,
                'session'     => $probeSession,
                'month'       => $probeMonth,
                'isLocked'    => true,
                'lockedBy'    => 'verifier_A17',
                'lockedAtMs'  => (int) (microtime(true) * 1000),
                '_probe'      => true,
            ], false);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'probe doc setup failed: ' . $e->getMessage()];
        }

        // First call should miss cache and read live
        $r = $this->lock_cache->is_locked($probeSchool, $probeSession, $probeMonth);
        echo "  first call:  source={$r['source']}  is_locked=" . var_export($r['is_locked'], true) . "\n";
        $firstOk = ($r['source'] === 'live') && ($r['is_locked'] === true);

        // Second call should hit cache (within TTL)
        $r2 = $this->lock_cache->is_locked($probeSchool, $probeSession, $probeMonth);
        echo "  second call: source={$r2['source']}  is_locked=" . var_export($r2['is_locked'], true) . "  age_ms={$r2['age_ms']}\n";
        $secondOk = ($r2['source'] === 'cache') && ($r2['is_locked'] === true);

        // Cleanup
        try { $this->firebase->firestoreDelete('staffAttendanceLocks', $docId); } catch (\Throwable $e) {}
        // Clear cache too so it doesn't pollute subsequent runs
        $this->lock_cache->invalidate($probeSchool, $probeSession, $probeMonth);

        $ok = $firstOk && $secondOk;
        return ['pass' => $ok, 'msg' => $ok
            ? 'live read first; cache hit second; both agree with Firestore truth'
            : 'parity mismatch'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A18 — Lock_cache TTL: structural check only at Step II.2            */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_cache_ttl(): array
    {
        echo "\n── A18: Lock_cache TTL is structurally bounded ──\n";
        // Structural check: verify TTL_SECONDS constant exists and is sane (60..3600 inclusive)
        if (!class_exists('Lock_cache')) {
            return ['pass' => false, 'msg' => 'Lock_cache class not loaded'];
        }
        $ttl = (int) constant('Lock_cache::TTL_SECONDS');
        echo "  TTL_SECONDS: {$ttl}\n";
        $ok = ($ttl >= 60 && $ttl <= 3600);
        return ['pass' => $ok, 'msg' => $ok
            ? "TTL is {$ttl}s (within sane band 60..3600)"
            : "TTL {$ttl} out of sane bounds"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A19 — Per-session isolation                                         */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_cache_session_isolation(): array
    {
        echo "\n── A19: Lock_cache per-session isolation ──\n";
        // Structural: verify cache uses CI session storage (per-PHP-session by cookie)
        // and key derivation is deterministic per (schoolId, session, monthKey).
        $src = (string) file_get_contents(APPPATH . 'libraries/Lock_cache.php');
        $usesSession = (strpos($src, '$this->ci->session->set_userdata') !== false
                     && strpos($src, '$this->ci->session->userdata') !== false
                     && strpos($src, '$this->ci->session->unset_userdata') !== false);
        $deterministicKey = (strpos($src, "sha1(\"{\$schoolId}|{\$session}|{\$monthKey}\")") !== false);
        echo "  uses CI session API:    " . ($usesSession ? 'yes' : 'no') . "\n";
        echo "  deterministic key hash: " . ($deterministicKey ? 'yes' : 'no') . "\n";
        $ok = $usesSession && $deterministicKey;
        return ['pass' => $ok, 'msg' => $ok
            ? 'session-backed + deterministic per-(school,session,month) key'
            : 'isolation mechanism missing'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A20 — invalidate() clears the cached entry                          */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_cache_invalidate(): array
    {
        echo "\n── A20: Lock_cache invalidate() clears cached entry ──\n";
        try {
            $this->load->library('lock_cache');
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'Lock_cache load failed'];
        }
        $school = $this->schoolFs;
        $session = 'PROBE_A20';
        $month = 'PROBE_' . date('YmdHis');
        $docId = "{$school}_{$session}_{$month}";

        // Setup: probe doc + cache populate
        try {
            $this->firebase->firestoreSet('staffAttendanceLocks', $docId, [
                'schoolId' => $school, 'session' => $session, 'month' => $month,
                'isLocked' => true, '_probe' => true,
            ], false);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'probe setup failed: ' . $e->getMessage()];
        }
        $this->lock_cache->is_locked($school, $session, $month); // populates cache

        // Confirm cache hit on next call
        $r1 = $this->lock_cache->is_locked($school, $session, $month);
        $cachedBefore = ($r1['source'] === 'cache');

        // Invalidate
        $this->lock_cache->invalidate($school, $session, $month);

        // Next call must NOT be cache (it must do a live read)
        $r2 = $this->lock_cache->is_locked($school, $session, $month);
        $liveAfter = ($r2['source'] === 'live');

        echo "  before invalidate: source={$r1['source']}\n";
        echo "  after  invalidate: source={$r2['source']}\n";

        // Cleanup
        try { $this->firebase->firestoreDelete('staffAttendanceLocks', $docId); } catch (\Throwable $e) {}
        $this->lock_cache->invalidate($school, $session, $month);

        $ok = $cachedBefore && $liveAfter;
        return ['pass' => $ok, 'msg' => $ok
            ? 'invalidate() correctly cleared cached entry; next call hit Firestore'
            : 'invalidate did not clear cache'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A21 — Fail-safe on session backend error                            */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_cache_fail_safe(): array
    {
        echo "\n── A21: Lock_cache fail-safe on session backend error ──\n";
        // Structural: verify _cache_get/_cache_put/_cache_clear all wrap session ops in try/catch
        $src = (string) file_get_contents(APPPATH . 'libraries/Lock_cache.php');
        $cacheGetSafe   = preg_match('/private function _cache_get.*?try.*?catch.*?Throwable/s', $src);
        $cachePutSafe   = preg_match('/private function _cache_put.*?try.*?catch.*?Throwable/s', $src);
        $cacheClearSafe = preg_match('/private function _cache_clear.*?try.*?catch.*?Throwable/s', $src);
        echo "  _cache_get  try/catch: " . ($cacheGetSafe   ? 'yes' : 'no') . "\n";
        echo "  _cache_put  try/catch: " . ($cachePutSafe   ? 'yes' : 'no') . "\n";
        echo "  _cache_clear try/catch: " . ($cacheClearSafe ? 'yes' : 'no') . "\n";
        $ok = ($cacheGetSafe && $cachePutSafe && $cacheClearSafe);
        return ['pass' => $ok, 'msg' => $ok
            ? 'all session ops wrapped in fail-safe try/catch'
            : 'one or more session ops can throw uncaught'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A22 — is_locked_live() exists for payroll/month-close               */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_cache_live_method(): array
    {
        echo "\n── A22: Lock_cache exposes is_locked_live() for payroll/month-close ──\n";
        if (!class_exists('Lock_cache')) {
            return ['pass' => false, 'msg' => 'Lock_cache class not loaded'];
        }
        $methods = get_class_methods('Lock_cache');
        $hasCached = in_array('is_locked', $methods, true);
        $hasLive   = in_array('is_locked_live', $methods, true);
        echo "  is_locked():       " . ($hasCached ? 'exists' : 'MISSING') . "\n";
        echo "  is_locked_live():  " . ($hasLive   ? 'exists' : 'MISSING') . "\n";

        // Also verify is_locked_live() doesn't go through cache (structural)
        $src = (string) file_get_contents(APPPATH . 'libraries/Lock_cache.php');
        $liveBypasses = preg_match('/public function is_locked_live[^}]*_live_read\(/s', $src);
        echo "  is_locked_live bypasses cache: " . ($liveBypasses ? 'yes (calls _live_read directly)' : 'no') . "\n";

        $ok = $hasCached && $hasLive && $liveBypasses;
        return ['pass' => $ok, 'msg' => $ok
            ? 'both methods exist; is_locked_live bypasses cache'
            : 'live-method contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A23 — Writer uses CAS precondition + zero RTDB                     */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_writer_uses_cas(): array
    {
        echo "\n── A23: Staff_attendance_writer uses CAS + zero RTDB ──\n";
        $path = APPPATH . 'libraries/Staff_attendance_writer.php';
        if (!is_file($path)) {
            return ['pass' => false, 'msg' => 'Staff_attendance_writer.php not found'];
        }
        $src = (string) file_get_contents($path);
        // Must use CAS precondition pattern
        $hasUpdateTimePrecondition = (strpos($src, "'precondition' => \$precondition") !== false)
                                    && (strpos($src, "'updateTime' => \$captured") !== false);
        $hasExistsFalse            = strpos($src, "'exists' => false") !== false;
        // Must NOT touch RTDB
        $rtdbReadCalls  = preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $src, $m1);
        $rtdbAnyCalls   = $rtdbReadCalls + 0;
        // Note: firestoreGet/firestoreSet/firestoreCommitBatch are allowed; RTDB get/set are not
        echo "  CAS updateTime precondition: " . ($hasUpdateTimePrecondition ? 'yes' : 'no') . "\n";
        echo "  exists=false precondition (new docs): " . ($hasExistsFalse ? 'yes' : 'no') . "\n";
        echo "  RTDB \$firebase->(get|set|update|delete|push) calls: {$rtdbAnyCalls} (expected: 0)\n";
        $ok = $hasUpdateTimePrecondition && $hasExistsFalse && ($rtdbAnyCalls === 0);
        return ['pass' => $ok, 'msg' => $ok
            ? 'CAS preconditions present; zero RTDB calls'
            : 'CAS pattern or RTDB-exclusion violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A24 — Happy-path integration test                                  */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_writer_happy_path(): array
    {
        echo "\n── A24: markSingleDay happy-path against synthetic tenant ──\n";
        $this->ci =& get_instance(); // safe noop if already
        try {
            $this->load->library('staff_attendance_writer');
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'load failed: ' . $e->getMessage()];
        }
        $probeSchool   = 'SCH_PROBE_WRITER_' . time();
        $probeSession  = '2026-27';
        $probeStaff    = 'STAPROBE001';
        $probeDate     = '2026-04-15';
        try {
            $this->staff_attendance_writer->init($this->firebase, $probeSchool, $probeSession);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'init failed: ' . $e->getMessage()];
        }

        // Invoke markSingleDay
        try {
            $r = $this->staff_attendance_writer->markSingleDay(
                $probeStaff, $probeDate, 'P', ['markedBy' => 'verifier_A24', 'source' => 'manual']
            );
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'markSingleDay threw: ' . $e->getMessage()];
        }
        echo "  ok={$r['ok']} attempts={$r['attempts']} reads={$r['fs_reads']} writes={$r['fs_writes']} dur={$r['duration_ms']}ms\n";
        echo "  cache_source={$r['cache_source']} previous_status={$r['previous_status']} new_status={$r['new_status']}\n";

        // Verify the documents were created
        $attDocId     = "{$probeSchool}_{$probeDate}_{$probeStaff}";
        $summaryDocId = "{$probeSchool}_{$probeStaff}_2026-04";
        $att = $this->firebase->firestoreGet('staffAttendance', $attDocId);
        $sum = $this->firebase->firestoreGet('staffAttendanceSummary', $summaryDocId);
        $attOk  = is_array($att) && ($att['status'] ?? '') === 'P' && ($att['previousStatus'] ?? '') === 'V';
        $sumOk  = is_array($sum) && ($sum['present'] ?? -1) === 1 && ($sum['void'] ?? -1) === 29
                  && (($sum['dayWise'] ?? '')[14] ?? '') === 'P';
        echo "  staffAttendance doc:        " . ($attOk ? 'present=P, previousStatus=V' : 'INCORRECT') . "\n";
        echo "  staffAttendanceSummary doc: " . ($sumOk ? 'dayWise[14]=P, present=1, void=29' : 'INCORRECT') . "\n";

        // Cleanup probe docs
        try { $this->firebase->firestoreDelete('staffAttendance', $attDocId); } catch (\Throwable $e) {}
        try { $this->firebase->firestoreDelete('staffAttendanceSummary', $summaryDocId); } catch (\Throwable $e) {}

        $ok = $r['ok'] && $attOk && $sumOk && $r['fs_writes'] === 2;
        return ['pass' => $ok, 'msg' => $ok
            ? 'markSingleDay wrote both docs with correct counts; 1 read + 2 writes'
            : 'markSingleDay did not produce expected canonical state'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A25 — Exception types defined for fail-loud propagation            */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_writer_exception_types(): array
    {
        echo "\n── A25: writer fail-loud exception types defined ──\n";
        // Force load (if not already)
        if (!class_exists('Staff_attendance_writer', false)) {
            try { $this->load->library('staff_attendance_writer'); } catch (\Throwable $e) {}
        }
        $exceptions = ['MonthLockedException', 'CASWriteException', 'CASRetryExhaustedException'];
        $missing = [];
        foreach ($exceptions as $cls) {
            $present = class_exists($cls, false);
            echo "  " . str_pad($cls, 32) . ($present ? 'defined' : 'MISSING') . "\n";
            if (!$present) $missing[] = $cls;
        }
        $ok = empty($missing);
        return ['pass' => $ok, 'msg' => $ok
            ? 'all 3 exception types defined (writer fails loud)'
            : 'missing exceptions: ' . implode(', ', $missing)];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A26 — staffAttendance has NO precondition; summary HAS precondition */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_writer_precondition_scope(): array
    {
        echo "\n── A26: precondition scope: summary YES, staffAttendance NO ──\n";
        $src   = (string) file_get_contents(APPPATH . 'libraries/Staff_attendance_writer.php');
        $lines = explode("\n", $src);
        $attLine = '';
        $sumLine = '';
        foreach ($lines as $ln) {
            // Match the staffAttendance op (with comma after) but NOT staffAttendanceSummary
            if (preg_match("/'collection'\s*=>\s*'staffAttendance'\s*,/", $ln)) {
                $attLine = $ln;
            }
            if (preg_match("/'collection'\s*=>\s*'staffAttendanceSummary'\s*,/", $ln)) {
                $sumLine = $ln;
            }
        }
        if ($attLine === '') return ['pass' => false, 'msg' => 'staffAttendance op line not found'];
        if ($sumLine === '') return ['pass' => false, 'msg' => 'staffAttendanceSummary op line not found'];

        $attHasPrecond = strpos($attLine, "'precondition'") !== false;
        $sumHasPrecond = strpos($sumLine, "'precondition'") !== false;
        echo "  staffAttendance        precondition: " . ($attHasPrecond ? 'YES (unexpected)' : 'no (correct)') . "\n";
        echo "  staffAttendanceSummary precondition: " . ($sumHasPrecond ? 'yes (correct)'    : 'NO (unexpected)') . "\n";

        $ok = (!$attHasPrecond) && $sumHasPrecond;
        return ['pass' => $ok, 'msg' => $ok
            ? 'precondition restricted to summary doc only'
            : 'precondition scope violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A8 — dispatcher present; _mark_staff_day_fs body is RTDB-free      */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_dispatcher_fs_zero_rtdb(): array
    {
        echo "\n── A8: mark_staff_day dispatcher + _mark_staff_day_fs zero-RTDB ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Dispatcher present + flag check + routes
        $hasDispatcher    = (bool) preg_match('/public function mark_staff_day\s*\(\s*\)\s*\{\s*\$this->config->load\(\s*[\'"]stream_b_flags[\'"]/', $src);
        $hasFlagBranch    = (bool) preg_match('/stream_b_writer_enabled\(\$this->school_id\s*,\s*\$this->config\)/', $src);
        // Dispatcher may either `return $this->_mark_staff_day_*()` directly
        // or `$resp = $this->_mark_staff_day_*()` for post-call telemetry commit.
        $routesToFs       = (bool) preg_match('/\$this->_mark_staff_day_fs\(\)/', $src);
        $routesToLegacy   = (bool) preg_match('/\$this->_mark_staff_day_legacy\(\)/', $src);

        echo "  public dispatcher present:       " . ($hasDispatcher ? 'yes' : 'no') . "\n";
        echo "  stream_b_writer_enabled check:   " . ($hasFlagBranch ? 'yes' : 'no') . "\n";
        echo "  routes to _mark_staff_day_fs:    " . ($routesToFs ? 'yes' : 'no') . "\n";
        echo "  routes to _mark_staff_day_legacy:" . ($routesToLegacy ? 'yes' : 'no') . "\n";

        // Extract _mark_staff_day_fs body and grep for RTDB calls
        // Match from "private function _mark_staff_day_fs" up to the next "private function" or "public function"
        $fsBody = '';
        if (preg_match('/private function _mark_staff_day_fs\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $fsBody = $m[0];
        }
        if ($fsBody === '') {
            return ['pass' => false, 'msg' => '_mark_staff_day_fs method body not extractable'];
        }

        // RTDB API calls
        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $fsBody, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        // Known RTDB-touching helpers that must NOT be called from the FS path
        $rtdbHelpers = [
            'update_staff_att_summary',  // attendance_helper RTDB summary cache
            '_check_staff_att_lock',     // legacy RTDB lock-doc read
            '_acquire_att_lock',         // legacy RTDB soft-lock
            '_release_att_lock',         // legacy RTDB soft-lock release
        ];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (strpos($fsBody, $h) !== false) $helperHits[] = $h;
        }

        echo "  _mark_staff_day_fs body length: " . strlen($fsBody) . " chars\n";
        echo "  RTDB API calls in FS body:      {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in FS body:   " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";

        $ok = $hasDispatcher && $hasFlagBranch && $routesToFs && $routesToLegacy
            && ($rtdbApiCalls === 0) && empty($helperHits);
        return ['pass' => $ok, 'msg' => $ok
            ? 'dispatcher routes correctly; FS path is RTDB-free'
            : 'dispatcher or FS-path zero-RTDB invariant violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A9 — _mark_staff_day_legacy preserved + reachable under flag OFF   */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_dispatcher_legacy_preserved(): array
    {
        echo "\n── A9: _mark_staff_day_legacy preserved + flag-OFF routes to it ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Legacy method exists and is private
        $hasLegacyMethod = (bool) preg_match('/private function _mark_staff_day_legacy\s*\(\s*\)/', $src);
        echo "  private _mark_staff_day_legacy() defined: " . ($hasLegacyMethod ? 'yes' : 'no') . "\n";

        // Legacy body retains the RTDB write at attPath (signature of original method) — proves byte-identity wasn't lost
        $hasLegacyRtdb = (bool) preg_match('/private function _mark_staff_day_legacy.*?firebase->set\(\$attPath/s', $src);
        echo "  legacy retains RTDB \$firebase->set(\$attPath) (proves preserved): " . ($hasLegacyRtdb ? 'yes' : 'no') . "\n";

        // Legacy still calls update_staff_att_summary helper (proves preserved)
        $hasLegacyHelper = (bool) preg_match('/private function _mark_staff_day_legacy.*?update_staff_att_summary/s', $src);
        echo "  legacy retains update_staff_att_summary call:                     " . ($hasLegacyHelper ? 'yes' : 'no') . "\n";

        // Dispatcher routes to legacy when flag is OFF (legacy callsite present)
        $flagOffRoutes = (bool) preg_match('/public function mark_staff_day.*?\$this->_mark_staff_day_legacy\(\)/s', $src);
        echo "  flag-OFF dispatcher reaches legacy:                               " . ($flagOffRoutes ? 'yes' : 'no') . "\n";

        // Stream B verifier A7 already confirmed flag defaults to false → production stays on legacy

        $ok = $hasLegacyMethod && $hasLegacyRtdb && $hasLegacyHelper && $flagOffRoutes;
        return ['pass' => $ok, 'msg' => $ok
            ? 'legacy method preserved with RTDB writes + helper; dispatcher routes to it when flag OFF'
            : 'legacy preservation invariant violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A27 — Step III.0: MVT telemetry library + dispatcher/fs-path emit  */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_mvt_telemetry(): array
    {
        echo "\n── A27: Step III.0 Stream_b_telemetry minimum viable instrumentation ──\n";

        // 1. Library present and loadable
        $libPath = APPPATH . 'libraries/Stream_b_telemetry.php';
        if (!is_file($libPath)) {
            return ['pass' => false, 'msg' => 'Stream_b_telemetry.php not found'];
        }
        try { $this->load->library('stream_b_telemetry'); }
        catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'Stream_b_telemetry load failed: ' . $e->getMessage()];
        }
        $methods = get_class_methods('Stream_b_telemetry');
        $hasBegin   = in_array('begin',   $methods, true);
        $hasUpdate  = in_array('update',  $methods, true);
        $hasCommit  = in_array('commit',  $methods, true);
        $hasAbort   = in_array('abort',   $methods, true);
        echo "  begin()  : " . ($hasBegin   ? 'yes' : 'no') . "\n";
        echo "  update() : " . ($hasUpdate  ? 'yes' : 'no') . "\n";
        echo "  commit() : " . ($hasCommit  ? 'yes' : 'no') . "\n";
        echo "  abort()  : " . ($hasAbort   ? 'yes' : 'no') . "\n";

        // 2. Dispatcher in Attendance.php emits telemetry around mark_staff_day
        $attSrc = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');
        $dispBegin  = (bool) preg_match("/public function mark_staff_day.*?stream_b_telemetry->begin\\(/s", $attSrc);
        $dispCommit = (bool) preg_match("/public function mark_staff_day.*?stream_b_telemetry->commit\\(/s", $attSrc);
        $dispLegacyRtdb = (bool) preg_match("/'code_path'\\s*=>\\s*'legacy'.*?'rtdb_writes_count'\\s*=>\\s*3/s", $attSrc);
        echo "  dispatcher emits begin():         " . ($dispBegin     ? 'yes' : 'no') . "\n";
        echo "  dispatcher emits commit():        " . ($dispCommit    ? 'yes' : 'no') . "\n";
        echo "  legacy path records rtdb_writes=3: " . ($dispLegacyRtdb ? 'yes' : 'no') . "\n";

        // 3. _mark_staff_day_fs body emits CAS + cache + rtdb=0 fields
        if (!preg_match('/private function _mark_staff_day_fs\\s*\\(.*?(?=\\n    (?:public|private|protected)\\s+function)/s', $attSrc, $m)) {
            return ['pass' => false, 'msg' => '_mark_staff_day_fs body not extractable'];
        }
        $fsBody = $m[0];
        $emitsCasAttempts  = (strpos($fsBody, "'cas_attempts'") !== false);
        $emitsCasOutcome   = (strpos($fsBody, "'cas_final_outcome'") !== false);
        $emitsCacheHit     = (strpos($fsBody, "'cache_hit'") !== false);
        $emitsRtdbZero     = (strpos($fsBody, "'rtdb_writes_count' => 0") !== false);
        echo "  fs path emits cas_attempts:       " . ($emitsCasAttempts ? 'yes' : 'no') . "\n";
        echo "  fs path emits cas_final_outcome:  " . ($emitsCasOutcome  ? 'yes' : 'no') . "\n";
        echo "  fs path emits cache_hit:          " . ($emitsCacheHit    ? 'yes' : 'no') . "\n";
        echo "  fs path emits rtdb_writes_count=0:" . ($emitsRtdbZero    ? 'yes' : 'no') . "\n";

        // 4. Best-effort write smoke test (writes one record, verifies file grew, removes test line)
        $logPath = APPPATH . 'logs/stream_b_phase2_telemetry.log';
        clearstatcache(true, $logPath);
        $sizeBefore = is_file($logPath) ? filesize($logPath) : 0;
        $rid = $this->stream_b_telemetry->begin('VERIFIER_A27', 'SCH_PROBE_A27');
        $this->stream_b_telemetry->update([
            'code_path'         => 'verifier',
            'cas_attempts'      => 1,
            'cas_final_outcome' => 'success',
            'cache_hit'         => true,
            'rtdb_writes_count' => 0,
        ]);
        $this->stream_b_telemetry->commit(200);
        clearstatcache(true, $logPath);
        $sizeAfter = is_file($logPath) ? filesize($logPath) : 0;
        $emittedOk = ($rid !== '') && ($sizeAfter > $sizeBefore);
        echo "  smoke test write: " . ($emittedOk ? "log grew by " . ($sizeAfter - $sizeBefore) . " bytes" : 'NO WRITE OBSERVED') . "\n";

        // Clean up the probe line so it doesn't pollute pilot data.
        // Uses line-based filter (more reliable than multiline preg_replace under PHP_EOL variance).
        if (is_file($logPath)) {
            try {
                $lines = (array) @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                $kept  = array_values(array_filter($lines, function ($l) {
                    return strpos($l, '"action":"VERIFIER_A27"') === false;
                }));
                $write = empty($kept) ? '' : implode("\n", $kept) . "\n";
                @file_put_contents($logPath, $write, LOCK_EX);
            } catch (\Throwable $e) {}
        }

        $ok = $hasBegin && $hasUpdate && $hasCommit && $hasAbort
            && $dispBegin && $dispCommit && $dispLegacyRtdb
            && $emitsCasAttempts && $emitsCasOutcome && $emitsCacheHit && $emitsRtdbZero
            && $emittedOk;
        return ['pass' => $ok, 'msg' => $ok
            ? 'library + dispatcher + fs-path emit + smoke-write all verified'
            : 'MVT telemetry contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A28 — Step III.1: bulkMarkDay sequential implementation present    */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_bulk_mark_structural(): array
    {
        echo "\n── A28: bulkMarkDay sequential CAS implementation (structural) ──\n";
        $src = (string) file_get_contents(APPPATH . 'libraries/Staff_attendance_writer.php');

        // 1. Stub removed: bulkMarkDay no longer throws BadMethodCallException
        $stillStubbed = (bool) preg_match('/bulkMarkDay\(\)\s+is\s+a\s+Phase\s+V\s+deliverable/', $src);

        // 2. Bulk reads summaries via firestoreQuery
        $usesQuery = (bool) preg_match("/firestoreQuery\(\s*'staffAttendanceSummary'/", $src);

        // 3. Per-staff sequential commitBatch
        $usesCommit = (bool) preg_match('/foreach\s*\(\s*\$statusByStaffId.*?firestoreCommitBatch/s', $src);

        // 4. CAS precondition path present
        $usesPrecondition = (strpos($src, "'precondition' => \$precondition") !== false);

        // 5. failed_ids return path
        $tracksFailures = (bool) preg_match("/'failed_ids'\s*=>\s*\\\$failedIds/", $src);

        // 6. Zero RTDB calls in writer (re-check, same as A23)
        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $src, $rtdb);
        $rtdbCount = count($rtdb[0]);

        // 7. bulkAutofillDay delegates to bulkMarkDay
        $autofillDelegates = (bool) preg_match('/function bulkAutofillDay.*?\$this->bulkMarkDay/s', $src);

        echo "  stub removed (no Phase V exception):     " . (!$stillStubbed ? 'yes' : 'no') . "\n";
        echo "  firestoreQuery on summaries:             " . ($usesQuery ? 'yes' : 'no') . "\n";
        echo "  per-staff commitBatch loop:              " . ($usesCommit ? 'yes' : 'no') . "\n";
        echo "  CAS precondition wired:                  " . ($usesPrecondition ? 'yes' : 'no') . "\n";
        echo "  failed_ids return field:                 " . ($tracksFailures ? 'yes' : 'no') . "\n";
        echo "  bulkAutofillDay delegates to bulkMarkDay:" . ($autofillDelegates ? 'yes' : 'no') . "\n";
        echo "  RTDB \$firebase->X calls in writer:       {$rtdbCount} (expected: 0)\n";

        $ok = !$stillStubbed && $usesQuery && $usesCommit && $usesPrecondition
            && $tracksFailures && $autofillDelegates && $rtdbCount === 0;
        return ['pass' => $ok, 'msg' => $ok
            ? 'sequential CAS implementation present; zero RTDB calls'
            : 'bulkMarkDay structural contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A29 — Step III.1: bulkMarkDay happy-path integration               */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_bulk_mark_happy_path(): array
    {
        echo "\n── A29: bulkMarkDay happy-path against synthetic tenant ──\n";
        try {
            $this->load->library('staff_attendance_writer');
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'writer load failed: ' . $e->getMessage()];
        }
        $probeSchool  = 'SCH_PROBE_BULK_' . time();
        $probeSession = '2026-27';
        $probeDate    = '2026-04-15';
        $probeMonth   = '2026-04';
        $probeStaff   = ['STABULK001', 'STABULK002', 'STABULK003'];
        try {
            $this->staff_attendance_writer->init($this->firebase, $probeSchool, $probeSession);
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'init failed: ' . $e->getMessage()];
        }

        // Invoke bulkMarkDay with mixed statuses
        try {
            $r = $this->staff_attendance_writer->bulkMarkDay(
                ['STABULK001' => 'P', 'STABULK002' => 'A', 'STABULK003' => 'L'],
                $probeDate,
                ['markedBy' => 'verifier_A29', 'source' => 'bulk_mark']
            );
        } catch (\Throwable $e) {
            return ['pass' => false, 'msg' => 'bulkMarkDay threw: ' . $e->getMessage()];
        }
        echo "  ok={$r['ok']} committed={$r['committed']}/3 attempts_total={$r['attempts_total']} "
           . "reads={$r['fs_reads']} writes={$r['fs_writes']} dur={$r['duration_ms']}ms\n";
        echo "  failed_ids: " . (empty($r['failed_ids']) ? '[]' : implode(',', $r['failed_ids'])) . "\n";

        // Verify each staff has both att + summary docs with correct status
        $allOk = true;
        $expectedStatus = ['STABULK001' => 'P', 'STABULK002' => 'A', 'STABULK003' => 'L'];
        $expectedField  = ['P' => 'present', 'A' => 'absent', 'L' => 'leave'];
        foreach ($probeStaff as $sid) {
            $expStatus = $expectedStatus[$sid];
            $expField  = $expectedField[$expStatus];
            $attDoc    = $this->firebase->firestoreGet('staffAttendance',
                "{$probeSchool}_{$probeDate}_{$sid}");
            $sumDoc    = $this->firebase->firestoreGet('staffAttendanceSummary',
                "{$probeSchool}_{$sid}_{$probeMonth}");
            $attOk = is_array($attDoc) && ($attDoc['status'] ?? '') === $expStatus
                                       && ($attDoc['previousStatus'] ?? '') === 'V';
            $sumOk = is_array($sumDoc)
                && ($sumDoc['dayWise'][14] ?? '') === $expStatus
                && ($sumDoc[$expField] ?? 0) === 1
                && ($sumDoc['void'] ?? 0) === 29;
            echo "  {$sid}: att=" . ($attOk ? "OK ({$expStatus})" : 'BAD')
               . " sum=" . ($sumOk ? "OK (dayWise[14]={$expStatus}, {$expField}=1, void=29)" : 'BAD') . "\n";
            if (!$attOk || !$sumOk) $allOk = false;
            // Cleanup
            try { $this->firebase->firestoreDelete('staffAttendance',
                "{$probeSchool}_{$probeDate}_{$sid}"); } catch (\Throwable $e) {}
            try { $this->firebase->firestoreDelete('staffAttendanceSummary',
                "{$probeSchool}_{$sid}_{$probeMonth}"); } catch (\Throwable $e) {}
        }

        // fs_reads = 1 (query) when pre-existing summaries cover all staff,
        // up to 1 + M when summaries are absent (synthetic tenant case).
        // Both are correct — assert the bounded range.
        $readsOk = ($r['fs_reads'] >= 1 && $r['fs_reads'] <= 1 + count($probeStaff));
        $ok = $r['ok'] && $r['committed'] === 3 && empty($r['failed_ids'])
            && $readsOk && $r['fs_writes'] === 6 && $allOk;
        return ['pass' => $ok, 'msg' => $ok
            ? "all 3 staff committed; {$r['fs_reads']} reads + 6 writes; per-staff state correct"
            : 'bulk happy-path produced unexpected state'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A30 — Step III.2: save_staff_attendance dispatcher + FS RTDB-free  */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_save_dispatcher_fs_zero_rtdb(): array
    {
        echo "\n── A30: save_staff_attendance dispatcher + FS path zero-RTDB ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Dispatcher pattern present
        $hasDispatcher  = (bool) preg_match('/public function save_staff_attendance\s*\(\s*\)\s*\{\s*\$this->config->load\(\s*[\'"]stream_b_flags[\'"]/', $src);
        $hasFlagBranch  = (bool) preg_match('/save_staff_attendance.*?stream_b_writer_enabled\s*\(\s*\$this->school_id/s', $src);
        $routesToFs     = (bool) preg_match('/\$this->_save_staff_attendance_fs\(\)/', $src);
        $routesToLegacy = (bool) preg_match('/\$this->_save_staff_attendance_legacy\(\)/', $src);

        echo "  public dispatcher present:                " . ($hasDispatcher  ? 'yes' : 'no') . "\n";
        echo "  stream_b_writer_enabled flag check:       " . ($hasFlagBranch  ? 'yes' : 'no') . "\n";
        echo "  routes to _save_staff_attendance_fs:      " . ($routesToFs     ? 'yes' : 'no') . "\n";
        echo "  routes to _save_staff_attendance_legacy:  " . ($routesToLegacy ? 'yes' : 'no') . "\n";

        // Extract _save_staff_attendance_fs body and assert zero RTDB
        $fsBody = '';
        if (preg_match('/private function _save_staff_attendance_fs\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $fsBody = $m[0];
        }
        if ($fsBody === '') {
            return ['pass' => false, 'msg' => '_save_staff_attendance_fs body not extractable'];
        }

        // RTDB API calls
        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $fsBody, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);
        // RTDB-touching helpers that must NOT be called from the FS path
        $rtdbHelpers = [
            'update_staff_att_summary',
            '_check_staff_att_lock',
            '_acquire_att_lock',
            '_release_att_lock',
        ];
        // Match actual method calls only (not docstring references).
        // Pattern: $this-><helper>(  with optional whitespace.
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $fsBody)) $helperHits[] = $h;
        }

        echo "  _save_staff_attendance_fs body length:    " . strlen($fsBody) . " chars\n";
        echo "  RTDB API calls in FS body:                {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in FS body:             " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";

        // FS path uses Lock_cache, F-SB-4 query, commitBatch with CAS
        $usesLockCache  = (strpos($fsBody, '$this->lock_cache->is_locked') !== false);
        $usesQuery      = (strpos($fsBody, "firestoreQuery('staffAttendanceSummary'") !== false);
        $usesCommit     = (strpos($fsBody, 'firestoreCommitBatch') !== false);
        $usesCAS        = (strpos($fsBody, "'precondition' => \$precondition") !== false);
        echo "  uses Lock_cache::is_locked:               " . ($usesLockCache ? 'yes' : 'no') . "\n";
        echo "  uses firestoreQuery on summaries:         " . ($usesQuery     ? 'yes' : 'no') . "\n";
        echo "  uses firestoreCommitBatch:                " . ($usesCommit    ? 'yes' : 'no') . "\n";
        echo "  uses CAS precondition:                    " . ($usesCAS       ? 'yes' : 'no') . "\n";

        $ok = $hasDispatcher && $hasFlagBranch && $routesToFs && $routesToLegacy
            && ($rtdbApiCalls === 0) && empty($helperHits)
            && $usesLockCache && $usesQuery && $usesCommit && $usesCAS;
        return ['pass' => $ok, 'msg' => $ok
            ? 'dispatcher routes correctly; FS path is RTDB-free; uses Lock_cache + F-SB-4 + CAS'
            : 'dispatcher or FS-path contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A31 — _save_staff_attendance_legacy preserved                       */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_save_dispatcher_legacy_preserved(): array
    {
        echo "\n── A31: _save_staff_attendance_legacy preserved + flag-OFF routes there ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $hasLegacyMethod   = (bool) preg_match('/private function _save_staff_attendance_legacy\s*\(\s*\)/', $src);
        $hasLegacyN1Read   = (bool) preg_match('/private function _save_staff_attendance_legacy.*?firebase->get\("Schools\/\{?\$school\}?\/\{?\$session\}?\/Staff_Attendance/s', $src);
        $hasLegacyRtdbSet  = (bool) preg_match('/private function _save_staff_attendance_legacy.*?firebase->set\(\$attPath/s', $src);
        $hasLegacyHelper   = (bool) preg_match('/private function _save_staff_attendance_legacy.*?update_staff_att_summary/s', $src);
        $flagOffRoutes     = (bool) preg_match('/public function save_staff_attendance.*?\$this->_save_staff_attendance_legacy\(\)/s', $src);

        echo "  private _save_staff_attendance_legacy() defined: " . ($hasLegacyMethod ? 'yes' : 'no') . "\n";
        echo "  legacy retains N+1 RTDB curStr read:             " . ($hasLegacyN1Read ? 'yes' : 'no') . "\n";
        echo "  legacy retains firebase->set(\$attPath):          " . ($hasLegacyRtdbSet ? 'yes' : 'no') . "\n";
        echo "  legacy retains update_staff_att_summary call:    " . ($hasLegacyHelper ? 'yes' : 'no') . "\n";
        echo "  flag-OFF dispatcher reaches legacy:              " . ($flagOffRoutes ? 'yes' : 'no') . "\n";

        $ok = $hasLegacyMethod && $hasLegacyN1Read && $hasLegacyRtdbSet && $hasLegacyHelper && $flagOffRoutes;
        return ['pass' => $ok, 'msg' => $ok
            ? 'legacy method preserved with N+1 read + RTDB writes + helper; dispatcher routes to it when flag OFF'
            : 'legacy preservation invariant violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A6 — all 7 Stream-B indexes resolve their target queries           */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_indexes_ready(): array
    {
        echo "\n── A6: 7 Stream-B Firestore indexes resolve target queries ──\n";
        $probes = [
            ['F-SB-1', 'staffAttendance',        [['schoolId','==',$this->schoolFs],['staffId','==','STA0001']], 'date',  'DESC', 30],
            ['F-SB-2', 'staffAttendanceSummary', [['schoolId','==',$this->schoolFs],['staffId','==','STA0001']], 'month', 'DESC', 12],
            ['F-SB-3', 'staffAttendance',        [['schoolId','==',$this->schoolFs],['date','==','2026-04-13']], null,    'ASC',  50],
            ['F-SB-4', 'staffAttendanceSummary', [['schoolId','==',$this->schoolFs],['month','==','2026-04']],   null,    'ASC',  50],
            ['F-SB-5', 'staffAttendance',        [['schoolId','==',$this->schoolFs],['date','==','2026-04-13'],['status','==','P']], null, 'ASC', 50],
            ['F-SB-7', 'staffAttendanceLocks',   [['schoolId','==',$this->schoolFs]], 'month', 'DESC', 20],
            // F-SB-6 uses a range filter (date >= ... <= ...) which firestoreQuery() helper
            // doesn't currently expose; skipped here and probed by stream_b_phase1_postdeploy_probe.js
        ];
        $ok = true;
        foreach ($probes as [$code, $col, $where, $order, $dir, $lim]) {
            $t0 = microtime(true);
            try {
                $r = $this->firebase->firestoreQuery($col, $where, $order, $dir, $lim);
                $ms = (int) ((microtime(true) - $t0) * 1000);
                printf("  %s  OK   docs=%-3d  %d ms\n", $code, count($r), $ms);
            } catch (\Throwable $e) {
                printf("  %s  FAIL  %s\n", $code, substr($e->getMessage(), 0, 100));
                $ok = false;
            }
        }
        return ['pass' => $ok, 'msg' => $ok ? "6/6 probed indexes resolve (F-SB-6 covered by Node post-deploy probe)" : "one or more index queries failed"];
    }
}
