<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AdminUsers - ERP Identity & Access Management (IAM)
 *
 * Central administrator management for each school tenant.
 * Manages admin accounts, RBAC roles/permissions, and login audit logs.
 *
 * Storage paths:
 *   Firestore  schools/{schoolId}.roles[roleName]   - role permission sets (canonical authority)
 *   Firestore  admins/{adminId}                      - admin user profiles
 *   RTDB       Users/Admin/{school_code}/{adminId}   - legacy mirror (audit + access history)
 *
 * Admin record schema (matches onboarding + login validator):
 *   Status        : 'Active' | 'Disabled'
 *   Role          : top-level role string (Admin_login reads this)
 *   Name          : top-level name string (Admin_login reads this)
 *   Credentials   : { Password: '<bcrypt>' }
 *   Profile       : { name, email, phone, role, school, school_id, firebase_id, created_at, createdBy }
 *   AccessHistory : { LastLogin, LoginIP, LoginAttempts, LockedUntil, IsLoggedIn }
 */
class AdminUsers extends MY_Controller
{
    /* -- Default permission sets (seeded on first use) ------------- */
    /* 13-role hierarchy (Super Admin handled separately via Superadmin_login) */
    private const DEFAULT_ROLES = [
        // ── Tier 1: Full Access ──────────────────────────────────────────
        'Admin' => [
            'label'       => 'School Admin',
            'description' => 'Full school-level access, all modules',
            'permissions' => ['SIS','Fees','Accounting','Attendance','Examinations','Results',
                              'LMS','Certificates','HR','Events','Communication','Operations',
                              'Academic','Reports','Configuration','Admin Users','Stories'],
            'is_system'   => true,
            'tier'        => 1,
            'sort_order'  => 1,
        ],

        // ── Tier 2: Leadership ───────────────────────────────────────────
        'Principal' => [
            'label'       => 'Principal',
            'description' => 'Academic oversight, approvals, reports (no accounting)',
            'permissions' => ['SIS','Attendance','Examinations','Results','LMS','Certificates',
                              'Academic','Reports','Events','Communication','Stories','Configuration'],
            'is_system'   => true,
            'tier'        => 2,
            'sort_order'  => 2,
        ],
        'Vice Principal' => [
            'label'       => 'Vice Principal',
            'description' => 'Academic oversight, limited approvals (no config)',
            'permissions' => ['SIS','Attendance','Examinations','Results','LMS','Certificates',
                              'Academic','Reports','Events','Communication','Stories'],
            'is_system'   => true,
            'tier'        => 2,
            'sort_order'  => 3,
        ],

        // ── Tier 3: Department Heads ─────────────────────────────────────
        'Academic Coordinator' => [
            'label'       => 'Academic Coordinator',
            'description' => 'Classes, exams, results, timetable, homework',
            'permissions' => ['SIS','Attendance','Examinations','Results','LMS',
                              'Academic','Reports','Stories'],
            'is_system'   => true,
            'tier'        => 3,
            'sort_order'  => 4,
        ],
        'HR Manager' => [
            'label'       => 'HR Manager',
            'description' => 'Staff, payroll, leaves, recruitment, appraisals',
            'permissions' => ['HR','Attendance','Reports'],
            'is_system'   => true,
            'tier'        => 3,
            'sort_order'  => 5,
        ],
        'Accountant' => [
            'label'       => 'Accountant',
            'description' => 'Fees, accounting, ledgers, bank recon, reports',
            'permissions' => ['Fees','Accounting','Reports'],
            'is_system'   => true,
            'tier'        => 3,
            'sort_order'  => 6,
        ],

        // ── Tier 4: Operational Staff ────────────────────────────────────
        'Front Office' => [
            'label'       => 'Front Office / Receptionist',
            'description' => 'Admissions CRM, visitor log, communication, certificates',
            'permissions' => ['SIS','Communication','Certificates','Events','Stories'],
            'is_system'   => true,
            'tier'        => 4,
            'sort_order'  => 7,
        ],
        'Class Teacher' => [
            'label'       => 'Class Teacher',
            'description' => 'Teacher + section-level reports, parent communication, red flags',
            'permissions' => ['SIS','Attendance','Examinations','Results','LMS',
                              'Stories','Communication','Reports','Events'],
            'is_system'   => true,
            'tier'        => 4,
            'sort_order'  => 8,
        ],
        'Teacher' => [
            'label'       => 'Teacher',
            'description' => 'Own class attendance, homework, marks, stories, messages',
            'permissions' => ['Attendance','Examinations','Results','LMS',
                              'Stories','Communication'],
            'is_system'   => true,
            'tier'        => 4,
            'sort_order'  => 9,
        ],

        // ── Tier 5: Specialist Roles ─────────────────────────────────────
        'Librarian' => [
            'label'       => 'Librarian',
            'description' => 'Library module only',
            'permissions' => ['Library'],
            'is_system'   => true,
            'tier'        => 5,
            'sort_order'  => 10,
        ],
        'Transport Manager' => [
            'label'       => 'Transport Manager',
            'description' => 'Transport, routes, vehicles, GPS tracking',
            'permissions' => ['Transport','Reports'],
            'is_system'   => true,
            'tier'        => 5,
            'sort_order'  => 11,
        ],
        'Hostel Warden' => [
            'label'       => 'Hostel Warden',
            'description' => 'Hostel allocation, hostel attendance',
            'permissions' => ['Hostel'],
            'is_system'   => true,
            'tier'        => 5,
            'sort_order'  => 12,
        ],

        // ── Tier 6: Minimal Access ───────────────────────────────────────
        'Staff' => [
            'label'       => 'Staff',
            'description' => 'View-only access, no module permissions',
            'permissions' => [],
            'is_system'   => true,
            'tier'        => 6,
            'sort_order'  => 13,
        ],
    ];

    /* -- All available modules for permission assignment ----------- */
    private const AVAILABLE_MODULES = [
        'SIS','Fees','Accounting','Attendance','Examinations','Results',
        'LMS','Certificates','HR','Events','Communication','Operations',
        // Operations sub-modules (grantable individually; 'Operations' = all).
        'Library','Transport','Hostel','Inventory','Assets',
        'Academic','Reports','Configuration','Admin Users','Stories','Homework',
    ];

    /* -- Login audit lives in security_events (Wave C). These are the
          admin-login event types the Login Activity tab surfaces. -------- */
    private const LOGIN_EVENTS = [
        'ADMIN_LOGIN_SUCCESS', 'ADMIN_LOGIN_FAILED', 'ADMIN_LOGIN_LOCKED',
    ];

    public function __construct()
    {
        parent::__construct();

        // change_my_password is the forced-set-new-password screen. The
        // MY_Controller gate redirects EVERY flagged user here regardless of
        // their role, so it must be reachable without the 'Admin Users' module
        // permission — otherwise non-admin roles (Teacher, Accountant, Front
        // Office, …) get bounced to admin/index, which redirects back here →
        // infinite redirect loop. Every other AdminUsers endpoint stays gated.
        if (strtolower($this->router->fetch_method()) !== 'change_my_password') {
            require_permission('Admin Users');
        }
    }

    /**
     * Process pending Firebase Auth syncs for admins created while Auth API was down.
     * Runs on every AdminUsers page load. Non-blocking.
     */
    private function _process_pending_syncs(): void
    {
        try {
            // M4: SCOPE the query to THIS school. Previously it read every
            // school's pending docs and relied on a broken filter (compared the
            // `schoolCode` field against `$this->school_name`, which is the SCH_
            // id — never equal), and then minted claims that fell back to the
            // CURRENT session's tenant — a cross-tenant claim-poisoning path.
            $pendingDocs = $this->fs->where('systemPendingSyncAdmins', [['schoolId', '==', $this->school_id]]);
            if (empty($pendingDocs)) return;

            $synced = 0;
            foreach ($pendingDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $data    = $doc['data'];
                $adminId = $d['id'] ?? ($data['id'] ?? '');
                // Belt-and-braces: never act on a doc that isn't explicitly this
                // tenant's, and never fabricate its tenant from the session.
                if ($adminId === '' || ($data['schoolId'] ?? '') !== $this->school_id) continue;

                $authEmail = Firebase::authEmail($adminId);
                $created   = $this->firebase->createFirebaseUser($authEmail, 'TempSync_' . bin2hex(random_bytes(8)), [
                    'uid'         => $adminId,
                    'displayName' => $data['name'] ?? '',
                ]);

                if ($created !== null && $created !== false) {
                    $this->firebase->setCanonicalClaims($adminId, [
                        'role'          => $data['role'] ?? $data['roleLabel'] ?? '',
                        'role_fallback' => 'Admin',
                        'role_label'    => $data['roleLabel'] ?? $data['role'] ?? '',
                        // Authoritative — the scoped query already proved tenancy.
                        'school_id'     => $this->school_id,
                        'school_code'   => $this->school_code,
                        'parent_db_key' => $this->parent_db_key,
                    ]);
                    $this->fs->remove('systemPendingSyncAdmins', $adminId);
                    $synced++;
                    log_message('info', "PendingSync: admin {$adminId} synced to Firebase Auth successfully");
                }
            }

            if ($synced > 0) {
                log_audit('AdminUsers', 'pending_sync', '', "Auto-synced {$synced} pending admin(s) to Firebase Auth");
            }
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::_process_pending_syncs — ' . $e->getMessage());
        }
    }

    /**
     * Normalize a raw Firebase admin record into the flat format the view expects.
     * Handles both top-level keys (Role, Name, Status) and nested Profile/ keys.
     */
    private function _normalize_admin(string $aid, array $a): array
    {
        $created = $a['Profile']['created_at'] ?? '';
        return [
            'adminId'   => $aid,
            'name'      => $a['Name'] ?? $a['Profile']['name'] ?? '',
            'email'     => $a['Profile']['email'] ?? '',
            'phone'     => $a['Profile']['phone'] ?? '',
            'role'      => $a['Role'] ?? $a['Profile']['role'] ?? '',
            'status'    => strtolower($a['Status'] ?? 'Active'),
            'createdAt' => is_numeric($created) ? date('Y-m-d', (int)$created) : (string)$created,
            'lastLogin' => $a['AccessHistory']['LastLogin'] ?? '',
        ];
    }

    /**
     * TRUE if $role (a role LABEL, as carried in the admin claim, or a ROLE_* id)
     * exists in the school's unified role catalogue (schools.staffRoles).
     */
    private function _unified_role_exists($schoolDoc, string $role): bool
    {
        if ($role === '' || !is_array($schoolDoc)) return false;
        $roles = is_array($schoolDoc['staffRoles'] ?? null) ? $schoolDoc['staffRoles'] : [];
        foreach ($roles as $rid => $r) {
            if (!is_array($r)) continue;
            if ((string) ($r['label'] ?? '') === $role || (string) $rid === $role) return true;
        }
        // Back-compat: a not-yet-folded tenant may still only have the legacy map.
        $legacy = is_array($schoolDoc['roles'] ?? null) ? $schoolDoc['roles'] : [];
        return isset($legacy[$role]);
    }

    /** The unified-catalogue role entry matching a label (or ROLE_* id), or null. */
    private function _role_entry($schoolDoc, string $label): ?array
    {
        if (!is_array($schoolDoc)) return null;
        $roles = is_array($schoolDoc['staffRoles'] ?? null) ? $schoolDoc['staffRoles'] : [];
        foreach ($roles as $rid => $r) {
            if (is_array($r) && ((string) ($r['label'] ?? '') === $label || (string) $rid === $label)) return $r;
        }
        return null;
    }

    /** Privilege tier for a role label (1 = highest; unknown → 7 = lowest). */
    private function _role_tier($schoolDoc, string $label): int
    {
        $e = $this->_role_entry($schoolDoc, $label);
        return $e ? (int) ($e['tier'] ?? 7) : 7;
    }

    /**
     * Gate a role BEFORE it is assigned to an admin (create/update). Returns an
     * error string to reject with, or null if the assignment is allowed.
     *   • Reserved bypass labels ('Super Admin'/'School Super Admin') are never
     *     assignable here; 'Admin' only as the genuine seeded system role.
     *   • Privilege ceiling: a non-(Super/School-Super) caller may not grant a
     *     role more privileged (lower tier) than their own.
     */
    private function _role_assignment_error(array $schoolDoc, string $role): ?string
    {
        $lower = strtolower($role);
        $entry = $this->_role_entry($schoolDoc, $role);

        if (in_array($lower, ['super admin', 'school super admin', 'admin'], true)) {
            if ($lower !== 'admin') {
                return "The role '{$role}' cannot be assigned from here.";
            }
            if (empty($entry['is_system'])) {
                return "A custom role named '{$role}' cannot be assigned.";
            }
        }

        $callerRole = (string) ($this->admin_role ?? '');
        $callerIsSuper = strcasecmp($callerRole, 'Super Admin') === 0
                      || strcasecmp($callerRole, 'School Super Admin') === 0;
        if (!$callerIsSuper
            && $this->_role_tier($schoolDoc, $role) < $this->_role_tier($schoolDoc, $callerRole)) {
            return 'You cannot assign a role with higher privileges than your own.';
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // GET / POST  /admin_users/change_my_password
    //
    // Self-service password change. Used by:
    //   1. The forced-change gate after an admin-driven reset (must_change_password=true).
    //   2. Any logged-in admin who wants to change their own password voluntarily.
    //
    // GET renders the form; POST validates + writes Firebase Auth + clears the claim
    // and the CI session flag.
    // -------------------------------------------------------------------------

    public function change_my_password(): void
    {
        if (empty($this->admin_id)) {
            redirect('admin_login');
            return;
        }

        if ($this->input->method() !== 'post') {
            $data = [
                'page_title'         => 'Set New Password',
                'must_change'        => (bool) $this->session->userdata('must_change_password'),
                'admin_id'           => $this->admin_id,
                'admin_name'         => $this->session->userdata('admin_name'),
                // CI cookie-CSRF is ON for this route; the standalone view posts
                // via fetch() so it must carry the token itself.
                'csrf_name'          => $this->security->get_csrf_token_name(),
                'csrf_hash'          => $this->security->get_csrf_hash(),
            ];
            $this->load->view('admin_users/change_my_password', $data);
            return;
        }

        $new_password     = (string) $this->input->post('new_password', FALSE);
        $confirm_password = (string) $this->input->post('confirm_password', FALSE);

        if ($new_password === '' || $confirm_password === '') {
            $this->json_error('New password and confirmation are required.');
            return;
        }
        if ($new_password !== $confirm_password) {
            $this->json_error('Passwords do not match.');
            return;
        }
        if (strlen($new_password) < 8 || strlen($new_password) > 72) {
            $this->json_error('Password must be 8–72 characters.');
            return;
        }
        if (!preg_match('/[A-Z]/', $new_password)
            || !preg_match('/[a-z]/', $new_password)
            || !preg_match('/[0-9]/', $new_password)) {
            $this->json_error('Password must contain an uppercase letter, a lowercase letter, and a digit.');
            return;
        }

        // M3: a VOLUNTARY self-change (not the forced first-login flow) must
        // re-authenticate with the current password — otherwise a hijacked
        // session (stolen cookie / XSS with the CSRF token) could silently take
        // over the account. The forced flow already proved identity at login.
        if (!$this->session->userdata('must_change_password')) {
            $current = (string) $this->input->post('current_password', FALSE);
            if ($current === '') {
                $this->json_error('Your current password is required.');
                return;
            }
            $verify = $this->firebase->verifyEmailPassword(Firebase::authEmail($this->admin_id), $current);
            if (empty($verify['ok'])) {
                // Distinguish a wrong password from an unreachable auth service so
                // a network blip doesn't read as "wrong password".
                $msg = (($verify['reason'] ?? '') === 'network')
                    ? 'Could not verify your current password right now. Please try again.'
                    : 'Your current password is incorrect.';
                $this->json_error($msg);
                return;
            }
        }

        try {
            $updated = $this->firebase->updateFirebaseUser($this->admin_id, ['password' => $new_password]);
            if ($updated === null) {
                $this->json_error('Failed to update password in Firebase Auth.');
                return;
            }

            // Clear the must-change-password claim while preserving the rest.
            $this->firebase->clearCustomClaims($this->admin_id, [
                'must_change_password', 'password_reset_at', 'password_reset_by',
            ]);

            // M2: invalidate any OTHER active sessions for this account after a
            // password change (the current CI session continues via cookie).
            try {
                $this->firebase->revokeRefreshTokens($this->admin_id);
            } catch (\Exception $revEx) {
                log_message('error', 'AdminUsers::change_my_password — token revoke failed: ' . $revEx->getMessage());
            }

            // Mirror bcrypt to RTDB (write-only, record-keeping).
            try {
                $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
                $this->firebase->update(
                    "Users/Admin/{$this->school_code}/{$this->admin_id}/Credentials",
                    ['Password' => $hashed]
                );
            } catch (\Exception $mirrorEx) {
                log_message('error', 'AdminUsers::change_my_password — RTDB mirror failed: ' . $mirrorEx->getMessage());
            }

            // Clear mustChangePassword on the admins Firestore doc too.
            try {
                $this->fs->set('admins', $this->fs->docId($this->admin_id), [
                    'mustChangePassword' => false,
                    'updatedAt'          => date('c'),
                ], true);
            } catch (\Exception $e) {
                log_message('error', 'AdminUsers::change_my_password Firestore clear failed: ' . $e->getMessage());
            }

            // Drop the session flag so the preflight guard releases the user.
            $this->session->unset_userdata('must_change_password');

            log_audit('AdminUsers', 'change_my_password', $this->admin_id, 'Self-changed password');

            $this->json_success([
                'message'  => 'Password updated. Redirecting to dashboard.',
                'redirect' => base_url('admin/index'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'AdminUsers::change_my_password failed: ' . $e->getMessage());
            $this->json_error('Failed to update password.');
        }
    }

    // -------------------------------------------------------------------------
    // GET  /admin_users
    // -------------------------------------------------------------------------

    public function index(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'admin_users_view');

        // Auto-retry admins created while Firebase Auth was unavailable (non-blocking).
        // Gated to the page load only — was previously firing on every AJAX request.
        $this->_process_pending_syncs();

        $data = [
            'page_title'        => 'Admin Users',
            'available_modules' => self::AVAILABLE_MODULES,
        ];

        $this->load->view('include/header', $data);
        $this->load->view('admin_users/index', $data);
        $this->load->view('include/footer');
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_dashboard
    // -------------------------------------------------------------------------

    public function get_dashboard(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'admin_users_dashboard');

        try {
            // Initialise before passing by-reference: the helper's params are
            // typed `int &`, so passing undefined vars binds null → TypeError
            // (this is why the dashboard tab was 500-ing on every load).
            $total = 0; $active = 0; $disabled = 0;
            $names = $this->_admin_name_map($total, $active, $disabled);

            // Recent successful logins from the security_events audit trail.
            $recent = $this->_login_events(10, true);
            foreach ($recent as &$r) {
                $r['adminName'] = $names[$r['adminId']] ?? $r['adminId'];
            }
            unset($r);

            $this->json_success([
                'total'    => $total,
                'active'   => $active,
                'disabled' => $disabled,
                'recent'   => $recent,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to load dashboard data.');
        }
    }

    /**
     * Build adminId => display-name map for the current school. Optionally
     * returns total/active/disabled counts via reference params (the dashboard
     * needs both from the same single read).
     */
    private function _admin_name_map(int &$total = 0, int &$active = 0, int &$disabled = 0): array
    {
        $total = 0; $active = 0; $disabled = 0;
        $map = [];
        try {
            foreach ($this->fs->schoolWhere('admins', []) as $doc) {
                $d   = $doc['data'] ?? $doc;
                $a   = $doc['data'];
                $aid = $a['adminId'] ?? $d['id'];
                $map[$aid] = $a['Name'] ?? $a['Profile']['name'] ?? $aid;
                $total++;
                if (($a['Status'] ?? 'Active') === 'Active') $active++;
                else $disabled++;
            }
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::_admin_name_map — ' . $e->getMessage());
        }
        return $map;
    }

    /**
     * Read admin-login events from the security_events collection (Wave C
     * audit trail), newest first. event_type is filtered client-side so the
     * query only needs the detail.schoolId + ts composite index.
     *
     * NOTE: Admin_login inits telemetry with a synthetic top-level
     * schoolId='ADMIN_PANEL' (no school context pre-auth), so the real
     * tenant lives in detail.schoolId — that's what we scope on here.
     *
     * @param int  $limit       Max login rows to return.
     * @param bool $successOnly Restrict to ADMIN_LOGIN_SUCCESS.
     * @return array  rows: adminId, loginTime, ipAddress, device, status
     */
    private function _login_events(int $limit, bool $successOnly = false): array
    {
        try {
            $docs = $this->fs->where(
                'security_events',
                [['detail.schoolId', '==', $this->school_id]],
                'ts', 'DESC',
                max($limit * 4, 400)
            );
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::_login_events — ' . $e->getMessage());
            return [];
        }

        $rows = [];
        foreach ($docs as $doc) {
            $d    = $doc['data'] ?? $doc;
            $type = $d['event_type'] ?? '';
            if (!in_array($type, self::LOGIN_EVENTS, true)) continue;
            if ($successOnly && $type !== 'ADMIN_LOGIN_SUCCESS') continue;

            $rows[] = [
                'adminId'   => (string) ($d['subject']['id'] ?? ''),
                'loginTime' => (string) ($d['ts'] ?? ''),
                'ipAddress' => (string) ($d['context']['ip'] ?? ''),
                'device'    => (string) ($d['context']['user_agent'] ?? ''),
                'status'    => $type === 'ADMIN_LOGIN_SUCCESS' ? 'success'
                             : ($type === 'ADMIN_LOGIN_LOCKED' ? 'locked' : 'failed'),
            ];
            if (count($rows) >= $limit) break;
        }
        return $rows;
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_admins
    // -------------------------------------------------------------------------

    public function get_admins(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'admin_users_list');

        try {
            $adminDocs = $this->fs->schoolWhere('admins', [], 'Name', 'ASC');

            // Most-recent successful login per admin, from the audit trail.
            // 200 recent successes covers the newest login for every admin in any
            // real school (far more than the handful per tenant) without the old
            // ~2000-doc over-fetch.
            $lastMap = [];
            foreach ($this->_login_events(200, true) as $ev) {
                $aid = $ev['adminId'];
                if ($aid !== '' && !isset($lastMap[$aid])) {
                    $lastMap[$aid] = $ev['loginTime']; // first seen = newest (ts DESC)
                }
            }

            $rows = [];
            foreach ($adminDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $a   = $doc['data'];
                $aid = $a['adminId'] ?? $d['id'];
                $row = $this->_normalize_admin($aid, $a);
                $row['lastLogin'] = $lastMap[$aid] ?? '';
                $rows[] = $row;
            }
            $this->json_success(['admins' => $rows]);
        } catch (Exception $e) {
            $this->json_error('Failed to load admin users.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/create_admin
    // -------------------------------------------------------------------------

    public function create_admin(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'create_admin');

        $name     = trim($this->input->post('name',      TRUE) ?? '');
        $email    = strtolower(trim($this->input->post('email', TRUE) ?? ''));
        $phone    = trim($this->input->post('phone',     TRUE) ?? '');
        $role     = trim($this->input->post('role',       TRUE) ?? '');
        $password = (string)($this->input->post('password', FALSE) ?? '');

        if (empty($name) || empty($email) || empty($role) || empty($password)) {
            $this->json_error('Name, email, role, and password are required.');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid email address.');
            return;
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            $this->json_error('Password must be 8–72 characters.');
            return;
        }
        if (!preg_match('/[A-Z]/', $password)
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[0-9]/', $password)) {
            $this->json_error('Password must contain an uppercase letter, a lowercase letter, and a digit.');
            return;
        }

        $role     = $this->safe_path_segment($role, 'role');

        try {
            // Verify the role exists in the unified catalogue (matched by LABEL,
            // which is what an admin's claim carries). Fold the school first so a
            // brand-new tenant has the defaults.
            ensure_unified_roles($this->fs, $this->school_id);
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            if (!$this->_unified_role_exists($schoolDoc, $role)) {
                $this->json_error("Role '{$role}' does not exist.");
                return;
            }
            // SECURITY: reserved-label + privilege-ceiling gate (C2/M1).
            $roleErr = $this->_role_assignment_error($schoolDoc, $role);
            if ($roleErr !== null) { $this->json_error($roleErr, 403); return; }

            // Check duplicate email across all admins
            $existingAdmins = $this->fs->schoolWhere('admins', []);
            foreach ($existingAdmins as $doc) {
                $a = $doc['data'];
                if (strtolower($a['Profile']['email'] ?? '') === $email) {
                    $this->json_error('An admin with this email already exists.');
                    return;
                }
            }

            // Hash password ONCE — same hash goes to both MongoDB and Firebase
            $hashed_pw = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Auto-generate ADM ID via Id_generator
            $this->load->library('id_generator');
            $admin_id    = $this->id_generator->generate('ADM');
            if ($admin_id === null) {
                log_message('error', 'AdminUsers::create_admin — Id_generator returned null for ADM (counter exhausted)');
                $this->json_error('Could not allocate an admin ID right now. Please try again.');
                return;
            }
            // Numeric part of the claimed id, for releaseClaim() on rollback.
            $adm_seq = (int) preg_replace('/^[A-Z]+/', '', $admin_id);

            // Create Firebase Auth account — this is the primary login source.
            // If it fails we abort BEFORE writing any Firestore record, so we
            // never leave a half-created admin that has no working login.
            try {
                $authEmail = Firebase::authEmail($admin_id);
                $created   = $this->firebase->createFirebaseUser($authEmail, $password, [
                    'uid'         => $admin_id,
                    'displayName' => $name,
                ]);
            } catch (Exception $e) {
                log_message('error', 'AdminUsers::create_admin Firebase Auth failed: ' . $e->getMessage());
                $created = null;
            }
            if ($created === null || $created === false) {
                // Hand the unused ADM number back so the sequence has no gap.
                $this->id_generator->releaseClaim('ADM', $adm_seq);
                $this->json_error('Could not create the login account (Firebase Auth unavailable, or that account already exists). No admin was created — please try again.');
                return;
            }
            $this->firebase->setCanonicalClaims($admin_id, [
                'role'          => $role,
                'role_fallback' => 'Admin',
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
                // Force set-new-password on first login. Web-panel gate
                // (MY_Controller / Admin_login) reads this claim; the admins-doc
                // mirror below is written for parity with reset_password.
                'extra'         => [
                    'must_change_password' => true,
                ],
            ]);
            $now       = date('Y-m-d H:i:s');

            // Firebase structure — same as School Super Admin
            $admin_data = [
                'Status'      => 'Active',
                'Role'        => $role,
                'Name'        => $name,
                'Email'       => $email,
                'Credentials' => [
                    'Id'       => $admin_id,
                    'Password' => $hashed_pw,
                ],
                'Profile'     => [
                    'name'        => $name,
                    'email'       => $email,
                    'phone'       => $phone,
                    'role'        => $role,
                    'school'      => $this->school_display_name,
                    'school_id'   => $this->school_code,
                    'firebase_id' => $this->school_id,
                    'created_at'  => $now,
                    'created_by'  => $this->admin_id,
                ],
                'AccessHistory' => [
                    'SA_LastLogin'   => null,
                    'SA_LastLoginIP' => null,
                    'LoginAttempts'  => 0,
                ],
                'Privileges'  => ['accountmanagement' => ''],
            ];

            // ── Firestore admins collection (exclude password) ──
            $fsData = array_merge($admin_data, [
                'schoolId' => $this->school_id,
                'adminId'  => $admin_id,
                'updatedAt' => date('c'),
                // Force set-new-password on first login (mirror of the claim above).
                'mustChangePassword' => true,
            ]);
            unset($fsData['Credentials']);

            // The admins doc is the source of truth. If it can't be written we
            // roll back the Auth account + ID claim so create is all-or-nothing.
            $written = false;
            try {
                $written = $this->fs->set('admins', $this->fs->docId($admin_id), $fsData, true);
            } catch (Exception $writeEx) {
                log_message('error', 'AdminUsers::create_admin — admins write failed: ' . $writeEx->getMessage());
            }
            if ($written === false) {
                try {
                    $this->firebase->deleteFirebaseUser($admin_id);
                } catch (Exception $rb) {
                    log_message('error', 'AdminUsers::create_admin — Auth rollback failed: ' . $rb->getMessage());
                }
                $this->id_generator->releaseClaim('ADM', $adm_seq);
                $this->json_error('Could not save the admin record. No admin was created — please try again.');
                return;
            }

            // ── Firestore staff collection dual-write (best-effort) ──
            try {
                $this->fs->saveStaff($admin_id, [
                    'Name'   => $name,
                    'Email'  => $email,
                    'Phone'  => $phone,
                    'Role'   => $role,
                    'Status' => 'Active',
                ]);
            } catch (Exception $staffEx) {
                log_message('error', "AdminUsers::create_admin — staff dual-write failed: {$staffEx->getMessage()}");
            }

            log_audit('AdminUsers', 'create_admin', $admin_id, "Created admin '{$name}' with role '{$role}'");

            // F8: do NOT echo the plaintext password back. The creator typed it,
            // so the UI already has it client-side for the one-time reveal.
            $this->json_success([
                'message'  => 'Admin created successfully.',
                'admin_id' => $admin_id,
                'name'     => $name,
                'role'     => $role,
            ]);
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::create_admin - ' . $e->getMessage());
            $this->json_error('Failed to create admin user.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/update_admin
    // -------------------------------------------------------------------------

    public function update_admin(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'update_admin');

        $admin_id = trim($this->input->post('admin_id', TRUE) ?? '');
        $name     = trim($this->input->post('name',     TRUE) ?? '');
        $email    = strtolower(trim($this->input->post('email', TRUE) ?? ''));
        $phone    = trim($this->input->post('phone',    TRUE) ?? '');
        $role     = trim($this->input->post('role',      TRUE) ?? '');

        if (empty($admin_id) || empty($name) || empty($email) || empty($role)) {
            $this->json_error('Admin ID, name, email, and role are required.');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid email address.');
            return;
        }

        $admin_id = $this->safe_path_segment($admin_id, 'admin_id');
        $role     = $this->safe_path_segment($role, 'role');

        try {
            $existing = $this->fs->getEntity('admins', $admin_id);
            if (empty($existing) || !is_array($existing)) {
                $this->json_error('Admin user not found.');
                return;
            }

            // M5: never let an admin change their OWN role (self-demote lockout).
            if ($admin_id === $this->admin_id
                && strcasecmp((string) ($existing['Role'] ?? ''), $role) !== 0) {
                $this->json_error('You cannot change your own role. Ask another admin.', 403);
                return;
            }

            // Validate the role against the unified catalogue (matched by label).
            ensure_unified_roles($this->fs, $this->school_id);
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            if (!$this->_unified_role_exists($schoolDoc, $role)) {
                $this->json_error("Role '{$role}' does not exist.");
                return;
            }
            // SECURITY: reserved-label + privilege-ceiling gate (C2/M1).
            $roleErr = $this->_role_assignment_error($schoolDoc, $role);
            if ($roleErr !== null) { $this->json_error($roleErr, 403); return; }

            // Duplicate email check (exclude self)
            $allAdmins = $this->fs->schoolWhere('admins', []);
            foreach ($allAdmins as $doc) {
                $d = $doc['data'] ?? $doc;
                $a   = $doc['data'];
                $aid = $a['adminId'] ?? $d['id'];
                if ($aid === $admin_id) continue;
                if (strtolower($a['Profile']['email'] ?? '') === $email) {
                    $this->json_error('Another admin already uses this email.');
                    return;
                }
            }

            // ── Firestore admins collection update ──
            $this->fs->updateEntity('admins', $admin_id, [
                'Name' => $name,
                'Role' => $role,
                'Profile' => array_merge($existing['Profile'] ?? [], [
                    'name'      => $name,
                    'email'     => $email,
                    'phone'     => $phone,
                    'role'      => $role,
                    'updatedAt' => time(),
                    'updatedBy' => $this->admin_id,
                ]),
            ]);

            // ── Firestore staff collection dual-write (best-effort) ──
            try {
                $this->fs->update('staff', $this->fs->docId($admin_id), [
                    'name'   => $name,
                    'email'  => $email,
                    'phone'  => $phone,
                    'role'   => $role,
                    'updatedAt' => date('c'),
                ]);
            } catch (Exception $staffEx) {
                log_message('error', "AdminUsers::update_admin — staff dual-write failed: {$staffEx->getMessage()}");
            }

            // Sync to Firebase Auth (best-effort — update display name + claims if role changed)
            try {
                $this->firebase->updateFirebaseUser($admin_id, [
                    'displayName' => $name,
                    'email'       => Firebase::authEmail($admin_id),
                ]);
                $old_role = $existing['Role'] ?? '';
                if ($old_role !== $role) {
                    $this->firebase->setCanonicalClaims($admin_id, [
                        'role'          => $role,
                        'role_fallback' => 'Admin',
                        'school_id'     => $this->school_id,
                        'school_code'   => $this->school_code,
                        'parent_db_key' => $this->parent_db_key,
                    ]);
                }
            } catch (Exception $syncEx) {
                log_message('error', 'AdminUsers::update_admin — Firebase Auth sync failed: ' . $syncEx->getMessage());
            }

            log_audit('AdminUsers', 'update_admin', $admin_id, "Updated admin '{$name}'");

            $this->json_success(['message' => "Admin '{$name}' updated."]);
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::update_admin - ' . $e->getMessage());
            $this->json_error('Failed to update admin user.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/disable_admin
    // -------------------------------------------------------------------------

    public function disable_admin(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'disable_admin');

        $admin_id   = $this->safe_path_segment(trim($this->input->post('admin_id', TRUE) ?? ''), 'admin_id');
        $new_status = trim($this->input->post('status', TRUE) ?? '');

        // Map lowercase view values to the capitalized values Admin_login expects
        $status_map = ['active' => 'Active', 'disabled' => 'Disabled'];
        if (!isset($status_map[$new_status])) {
            $this->json_error('Status must be "active" or "disabled".');
            return;
        }

        // Cannot disable yourself
        if ($admin_id === $this->admin_id) {
            $this->json_error('You cannot change your own status.');
            return;
        }

        try {
            $existing = $this->fs->getEntity('admins', $admin_id);
            if (empty($existing) || !is_array($existing)) {
                $this->json_error('Admin user not found.');
                return;
            }

            $mappedStatus = $status_map[$new_status];

            // ── Firestore admins collection ──
            $this->fs->updateEntity('admins', $admin_id, [
                'Status' => $mappedStatus,
            ]);

            // ── Firestore staff collection dual-write (best-effort) ──
            try {
                $this->fs->update('staff', $this->fs->docId($admin_id), [
                    'status'    => $mappedStatus,
                    'updatedAt' => date('c'),
                ]);
            } catch (Exception $staffEx) {
                log_message('error', "AdminUsers::disable_admin — staff dual-write failed: {$staffEx->getMessage()}");
            }

            // M2: a "Disabled" flag in Firestore alone left the Firebase Auth
            // session alive (ID token ~1h). On disable, also disable the Auth
            // account and revoke refresh tokens so access is cut immediately;
            // on re-enable, lift the Auth disable.
            try {
                $this->firebase->updateFirebaseUser($admin_id, ['disabled' => ($mappedStatus === 'Disabled')]);
                if ($mappedStatus === 'Disabled') {
                    $this->firebase->revokeRefreshTokens($admin_id);
                }
            } catch (Exception $authEx) {
                log_message('error', "AdminUsers::disable_admin — Auth disable/revoke failed: {$authEx->getMessage()}");
            }

            $name  = $existing['Name'] ?? $existing['Profile']['name'] ?? $admin_id;
            $label = $new_status === 'active' ? 'enabled' : 'disabled';

            log_audit('AdminUsers', 'toggle_status', $admin_id, "Admin '{$name}' {$label}");

            $this->json_success(['message' => "Admin '{$name}' {$label}."]);
        } catch (Exception $e) {
            $this->json_error('Failed to update admin status.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/delete_admin
    // -------------------------------------------------------------------------

    public function delete_admin(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'delete_admin');

        $admin_id = $this->safe_path_segment(trim($this->input->post('admin_id', TRUE) ?? ''), 'admin_id');

        if ($admin_id === $this->admin_id) {
            $this->json_error('You cannot delete your own account.');
            return;
        }

        try {
            $existing = $this->fs->getEntity('admins', $admin_id);
            if (empty($existing) || !is_array($existing)) {
                $this->json_error('Admin user not found.');
                return;
            }

            $name = $existing['Name'] ?? $existing['Profile']['name'] ?? $admin_id;

            // M10: kill the login BEFORE removing the Firestore record. If the
            // Auth delete later fails, the account is already token-revoked +
            // disabled, so an orphaned Auth user can't authenticate into a
            // now-recordless admin.
            try {
                $this->firebase->revokeRefreshTokens($admin_id);
                $this->firebase->updateFirebaseUser($admin_id, ['disabled' => true]);
            } catch (Exception $preEx) {
                log_message('error', 'AdminUsers::delete_admin — pre-delete revoke/disable failed: ' . $preEx->getMessage());
            }

            // ── Firestore admins collection ──
            $this->fs->removeEntity('admins', $admin_id);

            // ── Firestore staff collection dual-write (best-effort) ──
            try {
                $this->fs->remove('staff', $this->fs->docId($admin_id));
            } catch (Exception $staffEx) {
                log_message('error', "AdminUsers::delete_admin — staff dual-write failed: {$staffEx->getMessage()}");
            }

            // Remove from Firebase Auth (best-effort — already neutralised above)
            try {
                $this->firebase->deleteFirebaseUser($admin_id);
            } catch (Exception $syncEx) {
                log_message('error', 'AdminUsers::delete_admin — Firebase Auth delete failed: ' . $syncEx->getMessage());
            }

            log_audit('AdminUsers', 'delete_admin', $admin_id, "Deleted admin '{$name}'");

            $this->json_success(['message' => "Admin '{$name}' deleted."]);
        } catch (Exception $e) {
            $this->json_error('Failed to delete admin user.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/reset_password
    // -------------------------------------------------------------------------

    public function reset_password(): void
    {
        $this->_require_role(['Super Admin', 'School Super Admin', 'Admin'], 'reset_password');

        $admin_id     = $this->safe_path_segment(trim($this->input->post('admin_id', TRUE) ?? ''), 'admin_id');
        $new_password = (string)($this->input->post('new_password', FALSE) ?? '');

        // Self-reset would force-logout the caller mid-session. Use Change My Password instead.
        if ($admin_id === $this->admin_id) {
            $this->json_error('Use “Change My Password” to reset your own account.');
            return;
        }

        if ($new_password === '') {
            $this->json_error('New password is required.');
            return;
        }
        if (strlen($new_password) < 8 || strlen($new_password) > 72) {
            $this->json_error('Password must be 8–72 characters.');
            return;
        }
        if (!preg_match('/[A-Z]/', $new_password)
            || !preg_match('/[a-z]/', $new_password)
            || !preg_match('/[0-9]/', $new_password)) {
            $this->json_error('Password must contain an uppercase letter, a lowercase letter, and a digit.');
            return;
        }

        try {
            $existing = $this->fs->getEntity('admins', $admin_id);
            if (empty($existing) || !is_array($existing)) {
                $this->json_error('Admin user not found.');
                return;
            }

            // Tenant check — only reset admins in the current school.
            $adminSchool = (string) ($existing['schoolId'] ?? $existing['school_id'] ?? '');
            if ($adminSchool !== '' && $adminSchool !== $this->school_id) {
                log_message('error',
                    "RBAC tenant breach attempt: admin={$this->admin_id} school={$this->school_id} "
                    . "tried to reset admin={$admin_id} of school={$adminSchool}"
                );
                $this->json_error('Admin user not found in your school.', 404);
                return;
            }

            $name = $existing['Name'] ?? $existing['Profile']['name'] ?? $admin_id;
            $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

            // 1. Firebase Auth — primary auth source.
            $updated = $this->firebase->updateFirebaseUser($admin_id, ['password' => $new_password]);
            if ($updated === null) {
                $this->json_error('Failed to update Firebase Auth password.');
                return;
            }

            // 2. must-change-password claim — first-login self-set gate.
            $this->firebase->setCanonicalClaims($admin_id, [
                'role'          => (string) ($existing['Role'] ?? $existing['role'] ?? 'Admin'),
                'role_fallback' => 'Admin',
                'school_id'     => $this->school_id,
                'school_code'   => $this->school_code,
                'parent_db_key' => $this->parent_db_key,
                'extra'         => [
                    'must_change_password' => true,
                    'password_reset_at'    => time(),
                    'password_reset_by'    => (string) ($this->admin_id ?? ''),
                ],
            ]);

            // 3. Revoke refresh tokens — invalidates active sessions.
            $this->firebase->revokeRefreshTokens($admin_id);

            // 4. RTDB bcrypt mirror — write-only, kept for record-keeping per project policy.
            try {
                $rtdbBase = "Users/Admin/{$this->school_code}/{$admin_id}";
                $this->firebase->update($rtdbBase . '/Credentials', ['Password' => $hashed]);
            } catch (\Exception $mirrorEx) {
                log_message('error', 'AdminUsers::reset_password — RTDB mirror failed: ' . $mirrorEx->getMessage());
                // Non-fatal: Firebase Auth is the truth.
            }

            // 5. Firestore admins doc — sets mustChangePassword=true for clients
            //    that read this field instead of (or in addition to) the claim.
            try {
                $this->fs->set('admins', $this->fs->docId($admin_id), [
                    'mustChangePassword' => true,
                    'updatedAt'          => date('c'),
                ], true);
            } catch (\Exception $e) {
                log_message('error', 'AdminUsers::reset_password Firestore mustChange write failed: ' . $e->getMessage());
            }

            log_audit('AdminUsers', 'reset_password', $admin_id, "Password reset for '{$name}'");

            $this->json_success([
                'message' => "Password reset for '{$name}'. They will be required to change it on next login.",
                'admin_id' => $admin_id,
                'name'     => $name,
            ]);
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::reset_password failed: ' . $e->getMessage());
            $this->json_error('Failed to reset password.');
        }
    }

    // -------------------------------------------------------------------------
    // GET  /admin_users/school_super_admins
    //
    // Lists every other SSA in the caller's school so a School Super Admin
    // can reset a peer's password. Self is excluded — to change your own
    // password use /admin_users/change_my_password.
    // -------------------------------------------------------------------------

    public function school_super_admins(): void
    {
        if (strcasecmp($this->admin_role ?? '', 'School Super Admin') !== 0) {
            $this->session->set_flashdata('error', 'Only School Super Admins can access this page.');
            redirect('admin/index');
            return;
        }

        $this->load->library('Ssa_reset', null, 'ssa_reset');
        $all = $this->ssa_reset->listSsasInSchool($this->school_code);

        // Exclude self — peer-reset only.
        $peers = array_values(array_filter($all, fn($r) => $r['id'] !== $this->admin_id));

        $data = [
            'page_title' => 'School Super Admins',
            'peers'      => $peers,
            'self_id'    => $this->admin_id,
        ];

        $this->load->view('include/header', $data);
        $this->load->view('admin_users/school_super_admins', $data);
        $this->load->view('include/footer');
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/reset_ssa_password
    //
    // Caller MUST be School Super Admin. Target MUST be another SSA in the
    // same school. Refuses self-reset (use change_my_password for that).
    // -------------------------------------------------------------------------

    public function reset_ssa_password(): void
    {
        if (strcasecmp($this->admin_role ?? '', 'School Super Admin') !== 0) {
            $this->json_error('Only a School Super Admin can reset another SSA.', 403);
            return;
        }

        if ($this->input->method() !== 'post') {
            $this->json_error('POST only.', 405);
            return;
        }

        $target_id    = trim((string) $this->input->post('ssa_id', TRUE));
        $new_password = (string) $this->input->post('new_password', FALSE);

        if ($target_id === '' || !preg_match('/^SSA\d+$/', $target_id)) {
            $this->json_error('Invalid SSA id.');
            return;
        }
        if ($target_id === $this->admin_id) {
            $this->json_error('Use Change My Password to reset your own account.');
            return;
        }

        // Tenant check — target must exist under the caller's school_code.
        $targetPath = "Users/Admin/{$this->school_code}/{$target_id}";
        $target = $this->firebase->get($targetPath);
        if (empty($target) || !is_array($target)) {
            log_message('error',
                "RBAC tenant breach attempt: ssa={$this->admin_id} school={$this->school_code} "
                . "tried to reset ssa={$target_id} (not found in their school)"
            );
            $this->json_error('SSA not found in your school.', 404);
            return;
        }

        $this->load->library('Ssa_reset', null, 'ssa_reset');
        $result = $this->ssa_reset->resetSsaPassword(
            $this->school_code,
            $this->school_id,
            $target_id,
            $new_password,
            (string) $this->admin_id
        );

        if (empty($result['success'])) {
            $this->json_error($result['message'] ?? 'Reset failed.');
            return;
        }

        log_audit('AdminUsers', 'reset_ssa_password', $target_id,
            "Password reset for SSA '{$result['ssa_name']}' by peer SSA");

        $this->json_success([
            'message' => $result['message'],
            'ssa_id'  => $target_id,
            'name'    => $result['ssa_name'],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_roles
    // -------------------------------------------------------------------------

    public function get_roles(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'view_roles');

        try {
            // Roles now come from the SINGLE unified catalogue (schools.staffRoles).
            // Ensure this school is folded, then shape rows for the Add-Admin dropdown.
            // role_name = the role LABEL, because the admin claim carries the label.
            ensure_unified_roles($this->fs, $this->school_id);
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $raw = is_array($schoolDoc['staffRoles'] ?? null) ? $schoolDoc['staffRoles'] : [];

            $roles = [];
            foreach ($raw as $id => $r) {
                if (!is_array($r)) continue;
                $roles[] = [
                    'role_name'   => (string) ($r['label'] ?? $id),
                    'id'          => (string) $id,
                    'label'       => (string) ($r['label'] ?? $id),
                    'description' => (string) ($r['description'] ?? ''),
                    'permissions' => is_array($r['permissions'] ?? null) ? array_values($r['permissions']) : [],
                    'tier'        => $r['tier'] ?? 7,
                    'sort_order'  => $r['sort_order'] ?? 100,
                    'is_system'   => !empty($r['is_system']),
                ];
            }

            usort($roles, function ($a, $b) {
                return ($a['sort_order'] ?? 999) <=> ($b['sort_order'] ?? 999);
            });

            $this->json_success([
                'roles'   => $roles,
                'modules' => self::AVAILABLE_MODULES,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to load roles.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/save_role
    //
    // Updates ONLY the "Access" facet (module permissions) of a unified role.
    // The role's IDENTITY (name, category, capabilities, departments) is edited in
    // Departments & Roles (Org::save_role). Both write the same schools.staffRoles
    // entry — different fields — so there is one catalogue and no divergence.
    // -------------------------------------------------------------------------

    public function save_role(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'save_access');

        // Accept either the ROLE_* id (preferred) or the role label.
        $role = trim($this->input->post('role_id', TRUE) ?? '');
        if ($role === '') $role = trim($this->input->post('role_name', TRUE) ?? '');
        if ($role === '') { $this->json_error('Role is required.'); return; }

        $permsRaw = $this->input->post('permissions');

        try {
            ensure_unified_roles($this->fs, $this->school_id);
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $allRoles  = is_array($schoolDoc['staffRoles'] ?? null) ? $schoolDoc['staffRoles'] : [];

            // Field-level update of ONLY the Access facet (pure, tested helper).
            $res = set_role_access($allRoles, $role, is_array($permsRaw) ? $permsRaw : [], self::AVAILABLE_MODULES);
            if ($res === null) {
                $this->json_error('Role not found.');
                return;
            }
            $allRoles = $res['staffRoles'];
            $rid = $res['rid'];
            $allRoles[$rid]['accessUpdatedAt'] = date('c');
            $allRoles[$rid]['accessUpdatedBy'] = $this->admin_id;
            $this->fs->update('schools', $this->school_id, ['staffRoles' => $allRoles]);

            // If the caller just edited their OWN role's access, refresh their session.
            if ($res['label'] === (string) $this->admin_role) {
                $this->session->set_userdata('rbac_permissions', $res['permissions']);
            }

            log_audit('AdminUsers', 'save_access', $rid, "Set access for '{$res['label']}' → " . count($res['permissions']) . ' section(s)');

            $this->json_success(['message' => "Access updated for '{$res['label']}'.", 'role_id' => $rid, 'permissions' => $res['permissions']]);
        } catch (Exception $e) {
            log_message('error', 'AdminUsers::save_role (access) failed: ' . $e->getMessage());
            $this->json_error('Failed to update access.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/delete_role  — RETIRED (see save_role)
    // -------------------------------------------------------------------------

    public function delete_role(): void
    {
        $this->json_error('Roles are now managed in Departments & Roles (one catalogue for staff and admins).', 410);
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_login_logs
    // Reads admin-login events (success / failed / locked) from the
    // security_events audit trail — the Wave C canonical login audit source.
    // -------------------------------------------------------------------------

    public function get_login_logs(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'view_login_logs');

        try {
            $names = $this->_admin_name_map();
            $logs  = $this->_login_events(200);
            foreach ($logs as &$l) {
                $l['adminName'] = $names[$l['adminId']] ?? $l['adminId'];
            }
            unset($l);

            $this->json_success([
                'logs'    => $logs,
                'total'   => count($logs),
                'capped'  => count($logs) >= 200,
            ]);
        } catch (Exception $e) {
            $this->json_error('Failed to load login logs.');
        }
    }

    // -------------------------------------------------------------------------
    // PRIVATE: Seed default roles if none exist
    // -------------------------------------------------------------------------

    /**
     * DEPRECATED (unified-roles merge, 2026-07-07). The legacy RBAC-only seeder is
     * replaced by the single unified catalogue. Delegates to ensure_unified_roles()
     * so any lingering caller still upgrades the school correctly. The in-code
     * DEFAULT_ROLES / AVAILABLE_MODULES constants above are retained only as the
     * historical reference the migration folds from (via schools.roles); the live
     * default set now lives in Staff::default_staff_roles().
     */
    private function _seed_default_roles(): void
    {
        ensure_unified_roles($this->fs, $this->school_id);
    }
}
