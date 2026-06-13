<?php
defined('BASEPATH') OR exit('No direct script access allowed');

require_once APPPATH . 'core/MY_Superadmin_Controller.php';

/**
 * Superadmin_school_admins
 * Manage the School Super Admin (SSA) account that is auto-created for every
 * school during onboarding. One SSA per school (the primary, created at
 * onboard time). Mirrors the Super Admins section, but the records live in
 * the tenant space rather than the developer space:
 *   - Credentials  : Firebase Auth  (uid == SSAxxxx; login authenticates here).
 *   - Profile/status : RTDB Users/Admin/{school_code}/{SSAxxxx} (held bridge).
 *   - School link  : Firestore schools/{schoolId}.schoolCode.
 *
 * Visible to and controllable by EVERY super admin (no developer gate) — the
 * SSA list is operational tenant-support surface, not developer-account admin.
 */
class Superadmin_school_admins extends MY_Superadmin_Controller
{
    public function __construct()
    {
        parent::__construct();
        // Ssa_reset's constructor references a non-existent "Firestore" class but
        // skips that load when $CI->fs is already set. The superadmin base
        // controller (unlike the tenant MY_Controller) does not preload it, so we
        // bind the real firestore_service to the 'fs' alias first.
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('Ssa_reset', null, 'ssa_reset');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAGE: List every school's School Super Admin
    // ─────────────────────────────────────────────────────────────────────────

    public function index()
    {
        $this->load->view('superadmin/include/sa_header');
        $this->load->view('superadmin/school_admins/index');
        $this->load->view('superadmin/include/sa_footer');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX: Fetch one SSA row per school (the onboarding-created super admin)
    // ─────────────────────────────────────────────────────────────────────────

    public function fetch()
    {
        // Firestore-canonical: schools and their onboarding-created SSA live in
        // Firestore only. Read the same tenant registry the Schools page uses,
        // so every school visible there also surfaces its SSA here.
        $this->_fetch_firestore();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Firestore-canonical SSA list (b2.registry_firestore = ON).
    //   - Tenants come from the same list_tenants_summary() the Schools page
    //     uses (schoolId, schoolName, schoolCode, primarySsaId).
    //   - The SSA profile (name/email/status) is the canonical schoolSsa/{ssaId}
    //     doc written at onboarding.
    //   - Last login + live status still read from the held-bridge RTDB record
    //     when present (that is what toggle_status writes); falls back to the
    //     schoolSsa status otherwise.
    // ─────────────────────────────────────────────────────────────────────────

    private function _fetch_firestore()
    {
        $rows = [];
        try {
            // Two collection queries only — NOT per-row reads. The schoolSsa doc
            // is the canonical SSA record (name/email/status/schoolId/schoolCode);
            // the schools query just supplies the display name. Per-tenant reads
            // (the old approach) cost N sequential Firestore round trips and blew
            // past PHP's max_execution_time on the slow local REST client.
            $ssaDocs    = (array) $this->firebase->firestoreQuery('schoolSsa');
            $schoolDocs = (array) $this->firebase->firestoreQuery('schools');

            // Index school display names by schoolId (the doc id).
            $names = [];
            foreach ($schoolDocs as $row) {
                $id = (string) ($row['id'] ?? '');
                $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;
                if ($id === '' && isset($d['schoolId'])) $id = (string) $d['schoolId'];
                if ($id === '') continue;
                $nm = (string) ($d['schoolName'] ?? $d['name'] ?? '');
                if ($nm !== '') $names[$id] = $nm;
            }

            foreach ($ssaDocs as $row) {
                $id = (string) ($row['id'] ?? '');
                $d  = is_array($row['data'] ?? null) ? $row['data'] : $row;
                $ssa_id = ($id !== '') ? $id : (string) ($d['ssaId'] ?? $d['adminId'] ?? '');
                if ($ssa_id === '') continue;

                $school_uid  = (string) ($d['schoolId']   ?? '');
                $school_code = (string) ($d['schoolCode'] ?? '');
                $school_name = $names[$school_uid] ?? ($school_uid !== '' ? $school_uid : '—');

                $name  = (string) ($d['name']  ?? '');
                $email = (string) ($d['email'] ?? '');
                if ($name === '') $name = $ssa_id;

                $ssaStatus = (string) ($d['status'] ?? 'Active');
                $status    = (strcasecmp($ssaStatus, 'Active') === 0) ? 'Active' : 'Inactive';

                $rows[] = [
                    'ssa_id'      => $ssa_id,
                    'name'        => $name,
                    'email'       => $email,
                    'phone'       => (string) ($d['phone'] ?? ''),
                    'status'      => $status,
                    'school_name' => $school_name,
                    'school_code' => $school_code,
                    'school_uid'  => $school_uid,
                    'last_login'  => '',  // last-login enrichment intentionally skipped (kept fast)
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'SA school_admins fetch (firestore) failed: ' . $e->getMessage());
        }

        usort($rows, fn($a, $b) => strcmp($a['school_name'], $b['school_name']));
        $this->json_success(['admins' => $rows]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function _registry()
    {
        $this->load->library('b2_registry_service');
        $this->b2_registry_service->init($this->firebase);
        return $this->b2_registry_service;
    }

    /**
     * Resolve a school's login code from its uid via the canonical Firestore
     * schools/{uid} doc.
     */
    private function _resolve_school_code(string $school_uid): string
    {
        $doc = $this->firebase->firestoreGet('schools', $school_uid);
        return is_array($doc) ? (string) ($doc['schoolCode'] ?? '') : '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Toggle status (Active/Inactive)
    //   - Firebase Auth `disabled` is the credential gate.
    //   - RTDB Status carries the status field readers see.
    // ─────────────────────────────────────────────────────────────────────────

    public function toggle_status()
    {
        $school_uid = trim((string) ($this->input->post('school_uid', TRUE) ?? ''));
        $ssa_id     = trim((string) ($this->input->post('ssa_id', TRUE) ?? ''));

        if ($school_uid === '' || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.');
        }
        if ($ssa_id === '' || !preg_match('/^SSA\d+$/', $ssa_id)) {
            $this->json_error('Invalid SSA id.');
        }

        $school_code = $this->_resolve_school_code($school_uid);
        if ($school_code === '') {
            $this->json_error('School not found or missing school code.', 404);
        }

        $path     = "Users/Admin/{$school_code}/{$ssa_id}";
        $existing = $this->firebase->get($path);
        if (empty($existing) || !is_array($existing)) {
            $this->json_error('School Super Admin not found in this school.', 404);
        }

        $current    = (string) ($existing['Status'] ?? 'Active');
        $new_status = (strcasecmp($current, 'Active') === 0) ? 'Inactive' : 'Active';

        // Firebase Auth is the credential authority (uid == ssa_id).
        $updated = $this->firebase->updateFirebaseUser($ssa_id, ['disabled' => ($new_status === 'Inactive')]);
        if ($updated === null) {
            $this->json_error('Failed to update the login account (Firebase Auth). Status unchanged - please retry.');
        }
        $this->firebase->update($path, ['Status' => $new_status]);

        // Deactivating kicks active sessions on every device.
        if ($new_status === 'Inactive') {
            try { $this->firebase->revokeRefreshTokens($ssa_id); } catch (\Throwable $e) {}
        }

        // Mirror to Firestore admins/{ssa} only if the doc exists.
        try {
            $existingFs = $this->firebase->firestoreGet('admins', $ssa_id);
            if (!empty($existingFs)) {
                $this->firebase->firestoreSet('admins', $ssa_id, ['status' => $new_status], true);
            }
        } catch (\Throwable $e) {
            log_message('error', 'SA school_admins toggle: Firestore mirror failed for ' . $ssa_id . ': ' . $e->getMessage());
        }

        $this->sa_log('Toggled SSA status', $school_uid, [
            'ssa_id' => $ssa_id,
            'from'   => $current,
            'to'     => $new_status,
        ]);

        $this->json_success([
            'message'    => 'School Super Admin "' . $ssa_id . '" is now ' . $new_status . '.',
            'new_status' => $new_status,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX POST: Reset password (delegates to the shared Ssa_reset library —
    //   same path used by Superadmin_schools/reset_ssa_password).
    // ─────────────────────────────────────────────────────────────────────────

    public function reset_password()
    {
        $school_uid   = trim((string) ($this->input->post('school_uid', TRUE) ?? ''));
        $ssa_id       = trim((string) ($this->input->post('ssa_id', TRUE) ?? ''));
        $new_password = (string) ($this->input->post('new_password', FALSE) ?? '');

        if ($school_uid === '' || !preg_match("/^[A-Za-z0-9 ',_\-]+$/u", $school_uid)) {
            $this->json_error('Invalid school identifier.');
        }
        if ($ssa_id === '' || !preg_match('/^SSA\d+$/', $ssa_id)) {
            $this->json_error('Invalid SSA id.');
        }

        $school_code = $this->_resolve_school_code($school_uid);
        if ($school_code === '') {
            $this->json_error('School not found or missing school code.', 404);
        }

        $result = $this->ssa_reset->resetSsaPassword(
            $school_code,
            $school_uid,    // school_uid is the Firestore school_id (SCH_XXXXXX)
            $ssa_id,
            $new_password,
            'SA:' . (string) $this->sa_id
        );

        if (empty($result['success'])) {
            $this->json_error($result['message'] ?? 'Reset failed.');
        }

        $this->sa_log('ssa_password_reset', $school_uid, [
            'ssa_id'   => $ssa_id,
            'ssa_name' => $result['ssa_name'],
        ]);

        $this->json_success([
            'message' => $result['message'],
            'ssa_id'  => $ssa_id,
            'name'    => $result['ssa_name'],
        ]);
    }
}
