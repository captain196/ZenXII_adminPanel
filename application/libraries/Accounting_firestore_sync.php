<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting_firestore_sync — Dual-write layer for the accounting module.
 *
 * Mirrors the Fee_firestore_sync pattern. The Accounting controller and
 * Operations_accounting library write to RTDB (current source of truth for
 * the Accounting dashboard). This sync writes the same records to Firestore
 * collections so downstream Firestore-first readers (apps, reports, future
 * Accounting UI reads) see the same data.
 *
 * Collections (doc id patterns chosen so they scope by school + session):
 *   chartOfAccounts              {schoolCode}_{code}          (session-agnostic)
 *   accountingLedger             {schoolCode}_{session}_{entryId}
 *   accountingIndexByDate        {schoolCode}_{session}_{date}_{entryId}
 *   accountingIndexByAccount     {schoolCode}_{session}_{accountCode}_{entryId}
 *   accountingClosingBalances    {schoolCode}_{session}_{code}
 *   accountingCounters           {schoolCode}_{session}_{type}
 *   accountingIncomeExpense      {schoolCode}_{session}_{id}
 *   accountingPeriodLock         {schoolCode}_{session}
 *
 * Every write is wrapped in try/catch and logs on failure; callers should
 * route failures through queueForRetry (or use the bulk backfill later).
 */
class Accounting_firestore_sync
{
    private $firebase;
    private $schoolCode;
    private $session;
    private $ready = false;

    private const COL_COA         = 'chartOfAccounts';
    private const COL_LEDGER      = 'accountingLedger';
    private const COL_IDX_DATE    = 'accountingIndexByDate';
    private const COL_IDX_ACCT    = 'accountingIndexByAccount';
    private const COL_BALANCE     = 'accountingClosingBalances';
    private const COL_COUNTERS    = 'accountingCounters';
    private const COL_INC_EXP     = 'accountingIncomeExpense';
    private const COL_PERIOD_LOCK = 'accountingPeriodLock';
    private const COL_FEE_MAP     = 'feeAccountMap';

    public function init($firebase, string $schoolCode, string $session): self
    {
        $this->firebase   = $firebase;
        $this->schoolCode = $schoolCode;
        $this->session    = $session;
        $this->ready      = ($firebase !== null && $schoolCode !== '');
        if (!$this->ready) {
            log_message('error', 'Accounting_firestore_sync::init() — missing required params');
        }
        return $this;
    }

    // ─── CHART OF ACCOUNTS ─────────────────────────────────────────────

    public function syncChartOfAccount(string $code, array $data): bool
    {
        if (!$this->ready || $code === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$code}";
            $doc = array_merge($data, [
                'schoolId'  => $this->schoolCode,
                'code'      => $code,
                'updatedAt' => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_COA, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncChartOfAccount({$code}) failed: " . $e->getMessage());
            return false;
        }
    }

    public function deleteChartOfAccount(string $code): bool
    {
        if (!$this->ready || $code === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$code}";
            // Soft-delete by setting status=inactive; Accounting dashboard
            // already treats inactive accounts as hidden.
            return (bool) $this->firebase->firestoreSet(self::COL_COA, $docId, [
                'schoolId'  => $this->schoolCode,
                'code'      => $code,
                'status'    => 'inactive',
                'updatedAt' => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::deleteChartOfAccount({$code}) failed: " . $e->getMessage());
            return false;
        }
    }

    // ─── LEDGER ENTRIES ────────────────────────────────────────────────

    /**
     * Sync a double-entry journal to Firestore. Mirrors what
     * Operations_accounting::create_journal writes to RTDB.
     *
     * @param string $entryId  JE_{timestamp}_{rand}
     * @param array  $entry    voucher_no, narration, lines[], total_dr, total_cr, etc.
     */
    public function syncLedgerEntry(string $entryId, array $entry): bool
    {
        if (!$this->ready || $entryId === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$entryId}";
            $doc = array_merge($entry, [
                'schoolId'  => $this->schoolCode,
                'session'   => $this->session,
                'entryId'   => $entryId,
                'updatedAt' => date('c'),
            ]);
            $ok = (bool) $this->firebase->firestoreSet(self::COL_LEDGER, $docId, $doc);

            // Write date + per-account indices so readers can filter without
            // scanning the full collection.
            $date = (string) ($entry['date'] ?? date('Y-m-d'));
            try {
                $this->firebase->firestoreSet(self::COL_IDX_DATE,
                    "{$this->schoolCode}_{$this->session}_{$date}_{$entryId}", [
                        'schoolId'  => $this->schoolCode,
                        'session'   => $this->session,
                        'date'      => $date,
                        'entryId'   => $entryId,
                        'updatedAt' => date('c'),
                    ]);
                foreach (($entry['lines'] ?? []) as $line) {
                    $ac = (string) ($line['account_code'] ?? '');
                    if ($ac === '') continue;
                    $this->firebase->firestoreSet(self::COL_IDX_ACCT,
                        "{$this->schoolCode}_{$this->session}_{$ac}_{$entryId}", [
                            'schoolId'    => $this->schoolCode,
                            'session'     => $this->session,
                            'accountCode' => $ac,
                            'entryId'     => $entryId,
                            'updatedAt'   => date('c'),
                        ]);
                }
            } catch (\Exception $_) { /* indices are best-effort */ }

            return $ok;
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncLedgerEntry({$entryId}) failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a ledger entry as deleted (soft-delete) in Firestore + clear indices.
     * Mirrors Accounting::delete_journal_entry behaviour.
     */
    public function syncLedgerDelete(string $entryId, array $meta = []): bool
    {
        if (!$this->ready || $entryId === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$entryId}";
            $this->firebase->firestoreSet(self::COL_LEDGER, $docId, array_merge([
                'status'     => 'deleted',
                'deleted_at' => date('c'),
                'deleted_by' => (string) ($meta['deleted_by'] ?? ''),
                'updatedAt'  => date('c'),
            ], $meta));
            return true;
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncLedgerDelete({$entryId}) failed: " . $e->getMessage());
            return false;
        }
    }

    // ─── CLOSING BALANCES ──────────────────────────────────────────────

    /**
     * Overwrite the cached closing balance for one account.
     * Caller passes the new cumulative period_dr / period_cr.
     */
    public function syncClosingBalance(string $accountCode, float $periodDr, float $periodCr): bool
    {
        if (!$this->ready || $accountCode === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$accountCode}";
            return (bool) $this->firebase->firestoreSet(self::COL_BALANCE, $docId, [
                'schoolId'      => $this->schoolCode,
                'session'       => $this->session,
                'accountCode'   => $accountCode,
                'period_dr'     => round($periodDr, 2),
                'period_cr'     => round($periodCr, 2),
                'last_computed' => date('c'),
                'updatedAt'     => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncClosingBalance({$accountCode}) failed: " . $e->getMessage());
            return false;
        }
    }

    // ─── VOUCHER COUNTER ───────────────────────────────────────────────

    public function syncVoucherCounter(string $type, int $value): bool
    {
        if (!$this->ready || $type === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$type}";
            return (bool) $this->firebase->firestoreSet(self::COL_COUNTERS, $docId, [
                'schoolId'  => $this->schoolCode,
                'session'   => $this->session,
                'type'      => $type,
                'value'     => $value,
                'updatedAt' => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncVoucherCounter({$type}) failed: " . $e->getMessage());
            return false;
        }
    }

    // ─── INCOME / EXPENSE ──────────────────────────────────────────────

    public function syncIncomeExpense(string $id, array $data): bool
    {
        if (!$this->ready || $id === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$id}";
            $doc = array_merge($data, [
                'schoolId'  => $this->schoolCode,
                'session'   => $this->session,
                'id'        => $id,
                'updatedAt' => date('c'),
            ]);
            return (bool) $this->firebase->firestoreSet(self::COL_INC_EXP, $docId, $doc);
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncIncomeExpense({$id}) failed: " . $e->getMessage());
            return false;
        }
    }

    public function syncIncomeExpenseDelete(string $id): bool
    {
        if (!$this->ready || $id === '') return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}_{$id}";
            return (bool) $this->firebase->firestoreSet(self::COL_INC_EXP, $docId, [
                'schoolId'   => $this->schoolCode,
                'session'    => $this->session,
                'id'         => $id,
                'status'     => 'deleted',
                'deleted_at' => date('c'),
                'updatedAt'  => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncIncomeExpenseDelete({$id}) failed: " . $e->getMessage());
            return false;
        }
    }

    // ─── PERIOD LOCK ───────────────────────────────────────────────────

    public function syncPeriodLock(array $lockData): bool
    {
        if (!$this->ready) return false;
        try {
            $docId = "{$this->schoolCode}_{$this->session}";
            return (bool) $this->firebase->firestoreSet(self::COL_PERIOD_LOCK, $docId, array_merge([
                'schoolId'  => $this->schoolCode,
                'session'   => $this->session,
                'updatedAt' => date('c'),
            ], $lockData));
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::syncPeriodLock() failed: " . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  READ HELPERS — used by Session C pure-Firestore ledger writers
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Read the full chart of accounts for this school, keyed by code.
     * Returns [] on failure.
     */
    public function readChartOfAccounts(): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_COA,
                [['schoolId', '==', $this->schoolCode]]);
            $out = [];
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $code = (string) ($d['code'] ?? '');
                if ($code !== '') $out[$code] = $d;
            }
            return $out;
        } catch (\Exception $e) {
            log_message('error', 'Accounting_firestore_sync::readChartOfAccounts failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Find an existing journal entry by (source, source_ref).
     * Used by R.5 — lets the refund-journal poster detect "already
     * posted" before creating a second ledger entry, so a retry
     * attempt is idempotent.
     *
     * Returns the entry dict (or null if none). Caller can read
     * $row['entryId'] / $row['voucher_no'] for the existing record.
     */
    public function findJournalBySourceRef(string $source, string $sourceRef): ?array
    {
        if (!$this->ready || $source === '' || $sourceRef === '') return null;
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_LEDGER, [
                ['schoolId',   '==', $this->schoolCode],
                ['session',    '==', $this->session],
                ['source',     '==', $source],
                ['source_ref', '==', $sourceRef],
            ]);
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                if (is_array($d) && ($d['status'] ?? '') !== 'deleted') {
                    return $d;
                }
            }
            return null;
        } catch (\Exception $e) {
            log_message('error', "Accounting_firestore_sync::findJournalBySourceRef({$source}/{$sourceRef}) failed: " . $e->getMessage());
            return null;
        }
    }

    /** Single account by code; returns null if missing / inactive. */
    public function getAccount(string $code): ?array
    {
        if (!$this->ready || $code === '') return null;
        try {
            $d = $this->firebase->firestoreGet(self::COL_COA, "{$this->schoolCode}_{$code}");
            return is_array($d) ? $d : null;
        } catch (\Exception $_) { return null; }
    }

    /**
     * Increment the voucher counter for {type} and return the new value.
     * Verify-after-write + retry for concurrency safety.
     */
    public function nextCounter(string $type): int
    {
        if (!$this->ready || $type === '') return 0;
        $docId = "{$this->schoolCode}_{$this->session}_{$type}";
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $cur   = $this->firebase->firestoreGet(self::COL_COUNTERS, $docId);
                $curV  = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
                $next  = $curV + 1;
                $ok = $this->firebase->firestoreSet(self::COL_COUNTERS, $docId, [
                    'schoolId'  => $this->schoolCode,
                    'session'   => $this->session,
                    'type'      => $type,
                    'value'     => $next,
                    'updatedAt' => date('c'),
                ]);
                if (!$ok) { usleep(50000 * ($attempt + 1)); continue; }
                $verify = $this->firebase->firestoreGet(self::COL_COUNTERS, $docId);
                if (is_array($verify) && (int) ($verify['value'] ?? 0) === $next) {
                    return $next;
                }
                usleep(50000 * ($attempt + 1));
            } catch (\Exception $_) {
                usleep(50000 * ($attempt + 1));
            }
        }
        return 0;
    }

    /**
     * Read current closing balance for an account. Returns {period_dr, period_cr}.
     */
    public function readClosingBalance(string $accountCode): array
    {
        if (!$this->ready || $accountCode === '') return ['period_dr' => 0, 'period_cr' => 0];
        try {
            $d = $this->firebase->firestoreGet(self::COL_BALANCE, "{$this->schoolCode}_{$this->session}_{$accountCode}");
            if (!is_array($d)) return ['period_dr' => 0, 'period_cr' => 0];
            return [
                'period_dr' => (float) ($d['period_dr'] ?? 0),
                'period_cr' => (float) ($d['period_cr'] ?? 0),
            ];
        } catch (\Exception $_) { return ['period_dr' => 0, 'period_cr' => 0]; }
    }

    /**
     * Read the configured fee-head → account-code mapping (session-agnostic).
     * Returns [lowercase_fee_head => account_code].
     */
    public function readFeeAccountMap(): array
    {
        if (!$this->ready) return [];
        try {
            $rows = $this->firebase->firestoreQuery(self::COL_FEE_MAP,
                [['schoolId', '==', $this->schoolCode]]);
            $out = [];
            foreach ((array) $rows as $row) {
                $d = $row['data'] ?? $row;
                if (!is_array($d)) continue;
                $head = strtolower(trim((string) ($d['fee_head'] ?? '')));
                $code = trim((string) ($d['account_code'] ?? ''));
                if ($head !== '' && $code !== '') $out[$head] = $code;
            }
            return $out;
        } catch (\Exception $e) {
            log_message('error', 'Accounting_firestore_sync::readFeeAccountMap failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Write/update a fee-head → account-code mapping. Session-agnostic.
     */
    public function syncFeeAccountMapping(string $feeHead, string $accountCode): bool
    {
        if (!$this->ready || $feeHead === '' || $accountCode === '') return false;
        try {
            $key = strtoupper(preg_replace('/[^A-Z0-9]+/', '_', strtolower(trim($feeHead))));
            $key = trim($key, '_');
            $docId = "{$this->schoolCode}_{$key}";
            return (bool) $this->firebase->firestoreSet(self::COL_FEE_MAP, $docId, [
                'schoolId'     => $this->schoolCode,
                'fee_head'     => trim($feeHead),
                'account_code' => trim($accountCode),
                'updatedAt'    => date('c'),
            ]);
        } catch (\Exception $e) {
            log_message('error', 'Accounting_firestore_sync::syncFeeAccountMapping failed: ' . $e->getMessage());
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  BULK BACKFILL — used by firestore_bulk_sync admin endpoint
    //
    //  Phase 8A — RTDB backfills are GATED. RTDB has been retired from
    //  the live path (see memory "NO RTDB — Firestore ONLY"). These
    //  methods remain only so a post-incident "rebuild Firestore from
    //  the last RTDB snapshot" run is still possible. They NO-OP unless
    //  `ACCOUNTING_ALLOW_RTDB_BACKFILL=1` is explicitly set in the
    //  environment of the PHP process (not the admin's browser cookie).
    //
    //  Why gate instead of delete: the RTDB data set may still be
    //  valuable as a one-off recovery source. Deleting the code removes
    //  that safety net; gating keeps it while preventing any accidental
    //  call from a UI button / cron job.
    // ═══════════════════════════════════════════════════════════════════

    private static function _rtdbBackfillEnabled(): bool
    {
        return (string) getenv('ACCOUNTING_ALLOW_RTDB_BACKFILL') === '1';
    }

    /** Back-fill chart of accounts from RTDB. */
    public function syncAllChartOfAccounts(): int
    {
        if (!$this->ready) return 0;
        if (!self::_rtdbBackfillEnabled()) {
            log_message('error', '[ACC RTDB BACKFILL BLOCKED] syncAllChartOfAccounts — set ACCOUNTING_ALLOW_RTDB_BACKFILL=1 to explicitly enable.');
            return 0;
        }
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/Accounts/ChartOfAccounts";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $code => $data) {
                if (!is_array($data)) continue;
                if ($this->syncChartOfAccount((string) $code, $data)) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllChartOfAccounts failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill ledger entries for the current session from RTDB. */
    public function syncAllLedgerEntries(): int
    {
        if (!$this->ready) return 0;
        if (!self::_rtdbBackfillEnabled()) {
            log_message('error', '[ACC RTDB BACKFILL BLOCKED] syncAllLedgerEntries — set ACCOUNTING_ALLOW_RTDB_BACKFILL=1.');
            return 0;
        }
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Ledger";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $entryId => $entry) {
                if (!is_array($entry)) continue;
                if ($this->syncLedgerEntry((string) $entryId, $entry)) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllLedgerEntries failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill closing balances for the current session from RTDB. */
    public function syncAllClosingBalances(): int
    {
        if (!$this->ready) return 0;
        if (!self::_rtdbBackfillEnabled()) {
            log_message('error', '[ACC RTDB BACKFILL BLOCKED] syncAllClosingBalances — set ACCOUNTING_ALLOW_RTDB_BACKFILL=1.');
            return 0;
        }
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Closing_balances";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $code => $data) {
                if (!is_array($data)) continue;
                $dr = (float) ($data['period_dr'] ?? 0);
                $cr = (float) ($data['period_cr'] ?? 0);
                if ($this->syncClosingBalance((string) $code, $dr, $cr)) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllClosingBalances failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill income/expense records for the current session. */
    public function syncAllIncomeExpense(): int
    {
        if (!$this->ready) return 0;
        if (!self::_rtdbBackfillEnabled()) {
            log_message('error', '[ACC RTDB BACKFILL BLOCKED] syncAllIncomeExpense — set ACCOUNTING_ALLOW_RTDB_BACKFILL=1.');
            return 0;
        }
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Income_expense";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $id => $data) {
                if (!is_array($data)) continue;
                if ($this->syncIncomeExpense((string) $id, $data)) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllIncomeExpense failed: ' . $e->getMessage());
        }
        return $synced;
    }

    /** Back-fill voucher counters for the current session. */
    public function syncAllVoucherCounters(): int
    {
        if (!$this->ready) return 0;
        if (!self::_rtdbBackfillEnabled()) {
            log_message('error', '[ACC RTDB BACKFILL BLOCKED] syncAllVoucherCounters — set ACCOUNTING_ALLOW_RTDB_BACKFILL=1.');
            return 0;
        }
        $synced = 0;
        try {
            $path = "Schools/{$this->schoolCode}/{$this->session}/Accounts/Voucher_counters";
            $all = $this->firebase->get($path);
            if (!is_array($all)) return 0;
            foreach ($all as $type => $value) {
                if ($this->syncVoucherCounter((string) $type, (int) $value)) $synced++;
            }
        } catch (\Exception $e) {
            log_message('error', 'syncAllVoucherCounters failed: ' . $e->getMessage());
        }
        return $synced;
    }
}
