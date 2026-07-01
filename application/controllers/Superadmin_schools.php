<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_schools
 *
 * Primary data : Firestore schools/{schoolId} + schoolControl/{schoolId}  where schoolId = SCH_XXXXXX
 *
 * school_id (SCH_XXXXXX) is the permanent Firebase key — never the school name.
 * school_name is stored as a data field inside the node (profile/school_name).
 *
 * Lookup indexes:
 *   Indexes/School_codes/{school_code}  → school_id   (Admin_login fast path)
 *   Indexes/School_names/{name_key}     → school_id   (name uniqueness + reverse lookup)
 */
class Superadmin_schools extends MY_Superadmin_Controller
{
    // B1 onboarding-rollback state (set during onboard(); read by _onboard_rollback).
    private $_ob_schcodeVal = null;
    private $_ob_ssaVal     = null;
    private $_ob_fbUid      = null;
    private $_sec_telem     = null;

    public function __construct() { parent::__construct(); }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/schools
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $schools = [];

        // ── Single-source schools list (Firestore) ───────────────────────
        {
            $svc       = $this->_b23c_registry();
            $tenants   = $svc->list_tenants_summary();
            $planById  = [];
            foreach ($svc->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid !== '') $planById[$pid] = $fs;
            }
            foreach ($tenants as $t) {
                $sid    = (string) $t['schoolId'];
                $pfid   = (string) $t['planFamilyId'];
                $state  = (string) $t['lifecycleState'];
                // P0-2: every rendered field is sourced directly from the
                // summary row. The per-tenant get_tenant_detail() lookup
                // (3 Firestore reads/tenant) is removed — list_tenants_summary()
                // already carries city/logoUrl/domainIdentifier/totalStudents/
                // totalStaff/adminDisabled from the same schools doc. Status
                // derivation now matches the canonical adminDisabled source
                // (tenantPublic-first, strict) used by lifecycle_access() and
                // login_access_view(); active/suspended values are preserved.
                $topStatus = !empty($t['adminDisabled']) ? 'suspended' : 'active';
                $planName  = isset($planById[$pfid]) ? (string) ($planById[$pfid]['name'] ?? '—') : '—';
                $schools[] = [
                    'uid'          => $sid,
                    'name'         => $t['schoolName'] !== '' ? $t['schoolName'] : $sid,
                    'city'         => (string) ($t['city'] ?? ''),
                    'logo_url'     => (string) ($t['logoUrl'] ?? ''),
                    'domain_id'    => (string) ($t['domainIdentifier'] ?? ''),
                    'firebase_key' => $sid,
                    'status'       => $topStatus,
                    'created_at'   => '',
                    'plan_name'    => $planName,
                    'expiry_date'  => (string) $t['subscriptionPeriodEnd'],
                    'sub_status'   => $state,
                    'students'     => (int) ($t['totalStudents'] ?? 0),
                    'staff'        => (int) ($t['totalStaff']    ?? 0),
                ];
            }
            usort($schools, fn($a, $b) => strcmp($a['name'], $b['name']));
            $data = ['page_title' => 'Manage Schools', 'schools' => $schools];
            $this->load->view('superadmin/include/sa_header', $data);
            $this->load->view('superadmin/schools/index',     $data);
            $this->load->view('superadmin/include/sa_footer');
            return;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/schools/create
    // ─────────────────────────────────────────────────────────────────────────
    public function create()
    {
        $plans = [];

        // ── Single-source plan dropdown (Firestore) ──────────────────────
        foreach ($this->_b23c_registry()->list_plans() as $fs) {
            $pid = (string) ($fs['planFamilyId'] ?? '');
            if ($pid !== '') $plans[$pid] = (string) ($fs['name'] ?? $pid);
        }

        $data = ['page_title' => 'Onboard New School', 'plans' => $plans];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/schools/create',    $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/check_availability
    // ─────────────────────────────────────────────────────────────────────────
    public function check_availability()
    {
        $name = trim($this->input->post('school_name', TRUE) ?? '');
        $code = strtoupper(trim($this->input->post('school_code', TRUE) ?? ''));

        // Validate characters first
        if ($name !== '' && !preg_match("/^[A-Za-z0-9 '.,()&_\-]+$/u", $name)) {
            $this->json_error('School name contains invalid characters.'); return;
        }
        if ($code !== '' && !preg_match('/^[A-Z0-9]{3,10}$/', $code)) {
            $this->json_error('Code must be 3–10 uppercase letters/digits.'); return;
        }

        // ── Single-source uniqueness check (Firestore) ───────────────────
        $svc = $this->_b23c_registry();
        $name_taken = false;
        if ($name !== '') {
            $nameKey    = $this->_school_name_key($name);
            $name_taken = $svc->name_taken($nameKey);
        }
        $code_taken = $code !== '' && $svc->code_taken($code);
        $this->json_success([
            'name_taken' => $name_taken,
            'code_taken' => $code_taken,
            'available'  => !$name_taken && !$code_taken,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/onboard
    // ─────────────────────────────────────────────────────────────────────────
    public function onboard()
    {
        // Step 1 — School Profile
        $name        = trim($this->input->post('school_name', TRUE) ?? '');
        $city        = trim($this->input->post('city',        TRUE) ?? '');
        $street      = trim($this->input->post('street',      TRUE) ?? '');
        $email       = strtolower(trim($this->input->post('email',    TRUE) ?? ''));
        $phone       = trim($this->input->post('phone',       TRUE) ?? '');
        $logo_url    = trim($this->input->post('logo_url',    TRUE) ?? '');

        // Step 2 — Admin Account (School Code + SSA ID are auto-generated)
        $admin_name  = trim($this->input->post('admin_name',     TRUE) ?? '');
        $admin_email = strtolower(trim($this->input->post('admin_email',    TRUE) ?? ''));
        $admin_phone = trim($this->input->post('admin_phone',    TRUE) ?? ''); // SSA recovery-contact number (forget_password_details)
        $admin_pass  = (string)($this->input->post('admin_password', FALSE) ?? ''); // raw — no XSS filter on passwords

        // Step 3 — Subscription & Session
        $plan_id    = trim($this->input->post('plan_id',      TRUE) ?? '');
        $expiry     = trim($this->input->post('expiry_date',  TRUE) ?? '');
        $session_yr = trim($this->input->post('session_year', TRUE) ?? '');

        // ── Validation ────────────────────────────────────────────────────────
        if (empty($name) || empty($email) ||
            empty($admin_name) || empty($admin_email) || empty($admin_phone) || empty($admin_pass) ||
            empty($plan_id) || empty($expiry) || empty($session_yr)) {
            $this->json_error('All required fields must be filled.'); return;
        }
        if (!preg_match('/^[0-9+\-()\s]{7,20}$/', $admin_phone)) {
            $this->json_error('Invalid admin phone number.'); return;
        }
        if (!preg_match("/^[A-Za-z0-9 '.,()&_\-]+$/u", $name)) {
            $this->json_error('School name contains invalid characters.'); return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid school contact email.'); return;
        }
        if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid admin email address.'); return;
        }

        // ── B1: school code via Firestore Id_generator (atomic) when flag ON; ──
        // legacy Auth API (Mongo) otherwise. Inert until onboard.schcode_firestore=ON.
        $this->config->load('sa_migration_flags', FALSE, TRUE);
        $saFlags         = $this->config->item('sa_migration_flags');
        $useIdGenSchcode = is_array($saFlags) && !empty($saFlags['onboard.schcode_firestore']);
        $useFsSsa        = is_array($saFlags) && !empty($saFlags['onboard.ssa_firebase']);
        $this->_ob_schcodeVal = null; $this->_ob_ssaVal = null; $this->_ob_fbUid = null;

        // ── NO-MONGO HARD GATE (legacy onboarding decommissioned) ───────────────
        // Every legacy onboarding branch below routes school-code + SSA creation
        // through auth_client (the retired Mongo subsystem; B1 onboarding
        // convergence). The all-Firestore path is the ONLY supported onboarding
        // path. If any of the three cutover flags is off we fail loudly here
        // rather than silently provisioning an SSA via Mongo / a partial path
        // that cannot satisfy the canonical login contract.
        if (!$useIdGenSchcode || !$useFsSsa) {
            log_message('error', 'SA onboard BLOCKED — legacy Mongo onboarding path is decommissioned. '
                . 'Required all-Firestore flags: onboard.schcode_firestore=' . var_export($useIdGenSchcode, true)
                . ', onboard.ssa_firebase=' . var_export($useFsSsa, true) . ' (all must be true).');
            $this->_onboard_telem('ONBOARD_RESULT', ['result' => 'blocked_legacy_mongo_decommissioned']);
            $this->json_error('Onboarding is unavailable in this configuration. Please contact support.');
            return;
        }

        if ($useIdGenSchcode) {
            $this->load->library('id_generator');
            try { $school_code = $this->id_generator->safeGenerate('SCHCODE'); }
            catch (\Throwable $e) { log_message('error', 'SA onboard: Id_generator SCHCODE failed: ' . $e->getMessage()); $school_code = ''; }
            $this->_ob_schcodeVal = ($school_code !== '') ? ((int) $school_code - 10000) : null;
            $this->_onboard_telem('ONBOARD_IDGEN_SOURCE', ['kind' => 'SCHCODE', 'source' => 'firestore']);
        } else {
            $this->load->library('auth_client');
            $school_code = $this->auth_client->generate_id('SCHCODE');
            $this->_onboard_telem('ONBOARD_IDGEN_SOURCE', ['kind' => 'SCHCODE', 'source' => 'mongo']);
            $this->_onboard_telem('ONBOARD_MONGO_HIT', ['call' => 'generate_id']);
        }
        if (empty($school_code)) {
            $this->json_error('Failed to generate school code. Please try again.'); return;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $plan_id)) {
            $this->json_error('Invalid plan identifier.'); return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry) || strtotime($expiry) === false) {
            $this->json_error('Expiry date must be in YYYY-MM-DD format.'); return;
        }
        if (strtotime($expiry) < time()) {
            $this->json_error('Expiry date cannot be in the past.'); return;
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $session_yr)) {
            $this->json_error('Session year must be in YYYY-YY format (e.g. 2025-26).'); return;
        }
        if (strlen($admin_pass) < 8) {
            $this->json_error('Admin password must be at least 8 characters.'); return;
        }

        // ── Availability checks ───────────────────────────────────────────────
        $nameKey = $this->_school_name_key($name);

        // ── B2.3.2-E: Firestore-canonical onboarding branch ──────────────
        // When b2.registry_firestore=TRUE, this branch creates the 6+ canonical
        // Firestore documents (schools, schoolControl, tenantPublic,
        // subscriptions, schoolSsa, schoolCodeIndex, schoolNameIndex,
        // tenantAudit) atomically via B2_registry_service::create_tenant.
        // RTDB writes for NON-B2 surfaces — Users/Admin (tenant-admin auth,
        // owned by B-AUTH-RES), Schools/{id}/Sessions (academic session
        // bootstrap, owned by SW), _initialize_default_data (academic seed,
        // per-module retirement) — REMAIN in both branches because their
        // retirement is owned by other waves. These are HELD BRIDGES, NOT
        // B2 dual-writes. The legacy RTDB branch below is preserved verbatim
        // for rollback.
        {
            $svc = $this->_b23c_registry();

            // Plan must exist in Firestore canonical
            $planFs = $svc->get_plan($plan_id);
            if (!is_array($planFs)) {
                $this->json_error("Plan '{$plan_id}' not found in Firestore. Cannot create school."); return;
            }
            // Pre-flight uniqueness (race-safe gate is in create_tenant itself; this
            // is the cheap pre-check for clean error messaging)
            if ($svc->name_taken($nameKey)) {
                $this->json_error("A school named '{$name}' already exists."); return;
            }
            if ($svc->code_taken($school_code)) {
                $this->json_error("Generated school code '{$school_code}' collided. Please retry."); return;
            }

            $school_id  = $svc->mint_school_id($this->sa_id ?? 'system');
            if ($school_id === '') {
                $this->json_error('Failed to allocate a unique school ID. Please retry.'); return;
            }
            $now        = date('Y-m-d H:i:s');
            $grace_days = (int) ($planFs['graceDays'] ?? 7);
            $grace_end  = date('Y-m-d', strtotime($expiry . " +{$grace_days} days"));
            $hashed_pw  = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);

            // SSA id + Firebase Auth user (same B1 path used by the legacy branch).
            $this->load->library('id_generator');
            try { $admin_id = $this->id_generator->safeGenerate('SSA'); }
            catch (\Throwable $e) {
                log_message('error', 'SA onboard (FS): Id_generator SSA failed: ' . $e->getMessage());
                $this->json_error('Failed to generate admin ID. Please try again.'); return;
            }
            $this->_ob_ssaVal = (int) substr($admin_id, 3);

            $authEmail = Firebase::authEmail($admin_id);
            $fbUser    = $this->firebase->createFirebaseUser($authEmail, $admin_pass, ['uid' => $admin_id, 'displayName' => $admin_name]);
            if ($fbUser === null) {
                $this->_onboard_telem('ONBOARD_SSA_FBAUTH', ['result' => 'create_failed', 'ssa' => $admin_id, 'path' => 'firestore_canonical']);
                $this->json_error('Failed to create the admin login account. Please try again.'); return;
            }
            $this->_ob_fbUid = $admin_id;

            $claimOk = false;
            for ($attempt = 1; $attempt <= 2 && !$claimOk; $attempt++) {
                // Wave C canonical claim schema (camelCase) — MUST match the keys
                // Admin_login::_try_firebase_admin_login reads (schoolId/schoolCode/
                // role). The prior snake_case keys (school_id/school_code) left
                // $schoolId empty at login → ADMIN_LOGIN_AUTHZ_MISSING → SSA could
                // never sign in. See SSA0001 (working) for the canonical shape.
                $claimOk = $this->firebase->setFirebaseClaims($admin_id, [
                    'role' => 'school_super_admin', 'roleLabel' => 'School Super Admin',
                    'schoolId' => $school_id, 'schoolCode' => $school_code,
                    'parentDbKey' => $school_code,
                    // Dual-emit snake_case: admin-panel login reads camelCase
                    // (schoolId/schoolCode — see comment above), but Firestore
                    // Security Rules read school_id. Both are required so the SSA
                    // can sign into the panel AND read in the mobile apps.
                    'school_id' => $school_id, 'school_code' => $school_code,
                    'parent_db_key' => $school_code,
                ]);
                if (!$claimOk && $attempt < 2) usleep(200000);
            }
            if (!$claimOk) {
                try { $this->firebase->deleteFirebaseUser($admin_id); } catch (\Throwable $e) {}
                $this->_onboard_telem('ONBOARD_SSA_FBAUTH', ['result' => 'claims_failed', 'ssa' => $admin_id, 'path' => 'firestore_canonical']);
                $this->json_error('Failed to set admin permissions. Please try again.'); return;
            }

            // Atomic Firestore tenant creation (the load-bearing single source of truth).
            // SC-Step8 (Session Convergence — 2026-06-02): sessionYear is now
            // passed INTO create_tenant so the schools/{id} doc lands with
            // sessions[$session_yr] + currentSession atomically as part of the
            // same B2 6+ doc batch. Removes the pre-Step-8 race window where
            // the separate firestoreUpdate (formerly at L430) could fail after
            // create_tenant succeeded, leaving a tenant with no session state.
            $tenantResult = $svc->create_tenant([
                'schoolId'          => $school_id,
                'schoolCode'        => (string) $school_code,
                'schoolName'        => $name,
                'nameKey'           => $nameKey,
                'city'              => $city,
                'street'            => $street,
                'email'             => $email,
                'phone'             => $phone,
                'logoUrl'           => $logo_url,
                'domainIdentifier'  => strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name)),
                'planFamilyId'      => $plan_id,
                'planModules'       => is_array($planFs['modules'] ?? null) ? $planFs['modules'] : [],
                'planBillingCycle'  => (string) ($planFs['billingCycle'] ?? 'annual'),
                'periodStart'       => date('Y-m-d'),
                'periodEnd'         => $expiry,
                'graceEnd'          => $grace_end,
                'primarySsaId'      => $admin_id,
                'ssaName'           => $admin_name,
                'ssaEmail'          => $admin_email,
                'ssaPhone'          => $admin_phone,
                'createdBy'         => (string) $this->sa_id,
                'sessionYear'       => $session_yr,
            ]);
            if (empty($tenantResult['success'])) {
                $errCode = (string) ($tenantResult['error'] ?? 'unknown');
                // Roll back the Firebase Auth user (canonical Firestore docs
                // either weren't written or were already rolled back by create_tenant).
                try { $this->firebase->deleteFirebaseUser($admin_id); } catch (\Throwable $e) {}
                $errMsg = ($errCode === 'name_taken') ? "A school named '{$name}' already exists."
                       : (($errCode === 'code_taken') ? "School code '{$school_code}' is already in use." :
                          'Failed to create tenant records: ' . $errCode);
                $this->_onboard_telem('ONBOARD_RESULT', ['result' => 'failed', 'path' => 'firestore_canonical', 'error' => $errCode]);
                $this->json_error($errMsg); return;
            }

            // ── HELD-BRIDGE WRITES (NOT B2 surface — owned by other waves) ──
            // Users/Admin/{code}/{ssaId} — tenant-admin auth (B-AUTH-RES retirement).
            // Still read by Admin_login for the legacy authn-record path; cannot
            // be removed until B-AUTH-RES migrates Admin_login.
            try {
                $this->firebase->set("Users/Admin/{$school_code}/{$admin_id}", [
                    'Status'      => 'Active',
                    'Role'        => 'School Super Admin',
                    'Name'        => $admin_name,
                    'Email'       => $admin_email,
                    'Credentials' => ['Id' => $admin_id, 'Password' => $hashed_pw],
                    'Profile'     => [
                        'name' => $admin_name, 'email' => $admin_email, 'phone' => $phone,
                        'role' => 'school_super_admin', 'school' => $name,
                        'school_id' => $school_code, 'firebase_id' => $school_id,
                        'created_at' => $now, 'created_by' => $this->sa_id,
                    ],
                    'AccessHistory' => ['SA_LastLogin' => null, 'SA_LastLoginIP' => null, 'LoginAttempts' => 0],
                    'Privileges'    => ['accountmanagement' => ''],
                ]);
            } catch (Exception $e) {
                log_message('error', 'SA onboard (FS): Users/Admin write failed (non-fatal): ' . $e->getMessage());
            }

            // ── SSA staff doc — REQUIRED for login authorization ────────────
            // Admin_login::_try_firebase_admin_login reads staff/{schoolId}_{ssaId}
            // for the canonical Active/Inactive status check (Wave C). create_tenant
            // writes schoolSsa/{ssaId} but NOT this staff doc, so without this block
            // a freshly-onboarded SSA fails login with ADMIN_LOGIN_AUTHZ_MISSING
            // (reason=staff_doc_absent). Shape mirrors the working SSA0001 doc.
            $staffDoc = [
                'staffId'       => $admin_id,
                'schoolId'      => $school_id,
                'status'        => 'Active',
                'Status'        => 'Active',
                'role'          => 'School Super Admin',
                'Role'          => 'School Super Admin',
                'name'          => $admin_name,
                'Name'          => $admin_name,
                'email'         => $admin_email,
                'Email'         => $admin_email,
                'sessions'      => [$session_yr],
                'session'       => $session_yr,
                'auth_migrated' => true,
                'Privileges'    => ['accountmanagement' => ''],
                'Profile'       => [
                    'name' => $admin_name, 'email' => $admin_email, 'phone' => $phone,
                    'role' => 'school_super_admin', 'school' => $name,
                    'school_id' => $school_code, 'firebase_id' => $school_id,
                    'created_at' => $now, 'created_by' => (string) $this->sa_id,
                ],
                'updatedAt'     => date('c'),
            ];
            // Bounded retry (idempotent merge) — absorbs a transient single-write
            // failure so a blip cannot strand the SSA without a staff doc. If all
            // attempts fail, the contract self-heal below makes a final attempt
            // and, failing that, quarantines the tenant (no silent success).
            $staffOk = false;
            for ($attempt = 1; $attempt <= 3 && !$staffOk; $attempt++) {
                try {
                    $staffOk = (bool) $this->firebase->firestoreSet('staff', "{$school_id}_{$admin_id}", $staffDoc, true);
                } catch (\Throwable $e) {
                    log_message('error', "SA onboard (FS): staff doc write attempt {$attempt} failed for {$admin_id} ({$school_id}): " . $e->getMessage());
                }
                if (!$staffOk && $attempt < 3) usleep(200000); // 200ms backoff
            }
            if (!$staffOk) {
                log_message('error', "SA onboard (FS): staff doc write exhausted retries for {$admin_id} ({$school_id}) — contract self-heal will make a final attempt.");
            }

            // SC-Step8 (Session Convergence — 2026-06-02): RTDB Sessions +
            // Config/ActiveSession writes RETIRED. Admin_login is Firestore-
            // canonical (SW2 2026-05-26); MY_Controller is Firestore-canonical
            // (SC-Step5 dfcd8bbc). The schools/{id}.sessions[] + currentSession
            // seed is now ATOMIC within the B2 create_tenant batch above
            // (sessionYear arg passed). The pre-Step-8 separate firestoreUpdate
            // here is removed — its atomicity is now inherited from create_tenant.
            // POST-CLEAN-2 (mark RTDB session paths inert) is unblocked by this.

            // LOGO-1 (post-B3 2026-06-02): promote the temp-FS logo URL
            // that upload_logo() returned to a canonical Firebase Storage
            // URL now that schoolId exists. Non-fatal — on any failure
            // path the helper logs + returns the original $logo_url
            // unchanged (SSA can re-upload via School Config to recover).
            // See _promote_temp_logo_to_storage() docblock for full
            // semantics + the legacy-branch scoping decision.
            if ($logo_url !== '') {
                $logo_url = $this->_promote_temp_logo_to_storage($school_id, $logo_url);
            }

            // _initialize_default_data — academic seed (account books + fee
            // structures). Per-module retirement (Accounting + Fees waves).
            $this->_initialize_default_data($school_id, $session_yr, [
                'name'       => (string) ($planFs['name']        ?? $plan_id),
                'modules'    => is_array($planFs['modules'] ?? null) ? $planFs['modules'] : [],
                'grace_days' => $grace_days,
            ]);

            // ── Canonical SSA login-contract enforcement (fail-closed by repair) ──
            // Verify the freshly-provisioned SSA satisfies the Admin_login contract
            // (Auth user + schoolId/role claims + staff doc + login-eligible status).
            // On a violation we first attempt an idempotent SELF-HEAL, then re-verify.
            // If it STILL violates we neither silently succeed nor destructively roll
            // back — we QUARANTINE the tenant (lifecycle=provisioning_incomplete, which
            // the login access-gate auto-denies) and return json_error, leaving the
            // recoverable artifacts in place for idempotent repair.
            $ssaViolations = $this->_verify_ssa_login_contract($school_id, $admin_id);
            if (in_array('staff_doc_missing', $ssaViolations, true)) {
                try { $this->firebase->firestoreSet('staff', "{$school_id}_{$admin_id}", $staffDoc, true); }
                catch (\Throwable $e) {
                    log_message('error', "SA onboard (FS): staff doc self-heal failed for {$admin_id} ({$school_id}): " . $e->getMessage());
                }
                $ssaViolations = $this->_verify_ssa_login_contract($school_id, $admin_id);
            }

            if (!empty($ssaViolations)) {
                // Unresolved → quarantine (no destructive rollback) + fail-closed.
                try {
                    $svc->write_lifecycle_state($school_id, 'provisioning_incomplete',
                        'ssa_login_contract_unresolved: ' . implode(',', $ssaViolations));
                } catch (\Throwable $e) {
                    log_message('error', "SA onboard (FS): provisioning_incomplete flag failed for {$school_id}: " . $e->getMessage());
                }
                $this->_onboard_telem('ONBOARD_RESULT', [
                    'result' => 'ssa_contract_unresolved', 'path' => 'firestore_canonical',
                    'school_id' => $school_id, 'school_code' => $school_code,
                    'admin_id' => $admin_id, 'missing' => implode(',', $ssaViolations),
                ]);
                log_message('error', "SA onboard CONTRACT UNRESOLVED ssa={$admin_id} school={$school_id} "
                    . "— tenant quarantined (provisioning_incomplete); SSA cannot log in until repaired: "
                    . implode(', ', $ssaViolations));
                $this->json_error('School created, but admin-login provisioning is incomplete ('
                    . implode(', ', $ssaViolations) . '). The tenant has been flagged for repair — please retry or contact support.');
                return;
            }

            // Contract satisfied — log success only now (never alongside a violation).
            $this->sa_log('school_onboarded', $school_id, [
                'school_name' => $name, 'school_id' => $school_id,
                'school_code' => $school_code, 'admin_id' => $admin_id,
                'path' => 'firestore_canonical',
            ]);
            $this->_onboard_telem('ONBOARD_RESULT', [
                'result' => 'success', 'path' => 'firestore_canonical',
                'school_id' => $school_id, 'school_code' => $school_code,
                'admin_id' => $admin_id,
                'subscription_id' => (string) ($tenantResult['subscriptionId'] ?? ''),
            ]);
            $this->json_success([
                'school_name' => $name,
                'school_id'   => $school_id,
                'school_code' => $school_code,
                'admin_id'    => $admin_id,
                'message'     => "School '{$name}' onboarded successfully. School ID: {$school_id}. SSA Login — School Code: {$school_code}, SSA ID: {$admin_id}.",
            ]);
            return;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/schools/view/{name}
    // ─────────────────────────────────────────────────────────────────────────
    public function view($school_uid = '')
    {
        $school_uid  = urldecode(trim($school_uid));
        $school_name = $school_uid; // backward compat alias; replaced below with human name
        if (empty($school_uid)) { redirect('superadmin/schools'); return; }

        // ── Single-source school detail (Firestore) ──────────────────────
        {
            $svc    = $this->_b23c_registry();
            $detail = $svc->get_tenant_detail($school_uid);
            if (!is_array($detail)) { redirect('superadmin/schools'); return; }
            $schDoc = is_array($detail['schools']       ?? null) ? $detail['schools']       : [];
            $ctrl   = is_array($detail['schoolControl'] ?? null) ? $detail['schoolControl'] : [];
            $subDoc = is_array($detail['subscriptionDoc'] ?? null) ? $detail['subscriptionDoc'] : [];
            $life   = is_array($ctrl['lifecycle']    ?? null) ? $ctrl['lifecycle']    : [];
            $subPtr = is_array($ctrl['subscription'] ?? null) ? $ctrl['subscription'] : [];
            $adminDis = is_array($schDoc['adminDisabled'] ?? null) ? $schDoc['adminDisabled'] : [];
            $cache  = is_array($schDoc['statsCache'] ?? null) ? $schDoc['statsCache'] : [];

            $school_name = (string) ($schDoc['schoolName'] ?? $schDoc['name'] ?? $school_uid);
            $expiry = (string) ($subDoc['periodEnd'] ?? '');
            $topStatus = !empty($adminDis['value']) ? 'suspended' : 'active';
            $planFid = (string) ($subPtr['planId'] ?? '');
            $planName = '—';
            if ($planFid !== '') {
                $pf = $svc->get_plan($planFid);
                if (is_array($pf)) $planName = (string) ($pf['name'] ?? $planFid);
            }

            $school = [
                'profile' => [
                    'name'              => $school_name,
                    'city'              => (string) ($schDoc['city']             ?? ''),
                    'street'            => (string) ($schDoc['address'] ?? $schDoc['street'] ?? ''),
                    'email'             => (string) ($schDoc['email']            ?? ''),
                    'phone'             => (string) ($schDoc['phone']            ?? ''),
                    'logo_url'          => (string) ($schDoc['logoUrl']          ?? ''),
                    'school_code'       => (string) ($schDoc['schoolCode']       ?? ''),
                    'domain_identifier' => (string) ($schDoc['domainIdentifier'] ?? ''),
                    'firebase_key'      => $school_uid,
                    'status'            => $topStatus,
                    'created_at'        => (string) ($schDoc['createdAt'] ?? ''),
                    'created_by'        => (string) ($schDoc['createdBy'] ?? 'SA'),
                ],
                'subscription' => [
                    'plan_id'     => $planFid,
                    'plan_name'   => $planName,
                    'expiry_date' => $expiry,
                    'status'      => (string) ($life['state'] ?? 'inactive'),
                ],
                'stats_cache' => [
                    'total_students' => (int) ($cache['totalStudents'] ?? $cache['total_students'] ?? 0),
                    'total_staff'    => (int) ($cache['totalStaff']    ?? $cache['total_staff']    ?? 0),
                    'last_updated'   => (string) ($cache['lastUpdated'] ?? $cache['last_updated'] ?? 'Never'),
                ],
            ];
            $plans = [];
            foreach ($svc->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid !== '') $plans[$pid] = (string) ($fs['name'] ?? $pid);
            }
            $data = [
                'page_title' => 'School — ' . $school_name,
                'school_uid' => $school_uid,
                'school'     => $school,
                'plans'      => $plans,
            ];
            $this->load->view('superadmin/include/sa_header', $data);
            $this->load->view('superadmin/schools/view',      $data);
            $this->load->view('superadmin/include/sa_footer');
            return;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/toggle_status
    // ─────────────────────────────────────────────────────────────────────────
    public function toggle_status()
    {
        $school_name = trim($this->input->post('school_uid', TRUE) ?? '');
        $new_status  = trim($this->input->post('status',     TRUE) ?? '');

        if (empty($school_name) || !in_array($new_status, ['active', 'inactive', 'suspended'])) {
            $this->json_error('Invalid request.');
            return;
        }
        // [FIX-3] Validate school_name before use in Firebase path
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_name)) {
            $this->json_error('Invalid school identifier.'); return;
        }

        // ── Single-source status toggle (Firestore) ──────────────────────
        $svc = $this->_b23c_registry();
        $tenant = $svc->get_tenant_detail($school_name);
        if (!is_array($tenant)) { $this->json_error('School not found.'); return; }
        $ok = $svc->set_admin_disabled($school_name, $new_status, (string) ($this->sa_id ?? ''));
        if (!$ok) { $this->json_error('Failed to update school status.'); return; }
        $this->sa_log('school_status_changed', $school_name, ['new_status' => $new_status]);
        $this->json_success(['message' => "School status updated to '{$new_status}'."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/update_profile
    // ─────────────────────────────────────────────────────────────────────────
    public function update_profile()
    {
        $school_name = trim($this->input->post('school_uid',         TRUE) ?? '');
        $city        = trim($this->input->post('city',               TRUE) ?? '');
        $street      = trim($this->input->post('street',             TRUE) ?? '');
        $email       = strtolower(trim($this->input->post('email',   TRUE) ?? ''));
        $phone       = trim($this->input->post('phone',              TRUE) ?? '');
        $logo_url    = trim($this->input->post('logo_url',           TRUE) ?? '');
        $domain_id   = strtolower(trim($this->input->post('domain_identifier', TRUE) ?? ''));

        if (empty($school_name)) { $this->json_error('School name required.'); return; }
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_name)) {
            $this->json_error('Invalid school identifier.'); return;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid email address.'); return;
        }
        if ($logo_url !== '' && !filter_var($logo_url, FILTER_VALIDATE_URL)) {
            $this->json_error('Invalid logo URL.'); return;
        }
        // Defensive: ensure a Firebase Storage download URL has its object path
        // %2F-encoded. A raw-slash path (…/o/schools/SCH_x/logos/…) returns HTTP
        // 400 and the logo silently fails to render in every panel.
        $logo_url = self::encode_storage_url($logo_url);

        // ── Single-source profile patch (Firestore) ──────────────────────
        $nowIso = date('c');
        $ok = $this->_b23c_registry()->update_school_profile($school_name, [
            'city'             => $city,
            'address'          => $street,   // canonical (school panel + apps read `address`)
            'street'           => $street,   // legacy mirror (SA view reads `street`)
            'email'            => $email,
            'phone'            => $phone,
            'logoUrl'          => $logo_url,
            'domainIdentifier' => $domain_id,
            'updatedAt'        => $nowIso,
            'updatedBy'        => (string) $this->sa_id,
        ]);
        if (!$ok) { $this->json_error('Failed to update profile.'); return; }
        $this->sa_log('school_profile_updated', $school_name);
        $this->json_success(['message' => 'School profile updated.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/reset_ssa_password
    //
    // Developer Super Admin resets any School Super Admin's password.
    // MY_Superadmin_Controller already enforces sa_id session.
    // ─────────────────────────────────────────────────────────────────────────
    public function reset_ssa_password()
    {
        $school_uid   = trim((string) ($this->input->post('school_uid', TRUE) ?? ''));
        $target_id    = trim((string) ($this->input->post('ssa_id',     TRUE) ?? ''));
        $new_password = (string) ($this->input->post('new_password', FALSE) ?? '');

        if ($school_uid === '' || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.'); return;
        }
        if ($target_id === '' || !preg_match('/^SSA\d+$/', $target_id)) {
            $this->json_error('Invalid SSA id.'); return;
        }

        // Resolve school_code from the Firestore schools/{uid} doc.
        $schDoc = $this->firebase->firestoreGet('schools', $school_uid);
        $school_code = is_array($schDoc) ? (string) ($schDoc['schoolCode'] ?? '') : '';
        if ($school_code === '') {
            $this->json_error('School not found or missing school code.', 404); return;
        }

        // Confirm target SSA exists in that school.
        $target = $this->firebase->get("Users/Admin/{$school_code}/{$target_id}");
        if (empty($target) || !is_array($target)) {
            $this->json_error('SSA not found in this school.', 404); return;
        }

        $this->load->library('Ssa_reset', null, 'ssa_reset');
        $result = $this->ssa_reset->resetSsaPassword(
            $school_code,
            $school_uid,    // school_uid is the Firestore school_id (SCH_XXXXXX)
            $target_id,
            $new_password,
            'SA:' . (string) $this->sa_id
        );

        if (empty($result['success'])) {
            $this->json_error($result['message'] ?? 'Reset failed.'); return;
        }

        $this->sa_log('ssa_password_reset', $school_uid, [
            'ssa_id'   => $target_id,
            'ssa_name' => $result['ssa_name'],
        ]);

        $this->json_success([
            'message' => $result['message'],
            'ssa_id'  => $target_id,
            'name'    => $result['ssa_name'],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/assign_plan
    // ─────────────────────────────────────────────────────────────────────────
    public function assign_plan()
    {
        $school_name = trim($this->input->post('school_uid',   TRUE) ?? '');
        $plan_id     = trim($this->input->post('plan_id',      TRUE) ?? '');
        $expiry_date = trim($this->input->post('expiry_date',  TRUE) ?? '');

        if (empty($school_name) || empty($plan_id) || empty($expiry_date)) {
            $this->json_error('All fields are required.');
            return;
        }
        // [FIX-3] Validate school_name before Firebase path use
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_name)) {
            $this->json_error('Invalid school identifier.'); return;
        }
        // [FIX-4] Validate plan_id — prevent path injection
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $plan_id)) {
            $this->json_error('Invalid plan identifier.'); return;
        }
        // [FIX-5] Validate expiry_date format and sanity
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date) || strtotime($expiry_date) === false) {
            $this->json_error('Expiry date must be in YYYY-MM-DD format.'); return;
        }
        if (strtotime($expiry_date) < time()) {
            $this->json_error('Expiry date cannot be in the past.'); return;
        }

        // ── Single-source plan assignment (Firestore) ────────────────────
        $svc    = $this->_b23c_registry();
        $tenant = $svc->get_tenant_detail($school_name);
        if (!is_array($tenant)) { $this->json_error('School not found.'); return; }
        $plan   = $svc->get_plan($plan_id);
        if (!is_array($plan))   { $this->json_error('Plan not found.'); return; }
        $plan_name = (string) ($plan['name'] ?? $plan_id);
        $ok = $svc->assign_plan_to_school(
            $school_name, $plan_id, $expiry_date, (string) ($this->sa_id ?? '')
        );
        if (!$ok) { $this->json_error('Failed to assign plan.'); return; }
        $this->sa_log('plan_assigned', $school_name, ['plan_id' => $plan_id, 'expiry' => $expiry_date]);
        $this->json_success(['message' => "Plan '{$plan_name}' assigned. Expires {$expiry_date}."]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/refresh_school_stats
    // ─────────────────────────────────────────────────────────────────────────
    public function refresh_school_stats()
    {
        $school_name = trim($this->input->post('school_uid', TRUE) ?? '');
        if (empty($school_name)) { $this->json_error('School name required.'); return; }
        // [FIX-3] Validate school_name before Firebase path use
        if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_name)) {
            $this->json_error('Invalid school identifier.'); return;
        }

        try {
            $session_root = "Schools/{$school_name}";

            // Student count — Firestore is canonical (R1 migration).
            // Replaces the previous nested RTDB walk over
            // `Schools/{school}/{session}/{class}/{section}/Students/List`.
            // The Firestore `students` collection is school-scoped via the
            // schoolId field (admin's school_name == school_id, "SCH_xxx"),
            // so a single query returns every active student across every
            // class/section without iterating the tree. Side note: the
            // legacy walk summed across sessions, which double-counted any
            // student promoted across sessions; Firestore's count is the
            // canonical "current students" value.
            $total_students = 0;
            try {
                $studentRows = $this->firebase->firestoreQuery('students', [
                    ['schoolId', '==', $school_name],
                ]);
                $total_students = is_array($studentRows) ? count($studentRows) : 0;
            } catch (\Exception $e) {
                log_message('error', 'refresh_school_stats: students Firestore query failed: ' . $e->getMessage());
            }

            // Staff count — Firestore canonical, mirroring the R1 student
            // migration above. The legacy RTDB walk
            // (`Schools/{name}/{session}/Teachers`) no longer exists in the
            // current architecture; staff are written to the Firestore
            // `staff` collection by Entity_firestore_sync and queryable by
            // `schoolId`.
            $total_staff = 0;
            try {
                $staffRows = $this->firebase->firestoreQuery('staff', [
                    ['schoolId', '==', $school_name],
                ]);
                $total_staff = is_array($staffRows) ? count($staffRows) : 0;
            } catch (\Exception $e) {
                log_message('error', 'refresh_school_stats: staff Firestore query failed: ' . $e->getMessage());
            }

            $cacheData = [
                'total_students' => $total_students,
                'total_staff'    => $total_staff,
                'last_updated'   => date('Y-m-d H:i:s'),
            ];

            // ── Single-source stats-cache write (Firestore) ─────────────
            $this->_b23c_registry()->update_stats_cache($school_name, [
                'totalStudents' => $total_students,
                'totalStaff'    => $total_staff,
            ]);

            // M-05 FIX: Audit log for stats refresh
            $this->sa_log('school_stats_refreshed', $school_name, $cacheData);

            $this->json_success(array_merge($cacheData, ['message' => 'Stats refreshed.']));
        } catch (Exception $e) {
            log_message('error', 'SA refresh_school_stats: ' . $e->getMessage());
            $this->json_error('Failed to refresh stats: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/upload_logo
    // Uploads a logo image straight to Firebase Storage and returns its public
    // download URL (no more local /uploads/logos/ + localhost URL staging).
    //
    // Two callers, two cases:
    //   • Onboarding wizard (create.php) posts school_uid='temp_…' — the SCH_
    //     id doesn't exist yet, so the file goes to a temp Storage path
    //     schools/_onboarding_temp/{token}/logos/. onboard() then calls
    //     _promote_temp_logo_to_storage() to move it to schools/{SCH_ID}/logos/.
    //   • Edit page (view.php) posts the real SCH_ id — the file goes straight
    //     to schools/{SCH_ID}/logos/ and logoUrl is persisted immediately.
    // ─────────────────────────────────────────────────────────────────────────
    public function upload_logo()
    {
        $school_uid = trim($this->input->post('school_uid', TRUE) ?? '');
        // Validate school_uid to prevent path injection in the Storage path.
        if ($school_uid !== '' && !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.'); return;
        }

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('No valid file uploaded.'); return;
        }

        $file = $_FILES['logo'];
        if (!is_uploaded_file($file['tmp_name'])) {
            $this->json_error('Invalid upload.'); return;
        }
        $mime = mime_content_type($file['tmp_name']);
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!in_array($mime, $allowed_mimes, true)) {
            $this->json_error('Only JPEG, PNG, GIF, WebP, or SVG images are allowed.'); return;
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            $this->json_error('Logo file must be under 2 MB.'); return;
        }

        $ext           = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'png';
        $is_onboarding = ($school_uid === '' || strpos($school_uid, 'temp') === 0);

        if ($is_onboarding) {
            // No SCH_ id yet — stage under a random temp folder. onboard()
            // promotes this to schools/{SCH_ID}/logos/ on final submit.
            $token      = bin2hex(random_bytes(8));
            $remotePath = 'schools/_onboarding_temp/' . $token . '/logos/logo_' . $token . '.' . $ext;
        } else {
            // Existing school — school_uid IS the SCH_ id (see view()).
            $safe_id    = preg_replace('/[^A-Za-z0-9_\-]/', '_', $school_uid);
            $remotePath = 'schools/' . $safe_id . '/logos/logo_' . time() . '.' . $ext;
        }

        // Upload the PHP temp file directly to Storage — no local-disk staging.
        if (!$this->firebase->uploadFile($file['tmp_name'], $remotePath)) {
            $this->json_error('Failed to upload logo to storage.'); return;
        }
        $logo_url = self::encode_storage_url($this->firebase->getDownloadUrl($remotePath));
        if ($logo_url === '') {
            $this->json_error('Logo uploaded but URL could not be generated.'); return;
        }

        // For an existing school, persist the Storage URL now. For onboarding
        // the URL rides along in the onboard POST and is rewritten on promote.
        if (!$is_onboarding) {
            // ── Single-source logo URL write (Firestore) ─────────────────
            $this->_b23c_registry()->update_school_profile($school_uid, [
                'logoUrl'   => $logo_url,
                'updatedAt' => date('c'),
            ]);
        }

        $this->json_success(['logo_url' => $logo_url, 'message' => 'Logo uploaded successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Initialize default Firebase data for new school
    // ─────────────────────────────────────────────────────────────────────────
    private function _initialize_default_data(string $firebase_key, string $session_year, array $plan_data): void
    {
        try {
            $base = "Schools/{$firebase_key}/{$session_year}";

            foreach (['School Fees', 'Admission Fees', 'Transport Fees', 'Stationery', 'Misc Income'] as $account) {
                $this->firebase->set("{$base}/Accounts/Account_book/{$account}", ['__init' => true]);
            }
            // Write default fee titles to the correct Fees Structure path used by the fee system.
            // The fee chart auto-generator (Fees.php getDefaultFeeChart) reads from this node.
            $feesStructBase = "{$base}/Accounts/Fees/Fees Structure";
            foreach (['Tuition Fee' => '', 'Computer Fee' => '', 'Library Fee' => ''] as $fee => $v) {
                $this->firebase->set("{$feesStructBase}/Monthly/{$fee}", '');
            }
            $this->firebase->set("{$feesStructBase}/Yearly/Annual Fee", '');

            log_message('info', "SA: Default data initialized school={$firebase_key} session={$session_year}");
        } catch (Exception $e) {
            log_message('error', 'SA _initialize_default_data: ' . $e->getMessage());
        }
    }

    /**
     * Canonical SSA login-contract enforcement.
     *
     * Re-reads a freshly-provisioned School Super Admin and verifies it meets
     * the MINIMUM runtime contract that Admin_login::_try_firebase_admin_login
     * requires to grant a session:
     *   1. Firebase Auth user {ssaId}@schoolsync.app exists.
     *   2. custom claim schoolId (camelCase) is present.
     *   3. custom claim role is present.
     *   4. staff/{schoolId}_{ssaId} exists and is non-empty.
     *   5. staff status is login-eligible (not Inactive/Disabled).
     *
     * Tenant subscription + lockout are deliberately NOT checked here — they are
     * tenant-level / transient, not SSA-provisioning properties.
     *
     * Returns [] when fully compliant, or a list of violation codes. Pure read;
     * mutates nothing. Emits ONBOARD_RESULT telemetry + a loud error log on any
     * violation so a provisioning gap surfaces immediately instead of silently
     * succeeding.
     */
    private function _verify_ssa_login_contract(string $schoolId, string $ssaId): array
    {
        $violations = [];

        // 1-3. Auth user + mandatory claims.
        try {
            $u = $this->firebase->getFirebaseUserByEmail(Firebase::authEmail($ssaId));
            if ($u === null) {
                $violations[] = 'auth_user_missing';
            } else {
                $claims = (array) ($u->customClaims ?? []);
                if ((string) ($claims['schoolId'] ?? '') === '') $violations[] = 'claim_schoolId_missing';
                if ((string) ($claims['role']     ?? '') === '') $violations[] = 'claim_role_missing';
            }
        } catch (\Throwable $e) {
            $violations[] = 'auth_lookup_error';
        }

        // 4-5. staff doc existence + login-eligible status.
        try {
            $staff = $this->firebase->firestoreGet('staff', "{$schoolId}_{$ssaId}");
            if (!is_array($staff) || empty($staff)) {
                $violations[] = 'staff_doc_missing';
            } else {
                $status = strtolower(trim((string) ($staff['status'] ?? $staff['Status'] ?? 'Active')));
                if ($status === 'inactive' || $status === 'disabled') $violations[] = 'staff_status_not_login_eligible';
            }
        } catch (\Throwable $e) {
            $violations[] = 'staff_lookup_error';
        }

        if (empty($violations)) {
            $this->_onboard_telem('ONBOARD_RESULT', [
                'result' => 'ssa_contract_ok', 'school_id' => $schoolId, 'admin_id' => $ssaId,
            ]);
        } else {
            $this->_onboard_telem('ONBOARD_RESULT', [
                'result' => 'ssa_contract_violation', 'school_id' => $schoolId,
                'admin_id' => $ssaId, 'missing' => implode(',', $violations),
            ]);
            log_message('error', "SA onboard CONTRACT VIOLATION ssa={$ssaId} school={$schoolId} "
                . "— SSA may not be able to log in: " . implode(', ', $violations));
        }
        return $violations;
    }

    /**
     * B2.3.2-C helper: lazy-load + bind the B2_registry_service. Idempotent.
     */
    private function _b23c_registry()
    {
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        return $this->b2_registry_service;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Normalise a school name to a safe Firebase node key
    // Used for the Indexes/School_names/{nameKey} → school_id uniqueness index.
    // Spaces and special chars → underscores; alphanumeric + _ + - kept as-is.
    // ─────────────────────────────────────────────────────────────────────────
    private function _school_name_key(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', trim($name));
    }

    /**
     * Ensure a Firebase Storage download URL has its object path %2F-encoded.
     * A raw-slash path (…/o/schools/SCH_x/logos/file.jpg) returns HTTP 400 and
     * the <img> silently fails. No-op for already-encoded URLs and non-Storage
     * URLs (external/localhost). Mirrors Firebase::getDownloadUrl's urlencode.
     */
    public static function encode_storage_url(string $url): string
    {
        if ($url === '' || strpos($url, 'firebasestorage.googleapis.com') === false) return $url;
        if (!preg_match('#^(.*/o/)([^?]+)(\?.*)?$#', $url, $m)) return $url;
        $path = $m[2];
        if (strpos($path, '/') === false) return $url; // already encoded (no raw slash)
        $enc = implode('%2F', array_map('rawurlencode', explode('/', $path)));
        return $m[1] . $enc . ($m[3] ?? '');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Rollback partially-written onboarding data
    // ─────────────────────────────────────────────────────────────────────────
    private function _rollback_onboard(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $this->firebase->set($path, null);
            } catch (Exception $e) {
                log_message('error', "SA onboard rollback failed for {$path}: " . $e->getMessage());
            }
        }
    }

    // ── B1: onboarding telemetry (lazy Security_telemetry; best-effort, never throws) ──
    private function _onboard_telem(string $event, array $detail): void
    {
        try {
            if ($this->_sec_telem === null) {
                $this->load->library('security_telemetry', null, 'sec_telem');
                $this->sec_telem->init($this->firebase, 'SA_PANEL', ['uid' => $this->sa_id ?? '', 'role' => 'developer'], '');
                $this->_sec_telem = $this->sec_telem;
            }
            $subjectId = (string) ($detail['school_id'] ?? ($detail['ssa'] ?? ''));
            $this->_sec_telem->emit($event, 'info', $detail, ['type' => 'school', 'id' => $subjectId]);
        } catch (\Throwable $e) {
            log_message('error', 'onboard telem failed: ' . $e->getMessage());
        }
    }

    // ── B1: unified onboarding rollback — undoes Firebase Auth user + id-generator ──
    // claims + RTDB paths in safe order. SSA claim is released only if the Firebase
    // Auth user was deleted (else burned + ONBOARD_ROLLBACK_INCOMPLETE alert).
    private function _onboard_rollback(array $paths, string $reason): void
    {
        if ($this->_ob_fbUid !== null) {
            $deleted = $this->firebase->deleteFirebaseUser($this->_ob_fbUid);
            if ($deleted && $this->_ob_ssaVal !== null) {
                try { $this->id_generator->releaseClaim('SSA', $this->_ob_ssaVal); } catch (\Throwable $e) {}
            } elseif (!$deleted) {
                $this->_onboard_telem('ONBOARD_ROLLBACK_INCOMPLETE', ['uid' => $this->_ob_fbUid, 'reason' => 'fbauth_delete_failed']);
            }
        } elseif ($this->_ob_ssaVal !== null) {
            try { $this->id_generator->releaseClaim('SSA', $this->_ob_ssaVal); } catch (\Throwable $e) {}
        }
        if ($this->_ob_schcodeVal !== null) {
            try { $this->id_generator->releaseClaim('SCHCODE', $this->_ob_schcodeVal); } catch (\Throwable $e) {}
        }
        $this->_rollback_onboard($paths);
        $this->_onboard_telem('ONBOARD_RESULT', ['result' => 'rollback', 'reason' => $reason]);
    }

    /**
     * LOGO-1 (post-B3 2026-06-02; storage-first rework 2026-06-06): promote the
     * onboarding temp logo to its canonical schools/{schoolId}/logos/ path once
     * the schoolId exists, then point logoUrl at it.
     *
     * Current flow: upload_logo() uploads the file straight to Firebase Storage
     * at schools/_onboarding_temp/{token}/logos/ and returns a real download
     * URL (no localhost). This helper COPIES that object to
     * schools/{schoolId}/logos/{basename}, updates BOTH schools/{id}.logoUrl
     * and tenantPublic/{id}.logoUrl, then deletes the temp object.
     *
     * Legacy fallback: if $tempUrl still points at a local /uploads/logos/
     * file (an in-flight onboarding started before this rework), upload that
     * local file to the canonical path and @unlink it.
     *
     * Non-fatal — onboarding never fails on a Storage hiccup. On any failure
     * path the helper logs and returns the original $tempUrl unchanged (the
     * SSA can re-upload via School Config to recover).
     *
     * Scope deliberately limited to the FS-canonical onboard branch (see the
     * B2.3.3 RTDB-retirement note that previously lived here).
     *
     * Returns the canonical Firebase Storage URL on success, or the original
     * $tempUrl on any failure path.
     */
    private function _promote_temp_logo_to_storage(string $schoolId, string $tempUrl): string
    {
        if ($tempUrl === '') return '';

        // ── Primary path: temp logo already in Storage → copy to final path ──
        $tempPath = $this->firebase->storagePathFromUrl($tempUrl);
        if ($tempPath !== '' && strpos($tempPath, 'schools/_onboarding_temp/') === 0) {
            try {
                $basename  = basename($tempPath);
                $finalPath = "schools/{$schoolId}/logos/{$basename}";

                if (!$this->firebase->copyStorageFile($tempPath, $finalPath)) {
                    log_message('error',
                        "LOGO-1 promote: copyStorageFile failed for school={$schoolId}; leaving temp URL.");
                    return $tempUrl;
                }
                $newUrl = $this->firebase->getDownloadUrl($finalPath);
                if ($newUrl === '') {
                    log_message('error',
                        "LOGO-1 promote: getDownloadUrl empty for school={$schoolId}; leaving temp URL.");
                    return $tempUrl;
                }

                $now = date('c');
                $this->firebase->firestoreUpdate('schools', $schoolId, [
                    'logoUrl'       => $newUrl,
                    'logoUpdatedAt' => $now,
                ]);
                $this->firebase->firestoreUpdate('tenantPublic', $schoolId, [
                    'logoUrl'       => $newUrl,
                    'logoUpdatedAt' => $now,
                ]);

                // Best-effort temp cleanup — a leftover temp object is harmless.
                $this->firebase->deleteStorageFile($tempPath);

                log_message('info',
                    "LOGO-1 promote: temp logo moved to {$finalPath} for school={$schoolId} → {$newUrl}");
                return $newUrl;
            } catch (\Throwable $e) {
                log_message('error',
                    "LOGO-1 promote (storage): threw for school={$schoolId}: " . $e->getMessage());
                return $tempUrl;
            }
        }

        // ── Legacy fallback: temp logo still on local disk (/uploads/logos/) ──
        if (strpos($tempUrl, '/uploads/logos/') === false) return $tempUrl;

        $basename  = basename($tempUrl);
        $localPath = FCPATH . 'uploads/logos/' . $basename;
        if (!is_file($localPath) || !is_readable($localPath)) {
            log_message('warning',
                "LOGO-1 promote: temp file missing at {$localPath} for school={$schoolId}; leaving URL as-is so SSA can re-upload via School Config.");
            return $tempUrl;
        }

        try {
            $remotePath = "schools/{$schoolId}/logos/{$basename}";
            if (!$this->firebase->uploadFile($localPath, $remotePath)) {
                log_message('error',
                    "LOGO-1 promote: Firebase Storage uploadFile returned false for school={$schoolId}; leaving URL as-is.");
                return $tempUrl;
            }
            $newUrl = $this->firebase->getDownloadUrl($remotePath);
            if ($newUrl === '') {
                log_message('error',
                    "LOGO-1 promote: getDownloadUrl returned empty for school={$schoolId}; leaving URL as-is.");
                return $tempUrl;
            }

            $now = date('c');
            $this->firebase->firestoreUpdate('schools', $schoolId, [
                'logoUrl'       => $newUrl,
                'logoUpdatedAt' => $now,
            ]);
            $this->firebase->firestoreUpdate('tenantPublic', $schoolId, [
                'logoUrl'       => $newUrl,
                'logoUpdatedAt' => $now,
            ]);

            @unlink($localPath);

            log_message('info',
                "LOGO-1 promote: local temp logo promoted to Firebase Storage for school={$schoolId} → {$newUrl}");
            return $newUrl;
        } catch (\Throwable $e) {
            log_message('error',
                "LOGO-1 promote (legacy): threw for school={$schoolId}: " . $e->getMessage());
            return $tempUrl;
        }
    }
}
