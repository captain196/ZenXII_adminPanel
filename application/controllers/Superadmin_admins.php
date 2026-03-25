<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_admins
 * Manage developer super admin accounts (stored at Users/Admin/Our Panel/).
 * Only accessible by users with sa_role = 'developer'.
 */
class Superadmin_admins extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('auth_client');

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
    // AJAX: Fetch all developer admins
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch()
    {
        $raw = $this->firebase->get('Users/Admin/Our Panel');
        $admins = [];

        if (is_array($raw)) {
            foreach ($raw as $id => $data) {
                if (!is_array($data)) continue;
                $admins[] = [
                    'admin_id'   => $id,
                    'name'       => $data['Name'] ?? $data['Profile']['name'] ?? $id,
                    'email'      => $data['Email'] ?? $data['Profile']['email'] ?? '',
                    'status'     => $data['Status'] ?? 'Active',
                    'created_at' => $data['Profile']['created_at'] ?? $data['Created On'] ?? '',
                    'last_login' => $data['AccessHistory']['SA_LastLogin'] ?? $data['SA_LastLogin'] ?? '',
                    'is_current' => ($id === $this->sa_id),
                    'is_primary' => !empty($data['is_primary']),
                ];
            }
        }

        $this->json_success(['admins' => $admins]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Create a new developer super admin
    // ─────────────────────────────────────────────────────────────────────────

    public function create()
    {
        $name     = trim($this->input->post('name'));
        $email    = trim($this->input->post('email'));
        $phone    = trim($this->input->post('phone'));
        $password = $this->input->post('password');

        // ── Validate ──
        if (empty($name) || empty($password)) {
            $this->json_error('Name and Password are required.');
        }

        // Password strength: min 8 chars, must have upper + lower + digit
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) ||
            !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $this->json_error('Password must be at least 8 characters with uppercase, lowercase, and a number.');
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json_error('Invalid email format.');
        }

        // Name: basic sanitization
        $name = htmlspecialchars(strip_tags($name), ENT_QUOTES, 'UTF-8');
        if (strlen($name) > 100) {
            $this->json_error('Name must be 100 characters or less.');
        }

        // ── Auto-generate next SUP ID ──
        $all = $this->firebase->get('Users/Admin/Our Panel');
        $max_num = 0;
        if (is_array($all)) {
            foreach (array_keys($all) as $key) {
                if (preg_match('/^SUP(\d+)$/', $key, $m)) {
                    $num = (int) $m[1];
                    if ($num > $max_num) $max_num = $num;
                }
            }
        }
        $admin_id = 'SUP' . str_pad($max_num + 1, 4, '0', STR_PAD_LEFT);

        // ── Hash password & save ──
        $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $now    = date('Y-m-d H:i:s');

        $data = [
            'Status'      => 'Active',
            'Name'        => $name,
            'Email'       => $email,
            'Credentials' => [
                'Id'       => $admin_id,
                'Password' => $hashed,
            ],
            'Profile'     => [
                'name'       => $name,
                'email'      => $email,
                'phone'      => $phone,
                'role'       => 'developer',
                'created_at' => $now,
                'created_by' => $this->sa_id,
            ],
            'AccessHistory' => [
                'SA_LastLogin'   => null,
                'SA_LastLoginIP' => null,
                'LoginAttempts'  => 0,
            ],
            'Privileges'  => [
                'accountmanagement' => '',
            ],
            'Role'        => 'Super Admin',
        ];

        $result = $this->firebase->set('Users/Admin/Our Panel/' . $admin_id, $data);

        if ($result === null || $result === false) {
            $this->json_error('Failed to create admin. Firebase write error.');
        }

        // ── Sync to MongoDB via Auth API (best-effort) ──
        $sync = $this->auth_client->sync_admin([
            'adminId'      => $admin_id,
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone,
            'role'         => 'super_admin',
            'passwordHash' => $hashed,
            'createdBy'    => $this->sa_id,
        ]);
        $mongo_ok = !empty($sync['success']);

        $this->sa_log('Created developer admin', '', [
            'new_admin_id' => $admin_id,
            'mongodb_sync' => $mongo_ok ? 'ok' : 'failed',
        ]);
        $msg = 'Super Admin "' . $admin_id . '" created successfully.';
        if (!$mongo_ok) {
            $msg .= ' (Warning: MongoDB sync failed — login via Firebase will still work)';
        }
        $this->json_success(['message' => $msg, 'admin_id' => $admin_id]);
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

        // Cannot deactivate yourself
        if ($admin_id === $this->sa_id) {
            $this->json_error('You cannot deactivate your own account.');
        }

        $existing = $this->firebase->get('Users/Admin/Our Panel/' . $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }

        if (!empty($existing['is_primary'])) {
            $this->json_error('The primary super admin cannot be deactivated.');
        }

        $current = $existing['Status'] ?? 'Active';
        $new_status = ($current === 'Active') ? 'Inactive' : 'Active';

        $this->firebase->update('Users/Admin/Our Panel/' . $admin_id, ['Status' => $new_status]);

        // ── Sync status to MongoDB ──
        $this->auth_client->sync_admin([
            'adminId' => $admin_id,
            'name'    => $existing['Name'] ?? '',
            'email'   => $existing['Email'] ?? '',
            'role'    => $new_status === 'Active' ? 'super_admin' : 'inactive',
        ]);

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
    // AJAX POST: Reset password
    // ─────────────────────────────────────────────────────────────────────────

    public function reset_password()
    {
        $admin_id     = trim($this->input->post('admin_id'));
        $new_password = $this->input->post('new_password');

        if (empty($admin_id) || empty($new_password)) {
            $this->json_error('Admin ID and new password are required.');
        }

        // Password strength
        if (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) ||
            !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $this->json_error('Password must be at least 8 characters with uppercase, lowercase, and a number.');
        }

        $existing = $this->firebase->get('Users/Admin/Our Panel/' . $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }

        $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->firebase->update('Users/Admin/Our Panel/' . $admin_id . '/Credentials', [
            'Password' => $hashed,
        ]);

        // ── Sync to MongoDB ──
        $this->auth_client->reset_password($admin_id, '', $hashed, 'super_admin');

        $this->sa_log('Reset admin password', '', ['target_admin' => $admin_id]);
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

        $existing = $this->firebase->get('Users/Admin/Our Panel/' . $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }

        $this->firebase->update('Users/Admin/Our Panel/' . $admin_id, [
            'Name'  => $name,
            'Email' => $email,
        ]);
        $this->firebase->update('Users/Admin/Our Panel/' . $admin_id . '/Profile', [
            'name'  => $name,
            'email' => $email,
        ]);

        // ── Sync to MongoDB ──
        $this->auth_client->sync_admin([
            'adminId' => $admin_id,
            'name'    => $name,
            'email'   => $email,
            'role'    => 'super_admin',
        ]);

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

        $existing = $this->firebase->get('Users/Admin/Our Panel/' . $admin_id);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('Admin not found.');
        }

        if (!empty($existing['is_primary'])) {
            $this->json_error('The primary super admin cannot be deleted.');
        }

        $this->firebase->delete('Users/Admin/Our Panel', $admin_id);

        // ── Delete from MongoDB ──
        $this->auth_client->delete_admin($admin_id, '', 'super_admin');

        $this->sa_log('Deleted developer admin', '', ['deleted_admin' => $admin_id]);
        $this->json_success(['message' => 'Admin "' . $admin_id . '" has been deleted.']);
    }
}
