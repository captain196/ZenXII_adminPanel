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
            // The teacher app renders this `message` verbatim on screen, so it
            // must read as a sentence to a teacher rather than as a diagnosis
            // of our token plumbing. "Unable to resolve school/session from
            // token" named internals, told them nothing they could act on, and
            // was observed on the device.
            //
            // WHICH of the two is missing is what actually helps debugging, and
            // that stays here in the log. ('error', not 'warning' — CI3 drops
            // the 'warning' level entirely, so it would vanish.)
            log_message('error', 'Staff_attendance: unresolved tenant context'
                . ' school_id=[' . $this->school_id . ']'
                . ' session_year=[' . $this->session_year . ']'
                . ' uid=[' . (string) ($claims['uid'] ?? '') . ']');

            header('Content-Type: application/json');
            http_response_code(409);
            echo json_encode([
                'status'  => 'error',
                'message' => 'We couldn\'t verify your school session. '
                           . 'Please try again — if this keeps happening, sign out and sign in again.',
            ]);
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
        $integrityToken = (string) ($body['integrityToken'] ?? '');
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

        // ── Anti-spoof: Play Integrity attestation (config-gated; INERT by default) ──
        // The geofence is server-authoritative but judges CLIENT-supplied coords with
        // only the client `mock` flag as defence — defeatable by a rooted/fake-GPS
        // device. When Play Integrity is configured (see config/play_integrity.php),
        // a genuine-app + genuine-device attestation is verified server-side. A token
        // that is PRESENT but fails verification is a positive spoof signal → always
        // rejected; a MISSING token is rejected only once attestation is 'require'd
        // (so older app builds keep working during rollout). Disabled → no-op.
        $this->load->library('play_integrity');
        if ($this->play_integrity->available()) {
            $att = $this->play_integrity->verify($integrityToken, ['staffId' => $staffId]);
            if (!$att['ok'] && ($att['reason'] === 'verdict_failed' || $this->play_integrity->isRequired())) {
                $this->_record_punch($staffId, date('Y-m-d'), date('Y-m-d H:i:s'), $request, [
                    'allowed'        => false,
                    'reason'         => 'attestation_failed',
                    'mark'           => null,
                    'distanceMeters' => null,
                    'direction'      => $direction,
                    'attestation'    => $att['verdict'] ?? [],
                ], $clientPunchId);
                return $this->json_error(
                    'Device verification failed. Please use the official app on a genuine device.',
                    403
                );
            }
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

        // N-33 department resolved by _resolve_employee_calendar() below —
        // read it first so the holiday/SWD checks can be department-scoped
        // too (Phase D). A cheap, memoised call; no extra Firestore read.
        $calDept = $this->_resolve_employee_calendar($staffId, $dateISO)['department'] ?? '';
        $isHoliday = $this->holiday_service->is_holiday($dateISO, 'staff', $calDept, $staffId);
        $isSpecialWorkingDay = $this->holiday_service->is_special_working_day($dateISO, 'staff', $calDept, $staffId);

        $summaryId = "{$this->school_id}_{$staffId}_{$monthKey}";
        $summary   = $this->fs->get('staffAttendanceSummary', $summaryId);
        $dayWise   = is_array($summary) ? (string) ($summary['dayWise'] ?? '') : '';
        $currentMark = (strlen($dayWise) >= $day) ? strtoupper($dayWise[$day - 1]) : 'V';

        // ── N-35: THE EMPLOYEE'S OWN CALENDAR, RESOLVED BEFORE CLASSIFICATION ──
        //
        // This must precede evaluate(). Gate 6b is the ONLY place `W` can ever be
        // stamped, and it fires on this flag alone. If the flag is wrong the punch
        // falls through to Gate 7 and is recorded P/T — the fact that the employee
        // worked a scheduled rest day is then gone from the record for good, and
        // no later process (payroll included) can recover it.
        //
        // FAIL CLOSED (D2): an unresolvable schedule REFUSES the punch. It must not
        // fall through to normal P/T/M/A classification, and it must not borrow the
        // tenant-wide list. Classifying against an unknown calendar is what N-23 was.
        $cal = $this->_resolve_employee_calendar($staffId, $dateISO);
        if ($cal['block'] !== null) {
            return $this->json_error(
                'Attendance cannot be marked: this employee has no work schedule for '
                . $dateISO . ' (' . $cal['block'] . '). '
                . 'Assign one under HR & Payroll → Work Schedules, then try again.',
                ['blocked' => $cal['block'], 'staff_id' => $staffId, 'date' => $dateISO]
            );
        }
        $isWeeklyOff = $cal['isNonWorking'];

        // On check-out, resolve today's check-in time so the engine can classify
        // full vs half day by hours worked. Only read when checking out.
        $checkInMinutes = ($direction === 'out')
            ? $this->_today_checkin_minutes($staffId, $dateISO)
            : null;

        // ── Decision: pure business-rule engine ──
        $this->load->library('attendance_policy');
        $decision = $this->attendance_policy->evaluate($policy, $request, [
            'serverTime'           => $serverTime,
            'currentDayMark'       => $currentMark,
            'isHoliday'            => $isHoliday,
            'isWeeklyOff'          => $isWeeklyOff,
            'isSpecialWorkingDay'  => $isSpecialWorkingDay,
            'checkInMinutes'       => $checkInMinutes,
            'shiftId'              => 'default',
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
            'message'        => $this->_punch_message($decision, $direction, $checkInMinutes),
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

        // N-35/Phase D: this employee's department is fixed (not per-date), so
        // resolve it once, before the overlay calls that need it.
        $todayCal        = $this->_resolve_employee_calendar($staffId, $dateISO);
        $todayIsWeeklyOff = $todayCal['isNonWorking'];
        $todayDept        = $todayCal['department'] ?? '';

        $curDayWise  = $this->_overlay_daywise((string) ($curSum['dayWise'] ?? ''),  $monthKey, $pol, $dateISO,
                                              $this->_non_working_days($staffId, $monthKey), $staffId, $todayDept);
        $prevDayWise = $this->_overlay_daywise((string) ($prevSum['dayWise'] ?? ''), $prevKey,  $pol, $dateISO,
                                              $this->_non_working_days($staffId, $prevKey), $staffId, $todayDept);

        $todayStatus = (strlen($curDayWise) >= $day) ? strtoupper($curDayWise[$day - 1]) : 'V';
        $counts = $this->_count_daywise($curDayWise);

        // Rest-day flags for TODAY (overlay leaves today untouched) so the app
        // can offer the "work on a rest day = extra" flow.
        // N-35: the app's "work on a rest day" prompt must reflect THIS employee's
        // schedule. An unresolvable calendar reports false — the flag only ever
        // OFFERS the extra-work flow, and offering it wrongly is worse than not
        // offering it. The punch itself is refused separately, upstream.
        // Phase D — department/individual-scoped, matching the punch path.
        $todayIsHoliday = false;
        $todayIsSpecialWorkingDay = false;
        try {
            $todayIsHoliday = $this->holiday_service->is_holiday($dateISO, 'staff', $todayDept, $staffId);
            $todayIsSpecialWorkingDay = $this->holiday_service->is_special_working_day($dateISO, 'staff', $todayDept, $staffId);
        } catch (\Throwable $e) {}

        $history = av_build_history([
            $prevKey  => $prevDayWise,
            $monthKey => $curDayWise,
        ], $dateISO, 30);

        // Accepted punches across the 30-day window → per-day check-in/out times.
        // One query feeds BOTH today's times and the history calendar so each day
        // can show when the employee clocked in/out, not just a status colour.
        $windowStartISO = (clone $now)->modify('-29 days')->format('Y-m-d');
        $punchesByDate = [];
        try {
            $rows = $this->fs->schoolWhere('attendancePunches', [
                ['staffId', '==', $staffId],
                ['date',    '>=', $windowStartISO],
            ]);
            foreach ((array) $rows as $r) {
                $p = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                $d = (string) ($p['date'] ?? '');
                if ($d !== '') $punchesByDate[$d][] = $p;
            }
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::me punches query failed: ' . $e->getMessage());
        }
        $times = av_today_times($punchesByDate[$dateISO] ?? []);

        // Approved regularizations → claimed times, keyed by date. A regularized
        // day never has a physical punch, so its times live only on the request
        // doc. Overlaying them here is what makes an approved correction visible
        // (with its time) on the calendar, not just as a status letter.
        $regByDate = [];
        try {
            $regs = $this->fs->schoolWhere('attendanceRegularizations', [
                ['staffId', '==', $staffId],
                ['status',  '==', 'approved'],
            ]);
            foreach ((array) $regs as $r) {
                $p = is_array($r['data'] ?? null) ? $r['data'] : (is_array($r) ? $r : []);
                if (($p['personType'] ?? '') !== 'staff') continue;
                $d = (string) ($p['date'] ?? '');
                if ($d === '' || $d < $windowStartISO) continue;
                $regByDate[$d] = $p; // one approved reg per staff+day (doc id is staff_date)
            }
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::me regularizations query failed: ' . $e->getMessage());
        }

        // TODAY gets the same approved-regularization override as the history
        // days below — otherwise a regularization filed + approved for the current
        // day would fix the calendar (history) but the "Today" card kept showing
        // the wrong physical-punch time.
        $todayRegularized = false;
        $todayReg = $regByDate[$dateISO] ?? null;
        if ($todayReg !== null) {
            if (!empty($todayReg['claimedCheckInAt']))  $times['checkInAt']  = (string) $todayReg['claimedCheckInAt'];
            if (!empty($todayReg['claimedCheckOutAt'])) $times['checkOutAt'] = (string) $todayReg['claimedCheckOutAt'];
            $todayRegularized = true;
        }

        // Enrich each history day with its check-in/out times (null when none).
        // An approved regularization's claimed time overrides the physical punch
        // (see the override note in the loop); a status-only reg keeps the punch.
        foreach ($history as &$_h) {
            $ht = av_today_times($punchesByDate[$_h['date']] ?? []);
            $reg = $regByDate[$_h['date']] ?? null;
            $_h['checkInAt']  = $ht['checkInAt']  ?? null;
            $_h['checkOutAt'] = $ht['checkOutAt'] ?? null;
            if ($reg !== null) {
                // An approved regularization is a deliberate correction the admin
                // signed off on, so its claimed times OVERRIDE the physical punch
                // (the staff filed it precisely because the punch time was wrong
                // or missing — "by mistake wrong time marked"). Previously the
                // claimed time was only used when there was NO punch, so a day
                // that WAS punched kept the wrong time and the approval looked
                // like it "had no effect on the calendar time". Only override when
                // a claimed time is actually present; a status-only regularization
                // keeps the real punch time.
                if (!empty($reg['claimedCheckInAt']))  $_h['checkInAt']  = (string) $reg['claimedCheckInAt'];
                if (!empty($reg['claimedCheckOutAt'])) $_h['checkOutAt'] = (string) $reg['claimedCheckOutAt'];
                $_h['regularized'] = true; // let the app badge a corrected day
            }
        }
        unset($_h);

        // GPS config for the client — the app gates Clock-In/out behind a real
        // location fix INSIDE any ACTIVE campus (multi-campus aware) before
        // enabling the buttons, using this single authoritative source instead
        // of reading Firestore's singular geofence directly.
        $gpsPol   = is_array($pol['gps'] ?? null) ? $pol['gps'] : [];
        $gpsMeth  = is_array($pol['enabledMethods'] ?? null) ? $pol['enabledMethods'] : [];
        $rawFences = [];
        if (isset($gpsPol['geofences']) && is_array($gpsPol['geofences'])) {
            $rawFences = $gpsPol['geofences'];
        } elseif (is_array($gpsPol['geofence'] ?? null)) {
            $rawFences = [$gpsPol['geofence']];   // legacy singular → one campus
        }
        $fencesOut = [];
        foreach ($rawFences as $f) {
            if (!is_array($f) || empty($f['active'])) continue;
            $flat = $f['centerLat'] ?? null; $flng = $f['centerLng'] ?? null;
            $frad = (int) ($f['radius'] ?? 0);
            if (!is_numeric($flat) || !is_numeric($flng) || $frad <= 0) continue;
            $fencesOut[] = [
                'id'        => (string) ($f['id'] ?? ''),
                'name'      => (string) ($f['name'] ?? ''),
                'centerLat' => (float) $flat,
                'centerLng' => (float) $flng,
                'radius'    => $frad,
            ];
        }
        $gpsOut = [
            'enabled'                 => in_array('gps', $gpsMeth, true) && !empty($fencesOut),
            'geofences'               => $fencesOut,
            'maxAccuracyMeters'       => (int) ($gpsPol['maxAccuracyMeters'] ?? 100),
            'boundaryToleranceMeters' => (int) ($gpsPol['boundaryToleranceMeters'] ?? 0),
            'allowMockLocation'       => !empty($gpsPol['allowMockLocation']),
        ];

        return $this->json_success([
            'apiVersion' => 1,
            'staffId'    => $staffId,
            'schoolId'   => $this->school_id,
            'session'    => $this->session_year,
            'serverTime' => $now->format('c'),
            'today'      => [
                'date'           => $dateISO,
                'status'         => $todayStatus,         // P|T|M|A|L|H|O|W|V
                'checkInAt'      => $times['checkInAt'],  // ISO-8601 | null (reg-overridden)
                'checkOutAt'     => $times['checkOutAt'], // ISO-8601 | null (reg-overridden)
                'regularized'    => $todayRegularized,
                'isHoliday'      => $todayIsHoliday,
                'isWeeklyOff'    => $todayIsWeeklyOff,
                'isSpecialWorkingDay' => $todayIsSpecialWorkingDay,
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
            'history'    => $history,   // [{date,status,checkInAt,checkOutAt}] oldest→newest, 30d
            'gps'        => $gpsOut,     // {enabled, geofences[], maxAccuracyMeters, boundaryToleranceMeters, allowMockLocation}
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
    /**
     * N-35 — resolve THIS employee's calendar for ONE date, before classification.
     *
     * Runs on the punch path with a person waiting, so it is deliberately three
     * targeted reads rather than the finaliser's bulk index: the school doc for
     * the catalogue, this employee's assignments plus the department assignments,
     * and the staff doc for the department. Memoised per request.
     *
     * @return array ['block' => ?string, 'isNonWorking' => bool]
     *         `block` non-null means the calendar is UNKNOWN. The caller must
     *         refuse the punch — never classify against an unknown calendar.
     */
    private function _resolve_employee_calendar(string $staffId, string $dateISO): array
    {
        // Two caches, deliberately. $ctxMemo holds the LOADED data per employee —
        // resolving a whole month must not repeat the three reads 31 times.
        // $memo holds the per-date answer, which is pure computation on top.
        static $memo = [], $ctxMemo = [];
        $key = $staffId . '|' . $dateISO;
        if (isset($memo[$key])) return $memo[$key];

        require_once APPPATH . 'libraries/Policy_assignment_service.php';

        if (isset($ctxMemo[$staffId])) {
            $ctx = $ctxMemo[$staffId];
            if ($ctx === null) {
                return $memo[$key] = ['block' => 'calendar_unreadable', 'isNonWorking' => false, 'department' => ''];
            }
            // Trail 2 Phase D §0/Priority 1 (2026-08-18) — resolve the
            // department effective ON $dateISO, never the live value cached
            // in $ctx. See _department_as_of()'s own docblock for why.
            $deptAsOf = $this->_department_as_of($staffId, $dateISO, (string) ($ctx['department'] ?? ''));
            $ctx['department'] = $deptAsOf;
            $r = Policy_assignment_service::resolve($ctx, $dateISO);
            if (!empty($r['block'])) {
                return $memo[$key] = ['block' => (string) $r['block'], 'isNonWorking' => false, 'department' => $deptAsOf];
            }
            return $memo[$key] = [
                'block'        => null,
                'isNonWorking' => in_array(date('D', strtotime($dateISO)),
                                           (array) ($r['policy']['weeklyOffs'] ?? []), true),
                'department'   => $deptAsOf,
            ];
        }

        $flat = static function (array $docs): array {
            $out = [];
            foreach ($docs as $d) {
                if (!is_array($d)) continue;
                $out[] = (isset($d['data']) && is_array($d['data'])) ? $d['data'] : $d;
            }
            return $out;
        };

        try {
            $school = $this->fs->get('schools', $this->school_id);
            $policy = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];

            $catalogue = [];
            foreach ((array) ($policy['workSchedules'] ?? []) as $s) {
                if (is_array($s) && !empty($s['scheduleId'])) {
                    $catalogue[(string) $s['scheduleId']] = $s;
                }
            }

            $staff = $flat([$this->fs->get('staff', $this->fs->docId($staffId))]);
            $dept  = trim((string) ($staff[0]['department'] ?? $staff[0]['Department'] ?? ''));

            $assignments = [];
            foreach ($flat($this->fs->schoolWhere('staffScheduleAssignments', [])) as $a) {
                $type = (string) ($a['attachmentType'] ?? '');
                $id   = (string) ($a['attachmentId'] ?? '');
                if ($type === '') { $type = 'staff'; $id = (string) ($a['staffId'] ?? ''); }
                $assignments[] = [
                    'attachmentType' => $type,
                    'attachmentId'   => $id,
                    'policyId'       => (string) ($a['policyId'] ?? $a['scheduleId'] ?? ''),
                    'effectiveFrom'  => (string) ($a['effectiveFrom'] ?? ''),
                    'effectiveTo'    => (string) ($a['effectiveTo'] ?? ''),
                ];
            }

            $ctxMemo[$staffId] = [
                'policies'             => $catalogue,
                'assignments'          => $assignments,
                'staffId'              => $staffId,
                'department'           => $dept,   // LIVE value — the as-of resolution happens per-date below
                'organisationPolicyId' => null,
            ];

            // Trail 2 Phase D §0/Priority 1 — same as-of resolution as the
            // warm path above.
            $deptAsOf = $this->_department_as_of($staffId, $dateISO, $dept);
            $ctx = $ctxMemo[$staffId];
            $ctx['department'] = $deptAsOf;
            $r = Policy_assignment_service::resolve($ctx, $dateISO);

            if (!empty($r['block'])) {
                return $memo[$key] = ['block' => (string) $r['block'], 'isNonWorking' => false, 'department' => $deptAsOf];
            }

            $offs = (array) ($r['policy']['weeklyOffs'] ?? []);
            $dow  = date('D', strtotime($dateISO));

            return $memo[$key] = [
                'block'        => null,
                'isNonWorking' => in_array($dow, $offs, true),
                'department'   => $deptAsOf,
            ];
        } catch (\Throwable $e) {
            // A read failure is NOT "a normal working day". Refuse rather than
            // classify against a calendar we could not load.
            log_message('error', 'Staff_attendance calendar resolve failed for '
                . $staffId . ' ' . $dateISO . ': ' . $e->getMessage());
            $ctxMemo[$staffId] = null;   // do not retry the failed read all month
            return $memo[$key] = ['block' => 'calendar_unreadable', 'isNonWorking' => false, 'department' => ''];
        }
    }

    /**
     * Trail 2, Priority 1 (2026-08-18) — the department that was EFFECTIVE on
     * $dateISO, not the live `staff.Department` field.
     *
     * THE BUG THIS CLOSES. Staff::transfer_staff() and promote_staff() both
     * write `staff.Department` IMMEDIATELY, whatever effective date the admin
     * chose (that is the established, deliberate pattern in this codebase —
     * the effective date governs the employment-history timeline, not the
     * live field). Payroll was taught to resolve the historical value in
     * Trail 2 Phase J; Attendance never was. So a transfer dated two weeks
     * out silently changed TODAY's holiday/SWD/weekly-off scoping for that
     * employee the instant HR clicked Transfer — the department-scoped
     * Holiday_service lookups downstream of this method all received the
     * NEW department for dates that still belong to the OLD one.
     *
     * Reuses the SAME read-only resolver Payroll already uses
     * (Staff_employment_history_service::resolveAttributeAsOf) over the SAME
     * staffEmploymentTimeline projection — no second history system, no new
     * collection, no new writer.
     *
     * FAILS OPEN to $liveFallback (the current live value, i.e. exactly the
     * pre-fix behaviour) whenever the timeline can't answer: no history
     * recorded for this employee (the overwhelmingly common case — every
     * staff member whose department has never changed), a read error, or the
     * service being unavailable. Attendance must never refuse a punch because
     * an employment-history lookup was unavailable; a slightly-stale
     * department is strictly better than a blocked employee, and it is
     * identical to how this code behaved before this fix.
     *
     * Memoised per (staffId|dateISO) — the finaliser resolves whole months,
     * and this must not add 31 Firestore queries to that path.
     */
    private function _department_as_of(string $staffId, string $dateISO, string $liveFallback): string
    {
        static $deptMemo = [];
        $k = $staffId . '|' . $dateISO;
        if (array_key_exists($k, $deptMemo)) return $deptMemo[$k];

        try {
            $this->load->library('staff_employment_history_service');
            $svc = $this->staff_employment_history_service
                ->init($this->fs, $this->school_id, (string) ($this->admin_id ?? ''));
            $hist = $svc->resolveAttributeAsOf($staffId, 'department', $dateISO);
            if ($hist !== null && $hist !== '') return $deptMemo[$k] = $hist;
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance::_department_as_of failed for '
                . $staffId . ' ' . $dateISO . ': ' . $e->getMessage());
        }
        return $deptMemo[$k] = $liveFallback;
    }

    /**
     * Non-working day NUMBERS for one employee across one month, for the live
     * overlay. Returns [] when the calendar is unresolvable — the caller must
     * treat that as "label nothing", never as "no days off".
     */
    private function _non_working_days(string $staffId, string $monthKey): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) return [];
        [$y, $m] = array_map('intval', explode('-', $monthKey));
        $days = (int) date('t', mktime(0, 0, 0, $m, 1, $y));

        $out = [];
        for ($d = 1; $d <= $days; $d++) {
            $dateISO = sprintf('%s-%02d', $monthKey, $d);
            $cal = $this->_resolve_employee_calendar($staffId, $dateISO);
            if ($cal['block'] !== null) return [];      // unknown month → label nothing
            if (!$cal['isNonWorking']) continue;
            // Phase D — a Special Working Day overrides an otherwise-off
            // weekly-off day back to working; it must not be labelled 'O'.
            try {
                if ($this->holiday_service->is_special_working_day($dateISO, 'staff', $cal['department'] ?? '', $staffId)) continue;
            } catch (\Throwable $e) {}
            $out[$d] = true;
        }
        return $out;
    }

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
     *
     * Phase D: $staffId/$department scope the holiday/Special-Working-Day
     * checks to this employee. A Special Working Day beats everything —
     * an unmarked past SWD day is a genuine miss (auto-absents like any
     * other working day), never labelled O/H, mirroring
     * Working_calendar_service's precedence exactly.
     */
    /**
     * Trail 2 Phase D, Finding #1 (2026-08-18) — thin wrapper. The actual
     * overlay logic now lives in Staff_attendance_overlay::apply(), shared
     * with Attendance::_fetch_staff_attendance_fs() (the admin grid), so the
     * two surfaces can no longer disagree about a pre-finalization day's
     * state. See that class's docblock for the full rationale.
     */
    private function _overlay_daywise(string $dayWise, string $monthKey, array $policy, string $todayISO, array $nonWorkingDays = [], string $staffId = '', string $department = ''): string
    {
        require_once APPPATH . 'libraries/Staff_attendance_overlay.php';
        $autoAbsent = array_key_exists('autoAbsent', $policy) ? !empty($policy['autoAbsent']) : true;
        return \Staff_attendance_overlay::apply(
            $this->holiday_service,
            $dayWise,
            $monthKey,
            $autoAbsent,
            $todayISO,
            (array) ($nonWorkingDays ?? []),
            $staffId,
            $department
        );
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
    private function _punch_message(array $decision, string $direction, ?int $checkInMinutes = null): string
    {
        $mark = $decision['mark'] ?? null;
        if ($direction === 'out') {
            // ATT-D2: a check-out with no check-in for the day is accepted as an
            // audit punch, but it records nothing — no mark, no summary, no
            // worked hours. Saying "Checked out." there tells the employee their
            // day is on file when it is not.
            if ($checkInMinutes === null) {
                return 'No check-in was recorded today, so there is nothing to check out of. '
                     . 'Ask your admin to regularize this day.';
            }
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
