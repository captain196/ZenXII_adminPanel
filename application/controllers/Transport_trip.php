<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_trip — F7 Trip Logs + Transport Attendance controller
 * (LC-17 + LC-18, 2026-07-07).
 *
 * Admin-facing HTTP surface for the trip lifecycle:
 *   Ready → InProgress → Completed | Cancelled
 *
 * All state and validation lives in Transport_service::DOMAIN_TRIP; this
 * controller is a thin auth+forwarding shell. Teacher-app driver-facing
 * trip write path lands in F9 (LC-22 already reserves it) — F7 is the
 * admin-fired MVP.
 *
 * Endpoints:
 *   POST /transport_trip/create                — Ready state
 *   POST /transport_trip/start                 — Ready → InProgress
 *   POST /transport_trip/checkpoint_reached
 *   POST /transport_trip/checkpoint_left
 *   POST /transport_trip/checkpoint_skipped
 *   POST /transport_trip/board_student
 *   POST /transport_trip/drop_student
 *   POST /transport_trip/no_show_student
 *   POST /transport_trip/complete              — InProgress → Completed
 *   POST /transport_trip/cancel                — → Cancelled
 *   GET  /transport_trip/list
 *   GET  /transport_trip/get
 *   GET  /transport_trip/roster
 *   GET  /transport_trip/events
 */
class Transport_trip extends MY_Controller
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
        $role = (string) ($this->session->userdata('role') ?? '');
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
            $r = $this->transport->createTrip([
                'vehicle_id'        => (string) $this->input->post('vehicle_id'),
                'route_id'          => (string) $this->input->post('route_id'),
                'trip_date'         => (string) $this->input->post('trip_date'),
                'trip_direction'    => (string) $this->input->post('trip_direction'),
                'scheduled_start_at'=> (string) $this->input->post('scheduled_start_at'),
                'notes'             => (string) $this->input->post('notes'),
            ]);
            $this->_json(['status' => 'success', 'message' => "Trip {$r['tripId']} created (Ready).", 'trip' => $r['data']]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function start(): void
    {
        try {
            $r = $this->transport->startTrip((string) $this->input->post('id'));
            $this->_json(['status' => 'success', 'message' => 'Trip started.', 'trip' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function checkpoint_reached(): void
    {
        try {
            $r = $this->transport->markCheckpointReached(
                (string) $this->input->post('trip_id'),
                (string) $this->input->post('stop_id')
            );
            $this->_json(['status' => 'success', 'message' => 'Checkpoint reached.', 'checkpoint' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function checkpoint_left(): void
    {
        try {
            $r = $this->transport->markCheckpointLeft(
                (string) $this->input->post('trip_id'),
                (string) $this->input->post('stop_id')
            );
            $this->_json(['status' => 'success', 'message' => 'Checkpoint departed.', 'checkpoint' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function checkpoint_skipped(): void
    {
        try {
            $r = $this->transport->markCheckpointSkipped(
                (string) $this->input->post('trip_id'),
                (string) $this->input->post('stop_id'),
                (string) $this->input->post('reason')
            );
            $this->_json(['status' => 'success', 'message' => 'Checkpoint skipped.', 'checkpoint' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function board_student(): void
    {
        try {
            $r = $this->transport->markStudentBoarded(
                (string) $this->input->post('trip_id'),
                (string) $this->input->post('student_id'),
                (string) $this->input->post('actual_stop_id') ?: null,
                (string) $this->input->post('notes')
            );
            $this->_json(['status' => 'success', 'message' => 'Student boarded.', 'event' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function drop_student(): void
    {
        try {
            $r = $this->transport->markStudentDropped(
                (string) $this->input->post('trip_id'),
                (string) $this->input->post('student_id'),
                (string) $this->input->post('actual_stop_id') ?: null,
                (string) $this->input->post('notes')
            );
            $this->_json(['status' => 'success', 'message' => 'Student dropped.', 'event' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function no_show_student(): void
    {
        try {
            $r = $this->transport->markStudentNoShow(
                (string) $this->input->post('trip_id'),
                (string) $this->input->post('student_id'),
                (string) $this->input->post('notes')
            );
            $this->_json(['status' => 'success', 'message' => 'No-show recorded; parent notified.', 'event' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function complete(): void
    {
        try {
            $r = $this->transport->completeTrip(
                (string) $this->input->post('id'),
                [
                    'end_time'      => (string) $this->input->post('end_time'),
                    'end_odometer'  => (float)  $this->input->post('end_odometer'),
                    'fuel_used'     => (float)  $this->input->post('fuel_used'),
                    'distance_km'   => (float)  $this->input->post('distance_km'),
                    'notes'         => (string) $this->input->post('notes'),
                ]
            );
            $this->_json(['status' => 'success', 'message' => 'Trip completed.', 'trip' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function cancel(): void
    {
        try {
            $r = $this->transport->cancelTrip(
                (string) $this->input->post('id'),
                (string) $this->input->post('reason')
            );
            $this->_json(['status' => 'success', 'message' => 'Trip cancelled.', 'trip' => $r]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function list_all(): void
    {
        try {
            $trips = $this->transport->listTrips([
                'date'     => (string) $this->input->get('date'),
                'status'   => (string) $this->input->get('status'),
                'route_id' => (string) $this->input->get('route_id'),
            ]);
            $this->_json(['status' => 'success', 'trips' => $trips]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function get(): void
    {
        try {
            $trip = $this->transport->getTrip((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'trip' => $trip]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function roster(): void
    {
        try {
            $ids = $this->transport->getTripRoster((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'student_ids' => $ids]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function events(): void
    {
        try {
            $events = $this->transport->getTripBoardingEvents((string) $this->input->get('id'));
            $this->_json(['status' => 'success', 'events' => $events]);
        } catch (\Throwable $e) {
            $this->_json(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function _json(array $payload): void
    {
        $this->output->set_content_type('application/json')->set_output(json_encode($payload));
    }
}
