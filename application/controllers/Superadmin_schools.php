<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_schools
 *
 * Primary data : System/Schools/{school_id}/  where school_id = SCH_XXXXXX
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

        // ── B2.3.2-C: flag-gated single-source schools list ──────────────
        if ($this->_b23c_registry_firestore_on()) {
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

        try {
            // System/Schools is now the PRIMARY location for all school data
            $raw = $this->firebase->get('System/Schools') ?? [];

            foreach ($raw as $name => $schoolData) {
                if (!is_array($schoolData)) continue;

                $sub     = is_array($schoolData['subscription'] ?? null) ? $schoolData['subscription'] : [];
                $cache   = is_array($schoolData['stats_cache']  ?? null) ? $schoolData['stats_cache']  : [];
                $profile = is_array($schoolData['profile']      ?? null) ? $schoolData['profile']      : [];
                $saData  = $profile;

                // Expiry date — supports both old (duration/endDate) and new (expiry_date) format
                $expiry = $sub['expiry_date'] ?? ($sub['duration']['endDate'] ?? '');

                $schools[] = [
                    'uid'          => $name,   // school_id (SCH_XXXXXX) for migrated; school_name for legacy
                    // Prefer school_name data field; fall back to profile/name; last resort: the key itself
                    'name'         => $saData['school_name'] ?? $saData['name'] ?? $name,
                    'city'         => $saData['city']         ?? ($schoolData['city'] ?? ''),
                    'logo_url'     => $saData['logo_url']     ?? '',
                    'domain_id'    => $saData['domain_identifier'] ?? ($saData['subdomain'] ?? ''),
                    'firebase_key' => $name,
                    // Top-level status (SA master switch) takes priority over subscription/status
                    'status'       => strtolower($schoolData['status'] ?? $sub['status'] ?? 'inactive'),
                    'created_at'   => $saData['created_at']  ?? '',
                    'plan_name'    => $sub['plan_name']       ?? '—',
                    'expiry_date'  => $expiry,
                    'sub_status'   => $sub['status']          ?? 'Inactive',
                    'students'     => (int)($cache['total_students'] ?? 0),
                    'staff'        => (int)($cache['total_staff']    ?? 0),
                ];
            }
            usort($schools, fn($a, $b) => strcmp($a['name'], $b['name']));
        } catch (Exception $e) {
            log_message('error', 'SA schools/index: ' . $e->getMessage());
        }

        $data = ['page_title' => 'Manage Schools', 'schools' => $schools];
        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/schools/index',     $data);
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/schools/create
    // ─────────────────────────────────────────────────────────────────────────
    public function create()
    {
        $plans = [];

        // ── B2.3.2-C: flag-gated single-source plan dropdown ─────────────
        if ($this->_b23c_registry_firestore_on()) {
            foreach ($this->_b23c_registry()->list_plans() as $fs) {
                $pid = (string) ($fs['planFamilyId'] ?? '');
                if ($pid !== '') $plans[$pid] = (string) ($fs['name'] ?? $pid);
            }
        } else {
            try {
                $raw = $this->firebase->get('System/Plans') ?? [];
                foreach ($raw as $pid => $p) {
                    $plans[$pid] = $p['name'] ?? $pid;
                }
            } catch (Exception $e) {}
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

        // ── B2.3.2-C: flag-gated single-source uniqueness check ──────────
        if ($this->_b23c_registry_firestore_on()) {
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
            return;
        }

        try {
            $name_taken = false;
            if ($name !== '') {
                $nameKey    = $this->_school_name_key($name);
                $name_taken = !empty($this->firebase->get("Indexes/School_names/{$nameKey}"));
            }
            $code_taken = $code !== '' && !empty($this->firebase->get("Indexes/School_codes/{$code}"));

            $this->json_success([
                'name_taken' => $name_taken,
                'code_taken' => $code_taken,
                'available'  => !$name_taken && !$code_taken,
            ]);
        } catch (Exception $e) {
            $this->json_error('Availability check failed.');
        }
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
        $admin_pass  = (string)($this->input->post('admin_password', FALSE) ?? ''); // raw — no XSS filter on passwords

        // Step 3 — Subscription & Session
        $plan_id    = trim($this->input->post('plan_id',      TRUE) ?? '');
        $expiry     = trim($this->input->post('expiry_date',  TRUE) ?? '');
        $session_yr = trim($this->input->post('session_year', TRUE) ?? '');

        // ── Validation ────────────────────────────────────────────────────────
        if (empty($name) || empty($email) ||
            empty($admin_name) || empty($admin_email) || empty($admin_pass) ||
            empty($plan_id) || empty($expiry) || empty($session_yr)) {
            $this->json_error('All required fields must be filled.'); return;
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
        if (!$this->_b23c_registry_firestore_on() || !$useIdGenSchcode || !$useFsSsa) {
            log_message('error', 'SA onboard BLOCKED — legacy Mongo onboarding path is decommissioned. '
                . 'Required all-Firestore flags: b2.registry_firestore=' . var_export($this->_b23c_registry_firestore_on(), true)
                . ', onboard.schcode_firestore=' . var_export($useIdGenSchcode, true)
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
        if ($this->_b23c_registry_firestore_on()) {
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

            $school_id  = $this->_generate_school_id();
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

        try {
            $existingByName = $this->firebase->get("Indexes/School_names/{$nameKey}");
            if (!empty($existingByName)) {
                $this->json_error("A school named '{$name}' already exists."); return;
            }
            // School code is auto-generated — no need to check availability
        } catch (Exception $e) {
            log_message('error', 'SA onboard: Availability check failed — ' . $e->getMessage());
            $this->json_error('Unable to verify availability. Please try again.'); return;
        }

        // ── Generate unique school_id ─────────────────────────────────────────
        $school_id = $this->_generate_school_id();
        $rollbackPaths = []; // Track paths written for rollback on failure

        $plan_data  = [];
        try {
            $plan_data = $this->firebase->get("System/Plans/{$plan_id}") ?? [];
        } catch (Exception $e) {
            log_message('error', 'SA onboard: Plan fetch failed — ' . $e->getMessage());
        }
        if (empty($plan_data)) {
            $this->json_error("Plan '{$plan_id}' not found. Cannot create school without a valid plan."); return;
        }

        $now        = date('Y-m-d H:i:s');
        $grace_days = (int)($plan_data['grace_days'] ?? 7);
        $grace_end  = date('Y-m-d', strtotime($expiry . " +{$grace_days} days"));
        $hashed_pw  = password_hash($admin_pass, PASSWORD_BCRYPT, ['cost' => 12]);

        // ── 1. Indexes/School_names — name → school_id (uniqueness + reverse lookup) ─
        try {
            $result = $this->firebase->set("Indexes/School_names/{$nameKey}", $school_id);
            if ($result === false) {
                throw new \Exception("Failed to write to Indexes/School_names/{$nameKey}");
            }
            $rollbackPaths[] = "Indexes/School_names/{$nameKey}";
        } catch (Exception $e) {
            log_message('error', 'SA onboard: Indexes/School_names write failed — ' . $e->getMessage());
            $this->_onboard_rollback($rollbackPaths, 'onboard_write_failed');
            $this->json_error('Failed to register school name index. Please try again.'); return;
        }

        // ── 2. Indexes/School_codes/{code} → school_id  (Admin_login fast path) ─
        try {
            $result = $this->firebase->set("Indexes/School_codes/{$school_code}", $school_id);
            if ($result === false) {
                throw new \Exception("Failed to write to Indexes/School_codes/{$school_code}");
            }
            $rollbackPaths[] = "Indexes/School_codes/{$school_code}";
        } catch (Exception $e) {
            log_message('error', 'SA onboard: Indexes/School_codes write failed — ' . $e->getMessage());
            $this->_onboard_rollback($rollbackPaths, 'onboard_write_failed');
            $this->json_error('Failed to register school code index. Please try again.'); return;
        }

        // ── 3. System/Schools/{school_id}/subscription  (PRIMARY subscription data) ─
        try {
            $result = $this->firebase->set("System/Schools/{$school_id}/subscription", [
                'status'      => 'Active',
                'plan_id'     => $plan_id,
                'expiry_date' => $expiry,
                'grace_end'   => $grace_end,
                'plan_name'   => $plan_data['name'] ?? $plan_id,
                'duration'    => ['startDate' => date('Y-m-d'), 'endDate' => $expiry],
                'features'    => array_keys(array_filter($plan_data['modules'] ?? [])),
                'modules'     => $plan_data['modules'] ?? [],
            ]);
            if ($result === false) {
                throw new \Exception("Failed to write to System/Schools/{$school_id}/subscription");
            }
            $rollbackPaths[] = "System/Schools/{$school_id}/subscription";
        } catch (Exception $e) {
            $this->_onboard_rollback($rollbackPaths, 'onboard_write_failed');
            $this->json_error('Failed to create school subscription.'); return;
        }

        // ── 4. System/Schools/{school_id}/profile (THE canonical profile node) ─
        // school_name stored as a DATA FIELD — it is never the Firebase key.
        try {
            $result = $this->firebase->set("System/Schools/{$school_id}/profile", [
                'school_name'       => $name,           // canonical human-readable name (data field)
                'name'              => $name,            // legacy alias — kept for backward compat; readers should prefer school_name
                'school_id'         => $school_id,       // SCH_XXXXXX — self-reference
                'school_code'       => $school_code,     // admin login code
                'city'              => $city,
                'street'            => $street,
                'email'             => $email,
                'phone'             => $phone,
                'logo_url'          => $logo_url,
                'domain_identifier' => strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name)),
                'firebase_key'      => $school_id,       // was school_name — now school_id
                'status'            => 'active',
                'created_at'        => $now,
                'created_by'        => $this->sa_id,
            ]);
            if ($result === false) {
                throw new \Exception("Failed to write to System/Schools/{$school_id}/profile");
            }
            $rollbackPaths[] = "System/Schools/{$school_id}/profile";
        } catch (Exception $e) {
            log_message('error', 'SA onboard: profile write failed — ' . $e->getMessage());
            $this->_onboard_rollback($rollbackPaths, 'onboard_write_failed');
            $this->json_error('Failed to create school profile. Please try again.'); return;
        }

        // ── 5. System/Schools/{school_id} top-level — status + identifiers + stats ─
        try {
            $result = $this->firebase->update("System/Schools/{$school_id}", [
                'status'    => 'active',
                'school_id' => $school_id,
                'School Id' => $school_code,
                'stats_cache' => [
                    'total_students' => 0,
                    'total_staff'    => 0,
                    'last_updated'   => $now,
                ],
            ]);
            if ($result === false) {
                throw new \Exception("Failed to write to System/Schools/{$school_id}");
            }
            $rollbackPaths[] = "System/Schools/{$school_id}";
        } catch (Exception $e) {
            log_message('error', 'SA onboard: top-level identifiers write failed — ' . $e->getMessage());
            $this->_onboard_rollback($rollbackPaths, 'onboard_write_failed');
            $this->json_error('Failed to write school identifiers. Please try again.'); return;
        }

        // ── 6. Auto-generate SSA ID and create admin in Firebase + MongoDB ──────────
        // B1: Firestore Id_generator + Firebase Auth (flag onboard.ssa_firebase);
        // legacy Auth API (Mongo) otherwise. Inert until the flag is ON.
        $ssa_result = [];
        if ($useFsSsa) {
            $this->load->library('id_generator');
            try { $admin_id = $this->id_generator->safeGenerate('SSA'); }
            catch (\Throwable $e) {
                log_message('error', 'SA onboard: Id_generator SSA failed: ' . $e->getMessage());
                $this->_onboard_rollback($rollbackPaths, 'ssa_idgen_failed');
                $this->json_error('Failed to generate admin ID. Please try again.'); return;
            }
            $this->_ob_ssaVal = (int) substr($admin_id, 3);

            // Create the Firebase Auth user (credential authority) — FAIL-CHEAP GATE.
            $authEmail = Firebase::authEmail($admin_id);
            $fbUser = $this->firebase->createFirebaseUser($authEmail, $admin_pass, ['uid' => $admin_id, 'displayName' => $admin_name]);
            if ($fbUser === null) {
                $this->_onboard_telem('ONBOARD_SSA_FBAUTH', ['result' => 'create_failed', 'ssa' => $admin_id]);
                $this->_onboard_rollback($rollbackPaths, 'fbauth_create_failed');
                $this->json_error('Failed to create the admin login account. Please try again.'); return;
            }
            $this->_ob_fbUid = $admin_id;

            // Role claim — retry-then-rollback (2 attempts).
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
                ]);
                if (!$claimOk && $attempt < 2) usleep(200000);
            }
            if (!$claimOk) {
                $this->_onboard_telem('ONBOARD_SSA_FBAUTH', ['result' => 'claims_failed', 'ssa' => $admin_id]);
                $this->_onboard_rollback($rollbackPaths, 'claims_failed');
                $this->json_error('Failed to set admin permissions. Please try again.'); return;
            }
            $this->_onboard_telem('ONBOARD_SSA_FBAUTH', ['result' => 'created', 'ssa' => $admin_id]);
            $this->_onboard_telem('ONBOARD_IDGEN_SOURCE', ['kind' => 'SSA', 'source' => 'firestore']);
        } else {
            // Legacy: Auth API auto-generates the SSA id (+ creates the Firebase Auth user server-side).
            $this->load->library('auth_client');
            $ssa_result = $this->auth_client->sync_admin([
                'adminId'           => '__AUTO_SSA__',
                'name'              => $admin_name,
                'email'             => $admin_email,
                'phone'             => $phone,
                'role'              => 'school_super_admin',
                'roleLabel'         => 'School Super Admin',
                'passwordHash'      => $hashed_pw,
                'schoolId'          => $school_code,
                'schoolCode'        => $school_id,
                'parentDbKey'       => $school_code,
                'createdBy'         => $this->sa_id,
                'schoolDisplayName' => $name,
            ]);
            $this->_onboard_telem('ONBOARD_MONGO_HIT', ['call' => 'sync_admin']);
            if (!empty($ssa_result['adminId'])) {
                $admin_id = $ssa_result['adminId'];
                $this->_onboard_telem('ONBOARD_IDGEN_SOURCE', ['kind' => 'SSA', 'source' => 'mongo']);
            } else {
                $all_schools_admins = $this->firebase->get('Users/Admin') ?? [];
                $max_ssa = 0;
                foreach ($all_schools_admins as $key => $admins) {
                    if (!is_array($admins)) continue;
                    foreach (array_keys($admins) as $aid) {
                        if (preg_match('/^SSA(\d+)$/', $aid, $m)) { $num = (int) $m[1]; if ($num > $max_ssa) $max_ssa = $num; }
                    }
                }
                $admin_id = 'SSA' . str_pad($max_ssa + 1, 4, '0', STR_PAD_LEFT);
                $this->_onboard_telem('ONBOARD_IDGEN_SOURCE', ['kind' => 'SSA', 'source' => 'local_fallback']);
            }
        }

        // ── RTDB Users/Admin write (BOTH paths; Admin_login reads this) ────────
        try {
            $result = $this->firebase->set("Users/Admin/{$school_code}/{$admin_id}", [
                'Status'      => 'Active',
                'Role'        => 'School Super Admin',
                'Name'        => $admin_name,
                'Email'       => $admin_email,
                'Credentials' => [
                    'Id'       => $admin_id,
                    'Password' => $hashed_pw,
                ],
                'Profile'     => [
                    'name'        => $admin_name,
                    'email'       => $admin_email,
                    'phone'       => $phone,
                    'role'        => 'school_super_admin',
                    'school'      => $name,
                    'school_id'   => $school_code,
                    'firebase_id' => $school_id,
                    'created_at'  => $now,
                    'created_by'  => $this->sa_id,
                ],
                'AccessHistory' => [
                    'SA_LastLogin'   => null,
                    'SA_LastLoginIP' => null,
                    'LoginAttempts'  => 0,
                ],
                'Privileges'  => ['accountmanagement' => ''],
            ]);
            if ($result === false) {
                throw new \Exception("Failed to write to Users/Admin/{$school_code}/{$admin_id}");
            }
            $rollbackPaths[] = "Users/Admin/{$school_code}/{$admin_id}";
        } catch (Exception $e) {
            log_message('error', 'SA onboard: Admin account creation failed: ' . $e->getMessage());
            $this->_onboard_rollback($rollbackPaths, 'rtdb_admin_write_failed');
            $this->json_error('Failed to create admin account. Please try again.'); return;
        }

        // Legacy secondary Mongo sync — only when the Auth API path was used and didn't create it.
        if (!$useFsSsa && empty($ssa_result['adminId'])) {
            $this->auth_client->sync_admin([
                'adminId'           => $admin_id,
                'name'              => $admin_name,
                'email'             => $admin_email,
                'phone'             => $phone,
                'role'              => 'school_super_admin',
                'roleLabel'         => 'School Super Admin',
                'passwordHash'      => $hashed_pw,
                'schoolId'          => $school_code,
                'schoolCode'        => $school_id,
                'parentDbKey'       => $school_code,
                'createdBy'         => $this->sa_id,
                'schoolDisplayName' => $name,
            ]);
            $this->_onboard_telem('ONBOARD_MONGO_HIT', ['call' => 'sync_admin_secondary']);
        }

        $this->_initialize_default_data($school_id, $session_yr, $plan_data);

        // ── 8. Persist available sessions list + active session ─────────
        // SC-Step8 (Session Convergence — 2026-06-02): RTDB Sessions +
        // Config/ActiveSession writes RETIRED from the legacy branch too
        // (D1=b — Firestore-first consistency across both onboarding paths).
        // Admin_login + MY_Controller are Firestore-canonical post-SW2 +
        // SC-Step5; no runtime reader of the RTDB session path remains.
        // The standalone Firestore session-seed BELOW stays — the legacy
        // branch does not go through B2 create_tenant, so this write is
        // not redundant with the FS-canonical branch's atomic create.

        // Firestore canonical session seed. Legacy-path tenants that don't
        // have a schools/{id} doc yet will see this update fail closed
        // (logged, non-fatal) — those tenants are RTDB-only by definition
        // and school_config will fall back to legacy read paths. For
        // tenants that DO have a Firestore doc (post-Wave-A backfill),
        // this keeps the canonical source of truth synchronised.
        try {
            $this->firebase->firestoreUpdate('schools', $school_id, [
                'sessions'       => [$session_yr],
                'currentSession' => $session_yr,
                'updatedAt'      => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('error',
                'SA onboard (legacy): schools.sessions / currentSession Firestore write failed (non-fatal): ' . $e->getMessage());
        }

        $this->sa_log('school_onboarded', $school_id, [
            'school_name' => $name,
            'school_id'   => $school_id,
            'school_code' => $school_code,
            'admin_id'    => $admin_id,
        ]);
        $this->_onboard_telem('ONBOARD_RESULT', ['result' => 'success', 'school_id' => $school_id, 'school_code' => $school_code, 'admin_id' => $admin_id]);
        $this->json_success([
            'school_name' => $name,
            'school_id'   => $school_id,
            'school_code' => $school_code,
            'admin_id'    => $admin_id,
            'message'     => "School '{$name}' onboarded successfully. School ID: {$school_id}. SSA Login — School Code: {$school_code}, SSA ID: {$admin_id}.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/schools/view/{name}
    // ─────────────────────────────────────────────────────────────────────────
    public function view($school_uid = '')
    {
        $school_uid  = urldecode(trim($school_uid));
        $school_name = $school_uid; // backward compat alias; replaced below with human name
        if (empty($school_uid)) { redirect('superadmin/schools'); return; }

        // ── B2.3.2-C: flag-gated single-source school detail ─────────────
        if ($this->_b23c_registry_firestore_on()) {
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
                    'street'            => (string) ($schDoc['street']           ?? ''),
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

        try {
            // System/Schools is the PRIMARY and only location for school data
            $schoolData = $this->firebase->get("System/Schools/{$school_uid}") ?? [];
        } catch (Exception $e) {
            redirect('superadmin/schools');
            return;
        }

        if (empty($schoolData)) {
            redirect('superadmin/schools');
            return;
        }

        $sub    = is_array($schoolData['subscription'] ?? null) ? $schoolData['subscription'] : [];
        $cache  = is_array($schoolData['stats_cache']  ?? null) ? $schoolData['stats_cache']  : [];
        $prof   = is_array($schoolData['profile']      ?? null) ? $schoolData['profile']      : [];

        $expiry = $sub['expiry_date'] ?? ($sub['duration']['endDate'] ?? '');

        // Resolve human-readable school name from profile
        $school_name = $prof['school_name'] ?? $prof['name'] ?? $school_uid;

        // Build the unified school array the view expects
        $school = [
            'profile' => [
                'name'              => $school_name,
                'city'              => $prof['city']              ?? ($schoolData['city'] ?? ''),
                'street'            => $prof['street']            ?? '',
                'email'             => $prof['email']             ?? ($schoolData['email'] ?? ''),
                'phone'             => $prof['phone']             ?? ($schoolData['phone'] ?? ''),
                'logo_url'          => $prof['logo_url']          ?? '',
                'school_code'       => $prof['school_code']       ?? ($schoolData['School Id'] ?? ''),
                'domain_identifier' => $prof['domain_identifier'] ?? ($prof['subdomain'] ?? ''),
                'firebase_key'      => $school_uid,
                // Top-level status (SA master switch) takes priority over subscription/status
                'status'            => strtolower($schoolData['status'] ?? $sub['status'] ?? 'inactive'),
                'created_at'        => $prof['created_at']        ?? '',
                'created_by'        => $prof['created_by']        ?? 'SA',
            ],
            'subscription' => [
                'plan_id'     => $sub['plan_id']      ?? '',
                'plan_name'   => $sub['plan_name']    ?? '—',
                'expiry_date' => $expiry,
                'status'      => $sub['status']       ?? 'Inactive',
            ],
            'stats_cache' => [
                'total_students' => (int)($cache['total_students'] ?? 0),
                'total_staff'    => (int)($cache['total_staff']    ?? 0),
                'last_updated'   => $cache['last_updated']          ?? 'Never',
            ],
        ];

        // Plans dropdown
        $plans = [];
        try {
            $raw = $this->firebase->get('System/Plans') ?? [];
            foreach ($raw as $pid => $p) {
                $plans[$pid] = $p['name'] ?? $pid;
            }
        } catch (Exception $e) {}

        // School Super Admins (read from RTDB Users/Admin/{school_code}).
        $ssas = [];
        $school_code_for_lookup = (string) ($school['profile']['school_code'] ?? '');
        if ($school_code_for_lookup !== '') {
            $this->load->library('Ssa_reset', null, 'ssa_reset');
            $ssas = $this->ssa_reset->listSsasInSchool($school_code_for_lookup);
        }

        $data = [
            'page_title' => 'School — ' . $school_name,
            'school_uid' => $school_uid,
            'school'     => $school,
            'plans'      => $plans,
            'ssas'       => $ssas,
        ];

        $this->load->view('superadmin/include/sa_header', $data);
        $this->load->view('superadmin/schools/view',      $data);
        $this->load->view('superadmin/include/sa_footer');
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

        // Map to subscription status values MY_Controller understands
        $sub_status = ($new_status === 'active') ? 'Active' : ucfirst($new_status);

        // ── B2.3.2-C: flag-gated single-source status toggle ─────────────
        if ($this->_b23c_registry_firestore_on()) {
            $svc = $this->_b23c_registry();
            $tenant = $svc->get_tenant_detail($school_name);
            if (!is_array($tenant)) { $this->json_error('School not found.'); return; }
            $ok = $svc->set_admin_disabled($school_name, $new_status, (string) ($this->sa_id ?? ''));
            if (!$ok) { $this->json_error('Failed to update school status.'); return; }
            $this->sa_log('school_status_changed', $school_name, ['new_status' => $new_status]);
            $this->json_success(['message' => "School status updated to '{$new_status}'."]);
            return;
        }

        try {
            // Verify school exists before updating
            $existing = $this->firebase->get("System/Schools/{$school_name}/profile/school_id");
            if (empty($existing)) {
                $this->json_error('School not found.'); return;
            }

            $now = date('Y-m-d H:i:s');
            // 1. Top-level status on System/Schools/{name} — SA master switch
            $this->firebase->update("System/Schools/{$school_name}", [
                'status' => $new_status,
            ]);
            // 2. subscription/status — what MY_Controller live-checks
            $this->firebase->update("System/Schools/{$school_name}/subscription", [
                'status' => $sub_status,
            ]);
            // 3. Canonical profile node status
            $this->firebase->update("System/Schools/{$school_name}/profile", [
                'status'     => $new_status,
                'updated_at' => $now,
                'updated_by' => $this->sa_id,
            ]);
            $this->sa_log('school_status_changed', $school_name, ['new_status' => $new_status]);
            $this->json_success(['message' => "School status updated to '{$new_status}'."]);
        } catch (Exception $e) {
            log_message('error', 'SA toggle_status: ' . $e->getMessage());
            $this->json_error('Failed to update school status.');
        }
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

        $profileData = [
            'city'              => $city,
            'street'            => $street,
            'email'             => $email,
            'phone'             => $phone,
            'logo_url'          => $logo_url,
            'domain_identifier' => $domain_id,
            'updated_at'        => date('Y-m-d H:i:s'),
            'updated_by'        => $this->sa_id,
        ];

        // ── B2.3.2-C: flag-gated single-source profile patch ─────────────
        if ($this->_b23c_registry_firestore_on()) {
            $nowIso = date('c');
            $ok = $this->_b23c_registry()->update_school_profile($school_name, [
                'city'             => $city,
                'street'           => $street,
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
            return;
        }

        try {
            // Write to canonical location (System/Schools is PRIMARY)
            $this->firebase->update("System/Schools/{$school_name}/profile",  $profileData);
            $this->sa_log('school_profile_updated', $school_name);
            $this->json_success(['message' => 'School profile updated.']);
        } catch (Exception $e) {
            $this->json_error('Failed to update profile.');
        }
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

        // Resolve school_code from System/Schools/{uid}/profile/school_code.
        $school_code = (string) ($this->firebase->get("System/Schools/{$school_uid}/profile/school_code") ?? '');
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

        // ── B2.3.2-C: flag-gated single-source plan assignment ───────────
        if ($this->_b23c_registry_firestore_on()) {
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
            return;
        }

        try {
            // Verify school exists before assigning plan
            $existing = $this->firebase->get("System/Schools/{$school_name}/profile/school_id");
            if (empty($existing)) {
                $this->json_error('School not found.'); return;
            }

            $plan = $this->firebase->get("System/Plans/{$plan_id}") ?? [];
            if (empty($plan)) { $this->json_error('Plan not found.'); return; }

            $grace_days = (int)($plan['grace_days'] ?? 7);
            $grace_end  = date('Y-m-d', strtotime($expiry_date . " +{$grace_days} days"));
            $plan_name  = $plan['name'] ?? $plan_id;

            // Update System/Schools — PRIMARY location
            $this->firebase->update("System/Schools/{$school_name}/subscription", [
                'status'      => 'Active',
                'plan_id'     => $plan_id,
                'plan_name'   => $plan_name,
                'expiry_date' => $expiry_date,
                'grace_end'   => $grace_end,
                'duration'    => ['endDate' => $expiry_date, 'startDate' => date('Y-m-d')],
                'features'    => array_keys(array_filter($plan['modules'] ?? [])),
                'modules'     => $plan['modules'] ?? [],
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            // Sync top-level status field to active whenever a plan is (re-)assigned
            $this->firebase->update("System/Schools/{$school_name}", ['status' => 'active']);

            $this->sa_log('plan_assigned', $school_name, ['plan_id' => $plan_id, 'expiry' => $expiry_date]);
            $this->json_success(['message' => "Plan '{$plan_name}' assigned. Expires {$expiry_date}."]);
        } catch (Exception $e) {
            log_message('error', 'SA assign_plan: ' . $e->getMessage());
            $this->json_error('Failed to assign plan.');
        }
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

            // ── B2.3.2-C: flag-gated single-source stats-cache write ─────
            if ($this->_b23c_registry_firestore_on()) {
                $this->_b23c_registry()->update_stats_cache($school_name, [
                    'totalStudents' => $total_students,
                    'totalStaff'    => $total_staff,
                ]);
            } else {
                // Write to System/Schools — PRIMARY location
                $this->firebase->update("System/Schools/{$school_name}/stats_cache",  $cacheData);
            }

            // M-05 FIX: Audit log for stats refresh
            $this->sa_log('school_stats_refreshed', $school_name, $cacheData);

            $this->json_success(array_merge($cacheData, ['message' => 'Stats refreshed.']));
        } catch (Exception $e) {
            log_message('error', 'SA refresh_school_stats: ' . $e->getMessage());
            $this->json_error('Failed to refresh stats: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/migrate_existing
    //
    // Phase 1 — Registry migration (idempotent, safe to re-run):
    //   For every school keyed by school_name, generate a SCH_XXXXXX school_id
    //   and re-write all registry nodes using school_id as the key.
    //   Also copies Schools/{name}/Sessions → Schools/{school_id}/Sessions so
    //   Admin_login works immediately after Phase 1.
    //
    // Phase 2 (academic data) must be done separately via migrate_academic_data
    // because the full Schools tree may be too large for a single HTTP request.
    //
    // Pass dry_run=1 to preview without writing.
    // Only developer/superadmin roles may run this.
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * LEGACY MIGRATION ONLY — This method is for migrating pre-existing schools
     * from the old architecture (Users/Schools as primary) to the new architecture
     * (System/Schools as primary, Indexes/* for lookups). Not needed for new deployments.
     */
    public function migrate_existing_schools()
    {
        if (!in_array($this->sa_role, ['developer', 'superadmin'], true)) {
            $this->json_error('Insufficient privileges for schema migration.', 403); return;
        }

        $dry_run = (bool)$this->input->post('dry_run', TRUE);
        $now     = date('Y-m-d H:i:s');

        try {
            // Shallow reads — avoids pulling entire data trees into memory
            $registryKeys = array_keys((array)($this->firebase->shallow_get('Users/Schools') ?? []));  // read OLD location
            $academicKeys = array_keys((array)($this->firebase->shallow_get('Schools')       ?? []));
            $schoolIds    = (array)($this->firebase->get('School_ids')                       ?? []);  // read OLD index

            // Build reverse lookup: old_school_name → school_code
            // (only for School_ids entries that still point to a name, not SCH_...)
            $nameToCode = [];
            foreach ($schoolIds as $code => $val) {
                if (is_string($val) && strpos($val, 'SCH_') !== 0) {
                    $nameToCode[trim($val)] = (string)$code;
                }
            }

            // Collect all unique school_name keys (not yet migrated)
            $toMigrate = [];
            foreach (array_merge($registryKeys, $academicKeys) as $key) {
                if (!is_string($key) || $key === '' || strpos($key, 'SCH_') === 0) continue;
                if (!in_array($key, $toMigrate, true)) $toMigrate[] = $key;
            }

            $results = ['migrated' => [], 'skipped' => [], 'errors' => [], 'dry_run' => $dry_run];

            foreach ($toMigrate as $schoolName) {
                if (!preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $schoolName)) {
                    $results['skipped'][] = ['name' => $schoolName, 'reason' => 'invalid_chars']; continue;
                }

                try {
                    // Check if already migrated via School_names index
                    $nameKey    = $this->_school_name_key($schoolName);
                    $existingId = $this->firebase->get("Indexes/School_names/{$nameKey}");
                    if (!empty($existingId) && strpos((string)$existingId, 'SCH_') === 0) {
                        $results['skipped'][] = [
                            'name' => $schoolName, 'school_id' => $existingId, 'reason' => 'already_migrated',
                        ]; continue;
                    }

                    // Generate school_id
                    $school_id = $this->_generate_school_id();

                    // Resolve school_code from multiple old sources
                    $rawRegistry = (array)($this->firebase->get("Users/Schools/{$schoolName}") ?? []);  // read OLD location
                    $rawProfile  = is_array($rawRegistry['profile'] ?? null) ? $rawRegistry['profile'] : [];
                    $schoolCode  = $nameToCode[$schoolName]
                                ?? $rawProfile['school_code']
                                ?? ($rawRegistry['School Id'] ?? null);

                    if (!$schoolCode) {
                        $rawAcademic = (array)($this->firebase->get("Schools/{$schoolName}") ?? []);
                        $schoolCode  = $rawAcademic['School Id'] ?? $rawAcademic['school_code'] ?? null;
                    }
                    if (!$schoolCode) {
                        // Auto-generate: first 3 alpha chars of name + 5-digit random
                        $prefix     = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $schoolName), 0, 3)) ?: 'SCH';
                        $schoolCode = $prefix . str_pad(mt_rand(10000, 99999), 5, '0', STR_PAD_LEFT);
                    }
                    $schoolCode = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $schoolCode), 0, 10));

                    if (!$dry_run) {
                        // ── 1. Migrate Users/Schools/{name} → System/Schools/{school_id} ──
                        if (!empty($rawRegistry)) {
                            $rawRegistry['profile'] = array_merge($rawProfile, [
                                'school_name'    => $rawProfile['school_name'] ?? $rawProfile['name'] ?? $schoolName,
                                'name'           => $rawProfile['name'] ?? $schoolName,
                                'school_id'      => $school_id,
                                'school_code'    => $schoolCode,
                                'firebase_key'   => $school_id,
                                'migrated_from'  => $schoolName,
                                'migrated_at'    => $now,
                            ]);
                            $rawRegistry['school_id'] = $school_id;
                            $rawRegistry['School Id'] = $schoolCode;
                            $this->firebase->set("System/Schools/{$school_id}", $rawRegistry);
                        } else {
                            // No registry entry — create a minimal one
                            $this->firebase->set("System/Schools/{$school_id}", [
                                'status'       => 'active',
                                'school_id'    => $school_id,
                                'School Id'    => $schoolCode,
                                'profile'      => [
                                    'school_name'   => $schoolName,
                                    'name'          => $schoolName,
                                    'school_id'     => $school_id,
                                    'school_code'   => $schoolCode,
                                    'firebase_key'  => $school_id,
                                    'migrated_from' => $schoolName,
                                    'migrated_at'   => $now,
                                ],
                                'subscription' => ['status' => 'Active', 'plan_name' => 'Legacy'],
                                'stats_cache'  => ['total_students' => 0, 'total_staff' => 0, 'last_updated' => $now],
                            ]);
                        }

                        // ── 2. Copy System/Schools/{name} → System/Schools/{school_id} ──
                        $rawSystem = (array)($this->firebase->get("System/Schools/{$schoolName}") ?? []);
                        if (!empty($rawSystem)) {
                            $rawSysProf = is_array($rawSystem['profile'] ?? null) ? $rawSystem['profile'] : [];
                            $rawSystem['profile'] = array_merge($rawSysProf, [
                                'school_id'     => $school_id,
                                'firebase_key'  => $school_id,
                                'migrated_from' => $schoolName,
                            ]);
                            $this->firebase->set("System/Schools/{$school_id}", $rawSystem);
                        }

                        // ── 3. Write Indexes/School_codes/{code} → school_id ────────
                        $this->firebase->set("Indexes/School_codes/{$schoolCode}", $school_id);

                        // ── 4. Write Indexes/School_names/{nameKey} → school_id ─────
                        $this->firebase->set("Indexes/School_names/{$nameKey}", $school_id);

                        // ── 5. Copy Sessions → new path (critical for Admin_login) ────
                        $sessions = $this->firebase->get("Schools/{$schoolName}/Sessions");
                        if (!empty($sessions)) {
                            $this->firebase->set("Schools/{$school_id}/Sessions", $sessions);
                        }
                    }

                    $results['migrated'][] = [
                        'name'        => $schoolName,
                        'school_id'   => $school_id,
                        'school_code' => $schoolCode,
                        'action'      => $dry_run ? 'would_migrate' : 'registry_migrated',
                        'next_step'   => $dry_run ? '' : "POST superadmin/schools/migrate_academic with school_uid={$school_id} to copy academic data.",
                    ];

                } catch (Exception $inner) {
                    $results['errors'][] = ['name' => $schoolName, 'error' => $inner->getMessage()];
                }
            }

            if (!$dry_run && !empty($results['migrated'])) {
                $this->sa_log('schools_migrated_phase1', '', [
                    'count'   => count($results['migrated']),
                    'schools' => array_column($results['migrated'], 'name'),
                ]);
            }

            $this->json_success(array_merge($results, [
                'summary' => sprintf(
                    'Phase 1: %d migrated, %d skipped, %d errors. %s',
                    count($results['migrated']),
                    count($results['skipped']),
                    count($results['errors']),
                    $dry_run ? '(dry run — no changes written)' :
                        'Registry done. Run migrate_academic per school to copy full academic tree.'
                ),
            ]));

        } catch (Exception $e) {
            log_message('error', 'SA migrate_existing_schools: ' . $e->getMessage());
            $this->json_error('Migration failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST  /superadmin/schools/migrate_academic
    //
    // Phase 2 — copies Schools/{old_school_name} (full academic tree) to
    // Schools/{school_id}.  Run once per school after migrate_existing_schools.
    // The school_id and original name are looked up from the registry.
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * LEGACY MIGRATION ONLY — Phase 2 academic data migration. Not needed for new deployments.
     */
    public function migrate_academic_data()
    {
        if (!in_array($this->sa_role, ['developer', 'superadmin'], true)) {
            $this->json_error('Insufficient privileges.', 403); return;
        }

        $school_id = trim($this->input->post('school_uid', TRUE) ?? '');
        if (empty($school_id) || strpos($school_id, 'SCH_') !== 0) {
            $this->json_error('Provide the school_id (SCH_XXXXXX) of an already Phase-1-migrated school.'); return;
        }

        try {
            $profile     = $this->firebase->get("System/Schools/{$school_id}/profile") ?? [];
            $sourceName  = $profile['migrated_from'] ?? '';
            if (empty($sourceName)) {
                $this->json_error("No migrated_from field found for {$school_id}. Was Phase 1 run?"); return;
            }

            // Read the full academic tree (may be large — PHP memory limit applies)
            $academicData = $this->firebase->get("Schools/{$sourceName}");
            if (empty($academicData) || !is_array($academicData)) {
                $this->json_error("No academic data found at Schools/{$sourceName}."); return;
            }

            // Write to new path
            $this->firebase->set("Schools/{$school_id}", $academicData);

            $this->sa_log('schools_migrated_phase2', $school_id, [
                'source' => $sourceName,
                'dest'   => $school_id,
            ]);
            $this->json_success([
                'message' => "Academic data copied from Schools/{$sourceName} to Schools/{$school_id}.",
                'note'    => "Old node Schools/{$sourceName} kept intact. Delete it manually after verifying.",
            ]);
        } catch (Exception $e) {
            log_message('error', 'SA migrate_academic_data: ' . $e->getMessage());
            $this->json_error('Academic migration failed: ' . $e->getMessage());
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
        $logo_url = $this->firebase->getDownloadUrl($remotePath);
        if ($logo_url === '') {
            $this->json_error('Logo uploaded but URL could not be generated.'); return;
        }

        // For an existing school, persist the Storage URL now. For onboarding
        // the URL rides along in the onboard POST and is rewritten on promote.
        if (!$is_onboarding) {
            // ── B2.3.2-C: flag-gated single-source logo URL write ────────
            if ($this->_b23c_registry_firestore_on()) {
                $this->_b23c_registry()->update_school_profile($school_uid, [
                    'logoUrl'   => $logo_url,
                    'updatedAt' => date('c'),
                ]);
            } else {
                try {
                    $this->firebase->update("System/Schools/{$school_uid}/profile", ['logo_url' => $logo_url]);
                } catch (Exception $e) {
                    log_message('error', 'SA upload_logo: Firebase update failed — ' . $e->getMessage());
                }
            }
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

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Generate a unique school ID (SCH_XXXXXX format)
    // ─────────────────────────────────────────────────────────────────────────
    /**
     * B2.3.2-C: single-source check for the b2.registry_firestore atomic
     * co-cutover flag. Cached per-request via static. Returns FALSE during
     * the build phase. Same contract as the helpers in MY_Controller,
     * Admin_login, and Superadmin_plans.
     */
    private function _b23c_registry_firestore_on(): bool
    {
        static $cached = null;
        if ($cached === null) {
            $this->config->load('b2_migration_flags', FALSE, TRUE);
            $flags  = $this->config->item('b2_migration_flags') ?: [];
            $cached = !empty($flags['b2.registry_firestore']);
        }
        return $cached;
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

    private function _generate_school_id(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = 'SCH_' . strtoupper(bin2hex(random_bytes(5)));
            // Collision check
            $existing = $this->firebase->get("System/Schools/{$id}");
            if (!empty($existing)) continue;

            // Claim the ID by writing a placeholder
            $this->firebase->set("System/Schools/{$id}/_claim", [
                'claimed_at' => date('c'),
                'claimed_by' => $this->sa_id ?? 'system',
            ]);

            // Verify we own the claim (guards against concurrent requests)
            $verify = $this->firebase->get("System/Schools/{$id}/_claim/claimed_by");
            if ($verify === ($this->sa_id ?? 'system')) {
                return $id;
            }
        }
        // Extreme fallback: timestamp-based ID (guaranteed unique within ms)
        return 'SCH_' . strtoupper(dechex(time())) . strtoupper(bin2hex(random_bytes(2)));
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
