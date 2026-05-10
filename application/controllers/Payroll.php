<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll — Stage 3 UI integration controller.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  Operator-facing HTTP endpoints that orchestrate around the canonical
 *  PayrollAccountingService (Stage 2). No engine logic, no direct ledger
 *  writes, no bypass paths — this controller only:
 *    • renders the operator UI
 *    • validates inputs
 *    • computes preview line projections (read-only)
 *    • forwards confirmed posts to PayrollAccountingService
 *    • returns recent payroll posts for status visibility
 *
 *  The service layer enforces the canonical accounting routing. This
 *  controller cannot reach the ledger except through the service.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ENDPOINTS
 * ──────────────────────────────────────────────────────────────────────
 *  GET  /payroll                          — render UI (index view)
 *  POST /payroll/preview_accrual          — read-only line projection
 *  POST /payroll/preview_payout           — read-only line projection
 *  POST /payroll/preview_statutory        — read-only line projection
 *  POST /payroll/post_accrual             — submit accrual via service
 *  POST /payroll/post_payout              — submit payout via service
 *  POST /payroll/post_statutory_deposit   — submit statutory via service
 *  POST /payroll/post_reversal            — initiate reversal via service
 *  GET  /payroll/get_recent_posts         — list recent payroll journals
 *
 * ──────────────────────────────────────────────────────────────────────
 *  SAFETY PROPERTIES
 * ──────────────────────────────────────────────────────────────────────
 *    • Every POST endpoint is role-gated to FINANCE_ROLES
 *    • Every POST endpoint checks the payroll_engine_integration flag
 *      (returns HTTP 503 with logged refusal if disabled)
 *    • All write paths go through PayrollAccountingService::post*; no
 *      controller method invokes Operations_accounting directly nor
 *      writes to any accounting Firestore collection
 *    • Preview endpoints are pure-compute; no Firestore writes, no
 *      service calls — they project the line shape the service would
 *      produce, so operator can review before confirming
 *    • Server-side validation mirrors the service's validators so the
 *      UI surfaces clear errors before the engine call attempts
 *
 *  Out of scope (per Stage 3 authorization):
 *    • bulk payroll automation
 *    • reimbursement / advance flows
 *    • governance approval workflows
 *    • reporting / materializer activation
 *    • HR-module rewrite
 *    • operational payroll collections (payrollAccruals, payrollPayouts)
 */
class Payroll extends MY_Controller
{
    private const FINANCE_ROLES = [
        'Admin', 'Super Admin', 'School Super Admin', 'Our Panel',
        'Accountant', 'Finance', 'Principal', 'Vice Principal',
    ];

    private const ALLOWED_MODES        = ['cash', 'bank', 'cheque', 'upi', 'neft', 'rtgs', 'online'];
    private const ALLOWED_ROLE_CLASSES = ['teaching', 'non_teaching', 'admin', 'support'];
    private const ALLOWED_STAT_CODES   = ['2030', '2031', '2032', '2033', '2034'];

    /** @var PayrollAccountingService|null */
    private $payAcct = null;

    public function __construct()
    {
        parent::__construct();
        // _require_role() inherited from MY_Controller.
    }

    /**
     * Render the operator UI. Read-only page; the operator's POSTs go to
     * the dedicated post_* endpoints below.
     */
    public function index(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $data = [
            'page_title'      => 'Payroll Posting',
            'flag_enabled'    => (bool) $this->config->item('payroll_engine_integration'),
            'school_name'     => $this->school_name,
            'session_year'    => $this->session_year,
            'admin_id'        => $this->admin_id,
            'admin_role'      => $this->admin_role,
        ];
        $this->load->view('payroll/index', $data);
    }

    // ═════════════════════════════════════════════════════════════════
    //  PREVIEW ENDPOINTS (read-only; no engine, no service)
    // ═════════════════════════════════════════════════════════════════

    public function preview_accrual(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $payload = $this->_collectAccrualPayload();
        $err = $this->_validateAccrualPayload($payload);
        if ($err !== '') $this->json_error($err);

        $lines = $this->_buildAccrualPreviewLines($payload);
        $this->json_success([
            'preview' => [
                'source'          => 'payroll_accrual',
                'source_ref'      => "{$payload['period_label']}_{$payload['employee_id']}",
                'expected_entry_id' => "JE_PAYROLL_ACCRUAL_{$payload['period_label']}_{$payload['employee_id']}",
                'journal_date'    => $payload['journal_date'] ?? $payload['period_end'] ?? date('Y-m-d'),
                'lines'           => $lines,
                'total_dr'        => $this->_sumKey($lines, 'dr'),
                'total_cr'        => $this->_sumKey($lines, 'cr'),
            ],
        ]);
    }

    public function preview_payout(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $payload = $this->_collectPayoutPayload();
        $err = $this->_validatePayoutPayload($payload);
        if ($err !== '') $this->json_error($err);

        $cashOrBank = $this->_resolveCashOrBank($payload['mode'], $payload['bank_code'] ?? '');
        $amt = round((float) $payload['amount'], 2);
        $lines = [
            $this->_line('2020', $amt, 0, "Salary payout {$payload['period_label']} — {$payload['employee_id']}"),
            $this->_line($cashOrBank, 0, $amt, "Salary payout {$payload['period_label']} — {$payload['employee_id']}"),
        ];
        $this->json_success([
            'preview' => [
                'source'          => 'payroll_payout',
                'source_ref'      => "{$payload['period_label']}_{$payload['employee_id']}",
                'expected_entry_id' => "JE_PAYROLL_PAYOUT_{$payload['period_label']}_{$payload['employee_id']}",
                'journal_date'    => $payload['journal_date'] ?? date('Y-m-d'),
                'lines'           => $lines,
                'total_dr'        => $amt,
                'total_cr'        => $amt,
            ],
        ]);
    }

    public function preview_statutory(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $payload = $this->_collectStatutoryPayload();
        $err = $this->_validateStatutoryPayload($payload);
        if ($err !== '') $this->json_error($err);

        $cashOrBank = $this->_resolveCashOrBank($payload['mode'], $payload['bank_code'] ?? '');
        $amt = round((float) $payload['amount'], 2);
        $lines = [
            $this->_line($payload['account_code'], $amt, 0, "Statutory deposit {$payload['period_label']}"),
            $this->_line($cashOrBank, 0, $amt, "Statutory deposit {$payload['period_label']}"),
        ];
        $this->json_success([
            'preview' => [
                'source'          => 'payroll_stat',
                'source_ref'      => "{$payload['period_label']}_{$payload['account_code']}",
                'expected_entry_id' => "JE_PAYROLL_STAT_{$payload['period_label']}_{$payload['account_code']}",
                'journal_date'    => $payload['journal_date'] ?? date('Y-m-d'),
                'lines'           => $lines,
                'total_dr'        => $amt,
                'total_cr'        => $amt,
            ],
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  POST ENDPOINTS (commit via PayrollAccountingService)
    // ═════════════════════════════════════════════════════════════════

    public function post_accrual(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        if (!$this->_assertFlagEnabled()) return;
        $payload = $this->_collectAccrualPayload();
        $err = $this->_validateAccrualPayload($payload);
        if ($err !== '') $this->json_error($err);
        $entryId = $this->_service()->postAccrual($payload);
        if (!$entryId) {
            $this->json_error('Accrual posting failed. Check logs (PAYROLL_* prefix) for the engine-side reason.', 422);
        }
        $this->json_success(['entry_id' => $entryId, 'event' => 'accrual']);
    }

    public function post_payout(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        if (!$this->_assertFlagEnabled()) return;
        $payload = $this->_collectPayoutPayload();
        $err = $this->_validatePayoutPayload($payload);
        if ($err !== '') $this->json_error($err);
        $entryId = $this->_service()->postPayout($payload);
        if (!$entryId) {
            $this->json_error('Payout posting failed. Check logs (PAYROLL_* prefix) for the engine-side reason.', 422);
        }
        $this->json_success(['entry_id' => $entryId, 'event' => 'payout']);
    }

    public function post_statutory_deposit(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        if (!$this->_assertFlagEnabled()) return;
        $payload = $this->_collectStatutoryPayload();
        $err = $this->_validateStatutoryPayload($payload);
        if ($err !== '') $this->json_error($err);
        $entryId = $this->_service()->postStatutoryDeposit($payload);
        if (!$entryId) {
            $this->json_error('Statutory deposit failed. Check logs (PAYROLL_* prefix) for the engine-side reason.', 422);
        }
        $this->json_success(['entry_id' => $entryId, 'event' => 'statutory_deposit']);
    }

    public function post_reversal(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        if (!$this->_assertFlagEnabled()) return;
        $originalEntryId = trim((string) $this->input->post('original_entry_id'));
        $reason          = trim((string) $this->input->post('reason'));
        $journalDate     = trim((string) $this->input->post('journal_date'));

        if ($originalEntryId === '') $this->json_error('original_entry_id is required.');
        if ($reason === '')          $this->json_error('reason is required for audit trail.');
        if (!preg_match('/^JE_PAYROLL_/', $originalEntryId)) {
            $this->json_error('original_entry_id must reference a payroll journal (JE_PAYROLL_*).');
        }

        $entryId = $this->_service()->postReversal(
            $originalEntryId, $reason, $journalDate !== '' ? $journalDate : null
        );
        if (!$entryId) {
            $this->json_error('Reversal failed. Check logs (PAYROLL_* prefix) for the engine-side reason.', 422);
        }
        $this->json_success([
            'entry_id'           => $entryId,
            'original_entry_id'  => $originalEntryId,
            'event'              => 'reversal',
        ]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  STATUS / VISIBILITY
    // ═════════════════════════════════════════════════════════════════

    // ═════════════════════════════════════════════════════════════════
    //  STAGE 4 — Operational metadata queries (read-only)
    // ═════════════════════════════════════════════════════════════════

    /**
     * Lists payroll accruals for a period from the operational
     * collection (payrollAccruals). Pure read.
     */
    public function get_period_accruals(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $period = trim((string) $this->input->get('period_label'));
        if ($period === '') $this->json_error('period_label is required.');
        $repo = $this->_opsRepo();
        $rows = $repo->listAccrualsForPeriod($period);
        $this->json_success(['period_label' => $period, 'accruals' => $rows, 'count' => count($rows)]);
    }

    public function get_period_payouts(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $period = trim((string) $this->input->get('period_label'));
        if ($period === '') $this->json_error('period_label is required.');
        $repo = $this->_opsRepo();
        $rows = $repo->listPayoutsForPeriod($period);
        $this->json_success(['period_label' => $period, 'payouts' => $rows, 'count' => count($rows)]);
    }

    public function get_period_statutory(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $period = trim((string) $this->input->get('period_label'));
        if ($period === '') $this->json_error('period_label is required.');
        $repo = $this->_opsRepo();
        $rows = $repo->listStatutoryForPeriod($period);
        $this->json_success(['period_label' => $period, 'statutory' => $rows, 'count' => count($rows)]);
    }

    public function get_employee_history(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $emp = trim((string) $this->input->get('employee_id'));
        if ($emp === '') $this->json_error('employee_id is required.');
        $repo = $this->_opsRepo();
        $hist = $repo->listEmployeeHistory($emp);
        $this->json_success([
            'employee_id' => $emp,
            'accruals'    => $hist['accruals'] ?? [],
            'payouts'     => $hist['payouts']  ?? [],
        ]);
    }

    /**
     * Returns the most recent payroll journals (last 50) for the operator's
     * school+session, with denormalized fields for the UI table.
     * Pure read; never writes.
     */
    public function get_recent_posts(): void
    {
        $this->_require_role(self::FINANCE_ROLES);
        $rows = (array) $this->firebase->firestoreQuery('accounting', [
            ['schoolId', '==', $this->school_id],
            ['session',  '==', $this->session_year],
        ], 'created_at', 'DESC', 200);

        $payrollSources = ['payroll_accrual', 'payroll_payout', 'payroll_stat', 'payroll_reversal'];
        $out = [];
        foreach ($rows as $r) {
            $d = (array) ($r['data'] ?? []);
            $src = (string) ($d['source'] ?? '');
            if (!in_array($src, $payrollSources, true)) continue;
            $out[] = [
                'doc_id'      => (string) ($r['id'] ?? ''),
                'entry_id'    => (string) ($d['entryId'] ?? ''),
                'voucher_no'  => (string) ($d['voucher_no'] ?? ''),
                'source'      => $src,
                'source_ref'  => (string) ($d['source_ref'] ?? ''),
                'date'        => (string) ($d['date'] ?? $d['entry_date'] ?? ''),
                'created_at'  => (string) ($d['created_at'] ?? ''),
                'created_by'  => (string) ($d['created_by'] ?? ''),
                'total_dr'    => (float) ($d['total_dr'] ?? 0),
                'total_cr'    => (float) ($d['total_cr'] ?? 0),
                'lines_count' => count((array) ($d['lines'] ?? [])),
                'narration'   => (string) ($d['narration'] ?? ''),
            ];
            if (count($out) >= 50) break;
        }
        $this->json_success(['posts' => $out, 'count' => count($out)]);
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internal helpers
    // ═════════════════════════════════════════════════════════════════

    /**
     * Lazy-load the PayrollAccountingService (mirrors the FeeCollectionService
     * loading pattern in Fees.php). Service is initialized once per request.
     */
    private function _service(): PayrollAccountingService
    {
        if ($this->payAcct === null) {
            require_once APPPATH . 'services/PayrollAccountingService.php';
            $this->payAcct = new PayrollAccountingService();
            $this->payAcct->init(
                $this->firebase, $this->school_id, $this->session_year,
                $this->admin_id, $this
            );
        }
        return $this->payAcct;
    }

    /**
     * Stage 4 — Lazy-load the operational metadata repo for read-only
     * controller queries.
     */
    private function _opsRepo(): Payroll_operational_repo
    {
        $this->load->library('Payroll_operational_repo', null, 'payOpsRepo');
        $this->payOpsRepo->init($this->firebase, $this->school_id, $this->session_year);
        return $this->payOpsRepo;
    }

    /**
     * Returns false (and emits 503 + logged refusal) if the flag is OFF.
     * Caller must short-circuit on false; this method already wrote the
     * JSON error to output.
     */
    private function _assertFlagEnabled(): bool
    {
        if (!(bool) $this->config->item('payroll_engine_integration')) {
            log_message('error',
                "PAYROLL_UI_REFUSED reason='flag_off' actor={$this->admin_id} "
                . "endpoint={$this->uri->uri_string()}");
            $this->json_error(
                'Payroll engine integration is disabled. Set PAYROLL_ENGINE_INTEGRATION=1 to enable.',
                503
            );
            return false;
        }
        return true;
    }

    private function _collectAccrualPayload(): array
    {
        return [
            'employee_id'   => trim((string) $this->input->post('employee_id')),
            'employee_name' => trim((string) $this->input->post('employee_name')),
            'role_class'    => strtolower(trim((string) $this->input->post('role_class') ?: 'non_teaching')),
            'period_label'  => trim((string) $this->input->post('period_label')),
            'period_start'  => trim((string) $this->input->post('period_start')),
            'period_end'    => trim((string) $this->input->post('period_end')),
            'gross_salary'  => (float) $this->input->post('gross_salary'),
            'net_take_home' => (float) $this->input->post('net_take_home'),
            'deductions' => [
                'pf_employee'   => (float) $this->input->post('ded_pf_employee'),
                'esi_employee'  => (float) $this->input->post('ded_esi_employee'),
                'tds'           => (float) $this->input->post('ded_tds'),
                'prof_tax'      => (float) $this->input->post('ded_prof_tax'),
                'other'         => (float) $this->input->post('ded_other'),
            ],
            'employer_contributions' => [
                'pf_employer'  => (float) $this->input->post('emp_pf_employer'),
                'esi_employer' => (float) $this->input->post('emp_esi_employer'),
            ],
            'journal_date'  => trim((string) $this->input->post('journal_date')),
        ];
    }

    private function _collectPayoutPayload(): array
    {
        return [
            'employee_id'  => trim((string) $this->input->post('employee_id')),
            'period_label' => trim((string) $this->input->post('period_label')),
            'amount'       => (float) $this->input->post('amount'),
            'mode'         => strtolower(trim((string) $this->input->post('mode'))),
            'bank_code'    => trim((string) $this->input->post('bank_code')),
            'journal_date' => trim((string) $this->input->post('journal_date')),
        ];
    }

    private function _collectStatutoryPayload(): array
    {
        return [
            'period_label' => trim((string) $this->input->post('period_label')),
            'account_code' => trim((string) $this->input->post('account_code')),
            'amount'       => (float) $this->input->post('amount'),
            'mode'         => strtolower(trim((string) $this->input->post('mode'))),
            'bank_code'    => trim((string) $this->input->post('bank_code')),
            'journal_date' => trim((string) $this->input->post('journal_date')),
        ];
    }

    /**
     * Mirrors PayrollAccountingService::_validateAccrualPayload so the UI
     * surfaces the same errors the service would. Kept in sync structurally.
     */
    private function _validateAccrualPayload(array $p): string
    {
        if (empty($p['employee_id']))   return 'employee_id is required.';
        if (empty($p['period_label']))  return 'period_label is required (YYYY-MM).';
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $p['period_label'])) return 'period_label must be YYYY-MM.';
        if (!in_array($p['role_class'], self::ALLOWED_ROLE_CLASSES, true)) {
            return 'role_class must be one of: ' . implode(', ', self::ALLOWED_ROLE_CLASSES);
        }
        if ((float) $p['gross_salary'] <= 0)    return 'gross_salary must be > 0.';
        if ((float) $p['net_take_home'] < 0)    return 'net_take_home cannot be negative.';
        $jd = (string) ($p['journal_date'] ?? '');
        if ($jd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jd)) return 'journal_date must be YYYY-MM-DD.';

        $totalDed = (float) $p['deductions']['pf_employee']
                  + (float) $p['deductions']['esi_employee']
                  + (float) $p['deductions']['tds']
                  + (float) $p['deductions']['prof_tax']
                  + (float) $p['deductions']['other'];
        if (abs(round((float) $p['gross_salary'] - (float) $p['net_take_home'] - $totalDed, 2)) > 0.01) {
            return 'gross_salary must equal net_take_home + sum(deductions).';
        }
        return '';
    }

    private function _validatePayoutPayload(array $p): string
    {
        if (empty($p['employee_id']))   return 'employee_id is required.';
        if (empty($p['period_label']))  return 'period_label is required.';
        if ((float) $p['amount'] <= 0)  return 'amount must be > 0.';
        if (!in_array($p['mode'], self::ALLOWED_MODES, true)) {
            return 'mode must be one of: ' . implode(', ', self::ALLOWED_MODES);
        }
        if ($p['mode'] !== 'cash' && empty($p['bank_code'])) {
            return 'bank_code is required for non-cash payout.';
        }
        return '';
    }

    private function _validateStatutoryPayload(array $p): string
    {
        if (empty($p['period_label']))  return 'period_label is required.';
        if (empty($p['account_code']))  return 'account_code is required.';
        if (!in_array($p['account_code'], self::ALLOWED_STAT_CODES, true)) {
            return 'account_code must be one of: ' . implode(', ', self::ALLOWED_STAT_CODES);
        }
        if ((float) $p['amount'] <= 0)  return 'amount must be > 0.';
        if (!in_array($p['mode'], self::ALLOWED_MODES, true)) {
            return 'mode must be one of: ' . implode(', ', self::ALLOWED_MODES);
        }
        if ($p['mode'] !== 'cash' && empty($p['bank_code'])) {
            return 'bank_code is required for non-cash deposit.';
        }
        return '';
    }

    /**
     * Pure read-side line projection — mirrors the line-build logic in
     * PayrollAccountingService::postAccrual without any engine commit.
     * Operator sees what would post before clicking Confirm.
     */
    private function _buildAccrualPreviewLines(array $p): array
    {
        $employeeId   = (string) $p['employee_id'];
        $employeeName = (string) ($p['employee_name'] ?? $employeeId);
        $period       = (string) $p['period_label'];
        $gross        = round((float) $p['gross_salary'], 2);
        $netTakeHome  = round((float) $p['net_take_home'], 2);
        $d            = $p['deductions'];
        $e            = $p['employer_contributions'];

        $pfEmp   = round((float) $d['pf_employee'], 2);
        $esiEmp  = round((float) $d['esi_employee'], 2);
        $tds     = round((float) $d['tds'], 2);
        $pt      = round((float) $d['prof_tax'], 2);
        $other   = round((float) $d['other'], 2);
        $pfErEmp = round((float) $e['pf_employer'], 2);
        $esiErEmp= round((float) $e['esi_employer'], 2);

        $salaryAcct = ($p['role_class'] === 'teaching') ? '5010' : '5020';
        $totalEmployerCtb = round($pfErEmp + $esiErEmp, 2);

        $lines = [];
        $lines[] = $this->_line($salaryAcct, $gross, 0,
            "Salary accrual {$period} — {$employeeName} ({$employeeId})");
        if ($totalEmployerCtb > 0) {
            $lines[] = $this->_line('5030', $totalEmployerCtb, 0,
                "Employer PF/ESI contribution {$period} — {$employeeName}");
        }
        if ($netTakeHome > 0) {
            $lines[] = $this->_line('2020', 0, $netTakeHome,
                "Net salary payable — {$employeeName} {$period}");
        }
        $pfTotal  = round($pfEmp  + $pfErEmp,  2);
        $esiTotal = round($esiEmp + $esiErEmp, 2);
        if ($pfTotal  > 0) $lines[] = $this->_line('2030', 0, $pfTotal,  "PF (employee+employer) {$period}");
        if ($esiTotal > 0) $lines[] = $this->_line('2031', 0, $esiTotal, "ESI (employee+employer) {$period}");
        if ($tds      > 0) $lines[] = $this->_line('2032', 0, $tds,      "TDS {$period}");
        if ($pt       > 0) $lines[] = $this->_line('2033', 0, $pt,       "Professional Tax {$period}");
        if ($other    > 0) $lines[] = $this->_line('2034', 0, $other,    "Other deductions {$period}");

        return $lines;
    }

    private function _resolveCashOrBank(string $mode, string $bankCode): string
    {
        if ($mode === 'cash') return '1010';
        return $bankCode !== '' ? $bankCode : '1020';
    }

    private function _line(string $code, float $dr, float $cr, string $narration): array
    {
        return [
            'account_code' => $code,
            'dr'           => round($dr, 2),
            'cr'           => round($cr, 2),
            'narration'    => $narration,
        ];
    }

    private function _sumKey(array $rows, string $key): float
    {
        $s = 0.0;
        foreach ($rows as $r) $s += (float) ($r[$key] ?? 0);
        return round($s, 2);
    }
}
