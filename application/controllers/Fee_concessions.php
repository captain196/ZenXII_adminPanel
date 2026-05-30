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
 *   1. Role gate (MANAGE_ROLES) via MY_Controller::_require_role.
 *   2. Feature flag (CONCESSION_UI_ENABLED / SERVICE_ENROLLMENT_UI_ENABLED).
 * The flag gate stops accidental client population while billing still
 * ignores the data — the operator's explicit "no capture-without-billing"
 * requirement.
 */
class Fee_concessions extends MY_Controller
{
    private const MANAGE_ROLES = ['School Super Admin', 'School Admin'];
    private const VIEW_ROLES   = ['School Super Admin', 'School Admin', 'Accountant'];

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

    /** Render the management page (or the "Under construction" placeholder). */
    public function index()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_concessions_index');
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
        $this->_require_role(self::VIEW_ROLES, 'fee_concessions_list');
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
        $this->_require_role(self::MANAGE_ROLES, 'fee_concessions_create');
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

        $ok = $this->firebase->firestoreSet('studentConcessions', $docId, $doc);
        if (!$ok) return $this->json_error('Could not save concession. Please retry.');
        return $this->json_success(['message' => 'Concession recorded.', 'id' => $docId]);
    }

    public function revoke_concession()
    {
        $this->_require_role(self::MANAGE_ROLES, 'fee_concessions_revoke');
        if (!$this->_concession_ui_enabled()) return $this->json_error('Concession UI not enabled.');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $id = trim((string) $this->input->post('concession_id'));
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid concession_id.');

        $ok = $this->firebase->firestoreUpdate('studentConcessions', $id, [
            'status'      => 'revoked',
            'revokedBy'   => $this->session->userdata('admin_id') ?? '',
            'revokedAt'   => date('c'),
        ]);
        if (!$ok) return $this->json_error('Could not revoke concession. Please retry.');
        return $this->json_success(['message' => 'Concession revoked.']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SERVICE ENROLLMENTS (gated by SERVICE_ENROLLMENT_UI_ENABLED — Phase 3)
    // ─────────────────────────────────────────────────────────────────────

    public function list_enrollments()
    {
        $this->_require_role(self::VIEW_ROLES, 'fee_concessions_list_enrollments');
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
        $this->_require_role(self::MANAGE_ROLES, 'fee_concessions_enroll');
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
        $this->_require_role(self::MANAGE_ROLES, 'fee_concessions_discontinue');
        if (!$this->_service_ui_enabled()) return $this->json_error('Service-enrollment UI not enabled.');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $id    = trim((string) $this->input->post('enrollment_id'));
        $effTo = trim((string) ($this->input->post('effective_to') ?? '')) ?: date('Y-m-d');
        if ($id === '' || !$this->safe_path_segment($id)) return $this->json_error('Invalid enrollment_id.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effTo)) return $this->json_error('effective_to must be YYYY-MM-DD.');

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
