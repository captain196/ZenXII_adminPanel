<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * PayrollAccountingService — canonical payroll-to-accounting orchestration.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  Constructs payroll-specific journal payloads (accrual, payout, statutory
 *  deposit, reversal) and routes them EXCLUSIVELY through the canonical
 *  accounting engine (`Operations_accounting::create_journal`). This service
 *  is the only sanctioned bridge between the payroll domain and the
 *  immutable ledger.
 *
 *  Defining context: the historical `JE_20260414193106_f55be96d` imbalance
 *  was produced by a legacy HR_Payroll module that performed direct
 *  `firestoreSet('accounting', …)` writes, bypassing the canonical engine
 *  and its CAS / period-lock / idempotency / balance-batch protections.
 *  This service makes that pattern structurally impossible — it has no
 *  direct write paths to any accounting collection. The only sink is the
 *  engine.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  RESPONSIBILITIES
 * ──────────────────────────────────────────────────────────────────────
 *    • Validate per-event payroll payloads (Dr/Cr balance, account
 *      existence, period-shape, employee fields).
 *    • Map operator inputs (role_class, deduction map, mode) to engine-
 *      level account codes via existing CoA configuration.
 *    • Build deterministic source_refs so idempotency holds across retries.
 *    • Invoke `Operations_accounting::create_journal` with the constructed
 *      payload and return the resulting entryId.
 *    • Build reversal payloads from existing ledger docs (read-only) and
 *      route those reversals through the engine identically.
 *    • Honor the `payroll_engine_integration` feature flag — when off,
 *      every method short-circuits with a logged refusal.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  NON-RESPONSIBILITIES (out of scope, by design)
 * ──────────────────────────────────────────────────────────────────────
 *    • Operational records (`payrollAccruals`, `staffAdvances`, etc.) —
 *      callers are responsible for their own operational state.
 *    • Reimbursement / advance / advance-recovery journals — Stage 4+.
 *    • Bulk per-period orchestration — Stage 3+ (UI / HR module).
 *    • Reconciler-side payroll-drift sweep — Stage 9 (optional).
 *    • Approval workflows / governance — Stage 8 (post G1.5).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  USAGE
 * ──────────────────────────────────────────────────────────────────────
 *      $CI->load->library('PayrollAccountingService', null, 'payAcct');
 *      $CI->payAcct->init($CI->firebase, $schoolFs, $session, $adminId, $CI);
 *
 *      $entryId = $CI->payAcct->postAccrual([
 *          'employee_id'       => 'EMP_001',
 *          'employee_name'     => 'Anand Kumar',
 *          'role_class'        => 'teaching',     // teaching|non_teaching|admin|support
 *          'period_label'      => '2026-04',
 *          'period_start'      => '2026-04-01',
 *          'period_end'        => '2026-04-30',
 *          'gross_salary'      => 50000,
 *          'net_take_home'     => 42000,
 *          'deductions' => [
 *              'pf_employee'   => 6000,
 *              'esi_employee'  => 400,
 *              'tds'           => 500,
 *              'prof_tax'      => 200,
 *              'other'         => 900,
 *          ],
 *          'employer_contributions' => [
 *              'pf_employer'   => 6000,
 *              'esi_employer'  => 800,
 *          ],
 *          'journal_date'      => '2026-04-30',   // optional; defaults to period_end
 *      ]);
 *
 *      $entryId = $CI->payAcct->postPayout([
 *          'employee_id'   => 'EMP_001',
 *          'period_label'  => '2026-04',
 *          'amount'        => 42000,
 *          'mode'          => 'bank',             // cash|bank
 *          'bank_code'     => '1020',             // optional; required for non-cash
 *          'journal_date'  => '2026-05-05',       // optional; defaults to today
 *      ]);
 *
 *      $entryId = $CI->payAcct->postStatutoryDeposit([
 *          'period_label'  => '2026-04',
 *          'account_code'  => '2030',             // 2030|2031|2032|2033|2034
 *          'amount'        => 24000,
 *          'mode'          => 'bank',
 *          'bank_code'     => '1020',
 *          'journal_date'  => '2026-05-15',
 *      ]);
 *
 *      $entryId = $CI->payAcct->postReversal(
 *          'JE_PAYROLL_ACCRUAL_2026-04_EMP_001', 'Wrong gross — pro-rate days'
 *      );
 *
 *  All methods return the resulting entryId on success or null on failure.
 *  Failures are logged with PAYROLL_* prefixes for telemetry visibility.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCY (deterministic entryId via source/source_ref)
 * ──────────────────────────────────────────────────────────────────────
 *  Engine derives entryId as `JE_<SOURCE>_<SOURCE_REF>` (uppercase,
 *  alphanum + underscore). This service constructs source_ref so:
 *
 *    Accrual:   JE_PAYROLL_ACCRUAL_<period>_<employee>
 *    Payout:    JE_PAYROLL_PAYOUT_<period>_<employee>
 *    Statutory: JE_PAYROLL_STAT_<period>_<account_code>
 *    Reversal:  JE_PAYROLL_REVERSAL_<original_entry_id>
 *
 *  Re-running with the same key produces idempotent dedup — engine returns
 *  the existing entryId, no duplicate ledger doc.
 */
final class PayrollAccountingService
{
    private const FLAG = 'payroll_engine_integration';

    // Default account routing — operator can override via feeAccountMap
    // (Stage 3+) or by passing explicit codes in the payload (Stage 4+).
    // For Stage 2, these are the defaults referenced when the operator
    // doesn't supply explicit overrides. All codes must exist + be active
    // in the CoA — the engine rejects journals against missing accounts.
    private const ACCT_SALARY_TEACHING       = '5010';
    private const ACCT_SALARY_NON_TEACHING   = '5020';
    private const ACCT_EMPLOYER_CONTRIB      = '5030';
    private const ACCT_SALARY_PAYABLE        = '2020';
    private const ACCT_PF_PAYABLE            = '2030';
    private const ACCT_ESI_PAYABLE           = '2031';
    private const ACCT_TDS_PAYABLE           = '2032';
    private const ACCT_PT_PAYABLE            = '2033';
    private const ACCT_OTHER_DED_PAYABLE     = '2034';
    private const ACCT_CASH                  = '1010';
    private const ACCT_BANK_DEFAULT          = '1020';

    /** @var object|null */ private $firebase = null;
    /** @var object|null */ private $CI       = null;
    /** @var Payroll_operational_repo|null */ private $opsRepo = null;
    private string $schoolFs = '';
    private string $session  = '';
    private string $adminId  = '';
    private bool   $ready    = false;

    public function init($firebase, string $schoolFs, string $session, string $adminId, $CI): self
    {
        $this->firebase = $firebase;
        $this->schoolFs = $schoolFs;
        $this->session  = $session;
        $this->adminId  = $adminId !== '' ? $adminId : 'SYSTEM_PAYROLL';
        $this->CI       = $CI;
        $this->ready    = ($firebase !== null && $schoolFs !== '' && $session !== '' && $CI !== null);
        return $this;
    }

    /**
     * Reports whether the engine-integration feature flag is enabled.
     * When false, every public posting method refuses with a logged event;
     * no ledger writes happen.
     */
    public function isEnabled(): bool
    {
        if (!$this->ready) return false;
        return (bool) $this->CI->config->item(self::FLAG);
    }

    // ═════════════════════════════════════════════════════════════════
    //  ACCRUAL
    // ═════════════════════════════════════════════════════════════════

    /**
     * Post a payroll accrual journal for a single employee for a single
     * period. Recognizes salary expense (Dr) and creates payable
     * liabilities (Cr) per the canonical payroll model.
     *
     * Idempotency: deterministic entryId from (employee_id, period_label).
     * Re-call with identical payload → engine returns existing entryId.
     *
     * Returns null on:
     *   - feature flag off
     *   - validation failure
     *   - engine commit failure
     *   - period-locked rejection
     *
     * @param array $payload Per the docblock at top of class.
     * @return string|null   Engine-assigned entryId or null.
     */
    public function postAccrual(array $payload): ?string
    {
        if (!$this->_assertReady('postAccrual')) return null;

        $err = $this->_validateAccrualPayload($payload);
        if ($err !== '') {
            $this->_log("PAYROLL_ACCRUAL_REJECTED reason='{$err}' employee=" . ($payload['employee_id'] ?? '?'));
            return null;
        }

        $employeeId   = (string) $payload['employee_id'];
        $employeeName = (string) ($payload['employee_name'] ?? $employeeId);
        $roleClass    = (string) ($payload['role_class'] ?? 'non_teaching');
        $periodLabel  = (string) $payload['period_label'];
        $periodStart  = (string) ($payload['period_start'] ?? '');
        $periodEnd    = (string) ($payload['period_end']   ?? $periodLabel . '-30');
        $gross        = round((float) $payload['gross_salary'], 2);
        $netTakeHome  = round((float) $payload['net_take_home'], 2);
        $journalDate  = (string) ($payload['journal_date']  ?? $periodEnd);

        $deductions   = is_array($payload['deductions'] ?? null) ? $payload['deductions'] : [];
        $employerCtb  = is_array($payload['employer_contributions'] ?? null) ? $payload['employer_contributions'] : [];

        $pfEmp   = round((float) ($deductions['pf_employee']  ?? 0), 2);
        $esiEmp  = round((float) ($deductions['esi_employee'] ?? 0), 2);
        $tds     = round((float) ($deductions['tds']          ?? 0), 2);
        $pt      = round((float) ($deductions['prof_tax']     ?? 0), 2);
        $other   = round((float) ($deductions['other']        ?? 0), 2);
        $pfErEmp = round((float) ($employerCtb['pf_employer']  ?? 0), 2);
        $esiErEmp= round((float) ($employerCtb['esi_employer'] ?? 0), 2);

        $totalDeductions     = round($pfEmp + $esiEmp + $tds + $pt + $other, 2);
        $totalEmployerCtb    = round($pfErEmp + $esiErEmp, 2);

        // Internal arithmetic check: gross == net + deductions.
        // This is a payroll-domain check (the operator's payroll calc
        // must satisfy it). The engine independently validates Dr=Cr.
        if (abs(round($gross - $netTakeHome - $totalDeductions, 2)) > 0.01) {
            $this->_log("PAYROLL_ACCRUAL_REJECTED reason='gross_minus_deductions_!=_net' "
                . "gross={$gross} net={$netTakeHome} deductions={$totalDeductions} "
                . "employee={$employeeId} period={$periodLabel}");
            return null;
        }

        // Build lines.
        $salaryAcct = $this->_resolveSalaryAccount($roleClass);
        $lines = [];

        // Dr salary expense at gross.
        $lines[] = $this->_line($salaryAcct, $gross, 0,
            "Salary accrual {$periodLabel} — {$employeeName} ({$employeeId})");

        // Dr employer contribution if any.
        if ($totalEmployerCtb > 0) {
            $lines[] = $this->_line(self::ACCT_EMPLOYER_CONTRIB, $totalEmployerCtb, 0,
                "Employer PF/ESI contribution {$periodLabel} — {$employeeName}");
        }

        // Cr salary payable (net to employee).
        if ($netTakeHome > 0) {
            $lines[] = $this->_line(self::ACCT_SALARY_PAYABLE, 0, $netTakeHome,
                "Net salary payable — {$employeeName} {$periodLabel}");
        }

        // Cr deduction liabilities. Employee deductions + employer
        // contribution to PF/ESI accumulate on the same Payable account
        // (since both are remitted together to the statutory authority).
        $pfTotal  = round($pfEmp  + $pfErEmp,  2);
        $esiTotal = round($esiEmp + $esiErEmp, 2);
        if ($pfTotal  > 0) $lines[] = $this->_line(self::ACCT_PF_PAYABLE,         0, $pfTotal,  "PF (employee+employer) {$periodLabel} — {$employeeName}");
        if ($esiTotal > 0) $lines[] = $this->_line(self::ACCT_ESI_PAYABLE,        0, $esiTotal, "ESI (employee+employer) {$periodLabel} — {$employeeName}");
        if ($tds      > 0) $lines[] = $this->_line(self::ACCT_TDS_PAYABLE,        0, $tds,      "TDS {$periodLabel} — {$employeeName}");
        if ($pt       > 0) $lines[] = $this->_line(self::ACCT_PT_PAYABLE,         0, $pt,       "Professional Tax {$periodLabel} — {$employeeName}");
        if ($other    > 0) $lines[] = $this->_line(self::ACCT_OTHER_DED_PAYABLE,  0, $other,    "Other deductions {$periodLabel} — {$employeeName}");

        $sourceRef = $this->_accrualSourceRef($periodLabel, $employeeId);
        $narration = "Payroll accrual {$periodLabel} — {$employeeName} ({$employeeId})";

        $entryId = $this->_commit('payroll_accrual', $sourceRef, $narration, $lines, $journalDate, [
            'employee_id'  => $employeeId,
            'period_label' => $periodLabel,
        ]);
        if ($entryId !== null) {
            // Stage 4: best-effort operational metadata write. Failure is
            // logged but never blocks the financial commit (which has
            // already succeeded). The ledger is the source of truth.
            $this->_recordOperational(
                fn($repo, $voucherNo) => $repo->recordAccrualPost($payload, $entryId, $voucherNo, $this->adminId),
                $entryId
            );
        }
        return $entryId;
    }

    // ═════════════════════════════════════════════════════════════════
    //  PAYOUT
    // ═════════════════════════════════════════════════════════════════

    /**
     * Post a payroll payout journal — extinguish Salary Payable for one
     * employee+period via cash/bank. Idempotent on (employee, period).
     *
     * The engine does NOT auto-validate that the payout matches an
     * outstanding accrual balance; that's a payroll-module concern. The
     * engine validates Dr=Cr and account existence only.
     *
     * @param array $payload {employee_id, period_label, amount, mode (cash|bank),
     *                       bank_code (optional for non-cash), journal_date (optional)}
     */
    public function postPayout(array $payload): ?string
    {
        if (!$this->_assertReady('postPayout')) return null;

        $err = $this->_validatePayoutPayload($payload);
        if ($err !== '') {
            $this->_log("PAYROLL_PAYOUT_REJECTED reason='{$err}' employee=" . ($payload['employee_id'] ?? '?'));
            return null;
        }

        $employeeId   = (string) $payload['employee_id'];
        $periodLabel  = (string) $payload['period_label'];
        $amount       = round((float) $payload['amount'], 2);
        $mode         = strtolower((string) $payload['mode']);
        $bankCode     = (string) ($payload['bank_code'] ?? '');
        $journalDate  = (string) ($payload['journal_date'] ?? date('Y-m-d'));

        $cashOrBank = $this->_resolveCashOrBank($mode, $bankCode);

        $lines = [
            $this->_line(self::ACCT_SALARY_PAYABLE, $amount, 0,
                "Salary payout {$periodLabel} — employee {$employeeId}"),
            $this->_line($cashOrBank, 0, $amount,
                "Salary payout {$periodLabel} — employee {$employeeId}"),
        ];

        $sourceRef = $this->_payoutSourceRef($periodLabel, $employeeId);
        $narration = "Payroll payout {$periodLabel} — employee {$employeeId} ({$mode})";

        $entryId = $this->_commit('payroll_payout', $sourceRef, $narration, $lines, $journalDate, [
            'employee_id'  => $employeeId,
            'period_label' => $periodLabel,
            'mode'         => $mode,
        ]);
        if ($entryId !== null) {
            $this->_recordOperational(
                fn($repo, $voucherNo) => $repo->recordPayoutPost($payload, $entryId, $voucherNo, $this->adminId),
                $entryId
            );
        }
        return $entryId;
    }

    // ═════════════════════════════════════════════════════════════════
    //  STATUTORY DEPOSIT
    // ═════════════════════════════════════════════════════════════════

    /**
     * Post a statutory-deposit journal — extinguish a payable liability
     * (PF/ESI/TDS/PT/Other) by remitting to the statutory authority.
     * Per period per account_code. Idempotent on (period, account).
     *
     * @param array $payload {period_label, account_code, amount, mode, bank_code (optional), journal_date (optional)}
     */
    public function postStatutoryDeposit(array $payload): ?string
    {
        if (!$this->_assertReady('postStatutoryDeposit')) return null;

        $err = $this->_validateStatutoryPayload($payload);
        if ($err !== '') {
            $this->_log("PAYROLL_STAT_REJECTED reason='{$err}' period=" . ($payload['period_label'] ?? '?'));
            return null;
        }

        $periodLabel = (string) $payload['period_label'];
        $acctCode    = (string) $payload['account_code'];
        $amount      = round((float) $payload['amount'], 2);
        $mode        = strtolower((string) $payload['mode']);
        $bankCode    = (string) ($payload['bank_code'] ?? '');
        $journalDate = (string) ($payload['journal_date'] ?? date('Y-m-d'));

        $cashOrBank = $this->_resolveCashOrBank($mode, $bankCode);

        $lines = [
            $this->_line($acctCode, $amount, 0,
                "Statutory deposit {$periodLabel} — account {$acctCode}"),
            $this->_line($cashOrBank, 0, $amount,
                "Statutory deposit {$periodLabel} — account {$acctCode}"),
        ];

        $sourceRef = $this->_statSourceRef($periodLabel, $acctCode);
        $narration = "Statutory deposit {$periodLabel} — {$acctCode} ({$mode})";

        $entryId = $this->_commit('payroll_stat', $sourceRef, $narration, $lines, $journalDate, [
            'period_label' => $periodLabel,
            'account_code' => $acctCode,
        ]);
        if ($entryId !== null) {
            $this->_recordOperational(
                fn($repo, $voucherNo) => $repo->recordStatutoryPost($payload, $entryId, $voucherNo, $this->adminId),
                $entryId
            );
        }
        return $entryId;
    }

    // ═════════════════════════════════════════════════════════════════
    //  REVERSAL
    // ═════════════════════════════════════════════════════════════════

    /**
     * Reverse an existing payroll journal by reading its lines and
     * posting a mirror (Dr/Cr swapped) journal via the canonical engine.
     * Idempotent on $originalEntryId.
     *
     * Reads the original ledger doc (read-only) — never mutates it. The
     * original remains immutable; the reversal nets it to zero.
     *
     * Required only for unwinding wrong accruals, mis-routed payouts,
     * etc. The original entry is preserved as historical fact.
     *
     * @param string $originalEntryId   The entryId of the original journal.
     * @param string $reason            Operator-supplied correction reason.
     * @param string|null $journalDate  Today by default.
     * @return string|null              Reversal entryId or null.
     */
    public function postReversal(string $originalEntryId, string $reason, ?string $journalDate = null): ?string
    {
        if (!$this->_assertReady('postReversal')) return null;
        if ($originalEntryId === '' || $reason === '') {
            $this->_log("PAYROLL_REVERSAL_REJECTED reason='missing originalEntryId or reason'");
            return null;
        }

        // Read original ledger doc (read-only).
        $docId = "{$this->schoolFs}_{$this->session}_{$originalEntryId}";
        $orig = $this->firebase->firestoreGet('accounting', $docId);
        if (!is_array($orig)) {
            $this->_log("PAYROLL_REVERSAL_REJECTED reason='original_not_found' entryId={$originalEntryId}");
            return null;
        }
        $origLines = (array) ($orig['lines'] ?? []);
        if (empty($origLines)) {
            $this->_log("PAYROLL_REVERSAL_REJECTED reason='original_has_no_lines' entryId={$originalEntryId}");
            return null;
        }

        // Build swapped lines.
        $lines = [];
        foreach ($origLines as $L) {
            if (!is_array($L)) continue;
            $code = (string) ($L['account_code'] ?? '');
            $dr   = (float) ($L['dr'] ?? 0);
            $cr   = (float) ($L['cr'] ?? 0);
            if ($code === '') continue;
            $lines[] = $this->_line($code, $cr, $dr, "Reversal — {$reason}");
        }
        if (count($lines) < 2) {
            $this->_log("PAYROLL_REVERSAL_REJECTED reason='insufficient_lines_after_swap' entryId={$originalEntryId}");
            return null;
        }

        $sourceRef = $this->_reversalSourceRef($originalEntryId);
        $narration = "Payroll reversal of {$originalEntryId} — {$reason}";
        $jdate     = $journalDate ?: date('Y-m-d');

        $reversalEntry = $this->_commit('payroll_reversal', $sourceRef, $narration, $lines, $jdate, [
            'original_entry_id' => $originalEntryId,
            'reason'            => $reason,
        ]);
        if ($reversalEntry !== null) {
            // Update the operational record(s) tied to the original
            // entryId — flip status='reversed', capture reversal_entry_id.
            $this->_recordOperational(
                fn($repo, $_voucher) => $repo->recordReversal($originalEntryId, $reversalEntry, $reason, $this->adminId),
                $reversalEntry
            );
        }
        return $reversalEntry;
    }

    // ═════════════════════════════════════════════════════════════════
    //  INTERNALS
    // ═════════════════════════════════════════════════════════════════

    /**
     * Single canonical commit path. Every payroll event lands here, and
     * here only. By construction, payroll cannot reach the ledger via
     * any other route — the legacy direct-write pattern that produced
     * R1's imbalanced entry is structurally impossible.
     */
    private function _commit(
        string $source, string $sourceRef, string $narration,
        array $lines, string $journalDate, array $logCtx
    ): ?string {
        // Engine call. Operations_accounting::create_journal handles:
        //   • Dr=Cr validation (rejects unbalanced)
        //   • account existence + active + non-group check
        //   • period-lock guard (rejects entries in locked periods)
        //   • CAS-protected balance batch commit
        //   • deterministic entryId derivation (JE_<SOURCE>_<SOURCE_REF>)
        //   • idempotency claim/dedup
        //   • forensic 'created' event
        $opsAcct = $this->_opsAcct();
        if ($opsAcct === null) {
            $this->_log("PAYROLL_COMMIT_FAILED reason='opsAcct_unavailable' source={$source} sourceRef={$sourceRef}");
            return null;
        }

        try {
            $entryId = $opsAcct->create_journal(
                $narration, $lines, $source, $sourceRef, 'Journal', $journalDate
            );
        } catch (\Throwable $e) {
            $this->_log("PAYROLL_COMMIT_FAILED reason='engine_threw' source={$source} sourceRef={$sourceRef} err=" . $e->getMessage());
            return null;
        }
        if (!$entryId) {
            $this->_log("PAYROLL_COMMIT_FAILED reason='engine_returned_empty' source={$source} sourceRef={$sourceRef}");
            return null;
        }

        $ctx = http_build_query(array_merge($logCtx, ['source' => $source, 'sourceRef' => $sourceRef]));
        $this->_log("PAYROLL_COMMITTED entryId={$entryId} {$ctx}");
        return (string) $entryId;
    }

    private function _opsAcct()
    {
        if (!isset($this->CI->opsAcct)) {
            $this->CI->load->library('Operations_accounting', null, 'opsAcct');
            $this->CI->opsAcct->init(
                $this->firebase, $this->schoolFs, $this->session,
                $this->adminId, $this->CI
            );
        }
        return $this->CI->opsAcct ?? null;
    }

    /**
     * Stage 4 — lazy-load the operational metadata repo. Best-effort:
     * if the repo can't be initialized for any reason, the post-commit
     * hook silently skips operational recording (logged via
     * PAYROLL_OPS_REPO_INIT_FAILED). Financial commits already landed
     * on the ledger; operational state can be backfilled later.
     */
    private function _opsRepo(): ?Payroll_operational_repo
    {
        if ($this->opsRepo !== null) return $this->opsRepo;
        try {
            $this->CI->load->library('Payroll_operational_repo', null, 'payOpsRepo');
            $this->CI->payOpsRepo->init($this->firebase, $this->schoolFs, $this->session);
            $this->opsRepo = $this->CI->payOpsRepo;
            return $this->opsRepo;
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_REPO_INIT_FAILED err=" . $e->getMessage());
            return null;
        }
    }

    /**
     * Best-effort post-commit hook. Wraps the operational write in a
     * try/catch — even an exception on the repo side cannot disturb the
     * already-committed ledger entry. Failures are logged with an
     * entry-id back-reference so operators can backfill if needed.
     *
     * `$writer` receives the repo and the voucher_no (resolved from the
     * just-committed ledger doc) and returns bool. If the voucher_no
     * lookup fails, an empty string is passed — the repo's writers
     * accept that gracefully.
     */
    private function _recordOperational(callable $writer, string $entryId): void
    {
        try {
            $repo = $this->_opsRepo();
            if ($repo === null) return;

            // Look up voucher_no from the just-committed ledger doc.
            // Read-only; no engine invocation. If missing we proceed
            // with empty voucher_no (repo treats it as informational).
            $voucherNo = '';
            try {
                $doc = $this->firebase->firestoreGet('accounting',
                    "{$this->schoolFs}_{$this->session}_{$entryId}");
                if (is_array($doc)) {
                    $voucherNo = (string) ($doc['voucher_no'] ?? '');
                }
            } catch (\Throwable $_) { /* read error tolerated */ }

            $writer($repo, $voucherNo);
        } catch (\Throwable $e) {
            log_message('error',
                "PAYROLL_OPS_HOOK_FAILED entryId={$entryId} err=" . $e->getMessage()
                . " — financial commit already succeeded; operational record not written.");
        }
    }

    private function _assertReady(string $method): bool
    {
        if (!$this->ready) {
            $this->_log("PAYROLL_NOT_READY method={$method}");
            return false;
        }
        if (!$this->isEnabled()) {
            $this->_log("PAYROLL_FLAG_DISABLED method={$method} flag=" . self::FLAG);
            return false;
        }
        return true;
    }

    private function _validateAccrualPayload(array $p): string
    {
        if (empty($p['employee_id']))   return 'missing employee_id';
        if (empty($p['period_label']))  return 'missing period_label';
        if (!isset($p['gross_salary']) || (float) $p['gross_salary'] <= 0) return 'gross_salary must be > 0';
        if (!isset($p['net_take_home']) || (float) $p['net_take_home'] < 0) return 'net_take_home must be >= 0';
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $p['period_label'])) return 'period_label must be YYYY-MM';
        $jd = (string) ($p['journal_date'] ?? '');
        if ($jd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jd)) return 'journal_date must be YYYY-MM-DD';
        return '';
    }

    private function _validatePayoutPayload(array $p): string
    {
        if (empty($p['employee_id']))   return 'missing employee_id';
        if (empty($p['period_label']))  return 'missing period_label';
        if (!isset($p['amount']) || (float) $p['amount'] <= 0) return 'amount must be > 0';
        $mode = strtolower((string) ($p['mode'] ?? ''));
        if (!in_array($mode, ['cash', 'bank', 'cheque', 'upi', 'neft', 'rtgs', 'online'], true)) {
            return "invalid mode '{$mode}' (cash|bank|cheque|upi|neft|rtgs|online)";
        }
        if ($mode !== 'cash' && empty($p['bank_code'])) return 'bank_code required for non-cash payout';
        return '';
    }

    private function _validateStatutoryPayload(array $p): string
    {
        if (empty($p['period_label'])) return 'missing period_label';
        if (empty($p['account_code'])) return 'missing account_code';
        if (!isset($p['amount']) || (float) $p['amount'] <= 0) return 'amount must be > 0';
        $allowed = [
            self::ACCT_PF_PAYABLE, self::ACCT_ESI_PAYABLE, self::ACCT_TDS_PAYABLE,
            self::ACCT_PT_PAYABLE, self::ACCT_OTHER_DED_PAYABLE,
        ];
        if (!in_array((string) $p['account_code'], $allowed, true)) {
            return "account_code must be one of " . implode(',', $allowed);
        }
        $mode = strtolower((string) ($p['mode'] ?? ''));
        if (!in_array($mode, ['cash', 'bank', 'cheque', 'upi', 'neft', 'rtgs', 'online'], true)) {
            return "invalid mode '{$mode}'";
        }
        if ($mode !== 'cash' && empty($p['bank_code'])) return 'bank_code required for non-cash deposit';
        return '';
    }

    private function _resolveSalaryAccount(string $roleClass): string
    {
        $rc = strtolower(trim($roleClass));
        if ($rc === 'teaching') return self::ACCT_SALARY_TEACHING;
        // non_teaching, admin, support, and unknown → 5020 by convention.
        return self::ACCT_SALARY_NON_TEACHING;
    }

    private function _resolveCashOrBank(string $mode, string $bankCode): string
    {
        if ($mode === 'cash') return self::ACCT_CASH;
        return $bankCode !== '' ? $bankCode : self::ACCT_BANK_DEFAULT;
    }

    private function _accrualSourceRef(string $period, string $employeeId): string
    {
        return "{$period}_{$employeeId}";
    }

    private function _payoutSourceRef(string $period, string $employeeId): string
    {
        return "{$period}_{$employeeId}";
    }

    private function _statSourceRef(string $period, string $acctCode): string
    {
        return "{$period}_{$acctCode}";
    }

    private function _reversalSourceRef(string $originalEntryId): string
    {
        return $originalEntryId;
    }

    private function _line(string $accountCode, float $dr, float $cr, string $narration): array
    {
        return [
            'account_code' => $accountCode,
            'dr'           => round($dr, 2),
            'cr'           => round($cr, 2),
            'narration'    => $narration,
        ];
    }

    private function _log(string $msg): void
    {
        log_message('error', $msg);
    }
}
