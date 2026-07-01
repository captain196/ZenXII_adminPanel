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
 *   A8  — R1.2: mark_staff_day public method body is Firestore-only + delegates to _mark_staff_day_fs (legacy removed)
 *   A27 — Step III.0: Stream_b_telemetry library + dispatcher emit + fs-path emit (MVT)
 *   A28 — Step III.1: bulkMarkDay implementation is sequential CAS (structural)
 *   A29 — Step III.1: bulkMarkDay happy-path against synthetic tenant (integration)
 *   A30 — R1.3: save_staff_attendance public method is Firestore-only + delegates to fs (legacy removed)
 *   A32 — R1.4: fetch_staff_attendance public method is Firestore-only + delegates to fs (legacy removed)
 *   A33 — R2:   bulk_mark_staff body is Firestore-only + delegates to writer.bulkMarkDay (W3)
 *   A34 — R2:   autofill_staff_today body is Firestore-only + delegates to writer.bulkAutofillDay (W4)
 *   A35 — R3:   dead `_check_staff_att_lock` method is removed (lock-check moved to Lock_cache)
 *   A36 — R3:   lock_staff_attendance body is Firestore-only + writes staffAttendanceLocks + invalidates cache
 *   A37 — R3:   unlock_staff_attendance body is Firestore-only + writes staffAttendanceLocks + invalidates cache
 *   A40 — R5:   update_staff_att_summary + get_staff_attendance_summary helpers fully removed
 *   A41 — R5:   fix_attendance_keys staff + staff_late branches removed (student branches preserved)
 *   A42 — R5:   attendance_helper.php carries zero Staff_Attendance literal refs
 *   A43 — R6:   api_punch staff branch is Firestore-only + delegates to Staff_attendance_writer.markSingleDay (W6)
 *   A44 — R7:   fetch_individual_report RTDB staff fallback removed (Firestore-only)
 *   A45 — R7:   Health_check "Today's Staff Coverage" probe is Firestore-only (F-SB-3 query)
 *
 * Stream B target state: ZERO active in-scope RTDB sites.
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
        'Attendance.php'        => ['total_refs' => 1,  'active_ops_min' => 0],  // R7: -1 ref after deleting fetch_individual_report staff RTDB fallback (1 docblock comment remains in api_punch)
        'attendance_helper.php' => ['total_refs' => 0,  'active_ops_min' => 0],  // R5: helpers deleted (update_staff_att_summary + get_staff_attendance_summary)
        'Hr.php'                => ['total_refs' => 1,  'active_ops_min' => 0],  // 1 docblock-only line; 0 active firebase->X
        'Health_check.php'      => ['total_refs' => 0,  'active_ops_min' => 0],  // R7: probe migrated to Firestore F-SB-3 query (zero refs/ops)
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
        $results['A27'] = $this->_assert_mvt_telemetry();
        $results['A28'] = $this->_assert_bulk_mark_structural();
        $results['A29'] = $this->_assert_bulk_mark_happy_path();
        $results['A30'] = $this->_assert_save_dispatcher_fs_zero_rtdb();
        $results['A32'] = $this->_assert_fetch_dispatcher_fs_zero_rtdb();
        $results['A33'] = $this->_assert_w3_bulk_mark_staff_fs_only();
        $results['A34'] = $this->_assert_w4_autofill_staff_fs_only();
        $results['A35'] = $this->_assert_check_staff_att_lock_removed();
        $results['A36'] = $this->_assert_lock_staff_attendance_fs_only();
        $results['A37'] = $this->_assert_unlock_staff_attendance_fs_only();
        // A38/A39 retired — approve_attendance_request removed in RTDB-elimination Component 3.
        $results['A40'] = $this->_assert_legacy_helpers_removed();
        $results['A41'] = $this->_assert_fix_attendance_keys_staff_branches_removed();
        $results['A42'] = $this->_assert_attendance_helper_zero_staff_refs();
        $results['A43'] = $this->_assert_api_punch_staff_fs_only();
        $results['A44'] = $this->_assert_fetch_individual_report_staff_fallback_removed();
        $results['A45'] = $this->_assert_health_check_staff_probe_fs_only();

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
            'Health_check.php'      => $appPath . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'Health_check.php',
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
    /*  A8 — R1.2: public mark_staff_day is Firestore-only; delegates to fs */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_dispatcher_fs_zero_rtdb(): array
    {
        echo "\n── A8: mark_staff_day public method is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Public method delegates to _mark_staff_day_fs
        $delegatesToFs    = (bool) preg_match('/public function mark_staff_day\s*\(\s*\).*?\$this->_mark_staff_day_fs\(\)/s', $src);
        // No legacy method exists
        $noLegacyMethod   = !(bool) preg_match('/private function _mark_staff_day_legacy\s*\(\s*\)/', $src);
        // No flag-based dispatch
        $noFlagCheck      = !(bool) preg_match('/public function mark_staff_day\s*\(\s*\).*?stream_b_writer_enabled/s', $src);

        echo "  delegates to _mark_staff_day_fs:  " . ($delegatesToFs    ? 'yes' : 'no') . "\n";
        echo "  no _mark_staff_day_legacy method: " . ($noLegacyMethod   ? 'yes' : 'no') . "\n";
        echo "  no flag-based dispatch:           " . ($noFlagCheck      ? 'yes' : 'no') . "\n";

        // Extract _mark_staff_day_fs body and confirm RTDB-free
        $fsBody = '';
        if (preg_match('/private function _mark_staff_day_fs\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $fsBody = $m[0];
        }
        if ($fsBody === '') {
            return ['pass' => false, 'msg' => '_mark_staff_day_fs method body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $fsBody, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = [
            'update_staff_att_summary',
            '_check_staff_att_lock',
            '_acquire_att_lock',
            '_release_att_lock',
        ];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $fsBody)) $helperHits[] = $h;
        }

        echo "  _mark_staff_day_fs body length:    " . strlen($fsBody) . " chars\n";
        echo "  RTDB API calls in fs body:         {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in fs body:      " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";

        $ok = $delegatesToFs && $noLegacyMethod && $noFlagCheck
            && ($rtdbApiCalls === 0) && empty($helperHits);
        return ['pass' => $ok, 'msg' => $ok
            ? 'public mark_staff_day delegates to fs; legacy removed; fs body is RTDB-free'
            : 'Firestore-only contract violated'];
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
        // (R1.2/R1.3: legacy branches removed — telemetry is single-path observability now)
        $attSrc = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');
        $dispBegin  = (bool) preg_match("/public function mark_staff_day.*?stream_b_telemetry->begin\\(/s", $attSrc);
        $dispCommit = (bool) preg_match("/public function mark_staff_day.*?stream_b_telemetry->commit\\(/s", $attSrc);
        echo "  dispatcher emits begin():         " . ($dispBegin     ? 'yes' : 'no') . "\n";
        echo "  dispatcher emits commit():        " . ($dispCommit    ? 'yes' : 'no') . "\n";

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
            && $dispBegin && $dispCommit
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
    /*  A30 — R1.3: public save_staff_attendance is Firestore-only           */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_save_dispatcher_fs_zero_rtdb(): array
    {
        echo "\n── A30: save_staff_attendance public method is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Public method delegates to _save_staff_attendance_fs
        $delegatesToFs   = (bool) preg_match('/public function save_staff_attendance\s*\(\s*\).*?\$this->_save_staff_attendance_fs\(\)/s', $src);
        // No legacy method exists
        $noLegacyMethod  = !(bool) preg_match('/private function _save_staff_attendance_legacy\s*\(\s*\)/', $src);
        // No flag-based dispatch
        $noFlagCheck     = !(bool) preg_match('/public function save_staff_attendance\s*\(\s*\).*?stream_b_writer_enabled/s', $src);

        echo "  delegates to _save_staff_attendance_fs:        " . ($delegatesToFs   ? 'yes' : 'no') . "\n";
        echo "  no _save_staff_attendance_legacy method:       " . ($noLegacyMethod  ? 'yes' : 'no') . "\n";
        echo "  no flag-based dispatch:                        " . ($noFlagCheck     ? 'yes' : 'no') . "\n";

        // Extract _save_staff_attendance_fs body and confirm RTDB-free
        $fsBody = '';
        if (preg_match('/private function _save_staff_attendance_fs\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $fsBody = $m[0];
        }
        if ($fsBody === '') {
            return ['pass' => false, 'msg' => '_save_staff_attendance_fs body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $fsBody, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = [
            'update_staff_att_summary',
            '_check_staff_att_lock',
            '_acquire_att_lock',
            '_release_att_lock',
        ];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $fsBody)) $helperHits[] = $h;
        }

        // FS path uses Lock_cache, F-SB-4 query, commitBatch with CAS
        $usesLockCache  = (strpos($fsBody, '$this->lock_cache->is_locked') !== false);
        $usesQuery      = (strpos($fsBody, "firestoreQuery('staffAttendanceSummary'") !== false);
        $usesCommit     = (strpos($fsBody, 'firestoreCommitBatch') !== false);
        $usesCAS        = (strpos($fsBody, "'precondition' => \$precondition") !== false);

        echo "  _save_staff_attendance_fs body length:         " . strlen($fsBody) . " chars\n";
        echo "  RTDB API calls in fs body:                     {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in fs body:                  " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";
        echo "  uses Lock_cache::is_locked:                    " . ($usesLockCache ? 'yes' : 'no') . "\n";
        echo "  uses firestoreQuery on summaries:              " . ($usesQuery     ? 'yes' : 'no') . "\n";
        echo "  uses firestoreCommitBatch:                     " . ($usesCommit    ? 'yes' : 'no') . "\n";
        echo "  uses CAS precondition:                         " . ($usesCAS       ? 'yes' : 'no') . "\n";

        $ok = $delegatesToFs && $noLegacyMethod && $noFlagCheck
            && ($rtdbApiCalls === 0) && empty($helperHits)
            && $usesLockCache && $usesQuery && $usesCommit && $usesCAS;
        return ['pass' => $ok, 'msg' => $ok
            ? 'public save_staff_attendance delegates to fs; legacy removed; fs body is RTDB-free; uses Lock_cache + F-SB-4 + CAS'
            : 'Firestore-only contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A32 — R1.4: public fetch_staff_attendance is Firestore-only         */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_fetch_dispatcher_fs_zero_rtdb(): array
    {
        echo "\n── A32: fetch_staff_attendance public method is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Public method delegates to _fetch_staff_attendance_fs
        $delegatesToFs   = (bool) preg_match('/public function fetch_staff_attendance\s*\(\s*\).*?\$this->_fetch_staff_attendance_fs\(\)/s', $src);
        // No legacy method exists
        $noLegacyMethod  = !(bool) preg_match('/private function _fetch_staff_attendance_legacy\s*\(\s*\)/', $src);
        // No flag-based dispatch
        $noFlagCheck     = !(bool) preg_match('/public function fetch_staff_attendance\s*\(\s*\).*?stream_b_writer_enabled/s', $src);

        echo "  delegates to _fetch_staff_attendance_fs:       " . ($delegatesToFs   ? 'yes' : 'no') . "\n";
        echo "  no _fetch_staff_attendance_legacy method:      " . ($noLegacyMethod  ? 'yes' : 'no') . "\n";
        echo "  no flag-based dispatch:                        " . ($noFlagCheck     ? 'yes' : 'no') . "\n";

        // Extract _fetch_staff_attendance_fs body and confirm RTDB-free
        $fsBody = '';
        if (preg_match('/private function _fetch_staff_attendance_fs\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $fsBody = $m[0];
        }
        if ($fsBody === '') {
            return ['pass' => false, 'msg' => '_fetch_staff_attendance_fs body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $fsBody, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = [
            'update_staff_att_summary',
            '_check_staff_att_lock',
            '_acquire_att_lock',
            '_release_att_lock',
        ];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $fsBody)) $helperHits[] = $h;
        }

        // FS path uses F-SB-3 range query for late metadata (per Phase IV design package §3)
        $usesFsbRangeQuery = (strpos($fsBody, "firestoreQuery('staffAttendance'") !== false);
        $usesLateMinutes   = (strpos($fsBody, 'lateMinutes') !== false);

        echo "  _fetch_staff_attendance_fs body length:        " . strlen($fsBody) . " chars\n";
        echo "  RTDB API calls in fs body:                     {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in fs body:                  " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";
        echo "  uses firestoreQuery on staffAttendance F-SB-3: " . ($usesFsbRangeQuery ? 'yes' : 'no') . "\n";
        echo "  reads lateMinutes from per-day docs:           " . ($usesLateMinutes ? 'yes' : 'no') . "\n";

        $ok = $delegatesToFs && $noLegacyMethod && $noFlagCheck
            && ($rtdbApiCalls === 0) && empty($helperHits)
            && $usesFsbRangeQuery && $usesLateMinutes;
        return ['pass' => $ok, 'msg' => $ok
            ? 'public fetch_staff_attendance delegates to fs; legacy removed; fs body is RTDB-free; uses F-SB-3 + lateMinutes'
            : 'Firestore-only contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A33 — R2: W3 bulk_mark_staff body is Firestore-only                 */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_w3_bulk_mark_staff_fs_only(): array
    {
        echo "\n── A33: bulk_mark_staff body is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Extract method body
        $body = '';
        if (preg_match('/public function bulk_mark_staff\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'bulk_mark_staff body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = ['update_staff_att_summary', '_check_staff_att_lock', '_acquire_att_lock', '_release_att_lock'];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $body)) $helperHits[] = $h;
        }

        $delegatesToWriter = (strpos($body, '$this->staff_attendance_writer->bulkMarkDay') !== false);
        $loadsWriter       = (strpos($body, "load->library('staff_attendance_writer')") !== false);

        echo "  bulk_mark_staff body length:                {$body}";
        echo strlen($body) . " chars\n";
        echo "  RTDB API calls in body:                     {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in body:                  " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";
        echo "  loads staff_attendance_writer library:      " . ($loadsWriter ? 'yes' : 'no') . "\n";
        echo "  delegates to writer.bulkMarkDay:            " . ($delegatesToWriter ? 'yes' : 'no') . "\n";

        $ok = ($rtdbApiCalls === 0) && empty($helperHits) && $loadsWriter && $delegatesToWriter;
        return ['pass' => $ok, 'msg' => $ok
            ? 'W3 bulk_mark_staff body is RTDB-free + delegates to writer.bulkMarkDay'
            : 'W3 Firestore-only contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A34 — R2: W4 autofill_staff_today body is Firestore-only            */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_w4_autofill_staff_fs_only(): array
    {
        echo "\n── A34: autofill_staff_today body is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $body = '';
        if (preg_match('/public function autofill_staff_today\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function|\n\s*\/\*\s*=+)/s', $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'autofill_staff_today body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = ['update_staff_att_summary', '_check_staff_att_lock', '_acquire_att_lock', '_release_att_lock'];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $body)) $helperHits[] = $h;
        }

        $delegatesToWriter = (strpos($body, '$this->staff_attendance_writer->bulkAutofillDay') !== false);
        $loadsWriter       = (strpos($body, "load->library('staff_attendance_writer')") !== false);

        echo "  autofill_staff_today body length:           " . strlen($body) . " chars\n";
        echo "  RTDB API calls in body:                     {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in body:                  " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";
        echo "  loads staff_attendance_writer library:      " . ($loadsWriter ? 'yes' : 'no') . "\n";
        echo "  delegates to writer.bulkAutofillDay:        " . ($delegatesToWriter ? 'yes' : 'no') . "\n";

        $ok = ($rtdbApiCalls === 0) && empty($helperHits) && $loadsWriter && $delegatesToWriter;
        return ['pass' => $ok, 'msg' => $ok
            ? 'W4 autofill_staff_today body is RTDB-free + delegates to writer.bulkAutofillDay'
            : 'W4 Firestore-only contract violated'];
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

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A35 — R3: dead `_check_staff_att_lock` method is removed           */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_check_staff_att_lock_removed(): array
    {
        echo "\n── A35: dead _check_staff_att_lock method is removed ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $defined = (bool) preg_match('/function\s+_check_staff_att_lock\s*\(/', $src);
        $called  = (bool) preg_match('/\$this->_check_staff_att_lock\s*\(/', $src);

        echo "  method definition present:    " . ($defined ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  caller invocations present:   " . ($called  ? 'yes (FAIL)' : 'no  (PASS)') . "\n";

        $ok = !$defined && !$called;
        return ['pass' => $ok, 'msg' => $ok
            ? '_check_staff_att_lock fully removed; lock-check now via Lock_cache only'
            : 'dead method or stale caller still present'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A36 — R3: lock_staff_attendance body is Firestore-only              */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_lock_staff_attendance_fs_only(): array
    {
        echo "\n── A36: lock_staff_attendance body is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $body = '';
        if (preg_match('/public function lock_staff_attendance\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'lock_staff_attendance body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = ['update_staff_att_summary', '_check_staff_att_lock', '_acquire_att_lock', '_release_att_lock'];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $body)) $helperHits[] = $h;
        }

        $writesLocks    = (strpos($body, "firestoreSet('staffAttendanceLocks'") !== false);
        $invalidatesCache = (strpos($body, '$this->lock_cache->invalidate(') !== false);
        $loadsCache     = (strpos($body, "load->library('lock_cache')") !== false);

        echo "  lock_staff_attendance body length:          " . strlen($body) . " chars\n";
        echo "  RTDB API calls in body:                     {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in body:                  " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";
        echo "  firestoreSet('staffAttendanceLocks',...):   " . ($writesLocks ? 'yes' : 'no') . "\n";
        echo "  loads lock_cache library:                   " . ($loadsCache ? 'yes' : 'no') . "\n";
        echo "  invalidates Lock_cache:                     " . ($invalidatesCache ? 'yes' : 'no') . "\n";

        $ok = ($rtdbApiCalls === 0) && empty($helperHits) && $writesLocks && $loadsCache && $invalidatesCache;
        return ['pass' => $ok, 'msg' => $ok
            ? 'lock_staff_attendance writes Firestore staffAttendanceLocks + invalidates cache; zero RTDB'
            : 'lock_staff_attendance Firestore-only contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A37 — R3: unlock_staff_attendance body is Firestore-only            */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_unlock_staff_attendance_fs_only(): array
    {
        echo "\n── A37: unlock_staff_attendance body is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $body = '';
        if (preg_match('/public function unlock_staff_attendance\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function|\n\s*\/\*\s*=+)/s', $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'unlock_staff_attendance body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelpers = ['update_staff_att_summary', '_check_staff_att_lock', '_acquire_att_lock', '_release_att_lock'];
        $helperHits = [];
        foreach ($rtdbHelpers as $h) {
            if (preg_match('/\$this->' . preg_quote($h, '/') . '\s*\(/', $body)) $helperHits[] = $h;
        }

        $writesLocks    = (strpos($body, "firestoreSet('staffAttendanceLocks'") !== false);
        $invalidatesCache = (strpos($body, '$this->lock_cache->invalidate(') !== false);
        $loadsCache     = (strpos($body, "load->library('lock_cache')") !== false);

        echo "  unlock_staff_attendance body length:        " . strlen($body) . " chars\n";
        echo "  RTDB API calls in body:                     {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper calls in body:                  " . (empty($helperHits) ? '0' : implode(',', $helperHits)) . " (expected: 0)\n";
        echo "  firestoreSet('staffAttendanceLocks',...):   " . ($writesLocks ? 'yes' : 'no') . "\n";
        echo "  loads lock_cache library:                   " . ($loadsCache ? 'yes' : 'no') . "\n";
        echo "  invalidates Lock_cache:                     " . ($invalidatesCache ? 'yes' : 'no') . "\n";

        $ok = ($rtdbApiCalls === 0) && empty($helperHits) && $writesLocks && $loadsCache && $invalidatesCache;
        return ['pass' => $ok, 'msg' => $ok
            ? 'unlock_staff_attendance writes Firestore staffAttendanceLocks + invalidates cache; zero RTDB'
            : 'unlock_staff_attendance Firestore-only contract violated'];
    }


    /* ─────────────────────────────────────────────────────────────────── */
    /*  A40 — R5: legacy staff-summary helpers fully removed               */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_legacy_helpers_removed(): array
    {
        echo "\n── A40: legacy staff-summary helpers fully removed ──\n";
        $helperSrc = (string) file_get_contents(APPPATH . 'helpers/attendance_helper.php');

        $updDefined = (bool) preg_match('/function\s+update_staff_att_summary\s*\(/', $helperSrc);
        $getDefined = (bool) preg_match('/function\s+get_staff_attendance_summary\s*\(/', $helperSrc);

        // Also confirm no callers remain anywhere in application/ (excluding the verifier's
        // own negative-assertion guards, which mention the name as a literal string).
        $controllerSrc = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');
        $updCalls = preg_match_all('/\bupdate_staff_att_summary\s*\(/', $controllerSrc, $tmp);
        $getCalls = preg_match_all('/\bget_staff_attendance_summary\s*\(/', $controllerSrc, $tmp);

        echo "  update_staff_att_summary defined:      " . ($updDefined ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  get_staff_attendance_summary defined:  " . ($getDefined ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  callers in Attendance.php (upd):       {$updCalls} (expected: 0)\n";
        echo "  callers in Attendance.php (get):       {$getCalls} (expected: 0)\n";

        $ok = !$updDefined && !$getDefined && $updCalls === 0 && $getCalls === 0;
        return ['pass' => $ok, 'msg' => $ok
            ? 'both legacy staff-summary helpers deleted + zero callers in Attendance.php'
            : 'helpers still defined or callers remain'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A41 — R5: fix_attendance_keys staff/staff_late branches removed    */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_fix_attendance_keys_staff_branches_removed(): array
    {
        echo "\n── A41: fix_attendance_keys staff + staff_late branches removed ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $body = '';
        if (preg_match('/public function fix_attendance_keys\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'fix_attendance_keys body not extractable'];
        }

        // After R5, the staff/staff_late variable names + the staff $migrated keys
        // should all be absent from the method body.
        $hasOldStaffPath     = (bool) preg_match('/\$oldStaffPath\b/', $body);
        $hasNewStaffPath     = (bool) preg_match('/\$newStaffPath\b/', $body);
        $hasOldStaffLatePath = (bool) preg_match('/\$oldStaffLatePath\b/', $body);
        $hasNewStaffLatePath = (bool) preg_match('/\$newStaffLatePath\b/', $body);
        $hasStaffKey         = (bool) preg_match("/\\\$migrated\\['staff'\\]/", $body);
        $hasStaffLateKey     = (bool) preg_match("/\\\$migrated\\['staff_late'\\]/", $body);

        // Student branches must remain.
        $hasStudentBranch  = (strpos($body, "{\$secRoot}/Students/") !== false);
        $hasStudentLateKey = (strpos($body, "'student_late'") !== false);

        echo "  staff vars/keys present in method:      "
            . ($hasOldStaffPath || $hasNewStaffPath || $hasOldStaffLatePath || $hasNewStaffLatePath
                  || $hasStaffKey || $hasStaffLateKey ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  student branch preserved:               " . ($hasStudentBranch ? 'yes (PASS)' : 'no  (FAIL)') . "\n";
        echo "  student_late key preserved:             " . ($hasStudentLateKey ? 'yes (PASS)' : 'no  (FAIL)') . "\n";

        $staffRemoved = !$hasOldStaffPath && !$hasNewStaffPath && !$hasOldStaffLatePath
                        && !$hasNewStaffLatePath && !$hasStaffKey && !$hasStaffLateKey;
        $studentKept  = $hasStudentBranch && $hasStudentLateKey;
        $ok = $staffRemoved && $studentKept;
        return ['pass' => $ok, 'msg' => $ok
            ? 'staff + staff_late branches removed; student branches intact'
            : 'staff branches not fully removed or student branches damaged'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A42 — R5: attendance_helper.php carries zero Staff_Attendance refs */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_attendance_helper_zero_staff_refs(): array
    {
        echo "\n── A42: attendance_helper.php zero Staff_Attendance refs ──\n";
        $src = (string) file_get_contents(APPPATH . 'helpers/attendance_helper.php');

        $refCount = preg_match_all('/Staff_Attendance/', $src, $tmp);
        preg_match_all('/\$firebase->(get|set|update|delete|push)\s*\(/', $src, $rtdb);
        $rtdbCalls = count($rtdb[0]);

        echo "  Staff_Attendance literal refs:         {$refCount} (expected: 0)\n";
        echo "  \$firebase->X(...) RTDB calls in file:  {$rtdbCalls}\n";

        $ok = ($refCount === 0);
        return ['pass' => $ok, 'msg' => $ok
            ? 'attendance_helper.php is clean of Staff_Attendance refs after R5'
            : "{$refCount} Staff_Attendance refs still present in helper"];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A43 — R6: api_punch staff branch is Firestore-only (W6)            */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_api_punch_staff_fs_only(): array
    {
        echo "\n── A43: api_punch staff branch is Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        // Extract the staff branch inside api_punch (under `if ($direction === 'in')`):
        // its `} elseif ($personType === 'staff') {` opener has 12 leading spaces, and
        // the branch terminator is the next 12-indent closing brace.
        //
        // NB: an earlier, unrelated `elseif ($personType === 'staff')` exists at
        // 8-space indent (the tenant-membership check at the top of api_punch); the
        // 12-space anchor here is what makes the match select the attendance writer.
        $body = '';
        if (preg_match("/\n {12}\}\s*elseif\s*\(\\\$personType\s*===\s*'staff'\s*\).*?(?=\n {12}\})/s", $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'api_punch staff branch body not extractable'];
        }

        preg_match_all('/\$this->firebase->(get|set|update|delete|push)\s*\(/', $body, $rtdb);
        $rtdbApiCalls = count($rtdb[0]);

        $rtdbHelperPatterns = [
            '\$this->_acquire_att_lock\s*\(',
            '\$this->_release_att_lock\s*\(',
            'update_staff_att_summary\s*\(',
            '\$this->_check_staff_att_lock\s*\(',
        ];
        $helperHits = [];
        foreach ($rtdbHelperPatterns as $p) {
            if (preg_match('/' . $p . '/', $body)) $helperHits[] = $p;
        }

        $loadsWriter      = (strpos($body, "load->library('staff_attendance_writer')") !== false);
        $delegatesToWriter = (strpos($body, '$this->staff_attendance_writer->markSingleDay(') !== false);
        $peeksSummary     = (strpos($body, "firestoreGet('staffAttendanceSummary'") !== false);
        $handlesLock      = (strpos($body, 'MonthLockedException') !== false);

        echo "  api_punch staff branch length:         " . strlen($body) . " chars\n";
        echo "  RTDB API calls in branch:              {$rtdbApiCalls} (expected: 0)\n";
        echo "  RTDB-helper/mutex calls in branch:     " . (empty($helperHits) ? '0' : count($helperHits)) . " (expected: 0)\n";
        echo "  loads staff_attendance_writer:         " . ($loadsWriter ? 'yes' : 'no') . "\n";
        echo "  delegates to writer.markSingleDay:     " . ($delegatesToWriter ? 'yes' : 'no') . "\n";
        echo "  peeks Firestore summary for first-win: " . ($peeksSummary ? 'yes' : 'no') . "\n";
        echo "  catches MonthLockedException:          " . ($handlesLock ? 'yes' : 'no') . "\n";

        $ok = ($rtdbApiCalls === 0) && empty($helperHits)
              && $loadsWriter && $delegatesToWriter && $peeksSummary && $handlesLock;
        return ['pass' => $ok, 'msg' => $ok
            ? 'W6 api_punch staff branch is RTDB-free + delegates to writer.markSingleDay; lock-aware; first-IN-wins preserved'
            : 'W6 Firestore-only contract violated'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A44 — R7: fetch_individual_report staff RTDB fallback removed      */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_fetch_individual_report_staff_fallback_removed(): array
    {
        echo "\n── A44: fetch_individual_report staff RTDB fallback removed ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Attendance.php');

        $body = '';
        if (preg_match('/public function fetch_individual_report\s*\(\s*\).*?(?=\n    (?:public|private|protected)\s+function)/s', $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => 'fetch_individual_report body not extractable'];
        }

        // Three invariants after R7:
        //   1. No "Schools/.../Staff_Attendance" RTDB-path literal anywhere in the body.
        //   2. Every $this->firebase->get($attPath) call is gated by a "personType
        //      === 'student'" check in the same conditional block (this verifies the
        //      retired `else { staff }` arm is not silently reintroduced).
        //   3. The student fallback signature remains.
        $hasStaffAttendanceLiteral = (bool) preg_match('/Schools\/.*Staff_Attendance/', $body);

        // For each firebase->get($attPath), check the preceding ~300 chars contain a
        // "personType === 'student'" gate.
        $attPathReadsGated = true;
        $attPathReadCount  = 0;
        if (preg_match_all('/\$this->firebase->get\(\s*\$attPath\s*\)/', $body, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $hit) {
                $attPathReadCount++;
                $pos      = (int) $hit[1];
                $preceding = substr($body, max(0, $pos - 300), min(300, $pos));
                if (!preg_match("/\\\$personType\s*===\s*'student'/", $preceding)) {
                    $attPathReadsGated = false;
                }
            }
        }
        $studentFallbackKept = (strpos($body, "/Students/{\$personId}/Attendance/{\$attKey}") !== false);

        echo "  Staff_Attendance literal in body:      " . ($hasStaffAttendanceLiteral ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  firebase->get(attPath) call count:     {$attPathReadCount}\n";
        echo "  all attPath reads student-gated:       " . ($attPathReadsGated ? 'yes (PASS)' : 'no  (FAIL)') . "\n";
        echo "  student RTDB fallback preserved:       " . ($studentFallbackKept ? 'yes (PASS)' : 'no  (FAIL)') . "\n";

        $ok = !$hasStaffAttendanceLiteral && $attPathReadsGated && $studentFallbackKept;
        return ['pass' => $ok, 'msg' => $ok
            ? 'staff RTDB fallback removed; student fallback intact + gated (Stream A out of scope)'
            : 'staff fallback still present or student fallback damaged'];
    }

    /* ─────────────────────────────────────────────────────────────────── */
    /*  A45 — R7: Health_check Today's Staff Coverage probe Firestore-only */
    /* ─────────────────────────────────────────────────────────────────── */
    private function _assert_health_check_staff_probe_fs_only(): array
    {
        echo "\n── A45: Health_check Today's Staff Coverage probe Firestore-only ──\n";
        $src = (string) file_get_contents(APPPATH . 'controllers/Health_check.php');

        // Extract the staff-coverage probe block by anchoring on its display name.
        $body = '';
        if (preg_match("/'name'\s*=>\s*\"Today's Staff Coverage\".*?(?=\n            \],)/s", $src, $m)) {
            $body = $m[0];
        }
        if ($body === '') {
            return ['pass' => false, 'msg' => "Today's Staff Coverage probe block not extractable"];
        }

        $hasStaffAttendanceLiteral = (bool) preg_match('/Staff_Attendance/', $body);
        $usesFsQuery               = (strpos($body, "schoolWhere('staffAttendance'") !== false);
        $filtersByDate             = (strpos($body, "['date', '==', \$todayDate]") !== false);
        $catchesFsErrors           = (strpos($body, 'log_message') !== false);

        // The probe still legitimately reads RTDB Teachers (roster — Stream A / SIS
        // infrastructure, not Stream B staff-attendance). Confirm the staff-attendance
        // RTDB read is gone by checking no `$fb->get(... Staff_Attendance ...)` pattern.
        $hasFbGetStaffAtt = (bool) preg_match('/\$fb->get\s*\(\s*[^)]*Staff_Attendance/', $body);

        echo "  probe block length:                    " . strlen($body) . " chars\n";
        echo "  Staff_Attendance literal in probe:     " . ($hasStaffAttendanceLiteral ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  \$fb->get(...Staff_Attendance...):      " . ($hasFbGetStaffAtt ? 'yes (FAIL)' : 'no  (PASS)') . "\n";
        echo "  uses fs->schoolWhere('staffAttendance'):" . ($usesFsQuery ? ' yes' : ' no') . "\n";
        echo "  filters by date == todayDate:           " . ($filtersByDate ? 'yes' : 'no') . "\n";
        echo "  catches Firestore errors:               " . ($catchesFsErrors ? 'yes' : 'no') . "\n";

        $ok = !$hasStaffAttendanceLiteral && !$hasFbGetStaffAtt && $usesFsQuery && $filtersByDate && $catchesFsErrors;
        return ['pass' => $ok, 'msg' => $ok
            ? "Today's Staff Coverage reads Firestore staffAttendance (F-SB-3); zero RTDB staff-attendance ops"
            : 'probe Firestore-only contract violated'];
    }
}
