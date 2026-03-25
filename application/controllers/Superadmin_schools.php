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
    public function __construct() { parent::__construct(); }

    // ─────────────────────────────────────────────────────────────────────────
    // GET  /superadmin/schools
    // ─────────────────────────────────────────────────────────────────────────
    public function index()
    {
        $schools = [];
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
        try {
            $raw = $this->firebase->get('System/Plans') ?? [];
            foreach ($raw as $pid => $p) {
                $plans[$pid] = $p['name'] ?? $pid;
            }
        } catch (Exception $e) {}

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

        // Auto-generate school code via Auth API (race-safe sequential)
        $this->load->library('auth_client');
        $school_code = $this->auth_client->generate_id('SCHCODE');
        if (empty($school_code)) {
            $this->json_error('Failed to generate school code. Is the Auth API running?'); return;
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
        try {
            $nameKey        = $this->_school_name_key($name);
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
            $this->_rollback_onboard($rollbackPaths);
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
            $this->_rollback_onboard($rollbackPaths);
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
            $this->_rollback_onboard($rollbackPaths);
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
            $this->_rollback_onboard($rollbackPaths);
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
            $this->_rollback_onboard($rollbackPaths);
            $this->json_error('Failed to write school identifiers. Please try again.'); return;
        }

        // ── 6. Auto-generate SSA ID and create admin in Firebase + MongoDB ──────────
        // SSA ID is auto-generated via Node.js Auth API (race-safe atomic counter).
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
            'schoolDisplayName' => $school_name ?? '',
        ]);

        // If Auth API is unavailable, generate SSA ID locally from Firebase
        if (!empty($ssa_result['adminId'])) {
            $admin_id = $ssa_result['adminId'];
        } else {
            // Fallback: scan existing SSA IDs in Firebase to find next
            $all_schools_admins = $this->firebase->get('Users/Admin') ?? [];
            $max_ssa = 0;
            foreach ($all_schools_admins as $key => $admins) {
                if (!is_array($admins)) continue;
                foreach (array_keys($admins) as $aid) {
                    if (preg_match('/^SSA(\d+)$/', $aid, $m)) {
                        $num = (int) $m[1];
                        if ($num > $max_ssa) $max_ssa = $num;
                    }
                }
            }
            $admin_id = 'SSA' . str_pad($max_ssa + 1, 4, '0', STR_PAD_LEFT);
        }

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
            log_message('error', 'SA onboard: Admin account creation failed — ' . $e->getMessage());
            $this->_rollback_onboard($rollbackPaths);
            $this->json_error('Failed to create admin account. Please try again.'); return;
        }

        // Sync SSA to MongoDB (best-effort) — only if Auth API didn't already create it
        if (empty($ssa_result['adminId'])) {
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
                'schoolDisplayName' => $school_name ?? '',
            ]);
        }

        $this->_initialize_default_data($school_id, $session_yr, $plan_data);

        // ── 8. Persist available sessions list + active session ─────────
        // Login reads Schools/{school_id}/Sessions to populate the session switcher.
        // Without this, login computes session from system date, ignoring the onboarded year.
        try {
            $result = $this->firebase->set("Schools/{$school_id}/Sessions", [$session_yr]);
            if ($result === false) {
                throw new \Exception("Failed to write to Schools/{$school_id}/Sessions");
            }
            $result = $this->firebase->set("Schools/{$school_id}/Config/ActiveSession", $session_yr);
            if ($result === false) {
                throw new \Exception("Failed to write to Schools/{$school_id}/Config/ActiveSession");
            }
        } catch (Exception $e) {
            log_message('error', 'SA onboard: Sessions write failed — ' . $e->getMessage());
        }

        $this->sa_log('school_onboarded', $school_id, [
            'school_name' => $name,
            'school_id'   => $school_id,
            'school_code' => $school_code,
            'admin_id'    => $admin_id,
        ]);
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

        $data = [
            'page_title' => 'School — ' . $school_name,
            'school_uid' => $school_uid,
            'school'     => $school,
            'plans'      => $plans,
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
            $sessionKeys  = $this->firebase->shallow_get($session_root) ?? [];

            $total_students = 0;
            $total_staff    = 0;

            foreach ($sessionKeys as $sessionKey) {
                if (!preg_match('/^\d{4}-/', $sessionKey)) continue;

                $classKeys = $this->firebase->shallow_get("{$session_root}/{$sessionKey}") ?? [];
                foreach ($classKeys as $classKey) {
                    if (strpos($classKey, 'Class ') !== 0) continue;
                    $sectionKeys = $this->firebase->shallow_get("{$session_root}/{$sessionKey}/{$classKey}") ?? [];
                    foreach ($sectionKeys as $sectionKey) {
                        if (strpos($sectionKey, 'Section ') !== 0) continue;
                        $students = $this->firebase->shallow_get("{$session_root}/{$sessionKey}/{$classKey}/{$sectionKey}/Students/List") ?? [];
                        $total_students += count($students);
                    }
                }

                $teachers    = $this->firebase->shallow_get("{$session_root}/{$sessionKey}/Teachers") ?? [];
                $total_staff = max($total_staff, count($teachers));
            }

            $cacheData = [
                'total_students' => $total_students,
                'total_staff'    => $total_staff,
                'last_updated'   => date('Y-m-d H:i:s'),
            ];

            // Write to System/Schools — PRIMARY location
            $this->firebase->update("System/Schools/{$school_name}/stats_cache",  $cacheData);

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
    // Accepts a logo image file upload, saves to uploads/logos/, returns URL
    // ─────────────────────────────────────────────────────────────────────────
    public function upload_logo()
    {
        $school_name = trim($this->input->post('school_uid', TRUE) ?? '');
        // [FIX] Validate school_name to prevent path injection in Firebase write
        if ($school_name !== '' && !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_name)) {
            $this->json_error('Invalid school identifier.'); return;
        }

        if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            $this->json_error('No valid file uploaded.'); return;
        }

        $file = $_FILES['logo'];
        $mime = mime_content_type($file['tmp_name']);
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if (!in_array($mime, $allowed_mimes, true)) {
            $this->json_error('Only JPEG, PNG, GIF, WebP, or SVG images are allowed.'); return;
        }
        if ($file['size'] > 2 * 1024 * 1024) {
            $this->json_error('Logo file must be under 2 MB.'); return;
        }

        $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $label     = $school_name !== '' ? preg_replace('/[^A-Za-z0-9_]/', '_', $school_name) : 'new';
        $safe_name = 'logo_' . $label . '_' . time() . '.' . $ext;
        $upload_dir = FCPATH . 'uploads/logos/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $dest = $upload_dir . $safe_name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            $this->json_error('Failed to save uploaded file.'); return;
        }

        $logo_url = base_url('uploads/logos/' . $safe_name);

        // Do NOT write to Firebase here — the school doesn't exist yet during onboard.
        // The logo_url is returned to the client and included in the onboard POST,
        // which writes it to the correct System/Schools/{school_id}/profile node.
        // For existing schools (edit profile), update_profile() handles the Firebase write.
        if (!empty($school_name) && strpos($school_name, 'temp') !== 0) {
            try {
                $this->firebase->update("System/Schools/{$school_name}/profile", ['logo_url' => $logo_url]);
            } catch (Exception $e) {
                log_message('error', 'SA upload_logo: Firebase update failed — ' . $e->getMessage());
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

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: Generate a unique school ID (SCH_XXXXXX format)
    // ─────────────────────────────────────────────────────────────────────────
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
}
