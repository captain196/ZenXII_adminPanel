<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Financial_policies — the school's Benefits & Concession policy screen.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS IS A SEPARATE CONTROLLER
 * ─────────────────────────────────────────────────────────────────────────
 *  The obvious home would be Fee_management, which already owns concessions —
 *  but that file sits at a parked commit boundary this workstream must not
 *  disturb. A small dedicated controller keeps the diff honest and the routes
 *  explicit, and costs nothing: it owns two endpoints and one screen.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHAT THIS SCREEN IS, AND IS NOT
 * ─────────────────────────────────────────────────────────────────────────
 *  It configures WHETHER a school offers a benefit and on what terms. It is
 *  NOT where money is granted: a verified sibling or staff-ward relationship
 *  remains, on its own, worth nothing. The screen says so in as many words,
 *  because the single most likely misreading is "I ticked the relationship, so
 *  the discount happened".
 *
 *  Platform financial invariants are deliberately absent from it. There is no
 *  switch for tenant isolation, negative nets, audit trails, idempotency or
 *  I3's protection of service fees — those are not preferences, and a settings
 *  page that appears to offer them would be worse than one that does not.
 *
 *  `Fees: manage` throughout. Reading which benefits a school grants is
 *  financially sensitive, and changing them decides real money — this is not a
 *  view-level screen.
 */
class Financial_policies extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('benefit_policy_service', null, 'benefitPolicy');
    }

    private function svc()
    {
        return $this->benefitPolicy->init(
            $this->fs, $this->school_id, $this->session_year,
            (string) ($this->admin_id ?? 'system')
        );
    }

    /** The screen. */
    public function index()
    {
        $this->_require_role([], 'financial_policies_view', 'Fees', 'manage');
        $this->load->view('include/header');
        $this->load->view('financial_policies/index');
        $this->load->view('include/footer');
    }

    /**
     * POST student_id, benefit — evaluate ONE student against the policy.
     *
     * This is the seam between "the school has a policy" and "this child gets
     * money". It grants nothing by itself: with the benefit disabled it
     * returns policy_disabled, and with approval required it produces a
     * PENDING entitlement that a second person must authorise.
     *
     * `manage`, because a successful call can create a financial entitlement.
     */
    public function evaluate_student()
    {
        $this->_require_role([], 'financial_policies_evaluate', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $studentId = trim((string) $this->input->post('student_id'));
        $benefit   = trim((string) $this->input->post('benefit'));
        if ($studentId === '' || !$this->safe_path_segment($studentId)) {
            return $this->json_error('Invalid student_id.');
        }
        if (!in_array($benefit, Benefit_policy_service::BENEFITS, true)) {
            return $this->json_error('Unknown benefit.');
        }

        $this->load->library('entitlement_service',          null, 'entSvc');
        $this->load->library('student_relationship_service', null, 'relSvc');
        $this->load->library('benefit_award_service',        null, 'awardSvc');

        $this->entSvc->init($this->firebase, $this->fs, $this->school_id,
                            $this->session_year, (string) ($this->admin_id ?? 'system'));
        $this->relSvc->init($this->fs, $this->firebase, $this->school_id,
                            $this->session_year, (string) ($this->admin_id ?? 'system'));

        $res = $this->awardSvc->init(
            $this->svc(), $this->entSvc, $this->relSvc, $this->fs,
            $this->school_id, $this->session_year, (string) ($this->admin_id ?? 'system')
        )->evaluate($studentId, $benefit);

        return $this->json_success($res);
    }

    /** POST — current policy for this school + session. */
    public function get_policies()
    {
        $this->_require_role([], 'financial_policies_get', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $svc = $this->svc();
        $out = [];
        foreach (Benefit_policy_service::BENEFITS as $b) {
            // resolve() collapses anything invalid to disabled, which is what a
            // reader must act on. The raw stored value is returned alongside so
            // the form shows what was actually saved rather than silently
            // rewriting the operator's input.
            $out[$b] = $svc->resolve($b);
        }
        $raw = $svc->raw();

        return $this->json_success([
            'policies' => $out,
            'stored'   => array_intersect_key($raw, array_flip(Benefit_policy_service::BENEFITS)),
            'version'  => (int) ($raw['version'] ?? 0),
            'session'  => $this->session_year,
            'history'  => array_slice((array) ($raw['history'] ?? []), -10),
        ]);
    }

    /**
     * POST — save ONE benefit's policy.
     *
     * Validated server-side by the same rules the resolver applies, so a
     * crafted request cannot store a policy the reader would then refuse (or
     * worse, act on). The browser's validation is a convenience, never the
     * boundary.
     */
    public function save_policy()
    {
        $this->_require_role([], 'financial_policies_save', 'Fees', 'manage');
        if ($this->input->method() !== 'post') return $this->json_error('POST required.');

        $benefit = trim((string) $this->input->post('benefit'));
        if (!in_array($benefit, Benefit_policy_service::BENEFITS, true)) {
            return $this->json_error('Unknown benefit.');
        }

        $cfg = [
            'mode'          => trim((string) $this->input->post('mode')),
            'valueType'     => trim((string) $this->input->post('value_type')),
            'value'         => (float) $this->input->post('value'),
            'scope'         => trim((string) $this->input->post('scope')),
            'effectiveFrom' => trim((string) $this->input->post('effective_from')),
            'effectiveTo'   => trim((string) $this->input->post('effective_to')),
            'maxBenefit'    => (float) $this->input->post('max_benefit'),
        ];
        if ($benefit === Benefit_policy_service::BENEFIT_SIBLING) {
            $cfg['requireSiblingActive'] = ((string) $this->input->post('require_active') === '1');
        } else {
            $cfg['requireStaffActive'] = ((string) $this->input->post('require_active') === '1');
            $cfg['maxChildren']        = (int) $this->input->post('max_children');
        }

        $res = $this->svc()->saveBenefit($benefit, $cfg);
        if (empty($res['ok'])) return $this->json_error((string) ($res['error'] ?? 'Could not save.'));

        return $this->json_success([
            'version' => (int) $res['version'],
            'note'    => 'benefit=' . $benefit,
            'message' => $cfg['mode'] === Benefit_policy_service::MODE_DISABLED
                ? 'Benefit disabled. Existing approved concessions are unaffected; no new benefit will be granted.'
                : 'Policy saved as version ' . (int) $res['version']
                  . '. It applies to benefits evaluated from now on — already-billed periods are not repriced.',
        ]);
    }
}
