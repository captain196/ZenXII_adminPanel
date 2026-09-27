<?php
defined('BASEPATH') OR exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_rollout — the operator surface for TECHNICAL ROLLOUT flags.
 *
 * Deliberately its own controller, not an extra tab on Superadmin_plans: plans
 * decide what a school has BOUGHT, this decides what is SAFE TO SWITCH ON there.
 * Keeping them apart is what stops a subscription edit from moving a financial
 * switch — the same separation `rolloutFlags` has from `activeModules` in the
 * data model.
 *
 * Superadmin-only by construction (MY_Superadmin_Controller enforces the
 * session, the password-change gate and the CSRF token before any method here
 * runs). One tenant per call; no bulk endpoint exists.
 *
 * ⚠ Nothing here has been invoked. Creating the first real grant is T4, a
 *   separate authorization and the first production write of this programme.
 */
class Superadmin_rollout extends MY_Superadmin_Controller
{
    /** Human labels for the closed grantable set — the service owns the list. */
    private const LABELS = [
        'USE_FEE_HEAD_REGISTRY'           => 'M5 — Fee Head Registry classification',
        'USE_ENTITLEMENT_BILLING'         => 'Student Entitlements V2 — billing authority',
        'USE_ENTITLEMENT_SERVICE_PRICING' => 'Entitlement-aware Transport/Hostel pricing',
        'ENTITLEMENT_SHADOW_COMPARE'      => 'Entitlement A/B shadow comparison (read-only)',
    ];

    private function _svc()
    {
        $this->load->library('firebase');
        $this->load->library('rollout_grant_service', null, 'rolloutGrant');
        return $this->rolloutGrant->init($this->firebase, (string) ($this->sa_id ?? ''));
    }

    /**
     * GET/POST — what is granted for one school, plus the grantable vocabulary
     * and each flag's GLOBAL DEFAULT, so the screen can show what a grant would
     * actually change rather than a bare toggle.
     */
    public function for_school()
    {
        $schoolId = $this->safe_segment(trim((string) $this->input->post('school_id')), 'school_id');

        $this->config->load('fee_head_registry',      true, true);
        $this->config->load('fees_exemption_v2_flags', true, true);
        $globals = [
            'USE_FEE_HEAD_REGISTRY'           => (bool) $this->config->item('USE_FEE_HEAD_REGISTRY', 'fee_head_registry'),
            'USE_ENTITLEMENT_BILLING'         => (bool) $this->config->item('USE_ENTITLEMENT_BILLING', 'fees_exemption_v2_flags'),
            'USE_ENTITLEMENT_SERVICE_PRICING' => (bool) $this->config->item('USE_ENTITLEMENT_SERVICE_PRICING', 'fees_exemption_v2_flags'),
            'ENTITLEMENT_SHADOW_COMPARE'      => (bool) $this->config->item('ENTITLEMENT_SHADOW_COMPARE', 'fees_exemption_v2_flags'),
        ];

        $this->json_success([
            'schoolId'  => $schoolId,
            'grants'    => $this->_svc()->forSchool($schoolId),
            'grantable' => Rollout_grant_service::GRANTABLE,
            'labels'    => self::LABELS,
            'globals'   => $globals,
        ]);
    }

    /**
     * POST — grant or disable ONE flag for ONE school.
     *
     * `enabled` is read explicitly rather than defaulted: a rollout switch must
     * never be flipped by an omitted field.
     */
    public function set()
    {
        $schoolId = $this->safe_segment(trim((string) $this->input->post('school_id')), 'school_id');
        $flag     = trim((string) $this->input->post('flag'));
        $reason   = trim((string) $this->input->post('reason'));
        $expires  = trim((string) ($this->input->post('expires_at') ?? ''));

        $enabledRaw = $this->input->post('enabled');
        if ($enabledRaw === null || $enabledRaw === false || $enabledRaw === '') {
            $this->json_error('`enabled` must be supplied explicitly (1 or 0).');
            return;
        }
        $enabled = in_array((string) $enabledRaw, ['1', 'true', 'on', 'yes'], true);

        $res = $this->_svc()->set($schoolId, $flag, $enabled, $reason, $expires !== '' ? $expires : null);
        if (empty($res['ok'])) { $this->json_error((string) ($res['error'] ?? 'Could not save.')); return; }

        $this->sa_log($enabled ? 'rollout_flag_granted' : 'rollout_flag_disabled', $schoolId,
                      ['flag' => $flag, 'reason' => $reason, 'expiresAt' => $expires]);
        $this->json_success(['flag' => $flag, 'schoolId' => $schoolId, 'enabled' => $enabled]);
    }

    /** POST — remove the override so the flag reverts to its global default. */
    public function revoke()
    {
        $schoolId = $this->safe_segment(trim((string) $this->input->post('school_id')), 'school_id');
        $flag     = trim((string) $this->input->post('flag'));
        $reason   = trim((string) $this->input->post('reason'));

        $res = $this->_svc()->revoke($schoolId, $flag, $reason);
        if (empty($res['ok'])) { $this->json_error((string) ($res['error'] ?? 'Could not revoke.')); return; }

        $this->sa_log('rollout_flag_revoked', $schoolId, ['flag' => $flag, 'reason' => $reason]);
        $this->json_success(['flag' => $flag, 'schoolId' => $schoolId, 'reverted' => true]);
    }
}
