<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll-period override endpoints (ZenX HRMS W3.2.2).
 *
 * SCOPE — deliberately narrow
 * ---------------------------
 * This controller stores and withdraws payroll-period adjustments. It does NOT
 * compute payroll and does NOT post to accounting. Applying an override means
 * re-running the certified engine, which is `Hr::regenerate_staff_payroll()` —
 * already built, already posting an idempotent delta journal through
 * `PayrollAccountingService::postRunAdjustment()`, already validating Dr == Cr.
 *
 * Reusing that path rather than adding a second one is the whole design: an
 * override is an INPUT to the existing recalculation, not a parallel money path.
 * There is no second payroll engine and no second accounting posting here.
 *
 * RBAC
 * ----
 * Graded `HR` only, via `require_permission()`. No role-name matching, no
 * "Principal"/"Admin" string checks. Reading the grid is `HR:view`; creating or
 * withdrawing an adjustment is `HR:manage` — a money-moving action, so `view`
 * is deliberately NOT sufficient. Every endpoint enforces this independently of
 * the UI; hiding a button is not authorization.
 *
 * See §K of the gate report for why no NEW permission key was minted.
 */
class Payroll_overrides extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        require_permission('HR');
        require_once APPPATH . 'libraries/Payroll_override_service.php';
    }

    private function _require_view(): void
    {
        require_permission('HR', 'payroll_override_view', 'view');
    }

    /** Creating or withdrawing an adjustment moves money — `manage`, never `view`. */
    private function _require_manage(): void
    {
        require_permission('HR', 'payroll_override_manage', 'manage');
    }

    private function _service(): Payroll_override_service
    {
        $svc = new Payroll_override_service();
        $svc->init($this->fs, $this->admin_name ?: $this->admin_id);
        return $svc;
    }

    /**
     * Load a run and prove it belongs to THIS tenant.
     *
     * `get()` + `docId()` is the idiom every other run lookup in `Hr.php` uses,
     * and it is used here verbatim rather than the `getEntity()` shorthand —
     * mixing the two is the double-tenant-prefix bug class W1.3 found in ATS
     * and Attendance, and it is not worth re-opening for brevity.
     *
     * `docId()` prefixes the CALLER's school_id, so a forged run id from
     * another tenant resolves to a document that does not exist rather than to
     * the other tenant's run. The explicit schoolId re-check below is
     * belt-and-braces against a document carrying a foreign tenant id.
     */
    private function _load_run_scoped(string $runId): ?array
    {
        if ($runId === '') return null;
        try {
            $run = $this->fs->get('salarySlips', $this->fs->docId("RUN_{$runId}"));
        } catch (\Exception $e) {
            log_message('error', 'Payroll_overrides run load failed: ' . $e->getMessage());
            return null;
        }
        if (!is_array($run) || empty($run)) return null;

        $owner = (string) ($run['schoolId'] ?? '');
        if ($owner !== '' && $owner !== $this->school_id) {
            log_message('error', "PAYROLL_OVERRIDE_TENANT_MISMATCH run={$runId} owner={$owner} caller={$this->school_id}");
            return null;
        }
        return $run;
    }

    // ─────────────────────────────────────────────────────────────────────

    /** GET — every adjustment on a run, grouped by employee. One query. */
    public function list_for_run(): void
    {
        $this->_require_view();

        $runId = trim((string) ($this->input->get('run_id') ?? ''));
        $run   = $this->_load_run_scoped($runId);
        if ($run === null) $this->json_error('Payroll run not found.');

        $byStaff = $this->_service()->forRun($runId);

        $summaries = [];
        foreach ($byStaff as $sid => $rows) {
            $summaries[$sid] = Payroll_override_service::summarise($rows);
        }

        $this->json_success([
            'run_id'    => $runId,
            // NOT 'status' — it would overwrite json_success()'s envelope key
            // and break the caller's success check. See Hr::payslip_trace().
            'run_status' => (string) ($run['status'] ?? ''),
            'editable'  => Payroll_override_service::isRunEditable($run),
            'overrides' => $byStaff,
            'summary'   => $summaries,
        ]);
    }

    /** GET — the adjustment types an operator may choose, plus what is not yet supported. */
    public function types(): void
    {
        $this->_require_view();

        $supported = [];
        foreach (Payroll_override_service::TYPES as $code => $meta) {
            $supported[] = [
                'code'    => $code,
                'label'   => $meta['label'],
                'kind'    => $meta['kind'],
                'taxable' => $meta['taxable'],
            ];
        }

        $this->json_success([
            'types' => $supported,
            // Surfaced rather than hidden: an operator who looks for
            // "Reimbursement" deserves to be told it is not supported yet and
            // why, instead of finding it silently missing and booking it as a
            // bonus — which would tax it.
            'unsupported' => Payroll_override_service::unsupportedTypes(),
        ]);
    }

    /**
     * POST — create a payroll-period adjustment.
     *
     * Stores only. The operator (or the UI) then triggers the existing
     * `hr/regenerate_staff_payroll` to apply it, which is what recalculates the
     * payslip and posts the accounting delta.
     */
    public function create(): void
    {
        $this->_require_manage();

        $in = [
            'run_id'   => $this->input->post('run_id'),
            'staff_id' => $this->input->post('staff_id'),
            'type'     => $this->input->post('type'),
            'amount'   => $this->input->post('amount'),
            'reason'   => $this->input->post('reason'),
            'prorated' => $this->input->post('prorated'),
        ];

        $svc = $this->_service();
        $v   = $svc->validate($in);
        if (!$v['ok']) $this->json_error(implode(' ', $v['errors']));

        $clean = $v['clean'];

        // Tenant + editability, server-side. The UI also checks, and that is
        // not what makes it safe.
        $run = $this->_load_run_scoped($clean['run_id']);
        if ($run === null) $this->json_error('Payroll run not found.');
        if (!Payroll_override_service::isRunEditable($run)) {
            $this->json_error("Run is '" . ($run['status'] ?? '?') . "'. Adjustments are only allowed while the run is Draft. "
                . 'Use the audited unlock workflow if a finalized run must be corrected.');
        }

        // The employee must actually be on this run — otherwise an adjustment
        // could be attached to someone the run never paid, and it would sit
        // there invisibly until a regeneration that never comes.
        $slipId = $this->fs->docId("SLIP_{$clean['run_id']}_{$clean['staff_id']}");
        try {
            $slip = $this->fs->get('salarySlips', $slipId);
        } catch (\Exception $e) {
            $slip = null;
        }
        if (!is_array($slip) || empty($slip)) {
            $this->json_error('That employee has no payslip in this run.');
        }

        // Deterministic id → a double-submit dedups instead of creating a
        // second bonus. The sequence counts EXISTING adjustments of the same
        // type for this employee, so a genuine second bonus still gets its own
        // document and its own audit row.
        $existing = $svc->forStaff($clean['run_id'], $clean['staff_id']);
        $seq = 0;
        foreach ($existing as $row) {
            if ((string) ($row['type'] ?? '') === $clean['type']) $seq++;
        }

        $doc = $svc->create($clean, $seq);

        log_message('info', "PAYROLL_OVERRIDE_CREATED school={$this->school_id} run={$clean['run_id']} "
            . "staff={$clean['staff_id']} type={$clean['type']} paise={$clean['amount_paise']} by={$this->admin_name}");

        $this->json_success([
            'override'  => $doc,
            // The caller must recalculate for this to reach the payslip. Said
            // explicitly so a stored-but-unapplied adjustment is never mistaken
            // for a paid one.
            'applied'   => false,
            'next_step' => 'Recalculate this employee to apply the adjustment to the payslip and accounting.',
        ], 'Adjustment saved. Recalculate the employee to apply it.');
    }

    /**
     * POST — withdraw an adjustment.
     *
     * Voids, never deletes: a withdrawn adjustment is exactly what an auditor
     * asks about, and deleting the row destroys the answer.
     */
    public function void_override(): void
    {
        $this->_require_manage();

        $overrideId = trim((string) $this->input->post('override_id'));
        $runId      = trim((string) $this->input->post('run_id'));
        $reason     = trim((string) $this->input->post('reason'));

        if ($overrideId === '') $this->json_error('override_id is required.');
        if (strlen($reason) < Payroll_override_service::MIN_REASON) {
            $this->json_error('A reason of at least ' . Payroll_override_service::MIN_REASON . ' characters is required.');
        }

        $run = $this->_load_run_scoped($runId);
        if ($run === null) $this->json_error('Payroll run not found.');
        if (!Payroll_override_service::isRunEditable($run)) {
            $this->json_error('Run is not editable. Adjustments are frozen once the run leaves Draft.');
        }

        // Tenant ownership of the override itself — not merely of the run.
        $svc  = $this->_service();
        $rows = $svc->forRun($runId);
        $found = null;
        foreach ($rows as $staffRows) {
            foreach ($staffRows as $r) {
                if ((string) ($r['override_id'] ?? '') === $overrideId) { $found = $r; break 2; }
            }
        }
        if ($found === null) $this->json_error('Adjustment not found on this run.');

        $svc->void($overrideId, $reason);

        log_message('info', "PAYROLL_OVERRIDE_VOIDED school={$this->school_id} run={$runId} "
            . "override={$overrideId} by={$this->admin_name}");

        $this->json_success([
            'override_id' => $overrideId,
            'applied'     => false,
            'next_step'   => 'Recalculate this employee to remove the adjustment from the payslip and accounting.',
        ], 'Adjustment withdrawn. Recalculate the employee to apply the removal.');
    }
}
