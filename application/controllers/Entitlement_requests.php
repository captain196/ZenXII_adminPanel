<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Entitlement_requests — Phase F. The parent-facing MID-YEAR request flow.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS IS
 * ─────────────────────────────────────────────────────────────────────────
 *  Roadmap Phase F. A family's circumstances change mid-session — a job is
 *  lost, a sibling joins, they move and now need the bus. This is how they ask,
 *  without a phone call and without the school re-keying it.
 *
 *  A request is NOT a grant. It lands in the SAME `pending_approval` queue
 *  staff already work in Phase E, is approved by the SAME
 *  Entitlement_service::approve(), and is subject to the SAME maker-checker
 *  rule. A parent can never be the approver of their own request — structurally
 *  guaranteed, because approve() rejects self-approval and a parent has no
 *  admin session to reach it with at all.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY A TOKEN-AUTH CONTROLLER, NOT A PANEL ONE
 * ─────────────────────────────────────────────────────────────────────────
 *  The Parent app has no admin session — it authenticates with a Firebase ID
 *  token. This mirrors `Auth_api` exactly (Bearer token → verifyFirebaseToken),
 *  the already-proven pattern for app→PHP calls, rather than inventing a second
 *  authentication scheme.
 *
 *  It deliberately does NOT let the app write Firestore directly:
 *  `studentEntitlements` is deny-all in the rules (Phase B) and stays that way.
 *  Every write still goes through Entitlement_service under the service
 *  account. That is what keeps "one canonical write path" true with a fourth
 *  surface added — and what stops a client from ever authoring a financial
 *  grant.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT A PARENT CANNOT DO (enforced, not assumed)
 * ─────────────────────────────────────────────────────────────────────────
 *   • Cannot set the value, scope, or fee head of a concession. They describe
 *     a SITUATION; the school decides the money. A client-supplied amount would
 *     be a self-granted discount in all but name.
 *   • Cannot request for another family's child — the token identity must BE
 *     the student, re-verified against the student document server-side.
 *   • Cannot bypass approval — approvalRequired is forced true here, never read
 *     from the request body.
 *   • Cannot approve, reject, or discontinue anything. Those endpoints live in
 *     the panel controller behind graded Fees:manage.
 */
class Entitlement_requests extends CI_Controller
{
    /** What a family may ask for. Deliberately a closed vocabulary. */
    private const REQUEST_TYPES = [
        'sibling'    => ['kind' => 'concession', 'concessionType' => 'sibling'],
        'staff_ward' => ['kind' => 'concession', 'concessionType' => 'staff_ward'],
        'hardship'   => ['kind' => 'concession', 'concessionType' => 'other'],
        'scholarship'=> ['kind' => 'concession', 'concessionType' => 'scholarship'],
        'transport'  => ['kind' => 'service',    'serviceType'    => 'transport'],
        'hostel'     => ['kind' => 'service',    'serviceType'    => 'hostel'],
        // MEALS-1 — 'meals' removed. A family could request free meals, a school
        // could approve it, and no bill would ever reflect it: ZenX raises no meal
        // charge at all. Fee_head_resolver::ASSIGNABLE already withheld the category
        // for this exact reason; this map contradicted it. Entitlement_service now
        // refuses a category:meals scope, so this is defence in depth rather than the
        // guard itself. Restore when a meals module exists.
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
    }

    /**
     * POST /entitlement_request/submit
     * Header: Authorization: Bearer <firebase id token>
     * Body:   student_id, request_type, reason, [effective_from]
     */
    public function submit(): void
    {
        if ($this->input->method() !== 'post') {
            $this->_json(['status' => 'error', 'message' => 'POST required.'], 405);
        }

        $claims = $this->_auth();          // exits on failure
        $schoolId = (string) ($claims['school_id'] ?? $claims['schoolId'] ?? '');
        $uid      = (string) ($claims['user_id'] ?? $claims['sub'] ?? $claims['uid'] ?? '');
        if ($schoolId === '' || $uid === '') {
            $this->_json(['status' => 'error', 'message' => 'Token is missing school or user identity.'], 403);
        }

        $studentId = trim((string) $this->input->post('student_id'));
        $type      = trim((string) $this->input->post('request_type'));
        $reason    = trim((string) $this->input->post('reason'));

        if ($studentId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $studentId)) {
            $this->_json(['status' => 'error', 'message' => 'Invalid student_id.'], 400);
        }
        if (!isset(self::REQUEST_TYPES[$type])) {
            $this->_json(['status' => 'error', 'message' => 'Unsupported request type.'], 400);
        }
        if ($reason === '' || mb_strlen($reason) > 1000) {
            $this->_json(['status' => 'error', 'message' => 'Please describe your request (up to 1000 characters).'], 400);
        }

        // ── Ownership. The token identity must BE this student, AND the student
        //    must belong to the token's school. Both checks, server-side, every
        //    time — a client-supplied student_id is never trusted.
        if (!$this->_owns($schoolId, $uid, $studentId)) {
            log_message('error', "ENTITLEMENT_REQUEST_DENIED uid=[{$uid}] tried student=[{$studentId}] school=[{$schoolId}]");
            $this->_json(['status' => 'error', 'message' => 'You can only raise a request for your own child.'], 403);
        }

        $spec = self::REQUEST_TYPES[$type];

        // Effective-from: the family may ask for a future start, never a
        // backdated one — backdating would retroactively rewrite already-issued
        // demands, which is a staff decision with accounting consequences.
        $from = trim((string) ($this->input->post('effective_from') ?? ''));
        if ($from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || strcmp($from, date('Y-m-d')) < 0) {
            $from = date('Y-m-d');
        }

        $this->load->library('Entitlement_service', null, 'entitlementSvc');
        $svc = $this->entitlementSvc->init(
            $this->firebase, $this->fs, $schoolId,
            $this->_session_for($schoolId),
            $uid   // requestedBy = the parent, so the audit names who asked
        );

        // The school decides the money — a parent-raised request carries NO
        // value. It is recorded as a zero-value placeholder scoped to 'all',
        // which the approver edits into a real grant (or rejects). This is the
        // single most important line in this controller: it is why a parent
        // cannot self-discount.
        $res = $svc->propose([
            'studentId'        => $studentId,
            'kind'             => $spec['kind'],
            'serviceType'      => $spec['serviceType']    ?? '',
            'concessionType'   => $spec['concessionType'] ?? 'other',
            'scope'            => 'all',
            'valueType'        => 'fixed',
            'value'            => 0,
            // No `frequency` — retired at the P2 contract freeze (I1). A parent
            // describes a situation; the billing period is decided by the
            // demand the school later raises, not by the request.
            'effectiveFrom'    => $from,
            'reason'           => '[Parent request: ' . $type . '] ' . $reason,
            'source'           => 'parent_request',
            'approvalRequired' => true,   // FORCED — never read from the body
        ]);

        if (empty($res['ok'])) {
            $this->_json(['status' => 'error', 'message' => (string) ($res['error'] ?? 'Could not submit your request.')], 400);
        }

        $this->_json([
            'status'  => 'success',
            'message' => 'Your request has been sent to the school for review.',
            'id'      => $res['id'],
            'state'   => $res['status'],
        ]);
    }

    /**
     * GET /entitlement_request/mine?student_id=…
     * The family's own request history — so they can see it was received and
     * what came of it, instead of phoning the office to ask.
     */
    public function mine(): void
    {
        $claims = $this->_auth();
        $schoolId = (string) ($claims['school_id'] ?? $claims['schoolId'] ?? '');
        $uid      = (string) ($claims['user_id'] ?? $claims['sub'] ?? $claims['uid'] ?? '');
        $studentId = trim((string) $this->input->get('student_id'));

        if ($schoolId === '' || $uid === '' || $studentId === '') {
            $this->_json(['status' => 'error', 'message' => 'Missing identity or student_id.'], 400);
        }
        if (!$this->_owns($schoolId, $uid, $studentId)) {
            $this->_json(['status' => 'error', 'message' => 'Not permitted.'], 403);
        }

        $this->load->library('Entitlement_service', null, 'entitlementSvc');
        $rows = $this->entitlementSvc
            ->init($this->firebase, $this->fs, $schoolId, $this->_session_for($schoolId), $uid)
            ->forStudent($studentId);

        // Project to a parent-safe shape. Internal approver identities,
        // rejection notes and receivable figures are NOT a family's business.
        $out = [];
        foreach ($rows as $r) {
            if ((string) ($r['source'] ?? '') !== 'parent_request') continue;
            $out[] = [
                'id'            => (string) ($r['entitlementId'] ?? ''),
                'kind'          => (string) ($r['kind'] ?? ''),
                'type'          => (string) ($r['concessionType'] ?? $r['serviceType'] ?? ''),
                'status'        => (string) ($r['status'] ?? ''),
                'requestedAt'   => (string) ($r['requestedAt'] ?? ''),
                'effectiveFrom' => (string) ($r['effectiveFrom'] ?? ''),
            ];
        }
        $this->_json(['status' => 'success', 'requests' => $out]);
    }

    // ── internals ────────────────────────────────────────────────────────

    /** Bearer-token auth, mirroring Auth_api. Exits on any failure. */
    private function _auth(): array
    {
        $hdr = (string) ($this->input->get_request_header('Authorization', true) ?? '');
        if (stripos($hdr, 'Bearer ') !== 0) {
            $this->_json(['status' => 'error', 'message' => 'Authorization required.'], 401);
        }
        $idToken = trim(substr($hdr, 7));
        if ($idToken === '') {
            $this->_json(['status' => 'error', 'message' => 'Authorization required.'], 401);
        }
        $claims = $this->firebase->verifyFirebaseToken($idToken, true);
        if (!is_array($claims)) {
            $this->_json(['status' => 'error', 'message' => 'Session expired. Please sign in again.'], 401);
        }
        return $claims;
    }

    /**
     * Ownership: the authenticated identity must BE the student (each student
     * has a Firebase Auth account the family signs in with — the same identity
     * `isStaffOrOwnStudent()` keys on in firestore.rules), and that student must
     * live in the token's school. Fails CLOSED on any read error.
     */
    private function _owns(string $schoolId, string $uid, string $studentId): bool
    {
        if ($uid !== $studentId) return false;
        try {
            $doc = $this->firebase->firestoreGet('students', $schoolId . '_' . $studentId);
            if (!is_array($doc)) return false;
            return (string) ($doc['schoolId'] ?? '') === $schoolId;
        } catch (\Throwable $e) {
            log_message('error', 'Entitlement_requests::_owns failed: ' . $e->getMessage());
            return false;   // fail closed
        }
    }

    /** Active session for the tenant; falls back to the Indian academic year. */
    private function _session_for(string $schoolId): string
    {
        try {
            $doc = $this->firebase->firestoreGet('schools', $schoolId);
            $s = is_array($doc) ? (string) ($doc['schoolControl']['currentSession'] ?? '') : '';
            if ($s !== '') return $s;
        } catch (\Throwable $_) { /* fall through */ }
        $y = (int) date('Y');
        if ((int) date('n') < 4) $y--;
        return $y . '-' . substr((string) ($y + 1), 2);
    }

    private function _json(array $payload, int $http = 200): void
    {
        $this->output
            ->set_status_header($http)
            ->set_content_type('application/json')
            ->set_output(json_encode($payload));
        exit;
    }
}
