<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once APPPATH . 'libraries/Student_calendar_service.php';
require_once APPPATH . 'libraries/Student_calendar_store.php';

/**
 * Student_calendar — the school-week administration surface.
 *
 * This is where a school states how its academic week actually runs. Before it
 * existed the student calendar could be READ but never CONFIGURED, which made
 * the whole domain inert — the mirror image of CAL-D10, where `working_days`
 * could be configured but was never read.
 *
 * IT ANSWERS THE STUDENT QUESTION ONLY
 * ------------------------------------
 * "Is the school operating for students on this date?" Staff working time is a
 * different question with a different owner — Work_schedules /
 * Policy_assignment_service — and nothing here reads or writes it. A teacher
 * training day can close the school to students while every member of staff is
 * expected in; the two authorities must be able to disagree.
 *
 * NOTHING HERE CHANGES THE PAST
 * -----------------------------
 * Saving appends a NEW effective-dated version; existing versions are never
 * edited or removed, and a version taking effect on or before settled
 * attendance is refused outright. So the worst an administrator can do from
 * this screen is change the future.
 *
 * THE DIVERGENCE FLOW (CAL-D10)
 * -----------------------------
 * `working_days` has been saveable for a long time and has never been read by
 * attendance. A school may therefore have "Mon-Fri" on file while having run a
 * six-day week throughout. get_policy() REPORTS that mismatch and proposes it;
 * it never applies it. The school picks the effective date, and everything
 * before that date keeps meaning exactly what it already meant.
 */
class Student_calendar extends MY_Controller
{
    /** RBAC module. The school week is attendance configuration. */
    const MODULE = 'Attendance';

    public function __construct()
    {
        parent::__construct();
        $this->load->helper('rbac');
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * The School Week screen.
     *
     * Renders the shell only; every value is fetched through get_policy() so the
     * page has exactly one source of truth and cannot drift from the API that
     * the save path validates against.
     */
    public function index()
    {
        require_permission(self::MODULE, 'student_calendar_view', 'view');

        $this->load->view('include/header');
        $this->load->view('student_calendar/index');
        $this->load->view('include/footer');
    }

    /**
     * Current policy, history, and any divergence needing a decision.
     */
    public function get_policy()
    {
        require_permission(self::MODULE, 'student_calendar_view', 'view');

        try {
            $reader = $this->_reader();

            $today = $this->_today();
            [$version, $source] = $reader->versionFor($today);

            return $this->json_success([
                'configured'      => $reader->isConfigured(),
                'readable'        => $reader->load_ok(),
                'source'          => $source,
                'session'         => $this->session_year,
                'today'           => $today,
                'active'          => $version,
                'versions'        => $reader->versions(),
                'dayTokens'       => Student_calendar_service::DAYS,
                'dayNames'        => Student_calendar_service::DAYS_LONG,
                'settledThrough'  => $this->_settled_through(),
                'divergence'      => $reader->divergence(),
                'legacyNote'      => Student_calendar_service::legacyRuleDescription(),
                'readiness'       => $this->_calendar_readiness(),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Student_calendar::get_policy failed: ' . $e->getMessage());
            return $this->json_error('The school week could not be loaded.', 500);
        }
    }

    /**
     * Resolve a month WITHOUT saving anything — the "show me what this would do"
     * step. An administrator should never have to save a policy to discover
     * which days it closes.
     */
    public function preview()
    {
        require_permission(self::MODULE, 'student_calendar_view', 'view');

        $year  = (int) $this->input->post('year');
        $month = (int) $this->input->post('month');
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return $this->json_error('Choose a valid month to preview.');
        }

        // A draft version may be supplied to preview a policy that is not saved.
        $draft = $this->input->post('draft');
        if (is_string($draft)) $draft = json_decode($draft, true);

        try {
            $reader = $this->_reader();

            $holidayMap = $swdMap = [];
            try {
                $this->load->library('holiday_service');
                $this->holiday_service->init(
                    $this->fs, (string) $this->school_id, (string) $this->session_year);
                // STUDENT audience — a staff-only event must never appear as a
                // student closure in this preview.
                $holidayMap = $this->holiday_service->holidays_in_month($year, $month, 'students');
                $swdMap     = $this->holiday_service->special_working_days_in_month(
                    $year, $month, 'students');
            } catch (\Throwable $e) {
                log_message('error', 'Student_calendar::preview holidays unreadable: '
                    . $e->getMessage());
            }

            if (is_array($draft) && !empty($draft)) {
                // Preview is DATE-AGNOSTIC: _preview_with_draft() resolves every
                // day against a fixed epoch and never consults the caller's
                // effectiveFrom. The editor therefore sends a draft with no date
                // yet (currentDraft() = {weeklyOffs, nthWeekdayOffs}). The
                // save-time validateVersion() hard-requires effectiveFrom, so
                // running it verbatim rejected every live preview with
                // "An effective-from date (YYYY-MM-DD) is required." — the red
                // error an administrator saw before touching anything. Validate
                // the PATTERN only by supplying the same placeholder date the
                // preview already uses; the REAL date is still validated at
                // save_version() (via Student_calendar_store::saveVersion).
                $check = Student_calendar_service::validateVersion(
                    $draft + ['effectiveFrom' => '1970-01-01']
                );
                if (empty($check['ok'])) {
                    return $this->json_error(implode(' ', $check['errors']));
                }
                $days = $this->_preview_with_draft($draft, $year, $month, $holidayMap, $swdMap);
                return $this->json_success([
                    'year' => $year, 'month' => $month,
                    'nonWorking' => $days, 'source' => 'draft',
                ]);
            }

            $out = $reader->resolveMonth($year, $month, $holidayMap, $swdMap);
            return $this->json_success([
                'year'       => $year,
                'month'      => $month,
                'nonWorking' => $out['nonWorking'],
                'source'     => $out['source'],
                'configured' => $out['configured'],
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Student_calendar::preview failed: ' . $e->getMessage());
            return $this->json_error('The preview could not be generated.', 500);
        }
    }

    /**
     * Append a new school week, effective from a chosen future date.
     */
    public function save_version()
    {
        require_permission(self::MODULE, 'student_calendar_manage', 'manage');

        $weeklyOffs = $this->input->post('weeklyOffs');
        if (is_string($weeklyOffs)) $weeklyOffs = json_decode($weeklyOffs, true);
        $nth = $this->input->post('nthWeekdayOffs');
        if (is_string($nth)) $nth = json_decode($nth, true);

        $proposed = [
            'effectiveFrom'  => trim((string) $this->input->post('effectiveFrom')),
            'weeklyOffs'     => is_array($weeklyOffs) ? $weeklyOffs : [],
            'nthWeekdayOffs' => is_array($nth) ? $nth : [],
            'label'          => trim((string) $this->input->post('label')),
            'reason'         => trim((string) $this->input->post('reason')),
        ];

        try {
            $store = (new Student_calendar_store())->init(
                $this->firebase,
                (string) $this->school_id,
                (string) $this->session_year,
                (string) ($this->session->userdata('admin_id') ?? '')
            );

            $res = $store->saveVersion($proposed, $this->_settled_through());

            switch ($res['status']) {
                case Student_calendar_store::OK:
                    return $this->json_success([
                        'versions' => $res['versions'],
                        'message'  => $res['message'],
                    ]);

                case Student_calendar_store::INVALID:
                    return $this->json_error(implode(' ', $res['errors']), 422);

                case Student_calendar_store::CONFLICT:
                    // 409 so the front-end can offer a reload rather than a retry
                    // that would silently write over somebody else's policy.
                    return $this->json_error(implode(' ', $res['errors']), 409);

                default:
                    return $this->json_error(implode(' ', $res['errors']), 500);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_calendar::save_version failed: ' . $e->getMessage());
            return $this->json_error('The school week could not be saved.', 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * CAL-P4 — has this school actually declared any holidays for the period
     * ahead?
     *
     * WHY THIS IS THE ROLLOVER ANSWER. Investigation of
     * `scripts/rollover_academic_session.php` showed the student calendar needs
     * no rollover CARRY at all, and must not have one:
     *
     *   - The weekly pattern lives on `attendanceSettings/{schoolId}` — SCHOOL
     *     scoped, not session scoped — and its versions are effective-dated. A
     *     pattern effective from 2026-04-01 therefore continues into every later
     *     session automatically, with no copy. Copying it at rollover would at
     *     best duplicate a version and at worst be REFUSED outright by the
     *     duplicate-effectiveFrom guard.
     *
     *   - Dated holidays are deliberately NOT carried ("holidays change
     *     year-over-year"), which is correct: last year's Diwali is not this
     *     year's Diwali.
     *
     * So the real rollover gap was never a missing copy. It is that a new
     * session begins with the weekly pattern intact and NO dated holidays, and
     * nobody is told. Attendance then quietly treats every festival as a normal
     * teaching day until somebody notices.
     *
     * This reports that state. It carries nothing, writes nothing and decides
     * nothing — the school still enters its own holidays, which is the whole
     * point of the ownership model.
     *
     * THE SESSION WINDOW COMES FROM THE CANONICAL AUTHORITY.
     *
     * An earlier pass of this method concluded no session-boundary helper
     * existed and fell back to a rolling twelve months. That was WRONG, and the
     * mistake was searching for field names rather than for a service:
     * `Leave_year_service::boundsForSession()` is exactly that authority — pure,
     * static, already live in production through `prorateEntitlement()`, and
     * configurable per tenant via `schools/{id}.academicYearStartMonth` plus a
     * `sessionBoundaries` override map.
     *
     * Reusing it means the April convention is NOT re-invented here: that
     * default is the existing, centralised ZenX convention owned by the Leave
     * Policy programme, and a school whose year starts elsewhere overrides it in
     * one place that every consumer already reads.
     *
     * The rolling window survives ONLY as a fallback for a session label the
     * authority cannot parse, so a malformed label degrades to a slightly
     * imprecise reminder rather than to no reminder at all.
     */
    private function _calendar_readiness(): array
    {
        $out = [
            'checked'       => false,
            'holidaysAhead' => 0,
            'message'       => '',
            'window'        => '',
        ];

        try {
            $this->load->library('holiday_service');
            $this->holiday_service->init(
                $this->fs, (string) $this->school_id, (string) $this->session_year);

            // Fail-loud: load_ok() tells "none declared" apart from "could not
            // look", and reporting a read failure as "0 holidays" would be a
            // false alarm that trains an administrator to ignore this banner.
            if (!$this->holiday_service->load_ok()) return $out;

            [$from, $to, $windowLabel] = $this->_session_window();

            $ahead = $this->holiday_service->holidays_in_range($from, $to, 'students');

            $out['checked']       = true;
            $out['holidaysAhead'] = count($ahead);
            $out['window']        = $windowLabel;

            if ($out['holidaysAhead'] === 0) {
                $out['message'] = 'No student holidays are declared for ' . $windowLabel
                    . '. Your school week still applies, but every festival and closure '
                    . 'will be treated as a normal teaching day until they are added '
                    . 'under Academic Planner.';
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_calendar::_calendar_readiness failed: '
                . $e->getMessage());
        }

        return $out;
    }

    /**
     * The current session's date window, from the canonical authority.
     *
     * Reads the two OPTIONAL tenant fields `Leave_year_service` consumes —
     * neither exists on any tenant today, and their absence is the fully
     * supported case: the service derives correct bounds from the session label
     * alone. Nothing is written, and no default is invented here.
     *
     * @return array{0:string,1:string,2:string} from, to, human label
     */
    private function _session_window(): array
    {
        require_once APPPATH . 'libraries/Leave_year_service.php';

        $startMonth = \Leave_year_service::DEFAULT_START_MONTH;
        $overrides  = [];
        try {
            $school = $this->fs->get('schools', $this->school_id);
            if (is_array($school)) {
                $sm = (int) ($school['academicYearStartMonth'] ?? 0);
                if ($sm >= 1 && $sm <= 12) $startMonth = $sm;
                if (is_array($school['sessionBoundaries'] ?? null)) {
                    $overrides = $school['sessionBoundaries'];
                }
            }
        } catch (\Throwable $e) {
            // Config is optional; its absence is normal. Fall through to the
            // label-derived bounds rather than refusing to answer.
            log_message('error', 'Student_calendar::_session_window config read failed: '
                . $e->getMessage());
        }

        $bounds = \Leave_year_service::boundsForSession(
            (string) $this->session_year, $overrides, $startMonth);

        if (is_array($bounds)
            && !empty($bounds['startDate']) && !empty($bounds['endDate'])) {
            return [
                (string) $bounds['startDate'],
                (string) $bounds['endDate'],
                'the ' . $this->session_year . ' session',
            ];
        }

        // Unparseable label — a reminder over the next twelve months is still
        // far better than none.
        return [
            date('Y-m-d'),
            date('Y-m-d', strtotime('+12 months')),
            'the next twelve months',
        ];
    }

    private function _reader()
    {
        $this->load->library('student_calendar_reader');
        return $this->student_calendar_reader->init(
            $this->fs, (string) $this->school_id, (string) $this->session_year);
    }

    /** Server-resolved. A client must never choose "today" for a dating rule. */
    private function _today(): string
    {
        return date('Y-m-d');
    }

    /**
     * The date through which attendance is treated as settled, and therefore the
     * boundary a new policy may not cross.
     *
     * DELIBERATELY THE LAST DAY OF THE PREVIOUS MONTH, computed rather than
     * queried. The alternative — asking Firestore for the latest month that
     * actually holds attendance — is a scan whose answer changes as marking
     * happens, so two administrators could get different boundaries seconds
     * apart and one of them would be refused for no visible reason.
     *
     * The current month stays open because it is still being marked; everything
     * before it is closed. This is conservative: it can refuse a change a school
     * might legitimately have been allowed to make, and the remedy for that is an
     * explicit, audited correction workflow rather than a looser rule here.
     */
    private function _settled_through(): string
    {
        return date('Y-m-t', strtotime('first day of last month'));
    }

    /** Resolve a month against an unsaved draft, for the preview. */
    private function _preview_with_draft(
        array $draft, int $year, int $month, array $holidayMap, array $swdMap
    ): array {
        $days = (int) cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $out  = [];
        for ($d = 1; $d <= $days; $d++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $r = Student_calendar_service::resolveForDate(
                ['effectiveFrom' => '1970-01-01']
                    + ['weeklyOffs' => $draft['weeklyOffs'] ?? [],
                       'nthWeekdayOffs' => $draft['nthWeekdayOffs'] ?? []],
                $date,
                ['holiday' => isset($holidayMap[$d]), 'specialWorkingDay' => isset($swdMap[$d])]
            );
            if ($r['attendance'] === false) {
                $out[$d] = isset($holidayMap[$d])
                    ? (string) $holidayMap[$d]
                    : (Student_calendar_service::DAYS_LONG[
                        Student_calendar_service::dayToken($date)] ?? 'Non-teaching day');
            }
        }
        return $out;
    }
}
