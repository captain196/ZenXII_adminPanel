<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Campus_access — Campus Access & Visitor Management (MVP, 2026-08-09).
 *
 * Renamed from Visitor.php 2026-08-09: "Campus Access" is now the umbrella
 * module identity; Visitor Management remains a major sub-domain within it
 * (its own tabs, its own purpose-specific approval policy, untouched) —
 * per the operator's explicit instruction. Student/staff gate-pass
 * (early-leave/late-entry) and the muster/roll-call view are new siblings
 * added under the same controller/menu entry, backed by Gate_pass_service.
 *
 * RBAC: registered as a child of the 'Operations' umbrella under the single
 * key 'Campus Access' (application/helpers/rbac_helper.php). Graded:
 * 'view' = read dashboards/logs/muster; 'edit' = check-in/out, approve/deny/
 * release a visitor or gate pass (Front Office AND Security); 'manage' =
 * watchlist maintenance (Front Office/Admin only).
 *
 * Firestore: visitors / visitPasses / visitorWatchlist (Visitor Management,
 * unchanged) + campusAccessRequests / campusAccessEvents (new, Gate Pass +
 * unified ledger) — all school-scoped via Firestore_service::*Entity().
 *
 * APPROVAL POLICY IS NOT UNIFIED (explicit scope discipline, 2026-08-09):
 *   - Visitor / delivery / vendor / appointment: Visitor_service's existing
 *     purpose-specific rules (notify-only default, watchlist-triggered
 *     approval, block override) — untouched by this rename/extension.
 *   - Student early-leave/exit: dual approval (teacher + parent), NEVER
 *     auto-approved — Gate_pass_service.
 *   - Staff early-leave/exit: single admin approval — Gate_pass_service.
 *   - Late-entry (student or staff): no approval gate, a record not a
 *     decision — Gate_pass_service.
 */
class Campus_access extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_permission('Campus Access');

        // The Visitor Check-In photo capture (getUserMedia) needs camera
        // access, which MY_Controller's blanket Permissions-Policy denies
        // site-wide (application/core/MY_Controller.php:785 — intentional
        // platform-wide hardening, left untouched). header() replaces a
        // prior same-name header by default, so re-emitting it here AFTER
        // parent::__construct() has already sent the blanket denial
        // overrides it ONLY for this controller's own responses, same-origin
        // only (not third-party embeds). microphone/geolocation stay denied.
        header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

        $this->load->library('visitor_service', null, 'visitor');
        $this->visitor->init($this->fs, $this->numbering, $this->school_id, $this->session_year, $this->admin_id, $this->admin_name);

        $this->load->library('gate_pass_service', null, 'gatepass');
        $this->gatepass->init($this->fs, $this->numbering, $this->school_id, $this->session_year, $this->admin_id, $this->admin_name);
    }

    // ══════════════════════════════════════════════════════════════════
    //  PAGE
    // ══════════════════════════════════════════════════════════════════

    public function index()
    {
        require_permission('Campus Access', 'index', 'view');
        $data = [
            'title' => 'Campus Access Management',
            'can_manage' => has_permission('Campus Access', 'manage'),
            'can_edit'   => has_permission('Campus Access', 'edit'),
        ];
        $this->load->view('include/header', $data);
        $this->load->view('campus_access/index', $data);
        $this->load->view('include/footer');
    }

    /** Printable visitor gate pass — GET, standalone page (no header/footer chrome). */
    public function badge($passId)
    {
        require_permission('Campus Access', 'badge', 'view');
        $passId = trim((string) $passId);
        $pass = $this->fs->getEntity('visitPasses', $passId);
        if ($pass === null) {
            show_404();
            return;
        }
        $this->load->helper('qr_token');
        $qrDataUri = $pass['badgeCode'] !== '' ? qr_svg_data_uri((string) $pass['badgeCode']) : '';
        $this->load->view('campus_access/badge', [
            'pass' => $pass,
            'qrDataUri' => $qrDataUri,
            'schoolName' => $this->school_display_name ?: $this->school_name,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  LOOKUPS
    // ══════════════════════════════════════════════════════════════════

    public function search_visitors()
    {
        require_permission('Campus Access', 'search_visitors', 'view');
        $q = trim((string) $this->input->get('q'));
        try {
            $this->json_success(['items' => $this->visitor->searchVisitors($q)]);
        } catch (\Throwable $e) {
            $this->json_error('Search failed: ' . $e->getMessage());
        }
    }

    public function search_staff()
    {
        require_permission('Campus Access', 'search_staff', 'view');
        $q = trim((string) $this->input->get('q'));
        try {
            $this->json_success(['items' => $this->visitor->searchStaff($q)]);
        } catch (\Throwable $e) {
            $this->json_error('Search failed: ' . $e->getMessage());
        }
    }

    public function search_students()
    {
        require_permission('Campus Access', 'search_students', 'view');
        $q = trim((string) $this->input->get('q'));
        try {
            $this->json_success(['items' => $this->gatepass->searchStudents($q)]);
        } catch (\Throwable $e) {
            $this->json_error('Search failed: ' . $e->getMessage());
        }
    }

    /**
     * Read-only watchlist preview as the front desk types a name/phone —
     * the authoritative check still happens server-side again inside
     * create_pass(); this is a live warning, not the enforcement point.
     */
    public function watchlist_check()
    {
        require_permission('Campus Access', 'watchlist_check', 'view');
        $name = trim((string) $this->input->get('name'));
        $phone = trim((string) $this->input->get('phone'));
        try {
            $match = $this->visitor->matchWatchlist($name, $phone);
            // BUG-056 fix (2026-08-10): this stays 'view'-grade so ordinary
            // front-desk operators still get the live safety warning while
            // creating a pass (the whole point of this endpoint) — but the
            // safeguarding 'reason' text is more sensitive than bare
            // membership and is documented staff-only/manage-only elsewhere
            // (watchlist_list/add/deactivate are all 'manage', and
            // firestore.rules gates visitorWatchlist reads to staff for the
            // same reason). Strip it here unless the caller actually holds
            // 'manage' — severity/matched still surface so the view-grade
            // operator can still act (deny/escalate), just without reading
            // back the specific reason text.
            if (is_array($match) && !has_permission('Campus Access', 'manage')) {
                unset($match['reason']);
            }
            $this->json_success(['match' => $match]);
        } catch (\Throwable $e) {
            $this->json_error('Watchlist check failed: ' . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  VISITOR PASS LIFECYCLE (Visitor Management sub-domain — unchanged policy)
    // ══════════════════════════════════════════════════════════════════

    public function create_pass()
    {
        require_permission('Campus Access', 'create_pass', 'edit');
        $input = [
            'name'          => $this->input->post('name'),
            'phone'         => $this->input->post('phone'),
            'idProofType'   => $this->input->post('idProofType'),
            'idProofNumber' => $this->input->post('idProofNumber'),
            'visitorType'   => $this->input->post('visitorType'),
            'organization'  => $this->input->post('organization'),
            'vehicleNumber' => $this->input->post('vehicleNumber'),
            'purpose'       => $this->input->post('purpose'),
            'purposeNote'   => $this->input->post('purposeNote'),
            'hostStaffId'   => $this->input->post('hostStaffId'),
            'hostStaffName' => $this->input->post('hostStaffName'),
            'accompanying'  => $this->input->post('accompanying'),
            'expectedDurationMinutes' => $this->input->post('expectedDurationMinutes'),
            'notes'         => $this->input->post('notes'),
        ];
        try {
            $pass = $this->visitor->createPass($input);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
            return;
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::create_pass failed: ' . $e->getMessage());
            $this->json_error('Failed to create the visit pass.');
            return;
        }

        // Optional webcam-captured photo, submitted in the SAME multipart
        // request as the rest of the check-in form (the visitorId doesn't
        // exist until createPass() above returns, so this can't happen any
        // earlier). Best-effort — a failed photo upload never blocks check-in.
        $photo = $_FILES['photo'] ?? null;
        if ($photo !== null && (int) ($photo['error'] ?? 1) === UPLOAD_ERR_OK) {
            try {
                $this->load->library('document_service', null, 'docsvc');
                $this->docsvc->init($this->firebase, $this->school_id);
                $res = $this->docsvc->upload((string) $photo['tmp_name'], (string) $photo['name'], 'visitor_photo', ['uploadedBy' => $this->admin_id]);
                if ((bool) ($res['ok'] ?? false)) {
                    $documentId = (string) $res['document']['documentId'];
                    if ($this->visitor->attachVisitorDocument((string) $pass['visitorId'], $documentId, 'photo')) {
                        $pass['photoUrl'] = (string) ($res['document']['downloadUrl'] ?? '');
                        $this->fs->updateEntity('visitPasses', (string) $pass['id'], ['visitorPhotoUrl' => $pass['photoUrl']]);
                    }
                } else {
                    log_message('error', 'Campus_access::create_pass photo upload failed: ' . (string) ($res['error'] ?? 'unknown'));
                }
            } catch (\Throwable $e) {
                log_message('error', 'Campus_access::create_pass photo attach failed: ' . $e->getMessage());
            }
        }

        $this->json_success(['pass' => $pass]);
    }

    public function approve_pass($passId)
    {
        require_permission('Campus Access', 'approve_pass', 'edit');
        try {
            $pass = $this->visitor->approvePass(trim((string) $passId), false);
            $this->json_success(['pass' => $pass]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::approve_pass failed: ' . $e->getMessage());
            $this->json_error('Failed to approve the visit.');
        }
    }

    public function deny_pass($passId)
    {
        require_permission('Campus Access', 'deny_pass', 'edit');
        $reason = trim((string) $this->input->post('reason'));
        try {
            $pass = $this->visitor->denyPass(trim((string) $passId), $reason);
            $this->json_success(['pass' => $pass]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::deny_pass failed: ' . $e->getMessage());
            $this->json_error('Failed to deny the visit.');
        }
    }

    /** Front Office / Admin only (manage) — Security cannot override a watchlist block. */
    public function override_block($passId)
    {
        require_permission('Campus Access', 'override_block', 'manage');
        $reason = trim((string) $this->input->post('reason'));
        try {
            $pass = $this->visitor->overrideBlock(trim((string) $passId), $reason);
            $this->json_success(['pass' => $pass]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::override_block failed: ' . $e->getMessage());
            $this->json_error('Failed to override the block.');
        }
    }

    public function checkout_pass($passId)
    {
        require_permission('Campus Access', 'checkout_pass', 'edit');
        $verificationMethod = trim((string) $this->input->post('verificationMethod')) ?: 'manual';
        $qrToken = trim((string) $this->input->post('token'));
        try {
            $pass = $this->visitor->checkoutPass(trim((string) $passId), $verificationMethod, $qrToken);
            $this->json_success(['pass' => $pass]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::checkout_pass failed: ' . $e->getMessage());
            $this->json_error('Failed to check out the visitor.');
        }
    }

    /**
     * F-03 (2026-08-09 correction pass): READ-ONLY QR lookup step. Decodes +
     * verifies the badge token and returns pass details for the operator to
     * visually confirm before they take the SEPARATE, explicit checkout_pass
     * action. This endpoint never changes any state.
     */
    public function scan_qr_lookup()
    {
        require_permission('Campus Access', 'checkout_pass', 'edit');
        $token = trim((string) $this->input->post('token'));
        try {
            $result = $this->visitor->lookupByQrToken($token);
            // SEC-ID-3 (Phase 19C) — lookupByQrToken already returns a
            // `legacy` flag for unsigned 2-part badges, but nothing consumed
            // it: legacy usage on the visitor path was completely invisible,
            // so the migration decision had no evidence from this surface at
            // all. Level is 'error' deliberately — see the SEC-ID-3 note in
            // Attendance::scan_qr for why it is the only level that survives
            // log_threshold=1. Read-only endpoint; this adds telemetry only
            // and changes no behaviour, so no existing badge stops working.
            if (!empty($result['legacy'])) {
                log_message('error',
                    'SEC-ID-3 Campus_access::scan_qr_lookup — legacy unsigned badge scanned for pass '
                    . (string) ($result['passId'] ?? '?') . " school={$this->school_id}"
                );
            }
            $this->json_success($result);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::scan_qr_lookup failed: ' . $e->getMessage());
            $this->json_error('Failed to look up the QR code.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  GATE PASS — student/staff early-leave & late-entry (new sub-domain)
    // ══════════════════════════════════════════════════════════════════

    public function create_gate_pass()
    {
        require_permission('Campus Access', 'create_gate_pass', 'edit');
        $input = [
            'personType'     => $this->input->post('personType'),
            'personId'       => $this->input->post('personId'),
            'personName'     => $this->input->post('personName'),
            'reason'         => $this->input->post('reason'),
            'requestedTime'  => $this->input->post('requestedTime'),
            'teacherStaffId' => $this->input->post('teacherStaffId'),
        ];
        try {
            $req = $this->gatepass->createEarlyLeaveRequest($input);
            $this->json_success(['request' => $req]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::create_gate_pass failed: ' . $e->getMessage());
            $this->json_error('Failed to create the gate pass request.');
        }
    }

    public function log_late_entry()
    {
        require_permission('Campus Access', 'log_late_entry', 'edit');
        $input = [
            'personType' => $this->input->post('personType'),
            'personId'   => $this->input->post('personId'),
            'personName' => $this->input->post('personName'),
            'reason'     => $this->input->post('reason'),
        ];
        try {
            $req = $this->gatepass->logLateEntry($input);
            $this->json_success(['request' => $req]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::log_late_entry failed: ' . $e->getMessage());
            $this->json_error('Failed to log the late entry.');
        }
    }

    /** $role = teacher | parent | admin — one explicit human decision per call, never auto-filled. */
    public function record_approval($requestId, $role)
    {
        require_permission('Campus Access', 'record_approval', 'edit');
        $decision = trim((string) $this->input->post('decision'));
        $note = trim((string) $this->input->post('note'));
        // F-01: consent/delegation fields — only consulted by the service
        // for the role they actually apply to (parent consent, teacher delegate).
        $consent = [
            'guardianName' => $this->input->post('guardianName'),
            'relationship' => $this->input->post('relationship'),
            'consentMethod' => $this->input->post('consentMethod'),
            'contact' => $this->input->post('contact'),
            'delegateReason' => $this->input->post('delegateReason'),
        ];
        try {
            $req = $this->gatepass->recordApproval(trim((string) $requestId), trim((string) $role), $decision, $note, $consent);
            $this->json_success(['request' => $req]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::record_approval failed: ' . $e->getMessage());
            $this->json_error('Failed to record the approval.');
        }
    }

    public function release_gate_pass($requestId)
    {
        require_permission('Campus Access', 'release_gate_pass', 'edit');
        $verificationMethod = trim((string) $this->input->post('verificationMethod')) ?: 'manual';
        $cardTapId = trim((string) $this->input->post('cardTapId'));
        try {
            $req = $this->gatepass->releaseGatePass(trim((string) $requestId), $verificationMethod, $cardTapId);
            $this->json_success(['request' => $req]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::release_gate_pass failed: ' . $e->getMessage());
            $this->json_error('Failed to release the gate pass.');
        }
    }

    public function list_gate_passes()
    {
        require_permission('Campus Access', 'list_gate_passes', 'view');
        $status = trim((string) $this->input->get('status'));
        $rows = $this->gatepass->listToday(500, $status);
        $this->json_success(['items' => array_map(function ($r) { return $r['data'] ?? []; }, $rows)]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  MUSTER / ROLL-CALL — unified ledger view
    // ══════════════════════════════════════════════════════════════════

    public function muster()
    {
        require_permission('Campus Access', 'muster', 'view');
        try {
            $this->json_success($this->gatepass->muster());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::muster failed: ' . $e->getMessage());
            $this->json_error('Failed to build the muster view.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  DASHBOARD / LOG (Visitor Management sub-domain)
    // ══════════════════════════════════════════════════════════════════

    public function list_on_campus()
    {
        require_permission('Campus Access', 'list_on_campus', 'view');
        $rows = $this->visitor->listOnCampus();
        $this->json_success(['items' => array_map(function ($r) { return $r['data'] ?? []; }, $rows)]);
    }

    public function list_log()
    {
        require_permission('Campus Access', 'list_log', 'view');
        $filters = [
            'status'  => trim((string) $this->input->get('status')),
            'purpose' => trim((string) $this->input->get('purpose')),
        ];
        $rows = $this->visitor->listLog($filters);
        $this->json_success(['items' => array_map(function ($r) { return $r['data'] ?? []; }, $rows)]);
    }

    /** CSV export of the visitor log — MVP scope. Respects the same status/purpose filters as the on-screen log. */
    public function export_log_csv()
    {
        require_permission('Campus Access', 'export_log_csv', 'view');
        $filters = [
            'status'  => trim((string) $this->input->get('status')),
            'purpose' => trim((string) $this->input->get('purpose')),
        ];
        $rows = $this->visitor->listLog($filters);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="visitor_log_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Pass ID', 'Visitor', 'Phone', 'Purpose', 'Host', 'Status', 'Check-In', 'Check-Out', 'Watchlist Hit']);
        foreach ($rows as $row) {
            $d = (array) ($row['data'] ?? []);
            fputcsv($out, [
                $d['id'] ?? '', $d['visitorNameSnapshot'] ?? '', $d['visitorPhoneSnapshot'] ?? '',
                $d['purpose'] ?? '', $d['hostStaffNameSnapshot'] ?? '', $d['status'] ?? '',
                $d['checkInAt'] ?? '', $d['checkOutAt'] ?? '', !empty($d['watchlistHit']) ? 'Yes' : 'No',
            ]);
        }
        fclose($out);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════
    //  WATCHLIST — manage-level only (Visitor Management sub-domain)
    // ══════════════════════════════════════════════════════════════════

    public function watchlist_list()
    {
        require_permission('Campus Access', 'watchlist_list', 'manage');
        $this->json_success(['items' => $this->visitor->listWatchlist()]);
    }

    public function watchlist_add()
    {
        require_permission('Campus Access', 'watchlist_add', 'manage');
        $input = [
            'name'      => $this->input->post('name'),
            'phone'     => $this->input->post('phone'),
            'severity'  => $this->input->post('severity'),
            'reason'    => $this->input->post('reason'),
            'expiresAt' => $this->input->post('expiresAt'),
        ];
        try {
            $entry = $this->visitor->addWatchlistEntry($input);
            $this->json_success(['entry' => $entry]);
        } catch (\InvalidArgumentException $e) {
            $this->json_error($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::watchlist_add failed: ' . $e->getMessage());
            $this->json_error('Failed to add the watchlist entry.');
        }
    }

    public function watchlist_deactivate($id)
    {
        require_permission('Campus Access', 'watchlist_deactivate', 'manage');
        $reason = trim((string) $this->input->post('reason'));
        try {
            $ok = $this->visitor->deactivateWatchlistEntry(trim((string) $id), $reason);
            $ok ? $this->json_success([]) : $this->json_error('Failed to deactivate the entry.');
        } catch (\Throwable $e) {
            log_message('error', 'Campus_access::watchlist_deactivate failed: ' . $e->getMessage());
            $this->json_error('Failed to deactivate the entry.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  DOCUMENTS — Canonical Document Service (consume-only, never modify)
    // ══════════════════════════════════════════════════════════════════

    /** Upload + link an ID-proof or background-verification document for a visitor. */
    public function upload_document()
    {
        require_permission('Campus Access', 'upload_document', 'edit');
        $visitorId = trim((string) $this->input->post('visitorId'));
        $role = trim((string) $this->input->post('role')) === 'background_verification'
            ? 'background_verification' : 'id_proof';
        $type = $role === 'background_verification' ? 'visitor_verification' : 'visitor_id_proof';

        $file = $_FILES['file'] ?? null;
        if ($visitorId === '' || $file === null || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $this->json_error('A visitor and a valid file are required.');
            return;
        }

        $this->load->library('document_service', null, 'docsvc');
        $this->docsvc->init($this->firebase, $this->school_id);
        $res = $this->docsvc->upload((string) $file['tmp_name'], (string) $file['name'], $type, ['uploadedBy' => $this->admin_id]);
        if (!(bool) ($res['ok'] ?? false)) {
            $this->json_error((string) ($res['error'] ?? 'Upload failed.'));
            return;
        }
        $documentId = (string) $res['document']['documentId'];
        $ok = $this->visitor->attachVisitorDocument($visitorId, $documentId, $role);
        if (!$ok) {
            $this->json_error('Uploaded, but failed to attach to the visitor record.');
            return;
        }
        $this->json_success(['document' => $res['document']]);
    }
}
