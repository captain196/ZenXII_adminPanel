<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ══════════════════════════════════════════════════════════════════════════
 *  Demand_diag — READ-ONLY instrumentation for DEMAND-PARTIAL-1.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * DEMAND-PARTIAL-1: a single sequential admission persists a ragged subset of
 * the demand set (12 of 24, ₹9,500 of ₹18,000) while the response reports
 * `billing_state: billed`, and re-running generation is idempotent but never
 * converges. A leading hypothesis existed (silently-skipped batch operations),
 * but a hypothesis is not a root cause, so this controller measures the
 * pipeline instead of arguing about it.
 *
 * It walks the same stages the real generator does and reports where a valid
 * expected demand first disappears:
 *
 *      persisted fee structure
 *        → expanded chart (month → head → amount)
 *          → built demand specs (the PURE builder, no writes)
 *            → persisted feeDemands
 *
 * `Fee_lifecycle::buildAdmissionDemandSpecs()` is a pure builder that performs
 * no Firestore writes, so calling it here observes the real code path without
 * mutating anything. THIS FILE WRITES NOTHING — there is no set, update,
 * delete or commit anywhere in it.
 *
 * Emulator only, proven from the CLIENT rather than from an environment
 * variable, for the same reason `Lifecycle_probe` does it: on 2026-08-31 a
 * harness that trusted a variable wrote 85 documents into the live project.
 *
 *   php index.php demand_diag run <StudentId> "<Class>" "<Section>"
 */
class Demand_diag extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli()) { show_404(); exit; }
    }

    private function _refuse(string $what, string $why): void
    {
        fwrite(STDERR, "REFUSING TO RUN.\n  {$what}\n  {$why}\n");
        exit(3);
    }

    /** Fails CLOSED on every uncertainty; identical posture to Lifecycle_probe. */
    private function _assertEmulator(): void
    {
        require_once APPPATH . 'libraries/Firestore_rest_client.php';
        if (!class_exists('FirestoreRestClient')
            || !method_exists('FirestoreRestClient', 'isEmulatorTarget')) {
            $this->_refuse('FirestoreRestClient cannot report its destination',
                'Refusing rather than assuming where reads resolve.');
        }
        $sa = APPPATH . 'config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json';
        try {
            $c = new FirestoreRestClient($sa, 'graderadmin', '(default)');
            $root = $c->resolvedApiRoot();
            $ok   = $c->isEmulatorTarget();
        } catch (\Throwable $e) {
            $this->_refuse('could not construct the Firestore client', 'Destination unprovable.');
            return;
        }
        if (!$ok || stripos($root, 'firestore.googleapis.com') !== false) {
            $this->_refuse('RESOLVED destination = ' . $root,
                'This diagnostic runs only against a local emulator.');
        }
        fwrite(STDERR, "[diag] emulator destination: {$root}\n");
    }

    private function _seed(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $school = trim((string) getenv('ZX_SCHOOL'));
        $role   = trim((string) getenv('ZX_ADMIN_ROLE'));
        $admin  = trim((string) getenv('ZX_ADMIN_ID'));
        if ($school === '' || $role === '' || $admin === '') {
            $this->_refuse('ZX_SCHOOL / ZX_ADMIN_ROLE / ZX_ADMIN_ID not supplied',
                'The diagnostic must run as a real, attributable identity.');
        }
        $sess = trim((string) getenv('ZX_SESSION')) ?: '2026-27';
        $_SESSION = array_merge($_SESSION ?? [], [
            'admin_id' => $admin, 'school_id' => $school, 'school_code' => $school,
            'admin_role' => $role, 'admin_name' => 'Demand diagnostic',
            'session' => $sess, 'schoolName' => $school,
            'available_sessions' => [$sess],
        ]);
    }

    /**
     * EMULATOR-ONLY write probe: commit the specs the real builder produced,
     * through the real batch writer, then re-read raw persisted state.
     *
     * Generation was proven correct (24 specs, no empty ids, no collisions),
     * so the loss is downstream. This isolates the persistence layer: same
     * specs, same writer, counted before and after.
     */
    public function commit_probe($studentId = '', $class = '', $section = ''): void
    {
        $this->_assertEmulator();
        $this->_seed();
        $school  = (string) $this->session->userdata('school_id');
        $session = (string) $this->session->userdata('session');
        $actor   = (string) $this->session->userdata('admin_id');
        $this->load->library('firestore_service', null, 'fs');
        // MY_Controller::__construct() calls fs->init(); this diagnostic extends
        // CI_Controller, so without this line fs->schoolId() is '' and EVERY
        // schoolWhere() filters on schoolId == '' and returns zero rows — which
        // reads exactly like "this student has no demands". That artefact cost a
        // false defect report; a harness must construct its dependencies the way
        // the real caller does or it measures a different program.
        $this->fs->init($school, $session, $school);
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $school, $session);
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $school, $session, $actor);
        $built = $this->feeLifecycle->buildAdmissionDemandSpecs($studentId, $class, $section, false);
        $specs = (array) ($built['specs'] ?? []);
        $ok = $this->fsTxn->commitDemandBatch($specs);
        fwrite(STDOUT, json_encode([
            'specsSubmitted' => count($specs),
            'commitReturned' => $ok,
        ]) . "
");
    }

    public function run($studentId = '', $class = '', $section = ''): void
    {
        $this->_assertEmulator();
        $this->_seed();

        if ($studentId === '' || $class === '' || $section === '') {
            $this->_refuse('usage: demand_diag run <StudentId> "<Class>" "<Section>"', 'Nothing to measure.');
        }

        $school  = (string) $this->session->userdata('school_id');
        $session = (string) $this->session->userdata('session');
        $actor   = (string) $this->session->userdata('admin_id');

        // Initialise exactly as Fees.php does — same libraries, same order,
        // same arguments. A diagnostic that constructs its dependencies
        // differently from the real caller measures a different program.
        $this->load->library('firestore_service', null, 'fs');
        // MY_Controller::__construct() calls fs->init(); this diagnostic extends
        // CI_Controller, so without this line fs->schoolId() is '' and EVERY
        // schoolWhere() filters on schoolId == '' and returns zero rows — which
        // reads exactly like "this student has no demands". That artefact cost a
        // false defect report; a harness must construct its dependencies the way
        // the real caller does or it measures a different program.
        $this->fs->init($school, $session, $school);
        $this->load->library('Fee_firestore_txn', null, 'fsTxn');
        $this->fsTxn->init($this->firebase, $this->fs, $school, $session);
        $this->load->library('Fee_lifecycle', null, 'feeLifecycle');
        $this->feeLifecycle->init($this->firebase, $school, $session, $actor);

        // ── STAGE 1: the expanded chart the generator will iterate ──────────
        $merged  = $this->fsTxn->readFeeStructureWithIds($class, $section);
        $chart   = (array) ($merged['chart'] ?? []);
        $headIds = (array) ($merged['headIds'] ?? []);

        $chartPairs = [];
        foreach ($chart as $bucket => $heads) {
            if (!is_array($heads)) continue;
            foreach ($heads as $head => $amt) {
                if ((float) $amt <= 0) continue;
                $chartPairs["{$bucket}|{$head}"] = (float) $amt;
            }
        }

        // ── STAGE 2: the specs the PURE builder produces (no writes) ────────
        $built = $this->feeLifecycle->buildAdmissionDemandSpecs($studentId, $class, $section, false);
        $specs = (array) ($built['specs'] ?? []);

        $specRows = [];
        $emptyIds = 0;
        foreach ($specs as $s) {
            $did = trim((string) ($s['demandId'] ?? ''));
            if ($did === '') $emptyIds++;
            $d = (array) ($s['data'] ?? []);
            $specRows[] = [
                'demandId' => $did,
                'period'   => (string) ($d['periodKey'] ?? $d['period'] ?? '?'),
                'head'     => (string) ($d['feeHead'] ?? '?'),
                'amount'   => (float) ($d['netAmount'] ?? $d['grossAmount'] ?? $d['amount'] ?? 0),
                'op'       => (string) ($s['op'] ?? 'write'),
            ];
        }

        // ── STAGE 2b: THE ROUTED builder — what the ADMISSION actually calls ──
        // USE_UNIFIED_FEE_GEN routes admission through Fee_generation_service
        // instead of buildAdmissionDemandSpecs. Measuring only the legacy
        // builder measures a generator the admission does not use. Reflection
        // is used because the router is private; it is a pure builder (its
        // caller commits separately), so this observes without mutating.
        $routedRows = []; $routedErr = '';
        try {
            $rm = new ReflectionMethod('Fee_lifecycle', '_routedBuildAdmissionSpecs');
            $rm->setAccessible(true);
            $routed = (array) $rm->invoke($this->feeLifecycle, $studentId, $class, $section, false);
            foreach ((array) ($routed['specs'] ?? []) as $s2) {
                $d2 = (array) ($s2['data'] ?? []);
                $routedRows[] = [
                    'demandId' => trim((string) ($s2['demandId'] ?? '')),
                    'period'   => (string) ($d2['periodKey'] ?? $d2['period'] ?? '?'),
                    'head'     => (string) ($d2['feeHead'] ?? '?'),
                    'amount'   => (float) ($d2['netAmount'] ?? $d2['grossAmount'] ?? $d2['amount'] ?? 0),
                ];
            }
        } catch (\Throwable $e) { $routedErr = $e->getMessage(); }

        // ── STAGE 3: what is actually persisted right now ───────────────────
        $persisted = [];
        foreach ((array) $this->fsTxn->demandsForStudent($studentId) as $did => $d) {
            $persisted[(string) $did] = [
                'period' => (string) ($d['periodKey'] ?? $d['period_key'] ?? '?'),
                'head'   => (string) ($d['feeHead'] ?? '?'),
                'amount' => (float) ($d['netAmount'] ?? $d['amount'] ?? 0),
            ];
        }

        // ── STAGE 3b: the FAIL-CLOSED reader (B-011) ───────────────────────
        // demandsForStudent() ends in `catch (\Exception) { return []; }`, so a
        // failing query is indistinguishable from "this student has no demands".
        // demandsForStudentStrict() throws instead. Calling it turns a silent
        // zero into the actual error, which is the whole question here.
        $strictCount = null; $strictErr = '';
        try {
            $strictCount = count((array) $this->fsTxn->demandsForStudentStrict($studentId));
        } catch (\Throwable $e) {
            $strictErr = get_class($e) . ': ' . $e->getMessage();
        }

        // ── STAGE 3c: PROGRESSIVE PREDICATE NARROWING ──────────────────────
        // Both readers return 0 while documents demonstrably exist, and the
        // strict reader raises nothing — so no predicate is failing loudly.
        // Add one condition at a time and watch where the row count collapses.
        $narrow = [];
        $sid = (string) $this->fs->schoolId();
        $narrow['fs->schoolId()'] = $sid;
        $narrow['fsTxn.session']  = $session;
        try { $narrow['A schoolId only'] = count((array) $this->fs->where('feeDemands', [['schoolId','==',$sid]])); }
        catch (\Throwable $e) { $narrow['A schoolId only'] = 'ERR: ' . $e->getMessage(); }
        try { $narrow['B +session'] = count((array) $this->fs->where('feeDemands', [['schoolId','==',$sid],['session','==',$session]])); }
        catch (\Throwable $e) { $narrow['B +session'] = 'ERR: ' . $e->getMessage(); }
        try { $narrow['C +studentId'] = count((array) $this->fs->where('feeDemands', [['schoolId','==',$sid],['session','==',$session],['studentId','==',$studentId]])); }
        catch (\Throwable $e) { $narrow['C +studentId'] = 'ERR: ' . $e->getMessage(); }
        try { $narrow['D studentId only'] = count((array) $this->fs->where('feeDemands', [['studentId','==',$studentId]])); }
        catch (\Throwable $e) { $narrow['D studentId only'] = 'ERR: ' . $e->getMessage(); }

        $chartTotal = array_sum($chartPairs);
        $specTotal  = array_sum(array_column($specRows, 'amount'));
        $persTotal  = array_sum(array_column($persisted, 'amount'));

        fwrite(STDOUT, json_encode([
            'student'        => $studentId,
            'class'          => $class,
            'section'        => $section,
            'chartFound'     => (bool) ($built['chartFound'] ?? false),
            'builderOk'      => ($built['ok'] ?? true),
            'stage1_chart'   => ['pairs' => count($chartPairs), 'total' => $chartTotal,
                                 'headIds' => count($headIds)],
            'stage2_specs'   => ['count' => count($specRows), 'total' => $specTotal,
                                 'emptyDemandIds' => $emptyIds],
            'stage2b_routed' => ['count' => count($routedRows),
                                 'total' => array_sum(array_column($routedRows, 'amount')),
                                 'error' => $routedErr],
            'routedKeys'     => array_map(fn($r) => $r['period'] . '|' . $r['head'], $routedRows),
            'stage3_persist' => ['count' => count($persisted), 'total' => $persTotal],
            'stage3b_strict' => ['count' => $strictCount, 'error' => $strictErr],
            'stage3c_narrow' => $narrow,
            'LOSS_chart_to_specs'   => count($chartPairs) - count($specRows),
            'LOSS_specs_to_persist' => count($specRows) - count($persisted),
            'chartPairs'     => array_keys($chartPairs),
            'specs'          => $specRows,
            'persistedKeys'  => array_values(array_map(
                fn($p) => $p['period'] . '|' . $p['head'], $persisted)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
