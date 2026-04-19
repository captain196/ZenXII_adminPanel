<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Accounting System Controller
 *
 * Complete double-entry accounting for multi-school SaaS:
 *   Tab 1: Chart of Accounts (hierarchical, coded, 5 categories)
 *   Tab 2: Journal Entries / Ledger (double-entry, immutable after lock)
 *   Tab 3: Income & Expense Tracker
 *   Tab 4: Cash Book (enhanced)
 *   Tab 5: Bank Reconciliation
 *   Tab 6: Financial Reports (Trial Balance, P&L, Balance Sheet, Cash Flow)
 *   Tab 7: Settings & Period Lock
 *
 * Firebase paths:
 *   Schools/{school}/Accounts/ChartOfAccounts/{code}        (year-independent)
 *   Schools/{school}/{year}/Accounts/Ledger/{entry_id}
 *   Schools/{school}/{year}/Accounts/Ledger_index/by_date/{date}/{id}
 *   Schools/{school}/{year}/Accounts/Ledger_index/by_account/{code}/{id}
 *   Schools/{school}/{year}/Accounts/Income_expense/{id}
 *   Schools/{school}/{year}/Accounts/Bank_recon/{code}/{id}
 *   Schools/{school}/{year}/Accounts/Closing_balances/{code}
 *   Schools/{school}/{year}/Accounts/Voucher_counters/{type}
 *   Schools/{school}/{year}/Accounts/Period_lock
 *   Schools/{school}/{year}/Accounts/Audit_log/{log_id}
 */
class Accounting extends MY_Controller
{
    // =========================================================================
    //  ROLE CONSTANTS
    // =========================================================================

    private const ADMIN_ROLES   = ['Admin', 'Super Admin', 'School Super Admin', 'Our Panel', 'Principal'];
    private const FINANCE_ROLES = ['Admin', 'Super Admin', 'School Super Admin', 'Our Panel', 'Accountant', 'Finance', 'Principal', 'Vice Principal'];

    public function __construct()
    {
        parent::__construct();
        require_permission('Accounting');

        // Phase 4.1 — Firestore dual-write layer. Lazy-initialised and
        // shared with Operations_accounting so every accounting mutation
        // lands in Firestore alongside RTDB without caller-side boilerplate.
        $this->load->library('Accounting_firestore_sync', null, 'acctFsSync');
        $this->acctFsSync->init($this->firebase, $this->school_name, $this->session_year);
    }

    // =========================================================================
    //  PATH HELPERS
    // =========================================================================

    /** Year-scoped base: Schools/{school}/{year} */
    private function _bp(): string
    {
        return "Schools/{$this->school_name}/{$this->session_year}";
    }

    /** Chart of Accounts (year-independent) */
    private function _coa(): string
    {
        return "Schools/{$this->school_name}/Accounts/ChartOfAccounts";
    }

    /** Ledger path */
    private function _ledger(): string
    {
        return $this->_bp() . '/Accounts/Ledger';
    }

    /** Ledger index path */
    private function _idx(): string
    {
        return $this->_bp() . '/Accounts/Ledger_index';
    }

    /** Closing balances cache */
    private function _bal(): string
    {
        return $this->_bp() . '/Accounts/Closing_balances';
    }

    /** Income/Expense path */
    private function _ie_path(): string
    {
        return $this->_bp() . '/Accounts/Income_expense';
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FIRESTORE ACCOUNTING HELPERS — replace all RTDB paths above.
    //  Collections: accounting (ledger), chartOfAccounts, incomeExpense,
    //  bankRecon, accountingAudit, accountingConfig (locks/counters).
    //  Doc IDs prefixed with schoolId via fs->docId().
    // ═══════════════════════════════════════════════════════════════════

    /** Get all Chart of Accounts for this school. Returns [code => data]. */
    private function _fs_coa_all(): array
    {
        try {
            $docs = $this->fs->schoolWhere('chartOfAccounts', []);
            $result = [];
            $prefix = $this->school_id . '_';
            foreach ($docs as $d) {
                $r = is_array($d['data'] ?? null) ? $d['data'] : $d;
                $rawId = (string) ($d['id'] ?? '');
                $code = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                if ($code !== '') $result[$code] = $r;
            }
            return $result;
        } catch (\Exception $e) {
            log_message('error', 'Acct _fs_coa_all failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Get one CoA entry. */
    private function _fs_coa_get(string $code): ?array
    {
        try {
            $d = $this->fs->getEntity('chartOfAccounts', $code);
            return (is_array($d) && !empty($d)) ? $d : null;
        } catch (\Exception $e) { return null; }
    }

    /** Write one CoA entry. */
    private function _fs_coa_set(string $code, array $data): void
    {
        try { $this->fs->setEntity('chartOfAccounts', $code, $data); }
        catch (\Exception $e) { log_message('error', "Acct _fs_coa_set {$code} failed: " . $e->getMessage()); }
    }

    /** Delete one CoA entry. */
    private function _fs_coa_delete(string $code): void
    {
        try { $this->fs->removeEntity('chartOfAccounts', $code); }
        catch (\Exception $e) { log_message('error', "Acct _fs_coa_delete {$code} failed: " . $e->getMessage()); }
    }

    /** Get all ledger entries. Returns [entryId => data]. */
    private function _fs_ledger_all(): array
    {
        try {
            $docs = $this->fs->schoolWhere('accounting', []);
            $result = [];
            $prefix = $this->school_id . '_';
            foreach ($docs as $d) {
                $r = is_array($d['data'] ?? null) ? $d['data'] : $d;
                $rawId = (string) ($d['id'] ?? '');
                $id = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                // Skip non-ledger docs (BAL_, IDX_, etc.)
                if (strpos($id, 'JE_') === 0 || strpos($id, 'JV') === 0) {
                    $result[$id] = $r;
                }
            }
            return $result;
        } catch (\Exception $e) {
            log_message('error', 'Acct _fs_ledger_all failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Get one ledger entry. */
    private function _fs_ledger_get(string $entryId): ?array
    {
        try {
            $d = $this->firebase->firestoreGet('accounting', $this->fs->docId($entryId));
            return (is_array($d) && !empty($d)) ? $d : null;
        } catch (\Exception $e) { return null; }
    }

    /** Write one ledger entry. */
    private function _fs_ledger_set(string $entryId, array $data): void
    {
        $data['schoolId'] = $this->school_id;
        $data['session'] = $this->session_year;
        try { $this->firebase->firestoreSet('accounting', $this->fs->docId($entryId), $data, true); }
        catch (\Exception $e) { log_message('error', "Acct _fs_ledger_set {$entryId} failed: " . $e->getMessage()); }
    }

    /** Update fields on a ledger entry. */
    private function _fs_ledger_update(string $entryId, array $patch): void
    {
        try { $this->firebase->firestoreSet('accounting', $this->fs->docId($entryId), $patch, true); }
        catch (\Exception $e) { log_message('error', "Acct _fs_ledger_update {$entryId} failed: " . $e->getMessage()); }
    }

    /** Delete a ledger entry (soft-delete). */
    private function _fs_ledger_delete(string $entryId): void
    {
        $this->_fs_ledger_update($entryId, ['status' => 'deleted', 'deleted_at' => date('c')]);
    }

    /** Set a ledger index entry. */
    private function _fs_idx_set(string $indexKey, $value = true): void
    {
        try { $this->firebase->firestoreSet('accounting', $this->fs->docId($indexKey), ['schoolId' => $this->school_id, 'session' => $this->session_year, 'type' => 'index', 'value' => $value], true); }
        catch (\Exception $e) {}
    }

    /** Delete a ledger index entry. */
    private function _fs_idx_delete(string $indexKey): void
    {
        try { $this->firebase->firestoreDelete('accounting', $this->fs->docId($indexKey)); }
        catch (\Exception $e) {}
    }

    /** Get/set closing balance. */
    private function _fs_bal_get(string $code): ?array
    {
        try {
            $d = $this->firebase->firestoreGet('accounting', $this->fs->docId("BAL_{$this->session_year}_{$code}"));
            return is_array($d) ? $d : null;
        } catch (\Exception $e) { return null; }
    }
    private function _fs_bal_set(string $code, array $data): void
    {
        $data['type'] = 'closing_balance';
        $data['accountCode'] = $code;
        $data['schoolId'] = $this->school_id;
        $data['session'] = $this->session_year;
        $data['last_computed'] = date('c');
        try { $this->firebase->firestoreSet('accounting', $this->fs->docId("BAL_{$this->session_year}_{$code}"), $data, true); }
        catch (\Exception $e) { log_message('error', "Acct _fs_bal_set {$code} failed: " . $e->getMessage()); }
    }
    private function _fs_bal_all(): array
    {
        try {
            $docs = $this->fs->schoolWhere('accounting', []);
            $result = [];
            $prefix = $this->school_id . '_BAL_' . $this->session_year . '_';
            foreach ($docs as $d) {
                $rawId = (string) ($d['id'] ?? '');
                if (strpos($rawId, $prefix) !== 0) continue;
                $code = substr($rawId, strlen($prefix));
                $r = is_array($d['data'] ?? null) ? $d['data'] : $d;
                $result[$code] = $r;
            }
            return $result;
        } catch (\Exception $e) { return []; }
    }

    /** Voucher counter — flat key on school profile doc. */
    private function _fs_counter_get(string $type): int
    {
        $key = "acctCounters.{$type}";
        try {
            $doc = $this->fs->get('schools', $this->fs->docId('profile'));
            return (is_array($doc) && isset($doc[$key]) && is_numeric($doc[$key])) ? (int) $doc[$key] : 0;
        } catch (\Exception $e) { return 0; }
    }
    private function _fs_counter_set(string $type, int $val): void
    {
        try { $this->fs->update('schools', $this->fs->docId('profile'), ["acctCounters.{$type}" => $val]); }
        catch (\Exception $e) { log_message('error', "Acct counter set {$type} failed: " . $e->getMessage()); }
    }

    /** Audit log write. */
    private function _fs_audit_log(string $logId, array $data): void
    {
        $data['schoolId'] = $this->school_id;
        $data['session'] = $this->session_year;
        try { $this->fs->setEntity('accountingAudit', $logId, $data); }
        catch (\Exception $e) { log_message('error', "Acct audit log failed: " . $e->getMessage()); }
    }

    /** Period lock get/set. */
    private function _fs_lock_get(): ?array
    {
        try { return $this->fs->getEntity('accountingConfig', 'period_lock'); } catch (\Exception $e) { return null; }
    }
    private function _fs_lock_set(array $data): void
    {
        try { $this->fs->setEntity('accountingConfig', 'period_lock', $data); } catch (\Exception $e) {}
    }

    /** Income/Expense record helpers. */
    private function _fs_ie_all(): array
    {
        try {
            $docs = $this->fs->schoolWhere('incomeExpense', []);
            $result = [];
            $prefix = $this->school_id . '_';
            foreach ($docs as $d) {
                $r = is_array($d['data'] ?? null) ? $d['data'] : $d;
                $rawId = (string) ($d['id'] ?? '');
                $id = (strpos($rawId, $prefix) === 0) ? substr($rawId, strlen($prefix)) : $rawId;
                if ($id !== '') $result[$id] = $r;
            }
            return $result;
        } catch (\Exception $e) { return []; }
    }
    private function _fs_ie_get(string $id): ?array
    {
        try { $d = $this->fs->getEntity('incomeExpense', $id); return (is_array($d) && !empty($d)) ? $d : null; }
        catch (\Exception $e) { return null; }
    }
    private function _fs_ie_set(string $id, array $data): void
    {
        try { $this->fs->setEntity('incomeExpense', $id, $data); }
        catch (\Exception $e) { log_message('error', "Acct IE set {$id} failed: " . $e->getMessage()); }
    }
    private function _fs_ie_update(string $id, array $patch): void
    {
        try { $this->fs->updateEntity('incomeExpense', $id, $patch); }
        catch (\Exception $e) { log_message('error', "Acct IE update {$id} failed: " . $e->getMessage()); }
    }

    // =========================================================================
    //  PRIVATE SECURITY & AUDIT HELPERS
    // =========================================================================

    // _require_role() inherited from MY_Controller

    /**
     * Write an audit trail entry for every write operation.
     */
    private function _audit(string $action, string $entityType, string $entityId, $oldValue = null, $newValue = null): void
    {
        $logId = 'AL_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
        $this->_fs_audit_log($logId, [
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'admin_id'    => $this->admin_id,
            'admin_name'  => $this->admin_name,
            'timestamp'   => date('c'),
            'ip'          => $this->input->ip_address(),
            'old_value'   => $oldValue,
            'new_value'   => $newValue,
        ]);
    }

    /**
     * Check if a date falls within a locked period. Sends JSON error if locked.
     */
    private function _check_period_lock(string $date): void
    {
        $lock = $this->_fs_lock_get();
        if (is_array($lock) && !empty($lock['locked_until']) && $date <= $lock['locked_until']) {
            $this->json_error("Period locked until {$lock['locked_until']}. Cannot modify entries on or before that date.");
        }
    }

    /**
     * Generate a unique entry ID with microsecond component.
     */
    private function _generate_entry_id(string $prefix = 'JE'): string
    {
        return $prefix . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    // =========================================================================
    //  PAGE LOAD
    // =========================================================================

    public function index()
    {
        $seg = $this->uri->segment(2);
        $validTabs = ['chart','ledger','income-expense','cash-book','bank-recon','reports','settings'];
        $data = [
            'active_tab' => in_array($seg, $validTabs, true) ? $seg : 'chart',
        ];
        $this->load->view('include/header', $data);
        $this->load->view('accounting/index', $data);
        $this->load->view('include/footer');
    }

    // =========================================================================
    //  TAB 1: CHART OF ACCOUNTS
    // =========================================================================

    /** GET: Fetch full Chart of Accounts */
    public function get_chart()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $all = $this->_fs_coa_all();
        if (!is_array($all)) $all = [];

        // Auto-seed on first access if chart is empty (Admin/Principal only)
        if (empty($all) && in_array($this->admin_role, self::ADMIN_ROLES, true)) {
            $ts       = date('c');
            $defaults = $this->_default_coa_template($ts);
            foreach ($defaults as $code => $acct) {
                $this->_fs_coa_set($code, $acct);
            }
            $all = $defaults;
            log_message('info',
                "get_chart: auto-seeded " . count($defaults) . " accounts on first access"
                . " school=[{$this->school_name}] admin=[{$this->admin_id}]"
            );
        }

        // Merge closing balances (period dr/cr from journal entries)
        $closingBals = $this->_fs_bal_all();
        if (is_array($closingBals)) {
            foreach ($all as $code => &$acct) {
                if (!is_array($acct)) continue;
                $cb = $closingBals[$code] ?? null;
                $periodDr = (float) ($cb['period_dr'] ?? 0);
                $periodCr = (float) ($cb['period_cr'] ?? 0);
                $openBal  = (float) ($acct['opening_balance'] ?? 0);

                // Current balance = opening + net movement (Dr increases Assets/Expenses, Cr increases Liabilities/Income/Equity)
                $normalSide = $acct['normal_side'] ?? 'Dr';
                if ($normalSide === 'Dr') {
                    $currentBal = $openBal + $periodDr - $periodCr;
                } else {
                    $currentBal = $openBal + $periodCr - $periodDr;
                }

                $acct['period_dr']       = round($periodDr, 2);
                $acct['period_cr']       = round($periodCr, 2);
                $acct['current_balance'] = round($currentBal, 2);
            }
            unset($acct);
        }

        // Sort by code
        uksort($all, 'strnatcmp');

        $this->json_success(['accounts' => $all]);
    }

    /** POST: Create or update an account */
    public function save_account()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code        = $this->safe_path_segment(trim((string) $this->input->post('code')), 'code');
        $name        = trim((string) $this->input->post('name'));
        $category    = trim((string) $this->input->post('category'));
        $subCategory = trim((string) $this->input->post('sub_category'));
        $parentCode  = trim((string) $this->input->post('parent_code'));
        $isGroup     = $this->input->post('is_group') === 'true';
        $isBank      = $this->input->post('is_bank') === 'true';
        $description = trim((string) $this->input->post('description'));
        $openBal     = (float) $this->input->post('opening_balance');
        $isEdit      = $this->input->post('is_edit') === 'true';

        if (!$code || !$name || !$category) {
            return $this->json_error('Code, name, and category are required.');
        }

        // Validate code format: 4-6 digits
        if (!preg_match('/^\d{4,6}$/', $code)) {
            return $this->json_error('Account code must be 4-6 digits.');
        }

        $validCats = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
        if (!in_array($category, $validCats, true)) {
            return $this->json_error('Invalid category.');
        }

        // Check code uniqueness on create
        if (!$isEdit) {
            $existing = $this->_fs_coa_get($code);
            if ($existing) {
                return $this->json_error("Account code {$code} already exists.");
            }
        }

        // Normal side: Assets & Expenses = Dr, others = Cr
        $normalSide = in_array($category, ['Asset', 'Expense']) ? 'Dr' : 'Cr';

        $data = [
            'code'            => $code,
            'name'            => $name,
            'category'        => $category,
            'sub_category'    => $subCategory ?: $category,
            'parent_code'     => $parentCode ?: null,
            'is_group'        => $isGroup,
            'is_bank'         => $isBank,
            'normal_side'     => $normalSide,
            'description'     => $description,
            'opening_balance' => $openBal,
            'status'          => 'active',
            'is_system'       => false,
            'sort_order'      => (int) $code,
            'updated_at'      => date('c'),
        ];

        if ($isBank) {
            $data['bank_details'] = [
                'bank_name'  => trim((string) $this->input->post('bank_name')),
                'branch'     => trim((string) $this->input->post('branch')),
                'account_no' => trim((string) $this->input->post('account_no')),
                'ifsc'       => trim((string) $this->input->post('ifsc')),
            ];
        }

        if (!$isEdit) {
            $data['created_at'] = date('c');
        }

        // H-04 FIX: Wrap financial writes in try/catch
        try {
            $oldData = $isEdit ? $this->_fs_coa_get($code) : null;

            // Preserve is_system and created_at on edit
            if ($isEdit && is_array($oldData)) {
                if (!empty($oldData['is_system'])) $data['is_system'] = true;
                if (!empty($oldData['created_at'])) $data['created_at'] = $oldData['created_at'];
            }

            $this->_fs_coa_set($code, $data);
            $this->_audit($isEdit ? 'update_account' : 'create_account', 'chart_of_accounts', $code, $oldData, $data);

            // Firestore mirror (Phase 4.1)
            try { $this->acctFsSync->syncChartOfAccount($code, $data); }
            catch (\Exception $_) {}

            $this->json_success(['message' => $isEdit ? 'Account updated.' : 'Account created.']);
        } catch (\Exception $e) {
            log_message('error', 'save_account failed: ' . $e->getMessage());
            return $this->json_error('Failed to save account. Please try again.');
        }
    }

    /** POST: Delete (deactivate) an account */
    public function delete_account()
    {
        $this->_require_role(self::ADMIN_ROLES);

        $code = $this->safe_path_segment(trim((string) $this->input->post('code')), 'code');

        $acct = $this->_fs_coa_get($code);
        if (!$acct) {
            return $this->json_error('Account not found.');
        }
        if (!empty($acct['is_system'])) {
            return $this->json_error('Cannot delete system accounts.');
        }

        // Check if account has ledger entries
        $idx = [] /* index query removed — use Firestore accounting where queries */;
        if (!empty($idx)) {
            return $this->json_error('Cannot delete — account has ledger entries. Deactivate instead.');
        }

        $this->_fs_coa_delete($code);
        $this->_audit('delete_account', 'chart_of_accounts', $code, $acct, null);

        // Firestore mirror — soft-delete (sets status=inactive)
        try { $this->acctFsSync->deleteChartOfAccount($code); }
        catch (\Exception $_) {}

        $this->json_success(['message' => 'Account deleted.']);
    }

    /** POST: Seed default chart of accounts for Indian schools */
    public function seed_default_chart()
    {
        $this->_require_role(self::ADMIN_ROLES);

        $ts = date('c');
        $defaults = $this->_default_coa_template($ts);

        // Load existing accounts — merge (skip existing codes, add missing)
        $existing = $this->_fs_coa_all();
        if (!is_array($existing)) $existing = [];

        $createdCodes = [];
        $skippedCodes = [];

        foreach ($defaults as $code => $acct) {
            if (isset($existing[$code]) && is_array($existing[$code])) {
                $skippedCodes[] = $code;
                continue;
            }
            $this->_fs_coa_set($code, $acct);
            $createdCodes[] = $code;

            // Firestore mirror — per-account doc in the canonical collection
            // (Phase 4.1 standardized this shape across save/delete flows).
            try { $this->acctFsSync->syncChartOfAccount((string) $code, $acct); }
            catch (\Exception $_) {}
        }

        $created = count($createdCodes);
        $skipped = count($skippedCodes);

        // Legacy duplicate-mirror on the school doc (kept for backward compat
        // with any older reader; remove once Phase 4.2 swaps reads).
        if ($created > 0) {
            try {
                $allAccounts = $this->_fs_coa_all() ?? [];
                $this->fs->update('schools', $this->school_id, [
                    'chartOfAccounts' => is_array($allAccounts) ? $allAccounts : [],
                    'updatedAt'       => date('c'),
                ]);
            } catch (Exception $e) {
                log_message('error', "Firestore seed_default_chart: " . $e->getMessage());
            }
        }

        log_message('info',
            "seed_default_chart: school=[{$this->school_name}] admin=[{$this->admin_id}] "
            . "created=[" . implode(',', $createdCodes) . "] "
            . "skipped=[" . implode(',', $skippedCodes) . "]"
        );

        $this->_audit('seed_default_chart', 'chart_of_accounts', 'all', null, [
            'created'        => $created,
            'skipped'        => $skipped,
            'created_codes'  => $createdCodes,
            'total_defaults' => count($defaults),
        ]);

        if ($created === 0) {
            $this->json_success([
                'message' => "All " . count($defaults) . " default accounts already exist. Nothing to add.",
                'added'   => [],
                'skipped' => $skippedCodes,
            ]);
        } else {
            $this->json_success([
                'message' => "Seeded {$created} default accounts." . ($skipped ? " {$skipped} already existed (kept as-is)." : ''),
                'added'   => $createdCodes,
                'skipped' => $skippedCodes,
            ]);
        }
    }

    /** POST: Migrate existing Account_book entries to ChartOfAccounts */
    public function migrate_existing_accounts()
    {
        $this->_require_role(self::ADMIN_ROLES);

        $bookPath = $this->_bp() . '/Accounts/Account_book';
        $book = null /* account book — use Firestore chartOfAccounts */;
        if (!is_array($book)) {
            return $this->json_error('No existing Account_book entries found.');
        }

        $coaPath = $this->_coa();
        $existing = $this->_fs_coa_all();
        $ts = date('c');
        $migrated = 0;
        $nextCode = 6000; // start migrated accounts at 6000

        // Map sub-groups to categories
        $catMap = [
            'CASH' => 'Asset', 'BANK ACCOUNT' => 'Asset', 'CURRENT ASSETS' => 'Asset',
            'MOVABLE ASSETS' => 'Asset', 'STOCK IN HAND' => 'Asset', 'SUNDRY DEBTORS' => 'Asset',
            'FIXED ASSETS' => 'Asset', 'FURNITURE ACCOUNT' => 'Asset', 'OFFICE EQUIPMENT' => 'Asset',
            'PLANT & MACHINERY ACCOUNT' => 'Asset', 'VEHICLES' => 'Asset', 'MOVEABLE ASSETS' => 'Asset',
            'CURRENT LIABILITIES' => 'Liability', 'SECURED LOAN' => 'Liability',
            'SUNDRY CREDITORS' => 'Liability', 'UNSECURED LOAN' => 'Liability',
            'DUTY & TAXES' => 'Liability',
            'REVENUE ACCOUNT' => 'Income', 'INCOME FROM OTHER SOURCES' => 'Income',
            'SALE ACCOUNT' => 'Income',
            'PERSONAL EXP.' => 'Expense', 'DIRECT EXPENSES' => 'Expense', 'DIRECT EXP.' => 'Expense',
            'INDIRECT EXPENSES' => 'Expense', 'ADMINISTRATION EXP.' => 'Expense',
            'ADVERTISEMENT & PUBLICITY EXP.' => 'Expense', 'FINANCIAL EXP.' => 'Expense',
            'PURCHASE ACCOUNT' => 'Expense',
        ];

        foreach ($book as $acctName => $acctData) {
            if (!is_array($acctData)) continue;

            $code = (string) $nextCode;
            if (in_array($code, $existing ?: [], true)) {
                $nextCode++;
                $code = (string) $nextCode;
            }

            $under = strtoupper(trim($acctData['Under'] ?? ''));
            $category = $catMap[$under] ?? 'Expense';
            $isBank = ($under === 'BANK ACCOUNT');

            $entry = [
                'code'            => $code,
                'name'            => $acctName,
                'category'        => $category,
                'sub_category'    => $under ?: $category,
                'parent_code'     => null,
                'is_group'        => false,
                'is_bank'         => $isBank,
                'normal_side'     => in_array($category, ['Asset', 'Expense']) ? 'Dr' : 'Cr',
                'description'     => "Migrated from Account Book",
                'opening_balance' => 0,
                'status'          => 'active',
                'is_system'       => false,
                'sort_order'      => (int) $code,
                'created_at'      => $ts,
                'updated_at'      => $ts,
                'migrated_from'   => $acctName,
            ];

            if ($isBank) {
                $entry['bank_details'] = [
                    'bank_name'  => $acctData['branchName'] ?? '',
                    'branch'     => '',
                    'account_no' => $acctData['accountNumber'] ?? '',
                    'ifsc'       => $acctData['ifscCode'] ?? '',
                ];
            }

            $this->_fs_coa_set($code, $entry);
            $nextCode++;
            $migrated++;
        }

        $this->_audit('migrate_existing_accounts', 'chart_of_accounts', 'migration', null, ['migrated' => $migrated]);
        $this->json_success(['message' => "Migrated {$migrated} accounts.", 'migrated' => $migrated]);
    }

    // =========================================================================
    //  TAB 2: LEDGER / JOURNAL ENTRIES
    // =========================================================================

    /** POST: Fetch ledger entries with filters and pagination.
     *
     * Optimization: when date range is provided without account filter,
     * uses by_date index to fetch only relevant entry IDs instead of
     * downloading the entire Ledger node. Falls back to full scan only
     * when no filters are provided.
     */
    public function get_ledger_entries()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $dateFrom    = trim((string) $this->input->post('date_from'));
        $dateTo      = trim((string) $this->input->post('date_to'));
        $accountCode = trim((string) $this->input->post('account_code'));
        $vType       = trim((string) $this->input->post('voucher_type'));
        $page        = (int) ($this->input->post('page') ?: 1);
        $limit       = min(100, max(1, (int) ($this->input->post('limit') ?: 50)));
        // Backward compat: if 'offset' is sent (old UI), compute page from it
        $rawOffset   = $this->input->post('offset');
        if ($rawOffset !== null && $rawOffset !== '' && $page <= 1) {
            $page = (int) floor((int) $rawOffset / $limit) + 1;
        }

        $entries = [];

        if ($accountCode) {
            // Strategy A: use account index → fetch only matching IDs
            $safeCode = $this->safe_path_segment($accountCode, 'account_code');
            $ids = [] /* index query removed — use Firestore accounting where queries */;
            if (is_array($ids)) {
                $allLedger = $this->_fs_ledger_all();
                if (!is_array($allLedger)) $allLedger = [];
                foreach ($ids as $id) {
                    $entry = $allLedger[$id] ?? null;
                    if (!is_array($entry)) continue;
                    if (($entry['status'] ?? '') === 'deleted') continue;
                    if ($dateFrom && ($entry['date'] ?? '') < $dateFrom) continue;
                    if ($dateTo && ($entry['date'] ?? '') > $dateTo) continue;
                    if ($vType && ($entry['voucher_type'] ?? '') !== $vType) continue;
                    $entry['id'] = $id;
                    $entries[] = $entry;
                }
            }
        } elseif ($dateFrom || $dateTo) {
            // Strategy B: use date index to narrow the fetch window
            $dateIndex = [] /* RTDB index removed — Firestore accounting used directly */;
            if (!is_array($dateIndex)) $dateIndex = [];

            // Collect entry IDs from matching date range
            $targetIds = [];
            foreach ($dateIndex as $idxDate => $ids) {
                if ($dateFrom && $idxDate < $dateFrom) continue;
                if ($dateTo && $idxDate > $dateTo) continue;
                if (is_array($ids)) {
                    foreach (array_keys($ids) as $id) {
                        $targetIds[$id] = true;
                    }
                }
            }

            if (!empty($targetIds)) {
                $allLedger = $this->_fs_ledger_all();
                if (!is_array($allLedger)) $allLedger = [];
                foreach ($targetIds as $id => $_) {
                    $entry = $allLedger[$id] ?? null;
                    if (!is_array($entry)) continue;
                    if (($entry['status'] ?? '') === 'deleted') continue;
                    if ($vType && ($entry['voucher_type'] ?? '') !== $vType) continue;
                    $entry['id'] = $id;
                    $entries[] = $entry;
                }
            }
        } else {
            // Strategy C: no filters — full scan (fallback)
            $all = $this->_fs_ledger_all();
            if (is_array($all)) {
                foreach ($all as $id => $entry) {
                    if (!is_array($entry)) continue;
                    if (($entry['status'] ?? '') === 'deleted') continue;
                    if ($vType && ($entry['voucher_type'] ?? '') !== $vType) continue;
                    $entry['id'] = $id;
                    $entries[] = $entry;
                }
            }
        }

        // Sort by date desc
        usort($entries, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        // Pagination
        $total  = count($entries);
        $offset = ($page - 1) * $limit;
        $entries = array_slice($entries, $offset, $limit);

        // Enrich line items with account names from Chart of Accounts
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) $coa = [];
        foreach ($entries as &$entry) {
            if (!empty($entry['lines']) && is_array($entry['lines'])) {
                foreach ($entry['lines'] as &$line) {
                    $code = $line['account_code'] ?? '';
                    if ($code !== '' && empty($line['account_name'])) {
                        $line['account_name'] = $coa[$code]['name'] ?? '';
                    }
                }
                unset($line);
            }
        }
        unset($entry);

        $this->json_success([
            'entries'  => $entries,
            'total'    => $total,
            'page'     => $page,
            'limit'    => $limit,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    /** GET: Get next voucher number for a given type */
    public function get_next_voucher_no()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $type = trim((string) $this->input->get('type'));
        if (!$type) $type = 'Journal';

        $safeType = $this->safe_path_segment($type, 'type');
        $prefix = $this->_voucher_prefix($type);
        $counterPath = $this->_bp() . "/Accounts/Voucher_counters/{$safeType}";
        $current = $this->_fs_counter_get($voucherType ?? 'Journal');

        $next = $current + 1;
        $voucherNo = $prefix . str_pad($next, 6, '0', STR_PAD_LEFT);

        $this->json_success(['voucher_no' => $voucherNo, 'seq' => $next]);
    }

    /** POST: Create a journal entry (double-entry) */
    public function save_journal_entry()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $date      = trim((string) $this->input->post('date'));
        $vType     = trim((string) $this->input->post('voucher_type'));
        $narration = trim((string) $this->input->post('narration'));
        $linesJson = $this->input->post('lines');
        $source    = trim((string) $this->input->post('source')) ?: 'manual';
        $sourceRef = trim((string) $this->input->post('source_ref'));

        if (!$date || !$vType || !$linesJson) {
            return $this->json_error('Date, voucher type, and line items are required.');
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json_error('Invalid date format. Use YYYY-MM-DD.');
        }

        // Check period lock
        $this->_check_period_lock($date);

        $lines = is_string($linesJson) ? json_decode($linesJson, true) : $linesJson;
        if (!is_array($lines) || count($lines) < 2) {
            return $this->json_error('At least 2 line items required for double-entry.');
        }

        // Validate and sum
        $totalDr = 0;
        $totalCr = 0;
        $cleanLines = [];
        $affectedAccounts = [];

        foreach ($lines as $line) {
            $acCode = trim((string) ($line['account_code'] ?? ''));
            $acName = trim((string) ($line['account_name'] ?? ''));
            $dr     = round((float) ($line['dr'] ?? 0), 2);
            $cr     = round((float) ($line['cr'] ?? 0), 2);

            if (!$acCode) continue;
            if ($dr == 0 && $cr == 0) continue;
            if ($dr > 0 && $cr > 0) {
                return $this->json_error("Line for {$acCode}: cannot have both debit and credit.");
            }

            $totalDr += $dr;
            $totalCr += $cr;
            $cleanLines[] = [
                'account_code' => $acCode,
                'account_name' => $acName,
                'dr'           => $dr,
                'cr'           => $cr,
                'narration'    => trim((string) ($line['narration'] ?? '')),
            ];

            $affectedAccounts[$acCode] = [
                'dr' => ($affectedAccounts[$acCode]['dr'] ?? 0) + $dr,
                'cr' => ($affectedAccounts[$acCode]['cr'] ?? 0) + $cr,
            ];
        }

        if (empty($cleanLines)) {
            return $this->json_error('No valid line items provided.');
        }

        // Double-entry check: total debit must equal total credit
        if (abs($totalDr - $totalCr) > 0.01) {
            return $this->json_error("Debit ({$totalDr}) does not equal Credit ({$totalCr}).");
        }

        // Validate each account exists and is active in CoA
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) $coa = [];
        foreach ($cleanLines as $line) {
            $ac = $line['account_code'];
            if (!isset($coa[$ac]) || ($coa[$ac]['status'] ?? '') !== 'active') {
                return $this->json_error("Account {$ac} does not exist or is inactive.");
            }
            if (!empty($coa[$ac]['is_group'])) {
                return $this->json_error("Account {$ac} is a group account — cannot post directly.");
            }
        }

        // H-04 FIX: Wrap all financial writes in try/catch to prevent partial
        //    writes and provide clear error feedback for ledger operations.
        try {
            // Generate voucher number (read counter but DON'T write yet)
            $safeVType = $this->safe_path_segment($vType, 'voucher_type');
            $prefix = $this->_voucher_prefix($vType);
            $counterPath = $this->_bp() . "/Accounts/Voucher_counters/{$safeVType}";
            $currentSeq = $this->_fs_counter_get($voucherType ?? 'Journal');
            $newSeq = $currentSeq + 1;
            $voucherNo = $prefix . str_pad($newSeq, 6, '0', STR_PAD_LEFT);

            // Build entry
            $entryId = $this->_generate_entry_id('JE');
            $entry = [
                'date'         => $date,
                'voucher_no'   => $voucherNo,
                'voucher_type' => $vType,
                'narration'    => $narration,
                'lines'        => $cleanLines,
                'total_dr'     => round($totalDr, 2),
                'total_cr'     => round($totalCr, 2),
                'source'       => $source,
                'source_ref'   => $sourceRef ?: null,
                'is_finalized' => false,
                'status'       => 'active',
                'created_by'   => $this->admin_id,
                'created_at'   => date('c'),
            ];

            // Write entry FIRST — if this fails, counter stays unchanged (no orphan)
            $this->_fs_ledger_set($entryId, $entry);

            // Entry saved successfully — now commit the counter increment
            $this->_fs_counter_set($voucherType ?? 'Journal', $newSeq);

            // Write index entries
            $safeDateSeg = $this->safe_path_segment($date, 'date');
            $this->_fs_idx_set("IDX_DATE_{$safeDateSeg}_{$entryId}");
            foreach (array_keys($affectedAccounts) as $acCode) {
                $safeAc = $this->safe_path_segment($acCode, 'account_code');
                $this->_fs_idx_set("IDX_ACCT_{$safeAc}_{$entryId}");
            }

            // Update closing balances cache
            $this->_update_balances($affectedAccounts, 'add');

            $this->_audit('create', 'journal_entry', $entryId, null, $entry);

            // Firestore mirror — entry + counter. Closing-balance mirrors are
            // applied inside _update_balances (see that helper).
            try {
                $this->acctFsSync->syncLedgerEntry($entryId, $entry);
                $this->acctFsSync->syncVoucherCounter($safeVType, $newSeq);
            } catch (\Exception $_) {}

            $this->json_success([
                'message'    => 'Journal entry saved.',
                'entry_id'   => $entryId,
                'voucher_no' => $voucherNo,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'save_journal_entry failed: ' . $e->getMessage());
            return $this->json_error('Failed to save journal entry. Please try again.');
        }
    }

    /** POST: Soft-delete a non-finalized journal entry */
    public function delete_journal_entry()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $entryId = trim((string) $this->input->post('entry_id'));
        if (!$entryId) return $this->json_error('Entry ID required.');

        $entry = $this->_fs_ledger_get($entryId);
        if (!is_array($entry)) return $this->json_error('Entry not found.');
        if (($entry['status'] ?? '') === 'deleted') return $this->json_error('Entry already deleted.');

        if (!empty($entry['is_finalized'])) {
            return $this->json_error('Cannot delete finalized entries.');
        }

        // Check period lock
        $this->_check_period_lock($entry['date'] ?? '');

        // H-04 FIX: Wrap financial deletes in try/catch.
        // Write order: soft-delete FIRST (marks entry so retry won't double-reverse),
        // then reverse balances, then clean indices.
        try {
            // Soft-delete FIRST — prevents double-reversal on retry
            $this->_fs_ledger_update($entryId, [
                'status'     => 'deleted',
                'deleted_by' => $this->admin_id,
                'deleted_at' => date('c'),
            ]);

            // Reverse closing balances
            $affectedAccounts = [];
            foreach ($entry['lines'] ?? [] as $line) {
                $ac = $line['account_code'] ?? '';
                if (!$ac) continue;
                $affectedAccounts[$ac] = [
                    'dr' => ($affectedAccounts[$ac]['dr'] ?? 0) + ($line['dr'] ?? 0),
                    'cr' => ($affectedAccounts[$ac]['cr'] ?? 0) + ($line['cr'] ?? 0),
                ];
            }
            $this->_update_balances($affectedAccounts, 'subtract');

            // Remove indices
            $date = $entry['date'] ?? '';
            if ($date) {
                $safeDateSeg = $this->safe_path_segment($date, 'date');
                $this->_fs_idx_delete("IDX_DATE_{$safeDateSeg}_{$entryId}");
            }
            foreach (array_keys($affectedAccounts) as $acCode) {
                $safeAc = $this->safe_path_segment($acCode, 'account_code');
                $this->_fs_idx_delete("IDX_ACCT_{$safeAc}_{$entryId}");
            }

            $this->_audit('delete', 'journal_entry', $entryId, $entry, null);

            // Firestore mirror of the soft-delete (balance mirror already
            // handled by _update_balances above).
            try { $this->acctFsSync->syncLedgerDelete($entryId, ['deleted_by' => $this->admin_id]); }
            catch (\Exception $_) {}

            $this->json_success(['message' => 'Entry deleted.']);
        } catch (\Exception $e) {
            log_message('error', 'delete_journal_entry failed: ' . $e->getMessage());
            return $this->json_error('Failed to delete journal entry. Please try again.');
        }
    }

    /** POST: Finalize an entry (make immutable) */
    public function finalize_entry()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $entryId = trim((string) $this->input->post('entry_id'));
        if (!$entryId) return $this->json_error('Entry ID required.');

        $path = $this->_ledger() . "/{$entryId}";
        $entry = null /* RTDB path removed — use Firestore helper */;
        if (!is_array($entry)) return $this->json_error('Entry not found.');
        if (($entry['status'] ?? '') === 'deleted') return $this->json_error('Cannot finalize a deleted entry.');

        // RTDB update removed — use Firestore helper
$this->_fs_ledger_update($entryId ?? '', [
            'is_finalized' => true,
            'finalized_at' => date('c'),
        ]);

        $this->_audit('finalize', 'journal_entry', $entryId, null, ['finalized_at' => date('c')]);
        $this->json_success(['message' => 'Entry finalized.']);
    }

    // =========================================================================
    //  TAB 3: INCOME & EXPENSE
    // =========================================================================

    /** POST: Fetch income/expense records with pagination */
    public function get_income_expenses()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $type     = trim((string) $this->input->post('type')); // income|expense|''
        $dateFrom = trim((string) $this->input->post('date_from'));
        $dateTo   = trim((string) $this->input->post('date_to'));
        $limit    = (int) ($this->input->post('limit') ?: 100);
        $offset   = (int) ($this->input->post('offset') ?: 0);

        $all = $this->_fs_ie_all();
        if (!is_array($all)) $all = [];

        $records = [];
        foreach ($all as $id => $rec) {
            if (!is_array($rec)) continue;
            if (($rec['status'] ?? '') === 'deleted') continue;
            if ($type && ($rec['type'] ?? '') !== $type) continue;
            if ($dateFrom && ($rec['date'] ?? '') < $dateFrom) continue;
            if ($dateTo && ($rec['date'] ?? '') > $dateTo) continue;
            $rec['id'] = $id;
            $records[] = $rec;
        }

        usort($records, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        // Pagination
        $total = count($records);
        $records = array_slice($records, $offset, $limit);

        $this->json_success([
            'records'  => $records,
            'total'    => $total,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    /** POST: Create income or expense record + auto-create ledger entry */
    public function save_income_expense()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $type        = trim((string) $this->input->post('type'));
        $date        = trim((string) $this->input->post('date'));
        $accountCode = trim((string) $this->input->post('account_code'));
        $amount      = round((float) $this->input->post('amount'), 2);
        $payMode     = trim((string) $this->input->post('payment_mode'));
        $bankCode    = trim((string) $this->input->post('bank_account_code'));
        $description = trim((string) $this->input->post('description'));
        $category    = trim((string) $this->input->post('category'));
        $vendor      = trim((string) $this->input->post('vendor'));
        $receiptNo   = trim((string) $this->input->post('receipt_no'));

        if (!$type || !in_array($type, ['income', 'expense'])) {
            return $this->json_error('Type must be income or expense.');
        }
        if (!$date || !$accountCode || $amount <= 0) {
            return $this->json_error('Date, account, and amount are required.');
        }

        // Check period lock
        $this->_check_period_lock($date);

        // Determine cash/bank account for the other side of the entry
        $cashBankCode = $bankCode ?: '1010'; // default to Cash in Hand

        // Validate both accounts exist in CoA (single fetch)
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) $coa = [];

        if (!isset($coa[$accountCode]) || ($coa[$accountCode]['status'] ?? '') !== 'active') {
            return $this->json_error("Account {$accountCode} does not exist or is inactive.");
        }
        if (!isset($coa[$cashBankCode]) || ($coa[$cashBankCode]['status'] ?? '') !== 'active') {
            return $this->json_error("Cash/Bank account {$cashBankCode} does not exist or is inactive.");
        }

        // Build ledger lines
        if ($type === 'income') {
            // Dr Cash/Bank, Cr Income Account
            $lines = [
                ['account_code' => $cashBankCode, 'account_name' => '', 'dr' => $amount, 'cr' => 0, 'narration' => $description],
                ['account_code' => $accountCode, 'account_name' => '', 'dr' => 0, 'cr' => $amount, 'narration' => $description],
            ];
            $vType = 'Receipt';
        } else {
            // Dr Expense Account, Cr Cash/Bank
            $lines = [
                ['account_code' => $accountCode, 'account_name' => '', 'dr' => $amount, 'cr' => 0, 'narration' => $description],
                ['account_code' => $cashBankCode, 'account_name' => '', 'dr' => 0, 'cr' => $amount, 'narration' => $description],
            ];
            $vType = 'Payment';
        }

        // Resolve account names from the already-fetched CoA
        foreach ($lines as &$line) {
            $line['account_name'] = $coa[$line['account_code']]['name'] ?? $line['account_code'];
        }
        unset($line);

        // H-04 FIX: Wrap all financial writes in try/catch
        try {
            // Create ledger entry (read counter but DON'T write yet)
            $safeVType = $this->safe_path_segment($vType, 'voucher_type');
            $prefix = $this->_voucher_prefix($vType);
            $counterPath = $this->_bp() . "/Accounts/Voucher_counters/{$safeVType}";
            $seq = $this->_fs_counter_get($voucherType ?? 'Journal') + 1;
            $voucherNo = $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);

            $entryId = $this->_generate_entry_id('IE');
            $ledgerEntry = [
                'date'         => $date,
                'voucher_no'   => $voucherNo,
                'voucher_type' => $vType,
                'narration'    => $description,
                'lines'        => $lines,
                'total_dr'     => $amount,
                'total_cr'     => $amount,
                'source'       => $type,
                'source_ref'   => null,
                'is_finalized' => false,
                'status'       => 'active',
                'created_by'   => $this->admin_id,
                'created_at'   => date('c'),
            ];

            // Write entry FIRST — if this fails, counter stays unchanged (no orphan)
            $this->_fs_ledger_set($entryId, $ledgerEntry);

            // Entry saved successfully — now commit the counter
            $this->_fs_counter_set($voucherType ?? 'Journal', $seq);

            // Indices
            $safeDateSeg = $this->safe_path_segment($date, 'date');
            $this->_fs_idx_set("IDX_DATE_{$safeDateSeg}_{$entryId}");
            foreach ($lines as $line) {
                $safeAc = $this->safe_path_segment($line['account_code'], 'account_code');
                $this->_fs_idx_set("IDX_ACCT_{$safeAc}_{$entryId}");
            }

            // Update balances
            $affected = [];
            foreach ($lines as $line) {
                $ac = $line['account_code'];
                $affected[$ac] = [
                    'dr' => ($affected[$ac]['dr'] ?? 0) + $line['dr'],
                    'cr' => ($affected[$ac]['cr'] ?? 0) + $line['cr'],
                ];
            }
            $this->_update_balances($affected, 'add');

            // Save income/expense record
            $recordId = $entryId;
            $record = [
                'type'              => $type,
                'date'              => $date,
                'account_code'      => $accountCode,
                'amount'            => $amount,
                'payment_mode'      => $payMode,
                'bank_account_code' => $bankCode ?: null,
                'description'       => $description,
                'category'          => $category,
                'vendor'            => $vendor,
                'receipt_no'        => $receiptNo,
                'ledger_entry_id'   => $entryId,
                'status'            => 'active',
                'created_by'        => $this->admin_id,
                'created_at'        => date('c'),
            ];

            $this->_fs_ie_set($recordId, $record);

            $this->_audit('create', 'income_expense', $recordId, null, $record);

            // Firestore mirror — income/expense record + the underlying ledger entry.
            try {
                $this->acctFsSync->syncIncomeExpense($recordId, $record);
                // The ledger entry was written earlier in this method via the
                // RTDB firebase->set — mirror it now so reports match.
                $ledgerDoc = $this->_fs_ledger_get($entryId);
                if (is_array($ledgerDoc)) {
                    $this->acctFsSync->syncLedgerEntry($entryId, $ledgerDoc);
                }
            } catch (\Exception $_) {}

            $this->json_success([
                'message'    => ucfirst($type) . ' recorded.',
                'record_id'  => $recordId,
                'voucher_no' => $voucherNo,
            ]);
        } catch (\Exception $e) {
            log_message('error', 'save_income_expense failed: ' . $e->getMessage());
            return $this->json_error('Failed to save ' . $type . ' record. Please try again.');
        }
    }

    /** POST: Soft-delete income/expense + its ledger entry */
    public function delete_income_expense()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $id = trim((string) $this->input->post('id'));
        if (!$id) return $this->json_error('Record ID required.');

        $recPath = $this->_ie_path() . "/{$id}";
        $rec = $this->_fs_ie_get($id ?? 'unknown');
        if (!is_array($rec)) return $this->json_error('Record not found.');
        if (($rec['status'] ?? '') === 'deleted') return $this->json_error('Record already deleted.');

        // Check period lock
        $this->_check_period_lock($rec['date'] ?? '');

        // Soft-delete linked ledger entry
        $ledgerId = $rec['ledger_entry_id'] ?? '';
        if ($ledgerId) {
            $entry = $this->_fs_ledger_get($ledgerId);
            if (is_array($entry)) {
                if (!empty($entry['is_finalized'])) {
                    return $this->json_error('Linked journal entry is finalized.');
                }

                $affected = [];
                foreach ($entry['lines'] ?? [] as $line) {
                    $ac = $line['account_code'] ?? '';
                    if (!$ac) continue;
                    $affected[$ac] = [
                        'dr' => ($affected[$ac]['dr'] ?? 0) + ($line['dr'] ?? 0),
                        'cr' => ($affected[$ac]['cr'] ?? 0) + ($line['cr'] ?? 0),
                    ];
                }
                $this->_update_balances($affected, 'subtract');

                $date = $entry['date'] ?? '';
                if ($date) {
                    $safeDateSeg = $this->safe_path_segment($date, 'date');
                    $this->_fs_idx_delete("IDX_DATE_{$safeDateSeg}_{$ledgerId}");
                }
                foreach (array_keys($affected) as $acCode) {
                    $safeAc = $this->safe_path_segment($acCode, 'account_code');
                    $this->_fs_idx_delete("IDX_ACCT_{$safeAc}_{$ledgerId}");
                }

                // Soft-delete the ledger entry
                $this->_fs_ledger_update($ledgerId, [
                    'status'     => 'deleted',
                    'deleted_by' => $this->admin_id,
                    'deleted_at' => date('c'),
                ]);

                // Firestore mirror the ledger soft-delete.
                try { $this->acctFsSync->syncLedgerDelete($ledgerId, ['deleted_by' => $this->admin_id]); }
                catch (\Exception $_) {}
            }
        }

        // Soft-delete the income/expense record
        $this->_fs_ie_update($id ?? 'unknown', [
            'status'     => 'deleted',
            'deleted_by' => $this->admin_id,
            'deleted_at' => date('c'),
        ]);

        // Firestore mirror the income/expense soft-delete.
        try { $this->acctFsSync->syncIncomeExpenseDelete($id); }
        catch (\Exception $_) {}

        $this->_audit('delete', 'income_expense', $id, $rec, null);
        $this->json_success(['message' => 'Record deleted.']);
    }

    /** POST: Income/Expense summary by month */
    public function get_income_expense_summary()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $all = $this->_fs_ie_all();
        if (!is_array($all)) $all = [];

        $months = [];
        foreach ($all as $rec) {
            if (!is_array($rec)) continue;
            if (($rec['status'] ?? '') === 'deleted') continue;
            $m = substr($rec['date'] ?? '', 0, 7); // YYYY-MM
            $type = $rec['type'] ?? 'expense';
            $amt = (float) ($rec['amount'] ?? 0);

            if (!isset($months[$m])) $months[$m] = ['income' => 0, 'expense' => 0];
            $months[$m][$type] += $amt;
        }

        ksort($months);
        $this->json_success(['summary' => $months]);
    }

    // =========================================================================
    //  TAB 4: CASH BOOK
    // =========================================================================

    /** POST: Get cash book for a cash/bank account */
    public function get_cash_book()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $accountCode = trim((string) $this->input->post('account_code'));
        $dateFrom    = $this->_normalise_date((string) $this->input->post('date_from'));
        $dateTo      = $this->_normalise_date((string) $this->input->post('date_to'));

        if (!$accountCode) return $this->json_error('Account code required.');

        $this->_render_cash_book($accountCode, $dateFrom, $dateTo);
    }

    /**
     * Shared cash book / ledger report renderer.
     * Fetches all ledger entries at once (no N+1), filters by account+date, computes running balance.
     */
    private function _render_cash_book(string $accountCode, string $dateFrom, string $dateTo): void
    {
        $safeCode = $this->safe_path_segment($accountCode, 'account_code');

        // Get full CoA for account details + contra name resolution
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) $coa = [];

        $acct = $coa[$safeCode] ?? null;
        if (!is_array($acct)) {
            $this->json_error("Account {$accountCode} not found in Chart of Accounts.");
            return;
        }
        $openBal = (float) ($acct['opening_balance'] ?? 0);
        $normalSide = $acct['normal_side'] ?? 'Dr';

        // Get all entry IDs for this account
        $ids = [] /* index query removed — use Firestore accounting where queries */;
        if (!is_array($ids)) $ids = [];

        // Fetch FULL ledger once (fix N+1)
        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) $allLedger = [];

        // First pass: collect all entries, splitting pre-filter vs in-range
        $allTxns = [];
        foreach ($ids as $id) {
            $entry = $allLedger[$id] ?? null;
            if (!is_array($entry)) continue;
            if (($entry['status'] ?? '') === 'deleted') continue;

            $entryDate = $entry['date'] ?? '';
            if ($dateTo && $entryDate > $dateTo) continue;

            // Find this account's dr/cr in the entry lines, and identify contra accounts
            $dr = 0;
            $cr = 0;
            $contraAccounts = [];
            foreach ($entry['lines'] ?? [] as $line) {
                $lineCode = $line['account_code'] ?? '';
                if ($lineCode === $accountCode) {
                    $dr += (float) ($line['dr'] ?? 0);
                    $cr += (float) ($line['cr'] ?? 0);
                } elseif ($lineCode !== '') {
                    // This is a contra account — the other side of the entry
                    $contraAccounts[$lineCode] = $line['account_name']
                        ?? ($coa[$lineCode]['name'] ?? $lineCode);
                }
            }

            // Skip entries where this account has zero impact (index stale or rounding)
            if ($dr == 0 && $cr == 0) continue;

            // Build readable contra label
            $contraLabel = '';
            $contraKeys = array_keys($contraAccounts);
            if (count($contraKeys) === 1) {
                $contraLabel = $contraKeys[0] . ' - ' . $contraAccounts[$contraKeys[0]];
            } elseif (count($contraKeys) > 1) {
                $contraLabel = implode(', ', $contraKeys) . ' (Multiple)';
            }

            $allTxns[] = [
                'date'       => $entryDate,
                'voucher_no' => $entry['voucher_no'] ?? '',
                'type'       => $entry['voucher_type'] ?? 'Journal',
                'source'     => $entry['source'] ?? '',
                'source_ref' => $entry['source_ref'] ?? '',
                'narration'  => $entry['narration'] ?? '',
                'contra'     => $contraLabel,
                'dr'         => round($dr, 2),
                'cr'         => round($cr, 2),
                'entry_id'   => $id,
            ];
        }

        // Sort all by date
        usort($allTxns, fn($a, $b) => strcmp($a['date'], $b['date']));

        // Accumulate pre-filter transactions into opening balance
        $runningBal = $openBal;
        $transactions = [];
        foreach ($allTxns as $txn) {
            if ($dateFrom && $txn['date'] < $dateFrom) {
                // Pre-filter: accumulate into opening balance
                if ($normalSide === 'Dr') {
                    $runningBal += $txn['dr'] - $txn['cr'];
                } else {
                    $runningBal += $txn['cr'] - $txn['dr'];
                }
                continue;
            }
            $transactions[] = $txn;
        }

        // Effective opening balance includes pre-filter movements
        $effectiveOpenBal = round($runningBal, 2);

        // Compute running balance respecting account normal side
        foreach ($transactions as &$txn) {
            if ($normalSide === 'Dr') {
                $runningBal += $txn['dr'] - $txn['cr'];
            } else {
                $runningBal += $txn['cr'] - $txn['dr'];
            }
            $txn['balance'] = round($runningBal, 2);
        }
        unset($txn);

        // Compute totals
        $totalDr = 0;
        $totalCr = 0;
        foreach ($transactions as $txn) {
            $totalDr += $txn['dr'];
            $totalCr += $txn['cr'];
        }

        $this->json_success([
            'account'         => $acct,
            'account_code'    => $accountCode,
            'opening_balance' => $effectiveOpenBal,
            'transactions'    => $transactions,
            'total_dr'        => round($totalDr, 2),
            'total_cr'        => round($totalCr, 2),
            'closing_balance' => round($runningBal, 2),
        ]);
    }

    // =========================================================================
    //  TAB 5: BANK RECONCILIATION
    // =========================================================================

    /** GET: Get bank accounts from CoA */
    public function get_bank_accounts()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $all = $this->_fs_coa_all();
        if (!is_array($all)) $all = [];

        $banks = [];
        foreach ($all as $code => $acct) {
            if (!is_array($acct)) continue;
            if (!empty($acct['is_bank']) && ($acct['status'] ?? '') === 'active') {
                $banks[] = ['code' => $code, 'name' => $acct['name'] ?? $code];
            }
        }

        $this->json_success(['banks' => $banks]);
    }

    /** POST: Get bank recon entries */
    public function get_bank_statement()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code     = trim((string) $this->input->post('account_code'));
        $dateFrom = trim((string) $this->input->post('date_from'));
        $dateTo   = trim((string) $this->input->post('date_to'));

        if (!$code) return $this->json_error('Account code required.');

        $safeCode = $this->safe_path_segment($code, 'account_code');
        $path = $this->_bp() . "/Accounts/Bank_recon/{$safeCode}";
        $all = null /* RTDB path removed — use Firestore helper */;
        if (!is_array($all)) $all = [];

        $items = [];
        foreach ($all as $id => $item) {
            if (!is_array($item)) continue;
            if ($dateFrom && ($item['statement_date'] ?? '') < $dateFrom) continue;
            if ($dateTo && ($item['statement_date'] ?? '') > $dateTo) continue;
            $item['id'] = $id;
            $items[] = $item;
        }

        usort($items, fn($a, $b) => strcmp($a['statement_date'] ?? '', $b['statement_date'] ?? ''));
        $this->json_success(['items' => $items]);
    }

    /** POST: Import bank statement (CSV) with duplicate detection */
    public function import_bank_statement()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code = trim((string) $this->input->post('account_code'));
        if (!$code) return $this->json_error('Account code required.');

        $safeCode = $this->safe_path_segment($code, 'account_code');

        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            return $this->json_error('CSV file upload failed.');
        }

        $file = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$file) return $this->json_error('Cannot read file.');

        $header = fgetcsv($file); // Skip header row
        $basePath = $this->_bp() . "/Accounts/Bank_recon/{$safeCode}";
        $ts = date('c');

        // Load existing entries for duplicate detection
        $existingEntries = $this->_fs_ie_all(); /* bank recon entries */
        if (!is_array($existingEntries)) $existingEntries = [];
        $existingHashes = [];
        foreach ($existingEntries as $existItem) {
            if (!is_array($existItem)) continue;
            $hash = md5(($existItem['statement_date'] ?? '') . '|' . ($existItem['description'] ?? '') . '|' . ($existItem['debit'] ?? 0) . '|' . ($existItem['credit'] ?? 0));
            $existingHashes[$hash] = true;
        }

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($file)) !== false) {
            if (count($row) < 4) continue;

            $stDate  = trim($row[0]);
            $desc    = trim($row[1]);
            $debit   = (float) str_replace(',', '', $row[2] ?? '0');
            $credit  = (float) str_replace(',', '', $row[3] ?? '0');
            $ref     = trim($row[4] ?? '');

            // Normalize date to YYYY-MM-DD
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $stDate)) {
                $stDate = date('Y-m-d', strtotime(str_replace('/', '-', $stDate)));
            }

            // Check for duplicates
            $hash = md5($stDate . '|' . $desc . '|' . $debit . '|' . $credit);
            if (isset($existingHashes[$hash])) {
                $skipped++;
                continue;
            }
            $existingHashes[$hash] = true;

            $itemId = $this->_generate_entry_id('BK');
            $item = [
                'statement_date'    => $stDate,
                'description'       => $desc,
                'reference'         => $ref,
                'debit'             => $debit,
                'credit'            => $credit,
                'matched_ledger_id' => null,
                'status'            => 'unmatched',
                'imported_at'       => $ts,
            ];

            $this->_fs_ie_set($itemId, $item);
            $imported++;
        }

        fclose($file);
        $this->_audit('import_bank_statement', 'bank_recon', $safeCode, null, ['imported' => $imported, 'skipped' => $skipped]);
        $this->json_success([
            'message'  => "Imported {$imported} statement entries." . ($skipped ? " {$skipped} duplicates skipped." : ''),
            'imported' => $imported,
            'skipped'  => $skipped,
        ]);
    }

    /** POST: Match a bank statement item to a ledger entry */
    public function match_transaction()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code     = trim((string) $this->input->post('account_code'));
        $reconId  = trim((string) $this->input->post('recon_id'));
        $ledgerId = trim((string) $this->input->post('ledger_id'));

        if (!$code || !$reconId || !$ledgerId) {
            return $this->json_error('Account code, recon ID, and ledger ID are required.');
        }

        $safeCode = $this->safe_path_segment($code, 'account_code');
        $reconPath = $this->_bp() . "/Accounts/Bank_recon/{$safeCode}/{$reconId}";
        $recon = $this->_fs_ie_get($reconId ?? $id ?? 'unknown') /* bank recon via Firestore */;
        if (!is_array($recon)) return $this->json_error('Statement entry not found.');

        // Validate ledger entry exists and is not deleted
        $entry = $this->_fs_ledger_get($ledgerId);
        if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') {
            return $this->json_error('Ledger entry not found.');
        }

        $this->_fs_ie_update($reconId ?? $id ?? 'unknown', [
            'matched_ledger_id' => $ledgerId,
            'status'            => 'matched',
            'matched_at'        => date('c'),
        ]);

        $this->_audit('match', 'bank_recon', $reconId, null, ['ledger_id' => $ledgerId]);
        $this->json_success(['message' => 'Transaction matched.']);
    }

    /** POST: Unmatch a previously matched bank statement item */
    public function unmatch_transaction()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code    = trim((string) $this->input->post('account_code'));
        $reconId = trim((string) $this->input->post('recon_id'));
        if (!$code || !$reconId) return $this->json_error('Account code and recon ID required.');

        $safeCode = $this->safe_path_segment($code, 'account_code');
        $reconPath = $this->_bp() . "/Accounts/Bank_recon/{$safeCode}/{$reconId}";
        $recon = $this->_fs_ie_get($reconId ?? $id ?? 'unknown') /* bank recon via Firestore */;
        if (!is_array($recon)) return $this->json_error('Statement entry not found.');

        $oldLedgerId = $recon['matched_ledger_id'] ?? null;
        $this->_fs_ie_update($reconId ?? $id ?? 'unknown', [
            'matched_ledger_id' => null,
            'status'            => 'unmatched',
            'matched_at'        => null,
        ]);

        $this->_audit('unmatch', 'bank_recon', $reconId, ['ledger_id' => $oldLedgerId], null);
        $this->json_success(['message' => 'Transaction unmatched.']);
    }

    /** POST: Suggest matching ledger entries for a bank statement item */
    public function suggest_matches()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code    = trim((string) $this->input->post('account_code'));
        $reconId = trim((string) $this->input->post('recon_id'));
        if (!$code || !$reconId) return $this->json_error('Required fields missing.');

        $safeCode = $this->safe_path_segment($code, 'account_code');
        $reconPath = $this->_bp() . "/Accounts/Bank_recon/{$safeCode}/{$reconId}";
        $recon = $this->_fs_ie_get($reconId ?? $id ?? 'unknown') /* bank recon via Firestore */;
        if (!is_array($recon)) return $this->json_error('Statement entry not found.');

        $stmtAmount = max((float)($recon['debit'] ?? 0), (float)($recon['credit'] ?? 0));
        $stmtDate   = $recon['statement_date'] ?? '';

        // Get ledger entries for this account
        $ids = [] /* index query removed — use Firestore accounting where queries */;
        if (!is_array($ids)) return $this->json_success(['suggestions' => []]);

        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) $allLedger = [];

        // Already matched ledger IDs
        $allRecon = [] /* bank recon — TODO: migrate to Firestore bankRecon collection */;
        $matchedIds = [];
        if (is_array($allRecon)) {
            foreach ($allRecon as $r) {
                if (is_array($r) && ($r['status'] ?? '') === 'matched' && !empty($r['matched_ledger_id'])) {
                    $matchedIds[$r['matched_ledger_id']] = true;
                }
            }
        }

        $suggestions = [];
        foreach ($ids as $id) {
            if (isset($matchedIds[$id])) continue;
            $entry = $allLedger[$id] ?? null;
            if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') continue;

            // Find this account's dr/cr
            $dr = 0;
            $cr = 0;
            foreach ($entry['lines'] ?? [] as $line) {
                if (($line['account_code'] ?? '') === $code) {
                    $dr += (float)($line['dr'] ?? 0);
                    $cr += (float)($line['cr'] ?? 0);
                }
            }
            $entryAmount = max($dr, $cr);

            // Score: exact amount match = 100, close amount = 50, date match = 30
            $score = 0;
            if (abs($entryAmount - $stmtAmount) < 0.01) {
                $score += 100;
            } elseif ($stmtAmount > 0 && abs($entryAmount - $stmtAmount) / $stmtAmount < 0.05) {
                $score += 50;
            }

            if ($stmtDate && ($entry['date'] ?? '') === $stmtDate) {
                $score += 30;
            } elseif ($stmtDate && abs(strtotime($entry['date'] ?? '') - strtotime($stmtDate)) <= 259200) {
                $score += 15; // within 3 days
            }

            if ($score >= 15) {
                $suggestions[] = [
                    'entry_id'   => $id,
                    'date'       => $entry['date'] ?? '',
                    'voucher_no' => $entry['voucher_no'] ?? '',
                    'narration'  => $entry['narration'] ?? '',
                    'dr'         => $dr,
                    'cr'         => $cr,
                    'score'      => $score,
                ];
            }
        }

        usort($suggestions, fn($a, $b) => $b['score'] - $a['score']);
        $suggestions = array_slice($suggestions, 0, 10);

        $this->json_success(['suggestions' => $suggestions]);
    }

    /** POST: Get reconciliation summary */
    public function get_recon_summary()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code = trim((string) $this->input->post('account_code'));
        if (!$code) return $this->json_error('Account code required.');

        $safeCode = $this->safe_path_segment($code, 'account_code');

        // Bank statement total
        $stmtPath = $this->_bp() . "/Accounts/Bank_recon/{$safeCode}";
        $stmt = null /* bank statement — TODO: migrate to Firestore */;
        $bankBal = 0;
        $unmatchedCount = 0;
        if (is_array($stmt)) {
            foreach ($stmt as $item) {
                if (!is_array($item)) continue;
                $bankBal += (float) ($item['credit'] ?? 0) - (float) ($item['debit'] ?? 0);
                if (($item['status'] ?? '') === 'unmatched') $unmatchedCount++;
            }
        }

        // Book balance from closing_balances
        $bal = $this->_fs_bal_get($safeCode);
        $bookDr = (float) ($bal['period_dr'] ?? 0);
        $bookCr = (float) ($bal['period_cr'] ?? 0);

        $acct = $this->_fs_coa_get($safeCode);
        $openBal = (float) ($acct['opening_balance'] ?? 0);
        $bookBal = $openBal + $bookDr - $bookCr;

        $this->json_success([
            'bank_balance'   => round($bankBal, 2),
            'book_balance'   => round($bookBal, 2),
            'difference'     => round($bankBal - $bookBal, 2),
            'unmatched'      => $unmatchedCount,
        ]);
    }

    // =========================================================================
    //  TAB 6: FINANCIAL REPORTS
    // =========================================================================

    /**
     * Parse a date input that may be YYYY-MM-DD (HTML5 date input) or
     * MM/DD/YYYY (some browsers/locales). Returns YYYY-MM-DD or ''.
     */
    private function _normalise_date(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') return '';

        // Already YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;

        // Try MM/DD/YYYY or DD/MM/YYYY or any parseable format
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);

        return '';
    }

    /**
     * Compute per-account dr/cr totals from ledger entries within a date range.
     * If both $from and $to are empty, returns cached balances (fast path).
     *
     * @return array  ['account_code' => ['period_dr' => float, 'period_cr' => float], ...]
     */
    private function _compute_balances(string $from, string $to): array
    {
        // Fast path: no date filter → use cached closing balances
        if ($from === '' && $to === '') {
            $cached = $this->_fs_bal_all();
            return is_array($cached) ? $cached : [];
        }

        // Compute from raw ledger entries
        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) return [];

        $balances = [];
        foreach ($allLedger as $entry) {
            if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') continue;

            $entryDate = $entry['date'] ?? '';
            if ($from !== '' && $entryDate < $from) continue;
            if ($to !== '' && $entryDate > $to) continue;

            foreach ($entry['lines'] ?? [] as $line) {
                $ac = $line['account_code'] ?? '';
                if ($ac === '') continue;
                if (!isset($balances[$ac])) $balances[$ac] = ['period_dr' => 0, 'period_cr' => 0];
                $balances[$ac]['period_dr'] += (float) ($line['dr'] ?? 0);
                $balances[$ac]['period_cr'] += (float) ($line['cr'] ?? 0);
            }
        }

        return $balances;
    }

    /** POST: Trial Balance */
    public function trial_balance()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $from = $this->_normalise_date((string) $this->input->post('date_from'));
        $to   = $this->_normalise_date((string) $this->input->post('date_to'));
        // Legacy: as_of_date acts as upper bound if no date_to provided
        if ($to === '') {
            $to = $this->_normalise_date((string) $this->input->post('as_of_date'));
        }

        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return $this->json_success(['rows' => [], 'totals' => ['dr' => 0, 'cr' => 0]]);

        $balances = $this->_compute_balances($from, $to);

        $rows = [];
        $totalDr = 0;
        $totalCr = 0;

        foreach ($coa as $code => $acct) {
            if (!is_array($acct)) continue;
            if (($acct['status'] ?? '') !== 'active') continue;
            if (!empty($acct['is_group'])) continue;

            $openBal  = (float) ($acct['opening_balance'] ?? 0);
            $periodDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $periodCr = (float) ($balances[$code]['period_cr'] ?? 0);

            $normalSide = $acct['normal_side'] ?? 'Dr';
            if ($normalSide === 'Dr') {
                $closingBal = $openBal + $periodDr - $periodCr;
            } else {
                $closingBal = $openBal + $periodCr - $periodDr;
            }

            if (abs($closingBal) < 0.01) continue;

            $dr = $normalSide === 'Dr' ? max($closingBal, 0) : max(-$closingBal, 0);
            $cr = $normalSide === 'Cr' ? max($closingBal, 0) : max(-$closingBal, 0);

            $totalDr += $dr;
            $totalCr += $cr;

            $rows[] = [
                'code'            => $code,
                'name'            => $acct['name'] ?? '',
                'category'        => $acct['category'] ?? '',
                'opening_balance' => round($openBal, 2),
                'period_dr'       => round($periodDr, 2),
                'period_cr'       => round($periodCr, 2),
                'dr'              => round($dr, 2),
                'cr'              => round($cr, 2),
            ];
        }

        usort($rows, fn($a, $b) => strnatcmp($a['code'], $b['code']));

        $this->json_success([
            'rows'      => $rows,
            'totals'    => ['dr' => round($totalDr, 2), 'cr' => round($totalCr, 2)],
            'date_from' => $from,
            'date_to'   => $to,
        ]);
    }

    /** POST: Profit & Loss Statement */
    public function profit_loss()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $from = $this->_normalise_date((string) $this->input->post('date_from'));
        $to   = $this->_normalise_date((string) $this->input->post('date_to'));

        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return $this->json_success(['income' => [], 'expenses' => [], 'net' => 0]);

        $balances = $this->_compute_balances($from, $to);

        $income = [];
        $expenses = [];
        $totalIncome = 0;
        $totalExpense = 0;

        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') continue;
            if (!empty($acct['is_group'])) continue;

            $cat = $acct['category'] ?? '';
            $periodDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $periodCr = (float) ($balances[$code]['period_cr'] ?? 0);

            if ($cat === 'Income') {
                $amt = $periodCr - $periodDr;
                if (abs($amt) < 0.01) continue;
                $totalIncome += $amt;
                $income[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($amt, 2)];
            } elseif ($cat === 'Expense') {
                $amt = $periodDr - $periodCr;
                if (abs($amt) < 0.01) continue;
                $totalExpense += $amt;
                $expenses[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($amt, 2)];
            }
        }

        $this->json_success([
            'income'        => $income,
            'expenses'      => $expenses,
            'total_income'  => round($totalIncome, 2),
            'total_expense' => round($totalExpense, 2),
            'net_profit'    => round($totalIncome - $totalExpense, 2),
            'date_from'     => $from,
            'date_to'       => $to,
        ]);
    }

    /** POST: Balance Sheet */
    public function balance_sheet()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $to = $this->_normalise_date((string) $this->input->post('date_to'));
        // Balance Sheet is always "as of" a date — use date_to as upper bound, no lower bound
        // If date_from is provided, use it for period movements; otherwise all-time
        $from = $this->_normalise_date((string) $this->input->post('date_from'));

        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return $this->json_success(['assets' => [], 'liabilities' => [], 'equity' => []]);

        $balances = $this->_compute_balances($from, $to);

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];
        $netPL = 0;

        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') continue;
            if (!empty($acct['is_group'])) continue;

            $cat = $acct['category'] ?? '';
            $openBal  = (float) ($acct['opening_balance'] ?? 0);
            $periodDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $periodCr = (float) ($balances[$code]['period_cr'] ?? 0);

            $row = ['code' => $code, 'name' => $acct['name'] ?? ''];

            switch ($cat) {
                case 'Asset':
                    $bal = $openBal + $periodDr - $periodCr;
                    if (abs($bal) < 0.01) continue 2;
                    $row['amount'] = round($bal, 2);
                    $totals['assets'] += $bal;
                    $assets[] = $row;
                    break;
                case 'Liability':
                    $bal = $openBal + $periodCr - $periodDr;
                    if (abs($bal) < 0.01) continue 2;
                    $row['amount'] = round($bal, 2);
                    $totals['liabilities'] += $bal;
                    $liabilities[] = $row;
                    break;
                case 'Equity':
                    $bal = $openBal + $periodCr - $periodDr;
                    if (abs($bal) < 0.01) continue 2;
                    $row['amount'] = round($bal, 2);
                    $totals['equity'] += $bal;
                    $equity[] = $row;
                    break;
                case 'Income':
                    $netPL += ($periodCr - $periodDr);
                    break;
                case 'Expense':
                    $netPL -= ($periodDr - $periodCr);
                    break;
            }
        }

        if (abs($netPL) > 0.01) {
            $equity[] = ['code' => '-', 'name' => 'Current Year Surplus/Deficit', 'amount' => round($netPL, 2)];
            $totals['equity'] += $netPL;
        }

        $totalLiabilitiesEquity = round($totals['liabilities'] + $totals['equity'], 2);

        $this->json_success([
            'assets'                   => $assets,
            'liabilities'              => $liabilities,
            'equity'                   => $equity,
            'totals'                   => [
                'assets'               => round($totals['assets'], 2),
                'liabilities'          => round($totals['liabilities'], 2),
                'equity'               => round($totals['equity'], 2),
                'liabilities_equity'   => $totalLiabilitiesEquity,
            ],
            'total_liabilities_equity' => $totalLiabilitiesEquity,
            'date_from'                => $from,
            'date_to'                  => $to,
        ]);
    }

    /**
     * POST: Cash Flow Statement — Indirect Method
     *
     * Tracks actual cash MOVEMENTS through cash/bank accounts, classified by:
     *   Operating  — fees collected, salaries paid, day-to-day receipts/payments
     *   Investing  — fixed asset purchases/sales
     *   Financing  — loan receipts/repayments, equity contributions
     *
     * Contra entries (cash↔bank transfers) are excluded — they don't represent
     * economic activity, just movement between the school's own accounts.
     *
     * Opening balance is computed from:
     *   CoA opening_balance + all pre-period ledger movements on cash/bank accounts.
     */
    public function cash_flow()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $from = $this->_normalise_date((string) $this->input->post('date_from'));
        $to   = $this->_normalise_date((string) $this->input->post('date_to'));

        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) {
            return $this->json_success([
                'opening' => 0, 'closing' => 0, 'net_change' => 0,
                'operating' => [], 'investing' => [], 'financing' => [],
            ]);
        }

        // Identify all cash/bank accounts
        $cashCodes = [];
        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') continue;
            if (!empty($acct['is_group'])) continue;
            $sub = strtolower($acct['sub_category'] ?? '');
            if (in_array($code, ['1010', '1020']) || !empty($acct['is_bank']) || $sub === 'cash') {
                $cashCodes[$code] = true;
            }
        }

        // Static opening balance from CoA
        $openingFromCoA = 0;
        foreach ($cashCodes as $code => $_) {
            $openingFromCoA += (float) ($coa[$code]['opening_balance'] ?? 0);
        }

        // Fetch FULL ledger
        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) $allLedger = [];

        // Collect all entries that touch cash/bank accounts (deduplicated)
        $processedIds = [];
        $cashEntryIds = [];
        foreach (array_keys($cashCodes) as $cashCode) {
            $safeCode = $this->safe_path_segment($cashCode, 'account_code');
            $ids = [] /* index query removed — use Firestore accounting where queries */;
            if (!is_array($ids)) continue;
            foreach ($ids as $id) {
                if (!isset($processedIds[$id])) {
                    $processedIds[$id] = true;
                    $cashEntryIds[] = $id;
                }
            }
        }

        // Process entries: separate pre-period (opening) vs in-period (movements)
        //
        // Deduplication is by ENTRY ID only (via $processedIds above).
        // Each Ledger entry has a unique ID — voucher_no is NOT a dedup key
        // because different modules legitimately create separate entries with
        // their own voucher sequences.
        $preMovement  = 0;
        $operating    = [];
        $investing    = [];
        $financing    = [];
        $opTotal = 0; $invTotal = 0; $finTotal = 0;
        $processedCount = 0;
        $skippedCount   = 0;

        foreach ($cashEntryIds as $id) {
            $entry = $allLedger[$id] ?? null;
            if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') {
                $skippedCount++;
                continue;
            }

            $entryDate = $entry['date'] ?? '';
            if ($to !== '' && $entryDate > $to) continue;

            // Sum this entry's net impact on cash/bank accounts
            $cashDr = 0;
            $cashCr = 0;
            $otherAccounts = [];
            $isContra = true;

            foreach ($entry['lines'] ?? [] as $line) {
                $lineCode = $line['account_code'] ?? '';
                if (isset($cashCodes[$lineCode])) {
                    $cashDr += (float) ($line['dr'] ?? 0);
                    $cashCr += (float) ($line['cr'] ?? 0);
                } else {
                    $isContra = false;
                    $otherAccounts[$lineCode] = $coa[$lineCode] ?? [];
                }
            }

            $netCash = $cashDr - $cashCr;

            // Skip contra entries (cash↔bank transfers) and zero-impact
            if ($isContra || abs($netCash) < 0.01) continue;

            $processedCount++;

            // Pre-period: accumulate into opening balance
            if ($from !== '' && $entryDate < $from) {
                $preMovement += $netCash;
                continue;
            }

            // Classify by the other side's account category
            $flowType = 'operating';
            foreach ($otherAccounts as $otherCode => $otherAcct) {
                $otherCat = $otherAcct['category'] ?? '';
                $otherSub = strtolower($otherAcct['sub_category'] ?? '');

                if (strpos($otherSub, 'fixed') !== false || ($otherCat === 'Asset' && strpos($otherSub, 'current') === false)) {
                    $flowType = 'investing';
                    break;
                } elseif (strpos($otherSub, 'loan') !== false || strpos($otherSub, 'long-term') !== false || $otherCat === 'Equity') {
                    $flowType = 'financing';
                    break;
                }
            }

            // Description
            $desc = $entry['narration'] ?? '';
            if ($desc === '') {
                $names = [];
                foreach ($otherAccounts as $oc => $oa) {
                    $names[] = ($oa['name'] ?? $oc);
                }
                $desc = implode(', ', array_slice($names, 0, 2));
            }

            $source = $entry['source'] ?? '';
            $isSystem = in_array($source, ['income', 'expense', 'HR_Payroll', 'fees'], true);

            $item = [
                'date'       => $entryDate,
                'entry_id'   => $id,
                'narration'  => $desc,
                'voucher_no' => $entry['voucher_no'] ?? '',
                'source'     => $source ?: 'manual',
                'is_system'  => $isSystem,
                'inflow'     => $netCash > 0 ? round($netCash, 2) : 0,
                'outflow'    => $netCash < 0 ? round(abs($netCash), 2) : 0,
                'net'        => round($netCash, 2),
                '_accts'      => array_keys($otherAccounts), // for duplicate detection
                '_created_at' => $entry['created_at'] ?? '',  // for timestamp-based proximity
            ];

            switch ($flowType) {
                case 'investing':
                    $investing[] = $item;
                    $invTotal += $netCash;
                    break;
                case 'financing':
                    $financing[] = $item;
                    $finTotal += $netCash;
                    break;
                default:
                    $operating[] = $item;
                    $opTotal += $netCash;
                    break;
            }
        }

        // ── Possible-duplicate detection (flag only, never skip) ──
        //
        // Criteria — ALL must match to flag a pair:
        //   1. Same absolute amount
        //   2. Same direction (both inflows OR both outflows)
        //   3. Timestamp within ±36h (or date within ±1 day if no timestamp)
        //   4. At least one overlapping contra account code
        //   5. Normalised narration identical or >75% similar
        //
        // Performance: bucket by amount, cap pair comparisons at 50 per bucket.

        $allItems = [];
        foreach (['operating', 'investing', 'financing'] as $_cat) {
            foreach ($$_cat as &$_item) {
                $allItems[] = &$_item;
            }
            unset($_item);
        }

        $suspectPairs    = [];
        $pairsChecked    = 0;
        $maxPairsPerBucket = 50; // safety cap

        // Normalise narration: lowercase, strip punctuation, collapse whitespace
        $normNar = function (string $s): string {
            $s = strtolower(trim($s));
            $s = preg_replace('/[^a-z0-9\s]/', '', $s);  // strip punctuation
            return preg_replace('/\s+/', ' ', $s);         // collapse spaces
        };

        // Resolve timestamp: prefer created_at (ISO), fall back to date (YYYY-MM-DD noon)
        $resolveTs = function (array $item): int {
            if (!empty($item['_created_at'])) {
                $ts = strtotime($item['_created_at']);
                if ($ts !== false) return $ts;
            }
            $ts = strtotime($item['date'] . ' 12:00:00');
            return $ts !== false ? $ts : 0;
        };

        // Bucket by absolute amount
        $amtBuckets = [];
        foreach ($allItems as $idx => &$item) {
            $amtKey = number_format(abs($item['net']), 2, '.', '');
            $amtBuckets[$amtKey][] = $idx;
        }
        unset($item);

        foreach ($amtBuckets as $amtKey => $indices) {
            if (count($indices) < 2) continue;

            $cnt = count($indices);
            $bucketPairs = 0;

            for ($i = 0; $i < $cnt && $bucketPairs < $maxPairsPerBucket; $i++) {
                for ($j = $i + 1; $j < $cnt && $bucketPairs < $maxPairsPerBucket; $j++) {
                    $bucketPairs++;
                    $pairsChecked++;
                    $a = &$allItems[$indices[$i]];
                    $b = &$allItems[$indices[$j]];

                    // ── Check 1 (cheapest): same direction ──
                    // Both inflows (net > 0) or both outflows (net < 0)
                    if (($a['net'] > 0) !== ($b['net'] > 0)) continue;

                    // ── Check 2: time proximity — ±36h via timestamp, ±1 day via date ──
                    $tsA = $resolveTs($a);
                    $tsB = $resolveTs($b);
                    if ($tsA === 0 || $tsB === 0) continue;
                    $maxGap = (!empty($a['_created_at']) && !empty($b['_created_at']))
                        ? 129600   // 36 hours (timestamp precision available)
                        : 86400;   // 24 hours (date-only fallback)
                    if (abs($tsA - $tsB) > $maxGap) continue;

                    // ── Check 3: at least one shared contra account ──
                    $acctsA = $a['_accts'] ?? [];
                    $acctsB = $b['_accts'] ?? [];
                    if (!empty($acctsA) && !empty($acctsB)) {
                        $shared = array_intersect($acctsA, $acctsB);
                        if (empty($shared)) continue;
                    } else {
                        $shared = [];
                    }

                    // ── Check 4 (most expensive): normalised narration similarity ──
                    $nA = $normNar($a['narration']);
                    $nB = $normNar($b['narration']);
                    $similar = ($nA === $nB);
                    if (!$similar && strlen($nA) > 4 && strlen($nB) > 4) {
                        similar_text($nA, $nB, $pct);
                        $similar = ($pct > 75);
                    }
                    if (!$similar) continue;

                    // All checks passed — flag both (never remove)
                    $a['possible_duplicate'] = true;
                    $b['possible_duplicate'] = true;
                    $suspectPairs[] = [
                        'entry_a'      => $a['entry_id'],
                        'entry_b'      => $b['entry_id'],
                        'date_a'       => $a['date'],
                        'date_b'       => $b['date'],
                        'amount'       => (float) $amtKey,
                        'direction'    => $a['net'] > 0 ? 'inflow' : 'outflow',
                        'shared_accts' => array_values($shared),
                    ];
                }
            }
        }

        // Strip internal fields from response
        foreach ($allItems as &$item) {
            unset($item['_accts'], $item['_created_at']);
        }
        unset($item);

        if (!empty($suspectPairs)) {
            log_message('info',
                "cash_flow: " . count($suspectPairs) . " possible duplicate pair(s)"
                . " school=[{$this->school_name}]: "
                . json_encode($suspectPairs)
            );
        }

        // Debug logging
        log_message('debug',
            "cash_flow: school=[{$this->school_name}]"
            . " index_entries=" . count($cashEntryIds)
            . " processed={$processedCount}"
            . " skipped_deleted={$skippedCount}"
            . " dup_pairs_checked={$pairsChecked}"
            . " suspects=" . count($suspectPairs)
            . " operating=" . count($operating)
            . " investing=" . count($investing)
            . " financing=" . count($financing)
        );

        $openingBalance = round($openingFromCoA + $preMovement, 2);
        $netChange      = round($opTotal + $invTotal + $finTotal, 2);
        $closingBalance = round($openingBalance + $netChange, 2);

        $response = [
            'opening_balance'  => $openingBalance,
            'closing_balance'  => $closingBalance,
            'net_change'       => $netChange,
            'operating'        => $operating,
            'operating_total'  => round($opTotal, 2),
            'investing'        => $investing,
            'investing_total'  => round($invTotal, 2),
            'financing'        => $financing,
            'financing_total'  => round($finTotal, 2),
            'date_from'        => $from,
            'date_to'          => $to,
            'entry_count'      => $processedCount,
        ];

        if (!empty($suspectPairs)) {
            $response['suspect_duplicates'] = count($suspectPairs);
        }

        $this->json_success($response);
    }

    /**
     * POST: Day Book — chronological view of ALL ledger entries.
     * No account filter. Shows every transaction with line-item detail.
     */
    public function day_book()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $from = $this->_normalise_date((string) $this->input->post('date_from'));
        $to   = $this->_normalise_date((string) $this->input->post('date_to'));

        // Fetch full ledger
        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) $allLedger = [];

        // Fetch CoA for account name resolution
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) $coa = [];

        $entries  = [];
        $totalDr  = 0;
        $totalCr  = 0;

        foreach ($allLedger as $id => $entry) {
            if (!is_array($entry) || ($entry['status'] ?? '') === 'deleted') continue;

            $entryDate = $entry['date'] ?? '';
            if ($from !== '' && $entryDate < $from) continue;
            if ($to !== '' && $entryDate > $to) continue;

            $dr = round((float) ($entry['total_dr'] ?? 0), 2);
            $cr = round((float) ($entry['total_cr'] ?? 0), 2);

            // Build line items with resolved account names
            $lines = [];
            foreach ($entry['lines'] ?? [] as $line) {
                $ac   = $line['account_code'] ?? '';
                $name = $line['account_name'] ?? '';
                if ($name === '' && $ac !== '') {
                    $name = $coa[$ac]['name'] ?? $ac;
                }
                $lines[] = [
                    'account_code' => $ac,
                    'account_name' => $name,
                    'dr'           => round((float) ($line['dr'] ?? 0), 2),
                    'cr'           => round((float) ($line['cr'] ?? 0), 2),
                ];
            }

            $source = $entry['source'] ?? '';
            $entries[] = [
                'entry_id'     => $id,
                'date'         => $entryDate,
                'voucher_no'   => $entry['voucher_no'] ?? '',
                'voucher_type' => $entry['voucher_type'] ?? 'Journal',
                'narration'    => $entry['narration'] ?? '',
                'source'       => $source ?: 'manual',
                'is_system'    => in_array($source, ['income', 'expense', 'HR_Payroll', 'fees'], true),
                'is_finalized' => !empty($entry['is_finalized']),
                'total_dr'     => $dr,
                'total_cr'     => $cr,
                'lines'        => $lines,
                'created_at'   => $entry['created_at'] ?? '',
            ];

            $totalDr += $dr;
            $totalCr += $cr;
        }

        // Sort by date ASC, then by created_at ASC within same date
        usort($entries, function ($a, $b) {
            $d = strcmp($a['date'], $b['date']);
            if ($d !== 0) return $d;
            return strcmp($a['created_at'], $b['created_at']);
        });

        $this->json_success([
            'entries'   => $entries,
            'total_dr'  => round($totalDr, 2),
            'total_cr'  => round($totalCr, 2),
            'count'     => count($entries),
            'date_from' => $from,
            'date_to'   => $to,
        ]);
    }

    /** POST: Ledger report for a single account (no $_POST mutation) */
    public function ledger_report()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $code     = trim((string) $this->input->post('account_code'));
        $dateFrom = $this->_normalise_date((string) $this->input->post('date_from'));
        $dateTo   = $this->_normalise_date((string) $this->input->post('date_to'));

        if (!$code) return $this->json_error('Account code required.');

        $this->_render_cash_book($code, $dateFrom, $dateTo);
    }

    /** POST: Recompute all closing balances from scratch, with integrity report */
    public function recompute_balances()
    {
        $this->_require_role(self::ADMIN_ROLES);

        // Fetch existing cache for comparison
        $oldBalances = $this->_fs_bal_all();
        if (!is_array($oldBalances)) $oldBalances = [];

        $allEntries = $this->_fs_ledger_all();
        if (!is_array($allEntries)) $allEntries = [];

        $balances = [];
        foreach ($allEntries as $entry) {
            if (!is_array($entry)) continue;
            if (($entry['status'] ?? '') === 'deleted') continue;
            foreach ($entry['lines'] ?? [] as $line) {
                $ac = $line['account_code'] ?? '';
                if (!$ac) continue;
                if (!isset($balances[$ac])) {
                    $balances[$ac] = ['period_dr' => 0, 'period_cr' => 0];
                }
                $balances[$ac]['period_dr'] += (float) ($line['dr'] ?? 0);
                $balances[$ac]['period_cr'] += (float) ($line['cr'] ?? 0);
            }
        }

        // Round and add timestamp
        foreach ($balances as &$b) {
            $b['period_dr'] = round($b['period_dr'], 2);
            $b['period_cr'] = round($b['period_cr'], 2);
            $b['last_computed'] = date('c');
        }
        unset($b);

        // Compute discrepancies
        $discrepancies = [];
        $allCodes = array_unique(array_merge(array_keys($oldBalances), array_keys($balances)));
        foreach ($allCodes as $code) {
            $oldDr = (float) ($oldBalances[$code]['period_dr'] ?? 0);
            $oldCr = (float) ($oldBalances[$code]['period_cr'] ?? 0);
            $newDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $newCr = (float) ($balances[$code]['period_cr'] ?? 0);
            if (abs($oldDr - $newDr) > 0.01 || abs($oldCr - $newCr) > 0.01) {
                $discrepancies[] = [
                    'code'   => $code,
                    'old_dr' => $oldDr, 'old_cr' => $oldCr,
                    'new_dr' => $newDr, 'new_cr' => $newCr,
                ];
            }
        }

        // Bulk write closing balances to Firestore
foreach ($balances as $bc => $bv) { if (is_array($bv)) $this->_fs_bal_set((string)$bc, $bv); }
        $this->_audit('recompute_balances', 'closing_balances', 'all', null, [
            'accounts'      => count($balances),
            'discrepancies' => count($discrepancies),
        ]);
        $this->json_success([
            'message'       => 'Balances recomputed.',
            'accounts'      => count($balances),
            'discrepancies' => $discrepancies,
        ]);
    }

    // =========================================================================
    //  TAB 7: SETTINGS
    // =========================================================================

    /** GET: Get accounting settings */
    public function get_settings()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $lock = $this->_fs_lock_get();
        $counters = [] /* counters now on profile doc via _fs_counter_get */;

        $this->json_success([
            'period_lock' => is_array($lock) ? $lock : ['locked_until' => null],
            'counters'    => is_array($counters) ? $counters : [],
        ]);
    }

    /** POST: Lock accounting period (multi-path update for finalization) */
    public function lock_period()
    {
        $this->_require_role(self::ADMIN_ROLES);

        $date = trim((string) $this->input->post('locked_until'));
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->json_error('Valid date required (YYYY-MM-DD).');
        }

        $this->_fs_lock_set([
            'locked_until' => $date,
            'locked_by'    => $this->admin_id,
            'locked_at'    => date('c'),
        ]);

        // Finalize all entries on or before this date using multi-path update
        $dateIdx = [] /* RTDB index removed — Firestore accounting used directly */;
        $updates = [];
        if (is_array($dateIdx)) {
            foreach ($dateIdx as $entryDate => $ids) {
                if ($entryDate > $date) continue;
                if (!is_array($ids)) continue;
                foreach (array_keys($ids) as $id) {
                    $updates["Ledger/{$id}/is_finalized"] = true;
                    $updates["Ledger/{$id}/finalized_at"] = date('c');
                }
            }
        }

        $finalized = 0;
        if (!empty($updates)) {
            // RTDB Accounts node update removed. Counter reset via Firestore profile doc.
            foreach ($updates as $uKey => $uVal) {
                if (strpos($uKey, 'Voucher_counters/') === 0) {
                    $type = str_replace('Voucher_counters/', '', $uKey);
                    $this->_fs_counter_set($type, (int)$uVal);
                }
            }
            $finalized = (int) (count($updates) / 2);
        }

        $this->_audit('lock_period', 'period_lock', $date, null, ['finalized' => $finalized]);
        $this->json_success(['message' => "Period locked until {$date}. {$finalized} entries finalized."]);
    }

    /** GET: Check if migration has been done */
    public function get_migration_status()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $coa = $this->_fs_coa_all();
        $hasBook = !empty($this->_fs_coa_all()); /* check CoA not empty */

        $this->json_success([
            'coa_count'    => count($coa ?: []),
            'has_old_book' => $hasBook,
        ]);
    }

    /** POST: Carry forward closing balances as opening balances for next period */
    public function carry_forward_balances()
    {
        $this->_require_role(self::ADMIN_ROLES);

        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return $this->json_error('No chart of accounts found.');

        $balances = $this->_fs_bal_all();
        if (!is_array($balances)) $balances = [];

        $updated = 0;
        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active') continue;
            if (!empty($acct['is_group'])) continue;

            $openBal  = (float) ($acct['opening_balance'] ?? 0);
            $periodDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $periodCr = (float) ($balances[$code]['period_cr'] ?? 0);
            $normalSide = $acct['normal_side'] ?? 'Dr';

            if ($normalSide === 'Dr') {
                $closingBal = $openBal + $periodDr - $periodCr;
            } else {
                $closingBal = $openBal + $periodCr - $periodDr;
            }

            // Only update if closing balance differs from opening
            if (abs($closingBal - $openBal) > 0.01) {
                $safeCode = $this->safe_path_segment($code, 'code');
                $this->_fs_coa_set($safeCode, [
                    'opening_balance'      => round($closingBal, 2),
                    'balance_carried_from' => $this->session_year,
                    'updated_at'           => date('c'),
                ]);
                $updated++;
            }
        }

        $this->_audit('carry_forward', 'balances', $this->session_year, null, ['accounts_updated' => $updated]);
        $this->json_success(['message' => "Carried forward {$updated} account balances.", 'updated' => $updated]);
    }

    /** GET: Fetch audit log entries */
    public function get_audit_log()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $limit = (int) ($this->input->get('limit') ?: 50);
        $all = ($this->fs->schoolList('accountingAudit') ?? []);
        if (!is_array($all)) $all = [];

        $logs = [];
        foreach ($all as $id => $log) {
            if (!is_array($log)) continue;
            $log['id'] = $id;
            $logs[] = $log;
        }

        // Sort by timestamp desc
        usort($logs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));
        $logs = array_slice($logs, 0, $limit);

        $this->json_success(['logs' => $logs]);
    }


    // =========================================================================
    //  PRIVATE HELPERS
    // =========================================================================

    /** Incrementally update closing balances cache */
    private function _update_balances(array $affectedAccounts, string $op): void
    {
        foreach ($affectedAccounts as $code => $amounts) {
            $safeCode = $this->safe_path_segment($code, 'code');
            $path = $this->_bal() . "/{$safeCode}";
            $current = null /* RTDB path removed — use Firestore helper */;

            $pDr = (float) ($current['period_dr'] ?? 0);
            $pCr = (float) ($current['period_cr'] ?? 0);

            if ($op === 'add') {
                $pDr += $amounts['dr'];
                $pCr += $amounts['cr'];
            } else {
                $pDr -= $amounts['dr'];
                $pCr -= $amounts['cr'];
            }

            // RTDB set removed — use Firestore
$this->_fs_bal_set($code ?? '', [
                'period_dr'     => round($pDr, 2),
                'period_cr'     => round($pCr, 2),
                'last_computed' => date('c'),
            ]);

            // Firestore mirror of the updated balance.
            try { $this->acctFsSync->syncClosingBalance((string) $code, $pDr, $pCr); }
            catch (\Exception $_) {}
        }
    }

    /** Get voucher number prefix for a type */
    private function _voucher_prefix(string $type): string
    {
        $map = [
            'Journal' => 'JV-', 'Receipt' => 'RV-', 'Payment' => 'PV-',
            'Contra'  => 'CV-', 'Fee'     => 'FV-',
        ];
        return $map[$type] ?? 'GV-';
    }

    /** Default Chart of Accounts template for Indian schools */
    private function _default_coa_template(string $ts): array
    {
        $a = [];
        $add = function ($code, $name, $cat, $sub, $parent, $group = false, $bank = false) use (&$a, $ts) {
            $a[$code] = [
                'code' => $code, 'name' => $name, 'category' => $cat,
                'sub_category' => $sub, 'parent_code' => $parent,
                'is_group' => $group, 'is_bank' => $bank,
                'normal_side' => in_array($cat, ['Asset', 'Expense']) ? 'Dr' : 'Cr',
                'description' => '', 'opening_balance' => 0,
                'status' => 'active', 'is_system' => true,
                'sort_order' => (int) $code,
                'created_at' => $ts, 'updated_at' => $ts,
            ];
        };

        // Assets (1000-1999)
        $add('1000', 'Current Assets',        'Asset', 'Current Assets',  null, true);
        $add('1010', 'Cash in Hand',           'Asset', 'Current Assets',  '1000');
        $add('1020', 'Bank Account',            'Asset', 'Current Assets',  '1000', false, true);
        $add('1030', 'Accounts Receivable',    'Asset', 'Current Assets',  '1000');
        $add('1040', 'Advance to Staff',       'Asset', 'Current Assets',  '1000');
        $add('1050', 'Deposits & Prepayments', 'Asset', 'Current Assets',  '1000');
        $add('1060', 'Fees Receivable',        'Asset', 'Current Assets',  '1000');
        $add('1100', 'Fixed Assets',           'Asset', 'Fixed Assets',    null, true);
        $add('1110', 'Furniture & Fixtures',   'Asset', 'Fixed Assets',    '1100');
        $add('1120', 'Computer & Equipment',   'Asset', 'Fixed Assets',    '1100');
        $add('1130', 'Vehicles',               'Asset', 'Fixed Assets',    '1100');
        $add('1140', 'Building',               'Asset', 'Fixed Assets',    '1100');

        // Liabilities (2000-2999)
        $add('2000', 'Current Liabilities',       'Liability', 'Current Liabilities', null, true);
        $add('2010', 'Accounts Payable',           'Liability', 'Current Liabilities', '2000');
        $add('2020', 'Salary Payable',             'Liability', 'Current Liabilities',    '2000');
        $add('2030', 'PF Payable',                 'Liability', 'Statutory Liabilities', '2000');
        $add('2031', 'ESI Payable',                'Liability', 'Statutory Liabilities', '2000');
        $add('2032', 'TDS Payable',                'Liability', 'Statutory Liabilities', '2000');
        $add('2033', 'Professional Tax Payable',   'Liability', 'Statutory Liabilities', '2000');
        $add('2034', 'Other Deductions Payable',   'Liability', 'Statutory Liabilities', '2000');
        $add('2040', 'Security Deposits Received', 'Liability', 'Current Liabilities',   '2000');
        $add('2050', 'Advance Fees Received',      'Liability', 'Current Liabilities',   '2000');
        $add('2060', 'GST Payable',                'Liability', 'Statutory Liabilities', '2000');
        $add('2100', 'Long-term Liabilities',      'Liability', 'Long-term Liabilities', null, true);
        $add('2110', 'Loans Payable',              'Liability', 'Long-term Liabilities', '2100');

        // Equity (3000-3999)
        $add('3000', 'Equity',           'Equity', 'Equity', null, true);
        $add('3010', 'Trust Fund/Capital','Equity', 'Equity', '3000');
        $add('3020', 'Retained Surplus',  'Equity', 'Equity', '3000');

        // Income (4000-4999)
        $add('4000', 'Fee Income',         'Income', 'Fee Income',    null, true);
        $add('4010', 'Tuition Fees',       'Income', 'Fee Income',    '4000');
        $add('4020', 'Admission Fees',     'Income', 'Fee Income',    '4000');
        $add('4030', 'Examination Fees',   'Income', 'Fee Income',    '4000');
        $add('4040', 'Transport Fees',     'Income', 'Fee Income',    '4000');
        $add('4050', 'Hostel Fees',        'Income', 'Fee Income',    '4000');
        $add('4060', 'Late Fees/Penalty',  'Income', 'Fee Income',    '4000');
        $add('4100', 'Other Income',       'Income', 'Other Income',  null, true);
        $add('4110', 'Interest Income',    'Income', 'Other Income',  '4100');
        $add('4120', 'Donation Received',  'Income', 'Other Income',  '4100');
        $add('4130', 'Rent Income',        'Income', 'Other Income',  '4100');
        $add('4140', 'Miscellaneous Income','Income','Other Income',  '4100');

        // Expenses (5000-5999)
        $add('5000', 'Staff Expenses',           'Expense', 'Staff Expenses',  null, true);
        $add('5010', 'Teaching Staff Salary',    'Expense', 'Staff Expenses',  '5000');
        $add('5020', 'Non-Teaching Staff Salary','Expense', 'Staff Expenses',  '5000');
        $add('5030', 'PF/ESI Contribution',      'Expense', 'Staff Expenses',  '5000');
        $add('5100', 'Administrative Expenses',  'Expense', 'Admin Expenses',  null, true);
        $add('5110', 'Office Supplies',          'Expense', 'Admin Expenses',  '5100');
        $add('5120', 'Printing & Stationery',   'Expense', 'Admin Expenses',  '5100');
        $add('5130', 'Communication',            'Expense', 'Admin Expenses',  '5100');
        $add('5140', 'Travel & Conveyance',     'Expense', 'Admin Expenses',  '5100');
        $add('5150', 'Repairs & Maintenance',   'Expense', 'Admin Expenses',  '5100');
        $add('5160', 'Insurance',                'Expense', 'Admin Expenses',  '5100');
        $add('5170', 'Legal & Professional',    'Expense', 'Admin Expenses',  '5100');
        $add('5180', 'Bank Charges',             'Expense', 'Admin Expenses',  '5100');
        $add('5200', 'Educational Expenses',     'Expense', 'Educational',     null, true);
        $add('5210', 'Books & Library',          'Expense', 'Educational',     '5200');
        $add('5220', 'Laboratory Expenses',      'Expense', 'Educational',     '5200');
        $add('5230', 'Sports & Games',           'Expense', 'Educational',     '5200');
        $add('5240', 'Cultural Activities',      'Expense', 'Educational',     '5200');
        $add('5300', 'Utilities',                'Expense', 'Utilities',       null, true);
        $add('5310', 'Electricity',              'Expense', 'Utilities',       '5300');
        $add('5320', 'Water',                    'Expense', 'Utilities',       '5300');
        $add('5330', 'Generator/Fuel',           'Expense', 'Utilities',       '5300');

        return $a;
    }

    // =========================================================================
    //  EXPORT: Excel & PDF for all reports
    // =========================================================================

    /**
     * GET — Export a report as Excel (.xlsx).
     * Params: type, date_from, date_to (query string)
     */
    public function export_excel()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $type = trim($this->input->get('type') ?? '');
        $from = $this->_normalise_date((string) $this->input->get('date_from'));
        $to   = $this->_normalise_date((string) $this->input->get('date_to'));
        $validTypes = ['day_book', 'trial_balance', 'profit_loss', 'balance_sheet', 'cash_flow'];
        if (!in_array($type, $validTypes, true)) {
            show_error('Invalid report type.', 400);
            return;
        }

        $data = $this->_get_report_data($type, $from, $to);
        $schoolName = preg_replace('/[^A-Za-z0-9_ ]/', '', $this->school_display_name ?? $this->school_name);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $titles = [
            'day_book' => 'Day Book', 'trial_balance' => 'Trial Balance',
            'profit_loss' => 'Profit & Loss', 'balance_sheet' => 'Balance Sheet',
            'cash_flow' => 'Cash Flow',
        ];
        $title = $titles[$type] ?? 'Report';
        $sheet->setTitle(substr($title, 0, 31));

        // Header row: School name + report title + date range
        $sheet->setCellValue('A1', $schoolName);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $dateLabel = $title;
        if ($from || $to) $dateLabel .= '  |  ' . ($from ?: 'Start') . ' to ' . ($to ?: 'Present');
        $sheet->setCellValue('A2', $dateLabel);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);

        $row = 4; // data starts at row 4

        switch ($type) {
            case 'day_book':
                $row = $this->_xl_day_book($sheet, $data, $row);
                break;
            case 'trial_balance':
                $row = $this->_xl_trial_balance($sheet, $data, $row);
                break;
            case 'profit_loss':
                $row = $this->_xl_profit_loss($sheet, $data, $row);
                break;
            case 'balance_sheet':
                $row = $this->_xl_balance_sheet($sheet, $data, $row);
                break;
            case 'cash_flow':
                $row = $this->_xl_cash_flow($sheet, $data, $row);
                break;
        }

        // Auto-width columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        // Freeze header row (row 4 = first data header)
        $sheet->freezePane('A5');

        $filename = $schoolName . '_' . $type . '_' . date('Ymd') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    /**
     * GET — Export a report as PDF.
     */
    public function export_pdf()
    {
        $this->_require_role(self::FINANCE_ROLES);

        $type = trim($this->input->get('type') ?? '');
        $from = $this->_normalise_date((string) $this->input->get('date_from'));
        $to   = $this->_normalise_date((string) $this->input->get('date_to'));
        $validTypes = ['day_book', 'trial_balance', 'profit_loss', 'balance_sheet', 'cash_flow'];
        if (!in_array($type, $validTypes, true)) {
            show_error('Invalid report type.', 400);
            return;
        }

        $data = $this->_get_report_data($type, $from, $to);
        $schoolName = $this->school_display_name ?? $this->school_name;

        $titles = [
            'day_book' => 'Day Book', 'trial_balance' => 'Trial Balance',
            'profit_loss' => 'Profit & Loss Statement', 'balance_sheet' => 'Balance Sheet',
            'cash_flow' => 'Cash Flow Statement',
        ];
        $title = $titles[$type] ?? 'Report';
        $dateLabel = ($from || $to) ? (($from ?: 'Start') . ' to ' . ($to ?: date('Y-m-d'))) : 'All Transactions';

        $html = $this->_pdf_render($title, $schoolName, $dateLabel, $type, $data);

        $this->load->library('pdf_generator');
        $filename = preg_replace('/[^A-Za-z0-9_]/', '', $schoolName) . '_' . $type . '_' . date('Ymd') . '.pdf';

        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'sans-serif');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultPaperSize', ($type === 'day_book') ? 'A4' : 'A4');
        $options->set('defaultPaperOrientation', ($type === 'day_book' || $type === 'cash_flow') ? 'landscape' : 'portrait');
        $tempDir = APPPATH . 'cache/dompdf';
        if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
        $options->setTempDir($tempDir);
        $options->setFontDir($tempDir);
        $options->setFontCache($tempDir);

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->render();
        $dompdf->stream($filename, ['Attachment' => true]);
        exit;
    }

    // ── Report data fetcher (reuses existing logic without output) ──

    private function _get_report_data(string $type, string $from, string $to): array
    {
        switch ($type) {
            case 'day_book':
                return $this->_data_day_book($from, $to);
            case 'trial_balance':
                return $this->_data_trial_balance($from, $to);
            case 'profit_loss':
                return $this->_data_profit_loss($from, $to);
            case 'balance_sheet':
                return $this->_data_balance_sheet($from, $to);
            case 'cash_flow':
                return $this->_data_cash_flow($from, $to);
            default:
                return [];
        }
    }

    private function _data_day_book(string $from, string $to): array
    {
        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) return ['entries' => [], 'total_dr' => 0, 'total_cr' => 0];
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) $coa = [];

        $entries = [];
        $tDr = 0; $tCr = 0;
        foreach ($allLedger as $id => $e) {
            if (!is_array($e) || ($e['status'] ?? '') === 'deleted') continue;
            $d = $e['date'] ?? '';
            if ($from !== '' && $d < $from) continue;
            if ($to !== '' && $d > $to) continue;
            $dr = round((float) ($e['total_dr'] ?? 0), 2);
            $cr = round((float) ($e['total_cr'] ?? 0), 2);
            $lines = [];
            foreach ($e['lines'] ?? [] as $ln) {
                $ac = $ln['account_code'] ?? '';
                $lines[] = [
                    'code' => $ac,
                    'name' => $ln['account_name'] ?? ($coa[$ac]['name'] ?? $ac),
                    'dr' => round((float) ($ln['dr'] ?? 0), 2),
                    'cr' => round((float) ($ln['cr'] ?? 0), 2),
                ];
            }
            $entries[] = [
                'date' => $d, 'voucher_no' => $e['voucher_no'] ?? '', 'type' => $e['voucher_type'] ?? 'Journal',
                'source' => $e['source'] ?? 'manual', 'narration' => $e['narration'] ?? '',
                'dr' => $dr, 'cr' => $cr, 'lines' => $lines, 'created_at' => $e['created_at'] ?? '',
            ];
            $tDr += $dr; $tCr += $cr;
        }
        usort($entries, function ($a, $b) {
            $d = strcmp($a['date'], $b['date']);
            return $d !== 0 ? $d : strcmp($a['created_at'], $b['created_at']);
        });
        return ['entries' => $entries, 'total_dr' => round($tDr, 2), 'total_cr' => round($tCr, 2)];
    }

    private function _data_trial_balance(string $from, string $to): array
    {
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return ['rows' => [], 'total_dr' => 0, 'total_cr' => 0];
        $balances = $this->_compute_balances($from, $to);
        $rows = []; $tDr = 0; $tCr = 0;
        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active' || !empty($acct['is_group'])) continue;
            $ob = (float) ($acct['opening_balance'] ?? 0);
            $pDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $pCr = (float) ($balances[$code]['period_cr'] ?? 0);
            $ns = $acct['normal_side'] ?? 'Dr';
            $cb = ($ns === 'Dr') ? ($ob + $pDr - $pCr) : ($ob + $pCr - $pDr);
            if (abs($cb) < 0.01) continue;
            $dr = ($ns === 'Dr') ? max($cb, 0) : max(-$cb, 0);
            $cr = ($ns === 'Cr') ? max($cb, 0) : max(-$cb, 0);
            $tDr += $dr; $tCr += $cr;
            $rows[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'category' => $acct['category'] ?? '', 'dr' => round($dr, 2), 'cr' => round($cr, 2)];
        }
        usort($rows, fn($a, $b) => strnatcmp($a['code'], $b['code']));
        return ['rows' => $rows, 'total_dr' => round($tDr, 2), 'total_cr' => round($tCr, 2)];
    }

    private function _data_profit_loss(string $from, string $to): array
    {
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return ['income' => [], 'expenses' => [], 'total_income' => 0, 'total_expense' => 0, 'net' => 0];
        $balances = $this->_compute_balances($from, $to);
        $income = []; $expenses = []; $tI = 0; $tE = 0;
        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active' || !empty($acct['is_group'])) continue;
            $pDr = (float) ($balances[$code]['period_dr'] ?? 0);
            $pCr = (float) ($balances[$code]['period_cr'] ?? 0);
            $cat = $acct['category'] ?? '';
            if ($cat === 'Income') { $a = $pCr - $pDr; if (abs($a) < 0.01) continue; $tI += $a; $income[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($a, 2)]; }
            elseif ($cat === 'Expense') { $a = $pDr - $pCr; if (abs($a) < 0.01) continue; $tE += $a; $expenses[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($a, 2)]; }
        }
        return ['income' => $income, 'expenses' => $expenses, 'total_income' => round($tI, 2), 'total_expense' => round($tE, 2), 'net' => round($tI - $tE, 2)];
    }

    private function _data_balance_sheet(string $from, string $to): array
    {
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return ['assets' => [], 'liabilities' => [], 'equity' => [], 'totals' => ['assets' => 0, 'liabilities' => 0, 'equity' => 0]];
        $balances = $this->_compute_balances($from, $to);
        $assets = []; $liabilities = []; $equity = []; $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0]; $netPL = 0;
        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active' || !empty($acct['is_group'])) continue;
            $ob = (float) ($acct['opening_balance'] ?? 0); $pDr = (float) ($balances[$code]['period_dr'] ?? 0); $pCr = (float) ($balances[$code]['period_cr'] ?? 0);
            $cat = $acct['category'] ?? '';
            switch ($cat) {
                case 'Asset':     $b = $ob + $pDr - $pCr; if (abs($b) < 0.01) break; $totals['assets'] += $b; $assets[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($b, 2)]; break;
                case 'Liability': $b = $ob + $pCr - $pDr; if (abs($b) < 0.01) break; $totals['liabilities'] += $b; $liabilities[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($b, 2)]; break;
                case 'Equity':    $b = $ob + $pCr - $pDr; if (abs($b) < 0.01) break; $totals['equity'] += $b; $equity[] = ['code' => $code, 'name' => $acct['name'] ?? '', 'amount' => round($b, 2)]; break;
                case 'Income': $netPL += ($pCr - $pDr); break;
                case 'Expense': $netPL -= ($pDr - $pCr); break;
            }
        }
        if (abs($netPL) > 0.01) { $equity[] = ['code' => '-', 'name' => 'Current Year Surplus/Deficit', 'amount' => round($netPL, 2)]; $totals['equity'] += $netPL; }
        return ['assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'totals' => ['assets' => round($totals['assets'], 2), 'liabilities' => round($totals['liabilities'], 2), 'equity' => round($totals['equity'], 2)]];
    }

    private function _data_cash_flow(string $from, string $to): array
    {
        // Simplified: use _compute_balances for cash/bank accounts only
        $coa = $this->_fs_coa_all();
        if (!is_array($coa)) return ['operating' => 0, 'investing' => 0, 'financing' => 0, 'items' => []];
        $cashCodes = [];
        foreach ($coa as $code => $acct) {
            if (!is_array($acct) || ($acct['status'] ?? '') !== 'active' || !empty($acct['is_group'])) continue;
            if (in_array($code, ['1010', '1020']) || !empty($acct['is_bank'])) $cashCodes[$code] = true;
        }
        $openBal = 0;
        foreach ($cashCodes as $c => $_) $openBal += (float) ($coa[$c]['opening_balance'] ?? 0);

        $allLedger = $this->_fs_ledger_all();
        if (!is_array($allLedger)) $allLedger = [];
        $processed = []; $items = []; $pre = 0;
        $totals = ['operating' => 0, 'investing' => 0, 'financing' => 0];
        foreach (array_keys($cashCodes) as $cc) {
            $ids = [] /* index query removed — use Firestore accounting where queries */;
            if (!is_array($ids)) continue;
            foreach ($ids as $id) {
                if (isset($processed[$id])) continue; $processed[$id] = true;
                $e = $allLedger[$id] ?? null;
                if (!is_array($e) || ($e['status'] ?? '') === 'deleted') continue;
                $d = $e['date'] ?? '';
                if ($to !== '' && $d > $to) continue;
                $cDr = 0; $cCr = 0; $other = []; $contra = true;
                foreach ($e['lines'] ?? [] as $ln) {
                    $lc = $ln['account_code'] ?? '';
                    if (isset($cashCodes[$lc])) { $cDr += (float)($ln['dr']??0); $cCr += (float)($ln['cr']??0); }
                    else { $contra = false; $other[$lc] = $coa[$lc] ?? []; }
                }
                $net = $cDr - $cCr;
                if ($contra || abs($net) < 0.01) continue;
                if ($from !== '' && $d < $from) { $pre += $net; continue; }
                $ft = 'operating';
                foreach ($other as $oc => $oa) {
                    $os = strtolower($oa['sub_category'] ?? '');
                    if (strpos($os, 'fixed') !== false) { $ft = 'investing'; break; }
                    if (strpos($os, 'loan') !== false || ($oa['category'] ?? '') === 'Equity') { $ft = 'financing'; break; }
                }
                $totals[$ft] += $net;
                $items[] = ['date' => $d, 'voucher_no' => $e['voucher_no'] ?? '', 'narration' => $e['narration'] ?? '', 'type' => $ft, 'inflow' => max($net, 0), 'outflow' => max(-$net, 0), 'net' => round($net, 2)];
            }
        }
        usort($items, fn($a, $b) => strcmp($a['date'], $b['date']));
        $ob = round($openBal + $pre, 2); $nc = round($totals['operating'] + $totals['investing'] + $totals['financing'], 2);
        return ['items' => $items, 'operating' => round($totals['operating'], 2), 'investing' => round($totals['investing'], 2), 'financing' => round($totals['financing'], 2), 'opening' => $ob, 'closing' => round($ob + $nc, 2), 'net_change' => $nc];
    }

    // ── Excel sheet builders ──

    private function _xl_header(object $sheet, int $row, array $cols): int
    {
        $c = 'A';
        foreach ($cols as $label) {
            $sheet->setCellValue($c . $row, $label);
            $sheet->getStyle($c . $row)->getFont()->setBold(true);
            $sheet->getStyle($c . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E6F4F1');
            $c++;
        }
        return $row + 1;
    }

    private function _xl_day_book($sheet, array $data, int $row): int
    {
        $row = $this->_xl_header($sheet, $row, ['Date', 'Voucher No', 'Type', 'Source', 'Narration', 'Debit (₹)', 'Credit (₹)']);
        foreach ($data['entries'] as $e) {
            $sheet->setCellValue("A{$row}", $e['date']);
            $sheet->setCellValue("B{$row}", $e['voucher_no']);
            $sheet->setCellValue("C{$row}", $e['type']);
            $sheet->setCellValue("D{$row}", $e['source']);
            $sheet->setCellValue("E{$row}", $e['narration']);
            $sheet->setCellValue("F{$row}", $e['dr']);
            $sheet->setCellValue("G{$row}", $e['cr']);
            $sheet->getStyle("F{$row}:G{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            // Line items as sub-rows
            foreach ($e['lines'] as $ln) {
                $row++;
                $sheet->setCellValue("B{$row}", '  ' . $ln['code']);
                $sheet->setCellValue("E{$row}", '  ' . $ln['name']);
                $sheet->setCellValue("F{$row}", $ln['dr'] > 0 ? $ln['dr'] : '');
                $sheet->setCellValue("G{$row}", $ln['cr'] > 0 ? $ln['cr'] : '');
                $sheet->getStyle("A{$row}:G{$row}")->getFont()->setItalic(true)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF666666'));
                $sheet->getStyle("F{$row}:G{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            }
            $row++;
        }
        // Totals
        $sheet->setCellValue("E{$row}", 'TOTALS');
        $sheet->setCellValue("F{$row}", $data['total_dr']);
        $sheet->setCellValue("G{$row}", $data['total_cr']);
        $sheet->getStyle("E{$row}:G{$row}")->getFont()->setBold(true);
        $sheet->getStyle("F{$row}:G{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        return $row + 1;
    }

    private function _xl_trial_balance($sheet, array $data, int $row): int
    {
        $row = $this->_xl_header($sheet, $row, ['Code', 'Account Name', 'Category', 'Debit (₹)', 'Credit (₹)']);
        foreach ($data['rows'] as $r) {
            $sheet->setCellValue("A{$row}", $r['code']);
            $sheet->setCellValue("B{$row}", $r['name']);
            $sheet->setCellValue("C{$row}", $r['category']);
            $sheet->setCellValue("D{$row}", $r['dr']);
            $sheet->setCellValue("E{$row}", $r['cr']);
            $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $row++;
        }
        $sheet->setCellValue("C{$row}", 'TOTALS');
        $sheet->setCellValue("D{$row}", $data['total_dr']);
        $sheet->setCellValue("E{$row}", $data['total_cr']);
        $sheet->getStyle("C{$row}:E{$row}")->getFont()->setBold(true);
        $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        return $row + 1;
    }

    private function _xl_profit_loss($sheet, array $data, int $row): int
    {
        // Income section
        $sheet->setCellValue("A{$row}", 'INCOME'); $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12); $row++;
        $row = $this->_xl_header($sheet, $row, ['Code', 'Account', 'Amount (₹)']);
        foreach ($data['income'] as $r) {
            $sheet->setCellValue("A{$row}", $r['code']); $sheet->setCellValue("B{$row}", $r['name']); $sheet->setCellValue("C{$row}", $r['amount']);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row++;
        }
        $sheet->setCellValue("B{$row}", 'Total Income'); $sheet->setCellValue("C{$row}", $data['total_income']);
        $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true); $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row += 2;

        // Expense section
        $sheet->setCellValue("A{$row}", 'EXPENSES'); $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12); $row++;
        $row = $this->_xl_header($sheet, $row, ['Code', 'Account', 'Amount (₹)']);
        foreach ($data['expenses'] as $r) {
            $sheet->setCellValue("A{$row}", $r['code']); $sheet->setCellValue("B{$row}", $r['name']); $sheet->setCellValue("C{$row}", $r['amount']);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row++;
        }
        $sheet->setCellValue("B{$row}", 'Total Expenses'); $sheet->setCellValue("C{$row}", $data['total_expense']);
        $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true); $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row += 2;

        // Net
        $label = $data['net'] >= 0 ? 'Net Profit' : 'Net Loss';
        $sheet->setCellValue("B{$row}", $label); $sheet->setCellValue("C{$row}", abs($data['net']));
        $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        return $row + 1;
    }

    private function _xl_balance_sheet($sheet, array $data, int $row): int
    {
        foreach (['assets' => 'ASSETS', 'liabilities' => 'LIABILITIES', 'equity' => 'EQUITY'] as $key => $label) {
            $sheet->setCellValue("A{$row}", $label); $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12); $row++;
            $row = $this->_xl_header($sheet, $row, ['Code', 'Account', 'Amount (₹)']);
            foreach ($data[$key] as $r) {
                $sheet->setCellValue("A{$row}", $r['code']); $sheet->setCellValue("B{$row}", $r['name']); $sheet->setCellValue("C{$row}", $r['amount']);
                $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row++;
            }
            $sheet->setCellValue("B{$row}", 'Total ' . ucfirst($key)); $sheet->setCellValue("C{$row}", $data['totals'][$key] ?? 0);
            $sheet->getStyle("B{$row}:C{$row}")->getFont()->setBold(true);
            $sheet->getStyle("C{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row += 2;
        }
        return $row;
    }

    private function _xl_cash_flow($sheet, array $data, int $row): int
    {
        $sheet->setCellValue("A{$row}", 'Opening Balance'); $sheet->setCellValue("B{$row}", $data['opening'] ?? 0);
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row += 2;

        foreach (['operating' => 'OPERATING ACTIVITIES', 'investing' => 'INVESTING ACTIVITIES', 'financing' => 'FINANCING ACTIVITIES'] as $ft => $label) {
            $sheet->setCellValue("A{$row}", $label); $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11); $row++;
            $row = $this->_xl_header($sheet, $row, ['Date', 'Voucher', 'Narration', 'Inflow (₹)', 'Outflow (₹)', 'Net (₹)']);
            $sub = 0;
            foreach ($data['items'] as $item) {
                if ($item['type'] !== $ft) continue;
                $sheet->setCellValue("A{$row}", $item['date']); $sheet->setCellValue("B{$row}", $item['voucher_no']); $sheet->setCellValue("C{$row}", $item['narration']);
                $sheet->setCellValue("D{$row}", $item['inflow'] > 0 ? $item['inflow'] : '');
                $sheet->setCellValue("E{$row}", $item['outflow'] > 0 ? $item['outflow'] : '');
                $sheet->setCellValue("F{$row}", $item['net']);
                $sheet->getStyle("D{$row}:F{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $sub += $item['net']; $row++;
            }
            $sheet->setCellValue("C{$row}", 'Subtotal'); $sheet->setCellValue("F{$row}", round($sub, 2));
            $sheet->getStyle("C{$row}:F{$row}")->getFont()->setBold(true);
            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row += 2;
        }

        $sheet->setCellValue("A{$row}", 'Net Cash Change'); $sheet->setCellValue("B{$row}", $data['net_change'] ?? 0);
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00'); $row++;
        $sheet->setCellValue("A{$row}", 'Closing Balance'); $sheet->setCellValue("B{$row}", $data['closing'] ?? 0);
        $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        return $row + 1;
    }

    // ── PDF HTML builder ──

    private function _pdf_render(string $title, string $school, string $dateLabel, string $type, array $data): string
    {
        $css = '
        body{font-family:sans-serif;font-size:11px;color:#1a2e2a;margin:0;padding:20px 30px;}
        h1{font-size:18px;margin:0 0 2px;color:#0f766e;}
        .sub{font-size:12px;color:#4a6a60;margin:0 0 4px;}
        .date{font-size:10px;color:#7a9a8e;margin:0 0 16px;}
        table{width:100%;border-collapse:collapse;margin-bottom:16px;}
        th{background:#e6f4f1;color:#0f766e;font-size:10px;text-transform:uppercase;letter-spacing:.5px;
           padding:6px 8px;text-align:left;border-bottom:2px solid #b8cec6;}
        td{padding:5px 8px;border-bottom:1px solid #d1ddd8;font-size:10px;}
        .r{text-align:right;font-family:monospace;}
        .b{font-weight:bold;}
        .green{color:#16a34a;} .red{color:#dc2626;} .teal{color:#0f766e;}
        .total-row td{font-weight:bold;border-top:2px solid #0f766e;background:#f0f7f5;}
        .section{font-size:13px;font-weight:bold;color:#0f766e;margin:14px 0 6px;padding-bottom:4px;border-bottom:1px solid #b8cec6;}
        .footer{margin-top:20px;font-size:9px;color:#7a9a8e;text-align:center;border-top:1px solid #d1ddd8;padding-top:8px;}
        .sub-row td{font-size:9px;color:#666;font-style:italic;padding:2px 8px 2px 24px;}
        ';

        $h = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
        $h .= '<h1>' . htmlspecialchars($school) . '</h1>';
        $h .= '<p class="sub">' . htmlspecialchars($title) . '</p>';
        $h .= '<p class="date">' . htmlspecialchars($dateLabel) . '</p>';

        switch ($type) {
            case 'day_book': $h .= $this->_pdf_day_book($data); break;
            case 'trial_balance': $h .= $this->_pdf_trial_balance($data); break;
            case 'profit_loss': $h .= $this->_pdf_profit_loss($data); break;
            case 'balance_sheet': $h .= $this->_pdf_balance_sheet($data); break;
            case 'cash_flow': $h .= $this->_pdf_cash_flow($data); break;
        }

        $h .= '<div class="footer">Generated on ' . date('d M Y, h:i A') . '</div>';
        $h .= '</body></html>';
        return $h;
    }

    private function _fmt(float $v): string { return number_format($v, 2, '.', ','); }

    private function _pdf_day_book(array $d): string
    {
        $h = '<table><thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Narration</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>';
        foreach ($d['entries'] as $e) {
            $h .= '<tr><td>' . $e['date'] . '</td><td>' . $e['voucher_no'] . '</td><td>' . $e['type'] . '</td><td>' . htmlspecialchars($e['narration']) . '</td><td class="r">' . $this->_fmt($e['dr']) . '</td><td class="r">' . $this->_fmt($e['cr']) . '</td></tr>';
            foreach ($e['lines'] as $ln) {
                $h .= '<tr class="sub-row"><td></td><td>' . $ln['code'] . '</td><td colspan="2">' . htmlspecialchars($ln['name']) . '</td><td class="r">' . ($ln['dr'] > 0 ? $this->_fmt($ln['dr']) : '') . '</td><td class="r">' . ($ln['cr'] > 0 ? $this->_fmt($ln['cr']) : '') . '</td></tr>';
            }
        }
        $h .= '</tbody><tfoot><tr class="total-row"><td colspan="4" class="r">Totals</td><td class="r">' . $this->_fmt($d['total_dr']) . '</td><td class="r">' . $this->_fmt($d['total_cr']) . '</td></tr></tfoot></table>';
        return $h;
    }

    private function _pdf_trial_balance(array $d): string
    {
        $h = '<table><thead><tr><th>Code</th><th>Account</th><th>Category</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>';
        foreach ($d['rows'] as $r) {
            $h .= '<tr><td>' . $r['code'] . '</td><td>' . htmlspecialchars($r['name']) . '</td><td>' . $r['category'] . '</td><td class="r">' . ($r['dr'] > 0 ? $this->_fmt($r['dr']) : '') . '</td><td class="r">' . ($r['cr'] > 0 ? $this->_fmt($r['cr']) : '') . '</td></tr>';
        }
        $h .= '</tbody><tfoot><tr class="total-row"><td colspan="3" class="r">Totals</td><td class="r">' . $this->_fmt($d['total_dr']) . '</td><td class="r">' . $this->_fmt($d['total_cr']) . '</td></tr></tfoot></table>';
        $diff = abs($d['total_dr'] - $d['total_cr']);
        $h .= $diff < 0.01 ? '<p class="teal b">Balanced</p>' : '<p class="red b">Difference: ' . $this->_fmt($diff) . '</p>';
        return $h;
    }

    private function _pdf_profit_loss(array $d): string
    {
        $h = '<div class="section">Income</div><table><thead><tr><th>Account</th><th class="r">Amount</th></tr></thead><tbody>';
        foreach ($d['income'] as $r) $h .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td class="r green">' . $this->_fmt($r['amount']) . '</td></tr>';
        $h .= '<tr class="total-row"><td class="r b">Total Income</td><td class="r green b">' . $this->_fmt($d['total_income']) . '</td></tr></tbody></table>';
        $h .= '<div class="section">Expenses</div><table><thead><tr><th>Account</th><th class="r">Amount</th></tr></thead><tbody>';
        foreach ($d['expenses'] as $r) $h .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td class="r red">' . $this->_fmt($r['amount']) . '</td></tr>';
        $h .= '<tr class="total-row"><td class="r b">Total Expenses</td><td class="r red b">' . $this->_fmt($d['total_expense']) . '</td></tr></tbody></table>';
        $nc = $d['net'] >= 0 ? 'green' : 'red'; $nl = $d['net'] >= 0 ? 'Net Profit' : 'Net Loss';
        $h .= '<p class="' . $nc . ' b" style="font-size:14px;text-align:center;margin-top:12px">' . $nl . ': ' . $this->_fmt(abs($d['net'])) . '</p>';
        return $h;
    }

    private function _pdf_balance_sheet(array $d): string
    {
        $h = '';
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $k => $label) {
            $h .= '<div class="section">' . $label . '</div><table><thead><tr><th>Account</th><th class="r">Amount</th></tr></thead><tbody>';
            foreach ($d[$k] as $r) $h .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td class="r">' . $this->_fmt($r['amount']) . '</td></tr>';
            $h .= '<tr class="total-row"><td class="r b">Total ' . $label . '</td><td class="r b">' . $this->_fmt($d['totals'][$k] ?? 0) . '</td></tr></tbody></table>';
        }
        return $h;
    }

    private function _pdf_cash_flow(array $d): string
    {
        $h = '<p class="b">Opening Balance: ' . $this->_fmt($d['opening'] ?? 0) . '</p>';
        foreach (['operating' => 'Operating Activities', 'investing' => 'Investing Activities', 'financing' => 'Financing Activities'] as $ft => $label) {
            $h .= '<div class="section">' . $label . '</div><table><thead><tr><th>Date</th><th>Voucher</th><th>Description</th><th class="r">Inflow</th><th class="r">Outflow</th><th class="r">Net</th></tr></thead><tbody>';
            $sub = 0;
            foreach ($d['items'] as $item) {
                if ($item['type'] !== $ft) continue;
                $h .= '<tr><td>' . $item['date'] . '</td><td>' . $item['voucher_no'] . '</td><td>' . htmlspecialchars($item['narration']) . '</td><td class="r green">' . ($item['inflow'] > 0 ? $this->_fmt($item['inflow']) : '') . '</td><td class="r red">' . ($item['outflow'] > 0 ? $this->_fmt($item['outflow']) : '') . '</td><td class="r b">' . $this->_fmt($item['net']) . '</td></tr>';
                $sub += $item['net'];
            }
            $h .= '<tr class="total-row"><td colspan="5" class="r">Subtotal</td><td class="r b">' . $this->_fmt(round($sub, 2)) . '</td></tr></tbody></table>';
        }
        $h .= '<p class="b">Net Cash Change: ' . $this->_fmt($d['net_change'] ?? 0) . '</p>';
        $h .= '<p class="teal b" style="font-size:14px">Closing Balance: ' . $this->_fmt($d['closing'] ?? 0) . '</p>';
        return $h;
    }
}
