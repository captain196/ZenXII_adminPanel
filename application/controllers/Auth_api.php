<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Auth_api — token-authenticated mobile endpoints.
 *
 * Extends CI_Controller (not MY_Controller) because authentication is
 * by Firebase ID token, not by CI session. Routes mounted under /auth/*
 * are intended to be called from the Zenxii_Teacher and ZenXII_Parent
 * Android apps.
 *
 * Endpoints:
 *   POST /auth/clear_must_change   — finalise an admin-driven password reset
 *                                    by setting a permanent password and
 *                                    clearing the must_change_password claim.
 *
 * Auth model:
 *   Authorization: Bearer <Firebase ID token>
 *
 * All responses are JSON.
 */
class Auth_api extends CI_Controller
{
    private const MIN_PW = 8;
    private const MAX_PW = 72;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->output->set_content_type('application/json');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  POST /auth/clear_must_change
    //
    //  Body: new_password=<8–72 chars, upper+lower+digit>
    //  Headers: Authorization: Bearer <id_token>
    //
    //  On success: updates Firebase Auth password, clears the three
    //  password-reset claims, writes an audit entry. Returns 200 with
    //  { status: 'success', message: '...' }.
    // ─────────────────────────────────────────────────────────────────────
    public function clear_must_change(): void
    {
        if ($this->input->method() !== 'post') {
            $this->_json(['status' => 'error', 'message' => 'POST only.'], 405);
            return;
        }

        // 1. Extract bearer token.
        $authHeader = (string) $this->input->get_request_header('Authorization', TRUE);
        if (stripos($authHeader, 'Bearer ') !== 0) {
            $this->_json(['status' => 'error', 'message' => 'Missing or malformed Authorization header.'], 401);
            return;
        }
        $idToken = trim(substr($authHeader, 7));
        if ($idToken === '') {
            $this->_json(['status' => 'error', 'message' => 'Empty bearer token.'], 401);
            return;
        }

        // 2. Verify token. Pass checkRevoked=true so a revoked refresh
        //    token (e.g. after admin reset) doesn't slip through.
        $claims = $this->firebase->verifyFirebaseToken($idToken, true);
        if ($claims === null || empty($claims['uid'])) {
            $this->_json(['status' => 'error', 'message' => 'Invalid or expired token.'], 401);
            return;
        }
        $uid        = (string) $claims['uid'];
        $role       = (string) ($claims['role'] ?? '');
        $schoolId   = (string) ($claims['school_id'] ?? '');
        $schoolCode = (string) ($claims['school_code'] ?? '');

        // 3. Confirm the user is actually in the forced-change state.
        //    Defence in depth — prevents this endpoint being used as a
        //    generic password-change API by tokens that don't carry the flag.
        $mustChange = (bool) $this->firebase->getCustomClaim($uid, 'must_change_password', false);
        if (!$mustChange) {
            $this->_json([
                'status'  => 'error',
                'message' => 'No password change required for this account.',
            ], 400);
            return;
        }

        // 4. Validate the new password.
        $newPassword = (string) $this->input->post('new_password', FALSE);
        if ($newPassword === '') {
            $this->_json(['status' => 'error', 'message' => 'New password is required.'], 400);
            return;
        }
        if (strlen($newPassword) < self::MIN_PW || strlen($newPassword) > self::MAX_PW) {
            $this->_json([
                'status'  => 'error',
                'message' => 'Password must be ' . self::MIN_PW . '–' . self::MAX_PW . ' characters.',
            ], 400);
            return;
        }
        if (!preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)) {
            $this->_json([
                'status'  => 'error',
                'message' => 'Password must contain an uppercase letter, a lowercase letter, and a digit.',
            ], 400);
            return;
        }

        // 5. Update Firebase Auth password.
        $updated = $this->firebase->updateFirebaseUser($uid, ['password' => $newPassword]);
        if ($updated === null) {
            $this->_json(['status' => 'error', 'message' => 'Failed to update password.'], 500);
            return;
        }

        // 6. Clear the must-change-password claim (preserves all other claims).
        $this->firebase->clearCustomClaims($uid, [
            'must_change_password', 'password_reset_at', 'password_reset_by',
        ]);

        // 7. Clear mustChangePassword on the Firestore profile doc (collection
        //    chosen from the role claim — keeps the app's force-change UI gate
        //    in sync with the cleared state).
        if ($schoolId !== '') {
            $this->_clear_firestore_flag($schoolId, $uid, $role);
            $this->_audit($schoolId, $uid, $role);
        }

        log_message('info', 'Auth_api::clear_must_change OK uid=' . $uid . ' role=' . $role);

        $this->_json([
            'status'  => 'success',
            'message' => 'Password updated.',
            'uid'     => $uid,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Clear mustChangePassword=false on the appropriate Firestore profile doc.
     * Collection is chosen from the role claim — parents land in students,
     * admin roles in admins, everything else (teacher/staff) in staff.
     */
    private function _clear_firestore_flag(string $schoolId, string $uid, string $role): void
    {
        try {
            $this->load->library('firestore_service', null, 'fs');
            $this->fs->init($schoolId);
            if (!$this->fs->isReady()) return;

            // FOLD: route by uid prefix, not role label. A demoted admin's record
            // lives in `staff` under an STA id; only legacy ADM ids remain in
            // `admins`. Parents stay in `students`.
            $this->load->helper('admin_roster');
            $collection = ($role === 'Parent') ? 'students' : admin_record_collection($uid);

            $this->fs->set($collection, $this->fs->docId($uid), [
                'mustChangePassword' => false,
                'updatedAt'          => date('c'),
            ], true);
        } catch (\Exception $e) {
            log_message('error', 'Auth_api::_clear_firestore_flag failed: ' . $e->getMessage());
        }
    }

    /**
     * Write an audit log entry for the self-change.
     * Mirrors audit_helper::log_audit() but doesn't read the CI session
     * (since this is a token-authenticated request, not session-based).
     */
    private function _audit(string $schoolId, string $uid, string $role): void
    {
        try {
            $this->load->library('firestore_service', null, 'fs');
            $this->fs->init($schoolId);
            if (!$this->fs->isReady()) return;

            $nowMs = (int) round(microtime(true) * 1000);
            $logId = 'AL_' . date('Ymd_His') . '_' . substr(uniqid('', true), -6);

            $module = ($role === 'Parent') ? 'SIS'
                    : (in_array($role, ['Admin','Principal','Vice Principal','Super Admin','School Super Admin','HR Manager','Front Office','Accountant','Academic Coordinator'], true)
                       ? 'AdminUsers' : 'Staff');

            $entry = [
                'schoolId'    => $schoolId,
                'logId'       => $logId,
                'userId'      => $uid,
                'userName'    => $uid,
                'userRole'    => $role,
                'module'      => $module,
                'action'      => 'self_clear_must_change',
                'entityId'    => $uid,
                'description' => 'Self-set password after admin-driven reset (mobile)',
                'ipAddress'   => $this->input->ip_address(),
                'timestamp'   => gmdate('Y-m-d\TH:i:s\Z', (int) ($nowMs / 1000)),
                'timestampMs' => $nowMs,
            ];

            $this->fs->set('auditLogs', $this->fs->docId($logId), $entry);
        } catch (\Exception $e) {
            log_message('error', 'Auth_api::_audit failed: ' . $e->getMessage());
        }
    }

    private function _json(array $payload, int $http = 200): void
    {
        $this->output
            ->set_status_header($http)
            ->set_output(json_encode($payload));
    }
}
