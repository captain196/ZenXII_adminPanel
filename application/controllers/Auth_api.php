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

        // verifyFirebaseToken only copies out the snake_case tenant keys, and a
        // few legacy accounts carry camelCase only. Fall back to the raw claim so
        // the mirror write below stays reachable — when $schoolId came back empty
        // the entire Firestore clear used to be skipped in silence, which is one
        // of the ways an account ends up claim-cleared but mirror-still-true.
        if ($schoolId === '') {
            $schoolId = (string) $this->firebase->getCustomClaim($uid, 'schoolId', '');
        }

        $this->load->helper('force_change');
        $collection = force_change_profile_collection($uid, $role);

        // 3. Confirm the user is actually in the forced-change state.
        //    Defence in depth — this must not become a generic password-change
        //    API for tokens carrying no pending reset.
        //
        //    The predicate is (claim OR mirror), which is exactly what both apps
        //    gate on. Requiring the CLAIM alone made this endpoint NON-IDEMPOTENT:
        //    once step 6 cleared the claim, any failure before the client saw the
        //    response — dropped connection, app killed, 502, or a later wholesale
        //    claim re-mint that dropped the flag — left the user permanently
        //    unable to retry while their app kept showing the force-change screen
        //    and rejecting every submit with "No password change required".
        $claimFlag  = $this->firebase->getCustomClaim($uid, 'must_change_password', false);
        $mirrorFlag = $this->_read_firestore_flag($schoolId, $uid, $collection);

        if (!force_change_is_pending($claimFlag, $mirrorFlag)) {
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

        // 7. Clear mustChangePassword on the profile doc the apps gate on.
        //    Runs unconditionally, including when the claim was already gone:
        //    a stale `true` here is precisely what re-gates the user on their
        //    next cold start, so this is also the self-healing path for accounts
        //    left stuck by the earlier role-label misrouting.
        if ($schoolId !== '') {
            $cleared = $this->_clear_firestore_flag($schoolId, $uid, $collection);
            if (!$cleared) {
                // Fail LOUD. Reporting success here is what produced the lockout:
                // the claim is gone, so the client believes it is done, while the
                // mirror still gates it and every retry is refused. Telling the
                // user to try again keeps the account recoverable.
                log_message('error',
                    'Auth_api::clear_must_change mirror clear FAILED uid=' . $uid
                    . ' coll=' . $collection . ' school=' . $schoolId);
                $this->_json([
                    'status'  => 'error',
                    'message' => 'Your password was updated, but the change could not be finalised. Please try again.',
                ], 500);
                return;
            }
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
     * Read the mustChangePassword mirror off the profile doc.
     *
     * Returns null when the doc is missing or unreadable — the caller treats
     * null as "no signal" rather than "not pending", so a transient Firestore
     * failure can never on its own authorise a password set.
     *
     * @return bool|null
     */
    private function _read_firestore_flag(string $schoolId, string $uid, string $collection)
    {
        if ($schoolId === '') return null;
        try {
            $doc = $this->firebase->firestoreGet($collection, $schoolId . '_' . $uid);
            if (!is_array($doc) || !array_key_exists('mustChangePassword', $doc)) return null;
            return force_change_truthy($doc['mustChangePassword']);
        } catch (\Throwable $e) {
            log_message('error', 'Auth_api::_read_firestore_flag failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Set mustChangePassword=false on the profile doc the apps gate on.
     *
     * The collection is resolved by the CALLER via force_change_profile_collection(),
     * which routes on the id prefix. This method previously chose it from the role
     * claim — its own comment said "route by uid prefix, not role label" while the
     * code did the opposite, so a first-login student (role 'student', not 'Parent')
     * had their clear written to `admins/{schoolId}_STU####` and stayed gated.
     *
     * @return bool true when the write landed; false is a hard failure the caller
     *              must surface rather than swallow.
     */
    private function _clear_firestore_flag(string $schoolId, string $uid, string $collection): bool
    {
        try {
            $this->load->library('firestore_service', null, 'fs');
            $this->fs->init($schoolId);
            if (!$this->fs->isReady()) {
                log_message('error', 'Auth_api::_clear_firestore_flag — Firestore not ready for ' . $schoolId);
                return false;
            }

            return (bool) $this->fs->set($collection, $this->fs->docId($uid), [
                'mustChangePassword' => false,
                'updatedAt'          => date('c'),
            ], true);
        } catch (\Exception $e) {
            log_message('error', 'Auth_api::_clear_firestore_flag failed: ' . $e->getMessage());
            return false;
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
