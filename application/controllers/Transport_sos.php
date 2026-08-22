<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_sos — F6 SOS admin controller (LC-13, 2026-07-07).
 *
 * Admin-facing HTTP surface for the SOS lifecycle. Runtime state is owned
 * by Transport_notifier (which also fires the URGENT cascade); this
 * controller is a thin auth+forwarding shell.
 *
 * Endpoints:
 *   POST /transport_sos/raise    — raise a new SOS (admin-fired MVP)
 *   POST /transport_sos/respond  — mark active SOS as responded
 *   POST /transport_sos/resolve  — mark SOS as resolved
 *   GET  /transport_sos/list     — list SOS alerts (optional status filter)
 *   GET  /transport_sos/get      — fetch single SOS by id
 *
 * Teacher-app SOS write path lands in F9 (LC-22); this controller is
 * admin-only for now. Escalation is manual per operator Refinement 1.
 */
class Transport_sos extends MY_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firestore_service',  null, 'fs');
        $this->load->library('numbering_service',  null, 'numbering');
        $this->load->library('transport_service',  null, 'transport');
        $this->load->library('transport_notifier', null, 'transportNotifier');

        $sid  = (string) ($this->session->userdata('school_id')     ?? '');
        $sess = (string) ($this->session->userdata('session_year') ?? '');
        $uid  = (string) ($this->session->userdata('user_id')       ?? 'admin');
        if ($sid === '' || $sess === '') {
            $this->_json(['status' => 'error', 'message' => 'Session missing school/session context.']);
            exit;
        }
        require_permission('Transport', 'transport_sos', 'view');
        $this->fs->init($sid, $sess);
        $this->numbering->init($this->fs, $sid, $sess);
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, $uid);
        $this->transportNotifier->init($this->fs, $this->numbering, $this->transport, $sid, $sess, $uid);
    }

    public function raise(): void
    {
        require_permission('Transport', 'sos_raise', 'edit');
        try {
            $r = $this->transportNotifier->raiseSos([
                'triggered_by'      => (string) $this->session->userdata('user_id'),
                'triggered_by_role' => (string) $this->admin_role,
                'vehicle_id'        => (string) $this->input->post('vehicle_id'),
                'route_id'          => (string) $this->input->post('route_id'),
                'lat'               => (float)  $this->input->post('lat'),
                'lng'               => (float)  $this->input->post('lng'),
                'alert_type'        => (string) $this->input->post('alert_type') ?: 'emergency',
                'message'           => (string) $this->input->post('message'),
            ]);
            $this->_json(['status' => 'success', 'message' => "SOS {$r['sos_id']} raised.", 'sos_id' => $r['sos_id'], 'notify' => $r['notify']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function respond(): void
    {
        require_permission('Transport', 'sos_respond', 'manage');
        try {
            $r = $this->transportNotifier->respondSos(
                (string) $this->input->post('sos_id'),
                (string) $this->input->post('notes')
            );
            $this->_json(['status' => 'success', 'message' => 'SOS marked responded.', 'sos_id' => $r['sos_id']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function resolve(): void
    {
        require_permission('Transport', 'sos_resolve', 'manage');
        try {
            $r = $this->transportNotifier->resolveSos(
                (string) $this->input->post('sos_id'),
                (string) $this->input->post('notes')
            );
            $this->_json(['status' => 'success', 'message' => 'SOS resolved.', 'sos_id' => $r['sos_id']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function list_all(): void
    {
        $status = (string) $this->input->get('status');
        $limit  = (int) ($this->input->get('limit') ?: 50);
        try {
            $rows = $this->transportNotifier->listSos($limit, $status !== '' ? $status : null);
            $this->_json(['status' => 'success', 'alerts' => $rows]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function get(): void
    {
        $id = (string) $this->input->get('id');
        if ($id === '') { $this->_json(['status' => 'error', 'message' => 'id required']); return; }
        try {
            $doc = $this->fs->getEntity('sosAlerts', $id);
            $this->_json(['status' => 'success', 'alert' => is_array($doc) ? $doc : null]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function _json(array $payload): void
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($payload));
    }
}
