<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Working-week policy administration (N-30).
 *
 * WHY THIS EXISTS
 * ---------------
 * N-23/P-021 removed every hardcoded weekly-off rule from payroll and made the
 * tenant's configured policy the only authority. That is correct — and it means
 * payroll is BLOCKED for any tenant that has not configured one. This is the
 * surface where an administrator declares their organisation's working week, so
 * the block is a prompt rather than a dead end.
 *
 * WRITES THE CANONICAL DOCUMENT — NO SECOND CALENDAR
 * --------------------------------------------------
 * Everything here writes `schools/{id}.attendancePolicy.workingWeek.versions[]`,
 * which is exactly what `Working_calendar_service` reads. There is no
 * payroll-specific configuration and no second calendar model. Validation is
 * `Working_calendar_service::validateVersion()` — the same rules the resolver
 * relies on, not a re-implementation.
 *
 * HISTORY IS APPEND-ONLY
 * ----------------------
 * A new policy version is APPENDED with its own `effectiveFrom`. Existing
 * versions are never edited or removed, because a closed payroll period must
 * keep resolving to the version that priced it. `ed_select_for()` then picks
 * whichever version was in force for any given date.
 *
 * NO DEFAULT IS EVER WRITTEN. Nothing here seeds, infers or migrates a policy
 * from historical payroll, from another tenant, or from the legacy
 * Sunday + 2nd/4th-Saturday rule. The administrator states the policy.
 *
 * RETIRED (N-36, 2026-08-18) — WORKFORCE POLICY PROGRAMME PHASE C
 * -----------------------------------------------------------------
 * Superseded by `Work_schedules.php` (Phase 1), which every consumer —
 * Payroll (Phase A), Staff Attendance's punch/finaliser/grid (Phase B/N-35)
 * — now resolves through via `Policy_assignment_service`. This controller's
 * own writes (`attendancePolicy.workingWeek.versions[]`) are read by NO
 * runtime consumer anymore — `Working_calendar_service::effectiveVersion()`/
 * `resolveForMonth()` have exactly one caller each, both in this file.
 *
 * Deliberately NOT deleted: this file, its routes, and any
 * `attendancePolicy.workingWeek` data already written by a tenant are left
 * fully intact — only reachability is cut, via the guard below, so the
 * retirement is a single-line, fully reversible change with zero data risk.
 * A live read-only check on 2026-08-18 found `workingWeek` configured on
 * 0 of the checked tenant's data, so this closes a genuinely dead surface,
 * not a live one.
 */
class Working_week extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_once APPPATH . 'libraries/Working_calendar_service.php';
        // RETIRED — see class docblock. Every endpoint in this controller is
        // dead past this point; nothing below executes.
        $this->json_error(
            'This screen has been retired. Configure work schedules under '
            . 'Attendance → Work Schedules instead.', 410
        );
    }

    /** Reading the policy is a view-level action. */
    private function _require_view(): void
    {
        require_permission('Attendance', 'working_week_view', 'view');
    }

    /**
     * Changing it re-prices future payroll, so it is `manage` — and deliberately
     * the same grade that administers attendance policy, since this IS the
     * attendance policy.
     */
    private function _require_manage(): void
    {
        require_permission('Attendance', 'working_week_manage', 'manage');
    }

    /** The tenant's attendance policy document, or [] when absent. */
    private function _policyDoc(): array
    {
        try {
            $school = $this->fs->get('schools', $this->school_id);
            $p = (is_array($school) && is_array($school['attendancePolicy'] ?? null))
                ? $school['attendancePolicy'] : [];
        } catch (\Exception $e) {
            log_message('error', 'Working_week policy read failed: ' . $e->getMessage());
            $p = [];
        }
        return $p;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET — current policy, full version history, and whether payroll is
     * currently blocked for this tenant.
     */
    public function get_policy(): void
    {
        $this->_require_view();

        $pol      = $this->_policyDoc();
        $versions = [];
        if (is_array($pol['workingWeek']['versions'] ?? null)) {
            $versions = $pol['workingWeek']['versions'];
        }

        // Sort newest-first for display; resolution itself never depends on order.
        usort($versions, function ($a, $b) {
            return strcmp((string) ($b['effectiveFrom'] ?? ''), (string) ($a['effectiveFrom'] ?? ''));
        });

        $today   = date('Y-m-d');
        $current = Working_calendar_service::effectiveVersion($pol, $today);

        $this->json_success([
            'configured'      => $current !== null,
            'current_version' => $current,
            'versions'        => $versions,
            'days'            => Working_calendar_service::DAYS,
            // Stated by the server so the UI cannot invent its own wording.
            'not_configured_message' => $current === null
                ? 'Working-week policy is not configured for this school. '
                  . 'Payroll cannot be generated until the working week is declared.'
                : '',
            // Documentation only — shown so an administrator understands how
            // pre-policy payslips were calculated. It is NOT a suggested value
            // and is never applied automatically.
            'legacy_note' => Working_calendar_service::legacyRuleDescription(),
        ]);
    }

    /**
     * GET — preview the calendar a policy WOULD produce, without saving.
     *
     * Lets an administrator see "24 working days in May" before committing,
     * which is the difference between declaring a policy and guessing at one.
     * Accepts either a draft policy (for preview-before-save) or nothing (to
     * preview what is already stored).
     */
    public function preview(): void
    {
        $this->_require_view();

        $year  = (int) ($this->input->get('year')  ?: date('Y'));
        $month = (int) ($this->input->get('month') ?: date('n'));
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            $this->json_error('Invalid year or month.');
        }

        $draftJson = trim((string) ($this->input->get('draft') ?? ''));
        if ($draftJson !== '') {
            $draft = json_decode($draftJson, true);
            if (!is_array($draft)) $this->json_error('Malformed draft policy.');
            $v = Working_calendar_service::validateVersion($draft);
            if (!$v['ok']) $this->json_error(implode(' ', $v['errors']));
            // Preview the draft as though it were effective from the period start.
            $draft['effectiveFrom'] = sprintf('%04d-%02d-01', $year, $month);
            $pol = ['workingWeek' => ['versions' => [$draft]]];
        } else {
            $pol = $this->_policyDoc();
        }

        // Holidays come from the canonical Holiday_service — not re-derived.
        $holidays = [];
        try {
            $this->load->library('holiday_service');
            $holidays = $this->holiday_service->holidays_in_month($year, $month);
        } catch (\Exception $e) {
            log_message('error', 'Working_week preview holidays failed: ' . $e->getMessage());
        }

        $daysInMonth = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
        $cal = Working_calendar_service::resolveForMonth(
            $pol, $year, $month, $holidays, array_fill_keys(range(1, $daysInMonth), true)
        );

        $days = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $days[] = [
                'day'    => $d,
                'dow'    => date('D', mktime(0, 0, 0, $month, $d, $year)),
                'reason' => $cal['reason'][$d] ?? '',
                'working' => isset($cal['isWorking'][$d]),
            ];
        }

        $this->json_success([
            'year' => $year, 'month' => $month,
            'blocked'      => $cal['blocked'],
            'block_reason' => $cal['blockReason'],
            'working_count' => $cal['workingCount'],
            'working_mask'  => $cal['workingMask'],
            'policy_version'        => $cal['policyVersion'],
            'policy_effective_from' => $cal['policyEffectiveFrom'],
            'policy_source'         => $cal['policySource'],
            'days' => $days,
        ]);
    }

    /**
     * POST — append a new effective-dated policy version.
     *
     * Append-only: existing versions are never edited, so a closed payroll
     * period keeps resolving to the version that priced it (§5).
     */
    public function save_version(): void
    {
        $this->_require_manage();

        $weeklyOffs = $this->input->post('weeklyOffs');
        $nthRaw     = $this->input->post('nthWeekdayOffs');

        // Accept JSON or array — the UI posts JSON for the nested rule shape.
        if (is_string($weeklyOffs)) $weeklyOffs = json_decode($weeklyOffs, true);
        if (is_string($nthRaw))     $nthRaw     = json_decode($nthRaw, true);

        $draft = [
            'effectiveFrom'  => trim((string) $this->input->post('effectiveFrom')),
            'weeklyOffs'     => is_array($weeklyOffs) ? array_values($weeklyOffs) : [],
            'nthWeekdayOffs' => is_array($nthRaw) ? array_values($nthRaw) : [],
        ];

        $v = Working_calendar_service::validateVersion($draft);
        if (!$v['ok']) $this->json_error(implode(' ', $v['errors']));

        $pol      = $this->_policyDoc();
        $versions = is_array($pol['workingWeek']['versions'] ?? null)
            ? $pol['workingWeek']['versions'] : [];

        // One version per effective date — otherwise a date would resolve
        // ambiguously and the same period could price differently on re-read.
        foreach ($versions as $existing) {
            if ((string) ($existing['effectiveFrom'] ?? '') === $draft['effectiveFrom']) {
                $this->json_error('A policy version already exists with effective date '
                    . $draft['effectiveFrom'] . '. Choose a different date — existing versions are '
                    . 'never edited, so historical payroll stays reproducible.');
            }
        }

        $draft['version']   = count($versions) + 1;
        $draft['createdAt'] = date('c');
        $draft['createdBy'] = $this->admin_name ?: $this->admin_id;
        $versions[] = $draft;

        // Merge-write only the workingWeek branch — the rest of attendancePolicy
        // (geofences, shifts, weeklyOffs, autoAbsent) is untouched.
        $this->fs->set('schools', $this->school_id, [
            'attendancePolicy' => ['workingWeek' => ['versions' => $versions]],
        ], true);

        log_message('info', "WORKING_WEEK_POLICY_SAVED school={$this->school_id} "
            . "version={$draft['version']} effectiveFrom={$draft['effectiveFrom']} "
            . 'weeklyOffs=' . implode(',', $draft['weeklyOffs'])
            . ' nth=' . count($draft['nthWeekdayOffs'])
            . " by={$this->admin_name}");

        // Audit through the existing HR/attendance audit path, not a new one.
        if (method_exists($this, 'log_audit')) {
            $this->log_audit('working_week_policy_saved', [
                'version'       => $draft['version'],
                'effectiveFrom' => $draft['effectiveFrom'],
                'weeklyOffs'    => $draft['weeklyOffs'],
                'nthWeekdayOffs'=> $draft['nthWeekdayOffs'],
            ]);
        }

        $this->json_success(['version' => $draft, 'total_versions' => count($versions)],
            'Working-week policy version ' . $draft['version'] . ' saved, effective '
            . $draft['effectiveFrom'] . '.');
    }
}
