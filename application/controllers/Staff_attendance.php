<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_attendance — runtime endpoints for GPS staff self-attendance.
 *
 * Part of the Attendance Policy Framework (Phase 5). GPS is the first
 * capture method; biometric / QR / RFID / face / manual plug into the SAME
 * orchestration by changing only the request `method` + input context.
 *
 * ORCHESTRATION ONLY (by design)
 * ------------------------------
 * This controller does NOT contain attendance business rules. Its job is:
 *   1. Authenticate (Firebase ID token via Api_auth; uid == staffId).
 *   2. Load Firestore config (schools/{id}.attendancePolicy + currentSession).
 *   3. Load context (holiday flag via Holiday_service; current day mark).
 *   4. Call the Attendance_policy engine for the decision.
 *   5. Persist the status via Staff_attendance_writer (canonical sink).
 *   6. Write an audit record (accepted AND rejected) to attendancePunches.
 *   7. Return the API response.
 *
 * Business rules live in Attendance_policy. Persistence lives in
 * Staff_attendance_writer. Firestore is the only source of truth — this
 * controller performs ZERO RTDB access.
 *
 * Endpoints (Firebase Bearer auth):
 *   POST /staff_attendance/punch   { lat,lng,accuracy,mock,direction,clientPunchId }
 *
 * Identity safety: staffId is ALWAYS the authenticated token uid — never
 * read from the request body. A staff member can only punch themselves.
 */
class Staff_attendance extends MY_Controller
{
    /** Roles permitted to self-punch (parents are excluded). */
    private const STAFF_ROLES = [
        'Teacher', 'Class Teacher', 'Principal', 'Vice Principal',
        'Academic Coordinator', 'Accountant', 'Admin',
        'School Super Admin', 'Super Admin', 'Staff',
    ];

    /** Cached Firebase ID-token claims. */
    private array $_claims = [];

    /** Memoized schools/{id} doc — read ONCE per request (W8: was read twice). */
    private ?array $_schoolDoc = null;
    private bool   $_schoolDocLoaded = false;

    public function __construct()
    {
        // App-API bootstrap (mirrors Ptm.php): Bearer auth, no admin session.
        CI_Controller::__construct();
        $this->load->library('firebase');
        $this->load->library('api_auth');
        $this->load->library('Firestore_service', null, 'fs');

        $claims = $this->api_auth->require_auth();   // dies 401 if invalid
        $this->_claims      = $claims;
        $this->school_id    = (string) ($claims['school_id'] ?? '');
        $this->school_name  = $this->school_id;       // canonical: name == id
        $this->admin_id     = 'app:' . ($claims['uid'] ?? 'unknown');
        $this->admin_role   = (string) ($claims['role'] ?? '');
        $this->session_year = $this->_resolve_session($this->school_id);

        if ($this->school_id === '' || $this->session_year === '') {
            header('Content-Type: application/json');
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => 'Unable to resolve school/session from token.']);
            exit;
        }

        $this->fs->init($this->school_id, $this->session_year);
    }

    /**
     * Memoized read of schools/{id}. The GPS punch flow needs both
     * currentSession (constructor) and attendancePolicy (punch); W8 collapses
     * those to ONE Firestore read per request.
     */
    private function _school_doc(): ?array
    {
        if (!$this->_schoolDocLoaded) {
            $this->_schoolDocLoaded = true;
            try {
                $d = $this->firebase->firestoreGet('schools', $this->school_id);
                $this->_schoolDoc = is_array($d) ? $d : null;
            } catch (\Throwable $e) {
                log_message('error', 'Staff_attendance::_school_doc read failed: ' . $e->getMessage());
                $this->_schoolDoc = null;
            }
        }
        return $this->_schoolDoc;
    }

    /**
     * Resolve the active session — schools/{id}.currentSession is the SOLE
     * authority (fail-closed). Uses the memoized school doc (W8).
     */
    private function _resolve_session(string $schoolId): string
    {
        if ($schoolId === '') return '';
        $doc = $this->_school_doc();
        return (is_array($doc) && !empty($doc['currentSession'])) ? (string) $doc['currentSession'] : '';
    }

    /**
     * POST /staff_attendance/punch — GPS check-in / check-out.
     *
     * Firestore reads (accepted check-in): schools (1) + holiday (≤1 cold,
     *   cached) + summary peek (1) + writer summary (1) = ~3-4.
     * Firestore writes: accepted check-in = att+summary batch (2) + audit (1);
     *   check-out / rejected = audit (1). RTDB: none.
     */
    public function punch()
    {
        $this->api_auth->require_role(self::STAFF_ROLES);   // dies 403 if not staff
        $staffId = (string) ($this->_claims['uid'] ?? '');
        if ($staffId === '') {
            return $this->json_error('Unable to resolve staff identity.', 401);
        }

        // ── Parse input (JSON body or form) ──
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) $body = $_POST;

        $direction = (string) ($body['direction'] ?? 'in');
        $clientPunchId = (string) ($body['clientPunchId'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $clientPunchId)) {
            $clientPunchId = '';   // server will mint a random audit id
        }
        $request = [
            'method'    => 'gps',
            'direction' => $direction,
            'lat'       => $body['lat'] ?? null,
            'lng'       => $body['lng'] ?? null,
            'accuracy'  => $body['accuracy'] ?? null,
            'mock'      => !empty($body['mock']),
            // Audit-only context (ignored by the engine):
            'clientCapturedAt' => (string) ($body['clientCapturedAt'] ?? $body['clientTime'] ?? ''),
            'device'           => is_array($body['device'] ?? null) ? $body['device'] : [],
        ];

        // ── ISSUE-1: Active-staff authorization gate (Option B) ──
        // Authoritative Firestore read of staff/{schoolId}_{staffId}; rejects an
        // inactive / missing staff member BEFORE policy / holiday / engine, so a
        // deactivated teacher cannot record attendance even while their token is
        // still valid (deactivation is Firestore-status-only). 403 + audited.
        if (!$this->_assert_staff_active($staffId, $request, $clientPunchId)) {
            return; // 403/503 already sent by the gate; rejection already audited
        }

        // ── Load config: schools/{id} → attendancePolicy (W8: memoized —
        //    reuses the single schools read already done for session) ──
        $schoolDoc = $this->_school_doc();
        $policy = (is_array($schoolDoc) && is_array($schoolDoc['attendancePolicy'] ?? null))
            ? $schoolDoc['attendancePolicy'] : [];

        // ── Resolve server time in the policy timezone ──
        $tz = is_string($policy['timezone'] ?? null) && $policy['timezone'] !== ''
            ? $policy['timezone'] : 'Asia/Kolkata';
        try { $now = new DateTime('now', new DateTimeZone($tz)); }
        catch (\Throwable $e) { $now = new DateTime('now', new DateTimeZone('Asia/Kolkata')); }
        $dateISO    = $now->format('Y-m-d');
        $serverTime = $now->format('Y-m-d H:i:s');
        $monthKey   = substr($dateISO, 0, 7);
        $day        = (int) $now->format('j');

        // ── Context: holiday flag (cached) + current day mark (READ 2) ──
        $this->load->library('holiday_service');
        $this->holiday_service->init($this->fs, $this->school_id, $this->session_year);
        $isHoliday = $this->holiday_service->is_holiday($dateISO);

        $summaryId = "{$this->school_id}_{$staffId}_{$monthKey}";
        $summary   = $this->fs->get('staffAttendanceSummary', $summaryId);
        $dayWise   = is_array($summary) ? (string) ($summary['dayWise'] ?? '') : '';
        $currentMark = (strlen($dayWise) >= $day) ? strtoupper($dayWise[$day - 1]) : 'V';

        // Weekly-off for today (from policy.weeklyOffs, engine-authoritative).
        $isWeeklyOff = in_array($now->format('D'), (array) ($policy['weeklyOffs'] ?? []), true);

        // On check-out, resolve today's check-in time so the engine can classify
        // full vs half day by hours worked. Only read when checking out.
        $checkInMinutes = ($direction === 'out')
            ? $this->_today_checkin_minutes($staffId, $dateISO)
            : null;

        // ── Decision: pure business-rule engine ──
        $this->load->library('attendance_policy');
        $decision = $this->attendance_policy->evaluate($policy, $request, [
            'serverTime'     => $serverTime,
            'currentDayMark' => $currentMark,
            'isHoliday'      => $isHoliday,
            'isWeeklyOff'    => $isWeeklyOff,
            'checkInMinutes' => $checkInMinutes,
            'shiftId'        => 'default',
        ]);

        // ── Persist status (only when the engine says so) ──
        if ($decision['allowed'] && $decision['setsStatus'] && $decision['mark'] !== null) {
            $this->load->library('staff_attendance_writer');
            try {
                $this->staff_attendance_writer->init($this->firebase, $this->school_id, $this->session_year);
                $this->staff_attendance_writer->markSingleDay($staffId, $dateISO, $decision['mark'], [
                    'markedBy'     => 'gps:' . $staffId,
                    'source'       => 'gps_self',
                    'lateMinutes'  => (int) $decision['lateMinutes'],
                    'capturedById' => $staffId,
                    'punchEventId' => $clientPunchId,
                ]);
            } catch (MonthLockedException $e) {
                $decision['allowed']    = false;
                $decision['reason']     = 'month_locked';
                $decision['setsStatus'] = false;
                $decision['mark']       = null;
            } catch (\Throwable $e) {
                log_message('error', 'Staff_attendance::punch writer failed: ' . $e->getMessage());
                $this->_record_punch($staffId, $dateISO, $serverTime, $request, [
                    'allowed' => false, 'reason' => 'writer_failure', 'mark' => null,
                    'distanceMeters' => $decision['distanceMeters'] ?? null, 'direction' => $direction,
                ], $clientPunchId);
                return $this->json_error('Could not record attendance. Please retry.', 500);
            }
        }

        // ── Audit (accepted AND rejected) → attendancePunches ──
        $this->_record_punch($staffId, $dateISO, $serverTime, $request, $decision, $clientPunchId);

        // ── Response ──
        if (!$decision['allowed']) {
            return $this->json_error($this->_reason_message($decision['reason']),
                $this->_reason_http($decision['reason']));
        }

        return $this->json_success([
            'apiVersion'     => 1,
            'message'        => $this->_punch_message($decision, $direction),
            'direction'      => $direction,
            'status'         => $decision['mark'],     // P|T|null
            'mark'           => $decision['mark'],
            'lateMinutes'    => (int) $decision['lateMinutes'],
            'distanceMeters' => $decision['distanceMeters'],
            'checkInAt'      => $now->format('c'),
            'date'           => $dateISO,
        ]);
    }

    /**
     * GET /staff_attendance/me — structured, client-agnostic attendance read.
     *
     * Returns DOMAIN data (no presentation): today's status + check-in/out
     * times, the current-month summary counts, and a last-30-days history.
     * Consumable by the Teacher app, a future Employee app, HR/Admin portals,
     * payroll, and analytics — each renders independently.
     *
     * Firestore reads: current-month summary (1) + previous-month summary (1)
     *   + today's punches (1 query) = ~3. Writes: 0. RTDB: none.
     */
    public function me()
    {
        $this->api_auth->require_role(self::STAFF_ROLES);
        $staffId = (string) ($this->_claims['uid'] ?? '');
        if ($staffId === '') {
            return $this->json_error('Unable to resolve staff identity.', 401);
        }
        $this->load->helper('attendance_view');

        $tz = 'Asia/Kolkata';
        $schoolDoc = $this->_school_doc(); // W8: memoized single read
        $pol = (is_array($schoolDoc) && is_array($schoolDoc['attendancePolicy'] ?? null))
            ? $schoolDoc['attendancePolicy'] : [];
        if (is_string($pol['timezone'] ?? null) && $pol['timezone'] !== '') $tz = $pol['timezone'];

        try { $now = new DateTime('now', new DateTimeZone($tz)); }
        catch (\Throwable $e) { $now = new DateTime('now', new DateTimeZone('Asia/Kolkata')); }
        $dateISO  = $now->format('Y-m-d');
        $day      = (int) $now->format('j');
        $monthKey = $now->format('Y-m');
        $prevKey  = (clone $now)->modify('first day of last month')->format('Y-m');

        // Current + previous month summaries (for status, counts, 30-day window).
        $curSum  = $this->fs->get('staffAttendanceSummary', "{$this->school_id}_{$staffId}_{$monthKey}");
        $prevSum = $this->fs->get('staffAttendanceSummary', "{$this->school_id}_{$staffId}_{$prevKey}");
        $curSum  = is_array($curSum) ? $curSum : [];
        $prevSum = is_array($prevSum) ? $prevSum : [];

        // Phase 2 — "no vacant": overlay past days → weekly-offs O, holidays H, and
        // unmarked working days → Absent (A) ONLY when policy.autoAbsent is on
        // (default). With autoAbsent off, working days stay Vacant (V). Read-only;
        // the nightly finaliser persists the same marks for payroll.
        $this->load->library('holiday_service');
        $this->holiday_service->init($this->fs, $this->school_id, $this->session_year);
        $curDayWise  = $this->_overlay_daywise((string) ($curSum['dayWise'] ?? ''),  $monthKey, $pol, $dateISO);
        $prevDayWise = $this->_overlay_daywise((string) ($prevSum['dayWise'] ?? ''), $prevKey,  $pol, $dateISO);

        $todayStatus = (strlen($curDayWise) >= $day) ? strtoupper($curDayWise[$day - 1]) : 'V';
        $counts = $this->_count_daywise($curDayWise);

        // Rest-day flags for TODAY (overlay leaves today untouched) so the app
        // can offer the "work on a rest day = extra" flow.
        $todayIsHoliday = false;
        try { $todayIsHoliday = $this->holiday_service->is_holiday($dateISO); } catch (\Throwable $e) {}
        $todayIsWeeklyOff = in_array($now->format('D'), (array) ($pol['weeklyOffs'] ?? []), true);

        $history = av_build_history([
            $prevKey  => $prevDayWise,
            $monthKey => $curDayWise,
        ], $dateISO, 30);

        // Today's accepted punches → check-in/out times.
        $todayPunches = [];
        try {
            $rows = $this->fs->schoolWhere('attendancePunches', [
                ['staffId', '==', $staffId],
                ['date',    '==', $dateISO],
            ]);
            foreach ((array) $rows as $r) {
                $todayPunches[] = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::me punches query failed: ' . $e->getMessage());
        }
        $times = av_today_times($todayPunches);

        return $this->json_success([
            'apiVersion' => 1,
            'staffId'    => $staffId,
            'schoolId'   => $this->school_id,
            'session'    => $this->session_year,
            'serverTime' => $now->format('c'),
            'today'      => [
                'date'           => $dateISO,
                'status'         => $todayStatus,         // P|T|M|A|L|H|O|W|V
                'checkInAt'      => $times['checkInAt'],  // ISO-8601 | null
                'checkOutAt'     => $times['checkOutAt'], // ISO-8601 | null
                'isHoliday'      => $todayIsHoliday,
                'isWeeklyOff'    => $todayIsWeeklyOff,
                'allowWorkOnOff' => !empty($pol['allowWorkOnOff']),
            ],
            'month'      => [
                'monthKey'    => $monthKey,
                'present'     => $counts['P'],
                'absent'      => $counts['A'],
                'leave'       => $counts['L'],
                'holiday'     => $counts['H'],
                'tardy'       => $counts['T'],
                'halfDay'     => $counts['M'],
                'extraWorked' => $counts['W'],
                'weeklyOff'   => $counts['O'],
                'void'        => $counts['V'],
                'workingDays' => $counts['P'] + $counts['T'] + $counts['L'] + $counts['M'],
                'totalDays'   => strlen($curDayWise),
            ],
            'history'    => $history,   // [{date,status}] oldest→newest, last 30 days
        ]);
    }

    /* ─── private: authorization gate (ISSUE-1) ─────────────────────── */

    /**
     * Active-staff authorization gate. Reads the canonical, authoritative
     * staff/{schoolId}_{staffId} doc and allows the punch ONLY when the staff
     * record exists and status == Active. On rejection it records a rejected
     * attendancePunches audit row and sends the HTTP error; the caller returns.
     *
     * Fail-closed: a Firestore read error rejects (retryable 503) rather than
     * letting an unverified principal through. Reuses Firestore_service +
     * _record_punch only — no engine/writer/policy/RTDB involvement.
     *
     * @return bool true → continue; false → response already sent, caller returns.
     */
    private function _assert_staff_active(string $staffId, array $request, string $clientPunchId): bool
    {
        try {
            $doc = $this->fs->get('staff', "{$this->school_id}_{$staffId}");
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::_assert_staff_active read failed: ' . $e->getMessage());
            $this->json_error('Could not verify staff status. Please retry.', 503);
            return false; // fail-closed (retryable)
        }

        $reason = '';
        if (!is_array($doc) || empty($doc)) {
            $reason = 'staff_not_found';
        } else {
            // Tolerant of dual-case schema (status / Status).
            $status = strtolower((string) ($doc['status'] ?? $doc['Status'] ?? ''));
            if ($status !== 'active') $reason = 'staff_inactive';
        }

        if ($reason !== '') {
            $this->_record_punch($staffId, date('Y-m-d'), date('Y-m-d H:i:s'), $request, [
                'allowed'        => false,
                'reason'         => $reason,
                'mark'           => null,
                'distanceMeters' => null,
                'direction'      => (string) ($request['direction'] ?? 'in'),
            ], $clientPunchId);

            $msg = $reason === 'staff_not_found'
                ? 'Staff record not found or not authorized.'
                : 'Your staff account is not active. Please contact the administrator.';
            $this->json_error($msg, 403);
            return false;
        }
        return true;
    }

    /* ─── private: audit + response helpers (orchestration glue) ─────── */

    /**
     * Append one Firestore audit record per attempt (accepted or rejected),
     * carrying geo metadata + rejection reason + server timestamp. Best-effort:
     * an audit-write failure must never break the punch response.
     */
    private function _record_punch(string $staffId, string $dateISO, string $serverTime,
                                   array $request, array $decision, string $clientPunchId): void
    {
        $punchId = $clientPunchId !== ''
            ? "{$this->school_id}_{$clientPunchId}"
            : 'PUNCH_' . bin2hex(random_bytes(8));

        $clientCapturedAt = (string) ($request['clientCapturedAt'] ?? '');

        // Full, self-describing audit record so ANY accepted OR rejected event
        // can be fully reconstructed from Firestore alone (no cross-joins needed).
        $data = [
            // identity + scope
            'schoolId'        => $this->school_id,
            'session'         => $this->session_year,
            'staffId'         => $staffId,
            'personType'      => 'staff',
            // event classification
            'method'          => 'gps',
            'direction'       => (string) ($decision['direction'] ?? ($request['direction'] ?? 'in')),
            'date'            => $dateISO,
            // timestamps (server authoritative + optional client)
            'serverTimestamp' => date('c'),
            'serverTime'      => $serverTime,
            'clientCapturedAt'=> $clientCapturedAt !== '' ? $clientCapturedAt : null,
            // geo evidence
            'lat'             => is_numeric($request['lat'] ?? null) ? (float) $request['lat'] : null,
            'lng'             => is_numeric($request['lng'] ?? null) ? (float) $request['lng'] : null,
            'accuracy'        => is_numeric($request['accuracy'] ?? null) ? (float) $request['accuracy'] : null,
            'mock'            => !empty($request['mock']),
            'distanceMeters'  => $decision['distanceMeters'] ?? null,
            // decision
            'outcome'         => !empty($decision['allowed']) ? 'accepted' : 'rejected',
            'rejectionReason' => (string) ($decision['reason'] ?? ''),
            'mark'            => $decision['mark'] ?? null,
            // device + correlation
            'deviceInfo'      => $this->_sanitize_device($request['device'] ?? []),
            'correlationId'   => $clientPunchId !== '' ? $clientPunchId : $punchId,
            'punchId'         => $punchId,
            'apiVersion'      => 1,
            'createdAt'       => date('c'),
        ];

        try {
            $this->fs->set('attendancePunches', $punchId, $data, true);
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::_record_punch audit write failed: ' . $e->getMessage());
        }
    }

    /**
     * Whitelist + cap device metadata supplied by the client (defensive:
     * never persist arbitrary client-controlled keys/sizes). Returns null
     * when nothing useful was supplied.
     */
    private function _sanitize_device($device): ?array
    {
        if (!is_array($device) || empty($device)) return null;
        $out = [];
        foreach (['model', 'manufacturer', 'os', 'osVersion', 'sdkInt', 'appVersion', 'deviceId'] as $k) {
            if (isset($device[$k]) && (is_string($device[$k]) || is_numeric($device[$k]))) {
                $out[$k] = substr((string) $device[$k], 0, 120);
            }
        }
        return empty($out) ? null : $out;
    }

    /**
     * Earliest accepted check-IN time today, as minutes since midnight (policy
     * tz), or null if none. Feeds the engine's worked-hours (full/half) logic
     * at check-out. Reads the attendancePunches audit rows already written by
     * check-in — no schema change.
     */
    private function _today_checkin_minutes(string $staffId, string $dateISO): ?int
    {
        try {
            $rows = $this->fs->schoolWhere('attendancePunches', [
                ['staffId', '==', $staffId],
                ['date',    '==', $dateISO],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::_today_checkin_minutes query failed: ' . $e->getMessage());
            return null;
        }
        $best = null;
        foreach ((array) $rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
            if (($d['outcome'] ?? '') !== 'accepted' || ($d['direction'] ?? '') !== 'in') continue;
            $st = (string) ($d['serverTime'] ?? '');   // 'Y-m-d H:i:s' in policy tz
            if (!preg_match('/\b(\d{1,2}):(\d{2})/', $st, $m)) continue;
            $min = ((int) $m[1]) * 60 + (int) $m[2];
            if ($best === null || $min < $best) $best = $min;   // earliest IN wins
        }
        return $best;
    }

    /**
     * "No vacant" overlay: for a month's dayWise, replace every PAST unmarked
     * ('V') day with Absent (A), or weekly-off (O) / holiday (H) when the day
     * is a rest day. Today and future days are left untouched. Pure transform
     * (needs holiday_service already init'd).
     */
    private function _overlay_daywise(string $dayWise, string $monthKey, array $policy, string $todayISO): string
    {
        if ($dayWise === '') return $dayWise;
        $weeklyOffs = (array) ($policy['weeklyOffs'] ?? []);
        // autoAbsent (default ON): close past unmarked working days as Absent (A).
        // When OFF, leave them Vacant (V); weekly-offs/holidays are still labelled O/H.
        $autoAbsent = array_key_exists('autoAbsent', $policy) ? !empty($policy['autoAbsent']) : true;
        $len = strlen($dayWise);
        for ($d = 1; $d <= $len; $d++) {
            if (strtoupper($dayWise[$d - 1]) !== 'V') continue;      // only fill vacant
            $dateISO = sprintf('%s-%02d', $monthKey, $d);
            if ($dateISO >= $todayISO) continue;                    // today + future untouched
            try { $isHol = $this->holiday_service->is_holiday($dateISO); }
            catch (\Throwable $e) { $isHol = false; }
            if ($isHol) {
                $dayWise[$d - 1] = 'H';
            } else {
                $dow = date('D', strtotime($dateISO));
                if (in_array($dow, $weeklyOffs, true)) {
                    $dayWise[$d - 1] = 'O';
                } elseif ($autoAbsent) {
                    $dayWise[$d - 1] = 'A';
                }
                // else: autoAbsent OFF → leave the day Vacant (V)
            }
        }
        return $dayWise;
    }

    /** Count each status char in a dayWise string → keyed totals. */
    private function _count_daywise(string $dw): array
    {
        $c = ['P' => 0, 'A' => 0, 'L' => 0, 'H' => 0, 'T' => 0, 'V' => 0, 'M' => 0, 'W' => 0, 'O' => 0];
        $len = strlen($dw);
        for ($i = 0; $i < $len; $i++) {
            $ch = strtoupper($dw[$i]);
            if (isset($c[$ch])) $c[$ch]++;
        }
        return $c;
    }

    /** Human message for an accepted punch, covering half-day / extra / short-day. */
    private function _punch_message(array $decision, string $direction): string
    {
        $mark = $decision['mark'] ?? null;
        if ($direction === 'out') {
            if ($mark === 'M') return 'Checked out — half day recorded.';
            if ($mark === 'A') return 'Checked out — below minimum hours; marked absent.';
            return 'Checked out.';
        }
        if (!empty($decision['setsStatus'])) {
            if ($mark === 'T') return 'Checked in (late).';
            if ($mark === 'W') return 'Checked in — extra day (rest-day working).';
            return 'Checked in.';
        }
        return 'Already checked in.';
    }

    /** Map an engine rejection reason → HTTP status (pure). */
    private function _reason_http(string $reason): int
    {
        switch ($reason) {
            case 'invalid_coords':
            case 'invalid_direction':
                return 400;
            case 'method_disabled':
            case 'gps_disabled':
                return 422;
            case 'mock_location':
            case 'poor_accuracy':
            case 'outside_geofence':
            case 'too_early':
            case 'window_closed':
            case 'on_leave':
            case 'on_holiday':
            case 'on_weekly_off':
            case 'already_marked':
            case 'duplicate':
            case 'month_locked':
                return 409;
            default:
                return 400;
        }
    }

    /** Human-readable message for an engine rejection reason (pure). */
    private function _reason_message(string $reason): string
    {
        $map = [
            'invalid_coords'    => 'Invalid GPS coordinates.',
            'invalid_direction' => 'Invalid punch direction.',
            'method_disabled'   => 'GPS attendance is not enabled for this school.',
            'gps_disabled'      => 'GPS attendance is not configured for this school.',
            'mock_location'     => 'Mock/fake location detected. Punch rejected.',
            'poor_accuracy'     => 'GPS signal is too weak. Move to an open area and retry.',
            'outside_geofence'  => 'You are outside the campus area.',
            'too_early'         => 'Attendance window has not opened yet.',
            'window_closed'     => 'The attendance window for today has closed.',
            'on_leave'          => 'You are on approved leave today.',
            'on_holiday'        => 'Today is a holiday.',
            'on_weekly_off'     => 'Today is a weekly-off.',
            'already_marked'    => 'Attendance for today is already marked. Please contact admin for a correction.',
            'month_locked'      => 'Attendance for this month is locked.',
        ];
        return $map[$reason] ?? 'Attendance could not be recorded.';
    }
}
