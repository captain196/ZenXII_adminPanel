<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Lifecycle_probe — CLI-ONLY RUNTIME HARNESS FOR THE STUDENT LIFECYCLE
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * Gate 2 asks for evidence that a lifecycle transition is CORRECT AT RUNTIME
 * across every surface — not that the source looks right. Source assertions
 * (which the LC-1..LC-7 suites use) prove wiring; they cannot prove that a
 * real Firestore write lands, that a derived roster follows it, or that a
 * report agrees with the document it is derived from.
 *
 * So this drives the REAL controller methods, in a REAL process, against
 * REAL Firestore. Nothing here re-implements business logic: every transition
 * is dispatched into `Sis` / `Classes` / `School_config` exactly as an HTTP
 * request would.
 *
 * ── WHY ONE TRANSITION PER PROCESS ───────────────────────────────────────
 * `MY_Controller::json_success()` and `json_error()` both `exit`. That is
 * correct for a web request and fatal for an in-process sequence: the first
 * transition would terminate the harness. Each step therefore runs as its own
 * `php index.php lifecycle_probe step …` process, which also mirrors
 * production far better than a single long-lived script — every transition
 * gets a cold controller, a fresh session and its own Firestore client.
 *
 * ── WHY CLI-ONLY ─────────────────────────────────────────────────────────
 * `show_404()` for any non-CLI caller, matching `Deptrole_selftest` and the
 * `Transport_probe_*` controllers. This is the established ZenX pattern for
 * runtime verification and it is what makes seeding a session safe: there is
 * no route by which a browser can reach it. CI3 also skips CSRF verification
 * under CLI (`CI_Input::__construct` guards on `is_cli()`), so the harness
 * needs no token and no CSRF exemption is added anywhere.
 *
 * ── ON SEEDING THE SESSION ───────────────────────────────────────────────
 * The seed reproduces exactly what `Admin_login` writes, using the tenant's
 * REAL administrator identity and REAL role. It grants nothing: authorization
 * still runs for real inside every endpoint, so a transition that a School
 * Super Admin may not perform still fails here. Fabricating a privileged
 * identity would have made every RBAC result meaningless.
 *
 *   php index.php lifecycle_probe step <stepName> [args…]
 */
class Lifecycle_probe extends CI_Controller
{
    /**
     * ── THIS HARNESS WRITES, SO IT ONLY RUNS AGAINST AN EMULATOR ─────────
     *
     * Originally this was pinned to the live tenant `SCH_B56BB9A401`. Running
     * it there was refused, and correctly so: a harness that drives real
     * lifecycle endpoints against an operator's real school is a write-probe
     * on production data no matter how careful the sequence is.
     *
     * The tenant is now supplied by the driver, and `_assertEmulator()` below
     * refuses to proceed unless Firestore is pointed at an emulator. That is
     * strictly SAFER than the previous hard-coded constant — the old form
     * could reach production with no further ceremony; this one cannot reach
     * it at all.
     */
    private const DEFAULT_SCHOOL = 'SCH_EMUFIXTURE1';

    private function school(): string
    {
        $s = trim((string) getenv('ZX_SCHOOL'));
        return $s !== '' ? $s : self::DEFAULT_SCHOOL;
    }

    /**
     * Hard stop unless Firestore is an emulator.
     *
     * Checks the environment variable AND that the host is loopback, so a
     * variable pointing at some other machine cannot turn this into a remote
     * write tool. `Firestore_rest_client` independently validates the same
     * variable's shape; this is the second, coarser gate that decides whether
     * the harness may run at all.
     */
    private function _assertEmulator(): void
    {
        $emu = trim((string) getenv('FIRESTORE_EMULATOR_HOST'));
        $emu = preg_replace('#^https?://#i', '', $emu);
        $host = strtolower((string) strtok((string) $emu, ':'));
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);

        if ($emu === '' || !$loopback) {
            $this->_refuse('FIRESTORE_EMULATOR_HOST = ' . ($emu === '' ? '(unset)' : $emu),
                'Set it to a loopback emulator (e.g. 127.0.0.1:8092) and retry.');
        }

        // ── THE CHECK THAT WAS MISSING ──────────────────────────────────────
        //
        // Everything above proves only that an ENVIRONMENT VARIABLE expresses
        // an intention. On 2026-08-31 that was not enough: the emulator seam in
        // Firestore_rest_client was uncommitted, so a clean checkout had no way
        // to honour the variable and silently used
        // https://firestore.googleapis.com. This guard said "sandboxed" while
        // the harness wrote 85 documents into the live project.
        //
        // So now ASK THE CLIENT where it will actually talk. A variable states
        // an intention; only the client states a destination.
        //
        // Fails CLOSED on every uncertainty — missing class, missing method,
        // unreadable credential, unparseable root — because "I could not prove
        // it is the emulator" and "it is production" must be treated
        // identically before the first write.
        $clientFile = APPPATH . 'libraries/Firestore_rest_client.php';
        if (!is_file($clientFile)) {
            $this->_refuse('Firestore_rest_client.php not found',
                'The Firestore client is missing, so its destination cannot be proven.');
        }
        require_once $clientFile;

        if (!class_exists('FirestoreRestClient')
            || !method_exists('FirestoreRestClient', 'isEmulatorTarget')
            || !method_exists('FirestoreRestClient', 'resolvedApiRoot')) {
            $this->_refuse('FirestoreRestClient cannot report its destination',
                'This build predates the emulator-target check (the exact 2026-08-31 '
                . 'condition: a client with no emulator seam). Refusing rather than '
                . 'assuming the environment variable is honoured.');
        }

        $sa = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
        try {
            $probe = new FirestoreRestClient($sa, 'graderadmin', '(default)');
            $root  = $probe->resolvedApiRoot();
            $ok    = $probe->isEmulatorTarget();
        } catch (\Throwable $e) {
            $this->_refuse('could not construct the Firestore client: ' . $e->getMessage(),
                'Its destination cannot be proven, so no write may proceed.');
            return; // unreachable; keeps static analysers happy
        }

        if (!$ok || stripos($root, 'firestore.googleapis.com') !== false) {
            $this->_refuse('RESOLVED Firestore destination = ' . $root,
                'The client is NOT pointed at a local emulator. This is the exact '
                . 'condition that wrote to production on 2026-08-31. Nothing will run.');
        }

        fwrite(STDERR, "[probe] verified emulator destination: {$root}\n");

        // ── THE SECOND MISSING CHECK — FIREBASE AUTH (2026-09-01) ───────────
        //
        // Everything above proves only where FIRESTORE goes. Auth is a
        // different service on a different host, and it was never covered:
        // FIRESTORE_EMULATOR_HOST does not affect it, and
        // FIREBASE_AUTH_EMULATOR_HOST was set nowhere in this repository. So
        // this guard reported "sandboxed" while `Sis::save_admission` issued
        // REAL production Auth creates on every rehearsal. They failed only
        // because `_generatePassword()` returns fewer than Firebase's six
        // required characters when no date of birth is supplied. With a date of
        // birth — which a real admission always has — a live account would have
        // been created, carrying production `school_id` custom claims, outside
        // every Firestore cleanup rail.
        //
        // Same remedy, same reasoning as above: ask the LIBRARY where it will
        // talk, never an environment variable. Kreait resolves the Auth URL
        // through the identical builder at call time, so this answer cannot
        // drift from real behaviour.
        $authFile = APPPATH . 'libraries/Firebase.php';
        if (!is_file($authFile)) {
            $this->_refuse('Firebase.php not found',
                'The Auth client is missing, so its destination cannot be proven.');
        }
        if (!method_exists('Firebase', 'isAuthEmulatorTarget')
            || !method_exists('Firebase', 'resolvedAuthRoot')) {
            $this->_refuse('Firebase cannot report its AUTH destination',
                'This build predates the Auth emulator-target check. Refusing rather '
                . 'than assuming Auth is sandboxed — it was not, until 2026-09-01.');
        }
        try {
            $authRoot = $this->firebase->resolvedAuthRoot();
            $authOk   = $this->firebase->isAuthEmulatorTarget();
        } catch (\Throwable $e) {
            $this->_refuse('could not resolve the Auth destination: ' . $e->getMessage(),
                'Unprovable destination is treated as production.');
            return; // unreachable
        }
        // Test the HOST, never the whole URL: the emulator's own URL format is
        // http://{emulator}/identitytoolkit.googleapis.com/v1/... — the real
        // service name appears in the PATH, so a substring test rejects the
        // very destination it is supposed to accept. (Caught by running the
        // positive case; a negative-only proof would have shipped this.)
        $authHost = strtolower((string) parse_url($authRoot, PHP_URL_HOST));
        if (!$authOk || substr($authHost, -14) === 'googleapis.com') {
            $this->_refuse('RESOLVED Auth destination = ' . $authRoot,
                'Firebase Auth is NOT pointed at a local emulator. An admission '
                . 'creates a real login account with production claims. Set '
                . 'FIREBASE_AUTH_EMULATOR_HOST to a loopback emulator (e.g. '
                . '127.0.0.1:9199) and retry. Nothing will run.');
        }

        fwrite(STDERR, "[probe] verified emulator AUTH destination: {$authRoot}\n");
    }

    /** One refusal path, so no caller can accidentally continue past a failed check. */
    private function _refuse(string $what, string $why): void
    {
        fwrite(STDERR,
            "REFUSING TO RUN.\n"
            . "This harness executes real lifecycle WRITES through the real controllers.\n"
            . "It runs only against a local Firestore emulator.\n"
            . "  {$what}\n"
            . "  {$why}\n");
        exit(3);
    }

    /**
     * A second lock, independent of the school id. Every write this harness
     * performs must carry this marker in a name or reason field, so an
     * inspection can always separate harness data from tenant data — and so
     * cleanup can refuse to delete anything that is not ours.
     */
    public const MARKER = 'ZXLCPROBE';

    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); exit; }
    }

    /**
     * Seed the session exactly as Admin_login does, then hand control to the
     * real controller. Called by every step.
     */
    private function seed(string $sessionYear): void
    {
        // NOTHING may be loaded through CI's loader before the target
        // controller is constructed. `CI_Controller::__construct()` re-resolves
        // every class already registered with `is_loaded()`, so a library
        // loaded here surfaces inside the target as an unresolvable core class
        // ("Class CI_Firebase not found"). The session is therefore seeded
        // through PHP's own session handling, which CI3's Session library
        // attaches to rather than replaces when it finds one already active.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // The administrator's REAL role, resolved read-only by the driver and
        // passed in. It is never defaulted: an unset value means the driver
        // could not confirm the identity, and inventing a role here would make
        // every authorization result in this harness meaningless.
        $role = trim((string) getenv('ZX_ADMIN_ROLE'));
        if ($role === '') {
            fwrite(STDERR, "FATAL: ZX_ADMIN_ROLE not supplied — refusing to assume a role.\n");
            exit(1);
        }
        $adminId = trim((string) getenv('ZX_ADMIN_ID'));
        if ($adminId === '') {
            fwrite(STDERR, "FATAL: ZX_ADMIN_ID not supplied.\n");
            exit(1);
        }

        // Mirrors Admin_login's session payload exactly.
        $school = $this->school();
        $seed = [
            'admin_id'    => $adminId,
            'school_id'   => $school,
            'school_code' => $school,
            'admin_role'  => $role,
            'admin_name'  => 'Lifecycle Probe (' . $role . ')',
            'session'     => $sessionYear,
            'schoolName'  => $school,
            'school_display_name' => $school,
            'available_sessions'  => [$sessionYear],
            'sub_check_ts'  => time(),
            'push_check_ts' => time(),
        ];

        // Bypass roles are seeded by Admin_login; every other role is hydrated
        // from Firestore by MY_Controller on the first request. Reproduced
        // rather than short-circuited, so a non-bypass role would still have
        // to earn its permissions here.
        require_once APPPATH . 'helpers/rbac_helper.php';
        if (defined('RBAC_BYPASS_ROLES') && in_array($role, RBAC_BYPASS_ROLES, true)) {
            $seed['rbac_permissions'] = defined('RBAC_MODULES') ? RBAC_MODULES : [];
        }

        // CI3's Session library namespaces userdata directly on $_SESSION.
        foreach ($seed as $k => $v) { $_SESSION[$k] = $v; }

        // Controller methods gate on the request method; CLI reports neither.
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /**
     * Dispatch into a real controller method.
     *
     * The target controller is constructed here, which re-binds CI's
     * `get_instance()` singleton to it — deliberate, because every library the
     * endpoint loads must resolve against the endpoint, not against this probe.
     */
    /**
 * Delegate to the SHARED dispatcher.
 *
 * This helper used to live here in full, and a verbatim copy lived in
 * `Production_admission_probe`. That meant production would have been the
 * FIRST runtime execution of the code orchestrating a real admission —
 * every emulator rehearsal proved only the copy. One implementation, in
 * `libraries/Probe_dispatch.php`, makes this rehearsal a real proof of the
 * production path.
 */
    private function dispatch(string $controller, string $method, array $post)
    {
        require_once APPPATH . 'libraries/Probe_dispatch.php';
        return Probe_dispatch::run($controller, $method, $post);
    }

    public function step($name = '', $a1 = '', $a2 = '', $a3 = '', $a4 = '')
    {
        // BEFORE anything else, including the session seed. A harness that
        // wrote one document and then discovered it was pointed at production
        // would already have failed.
        $this->_assertEmulator();

        $sessionYear = getenv('ZX_SESSION') ?: '2026-27';
        $this->seed($sessionYear);

        switch ($name) {
            // ── Read-only sanity: proves the harness can reach a real
            //    endpoint, run real authorization, and get a real answer,
            //    before it is trusted with anything that writes.
            case 'ping':
                $this->dispatch('sis', 'search_student', ['status' => 'Active', 'page' => 1]);
                return;

            // ── FINANCIAL FOUNDATION — the fee head registry ────────────
            // Dispatches the REAL administrator endpoint, so this runs the same
            // RBAC ('Fees'/'manage'), the same tenant-from-session rule and the
            // same canonical write authority a school would use. It is not a
            // shortcut into the service: a fixture that wrote feeHeadRegistry
            // directly would prove the collection accepts documents and nothing
            // about whether a school can actually create one.
            //
            // This step is what a clean tenant needs before ANY fee can be
            // generated under USE_FEE_HEAD_REGISTRY — without it, head
            // resolution fails closed and admission produces zero demands.
            // Registers a fee head through the SCREEN a school actually uses:
            // Fee Management → Fee Heads (fee_management/categories). That
            // endpoint now writes the permanent registry identity first and the
            // session title list second, so this exercises the real operator
            // workflow rather than a service-only side door.
            case 'register_head':
                $this->dispatch('fee_management', 'save_fee_title', [
                    'fee_title'    => $a1,
                    'fee_type'     => $a3 !== '' ? $a3 : 'Monthly',
                    'fee_category' => $a2 !== '' ? $a2 : 'academic',
                ]);
                return;

            // Billing readiness as the operator sees it on that same screen.
            case 'head_readiness':
                $this->dispatch('fee_management', 'fee_head_readiness', []);
                return;

            // Diagnostic: ask the ONE fee-head authority to resolve a head by
            // name, exactly as generation does. Read-only. Exists because a
            // registered head that generation cannot resolve is invisible
            // otherwise — the only symptom is demands=0.
            case 'resolve_head':
                $this->dispatch('entitlements', 'fee_heads', []);
                return;

            // Capture a SERVICE request exactly as the admission form does:
            // views/_entitlements_capture.php posts to entitlements/propose with
            // kind=service + service_type. Used to establish whether that record
            // is consumed by anything downstream.
            // A CONCESSION through the canonical capture path, exactly as
            // views/_entitlements_capture.php posts it. $a2 is the scope —
            // 'all' for a blanket concession, 'category:transport' for a
            // service-scoped one. The difference is invariant I3: a blanket
            // concession must NOT reach a service fee.
            case 'concession':
                $this->dispatch('entitlements', 'propose', [
                    'student_id'      => $a1,
                    'kind'            => 'concession',
                    'concession_type' => 'scholarship',
                    'scope'           => $a2 !== '' ? $a2 : 'all',
                    'value_type'      => 'percent',
                    'value'           => $a3 !== '' ? $a3 : '50',
                    'effective_from'  => (getenv('ZX_CONC_FROM') ?: date('Y-m-d')),
                    'reason'          => self::MARKER . ' fare-basis concession probe',
                ]);
                return;

            case 'propose_service':
                $this->dispatch('entitlements', 'propose', [
                    'student_id'   => $a1,
                    'kind'         => 'service',
                    'service_type' => $a2 !== '' ? $a2 : 'transport',
                    'scope'        => 'all',
                    'value_type'   => 'percent',
                    'value'        => '0',
                    'effective_from' => (getenv('ZX_EFFECTIVE_FROM') ?: '2026-04-01'),
                    'reason'       => 'admission service request probe',
                ]);
                return;

            // ── SERVICE FEE — the Phase D path ───────────────────────────
            // A one-off student charge routes through Student_charge_service ->
            // Fee_lifecycle::createModuleFee(), the SAME canonical entry point
            // Transport, Hostel and ID-card replacement use. So this exercises
            // the real service-pricing branch (_priceServiceFee) rather than a
            // transport-specific side door, and it goes through the admin
            // endpoint so RBAC and the M5 registered-head rule both apply.
            // A TRANSPORT module fee — the canonical service case.
            //
            // Uses Fee_lifecycle::createModuleFee() with moduleType 'Transport'
            // exactly as the Transport and Hostel controllers do (and as
            // Transport_probe_fin1 already does). The module TYPE is what
            // reaches Service_entitlement_pricing as the service category, so a
            // student charge cannot stand in for it: StudentCharge is not one of
            // SERVICE_CATEGORIES ['transport','hostel','meals'], and invariant
            // I3 therefore does not apply to it. Proving I3 needs a real fare.
            case 'module_fee':
                $this->load->library('firebase', null, 'firebase');
                $this->load->library('fee_lifecycle', null, 'feeLifecycle');
                $this->feeLifecycle->init($this->firebase,
                    (string) getenv('ZX_SCHOOL'), (string) getenv('ZX_SESSION'), 'lifecycle_probe');
                $demandId = $this->feeLifecycle->createModuleFee($a1, $a2 !== '' ? $a2 : 'Transport', [
                    'fee_head'  => $a3 !== '' ? $a3 : 'Transport Fee',
                    'amount'    => (float) $a4,
                    'frequency' => 'Monthly',
                ]);
                echo json_encode(['status' => $demandId ? 'success' : 'error',
                    'demand_id' => $demandId, 'module' => $a2, 'amount' => $a4]);
                return;

            case 'student_charge':
                $this->dispatch('fee_management', 'raise_student_charge', [
                    'student_id' => $a1,
                    'fee_head'   => $a2,
                    'amount'     => $a3,
                    'reason'     => 'Phase D service-entitlement proof',
                ]);
                return;

            // ── REFUND / REVERSAL — the recovery path ────────────────────
            // Three real operator endpoints, in the order the state machine
            // requires: create (pending) -> approve -> process. Dispatched
            // rather than calling Fee_refund_service directly, so RBAC, the
            // status guard and the receipt-number normalisation all run.
            //
            // This exists because the reversal was the one link in the money
            // chain that had been TRACED but never EXECUTED — and a rollback
            // procedure nobody has run is not a rollback procedure.
            case 'refund_create':
                $this->dispatch('fee_management', 'create_refund', [
                    'student_id' => $a1, 'student_name' => 'Refund Probe',
                    'class' => '5', 'section' => 'A',
                    'amount' => $a2, 'fee_title' => 'Tuition Fee',
                    'receipt_no' => $a3, 'reason' => 'closure-proof reversal',
                ]);
                return;

            case 'refund_approve':
                $this->dispatch('fee_management', 'approve_refund', [
                    'refund_id' => $a1, 'remarks' => 'closure proof',
                ]);
                return;

            case 'refund_process':
                $this->dispatch('fee_management', 'process_refund', [
                    'refund_id' => $a1, 'refund_mode' => $a2 !== '' ? $a2 : 'cash',
                ]);
                return;

            // ── ADMISSION — the transition that mints identity ───────────
            // Exercises the real Student ID counter, the real SRNO
            // allocation (SRNO-F1 fail-closed contract) and the real
            // Admission Number claim. Everything downstream depends on this
            // producing a coherent identity, so it is the first write step.
            // admit_transport — the SAME save_admission call, plus the transport
            // DECLARATION the direct-admission form now posts. Proves the link
            // without a second code path: identical endpoint, one extra field.
            // The Transport operator side, through the REAL endpoints the
            // Operations → Transport screen calls. No service shortcuts: route
            // and stop are created the way staff create them, and approval runs
            // approveTransportRequest() -> assignStudent() with its atomic
            // capacity reservation and createModuleFee().
            case 'trn_route':
                // monthly_fee is the CANONICAL transport fare. assignStudent()
                // computes the billed amount from the ROUTE — _computeAssignmentFee
                // reads $route['monthly_fee'] — and the monthly_fee carried on an
                // approval payload is NOT read. A route seeded without a fare
                // therefore enrols the student correctly and bills 0, which is
                // what made an earlier run of this probe read as "approval raises
                // no fee" when the product was behaving correctly.
                $this->dispatch('transport', 'save_route', [
                    'name' => $a1 !== '' ? $a1 : 'Route A', 'service_days' => 'Mon,Tue,Wed,Thu,Fri',
                    'monthly_fee' => $a2 !== '' ? $a2 : '1200',
                ]);
                return;

            case 'trn_stop':
                $this->dispatch('transport', 'save_stop', [
                    'route_id' => $a1, 'name' => $a2 !== '' ? $a2 : 'Sector 12 Stop',
                    'address' => 'Market Road', 'order' => '1',
                    'pickup_time' => '07:15', 'drop_time' => '14:30',
                ]);
                return;

            // Standalone request, i.e. the operator/app path rather than the
            // admission path. Used to runtime-prove the two refusals that keep
            // a student from being enrolled or billed twice: already_pending
            // (a duplicate submission) and already_assigned (a student who is
            // already on a route). Source-reading is not proof of either.
            case 'trn_request':
                $this->dispatch('transport', 'request_transport', [
                    'student_id'     => $a1,
                    'requested_area' => $a2 !== '' ? $a2 : 'Sector 7',
                    'type'           => 'both',
                ]);
                return;

            // Suspend / resume / route-change / unassign. These exist because
            // each is a SEPARATE createModuleFee call site with its own
            // proration behaviour, and the differences between them are not
            // visible from the source alone.
            case 'trn_suspend':
                $this->dispatch('transport', 'suspend_assignment', [
                    'student_id' => $a1, 'from' => $a2 !== '' ? $a2 : date('Y-m-d'),
                    'until' => $a3, 'reason' => 'probe suspend',
                ]);
                return;

            case 'trn_resume':
                $this->dispatch('transport', 'resume_assignment', [
                    'student_id' => $a1, 'reason' => 'probe resume',
                ]);
                return;

            case 'trn_change_route':
                $this->dispatch('transport', 'change_route', [
                    // new_route_id / new_stop_id — the endpoint's own names.
                    'student_id' => $a1, 'new_route_id' => $a2, 'new_stop_id' => $a3,
                    'reason' => 'probe route change',
                ]);
                return;

            // ── VERIFIED ADMISSION RELATIONSHIPS ─────────────────────────
            case 'lookup_sibling':
                $this->dispatch('sis', 'lookup_sibling', ['sr_no' => $a1]);
                return;

            case 'lookup_staff':
                $this->dispatch('sis', 'lookup_staff_member', ['staff_id' => $a1]);
                return;

            // Admission carrying relationship declarations. $a4 is the
            // sibling's SR No, ZX_STAFF_ID the employee id, ZX_RTE the
            // RTE/EWS reference — env rather than more positional args.
            case 'admit_related':
                $this->dispatch('sis', 'save_admission', [
                    'name'         => $a1 !== '' ? $a1 : (self::MARKER . ' Student'),
                    'class'        => $a2 !== '' ? $a2 : '5',
                    'section'      => $a3 !== '' ? $a3 : 'A',
                    'phone_number' => '9000000001',
                    'phone'        => '9000000001',
                    'email'        => '',
                    'decl_sibling'       => $a4 !== '' ? '1' : '',
                    'decl_sibling_srno'  => $a4,
                    'decl_staff_ward'    => getenv('ZX_STAFF_ID') ? '1' : '',
                    'decl_staff_ward_id' => (string) getenv('ZX_STAFF_ID'),
                    'decl_rte'           => getenv('ZX_RTE') ? '1' : '',
                    'decl_rte_ref'       => (string) getenv('ZX_RTE'),
                ]);
                return;

            // Direct service call, for the adversarial cases the admission form
            // cannot reach: re-linking the same pair, and self-linking.
            case 'rel_link':
                $this->dispatch('sis', 'link_sibling', [
                    'student_id' => $a1, 'sr_no' => $a2,
                ]);
                return;

            case 'rel_list':
                $this->dispatch('sis', 'relationships_for_student', ['student_id' => $a1]);
                return;

            case 'trn_unassign':
                $this->dispatch('transport', 'delete_assignment', ['student_id' => $a1, 'reason' => 'probe removal']);
                return;

            // ── BENEFIT POLICY (Phase 2/3) ───────────────────────────────
            // Driven through the REAL controller so RBAC, validation and the
            // canonical store are all exercised — not by writing feeSettings
            // directly, which would prove only that Firestore accepts a map.
            case 'policy_get':
                $this->dispatch('financial_policies', 'get_policies', []);
                return;

            case 'policy_set':
                $this->dispatch('financial_policies', 'save_policy', [
                    'benefit'        => $a1,
                    'mode'           => $a2,
                    'value_type'     => getenv('ZX_VT') ?: 'percent',
                    'value'          => $a3 !== '' ? $a3 : '25',
                    'scope'          => getenv('ZX_SCOPE') ?: 'all',
                    'effective_from' => (string) getenv('ZX_POL_FROM'),
                    'effective_to'   => (string) getenv('ZX_POL_TO'),
                    'max_benefit'    => (string) (getenv('ZX_MAXB') ?: '0'),
                    'require_active' => getenv('ZX_REQ_ACTIVE') !== '0' ? '1' : '0',
                    'max_children'   => (string) (getenv('ZX_MAXKIDS') ?: '0'),
                ]);
                return;

            case 'benefit_eval':
                $this->dispatch('financial_policies', 'evaluate_student', [
                    'student_id' => $a1, 'benefit' => $a2,
                ]);
                return;

            // The LEGACY concession write, through its real endpoint — so the
            // authority-demotion claim is tested against the path an operator
            // actually clicks, not against a hand-seeded document.
            case 'legacy_concession':
                $this->dispatch('fee_concessions', 'create_concession', [
                    'student_id'     => $a1,
                    'type'           => 'percent',
                    'value'          => $a2 !== '' ? $a2 : '50',
                    'scope'          => $a3 !== '' ? $a3 : 'all',
                    'target'         => (string) getenv('ZX_TARGET'),
                    'reason'         => self::MARKER . ' legacy authority probe',
                    'approved_by'    => 'SSA9001',
                    'effective_from' => '2026-04-01',
                    'effective_to'   => '',
                    'session'        => getenv('ZX_SESSION') ?: '2026-27',
                ]);
                return;

            // The canonical RE-PRICING path. Concessions and entitlements apply
            // to demands generated after they exist; an existing demand is only
            // re-priced here. This is what makes the authority question
            // testable at all.
            // The COLLECTION-layer discount. Writes studentDiscounts, which
            // fetch_months turns into netDue — a third financial authority,
            // separate from demand pricing.
            case 'set_discount':
                $this->dispatch('fees', 'submit_discount', [
                    'userId' => $a1, 'discount' => $a2,
                    // ZX_NO_REASON exercises the mandatory-reason invariant.
                    'reason' => getenv('ZX_NO_REASON') ? '' : (self::MARKER . ' authority probe'),
                    'class' => $a3 !== '' ? $a3 : 'Class 5th', 'section' => 'Section A',
                ]);
                return;

            // The fee counter's student lookup. Accepts an SR Number as well as
            // a student id — SR No is what a parent can actually recite.
            // WEAK-ELIG-1: who does the bulk-discount screen consider eligible?
            // ADJ-GOV-2 — the BULK door. This is the writer that had no
            // outstanding-balance ceiling and no reason, applied to a whole
            // selection of students in one click.
            case 'apply_discount':
                $this->dispatch('fee_management', 'apply_discount', [
                    'discount_id'  => $a1,
                    'student_ids'  => [json_encode([
                        'user_id' => $a2,
                        'class'   => $a3 !== '' ? $a3 : 'Class 10th',
                        'section' => 'Section A',
                    ])],
                    // ADJ-GOV-4 — an EXPIRY the mirror used to erase, turning an
                    // expiring bulk discount into a permanent one.
                    'valid_until'  => (string) getenv('ZX_VALID_UNTIL'),
                ]);
                return;

            // THE COLLECTION PATH. `$a2` is the amount tendered, `$a3` the
            // discount claimed on this receipt — the figure FeeCollectionService
            // caps against studentDiscounts.onDemandDiscount. Used to prove
            // whether an authorised waiver is CONSUMED by a receipt or can be
            // spent again on the next one.
            case 'collect':
                $this->dispatch('fees', 'submit_fees', [
                    'userId'         => $a1,
                    'submitAmount'   => $a2,
                    'discountAmount' => $a3 !== '' ? $a3 : '0',
                    'schoolFees'     => (getenv('ZX_SCHOOL_FEES') !== false ? (string) getenv('ZX_SCHOOL_FEES') : '900'),
                    'fineAmount'     => '0',
                    'paymentMode'    => 'Cash',
                    'selectedMonths' => (string) (getenv('ZX_MONTHS') ?: 'April'),
                    'monthTotals'    => (string) (getenv('ZX_MONTH_TOTALS') ?: '{"April":900}'),
                    'receiptNo'      => '',
                    'reference'      => self::MARKER . ' collection probe',
                ]);
                return;

            // ACCOUNTING — the CANONICAL chart-of-accounts seed a real school
            // uses (Accounting → Seed Chart). The refund journal needs an
            // active fee-income account plus a cash/bank account; seeding
            // through the product's own endpoint is what makes the journal leg
            // a REAL proof rather than fabricated accounting data.
            // ── DAY-ONE SETUP · the screens a brand-new school fills in ─────
            case 'add_section':
                $this->dispatch('classes', 'add_section', [
                    'class_name'   => $a1,
                    'section_name' => $a2 !== '' ? $a2 : 'A',
                    'max_strength' => $a3 !== '' ? $a3 : '40',
                ]);
                return;

            // The fee chart. `fees` is the JSON the Fee Chart screen posts:
            // { "<Month>": { "<Head>": amount, ... }, ... }
            case 'fee_chart':
                // save_updated_fees accepts AJAX POST only. The browser sends
                // X-Requested-With; the harness must send it too. Faking the
                // header here keeps the CONTROLLER's guard fully intact — the
                // alternative (relaxing the guard for testing) would weaken a
                // real defence to make a test pass.
                $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
                $this->dispatch('fees', 'save_updated_fees', [
                    'class'   => $a1,
                    'section' => $a2 !== '' ? $a2 : 'Section A',
                    'fees'    => (string) getenv('ZX_FEES_JSON'),
                ]);
                return;

            case 'seed_coa':
                $this->dispatch('accounting', 'seed_default_chart', []);
                return;

            case 'retry_journal':
                $this->dispatch('fee_management', 'retry_refund_journal', ['refund_id' => $a1]);
                return;

            case 'disc_policy':
                $this->dispatch('fee_management', 'save_discount', [
                    'name' => 'WEAKELIG ' . $a1, 'policy_name' => 'WEAKELIG ' . $a1,
                    'type' => 'percentage', 'discount_type' => 'percentage',
                    'value' => '10', 'criteria' => $a1, 'active' => '1',
                ]);
                return;

            case 'eligible':
                $this->dispatch('fee_management', 'fetch_eligible_students', ['discount_id' => $a1]);
                return;

            case 'fee_lookup':
                $this->dispatch('fees', 'lookup_student', ['user_id' => $a1]);
                return;

            case 'fee_months':
                $this->dispatch('fees', 'fetch_months', ['user_id' => $a1]);
                return;

            // The SECOND writer of studentDiscounts.onDemandDiscount.
            case 'set_discount2':
                $this->dispatch('fees', 'set_student_discount', [
                    'user_id' => $a1, 'amount' => $a2,
                    'reason'  => getenv('ZX_NO_REASON') ? '' : (self::MARKER . ' door-2 probe'),
                ]);
                return;

            case 'recalc':
                $this->dispatch('fees', 'recalculate_demands', ['student_id' => $a1]);
                return;

            // ── GENERATION · the two branches DEC-013b / P5-D separate ──────
            //
            // With no ZX_MONTHS the endpoint sees the WHOLE session and routes
            // through the canonical pipeline (entitlement-priced). With
            // ZX_MONTHS set it takes the LEGACY month-subset branch, which is
            // entitlement-blind and must refuse an entitled student.
            case 'generate':
                $post = ['student_id' => $a1];
                $m = trim((string) getenv('ZX_MONTHS'));
                if ($m !== '') $post['months'] = $m;
                $this->dispatch('fees', 'generate_demands_for_student', $post);
                return;

            case 'trn_requests':
                $this->dispatch('transport', 'get_transport_requests', ['status' => 'pending']);
                return;

            case 'trn_approve':
                $this->dispatch('transport', 'approve_transport_request', [
                    'request_id' => $a1, 'route_id' => $a2, 'stop_id' => $a3,
                    'monthly_fee' => $a4 !== '' ? $a4 : '1000',
                    'type' => 'both', 'effective_from' => (getenv('ZX_EFFECTIVE_FROM') ?: '2026-04-01'),
                ]);
                return;

            case 'trn_reject':
                $this->dispatch('transport', 'reject_transport_request', [
                    'request_id' => $a1, 'reason' => $a2 !== '' ? $a2 : 'no route serves this area',
                ]);
                return;

            case 'admit_transport':
                $this->dispatch('sis', 'save_admission', [
                    'name'         => $a1 !== '' ? $a1 : (self::MARKER . ' Student'),
                    'class'        => $a2 !== '' ? $a2 : '5',
                    'section'      => $a3 !== '' ? $a3 : 'A',
                    'phone_number' => '9000000001',
                    'phone'        => '9000000001',
                    'email'        => '',
                    'decl_transport'      => '1',
                    'decl_transport_area' => $a4 !== '' ? $a4 : 'Sector 12 / Market Road',
                ]);
                return;

            case 'admit':
                $this->dispatch('sis', 'save_admission', [
                    'name'         => $a1 !== '' ? $a1 : (self::MARKER . ' Student'),
                    'class'        => $a2 !== '' ? $a2 : '5',
                    'section'      => $a3 !== '' ? $a3 : 'A',
                    'roll_no'      => $a4,
                    'phone_number' => '9000000001',
                    'phone'        => '9000000001',
                    'email'        => '',
                    // Admission-fee collection inputs. The real screen posts these
                    // whenever schools.admissionFee is enabled; without them
                    // _admission_fee_precheck() refuses, so a tenant WITH a
                    // configured admission fee could not be rehearsed at all.
                    // Empty by default, so every existing driver is unchanged.
                    // A date of birth is what makes _generatePassword() return a
                    // password of PRODUCTION length (3 letters + 4 DOB digits + @).
                    // Without one it returns 4 characters and Firebase rejects the
                    // Auth create — which is exactly why the missing Auth sandbox
                    // went unnoticed for so long. Rehearsals must reproduce the
                    // real password length, not the accidental short one.
                    'dob'          => (string) getenv('ZX_DOB'),
                    'admission_payment_mode'      => (string) getenv('ZX_ADM_FEE_MODE'),
                    'admission_bank_ledger_code'  => (string) getenv('ZX_ADM_BANK_CODE'),
                    'admission_bank_reference'    => (string) getenv('ZX_ADM_BANK_REF'),
                ]);
                return;

            case 'change_status':
                $this->dispatch('sis', 'change_status', ['user_id' => $a1, 'status' => $a2]);
                return;

            case 'issue_tc':
                // `force_override` is the operator's own escape hatch: the
                // endpoint refuses a TC while dues remain and returns
                // `can_override: true`, so the UI can offer it.
                //
                // This is precisely the LC-6 scenario and the ONLY one in
                // which that fix matters. Without the override there are no
                // collectable demands left at TC time, so freezing would be a
                // no-op; WITH it, a departing student keeps a live fee book
                // unless issue_tc freezes it.
                $this->dispatch('sis', 'issue_tc', [
                    'user_id'        => $a1,
                    'reason'         => self::MARKER . ' controlled lifecycle verification',
                    'destination'    => self::MARKER . ' test destination',
                    'force_override' => ($a2 === 'override' ? 'true' : ''),
                ]);
                return;

            case 'cancel_tc':
                $this->dispatch('sis', 'cancel_tc', ['user_id' => $a1, 'tc_key' => $a2]);
                return;

            case 'withdraw':
                $this->dispatch('sis', 'withdraw_student', [
                    'user_id' => $a1,
                    'reason'  => self::MARKER . ' controlled lifecycle verification',
                ]);
                return;

            // ── ROLLOVER — promotion AND terminal-class completion ───────
            // The only path that graduates anyone. `$a1` is the source
            // session, `$a2` the destination.
            case 'rollover':
                $this->dispatch('school_config', 'rollover_session', [
                    'from_session'     => $a1,
                    'to_session'       => $a2,
                    'copy_sections'    => 0,   // the fixture already has both sessions
                    'promote_students' => 1,
                    'set_active'       => 0,
                ]);
                return;

            case 'rollover_preview':
                $this->dispatch('school_config', 'rollover_preview', [
                    'from_session' => $a1,
                    'to_session'   => $a2,
                ]);
                return;

            case 'transfer':
                // student_ids is an ARRAY on the real endpoint (the UI posts
                // student_ids[]). Passing a scalar was a harness bug that
                // surfaced as the endpoint's own "Invalid data" — a good
                // reminder that a harness failure and a product failure look
                // identical from the outside.
                $this->dispatch('classes', 'transfer_students', [
                    'student_ids'  => [$a1],
                    'from_class'   => getenv('ZX_FROM_CLASS') ?: '',
                    'from_section' => getenv('ZX_FROM_SECTION') ?: '',
                    'to_class'     => $a2,
                    'to_section'   => $a3,
                ]);
                return;

            default:
                fwrite(STDERR, "unknown step '{$name}'\n");
                exit(2);
        }
    }
}
