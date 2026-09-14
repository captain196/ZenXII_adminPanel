<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'libraries/Timetable_plan_store.php';
// Loaded here, not lazily: versions() reads MAX_CANDIDATES to report how
// much room is left, and a constant on an unloaded class is a fatal error
// rather than a missing value.
require_once APPPATH . 'libraries/Timetable_service.php';

/**
 * Timetable_lifecycle — the canonical Master Timetable server surface.
 *
 * The version architecture is the source of truth for timetables. This is the
 * only HTTP surface that reaches it. The legacy `timetables` collection is a
 * derived compatibility projection for consumers that cannot yet read the
 * canonical architecture, and nothing here writes it.
 *
 *   list      → versions for this session, and what applies on a date
 *   draft     → a new change version copied from an existing one
 *   generate  → generation into a NEW isolated candidate (never live data)
 *   save_day  → edit a draft/candidate day (refused once published)
 *   validate  → check a candidate, recording a verdict tied to its content
 *   publish   → make a validated candidate live from a stated date
 *   projection_preview → what the compatibility projection WOULD write
 *
 * ── AUTHORIZATION ───────────────────────────────────────────────────────
 *
 * Graded RBAC on the existing `Academic` module, which is where timetables
 * already live (Academic.php gates every timetable action with it today):
 *
 *   view   → reading versions, resolution, verdicts, projection previews
 *   edit   → creating and editing drafts and candidates, generating, validating
 *   manage → publishing
 *
 * Publishing is separated at `manage` deliberately: editing a candidate affects
 * nobody, and publishing changes what every teacher and parent sees. No legacy
 * role-name gate is used here — `_require_role()` still shadows RBAC in parts
 * of this codebase and blocks custom roles, and a new surface must not inherit
 * that.
 *
 * ── WHAT THIS SURFACE DOES NOT DO ───────────────────────────────────────
 *
 * It cannot unpublish, edit a published version, delete a version, or write the
 * legacy collection. Corrections are made by taking a change version and
 * publishing a new one. Materialising a published version for the Teacher app
 * is the projection cron's job, run deliberately and out of band.
 */
class Timetable_lifecycle extends MY_Controller
{
    /** @var Timetable_plan_store */
    private $store;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
    }

    /** Bind the store to THIS request's tenant and session. Never caller-supplied. */
    private function store(): Timetable_plan_store
    {
        if ($this->store === null) {
            // TT-C8 option 6 — the audit service is WIRED here now.
            //
            // It never was. init()'s fifth argument was omitted at this call
            // site and at every other one, so Timetable_plan_store::_audit()
            // returned immediately and the entire version lifecycle — create,
            // publish, project, delete — recorded nothing at all. The same
            // pattern already exists in generator() below for the legacy
            // generator; this is that pattern, applied to the store.
            //
            // Publishing changes what every teacher sees, and SUPERSEDE ends a
            // live timetable. Neither can ship without a record of who did it.
            $this->load->library('audit_log_service', null, 'audit_svc');
            if (!$this->audit_svc->isReady()) {
                $this->audit_svc->init(
                    $this->firebase, (string) $this->school_id, (string) $this->session_year,
                    [
                        'uid'  => (string) ($this->admin_id   ?? ''),
                        'name' => (string) ($this->admin_name ?? ''),
                        'role' => (string) ($this->admin_role ?? ''),
                    ]
                );
            }

            $this->store = (new Timetable_plan_store())->init(
                $this->firebase,
                (string) $this->school_id,
                (string) $this->session_year,
                (string) ($this->session->userdata('admin_id') ?? ''),
                $this->audit_svc
            );
        }
        return $this->store;
    }

    /** The one plan the compatibility contract permits. See ensureRegularPlan(). */
    private function planId(): string
    {
        $plan = $this->store()->ensureRegularPlan();
        return (string) ($plan['planId'] ?? 'REGULAR');
    }

    // ── the screen ──────────────────────────────────────────────────────

    /**
     * The Master Timetable screen.
     *
     * `view` to open it: seeing which timetable applies and what a check found
     * changes nothing. The two grades that DO change something are passed to the
     * page so it can hide what the caller cannot do — but hiding a button is not
     * authorization, and every action re-checks its own permission server-side.
     */
    public function index(): void
    {
        require_permission('Academic', 'timetable_lifecycle', 'view');

        // The timetable is a tab of Academic Planner, beside the two things it
        // is built from. This URL is kept because it was published, linked and
        // bookmarked — it lands on the same screen rather than 404ing, and
        // Academic Planner already opens a tab from the hash.
        redirect(base_url('academic#timetable'));
    }
    // ── read ────────────────────────────────────────────────────────────

    /**
     * The generator, bound to THIS request's tenant and session.
     *
     * Loading the library is not enough: Timetable_service keeps the school id
     * and session as state and every lookup is built from them. An un-init'd
     * instance addresses document "_" and finds nothing — which surfaced as
     * "Period Scheduling not configured" on a school whose period scheduling was
     * configured, because an empty read and an absent setting look identical
     * from the outside.
     *
     * isReady() is checked rather than assumed, so a future change to init()
     * cannot leave this silently half-wired again.
     */
    private function generator(): Timetable_service
    {
        $this->load->library('audit_log_service', null, 'audit_svc');
        if (!$this->audit_svc->isReady()) {
            $this->audit_svc->init(
                $this->firebase, (string) $this->school_id, (string) $this->session_year,
                [
                    'uid'  => (string) ($this->admin_id   ?? ''),
                    'name' => (string) ($this->admin_name ?? ''),
                    'role' => (string) ($this->admin_role ?? ''),
                ]
            );
        }

        $this->load->library('timetable_service', null, 'tt');
        if (!$this->tt->isReady()) {
            $this->tt->init(
                $this->firebase, $this->fs,
                (string) $this->school_id, (string) $this->session_year,
                (string) ($this->admin_id   ?? ''),
                (string) ($this->admin_name ?? ''),
                $this->audit_svc
            );
        }

        if (!$this->tt->isReady()) {
            throw new Service_exception(
                'The timetable service could not be started for this school, so '
                . 'nothing was generated. Please contact support.',
                'not_ready'
            );
        }
        return $this->tt;
    }
    public function versions(): void
    {
        require_permission('Academic', 'timetable_versions', 'view');

        try {
            $versions = $this->store()->listVersions();
            $date     = (string) ($this->input->get('date') ?: date('Y-m-d'));
            $resolved = $this->store()->resolveForDate($date);
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
            return;
        }

        $this->json_success([
            'versions'   => $versions,
            'date'       => $date,
            'resolution' => $resolved,
            // How close this session is to the point where no timetable can
            // be resolved at all. Sent so the screen can show it growing
            // rather than springing a refusal on the operator.
            'versionCount' => count($versions),
            'versionLimit' => Timetable_plan_store::VERSION_SCAN_LIMIT,
            'batchMax'     => Timetable_service::MAX_CANDIDATES,
        ]);
    }

    /**
     * A version and its days, for the grid editor.
     *
     * `view`: looking at a candidate changes nothing. The payload says whether
     * the version is editable so the screen can show the operator they are
     * looking at a published timetable rather than letting them try and be
     * refused cell by cell.
     */
    public function days(): void
    {
        require_permission('Academic', 'timetable_days', 'view');

        $versionId = (string) ($this->input->get('version_id') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $read = $this->store()->readVersionForEditing($this->planId(), $versionId);
            // What this school calls each break. Cosmetic, so a failure to read
            // it returns nothing rather than failing the week.
            $read['breaks'] = $this->store()->breakLabels();
            $this->json_success($read);
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * What may be put in a cell for one class-section.
     *
     * Reads the subject-teacher assignments that already govern who teaches what.
     * The legacy editor only WARNED when a period used a pairing with no
     * assignment behind it; offering the assigned pairings instead prevents the
     * mistake rather than reporting it afterwards.
     */
    public function editor_options(): void
    {
        require_permission('Academic', 'timetable_editor_options', 'view');

        $classSection = (string) ($this->input->get('class_section') ?: '');
        if ($classSection === '') {
            $this->json_error('A class and section are required.');
            return;
        }

        $parts   = explode('/', $classSection, 2);
        $class   = trim($parts[0] ?? '');
        $section = trim($parts[1] ?? '');

        $this->load->library('subject_assignment_service', null, 'sas');
        if (!$this->sas->isReady()) {
            $this->sas->init($this->fs, $this->firebase,
                (string) $this->school_id, (string) $this->school_id,
                (string) $this->session_year);
        }

        try {
            $merged = array_merge(
                $this->sas->getAssignmentsForClass($class, $section),
                $this->sas->getAssignmentsForClass($class, '')
            );
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_lifecycle: assignment read failed: ' . $e->getMessage());
            $this->json_error('The subject assignments could not be read, so the '
                . 'options for this class are not available. Please try again.');
            return;
        }

        $seen = [];
        $out  = [];
        foreach ($merged as $a) {
            if (!is_array($a)) continue;
            $subject = (string) ($a['subjectName'] ?? $a['subjectCode'] ?? '');
            $tid     = (string) ($a['teacherId'] ?? '');
            if ($subject === '' || $tid === '') continue;

            $key = $subject . '|' . $tid;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $out[] = [
                'subject'        => $subject,
                'teacherId'      => $tid,
                'teacher'        => (string) ($a['teacherName'] ?? ''),
                'periodsPerWeek' => (int) ($a['periodsPerWeek'] ?? 0),
            ];
        }

        $this->json_success(['classSection' => $classSection, 'options' => $out]);
    }
    public function verdict(): void
    {
        require_permission('Academic', 'timetable_verdict', 'view');

        $versionId = (string) ($this->input->get('version_id') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $this->json_success($this->store()->currentVerdict($this->planId(), $versionId));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * What the compatibility projection WOULD write. Read-only: this never
     * passes $apply, so no teacher-visible document can change through an HTTP
     * request. Applying it is the cron's job, behind APPLY=1.
     */
    public function projection_preview(): void
    {
        require_permission('Academic', 'timetable_projection_preview', 'view');

        $versionId = (string) ($this->input->get('version_id') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $this->json_success($this->store()->projectPublishedVersion(
                $this->planId(), $versionId));   // dry run — $apply omitted
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Is the legacy mirror showing what the resolver says is in force?
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     *
     * Publishing is instant for the resolver and invisible to everyone reading
     * `timetables` — the Teacher app, the Parent app, Substitute, Lesson
     * Planner. Those documents change only when the projection cron runs, and
     * this repository does not own a scheduler (every ZenX cron is CLI-only,
     * one tenant per invocation, driven by the operator's own crontab — see
     * Transport_expiry_cron).
     *
     * So a correct, published, in-force timetable can sit unmaterialised for
     * days while every app shows the previous one, and nothing says so. That is
     * how it was found on 2026-09-12: the only symptom was a teacher missing
     * from a workload table.
     *
     * This makes the gap VISIBLE where it is created. It is read-only and it
     * decides nothing: it asks the resolver which version is in force, then
     * asks the projection for a DRY RUN. A pending write means the mirror
     * disagrees with the canonical answer. No second authority, no new rule.
     *
     * `view`: seeing whether teachers are looking at today's timetable changes
     * nothing. Applying it still requires the CLI and APPLY=1 — the seam where
     * a person decides, which this does not move.
     */
    public function visibility(): void
    {
        require_permission('Academic', 'timetable_projection_preview', 'view');

        $date = (string) ($this->input->get('date') ?: '');
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        try {
            $store  = $this->store();
            $planId = $this->planId();
            $res    = $store->resolveForDate($date);
            $status = (string) ($res['status'] ?? '');

            $out = [
                'date'      => $date,
                'status'    => $status,
                'versionId' => (string) ($res['version']['versionId'] ?? ''),
                'explain'   => (string) ($res['explain'] ?? ''),
                // The exact command, so the answer carries its own remedy.
                'command'   => 'APPLY=1 SCHOOL_ID=' . $this->school_id
                             . ' SESSION_YEAR=' . $this->session_year
                             . ' php index.php timetable_projection_cron run',
            ];

            // Only a single resolved version can be compared. Everything else
            // means we do not know what SHOULD be there, and claiming the
            // mirror is "current" or "stale" would both be inventions.
            if ($status !== Timetable_plan_service::RESOLVED || $out['versionId'] === '') {
                $out['comparable'] = false;
                $out['pending']    = null;
                $this->json_success($out);
                return;
            }

            $plan = $store->projectPublishedVersion($planId, $out['versionId']);  // dry run

            $out['comparable'] = true;
            $out['pending']    = count($plan['writes'] ?? []);
            $out['unchanged']  = count($plan['unchanged'] ?? []);
            $out['orphans']    = count($plan['orphans'] ?? []);

            // ── equivalence, not "the command ran" ──────────────────────
            //
            // `current` is the answer to "are teachers seeing this timetable",
            // so it must be an equivalence check and nothing weaker. It is
            // taken from the diagnosis rather than recomputed from the pending
            // count, because those two disagree in the case that matters: the
            // apps can hold the right NUMBER of documents, all of them wrong.
            $dx = is_array($plan['diagnosis'] ?? null) ? $plan['diagnosis'] : [];
            $out['current']         = (bool) ($dx['inSync'] ?? false);
            $out['diagnosis']       = $dx['summary'] ?? '';
            $out['states']          = $dx['counts'] ?? [];
            $out['expected']        = $dx['expected'] ?? 0;
            // Which published version the apps' copy actually came from, and
            // when. More than one entry means the collection is a mixture,
            // which no single publish produces and a partial run does.
            $out['sourceVersions']  = $dx['sourceVersions'] ?? [];
            $out['lastProjectedAt'] = $dx['lastProjectedAt'] ?? '';

            $this->json_success($out);
                return;
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_lifecycle::visibility failed: ' . $e->getMessage());
            $this->json_error(
                'Whether teachers are seeing the current timetable could not be '
                . 'determined. This is not a confirmation that they are.');
        }
    }

    /**
     * Send the timetable that is in force TODAY to the Teacher and Parent apps.
     *
     * ── WHY THIS EXISTS ─────────────────────────────────────────────────
     *
     * publishCandidate() already materialises a version into the legacy
     * `timetables` collection — but only when the version is in force on the
     * day it is published. Publish one that starts LATER and the materialising
     * falls to Timetable_projection_cron, which nothing in this repository
     * schedules. The result, seen on a real tenant on 2026-09-12: a timetable
     * published on the 12th to start on the 13th, and Teacher/Parent apps that
     * would never have shown it. The office saw one timetable and the school
     * saw another, with no error anywhere.
     *
     * So the school could not complete, from its own screen, an operation the
     * product performs on its behalf every other time. That is the gap this
     * closes, and it closes it the way the rest of the lifecycle works: a
     * person decides and presses a button.
     *
     * ── WHAT THIS IS NOT ────────────────────────────────────────────────
     *
     * This is NOT a scheduler. Nothing here runs on a timer, and the cron keeps
     * the one job it alone can do — refreshing unattended when a future-dated
     * timetable becomes effective overnight. This only makes the same operation
     * reachable by the administrator who published.
     *
     * It is also NOT a change to visibility(), which stays a dry run. Reading
     * whether teachers see the current timetable and changing what they see are
     * different authorities, and one endpoint holding both would mean any
     * caller of the cheaper one could perform the costlier.
     *
     * `manage`, the same grade as publish(): this decides what every teacher and
     * parent in the school opens tomorrow morning.
     *
     * TODAY ONLY, deliberately — the same rule Timetable_projection_cron
     * enforces (TT-C8-D1). The legacy collection has ONE document per section
     * per weekday and no date dimension, so it can only ever represent the
     * timetable in force now. Materialising a future version would publish it
     * early to every app, and materialising a past one would roll the school
     * back. Neither is expressible as an undo.
     */
    public function apply_projection(): void
    {
        require_permission('Academic', 'timetable_projection_apply', 'manage');

        $today = date('Y-m-d');

        // The date is accepted only so a stale screen cannot silently apply a
        // different day's timetable than the one the operator was looking at.
        // It is a confirmation, never a selector.
        $asked = (string) ($this->input->post('date') ?: $today);
        if ($asked !== $today) {
            $this->json_error(
                'Only the timetable in force today can be sent to teachers and '
                . 'parents. The apps hold one timetable per weekday, with no date '
                . 'attached, so sending another day\'s would show it on the wrong '
                . 'day. Nothing was changed — reload the page and try again.');
            return;
        }

        try {
            $store  = $this->store();
            $planId = $this->planId();
            $res    = $store->resolveForDate($today);
            $status = (string) ($res['status'] ?? '');
            $vid    = (string) ($res['version']['versionId'] ?? '');

            // "No timetable applies" and "we could not tell" are different, and
            // neither is a reason to write. Writing on an unreadable resolve
            // would be the exact failure this programme removed: acting on an
            // absence we never proved.
            if ($status !== Timetable_plan_service::RESOLVED || $vid === '') {
                $this->json_error(
                    (string) ($res['explain'] ?? '')
                    ?: 'Which timetable applies today could not be determined, so '
                     . 'nothing was sent. Teachers and parents still see what they '
                     . 'saw before.');
                return;
            }

            $plan = $store->projectPublishedVersion($planId, $vid, true);

            $written = (int) ($plan['written'] ?? 0);
            $orphans = is_array($plan['orphans'] ?? null) ? $plan['orphans'] : [];

            $this->json_success([
                'versionId' => $vid,
                'written'   => $written,
                'unchanged' => count($plan['unchanged'] ?? []),
                'orphans'   => count($orphans),
                // Deliberately NOT "they now see it". Both apps read this
                // collection with a one-shot get() backed by a persistent disk
                // cache and no snapshot listener, so a phone already sitting on
                // the timetable screen keeps showing the old one until it is
                // pulled to refresh or reopened. Claiming otherwise would send
                // an office to tell a teacher to look again at something that
                // has not changed on their screen yet.
                'message'   => $written === 0
                    ? 'Teachers and parents were already being sent this timetable '
                      . '— nothing needed changing.'
                    : 'Sent. ' . $written . ' class page(s) updated. Teachers and '
                      . 'parents will see it the next time their app refreshes — '
                      . 'immediately if they reopen the timetable screen or pull '
                      . 'down to refresh.',
            ]);
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Timetable_lifecycle::apply_projection failed: '
                . $e->getMessage());
            $this->json_error(
                'The timetable could not be sent to teachers and parents. Some '
                . 'classes may still be showing the previous one — please try '
                . 'again in a moment.');
        }
    }

    // ── edit ────────────────────────────────────────────────────────────

    public function create_change_version(): void
    {
        require_permission('Academic', 'timetable_change_version', 'edit');

        $sourceId = (string) ($this->input->post('source_version_id') ?: '');
        if ($sourceId === '') { $this->json_error('A source version is required.'); return; }

        try {
            $this->json_success($this->store()->createChangeVersion($this->planId(), $sourceId));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    public function generate_candidate(): void
    {
        require_permission('Academic', 'timetable_generate', 'edit');

        $fromVersionId = (string) ($this->input->post('from_version_id') ?: '');
        $scopeClass    = (string) ($this->input->post('scope_class') ?: '');

        try {
            $this->json_success($this->generator()->generateIntoCandidate(
                $this->store(), $this->planId(),
                $fromVersionId !== '' ? $fromVersionId : null, $scopeClass));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Save SEVERAL periods of a day at once.
     *
     * ── WHY THIS DOES NOT ACCEPT A WHOLE DAY ────────────────────────────
     *
     * The obvious shape for a bulk save is "here is the day, store it", and that
     * is exactly what the legacy saveSectionTimetable() did — which is ACAD-D21.
     * The browser's copy may be minutes old, so writing it back silently reverts
     * every change made meanwhile and reports success.
     *
     * So this takes only the cells the operator actually CHANGED, addressed by
     * period index. The store re-reads the day and replaces just those indices,
     * leaving every untouched period as the SERVER currently has it. Saving ten
     * cells is therefore exactly as safe as saving one — there is no bulk
     * shortcut that trades safety for convenience.
     *
     * `cells` is a JSON object of periodIndex => {subject, teacherId, teacher,
     * room, type}.
     */
    public function save_day(): void
    {
        require_permission('Academic', 'timetable_save_day', 'edit');

        $versionId    = (string) ($this->input->post('version_id') ?: '');
        $classSection = (string) ($this->input->post('class_section') ?: '');
        $day          = (string) ($this->input->post('day') ?: '');
        $cellsRaw     = $this->input->post('cells');

        if ($versionId === '' || $classSection === '' || $day === '') {
            $this->json_error('Version, class-section and day are all required.');
            return;
        }

        $cells = is_string($cellsRaw) ? json_decode($cellsRaw, true) : $cellsRaw;
        if (!is_array($cells) || empty($cells)) {
            $this->json_error('The changes for this day could not be read.');
            return;
        }

        // Every key must address a real period. A non-numeric key would cast to
        // 0 and silently rewrite the first period of the day.
        $clean = [];
        foreach ($cells as $index => $cell) {
            if (!is_numeric($index) || (int) $index < 0 || !is_array($cell)) {
                $this->json_error('One of the periods was not addressed correctly.');
                return;
            }
            $clean[(int) $index] = [
                'subject'   => (string) ($cell['subject']   ?? ''),
                'teacherId' => (string) ($cell['teacherId'] ?? ''),
                'teacher'   => (string) ($cell['teacher']   ?? ''),
                'room'      => (string) ($cell['room']      ?? ''),
                'type'      => (string) ($cell['type']      ?? ''),
            ];
        }

        try {
            $this->json_success($this->store()->saveCandidateDayCells(
                $this->planId(), $versionId, $classSection, $day, $clean));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Edit ONE period of a draft or candidate.
     *
     * `edit`, not `manage`: changing a candidate affects nobody until it is
     * published. The store re-reads the day server-side and replaces only the
     * addressed cell, so two people editing different periods of the same day do
     * not overwrite each other, and it marks the recorded verdict stale.
     */
    public function save_period(): void
    {
        require_permission('Academic', 'timetable_save_period', 'edit');

        $versionId    = (string) ($this->input->post('version_id') ?: '');
        $classSection = (string) ($this->input->post('class_section') ?: '');
        $day          = (string) ($this->input->post('day') ?: '');
        $periodRaw    = $this->input->post('period_index');

        if ($versionId === '' || $classSection === '' || $day === ''
            || $periodRaw === null || $periodRaw === '') {
            $this->json_error('Version, class-section, day and period are all required.');
            return;
        }
        if (!is_numeric($periodRaw) || (int) $periodRaw < 0) {
            $this->json_error('That period number is not valid.');
            return;
        }

        try {
            $this->json_success($this->store()->saveCandidatePeriod(
                $this->planId(), $versionId, $classSection, $day, (int) $periodRaw, [
                    'subject'   => (string) ($this->input->post('subject')    ?: ''),
                    'teacherId' => (string) ($this->input->post('teacher_id') ?: ''),
                    'teacher'   => (string) ($this->input->post('teacher')    ?: ''),
                    'room'      => (string) ($this->input->post('room')       ?: ''),
                    'type'      => (string) ($this->input->post('type')       ?: ''),
                ]));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }
    public function validate_candidate(): void
    {
        require_permission('Academic', 'timetable_validate', 'edit');

        $versionId = (string) ($this->input->post('version_id') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $this->json_success($this->store()->validateCandidate($this->planId(), $versionId));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Discard a proposal that was never published.
     *
     * `manage`, not `edit`: editing a proposal changes work in progress, and
     * this removes it. A published timetable can never be discarded — the store
     * refuses — so the highest grade this can reach is still bounded by that.
     */
    public function discard(): void
    {
        require_permission('Academic', 'timetable_discard', 'manage');

        $versionId = (string) ($this->input->post('version_id') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $this->json_success($this->store()->discardVersion($this->planId(), $versionId));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Build several genuinely different timetables in one request.
     *
     * Same grade as generating one: both produce new proposals and neither
     * touches anything published. The count is bounded by the service, which
     * refuses honestly — naming the number that WOULD fit — rather than
     * starting work it cannot finish.
     */
    public function generate_candidates(): void
    {
        require_permission('Academic', 'timetable_generate', 'edit');

        $count      = (int) ($this->input->post('count') ?: 1);
        $scopeClass = (string) ($this->input->post('scope_class') ?: '');

        try {
            $this->json_success($this->generator()->generateCandidates(
                $this->store(), $this->planId(), $count, $scopeClass));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * What could be removed to recover capacity, and what never can.
     *
     * `view`: it reports on versions the operator can already see, and removes
     * nothing.
     */
    public function capacity(): void
    {
        require_permission('Academic', 'timetable_capacity', 'view');

        try {
            $this->json_success($this->store()->retentionReport());
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Give up on a build that never finished.
     *
     * `manage`: it is the step that makes a version removable, so it carries
     * the grade of the removal it enables rather than the grade of editing.
     */
    public function abandon(): void
    {
        require_permission('Academic', 'timetable_abandon', 'manage');

        $versionId = (string) ($this->input->post('version_id') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $this->json_success($this->store()->abandonStuckRun($this->planId(), $versionId));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Remove proposals the operator has chosen to let go of.
     *
     * `manage`, matching discard — which is what this ultimately calls for each
     * one. A dry run is the default: writing requires asking for it, so a
     * mis-wired caller reports instead of deleting.
     */
    public function reclaim(): void
    {
        require_permission('Academic', 'timetable_reclaim', 'manage');

        $ids = $this->input->post('version_ids');
        if (is_string($ids)) { $ids = array_filter(explode(',', $ids)); }
        if (!is_array($ids) || empty($ids)) {
            $this->json_error('Choose at least one timetable to remove.');
            return;
        }

        $apply = (string) ($this->input->post('apply') ?: '') === '1';

        try {
            $this->json_success($this->store()->reclaimVersions(
                $this->planId(), array_map('strval', $ids), !$apply));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Name a timetable. `edit`, not `manage`: naming changes no schedule.
     *
     * Permitted on a published timetable too -- see renameVersion() in the
     * store for why a label is metadata rather than the immutable data.
     */
    public function rename(): void
    {
        require_permission('Academic', 'timetable_rename', 'edit');

        $versionId = (string) ($this->input->post('version_id') ?: '');
        $label     = (string) ($this->input->post('label') ?: '');
        if ($versionId === '') { $this->json_error('A version is required.'); return; }

        try {
            $this->json_success($this->store()->renameVersion(
                $this->planId(), $versionId, $label));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * Create an empty timetable for the school to fill in by hand.
     *
     * The same grade as generating one: both produce a new proposal and
     * neither touches anything published.
     */
    public function create_blank(): void
    {
        require_permission('Academic', 'timetable_generate', 'edit');

        $scopeClass = (string) ($this->input->post('scope_class') ?: '');

        try {
            $this->json_success($this->generator()->createBlankCandidate(
                $this->store(), $this->planId(), $scopeClass));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * The timetable as a PDF, to print or hand out.
     *
     * `view`: this reveals nothing the screen does not already show. Any
     * version can be exported, but one that is not published is stamped as
     * such on every page -- a proposal printed and pinned to a staffroom wall
     * is indistinguishable from the real thing otherwise, and that is exactly
     * how a school ends up teaching a timetable it never published.
     */
    public function pdf(): void
    {
        require_permission('Academic', 'timetable_pdf', 'view');

        $versionId = (string) ($this->input->get('version_id') ?: '');
        if ($versionId === '') { show_error('A version is required.', 400); return; }

        try {
            $store   = $this->store();
            $version = $store->getVersion($this->planId(), $versionId);
            if ($version === null) { show_error('That timetable does not exist.', 404); return; }
            $read = $store->readVersionForEditing($this->planId(), $versionId);
        } catch (Service_exception $e) {
            // A partial read must never become a partial timetable on paper.
            show_error($e->getMessage(), 500);
            return;
        }

        $html = $this->load->view('timetable/pdf', [
            'schoolName' => (string) ($this->school_display_name ?: $this->school_name),
            'session'    => (string) $this->session_year,
            'version'    => $version,
            'days'       => $read['days'] ?? [],
            // The printed sheet must call the break what the school calls it,
            // or paper and screen disagree about the same timetable.
            'breaks'     => $this->store()->breakLabels(),
        ], true);

        $this->load->library('pdf_generator');

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'sans-serif');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultPaperOrientation', 'landscape');
        $tempDir = APPPATH . 'cache/dompdf';
        if (!is_dir($tempDir)) { mkdir($tempDir, 0755, true); }
        $options->setTempDir($tempDir);
        $options->setFontDir($tempDir);
        $options->setFontCache($tempDir);

        $name = preg_replace('/[^A-Za-z0-9_]/', '',
            str_replace(' ', '_', (string) ($version['label'] ?? 'Timetable')));
        if ($name === '') { $name = 'Timetable'; }

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();
        $dompdf->stream($name . '_' . date('Ymd') . '.pdf', ['Attachment' => true]);
        exit;
    }

    // ── manage ──────────────────────────────────────────────────────────

    /**
     * Publishing is `manage`, not `edit`: everything before it is private work
     * on a candidate, and this is the step that changes what every teacher sees.
     */
    public function publish(): void
    {
        require_permission('Academic', 'timetable_publish', 'manage');

        $versionId     = (string) ($this->input->post('version_id') ?: '');
        $effectiveFrom = (string) ($this->input->post('effective_from') ?: '');
        $effectiveTo   = (string) ($this->input->post('effective_to') ?: '');

        if ($versionId === '' || $effectiveFrom === '') {
            $this->json_error('A version and the date it takes effect are both required.');
            return;
        }

        try {
            $this->json_success($this->store()->publishCandidate(
                $this->planId(), $versionId, $effectiveFrom, $effectiveTo));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }

    /**
     * End the future applicability of a published, open-ended timetable.
     *
     * `manage`, the same authority as publishing: ending what teachers are
     * currently shown is at least as consequential as starting it. The action
     * string is for the audit and lifecycle trail — RBAC grants on module and
     * graded level only, so this is not a separately grantable capability and
     * is deliberately not modelled as one.
     */
    public function supersede(): void
    {
        require_permission('Academic', 'timetable_supersede', 'manage');

        $versionId = (string) ($this->input->post('version_id') ?: '');
        $lastDate  = (string) ($this->input->post('last_effective_date') ?: '');
        $reason    = (string) ($this->input->post('reason') ?: '');

        if ($versionId === '' || $lastDate === '') {
            $this->json_error('A version and the last day it applies are both required.');
            return;
        }

        try {
            $this->json_success($this->store()->supersedeVersion(
                $this->planId(), $versionId, $lastDate, $reason));
        } catch (Service_exception $e) {
            $this->json_error($e->getMessage());
        }
    }
}
