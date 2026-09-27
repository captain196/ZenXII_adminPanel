<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Compensation — salary component master endpoints (ZenX HRMS W2.1).
 *
 * Deliberately a SEPARATE controller rather than more methods on `Hr.php`,
 * which is already ~8,900 lines and was explicitly ruled out as the home for
 * new HRMS surface area. The compensation layer will grow through W2.2–W2.6;
 * it starts in its own file.
 *
 * SCOPE (W2.1): catalogue CRUD only. This controller reads and writes exactly
 * one collection, `salaryComponents`, and NOTHING in the payroll path consults
 * it yet. Payroll behaviour after this gate is byte-for-byte what it was before.
 *
 * RBAC: the existing graded `HR` module. Reads need HR:view, writes need
 * HR:manage — the same boundary `Hr::save_salary_structure()` already applies
 * to compensation data. No new RBAC module is created here: separating Payroll
 * from HR is gap 92 (G19), scheduled for W5 and cross-system (PHP resolver +
 * staffCapabilities Cloud Function + Firestore rules). Introducing a partial
 * split now would leave the three copies disagreeing.
 *
 * ENTITLEMENT: rides the existing `hrms_payroll` capability via MY_Controller's
 * gate. No new entitlement key is introduced.
 */
class Compensation extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_permission('HR');
        $this->load->library('salary_component_service', null, 'components');
        $this->components->init($this->fs, (string) ($this->admin_name ?? ''));
    }

    private function _require_view(): void
    {
        require_permission('HR', 'compensation_view', 'view');
    }

    // ── Page (W2.5) ──────────────────────────────────────────────────────

    /**
     * GET /compensation[/tab] — the compensation administration page.
     *
     * All four tabs route here and the active one comes from the URI segment,
     * matching how `Hr::index()` already works. `can_manage` is resolved
     * server-side and drives whether the forms render at all — the endpoints
     * enforce it again, so hiding a form is a courtesy, never the control.
     */
    public function index(): void
    {
        $this->_require_view();

        $tab = $this->uri->segment(2, 'components');
        $allowed = ['components', 'templates', 'grades', 'statutory', 'classification',
                    'assignments', 'fnf', 'readiness'];
        if (!in_array($tab, $allowed, true)) $tab = 'components';

        $data = [
            'active_tab' => $tab,
            'can_manage' => has_permission('HR', 'manage'),
        ];

        $this->load->view('include/header', $data);
        $this->load->view('compensation/index', $data);
        $this->load->view('include/footer');
    }

    private function _require_manage(): void
    {
        require_permission('HR', 'compensation_manage', 'manage');
    }

    // ── Reads ────────────────────────────────────────────────────────────

    /** GET — the tenant's component catalogue. */
    public function get_components(): void
    {
        $this->_require_view();

        $activeOnly = ($this->input->get('active_only') ?? '0') === '1';
        $list = $this->components->listAll($activeOnly);

        $this->json_success([
            'components' => $list,
            'total'      => count($list),
            'meta'       => [
                'kinds'     => Salary_component_service::KINDS,
                'calcTypes' => Salary_component_service::CALC_TYPES,
                'bases'     => Salary_component_service::BASES,
                'statutory' => Salary_component_service::STATUTORY,
            ],
        ]);
    }

    /** GET — one component by code. */
    public function get_component(): void
    {
        $this->_require_view();

        $code = $this->safe_path_segment(trim((string) ($this->input->get('code') ?? '')), 'code');
        $c = $this->components->get($code);
        if ($c === null) {
            $this->json_error('Component not found.');
            return;
        }
        $this->json_success(['component' => $c]);
    }

    // ── Writes ───────────────────────────────────────────────────────────

    /** POST — create a component. */
    public function save_component(): void
    {
        $this->_require_manage();

        $in = $this->_payload();
        $code = strtoupper(trim((string) ($in['code'] ?? '')));

        // Update when the code already exists, create otherwise. The code is
        // the document-id suffix, so this is a deterministic decision rather
        // than a guess about operator intent.
        $exists = $code !== '' && $this->components->get($code) !== null;

        [$ok, $errors] = $exists
            ? $this->components->update($code, $in)
            : $this->components->create($in);

        if (!$ok) {
            $this->json_error(implode(' ', $errors));
            return;
        }
        $this->json_success([
            'code'    => $code,
            'message' => $exists ? 'Component updated.' : 'Component created.',
        ]);
    }

    /** POST — activate / deactivate. No hard delete; see the service docblock. */
    public function set_component_active(): void
    {
        $this->_require_manage();

        $code   = $this->safe_path_segment(trim((string) ($this->input->post('code') ?? '')), 'code');
        $active = ((string) ($this->input->post('active') ?? '1')) === '1';

        [$ok, $errors] = $this->components->setActive($code, $active);
        if (!$ok) {
            $this->json_error(implode(' ', $errors));
            return;
        }
        $this->json_success(['message' => $active ? 'Component activated.' : 'Component deactivated.']);
    }

    /**
     * POST — idempotently seed this tenant's 13 system components.
     *
     * Idempotent by design: existing codes are skipped, never overwritten, so
     * an operator's name/order edits survive a re-run and a second click is
     * harmless.
     */
    public function seed_system_components(): void
    {
        $this->_require_manage();

        $report = $this->components->seedSystemComponents();
        $this->json_success([
            'message' => sprintf(
                'Seed complete — %d created, %d already present, %d failed.',
                count($report['created']), count($report['skipped']), count($report['failed'])
            ),
            'report'  => $report,
        ]);
    }

    // ── Statutory configuration (W2.2) ───────────────────────────────────

    /**
     * GET — this tenant's statutory configurations, plus the one in force.
     *
     * `as_of` (YYYY-MM-DD, default today) selects the effective window. A null
     * `effective` is a real answer — no configuration covers that date — not
     * an error, and W2.2 deliberately ships with none written (see the report).
     */
    public function get_statutory_config(): void
    {
        $this->_require_view();
        $this->_statutory();

        $asOf = trim((string) ($this->input->get('as_of') ?? '')) ?: date('Y-m-d');
        $all  = $this->statutory->listAll();

        $this->json_success([
            'configs'   => $all,
            'total'     => count($all),
            'as_of'     => $asOf,
            'effective' => Statutory_config_service::selectFor($all, $asOf),
            // Surfaced so an operator sees ambiguity rather than discovering it
            // through a wrong payslip months later.
            'overlaps'  => Statutory_config_service::findOverlaps($all),
            'meta'      => ['tdsModes' => Statutory_config_service::TDS_MODES],
        ]);
    }

    /**
     * POST — create or replace the configuration starting on `effective_from`.
     *
     * The service refuses any window that would overlap an existing one.
     * Payload is JSON so the nested pf/esi/pt/tds maps and the PT slab array
     * survive intact — form encoding would flatten them.
     */
    public function save_statutory_config(): void
    {
        $this->_require_manage();
        $this->_statutory();

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw)) {
            $raw = json_decode((string) ($this->input->post('config') ?? ''), true);
        }
        if (!is_array($raw)) {
            $this->json_error('Send the configuration as a JSON body (or a `config` JSON field).');
            return;
        }

        [$ok, $errors] = $this->statutory->save($raw);
        if (!$ok) {
            $this->json_error(implode(' ', $errors));
            return;
        }
        $this->json_success(['message' => 'Statutory configuration saved.']);
    }

    // ── Salary templates (W2.6) ──────────────────────────────────────────

    /** GET — this tenant's reusable salary structures. */
    public function get_templates(): void
    {
        $this->_require_view();
        $this->_templates();
        $list = $this->templates->listAll(($this->input->get('active_only') ?? '0') === '1');
        $this->json_success(['templates' => $list, 'total' => count($list)]);
    }

    /** POST — create or update a template (JSON body; `lines` is an array). */
    public function save_template(): void
    {
        $this->_require_manage();
        $this->_templates();

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw)) $raw = json_decode((string) ($this->input->post('template') ?? ''), true);
        if (!is_array($raw)) { $this->json_error('Send the template as a JSON body.'); return; }

        [$ok, $errors] = $this->templates->save($raw, $this->_catalogue());
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }
        $this->json_success(['message' => 'Template saved.']);
    }

    /** POST — activate / deactivate a template. */
    public function set_template_active(): void
    {
        $this->_require_manage();
        $this->_templates();

        $code   = $this->safe_path_segment(trim((string) ($this->input->post('code') ?? '')), 'code');
        $active = ((string) ($this->input->post('active') ?? '1')) === '1';

        [$ok, $errors] = $this->templates->setActive($code, $active);
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }
        $this->json_success(['message' => $active ? 'Template activated.' : 'Template deactivated.']);
    }

    // ── Assignment preview (W2.6) ────────────────────────────────────────

    /**
     * POST — evaluate a candidate assignment WITHOUT saving it.
     *
     * The administrator sees, before committing: the component split, the CTC
     * with its employer breakdown, the pay-band verdict, and any validation
     * errors. Doing this server-side rather than in JavaScript means the
     * preview is computed by the same code that will price the payslip — a
     * browser-side estimate that disagreed with the saved result would be worse
     * than no preview.
     *
     * Reads nothing it does not need and writes nothing at all.
     */
    public function preview_assignment(): void
    {
        $this->_require_view();
        $this->_assignments();
        $this->_grades();
        $this->load->library('salary_template_service', null, 'templates');

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw)) $raw = json_decode((string) ($this->input->post('assignment') ?? ''), true);
        if (!is_array($raw)) { $this->json_error('Send the candidate assignment as a JSON body.'); return; }

        $catalogue = $this->_catalogue();

        // A template + a gross figure is enough to build the lines.
        if (empty($raw['lines']) && !empty($raw['templateId'])) {
            $tpl = $this->templates->get((string) $raw['templateId']);
            if ($tpl === null) { $this->json_error('That template no longer exists.'); return; }
            $raw = Salary_template_service::apply(
                $tpl,
                (string) ($raw['staffId'] ?? ''),
                (string) ($raw['effectiveFrom'] ?? date('Y-m-d')),
                (int) ($raw['grossPaise'] ?? 0)
            ) + $raw;
        }

        [$clean, $errors] = $this->assignments->validate($raw, $catalogue);

        $split = Salary_assignment_service::splitGross($clean, $catalogue);
        $ctc   = Salary_assignment_service::deriveCtc($clean, $catalogue);
        $band  = $this->grades->checkAssignmentBand($clean);

        // Overlap is reported here too, so the operator learns about it while
        // editing rather than when the save is refused.
        $others = array_values(array_filter(
            $this->assignments->listForStaff((string) $clean['staffId']),
            fn($a) => (string) ($a['effectiveFrom'] ?? '') !== $clean['effectiveFrom']
        ));
        $this->load->helper('effective_dating');
        $overlaps = ed_find_overlaps(array_merge($others, [$clean]), 'assignment');

        $this->json_success([
            'valid'      => empty($errors) && empty($overlaps),
            'errors'     => $errors,
            'overlaps'   => $overlaps,
            'split'      => $split,
            'ctc'        => $ctc,
            'band'       => $band,
            'assignment' => $clean,
        ]);
    }

    /** This tenant's component catalogue, keyed by code. */
    private function _catalogue(): array
    {
        $out = [];
        foreach ($this->components->listAll() as $c) $out[(string) $c['code']] = $c;
        return $out;
    }

    private function _templates(): void
    {
        if (!isset($this->templates)) {
            $this->load->library('salary_template_service', null, 'templates');
            $this->templates->init($this->fs, (string) ($this->admin_name ?? ''));
        }
    }

    // ── Pay grades (W2.5) ────────────────────────────────────────────────

    /** GET — this tenant's pay grades. */
    public function get_grades(): void
    {
        $this->_require_view();
        $this->_grades();
        $list = $this->grades->listAll(($this->input->get('active_only') ?? '0') === '1');
        $this->json_success(['grades' => $list, 'total' => count($list)]);
    }

    /** POST — create or update a pay grade. */
    public function save_grade(): void
    {
        $this->_require_manage();
        $this->_grades();

        [$ok, $errors] = $this->grades->save([
            'code'         => (string) ($this->input->post('code') ?? ''),
            'name'         => (string) ($this->input->post('name') ?? ''),
            'description'  => (string) ($this->input->post('description') ?? ''),
            // Sent as integer paise by the UI; the service rejects a decimal,
            // which is what stops rupees being stored where paise are expected.
            'minPaise'     => (string) ($this->input->post('minPaise') ?? '0'),
            'maxPaise'     => (string) ($this->input->post('maxPaise') ?? '0'),
            'displayOrder' => (int) ($this->input->post('displayOrder') ?? 0),
            'active'       => ((string) ($this->input->post('active') ?? '1')) === '1',
        ]);
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }
        $this->json_success(['message' => 'Pay grade saved.']);
    }

    /** POST — activate / deactivate. No hard delete: assignments reference grades by code. */
    public function set_grade_active(): void
    {
        $this->_require_manage();
        $this->_grades();

        $code   = $this->safe_path_segment(trim((string) ($this->input->post('code') ?? '')), 'code');
        $active = ((string) ($this->input->post('active') ?? '1')) === '1';

        [$ok, $errors] = $this->grades->setActive($code, $active);
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }
        $this->json_success(['message' => $active ? 'Pay grade activated.' : 'Pay grade deactivated.']);
    }

    private function _grades(): void
    {
        if (!isset($this->grades)) {
            $this->load->library('salary_grade_service', null, 'grades');
            $this->grades->init($this->fs, (string) ($this->admin_name ?? ''));
        }
    }

    // ── Salary assignments (W2.3) ────────────────────────────────────────

    /** GET — one employee's assignment history, plus the one in force. */
    public function get_salary_assignments(): void
    {
        $this->_require_view();
        $this->_assignments();

        $staffId = trim((string) ($this->input->get('staff_id') ?? ''));
        // IDOR fix (2026-08-18) — HR:view alone must not read an arbitrary
        // OTHER employee's salary assignment history; the same clamp
        // Hr.php already applies to every other cross-staff payroll read
        // (get_payroll_slips/export_payroll_report/get_payslip). Self-
        // service (viewing one's own assignment) is unaffected.
        if (!(function_exists('has_permission') && has_permission('HR', 'manage'))) {
            $staffId = (string) ($this->admin_id ?? '');
        }
        $staffId = $this->safe_path_segment($staffId, 'staff_id');
        $asOf    = trim((string) ($this->input->get('as_of') ?? '')) ?: date('Y-m-d');

        $all = $this->assignments->listForStaff($staffId);
        $this->load->helper('effective_dating');

        $this->json_success([
            'staff_id'    => $staffId,
            'assignments' => $all,
            'as_of'       => $asOf,
            'effective'   => ed_select_for($all, $asOf),
            'overlaps'    => ed_find_overlaps($all, 'assignment'),
        ]);
    }

    /** POST — create or supersede an assignment (JSON body). */
    public function save_salary_assignment(): void
    {
        $this->_require_manage();
        $this->_assignments();

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw)) $raw = json_decode((string) ($this->input->post('assignment') ?? ''), true);
        if (!is_array($raw)) {
            $this->json_error('Send the assignment as a JSON body (or an `assignment` JSON field).');
            return;
        }

        // Lines may only reference components this tenant actually has.
        $catalogue = [];
        foreach ($this->components->listAll() as $c) $catalogue[(string) $c['code']] = $c;

        [$ok, $errors] = $this->assignments->save($raw, $catalogue);
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }
        $this->json_success(['message' => 'Salary assignment saved.']);
    }

    /**
     * GET — payroll readiness for a period.
     *
     * The "missing configuration → visible setup requirement" surface: it names
     * the people who cannot be paid and why, so payroll is blocked with an
     * actionable list rather than run with zeros.
     */
    public function get_payroll_readiness(): void
    {
        $this->_require_view();
        $this->_assignments();

        $asOf = trim((string) ($this->input->get('as_of') ?? '')) ?: date('Y-m-d');

        $staff = [];
        try {
            foreach ($this->fs->schoolWhere('staff', [['status', '==', 'Active']]) as $row) {
                $d = $row['data'] ?? null;
                if (!is_array($d)) continue;
                $staff[] = ['staffId' => (string) ($d['staffId'] ?? ''),
                            'name'    => (string) ($d['Name'] ?? $d['name'] ?? '')];
            }
        } catch (\Exception $e) {
            log_message('error', 'Compensation::get_payroll_readiness staff read failed — ' . $e->getMessage());
            $this->json_error('Could not read the staff roster.');
            return;
        }

        // Payout checks are opt-in: bank/PAN/PF/ESI live in the server-only PII
        // mirror, so the roster read above may not carry them. Asking for them
        // without merging the mirror would report every employee as unpayable.
        $this->_grades();
        $opts = [
            'checkPayoutDetails' => ((string) ($this->input->get('check_payout') ?? '0')) === '1',
            'gradeService'       => $this->grades,
        ];

        // READY-D1. Resolve through the compensation authority that is actually
        // in force. With USE_COMPONENT_COMPENSATION off -- the production default --
        // component assignments do not exist, and evaluating them reported all 55
        // employees of a live tenant as unpayable while payroll ran correctly from
        // legacy salary structures. The mode travels back with the payload so the
        // screen can say which authority it judged, rather than presenting a wall
        // of blockers with no explanation.
        require_once APPPATH . 'libraries/Compensation_reader.php';
        $this->config->load('hrms_compensation_flags', true);
        $mode = Compensation_reader::modeFromFlags(
            (bool) $this->config->item('USE_COMPONENT_COMPENSATION', 'hrms_compensation_flags'),
            (bool) $this->config->item('COMPENSATION_SHADOW_COMPARE', 'hrms_compensation_flags')
        );

        if ($mode !== Compensation_reader::MODE_COMPONENT) {
            $reader = new Compensation_reader();
            $reader->init($this->fs, $mode);

            // Legacy structures carry no lifecycle of their own: a structure that
            // exists IS the effective one. Normalised here into the shape
            // readiness() checks, so one implementation serves both authorities.
            $opts['resolver'] = static function (string $staffId, string $date) use ($reader): ?array {
                $doc = $reader->resolve($staffId, $date);
                if (!is_array($doc) || $doc === []) return null;

                $lines = [];
                foreach ($doc as $code => $amount) {
                    if (is_numeric($amount) && (float) $amount > 0.0) {
                        $lines[] = ['code' => (string) $code, 'amount' => (float) $amount];
                    }
                }
                if ($lines === []) return null;   // a structure of all zeroes pays nothing

                return ['status' => 'active', 'lines' => $lines, 'effectiveFrom' => ''];
            };
            $opts['missingReason'] = 'No salary structure is on record for this employee.';
        }

        $this->json_success($this->assignments->readiness($staff, $asOf, $opts)
            + ['as_of' => $asOf, 'compensation_mode' => $mode]);
    }

    /** Lazily load the assignment service. */
    private function _assignments(): void
    {
        if (!isset($this->assignments)) {
            $this->load->library('salary_assignment_service', null, 'assignments');
            $this->assignments->init($this->fs, (string) ($this->admin_name ?? ''));
        }
    }

    /** Lazily load the statutory service — only these two endpoints need it. */
    private function _statutory(): void
    {
        if (!isset($this->statutory)) {
            $this->load->library('statutory_config_service', null, 'statutory');
            $this->statutory->init($this->fs, (string) ($this->admin_name ?? ''));
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Collect the component payload from POST.
     *
     * Booleans arrive as '1'/'0' strings from a form post; integers likewise.
     * They are normalised here so the service validates real types — in
     * particular `amountPaise` must survive as an integer string so the
     * "integer paise, never rupees" check can reject a decimal.
     */
    private function _payload(): array
    {
        $bool = fn(string $k) => in_array((string) ($this->input->post($k) ?? '0'), ['1', 'true', 'on'], true);

        $basisComponents = $this->input->post('basisComponents');
        if (is_string($basisComponents)) {
            $basisComponents = array_filter(array_map('trim', explode(',', $basisComponents)));
        }
        if (!is_array($basisComponents)) $basisComponents = [];

        $rate   = $this->input->post('rate');
        $amount = $this->input->post('amountPaise');

        return [
            'code'            => (string) ($this->input->post('code') ?? ''),
            'name'            => (string) ($this->input->post('name') ?? ''),
            'kind'            => (string) ($this->input->post('kind') ?? ''),
            'calcType'        => (string) ($this->input->post('calcType') ?? ''),
            'basis'           => (string) ($this->input->post('basis') ?? 'NONE'),
            'basisComponents' => $basisComponents,
            'rate'            => ($rate === null || $rate === '') ? null : $rate,
            'amountPaise'     => ($amount === null || $amount === '') ? null : $amount,
            'taxable'         => $bool('taxable'),
            'pfApplicable'    => $bool('pfApplicable'),
            'esiApplicable'   => $bool('esiApplicable'),
            'prorated'        => $bool('prorated'),
            'statutory'       => $this->input->post('statutory'),
            'displayOrder'    => (string) ($this->input->post('displayOrder') ?? '0'),
            'active'          => $bool('active'),
        ];
    }

    // ── Employee statutory profile (CA-6) ────────────────────────────────

    /**
     * GET — one employee's PF/ESI statutory profile.
     *
     * Lives on the STAFF document under `statutoryProfile`, not in a new
     * collection: a new collection would need a new Firestore rules block, and
     * `firestore.rules` is concurrently owned. Reusing the staff document
     * inherits the access control that already protects staff records, which is
     * the right posture for data of this sensitivity anyway.
     */
    public function get_staff_statutory_profile(): void
    {
        $this->_require_view();

        $staffId = trim((string) ($this->input->get('staff_id') ?? ''));
        if ($staffId === '') { $this->json_error('staff_id is required.'); return; }

        require_once APPPATH . 'libraries/Staff_statutory_profile_service.php';

        try {
            $doc = $this->fs->get('staff', $this->fs->docId($staffId));
        } catch (\Exception $e) {
            log_message('error', 'Compensation::get_staff_statutory_profile — ' . $e->getMessage());
            $this->json_error('Could not read the staff record.');
            return;
        }
        if (!is_array($doc) || $doc === []) { $this->json_error('Staff member not found.'); return; }

        $profile = is_array($doc[Staff_statutory_profile_service::FIELD] ?? null)
            ? $doc[Staff_statutory_profile_service::FIELD]
            : Staff_statutory_profile_service::emptyProfile();

        $this->json_success([
            'staff_id'     => $staffId,
            'staff_name'   => (string) ($doc['name'] ?? $doc['staff_name'] ?? ''),
            'profile'      => $profile,
            'completeness' => Staff_statutory_profile_service::completeness($profile),
            'meta'         => [
                'pfStates' => Staff_statutory_profile_service::PF_STATES,
                'pfBases'  => Staff_statutory_profile_service::BASES,
            ],
        ]);
    }

    /**
     * POST — record an employee's statutory profile.
     *
     * The service validates and NORMALISES before anything is written, so an
     * incoherent record (a member with no basis, a Para 26(6) election claiming
     * pension-scheme membership) is refused rather than stored and later
     * discovered on a payslip.
     *
     * Only the `statutoryProfile` field is written. A whole-document write here
     * would race every other staff editor in the panel.
     */
    public function save_staff_statutory_profile(): void
    {
        $this->_require_manage();

        $staffId = trim((string) ($this->input->post('staff_id') ?? ''));
        if ($staffId === '') { $this->json_error('staff_id is required.'); return; }

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw) || !isset($raw['profile'])) {
            $raw = ['profile' => json_decode((string) ($this->input->post('profile') ?? ''), true)];
        }
        if (!is_array($raw['profile'] ?? null)) {
            $this->json_error('Send the profile as a JSON body (or a `profile` JSON field).');
            return;
        }

        require_once APPPATH . 'libraries/Staff_statutory_profile_service.php';

        [$ok, $clean, $errors] = Staff_statutory_profile_service::validate($raw['profile']);
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }

        $clean['updatedAt'] = date('c');
        $clean['updatedBy'] = (string) ($this->admin_name ?? '');

        try {
            $existing = $this->fs->get('staff', $this->fs->docId($staffId));
            if (!is_array($existing) || $existing === []) {
                $this->json_error('Staff member not found.');
                return;
            }

            // Dotted path — writes ONLY this field, so a concurrent edit to the
            // rest of the staff record is not clobbered.
            $written = $this->fs->update('staff', $this->fs->docId($staffId), [
                Staff_statutory_profile_service::FIELD => $clean,
            ]);
            if ($written === false) { $this->json_error('Could not save the statutory profile.'); return; }
        } catch (\Exception $e) {
            log_message('error', 'Compensation::save_staff_statutory_profile — ' . $e->getMessage());
            $this->json_error('Could not save the statutory profile.');
            return;
        }

        // The audit trail records WHAT changed, because a PF membership change
        // alters somebody's take-home and their employer's liability.
        log_audit('Compensation', 'staff_statutory_profile_saved', $staffId,
            'PF state=' . $clean['pf']['state'] . ' basis=' . $clean['pf']['basis']
            . ' memberSince=' . ($clean['pf']['memberSince'] ?: '-')
            . ' by ' . (string) ($this->admin_name ?? ''));

        $this->json_success([
            'message'      => 'Statutory profile saved.',
            'profile'      => $clean,
            'completeness' => Staff_statutory_profile_service::completeness($clean),
        ]);
    }

    // ── Component wage classification (Feature 1) ────────────────────────

    /**
     * GET — every component with its statutory default, its tenant override,
     * and what actually resolves for a given date.
     *
     * Returns all three because they answer different questions: what the Code
     * on Wages implies, what this school decided, and which of the two is in
     * force for the period being looked at. Showing only the resolved value
     * would hide that a school had overridden anything.
     */
    public function get_component_classifications(): void
    {
        $this->_require_view();
        require_once APPPATH . 'libraries/Statutory_wage_base.php';

        $asOf = trim((string) ($this->input->get('as_of') ?? '')) ?: date('Y-m-d');

        $rows = [];
        foreach ($this->components->listAll() as $c) {
            $code = (string) ($c['code'] ?? '');
            if ($code === '') continue;

            $detail = Statutory_wage_base::classificationDetail($code, $c, $asOf);

            $rows[] = [
                'code'              => $code,
                'name'              => (string) ($c['name'] ?? $code),
                'kind'              => (string) ($c['kind'] ?? ''),
                'statutoryDefault'  => Statutory_wage_base::DEFAULT_CLASSIFICATION[$code] ?? null,
                'resolved'          => $detail['classification'],
                'source'            => $detail['source'],
                'effectiveFrom'     => $detail['effectiveFrom'],
                'reason'            => $detail['reason'],
                'verifiedBy'        => $detail['verifiedBy'],
                'verifiedAt'        => $detail['verifiedAt'],
                'schedule'          => is_array($c['wageClassificationSchedule'] ?? null)
                    ? $c['wageClassificationSchedule'] : [],
                'overridden'        => $detail['explicit'],
            ];
        }

        $this->json_success([
            'as_of'          => $asOf,
            'components'     => $rows,
            'classifications' => Statutory_wage_base::CLASSIFICATIONS,
            // Surfaced so the screen can explain WHY a default is what it is,
            // rather than presenting it as an arbitrary product choice.
            'guidance'       => [
                'INCLUDE'     => 'Wages. Counts toward the statutory base and toward total remuneration.',
                'CONDITIONAL' => 'Not wages by default, but IS remuneration — it counts toward the '
                    . '50% test and is drawn on by the add-back.',
                'EXCLUDE'     => 'Not remuneration at all (a reimbursement of actual expense). '
                    . 'Counts toward neither.',
            ],
        ]);
    }

    /**
     * POST — record a classification determination for one component.
     *
     * DATED, NOT OVERWRITTEN. A CA's determination under the universality test
     * is a statement about a point in time: an allowance can genuinely become
     * universal, or stop being so. Overwriting the value would silently reprice
     * every past period the moment somebody corrected a classification, so a
     * change appends a dated row and the payroll engine resolves the row in
     * force for the period it is pricing.
     */
    public function save_component_classification(): void
    {
        $this->_require_manage();
        require_once APPPATH . 'libraries/Statutory_wage_base.php';

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw)) {
            $raw = json_decode((string) ($this->input->post('classification') ?? ''), true);
        }
        if (!is_array($raw)) {
            $this->json_error('Send the determination as a JSON body.');
            return;
        }

        $code = strtoupper(trim((string) ($raw['code'] ?? '')));
        if ($code === '') { $this->json_error('code is required.'); return; }

        $existing = $this->components->get($code);
        if ($existing === null) { $this->json_error("No component '{$code}'."); return; }

        $class = strtoupper(trim((string) ($raw['classification'] ?? '')));
        if (!in_array($class, Statutory_wage_base::CLASSIFICATIONS, true)) {
            $this->json_error('classification must be one of: '
                . implode(', ', Statutory_wage_base::CLASSIFICATIONS) . '.');
            return;
        }

        $from = trim((string) ($raw['effectiveFrom'] ?? ''));
        $this->load->helper('effective_dating');
        if (!ed_is_iso_date($from)) {
            $this->json_error('effectiveFrom must be an ISO date (YYYY-MM-DD).');
            return;
        }

        $reason = trim((string) ($raw['reason'] ?? ''));

        // Departing from the statutory default must be explained. An
        // unexplained reclassification is indistinguishable from a mistake when
        // somebody reviews it a year later — and this one moves money.
        $default = Statutory_wage_base::DEFAULT_CLASSIFICATION[$code] ?? null;
        if ($default !== null && $class !== $default && $reason === '') {
            $this->json_error("Classifying {$code} as {$class} departs from the statutory "
                . "default ({$default}) and requires a reason.");
            return;
        }

        $schedule = is_array($existing['wageClassificationSchedule'] ?? null)
            ? $existing['wageClassificationSchedule'] : [];

        // Close the currently open row the day before the new one starts, so
        // the two never overlap. An overlap would make a closed period resolve
        // differently on re-read.
        $dayBefore = date('Y-m-d', strtotime($from . ' -1 day'));
        foreach ($schedule as &$row) {
            if (($row['effectiveTo'] ?? null) === null && (string) ($row['effectiveFrom'] ?? '') < $from) {
                $row['effectiveTo'] = $dayBefore;
            }
        }
        unset($row);

        $schedule[] = [
            'classification' => $class,
            'effectiveFrom'  => $from,
            'effectiveTo'    => null,
            'reason'         => $reason,
            'verifiedBy'     => trim((string) ($raw['verifiedBy'] ?? '')),
            'verifiedAt'     => trim((string) ($raw['verifiedAt'] ?? '')),
            'setBy'          => (string) ($this->admin_name ?? ''),
            'setAt'          => date('c'),
        ];

        $overlaps = ed_find_overlaps($schedule, 'classification');
        if ($overlaps !== []) {
            $this->json_error('That determination would overlap an existing one: '
                . implode(' ', $overlaps));
            return;
        }

        try {
            $ok = $this->fs->update('salaryComponents', $this->fs->docId($code), [
                'wageClassification'         => $class,
                'wageClassificationReason'   => $reason,
                'wageClassificationSchedule' => $schedule,
                'updatedAt'                  => date('c'),
                'updatedBy'                  => (string) ($this->admin_name ?? ''),
            ]);
            if ($ok === false) { $this->json_error('Could not save the determination.'); return; }
        } catch (\Exception $e) {
            log_message('error', 'Compensation::save_component_classification — ' . $e->getMessage());
            $this->json_error('Could not save the determination.');
            return;
        }

        log_audit('Compensation', 'component_classification_set', $code,
            "{$code} classified {$class} effective {$from}"
            . ($reason !== '' ? " — {$reason}" : '')
            . ' by ' . (string) ($this->admin_name ?? ''));

        $this->json_success([
            'message'  => "{$code} classified {$class} with effect from {$from}.",
            'schedule' => $schedule,
        ]);
    }

    // ── Full & Final policy (Features 3, 4, 5) ───────────────────────────

    /**
     * Where the tenant's F&F policy lives.
     *
     * On the EXISTING `schools` document, beside `payrollLocks`,
     * `salaryDefaults` and `leaveTypes` — the established home for tenant-wide
     * HR policy. Not a new collection: a new collection would need a Firestore
     * rules block, and `firestore.rules` is concurrently owned by another
     * programme and must not be edited.
     */
    private const FNF_FIELD = 'fnfSettings';
    /** Past versions, newest last. Bounded — see Fnf_policy_service::HISTORY_LIMIT. */
    private const FNF_HISTORY_FIELD = 'fnfSettingsHistory';

    /** GET — the F&F policy, with the vocabulary the screen needs to render. */
    public function get_fnf_policy(): void
    {
        $this->_require_view();
        require_once APPPATH . 'libraries/Fnf_policy_service.php';

        try {
            $school = $this->fs->get('schools', $this->fs->docId('profile'));
        } catch (\Exception $e) {
            log_message('error', 'Compensation::get_fnf_policy — ' . $e->getMessage());
            $this->json_error('Could not read the school profile.');
            return;
        }

        $policy = is_array($school[self::FNF_FIELD] ?? null)
            ? array_replace_recursive(Fnf_policy_service::defaults(), $school[self::FNF_FIELD])
            : Fnf_policy_service::defaults();

        // FNF-1 — past versions, so an operator can see WHAT the terms were and
        // from when, not merely what they are now. Newest last, bounded.
        $fnfHistory = is_array($school[self::FNF_HISTORY_FIELD] ?? null)
            ? $school[self::FNF_HISTORY_FIELD]
            : [];

        $this->json_success([
            'policy'       => $policy,
            'history'      => $fnfHistory,
            'completeness' => Fnf_policy_service::completeness($policy),
            'meta'         => [
                'historyLimit'  => Fnf_policy_service::HISTORY_LIMIT,
                'bases'         => Fnf_policy_service::BASES,
                'basisLabels'   => Fnf_policy_service::BASIS_LABELS,
                'approvalModes' => Fnf_policy_service::APPROVAL_MODES,
            ],
            // Said plainly so the screen can explain the refusal rather than
            // showing an empty settlement with no reason.
            'notes' => [
                'basis'    => 'No statute sets the notice-recovery basis — it is a term of your '
                    . 'appointment letters. Until it is set, a settlement refuses to compute a '
                    . 'recovery rather than showing a plausible wrong number.',
                'approval' => 'Separation of duties is always enforced and is not configurable: '
                    . 'the person who raises a settlement can never approve it.',
            ],
        ]);
    }

    /**
     * POST — record the F&F policy.
     *
     * The service validates and normalises first, so an incoherent policy (a
     * threshold mode with no threshold) is refused rather than stored and later
     * discovered to have silently behaved as single approval.
     */
    public function save_fnf_policy(): void
    {
        $this->_require_manage();
        require_once APPPATH . 'libraries/Fnf_policy_service.php';

        $raw = json_decode((string) $this->input->raw_input_stream, true);
        if (!is_array($raw)) {
            $raw = json_decode((string) ($this->input->post('policy') ?? ''), true);
        }
        if (!is_array($raw)) {
            $this->json_error('Send the policy as a JSON body (or a `policy` JSON field).');
            return;
        }

        list($ok, $clean, $errors) = Fnf_policy_service::validate($raw);
        if (!$ok) { $this->json_error(implode(' ', $errors)); return; }

        $clean['updatedAt'] = date('c');
        $clean['updatedBy'] = (string) ($this->admin_name ?? '');

        // ── EFFECTIVE DATING + HISTORY (FNF-1) ──────────────────────────
        // Every save used to overwrite in place, so there was no record of what
        // the policy USED to be and no way to reproduce a settlement computed
        // under earlier terms. Settlements are not persisted yet, which is
        // exactly why this belongs in now: the machinery to pin a policy has to
        // exist before the first settlement is stored, not after.
        $existing = [];
        $history  = [];
        try {
            $schoolDoc = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($schoolDoc)) {
                $existing = is_array($schoolDoc[self::FNF_FIELD] ?? null)
                    ? $schoolDoc[self::FNF_FIELD] : [];
                $history  = is_array($schoolDoc[self::FNF_HISTORY_FIELD] ?? null)
                    ? $schoolDoc[self::FNF_HISTORY_FIELD] : [];
            }
        } catch (\Exception $e) {
            // A history we cannot READ must not become a history we overwrite.
            log_message('error', 'save_fnf_policy history read failed: ' . $e->getMessage());
            $this->json_error('Could not read the existing policy. Nothing was changed.');
            return;
        }

        [$dateOk, $effectiveFrom, $dateErr] = Fnf_policy_service::validateEffectiveFrom(
            $this->input->post('effective_from'), $existing, date('Y-m-d')
        );
        if (!$dateOk) { $this->json_error($dateErr); return; }

        $clean['effectiveFrom'] = $effectiveFrom;
        [$clean, $history] = Fnf_policy_service::withHistory($clean, $existing, $history);

        try {
            // Dotted single-field write — the school profile is edited by many
            // surfaces and a whole-document write here would clobber them.
            $written = $this->fs->update('schools', $this->fs->docId('profile'), [
                self::FNF_FIELD => $clean,
                self::FNF_HISTORY_FIELD => $history,
            ]);
            if ($written === false) { $this->json_error('Could not save the F&F policy.'); return; }
        } catch (\Exception $e) {
            log_message('error', 'Compensation::save_fnf_policy — ' . $e->getMessage());
            $this->json_error('Could not save the F&F policy.');
            return;
        }

        log_audit('Compensation', 'fnf_policy_saved', $this->school_id,
            'notice basis=' . $clean['noticeRecovery']['basis']
            . ' approval=' . $clean['approval']['mode']
            . ' threshold=' . ($clean['approval']['dualThresholdPaise'] ?? '-')
            . ' clearance=' . implode('|', $clean['clearance']['required'])
            . ' by ' . (string) ($this->admin_name ?? ''));

        $this->json_success([
            'message'      => 'Full & Final policy saved.',
            'policy'       => $clean,
            'completeness' => Fnf_policy_service::completeness($clean),
        ]);
    }

    // ── F&F preview (Feature 6) ──────────────────────────────────────────

    /**
     * GET — project a settlement for one employee WITHOUT creating one.
     *
     * STRICTLY READ-ONLY. It reads, composes and returns. It writes nothing,
     * posts nothing, and notifies nobody — the preview service it delegates to
     * has no persistence capability at all, which is asserted by test rather
     * than left to discipline.
     *
     * Optionally takes a PROPOSED policy (`?proposed_basis=gross`) and returns
     * current-versus-proposed with per-line deltas, so an owner can see what a
     * contractual choice would do before committing to it.
     */
    public function preview_fnf(): void
    {
        $this->_require_view();

        $staffId = trim((string) ($this->input->get('staff_id') ?? ''));
        if ($staffId === '') { $this->json_error('staff_id is required.'); return; }

        require_once APPPATH . 'libraries/Fnf_policy_service.php';
        require_once APPPATH . 'libraries/Fnf_preview_service.php';
        require_once APPPATH . 'libraries/Fnf_notice_resolver.php';
        require_once APPPATH . 'libraries/Fnf_encashment_resolver.php';

        // ── Tenant policy ──
        try {
            $school = $this->fs->get('schools', $this->fs->docId('profile'));
        } catch (\Exception $e) {
            log_message('error', 'Compensation::preview_fnf school read — ' . $e->getMessage());
            $this->json_error('Could not read the school profile.');
            return;
        }
        $policy = is_array($school[self::FNF_FIELD] ?? null)
            ? array_replace_recursive(Fnf_policy_service::defaults(), $school[self::FNF_FIELD])
            : Fnf_policy_service::defaults();

        // ── Notice position (resolved BEFORE salary, because its last working
        //    day is the as-of date the salary must be read at) ──
        $notice = ['requiredDays' => 0, 'servedDays' => 0, 'shortfallDays' => 0,
                   'recoverable' => false, 'recoverability' => 'no_notice_record'];
        try {
            $rows = $this->fs->schoolWhere('staffNoticePeriods', [['staffId', '==', $staffId]]);
            $latest = null;
            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if (!is_array($d)) continue;
                if ($latest === null
                    || (string) ($d['createdAt'] ?? '') > (string) ($latest['createdAt'] ?? '')) {
                    $latest = $d;
                }
            }
            if (is_array($latest)) $notice = Fnf_notice_resolver::resolve($latest);
        } catch (\Throwable $e) {
            log_message('error', 'Compensation::preview_fnf notice read — ' . $e->getMessage());
        }

        // A settlement is priced at the LAST WORKING DAY, not at today. Reading
        // today's salary for somebody who left in March would price their
        // settlement against a revision they never worked under.
        $asOf = (string) ($notice['lastWorkingDay'] ?? '') ?: date('Y-m-d');

        // ── Salary, as of the settlement date ──
        $monthly = ['basicPaise' => 0, 'grossPaise' => 0, 'ctcPaise' => 0];
        try {
            $structure = $this->_salary_structure_as_of($staffId, $asOf);
            if (is_array($structure)) {
                $toP = static fn($v) => (int) round(((float) $v) * 100);
                $basic = $toP($structure['basic'] ?? 0);
                $gross = $basic
                    + $toP($structure['hra'] ?? 0) + $toP($structure['da'] ?? 0)
                    + $toP($structure['ta'] ?? 0) + $toP($structure['medical'] ?? 0)
                    + $toP($structure['other_allowances'] ?? 0);

                // CTC adds the employer's own contributions. Where they are not
                // configured this equals gross — which is honest: an unconfigured
                // employer contribution is zero, not an invented percentage.
                $ctc = $gross
                    + (int) round($basic * ((float) ($structure['pf_employer'] ?? 0)) / 100)
                    + (int) round($gross * ((float) ($structure['esi_employer'] ?? 0)) / 100);

                $monthly = ['basicPaise' => $basic, 'grossPaise' => $gross, 'ctcPaise' => $ctc];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Compensation::preview_fnf salary read — ' . $e->getMessage());
        }

        // ── Leave balances → encashable days ──
        $encashment = ['types' => []];
        try {
            $bal = $this->fs->get('leaveApplications',
                $this->fs->docId('BAL_' . $staffId . '_' . date('Y')));
            $balances = is_array($bal['balances'] ?? null) ? $bal['balances'] : [];
            $leaveTypes = is_array($school['leaveTypes'] ?? null) ? $school['leaveTypes'] : [];
            if ($balances !== []) {
                $encashment = Fnf_encashment_resolver::resolve($balances, $leaveTypes);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Compensation::preview_fnf leave read — ' . $e->getMessage());
        }

        // ── Clearance ──
        $clearance = [];
        try {
            $doc = $this->fs->get('staffClearances', $this->school_id . '_' . $staffId);
            if (is_array($doc)) $clearance = $doc;
        } catch (\Throwable $e) {
            log_message('error', 'Compensation::preview_fnf clearance read — ' . $e->getMessage());
        }

        $inputs = [
            'policy'     => $policy,
            'notice'     => $notice,
            'encashment' => $encashment,
            'tenure'     => [],
            'clearance'  => $clearance,
            'monthly'    => $monthly,
            // The tenant's existing divisor. Defaulting to 30 is stated rather
            // than hidden: it is what the shipped engine uses when nothing is
            // configured, so the preview must not silently use a different one.
            'daysBasis'  => (float) ($school['salaryDefaults']['days_basis'] ?? 30),
            'unpaidSalaryPaise' => 0,
            'asOfDate'          => $asOf,
        ];

        $proposedBasis = strtolower(trim((string) ($this->input->get('proposed_basis') ?? '')));
        if ($proposedBasis !== '' && in_array($proposedBasis, Fnf_policy_service::BASES, true)) {
            $proposed = $policy;
            $proposed['noticeRecovery']['basis'] = $proposedBasis;
            $this->json_success(Fnf_preview_service::compare($inputs, $proposed));
            return;
        }

        $this->json_success(Fnf_preview_service::project($inputs));
    }

    /**
     * The employee's salary structure AS OF a date, through the canonical seam.
     *
     * FIXED 2026-08-23 during final verification. This previously did a raw
     * `SAL_{staffId}` read while being named after the reader it did not use.
     * Two things were wrong with that, and the name hid both:
     *
     *   1. It bypassed `Compensation_reader`, so after the component cutover the
     *      preview would price a settlement from the legacy document that is no
     *      longer the authority — quietly, and only for settlements.
     *   2. It had no as-of date, so a scheduled salary revision was invisible
     *      to it. A settlement dated after a revision would show the old salary.
     *
     * The reader is memoised for the request, matching `Hr::_compensation_reader()`.
     * A read failure returns null and the preview reports zero figures rather
     * than guessing — a settlement preview must never invent a salary.
     */
    private function _salary_structure_as_of(string $staffId, string $asOfDate): ?array
    {
        static $reader = null;

        try {
            if ($reader === null) {
                require_once APPPATH . 'libraries/Compensation_reader.php';
                $this->config->load('hrms_compensation_flags', true);

                $reader = new Compensation_reader();
                $reader->init($this->fs, Compensation_reader::modeFromFlags(
                    (bool) $this->config->item('USE_COMPONENT_COMPENSATION', 'hrms_compensation_flags'),
                    (bool) $this->config->item('COMPENSATION_SHADOW_COMPARE', 'hrms_compensation_flags')
                ));
            }

            $doc = $reader->resolve($staffId, $asOfDate);
            return is_array($doc) && $doc !== [] ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', 'Compensation::_salary_structure_as_of — ' . $e->getMessage());
            return null;
        }
    }

    /* ================================================================
       F&F SETTLEMENT LIFECYCLE  (FNF-2)
       ----------------------------------------------------------------
       Identity and lifecycle only — see STAFF_FNF_SETTLEMENT_DESIGN §3.
       Every amount is a stored OUTPUT of an off-cycle payroll run; this
       document records which run produced it and freezes the inputs that
       run consumed, so an approved settlement stays explainable after the
       underlying data moves on.
       ================================================================ */

    private const SETTLEMENT_COL = 'staffSettlements';

    /** POST — settlements for one staff member, newest first. */
    public function fnf_settlements(): void
    {
        $this->_require_view();

        $staffId = trim((string) $this->input->post('staff_id'));

        $conds = [];
        if ($staffId !== '') { $conds[] = ['staffId', '==', $staffId]; }

        $rows = [];
        try {
            $rows = $this->fs->schoolWhere(self::SETTLEMENT_COL, $conds);
        } catch (\Throwable $e) {
            log_message('error', 'fnf_settlements query failed: ' . $e->getMessage());
            $this->json_error('Could not load settlements.', 500);
            return;
        }

        $out = [];
        foreach ($rows as $entry) {
            $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
            if (!is_array($d)) continue;
            $d['settlementId'] = $entry['id'] ?? ($d['settlementId'] ?? '');
            $out[] = $d;
        }
        usort($out, static fn($a, $b) => strcmp((string) ($b['initiatedAt'] ?? ''), (string) ($a['initiatedAt'] ?? '')));

        $this->json_success(['settlements' => $out, 'count' => count($out)]);
    }

    /**
     * POST — open a settlement for a separated employee.
     *
     * The open-slot and notice-settleable guards are the state machine's, not
     * this method's: one place decides what is legal.
     */
    public function fnf_initiate(): void
    {
        require_permission('HR', 'fnf_initiate', 'manage');

        $staffId = trim((string) $this->input->post('staff_id'));
        if ($staffId === '') { $this->json_error('Staff ID is required.'); return; }

        $staff = $this->fs->getEntity('staff', $staffId);
        if (!is_array($staff) || empty($staff)) { $this->json_error('Staff record not found.', 404); return; }

        $ctx = $this->_fnf_context($staffId);

        $this->load->library('fnf_state_machine', null, 'fnfsm');
        $can = $this->fnfsm->can('', Fnf_state_machine::E_INITIATE, $ctx);
        if (empty($can['ok'])) { $this->json_error($this->_fnf_refusal($can['reason'], $ctx)); return; }

        $now = date('c');
        $sid = $this->school_id . '_SET_' . $staffId . '_' . date('YmdHis');

        $doc = [
            'schoolId'        => $this->school_id,
            'session'         => $this->session_year,
            'staffId'         => $staffId,
            'staffName'       => (string) ($staff['Name'] ?? $staff['name'] ?? $staffId),
            'settlementId'    => $sid,
            'status'          => Fnf_state_machine::S_INITIATED,
            'statusHistory'   => [[
                'to' => Fnf_state_machine::S_INITIATED, 'at' => $now,
                'by' => (string) ($this->admin_name ?? ''), 'event' => Fnf_state_machine::E_INITIATE,
            ]],
            // Resolved from the notice document, never re-derived here — two
            // derivations of "last working day" would eventually disagree, and
            // the disagreement would be a number on somebody's settlement.
            'lastWorkingDay'  => (string) ($ctx['lastWorkingDay'] ?? ''),
            'noticeStatus'    => (string) ($ctx['noticeStatus'] ?? ''),
            'initiatedBy'     => (string) ($this->admin_name ?? ''),
            // The ID, not just the name: separation of duties compares
            // identities, and two people can share a display name.
            'initiatedById'   => (string) ($this->admin_id ?? ''),
            'initiatedAt'     => $now,
            'computation'     => null,
            'paymentRef'      => '',
            'accountingEntryId' => '',
        ];

        try {
            $ok = $this->fs->set(self::SETTLEMENT_COL, $sid, $doc, false);
            if ($ok === false) { $this->json_error('Could not open the settlement.'); return; }
        } catch (\Throwable $e) {
            log_message('error', 'fnf_initiate write failed: ' . $e->getMessage());
            $this->json_error('Could not open the settlement.', 500);
            return;
        }

        $this->_fnf_audit($sid, $staffId, 'FNF_INITIATE', Fnf_state_machine::S_INITIATED, '');

        $this->json_success(['message' => 'Settlement opened.', 'settlementId' => $sid, 'settlement' => $doc]);
    }

    /**
     * POST — attach the off-cycle payroll run that computed this settlement.
     *
     * This endpoint does NOT calculate. It records which run produced the
     * figures, freezes the inputs that run consumed, and pins the policy that
     * applied — see the design's boundary of responsibility.
     */
    public function fnf_compute(): void
    {
        require_permission('HR', 'fnf_compute', 'manage');

        $sid   = trim((string) $this->input->post('settlement_id'));
        $runId = trim((string) $this->input->post('run_id'));
        if ($sid === '' || $runId === '') { $this->json_error('Settlement and payroll run are required.'); return; }

        $doc = $this->_fnf_load($sid);
        if ($doc === null) { $this->json_error('Settlement not found.', 404); return; }

        $ctx = $this->_fnf_context((string) $doc['staffId']);
        $ctx['clearanceOverride'] = (bool) $this->input->post('clearance_override');

        $this->load->library('fnf_state_machine', null, 'fnfsm');
        $can = $this->fnfsm->can((string) $doc['status'], Fnf_state_machine::E_COMPUTE, $ctx);
        if (empty($can['ok'])) { $this->json_error($this->_fnf_refusal($can['reason'], $ctx)); return; }

        // The run must exist, belong to this school, and be an OFF-CYCLE run —
        // attaching a monthly run would tie a leaver's settlement to everyone
        // else's payroll.
        $run = null;
        // RUN-D1: a run document is `{schoolId}_RUN_{runId}` — Hr.php writes it
        // that way (`fs->set('salarySlips', fs->docId("RUN_{$runId}"), …)`) and
        // reads it back the same way. Passing the bare run id here looked for a
        // document that has never existed under that name, so a settlement could
        // never be computed from a real payroll run: "Payroll run not found",
        // with the run sitting in Firestore all along.
        try {
            $run = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
        } catch (\Throwable $e) {
            $run = null;
        }
        if (!is_array($run) || (string) ($run['schoolId'] ?? '') !== (string) $this->school_id) {
            $this->json_error('Payroll run not found.', 404);
            return;
        }
        if ((string) ($run['runType'] ?? 'monthly') !== 'offcycle') {
            $this->json_error('A settlement must be computed from an OFF-CYCLE payroll run, not the monthly run.');
            return;
        }

        // ── POLICY PINNING ──────────────────────────────────────────────
        // Resolved as of the LAST WORKING DAY, not today: a settlement for
        // someone who left in March must be computed on March's terms even if
        // the policy changed in April. This is what makes an approved
        // settlement reproducible.
        $asOfDate = (string) ($doc['lastWorkingDay'] ?? '');
        [$policy, $policyMeta] = $this->_fnf_policy_as_of($asOfDate);

        $now = date('c');
        $computation = [
            'runId'          => $runId,
            'computedAt'     => $now,
            'computedBy'     => (string) ($this->admin_name ?? ''),
            // Frozen: an April edit to a March attendance mark must not silently
            // change what was approved.
            'inputsSnapshot' => [
                'lastWorkingDay' => $asOfDate,
                'noticeStatus'   => (string) ($doc['noticeStatus'] ?? ''),
                'runMonth'       => (string) ($run['month'] ?? ''),
                'runYear'        => (string) ($run['year'] ?? ''),
                'frozenAt'       => $now,
            ],
            'policyVersion'   => $policyMeta['version'],
            'policyEffective' => $policyMeta['effectiveFrom'],
            'policyResolved'  => $policyMeta['resolved'],
            'noticeBasis'     => (string) ($policy['noticeRecovery']['basis'] ?? 'unset'),
            'clearanceOverridden' => !empty($ctx['clearanceOverride']),
        ];

        $patch = [
            'status'      => Fnf_state_machine::S_CALCULATED,
            'computation' => $computation,
            'computedAt'  => $now,
        ];

        if (!$this->_fnf_apply($sid, $doc, $patch, Fnf_state_machine::E_COMPUTE, '')) { return; }

        $this->json_success([
            'message'     => 'Settlement computed from run ' . $runId . '.',
            'computation' => $computation,
        ]);
    }

    /**
     * POST — every other transition: submit, approve, reject, cancel,
     * record payment, close.
     *
     * One endpoint rather than six: six would be six places to get RBAC, the
     * compare-and-swap or the audit subtly different.
     */
    public function fnf_transition(): void
    {
        require_permission('HR', 'fnf_transition', 'manage');

        $sid   = trim((string) $this->input->post('settlement_id'));
        $event = trim((string) $this->input->post('event'));
        $note  = mb_substr(trim((string) $this->input->post('note')), 0, 500);

        if ($sid === '' || $event === '') { $this->json_error('Settlement and event are required.'); return; }

        $doc = $this->_fnf_load($sid);
        if ($doc === null) { $this->json_error('Settlement not found.', 404); return; }

        $ctx = [
            'note'          => $note,
            'hasComputation'=> is_array($doc['computation'] ?? null) && !empty($doc['computation']),
            // Separation of duties: the machine compares these, so a reviewer
            // cannot approve a settlement they themselves initiated.
            'actorId'     => (string) ($this->admin_id ?? ''),
            // The EMPLOYEE being settled — an admin who is also the leaver
            // must not approve their own settlement.
            'subjectId'   => (string) ($doc['staffId'] ?? ''),
            // Whoever opened it — the second half of separation of duties.
            'initiatorId' => (string) ($doc['initiatedById'] ?? ''),
            // The remaining guards read these THREE keys by name. Passing the
            // note under a different key left recordPayment, close and cancel
            // refusing unconditionally — the guard saw an empty string.
            'reason'      => $note,
            'paymentRef'  => mb_substr(trim((string) $this->input->post('payment_ref')), 0, 120),
            'accountingEntryId' => mb_substr(trim((string) $this->input->post('accounting_entry_id')), 0, 120),
        ];

        $this->load->library('fnf_state_machine', null, 'fnfsm');
        $can = $this->fnfsm->can((string) $doc['status'], $event, $ctx);
        if (empty($can['ok'])) { $this->json_error($this->_fnf_refusal($can['reason'], $ctx)); return; }

        $now   = date('c');
        $patch = ['status' => $can['to']];

        switch ($event) {
            case Fnf_state_machine::E_APPROVE:
                $patch['approvedBy'] = (string) ($this->admin_name ?? '');
                $patch['approvedAt'] = $now;
                break;
            case Fnf_state_machine::E_REJECT:
                $patch['reviewedBy'] = (string) ($this->admin_name ?? '');
                $patch['reviewedAt'] = $now;
                $patch['rejectNote'] = $note;
                break;
            case Fnf_state_machine::E_RECORD_PAYMENT:
                $patch['paidAt']     = $now;
                $patch['paymentRef'] = $ctx['paymentRef'];
                break;
            case Fnf_state_machine::E_CLOSE:
                $patch['closedAt']          = $now;
                $patch['accountingEntryId'] = $ctx['accountingEntryId'];
                break;
            case Fnf_state_machine::E_CANCEL:
                $patch['cancelledBy']     = (string) ($this->admin_name ?? '');
                $patch['cancelledAt']     = $now;
                $patch['cancelledReason'] = $note;
                break;
        }

        if (!$this->_fnf_apply($sid, $doc, $patch, $event, $note)) { return; }

        $this->json_success(['message' => 'Settlement moved to ' . $can['to'] . '.', 'status' => $can['to']]);
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    /** Load a settlement, tenant-checked. */
    private function _fnf_load(string $sid): ?array
    {
        try { $doc = $this->fs->get(self::SETTLEMENT_COL, $sid); } catch (\Throwable $e) { return null; }

        if (!is_array($doc) || empty($doc)) return null;
        // A settlement from another tenant reads as absent — confirming it
        // exists would itself be a leak.
        if ((string) ($doc['schoolId'] ?? '') !== (string) $this->school_id) return null;

        return $doc;
    }

    /**
     * Write a transition under compare-and-swap.
     *
     * Two reviewers approving the same settlement at once is the ordinary case
     * in a school with a Principal and an Admin. Without this both would write,
     * and the statusHistory would record one of them.
     */
    private function _fnf_apply(string $sid, array $doc, array $patch, string $event, string $note): bool
    {
        $now  = date('c');
        $hist = is_array($doc['statusHistory'] ?? null) ? $doc['statusHistory'] : [];
        $hist[] = [
            'to' => $patch['status'], 'at' => $now,
            'by' => (string) ($this->admin_name ?? ''), 'event' => $event,
            'note' => $note,
        ];
        $patch['statusHistory'] = $hist;

        $version = (string) ($doc['__updateTime'] ?? '');

        if ($version !== '' && method_exists($this->firebase, 'firestoreCommitBatchStatus')) {
            $res = $this->firebase->firestoreCommitBatchStatus([[
                'op' => 'update', 'collection' => self::SETTLEMENT_COL, 'docId' => $sid,
                'merge' => true, 'data' => $patch,
                'precondition' => ['updateTime' => $version],
            ]]);
            $status = (string) ($res['status'] ?? 'FAILURE');

            if ($status === 'PRECONDITION_CONFLICT') {
                $this->json_error('Someone else changed this settlement while you were working on it. '
                    . 'Refresh to see the current state.', 409);
                return false;
            }
            if ($status !== 'SUCCESS') {
                log_message('error', 'fnf transition CAS failed for ' . $sid . ': ' . $status);
                $this->json_error('Could not save. Please retry.', 500);
                return false;
            }
        } else {
            try {
                if ($this->fs->set(self::SETTLEMENT_COL, $sid, $patch, true) === false) {
                    $this->json_error('Could not save. Please retry.', 500);
                    return false;
                }
            } catch (\Throwable $e) {
                log_message('error', 'fnf transition write failed: ' . $e->getMessage());
                $this->json_error('Could not save. Please retry.', 500);
                return false;
            }
        }

        $this->_fnf_audit($sid, (string) ($doc['staffId'] ?? ''), 'FNF_' . strtoupper($event),
            (string) $patch['status'], $note);

        return true;
    }

    /** The policy in force on a date, with the metadata a settlement pins. */
    private function _fnf_policy_as_of(string $date): array
    {
        $current = Fnf_policy_service::defaults();
        $history = [];

        try {
            $school = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($school)) {
                if (is_array($school[self::FNF_FIELD] ?? null)) {
                    $current = array_replace_recursive($current, $school[self::FNF_FIELD]);
                }
                if (is_array($school[self::FNF_HISTORY_FIELD] ?? null)) {
                    $history = $school[self::FNF_HISTORY_FIELD];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'fnf policy read failed: ' . $e->getMessage());
        }

        $resolved = Fnf_policy_service::asOf($date, $current, $history);

        return [$resolved, [
            'version'       => (int) ($resolved['version'] ?? 0),
            'effectiveFrom' => (string) ($resolved['effectiveFrom'] ?? ''),
            // Recorded rather than hidden: a settlement computed when no policy
            // was yet in force must say so on its face.
            'resolved'      => empty($resolved['_asOfUnavailable']),
        ]];
    }

    /** Facts the state machine's guards need. */
    private function _fnf_context(string $staffId): array
    {
        // CLR-D2: this helper calls Fnf_state_machine:: and Fnf_policy_service::
        // STATICALLY, and CI's loader does not resolve static calls — callers
        // that had not already loaded them got "Class ... not found", which the
        // try/catch blocks below then SWALLOWED. The failure was invisible and
        // it failed CLOSED: hasOpenSettlement stayed true and clearanceComplete
        // stayed false, so F&F refused "clearance is not complete" for an
        // employee whose nine departments were all cleared.
        //
        // fnf_compute() is the clearest case — it builds this context BEFORE
        // its own load->library('fnf_state_machine') line, so the class could
        // not possibly exist yet. Loading here fixes every caller at once, and
        // require_once is idempotent for the ones that already did it.
        require_once APPPATH . 'libraries/Fnf_state_machine.php';
        require_once APPPATH . 'libraries/Fnf_policy_service.php';

        $ctx = [
            'hasOpenSettlement' => false,
            'noticeStatus'      => '',
            'lastWorkingDay'    => '',
            'clearanceComplete' => false,
        ];

        // One open settlement per person — the machine refuses a second.
        try {
            $rows = $this->fs->schoolWhere(self::SETTLEMENT_COL, [['staffId', '==', $staffId]]);
            foreach ($rows as $entry) {
                $d = is_array($entry) ? ($entry['data'] ?? $entry) : null;
                if (!is_array($d)) continue;
                if (in_array((string) ($d['status'] ?? ''), Fnf_state_machine::OPEN, true)) {
                    $ctx['hasOpenSettlement'] = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Fail CLOSED: if we cannot prove there is no open settlement, we
            // must not open a second one.
            log_message('error', 'fnf open-slot check failed: ' . $e->getMessage());
            $ctx['hasOpenSettlement'] = true;
        }

        try {
            $notice = $this->fs->get('staffNoticePeriods', $this->fs->docId($staffId));
            if (is_array($notice)) {
                // UAT-D1: read `status`, NOT `decision`. The document carries both —
                // status is the settled vocabulary (approved/waived/reduced) that
                // Fnf_state_machine::NOTICE_SETTLEABLE speaks; decision is the verb the
                // operator pressed (approve/waive/reduce). Reading the verb first meant
                // an APPROVED notice arrived as "approve", which is not settleable, so
                // F&F could never be initiated for anybody.
                $noticeStatus = trim((string) ($notice['status'] ?? ''));
                if ($noticeStatus === '') {
                    // Documents written before `status` existed carry only the verb.
                    $verbToStatus = ['approve' => 'approved', 'waive' => 'waived',
                                     'reduce'  => 'reduced',  'reject' => 'rejected'];
                    $verb = trim((string) ($notice['decision'] ?? ''));
                    $noticeStatus = $verbToStatus[$verb] ?? $verb;
                }
                $ctx['noticeStatus']   = $noticeStatus;
                $ctx['lastWorkingDay'] = (string) ($notice['actualLastWorkingDay']
                    ?? $notice['expectedLastWorkingDay'] ?? '');
            }
        } catch (\Throwable $e) {
            log_message('error', 'fnf notice read failed: ' . $e->getMessage());
        }

        // Fall back to the separation record's last working day when no notice
        // document exists — a school that separates without a notice period
        // still has a leaver to settle.
        if ($ctx['lastWorkingDay'] === '') {
            try {
                $staff = $this->fs->getEntity('staff', $staffId);
                if (is_array($staff)) {
                    $ctx['lastWorkingDay'] = (string) ($staff['lastWorkingDate'] ?? $staff['exitDate'] ?? '');
                }
            } catch (\Throwable $e) { /* leave blank; compute will pin an unresolved policy */ }
        }

        try {
            $clearance = $this->fs->get('staffClearances', $this->fs->docId($staffId));
            $verdict = Fnf_policy_service::resolveClearance(
                $this->_fnf_policy_current(), is_array($clearance) ? $clearance : []
            );
            // CLR-D1: read the key the resolver actually returns.
            //
            // `resolveClearance()` returns canApprove / canCompute / blocking /
            // reason — there is no `complete`. Reading one meant this was
            // ALWAYS false, so `clearance_incomplete` refused every compute for
            // every employee no matter how thoroughly clearance was finished.
            // Verified live: all nine departments cleared, the clearance record
            // reading status=completed, and compute still refused.
            //
            // `canCompute` is the right key rather than a convenient one: it
            // already encodes the school's own `blockApprovalOnly` choice —
            // true (the default) means clearance gates APPROVAL and a leaver
            // may still be told what they are owed; false means the school has
            // asked for computation to be gated too. `canApprove` stays
            // separate and keeps guarding approval.
            $ctx['clearanceComplete'] = !empty($verdict['canCompute']);
        } catch (\Throwable $e) {
            log_message('error', 'fnf clearance read failed: ' . $e->getMessage());
        }

        return $ctx;
    }

    /** Today's policy — for guards that are about now, not about the leaving date. */
    private function _fnf_policy_current(): array
    {
        try {
            $school = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($school) && is_array($school[self::FNF_FIELD] ?? null)) {
                return array_replace_recursive(Fnf_policy_service::defaults(), $school[self::FNF_FIELD]);
            }
        } catch (\Throwable $e) { /* fall through */ }

        return Fnf_policy_service::defaults();
    }

    /** A refusal code turned into something an administrator can act on. */
    private function _fnf_refusal(string $code, array $ctx): string
    {
        switch ($code) {
            case 'settlement_already_open':
                return 'This employee already has an open settlement. Finish or cancel it first.';
            case 'separation_not_settleable':
                return 'This employee\'s notice period is not in a settleable state'
                     . ($ctx['noticeStatus'] !== '' ? ' (currently: ' . $ctx['noticeStatus'] . ')' : '')
                     . '. Approve, waive or complete the notice period first.';
            case 'clearance_incomplete':
                return 'Clearance is not complete. Finish the outstanding items, or record an '
                     . 'explicit clearance override with a reason.';
            case 'no_computation':
                return 'Compute the settlement from an off-cycle payroll run before sending it for review.';
            case 'payment_reference_required':
                return 'Record the payment reference (UTR, cheque number or voucher id) '
                     . 'so the payment can be traced later.';
            case 'accounting_entry_required':
                return 'Enter the accounting entry id this settlement was posted under '
                     . 'before closing it.';
            case 'reason_required':
                return 'A reason is required when cancelling a settlement.';
            case 'note_required':
                return 'A note is required when rejecting a settlement.';
            case 'self_approval_forbidden':
                return 'You cannot approve your own settlement. Ask another approver.';
            case 'initiator_cannot_approve':
                return 'You opened this settlement, so you cannot also approve it. '
                     . 'Ask another approver.';
            case 'actor_required':
                return 'Your account could not be identified. Sign out and back in, then retry.';
            case 'terminal_state':
                return 'This settlement is closed and can no longer be changed.';
            case 'unknown_event':
                return 'That is not a settlement action this system recognises.';
            case 'illegal_transition':
                return 'That action is not available from the settlement\'s current state.';
        }
        return 'That action was refused (' . $code . ').';
    }

    /** Audit — every transition, with who and what state. */
    private function _fnf_audit(string $sid, string $staffId, string $action, string $to, string $note): void
    {
        log_message('info', $action . ' settlement=[' . $sid . '] staff=[' . $staffId
            . '] to=[' . $to . '] by=[' . ($this->admin_name ?? '') . '] school=[' . $this->school_id . ']'
            . ($note !== '' ? ' note=[' . $note . ']' : ''));

        if (method_exists($this, '_audit_compensation')) {
            $this->_audit_compensation($action, $sid, ['staffId' => $staffId, 'toStatus' => $to, 'note' => $note]);
        }
    }

    /**
     * GET — the Full & Final settlement statement as a PDF.
     *
     * Reuses Pdf_generator, the same generator behind payslips and report
     * cards. The figures come from the slip the linked off-cycle run produced;
     * this method formats, it does not calculate.
     */
    public function download_settlement(): void
    {
        $this->_require_view();

        $sid = trim((string) ($this->input->get('settlement_id') ?? ''));
        if ($sid === '') { $this->json_error('settlement_id is required.'); return; }

        $doc = $this->_fnf_load($sid);
        if ($doc === null) { $this->json_error('Settlement not found.', 404); return; }

        $comp = is_array($doc['computation'] ?? null) ? $doc['computation'] : [];
        if (empty($comp) || empty($comp['runId'])) {
            // No computation, no figures. Producing a statement here would be a
            // document with blanks where the money goes.
            $this->json_error('This settlement has not been computed yet, so there is nothing to '
                . 'produce a statement from. Compute it from an off-cycle payroll run first.');
            return;
        }

        $staffId = (string) ($doc['staffId'] ?? '');
        $runId   = (string) $comp['runId'];

        // The slip the run wrote — same doc-id shape payroll uses everywhere.
        $slip = [];
        try {
            $found = $this->fs->get('salarySlips', $this->fs->docId("SLIP_{$runId}_{$staffId}"));
            if (is_array($found)) { $slip = $found; }
        } catch (\Throwable $e) {
            log_message('error', 'download_settlement slip read failed: ' . $e->getMessage());
        }

        $profile = [];
        try {
            $found = $this->fs->getEntity('staff', $staffId);
            if (is_array($found)) { $profile = $found; }
        } catch (\Throwable $e) { /* the statement still renders without it */ }

        $school = [];
        try {
            $found = $this->fs->get('schools', $this->fs->docId('profile'));
            if (is_array($found)) { $school = $found; }
        } catch (\Throwable $e) { /* ditto */ }

        $html = $this->_render_settlement_html($doc, $comp, $slip, $profile, $school);

        $this->load->library('pdf_generator');
        $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($school['schoolName'] ?? 'School'))
              . '_FullAndFinal_' . preg_replace('/[^A-Za-z0-9_]/', '', $staffId) . '.pdf';

        $this->pdf_generator->download($html, $name);
    }

    /**
     * The statement's HTML.
     *
     * Deliberately plain: this is a document somebody may have to produce years
     * later, and the figures matter more than the styling.
     */
    private function _render_settlement_html(array $doc, array $comp, array $slip,
                                             array $profile, array $school): string
    {
        $e = static function ($v): string { return html_escape((string) ($v ?? '')); };
        // RT-3: amounts are DENOMINATED, matching the payslip this statement sits
        // beside. The symbol is wrapped in its own DejaVu span DELIBERATELY:
        // dompdf resolves `sans-serif` to core Helvetica, which cannot encode
        // U+20B9 and silently substitutes a literal '?' — verified byte-level in
        // the PDF content stream. Scoping the font to the symbol alone embeds a
        // ~4KB DejaVu subset while leaving every other glyph on Helvetica, so no
        // text metric elsewhere in the document shifts.
        $money = static function ($v): string {
            return '<span class="cur">₹</span> ' . number_format((float) $v, 2, '.', ',');
        };

        // RT-4: a labelled row left blank reads as missing data on a money
        // document. The same em dash the history table already uses says 'absent'.
        $orDash = static function ($v): string {
            $v = trim((string) $v);
            return $v === '' ? '—' : $v;
        };

        $status = (string) ($doc['status'] ?? '');
        $final  = in_array($status, ['approved', 'paid', 'closed'], true);

        $schoolName = (string) ($school['schoolName'] ?? $school['name'] ?? '');
        $staffName  = (string) ($doc['staffName'] ?? $profile['Name'] ?? $doc['staffId'] ?? '');

        $gross = (float) ($slip['gross'] ?? 0);
        $ded   = (float) ($slip['total_deductions'] ?? 0);
        $net   = (float) ($slip['net_pay'] ?? 0);

        // A settlement that is not yet approved must not read as a final
        // statement. The banner is the honest version of a watermark.
        $banner = $final ? '' :
            '<div class="warn"><b>PROVISIONAL — not yet approved.</b> These figures may still '
            . 'change. This is not a final settlement statement.</div>';

        $noticeBasis = (string) ($comp['noticeBasis'] ?? 'unset');
        $policyLine  = $noticeBasis === 'unset'
            ? 'Notice recovery basis was not configured, so no recovery was applied.'
            : 'Notice recovery basis: <b>' . $e($noticeBasis) . '</b>';

        $policyResolved = ($comp['policyResolved'] ?? true)
            ? ''
            : '<div class="warn">No Full &amp; Final policy was in force on the last working day, '
              . 'so the settlement was computed without one.</div>';

        $rows = '';
        foreach ([
            ['Employee',           $staffName . ' (' . (string) ($doc['staffId'] ?? '') . ')'],
            ['Department',         (string) ($profile['Department'] ?? $profile['department'] ?? '—')],
            ['Designation',        (string) ($profile['designation'] ?? $profile['Position'] ?? '—')],
            ['Date of joining',    (string) ($profile['Date Of Joining'] ?? '—')],
            ['Last working day',   (string) ($doc['lastWorkingDay'] ?? '—')],
            ['Settlement ID',      (string) ($doc['settlementId'] ?? '')],
            ['Status',             ucfirst($status)],
        ] as $r) {
            $rows .= '<tr><th>' . $e($r[0]) . '</th><td>' . $e($r[1]) . '</td></tr>';
        }

        $lifecycle = '';
        foreach (is_array($doc['statusHistory'] ?? null) ? $doc['statusHistory'] : [] as $h) {
            if (!is_array($h)) continue;
            $lifecycle .= '<tr><td>' . $e($h['to'] ?? '') . '</td><td>' . $e($h['at'] ?? '')
                        . '</td><td>' . $e($h['by'] ?? '') . '</td><td>' . $e($h['note'] ?? '') . '</td></tr>';
        }
        if ($lifecycle === '') { $lifecycle = '<tr><td colspan="4">—</td></tr>'; }

        $css = 'body{font-family:sans-serif;font-size:11px;color:#1a2e2a;margin:0;padding:22px 30px}
        .cur{font-family:"DejaVu Sans"}
        h1{font-size:17px;margin:0 0 2px}h2{font-size:12px;margin:18px 0 6px;text-transform:uppercase;
        letter-spacing:.5px;color:#555;border-bottom:1px solid #ddd;padding-bottom:3px}
        table{width:100%;border-collapse:collapse;margin-bottom:4px}
        th,td{padding:5px 7px;text-align:left;border-bottom:1px solid #eee;vertical-align:top}
        th{width:180px;color:#555;font-weight:600}
        .num{text-align:right;font-variant-numeric:tabular-nums}
        .tot td{border-top:2px solid #333;font-weight:700;border-bottom:none}
        .warn{border:1px solid #d97706;background:#fff8ec;color:#7c2d12;padding:8px 10px;
        border-radius:4px;margin:10px 0}
        .foot{margin-top:22px;font-size:9.5px;color:#777;border-top:1px solid #ddd;padding-top:8px}';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'
            . '<h1>' . $e($schoolName) . '</h1>'
            . '<div>Full &amp; Final Settlement Statement</div>'
            . $banner
            . $policyResolved

            . '<h2>Employee</h2><table>' . $rows . '</table>'

            . '<h2>Settlement</h2><table>'
            . '<tr><th>Computed from run</th><td>' . $e($comp['runId'] ?? '') . '</td></tr>'
            . '<tr><th>Computed at</th><td>' . $orDash($e($comp['computedAt'] ?? '')) . '</td></tr>'
            . '<tr><th>Policy applied</th><td>v' . $e((string) ($comp['policyVersion'] ?? '0'))
            . (($comp['policyEffective'] ?? '') ? ' (effective ' . $e($comp['policyEffective']) . ')' : '')
            . '<br>' . $policyLine . '</td></tr>'
            . (!empty($comp['clearanceOverridden'] ?? null)
                ? '<tr><th>Clearance</th><td>Overridden by an administrator</td></tr>' : '')
            . '</table>'

            . '<h2>Amounts</h2><table>'
            . '<tr><th>Gross earnings</th><td class="num">' . $money($gross) . '</td></tr>'
            . '<tr><th>Total deductions</th><td class="num">' . $money($ded) . '</td></tr>'
            // A NEGATIVE net means the recovery exceeded what was owed — the
            // employee owes the school. Printing "Net payable: -21,500.00" and
            // leaving the reader to work out the sign is how a leaver misreads a
            // money document. Name what the number is.
            . ($net < 0
                ? '<tr class="tot"><td>Net recoverable from employee</td><td class="num">'
                  . $money(abs($net)) . '</td></tr>'
                : '<tr class="tot"><td>Net payable</td><td class="num">' . $money($net) . '</td></tr>')
            . '</table>'
            . ($slip === []
                ? '<div class="warn">The payroll slip for this run could not be read, so the '
                  . 'amounts above are shown as zero. This statement should not be issued.</div>'
                : '')

            . '<h2>History</h2><table>'
            . '<tr><th style="width:auto">State</th><th style="width:auto">When</th>'
            . '<th style="width:auto">By</th><th style="width:auto">Note</th></tr>'
            . $lifecycle . '</table>'

            . (!empty($doc['paymentRef']) ? '<h2>Payment</h2><table><tr><th>Reference</th><td>'
                . $e($doc['paymentRef']) . '</td></tr>'
                . (!empty($doc['accountingEntryId']) ? '<tr><th>Accounting entry</th><td>'
                    . $e($doc['accountingEntryId']) . '</td></tr>' : '')
                . '</table>' : '')

            . '<div class="foot">Generated ' . $e(date('d M Y H:i'))
            . '. Amounts are those computed by payroll run ' . $e($comp['runId'] ?? '')
            . '; this statement reports them and does not recalculate.</div>'
            . '</body></html>';
    }
}
