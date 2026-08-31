<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Fee_concessions — admin capture endpoints for the Fees Exemption v2 model.
 *
 * Writes ONLY to studentServiceEnrollments + studentConcessions. Does NOT
 * touch fee-demand generation, billing, or the legacy exemptedFees /
 * discountHeads fields. Captured data is consumed by the unified generator
 * from Phase 2 (concessions) and Phase 3 (service enrollments).
 *
 * Every endpoint is double-gated:
 *   1. Role gate — canonical graded RBAC only (Fees:view / Fees:manage via
 *      has_permission(), resolved through MY_Controller::_require_role with
 *      an empty legacy allow-list). RBAC migration (2026-08-08): this module
 *      never carried a load-bearing legacy fallback — see
 *      RBAC_MIGRATION_REPORT.md for the audit evidence (ROLE_ADMIN is
 *      catalogue-backfilled to all modules incl. Fees; ROLE_ACCOUNTANT is
 *      catalogue-seeded with Fees:manage by default; 'School Admin' was an
 *      orphaned label used nowhere else in the codebase for gating; Super/
 *      School Super Admin bypass unconditionally inside _require_role
 *      itself, independent of any allow-list). Do NOT reintroduce a
 *      role-name array here — add/adjust the module capability instead.
 *   2. Feature flag (CONCESSION_UI_ENABLED / SERVICE_ENROLLMENT_UI_ENABLED).
 * The flag gate stops accidental client population while billing still
 * ignores the data — the operator's explicit "no capture-without-billing"
 * requirement.
 */
class Fee_concessions extends MY_Controller
{

    public function __construct()
    {
        parent::__construct();
        $this->load->library('firebase');
        $this->load->library('firestore_service', null, 'fs');
        $this->config->load('fees_exemption_v2_flags', true);
    }

    /** Either UI tab enabled? Used to gate the page render. */
    private function _ui_enabled(): bool
    {
        return (bool) $this->config->item('CONCESSION_UI_ENABLED', 'fees_exemption_v2_flags')
            || (bool) $this->config->item('SERVICE_ENROLLMENT_UI_ENABLED', 'fees_exemption_v2_flags');
    }

    private function _concession_ui_enabled(): bool
    {
        return (bool) $this->config->item('CONCESSION_UI_ENABLED', 'fees_exemption_v2_flags');
    }

    private function _service_ui_enabled(): bool
    {
        return (bool) $this->config->item('SERVICE_ENROLLMENT_UI_ENABLED', 'fees_exemption_v2_flags');
    }

    /**
     * P1 #12 (2026-08-12) — cross-tenant IDOR guard for concession /
     * service-enrollment mutation.
     *
     * revoke_concession() and discontinue_service() take a document id
     * straight from the client and passed it to firestoreUpdate() with no
     * ownership check. safe_path_segment() only sanitizes characters — it
     * proves nothing about which tenant owns the document. And because
     * firestore.rules denies ALL client access to studentConcessions and
     * studentServiceEnrollments (`allow read, write: if false`), this PHP
     * layer is the ENTIRE authorization boundary for these collections: a
     * School A admin who learned a School B document id could revoke School
     * B's concession outright.
     *
     * Fetch-then-compare, mirroring the cross-student guard already proven in
     * Fee_management::parent_verify_payment (the `$order['student_id'] !==
     * $studentId` / `$alreadyProcessed['studentId'] !== $studentId` pattern).
     *
     * Returns the document on success, or null after emitting the JSON error
     * — callers MUST `return` immediately on null.
     *
     * A missing document and a cross-tenant document deliberately return the
     * SAME 'not found' response, so the endpoint cannot be used as an
     * existence oracle to enumerate another tenant's document ids.
     */
    private function _owned_doc(string $collection, string $id, string $label): ?array
    {
        try {
            $doc = $this->firebase->firestoreGet($collection, $id);
        } catch (\Throwable $e) {
            log_message('error', "Fee_concessions::_owned_doc {$collection}/{$id} fetch failed: " . $e->getMessage());
            $this->json_error("Could not load {$label}. Please retry.");
            return null;
        }

        if (!is_array($doc)) {
            $this->json_error(ucfirst($label) . ' not found.');
            return null;
        }

        if ((string) ($doc['schoolId'] ?? '') !== (string) $this->school_id) {
            log_message(
                'error',
                'CROSS_TENANT_PROBE Fee_concessions::_owned_doc — school_id=' . $this->school_id
                . " attempted {$collection}/{$id} owned by schoolId="
                . (string) ($doc['schoolId'] ?? '(none)')
            );
            // Identical response to the true-miss branch above (existence-oracle collapse).
            $this->json_error(ucfirst($label) . ' not found.');
            return null;
        }

        return $doc;
    }

    /**
     * P2 #31 (2026-08-12) — referential integrity guard.
     *
     * create_concession() and enroll_service() accepted any client-supplied
     * student_id that passed safe_path_segment(), so a typo or a forged id
     * produced an orphaned concession/entitlement row referencing a student
     * that does not exist, or one belonging to another tenant. The document id
     * is self-prefixed with the caller's school, so this is a data-integrity
     * problem rather than a confidentiality one — but a concession pointing at
     * nothing silently never applies, which reads as a billing bug.
     *
     * Returns true when the student exists in THIS school. Emits the JSON
     * error and returns false otherwise — callers MUST `return` on false.
     */
    private function _student_belongs_to_school(string $studentId): bool
    {
        try {
            $student = $this->fs->get('students', $this->fs->docId($studentId));
        } catch (\Throwable $e) {
            log_message('error', "Fee_concessions::_student_belongs_to_school {$studentId} failed: " . $e->getMessage());
            $this->json_error('Could not verify the student. Please retry.');
            return false;
        }

        if (!is_array($student) || empty($student)) {
            $this->json_error('Unknown student.');
            return false;
        }

        // fs->docId() already prefixes the caller's school, so a hit is
        // structurally same-tenant. Re-assert it explicitly: defence in depth
        // against any future change to the docId convention.
        $docSchool = (string) ($student['schoolId'] ?? $this->school_id);
        if ($docSchool !== (string) $this->school_id) {
            log_message(
                'error',
                'CROSS_TENANT_PROBE Fee_concessions::_student_belongs_to_school — school_id='
                . $this->school_id . " student={$studentId} owned by schoolId={$docSchool}"
            );
            $this->json_error('Unknown student.');
            return false;
        }

        return true;
    }

    /** Render the management page (or the "Under construction" placeholder). */
    public function index()
    {
        $this->_require_role([], 'fee_concessions_index', 'Fees', 'view');
        $data = [
            'concession_ui_enabled' => $this->_concession_ui_enabled(),
            'service_ui_enabled'    => $this->_service_ui_enabled(),
            'school_id'             => $this->school_id,
            'session_year'          => $this->session_year,
        ];
        $this->load->view('include/header');
        $this->load->view('fee_management/concessions', $data);
        $this->load->view('include/footer');
    }

    // ─────────────────────────────────────────────────────────────────────
    // CONCESSIONS (gated by CONCESSION_UI_ENABLED)
    // ─────────────────────────────────────────────────────────────────────

    public function list_concessions()
    {
        $this->_require_role([], 'fee_concessions_list', 'Fees', 'view');
        if (!$this->_concession_ui_enabled()) return $this->json_error('Concession UI not enabled.');

        $userId = trim((string) $this->input->post('student_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid student_id.');

        $rows = $this->firebase->firestoreQuery('studentConcessions', [
            ['schoolId',  '==', $this->school_id],
            ['studentId', '==', $userId],
        ], null, 'ASC', 200);
        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $d['_id'] = (string) ($r['id'] ?? '');
            $out[] = $d;
        }
        return $this->json_success(['concessions' => $out]);
    }

    public function create_concession()
    {
        $this->_require_role([], 'fee_concessions_create', 'Fees', 'manage');
        if (!$this->_concession_ui_enabled()) return $this->json_error('Concession UI not enabled.');
        if ($this->input->method() !== 'post')  return $this->json_error('POST required.');

        $userId  = trim((string) $this->input->post('student_id'));
        $scope   = trim((string) $this->input->post('scope'));    // head | category | all
        $target  = trim((string) $this->input->post('target'));   // feeHeadId | category name | '' for 'all'
        $type    = trim((string) $this->input->post('type'));     // percent | fixed | fullExempt
        $value   = (float) ($this->input->post('value') ?? 0);
        $session = trim((string) ($this->input->post('session') ?? '')) ?: null;
        $effFrom = trim((string) $this->input->post('effective_from'));
        $effTo   = trim((string) ($this->input->post('effective_to') ?? '')) ?: null;
        $reason  = trim((string) $this->input->post('reason'));
        $approver= trim((string) ($this->input->post('approved_by') ?? '')) ?: ($this->session->userdata('admin_id') ?? '');

        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid student_id.');
        // P2 #31 — the concession must reference a real student in THIS school.
        if (!$this->_student_belongs_to_school($userId)) return;
        if (!in_array($scope, ['head', 'category', 'all'], true)) return $this->json_error('Invalid scope.');
        if (!in_array($type,  ['percent', 'fixed', 'fullExempt'], true)) return $this->json_error('Invalid type.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effFrom)) return $this->json_error('effective_from must be YYYY-MM-DD.');
        if ($effTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effTo)) return $this->json_error('effective_to must be YYYY-MM-DD.');
        if ($type === 'percent' && ($value < 0 || $value > 100)) return $this->json_error('Percent value must be 0..100.');
        if ($type === 'fixed'   && $value <  0) return $this->json_error('Fixed value must be >= 0.');
        if ($reason === '') return $this->json_error('Reason is required.');

        $now    = date('c');
        $seq    = date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $docId  = "{$this->school_id}_{$userId}_{$seq}";

        $doc = [
            'schoolId'        => $this->school_id,
            'studentId'       => $userId,
            'scope'           => $scope,
            'targetFeeHeadId' => $scope === 'head'     ? $target : null,
            'targetCategory'  => $scope === 'category' ? $target : null,
            'type'            => $type,
            'value'           => $type === 'fullExempt' ? 0 : $value,
            'session'         => $session,
            'effectiveFrom'   => $effFrom,
            'effectiveTo'     => $effTo,
            'reason'          => $reason,
            'approvedBy'      => $approver,
            'approvedAt'      => $now,
            'status'          => 'active',
            'createdBy'       => $this->session->userdata('admin_id') ?? '',
            'createdAt'       => $now,
        ];

        // ══ LEGACY-SEAL-1 · A CLEAN SCHOOL NEVER ACQUIRES THE OLD AUTHORITY ══
        //
        // `studentConcessions` is the LEGACY demand-pricing authority. It still
        // works, and schools with existing records depend on it — but a student
        // holding one is pinned to legacy pricing (AUTH-SCOPE-1), which means
        // their canonical entitlements and every policy-driven benefit are NOT
        // applied to them. Proven: a student with BOTH an active legacy
        // concession and an active 50% entitlement received exactly ONE
        // discount, the legacy one.
        //
        // For a school that already has legacy records that is correct and
        // deliberate: switching their authority mid-history would drop
        // discounts that are currently reducing real bills.
        //
        // For a school with NO legacy records it is pure harm. One click here
        // would silently opt that student out of the canonical benefit engine
        // the rest of the product is built on, and nothing in the UI would say
        // so. A new school must not inherit an architecture it never had.
        //
        // So the door is sealed exactly where it should be: a school with no
        // legacy concessions cannot create its first one. Schools that already
        // have them are untouched, and every historical record stays readable
        // and keeps pricing.
        //
        // Fails SAFE toward the CANONICAL path: if the check itself fails we
        // refuse rather than write, because a wrongly-created legacy row
        // silently changes which engine prices that student.
        try {
            $existingLegacy = $this->firebase->firestoreQuery('studentConcessions', [
                ['schoolId', '==', (string) $this->school_name],
            ], null, 'ASC', 1);

            if (empty($existingLegacy)) {
                log_message('error', 'LEGACY_SEAL_1 school=[' . $this->school_name
                    . '] student=[' . $studentId . '] — refused a FIRST legacy concession. This '
                    . 'school has no legacy records, so it uses the canonical entitlement '
                    . 'authority; a legacy concession would silently exclude this student from '
                    . 'every policy-driven benefit.');

                return $this->json_error(
                    'This school uses the current concessions system, so this older screen '
                    . 'cannot create a new concession here. Record it under Fees → Student '
                    . 'Benefits (Services & Concessions) instead — it will be approved, '
                    . 'allocated and auditable, and it will work alongside any sibling or '
                    . 'staff-ward policy the school has configured.'
                );
            }
        } catch (\Throwable $e) {
            log_message('error', 'LEGACY_SEAL_1 could not determine whether school ['
                . $this->school_name . '] holds legacy concessions — refusing the write rather '
                . 'than risking a silent authority switch: ' . $e->getMessage());
            return $this->json_error('Could not verify this school\'s concession setup. '
                . 'Please use Fees → Student Benefits to record this concession.');
        }

        $ok = $this->firebase->firestoreSet('studentConcessions', $docId, $doc);
        if (!$ok) return $this->json_error('Could not save concession. Please retry.');
        return $this->json_success(['message' => 'Concession recorded.', 'id' => $docId]);
    }

    public function revoke_concession()
    {
        $this->_require_role([], 'fee_concessions_revoke', 'Fees', 'manage');
        if (!$this->_concession_ui_enabled()) return $this->json_error('Concession UI not enabled.');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $id = trim((string) $this->input->post('concession_id'));
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid concession_id.');

        // P1 #12 — verify tenant ownership BEFORE mutating. This PHP check is
        // the entire authorization boundary; firestore.rules denies all client
        // access to this collection, so nothing else stands behind it.
        if ($this->_owned_doc('studentConcessions', $id, 'concession') === null) return;

        $ok = $this->firebase->firestoreUpdate('studentConcessions', $id, [
            'status'      => 'revoked',
            'revokedBy'   => $this->session->userdata('admin_id') ?? '',
            'revokedAt'   => date('c'),
        ]);
        if (!$ok) return $this->json_error('Could not revoke concession. Please retry.');
        return $this->json_success(['message' => 'Concession revoked.']);
    }

    /**
     * Smart-confirm support for the legacy fee-gen UIs (manual / bulk /
     * recalc). Returns the active concessions on file for the given student
     * so the legacy-gen JS can warn the operator before running — Phase 2
     * legacy paths do not yet apply these concessions (Phase 3 convergence).
     *
     * READ-ONLY. NOT gated by CONCESSION_UI_ENABLED — the smart-confirm
     * must work even when the capture UI is hidden so admins are warned
     * about pre-existing concessions on any legacy-gen attempt.
     */
    public function check_active_concessions()
    {
        $this->_require_role([], 'fee_concessions_check_active', 'Fees', 'view');

        $userId = trim((string) $this->input->post('student_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid student_id.');

        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentConcessions', [
                ['schoolId',  '==', $this->school_id],
                ['studentId', '==', $userId],
                ['status',    '==', 'active'],
            ], null, 'ASC', 50);
        } catch (\Throwable $e) {
            log_message('error', "Fee_concessions::check_active_concessions failed for {$userId}: " . $e->getMessage());
            return $this->json_error('Could not query concessions. Please retry.');
        }

        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $out[] = [
                'id'            => (string) ($r['id'] ?? ''),
                'scope'         => (string) ($d['scope'] ?? ''),
                'target'        => (string) ($d['targetFeeHeadId'] ?? $d['targetCategory'] ?? ''),
                'type'          => (string) ($d['type'] ?? ''),
                'value'         => $d['value'] ?? 0,
                'effectiveFrom' => (string) ($d['effectiveFrom'] ?? ''),
                'effectiveTo'   => $d['effectiveTo'] ?? null,
                'reason'        => (string) ($d['reason'] ?? ''),
            ];
        }
        return $this->json_success(['active_concessions' => $out, 'count' => count($out)]);
    }

    /**
     * School-wide active-concession count — supports the smart-confirm
     * modal on chart-save and bulk-generate (where the operation spans
     * many students, not a single one). Returns total count + a sample
     * of student-ids so the operator can decide whether to proceed with
     * the legacy path that does not apply concessions.
     *
     * READ-ONLY. NOT gated by CONCESSION_UI_ENABLED — same rationale as
     * check_active_concessions.
     */
    public function check_school_active_concessions()
    {
        $this->_require_role([], 'fee_concessions_check_school', 'Fees', 'view');

        $rows = [];
        try {
            $rows = $this->firebase->firestoreQuery('studentConcessions', [
                ['schoolId', '==', $this->school_id],
                ['status',   '==', 'active'],
            ], null, 'ASC', 250);
        } catch (\Throwable $e) {
            log_message('error', 'Fee_concessions::check_school_active_concessions failed: ' . $e->getMessage());
            return $this->json_error('Could not query concessions. Please retry.');
        }

        $byStudent = [];
        foreach ($rows as $r) {
            $d   = is_array($r['data'] ?? null) ? $r['data'] : [];
            $sid = (string) ($d['studentId'] ?? '');
            if ($sid === '') continue;
            if (!isset($byStudent[$sid])) $byStudent[$sid] = 0;
            $byStudent[$sid]++;
        }
        $sample = array_slice(array_keys($byStudent), 0, 5);

        return $this->json_success([
            'count'           => count($rows),
            'student_count'   => count($byStudent),
            'sample_students' => $sample,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SERVICE ENROLLMENTS (gated by SERVICE_ENROLLMENT_UI_ENABLED — Phase 3)
    // ─────────────────────────────────────────────────────────────────────

    public function list_enrollments()
    {
        $this->_require_role([], 'fee_concessions_list_enrollments', 'Fees', 'view');
        if (!$this->_service_ui_enabled()) return $this->json_error('Service-enrollment UI not enabled.');

        $userId = trim((string) $this->input->post('student_id'));
        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid student_id.');

        $rows = $this->firebase->firestoreQuery('studentServiceEnrollments', [
            ['schoolId',  '==', $this->school_id],
            ['studentId', '==', $userId],
        ], null, 'ASC', 50);
        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            $d['_id'] = (string) ($r['id'] ?? '');
            $out[] = $d;
        }
        return $this->json_success(['enrollments' => $out]);
    }

    public function enroll_service()
    {
        $this->_require_role([], 'fee_concessions_enroll', 'Fees', 'manage');
        if (!$this->_service_ui_enabled()) return $this->json_error('Service-enrollment UI not enabled.');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $userId      = trim((string) $this->input->post('student_id'));
        $serviceType = strtolower(trim((string) $this->input->post('service_type'))); // transport|hostel|meals|...
        $planRef     = trim((string) ($this->input->post('plan_ref') ?? ''));
        $label       = trim((string) ($this->input->post('label')    ?? ''));
        $amount      = (float) ($this->input->post('amount') ?? 0);
        $frequency   = strtolower(trim((string) ($this->input->post('frequency') ?? 'monthly')));
        $effFrom     = trim((string) $this->input->post('effective_from'));
        $effTo       = trim((string) ($this->input->post('effective_to') ?? '')) ?: null;

        if ($userId === '' || !$this->safe_path_segment($userId)) return $this->json_error('Invalid student_id.');
        // P2 #31 — the enrollment must reference a real student in THIS school.
        if (!$this->_student_belongs_to_school($userId)) return;
        if ($serviceType === '' || !preg_match('/^[a-z][a-z0-9_]{1,30}$/', $serviceType)) return $this->json_error('Invalid service_type.');
        if (!in_array($frequency, ['monthly', 'annual'], true)) return $this->json_error('Invalid frequency.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effFrom)) return $this->json_error('effective_from must be YYYY-MM-DD.');
        if ($effTo !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effTo)) return $this->json_error('effective_to must be YYYY-MM-DD.');
        if ($amount < 0) return $this->json_error('amount must be >= 0.');

        $now   = date('c');
        $docId = "{$this->school_id}_{$userId}_{$serviceType}";

        $doc = [
            'schoolId'      => $this->school_id,
            'studentId'     => $userId,
            'serviceType'   => $serviceType,
            'planRef'       => $planRef,
            'label'         => $label,
            'amount'        => $amount,
            'frequency'     => $frequency,
            'effectiveFrom' => $effFrom,
            'effectiveTo'   => $effTo,
            'status'        => 'active',
            'createdBy'     => $this->session->userdata('admin_id') ?? '',
            'createdAt'     => $now,
            'updatedAt'     => $now,
        ];

        $ok = $this->firebase->firestoreSet('studentServiceEnrollments', $docId, $doc);
        if (!$ok) return $this->json_error('Could not save enrollment. Please retry.');
        return $this->json_success(['message' => 'Service enrolled.', 'id' => $docId]);
    }

    public function discontinue_service()
    {
        $this->_require_role([], 'fee_concessions_discontinue', 'Fees', 'manage');
        if (!$this->_service_ui_enabled()) return $this->json_error('Service-enrollment UI not enabled.');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $id    = trim((string) $this->input->post('enrollment_id'));
        $effTo = trim((string) ($this->input->post('effective_to') ?? '')) ?: date('Y-m-d');
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid enrollment_id.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effTo)) return $this->json_error('effective_to must be YYYY-MM-DD.');

        // P1 #12 — same tenant-ownership guard as revoke_concession. Applied
        // even though SERVICE_ENROLLMENT_UI_ENABLED is currently false and this
        // endpoint is therefore unreachable: the two mutators must not drift,
        // or flipping that flag later silently reopens the IDOR.
        if ($this->_owned_doc('studentServiceEnrollments', $id, 'enrollment') === null) return;

        $ok = $this->firebase->firestoreUpdate('studentServiceEnrollments', $id, [
            'status'        => 'discontinued',
            'effectiveTo'   => $effTo,
            'discontinuedBy'=> $this->session->userdata('admin_id') ?? '',
            'discontinuedAt'=> date('c'),
            'updatedAt'     => date('c'),
        ]);
        if (!$ok) return $this->json_error('Could not discontinue. Please retry.');
        return $this->json_success(['message' => 'Service discontinued.']);
    }
}
