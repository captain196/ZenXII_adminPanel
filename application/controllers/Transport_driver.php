<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_driver — F4 Driver Management sub-controller (2026-07-07).
 *
 * Dedicated controller for driver identity + driver_meta + incidents +
 * reviews + temporary assignments + activity snapshot. HR staff master
 * ownership discipline enforced by Transport_service._assertStaffField
 * Discipline() at every write; verifier CH-TRN-F4-1 asserts source-scan
 * cleanliness.
 *
 * Endpoints:
 *   GET  /transport_driver/list
 *   GET  /transport_driver/get?staff_id=X
 *   POST /transport_driver/save_meta                (driver_meta subobject)
 *   POST /transport_driver/replace_permanently      (vehicle.staff_id mutation)
 *   POST /transport_driver/record_incident
 *   POST /transport_driver/resolve_incident
 *   GET  /transport_driver/list_incidents?staff_id&severity
 *   POST /transport_driver/save_review
 *   GET  /transport_driver/list_reviews?staff_id
 *   GET  /transport_driver/activity_snapshot?staff_id&from&to
 *   POST /transport_driver/assign_temp
 *   POST /transport_driver/resolve_temp
 *   GET  /transport_driver/list_temp?vehicle_id&active_only
 *   GET  /transport_driver/effective?vehicle_id&on_date
 *   POST /transport_driver/rebuild_activity        (admin — Refinement #4 F4)
 */
class Transport_driver extends MY_Controller
{
    private const MANAGE_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal',
        'Operations Manager', 'Transport Manager', 'HR Manager',
    ];
    private const VIEW_ROLES = [
        'Super Admin', 'School Super Admin', 'Admin', 'Principal', 'Vice Principal',
        'Operations Manager', 'Transport Manager', 'HR Manager', 'Accountant',
    ];

    public function __construct()
    {
        parent::__construct();
        require_permission('Operations');
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id ?? 'system'
        );
    }

    private function _require_manage(): void
    {
        if (!in_array($this->admin_role, self::MANAGE_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }
    private function _require_view(): void
    {
        if (!in_array($this->admin_role, self::VIEW_ROLES, true)) {
            $this->json_error('Access denied.', 403);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Driver identity + meta
    // ═══════════════════════════════════════════════════════════════════

    public function list()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $this->json_success(['drivers' => $this->transport->listActiveDrivers()]);
    }

    public function get()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $sid = $this->safe_path_segment(trim($this->input->get('staff_id') ?? ''), 'staff_id');
        $this->json_success(['driver' => $this->transport->getDriver($sid)]);
    }

    public function save_meta()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_driver_meta');
        $this->_require_manage();
        $sid = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');
        $canDriveTypes = $this->input->post('can_drive_types');
        $canDriveTypes = is_array($canDriveTypes) ? $canDriveTypes : (is_string($canDriveTypes) && $canDriveTypes !== '' ? explode(',', $canDriveTypes) : []);
        $meta = [
            'licence_no'              => trim($this->input->post('licence_no') ?? ''),
            'licence_class'           => trim($this->input->post('licence_class') ?? ''),
            'licence_issued'          => trim($this->input->post('licence_issued') ?? ''),
            'licence_expires'         => trim($this->input->post('licence_expires') ?? ''),
            'licence_authority'       => trim($this->input->post('licence_authority') ?? ''),
            'medical_check_last'      => trim($this->input->post('medical_check_last') ?? ''),
            'medical_expires'         => trim($this->input->post('medical_expires') ?? ''),
            'badge_no'                => trim($this->input->post('badge_no') ?? ''),
            'badge_expires'           => trim($this->input->post('badge_expires') ?? ''),
            'years_experience'        => (int) ($this->input->post('years_experience') ?? 0),
            'can_drive_types'         => array_map('trim', $canDriveTypes),
            'emergency_contact_name'  => trim($this->input->post('emergency_contact_name') ?? ''),
            'emergency_contact_phone' => trim($this->input->post('emergency_contact_phone') ?? ''),
            'replacement_availability'=> ((string) ($this->input->post('replacement_availability') ?? '')) === '1',
            'operational_status'      => trim($this->input->post('operational_status') ?? 'Available'),
        ];
        try {
            $this->transport->updateDriverMeta($sid, $meta);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\LogicException $e) {
            log_message('error', 'Transport_driver::save_meta discipline: ' . $e->getMessage());
            $this->json_error('HR field discipline violation.');
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::save_meta failed: ' . $e->getMessage());
            $this->json_error('Failed to save driver meta.');
        }
        $this->json_success(['message' => 'Driver meta updated.']);
    }

    public function replace_permanently()
    {
        $this->_require_role(self::MANAGE_ROLES, 'replace_driver');
        $this->_require_manage();
        try {
            $this->transport->replaceDriverPermanently(
                $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                $this->safe_path_segment(trim($this->input->post('new_staff_id') ?? ''), 'staff_id'),
                trim($this->input->post('reason') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::replace_permanently failed: ' . $e->getMessage());
            $this->json_error('Failed to replace driver.');
        }
        $this->json_success(['message' => 'Driver replaced.']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Incidents
    // ═══════════════════════════════════════════════════════════════════

    public function record_incident()
    {
        $this->_require_role(self::MANAGE_ROLES, 'record_incident');
        $this->_require_manage();
        $attachments = $this->input->post('attachment_paths');
        $attachments = is_array($attachments) ? $attachments : (is_string($attachments) && $attachments !== '' ? explode(',', $attachments) : []);
        try {
            $result = $this->transport->recordDriverIncident([
                'staff_id'          => $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id'),
                'incident_type'     => trim($this->input->post('incident_type') ?? ''),
                'incident_date'     => trim($this->input->post('incident_date') ?? date('Y-m-d')),
                'incident_time'     => trim($this->input->post('incident_time') ?? ''),
                'severity'          => trim($this->input->post('severity') ?? 'Minor'),
                'description'       => trim($this->input->post('description') ?? ''),
                'related_trip_id'   => trim($this->input->post('related_trip_id') ?? ''),
                'related_vehicle_id'=> trim($this->input->post('related_vehicle_id') ?? ''),
                'location'          => trim($this->input->post('location') ?? ''),
                'attachment_paths'  => array_map('trim', $attachments),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::record_incident failed: ' . $e->getMessage());
            $this->json_error('Failed to record incident.');
        }
        $this->json_success(['id' => $result['id'], 'message' => 'Incident recorded.']);
    }

    public function resolve_incident()
    {
        $this->_require_role(self::MANAGE_ROLES, 'resolve_incident');
        $this->_require_manage();
        try {
            $this->transport->resolveDriverIncident(
                $this->safe_path_segment(trim($this->input->post('incident_id') ?? ''), 'incident_id'),
                trim($this->input->post('resolution') ?? ''),
                trim($this->input->post('action_taken') ?? '')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::resolve_incident failed: ' . $e->getMessage());
            $this->json_error('Failed to resolve incident.');
        }
        $this->json_success(['message' => 'Incident resolved.']);
    }

    public function list_incidents()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $sid = trim($this->input->get('staff_id') ?? '');
        $sev = trim($this->input->get('severity') ?? '');
        $this->json_success(['incidents' => $this->transport->listDriverIncidents($sid, $sev)]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Performance reviews
    // ═══════════════════════════════════════════════════════════════════

    public function save_review()
    {
        $this->_require_role(self::MANAGE_ROLES, 'save_review');
        $this->_require_manage();
        $actions = $this->input->post('action_items');
        $actions = is_array($actions) ? $actions : (is_string($actions) && $actions !== '' ? explode(',', $actions) : []);
        try {
            $result = $this->transport->savePerformanceReview([
                'id'                    => trim($this->input->post('id') ?? ''),
                'staff_id'              => $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id'),
                'review_period_start'   => trim($this->input->post('review_period_start') ?? ''),
                'review_period_end'     => trim($this->input->post('review_period_end') ?? ''),
                'rating'                => (int) ($this->input->post('rating') ?? 3),
                'reviewer_staff_id'     => trim($this->input->post('reviewer_staff_id') ?? ''),
                'notes'                 => trim($this->input->post('notes') ?? ''),
                'action_items'          => array_map('trim', $actions),
                'next_review_due'       => trim($this->input->post('next_review_due') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::save_review failed: ' . $e->getMessage());
            $this->json_error('Failed to save review.');
        }
        $this->json_success(['id' => $result['id'], 'message' => 'Review saved.']);
    }

    public function list_reviews()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $sid = $this->safe_path_segment(trim($this->input->get('staff_id') ?? ''), 'staff_id');
        $this->json_success(['reviews' => $this->transport->listPerformanceReviews($sid)]);
    }

    public function activity_snapshot()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $sid  = $this->safe_path_segment(trim($this->input->get('staff_id') ?? ''), 'staff_id');
        $from = trim($this->input->get('from') ?? '');
        $to   = trim($this->input->get('to')   ?? '');
        $this->json_success(['snapshot' => $this->transport->getDriverActivitySnapshot($sid, $from, $to)]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Temporary assignments
    // ═══════════════════════════════════════════════════════════════════

    public function assign_temp()
    {
        $this->_require_role(self::MANAGE_ROLES, 'assign_temp');
        $this->_require_manage();
        try {
            $result = $this->transport->assignTemporaryDriver([
                'vehicle_id'        => $this->safe_path_segment(trim($this->input->post('vehicle_id') ?? ''), 'vehicle_id'),
                'covering_staff_id' => $this->safe_path_segment(trim($this->input->post('covering_staff_id') ?? ''), 'staff_id'),
                'effective_from'    => trim($this->input->post('effective_from') ?? ''),
                'effective_to'      => trim($this->input->post('effective_to') ?? ''),
                'reason'            => trim($this->input->post('reason') ?? ''),
                'notes'             => trim($this->input->post('notes') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\RuntimeException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::assign_temp failed: ' . $e->getMessage());
            $this->json_error('Failed to assign temporary driver.');
        }
        $this->json_success(['id' => $result['id'], 'message' => 'Temporary driver assigned.']);
    }

    public function resolve_temp()
    {
        $this->_require_role(self::MANAGE_ROLES, 'resolve_temp');
        $this->_require_manage();
        try {
            $this->transport->resolveTemporaryDriver(
                $this->safe_path_segment(trim($this->input->post('assignment_id') ?? ''), 'assignment_id'),
                trim($this->input->post('reason') ?? 'manual')
            );
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::resolve_temp failed: ' . $e->getMessage());
            $this->json_error('Failed to resolve temporary assignment.');
        }
        $this->json_success(['message' => 'Temporary assignment resolved.']);
    }

    public function list_temp()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = trim($this->input->get('vehicle_id') ?? '');
        $activeOnly = ((string) ($this->input->get('active_only') ?? '1')) !== '0';
        $this->json_success(['assignments' => $this->transport->listTemporaryAssignments($vid, $activeOnly)]);
    }

    public function effective()
    {
        $this->_require_role(self::VIEW_ROLES, 'transport_view');
        $this->_require_view();
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $onDate = trim($this->input->get('on_date') ?? '');
        $this->json_success(['effective_staff_id' => $this->transport->getEffectiveDriver($vid, $onDate === '' ? null : $onDate)]);
    }

    public function rebuild_activity()
    {
        $this->_require_role(self::MANAGE_ROLES, 'rebuild_activity');
        $this->_require_manage();
        $sid = trim($this->input->post('staff_id') ?? '');
        $ym  = trim($this->input->post('year_month') ?? '');
        try {
            $r = $this->transport->rebuildActivitySummary($sid, $ym);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver::rebuild_activity failed: ' . $e->getMessage());
            $this->json_error('Rebuild failed.');
        }
        $this->json_success([
            'rebuilt_count' => $r['rebuilt_count'],
            'year_month'    => $r['year_month'],
            'message'       => "Activity summary rebuilt for {$r['rebuilt_count']} driver(s), month {$r['year_month']}.",
        ]);
    }
}
