<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_event — F8 Transport Events controller (LC-23, 2026-07-07).
 *
 * Admin-facing HTTP surface for the event lifecycle:
 *   Planned → Scheduled → InProgress → Completed | Cancelled
 *
 * All state, validation, roster snapshotting, driver resolution, escort
 * management, cost aggregation, and notifications live in
 * Transport_service::DOMAIN_EVENT. This controller is a thin
 * auth+forwarding shell. Role-gated to Admin / Super Admin / Transport
 * Manager.
 *
 * Endpoints (14):
 *   POST /transport_event/create              — Planned
 *   POST /transport_event/update              — Planned/Scheduled edits
 *   POST /transport_event/assign_student
 *   POST /transport_event/unassign_student
 *   POST /transport_event/update_consent
 *   POST /transport_event/add_escort          — teacher escorts (Refinement +)
 *   POST /transport_event/remove_escort
 *   POST /transport_event/schedule            — Planned → Scheduled
 *   POST /transport_event/dispatch_trip       — creates tripLogs (kind=Event)
 *   POST /transport_event/complete            — InProgress → Completed
 *   POST /transport_event/cancel              — → Cancelled
 *   GET  /transport_event/list
 *   GET  /transport_event/get
 *   GET  /transport_event/roster
 *   GET  /transport_event/trips
 *   GET  /transport_event/escorts
 *   GET  /transport_event/cost_summary
 */
class Transport_event extends MY_Controller
{
    private const REQUIRED_ROLES = ['Admin', 'Super Admin', 'Transport Manager'];

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firestore_service', null, 'fs');
        $this->load->library('numbering_service', null, 'numbering');
        $this->load->library('transport_service', null, 'transport');

        $sid  = (string) ($this->session->userdata('school_id')    ?? '');
        $sess = (string) ($this->session->userdata('session_year') ?? '');
        $uid  = (string) ($this->session->userdata('user_id')      ?? 'admin');
        if ($sid === '' || $sess === '') {
            $this->_json(['status' => 'error', 'message' => 'Session missing school/session context.']);
            exit;
        }
        $role = (string) ($this->admin_role ?? '');
        if (!in_array($role, self::REQUIRED_ROLES, true)) {
            $this->_json(['status' => 'error', 'message' => 'Access denied.']);
            exit;
        }
        $this->fs->init($sid, $sess);
        $this->numbering->init($this->fs, $sid, $sess);
        $this->transport->init($this->fs, $this->numbering, $sid, $sess, $uid);
    }

    public function create(): void
    {
        try {
            $escortsJson = (string) $this->input->post('escorts_json');
            $vehiclesJson = (string) $this->input->post('assigned_vehicle_ids_json');
            $r = $this->transport->createEvent([
                'title'                   => (string) $this->input->post('title'),
                'event_type'              => (string) $this->input->post('event_type'),
                'description'             => (string) $this->input->post('description'),
                'event_date'              => (string) $this->input->post('event_date'),
                'event_end_date'          => (string) $this->input->post('event_end_date'),
                'event_start_at'          => (string) $this->input->post('event_start_at'),
                'event_end_at'            => (string) $this->input->post('event_end_at'),
                'destination'             => (string) $this->input->post('destination'),
                'pickup_location'         => (string) $this->input->post('pickup_location'),
                'drop_location'           => (string) $this->input->post('drop_location'),
                'linked_school_event_id'  => (string) $this->input->post('linked_school_event_id'),
                'transport_charter_type'  => (string) $this->input->post('transport_charter_type'),
                'assigned_vehicle_ids'    => $vehiclesJson !== '' ? (array) json_decode($vehiclesJson, true) : [],
                'escorts'                 => $escortsJson !== ''  ? (array) json_decode($escortsJson,  true) : [],
                'expected_students_count' => (int)    $this->input->post('expected_students_count'),
                'budgeted_amount'         => (float)  $this->input->post('budgeted_amount'),
                'notes'                   => (string) $this->input->post('notes'),
            ]);
            $this->_json(['status' => 'success', 'message' => "Event {$r['eventId']} created (Planned).", 'event' => $r['data']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function update(): void
    {
        try {
            $body = [];
            foreach (['title','description','destination','pickup_location','drop_location',
                      'event_start_at','event_end_at','transport_charter_type','notes',
                      'budgeted_amount','linked_school_event_id','expected_students_count'] as $k) {
                if ($this->input->post($k) !== null && $this->input->post($k) !== '') {
                    $body[$k] = $this->input->post($k);
                }
            }
            $vJson = (string) $this->input->post('assigned_vehicle_ids_json');
            if ($vJson !== '') { $body['assigned_vehicle_ids'] = (array) json_decode($vJson, true); }
            $eJson = (string) $this->input->post('escorts_json');
            if ($eJson !== '') { $body['escorts'] = (array) json_decode($eJson, true); }
            $r = $this->transport->updateEvent((string) $this->input->post('id'), $body);
            $this->_json(['status' => 'success', 'message' => 'Event updated.', 'event' => $r['data']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function assign_student(): void
    {
        try {
            $r = $this->transport->assignStudentToEvent(
                (string) $this->input->post('event_id'),
                (string) $this->input->post('student_id'),
                [
                    'assigned_vehicle_id' => (string) $this->input->post('assigned_vehicle_id'),
                    'pickup_stop'         => (string) $this->input->post('pickup_stop'),
                    'drop_stop'           => (string) $this->input->post('drop_stop'),
                ]
            );
            $this->_json(['status' => 'success', 'message' => $r['duplicate'] ? 'Already assigned.' : 'Student added to roster.', 'assignment' => $r['data']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function unassign_student(): void
    {
        try {
            $this->transport->unassignStudentFromEvent(
                (string) $this->input->post('event_id'),
                (string) $this->input->post('student_id')
            );
            $this->_json(['status' => 'success', 'message' => 'Student removed from roster.']);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function update_consent(): void
    {
        try {
            $r = $this->transport->updateEventConsent(
                (string) $this->input->post('assignment_id'),
                (string) $this->input->post('consent_status'),
                (string) $this->input->post('notes')
            );
            $this->_json(['status' => 'success', 'message' => 'Consent updated.', 'assignment' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function add_escort(): void
    {
        try {
            $r = $this->transport->addEventEscort(
                (string) $this->input->post('event_id'),
                [
                    'staff_id' => (string) $this->input->post('staff_id'),
                    'role'     => (string) $this->input->post('role'),
                    'notes'    => (string) $this->input->post('notes'),
                ]
            );
            $this->_json(['status' => 'success', 'message' => 'Escort added.', 'escorts' => $r['escorts']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function remove_escort(): void
    {
        try {
            $r = $this->transport->removeEventEscort(
                (string) $this->input->post('event_id'),
                (string) $this->input->post('staff_id')
            );
            $this->_json(['status' => 'success', 'message' => 'Escort removed.', 'escorts' => $r['escorts']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function schedule(): void
    {
        try {
            $r = $this->transport->scheduleEvent((string) $this->input->post('id'));
            $this->_json(['status' => 'success', 'message' => "Event Scheduled ({$r['roster_size']} students; parents notified).", 'event' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function dispatch_trip(): void
    {
        try {
            $r = $this->transport->dispatchEventTrip((string) $this->input->post('event_id'), [
                'vehicle_id'         => (string) $this->input->post('vehicle_id'),
                'route_id'           => (string) $this->input->post('route_id'),
                'trip_direction'     => (string) $this->input->post('trip_direction'),
                'scheduled_start_at' => (string) $this->input->post('scheduled_start_at'),
            ]);
            $this->_json(['status' => 'success', 'message' => "Trip {$r['tripId']} dispatched.", 'result' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function complete(): void
    {
        try {
            $r = $this->transport->completeEvent(
                (string) $this->input->post('id'),
                (string) $this->input->post('notes')
            );
            $msg = 'Event completed.';
            if (!empty($r['over_budget'])) { $msg .= ' WARNING: over budget.'; }
            $this->_json(['status' => 'success', 'message' => $msg, 'event' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function cancel(): void
    {
        try {
            $r = $this->transport->cancelEvent(
                (string) $this->input->post('id'),
                (string) $this->input->post('reason')
            );
            $this->_json(['status' => 'success', 'message' => 'Event cancelled.', 'event' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function list_all(): void
    {
        try {
            $events = $this->transport->listEvents([
                'status'     => (string) $this->input->get('status'),
                'event_type' => (string) $this->input->get('event_type'),
                'event_date' => (string) $this->input->get('event_date'),
            ]);
            $this->_json(['status' => 'success', 'events' => $events]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function get(): void
    {
        try {
            $event = $this->transport->getEvent((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'event' => $event]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function roster(): void
    {
        try {
            $roster = $this->transport->getEventRoster((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'roster' => $roster]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function trips(): void
    {
        try {
            $trips = $this->transport->getEventTrips((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'trips' => $trips]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function escorts(): void
    {
        try {
            $escorts = $this->transport->getEventEscorts((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'escorts' => $escorts]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function cost_summary(): void
    {
        try {
            $summary = $this->transport->computeEventCostSummary((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'summary' => $summary]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function _json(array $payload): void
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($payload));
    }
}
