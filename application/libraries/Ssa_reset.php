<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ssa_reset — shared helper that resets a School Super Admin's password.
 *
 * Callers (AdminUsers, Superadmin_schools) handle their own auth/tenant
 * checks before invoking this; the library only does the Firebase work.
 */
class Ssa_reset
{
    private $firebase;
    private $fs;

    public function __construct()
    {
        $CI =& get_instance();
        if (!isset($CI->firebase)) { $CI->load->library('firebase'); }
        if (!isset($CI->fs))       { $CI->load->library('firestore_service', null, 'fs'); }
        $this->firebase = $CI->firebase;
        $this->fs       = $CI->fs;
    }

    /**
     * Reset an SSA's password and force re-auth.
     *
     * @param string $school_code  RTDB key for the school (login code, e.g. "10005" or "SCH_XXXXXX")
     * @param string $school_id    Firestore school id (SCH_XXXXXX); equals school_code for new schools
     * @param string $ssa_id       Target SSA id (e.g. "SSA0001")
     * @param string $new_password Raw new password (8-72 chars, must contain upper/lower/digit)
     * @param string $actor_id     Caller id for audit trail (admin_id or sa_id)
     * @return array               ['success'=>bool, 'message'=>string, 'ssa_name'=>string]
     */
    public function resetSsaPassword(
        string $school_code,
        string $school_id,
        string $ssa_id,
        string $new_password,
        string $actor_id
    ): array {
        $validation = $this->_validatePassword($new_password);
        if ($validation !== null) {
            return ['success' => false, 'message' => $validation, 'ssa_name' => ''];
        }

        // Firestore canonical existence + role check.
        $existing = $this->fs->getEntity('staff', "{$school_id}_{$ssa_id}");
        if (empty($existing) || !is_array($existing)) {
            return ['success' => false, 'message' => 'School Super Admin not found.', 'ssa_name' => ''];
        }

        $role = (string) ($existing['Role'] ?? $existing['role'] ?? '');
        if (strcasecmp($role, 'School Super Admin') !== 0
            && strcasecmp($role, 'school_super_admin') !== 0) {
            return ['success' => false, 'message' => 'Target account is not a School Super Admin.', 'ssa_name' => ''];
        }

        $name = (string) ($existing['Name'] ?? $existing['name'] ?? $ssa_id);

        // 1. Firebase Auth — primary auth source.
        $updated = $this->firebase->updateFirebaseUser($ssa_id, ['password' => $new_password]);
        if ($updated === null) {
            return ['success' => false, 'message' => 'Failed to update Firebase Auth password.', 'ssa_name' => $name];
        }

        // 2. must_change_password claim — forces force-change screen on next login.
        // setFirebaseClaims REPLACES the whole claim set, so we must re-emit
        // BOTH key styles: snake_case (school_id) for Firestore Security Rules
        // AND camelCase (schoolId) for admin-panel login (Admin_login reads
        // camelCase). Omitting camelCase here would lock the SSA out of the
        // panel after a password reset; omitting snake_case breaks app reads.
        $this->firebase->setCanonicalClaims($ssa_id, [
            'role'          => 'School Super Admin',
            'role_label'    => 'School Super Admin',
            'school_id'     => $school_id,
            'school_code'   => $school_code,
            'parent_db_key' => $school_code,
            'extra'         => [
                'must_change_password' => true,
                'password_reset_at'    => time(),
                'password_reset_by'    => $actor_id,
            ],
        ]);

        // 3. Revoke refresh tokens — kicks active sessions on every device.
        $this->firebase->revokeRefreshTokens($ssa_id);

        // 4. Firestore admins/{ssa_id} — mirror mustChangePassword if the doc exists.
        //    SSAs aren't always written to Firestore admins during onboarding, so
        //    update only if present; skip silently otherwise.
        try {
            $existingFs = $this->fs->get('admins', $this->fs->docId($ssa_id));
            if (!empty($existingFs)) {
                $this->fs->set('admins', $this->fs->docId($ssa_id), [
                    'mustChangePassword' => true,
                    'updatedAt'          => date('c'),
                ], true);
            }
        } catch (\Exception $e) {
            log_message('error', 'Ssa_reset: Firestore mustChange write failed for ' . $ssa_id . ': ' . $e->getMessage());
        }

        return [
            'success'  => true,
            'message'  => "Password reset for '{$name}'. They will be required to change it on next login.",
            'ssa_name' => $name,
        ];
    }

    /**
     * Find every SSA in a school. Returns [['id'=>..., 'name'=>..., 'email'=>..., 'status'=>...], ...].
     * Firestore canonical: queries staff/* by schoolId, filters by SSA id pattern + role.
     */
    public function listSsasInSchool(string $school_id): array
    {
        try {
            $docs = $this->firebase->firestoreQuery('staff', [['schoolId', '==', $school_id]]);
        } catch (\Throwable $e) {
            log_message('error', 'Ssa_reset::listSsasInSchool query failed: ' . $e->getMessage());
            return [];
        }
        if (!is_array($docs)) return [];

        $out = [];
        foreach ($docs as $entry) {
            $d = is_array($entry['data'] ?? null) ? $entry['data'] : (is_array($entry) ? $entry : []);
            $id = (string) ($d['staffId'] ?? '');
            if ($id === '' && isset($entry['id'])) {
                $eid = (string) $entry['id'];
                $pos = strrpos($eid, '_');
                if ($pos !== false) $id = substr($eid, $pos + 1);
            }
            if ($id === '') continue;
            $role = (string) ($d['Role'] ?? $d['role'] ?? '');
            $isSsa = strcasecmp($role, 'School Super Admin') === 0
                  || strcasecmp($role, 'school_super_admin') === 0
                  || preg_match('/^SSA\d+$/', $id);
            if (!$isSsa) continue;

            $out[] = [
                'id'     => $id,
                'name'   => (string) ($d['Name']   ?? $d['name']  ?? $id),
                'email'  => (string) ($d['Email']  ?? $d['email'] ?? ''),
                'status' => (string) ($d['Status'] ?? $d['status'] ?? 'Active'),
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    private function _validatePassword(string $pw): ?string
    {
        if ($pw === '')                  return 'New password is required.';
        if (strlen($pw) < 8)             return 'Password must be at least 8 characters.';
        if (strlen($pw) > 72)            return 'Password must be 72 characters or fewer (bcrypt limit).';
        if (!preg_match('/[A-Z]/', $pw)) return 'Password must contain an uppercase letter.';
        if (!preg_match('/[a-z]/', $pw)) return 'Password must contain a lowercase letter.';
        if (!preg_match('/[0-9]/', $pw)) return 'Password must contain a digit.';
        return null;
    }
}
