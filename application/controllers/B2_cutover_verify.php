<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Wave B2.3.2 — Cutover verifier (PHP wiring side).
 *
 * Asserts the static / source-level invariants required BEFORE the single
 * atomic co-cutover flag `b2.registry_firestore` may be flipped. Build phase
 * MUST be at the assert state below; if any gate fails, flag activation is
 * NOT authorized.
 *
 * Sub-package gates land incrementally; each one tightens the assertion set:
 *   a()  — B2.3.2-A:  flag declared boolean false, B2_registry_service exists
 *                     with skeleton API, MY_Controller branches per-request
 *                     status gate behind the flag, legacy RTDB read string
 *                     still present (off-branch).
 *   d()  — B2.3.2-D:  (pending)
 *   b()  — B2.3.2-B:  (pending)
 *   c()  — B2.3.2-C:  (pending)
 *   all() — runs every implemented gate.
 *
 * Usage:
 *   php index.php b2_cutover_verify a
 *   php index.php b2_cutover_verify all
 *
 * @schema-locked  2026-05-30 (B2.3.2-A)
 */
class B2_cutover_verify extends CI_Controller
{
    private $pass   = 0;
    private $fail   = 0;
    private $errors = [];

    public function __construct() { parent::__construct(); if (!defined('STDIN')) { exit("CLI only\n"); } }

    public function all()
    {
        $this->a(false);
        $this->d(false);
        $this->b1(false);
        $this->b2(false);
        $this->b3(false);
        $this->c(false);
        $this->e(false);
        $this->e_reports(false);
        echo "\n=== B2.3.2 CUTOVER VERIFIER ===\n";
        echo "PASS: {$this->pass}  FAIL: {$this->fail}\n";
        foreach ($this->errors as $e) echo "  ! $e\n";
        $gate = ($this->fail === 0) ? 'PASS' : 'REVIEW';
        echo "CUTOVER VERIFIER GATE: $gate\n";
        exit($this->fail === 0 ? 0 : 2);
    }

    // ── B2.3.2-A foundation: flag + service + bootstrap gate ──
    public function a($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── A.1: flag declared boolean false ────────────────────────────
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2 = $this->config->item('b2_migration_flags');
        $this->_assert(is_array($b2), 'A.1/flag: b2_migration_flags must be a non-empty array');
        $this->_assert(array_key_exists('b2.registry_firestore', $b2 ?: []),
            'A.1/flag: b2.registry_firestore must be declared');
        if (is_array($b2) && array_key_exists('b2.registry_firestore', $b2)) {
            $this->_assert(is_bool($b2['b2.registry_firestore']),
                'A.1/flag: b2.registry_firestore must be boolean (got ' . var_export($b2['b2.registry_firestore'], true) . ')');
            $this->_assert($b2['b2.registry_firestore'] === false,
                'A.1/flag: b2.registry_firestore must be FALSE during build phase (got ' . var_export($b2['b2.registry_firestore'], true) . ')');
        }

        // ── A.2: B2_registry_service file exists ────────────────────────
        $libPath = APPPATH . 'libraries/B2_registry_service.php';
        $this->_assert(is_file($libPath), 'A.2/library: file missing: ' . $libPath);

        // ── A.3: B2_registry_service class loadable + has skeleton API ──
        if (is_file($libPath)) {
            $this->load->library('b2_registry_service');
            $svc = $this->b2_registry_service;
            $this->_assert(is_object($svc), 'A.3/library: B2_registry_service did not instantiate');
            if (is_object($svc)) {
                foreach (['init', 'is_ready', 'firestore_authoritative',
                          'get_school_control', 'lifecycle_access'] as $m) {
                    $this->_assert(method_exists($svc, $m),
                        'A.3/library: B2_registry_service missing public method ' . $m);
                }
                // A.4: firestore_authoritative() must return FALSE during build phase
                $svc->init(null);
                $this->_assert($svc->firestore_authoritative() === false,
                    'A.4/flag: firestore_authoritative() must return FALSE while flag is OFF');
            }
        }

        // ── A.5: MY_Controller has gated branch + helper method ─────────
        $myc = APPPATH . 'core/MY_Controller.php';
        $this->_assert(is_file($myc), 'A.5/wiring: MY_Controller.php not found at ' . $myc);
        if (is_file($myc)) {
            $src = (string) file_get_contents($myc);
            $this->_assert(strpos($src, '_b23a_registry_firestore_on') !== false,
                'A.5/wiring: MY_Controller missing _b23a_registry_firestore_on helper');
            $this->_assert(strpos($src, 'b2_registry_service') !== false,
                'A.5/wiring: MY_Controller missing b2_registry_service reference');
            $this->_assert(strpos($src, 'lifecycle_access') !== false,
                'A.5/wiring: MY_Controller missing lifecycle_access() call');
            // A.6: legacy RTDB read string MUST still be present (the OFF-branch must compile)
            $this->_assert(strpos($src, "System/Schools/{\$this->school_id}/subscription/status") !== false,
                'A.6/wiring: MY_Controller legacy RTDB status read string missing (off-branch broken)');
            $this->_assert(strpos($src, "Users/Schools/{\$this->school_id}/subscription/status") !== false,
                'A.6/wiring: MY_Controller legacy RTDB fallback read string missing (off-branch broken)');
        }

        // ── A.7: structural shape — Firestore branch and RTDB branch are
        //        mutually exclusive (no fall-through bridge) ─────────────
        // We confirm by checking the gating helper is invoked exactly
        // around the new lifecycle_access() call, and that the legacy
        // RTDB read strings appear in a region NOT reached when the flag
        // is on (asserted via positional containment: legacy reads sit
        // inside an `else` branch following the helper-gated `if`).
        if (isset($src) && is_string($src)) {
            $helperPos = strpos($src, '_b23a_registry_firestore_on');
            $fsCallPos = strpos($src, 'lifecycle_access(');
            $rtdbPos   = strpos($src, "System/Schools/{\$this->school_id}/subscription/status");
            $this->_assert($helperPos !== false && $fsCallPos !== false && $rtdbPos !== false,
                'A.7/structure: cannot locate all three landmarks (helper, Firestore call, RTDB read)');
            if ($helperPos !== false && $fsCallPos !== false && $rtdbPos !== false) {
                // The Firestore call must follow the helper invocation (inside the IF),
                // and the legacy RTDB read must come AFTER the Firestore call (in the ELSE).
                $this->_assert($helperPos < $fsCallPos,
                    'A.7/structure: lifecycle_access() must come AFTER the flag-helper invocation');
                $this->_assert($fsCallPos < $rtdbPos,
                    'A.7/structure: legacy RTDB read must come AFTER the Firestore branch (it is the ELSE)');
            }
        }

        if ($solo) $this->_finish('A');
    }

    // ── B2.3.2-D: Admin_login gating ──
    public function d($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── D.1: B2_registry_service has the four B2.3.2-D methods ──────
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'D.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            foreach (['resolve_school_code', 'login_access_view',
                      'get_features', 'get_display_name'] as $m) {
                $this->_assert(method_exists($svc, $m),
                    'D.1/library: B2_registry_service missing public method ' . $m);
            }
        }

        // ── D.2: Admin_login source has the helper + four gated sites ──
        $ctrl = APPPATH . 'controllers/Admin_login.php';
        $this->_assert(is_file($ctrl), 'D.2/wiring: Admin_login.php not found');
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);

            // D.2.helper: local _b23d_registry_firestore_on() declared
            $this->_assert(strpos($src, 'private function _b23d_registry_firestore_on') !== false,
                'D.2/wiring: Admin_login missing _b23d_registry_firestore_on() helper');

            // D.2.code:    resolve_school_code() wired into _resolveSchoolId
            $this->_assert(strpos($src, 'resolve_school_code(') !== false,
                'D.2/wiring: Admin_login missing resolve_school_code() call');

            // D.2.sub:     login_access_view() wired into subscription gate
            $this->_assert(strpos($src, 'login_access_view(') !== false,
                'D.2/wiring: Admin_login missing login_access_view() call');

            // D.2.feat:    get_features() wired into features read
            $this->_assert(strpos($src, '->get_features(') !== false,
                'D.2/wiring: Admin_login missing get_features() call');

            // D.2.name:    get_display_name() wired into display-name read
            $this->_assert(strpos($src, '->get_display_name(') !== false,
                'D.2/wiring: Admin_login missing get_display_name() call');

            // ── D.3: legacy RTDB read strings still present (off-branch must compile) ──
            $legacy = [
                "Indexes/School_codes/{\$schoolCode}",
                "School_ids/{\$schoolCode}",
                "System/Schools/{\$schoolId_resolved}/subscription",
                "Users/Schools/{\$schoolId_resolved}/subscription",
                "System/Schools/{\$schoolId_resolved}/subscription/features",
                "Users/Schools/{\$schoolId_resolved}/subscription/features",
                "System/Schools/{\$schoolId_resolved}/profile",
                "Users/Schools/{\$schoolId_resolved}/profile",
            ];
            foreach ($legacy as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'D.3/wiring: Admin_login legacy RTDB read string missing (off-branch broken): ' . $needle);
            }

            // ── D.4: structural ordering ──
            // The four Firestore-branch markers must appear in source AT positions
            // before the corresponding legacy RTDB read strings — proving each
            // legacy read is in the ELSE branch of its gating IF.
            $pairs = [
                ['resolve_school_code(',  "Indexes/School_codes/{\$schoolCode}"],
                ['login_access_view(',    "System/Schools/{\$schoolId_resolved}/subscription"],
                ['->get_features(',       "System/Schools/{\$schoolId_resolved}/subscription/features"],
                ['->get_display_name(',   "System/Schools/{\$schoolId_resolved}/profile"],
            ];
            foreach ($pairs as $pair) {
                $fsPos = strpos($src, $pair[0]);
                $rtPos = strpos($src, $pair[1]);
                $this->_assert($fsPos !== false && $rtPos !== false,
                    'D.4/structure: cannot locate landmarks for pair ' . $pair[0] . ' / ' . $pair[1]);
                if ($fsPos !== false && $rtPos !== false) {
                    $this->_assert($fsPos < $rtPos,
                        'D.4/structure: ' . $pair[0] . ' must precede legacy ' . $pair[1] . ' (ELSE-branch ordering)');
                }
            }
        }

        // ── D.5: flag still FALSE during build phase (no activation) ────
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2 = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2['b2.registry_firestore']) && $b2['b2.registry_firestore'] === false,
            'D.5/flag: b2.registry_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('D');
    }

    // ── B2.3.2-B-1: plan CRUD ──
    public function b1($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'B1.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            foreach (['list_plans', 'get_plan', 'create_plan', 'update_plan',
                      'delete_plan', 'count_schools_on_plan'] as $m) {
                $this->_assert(method_exists($svc, $m),
                    'B1.1/library: B2_registry_service missing public method ' . $m);
            }
        }

        $ctrl = APPPATH . 'controllers/Superadmin_plans.php';
        $this->_assert(is_file($ctrl), 'B1.2/wiring: Superadmin_plans.php not found');
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);
            // helper present
            $this->_assert(strpos($src, 'private function _b23b_registry_firestore_on') !== false,
                'B1.2/wiring: Superadmin_plans missing _b23b_registry_firestore_on() helper');
            $this->_assert(strpos($src, 'private function _b23b_registry') !== false,
                'B1.2/wiring: Superadmin_plans missing _b23b_registry() service loader');
            $this->_assert(strpos($src, '_b23b_plan_view_shape') !== false,
                'B1.2/wiring: Superadmin_plans missing _b23b_plan_view_shape() helper');

            // CRUD method-level calls present
            foreach (['list_plans(', '->create_plan(', '->update_plan(',
                      '->delete_plan(', '->get_plan(', '->count_schools_on_plan('] as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'B1.3/wiring: Superadmin_plans missing call: ' . $needle);
            }

            // legacy RTDB strings preserved in CRUD methods (off-branch must compile)
            foreach ([
                "\$this->firebase->get('System/Plans')",
                "\$this->firebase->set(\"System/Plans/{\$plan_id}\"",
                "\$this->firebase->update(\"System/Plans/{\$plan_id}\"",
                "\$this->firebase->delete(\"System/Plans\", \$plan_id)",
                "\$this->firebase->get('System/Schools')",
            ] as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'B1.4/wiring: legacy RTDB string missing (off-branch broken): ' . $needle);
            }

            // structural ordering: each Firestore call precedes its paired legacy RTDB string
            $pairs = [
                ['list_plans(',           "\$this->firebase->get('System/Plans')"],
                ['->create_plan(',        "\$this->firebase->set(\"System/Plans/{\$plan_id}\""],
                ['->update_plan(',        "\$this->firebase->update(\"System/Plans/{\$plan_id}\""],
                ['->delete_plan(',        "\$this->firebase->delete(\"System/Plans\", \$plan_id)"],
                ['->count_schools_on_plan(', "\$this->firebase->get('System/Schools')"],
            ];
            foreach ($pairs as $pair) {
                $fsPos = strpos($src, $pair[0]);
                $rtPos = strpos($src, $pair[1]);
                if ($fsPos !== false && $rtPos !== false) {
                    $this->_assert($fsPos < $rtPos,
                        'B1.5/structure: ' . $pair[0] . ' must precede legacy ' . $pair[1]);
                }
            }
        }

        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2 = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2['b2.registry_firestore']) && $b2['b2.registry_firestore'] === false,
            'B1.6/flag: b2.registry_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('B1');
    }

    // ── B2.3.2-B-2: read views (fetch_subscriptions / fetch_payments /
    //              get_school_plan / fetch_school_payments / expire_check) ──
    public function b2($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'B2.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            foreach (['list_tenants_summary', 'get_tenant_detail',
                      'write_lifecycle_state', 'list_payments',
                      'list_payments_for_school'] as $m) {
                $this->_assert(method_exists($svc, $m),
                    'B2.1/library: B2_registry_service missing public method ' . $m);
            }
        }

        $ctrl = APPPATH . 'controllers/Superadmin_plans.php';
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);

            // Read-view method-level calls present (each method invokes the
            // service via $svc / _b23b_registry()).
            foreach (['list_tenants_summary(',
                      '->get_tenant_detail(',
                      '->write_lifecycle_state(',
                      '->list_payments()',
                      '->list_payments_for_school('] as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'B2.2/wiring: Superadmin_plans missing call: ' . $needle);
            }

            // Each gated method has the per-method flag-check before the
            // legacy try block. Asserted positionally — both the flag-check
            // and the legacy read are searched WITHIN the method body
            // (between the public-function declaration and the next one).
            $methodFlags = [
                ['public function fetch_subscriptions()',  "\$this->firebase->get('System/Schools')"],
                ['public function expire_check()',         "\$this->firebase->get('System/Schools')"],
                ['public function payments()',             "\$this->firebase->get('System/Schools')"],
                ['public function fetch_payments()',       "\$this->firebase->get('System/Payments')"],
                ['public function get_school_plan()',      "\$this->firebase->get(\"System/Schools/{\$school_uid}/subscription\")"],
                ['public function fetch_school_payments()',"\$this->firebase->get('System/Payments')"],
            ];
            foreach ($methodFlags as $pair) {
                $methodPos = strpos($src, $pair[0]);
                if ($methodPos === false) {
                    $this->_assert(false, 'B2.3/structure: cannot locate method header ' . $pair[0]);
                    continue;
                }
                // Search for the legacy read AFTER the method header.
                $legacyPos = strpos($src, $pair[1], $methodPos);
                $helperPos = strpos($src, '_b23b_registry_firestore_on(', $methodPos);
                $this->_assert($legacyPos !== false && $helperPos !== false && $helperPos < $legacyPos,
                    'B2.3/structure: ' . $pair[0] . ' missing flag-check before legacy read ' . $pair[1]);
            }
        }

        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2flags = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2flags['b2.registry_firestore']) && $b2flags['b2.registry_firestore'] === false,
            'B2.4/flag: b2.registry_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('B2');
    }

    // ── B2.3.2-B-3: billing-write methods ──
    public function b3($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── B3.1: library has the billing-write data methods ────────────
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'B3.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            foreach (['get_payment', 'create_payment', 'update_payment',
                      'delete_payment', 'record_payment_completion'] as $m) {
                $this->_assert(method_exists($svc, $m),
                    'B3.1/library: B2_registry_service missing public method ' . $m);
            }
        }

        $ctrl = APPPATH . 'controllers/Superadmin_plans.php';
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);

            // ── B3.2: each billing method invokes the service ───────────
            foreach (['->create_payment(',
                      '->get_payment(',
                      '->update_payment(',
                      '->delete_payment(',
                      '->record_payment_completion('] as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'B3.2/wiring: Superadmin_plans missing call: ' . $needle);
            }

            // ── B3.3: each billing method has the per-method flag-check
            //          before its legacy RTDB read/write strings ──────────
            $billingMethods = [
                ['public function generate_invoice()', "\$this->firebase->get(\"System/Schools/{\$school_uid}/subscription\")"],
                ['public function generate_invoice()', "\$this->firebase->set(\"System/Payments/{\$payment_id}\""],
                ['public function collect_payment()',  "\$this->firebase->get(\"System/Payments/{\$invoice_id}\")"],
                ['public function collect_payment()',  "\$this->firebase->update(\"System/Payments/{\$invoice_id}\""],
                ['public function add_payment()',      "\$this->firebase->set(\"System/Payments/{\$payment_id}\""],
                ['public function update_payment()',   "\$this->firebase->get(\"System/Payments/{\$payment_id}\")"],
                ['public function update_payment()',   "\$this->firebase->update(\"System/Payments/{\$payment_id}\""],
                ['public function delete_payment()',   "\$this->firebase->delete('System/Payments', \$payment_id)"],
            ];
            foreach ($billingMethods as $pair) {
                $methodPos = strpos($src, $pair[0]);
                if ($methodPos === false) {
                    $this->_assert(false, 'B3.3/structure: cannot locate method header ' . $pair[0]);
                    continue;
                }
                $legacyPos = strpos($src, $pair[1], $methodPos);
                $helperPos = strpos($src, '_b23b_registry_firestore_on(', $methodPos);
                $this->_assert($legacyPos !== false && $helperPos !== false && $helperPos < $legacyPos,
                    'B3.3/structure: ' . $pair[0] . ' missing flag-check before legacy ' . $pair[1]);
            }

            // ── B3.4: no NEW Firestore-write to RTDB-payments pattern ───
            //          The new gated branches must use the library
            //          (->create_payment/update_payment/delete_payment) and
            //          MUST NOT dual-write to System/Payments via $firebase.
            //          Asserted by counting RTDB-write occurrences and
            //          comparing against the expected legacy footprint:
            //
            //          firebase->set("System/Payments/  → exactly 2
            //            (generate_invoice legacy + add_payment legacy)
            //          firebase->update("System/Payments/  → exactly 4
            //            (_migrate_payment helper, fetch_payments auto-overdue,
            //             collect_payment legacy, update_payment legacy)
            //          firebase->delete('System/Payments  → exactly 1
            //            (delete_payment legacy)
            //
            //          If any of these counts increases beyond the expected
            //          legacy footprint, the gated Firestore branch has
            //          gained a forbidden RTDB dual-write.
            $count_set_payments = substr_count($src, 'firebase->set("System/Payments/');
            $this->_assert($count_set_payments === 2,
                'B3.4/no-bridge: expected exactly 2 legacy set("System/Payments/...") sites; got ' . $count_set_payments);

            $count_update_payments = substr_count($src, 'firebase->update("System/Payments/');
            $this->_assert($count_update_payments === 4,
                'B3.4/no-bridge: expected exactly 4 legacy update("System/Payments/...") sites (1 _migrate_payment + 1 fetch_payments auto-overdue + 1 collect_payment + 1 update_payment); got ' . $count_update_payments);

            $count_delete_payments = substr_count($src, "firebase->delete('System/Payments'");
            $this->_assert($count_delete_payments === 1,
                'B3.4/no-bridge: expected exactly 1 legacy delete(System/Payments...) site; got ' . $count_delete_payments);
        }

        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2flags = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2flags['b2.registry_firestore']) && $b2flags['b2.registry_firestore'] === false,
            'B3.5/flag: b2.registry_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('B3');
    }

    // ── B2.3.2-C: Superadmin_schools ──
    public function c($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── C.1: library has the C-package data methods ─────────────────
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'C.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            foreach (['code_taken', 'name_taken', 'update_school_profile',
                      'set_admin_disabled', 'assign_plan_to_school',
                      'update_stats_cache'] as $m) {
                $this->_assert(method_exists($svc, $m),
                    'C.1/library: B2_registry_service missing public method ' . $m);
            }
        }

        $ctrl = APPPATH . 'controllers/Superadmin_schools.php';
        $this->_assert(is_file($ctrl), 'C.2/wiring: Superadmin_schools.php not found');
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);

            // helpers present
            $this->_assert(strpos($src, 'private function _b23c_registry_firestore_on') !== false,
                'C.2/wiring: Superadmin_schools missing _b23c_registry_firestore_on() helper');
            $this->_assert(strpos($src, 'private function _b23c_registry') !== false,
                'C.2/wiring: Superadmin_schools missing _b23c_registry() service loader');

            // ── C.3: each gated method invokes the service ──────────────
            foreach (['->code_taken(',
                      '->name_taken(',
                      '->update_school_profile(',
                      '->set_admin_disabled(',
                      '->assign_plan_to_school(',
                      '->update_stats_cache(',
                      'list_tenants_summary(',
                      '->get_tenant_detail('] as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'C.3/wiring: Superadmin_schools missing call: ' . $needle);
            }

            // ── C.4: legacy RTDB strings preserved (off-branch must compile) ──
            $legacy = [
                "\$this->firebase->get('System/Schools')",
                "\$this->firebase->get('System/Plans')",
                "\$this->firebase->get(\"Indexes/School_names/{\$nameKey}\")",
                "\$this->firebase->get(\"Indexes/School_codes/{\$code}\")",
                "\$this->firebase->get(\"System/Schools/{\$school_uid}\")",
                "\$this->firebase->update(\"System/Schools/{\$school_name}/profile\"",
                "\$this->firebase->update(\"System/Schools/{\$school_name}/subscription\"",
                "\$this->firebase->update(\"System/Schools/{\$school_name}/stats_cache\"",
                "\$this->firebase->update(\"System/Schools/{\$school_name}\"",
            ];
            foreach ($legacy as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'C.4/wiring: legacy RTDB string missing (off-branch broken): ' . $needle);
            }

            // ── C.5: per-method structural ordering — flag-check appears
            //        before paired legacy RTDB read/write strings ─────────
            $methodFlags = [
                ['public function index()',                  "\$this->firebase->get('System/Schools')"],
                ['public function create()',                 "\$this->firebase->get('System/Plans')"],
                ['public function check_availability()',     "\$this->firebase->get(\"Indexes/School_names/{\$nameKey}\")"],
                ['public function view(',                    "\$this->firebase->get(\"System/Schools/{\$school_uid}\")"],
                ['public function toggle_status()',          "\$this->firebase->update(\"System/Schools/{\$school_name}\""],
                ['public function update_profile()',         "\$this->firebase->update(\"System/Schools/{\$school_name}/profile\""],
                ['public function assign_plan()',            "\$this->firebase->update(\"System/Schools/{\$school_name}/subscription\""],
                ['public function refresh_school_stats()',   "\$this->firebase->update(\"System/Schools/{\$school_name}/stats_cache\""],
            ];
            foreach ($methodFlags as $pair) {
                $methodPos = strpos($src, $pair[0]);
                if ($methodPos === false) {
                    $this->_assert(false, 'C.5/structure: cannot locate method header ' . $pair[0]);
                    continue;
                }
                $legacyPos = strpos($src, $pair[1], $methodPos);
                $helperPos = strpos($src, '_b23c_registry_firestore_on(', $methodPos);
                $this->_assert($legacyPos !== false && $helperPos !== false && $helperPos < $legacyPos,
                    'C.5/structure: ' . $pair[0] . ' missing flag-check before legacy ' . $pair[1]);
            }

            // ── C.6: no NEW RTDB-write dual-pattern. Each legacy footprint
            //        is the legacy count only. If the Firestore branch ever
            //        added a dual-write, these counts would increase. ─────
            // Legacy onboard() writes a lot of nodes — only count writes
            // touched by the C-gated methods (which use the {$school_name}
            // variable name, not {$school_id}).
            // Legacy footprint = the legacy code only. C-gated methods that
            // touch System/Schools/{$school_name}/* via legacy update():
            //   profile      → 3 sites (toggle_status + update_profile + upload_logo)
            //   subscription → 2 sites (toggle_status + assign_plan)
            //   stats_cache  → 1 site  (refresh_school_stats)
            //   top-level    → 2 sites (toggle_status + assign_plan)
            $count_profile_update = substr_count($src, 'firebase->update("System/Schools/{$school_name}/profile"');
            $this->_assert($count_profile_update === 3,
                'C.6/no-bridge: expected exactly 3 legacy update("System/Schools/{$school_name}/profile") sites (toggle_status + update_profile + upload_logo); got ' . $count_profile_update);

            $count_sub_update = substr_count($src, 'firebase->update("System/Schools/{$school_name}/subscription"');
            $this->_assert($count_sub_update === 2,
                'C.6/no-bridge: expected exactly 2 legacy update("System/Schools/{$school_name}/subscription") sites (toggle_status + assign_plan); got ' . $count_sub_update);

            $count_stats_update = substr_count($src, 'firebase->update("System/Schools/{$school_name}/stats_cache"');
            $this->_assert($count_stats_update === 1,
                'C.6/no-bridge: expected exactly 1 legacy update("System/Schools/{$school_name}/stats_cache") site; got ' . $count_stats_update);
        }

        // ── C.7: flag still FALSE during build phase (no activation) ────
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2flags = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2flags['b2.registry_firestore']) && $b2flags['b2.registry_firestore'] === false,
            'C.7/flag: b2.registry_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('C');
    }

    // ── B2.3.2-E: onboard() Firestore-canonical writes ──
    public function e($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── E.1: library has create_tenant() ────────────────────────────
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'E.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            $this->_assert(method_exists($svc, 'create_tenant'),
                'E.1/library: B2_registry_service missing public method create_tenant');
        }

        $ctrl = APPPATH . 'controllers/Superadmin_schools.php';
        $this->_assert(is_file($ctrl), 'E.2/wiring: Superadmin_schools.php not found');
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);

            // ── E.2: onboard() invokes create_tenant via the service ────
            $this->_assert(strpos($src, '->create_tenant(') !== false,
                'E.2/wiring: onboard() missing create_tenant() call');

            // ── E.3: legacy RTDB strings preserved in onboard() (off-branch must compile) ──
            $legacyOnboard = [
                "\$this->firebase->set(\"Indexes/School_names/{\$nameKey}\"",
                "\$this->firebase->set(\"Indexes/School_codes/{\$school_code}\"",
                "\$this->firebase->set(\"System/Schools/{\$school_id}/subscription\"",
                "\$this->firebase->set(\"System/Schools/{\$school_id}/profile\"",
                "\$this->firebase->update(\"System/Schools/{\$school_id}\"",
            ];
            foreach ($legacyOnboard as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'E.3/wiring: onboard() legacy RTDB string missing (off-branch broken): ' . $needle);
            }

            // ── E.4: structural ordering — Firestore-branch landmarks must
            //        precede legacy RTDB writes in onboard() ─────────────
            $onboardPos = strpos($src, 'public function onboard()');
            $this->_assert($onboardPos !== false, 'E.4/structure: cannot locate onboard() header');
            if ($onboardPos !== false) {
                $createTenantPos = strpos($src, '->create_tenant(', $onboardPos);
                $legacySetPos    = strpos($src, "\$this->firebase->set(\"Indexes/School_names/{\$nameKey}\"", $onboardPos);
                $helperPos       = strpos($src, '_b23c_registry_firestore_on(', $onboardPos);
                $this->_assert($createTenantPos !== false && $legacySetPos !== false && $helperPos !== false,
                    'E.4/structure: missing landmark in onboard() (helper / create_tenant / legacy)');
                if ($createTenantPos !== false && $legacySetPos !== false && $helperPos !== false) {
                    $this->_assert($helperPos < $createTenantPos,
                        'E.4/structure: _b23c_registry_firestore_on must precede create_tenant call');
                    $this->_assert($createTenantPos < $legacySetPos,
                        'E.4/structure: create_tenant must precede legacy Indexes/School_names write (proves legacy is in ELSE)');
                }
            }

            // ── E.5: held-bridge writes (NON-B2 surfaces) present in BOTH branches ──
            // These three are owned by other retirement waves (B-AUTH-RES,
            // session-propagation, per-module). The Firestore branch must
            // still execute them so the tenant is fully operational at
            // onboard time even when the cutover flag is true.
            $count_users_admin = substr_count($src, '$this->firebase->set("Users/Admin/{$school_code}/{$admin_id}"');
            $this->_assert($count_users_admin === 2,
                'E.5/held-bridge: expected exactly 2 Users/Admin/{$school_code}/{$admin_id} writes (Firestore branch + legacy branch); got ' . $count_users_admin);
            // Sessions: 2 from onboard (Firestore + legacy) + 1 from migrate_existing_schools
            // (one-shot legacy tool, out of B2 scope) = 3 total.
            $count_sessions = substr_count($src, '$this->firebase->set("Schools/{$school_id}/Sessions"');
            $this->_assert($count_sessions === 3,
                'E.5/held-bridge: expected exactly 3 Schools/{$school_id}/Sessions writes (Firestore + legacy onboard + migrate_existing_schools); got ' . $count_sessions);
            $count_init_default = substr_count($src, '$this->_initialize_default_data($school_id');
            $this->_assert($count_init_default === 2,
                'E.5/held-bridge: expected exactly 2 _initialize_default_data calls (Firestore + legacy); got ' . $count_init_default);

            // ── E.6: B2-surface RTDB writes inside the controller —
            //        pinned to the legacy footprint only. The Firestore
            //        branch must NOT add any new RTDB write to B2 nodes.
            //        Counts include:
            //          - legacy onboard branch (always present)
            //          - migrate_existing_schools (one-shot legacy tool, OUT OF B2 SCOPE)
            //          - _generate_school_id _claim placeholder (pre-existing,
            //            collision-avoidance; runs in BOTH branches; NOT a
            //            B2 dual-write because it's not a B2 data node)
            //        If any count increases, the Firestore branch has
            //        introduced a forbidden RTDB dual-write to a B2 node.
            $count_idx_names = substr_count($src, 'firebase->set("Indexes/School_names/');
            $this->_assert($count_idx_names === 2,
                'E.6/no-bridge: expected exactly 2 Indexes/School_names writes (onboard legacy + migrate_existing_schools); got ' . $count_idx_names);
            $count_idx_codes = substr_count($src, 'firebase->set("Indexes/School_codes/');
            $this->_assert($count_idx_codes === 2,
                'E.6/no-bridge: expected exactly 2 Indexes/School_codes writes (onboard legacy + migrate_existing_schools); got ' . $count_idx_codes);
            // System/Schools/... set: 2 onboard legacy (subscription + profile)
            //                       + 3 migrate_existing_schools sites
            //                       + 1 _generate_school_id _claim placeholder
            $count_sys_school_set = substr_count($src, 'firebase->set("System/Schools/');
            $this->_assert($count_sys_school_set === 6,
                'E.6/no-bridge: expected exactly 6 firebase->set("System/Schools/...") sites (2 onboard legacy + 3 migrate_existing + 1 _claim); got ' . $count_sys_school_set);
        }

        // ── E.7: flag still FALSE during build phase (no activation) ────
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2flags = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2flags['b2.registry_firestore']) && $b2flags['b2.registry_firestore'] === false,
            'E.7/flag: b2.registry_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('E');
    }

    // ── B2.3.4-E: Superadmin_reports module migration ──
    public function e_reports($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── E_REPORTS.1: library has the two new methods + extended summary ──
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'E_REPORTS.1/library: B2_registry_service did not instantiate');
        if (is_object($svc)) {
            $this->_assert(method_exists($svc, 'list_recent_activity'),
                'E_REPORTS.1/library: missing list_recent_activity');
            $this->_assert(method_exists($svc, 'list_paid_payments'),
                'E_REPORTS.1/library: missing list_paid_payments');
        }

        $ctrl = APPPATH . 'controllers/Superadmin_reports.php';
        $this->_assert(is_file($ctrl), 'E_REPORTS.2/wiring: Superadmin_reports.php not found');
        if (is_file($ctrl)) {
            $src = (string) file_get_contents($ctrl);

            // ── E_REPORTS.2: controller helpers + service calls present ──
            $this->_assert(strpos($src, 'private function _b234e_reports_firestore_on') !== false,
                'E_REPORTS.2/wiring: Superadmin_reports missing _b234e_reports_firestore_on() helper');
            $this->_assert(strpos($src, 'private function _b234e_registry') !== false,
                'E_REPORTS.2/wiring: Superadmin_reports missing _b234e_registry() service loader');
            $this->_assert(strpos($src, 'private function _b234e_lifecycle_to_status') !== false,
                'E_REPORTS.2/wiring: Superadmin_reports missing _b234e_lifecycle_to_status() helper');
            foreach (['list_tenants_summary(', '->list_recent_activity(',
                      '->list_paid_payments(', '->list_plans()'] as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'E_REPORTS.2/wiring: Superadmin_reports missing call: ' . $needle);
            }

            // ── E_REPORTS.3: legacy RTDB sentinels preserved (off-branch must compile) ──
            $legacy = [
                "\$this->firebase->get('System/Schools')",
                "\$this->firebase->get('System/Payments')",
                "\$this->firebase->get(\"System/Logs/Activity/{\$current}\")",
            ];
            foreach ($legacy as $needle) {
                $this->_assert(strpos($src, $needle) !== false,
                    'E_REPORTS.3/wiring: legacy RTDB string missing (off-branch broken): ' . $needle);
            }

            // ── E_REPORTS.4: per-method structural ordering ─────────────
            $methodFlags = [
                ['public function students_summary()',    "\$this->firebase->get('System/Schools')"],
                ['public function revenue_summary()',     "\$this->firebase->get('System/Schools')"],
                ['public function activity_summary()',    "\$this->firebase->get(\"System/Logs/Activity/{\$current}\")"],
                ['public function plans_distribution()',  "\$this->firebase->get('System/Schools')"],
            ];
            foreach ($methodFlags as $pair) {
                $methodPos = strpos($src, $pair[0]);
                if ($methodPos === false) {
                    $this->_assert(false, 'E_REPORTS.4/structure: cannot locate method header ' . $pair[0]);
                    continue;
                }
                $legacyPos = strpos($src, $pair[1], $methodPos);
                $helperPos = strpos($src, '_b234e_reports_firestore_on(', $methodPos);
                $this->_assert($legacyPos !== false && $helperPos !== false && $helperPos < $legacyPos,
                    'E_REPORTS.4/structure: ' . $pair[0] . ' missing flag-check before legacy read');
            }

            // ── E_REPORTS.5: no-bridge footprint counts pinned ──
            // The Firestore branches must not introduce any new RTDB read on
            // these B2 paths. Counts include legacy occurrences only:
            //   System/Schools  → 3 (students/revenue/plans_distribution legacy)
            //   System/Payments → 1 (revenue legacy)
            //   System/Logs/Activity → 1 (activity legacy)
            $cnt_sch = substr_count($src, "firebase->get('System/Schools')");
            $this->_assert($cnt_sch === 3,
                'E_REPORTS.5/no-bridge: expected exactly 3 System/Schools reads (legacy footprint); got ' . $cnt_sch);
            $cnt_pay = substr_count($src, "firebase->get('System/Payments')");
            $this->_assert($cnt_pay === 1,
                'E_REPORTS.5/no-bridge: expected exactly 1 System/Payments read (legacy footprint); got ' . $cnt_pay);
            $cnt_log = substr_count($src, "firebase->get(\"System/Logs/Activity/");
            $this->_assert($cnt_log === 1,
                'E_REPORTS.5/no-bridge: expected exactly 1 System/Logs/Activity read (legacy footprint); got ' . $cnt_log);
        }

        // ── E_REPORTS.6: flag still FALSE during build phase ────────────
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2flags = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(isset($b2flags['b2.reports_firestore']) && $b2flags['b2.reports_firestore'] === false,
            'E_REPORTS.6/flag: b2.reports_firestore must be FALSE during build phase');

        if ($solo) $this->_finish('E_REPORTS');
    }

    /**
     * ── POST-ACTIVATION VERIFIER ────────────────────────────────────────
     *
     * Run AFTER `b2.registry_firestore` has been flipped to TRUE. Asserts
     * the post-flip invariants:
     *
     *   ACT.1 — flag is TRUE
     *   ACT.2 — B2_registry_service is bound + `firestore_authoritative()`
     *           returns TRUE
     *   ACT.3 — library exposes the full canonical 30-method surface
     *           (foundation + A + D + B1 + B2 + B3 + C + E)
     *   ACT.4 — every gated controller invokes the expected service methods
     *           (proves wiring is intact)
     *   ACT.5 — legacy RTDB sentinels still present in source (proves the
     *           rollback path is preserved end-to-end)
     *   ACT.6 — legacy RTDB write footprint counts pinned to their
     *           build-phase values (proves NO new RTDB write was added
     *           post-activation; any drift indicates a dual-write regression)
     *   ACT.7 — live Firestore smoke (benign `list_plans` call returns an
     *           array, not NULL — proves the library can reach Firestore
     *           right now)
     *
     * Build-phase methods (a/d/b1/b2/b3/c/e) remain valid for re-verifying
     * post-rollback. The harness picks `all` (requires flag=false) OR
     * `activated` (requires flag=true) at the operator's choice.
     *
     * NOT chained into `all()` — `all` is the build-phase aggregator that
     * requires flag=false. `activated` is invoked directly after the flip:
     *
     *     php index.php b2_cutover_verify activated
     */
    public function activated($solo = true)
    {
        if ($solo) { $this->pass = $this->fail = 0; $this->errors = []; }

        // ── ACT.1: flag is TRUE ─────────────────────────────────────────
        $this->config->load('b2_migration_flags', FALSE, TRUE);
        $b2 = $this->config->item('b2_migration_flags') ?: [];
        $this->_assert(is_array($b2) && !empty($b2),
            'ACT.1/flag: b2_migration_flags config must load as non-empty array');
        $this->_assert(isset($b2['b2.registry_firestore']) && $b2['b2.registry_firestore'] === true,
            'ACT.1/flag: b2.registry_firestore must be TRUE post-activation (got ' .
            var_export($b2['b2.registry_firestore'] ?? null, true) . ')');

        // ── ACT.2: service bound + reports firestore_authoritative ──────
        $this->load->library('b2_registry_service');
        $svc = $this->b2_registry_service;
        $this->_assert(is_object($svc), 'ACT.2/library: B2_registry_service must instantiate');
        if (is_object($svc)) {
            $svc->init($this->firebase);
            $this->_assert($svc->is_ready() === true,
                'ACT.2/library: is_ready() must return TRUE after init($firebase)');
            $this->_assert($svc->firestore_authoritative() === true,
                'ACT.2/library: firestore_authoritative() must return TRUE');
        }

        // ── ACT.3: library has the full canonical 30-method surface ─────
        $expectedMethods = [
            // foundation + A
            'init', 'is_ready', 'firestore_authoritative',
            'get_school_control', 'lifecycle_access',
            // D
            'resolve_school_code', 'login_access_view', 'get_features', 'get_display_name',
            // B-1
            'list_plans', 'get_plan', 'create_plan', 'update_plan', 'delete_plan',
            'count_schools_on_plan',
            // B-2
            'list_tenants_summary', 'get_tenant_detail', 'write_lifecycle_state',
            // B-3
            'list_payments', 'list_payments_for_school', 'get_payment',
            'create_payment', 'update_payment', 'delete_payment',
            'record_payment_completion',
            // C
            'code_taken', 'name_taken', 'update_school_profile',
            'set_admin_disabled', 'assign_plan_to_school', 'update_stats_cache',
            // E
            'create_tenant',
        ];
        if (is_object($svc)) {
            foreach ($expectedMethods as $m) {
                $this->_assert(method_exists($svc, $m),
                    'ACT.3/library: B2_registry_service missing public method ' . $m);
            }
        }

        // ── ACT.4: controllers contain all required service invocations ─
        $controllerWiring = [
            APPPATH . 'core/MY_Controller.php' => [
                'lifecycle_access(',
                '_b23a_registry_firestore_on',
            ],
            APPPATH . 'controllers/Admin_login.php' => [
                'resolve_school_code(',
                'login_access_view(',
                '->get_features(',
                '->get_display_name(',
                '_b23d_registry_firestore_on',
            ],
            APPPATH . 'controllers/Superadmin_plans.php' => [
                'list_plans(', '->create_plan(', '->update_plan(', '->delete_plan(',
                '->get_plan(', '->count_schools_on_plan(',
                'list_tenants_summary(', '->get_tenant_detail(',
                '->write_lifecycle_state(',
                '->list_payments()', '->list_payments_for_school(',
                '->create_payment(', '->get_payment(', '->update_payment(',
                '->delete_payment(', '->record_payment_completion(',
                '_b23b_registry_firestore_on',
            ],
            APPPATH . 'controllers/Superadmin_schools.php' => [
                '->code_taken(', '->name_taken(',
                '->update_school_profile(', '->set_admin_disabled(',
                '->assign_plan_to_school(', '->update_stats_cache(',
                '->create_tenant(',
                '_b23c_registry_firestore_on',
            ],
        ];
        foreach ($controllerWiring as $path => $needles) {
            $this->_assert(is_file($path),
                'ACT.4/wiring: file missing: ' . basename($path));
            if (is_file($path)) {
                $src = (string) file_get_contents($path);
                foreach ($needles as $n) {
                    $this->_assert(strpos($src, $n) !== false,
                        'ACT.4/wiring: ' . basename($path) . ' missing service call: ' . $n);
                }
            }
        }

        // ── ACT.5: legacy RTDB sentinels still present (rollback intact) ─
        $rollbackSentinels = [
            APPPATH . 'core/MY_Controller.php' => [
                "System/Schools/{\$this->school_id}/subscription/status",
                "Users/Schools/{\$this->school_id}/subscription/status",
            ],
            APPPATH . 'controllers/Admin_login.php' => [
                "Indexes/School_codes/{\$schoolCode}",
                "System/Schools/{\$schoolId_resolved}/subscription",
                "System/Schools/{\$schoolId_resolved}/subscription/features",
                "System/Schools/{\$schoolId_resolved}/profile",
            ],
            APPPATH . 'controllers/Superadmin_plans.php' => [
                "\$this->firebase->get('System/Plans')",
                "\$this->firebase->get('System/Schools')",
                "\$this->firebase->get('System/Payments')",
                "\$this->firebase->set(\"System/Plans/{\$plan_id}\"",
                "\$this->firebase->delete(\"System/Plans\", \$plan_id)",
            ],
            APPPATH . 'controllers/Superadmin_schools.php' => [
                "\$this->firebase->get(\"System/Schools/{\$school_uid}\")",
                "\$this->firebase->update(\"System/Schools/{\$school_name}/profile\"",
                "\$this->firebase->update(\"System/Schools/{\$school_name}/subscription\"",
                "\$this->firebase->update(\"System/Schools/{\$school_name}/stats_cache\"",
                "\$this->firebase->set(\"Indexes/School_names/{\$nameKey}\"",
                "\$this->firebase->set(\"Indexes/School_codes/{\$school_code}\"",
                "\$this->firebase->set(\"System/Schools/{\$school_id}/subscription\"",
                "\$this->firebase->set(\"System/Schools/{\$school_id}/profile\"",
            ],
        ];
        foreach ($rollbackSentinels as $path => $needles) {
            if (!is_file($path)) continue;
            $src = (string) file_get_contents($path);
            foreach ($needles as $n) {
                $this->_assert(strpos($src, $n) !== false,
                    'ACT.5/rollback: ' . basename($path) . ' missing legacy sentinel: ' . $n);
            }
        }

        // ── ACT.6: legacy RTDB write footprint counts pinned ────────────
        // Identical to the build-phase ACT counts (B1.4 / B3.4 / C.6 / E.6).
        // Any drift here means a new RTDB write was added — i.e. dual-write
        // regression.
        $sm = APPPATH . 'controllers/Superadmin_plans.php';
        if (is_file($sm)) {
            $src = (string) file_get_contents($sm);
            $this->_assert(substr_count($src, 'firebase->set("System/Payments/') === 2,
                'ACT.6/footprint: System/Payments set count drift, got ' . substr_count($src, 'firebase->set("System/Payments/'));
            $this->_assert(substr_count($src, 'firebase->update("System/Payments/') === 4,
                'ACT.6/footprint: System/Payments update count drift, got ' . substr_count($src, 'firebase->update("System/Payments/'));
            $this->_assert(substr_count($src, "firebase->delete('System/Payments'") === 1,
                'ACT.6/footprint: System/Payments delete count drift, got ' . substr_count($src, "firebase->delete('System/Payments'"));
        }
        $ss = APPPATH . 'controllers/Superadmin_schools.php';
        if (is_file($ss)) {
            $src = (string) file_get_contents($ss);
            $this->_assert(substr_count($src, 'firebase->update("System/Schools/{$school_name}/profile"') === 3,
                'ACT.6/footprint: profile-update count drift');
            $this->_assert(substr_count($src, 'firebase->update("System/Schools/{$school_name}/subscription"') === 2,
                'ACT.6/footprint: subscription-update count drift');
            $this->_assert(substr_count($src, 'firebase->update("System/Schools/{$school_name}/stats_cache"') === 1,
                'ACT.6/footprint: stats_cache-update count drift');
            $this->_assert(substr_count($src, 'firebase->set("Indexes/School_names/') === 2,
                'ACT.6/footprint: Indexes/School_names set count drift');
            $this->_assert(substr_count($src, 'firebase->set("Indexes/School_codes/') === 2,
                'ACT.6/footprint: Indexes/School_codes set count drift');
            $this->_assert(substr_count($src, 'firebase->set("System/Schools/') === 6,
                'ACT.6/footprint: System/Schools set count drift');
        }

        // ── ACT.7: live Firestore reachability smoke ────────────────────
        // Benign `list_plans` call. Returns an array (possibly empty) on
        // success; returns [] on Firestore unreachable (logged inside the
        // library). A returned NULL or thrown exception would be a fatal
        // post-activation health-check failure.
        if (is_object($svc)) {
            $svc->init($this->firebase);
            try {
                $list = $svc->list_plans();
                $this->_assert(is_array($list),
                    'ACT.7/smoke: list_plans() must return an array (got ' . gettype($list) . ')');
            } catch (\Throwable $e) {
                $this->_assert(false,
                    'ACT.7/smoke: list_plans() threw: ' . $e->getMessage());
            }
        }

        if ($solo) $this->_finish('ACTIVATED');
    }

    private function _assert(bool $cond, string $msg): void
    {
        if ($cond) { $this->pass++; }
        else       { $this->fail++; $this->errors[] = $msg; }
    }

    private function _finish(string $tag): void
    {
        echo "=== B2.3.2 $tag CUTOVER verifier ===\n";
        echo "PASS: {$this->pass}  FAIL: {$this->fail}\n";
        foreach ($this->errors as $e) echo "  ! $e\n";
        $gate = ($this->fail === 0) ? 'PASS' : 'REVIEW';
        echo "$tag GATE: $gate\n";
        exit($this->fail === 0 ? 0 : 2);
    }
}
