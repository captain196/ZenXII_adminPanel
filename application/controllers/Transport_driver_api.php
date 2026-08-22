<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Transport_driver_api — canonical trip lifecycle for the Driver (Teacher) app.
 *
 * ─── WHY THIS EXISTS (decision D-8, 2026-08-08) ──────────────────────────
 * The Teacher app used to write `tripLogs` DIRECTLY to Firestore, bypassing
 * `Transport_service` entirely. That produced two writers with different
 * behaviour for the same document: an app-started trip silently skipped driver
 * readiness re-validation (LOG-1), the CAS transition guard (CON-6), the audit
 * row, the `trip.started` parent notification, the vehicle route
 * denormalisation (XMOD-1) — and, once F13 landed, GPS session creation.
 *
 * The operator's ruling: ONE canonical business flow. Both the admin panel and
 * the driver app now execute the same `Transport_service` lifecycle, so every
 * current and future business rule runs from a single place.
 *
 * ─── AUTHENTICATION: Firebase Bearer, not an admin session ───────────────
 * Mirrors `Staff_attendance` / `Ptm` — the established app-API bootstrap in
 * this codebase. `CI_Controller::__construct()` is called directly to skip
 * MY_Controller's admin-session bootstrap, and `Api_auth::require_auth()`
 * verifies the Firebase ID token (401 on failure).
 *
 * ─── AUTHORIZATION: OWNERSHIP, NOT AN RBAC MODULE GRANT ──────────────────
 * A driver may act on a trip if and only if `trip.driver_staff_id` equals the
 * authenticated token uid. There is deliberately NO `require_permission()`
 * call here, and the `Driver` role is deliberately NOT granted the `Transport`
 * module.
 *
 * That is the operator's "minimum permissions" instruction taken literally.
 * Granting `Transport:edit` to drivers would be the easy path and the wrong
 * one — it would also authorise `save_vehicle`, `delete_route`,
 * `terminate_vendor` and every other Transport write. Ownership-based
 * authorisation grants exactly one capability: run the lifecycle of a trip
 * you are already assigned to drive.
 *
 * This is the same model `Staff_attendance` uses (you may punch only yourself)
 * and the same model the Firestore rule `isDriverOfTrip()` already enforces —
 * so the server and the rules layer now agree on one authorisation concept.
 *
 * Identity safety: the driver id is ALWAYS the authenticated token uid. It is
 * never read from the request body, so a driver cannot act as another driver.
 *
 * ─── ENDPOINTS ───────────────────────────────────────────────────────────
 *   POST transport_driver_api/start_trip     {trip_id}
 *   POST transport_driver_api/complete_trip  {trip_id, end_odometer?, distance?, fuel_used?, notes?}
 *
 * `cancel_trip` is deliberately absent: cancelling a trip is an operational
 * decision that belongs to the panel, not to the driver. A driver who cannot
 * continue completes the trip or raises SOS.
 */
class Transport_driver_api extends MY_Controller
{
    /** @var array Firebase ID-token claims. */
    private array $_claims = [];

    public function __construct()
    {
        // App-API bootstrap (mirrors Staff_attendance): Bearer auth, no admin
        // session. CI_Controller directly — NOT MY_Controller::__construct —
        // because there is no PHP session to bootstrap from.
        CI_Controller::__construct();
        $this->load->library('firebase');
        $this->load->library('api_auth');
        $this->load->library('Firestore_service', null, 'fs');

        $claims = $this->api_auth->require_auth();       // dies 401 if invalid
        $this->_claims      = $claims;
        $this->school_id    = (string) ($claims['school_id'] ?? '');
        $this->school_name  = $this->school_id;          // canonical: name == id
        $this->admin_id     = 'app:' . ($claims['uid'] ?? 'unknown');
        $this->admin_role   = (string) ($claims['role'] ?? '');
        $this->session_year = $this->_resolveSession($this->school_id);

        if ($this->school_id === '' || $this->session_year === '') {
            $this->_json(409, ['status' => 'error', 'message' => 'Unable to resolve school/session from token.']);
        }

        $this->fs->init($this->school_id, $this->session_year);
        $this->load->library('numbering_service', null, 'numbering');
        $this->numbering->init($this->fs, $this->school_name, $this->session_year);
        $this->load->library('transport_service', null, 'transport');
        $this->transport->init(
            $this->fs, $this->numbering,
            $this->school_name, $this->session_year,
            $this->admin_id
        );
        // F14 — attendance decision layer. Owns no storage: every write it makes
        // goes through the transport service above, so there remains exactly one
        // attendance writer.
        $this->load->library('transport_qr_attendance_service', null, 'qratt');
        $this->qratt->init(
            $this->fs, $this->transport,
            $this->school_name, $this->session_year,
            (string) ($this->_claims['uid'] ?? '')
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  F14 — QR attendance (2026-08-12)
    //
    //  Every endpoint below is authorized by trip OWNERSHIP, exactly like
    //  start_trip/complete_trip: `_assertOwnTrip()` requires the trip's
    //  driver_staff_id to equal the caller's token uid. There is deliberately
    //  no require_permission() — a driver holds no Transport module grant, and
    //  granting one would hand them vehicle/route management too.
    //
    //  These exist because Firestore rules cannot enforce what F14 requires:
    //  they cannot verify an HMAC signature, nor resolve a transport
    //  entitlement across studentRoutes status + effective dates + session +
    //  service days. Attendance mutations are therefore server-only.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST transport_driver_api/scan
     *   trip_id, qr (raw scanned string)
     *   optional: confirm_alternate=1, client_event_id, client_event_at
     *
     * Response `status` is one of:
     *   success            — event recorded
     *   already            — same event already recorded (idempotent no-op)
     *   needs_confirmation — student belongs to another bus; app must offer
     *                        "Board as alternate bus" and re-post with
     *                        confirm_alternate=1
     *   error              — rejected, with a driver-readable reason
     */
    public function scan(): void
    {
        $tripId = $this->_requiredTripId();
        $this->_assertOwnTrip($tripId);

        $qr = (string) ($this->input->post('qr') ?? '');
        if (trim($qr) === '') {
            $this->_json(400, ['status' => 'error', 'message' => 'No QR code was supplied.']);
        }

        try {
            $res = $this->qratt->scan($qr, $tripId, [
                'confirm_alternate' => (string) ($this->input->post('confirm_alternate') ?? '') === '1',
                'client_event_id'   => (string) ($this->input->post('client_event_id') ?? ''),
                'client_event_at'   => (string) ($this->input->post('client_event_at') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            // Business/security rejection — bad token, wrong school, not
            // entitled. 422 so the app can show the reason rather than treating
            // it as a transport failure and silently retrying from the queue.
            $this->_json(422, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', "Transport_driver_api::scan failed trip={$tripId}: " . $e->getMessage());
            $this->_json(500, ['status' => 'error', 'message' => 'Could not record the scan. Please try again.']);
        }

        $map = ['ok' => 'success', 'already' => 'already', 'needs_confirmation' => 'needs_confirmation'];
        $status = $map[(string) ($res['status'] ?? '')] ?? 'error';
        // A rejection is a 422, never a 200 — an app that only checks the HTTP
        // status must not conclude the child was recorded.
        $this->_json($status === 'error' ? 422 : 200, ['status' => $status] + $res);
    }

    /**
     * POST transport_driver_api/mark_all_alighted_at_school
     *   trip_id [, client_event_id]
     *
     * Morning only, and refused unless F13.1 telemetry places the bus inside
     * the school's configured geofence. Marks only students who ACTUALLY
     * boarded — it never manufactures an alighting for someone who never got on.
     */
    public function mark_all_alighted_at_school(): void
    {
        $tripId = $this->_requiredTripId();
        $this->_assertOwnTrip($tripId);

        try {
            $res = $this->qratt->markAllAlightedAtSchool($tripId, [
                'client_event_id' => (string) ($this->input->post('client_event_id') ?? ''),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->_json(422, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', "Transport_driver_api::mark_all_alighted_at_school failed trip={$tripId}: " . $e->getMessage());
            $this->_json(500, ['status' => 'error', 'message' => 'Could not record arrival at school. Please try again.']);
        }
        if ((string) ($res['status'] ?? '') !== 'ok') {
            $this->_json(422, ['status' => 'error'] + $res);
        }
        $this->_json(200, ['status' => 'success'] + $res);
    }

    /**
     * POST transport_driver_api/resolve_exception
     *   trip_id, student_id, resolution (absent|verified_other) [, reason]
     *
     * `alternate_bus` is NOT accepted here: the server derives it from
     * canonical attendance evidence, so a driver can neither assert it nor be
     * held responsible for a child who quietly took another bus.
     */
    public function resolve_exception(): void
    {
        $tripId = $this->_requiredTripId();
        $this->_assertOwnTrip($tripId);

        $studentId  = trim((string) ($this->input->post('student_id') ?? ''));
        $resolution = trim((string) ($this->input->post('resolution') ?? ''));
        if ($studentId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $studentId)) {
            $this->_json(400, ['status' => 'error', 'message' => 'A valid student_id is required.']);
        }

        try {
            $res = $this->qratt->resolveException($tripId, $studentId, $resolution,
                (string) ($this->input->post('reason') ?? ''), [
                    'client_event_id' => (string) ($this->input->post('client_event_id') ?? ''),
                ]);
        } catch (\InvalidArgumentException $e) {
            $this->_json(422, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', "Transport_driver_api::resolve_exception failed trip={$tripId}: " . $e->getMessage());
            $this->_json(500, ['status' => 'error', 'message' => 'Could not record the resolution. Please try again.']);
        }
        $st = (string) ($res['status'] ?? '');
        if ($st === 'ok' || $st === 'auto_resolved') {
            $this->_json(200, ['status' => 'success'] + $res);
        }
        $this->_json(422, ['status' => 'error'] + $res);
    }

    /**
     * GET transport_driver_api/trip_roster?trip_id=…
     *
     * The driver's operational student list plus counts and the reason (if any)
     * the trip cannot yet be completed.
     */
    public function trip_roster(): void
    {
        $tripId = trim((string) ($this->input->get('trip_id') ?? ''));
        if ($tripId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $tripId)) {
            $this->_json(400, ['status' => 'error', 'message' => 'A valid trip_id is required.']);
        }
        $this->_assertOwnTrip($tripId);

        try {
            $roster   = $this->qratt->tripRoster($tripId);
            $blockers = $this->qratt->completionBlockers($tripId);
        } catch (\InvalidArgumentException $e) {
            $this->_json(422, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', "Transport_driver_api::trip_roster failed trip={$tripId}: " . $e->getMessage());
            $this->_json(500, ['status' => 'error', 'message' => 'Could not load the student list. Please try again.']);
        }
        $this->_json(200, ['status' => 'success', 'roster' => $roster, 'blockers' => $blockers]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Endpoints
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST transport_driver_api/start_trip — Ready → InProgress.
     *
     * Delegates to the canonical `Transport_service::startTrip()`, so the
     * driver path now gets LOG-1 readiness re-validation, the CON-6 CAS guard,
     * the audit row, the `trip.started` parent notification, XMOD-1 route
     * denormalisation and F13 GPS session creation — none of which the old
     * direct-Firestore write performed.
     */
    public function start_trip(): void
    {
        $tripId = $this->_requiredTripId();
        $this->_assertOwnTrip($tripId);

        try {
            $res = $this->transport->startTrip($tripId);
        } catch (\InvalidArgumentException $e) {
            // Business rejections — unfit driver (LOG-1), suspended route
            // (LOG-2), wrong state. These are the whole point of routing
            // through the service, so surface the reason verbatim to the app.
            $this->_json(422, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver_api::start_trip failed for ' . $tripId . ': ' . $e->getMessage());
            $this->_json(500, ['status' => 'error', 'message' => 'Could not start the trip. Please try again.']);
        }
        $this->_json(200, ['status' => 'success'] + (array) $res);
    }

    /**
     * POST transport_driver_api/complete_trip — InProgress → Completed.
     *
     * Disarms the F13 tracking kill switch and tears down the live-position
     * session as part of the canonical transition.
     */
    public function complete_trip(): void
    {
        $tripId = $this->_requiredTripId();
        $this->_assertOwnTrip($tripId);

        $input = [
            'end_odometer' => (float) ($this->input->post('end_odometer') ?? 0),
            'distance_km'  => (float) ($this->input->post('distance_km') ?? 0),
            'fuel_used'    => (float) ($this->input->post('fuel_used') ?? 0),
            'notes'        => trim((string) ($this->input->post('notes') ?? '')),
        ];

        try {
            $res = $this->transport->completeTrip($tripId, $input);
        } catch (\InvalidArgumentException $e) {
            // e.g. a checkpoint still Pending — a real guard the driver must see.
            $this->_json(422, ['status' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver_api::complete_trip failed for ' . $tripId . ': ' . $e->getMessage());
            $this->_json(500, ['status' => 'error', 'message' => 'Could not complete the trip. Please try again.']);
        }
        $this->_json(200, ['status' => 'success'] + (array) $res);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Authorization + helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * THE authorization boundary: the caller must be the trip's assigned driver.
     *
     * Compared against the authenticated token uid — never a body field — so a
     * driver cannot act on another driver's trip by supplying an id.
     *
     * A missing trip and a someone-else's trip both return 403 with the same
     * message: distinguishing them would let a driver enumerate which trip ids
     * exist in the tenant.
     */
    private function _assertOwnTrip(string $tripId): array
    {
        $uid = (string) ($this->_claims['uid'] ?? '');
        if ($uid === '') {
            $this->_json(401, ['status' => 'error', 'message' => 'Authentication required.']);
        }

        $trip = null;
        try {
            $trip = $this->fs->getEntity('tripLogs', $tripId);
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver_api trip read failed: ' . $e->getMessage());
        }

        $driver = is_array($trip) ? (string) ($trip['driver_staff_id'] ?? '') : '';
        if ($driver === '' || $driver !== $uid) {
            log_message('error',
                "Transport_driver_api DENIED: uid=[{$uid}] trip=[{$tripId}] "
                . 'tripDriver=[' . ($driver !== '' ? $driver : 'none') . "] school=[{$this->school_id}]");
            $this->_json(403, ['status' => 'error', 'message' => 'This trip is not assigned to you.']);
        }
        return $trip;
    }

    /** Trip id from the body, path-safe. */
    private function _requiredTripId(): string
    {
        $tripId = trim((string) ($this->input->post('trip_id') ?? ''));
        if ($tripId === '' || !preg_match('/^[A-Za-z0-9_\-]{1,80}$/', $tripId)) {
            $this->_json(400, ['status' => 'error', 'message' => 'A valid trip_id is required.']);
        }
        return $tripId;
    }

    /** schools/{id}.currentSession is the sole authority (fail-closed). */
    private function _resolveSession(string $schoolId): string
    {
        if ($schoolId === '') { return ''; }
        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolId);
            if (is_array($doc)) {
                $s = (string) ($doc['schoolControl']['currentSession'] ?? $doc['currentSession'] ?? '');
                if ($s !== '') { return $s; }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Transport_driver_api::_resolveSession failed: ' . $e->getMessage());
        }
        return '';
    }

    /** Emit JSON and stop. */
    private function _json(int $code, array $payload): void
    {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode($payload);
        exit;
    }
}
