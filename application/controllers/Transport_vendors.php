<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport Vendors — F1 Fleet Management sub-controller (2026-07-07).
 *
 * Vendor management endpoints live here (not in Transport.php) so the
 * primary Transport controller stays under the ≤300-LoC guideline and
 * DOMAIN_VENDOR remains cleanly separable if it is later extracted into
 * its own service.
 *
 * All endpoints delegate to Transport_service (DOMAIN_VENDOR methods).
 * Firestore rules for `transportVendors` were landed by Phase 0.
 *
 * Endpoints:
 *   GET  /transport_vendors/get_vendors               — list all (active + terminated)
 *   GET  /transport_vendors/get_active_vendors        — list Active only
 *   POST /transport_vendors/save_vendor               — create/update
 *   POST /transport_vendors/terminate_vendor          — status flip
 *   POST /transport_vendors/link_vendor_to_vehicle    — attach vendor to vehicle
 */
class Transport_vendors extends MY_Controller
{

    public function __construct()
    {
        parent::__construct();
        require_permission('Transport');
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
    }

    // ─── read endpoints ──────────────────────────────────────────────────

    public function get_vendors()
    {
        require_permission('Transport', 'transport_view', 'view');
        $this->json_success(['vendors' => $this->transport->listAllVendors()]);
    }

    public function get_active_vendors()
    {
        require_permission('Transport', 'transport_view', 'view');
        $this->json_success(['vendors' => $this->transport->listActiveVendors()]);
    }

    // ─── write endpoints ─────────────────────────────────────────────────

    public function save_vendor()
    {
        require_permission('Transport', 'save_vendor', 'manage');
        try {
            $result = $this->transport->saveVendor([
                'id'             => trim($this->input->post('id') ?? ''),
                'vendor_name'    => trim($this->input->post('vendor_name') ?? ''),
                'contact_person' => trim($this->input->post('contact_person') ?? ''),
                'phone'          => trim($this->input->post('phone') ?? ''),
                'email'          => trim($this->input->post('email') ?? ''),
                'gstin'          => trim($this->input->post('gstin') ?? ''),
                'address'        => trim($this->input->post('address') ?? ''),
                'status'         => trim($this->input->post('status') ?? ''),
                'notes'          => trim($this->input->post('notes') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_vendors::save_vendor failed: ' . $e->getMessage());
            $this->json_error('Failed to save vendor.');
        }
        $verb = $result['isNew'] ? 'created' : 'updated';
        $this->json_success(['id' => $result['id'], 'message' => "Vendor {$verb}."]);
    }

    public function terminate_vendor()
    {
        require_permission('Transport', 'terminate_vendor', 'manage');
        $id     = $this->safe_path_segment(trim($this->input->post('id') ?? ''), 'vendor_id');
        $reason = trim($this->input->post('reason') ?? '');
        try {
            $this->transport->terminateVendor($id, $reason);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_vendors::terminate_vendor failed: ' . $e->getMessage());
            $this->json_error('Failed to terminate vendor.');
        }
        $this->json_success(['message' => 'Vendor terminated.']);
    }

    public function link_vendor_to_vehicle()
    {
        require_permission('Transport', 'link_vendor_to_vehicle', 'manage');
        $vendorId  = $this->safe_path_segment(trim($this->input->post('vendor_id')  ?? ''), 'vendor_id');
        $vehicleId = $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id');
        $terms = [
            'monthly_hire_amount' => (float) ($this->input->post('monthly_hire_amount') ?? 0),
            'contract_start_date' => trim($this->input->post('contract_start_date') ?? ''),
            'contract_end_date'   => trim($this->input->post('contract_end_date') ?? ''),
            'contract_terms_path' => trim($this->input->post('contract_terms_path') ?? ''),
        ];
        try {
            $this->transport->linkVendorToVehicle($vendorId, $vehicleId, $terms);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_vendors::link_vendor_to_vehicle failed: ' . $e->getMessage());
            $this->json_error('Failed to link vendor.');
        }
        $this->json_success(['message' => 'Vendor linked to vehicle.']);
    }
}
