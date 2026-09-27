<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student360 — the unified student workspace.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  THIS CONTROLLER ORCHESTRATES. IT DOES NOT DECIDE.
 * ─────────────────────────────────────────────────────────────────────────
 *  Student 360 exists because a school office currently answers "what is the
 *  situation with this child?" by opening five modules. It does NOT exist to
 *  become a sixth implementation of what those modules already do.
 *
 *  So the rules for this file are narrow and deliberate:
 *
 *    • It performs NO Firestore writes. Not one.
 *    • It contains NO pricing, NO eligibility, NO lifecycle rules.
 *    • Every action it offers is DECLARED in Action_registry and EXECUTED by
 *      the module that owns it — Entitlement_service, Transport_service, the
 *      Fees counter, the SIS certificates screen.
 *    • Its read model is assembled once by Student_context, so the action rail
 *      cannot become an N+1 fan-out as it grows.
 *
 *  Concretely: this controller is ~200 lines and would not grow if ten more
 *  actions were added, because the actions live in the registry. If a future
 *  change adds a business rule *here*, that change is in the wrong file.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  RBAC
 * ─────────────────────────────────────────────────────────────────────────
 *  The page itself gates on `SIS:view` — seeing a student's summary is a
 *  read of the student record. (The module key is the exact string `SIS`:
 *  `has_permission()` compares with `in_array(..., true)`, so `Sis` would
 *  silently deny every non-bypass role while looking correct.)
 *
 *  Each ACTION then gates independently on its
 *  own declared capability inside Action_registry::resolveFor(), so a
 *  front-office user who may view a student but not grant a concession sees
 *  the workspace without that action.
 *
 *  Both layers are advisory-for-UI only. The real authorization runs inside
 *  each owning service when the action is actually invoked — this surface
 *  never becomes the security boundary for another module.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THE ENTITLEMENTS PANEL HAS NO WRITE ENDPOINTS HERE
 * ─────────────────────────────────────────────────────────────────────────
 *  The Entitlements controller was written pathway-agnostic precisely so a
 *  fourth surface could mount it unchanged — its own header says "Adding a
 *  fourth surface later (Student 360) requires no change here." Student 360
 *  therefore posts to `entitlements/propose|approve|reject|discontinue` and
 *  renders `_entitlements_capture.php`. No entitlement endpoint is duplicated
 *  in this controller, and none may be added.
 */
class Student360 extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    /**
     * Student-id validation for the HTML route.
     *
     * The canonical `safe_path_segment()` is used on the JSON endpoints below,
     * exactly as Entitlements.php does. It cannot be used here: on a bad value
     * it emits a JSON error body and `exit`s, which on a page route would send
     * a browser a JSON 400 instead of a page. `_is_safe_segment()` — the pure
     * predicate underneath it — is private on MY_Controller and unreachable,
     * so the same character class is applied directly for the HTML path.
     */
    private function _valid_id(string $id): bool
    {
        return $id !== '' && (bool) preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $id);
    }

    /** The registry, loaded the CI way so the class is always defined. */
    private function _registry()
    {
        $this->load->library('Action_registry', null, 'actionRegistry');
        return $this->actionRegistry;
    }

    /**
     * Build the read model. Constructed identically for the page render and
     * the JSON endpoints, so the two can never disagree about what is true.
     *
     * Transport_service is init'd exactly as Transport.php does it — same
     * arguments, same order — because a divergent init is how a tenant- or
     * session-scoping bug gets introduced quietly.
     */
    private function _context(string $studentId): ?array
    {
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);

        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );

        // Constructed exactly as Entitlements::_svc() does — same arguments,
        // same order. Student 360 reads entitlements through this service; it
        // never queries `studentEntitlements` itself.
        $this->load->library('Entitlement_service', null, 'entitlementSvc');
        $this->entitlementSvc->init(
            $this->firebase, $this->fs, $this->school_id, $this->session_year,
            (string) ($this->admin_id ?? '')
        );

        $this->load->library('Student_context', null, 'studentCtx');
        $ctx = $this->studentCtx
            ->init(
                $this->firebase, $this->fs, $this->school_id, $this->session_year,
                $this->transport, $this->entitlementSvc
            )
            ->build($studentId);

        return $ctx === null ? null : $this->_redact($ctx);
    }

    /**
     * Strip eagerly-loaded module data the current user may not see.
     *
     * ── WHY THIS EXISTS ──────────────────────────────────────────────────
     * The LAZY panels each check their own capability before fetching, so a
     * user without `Attendance:view` can never obtain attendance data. The
     * EAGER context has no such gate: it is built once, before anyone asks for
     * a particular panel.
     *
     * That was harmless while the eager set held only the workspace summary,
     * but Discipline is different — behaviour records are exactly the kind of
     * data a front-office user may legitimately be denied. Loading it eagerly
     * to drive the alert strip would otherwise have handed it to every holder
     * of `SIS:view`, straight through `student360/context` as JSON.
     *
     * So the data is REMOVED here, not merely hidden in the view. Hiding a tab
     * leaves the payload in the JSON endpoint; removing the key means there is
     * nothing to leak. Alerts are then recomputed from the redacted context,
     * so a user who cannot see discipline does not get a discipline alert
     * either — an alert is a disclosure too.
     *
     * The trade is deliberate: a permitted user pays 2 reads for the alert
     * strip; an unpermitted user pays them and gets nothing. Skipping the read
     * would require knowing the permission before building the context, which
     * would push RBAC into Student_context and split the rule across two files.
     */
    private function _redact(array $ctx): array
    {
        if (!function_exists('has_permission') || !has_permission('Red Flags', 'view')) {
            $ctx['discipline'] = ['available' => false, 'permitted' => false, 'flags' => [],
                                  'activeCount' => 0, 'resolvedCount' => 0,
                                  'byType' => [], 'bySeverity' => [], 'highActive' => 0];
        } else {
            $ctx['discipline']['permitted'] = true;
        }

        // The withheld/exam-blocked flags are fee facts; gate them with Fees.
        if (!function_exists('has_permission') || !has_permission('Fees', 'view')) {
            $ctx['defaulter'] = ['available' => false, 'permitted' => false, 'isDefaulter' => false,
                                 'totalDues' => 0.0, 'resultWithheld' => false, 'examBlocked' => false];
        } else {
            $ctx['defaulter']['permitted'] = true;
        }

        // Recompute alerts over the redacted view — never over the raw one.
        $this->load->library('Student_context', null, 'studentCtx');
        $ctx['alerts'] = $this->studentCtx->alertsFor($ctx);
        return $ctx;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PAGE
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET student360/view/{studentId} — the workspace.
     */
    public function view(string $studentId = '')
    {
        $this->_require_role([], 'student360_view', 'SIS', 'view');

        $studentId = trim($studentId);
        // A malformed id and a missing student both end at show_404() — see
        // the enumeration note below; there is no reason to distinguish them.
        if (!$this->_valid_id($studentId)) { show_404(); return; }

        $ctx = $this->_context($studentId);
        if ($ctx === null) {
            // Covers "no such student" and "student belongs to another
            // tenant" with ONE indistinguishable response. Splitting them
            // would turn this page into an id-enumeration oracle across
            // schools.
            show_404();
            return;
        }

        $this->load->library('Student_timeline_reader', null, 'timelineReader');
        $timeline = $this->timelineReader
            ->init($this->firebase, $this->school_id)
            ->forStudent($studentId);

        $data = [
            'title'    => 'Student 360 — ' . $ctx['name'],
            'ctx'      => $ctx,
            'actions'  => $this->_registry()->resolveFor($ctx),
            'timeline' => $timeline,
        ];
        $this->load->view('include/header', $data);
        $this->load->view('student360/workspace', $data);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  JSON
    // ─────────────────────────────────────────────────────────────────────

    /**
     * GET student360/context/{studentId} — the same snapshot, as JSON, for
     * panel refresh after an action completes.
     *
     * Refreshing through this endpoint (rather than patching the DOM from an
     * action's own response) means the workspace always redisplays state read
     * back from the owning modules. If a service partially applied a change,
     * the operator sees what actually happened, not what the UI hoped for.
     */
    public function context(string $studentId = '')
    {
        $this->_require_role([], 'student360_context', 'SIS', 'view');

        $studentId = trim($studentId);
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }

        $ctx = $this->_context($studentId);
        if ($ctx === null) return $this->json_error('Student not found.');

        return $this->json_success([
            'context' => $ctx,
            'actions' => $this->_registry()->resolveFor($ctx),
        ]);
    }

    /**
     * GET student360/panel/{name}/{studentId} — one lazy panel.
     *
     * ── WHY PANELS ARE LAZY ──────────────────────────────────────────────
     * Loading attendance, hostel and documents on every page view would make
     * the common case (a clerk opening a student to take a payment) pay for
     * the rare one, and would multiply the read cost recorded as D-04 by
     * roughly six. Each panel is fetched only when its tab is opened.
     *
     * ── EACH PANEL CARRIES ITS OWN CAPABILITY ────────────────────────────
     * `SIS:view` gets you the workspace; it does not get you every module's
     * data through it. A user who may not open the Attendance module must not
     * read attendance by way of Student 360 — otherwise this surface becomes a
     * way around the permission model rather than a view onto it.
     */
    private const PANEL_PERMISSION = [
        'attendance'   => ['Attendance',   'view'],
        'hostel'       => ['Hostel',       'view'],
        'documents'    => ['SIS',          'view'],
        'certificates' => ['Certificates', 'view'],
        // A-1 gates on Results, not Examinations: this panel shows published
        // RESULTS (marks already computed and released), and a user cleared to
        // set up exams is not automatically cleared to read a child's marks.
        'results'      => ['Results',      'view'],
        'receipts'     => ['Fees',         'view'],
    ];

    public function panel(string $name = '', string $studentId = '')
    {
        $name = trim($name);
        if (!isset(self::PANEL_PERMISSION[$name])) return $this->json_error('Unknown panel.');

        [$mod, $lvl] = self::PANEL_PERMISSION[$name];
        $this->_require_role([], 'student360_panel_' . $name, $mod, $lvl);

        $studentId = trim($studentId);
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }

        // Resolve through the context first so a student outside this tenant
        // cannot have any panel read, even though each query is itself
        // schoolId-scoped. Defence in depth.
        if ($this->_context($studentId) === null) return $this->json_error('Student not found.');

        $this->load->library('Document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_id);

        // Fees' own receipt accessor. Constructed here rather than inside the
        // reader so Student 360 owns no module's configuration.
        $this->load->library('Fee_firestore_txn', null, 'feeTxn');
        $this->feeTxn->init($this->firebase, $this->fs, $this->school_id, $this->session_year);

        $this->load->library('Student_panel_reader', null, 'panelReader');
        $reader = $this->panelReader->init(
            $this->firebase, $this->fs, $this->school_id, $this->session_year,
            $this->docsvc, $this->feeTxn
        );

        // Explicit dispatch rather than `$reader->{$name}()`. `$name` is already
        // constrained to the PANEL_PERMISSION keys above, so a variable call
        // would be safe today — but it would also silently expose any future
        // public method that happened to share a panel's name. A match arm
        // costs one line and cannot drift.
        $data = match ($name) {
            'attendance'   => $reader->attendance($studentId),
            'hostel'       => $reader->hostel($studentId),
            'documents'    => $reader->documents($studentId),
            'certificates' => $reader->certificates($studentId),
            'results'      => $reader->results($studentId),
            'receipts'     => $reader->receipts($studentId),
        };

        return $this->json_success(['panel' => $name, 'data' => $data]);
    }

    /**
     * GET student360/timeline/{studentId} — history only, loaded on demand.
     *
     * Split from the page render on purpose: the timeline is the most
     * expensive part of the workspace and the least often read. Keeping it
     * behind its own request means the summary — the part the office actually
     * needs at the counter — is never waiting on audit reads.
     */
    public function timeline(string $studentId = '')
    {
        $this->_require_role([], 'student360_timeline', 'SIS', 'view');

        $studentId = trim($studentId);
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }

        // Resolve through the context first, so a student outside this tenant
        // cannot have their history read even though the audit queries are
        // themselves schoolId-scoped. Defence in depth, cheap here.
        if ($this->_context($studentId) === null) return $this->json_error('Student not found.');

        $this->load->library('Student_timeline_reader', null, 'timelineReader');
        return $this->json_success(
            $this->timelineReader->init($this->firebase, $this->school_id)->forStudent($studentId)
        );
    }
}
