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
     * @param array  $policy    schools/{id}.attendancePolicy (weeklyOffs, autoAbsent)
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

        $weeklyOffs = (array) ($policy['weeklyOffs'] ?? []);

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

                $resolved = $this->_overlay($freshDayWise, $monthKey, $weeklyOffs, $todayISO);
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
     */
    private function _overlay(string $dayWise, string $monthKey, array $weeklyOffs, string $todayISO): string
    {
        $len = strlen($dayWise);
        for ($d = 1; $d <= $len; $d++) {
            $ch = strtoupper($dayWise[$d - 1]);
            if ($ch !== 'V' && $ch !== 'A') continue;         // only unmarked / auto-absent
            $dateISO = sprintf('%s-%02d', $monthKey, $d);
            if ($dateISO >= $todayISO) continue;              // today + future untouched
            // FAIL CLOSED (audit finding M3): if the per-date holiday lookup errors
            // we must NOT default the day to Absent — the finaliser PERSISTS its
            // result, so a transient outage would permanently mark staff Absent on an
            // actual holiday and feed payroll. Skip the day (leave it as-is); the
            // next daily run retries it. (run() also fail-closes the whole run when
            // the calendar is unreadable via holiday_service->load_ok().)
            try { $isHol = $this->ci->holiday_service->is_holiday($dateISO); }
            catch (\Throwable $e) { continue; }
            if ($isHol) {
                $dayWise[$d - 1] = 'H';                        // V→H or retroactive A→H
            } elseif ($ch === 'V') {
                if (in_array(date('D', strtotime($dateISO)), $weeklyOffs, true)) {
                    $dayWise[$d - 1] = 'O';
                } else {
                    $dayWise[$d - 1] = 'A';
                }
            }
            // $ch === 'A' and not a holiday → leave the genuine Absent untouched.
        }
        return $dayWise;
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
