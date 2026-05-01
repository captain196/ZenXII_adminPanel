<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Operations_accounting — Shared accounting & ID generation helpers
 *
 * Provides:
 *   - validate_accounts()      — verify CoA accounts exist and are active
 *   - create_journal()         — create a double-entry journal with indices + balances
 *   - next_id()                — sequential ID generator via Firebase counters
 *   - search_students()        — cached student search (file cache, 5-min TTL)
 *
 * Used by: Library, Inventory, Assets, Transport, Hostel controllers.
 * Eliminates code duplication across Operations sub-modules.
 *
 * Matches the journal format from Hr.php and Accounting.php:
 *   Accounts:  Schools/{school}/Accounts/ChartOfAccounts/{code}
 *   Ledger:    Schools/{school}/{year}/Accounts/Ledger/{entryId}
 *   Index:     Schools/{school}/{year}/Accounts/Ledger_index/by_date|by_account
 *   Balances:  Schools/{school}/{year}/Accounts/Closing_balances/{code}
 *   Counter:   Schools/{school}/{year}/Accounts/Voucher_counters/{type}
 */
class Operations_accounting
{
    /** @var object Firebase library instance */
    private $firebase;

    /** @var string School key (SCH_XXXXXX) */
    private $school_name;

    /** @var string Key for Users/Parents/ path — school_code for legacy, school_id for SCH_ schools */
    private $parent_db_key;

    /** @var string Session year (YYYY-YY) */
    private $session_year;

    /** @var string Admin ID */
    private $admin_id;

    /** @var object CI controller instance (for json_error) */
    private $CI;

    /**
     * Initialize with controller context.
     *
     * @param object $firebase       Firebase library instance
     * @param string $school_name    School key (SCH_XXXXXX)
     * @param string $session_year   Session year (e.g. 2025-26)
     * @param string $admin_id       Current admin ID
     * @param object $CI             Controller instance (must have json_error())
     * @param string $parent_db_key  Key for Users/Parents/ path (defaults to school_name)
     */
    public function init($firebase, string $school_name, string $session_year, string $admin_id, $CI, string $parent_db_key = ''): void
    {
        $this->firebase       = $firebase;
        $this->school_name    = $school_name;
        $this->parent_db_key  = $parent_db_key !== '' ? $parent_db_key : $school_name;
        $this->session_year   = $session_year;
        $this->admin_id       = $admin_id;
        $this->CI             = $CI;
    }

    // ====================================================================
    //  SEQUENTIAL ID GENERATION
    // ====================================================================

    /**
     * Generate a sequential ID from a Firebase counter.
     *
     * @param string $counterPath Full Firebase path to the counter node
     * @param string $prefix      ID prefix (e.g. 'BK', 'ISS', 'VH')
     * @param int    $pad         Zero-padding width (default 4 → BK0001)
     * @return string             Generated ID (e.g. BK0001)
     */
    public function next_id(string $counterPath, string $prefix, int $pad = 4): string
    {
        // Pure Firestore: use the counterPath as a Firestore collection key.
        // Convert RTDB path like "Schools/{s}/{y}/Library/Counters/book"
        // into a Firestore doc in `opsCounters` collection.
        $docId = preg_replace('/[\/\s]+/', '_', trim($counterPath, '/'));
        $col   = 'opsCounters';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $cur  = $this->firebase->firestoreGet($col, $docId);
            $curV = is_array($cur) ? (int) ($cur['value'] ?? 0) : 0;
            $next = $curV + 1;
            $this->firebase->firestoreSet($col, $docId, [
                'value'     => $next,
                'prefix'    => $prefix,
                'updatedAt' => date('c'),
            ]);
            $verify = $this->firebase->firestoreGet($col, $docId);
            if (is_array($verify) && (int) ($verify['value'] ?? 0) === $next) {
                return $prefix . str_pad($next, $pad, '0', STR_PAD_LEFT);
            }
            usleep(50000 * $attempt);
        }
        $fallback = (int) (microtime(true) * 1000) % 1000000;
        return $prefix . str_pad($fallback, $pad, '0', STR_PAD_LEFT) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    // ====================================================================
    //  CACHED STUDENT SEARCH
    // ====================================================================

    /**
     * Search students with file-cache backed lookup.
     *
     * Loads the full student list from Firebase once, caches a lightweight
     * index (id, name, class, section, user_id) for 5 minutes, and filters
     * in PHP. All Operations controllers share this single cache entry,
     * eliminating repeated full-tree downloads on every typeahead keystroke.
     *
     * @param string $query   Search term (min 2 chars, matched against name and id)
     * @param int    $limit   Max results to return (default 20)
     * @return array          Array of matching student records
     */
    public function search_students(string $query, int $limit = 20): array
    {
        $q = strtolower(trim($query));
        if (strlen($q) < 2) {
            $this->CI->json_error('Enter at least 2 characters.');
        }

        $dbKey = $this->parent_db_key;
        $cacheKey = 'ops_students_' . md5($dbKey);

        // Try file cache first (5-minute TTL)
        $CI =& get_instance();
        $CI->load->driver('cache', ['adapter' => 'file']);
        $index = $CI->cache->get($cacheKey);

        if ($index === false) {
            // Cache miss — load from Firestore students collection.
            $schoolFs = $this->school_name;
            $stuRows  = $this->firebase->firestoreQuery('students', [
                ['schoolId', '==', $schoolFs],
            ]);
            $index = [];
            foreach ((array) $stuRows as $r) {
                $s = $r['data'] ?? $r;
                if (!is_array($s)) continue;
                $index[] = [
                    'id'      => (string) ($s['studentId'] ?? $s['userId'] ?? ''),
                    'name'    => (string) ($s['name'] ?? $s['Name'] ?? ''),
                    'class'   => (string) ($s['className'] ?? ''),
                    'section' => (string) ($s['section'] ?? ''),
                    'user_id' => (string) ($s['studentId'] ?? $s['userId'] ?? ''),
                ];
            }
            // Cache for 5 minutes (300 seconds)
            $CI->cache->save($cacheKey, $index, 300);
        }

        // Filter cached index
        $results = [];
        foreach ($index as $s) {
            $nameMatch = strpos(strtolower($s['name']), $q) !== false;
            $idMatch   = strpos(strtolower($s['id']), $q) !== false;
            $uidMatch  = strpos(strtolower($s['user_id'] ?? ''), $q) !== false;
            if ($nameMatch || $idMatch || $uidMatch) {
                $results[] = $s;
                if (count($results) >= $limit) break;
            }
        }

        return $results;
    }

    // ====================================================================
    //  PAGINATION HELPER
    // ====================================================================

    /**
     * Apply pagination to an array and return paginated result with metadata.
     *
     * Backward-compatible: if no page param is provided, returns all data
     * with page=1 and total=count. Existing UIs that ignore pagination
     * fields continue to work unchanged.
     *
     * @param array  $list      Full list of records
     * @param string $dataKey   Response key name (e.g. 'books', 'items', 'assets')
     * @param int|null $page    Page number (null = return all)
     * @param int    $limit     Records per page (default 50, max 200)
     * @return array            ['dataKey' => [...], 'page' => int, 'limit' => int, 'total' => int]
     */
    public function paginate(array $list, string $dataKey, $page = null, int $limit = 50): array
    {
        $total = count($list);
        $limit = max(1, min(200, $limit));

        if ($page !== null) {
            $page = max(1, (int) $page);
            $list = array_slice($list, ($page - 1) * $limit, $limit);
        } else {
            $page = 1;
            $limit = $total;
        }

        return [
            $dataKey => array_values($list),
            'page'   => $page,
            'limit'  => $limit,
            'total'  => $total,
        ];
    }

    // ====================================================================
    //  ACCOUNT VALIDATION
    // ====================================================================

    /**
     * Validate that accounting accounts exist and are active.
     * Calls json_error() and exits if any are missing/inactive.
     *
     * Fetches ChartOfAccounts once and validates all codes from memory
     * instead of one Firebase read per code (N+1 fix).
     *
     * @param array $codes Array of account codes (e.g. ['1010', '4060'])
     */
    public function validate_accounts(array $codes): void
    {
        // Session C: Chart of Accounts lives in Firestore `chartOfAccounts`
        // keyed `{schoolId}_{code}`. Read per-code — avoids loading the full
        // chart just to check a couple of entries.
        $sync = $this->_acctFsSync();
        $missing = [];
        foreach ($codes as $code) {
            $acct = $sync ? $sync->getAccount((string) $code) : null;
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
                $missing[] = $code;
            }
        }
        if (!empty($missing)) {
            $this->CI->json_error(
                'Missing or inactive accounts: ' . implode(', ', $missing)
                . '. Set them up in Accounting first.'
            );
        }
    }

    // ====================================================================
    //  JOURNAL CREATION
    // ====================================================================

    /**
     * Create a journal entry compatible with the Accounting module.
     *
     * Writes:
     *   - Ledger entry at {year}/Accounts/Ledger/{entryId}
     *   - Date index at {year}/Accounts/Ledger_index/by_date/{date}/{entryId}
     *   - Account index at {year}/Accounts/Ledger_index/by_account/{code}/{entryId}
     *   - Closing balances at {year}/Accounts/Closing_balances/{code}
     *
     * @param string $narration  Human-readable description
     * @param array  $lines      Array of ['account_code'=>..., 'dr'=>..., 'cr'=>...]
     * @param string $source     Source module name (e.g. 'Library', 'Inventory', 'Assets')
     * @param string $sourceRef  Reference ID (e.g. fine ID, purchase ID)
     * @return string            The generated entry ID
     */
    public function create_journal(string $narration, array $lines, string $source = '', string $sourceRef = ''): string
    {
        // Phase 8B — v2 path: idempotency + period-lock + CAS-guarded
        // balance updates in a single atomic batch. Refund journals
        // transit through this method (create_refund_journal → here),
        // so converting create_journal inherits CAS protection for
        // refunds transparently.
        //
        // Gated on the ACCOUNTING_V2 flag — same rollout knob as the
        // fee journal. Legacy path remains the default.
        if ((string) getenv('ACCOUNTING_V2') === '1') {
            return $this->_create_journal_v2($narration, $lines, $source, $sourceRef);
        }

        // Session C: pure-Firestore journal creation. Ledger, indices, closing
        // balances and voucher counters all live in Firestore collections.
        // Zero RTDB calls in this method.

        if (count($lines) < 2) {
            $this->CI->json_error('Journal entry requires at least 2 line items.');
        }

        $sync = $this->_acctFsSync();
        if (!$sync) {
            $this->CI->json_error('Accounting service is not available. Please try again.');
        }

        // Load CoA once (for name resolution + group-account guard).
        $coa = $sync->readChartOfAccounts();

        $totalDr  = 0;
        $totalCr  = 0;
        $affected = [];
        foreach ($lines as &$ln) {
            $dr = round((float) ($ln['dr'] ?? 0), 2);
            $cr = round((float) ($ln['cr'] ?? 0), 2);
            $ln['dr'] = $dr;
            $ln['cr'] = $cr;
            $totalDr += $dr;
            $totalCr += $cr;

            $acCode = (string) ($ln['account_code'] ?? '');
            $acct   = $coa[$acCode] ?? null;
            $ln['account_name'] = is_array($acct) ? ($acct['name'] ?? $acCode) : $acCode;

            if (is_array($acct) && !empty($acct['is_group'])) {
                $this->CI->json_error("Account {$acCode} is a group account — cannot post directly.");
            }
            if ($acCode !== '') {
                $affected[$acCode] = [
                    'dr' => ($affected[$acCode]['dr'] ?? 0) + $dr,
                    'cr' => ($affected[$acCode]['cr'] ?? 0) + $cr,
                ];
            }
        }
        unset($ln);

        if (abs($totalDr - $totalCr) > 0.01) {
            $this->CI->json_error("Unbalanced journal: Debit ({$totalDr}) does not equal Credit ({$totalCr}).");
        }

        // Voucher counter (Firestore nextCounter with verify-after-write).
        $seq = $sync->nextCounter('Journal');
        if ($seq <= 0) {
            // Fallback ID so the journal isn't lost if the counter service hiccups.
            $seq = (int) (microtime(true) * 1000) % 1000000;
        }
        $voucherNo = 'JV-' . str_pad($seq, 6, '0', STR_PAD_LEFT);
        $entryId   = 'JE_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

        $entry = [
            'date'         => date('Y-m-d'),
            'voucher_no'   => $voucherNo,
            'voucher_type' => 'Journal',
            'narration'    => $narration,
            'lines'        => array_values($lines),
            'total_dr'     => round($totalDr, 2),
            'total_cr'     => round($totalCr, 2),
            'source'       => $source,
            'source_ref'   => $sourceRef ?: null,
            'is_finalized' => false,
            'status'       => 'active',
            'created_by'   => $this->admin_id,
            'created_at'   => date('c'),
        ];

        // Write to Firestore: ledger doc + per-date index + per-account indices.
        if (!$sync->syncLedgerEntry($entryId, $entry)) {
            $this->CI->json_error('Failed to record journal entry. Nothing was written.');
        }

        // Update closing balances. Read-modify-write per account; on mismatch,
        // a short backoff retry keeps concurrent writers consistent.
        foreach ($affected as $code => $amounts) {
            for ($retry = 0; $retry < 3; $retry++) {
                $cur = $sync->readClosingBalance((string) $code);
                $newDr = round($cur['period_dr'] + $amounts['dr'], 2);
                $newCr = round($cur['period_cr'] + $amounts['cr'], 2);
                if ($sync->syncClosingBalance((string) $code, $newDr, $newCr)) {
                    break;
                }
                usleep(50000 * ($retry + 1));
                if ($retry === 2) {
                    log_message('error', "Closing balance write failed for {$code} after 3 retries (journal {$entryId})");
                }
            }
        }

        return $entryId;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Phase 8B — CAS manual-journal (ACCOUNTING_V2 path).
    //
    //  Same invariants as _create_fee_journal_v2:
    //    • Exactly one ledger row per (idempKey). idempKey derived
    //      deterministically so a retry (browser re-submit, CLI recon,
    //      refund reversal replay) lands on the same slot.
    //    • Ledger + all affected closing-balance writes in ONE commit
    //      batch (atomic).
    //    • All balance writes carry `currentDocument.updateTime` CAS so
    //      concurrent posts can't silently overwrite each other.
    //    • Period-closed entries are rejected.
    //
    //  Callers (refund wrappers, admin UI) expect `returns string`.
    //  On permanent failure we call `$this->CI->json_error(...)` which
    //  halts the request — matches legacy behaviour so current callers
    //  don't need to change. A retry-loop CLI that can't tolerate
    //  json_error should call _create_fee_journal_v2 directly instead.
    // ════════════════════════════════════════════════════════════════════
    private function _create_journal_v2(string $narration, array $lines, string $source, string $sourceRef): string
    {
        // ── 1. Validation (same rules as legacy) ─────────────────────────
        if (count($lines) < 2) {
            $this->CI->json_error('Journal entry requires at least 2 line items.');
        }
        $sync = $this->_acctFsSync();
        if (!$sync) { $this->CI->json_error('Accounting service is not available. Please try again.'); }

        $CI =& get_instance();
        $CI->load->library('Accounting_cache',       null, 'acctCache');
        $CI->load->library('Accounting_idempotency', null, 'acctIdemp');
        $CI->load->library('Accounting_period_lock', null, 'acctLock');
        $CI->acctCache->init($this->school_name, $this->session_year);
        $CI->acctIdemp->init($this->firebase, $this->school_name, $this->session_year);
        $CI->acctLock ->init($this->firebase, $CI->acctCache, $this->school_name, $this->session_year);

        $coa = $CI->acctCache->remember('coa', 300, function () use ($sync) {
            return $sync->readChartOfAccounts();
        });

        // Normalise lines + dr/cr + per-account aggregation. Identical to
        // legacy path — copied verbatim so behaviour matches byte-for-byte.
        $totalDr = 0; $totalCr = 0; $affected = [];
        foreach ($lines as &$ln) {
            $dr = round((float) ($ln['dr'] ?? 0), 2);
            $cr = round((float) ($ln['cr'] ?? 0), 2);
            $ln['dr'] = $dr; $ln['cr'] = $cr;
            $totalDr += $dr; $totalCr += $cr;
            $acCode = (string) ($ln['account_code'] ?? '');
            $acct   = $coa[$acCode] ?? null;
            $ln['account_name'] = is_array($acct) ? ($acct['name'] ?? $acCode) : $acCode;
            if (is_array($acct) && !empty($acct['is_group'])) {
                $this->CI->json_error("Account {$acCode} is a group account — cannot post directly.");
            }
            if ($acCode !== '') {
                $affected[$acCode] = [
                    'dr' => ($affected[$acCode]['dr'] ?? 0) + $dr,
                    'cr' => ($affected[$acCode]['cr'] ?? 0) + $cr,
                ];
            }
        }
        unset($ln);
        if (abs($totalDr - $totalCr) > 0.01) {
            $this->CI->json_error("Unbalanced journal: Debit ({$totalDr}) does not equal Credit ({$totalCr}).");
        }

        $date = date('Y-m-d');

        // ── 2. Period-lock check ─────────────────────────────────────────
        // Phase 8C (R1) — bypass cache on the write path. Correctness
        // outweighs the 1-2s cost of an extra read. Dashboards/reports
        // still use the cached validate().
        $lockCheck = $CI->acctLock->forceValidate($date);
        if (!empty($lockCheck['locked'])) {
            log_message('error', "ACC_JOURNAL_PERIOD_LOCKED source={$source} ref={$sourceRef} date={$date} lockedUntil={$lockCheck['lockedUntil']}");
            $this->CI->json_error("Period is closed up to {$lockCheck['lockedUntil']}. Cannot post on {$date}.");
        }

        // ── 3. Derive idempotency key ────────────────────────────────────
        //  Deterministic when sourceRef is present (fee payment, refund
        //  reversal, gateway voucher). Hashed-payload fallback for plain
        //  manual admin journals — double-click / re-submit within the
        //  staleness window (120 s) collapses to the same slot.
        if ($sourceRef !== '' && $source !== '') {
            $sourcePrefix = strtoupper($source);
            $sourcePrefix = preg_replace('/[^A-Z0-9_]+/', '_', $sourcePrefix);
            $idempKey = "JE_{$sourcePrefix}_{$sourceRef}";
        } else {
            $sortedLines = array_map(function ($l) {
                return [
                    'c' => (string) ($l['account_code'] ?? ''),
                    'd' => round((float) ($l['dr'] ?? 0), 2),
                    'r' => round((float) ($l['cr'] ?? 0), 2),
                ];
            }, $lines);
            usort($sortedLines, fn($a, $b) => strcmp($a['c'], $b['c']));
            $idempKey = 'JE_MAN_' . md5(
                $this->school_name . '|' . $date . '|' .
                round($totalDr, 2) . '|' .
                json_encode($sortedLines) . '|' .
                $this->admin_id . '|Journal'
            );
        }

        // ── 4. Claim idempotency slot ────────────────────────────────────
        $claim = $CI->acctIdemp->claim($idempKey, $source ?: 'manual');
        if (!empty($claim['dedup'])) {
            log_message('debug', "[ACC v2] dedup hit — returning existing entry {$claim['entryId']} for idempKey={$idempKey}");
            return (string) $claim['entryId'];
        }
        if (!empty($claim['in_progress'])) {
            $ageSec = (int) ($claim['ageSec'] ?? 0);
            log_message('error', "ACC_JOURNAL_IN_PROGRESS idempKey={$idempKey} ageSec={$ageSec}");
            $this->CI->json_error('This journal is currently being posted. Please wait a moment and retry.');
        }
        if (isset($claim['error'])) {
            log_message('error', "ACC_IDEMP_ERROR idempKey={$idempKey} error=" . $claim['error']);
            $this->CI->json_error('Accounting idempotency service is unavailable. Please retry.');
        }

        // ── 5. Build entry + voucher + entryId ───────────────────────────
        $seq = $sync->nextCounter('Journal');
        if ($seq <= 0) $seq = (int) (microtime(true) * 1000) % 1000000;
        $voucherNo = 'JV-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        //  Deterministic entryId from the idempKey so dedup lookups are
        //  direct (no extra index). Not strictly required — entryId
        //  could be a random id — but keeping them aligned simplifies
        //  both reconciler and audit trails.
        $entryId     = $idempKey;
        $ledgerDocId = "{$this->school_name}_{$this->session_year}_{$entryId}";

        $entry = [
            'schoolId'     => $this->school_name,
            'session'      => $this->session_year,
            'entryId'      => $entryId,
            'date'         => $date,
            'voucher_no'   => $voucherNo,
            'voucher_type' => ($source === 'fee_refund') ? 'Refund' : 'Journal',
            'narration'    => $narration,
            'lines'        => array_values($lines),
            'total_dr'     => round($totalDr, 2),
            'total_cr'     => round($totalCr, 2),
            'source'       => $source,
            'source_ref'   => $sourceRef !== '' ? $sourceRef : null,
            'is_finalized' => false,
            'status'       => 'active',
            'created_by'   => $this->admin_id,
            'created_at'   => date('c'),
            'updatedAt'    => date('c'),
        ];

        // ── 6. CAS LOOP ──────────────────────────────────────────────────
        $MAX_ATTEMPTS = 5;
        $lastError    = '';
        for ($attempt = 1; $attempt <= $MAX_ATTEMPTS; $attempt++) {
            $balReqs = [];
            foreach (array_keys($affected) as $code) {
                $balReqs[(string) $code] = [
                    'collection' => 'accountingClosingBalances',
                    'docId'      => "{$this->school_name}_{$this->session_year}_{$code}",
                ];
            }
            $balDocs = (array) $this->firebase->firestoreGetParallel($balReqs);

            $ops = [];
            // 1. Ledger entry (exists:false — atomic dedup backstop even
            //    if idempotency slot was stale-overridden by a racer).
            $ops[] = [
                'op'           => 'create',
                'collection'   => 'accounting',
                'docId'        => $ledgerDocId,
                'data'         => $entry,
                'precondition' => ['exists' => false],
            ];
            // 2. Closing balances — CAS per account.
            foreach ($affected as $code => $amounts) {
                $cur   = is_array($balDocs[(string) $code] ?? null) ? $balDocs[(string) $code] : null;
                $curDr = $cur ? (float) ($cur['period_dr'] ?? 0) : 0.0;
                $curCr = $cur ? (float) ($cur['period_cr'] ?? 0) : 0.0;
                $newDr = round($curDr + (float) $amounts['dr'], 2);
                $newCr = round($curCr + (float) $amounts['cr'], 2);
                $op = [
                    'op'         => 'set',
                    'collection' => 'accountingClosingBalances',
                    'docId'      => "{$this->school_name}_{$this->session_year}_{$code}",
                    'data'       => [
                        'schoolId'      => $this->school_name,
                        'session'       => $this->session_year,
                        'accountCode'   => (string) $code,
                        'period_dr'     => $newDr,
                        'period_cr'     => $newCr,
                        'last_entry_id' => $entryId,
                        'last_computed' => date('c'),
                        'updatedAt'     => date('c'),
                    ],
                ];
                if ($cur === null) {
                    $op['precondition'] = ['exists' => false];
                } else {
                    $ut = (string) ($cur['__updateTime'] ?? '');
                    if ($ut !== '') $op['precondition'] = ['updateTime' => $ut];
                }
                $ops[] = $op;
            }

            $ok = (bool) $this->firebase->firestoreCommitBatch($ops);
            if ($ok) {
                $CI->acctIdemp->markSuccess($idempKey, $entryId);
                log_message('error', "ACC_JOURNAL_COMMITTED entryId={$entryId} source={$source} attempt={$attempt} ops=" . count($ops));
                return $entryId;
            }

            $lastError = "batch commit returned false on attempt {$attempt}";
            log_message('error', "ACC_JOURNAL_CAS_RETRY entryId={$entryId} attempt={$attempt}");
            if ($attempt < $MAX_ATTEMPTS) {
                $baseMs = min(2000, 100 * (1 << ($attempt - 1)));
                $jitter = random_int(0, 100);
                usleep(($baseMs + $jitter) * 1000);
            }
        }

        $CI->acctIdemp->markFailed($idempKey, $lastError);
        log_message('error', "ACC_JOURNAL_FAILED entryId={$entryId} attempts={$MAX_ATTEMPTS} error={$lastError}");
        $this->CI->json_error('Could not post journal after multiple attempts (concurrent update storm). Please retry.');
        // Unreachable — json_error halts — but keep the return for the
        // type-checker's peace of mind.
        return '';
    }

    /**
     * Public wrapper around Accounting_firestore_sync::findJournalBySourceRef.
     * Exists so Fee_refund_service::retryJournal can distinguish "already
     * posted" from "just posted" — the internal idempotency guard inside
     * create_refund_journal(_granular) hides that signal by returning the
     * same entryId shape in both cases, which made the retry toast say
     * "Journal posted successfully" even when no new entry was written.
     */
    public function findExistingRefundJournal(string $refundId): ?array
    {
        if ($refundId === '') return null;
        $sync = $this->_acctFsSync();
        if (!$sync) return null;
        return $sync->findJournalBySourceRef('fee_refund', $refundId);
    }

    /**
     * Lazy-load and return the Firestore sync library, or null if unavailable.
     * Keeps Operations_accounting non-fatal when sync isn't yet configured.
     */
    private function _acctFsSync()
    {
        static $cached = null;
        if ($cached !== null) return $cached ?: null;
        try {
            $CI =& get_instance();
            if (!isset($CI->acctFsSync)) {
                $CI->load->library('Accounting_firestore_sync', null, 'acctFsSync');
                $CI->acctFsSync->init($this->firebase, $this->school_name, $this->session_year);
            }
            $cached = $CI->acctFsSync;
        } catch (\Exception $e) {
            log_message('error', 'Operations_accounting::_acctFsSync init failed: ' . $e->getMessage());
            $cached = false;
        }
        return $cached ?: null;
    }

    // ====================================================================
    //  FEE JOURNAL ENTRY
    // ====================================================================

    /**
     * Create a journal entry for a fee payment.
     *
     * Single source of truth for fee accounting — called by Fees.php.
     * Payment mode selects correct debit account (cash or bank).
     * Errors are logged but never block fee submission (returns null).
     *
     * @param array $params Keys: school_name, session_year, date, amount, payment_mode,
     *                       bank_code, receipt_no, student_name, student_id, class, admin_id
     * @return string|null  Entry ID or null on failure
     */
    public function create_fee_journal(array $params): ?string
    {
        // Phase 8A — feature-flagged v2 path: idempotency gate + period-lock
        // guard + CAS batch commit. Default OFF for safety. Enable per-
        // tenant via the env flag once you've smoke-tested on staging.
        //
        // The v2 path produces EXACTLY the same ledger+balance state as
        // the legacy path on the happy path; it only differs on error
        // paths (safer dedup, atomic batch, period-lock enforcement).
        if ((string) getenv('ACCOUNTING_V2') === '1' || !empty($params['accounting_v2'])) {
            return $this->_create_fee_journal_v2($params);
        }

        // Session C: pure-Firestore fee journal. Dr Cash/Bank, Cr Fee Income.
        $date     = $params['date'] ?? date('Y-m-d');
        $amount   = round((float) ($params['amount'] ?? 0), 2);
        $payMode  = strtolower(trim($params['payment_mode'] ?? 'cash'));
        $bankCode = trim($params['bank_code'] ?? '');
        $receipt  = $params['receipt_no']   ?? '';
        $student  = $params['student_name'] ?? '';
        $stuId    = $params['student_id']   ?? '';
        $class    = $params['class']        ?? '';
        $adminId  = $params['admin_id'] ?? $this->admin_id;

        if ($amount <= 0) return null;

        $sync = $this->_acctFsSync();
        if (!$sync) return null;

        $coa = $sync->readChartOfAccounts();
        if (empty($coa)) return null; // Accounting not set up

        // Select cash/bank account based on payment mode.
        if ($bankCode !== '' && isset($coa[$bankCode])) {
            $cashBankCode = $bankCode;
        } elseif (in_array($payMode, ['bank', 'cheque', 'upi', 'neft', 'rtgs', 'online', 'bank_transfer'], true)) {
            $cashBankCode = '1010'; // safe fallback if no bank account configured
            foreach ($coa as $code => $acct) {
                if (!empty($acct['is_bank']) && ($acct['status'] ?? '') === 'active') {
                    $cashBankCode = $code;
                    break;
                }
            }
        } else {
            $cashBankCode = '1010'; // Cash in Hand
        }

        $feeIncomeCode = '4010'; // Tuition Fees

        $cashAcct = $coa[$cashBankCode]  ?? null;
        $feeAcct  = $coa[$feeIncomeCode] ?? null;
        if (!is_array($cashAcct) || ($cashAcct['status'] ?? '') !== 'active') {
            log_message('error', "Fee journal: cash/bank account {$cashBankCode} missing/inactive");
            return null;
        }
        if (!is_array($feeAcct) || ($feeAcct['status'] ?? '') !== 'active') {
            log_message('error', "Fee journal: fee income account {$feeIncomeCode} missing/inactive");
            return null;
        }

        $narration = "Fee payment: {$student} ({$stuId}) - {$class}" . ($receipt ? " Rcpt#{$receipt}" : '');

        // Phase 7G (H2) — if a deterministic entry id was supplied (e.g.
        // "JE_FEE_F12" from the async-queue worker), use it AND treat
        // an already-existing ledger doc as a successful no-op. This
        // makes journal creation safe to retry: the ledger entry doc
        // gets overwritten harmlessly via `firestoreSet`, but the
        // closing-balance updates below are read-modify-write and
        // double-count on a second call — we MUST short-circuit.
        $suppliedEntryId = (string) ($params['journal_entry_id'] ?? '');
        if ($suppliedEntryId !== '') {
            $entryId = $suppliedEntryId;
            // Existence check — the ledger collection uses
            // "{schoolCode}_{session}_{entryId}" as doc id (see
            // Accounting_firestore_sync::syncLedgerEntry).
            $existingDocId = "{$this->school_name}_{$this->session_year}_{$entryId}";
            $existing = $this->firebase->firestoreGet('accounting', $existingDocId);
            if (is_array($existing) && !empty($existing['entryId'])) {
                log_message('debug', "[FEE JOURNAL IDEMPOTENT] entry {$entryId} already present — skipping re-post");
                return $entryId;
            }
        } else {
            $entryId = 'FE_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        $seq = $sync->nextCounter('Fee');
        if ($seq <= 0) $seq = (int) (microtime(true) * 1000) % 1000000;
        $voucherNo = 'FV-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

        $lines = [
            ['account_code' => $cashBankCode,  'account_name' => $cashAcct['name'] ?? $cashBankCode,  'dr' => $amount, 'cr' => 0,       'narration' => $narration],
            ['account_code' => $feeIncomeCode, 'account_name' => $feeAcct['name']  ?? $feeIncomeCode, 'dr' => 0,       'cr' => $amount, 'narration' => $narration],
        ];

        $entry = [
            'date'         => $date,
            'voucher_no'   => $voucherNo,
            'voucher_type' => 'Fee',
            'narration'    => $narration,
            'lines'        => $lines,
            'total_dr'     => $amount,
            'total_cr'     => $amount,
            'source'       => 'fee_payment',
            'source_ref'   => $receipt,
            'is_finalized' => false,
            'status'       => 'active',
            'created_by'   => $adminId,
            'created_at'   => date('c'),
        ];

        if (!$sync->syncLedgerEntry($entryId, $entry)) return null;

        // Closing balances for Cash (Dr) and Fee Income (Cr).
        foreach ([[$cashBankCode, $amount, 0.0], [$feeIncomeCode, 0.0, $amount]] as [$ac, $dr, $cr]) {
            for ($retry = 0; $retry < 3; $retry++) {
                $cur = $sync->readClosingBalance((string) $ac);
                $newDr = round($cur['period_dr'] + $dr, 2);
                $newCr = round($cur['period_cr'] + $cr, 2);
                if ($sync->syncClosingBalance((string) $ac, $newDr, $newCr)) break;
                usleep(50000 * ($retry + 1));
                if ($retry === 2) {
                    log_message('error', "Fee closing balance write failed for {$ac} (entry {$entryId})");
                }
            }
        }

        return $entryId;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Phase 8A — CAS-based fee journal (ACCOUNTING_V2 path).
    //
    //  Strict invariants:
    //    • Exactly one ledger row per (schoolId, session, entryId), where
    //      entryId is deterministic on the caller's receipt key.
    //    • Ledger + closing balances + audit row land in ONE commitBatch.
    //    • Balance writes carry `currentDocument.updateTime` so concurrent
    //      posts cannot silently over-write each other.
    //    • Period-closed entries are rejected at the write-path.
    //    • Idempotent under retry — same caller + same receipt returns the
    //      already-committed entryId without re-posting.
    //
    //  Performance:
    //    • Two Firestore round-trips per attempt (parallel read + one
    //      commit batch). No sequential per-account writes.
    //    • Cache hit on CoA + period-lock avoids extra reads when warm.
    //
    //  Never throws — returns the entryId on success, null on permanent
    //  failure. Callers (FeeWorker) treat null as "retry later".
    // ════════════════════════════════════════════════════════════════════
    private function _create_fee_journal_v2(array $params): ?string
    {
        $CI =& get_instance();
        $date     = (string) ($params['date']         ?? date('Y-m-d'));
        $amount   = round((float) ($params['amount']   ?? 0), 2);
        $payMode  = strtolower(trim((string) ($params['payment_mode'] ?? 'cash')));
        $bankCode = trim((string) ($params['bank_code']    ?? ''));
        $receipt  = (string) ($params['receipt_no']   ?? '');
        $student  = (string) ($params['student_name'] ?? '');
        $stuId    = (string) ($params['student_id']   ?? '');
        $class    = (string) ($params['class']        ?? '');
        $adminId  = (string) ($params['admin_id']     ?? $this->admin_id);

        if ($amount <= 0 || $receipt === '') {
            log_message('error', "[ACC v2] rejected: amount={$amount} receipt='{$receipt}'");
            return null;
        }

        // ── Derive deterministic idempotency key + entryId ───────────────
        $suppliedEntryId = (string) ($params['journal_entry_id'] ?? '');
        $entryId  = $suppliedEntryId !== '' ? $suppliedEntryId : "JE_FEE_{$receipt}";
        $idempKey = $entryId;  // same key — one slot, one entry

        // ── Load v2 libraries ────────────────────────────────────────────
        $CI->load->library('Accounting_cache',       null, 'acctCache');
        $CI->load->library('Accounting_idempotency', null, 'acctIdemp');
        $CI->load->library('Accounting_period_lock', null, 'acctLock');
        $CI->acctCache->init($this->school_name, $this->session_year);
        $CI->acctIdemp->init($this->firebase,    $this->school_name, $this->session_year);
        $CI->acctLock ->init($this->firebase, $CI->acctCache, $this->school_name, $this->session_year);

        // ── Period-lock guard (cached 60 s) ──────────────────────────────
        // Phase 8C (R1) — bypass cache on the write path. Correctness
        // outweighs the 1-2s cost of an extra read. Dashboards/reports
        // still use the cached validate().
        $lockCheck = $CI->acctLock->forceValidate($date);
        if (!empty($lockCheck['locked'])) {
            log_message('error', "ACC_JOURNAL_PERIOD_LOCKED entryId={$entryId} date={$date} lockedUntil={$lockCheck['lockedUntil']}");
            return null;
        }

        // ── Idempotency claim ────────────────────────────────────────────
        $claim = $CI->acctIdemp->claim($idempKey, 'fee_payment');
        if (!empty($claim['dedup']))       return (string) $claim['entryId'];
        if (!empty($claim['in_progress'])) {
            log_message('error', "ACC_JOURNAL_IN_PROGRESS entryId={$entryId} ageSec=" . ($claim['ageSec'] ?? 0));
            return null;
        }
        if (isset($claim['error'])) {
            log_message('error', "ACC_IDEMP_ERROR entryId={$entryId} error=" . $claim['error']);
            return null;
        }

        // ── Build the entry: resolve accounts + narration ────────────────
        $sync = $this->_acctFsSync();
        if (!$sync) { $CI->acctIdemp->markFailed($idempKey, 'accounting sync unavailable'); return null; }
        $coa = $CI->acctCache->remember('coa', 300, function () use ($sync) {
            return $sync->readChartOfAccounts();
        });
        if (empty($coa)) { $CI->acctIdemp->markFailed($idempKey, 'CoA not set up'); return null; }

        if ($bankCode !== '' && isset($coa[$bankCode])) {
            $cashBankCode = $bankCode;
        } elseif (in_array($payMode, ['bank','cheque','upi','neft','rtgs','online','bank_transfer'], true)) {
            $cashBankCode = '1010';
            foreach ($coa as $code => $acct) {
                if (!empty($acct['is_bank']) && ($acct['status'] ?? '') === 'active') { $cashBankCode = $code; break; }
            }
        } else {
            $cashBankCode = '1010';
        }
        $feeIncomeCode = '4010';

        $cashAcct = $coa[$cashBankCode]  ?? null;
        $feeAcct  = $coa[$feeIncomeCode] ?? null;
        if (!is_array($cashAcct) || ($cashAcct['status'] ?? '') !== 'active'
         || !is_array($feeAcct)  || ($feeAcct['status']  ?? '') !== 'active') {
            $CI->acctIdemp->markFailed($idempKey, "account missing/inactive cash={$cashBankCode} fee={$feeIncomeCode}");
            return null;
        }

        $narration = "Fee payment: {$student} ({$stuId}) - {$class}" . ($receipt ? " Rcpt#{$receipt}" : '');
        $seq       = $sync->nextCounter('Fee');
        if ($seq <= 0) $seq = (int) (microtime(true) * 1000) % 1000000;
        $voucherNo = 'FV-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);

        $lines = [
            ['account_code' => $cashBankCode,  'account_name' => (string) ($cashAcct['name'] ?? $cashBankCode),
             'dr' => $amount, 'cr' => 0,       'narration' => $narration],
            ['account_code' => $feeIncomeCode, 'account_name' => (string) ($feeAcct['name']  ?? $feeIncomeCode),
             'dr' => 0,       'cr' => $amount, 'narration' => $narration],
        ];
        $ledgerDocId = "{$this->school_name}_{$this->session_year}_{$entryId}";
        $entry = [
            'schoolId'     => $this->school_name,
            'session'      => $this->session_year,
            'entryId'      => $entryId,
            'date'         => $date,
            'voucher_no'   => $voucherNo,
            'voucher_type' => 'Fee',
            'narration'    => $narration,
            'lines'        => $lines,
            'total_dr'     => $amount,
            'total_cr'     => $amount,
            'source'       => 'fee_payment',
            'source_ref'   => $receipt,
            'is_finalized' => false,
            'status'       => 'active',
            'created_by'   => $adminId,
            'created_at'   => date('c'),
            'updatedAt'    => date('c'),
        ];
        $balanceDeltas = [
            $cashBankCode  => ['dr' => $amount, 'cr' => 0],
            $feeIncomeCode => ['dr' => 0,       'cr' => $amount],
        ];

        // ── CAS loop: read balances → build batch → commit-or-retry ──────
        $MAX_ATTEMPTS = 5;
        $lastError    = '';
        for ($attempt = 1; $attempt <= $MAX_ATTEMPTS; $attempt++) {
            $balReqs = [];
            foreach (array_keys($balanceDeltas) as $ac) {
                $balReqs[$ac] = [
                    'collection' => 'accountingClosingBalances',
                    'docId'      => "{$this->school_name}_{$this->session_year}_{$ac}",
                ];
            }
            $balDocs = (array) $this->firebase->firestoreGetParallel($balReqs); // 1 RTT

            $ops = [];
            // 1. Ledger entry — dedup guard: exists:false (idempotency slot
            //    already vetted duplicates; if this still races, caller
            //    must retry via idempotency path, not here).
            $ops[] = [
                'op'           => 'create',
                'collection'   => 'accounting',
                'docId'        => $ledgerDocId,
                'data'         => $entry,
                'precondition' => ['exists' => false],
            ];
            // 2. Closing balances — CAS per doc.
            foreach ($balanceDeltas as $ac => $delta) {
                $cur        = is_array($balDocs[$ac] ?? null) ? $balDocs[$ac] : null;
                $curDr      = $cur ? (float) ($cur['period_dr'] ?? 0) : 0.0;
                $curCr      = $cur ? (float) ($cur['period_cr'] ?? 0) : 0.0;
                $newDr      = round($curDr + (float) $delta['dr'], 2);
                $newCr      = round($curCr + (float) $delta['cr'], 2);
                $balDocId   = "{$this->school_name}_{$this->session_year}_{$ac}";
                $op = [
                    'op'         => 'set',
                    'collection' => 'accountingClosingBalances',
                    'docId'      => $balDocId,
                    'data'       => [
                        'schoolId'      => $this->school_name,
                        'session'       => $this->session_year,
                        'accountCode'   => $ac,
                        'period_dr'     => $newDr,
                        'period_cr'     => $newCr,
                        'last_entry_id' => $entryId,
                        'last_computed' => date('c'),
                        'updatedAt'     => date('c'),
                    ],
                ];
                if ($cur === null) {
                    $op['precondition'] = ['exists' => false];
                } else {
                    $ut = (string) ($cur['__updateTime'] ?? '');
                    if ($ut !== '') $op['precondition'] = ['updateTime' => $ut];
                    // If updateTime is missing for some reason, proceed
                    // without precondition rather than silently failing —
                    // the CAS retry + idempotency gate still covers us.
                }
                $ops[] = $op;
            }

            // 3. Atomic commit.
            $ok = (bool) $this->firebase->firestoreCommitBatch($ops);
            if ($ok) {
                $CI->acctIdemp->markSuccess($idempKey, $entryId);
                log_message('error', "ACC_JOURNAL_COMMITTED entryId={$entryId} attempt={$attempt} ops=" . count($ops));
                return $entryId;
            }

            // 4. Commit failed — most likely one of the balance updateTime
            //    preconditions raced. Re-read + retry with backoff.
            $lastError = "batch commit returned false on attempt {$attempt}";
            log_message('error', "ACC_JOURNAL_CAS_RETRY entryId={$entryId} attempt={$attempt}");
            if ($attempt < $MAX_ATTEMPTS) {
                // Exponential backoff with jitter — two colliding writers
                // must not retry in lockstep.
                $baseMs = min(2000, 100 * (1 << ($attempt - 1)));
                $jitter = random_int(0, 100);
                usleep(($baseMs + $jitter) * 1000);
            }
        }

        // All CAS attempts exhausted. Mark failed so operator sees it.
        // Reconciliation cron will surface fee-receipt-without-journal
        // drift if this entry truly never lands.
        $CI->acctIdemp->markFailed($idempKey, $lastError);
        log_message('error', "ACC_JOURNAL_FAILED entryId={$entryId} attempts={$MAX_ATTEMPTS} error={$lastError}");
        return null;
    }

    // ════════════════════════════════════════════════════════════════════
    //  FEE HEAD → ACCOUNT CODE MAPPING
    // ════════════════════════════════════════════════════════════════════

    /**
     * Load the fee head → account code mapping from Firebase config.
     *
     * Path: Schools/{school}/Accounts/Fee_Account_Map/{sanitized_head}
     *       = { fee_head: "Tuition Fee", account_code: "4010" }
     *
     * If no config exists, uses intelligent keyword-based defaults:
     *   Tuition/School Fee → 4010 (Tuition Fees)
     *   Admission/Registration → 4020 (Admission Fees)
     *   Exam/Test → 4030 (Exam Fees)
     *   Transport/Bus → 4040 (Transport Fees)
     *   Hostel/Boarding → 4050 (Hostel Fees)
     *   Late/Fine/Penalty → 4060 (Late Fees/Fines)
     *   Lab/Computer/Library → 4010 (mapped to Tuition as sub-head)
     *   Sport/Game → 4010
     *   Everything else → 4010 (safest default: Tuition Fees)
     *
     * @param array $coa  Full Chart of Accounts (already fetched)
     * @return array       [lowercase_fee_head => account_code]
     */
    public function get_fee_account_map(array $coa): array
    {
        $sync = $this->_acctFsSync();
        if (!$sync) return [];
        $configMap = $sync->readFeeAccountMap();
        $map = [];
        foreach ($configMap as $head => $code) {
            if ($code !== '' && isset($coa[$code]) && ($coa[$code]['status'] ?? '') === 'active') {
                $map[$head] = $code;
            }
        }
        return $map;
    }

    /**
     * Resolve the account code for a fee head.
     *
     * Priority: configured map → keyword matching → default 4010.
     *
     * @param string $feeHead     Fee head name (e.g. "Tuition Fee")
     * @param array  $configMap   Config map from get_fee_account_map()
     * @param array  $coa         Full Chart of Accounts
     * @return string             Account code (e.g. "4010")
     */
    public function resolve_fee_account(string $feeHead, array $configMap, array $coa): string
    {
        $headLower = strtolower(trim($feeHead));

        // 1. Exact match in config
        if (isset($configMap[$headLower])) {
            return $configMap[$headLower];
        }

        // 2. Keyword-based matching (common Indian school fee heads)
        $keywords = [
            '4020' => ['admission', 'registration', 'enrol'],
            '4030' => ['exam', 'test', 'assessment'],
            '4040' => ['transport', 'bus', 'conveyance', 'vehicle'],
            '4050' => ['hostel', 'boarding', 'mess', 'accommodation'],
            '4060' => ['late', 'fine', 'penalty', 'overdue'],
        ];

        foreach ($keywords as $code => $terms) {
            // Only use if account exists in CoA
            if (!isset($coa[$code]) || ($coa[$code]['status'] ?? '') !== 'active') continue;
            foreach ($terms as $term) {
                if (strpos($headLower, $term) !== false) {
                    return $code;
                }
            }
        }

        // 3. Default: Tuition Fees (4010)
        return '4010';
    }

    // ====================================================================
    //  GRANULAR FEE JOURNAL ENTRY (DEMAND-BASED)
    // ====================================================================

    /**
     * Create a multi-line journal entry using fee demand allocations.
     *
     * Instead of a simple Dr Cash / Cr 4010, this creates:
     *   Dr Cash/Bank ₹total
     *   Cr 4010 Tuition   ₹X   (per allocation's fee head → account mapping)
     *   Cr 4040 Transport ₹Y
     *   Cr 4060 Late Fee  ₹Z   (if fine > 0)
     *
     * Falls back to create_fee_journal() if allocations are empty.
     *
     * @param array $params Standard params (school_name, session_year, date, amount,
     *                      payment_mode, bank_code, receipt_no, student_name, student_id,
     *                      class, admin_id)
     * @param array $allocations Demand allocations from submit_fees():
     *                           [{demand_id, fee_head, category, period, amount, ...}, ...]
     * @param float $fineAmount  Fine/late fee collected (separate from allocations)
     * @param float $discountAmount  Discount applied (for contra entry)
     * @return string|null Entry ID or null on failure
     */
    public function create_fee_journal_granular(
        array $params,
        array $allocations,
        float $fineAmount = 0,
        float $discountAmount = 0
    ): ?string {
        // If no allocations, fall back to simple 2-line journal
        if (empty($allocations)) {
            return $this->create_fee_journal($params);
        }

        $date     = $params['date'] ?? date('Y-m-d');
        $amount   = round((float) ($params['amount'] ?? 0), 2);
        $payMode  = strtolower(trim($params['payment_mode'] ?? 'cash'));
        $bankCode = trim($params['bank_code'] ?? '');
        $receipt  = $params['receipt_no'] ?? '';
        $student  = $params['student_name'] ?? '';
        $stuId    = $params['student_id'] ?? '';
        $class    = $params['class'] ?? '';
        $adminId  = $params['admin_id'] ?? $this->admin_id;

        if ($amount <= 0) return null;

        // Pure Firestore: load CoA from Firestore
        $sync = $this->_acctFsSync();
        if (!$sync) return null;
        $coa = $sync->readChartOfAccounts();
        if (empty($coa)) return null;

        // ── Resolve cash/bank debit account ──
        if ($bankCode && isset($coa[$bankCode])) {
            $cashBankCode = $bankCode;
        } elseif (in_array($payMode, ['bank', 'cheque', 'upi', 'neft', 'rtgs', 'online'])) {
            $cashBankCode = '1010';
            foreach ($coa as $code => $acct) {
                if (!empty($acct['is_bank']) && ($acct['status'] ?? '') === 'active') {
                    $cashBankCode = $code;
                    break;
                }
            }
        } else {
            $cashBankCode = '1010'; // Cash in Hand
        }

        // Validate cash/bank account
        $cashAcct = $coa[$cashBankCode] ?? null;
        if (!is_array($cashAcct) || ($cashAcct['status'] ?? '') !== 'active') {
            log_message('error', "Granular fee journal: cash/bank account {$cashBankCode} missing/inactive");
            return null;
        }

        // ── Load fee head → account mapping ──
        $configMap = $this->get_fee_account_map($coa);

        // ── Build credit lines from allocations (grouped by account code) ──
        // Multiple fee heads may map to the same account code; aggregate them.
        $creditsByAccount = []; // account_code => { amount, heads[] }

        foreach ($allocations as $alloc) {
            $feeHead    = $alloc['fee_head'] ?? '';
            $allocAmt   = round((float) ($alloc['amount'] ?? 0), 2);
            if ($allocAmt <= 0 || $feeHead === '') continue;

            $acctCode = $this->resolve_fee_account($feeHead, $configMap, $coa);

            if (!isset($creditsByAccount[$acctCode])) {
                $creditsByAccount[$acctCode] = ['amount' => 0, 'heads' => []];
            }
            $creditsByAccount[$acctCode]['amount'] += $allocAmt;
            $creditsByAccount[$acctCode]['heads'][] = $feeHead;
        }

        // ── Add fine as separate credit line to Late Fee Income (4060) ──
        $fineAmount = round($fineAmount, 2);
        if ($fineAmount > 0) {
            $fineCode = '4060'; // Late Fees/Fines
            if (!isset($coa[$fineCode]) || ($coa[$fineCode]['status'] ?? '') !== 'active') {
                // Fall back to 4010 if 4060 doesn't exist
                $fineCode = '4010';
            }
            if (!isset($creditsByAccount[$fineCode])) {
                $creditsByAccount[$fineCode] = ['amount' => 0, 'heads' => []];
            }
            $creditsByAccount[$fineCode]['amount'] += $fineAmount;
            $creditsByAccount[$fineCode]['heads'][] = 'Late Fee';
        }

        // ── Validate all credit accounts exist ──
        $validCredits   = [];
        $fallbackAmount = 0; // amounts from invalid accounts fall back to 4010

        foreach ($creditsByAccount as $code => $data) {
            $acct = $coa[$code] ?? null;
            if (is_array($acct) && ($acct['status'] ?? '') === 'active' && empty($acct['is_group'])) {
                $validCredits[$code] = $data;
            } else {
                log_message('info', "Granular fee journal: account {$code} unavailable, amount " . $data['amount'] . " falls back to 4010");
                $fallbackAmount += $data['amount'];
            }
        }

        // Add fallback to 4010 if any accounts were invalid
        if ($fallbackAmount > 0) {
            if (!isset($validCredits['4010'])) {
                $validCredits['4010'] = ['amount' => 0, 'heads' => []];
            }
            $validCredits['4010']['amount'] += $fallbackAmount;
            $validCredits['4010']['heads'][] = '(fallback)';
        }

        // ── Safety: if no valid credits, fall back to simple journal ──
        if (empty($validCredits)) {
            log_message('error', "Granular fee journal: no valid credit accounts, falling back to simple");
            return $this->create_fee_journal($params);
        }

        // ══════════════════════════════════════════════════════════
        //  DISCOUNT ACCOUNTING (proper double-entry)
        //
        //  Gross fee income is credited at full value (allocations).
        //  Discount is recorded as a separate debit to an Expense account.
        //  This keeps income statements accurate and discount visible.
        //
        //  Journal structure when discount exists:
        //    Dr 1010 Cash/Bank       ₹net_received (amount)
        //    Dr 5190 Discount Allowed ₹discount
        //    Cr 4010 Tuition Fee      ₹gross_tuition
        //    Cr 4040 Transport Fee    ₹gross_transport
        //    Cr 4060 Late Fee         ₹fine
        //
        //  When no discount: Dr Cash = Cr total (simple).
        // ══════════════════════════════════════════════════════════

        $discountAmount = round($discountAmount, 2);
        $narration = "Fee payment: {$student} ({$stuId}) - {$class}" . ($receipt ? " Rcpt#{$receipt}" : '');
        $lines     = [];
        $affected  = [];

        // ── DEBIT SIDE ──

        // 1. Dr Cash/Bank = amount actually received
        $cashDebit = $amount;
        $lines[] = [
            'account_code' => $cashBankCode,
            'account_name' => $cashAcct['name'] ?? $cashBankCode,
            'dr'           => round($cashDebit, 2),
            'cr'           => 0,
            'narration'    => $narration,
        ];
        $affected[$cashBankCode] = ['dr' => round($cashDebit, 2), 'cr' => 0];

        // 2. Dr Discount Allowed (Expense) = discount amount
        //    Account 5190 "Discount Allowed" — create if needed via keyword match
        if ($discountAmount > 0) {
            $discountAcctCode = '5190'; // Discount Allowed (Expense)
            // Check if 5190 exists; if not, try to find any "discount" expense account
            if (!isset($coa[$discountAcctCode]) || ($coa[$discountAcctCode]['status'] ?? '') !== 'active') {
                $discountAcctCode = null;
                foreach ($coa as $dc => $da) {
                    if (!is_array($da)) continue;
                    if (($da['category'] ?? '') === 'Expense'
                        && ($da['status'] ?? '') === 'active'
                        && empty($da['is_group'])
                        && stripos($da['name'] ?? '', 'discount') !== false) {
                        $discountAcctCode = $dc;
                        break;
                    }
                }
            }

            if ($discountAcctCode !== null) {
                $discAcct = $coa[$discountAcctCode];
                $lines[] = [
                    'account_code' => $discountAcctCode,
                    'account_name' => $discAcct['name'] ?? 'Discount Allowed',
                    'dr'           => round($discountAmount, 2),
                    'cr'           => 0,
                    'narration'    => "Discount — {$student}",
                ];
                if (!isset($affected[$discountAcctCode])) {
                    $affected[$discountAcctCode] = ['dr' => 0, 'cr' => 0];
                }
                $affected[$discountAcctCode]['dr'] += round($discountAmount, 2);
            } else {
                // No discount account available — reduce income side instead (legacy behavior)
                log_message('info', "Granular journal: No discount expense account found, reducing income");
                $discountAmount = 0; // treat as if no discount for journal balancing
            }
        }

        // Total debit = cash + discount
        $totalDebit = round($cashDebit + $discountAmount, 2);

        // ── CREDIT SIDE ──
        // Credits should equal total debit (gross fee income)
        // Adjust credit totals to match debit side
        $totalCredits = 0;
        foreach ($validCredits as $data) {
            $totalCredits += $data['amount'];
        }

        // The allocations are net amounts (after discount). If discount is separately debited,
        // the credit side needs to represent gross income = allocations + discount.
        // Distribute discount proportionally across credit accounts to show gross income.
        if ($discountAmount > 0 && $totalCredits > 0) {
            foreach ($validCredits as $code => &$data) {
                $proportion = $data['amount'] / $totalCredits;
                $data['amount'] = round($data['amount'] + ($discountAmount * $proportion), 2);
            }
            unset($data);
            // Recalculate
            $totalCredits = 0;
            foreach ($validCredits as $data) {
                $totalCredits += $data['amount'];
            }
        }

        // Rounding adjustment: ensure Dr = Cr exactly
        $diff = round($totalDebit - $totalCredits, 2);
        if (abs($diff) > 0.005 && abs($diff) <= 1.00) {
            $maxCode = '';
            $maxAmt  = 0;
            foreach ($validCredits as $code => $data) {
                if ($data['amount'] > $maxAmt) { $maxAmt = $data['amount']; $maxCode = $code; }
            }
            if ($maxCode !== '') {
                $validCredits[$maxCode]['amount'] = round($validCredits[$maxCode]['amount'] + $diff, 2);
            }
        } elseif (abs($diff) > 1.00) {
            log_message('error', "Granular fee journal: debit/credit mismatch of {$diff}, falling back");
            return $this->create_fee_journal($params);
        }

        // Credit lines: one per fee income account
        foreach ($validCredits as $code => $data) {
            $creditAmt = round($data['amount'], 2);
            if ($creditAmt <= 0) continue;

            $headNames = implode(', ', array_unique($data['heads']));
            $acctName  = $coa[$code]['name'] ?? $code;

            $lines[] = [
                'account_code' => $code,
                'account_name' => $acctName,
                'dr'           => 0,
                'cr'           => $creditAmt,
                'narration'    => "{$headNames} — {$student}",
            ];

            if (!isset($affected[$code])) {
                $affected[$code] = ['dr' => 0, 'cr' => 0];
            }
            $affected[$code]['cr'] += $creditAmt;
        }

        // ── Final Dr = Cr check ──
        $totalDr = 0;
        $totalCr = 0;
        foreach ($lines as $ln) {
            $totalDr += $ln['dr'];
            $totalCr += $ln['cr'];
        }
        if (abs($totalDr - $totalCr) > 0.01) {
            log_message('error', "Granular fee journal: UNBALANCED Dr={$totalDr} Cr={$totalCr}, falling back");
            return $this->create_fee_journal($params);
        }

        // ── Generate voucher number (pure Firestore counter) ──
        $seq = $sync->nextCounter('Fee');
        if ($seq <= 0) $seq = (int) (microtime(true) * 1000) % 1000000;
        $voucherNo = 'FV-' . str_pad($seq, 6, '0', STR_PAD_LEFT);

        // ── Generate entry ID ──
        $entryId = 'FE_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $entry = [
            'date'         => $date,
            'voucher_no'   => $voucherNo,
            'voucher_type' => 'Fee',
            'narration'    => $narration,
            'lines'        => array_values($lines),
            'total_dr'     => round($totalDr, 2),
            'total_cr'     => round($totalCr, 2),
            'source'       => 'fee_payment',
            'source_ref'   => $receipt,
            'is_finalized' => false,
            'status'       => 'active',
            'created_by'   => $adminId,
            'created_at'   => date('c'),
        ];

        // ── Write ledger entry + indices (pure Firestore) ──
        if (!$sync->syncLedgerEntry($entryId, $entry)) return null;

        // ── Update closing balances (pure Firestore, per-account verify+retry) ──
        foreach ($affected as $ac => $amounts) {
            for ($retry = 0; $retry < 3; $retry++) {
                $cur   = $sync->readClosingBalance((string) $ac);
                $newDr = round($cur['period_dr'] + (float) $amounts['dr'], 2);
                $newCr = round($cur['period_cr'] + (float) $amounts['cr'], 2);
                if ($sync->syncClosingBalance((string) $ac, $newDr, $newCr)) break;
                usleep(50000 * ($retry + 1));
                if ($retry === 2) {
                    log_message('error', "Granular fee closing balance write failed for {$ac} (entry {$entryId})");
                }
            }
        }

        return $entryId;
    }

    // ====================================================================
    //  FEE ACCOUNT MAP MANAGEMENT
    // ====================================================================

    /**
     * Save a fee head → account code mapping.
     *
     * Called by admin to configure which fee heads map to which income accounts.
     * Path: Schools/{school}/Accounts/Fee_Account_Map/{sanitized_key}
     *
     * @param string $feeHead     Fee head name (e.g. "Tuition Fee")
     * @param string $accountCode Account code (e.g. "4010")
     */
    public function save_fee_account_mapping(string $feeHead, string $accountCode): void
    {
        $sync = $this->_acctFsSync();
        if ($sync) $sync->syncFeeAccountMapping($feeHead, $accountCode);
    }

    // ====================================================================
    //  REFUND JOURNAL ENTRY
    // ====================================================================

    /**
     * Create a journal entry for a fee refund.
     *
     * Reversal of fee payment: Dr Fee Income (4010), Cr Cash/Bank (1010).
     * Uses create_journal() for full validation (dr==cr, group guard, indices).
     *
     * @param array $params Keys: student_name, student_id, class, amount,
     *                       refund_mode, refund_id, receipt_no
     * @return string|null  Entry ID or null on failure
     */
    public function create_refund_journal(array $params): ?string
    {
        // Session C: pure-Firestore refund reversal. Dr Fee Income, Cr Cash/Bank.
        $amount     = round((float) ($params['amount'] ?? 0), 2);
        $refundMode = strtolower(trim($params['refund_mode'] ?? 'cash'));
        $student    = $params['student_name'] ?? '';
        $stuId      = $params['student_id']   ?? '';
        $class      = $params['class']        ?? '';
        $refId      = $params['refund_id']    ?? '';
        $origRcpt   = $params['receipt_no']   ?? '';

        if ($amount <= 0) return null;

        $sync = $this->_acctFsSync();
        if (!$sync) return null;

        // R.5: idempotency guard. If a journal for this refund was already
        // posted (e.g. prior attempt succeeded but the refund-doc write
        // failed, or admin is retrying via retry_refund_journal), skip
        // creating a duplicate ledger entry and return the existing ID.
        if ($refId !== '') {
            $existing = $sync->findJournalBySourceRef('fee_refund', $refId);
            if (is_array($existing) && !empty($existing['entryId'])) {
                log_message('debug', "create_refund_journal: idempotent — existing journal {$existing['entryId']} found for refund={$refId}");
                return (string) $existing['entryId'];
            }
        }

        $coa = $sync->readChartOfAccounts();
        if (empty($coa)) return null;

        // Pick cash/bank account based on refund mode.
        if (in_array($refundMode, ['bank_transfer', 'cheque', 'online', 'upi', 'neft'], true)) {
            $cashBankCode = '1010';
            foreach ($coa as $code => $acct) {
                if (!empty($acct['is_bank']) && ($acct['status'] ?? '') === 'active') {
                    $cashBankCode = $code;
                    break;
                }
            }
        } else {
            $cashBankCode = '1010';
        }

        $feeIncomeCode = '4010';
        $cashAcct = $coa[$cashBankCode]  ?? null;
        $feeAcct  = $coa[$feeIncomeCode] ?? null;
        if (!is_array($cashAcct) || ($cashAcct['status'] ?? '') !== 'active') {
            log_message('error', "Refund journal: cash/bank account {$cashBankCode} missing/inactive");
            return null;
        }
        if (!is_array($feeAcct) || ($feeAcct['status'] ?? '') !== 'active') {
            log_message('error', "Refund journal: fee income account {$feeIncomeCode} missing/inactive");
            return null;
        }

        $narration = "Fee refund: {$student} ({$stuId}) - {$class}"
            . ($origRcpt ? " OrigRcpt#{$origRcpt}" : '')
            . " Ref#{$refId}";

        $lines = [
            ['account_code' => $feeIncomeCode, 'dr' => $amount, 'cr' => 0],
            ['account_code' => $cashBankCode,  'dr' => 0,       'cr' => $amount],
        ];

        try {
            return $this->create_journal($narration, $lines, 'fee_refund', $refId);
        } catch (\Exception $e) {
            log_message('error', 'create_refund_journal failed: ' . $e->getMessage());
            return null;
        }
    }

    // ====================================================================
    //  GRANULAR REFUND JOURNAL (DEMAND-BASED)
    // ====================================================================

    /**
     * Create a multi-line refund reversal journal using receipt allocations.
     *
     * Reverses the original fee journal:
     *   Dr 4010 Tuition Fee     ₹X   (reverse each income credit)
     *   Dr 4040 Transport Fee   ₹Y
     *   Cr 1010 Cash/Bank       ₹total (refund paid out)
     *
     * Falls back to create_refund_journal() if allocations empty.
     *
     * @param array $params     Standard refund params
     * @param array $allocations Original receipt allocations to reverse
     * @return string|null      Entry ID or null on failure
     */
    public function create_refund_journal_granular(array $params, array $allocations): ?string
    {
        if (empty($allocations)) {
            return $this->create_refund_journal($params);
        }

        $amount     = round((float) ($params['amount'] ?? 0), 2);
        $refundMode = strtolower(trim($params['refund_mode'] ?? 'cash'));
        $student    = $params['student_name'] ?? '';
        $stuId      = $params['student_id'] ?? '';
        $class      = $params['class'] ?? '';
        $refId      = $params['refund_id'] ?? '';
        $origRcpt   = $params['receipt_no'] ?? '';

        if ($amount <= 0) return null;

        // Pure Firestore CoA fetch
        $sync = $this->_acctFsSync();
        if (!$sync) return null;

        // R.5: idempotency guard (see create_refund_journal for rationale).
        if ($refId !== '') {
            $existing = $sync->findJournalBySourceRef('fee_refund', $refId);
            if (is_array($existing) && !empty($existing['entryId'])) {
                log_message('debug', "create_refund_journal_granular: idempotent — existing journal {$existing['entryId']} found for refund={$refId}");
                return (string) $existing['entryId'];
            }
        }

        $coa = $sync->readChartOfAccounts();
        if (empty($coa)) return null;

        // Resolve cash/bank account
        if (in_array($refundMode, ['bank_transfer', 'cheque', 'online', 'upi', 'neft'])) {
            $cashBankCode = '1010';
            foreach ($coa as $code => $acct) {
                if (!empty($acct['is_bank']) && ($acct['status'] ?? '') === 'active') {
                    $cashBankCode = $code;
                    break;
                }
            }
        } else {
            $cashBankCode = '1010';
        }

        $configMap = $this->get_fee_account_map($coa);
        $narration = "Fee refund: {$student} ({$stuId}) - {$class}"
            . ($origRcpt ? " OrigRcpt#{$origRcpt}" : '')
            . " Ref#{$refId}";

        // Build debit lines (reverse income accounts) grouped by account code
        $debitsByAccount = [];
        foreach ($allocations as $alloc) {
            $feeHead  = $alloc['fee_head'] ?? '';
            $allocAmt = round((float) ($alloc['amount'] ?? 0), 2);
            if ($allocAmt <= 0 || $feeHead === '') continue;

            $acctCode = $this->resolve_fee_account($feeHead, $configMap, $coa);
            if (!isset($debitsByAccount[$acctCode])) {
                $debitsByAccount[$acctCode] = ['amount' => 0, 'heads' => []];
            }
            $debitsByAccount[$acctCode]['amount'] += $allocAmt;
            $debitsByAccount[$acctCode]['heads'][] = $feeHead;
        }

        // Build journal lines
        $lines = [];

        // Dr lines: reverse fee income per account
        $totalDr = 0;
        foreach ($debitsByAccount as $code => $data) {
            $drAmt = round($data['amount'], 2);
            if ($drAmt <= 0) continue;
            $acct = $coa[$code] ?? null;
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') {
                $code = '4010'; // fallback
                $acct = $coa['4010'] ?? null;
            }
            $headNames = implode(', ', array_unique($data['heads']));
            $lines[] = [
                'account_code' => $code,
                'account_name' => is_array($acct) ? ($acct['name'] ?? $code) : $code,
                'dr'           => $drAmt,
                'cr'           => 0,
                'narration'    => "Refund: {$headNames} — {$student}",
            ];
            $totalDr += $drAmt;
        }

        // Rounding: ensure total Dr = refund amount
        $diff = round($amount - $totalDr, 2);
        if (abs($diff) > 0.005 && abs($diff) <= 1.00 && !empty($lines)) {
            $lines[0]['dr'] = round($lines[0]['dr'] + $diff, 2);
            $totalDr = $amount;
        } elseif (abs($diff) > 1.00) {
            return $this->create_refund_journal($params);
        }

        // Cr line: Cash/Bank (refund paid out)
        $lines[] = [
            'account_code' => $cashBankCode,
            'account_name' => $coa[$cashBankCode]['name'] ?? $cashBankCode,
            'dr'           => 0,
            'cr'           => round($amount, 2),
            'narration'    => $narration,
        ];

        try {
            return $this->create_journal($narration, $lines, 'fee_refund', $refId);
        } catch (\Exception $e) {
            log_message('error', 'create_refund_journal_granular failed: ' . $e->getMessage());
            return null;
        }
    }
}
