<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * AdminUsers - ERP Identity & Access Management (IAM)
 *
 * Central administrator management for each school tenant.
 * Manages admin accounts, RBAC roles/permissions, and login audit logs.
 *
 * Firebase paths (aligned with Admin_login.php & Superadmin_schools.php):
 *   Users/Admin/{school_code}/{adminId}       - admin user profiles
 *   Schools/{school}/Roles/{roleName}          - role permission sets
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

    public function __construct()
    {
        parent::__construct();
        require_permission('Admin Users');

        // Auto-retry pending MongoDB syncs on every AdminUsers page load (non-blocking)
        $this->_process_pending_syncs();
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
                        'role'          => $data['roleLabel'] ?? $data['role'] ?? '',
                        'school_id'     => $data['schoolId'] ?? $this->school_id,
                        'school_code'   => $data['schoolCode'] ?? $this->school_code,
                        'parent_db_key' => $data['parentDbKey'] ?? $this->parent_db_key,
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
    // GET  /admin_users
    // -------------------------------------------------------------------------

    public function index(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'admin_users_view');

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
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'admin_users_dashboard');

        try {
            $adminDocs = $this->fs->schoolWhere('admins', []);

            $total = 0; $active = 0; $disabled = 0;
            $recent = [];

            foreach ($adminDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $a   = $doc['data'];
                $aid = $a['adminId'] ?? $d['id'];
                $total++;
                $status = $a['Status'] ?? 'Active';
                if ($status === 'Active') $active++;
                else $disabled++;

                $lastLogin = $a['AccessHistory']['LastLogin'] ?? '';
                if (!empty($lastLogin)) {
                    $recent[] = [
                        'adminId'   => $aid,
                        'adminName' => $a['Name'] ?? $a['Profile']['name'] ?? $aid,
                        'loginTime' => $lastLogin,
                        'ipAddress' => $a['AccessHistory']['LoginIP'] ?? '',
                        'status'    => 'success',
                        'device'    => '-',
                    ];
                }
            }

            usort($recent, fn($a, $b) => strcmp($b['loginTime'] ?? '', $a['loginTime'] ?? ''));
            $recent = array_slice($recent, 0, 10);

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

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_admins
    // -------------------------------------------------------------------------

    public function get_admins(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'admin_users_list');

        try {
            $adminDocs = $this->fs->schoolWhere('admins', [], 'Name', 'ASC');
            $rows = [];
            foreach ($adminDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $a   = $doc['data'];
                $aid = $a['adminId'] ?? $d['id'];
                $rows[] = $this->_normalize_admin($aid, $a);
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
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'create_admin');

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
        if (strlen($password) < 8) {
            $this->json_error('Password must be at least 8 characters.');
            return;
        }
        if (strlen($password) > 72) {
            $this->json_error('Password must be 72 characters or less.');
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
            $auth_synced = false;

            // Create Firebase Auth account
            try {
                $authEmail = Firebase::authEmail($admin_id);
                $created   = $this->firebase->createFirebaseUser($authEmail, $password, [
                    'uid'         => $admin_id,
                    'displayName' => $name,
                ]);
                if ($created !== null && $created !== false) {
                    $this->firebase->setFirebaseClaims($admin_id, [
                        'role'          => $role,
                        'school_id'     => $this->school_id,
                        'school_code'   => $this->school_code,
                        'parent_db_key' => $this->parent_db_key,
                    ]);
                    $auth_synced = true;
                }
            } catch (Exception $e) {
                log_message('error', 'AdminUsers::create_admin Firebase Auth failed: ' . $e->getMessage());
            }
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
            $this->fs->set('admins', $this->fs->docId($admin_id), $fsData, true);

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

            $msg = 'Admin created successfully.';
            if (!$auth_synced) {
                $msg .= ' (Note: Firebase Auth account could not be created — admin can log in via RTDB credentials only.)';
            }

            $this->json_success([
                'message'  => $msg,
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
                        'role'          => $role,
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
        $this->_require_role(['Super Admin', 'Admin'], 'reset_password');

        $admin_id     = $this->safe_path_segment(trim($this->input->post('admin_id', TRUE) ?? ''), 'admin_id');
        $new_password = (string)($this->input->post('new_password', FALSE) ?? '');

        if (strlen($new_password) < 8) {
            $this->json_error('Password must be at least 8 characters.');
            return;
        }
        if (strlen($new_password) > 72) {
            $this->json_error('Password must be 72 characters or less.');
            return;
        }

        try {
            $existing = $this->fs->getEntity('admins', $admin_id);
            if (empty($existing) || !is_array($existing)) {
                $this->json_error('Admin user not found.');
                return;
            }

            // Password reset — Firebase Auth is the primary auth source now
            $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);

            // RTDB credential write removed — Firebase Auth is the primary auth source.
            // Passwords are no longer stored in any database (RTDB or Firestore).

            // Sync password to Firebase Auth (best-effort)
            try {
                $this->firebase->updateFirebaseUser($admin_id, ['password' => $new_password]);
            } catch (Exception $syncEx) {
                log_message('error', 'AdminUsers::reset_password — Firebase Auth sync failed: ' . $syncEx->getMessage());
            }

            $name = $existing['Name'] ?? $existing['Profile']['name'] ?? $admin_id;

            log_audit('AdminUsers', 'reset_password', $admin_id, "Password reset for '{$name}'");

            $this->json_success(['message' => "Password reset for '{$name}'."]);
        } catch (Exception $e) {
            $this->json_error('Failed to reset password.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_roles
    // -------------------------------------------------------------------------

    public function get_roles(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'view_roles');

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

            // ── Firestore rbacRoles collection ──
            try {
                $this->fs->setEntity('rbacRoles', $role_name, $role_data);
            } catch (Exception $roleEx) {
                log_message('error', "AdminUsers::save_role — rbacRoles dual-write failed: {$roleEx->getMessage()}");
            }

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

            // ── Firestore rbacRoles collection ──
            try {
                $this->fs->removeEntity('rbacRoles', $role_name);
            } catch (Exception $roleEx) {
                log_message('error', "AdminUsers::delete_role — rbacRoles dual-write failed: {$roleEx->getMessage()}");
            }

            log_audit('AdminUsers', 'delete_role', $role_name, "Deleted role '{$role_name}'");

            $this->json_success(['message' => "Role '{$role_name}' deleted."]);
        } catch (Exception $e) {
            $this->json_error('Failed to delete role.');
        }
    }

    // -------------------------------------------------------------------------
    // POST  /admin_users/get_login_logs
    // Aggregates AccessHistory from each admin record (no centralized log exists)
    // -------------------------------------------------------------------------

    public function get_login_logs(): void
    {
        $this->_require_role(['Super Admin', 'Admin', 'Principal'], 'view_login_logs');

        try {
            $adminDocs = $this->fs->schoolWhere('admins', []);
            $rows = [];

            foreach ($adminDocs as $doc) {
                $d = $doc['data'] ?? $doc;
                $a   = $doc['data'];
                $aid = $a['adminId'] ?? $d['id'];
                $access    = $a['AccessHistory'] ?? [];
                $lastLogin = $access['LastLogin'] ?? '';
                if (empty($lastLogin)) continue;

                $rows[] = [
                    'adminId'   => $aid,
                    'adminName' => $a['Name'] ?? $a['Profile']['name'] ?? $aid,
                    'loginTime' => $lastLogin,
                    'ipAddress' => $access['LoginIP'] ?? '',
                    'status'    => 'success',
                    'device'    => '-',
                    'isOnline'  => !empty($access['IsLoggedIn']),
                ];
            }

            usort($rows, fn($a, $b) => strcmp($b['loginTime'] ?? '', $a['loginTime'] ?? ''));

            $this->json_success([
                'logs'  => $rows,
                'total' => count($rows),
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
