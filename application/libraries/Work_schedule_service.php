<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Work_schedule_service — named work schedules + employee assignment (N-31…N-33).
 *
 * WORKFORCE POLICY RECONCILIATION (2026-08-18): `resolveEmployeeCalendar()` now
 * delegates schedule/assignment RESOLUTION to `Policy_assignment_service` (the
 * Phase 0 canonical engine already used by Staff Attendance's punch path and
 * finaliser, and by the Work_schedules admin surface) instead of its own
 * `versions[]`-based selection. This closes the gap the reconciliation report
 * identified: Payroll was the one remaining consumer still on the pre-Phase-0
 * resolver, expecting a `workSchedules[].versions[]` shape the live admin UI
 * (`Work_schedules::save_schedule()`) never writes — every schedule created
 * through the shipped screen has only a flat `weeklyOffs` field, so the old
 * path always returned BLOCK_SCHEDULE_INVALID for it. This was the exact
 * refactor the Phase 0 gate report already promised
 * ("Work_schedule_service becomes a caller in Phase 1, not a copy") and that
 * had not yet been carried out.
 *
 * THE PRODUCT MODEL (current)
 * ----------------------------
 *   Organisation
 *     └── named work schedules      (Teaching, Administration, Transport, …)
 *           weeklyOffs — flat, no version history (effective dating lives on
 *           the ASSIGNMENT, per Policy_assignment_service, not the schedule)
 *   Employee
 *     └── effective-dated assignment, staff-level or department-level
 *           ↓
 *   resolveEmployeeCalendar(attendancePolicy, ALL tenant assignments, staffId,
 *                            department, period)
 *
 * Two employees in one school, in one payroll month, legitimately receive
 * different working-day counts — including via a shared DEPARTMENT
 * assignment, which the pre-reconciliation path could never see (it read
 * assignments pre-filtered/grouped by staffId, silently dropping every
 * department-level record — see `allAssignmentsForRun()`).
 *
 * WHY `workSchedules` AND NOT `attendancePolicy.shifts`
 * -----------------------------------------------------
 * `shifts` describes TIMINGS (`windows`, `schedule`); weekly-offs live at the
 * policy ROOT, outside it. Two schedules can share identical timings yet
 * differ in working days — the case that matters — and `shifts` cannot
 * express it. `shifts` is left untouched for the punch engine that already
 * uses it.
 *
 * NO DEFAULT SCHEDULE, EVER
 * -------------------------
 * An employee with no effective assignment is BLOCKED with a named reason. No
 * fallback to "the tenant's schedule", no weekend guess, no legacy rule.
 * Block codes are `Policy_assignment_service`'s, mapped onto this class's
 * historical BLOCK_* names so callers (`Hr.php`) need no changes.
 *
 * EFFECTIVE DATING REUSED, NOT REINVENTED
 * ---------------------------------------
 * Assignment resolution is `Policy_assignment_service::resolve()` — the same
 * engine, same overlap-refusal, same fail-closed posture already proven by
 * Attendance and the admin surface. Calendar maths (weekly-off/nth-weekday/
 * holiday arithmetic) stays delegated to
 * `Working_calendar_service::resolveFromVersion()`, fed the resolved
 * schedule's flat `weeklyOffs` directly — this service still owns *whose*
 * calendar, never *how* a calendar is computed.
 */

// `BLOCK_MAP` below is a CLASS CONSTANT built from
// `Policy_assignment_service::BLOCK_*`. PHP evaluates class constants when the
// class is DEFINED, not when a method first runs, so that service has to exist
// by the time this file is parsed. The `require_once` further down sits inside
// resolveEmployeeCalendar() and therefore runs far too late: merely loading
// this library fataled with `Class "Policy_assignment_service" not found`,
// which took the whole /work_schedules screen down.
//
// CI's autoloader does not resolve statics, which is why Work_schedules.php
// already requires Working_calendar_service.php explicitly for exactly this
// reason. Same fix, same pattern, one line earlier in the chain.
require_once APPPATH . 'libraries/Policy_assignment_service.php';

class Work_schedule_service
{
    /** Employee→schedule assignments. Mirrors `staffSalaryAssignments` (W2). */
    const ASSIGNMENT_COLLECTION = 'staffScheduleAssignments';

    const STATUS_ACTIVE = 'active';
    const STATUS_VOID   = 'void';

    /** The five distinct block states (§10). Each names a different fix. */
    const BLOCK_NO_SCHEDULES      = 'no_schedules';        // A — tenant has none
    const BLOCK_NO_ASSIGNMENT     = 'no_assignment';       // B — employee unassigned
    const BLOCK_ASSIGNMENT_EXPIRED= 'assignment_expired';  // C — lapsed before the period
    const BLOCK_AMBIGUOUS         = 'ambiguous_assignment';// D — overlapping assignments
    const BLOCK_SCHEDULE_INVALID  = 'schedule_invalid';    // E — schedule missing/unversioned

    /** @var object Firestore_service */
    private $fs;
    private string $actor = '';

    public function init($fs, string $actor = ''): void
    {
        $this->fs    = $fs;
        $this->actor = $actor;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Schedules (tenant-owned, named)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * All named schedules from the policy document: [scheduleId => schedule].
     *
     * ROOT CAUSE OF THE 2026-08-19 PAYROLL OUTAGE. This docblock has always
     * promised a map keyed by scheduleId, but the implementation returned the
     * stored value verbatim — and it is STORED as a list
     * (Work_schedules::save_schedule writes `array_values($list)`).
     *
     * Consumers that iterate could not tell the difference, so three of them
     * quietly re-keyed the result themselves and never noticed. The one
     * consumer that believed the documented contract —
     * resolveEmployeeCalendar(), feeding Policy_assignment_service::resolve(),
     * which looks a policy up as `$policies[$policyId]` — matched nothing.
     * Every employee failed closed with `policy_missing`, so payroll skipped
     * the entire roster while staff attendance kept working normally.
     *
     * The implementation now honours the contract. Iterating callers are
     * unaffected (a foreach over a map yields the same values in the same
     * order), and callers that re-key defensively are idempotent. The STORED
     * shape is deliberately unchanged: this keys on read, it does not migrate.
     */
    public static function schedulesFrom(?array $attendancePolicy): array
    {
        if (!is_array($attendancePolicy)) return [];
        $s = $attendancePolicy['workSchedules'] ?? null;
        if (!is_array($s)) return [];

        $out = [];
        foreach ($s as $row) {
            // Same guard the three attendance-side consumers already apply, so
            // consolidating here cannot change what any of them see.
            if (is_array($row) && !empty($row['scheduleId'])) {
                $out[(string) $row['scheduleId']] = $row;
            }
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Employee assignment
    // ─────────────────────────────────────────────────────────────────────

    /** Active assignments for one employee. */
    public function assignmentsFor(string $staffId): array
    {
        $out = [];
        try {
            $docs = $this->fs->schoolWhere(self::ASSIGNMENT_COLLECTION, [['staffId', '==', $staffId]]);
            if (is_array($docs)) {
                foreach ($docs as $d) {
                    $a = is_array($d['data'] ?? null) ? $d['data'] : $d;
                    if ((string) ($a['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) continue;
                    $out[] = $a;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Work_schedule_service::assignmentsFor failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Every assignment on the tenant, grouped by staff — ONE query.
     *
     * Payroll resolves a whole roster, so a per-employee read would be an N+1
     * that grows with headcount. Same reasoning as `Compensation_reader::resolveMany()`.
     *
     * Retained for any other caller that genuinely wants only staff-level
     * assignments keyed by staffId. Payroll no longer uses this (see
     * `allAssignmentsForRun()`) — grouping by staffId silently drops every
     * DEPARTMENT-level assignment, since a department-attached record has no
     * `staffId` field at all.
     *
     * @return array<string,array>
     */
    public function assignmentsForRun(): array
    {
        $byStaff = [];
        try {
            $docs = $this->fs->schoolWhere(self::ASSIGNMENT_COLLECTION, []);
            if (is_array($docs)) {
                foreach ($docs as $d) {
                    $a = is_array($d['data'] ?? null) ? $d['data'] : $d;
                    if ((string) ($a['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) continue;
                    $sid = (string) ($a['staffId'] ?? '');
                    if ($sid === '') continue;
                    $byStaff[$sid][] = $a;
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Work_schedule_service::assignmentsForRun failed: ' . $e->getMessage());
        }
        return $byStaff;
    }

    /**
     * Every ACTIVE assignment on the tenant, flat and normalised — ONE query,
     * any attachment level (staff or department). This is what
     * `Policy_assignment_service::resolve()` needs: it does its own
     * per-employee/per-department filtering internally
     * (`assignmentsAtLevel()`), so the caller must hand it the whole tenant
     * list, not a pre-filtered slice.
     *
     * @return array  list of normalised records (see `normaliseAssignment()`)
     */
    public function allAssignmentsForRun(): array
    {
        $out = [];
        try {
            $docs = $this->fs->schoolWhere(self::ASSIGNMENT_COLLECTION, []);
            if (is_array($docs)) {
                foreach ($docs as $d) {
                    $a = is_array($d['data'] ?? null) ? $d['data'] : $d;
                    if ((string) ($a['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_ACTIVE) continue;
                    $out[] = self::normaliseAssignment($a);
                }
            }
        } catch (\Exception $e) {
            log_message('error', 'Work_schedule_service::allAssignmentsForRun failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Read any assignment record — Phase 1 shape (`attachmentType`/
     * `attachmentId`/`policyId`) or the older staff-only shape (`staffId`/
     * `scheduleId`) — as the canonical `Policy_assignment_service` record.
     * Nothing has to be migrated; every write already mirrors both shapes.
     *
     * Same logic `Work_schedules::_normaliseAssignment()` uses on the admin
     * surface — extracted here so Payroll and the admin surface share one
     * implementation rather than two copies that could drift.
     */
    public static function normaliseAssignment(array $a): array
    {
        $type = (string) ($a['attachmentType'] ?? '');
        $id   = (string) ($a['attachmentId'] ?? '');

        if ($type === '') {                       // legacy record
            $type = 'staff';
            $id   = (string) ($a['staffId'] ?? '');
        }

        return [
            'assignmentId'   => (string) ($a['assignmentId'] ?? ''),
            'attachmentType' => $type,
            'attachmentId'   => $id,
            'policyId'       => (string) ($a['policyId'] ?? $a['scheduleId'] ?? ''),
            'policyType'     => (string) ($a['policyType'] ?? 'work_schedule'),
            'effectiveFrom'  => (string) ($a['effectiveFrom'] ?? ''),
            'effectiveTo'    => (string) ($a['effectiveTo'] ?? ''),
        ];
    }

    /**
     * Which assignment governed this employee on `$date`?
     *
     * Returns ['assignment'=>…, 'block'=>null] or ['assignment'=>null, 'block'=>CODE].
     * Overlaps are REFUSED rather than silently resolved by read order — two
     * assignments covering one date would make payroll depend on document
     * ordering, and a closed period could reprice on re-read.
     */
    public static function effectiveAssignment(array $assignments, string $date): array
    {
        if (empty($assignments)) {
            return ['assignment' => null, 'block' => self::BLOCK_NO_ASSIGNMENT];
        }

        require_once APPPATH . 'helpers/effective_dating_helper.php';

        $covering = [];
        foreach ($assignments as $a) {
            $from = (string) ($a['effectiveFrom'] ?? '');
            if ($from === '' || $from > $date) continue;
            $to = $a['effectiveTo'] ?? null;
            if ($to !== null && (string) $to !== '' && (string) $to < $date) continue;
            $covering[] = $a;
        }

        if (count($covering) > 1) {
            return ['assignment' => null, 'block' => self::BLOCK_AMBIGUOUS];
        }
        if (count($covering) === 1) {
            return ['assignment' => $covering[0], 'block' => null];
        }

        // Nothing covers the date. Distinguish "never started" from "lapsed",
        // because the administrator fixes them differently.
        foreach ($assignments as $a) {
            $to = (string) ($a['effectiveTo'] ?? '');
            if ($to !== '' && $to < $date) {
                return ['assignment' => null, 'block' => self::BLOCK_ASSIGNMENT_EXPIRED];
            }
        }
        return ['assignment' => null, 'block' => self::BLOCK_NO_ASSIGNMENT];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  THE resolver
    // ─────────────────────────────────────────────────────────────────────

    /** Maps Policy_assignment_service's block vocabulary onto this class's historical BLOCK_* names — callers (Hr.php) compare against Work_schedule_service::BLOCK_*, never the newer service's constants directly, so no caller needs to change. */
    private const BLOCK_MAP = [
        \Policy_assignment_service::BLOCK_NO_POLICIES        => self::BLOCK_NO_SCHEDULES,
        \Policy_assignment_service::BLOCK_NO_ASSIGNMENT      => self::BLOCK_NO_ASSIGNMENT,
        \Policy_assignment_service::BLOCK_ASSIGNMENT_EXPIRED => self::BLOCK_ASSIGNMENT_EXPIRED,
        \Policy_assignment_service::BLOCK_AMBIGUOUS          => self::BLOCK_AMBIGUOUS,
        \Policy_assignment_service::BLOCK_POLICY_MISSING     => self::BLOCK_SCHEDULE_INVALID,
    ];

    /**
     * The employee's working calendar for one month.
     *
     * @param array|null $attendancePolicy  schools/{id}.attendancePolicy
     * @param array      $assignments       ALL active tenant assignments, any
     *                                      attachment level — NOT pre-filtered
     *                                      to this employee. Use
     *                                      `allAssignmentsForRun()` to load
     *                                      these; `Policy_assignment_service`
     *                                      does its own per-employee/
     *                                      per-department filtering.
     * @param string     $department        this employee's department ('' if none)
     * @param array      $holidays          [day => name], audience/department/staffId-filtered
     * @param array      $inWindow          [day => true] employment window
     * @param array      $specialWorkingDays [day => name], Phase D — same filtering
     * @param array      $halfDays           [day => name], Phase D — informational only,
     *                                       does not change workingCount arithmetic
     */
    public static function resolveEmployeeCalendar(
        ?array $attendancePolicy,
        array $assignments,
        string $staffId,
        int $year,
        int $monthNum,
        array $holidays,
        array $inWindow,
        string $department = '',
        array $specialWorkingDays = [],
        array $halfDays = [],
        float $halfDayWeight = 1.0
    ): array {
        require_once APPPATH . 'libraries/Working_calendar_service.php';
        require_once APPPATH . 'libraries/Policy_assignment_service.php';

        $periodStart = sprintf('%04d-%02d-01', $year, $monthNum);

        // Keyed by scheduleId, which is what Policy_assignment_service::resolve()
        // requires. The keying now lives in schedulesFrom() itself — see the
        // root-cause note there; this call site no longer compensates for it.
        $schedules = self::schedulesFrom($attendancePolicy);

        $fail = function (string $code, string $why) use ($year, $monthNum, $staffId) {
            $daysInMonth = (int) date('t', mktime(0, 0, 0, $monthNum, 1, $year));
            return [
                'configured' => false, 'blocked' => true,
                'blockCode' => $code, 'blockReason' => $why,
                'isWorking' => [], 'reason' => [], 'workingCount' => 0,
                'workingMask' => str_repeat(\Working_calendar_service::MASK_OUTSIDE, $daysInMonth),
                'halfDayCount' => 0, 'specialWorkingDayCount' => 0,
                'scheduleId' => '', 'scheduleName' => '',
                'policyVersion' => null, 'policyEffectiveFrom' => '',
                'assignmentEffectiveFrom' => '', 'policySource' => 'unconfigured',
                'staffId' => $staffId,
            ];
        };

        if (empty($schedules)) {
            return $fail(self::BLOCK_NO_SCHEDULES,
                'No work schedules are defined for this school. Create a work schedule under '
                . 'Attendance → Work Schedules before generating payroll.');
        }

        // Resolve WHOSE schedule applies via the canonical engine — the same
        // one Staff Attendance and the admin surface already use. No
        // organisation-level fallback here: payroll's D4 stance is to refuse
        // rather than adopt a tenant-wide policy nobody confirmed for pay.
        $r = \Policy_assignment_service::resolve([
            'policies'             => $schedules,
            'assignments'          => $assignments,
            'staffId'              => $staffId,
            'department'           => $department,
            'organisationPolicyId' => null,
        ], $periodStart);

        if (!empty($r['block'])) {
            $code = self::BLOCK_MAP[$r['block']] ?? self::BLOCK_SCHEDULE_INVALID;
            return $fail($code, \Policy_assignment_service::explain($r));
        }

        $schedule   = $r['policy'];
        $scheduleId = (string) $r['policyId'];
        $assignment = $r['assignment']; // null only at the organisation level, unreachable here

        // Calendar maths delegated — this service never computes a calendar.
        // The resolved schedule's flat `weeklyOffs` is passed directly as the
        // "version": schedules no longer carry `versions[]` — effective
        // dating lives on the ASSIGNMENT (Policy_assignment_service), not on
        // schedule content, so there is nothing further to select here.
        $cal = \Working_calendar_service::resolveFromVersion(
            $schedule, $year, $monthNum, $holidays, $inWindow, $specialWorkingDays, $halfDays,
            $halfDayWeight
        );

        // Identity of WHOSE calendar this is — frozen into payroll evidence so
        // a later schedule change cannot reinterpret a closed period.
        $cal['scheduleId']              = $scheduleId;
        $cal['scheduleName']            = (string) ($schedule['name'] ?? $scheduleId);
        $cal['assignmentEffectiveFrom'] = (string) ($assignment['effectiveFrom'] ?? '');
        $cal['blockCode']               = '';
        $cal['staffId']                 = $staffId;

        return $cal;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Assignment writes
    // ─────────────────────────────────────────────────────────────────────

    /** Validate an assignment before storing it. */
    public function validateAssignment(array $in, array $existing = []): array
    {
        require_once APPPATH . 'helpers/effective_dating_helper.php';
        $errors = [];

        $staffId    = trim((string) ($in['staffId'] ?? ''));
        $scheduleId = trim((string) ($in['scheduleId'] ?? ''));
        $from       = trim((string) ($in['effectiveFrom'] ?? ''));
        $to         = trim((string) ($in['effectiveTo'] ?? ''));

        if ($staffId === '')    $errors[] = 'Employee is required.';
        if ($scheduleId === '') $errors[] = 'Work schedule is required.';
        if ($from === '' || !ed_is_iso_date($from)) {
            $errors[] = 'An effective-from date (YYYY-MM-DD) is required.';
        }
        if ($to !== '' && !ed_is_iso_date($to)) $errors[] = 'Effective-to must be YYYY-MM-DD.';
        if ($from !== '' && $to !== '' && $to < $from) {
            $errors[] = 'Effective-to cannot precede effective-from.';
        }

        // Overlap against this employee's existing assignments — refused, not
        // silently resolved, so a period can never resolve two ways.
        if (empty($errors)) {
            $candidate = array_merge($existing, [[
                'effectiveFrom' => $from, 'effectiveTo' => $to !== '' ? $to : null,
            ]]);
            $overlaps = ed_find_overlaps($candidate, 'work-schedule assignment');
            if (!empty($overlaps)) {
                $errors[] = 'This overlaps an existing assignment: ' . implode('; ', $overlaps);
            }
        }

        return ['ok' => empty($errors), 'errors' => $errors, 'clean' => [
            'staffId' => $staffId, 'scheduleId' => $scheduleId,
            'effectiveFrom' => $from, 'effectiveTo' => $to !== '' ? $to : null,
        ]];
    }

    /** Persist an assignment. Append-only; history is never overwritten. */
    public function saveAssignment(array $clean, int $seq): array
    {
        $id  = "WSA_{$clean['staffId']}_{$seq}";
        $now = date('c');
        $doc = array_merge($clean, [
            'assignmentId' => $id,
            'status'       => self::STATUS_ACTIVE,
            'createdAt'    => $now, 'createdBy' => $this->actor,
            'updatedAt'    => $now, 'updatedBy' => $this->actor,
        ]);
        $this->fs->set(self::ASSIGNMENT_COLLECTION, $this->fs->docId($id), $doc, false);
        return $doc;
    }
}
