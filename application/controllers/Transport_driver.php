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

    // ═══════════════════════════════════════════════════════════════════
    //  Driver identity + meta
    // ═══════════════════════════════════════════════════════════════════

    public function list()
    {
        require_permission('Transport', 'transport_view', 'view');
        $this->json_success(['drivers' => $this->transport->listActiveDrivers()]);
    }

    public function get()
    {
        require_permission('Transport', 'transport_view', 'view');
        $sid = $this->safe_path_segment(trim($this->input->get('staff_id') ?? ''), 'staff_id');
        $this->json_success(['driver' => $this->transport->getDriver($sid)]);
    }

    public function save_meta()
    {
        // ── Batch 3 — MIXED-PURPOSE ENDPOINT, FIELD-PARTITIONED ──────────────
        //
        // Stays at Transport:edit deliberately: it carries legitimate driver
        // self-service (emergency contacts etc.) alongside the credential fields
        // that gate dispatch fitness. The partition is:
        //
        //   SENSITIVE  → additionally requires Transport:manage
        //   BENIGN     → Transport:edit, but ONLY on the actor's own record
        //
        // Authorization decisions are made on the fields the client ACTUALLY
        // submitted (post() !== null), never on the shape of this array — so a
        // sensitive field cannot be smuggled into an otherwise-benign request.
        // Graded RBAC remains the first boundary; everything here is additive and
        // uses the existing resolver via has_permission(). No RBAC change.
        require_permission('Transport', 'save_driver_meta', 'edit');
        $sid = $this->safe_path_segment(trim($this->input->post('staff_id') ?? ''), 'staff_id');

        $sensitive = Transport_service::DRIVER_META_SENSITIVE_FIELDS;
        $benign    = Transport_service::DRIVER_META_BENIGN_FIELDS;

        $submitted = [];
        foreach (array_merge($sensitive, $benign) as $f) {
            if ($this->input->post($f) !== null) { $submitted[] = $f; }
        }
        $hasSensitive = array_intersect($submitted, $sensitive) !== [];

        if ($submitted === []) {
            $this->json_error('No driver metadata fields were supplied.');
            return;
        }
        if ($hasSensitive && !has_permission('Transport', 'manage')) {
            $this->json_error(
                'Driver licence, medical, badge and operational-status fields require '
                . 'Transport · manage. Ask an administrator to update them.', 403);
            return;
        }
        if (!$hasSensitive && (string) $sid !== (string) ($this->admin_id ?? '')) {
            $this->json_error(
                'You may only update your own driver details. Editing another staff '
                . "member's record requires Transport · manage.", 403);
            return;
        }

        // Build ONLY the submitted keys. The service merges them over the stored
        // driver_meta, so unsubmitted fields are preserved rather than erased.
        $meta = [];
        foreach ($submitted as $f) {
            $raw = $this->input->post($f);
            switch ($f) {
                case 'years_experience':
                    $meta[$f] = (int) $raw; break;
                case 'replacement_availability':
                    $meta[$f] = ((string) $raw) === '1'; break;
                case 'can_drive_types':
                    $list = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? explode(',', $raw) : []);
                    $meta[$f] = array_map('trim', $list); break;
                default:
                    $meta[$f] = trim((string) $raw);
            }
        }
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
        require_permission('Transport', 'replace_driver', 'manage');
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
        require_permission('Transport', 'record_incident', 'edit');
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
            // Second arg = anySubject. Manage may file against anyone; an
            // edit-level caller is scoped to themselves or a trip they operated.
            // Existing Graded RBAC resolver, read only — no RBAC change.
            ], function_exists('has_permission') ? has_permission('Transport', 'manage') : false);
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
        require_permission('Transport', 'resolve_incident', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        $sid = trim($this->input->get('staff_id') ?? '');
        $sev = trim($this->input->get('severity') ?? '');
        $this->json_success(['incidents' => $this->transport->listDriverIncidents($sid, $sev)]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Performance reviews
    // ═══════════════════════════════════════════════════════════════════

    public function save_review()
    {
        require_permission('Transport', 'save_review', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        $sid = $this->safe_path_segment(trim($this->input->get('staff_id') ?? ''), 'staff_id');
        $this->json_success(['reviews' => $this->transport->listPerformanceReviews($sid)]);
    }

    public function activity_snapshot()
    {
        require_permission('Transport', 'transport_view', 'view');
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
        require_permission('Transport', 'assign_temp', 'manage');
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
        require_permission('Transport', 'resolve_temp', 'manage');
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
        require_permission('Transport', 'transport_view', 'view');
        $vid = trim($this->input->get('vehicle_id') ?? '');
        $activeOnly = ((string) ($this->input->get('active_only') ?? '1')) !== '0';
        $this->json_success(['assignments' => $this->transport->listTemporaryAssignments($vid, $activeOnly)]);
    }

    public function effective()
    {
        require_permission('Transport', 'transport_view', 'view');
        $vid = $this->safe_path_segment(trim($this->input->get('vehicle_id') ?? ''), 'vehicle_id');
        $onDate = trim($this->input->get('on_date') ?? '');
        $this->json_success(['effective_staff_id' => $this->transport->getEffectiveDriver($vid, $onDate === '' ? null : $onDate)]);
    }

    public function rebuild_activity()
    {
        require_permission('Transport', 'rebuild_activity', 'manage');
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
