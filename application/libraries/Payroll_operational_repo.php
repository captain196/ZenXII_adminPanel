<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payroll_operational_repo — Stage 4 operational metadata layer.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PURPOSE
 * ──────────────────────────────────────────────────────────────────────
 *  Persists operator-facing metadata for payroll events into three new
 *  Firestore collections:
 *
 *    payrollAccruals
 *    payrollPayouts
 *    payrollStatutoryDeposits
 *
 *  These collections are operational indexes / convenience metadata —
 *  NOT financial truth. The ledger (`accounting`) remains the source of
 *  truth for every financial statement, reconciliation, and audit. The
 *  operational records hold:
 *
 *    • payroll-domain context (employee_id, period_label, deductions
 *      breakdown, etc.) — useful for UIs / reports
 *    • a journal_entry_id back-pointer to the canonical ledger doc
 *    • a status field tracking the operational lifecycle
 *      (posted → reversed → superseded)
 *
 *  Loss or corruption of these collections has ZERO financial impact:
 *  the ledger is still correct, balances are still correct, the
 *  reconciler still converges. The operational collections can be
 *  regenerated from the ledger at any point if needed.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ARCHITECTURAL BOUNDARY
 * ──────────────────────────────────────────────────────────────────────
 *  This class:
 *    • writes ONLY to the three operational collections above
 *    • reads ONLY from those collections (and accepts journal_entry_id
 *      as input — does not query `accounting`)
 *    • NEVER writes to `accounting`, `accountingClosingBalances`,
 *      `accountingIdempotency`, `accountingForensics`, or any other
 *      financial collection
 *    • NEVER invokes Operations_accounting or PayrollAccountingService
 *
 *  All writes are best-effort: every method wraps Firestore calls in
 *  try/catch with structured error logging (PAYROLL_OPS_*). A write
 *  failure is logged but never throws — callers (PayrollAccountingService
 *  post-commit hook) must NOT roll back the financial commit on
 *  operational failure. The ledger entry that already landed remains
 *  authoritative; the operational record can be backfilled later via
 *  a regenerate-from-ledger flow (Stage 9 reconciler extension).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCY
 * ──────────────────────────────────────────────────────────────────────
 *  All writes use deterministic doc IDs derived from (schoolId, session,
 *  domain key). Re-running the same operational write produces an
 *  idempotent merge — same fields, same status, no duplicate docs.
 *  Combined with the service's deterministic journal entryIds, every
 *  payroll event has a 1:1 mapping between ledger doc and operational
 *  doc.
 *
 *    Accrual doc id:   {schoolFs}_{session}_acc_{period}_{employee}
 *    Payout doc id:    {schoolFs}_{session}_pay_{period}_{employee}
 *    Statutory doc id: {schoolFs}_{session}_stat_{period}_{account}
 */
final class Payroll_operational_repo
{
    private const COL_ACCRUAL = 'payrollAccruals';
    private const COL_PAYOUT  = 'payrollPayouts';
    private const COL_STAT    = 'payrollStatutoryDeposits';

    /** @var object|null */ private $firebase = null;
    private string $schoolFs = '';
    private string $session  = '';
    private bool   $ready    = false;

    public function init($firebase, string $schoolFs, string $session): self
    {
        $this->firebase = $firebase;
        $this->schoolFs = $schoolFs;
        $this->session  = $session;
        $this->ready    = ($firebase !== null && $schoolFs !== '' && $session !== '');
        return $this;
    }

    // ═════════════════════════════════════════════════════════════════
    //  WRITE PATHS
    // ═════════════════════════════════════════════════════════════════

    /**
     * Persist an accrual operational record. Deterministic doc id
     * (period + employee) + Firestore merge means re-running is a no-op.
     *
     * Returns true on success, false on failure (logged). NEVER throws.
     */
    public function recordAccrualPost(array $accrualPayload, string $entryId, string $voucherNo, string $postedBy): bool
    {
        if (!$this->ready) return false;
        $employeeId  = (string) ($accrualPayload['employee_id'] ?? '');
        $periodLabel = (string) ($accrualPayload['period_label'] ?? '');
        if ($employeeId === '' || $periodLabel === '') return false;

        $docId = $this->_accrualDocId($periodLabel, $employeeId);
        $doc = [
            'schoolId'       => $this->schoolFs,
            'session'        => $this->session,
            'period_label'   => $periodLabel,
            'period_start'   => (string) ($accrualPayload['period_start'] ?? ''),
            'period_end'     => (string) ($accrualPayload['period_end']   ?? ''),
            'employee_id'    => $employeeId,
            'employee_name'  => (string) ($accrualPayload['employee_name'] ?? ''),
            'role_class'     => (string) ($accrualPayload['role_class']    ?? ''),
            'gross_salary'   => round((float) ($accrualPayload['gross_salary']   ?? 0), 2),
            'net_take_home'  => round((float) ($accrualPayload['net_take_home']  ?? 0), 2),
            'deductions'     => is_array($accrualPayload['deductions'] ?? null) ? $accrualPayload['deductions'] : [],
            'employer_contributions' => is_array($accrualPayload['employer_contributions'] ?? null) ? $accrualPayload['employer_contributions'] : [],
            'journal_entry_id' => $entryId,
            'voucher_no'     => $voucherNo,
            'posted_at'      => date('c'),
            'posted_by'      => $postedBy !== '' ? $postedBy : 'SYSTEM_PAYROLL',
            'status'         => 'posted',
            'updated_at'     => date('c'),
        ];
        return $this->_safeSet(self::COL_ACCRUAL, $docId, $doc, 'accrual');
    }

    public function recordPayoutPost(array $payoutPayload, string $entryId, string $voucherNo, string $postedBy): bool
    {
        if (!$this->ready) return false;
        $employeeId  = (string) ($payoutPayload['employee_id'] ?? '');
        $periodLabel = (string) ($payoutPayload['period_label'] ?? '');
        if ($employeeId === '' || $periodLabel === '') return false;

        $docId = $this->_payoutDocId($periodLabel, $employeeId);
        $doc = [
            'schoolId'       => $this->schoolFs,
            'session'        => $this->session,
            'period_label'   => $periodLabel,
            'employee_id'    => $employeeId,
            'amount'         => round((float) ($payoutPayload['amount'] ?? 0), 2),
            'mode'           => (string) ($payoutPayload['mode'] ?? ''),
            'bank_code'      => (string) ($payoutPayload['bank_code'] ?? ''),
            'journal_entry_id' => $entryId,
            'voucher_no'     => $voucherNo,
            'posted_at'      => date('c'),
            'posted_by'      => $postedBy !== '' ? $postedBy : 'SYSTEM_PAYROLL',
            'status'         => 'posted',
            'updated_at'     => date('c'),
        ];
        return $this->_safeSet(self::COL_PAYOUT, $docId, $doc, 'payout');
    }

    public function recordStatutoryPost(array $statPayload, string $entryId, string $voucherNo, string $postedBy): bool
    {
        if (!$this->ready) return false;
        $periodLabel = (string) ($statPayload['period_label'] ?? '');
        $acctCode    = (string) ($statPayload['account_code'] ?? '');
        if ($periodLabel === '' || $acctCode === '') return false;

        $docId = $this->_statDocId($periodLabel, $acctCode);
        $doc = [
            'schoolId'       => $this->schoolFs,
            'session'        => $this->session,
            'period_label'   => $periodLabel,
            'account_code'   => $acctCode,
            'amount'         => round((float) ($statPayload['amount'] ?? 0), 2),
            'mode'           => (string) ($statPayload['mode'] ?? ''),
            'bank_code'      => (string) ($statPayload['bank_code'] ?? ''),
            'journal_entry_id' => $entryId,
            'voucher_no'     => $voucherNo,
            'posted_at'      => date('c'),
            'posted_by'      => $postedBy !== '' ? $postedBy : 'SYSTEM_PAYROLL',
            'status'         => 'posted',
            'updated_at'     => date('c'),
        ];
        return $this->_safeSet(self::COL_STAT, $docId, $doc, 'stat');
    }

    /**
     * Update an operational record to reflect a reversal — locates the
     * record by its original entryId, sets status='reversed', and
     * captures the reversal entryId + reason. Best-effort; if the
     * record can't be located the reversal still succeeded financially
     * (the ledger reflects truth).
     */
    public function recordReversal(string $originalEntryId, string $reversalEntryId, string $reason, string $postedBy): bool
    {
        if (!$this->ready || $originalEntryId === '' || $reversalEntryId === '') return false;

        $collections = [
            self::COL_ACCRUAL => 'accrual',
            self::COL_PAYOUT  => 'payout',
            self::COL_STAT    => 'stat',
        ];
        foreach ($collections as $col => $domain) {
            try {
                $rows = (array) $this->firebase->firestoreQuery($col, [
                    ['schoolId',         '==', $this->schoolFs],
                    ['session',          '==', $this->session],
                    ['journal_entry_id', '==', $originalEntryId],
                ], '', '', 1);
                if (!empty($rows)) {
                    $docId = (string) ($rows[0]['id'] ?? '');
                    if ($docId === '') continue;
                    $patch = [
                        'status'             => 'reversed',
                        'reversal_entry_id'  => $reversalEntryId,
                        'reversed_at'        => date('c'),
                        'reversed_by'        => $postedBy !== '' ? $postedBy : 'SYSTEM_PAYROLL',
                        'reversal_reason'    => $reason,
                        'updated_at'         => date('c'),
                    ];
                    $ok = $this->_safeSet($col, $docId, $patch, "{$domain}_reversal", true);
                    if ($ok) {
                        // 2026-05-10 fix — was logged at 'error' level which
                        // polluted error monitoring with non-error events.
                        // Successful reversal recording is informational.
                        log_message('info',
                            "PAYROLL_OPS_REVERSAL_RECORDED collection={$col} docId={$docId} "
                            . "originalEntryId={$originalEntryId} reversalEntryId={$reversalEntryId}");
                        return true;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error',
                    "PAYROLL_OPS_REVERSAL_LOOKUP_FAILED col={$col} originalEntryId={$originalEntryId} "
                    . "err=" . $e->getMessage());
            }
        }
        log_message('error',
            "PAYROLL_OPS_REVERSAL_NOT_FOUND originalEntryId={$originalEntryId} "
            . "reversalEntryId={$reversalEntryId} — financial reversal succeeded but no "
            . "operational record updated. Backfill via regenerate-from-ledger if needed.");
        return false;
    }

    // ═════════════════════════════════════════════════════════════════
    //  READ PATHS
    // ═════════════════════════════════════════════════════════════════

    public function getAccrual(string $periodLabel, string $employeeId): ?array
    {
        if (!$this->ready) return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_ACCRUAL, $this->_accrualDocId($periodLabel, $employeeId));
            return is_array($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_GET_ACCRUAL_FAILED period={$periodLabel} emp={$employeeId} err=" . $e->getMessage());
            return null;
        }
    }

    public function getPayout(string $periodLabel, string $employeeId): ?array
    {
        if (!$this->ready) return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_PAYOUT, $this->_payoutDocId($periodLabel, $employeeId));
            return is_array($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_GET_PAYOUT_FAILED period={$periodLabel} emp={$employeeId} err=" . $e->getMessage());
            return null;
        }
    }

    public function getStatutory(string $periodLabel, string $accountCode): ?array
    {
        if (!$this->ready) return null;
        try {
            $doc = $this->firebase->firestoreGet(self::COL_STAT, $this->_statDocId($periodLabel, $accountCode));
            return is_array($doc) ? $doc : null;
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_GET_STAT_FAILED period={$periodLabel} acct={$accountCode} err=" . $e->getMessage());
            return null;
        }
    }

    public function listAccrualsForPeriod(string $periodLabel, int $limit = 200): array
    {
        if (!$this->ready) return [];
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL_ACCRUAL, [
                ['schoolId',     '==', $this->schoolFs],
                ['session',      '==', $this->session],
                ['period_label', '==', $periodLabel],
            ], 'employee_id', 'ASC', $limit);
            return $this->_unwrapRows($rows);
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_LIST_ACCRUALS_FAILED period={$periodLabel} err=" . $e->getMessage());
            return [];
        }
    }

    public function listPayoutsForPeriod(string $periodLabel, int $limit = 200): array
    {
        if (!$this->ready) return [];
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL_PAYOUT, [
                ['schoolId',     '==', $this->schoolFs],
                ['session',      '==', $this->session],
                ['period_label', '==', $periodLabel],
            ], 'employee_id', 'ASC', $limit);
            return $this->_unwrapRows($rows);
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_LIST_PAYOUTS_FAILED period={$periodLabel} err=" . $e->getMessage());
            return [];
        }
    }

    public function listStatutoryForPeriod(string $periodLabel, int $limit = 50): array
    {
        if (!$this->ready) return [];
        try {
            $rows = (array) $this->firebase->firestoreQuery(self::COL_STAT, [
                ['schoolId',     '==', $this->schoolFs],
                ['session',      '==', $this->session],
                ['period_label', '==', $periodLabel],
            ], 'account_code', 'ASC', $limit);
            return $this->_unwrapRows($rows);
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_LIST_STAT_FAILED period={$periodLabel} err=" . $e->getMessage());
            return [];
        }
    }

    public function listEmployeeHistory(string $employeeId, int $limit = 24): array
    {
        if (!$this->ready) return [];
        try {
            $accruals = (array) $this->firebase->firestoreQuery(self::COL_ACCRUAL, [
                ['schoolId',    '==', $this->schoolFs],
                ['session',     '==', $this->session],
                ['employee_id', '==', $employeeId],
            ], 'period_label', 'DESC', $limit);
            $payouts  = (array) $this->firebase->firestoreQuery(self::COL_PAYOUT, [
                ['schoolId',    '==', $this->schoolFs],
                ['session',     '==', $this->session],
                ['employee_id', '==', $employeeId],
            ], 'period_label', 'DESC', $limit);
            return [
                'accruals' => $this->_unwrapRows($accruals),
                'payouts'  => $this->_unwrapRows($payouts),
            ];
        } catch (\Throwable $e) {
            log_message('error', "PAYROLL_OPS_EMP_HISTORY_FAILED emp={$employeeId} err=" . $e->getMessage());
            return ['accruals' => [], 'payouts' => []];
        }
    }

    // ═════════════════════════════════════════════════════════════════
    //  Internals
    // ═════════════════════════════════════════════════════════════════

    private function _accrualDocId(string $period, string $employeeId): string
    {
        return "{$this->schoolFs}_{$this->session}_acc_{$period}_{$employeeId}";
    }

    private function _payoutDocId(string $period, string $employeeId): string
    {
        return "{$this->schoolFs}_{$this->session}_pay_{$period}_{$employeeId}";
    }

    private function _statDocId(string $period, string $acctCode): string
    {
        return "{$this->schoolFs}_{$this->session}_stat_{$period}_{$acctCode}";
    }

    /**
     * Best-effort write. On failure: log + return false. NEVER throws.
     * `$merge=true` for partial-update patches; default false for full
     * writes (the deterministic doc id makes that safe — re-running a
     * full write replaces the same doc with the same content).
     */
    private function _safeSet(string $collection, string $docId, array $doc, string $domain, bool $merge = false): bool
    {
        try {
            $ok = (bool) $this->firebase->firestoreSet($collection, $docId, $doc, $merge);
            if (!$ok) {
                log_message('error',
                    "PAYROLL_OPS_WRITE_FAILED domain={$domain} col={$collection} docId={$docId} "
                    . "reason='firestoreSet returned false'");
            }
            return $ok;
        } catch (\Throwable $e) {
            log_message('error',
                "PAYROLL_OPS_WRITE_FAILED domain={$domain} col={$collection} docId={$docId} "
                . "err=" . $e->getMessage());
            return false;
        }
    }

    private function _unwrapRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $d = is_array($r['data'] ?? null) ? $r['data'] : [];
            if (!empty($r['id'])) $d['__doc_id'] = (string) $r['id'];
            $out[] = $d;
        }
        return $out;
    }
}
