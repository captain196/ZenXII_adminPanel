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
            'permissions' => ['Operations'],
            'is_system'   => true,
            'tier'        => 5,
            'sort_order'  => 10,
        ],
        'Transport Manager' => [
            'label'       => 'Transport Manager',
            'description' => 'Transport, routes, vehicles, GPS tracking',
            'permissions' => ['Operations','Reports'],
            'is_system'   => true,
            'tier'        => 5,
            'sort_order'  => 11,
        ],
        'Hostel Warden' => [
            'label'       => 'Hostel Warden',
            'description' => 'Hostel allocation, hostel attendance',
            'permissions' => ['Operations'],
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
        'Academic','Reports','Configuration','Admin Users','Stories',
    ];

    /* -- Login audit lives in security_events (Wave C). These are the
          admin-login event types the Login Activity tab surfaces. -------- */
    private const LOGIN_EVENTS = [
        'ADMIN_LOGIN_SUCCESS', 'ADMIN_LOGIN_FAILED', 'ADMIN_LOGIN_LOCKED',
    ];

    public function __construct()
    {
        parent::__construct();
        require_permission('Admin Users');
    }

    /**
     * Process pending Firebase Auth syncs for admins created while Auth API was down.
     * Runs on every AdminUsers page load. Non-blocking.
     */
    private function _process_pending_syncs(): void
    {
        try {
            $pendingDocs = $this->fs->where('systemPendingSyncAdmins', []);
            if (empty($pendingDocs)) return;

            $synced = 0;
            foreach ($pendingDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $data    = $doc['data'];
                $adminId = $d['id'];
                if (($data['schoolCode'] ?? '') !== $this->school_name) continue;

                $authEmail = Firebase::authEmail($adminId);
                $created   = $this->firebase->createFirebaseUser($authEmail, 'TempSync_' . bin2hex(random_bytes(8)), [
                    'uid'         => $adminId,
                    'displayName' => $data['name'] ?? '',
                ]);

                if ($created !== null && $created !== false) {
                    $this->firebase->setFirebaseClaims($adminId, [
                        'role'        => $data['role'] ?? $data['roleLabel'] ?? '',
                        'roleLabel'   => $data['roleLabel'] ?? $data['role'] ?? '',
                        'schoolId'    => $data['schoolId'] ?? $this->school_id,
                        'schoolCode'  => $data['schoolCode'] ?? $this->school_code,
                        'parentDbKey' => $data['parentDbKey'] ?? $this->parent_db_key,
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
            $lastMap = [];
            foreach ($this->_login_events(500, true) as $ev) {
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
            // Verify the role exists in school config
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $allRoles  = $schoolDoc['roles'] ?? [];
            if (empty($allRoles[$role])) {
                $this->_seed_default_roles();
                $schoolDoc = $this->fs->get('schools', $this->school_id);
                $allRoles  = $schoolDoc['roles'] ?? [];
                if (empty($allRoles[$role])) {
                    $this->json_error("Role '{$role}' does not exist.");
                    return;
                }
            }

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
            $this->firebase->setFirebaseClaims($admin_id, [
                'role'        => $role,
                'roleLabel'   => $role,
                'schoolId'    => $this->school_id,
                'schoolCode'  => $this->school_code,
                'parentDbKey' => $this->parent_db_key,
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

            $this->json_success([
                'message'  => 'Admin created successfully.',
                'admin_id' => $admin_id,
                'name'     => $name,
                'role'     => $role,
                'password' => $password,
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
                    $this->firebase->setFirebaseClaims($admin_id, [
                        'role'        => $role,
                        'roleLabel'   => $role,
                        'schoolId'    => $this->school_id,
                        'schoolCode'  => $this->school_code,
                        'parentDbKey' => $this->parent_db_key,
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

            // ── Firestore admins collection ──
            $this->fs->removeEntity('admins', $admin_id);

            // ── Firestore staff collection dual-write (best-effort) ──
            try {
                $this->fs->remove('staff', $this->fs->docId($admin_id));
            } catch (Exception $staffEx) {
                log_message('error', "AdminUsers::delete_admin — staff dual-write failed: {$staffEx->getMessage()}");
            }

            // Remove from Firebase Auth (best-effort)
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
            $this->firebase->setFirebaseClaims($admin_id, [
                'role'                 => (string) ($existing['Role'] ?? $existing['role'] ?? 'Admin'),
                'roleLabel'            => (string) ($existing['Role'] ?? $existing['role'] ?? 'Admin'),
                'schoolId'             => $this->school_id,
                'schoolCode'           => $this->school_code,
                'parentDbKey'          => $this->parent_db_key,
                'must_change_password' => true,
                'password_reset_at'    => time(),
                'password_reset_by'    => (string) ($this->admin_id ?? ''),
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
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $raw = $schoolDoc['roles'] ?? [];
            if (empty($raw) || !is_array($raw)) {
                $this->_seed_default_roles();
                $schoolDoc = $this->fs->get('schools', $this->school_id);
                $raw = $schoolDoc['roles'] ?? [];
            }

            $roles = [];
            foreach ($raw as $name => $r) {
                if (!is_array($r)) continue;
                $roles[] = array_merge(['role_name' => $name], $r);
            }

            // Sort by tier (asc) then sort_order (asc), custom roles last
            usort($roles, function ($a, $b) {
                $ta = $a['sort_order'] ?? 999;
                $tb = $b['sort_order'] ?? 999;
                return $ta <=> $tb;
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
    // -------------------------------------------------------------------------

    public function save_role(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'save_role');

        $role_name   = trim($this->input->post('role_name',   TRUE) ?? '');
        $label       = trim($this->input->post('label',        TRUE) ?? '');
        $description = trim($this->input->post('description',  TRUE) ?? '');
        $permissions = $this->input->post('permissions') ?? [];

        if (empty($role_name) || empty($label)) {
            $this->json_error('Role name and label are required.');
            return;
        }

        $role_name = $this->safe_path_segment($role_name, 'role_name');

        if (!is_array($permissions)) $permissions = [];
        // Whitelist permissions against available modules
        $permissions = array_values(array_intersect($permissions, self::AVAILABLE_MODULES));

        try {
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $allRoles  = $schoolDoc['roles'] ?? [];
            $existing  = $allRoles[$role_name] ?? null;
            $is_system = is_array($existing) && !empty($existing['is_system']);

            $role_data = array_merge($existing ?? [], [
                'label'       => $label,
                'description' => $description,
                'permissions' => $permissions,
                'updatedAt'   => date('Y-m-d H:i:s'),
                'updatedBy'   => $this->admin_id,
            ]);

            if (!$is_system) {
                $role_data['is_system'] = false;
                if (empty($existing)) {
                    $role_data['createdAt'] = date('Y-m-d H:i:s');
                    $role_data['createdBy'] = $this->admin_id;
                    $role_data['tier']       = 7;
                    $role_data['sort_order'] = 100;
                }
            }

            $allRoles[$role_name] = $role_data;
            $this->fs->update('schools', $this->school_id, ['roles' => $allRoles]);

            // Refresh current admin's cached permissions if their role was just modified
            if ($role_name === $this->admin_role) {
                $this->session->set_userdata('rbac_permissions', $permissions);
            }

            log_audit('AdminUsers', 'save_role', $role_name, "Saved role '{$label}' with " . count($permissions) . " permissions");

            $this->json_success(['message' => "Role '{$label}' saved."]);
        } catch (Exception $e) {
            $this->json_error('Failed to save role.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/delete_role
    // -------------------------------------------------------------------------

    public function delete_role(): void
    {
        $this->_require_role(['Super Admin', 'Admin'], 'delete_role');

        $role_name = $this->safe_path_segment(trim($this->input->post('role_name', TRUE) ?? ''), 'role_name');

        try {
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $allRoles  = $schoolDoc['roles'] ?? [];
            $existing  = $allRoles[$role_name] ?? null;
            if (empty($existing) || !is_array($existing)) {
                $this->json_error('Role not found.');
                return;
            }
            if (!empty($existing['is_system'])) {
                $this->json_error('System roles cannot be deleted.');
                return;
            }

            // Check if any admin uses this role
            $adminDocs = $this->fs->schoolWhere('admins', []);
            foreach ($adminDocs as $doc) {
                $a = $doc['data'];
                $aRole = $a['Role'] ?? $a['Profile']['role'] ?? '';
                $aName = $a['Name'] ?? $a['Profile']['name'] ?? '';
                if ($aRole === $role_name) {
                    $this->json_error("Cannot delete: role is assigned to admin '{$aName}'.");
                    return;
                }
            }

            unset($allRoles[$role_name]);
            $this->fs->update('schools', $this->school_id, ['roles' => $allRoles]);

            log_audit('AdminUsers', 'delete_role', $role_name, "Deleted role '{$role_name}'");

            $this->json_success(['message' => "Role '{$role_name}' deleted."]);
        } catch (Exception $e) {
            $this->json_error('Failed to delete role.');
        }
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
     * Seed/upgrade default roles. Adds missing system roles without overwriting
     * custom permission changes made by school admins to existing roles.
     */
    private function _seed_default_roles(): void
    {
        try {
            $schoolDoc = $this->fs->get('schools', $this->school_id);
            $existing  = $schoolDoc['roles'] ?? [];
            if (!is_array($existing)) $existing = [];
            $changed = false;

            foreach (self::DEFAULT_ROLES as $name => $config) {
                if (isset($existing[$name])) {
                    $updates = [];
                    foreach (['tier', 'sort_order', 'is_system'] as $field) {
                        if (!isset($existing[$name][$field]) && isset($config[$field])) {
                            $updates[$field] = $config[$field];
                        }
                    }
                    if (($existing[$name]['label'] ?? '') === $name) {
                        $updates['label'] = $config['label'];
                    }
                    if (!empty($updates)) {
                        $updates['updatedAt'] = date('Y-m-d H:i:s');
                        $updates['updatedBy'] = 'system';
                        $existing[$name] = array_merge($existing[$name], $updates);
                        $changed = true;
                    }
                } else {
                    $existing[$name] = array_merge($config, [
                        'createdAt' => date('Y-m-d H:i:s'),
                        'createdBy' => 'system',
                    ]);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->fs->set('schools', $this->school_id, ['roles' => $existing], true);
            }
        } catch (Exception $e) {
            log_message('error', 'AdminUsers: Failed to seed default roles - ' . $e->getMessage());
        }
    }
}
