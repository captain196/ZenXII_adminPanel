<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_admins
 * Manage developer super admin accounts.
 *   - Credentials  : Firebase Auth (login authenticates here).
 *   - Profile/metadata : Firestore `superAdmins/{SUPxxxx}` (password-free).
 *   - No RTDB, no MongoDB/Auth API (Wave A convergence).
 * Only accessible by users with sa_role = 'developer'.
 */
class Superadmin_admins extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();

        // Only developers can manage other super admins
        if ($this->sa_role !== 'developer') {
            if ($this->input->is_ajax_request()) {
                $this->json_error('Access denied. Developer role required.', 403);
            }
            redirect('superadmin/dashboard');
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE: List all developer super admins
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        $this->load->view('superadmin/include/sa_header');
        $this->load->view('superadmin/admins/index');
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: Fetch all developer admins (from Firestore superAdmins)
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch()
    {
        $admins = [];
        try {
            foreach ((array) $this->firebase->firestoreQuery('superAdmins') as $row) {
                $id = (string) ($row['id'] ?? '');
                $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;
                if ($id === '' && isset($d['superAdminId'])) $id = (string) $d['superAdminId'];
                if ($id === '') continue;
                $ah = is_array($d['accessHistory'] ?? null) ? $d['accessHistory'] : [];
                $admins[] = [
                    'admin_id'   => $id,
                    'name'       => $d['name'] ?? $id,
                    'email'      => $d['email'] ?? '',
                    'status'     => $d['status'] ?? 'Active',
                    'created_at' => $d['createdAt'] ?? '',
                    'last_login' => $ah['lastLogin'] ?? '',
                    'is_current' => ($id === $this->sa_id),
                    'is_primary' => !empty($d['isPrimary']),
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'SA fetch superAdmins failed: ' . $e->getMessage());
        }

        $this->json_success(['admins' => $admins]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Create a new developer super admin (Firebase Auth + Firestore)
    // ─────────────────────────────────────────────────────────────────────────

    public function create()
    {
        $name     = trim($this->input->post('name'));
        $email    = trim($this->input->post('email'));
        $phone    = trim($this->input->post('phone'));
        $password = $this->input->post('password');

        if (empty($name) || empty($password)) {
            $this->json_error('Name and Password are required.');
        }
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->json_error('Password must be at least 8 characters with uppercase, lowercase, and a number.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid email format.');
        }
        $name = htmlspecialchars(strip_tags($name), ENT_QUOTES, 'UTF-8');
        if (strlen($name) > 100) {
            $this->json_error('Name must be 100 characters or less.');
        }

        // Next SUP id: scan the canonical Firestore superAdmins, and also the legacy
        // RTDB Our Panel (transition-safety, so we never reuse a legacy id). The RTDB
        // read here is id-safety only and is dropped once Our Panel is retired.
        $max_num = 0;
        try {
            foreach ((array) $this->firebase->firestoreQuery('superAdmins') as $row) {
                $rid = (string) ($row['id'] ?? ($row['data']['superAdminId'] ?? ''));
                if (preg_match('/^SUP(\d+)$/', $rid, $m)) { $max_num = max($max_num, (int) $m[1]); }
            }
        } catch (\Throwable $e) { /* fall through to legacy scan */ }
        $legacy = $this->firebase->get('Users/Admin/Our Panel');
        if (is_array($legacy)) {
            foreach (array_keys($legacy) as $key) {
                if (preg_match('/^SUP(\d+)$/', $key, $m)) { $max_num = max($max_num, (int) $m[1]); }
            }
        }
        $admin_id  = 'SUP' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);
        $authEmail = Firebase::authEmail($admin_id);
        $nowIso    = date('c');

        // ── Credential authority: create the Firebase Auth user + role claim ──
        $fbUser = $this->firebase->createFirebaseUser($authEmail, $password, [
            'uid' => $admin_id, 'displayName' => $name,
        ]);
        if ($fbUser === null) {
            $this->json_error('Failed to create the Firebase Auth login account. Admin not created.');
        }
        $this->firebase->setFirebaseClaims($admin_id, ['role' => 'super_admin']);

        // ── Canonical profile in Firestore (password-free; mirrors A1 schema) ──
        $ok = $this->firebase->firestoreSet('superAdmins', $admin_id, [
            'superAdminId'        => $admin_id,
            'name'                => $name,
            'email'               => $email,
            'status'              => 'Active',
            'role'                => 'super_admin',
            'isPrimary'           => false,
            'phone'               => $phone,
            'createdAt'           => $nowIso,
            'createdBy'           => $this->sa_id,
            'firebaseUid'         => $admin_id,
            'authProvider'        => 'firebase',
            'accessHistory'       => ['lastLogin' => null, 'lastLoginIp' => null],
            'migratedToFirestore' => true,
            'migratedAt'          => $nowIso,
            'source'              => 'sa_create',
        ], false);
        if ($ok === false) {
            // Roll back the Firebase Auth user so we don't leave an orphan credential.
            $this->firebase->deleteFirebaseUser($admin_id);
            $this->json_error('Failed to write the admin profile. Admin not created.');
        }

        $this->sa_log('Created developer admin', '', ['new_admin_id' => $admin_id, 'store' => 'firebase+firestore']);
        $this->json_success([
            'message'  => 'Super Admin "' . $admin_id . '" created. They can log in with this ID and the password you set.',
            'admin_id' => $admin_id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Toggle status (Active/Inactive)
    // ─────────────────────────────────────────────────────────────────────────

    public function toggle_status()
    {
        $admin_id = trim($this->input->post('admin_id'));
        if (empty($admin_id)) {
            $this->json_error('Admin ID is required.');
        }
        if ($admin_id === $this->sa_id) {
            $this->json_error('You cannot deactivate your own account.');
        }

        $existing = $this->firebase->firestoreGet('superAdmins', $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }
        if (!empty($existing['isPrimary'])) {
            $this->json_error('The primary super admin cannot be deactivated.');
        }

        $current    = $existing['status'] ?? 'Active';
        $new_status = ($current === 'Active') ? 'Inactive' : 'Active';

        // Firebase Auth disables/enables login; Firestore carries the status field.
        $this->firebase->updateFirebaseUser($admin_id, ['disabled' => ($new_status === 'Inactive')]);
        $this->firebase->firestoreSet('superAdmins', $admin_id, ['status' => $new_status], true);

        $this->sa_log('Toggled admin status', '', [
            'target_admin' => $admin_id,
            'from'         => $current,
            'to'           => $new_status,
        ]);

        $this->json_success([
            'message'    => 'Admin "' . $admin_id . '" is now ' . $new_status . '.',
            'new_status' => $new_status,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Reset password (Firebase Auth — the credential authority)
    // ─────────────────────────────────────────────────────────────────────────

    public function reset_password()
    {
        $admin_id     = trim($this->input->post('admin_id'));
        $new_password = $this->input->post('new_password');

        if (empty($admin_id) || empty($new_password)) {
            $this->json_error('Admin ID and new password are required.');
        }
        if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) ||
            !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $this->json_error('Password must be at least 8 characters with uppercase, lowercase, and a number.');
        }

        $existing = $this->firebase->firestoreGet('superAdmins', $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }

        // Credential authority = Firebase Auth (SA login authenticates here). uid == admin_id.
        $fbUser = $this->firebase->updateFirebaseUser($admin_id, ['password' => $new_password]);
        if ($fbUser === null) {
            $this->json_error('Failed to update the login credential (Firebase Auth). Password unchanged - please retry.');
        }

        $this->sa_log('Reset admin password', '', ['target_admin' => $admin_id, 'store' => 'firebase']);
        $this->json_success(['message' => 'Password for "' . $admin_id . '" has been reset.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Update profile (name, email)
    // ─────────────────────────────────────────────────────────────────────────

    public function update_profile()
    {
        $admin_id = trim($this->input->post('admin_id'));
        $name     = trim($this->input->post('name'));
        $email    = trim($this->input->post('email'));

        if (empty($admin_id) || empty($name)) {
            $this->json_error('Admin ID and Name are required.');
        }

        $name = htmlspecialchars(strip_tags($name), ENT_QUOTES, 'UTF-8');
        if (strlen($name) > 100) {
            $this->json_error('Name must be 100 characters or less.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid email format.');
        }

        $existing = $this->firebase->firestoreGet('superAdmins', $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }

        $this->firebase->firestoreSet('superAdmins', $admin_id, [
            'name'  => $name,
            'email' => $email,
        ], true);
        $this->firebase->updateFirebaseUser($admin_id, ['displayName' => $name]);

        $this->sa_log('Updated admin profile', '', ['target_admin' => $admin_id]);
        $this->json_success(['message' => 'Profile updated for "' . $admin_id . '".']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Delete admin (cannot delete self)
    // ─────────────────────────────────────────────────────────────────────────

    public function delete()
    {
        $admin_id = trim($this->input->post('admin_id'));
        if (empty($admin_id)) {
            $this->json_error('Admin ID is required.');
        }
        if ($admin_id === $this->sa_id) {
            $this->json_error('You cannot delete your own account.');
        }

        $existing = $this->firebase->firestoreGet('superAdmins', $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }
        if (!empty($existing['isPrimary'])) {
            $this->json_error('The primary super admin cannot be deleted.');
        }

        // Remove the Firebase Auth login + the Firestore profile.
        $this->firebase->deleteFirebaseUser($admin_id);
        $this->firebase->firestoreDelete('superAdmins', $admin_id);

        $this->sa_log('Deleted developer admin', '', ['deleted_admin' => $admin_id]);
        $this->json_success(['message' => 'Admin "' . $admin_id . '" has been deleted.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Super-admin recovery contact — the block shown to OTHER super admins when
    // they use "forgot password" on the SA login (they contact the primary).
    // Owned solely by the primary super admin SUP0001. Stored at Firestore
    // platformSettings/superAdminRecovery {name,email,number}.
    // ─────────────────────────────────────────────────────────────────────────

    public function get_super_recovery()
    {
        if ($this->sa_id !== 'SUP0001') {
            $this->json_error('Only the primary super admin can view this.', 403);
        }
        $block = (array) ($this->firebase->firestoreGet('platformSettings', 'superAdminRecovery') ?? []);
        $this->json_success([
            'recovery' => [
                'name'   => (string) ($block['name']   ?? ''),
                'email'  => (string) ($block['email']  ?? ''),
                'number' => (string) ($block['number'] ?? ''),
            ],
        ]);
    }

    public function update_super_recovery()
    {
        if ($this->sa_id !== 'SUP0001') {
            $this->json_error('Only the primary super admin (SUP0001) can edit this.', 403);
        }

        $name   = trim((string) ($this->input->post('name',   TRUE) ?? ''));
        $email  = strtolower(trim((string) ($this->input->post('email',  TRUE) ?? '')));
        $number = trim((string) ($this->input->post('number', TRUE) ?? ''));

        if ($name === '')                 $this->json_error('Name is required.');
        if (mb_strlen($name) > 100)       $this->json_error('Name must be 100 characters or less.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
                                          $this->json_error('Invalid email format.');
        if ($number !== '' && !preg_match('/^[\d\s\+\-\(\)]{7,20}$/', $number))
                                          $this->json_error('Invalid phone number.');
        if ($email === '' && $number === '')
                                          $this->json_error('Provide at least an email or a phone number.');

        $name = htmlspecialchars(strip_tags($name), ENT_QUOTES, 'UTF-8');

        $block = [
            'name'      => $name,
            'email'     => $email,
            'number'    => $number,
            'updatedAt' => date('c'),
            'updatedBy' => (string) $this->sa_id,
        ];

        $ok = $this->firebase->firestoreSet('platformSettings', 'superAdminRecovery', $block, true);
        if (!$ok) {
            $this->json_error('Could not save the recovery contact. Please try again.');
        }

        $this->sa_log('Updated super-admin recovery contact', '', ['name' => $name]);
        $this->json_success([
            'message'  => 'Super-admin recovery contact saved.',
            'recovery' => ['name' => $name, 'email' => $email, 'number' => $number],
        ]);
    }
}
