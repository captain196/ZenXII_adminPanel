<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff_attendance_finaliser — persists the "no-vacant" overlay for payroll.
 *
 * WHY THIS EXISTS
 * ---------------
 * The GPS punch flow only ever writes TODAY's mark. Past working days a staff
 * member never punched stay 'V' (vacant) in the stored dayWise. Staff_attendance::me()
 * masks that at READ time via _overlay_daywise (V → A / O / H for past days), but
 * anything that reads the STORED counts — payroll, HR reports, exports — saw the
 * wrong absent/workingDays totals because nothing ever persisted the overlay.
 *
 * This finaliser closes that gap: it walks each staff month's summary, applies the
 * SAME overlay me() uses, and writes the resolved dayWise + recomputed counts back
 * to Firestore. It is invoked from the admin-page poller (once/day/school) so no
 * new cron infrastructure is required.
 *
 * SAFETY
 * ------
 *   - Only fills PAST vacant days; today and the future are never touched.
 *   - Re-reads each doc immediately before writing and recomputes on the fresh
 *     dayWise, so a concurrent correction editing a past day is not clobbered.
 *   - Best-effort: a single doc's write failure is logged and skipped; it will be
 *     retried on the next daily run.
 *   - Count fields + workingDays are IDENTICAL to Staff_attendance_writer so both
 *     write paths agree (present+leave+tardy+halfDay).
 */
class Staff_attendance_finaliser
{
    /** dayWise char → summary count field. Mirrors Staff_attendance_writer::STATUS_TO_FIELD. */
    private const STATUS_TO_FIELD = [
        'P' => 'present', 'A' => 'absent', 'L' => 'leave', 'H' => 'holiday',
        'T' => 'tardy',   'V' => 'void',   'M' => 'halfDay', 'W' => 'extraWorked',
        'O' => 'weeklyOff',
    ];

    /** @var \CI_Controller */
    private $ci;
    /** @var \Firestore_service */
    private $fs;
    /** @var object|null Firebase library — used for CAS (firestoreGet/CommitBatch). */
    private $firebase = null;
    private string $schoolId    = '';
    private string $sessionYear = '';
    private bool   $ready       = false;

    public function __construct()
    {
        $this->ci =& get_instance();
    }

    public function init($fs, string $schoolId, string $sessionYear): void
    {
        if ($schoolId === '' || $sessionYear === '') {
            throw new \InvalidArgumentException('schoolId and sessionYear are required');
        }
        $this->fs          = $fs;
        // The Firebase library (loaded alongside Firestore_service) exposes the
        // CAS primitives firestoreGet(__updateTime) + firestoreCommitBatch
        // (precondition). Captured here so the write path can compare-and-set
        // like Staff_attendance_writer does. Null → graceful non-CAS fallback.
        $this->firebase    = (isset($this->ci->firebase)
            && method_exists($this->ci->firebase, 'firestoreCommitBatch')
            && method_exists($this->ci->firebase, 'firestoreGet'))
            ? $this->ci->firebase : null;
        $this->schoolId    = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->ci->load->library('holiday_service');
        $this->ci->holiday_service->init($this->fs, $this->schoolId, $this->sessionYear);
        $this->ready = true;
    }

    /**
     * Finalise past vacant days for the given month keys.
     *
     * @param array  $policy    schools/{id}.attendancePolicy — only `autoAbsent` is
     *                          read. The weekly-off calendar is NOT taken from here
     *                          (N-35); it is resolved per employee, per date.
     * @param string $todayISO  'Y-m-d' in the school timezone (resolved by caller)
     * @param array  $monthKeys ['YYYY-MM', ...] to process (typically current + prev)
     * @param int    $maxDocs   safety cap on docs scanned per run
     * @return array ['scanned'=>int,'updated'=>int,'daysFilled'=>int,'skipped'=>int]
     */
    public function run(array $policy, string $todayISO, array $monthKeys, int $maxDocs = 500): array
    {
        if (!$this->ready) {
            throw new \LogicException('Staff_attendance_finaliser not initialised; call init() first');
        }
        $out = ['scanned' => 0, 'updated' => 0, 'daysFilled' => 0, 'skipped' => 0];

        // autoAbsent OFF → the overlay leaves working days Vacant, so there is
        // nothing to persist. Rest-day labelling (O/H) alone is not worth a write.
        $autoAbsent = array_key_exists('autoAbsent', $policy) ? !empty($policy['autoAbsent']) : true;
        if (!$autoAbsent) return $out;

        // FAIL CLOSED (watertight 2026-07-20): if the holiday calendar could not be
        // READ (a transient Firestore failure, not a genuinely empty calendar), do
        // NOT persist any auto-absent this run — otherwise a real holiday would be
        // baked into payroll as Absent and never self-correct. load_ok() returns
        // true for an empty calendar and only false on an actual read failure.
        if (!$this->ci->holiday_service->load_ok()) {
            log_message('warning', 'Staff_attendance_finaliser: holiday calendar unreadable — skipping run (fail-closed); will retry next run.');
            return $out;
        }
        // Whether the calendar has ANY holidays — gates the retroactive A→H pass so
        // docs with only past 'A' days are re-examined only when holidays exist.
        $hasHolidays = !empty($this->ci->holiday_service->all_holiday_dates());

        // N-35: the tenant-wide weeklyOffs list is GONE. Each employee resolves
        // their own calendar through the same authority payroll uses. Loaded
        // once here, resolved in memory per employee — Policy_assignment_service
        // performs no I/O, so this stays one read regardless of headcount.
        $this->_loadScheduleIndex();

        foreach ($monthKeys as $monthKey) {
            if (!preg_match('/^\d{4}-\d{2}$/', (string) $monthKey)) continue;

            try {
                $rows = $this->fs->schoolWhere('staffAttendanceSummary', [
                    ['month', '==', $monthKey],
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'Staff_attendance_finaliser query failed: ' . $e->getMessage());
                continue;
            }

            foreach ((array) $rows as $row) {
                if ($out['scanned'] >= $maxDocs) return $out;
                $data    = is_array($row['data'] ?? null) ? $row['data'] : (is_array($row) ? $row : []);
                $staffId = (string) ($data['staffId'] ?? '');
                $dayWise = (string) ($data['dayWise'] ?? '');
                if ($staffId === '' || $dayWise === '') continue;
                $out['scanned']++;

                // Quick skip: nothing fillable → no work (cheap pre-check before the
                // fresh re-read). Fillable = a past 'V' (needs closing) OR, when the
                // calendar has holidays, a past 'A' (may need retroactive A→H).
                if (!$this->_has_past_fillable($dayWise, $monthKey, $todayISO, $hasHolidays)) continue;

                // Fresh re-read to shrink the race window against a concurrent
                // correction on a past day of the same month. Prefer the
                // Firebase reader so we also capture __updateTime for CAS.
                $docId = "{$this->schoolId}_{$staffId}_{$monthKey}";
                $capturedUpdateTime = '';
                try {
                    if ($this->firebase !== null) {
                        $fresh = $this->firebase->firestoreGet('staffAttendanceSummary', $docId);
                        if (is_array($fresh)) $capturedUpdateTime = (string) ($fresh['__updateTime'] ?? '');
                    } else {
                        $fresh = $this->fs->get('staffAttendanceSummary', $docId);
                    }
                } catch (\Throwable $e) { $out['skipped']++; continue; }
                $freshDayWise = is_array($fresh) ? (string) ($fresh['dayWise'] ?? $dayWise) : $dayWise;

                // FAIL CLOSED (N-35). null = this employee's calendar cannot be
                // resolved for this month — no assignment, an overlap, or a period
                // predating any assignment. Marking 'A' on a day whose working
                // status is unknown is exactly the N-23 harm, so skip entirely.
                $nonWorking = $this->_nonWorkingDays($staffId, $monthKey);
                if ($nonWorking === null) { $out['skipped']++; continue; }

                $resolved = $this->_overlay($freshDayWise, $monthKey, $nonWorking, $todayISO, $staffId);
                if ($resolved === $freshDayWise) continue;   // race resolved it already

                $filled = 0;
                $n = min(strlen($resolved), strlen($freshDayWise));
                for ($i = 0; $i < $n; $i++) {
                    if ($freshDayWise[$i] === 'V' && $resolved[$i] !== 'V') $filled++;
                }

                $counts  = $this->_counts($resolved);
                $payload = array_merge([
                    'schoolId'   => $this->schoolId,
                    'session'    => $this->sessionYear,
                    'staffId'    => $staffId,
                    'month'      => $monthKey,
                    'dayWise'    => $resolved,
                    'totalDays'  => strlen($resolved),
                    '_updatedAt' => date('c'),
                    '_finalisedAt' => date('c'),
                ], $counts);
                // Parity with Staff_attendance_writer::workingDays (adds halfDay).
                $payload['workingDays'] = (int) ($counts['present'] + $counts['leave'] + $counts['tardy'] + $counts['halfDay']);

                // CAS write (audit finding H2): mirror Staff_attendance_writer —
                // the summary set is guarded by a precondition on the doc's
                // __updateTime, so if a correction/regularization CAS-wrote a
                // past day between our re-read and this commit, the commit FAILS
                // (returns false) rather than clobbering it with our stale
                // whole-dayWise overwrite. A conflict is skipped and retried on
                // the next daily run. Falls back to a plain set only when the
                // Firebase CAS primitive is unavailable.
                try {
                    if ($this->firebase !== null) {
                        $precondition = ($capturedUpdateTime !== '')
                            ? ['updateTime' => $capturedUpdateTime]
                            : ['exists' => true]; // only overlay an existing summary
                        $ok = $this->firebase->firestoreCommitBatch([
                            ['op' => 'set', 'collection' => 'staffAttendanceSummary',
                             'docId' => $docId, 'data' => $payload, 'merge' => true,
                             'precondition' => $precondition],
                        ]);
                        if ($ok === true) {
                            $out['updated']++;
                            $out['daysFilled'] += $filled;
                        } else {
                            // Conflict-or-transient: a concurrent writer won. Skip;
                            // the next daily run re-reads and retries safely.
                            $out['skipped']++;
                        }
                    } else {
                        $this->fs->set('staffAttendanceSummary', $docId, $payload, true);
                        $out['updated']++;
                        $out['daysFilled'] += $filled;
                    }
                } catch (\Throwable $e) {
                    log_message('error', "Staff_attendance_finaliser write failed for {$docId}: " . $e->getMessage());
                    $out['skipped']++;
                }
            }
        }

        return $out;
    }

    /* ─── private helpers ──────────────────────────────────────────────── */

    /**
     * Cheap pre-check: is there any past day worth (re)finalising?
     *  - a past 'V' (unmarked) always needs closing;
     *  - a past 'A' needs re-examination ONLY when the calendar has holidays,
     *    because it may now fall on a holiday added after it was auto-absented.
     */
    private function _has_past_fillable(string $dayWise, string $monthKey, string $todayISO, bool $hasHolidays): bool
    {
        $len = strlen($dayWise);
        for ($d = 1; $d <= $len; $d++) {
            if (sprintf('%s-%02d', $monthKey, $d) >= $todayISO) continue; // past only
            $ch = strtoupper($dayWise[$d - 1]);
            if ($ch === 'V') return true;
            if ($ch === 'A' && $hasHolidays) return true;
        }
        return false;
    }

    /**
     * "No-vacant" overlay — identical semantics to Staff_attendance::_overlay_daywise
     * (kept in sync): past 'V' → 'H' (holiday) / 'O' (weekly-off) / 'A' (working day).
     * autoAbsent is already asserted true by the caller.
     *
     * RETROACTIVE (2026-07-20): a past 'A' that now falls on a holiday is corrected
     * to 'H'. This fixes days auto-absented BEFORE the holiday was authored in the
     * Academic Calendar. Only 'A' (and 'V') are eligible — P/L/T/M/W/O are genuine
     * marks and are never touched. workingDays is unaffected (neither A nor H counts).
     *
     * N-35: takes DATE-RESOLVED non-working day numbers for THIS employee, not a
     * tenant-wide weekday list. Weekday names no longer appear in this file —
     * the calendar is decided upstream, per person, by the same authority payroll
     * uses. Two employees in one school can now differ on the same Sunday.
     *
     * @param array $nonWorkingDays [dayNumber => true] for this employee/month
     */
    private function _overlay(string $dayWise, string $monthKey, array $nonWorkingDays, string $todayISO, string $staffId = ''): string
    {
        $len = strlen($dayWise);
        for ($d = 1; $d <= $len; $d++) {
            $ch = strtoupper($dayWise[$d - 1]);
            if ($ch !== 'V' && $ch !== 'A') continue;         // only unmarked / auto-absent
            $dateISO = sprintf('%s-%02d', $monthKey, $d);
            if ($dateISO >= $todayISO) continue;              // today + future untouched
            // Trail 2 Priority 1 (2026-08-18) — the department EFFECTIVE on
            // this date, resolved per-day rather than read once from the live
            // field before the loop. Same reasoning as _nonWorkingDays(); see
            // _departmentAsOf()'s docblock. Memoised, so this costs at most
            // one lookup per distinct date across the whole run.
            $department = $this->_departmentAsOf($staffId, $dateISO);
            // FAIL CLOSED (audit finding M3): if the per-date holiday lookup errors
            // we must NOT default the day to Absent — the finaliser PERSISTS its
            // result, so a transient outage would permanently mark staff Absent on an
            // actual holiday and feed payroll. Skip the day (leave it as-is); the
            // next daily run retries it. (run() also fail-closes the whole run when
            // the calendar is unreadable via holiday_service->load_ok().)
            try {
                $isHol = $this->ci->holiday_service->is_holiday($dateISO, 'staff', $department, $staffId);
                // Phase D — Special Working Day beats holiday/weekly-off: an
                // unmarked/auto-absented day here is a genuine miss on what is
                // now a normal working day, never O/H.
                $isSwd = $this->ci->holiday_service->is_special_working_day($dateISO, 'staff', $department, $staffId);
            }
            catch (\Throwable $e) { continue; }
            if ($isSwd) {
                if ($ch === 'V') $dayWise[$d - 1] = 'A';
            } elseif ($isHol) {
                $dayWise[$d - 1] = 'H';                        // V→H or retroactive A→H
            } elseif ($ch === 'V') {
                // Scheduled OFF + no work -> O.  Scheduled WORKING + no work -> A.
                $dayWise[$d - 1] = isset($nonWorkingDays[$d]) ? 'O' : 'A';
            }
            // $ch === 'A' and not a holiday → leave the genuine Absent untouched.
        }
        return $dayWise;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  N-35 — the employee-specific calendar
    // ─────────────────────────────────────────────────────────────────────

    private array $catalogue      = [];
    private array $assignments    = [];
    private array $staffDept      = [];
    private array $calendarCache  = [];
    private bool  $indexLoaded    = false;

    /**
     * One read of the catalogue, the assignments and the staff department map.
     * Resolution itself is pure and in-memory, so headcount does not add reads —
     * a per-employee query inside the loop would be N round-trips.
     */
    private function _loadScheduleIndex(): void
    {
        if ($this->indexLoaded) return;
        $this->indexLoaded = true;

        require_once APPPATH . 'libraries/Policy_assignment_service.php';

        $school = $this->fs->get('schools', $this->schoolId);
        $policy = is_array($school['attendancePolicy'] ?? null) ? $school['attendancePolicy'] : [];
        foreach ((array) ($policy['workSchedules'] ?? []) as $s) {
            if (is_array($s) && !empty($s['scheduleId'])) {
                $this->catalogue[(string) $s['scheduleId']] = $s;
            }
        }

        foreach ($this->_unwrap($this->fs->schoolWhere('staffScheduleAssignments', [])) as $a) {
            $type = (string) ($a['attachmentType'] ?? '');
            $id   = (string) ($a['attachmentId'] ?? '');
            if ($type === '') { $type = 'staff'; $id = (string) ($a['staffId'] ?? ''); }
            $this->assignments[] = [
                'attachmentType' => $type,
                'attachmentId'   => $id,
                'policyId'       => (string) ($a['policyId'] ?? $a['scheduleId'] ?? ''),
                'effectiveFrom'  => (string) ($a['effectiveFrom'] ?? ''),
                'effectiveTo'    => (string) ($a['effectiveTo'] ?? ''),
            ];
        }

        foreach ($this->_unwrap($this->fs->schoolWhere('staff', [])) as $s) {
            $sid = trim((string) ($s['staffId'] ?? $s['staff_id'] ?? ''));
            if ($sid !== '') {
                $this->staffDept[$sid] = trim((string) ($s['department'] ?? $s['Department'] ?? ''));
            }
        }

        // NO-N+1: _departmentAsOf() memoises per (staffId|date), and the shared
        // history service memoises per (staffId|attribute) — init() does not
        // clear that cache — so a run already costs ONE timeline query per
        // staff member rather than one per staff-day. Over a whole roster that
        // is still N round trips, every one of which this single warm replaces.
        //
        // Same call, same reasoning and the same fail-open contract as the
        // payroll roster loop (Hr.php). Warming is idempotent, read-only, and a
        // failed warm simply leaves the per-staff reads in place, so the run
        // degrades in speed and never in correctness. Warm/cold equivalence is
        // asserted in EmploymentTimelineQueryCountTest.
        try {
            $this->ci->load->library('staff_employment_history_service');
            $this->ci->staff_employment_history_service
                ->init($this->fs, $this->schoolId, 'finaliser')
                ->warmTimeline();
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance_finaliser: timeline warm failed, '
                . 'falling back to per-staff reads: ' . $e->getMessage());
        }
    }

    /** Firestore rows arrive as ['id'=>…, 'data'=>[…]]; flatten defensively. */
    private function _unwrap(array $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            if (!is_array($d)) continue;
            $out[] = (isset($d['data']) && is_array($d['data'])) ? $d['data'] : $d;
        }
        return $out;
    }

    /**
     * Non-working day NUMBERS for one employee in one month.
     *
     * @return array|null [dayNumber => true], or NULL when the calendar cannot be
     *         resolved — no assignment, an overlap, or a period predating every
     *         assignment. NULL is never treated as "no days off"; the caller skips
     *         the employee entirely. A guessed calendar is what N-23 was.
     */
    private function _nonWorkingDays(string $staffId, string $monthKey): ?array
    {
        $key = $staffId . '|' . $monthKey;
        if (array_key_exists($key, $this->calendarCache)) return $this->calendarCache[$key];

        [$y, $m] = array_map('intval', explode('-', $monthKey));
        $days    = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $ctx     = [
            'policies'             => $this->catalogue,
            'assignments'          => $this->assignments,
            'staffId'              => $staffId,
            'department'           => $this->staffDept[$staffId] ?? '',
            'organisationPolicyId' => null,
        ];

        $out = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = sprintf('%s-%02d', $monthKey, $d);
            // Trail 2 Priority 1 (2026-08-18) — resolve the department that
            // was EFFECTIVE on this date, not the live staff.Department the
            // bulk index loaded. See _departmentAsOf()'s docblock. This
            // matters most here of all the Attendance surfaces, because the
            // finaliser PERSISTS its overlay.
            $ctx['department'] = $this->_departmentAsOf($staffId, $date);
            $r    = \Policy_assignment_service::resolve($ctx, $date);

            if (!empty($r['block'])) {
                // Unresolvable on ANY day of the month → the whole month is
                // unsafe to finalise for this employee. Logged, never guessed.
                log_message('error', sprintf(
                    'Staff_attendance_finaliser: %s unresolved for %s (%s) — skipped, not defaulted.',
                    $staffId, $date, $r['block']));
                return $this->calendarCache[$key] = null;
            }

            $offs = (array) ($r['policy']['weeklyOffs'] ?? []);
            if (!in_array(date('D', strtotime($date)), $offs, true)) continue;
            // Phase D — a Special Working Day overrides an otherwise-off
            // weekly-off day back to working; never counted non-working here.
            try {
                if ($this->ci->holiday_service->is_special_working_day($date, 'staff', $ctx['department'], $staffId)) continue;
            } catch (\Throwable $e) {}
            $out[$d] = true;
        }

        return $this->calendarCache[$key] = $out;
    }

    /**
     * Trail 2, Priority 1 (2026-08-18) — the department EFFECTIVE on $date,
     * not the live `staff.Department` this class's bulk index loaded once
     * per run.
     *
     * Same fix, same reasoning, and the same read-only resolver as
     * Staff_attendance::_department_as_of() and Payroll's Phase J fix —
     * Staff_employment_history_service::resolveAttributeAsOf() over the
     * existing staffEmploymentTimeline. No second history system.
     *
     * This surface is the most consequential of the three: the finaliser
     * PERSISTS its overlay into staffAttendanceSummary, which payroll then
     * consumes. A future-dated transfer previously caused the nightly run
     * to persist O/H/A marks resolved against the NEW department for dates
     * that still belonged to the OLD one.
     *
     * FAILS OPEN to the live value (identical to pre-fix behaviour) — a run
     * must never be blocked by an employment-history lookup. Memoised per
     * (staffId|date) for the life of the run, so a month costs at most one
     * lookup per distinct date rather than one per call.
     */
    private array $deptAsOfCache = [];

    private function _departmentAsOf(string $staffId, string $date): string
    {
        $k = $staffId . '|' . $date;
        if (array_key_exists($k, $this->deptAsOfCache)) return $this->deptAsOfCache[$k];

        $live = $this->staffDept[$staffId] ?? '';
        try {
            $this->ci->load->library('staff_employment_history_service');
            $svc = $this->ci->staff_employment_history_service
                ->init($this->fs, $this->schoolId, 'finaliser');
            $hist = $svc->resolveAttributeAsOf($staffId, 'department', $date);
            if ($hist !== null && $hist !== '') return $this->deptAsOfCache[$k] = $hist;
        } catch (\Throwable $e) {
            log_message('error', 'Staff_attendance_finaliser::_departmentAsOf failed for '
                . $staffId . ' ' . $date . ': ' . $e->getMessage());
        }
        return $this->deptAsOfCache[$k] = $live;
    }

    /** Count each dayWise char into the canonical 9-field count set. */
    private function _counts(string $dayWise): array
    {
        $counts = [
            'present' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'tardy' => 0,
            'void' => 0, 'halfDay' => 0, 'extraWorked' => 0, 'weeklyOff' => 0,
        ];
        $len = strlen($dayWise);
        for ($i = 0; $i < $len; $i++) {
            $f = self::STATUS_TO_FIELD[strtoupper($dayWise[$i])] ?? 'void';
            $counts[$f]++;
        }
        return $counts;
    }
}
